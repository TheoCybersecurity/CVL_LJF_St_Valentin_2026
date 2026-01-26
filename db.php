<?php
require_once '/var/www/config/config_cvl_ljf_st_valentin_2026.php';
date_default_timezone_set('Europe/Paris');

try {
    $dsn = "mysql:host=" . DATABASE_HOST . ";dbname=" . DATABASE_NAME . ";charset=utf8mb4";
    $pdo = new PDO($dsn, DATABASE_USERNAME, DATABASE_PASSWORD);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $offset = date('P');
    $pdo->exec("SET time_zone = '$offset'");
} catch (PDOException $e) {
    die("Erreur de connexion BDD Projet : " . $e->getMessage());
}
?>