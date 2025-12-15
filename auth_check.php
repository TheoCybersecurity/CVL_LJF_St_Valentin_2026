<?php
require_once '/var/www/config/config.php'; 
require_once '/var/www/config/config_cvl_ljf_st_valentin_2026.php'; 
require '/var/www/config/vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

function showTransitionPage() {
    include('/var/www/html/common_errors/transition.php');
    exit;
}

if (!isset($_COOKIE['jwt'])) {
    showTransitionPage();
}

try {
    $decoded = JWT::decode($_COOKIE['jwt'], new Key(JWT_SECRET, 'HS256'));
    $username_from_jwt = $decoded->user->name;
} catch (Exception $e) {
    showTransitionPage();
}

// 1. Connexion BDD Projets (Vérif Accès)
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
} else {
    showTransitionPage();
}
$stmt->close();
$conn_projets->close();

// 2. Connexion BDD Projet Local (Récup infos persos)
try {
    $dsn = "mysql:host=" . DATABASE_HOST . ";dbname=" . DATABASE_NAME . ";charset=utf8mb4";
    $pdo_local = new PDO($dsn, DATABASE_USERNAME, DATABASE_PASSWORD);
    $pdo_local->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmtLocal = $pdo_local->prepare("SELECT nom, prenom, classe FROM project_users WHERE user_id = ?");
    $stmtLocal->execute([$current_user_id]);
    $localUser = $stmtLocal->fetch(PDO::FETCH_ASSOC);

    // Pré-remplissage des variables
    if ($localUser) {
        $current_user_nom = $localUser['nom'];
        $current_user_prenom = $localUser['prenom'];
        $current_user_classe = $localUser['classe'];
    } else {
        $current_user_nom = "";
        $current_user_prenom = "";
        $current_user_classe = "";
    }
} catch (PDOException $e) {
    // Si la table n'est pas encore prête, on évite le crash
    $current_user_nom = ""; $current_user_prenom = ""; $current_user_classe = "";
}
?>