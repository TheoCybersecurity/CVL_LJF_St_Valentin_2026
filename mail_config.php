<?php
// mail_config.php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once '/var/www/config/config_cvl_ljf_st_valentin_2026.php';

// Utilisation de __DIR__ pour être sûr de trouver le dossier peu importe où ce fichier est inclus
// ATTENTION : Si votre dossier PHPMailer contient un sous-dossier "src", ajoutez /src/ dans les chemins ci-dessous
require_once __DIR__ . '/PHPMailer/Exception.php';
require_once __DIR__ . '/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/SMTP.php';

function getMailer() {
    $mail = new PHPMailer(true);
    
    // Configuration du serveur SMTP
    $mail->isSMTP();
    $mail->Host       = 'ssl0.ovh.net';
    $mail->SMTPAuth   = true;
    $mail->Username   = 'noreply-stvalentin@marescal.fr';
    $mail->Password   = SMTP_PASSWORD;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    $mail->Port       = 465;
    $mail->CharSet    = 'UTF-8';

    // Expéditeur par défaut
    $mail->setFrom('noreply-stvalentin@marescal.fr', 'Saint Valentin CVL');
    
    return $mail;
}
?>