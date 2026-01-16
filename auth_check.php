<?php
// auth_check.php

// 0. Démarrage session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '/var/www/config/config.php'; 
require_once '/var/www/config/config_cvl_ljf_st_valentin_2026.php'; 
require '/var/www/config/vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

$project_name = 'CVL_LJF_St_Valentin_2026';

// --- FONCTION DE NETTOYAGE ---
// Si l'auth échoue, on nettoie TOUT avant de montrer la page de transition
function triggerLogoutAndExit() {
    session_unset();     // Vide les variables $_SESSION
    session_destroy();   // Détruit le fichier de session côté serveur
    global $project_name;
    include('/var/www/html/common_errors/transition.php');
    exit;
}

// 1. Vérif présence Cookie
if (!isset($_COOKIE['jwt'])) {
    triggerLogoutAndExit(); // On nettoie la session ici !
}

try {
    $decoded = JWT::decode($_COOKIE['jwt'], new Key(JWT_SECRET, 'HS256'));
    $username_from_jwt = $decoded->user->name;
} catch (Exception $e) {
    triggerLogoutAndExit(); // Et ici aussi !
}

// 2. Connexion BDD Projets (Vérif Accès Global)
$conn_projets = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn_projets->connect_error) die("Erreur BDD Projets");

$access_col = ACCESS_COLUMN; 
$stmt = $conn_projets->prepare("SELECT id, $access_col FROM users WHERE user = ?");
$stmt->bind_param("s", $username_from_jwt);
$stmt->execute();
$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {
    if ($row[$access_col] != 1) {
        http_response_code(403);
        include('/var/www/html/common_errors/error403.php');
        exit;
    }
    // ID GLOBAL
    $current_user_id = $row['id'];
    $_SESSION['user_id'] = $current_user_id; // On met à jour la session
    
} else {
    $stmt->close();
    $conn_projets->close();
    triggerLogoutAndExit(); // User inconnu -> Dehors
}
$stmt->close();
$conn_projets->close();

// 3. Connexion BDD Projet Local
try {
    $dsn = "mysql:host=" . DATABASE_HOST . ";dbname=" . DATABASE_NAME . ";charset=utf8mb4";
    $pdo_local = new PDO($dsn, DATABASE_USERNAME, DATABASE_PASSWORD);
    $pdo_local->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdo = $pdo_local; 

    // On récupère class_id
    $stmtLocal = $pdo_local->prepare("SELECT nom, prenom, class_id FROM users WHERE user_id = ?");
    $stmtLocal->execute([$current_user_id]);
    $localUser = $stmtLocal->fetch(PDO::FETCH_ASSOC);

    if ($localUser) {
        $current_user_nom = $localUser['nom'];
        $current_user_prenom = $localUser['prenom'];
        $current_user_classe = $localUser['class_id']; 
        
        // Mise à jour des infos de session
        $_SESSION['prenom'] = $current_user_prenom;
        $_SESSION['nom'] = $current_user_nom;
    } else {
        $current_user_nom = "";
        $current_user_prenom = "";
        $current_user_classe = "";
    }
} catch (PDOException $e) {
    $current_user_nom = ""; $current_user_prenom = ""; $current_user_classe = "";
}

// 4. Fonction CheckAccess (Rien ne change ici)
function checkAccess($requiredRole = 'cvl') {
    global $pdo_local, $current_user_id;

    if (!isset($pdo_local)) die("Erreur DB Local");

    $stmt = $pdo_local->prepare("SELECT role FROM cvl_members WHERE user_id = ?");
    $stmt->execute([$current_user_id]);
    $member = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$member) {
        header("Location: index.php"); 
        exit;
    }

    $userRole = $member['role'];

    if ($requiredRole === 'admin' && $userRole !== 'admin') {
        header("Location: admin.php"); 
        exit;
    }
    return $userRole; 
}
?>