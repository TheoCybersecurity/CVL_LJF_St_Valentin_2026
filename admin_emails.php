<?php
/**
 * Administration - Gestion des Campagnes Emailing
 * admin_emails.php
 * * Ce script permet aux administrateurs d'envoyer des emails transactionnels ou informatifs en masse.
 * Il gère trois types de campagnes :
 * 1. Rappels de paiement (automatique pour les impayés).
 * 2. Notifications post-événement (pour les roses non distribuées cause absence).
 * 3. Messages personnalisés (ciblage par statut de commande).
 *
 * Utilise PHPMailer via la fonction helper getMailer().
 */

require 'db.php';
require 'auth_check.php';
require 'mail_config.php'; 

// Vérification des droits d'administration
checkAccess('admin');

use PHPMailer\PHPMailer\Exception;

// =================================================================
// CONFIGURATION DU MODULE D'ENVOI
// =================================================================

/** * @var bool $TEST_MODE 
 * Mode Sandbox : Si true, tous les emails sont détournés vers $TEST_EMAIL 
 * pour éviter de spammer les utilisateurs réels pendant le développement.
 */
$TEST_MODE = false; 
$TEST_EMAIL = 'admin@example.com'; // Adresse de réception en mode test

$messageStr = "";
$messageType = "";
$errorLog = []; // Pile pour stocker les erreurs d'envoi SMTP

