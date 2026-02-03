<?php
require 'db.php';
require 'auth_check.php';
require 'mail_config.php'; // On inclut ta configuration PHPMailer

checkAccess('admin');

use PHPMailer\PHPMailer\Exception;

// --- CONFIGURATION DE SÉCURITÉ ---
// METTRE SUR FALSE POUR ENVOYER RÉELLEMENT AUX ÉLÈVES
$TEST_MODE = false; 
$TEST_EMAIL = 'theo.marescal@gmail.com'; 

$messageStr = "";
$messageType = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mode = $_POST['mode']; // 'reminder' ou 'custom'
    $customSubject = $_POST['subject'] ?? "Information CVL";
    $customBody = $_POST['custom_message'] ?? "";
    
    // 1. SÉLECTION DES DESTINATAIRES
    $recipients = [];

    if ($mode === 'reminder') {
        // Jointure pour récupérer l'email depuis 'users'
        // On ne sélectionne QUE ceux qui ont un reste à payer > 0
        $sql = "SELECT u.email, u.nom, u.prenom, SUM(o.total_price) as total_due 
                FROM orders o
                JOIN users u ON o.user_id = u.user_id
                WHERE o.is_paid = 0 
                GROUP BY u.user_id";
                
        $stmt = $pdo->query($sql);
        $recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $subject = "Rappel : Paiement de vos Roses 🌹"; // Sujet fixe pour le rappel
        
    } else {
        // Mode personnalisé : Tous les acheteurs distincts
        $sql = "SELECT DISTINCT u.email, u.nom, u.prenom 
                FROM orders o
                JOIN users u ON o.user_id = u.user_id";
                
        $stmt = $pdo->query($sql);
        $recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $subject = $customSubject;
    }

    // 2. PRÉPARATION DE PHPMAILER
    $countSent = 0;
    $mail = getMailer(); // On récupère l'instance configurée depuis mail_config.php

    foreach ($recipients as $row) {
        $prenom = htmlspecialchars($row['prenom']);
        $nom = htmlspecialchars($row['nom']);
        $emailUser = $row['email'];

        // Si pas d'email, on passe
        if (empty($emailUser)) continue;

        // --- CONSTRUCTION DU CONTENU INTÉRIEUR ---
        $innerContent = "";
        
        if ($mode === 'reminder') {
            $amount = number_format($row['total_due'], 2);
            $innerContent = "
                <p>Bonjour <strong>$prenom</strong>,</p>
                <p>Sauf erreur de notre part, nous n'avons pas encore reçu le règlement de vos commandes de roses.</p>
                
                <div style='background-color: #fff0f6; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #d63384;'>
                    <h3 style='margin: 0; color: #d63384;'>Reste à payer : $amount €</h3>
                    <p style='margin: 5px 0 0 0; font-size: 0.9em;'>Veuillez régler auprès du CVL pour valider la commande. Les paiements se font par <strong>espèce</strong> et par <strong>carte bancaire</strong> dans le hall de la <strong>Vie Scolaire</strong>.</p>
                    <p style='margin: 5px 0 0 0; font-size: 0.9em;'>Les paiements ne se feront que du <strong>2 au 6 février 2026</strong> uniquement de <strong>13h à 14h</strong>.</p>
                </div>
                
                <p>Sans paiement de votre part avant la date limite, vos commandes seront malheureusement annulées.</p>
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
                    L'équipe du CVL - Lycée Jules Fil.
                </p>
            </div>
        </div>";

        // --- ENVOI VIA PHPMAILER ---
        try {
            // Important : On vide les adresses précédentes pour ne pas accumuler les destinataires
            $mail->clearAddresses();
            
            // Gestion Mode Test
            $destinataire = $TEST_MODE ? $TEST_EMAIL : $emailUser;
            
            $mail->addAddress($destinataire); // Ajout du destinataire
            $mail->isHTML(true);              // Format HTML
            $mail->Subject = $subject;        // Sujet
            $mail->Body    = $emailHtml;      // Contenu

            $mail->send();
            $countSent++;
        } catch (Exception $e) {
            // En cas d'erreur sur un mail spécifique, on continue quand même les autres
            // On pourrait logger l'erreur ici : error_log($mail->ErrorInfo);
        }
    }
    
    $messageType = "success";
    $targetName = ($mode === 'reminder') ? "élèves en attente de paiement" : "acheteurs";
    $messageStr = "$countSent emails envoyés avec succès aux $targetName via SMTP.";
    
    if ($TEST_MODE) {
        $messageStr .= " <br><strong>(MODE TEST ACTIF : Tous les mails ont été envoyés à $TEST_EMAIL)</strong>";
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <title>Administration Emails - St Valentin</title>
    <?php include 'head_imports.php'; ?>
</head>
<body class="bg-light">

<div class="container py-5" style="max-width: 800px;">
    
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="fas fa-envelope-open-text text-danger me-2"></i> Campagnes Email</h2>
        <a href="manage_orders.php" class="btn btn-outline-secondary btn-sm">Retour aux commandes</a>
    </div>

    <?php if ($TEST_MODE): ?>
    <div class="alert alert-warning border-warning d-flex align-items-center">
        <i class="fas fa-exclamation-triangle fa-2x me-3"></i>
        <div>
            <strong>MODE TEST ACTIVÉ</strong><br>
            Les emails ne partiront pas aux élèves. Ils seront tous envoyés à : <u><?php echo $TEST_EMAIL; ?></u>.
            <br><small>Changez <code>$TEST_MODE = false</code> dans le code pour envoyer réellement.</small>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($messageStr): ?>
        <div class="alert alert-<?php echo $messageType; ?>">
            <?php echo $messageStr; ?>
        </div>
    <?php endif; ?>

    <div class="card shadow-sm border-0">
        <div class="card-body p-4">
            <form method="POST" id="emailForm" onsubmit="return confirm('Êtes-vous sûr de vouloir envoyer ces emails ?');">
                
                <div class="mb-4">
                    <label class="form-label fw-bold">Type de campagne</label>
                    <div class="btn-group w-100" role="group">
                        <input type="radio" class="btn-check" name="mode" id="modeReminder" value="reminder" checked onchange="toggleForm()">
                        <label class="btn btn-outline-danger" for="modeReminder">
                            <i class="fas fa-hand-holding-usd me-2"></i> Rappel Paiement (Impayés uniquement)
                        </label>

                        <input type="radio" class="btn-check" name="mode" id="modeCustom" value="custom" onchange="toggleForm()">
                        <label class="btn btn-outline-primary" for="modeCustom">
                            <i class="fas fa-pen-nib me-2"></i> Message Personnalisé (Tous)
                        </label>
                    </div>
                </div>

                <div id="reminderInfo" class="alert alert-light border">
                    <h6 class="text-danger"><i class="fas fa-info-circle me-1"></i> Aperçu du message automatique :</h6>
                    <small class="text-muted">
                        "Bonjour [Prénom],<br>
                        Sauf erreur de notre part, nous n'avons pas encore reçu le règlement...<br>
                        Reste à payer : [Montant] €..."
                    </small>
                </div>

                <div id="customFields" style="display: none;">
                    <div class="mb-3">
                        <label class="form-label">Objet du mail</label>
                        <input type="text" name="subject" class="form-control" placeholder="Ex: Info importante concernant la distribution...">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Votre message</label>
                        <textarea name="custom_message" class="form-control" rows="6" placeholder="Écrivez votre message ici. Il sera inséré dans le cadre officiel..."></textarea>
                        <div class="form-text">Le "Bonjour [Prénom]" et la signature sont ajoutés automatiquement.</div>
                    </div>
                </div>

                <div class="d-grid mt-4">
                    <button type="submit" class="btn btn-dark btn-lg">
                        <i class="fas fa-paper-plane me-2"></i> Envoyer les emails
                    </button>
                </div>

            </form>
        </div>
    </div>
</div>

<script>
    function toggleForm() {
        const isCustom = document.getElementById('modeCustom').checked;
        const reminderInfo = document.getElementById('reminderInfo');
        const customFields = document.getElementById('customFields');

        if (isCustom) {
            reminderInfo.style.display = 'none';
            customFields.style.display = 'block';
        } else {
            reminderInfo.style.display = 'block';
            customFields.style.display = 'none';
        }
    }
</script>

</body>
</html>