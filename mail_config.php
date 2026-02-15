<?php
/**
 * Configuration du Service de Messagerie (SMTP)
 * mail_config.php
 * * Ce module centralise l'instanciation et le paramétrage de la librairie PHPMailer.
 * Il fournit une méthode factory pour obtenir une instance pré-configurée
 * avec les paramètres du serveur SMTP (OVH) utilisés pour les emails transactionnels.
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Chargement de la configuration globale (incluant la constante SMTP_PASSWORD)
require_once '/var/www/config/config_cvl_ljf_st_valentin_2026.php';

// Importation manuelle des dépendances de la librairie PHPMailer.
// L'utilisation de __DIR__ garantit la résolution correcte des chemins absolus quel que soit le script appelant.
require_once __DIR__ . '/PHPMailer/Exception.php';
require_once __DIR__ . '/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/SMTP.php';

/**
 * Instancie et configure un objet PHPMailer prêt à l'emploi.
 *
 * @return PHPMailer L'objet mailer configuré (SMTP Auth, SSL, Encodage UTF-8).
 */
function getMailer() {
    $mail = new PHPMailer(true);
    
    // Paramétrage du serveur SMTP sortant (OVH)
    $mail->isSMTP();
    $mail->Host       = 'ssl0.ovh.net';
    $mail->SMTPAuth   = true;
    $mail->Username   = 'noreply-stvalentin@marescal.fr';
    $mail->Password   = SMTP_PASSWORD; // Constante définie dans config_cvl_ljf_st_valentin_2026.php
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    $mail->Port       = 465;
    $mail->CharSet    = 'UTF-8';

    // Définition de l'identité de l'expéditeur par défaut
    $mail->setFrom('noreply-stvalentin@marescal.fr', 'Saint Valentin CVL');
    
    return $mail;
}
?>