// =================================================================
// TRAITEMENT DU FORMULAIRE (ENVOI)
// =================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Extension du temps d'exécution PHP pour éviter les timeouts lors des envois en masse (+5 min)
    set_time_limit(300);

    // Récupération des paramètres de campagne
    $mode = $_POST['mode']; // 'reminder', 'custom' ou 'absent_recipient'
    $customSubject = $_POST['subject'] ?? "Information CVL";
    $customBody = $_POST['custom_message'] ?? "";
    $targetFilter = $_POST['target_filter'] ?? 'all'; 
    
    // --- 1. SÉLECTION ET PRÉPARATION DES DONNÉES ---
    $recipients = [];

    if ($mode === 'reminder') {
        // [CAMPAGNE AUTOMATIQUE] RAPPEL DE PAIEMENT
        // Cible : Utilisateurs ayant commandé mais n'ayant pas réglé (is_paid = 0).
        // Donnée : Calcule la somme totale due par utilisateur.
        $sql = "SELECT u.email, u.nom, u.prenom, SUM(o.total_price) as total_due 
                FROM orders o
                JOIN users u ON o.user_id = u.user_id
                WHERE o.is_paid = 0 
                GROUP BY u.user_id";
        
        $stmt = $pdo->query($sql);
        $recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $subject = "Rappel : Paiement de vos Roses 🌹"; 

    } elseif ($mode === 'absent_recipient') {
        // [CAMPAGNE AUTOMATIQUE] DESTINATAIRES ABSENTS
        // Cible : Acheteurs dont les roses ont été payées mais non distribuées (is_distributed = 0).
        // Objectif : Prévenir l'acheteur qu'il doit récupérer la fleur ou prévenir son destinataire.
        
        $sql = "SELECT 
                    u.email, u.nom as buyer_nom, u.prenom as buyer_prenom,
                    r.nom as dest_nom, r.prenom as dest_prenom
                FROM orders o
                JOIN users u ON o.user_id = u.user_id
                JOIN order_recipients ort ON o.id = ort.order_id
                JOIN recipients r ON ort.recipient_id = r.id
                WHERE o.is_paid = 1 
                AND ort.is_distributed = 0";
        
        $rawResults = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        
        // Logique de regroupement :
        // Un acheteur peut avoir commandé pour plusieurs personnes absentes.
        // Nous regroupons les destinataires par adresse email de l'acheteur pour n'envoyer qu'un seul mail.
        $groupedBuyers = [];
        foreach ($rawResults as $row) {
            $email = $row['email'];
            
            // Initialisation de l'acheteur s'il n'existe pas encore dans le tableau
            if (!isset($groupedBuyers[$email])) {
                $groupedBuyers[$email] = [
                    'email' => $email,
                    'nom' => $row['buyer_nom'],
                    'prenom' => $row['buyer_prenom'],
                    'absent_names' => []
                ];
            }
            
            // Ajout du destinataire à la liste (Dédoublonnage simple)
            $destFullName = $row['dest_prenom'] . ' ' . $row['dest_nom'];
            if (!in_array($destFullName, $groupedBuyers[$email]['absent_names'])) {
                $groupedBuyers[$email]['absent_names'][] = $destFullName;
            }
        }
        
        // Conversion en tableau indexé pour l'itération
        $recipients = array_values($groupedBuyers);
        $subject = "Information : Vos roses non distribuées 🌹";

    } else {
        // [CAMPAGNE MANUELLE] MESSAGE PERSONNALISÉ
        // Cible : Définie par le filtre (Payé, Impayé, Non Distribué, ou Tous).
        
        if ($targetFilter === 'undistributed') {
            // Filtre spécifique : Payé mais non distribué
            $sql = "SELECT DISTINCT u.email, u.nom, u.prenom 
                    FROM orders o
                    JOIN users u ON o.user_id = u.user_id
                    JOIN order_recipients ort ON o.id = ort.order_id
                    WHERE o.is_paid = 1 AND ort.is_distributed = 0
                    GROUP BY u.user_id";
        } else {
            // Filtres standards basés sur le statut de paiement
            $sql = "SELECT DISTINCT u.email, u.nom, u.prenom 
                    FROM orders o
                    JOIN users u ON o.user_id = u.user_id";
            
            if ($targetFilter === 'paid') $sql .= " WHERE o.is_paid = 1";
            elseif ($targetFilter === 'unpaid') $sql .= " WHERE o.is_paid = 0";
            
            $sql .= " GROUP BY u.user_id";
        }
        
        $recipients = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        $subject = $customSubject;
    }

    // --- 2. BOUCLE D'ENVOI ---
    $countSent = 0;
    $mail = getMailer(); 

    foreach ($recipients as $row) {
        $prenom = htmlspecialchars($row['prenom']);
        $nom = htmlspecialchars($row['nom']);
        $emailUser = $row['email'];

        // Validation basique de l'email
        if (empty($emailUser)) {
            $errorLog[] = "Introuvable : Pas d'adresse email pour <strong>$nom $prenom</strong>.";
            continue;
        }

        // --- CONSTRUCTION DU CORPS DU MESSAGE ---
        $innerContent = "";
        
        if ($mode === 'reminder') {
            // Template : Rappel de paiement
            $amount = number_format($row['total_due'], 2);
            $innerContent = "
                <p>Bonjour <strong>$prenom</strong>,</p>
                <p>DERNIER JOUR ⚠️ ! Sauf erreur de notre part, nous n'avons pas encore reçu le règlement de vos commandes. Le paiement s'arrête <strong>ce soir</strong> !</p>
                <div style='background-color: #fff0f6; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #d63384;'>
                    <h3 style='margin: 0; color: #d63384;'>Reste à payer : $amount €</h3>
                    <p style='margin: 5px 0 0 0; font-size: 0.9em;'>Veuillez régler au stand du CVL (Hall Vie Scolaire Bat E) aujourd'hui.</p>
                </div>
                <p>Sans paiement, les commandes seront annulées.</p>
            ";

        } elseif ($mode === 'absent_recipient') {
            // Template : Destinataire Absent
            // Formatage de la liste des noms (ex: "Tom et Léa")
            $namesList = implode(', ', $row['absent_names']);
            $lastIndex = strrpos($namesList, ', ');
            if ($lastIndex !== false) {
                $namesList = substr_replace($namesList, ' et ', $lastIndex, 2);
            }

            $innerContent = "
                <p>Bonjour <strong>$prenom</strong>,</p>
                <p>Nous n'avons pas pu distribuer vos roses à <strong>$namesList</strong> car ces personnes étaient absentes (ou introuvables) lors de notre passage.</p>
                
                <div style='background-color: #fff3cd; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #ffc107;'>
                    <h3 style='margin: 0; color: #856404; font-size: 1.1em;'>📍 Comment récupérer les fleurs ?</h3>
                    <p style='margin: 10px 0 0 0; color: #333;'>
                        Elles ont été déposées à la <strong>Vie Scolaire</strong>.
                    </p>
                    <ul style='margin-bottom:0;'>
                        <li>Soit vous allez les récupérer vous-même.</li>
                        <li>Soit vous prévenez votre/vos destinataire(s) d'aller les chercher.</li>
                    </ul>
                </div>
                
                <p><strong>⚠️ Attention :</strong> Les roses sont disponibles dès ce <strong>Lundi 16 février</strong>. Ne tardez pas trop à venir les chercher pour éviter qu'elles ne fanent !</p>
            ";

        } else {
            // Template : Message Personnalisé (Texte brut converti en HTML)
            $formattedBody = nl2br(htmlspecialchars($customBody));
            $innerContent = "
                <p>Bonjour <strong>$prenom</strong>,</p>
                <div style='font-size: 1.05em; line-height: 1.6;'>
                    $formattedBody
                </div>
            ";
        }

        // --- WRAPPER HTML GLOBAL (Design System) ---
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

        // --- ENVOI VIA SMTP ---
        try {
            $mail->clearAddresses();
            // Bascule vers l'email de test si le mode DEV est actif
            $destinataire = $TEST_MODE ? $TEST_EMAIL : $emailUser;
            
            $mail->addAddress($destinataire);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $emailHtml;

            $mail->send();
            $countSent++;

            // Throttling (Anti-spam) : Pause d'une seconde tous les 10 mails
            // Permet de ne pas saturer le serveur SMTP et d'éviter le blacklistage.
            if ($countSent % 10 == 0) { sleep(1); }

        } catch (Exception $e) {
            // Log de l'erreur sans arrêter le script pour les autres destinataires
            $errorLog[] = "Échec pour <strong>$nom $prenom</strong> ($emailUser) : " . $mail->ErrorInfo;
            
            // Réinitialisation de l'objet Mailer en cas de crash critique
            try { $mail = getMailer(); } catch (Exception $ex) {}
        }
    }
    
    // --- FEEDBACK UTILISATEUR ---
    // Construction du message de confirmation en fonction du contexte
    $targetName = "personnes";
    if ($mode === 'reminder') $targetName = "élèves en attente de paiement";
    elseif ($mode === 'absent_recipient') $targetName = "acheteurs avec destinataires absents";
    elseif ($targetFilter === 'paid') $targetName = "élèves ayant payé";
    elseif ($targetFilter === 'undistributed') $targetName = "élèves en attente de distribution";
    elseif ($targetFilter === 'all') $targetName = "tous les acheteurs";

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
            <div class="bg-white p-2 rounded text-danger" style="max-height: 200px; overflow-y: auto;">
                <ul class="mb-0 ps-3">
                    <?php foreach ($errorLog as $err): ?>
                        <li><?php echo $err; ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    <?php endif; ?>

    <div class="card shadow-sm border-0 mt-4">
        <div class="card-body p-4">
            <form method="POST" onsubmit="return confirm('Confirmer l\'envoi ?');">
                
                <div class="mb-4">
                    <label class="form-label fw-bold">Type de campagne</label>
                    <div class="d-flex flex-column gap-2">
                        <div class="btn-group w-100" role="group">
                            <input type="radio" class="btn-check" name="mode" id="modeReminder" value="reminder" onchange="toggleForm()">
                            <label class="btn btn-outline-danger" for="modeReminder">
                                <i class="fas fa-hand-holding-usd me-2"></i> Rappel Paiement
                            </label>

                            <input type="radio" class="btn-check" name="mode" id="modeCustom" value="custom" checked onchange="toggleForm()">
                            <label class="btn btn-outline-primary" for="modeCustom">
                                <i class="fas fa-pen-nib me-2"></i> Message Perso
                            </label>
                        </div>
                        
                        <div class="w-100">
                            <input type="radio" class="btn-check" name="mode" id="modeAbsent" value="absent_recipient" onchange="toggleForm()">
                            <label class="btn btn-outline-warning w-100" for="modeAbsent">
                                <i class="fas fa-user-clock me-2"></i> 🌹 Destinataires Absents (Retardataires)
                            </label>
                        </div>
                    </div>
                </div>

                <div id="reminderInfo" class="alert alert-light border" style="display:none;">
                    <h6 class="text-danger"><i class="fas fa-info-circle me-1"></i> Rappel automatique</h6>
                    <small class="text-muted">Envoie le montant restant dû à tous les élèves qui n'ont pas encore payé.</small>
                </div>

                <div id="absentInfo" class="alert alert-light border" style="display:none;">
                    <h6 class="text-warning"><i class="fas fa-info-circle me-1"></i> Notification post-distribution</h6>
                    <small class="text-muted">
                        Envoie un mail à l'ACHETEUR pour lui dire que son destinataire était absent.<br>
                        Le mail liste automatiquement les noms des absents concernés et indique d'aller à la Vie Scolaire.
                    </small>
                </div>

                <div id="customFields">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Qui doit recevoir ce message ?</label>
                        <select name="target_filter" class="form-select border-primary">
                            <option value="all">👥 Tout le monde (Tous ceux qui ont commandé)</option>
                            <option value="paid">✅ Uniquement ceux qui ont PAYÉ</option>
                            <option value="unpaid">⚠️ Uniquement ceux qui n'ont PAS PAYÉ</option>
                            <option value="undistributed">🚚 Non Distribués (Payés mais pas reçus)</option>
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
    /**
     * Gère l'affichage dynamique des formulaires selon le mode choisi.
     */
    function toggleForm() {
        const mode = document.querySelector('input[name="mode"]:checked').value;
        
        // Affichage des boîtes d'information contextuelles
        document.getElementById('reminderInfo').style.display = (mode === 'reminder') ? 'block' : 'none';
        document.getElementById('absentInfo').style.display = (mode === 'absent_recipient') ? 'block' : 'none';
        
        // Le formulaire personnalisé (Objet/Message/Filtre) n'est visible qu'en mode 'custom'
        document.getElementById('customFields').style.display = (mode === 'custom') ? 'block' : 'none';
    }
    
    // Initialisation au chargement de la page
    toggleForm();
</script>

</body>
</html>