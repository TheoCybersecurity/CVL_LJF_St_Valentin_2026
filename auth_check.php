<?php
/**
 * Système d'Authentification et de Gestion de Session
 * auth_check.php
 * * Ce script gère le cycle de vie de la connexion utilisateur :
 * 1. Vérification du cookie JWT (SSO).
 * 2. Validation des droits d'accès sur la base de données centrale.
 * 3. Synchronisation avec la base de données locale du projet.
 * 4. Gestion des rôles (RBAC) pour l'accès aux pages protégées.
 */

// Initialisation de la session et chargement des dépendances
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '/var/www/config/config.php'; 
require_once '/var/www/config/config_cvl_ljf_st_valentin_2026.php'; 
require '/var/www/config/vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

$project_name = 'CVL_LJF_St_Valentin_2026';

/**
 * Gestion de la déconnexion forcée.
 * Nettoie la session et redirige vers la page de transition en cas d'échec d'authentification.
 */
function triggerLogoutAndExit() {
    session_unset();     // Nettoyage des variables de session
    session_destroy();   // Destruction de la session serveur
    global $project_name;
    include('/var/www/html/common_errors/transition.php');
    exit;
}

// --- 1. Validation du Jeton d'Authentification (JWT) ---
if (!isset($_COOKIE['jwt'])) {
    require_once '/var/www/html/shared/php/auto_refresh.php';
    if (!tryRefreshToken()) {
        triggerLogoutAndExit();
    }
}

try {
    $decoded = JWT::decode($_COOKIE['jwt'], new Key(JWT_SECRET, 'HS256'));
    $username_from_jwt = $decoded->user->name;
} catch (\Firebase\JWT\ExpiredException $e) {
    require_once '/var/www/html/shared/php/auto_refresh.php';
    if (!tryRefreshToken()) {
        triggerLogoutAndExit();
    }
    $decoded = JWT::decode($_COOKIE['jwt'], new Key(JWT_SECRET, 'HS256'));
    $username_from_jwt = $decoded->user->name;
} catch (Exception $e) {
    triggerLogoutAndExit();
}

// --- 2. Vérification des droits via l'API interne (SSO) ---
// Remplace la connexion directe à la base centrale — voir
// shared/php/auth_api_client.php.
require_once '/var/www/html/shared/php/auth_api_client.php';
$access_col = ACCESS_COLUMN;
$row = authCheckAccess($username_from_jwt, authCurrentLogContext());

if (!$row) {
    triggerLogoutAndExit(); // Utilisateur introuvable dans le référentiel central
}

// Vérification de l'indicateur d'accès spécifique à ce projet
if ((int)($row['permissions'][$access_col] ?? 0) !== 1) {
    http_response_code(403);
    include('/var/www/html/common_errors/error403.php');
    exit;
}
// Récupération de l'ID Global unique
$current_user_id = $row['id'];
$_SESSION['user_id'] = $current_user_id;

// --- 3. Synchronisation avec la Base de Données Locale ---
try {
    $dsn = "mysql:host=" . DATABASE_HOST . ";dbname=" . DATABASE_NAME . ";charset=utf8mb4";
    $pdo_local = new PDO($dsn, DATABASE_USERNAME, DATABASE_PASSWORD);
    $pdo_local->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdo = $pdo_local; 

    // Récupération des métadonnées locales (Identité, Classe)
    $stmtLocal = $pdo_local->prepare("SELECT nom, prenom, class_id FROM users WHERE user_id = ?");
    $stmtLocal->execute([$current_user_id]);
    $localUser = $stmtLocal->fetch(PDO::FETCH_ASSOC);

    if ($localUser) {
        $current_user_nom = $localUser['nom'];
        $current_user_prenom = $localUser['prenom'];
        $current_user_classe = $localUser['class_id']; 
        
        // Persistance en session pour éviter des requêtes répétitives
        $_SESSION['prenom'] = $current_user_prenom;
        $_SESSION['nom'] = $current_user_nom;
    } else {
        $current_user_nom = "";
        $current_user_prenom = "";
        $current_user_classe = "";
    }
} catch (PDOException $e) {
    // Gestion silencieuse : l'utilisateur est authentifié mais sans profil local complet
    $current_user_nom = ""; $current_user_prenom = ""; $current_user_classe = "";
}

/**
 * Vérification des Rôles (RBAC).
 * Contrôle si l'utilisateur possède le niveau d'accréditation requis (Membre ou Admin).
 * * @param string $requiredRole Le rôle minimum requis ('cvl' ou 'admin').
 * @return string Le rôle actuel de l'utilisateur.
 */
function checkAccess($requiredRole = 'cvl') {
    global $pdo_local, $current_user_id;

    if (!isset($pdo_local)) die("Erreur de connexion BDD Locale");

    // Vérification de l'appartenance à l'équipe organisatrice
    $stmt = $pdo_local->prepare("SELECT role FROM cvl_members WHERE user_id = ?");
    $stmt->execute([$current_user_id]);
    $member = $stmt->fetch(PDO::FETCH_ASSOC);

    // Si non-membre, redirection vers l'accueil public
    if (!$member) {
        header("Location: index.php"); 
        exit;
    }

    $userRole = $member['role'];

    // Si un rôle 'admin' est requis mais que l'utilisateur n'est que membre
    if ($requiredRole === 'admin' && $userRole !== 'admin') {
        header("Location: admin.php"); 
        exit;
    }
    return $userRole; 
}

// Journalisation d'accès désormais faite en un seul aller-retour par
// authCheckAccess() ci-dessus (voir shared/php/auth_api_client.php).

?>
