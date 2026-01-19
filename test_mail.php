<?php
// On inclut les fichiers de la librairie PHPMailer qu'on vient d'installer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

require 'PHPMailer/Exception.php';
require 'PHPMailer/PHPMailer.php';
require 'PHPMailer/SMTP.php';

$messageStatus = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_email'])) {
    $emailDestinataire = $_POST['test_email'];

    // Création de l'instance PHPMailer
    $mail = new PHPMailer(true);

    try {
        // --- 1. CONFIGURATION DU SERVEUR (OVH) ---
        // $mail->SMTPDebug = SMTP::DEBUG_SERVER; // Décommenter pour voir les logs techniques si ça plante
        $mail->isSMTP();                                            
        $mail->Host       = 'ssl0.ovh.net';                     // Serveur SMTP OVH
        $mail->SMTPAuth   = true;                                   
        $mail->Username   = 'noreply-stvalentin@marescal.fr';   // Ton adresse complète
        $mail->Password   = 'jamci0-pitpIq-nonguf';             // <--- METTRE LE MOT DE PASSE ICI
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;        // Chiffrement SSL
        $mail->Port       = 465;                                // Port TCP pour SSL
        $mail->CharSet    = 'UTF-8';                            // Pour les accents

        // --- 2. DESTINATAIRES ---
        $mail->setFrom('noreply-stvalentin@marescal.fr', 'Saint Valentin CVL'); // L'expéditeur (Nom affiché)
        $mail->addAddress($emailDestinataire);                  // Le destinataire entré dans le formulaire
        $mail->addReplyTo('noreply-stvalentin@marescal.fr', 'Pas de réponse');

        // --- 3. CONTENU DU MAIL ---
        $mail->isHTML(true);                                  
        $mail->Subject = 'Test technique - Saint Valentin';
        $mail->Body    = '
            <div style="font-family: Arial, sans-serif; padding: 20px; border: 1px solid #ddd; border-radius: 10px;">
                <h2 style="color: #d63384;">Ceci est un test ! 🌹</h2>
                <p>Si vous recevez ce mail, c\'est que la configuration <strong>SMTP OVH</strong> fonctionne parfaitement.</p>
                <p>Bonne journée,<br>L\'équipe technique.</p>
            </div>
        ';
        $mail->AltBody = 'Ceci est un test. Si vous lisez ça, le HTML ne marche pas, mais le mail est arrivé.';

        $mail->send();
        $messageStatus = '<div class="alert alert-success">✅ Mail envoyé avec succès à <strong>' . htmlspecialchars($emailDestinataire) . '</strong> ! Vérifie tes spams au cas où.</div>';
    } catch (Exception $e) {
        $messageStatus = '<div class="alert alert-danger">❌ Le message n\'a pas pu être envoyé. Erreur Mailer: ' . $mail->ErrorInfo . '</div>';
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test Envoi Mail</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light d-flex align-items-center justify-content-center" style="height: 100vh;">

    <div class="card shadow p-4" style="max-width: 500px; width: 100%;">
        <h3 class="text-center mb-4">Test Serveur Mail 📧</h3>
        
        <?php echo $messageStatus; ?>

        <form method="POST">
            <div class="mb-3">
                <label for="email" class="form-label">Envoyer un test à :</label>
                <input type="email" class="form-control" name="test_email" id="email" placeholder="theo@marescal.fr" required>
            </div>
            <button type="submit" class="btn btn-primary w-100">Envoyer le test 🚀</button>
        </form>
        
        <div class="mt-3 text-muted small text-center">
            Serveur: ssl0.ovh.net | Port: 465 | User: noreply-stvalentin@marescal.fr
        </div>
    </div>

</body>
</html>