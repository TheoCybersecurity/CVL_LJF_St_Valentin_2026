<?php
/**
 * Initialisation de la Base de Données
 * * Ce fichier établit la connexion à la base de données MySQL via l'interface PDO.
 * Il configure l'environnement (Timezone), les modes d'erreur et l'encodage par défaut.
 * Ce fichier doit être inclus dans tous les scripts nécessitant un accès aux données.
 */

// Chargement de la configuration externe (Contient les constantes DATABASE_HOST, DATABASE_USER, etc.)
require_once '/var/www/config/config_cvl_ljf_st_valentin_2026.php';

// Définition du fuseau horaire de référence pour l'application PHP
date_default_timezone_set('Europe/Paris');

try {
    // Construction de la chaîne de connexion (DSN) avec encodage UTF-8 mb4
    $dsn = "mysql:host=" . DATABASE_HOST . ";dbname=" . DATABASE_NAME . ";charset=utf8mb4";
    
    // Instanciation de l'objet PDO
    $pdo = new PDO($dsn, DATABASE_USERNAME, DATABASE_PASSWORD);
    
    // Configuration des attributs PDO
    // Mode d'erreur : Exception (Permet de capturer les erreurs SQL via try/catch)
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // Mode de récupération : Tableau associatif (Clé = Nom colonne)
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    
    // Synchronisation du fuseau horaire de la session MySQL avec celui de PHP
    // Garantit que les fonctions SQL comme NOW() retournent la même heure que date() en PHP
    $offset = date('P');
    $pdo->exec("SET time_zone = '$offset'");

} catch (PDOException $e) {
    // Arrêt critique du script si la base de données est inaccessible
    die("Erreur critique de connexion à la base de données : " . $e->getMessage());
}
?>