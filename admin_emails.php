<?php
require 'db.php'; // Assure-toi que c'est bien le bon nom de fichier (ou pdo_connect.php selon ton projet)
require 'auth_check.php';
require 'mail_config.php'; 

checkAccess('admin');

use PHPMailer\PHPMailer\Exception;

// --- CONFIGURATION ---
// METTRE SUR FALSE POUR ENVOYER RÉELLEMENT AUX ÉLÈVES
$TEST_MODE = false; 
$TEST_EMAIL = 'theo.marescal@gmail.com'; 

$messageStr = "";
$messageType = "";
$errorLog = []; // Tableau pour stocker les erreurs précises

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Augmente le temps max d'exécution du script à 5 minutes (pour les envois lents)
    set_time_limit(300);

    $mode = $_POST['mode']; // 'reminder' ou 'custom'
    $customSubject = $_POST['subject'] ?? "Information CVL";
    $customBody = $_POST['custom_message'] ?? "";
    $targetFilter = $_POST['target_filter'] ?? 'all'; // 'all', 'paid', 'unpaid'
    
    // 1. SÉLECTION DES DESTINATAIRES
    $recipients = [];

    if ($mode === 'reminder') {
        // --- MODE RAPPEL (Uniquement impayés, logique fixe) ---
        $sql = "SELECT u.email, u.nom, u.prenom, SUM(o.total_price) as total_due 
                FROM orders o
                JOIN users u ON o.user_id = u.user_id
                WHERE o.is_paid = 0 
                GROUP BY u.user_id";
        
        $stmt = $pdo->query($sql);
        $recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $subject = "Rappel : Paiement de vos Roses 🌹"; 
        
    } else {
        // --- MODE PERSONNALISÉ (Avec filtre) ---
        $sql = "SELECT DISTINCT u.email, u.nom, u.prenom 
                FROM orders o
                JOIN users u ON o.user_id = u.user_id";

        // Application du filtre
        if ($targetFilter === 'paid') {
            $sql .= " WHERE o.is_paid = 1";
        } elseif ($targetFilter === 'unpaid') {
            $sql .= " WHERE o.is_paid = 0";
        }
        // Si 'all', on ne met pas de WHERE, on prend tout le monde.

        $sql .= " GROUP BY u.user_id";
                
        $stmt = $pdo->query($sql);
        $recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $subject = $customSubject;
    }

    // 2. ENVOI
    $countSent = 0;
    $mail = getMailer(); 

    foreach ($recipients as $row) {
        $prenom = htmlspecialchars($row['prenom']);
        $nom = htmlspecialchars($row['nom']);
        $emailUser = $row['email'];

        // Détection des erreurs "Pas d'email"
        if (empty($emailUser)) {
            $errorLog[] = "Introuvable : Pas d'adresse email pour <strong>$nom $prenom</strong>.";
            continue;
        }

        // --- CONTENU ---
        $innerContent = "";
        
        if ($mode === 'reminder') {
            $amount = number_format($row['total_due'], 2);
            $innerContent = "
                <p>Bonjour <strong>$prenom</strong>,</p>
                <p>DERNIER JOUR ⚠️ ! Sauf erreur de notre part, nous n'avons pas encore reçu le règlement de vos commandes de roses. Faites vite, le paiement de vos commandes s'arrête <strong>ce soir</strong> !</p>
                
                <div style='background-color: #fff0f6; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #d63384;'>
                    <h3 style='margin: 0; color: #d63384;'>Reste à payer : $amount €</h3>
                    <p style='margin: 5px 0 0 0; font-size: 0.9em;'>Veuillez régler au stand du CVL, dans le hall de la vie scolaire, RDC bâtiment E, aujourd'hui (aux pauses de 10 et 16h et de 12h à 14h).</p>
                </div>
                
                <p>Sans paiement avant la date limite, les commandes seront annulées.</p>
            ";
        } else {
            // Mode Personnalisé
            $formattedBody = nl2br(htmlspecialchars($customBody));
            $innerContent = "
                <p>Bonjour <strong>$prenom</strong>,</p>
                <div style='font-size: 1.05em; line-height: 1.6;'>
                    $formattedBody
                </div>
            ";
        }

        // --- TEMPLATE GLOBAL ---
        $emailHtml = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: auto; border: 1px solid #eee; border-radius: 8px; background-color: #ffffff;'>
            <div style='background-color: #d63384; padding: 20px; text-align: center; color: white; border-radius: 8px 8px 0 0;'>
                <h1 style='margin: 0;'>Saint Valentin 🌹</h1>
            </div>
            <div style='padding: 20px; color: #333;'>
                $innerContent
                <hr style='border: 0; border-top: 1px solid #eee; margin: 20px 0;'>
                <p style='font-size: 0.8em; color: #888; text-align: center;'>
                    Ceci est un mail automatique. Merci de ne pas répondre.<br>
                    L'équipe du CVL.
                </p>
            </div>
        </div>";

        // --- ENVOI ---
        try {
            $mail->clearAddresses();
            $destinataire = $TEST_MODE ? $TEST_EMAIL : $emailUser;
            
            $mail->addAddress($destinataire);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $emailHtml;

            $mail->send();
            $countSent++;

            // PAUSE ANTI-SPAM : Dort 1 seconde tous les 10 mails pour ne pas bloquer le SMTP
            if ($countSent % 10 == 0) { sleep(1); }

        } catch (Exception $e) {
            // ENREGISTREMENT DE L'ERREUR
            $errorLog[] = "Échec pour <strong>$nom $prenom</strong> ($emailUser) : " . $mail->ErrorInfo;
            
            // On réinitialise le mailer en cas de crash critique
            try { $mail = getMailer(); } catch (Exception $ex) {}
        }
    }
    
    // Message de confirmation adapté
    $targetName = "personnes";
    if ($mode === 'reminder') $targetName = "élèves en attente de paiement";
    elseif ($targetFilter === 'paid') $targetName = "élèves ayant payé";
    elseif ($targetFilter === 'unpaid') $targetName = "élèves n'ayant pas payé";
    elseif ($targetFilter === 'all') $targetName = "tous les acheteurs";

    // Si on a des erreurs, on met un warning, sinon success
    if (!empty($errorLog)) {
        $messageType = "warning";
        $messageStr = "Envoi terminé avec des erreurs. $countSent emails envoyés sur " . count($recipients) . ".";
    } else {
        $messageType = "success";
        $messageStr = "$countSent emails envoyés avec succès ($targetName).";
    }
    
    if ($TEST_MODE) {
        $messageStr .= " <br><strong>(MODE TEST ACTIF : Tout envoyé à $TEST_EMAIL)</strong>";
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <title>Administration Emails</title>
    <?php include 'head_imports.php'; ?>
</head>
<body class="bg-light">

<div class="container py-5" style="max-width: 800px;">
    
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="fas fa-envelope-open-text text-danger me-2"></i> Campagnes Email</h2>
        <a href="admin.php" class="btn btn-outline-secondary btn-sm">Retour</a>
    </div>

    <?php if ($TEST_MODE): ?>
    <div class="alert alert-warning border-warning d-flex align-items-center">
        <i class="fas fa-exclamation-triangle fa-2x me-3"></i>
        <div>
            <strong>MODE TEST ACTIVÉ</strong><br>
            Les emails seront tous envoyés à : <u><?php echo $TEST_EMAIL; ?></u>.
        </div>
    </div>
    <?php endif; ?>

    <?php if ($messageStr): ?>
        <div class="alert alert-<?php echo $messageType; ?>">
            <?php echo $messageStr; ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($errorLog)): ?>
        <div class="alert alert-danger mt-3">
            <h5><i class="fas fa-bug me-2"></i> Rapport d'erreurs (<?php echo count($errorLog); ?>)</h5>
            <div class="bg-white p-2 rounded text-danger" style="max-height: 200px; overflow-y: auto; font-size: 0.85rem;">
                <ul class="mb-0 ps-3">
                    <?php foreach ($errorLog as $err): ?>
                        <li><?php echo $err; ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <small class="mt-2 d-block">Ces utilisateurs n'ont pas reçu le mail.</small>
        </div>
    <?php endif; ?>

    <div class="card shadow-sm border-0 mt-4">
        <div class="card-body p-4">
            <form method="POST" onsubmit="return confirm('Confirmer l\'envoi ?');">
                
                <div class="mb-4">
                    <label class="form-label fw-bold">Type de campagne</label>
                    <div class="btn-group w-100" role="group">
                        <input type="radio" class="btn-check" name="mode" id="modeReminder" value="reminder" checked onchange="toggleForm()">
                        <label class="btn btn-outline-danger" for="modeReminder">
                            <i class="fas fa-hand-holding-usd me-2"></i> Rappel Paiement
                        </label>

                        <input type="radio" class="btn-check" name="mode" id="modeCustom" value="custom" onchange="toggleForm()">
                        <label class="btn btn-outline-primary" for="modeCustom">
                            <i class="fas fa-pen-nib me-2"></i> Message Perso
                        </label>
                    </div>
                </div>

                <div id="reminderInfo" class="alert alert-light border">
                    <h6 class="text-danger"><i class="fas fa-info-circle me-1"></i> Rappel automatique</h6>
                    <small class="text-muted">Envoie le montant restant dû à tous les élèves qui n'ont pas encore payé.</small>
                </div>

                <div id="customFields" style="display: none;">
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">Qui doit recevoir ce message ?</label>
                        <select name="target_filter" class="form-select border-primary">
                            <option value="all">👥 Tout le monde (Tous ceux qui ont commandé)</option>
                            <option value="paid">✅ Uniquement ceux qui ont PAYÉ</option>
                            <option value="unpaid">⚠️ Uniquement ceux qui n'ont PAS PAYÉ</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Objet</label>
                        <input type="text" name="subject" class="form-control" placeholder="Ex: Merci pour votre commande !">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Message</label>
                        <textarea name="custom_message" class="form-control" rows="5" placeholder="Votre message..."></textarea>
                    </div>
                </div>

                <div class="d-grid mt-4">
                    <button type="submit" class="btn btn-dark btn-lg">Envoyer</button>
                </div>

            </form>
        </div>
    </div>
</div>

<script>
    function toggleForm() {
        const isCustom = document.getElementById('modeCustom').checked;
        document.getElementById('reminderInfo').style.display = isCustom ? 'none' : 'block';
        document.getElementById('customFields').style.display = isCustom ? 'block' : 'none';
    }
</script>

</body>
</html>