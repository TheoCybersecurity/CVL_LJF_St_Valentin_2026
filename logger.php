<?php
/**
 * Module de Journalisation (Audit Logger)
 * logger.php
 * * Ce service centralisé permet d'enregistrer les actions critiques des utilisateurs.
 * Il alimente la table 'audit_logs' pour assurer la traçabilité (Qui a fait quoi, quand et sur quoi),
 * facilitant ainsi le débogage technique et l'audit de sécurité.
 */

/**
 * Enregistre une entrée dans le journal d'audit.
 * * Cette fonction est conçue pour être "Fail-Safe" : une erreur lors de l'écriture du log
 * ne doit jamais interrompre l'exécution du script principal.
 *
 * @param int|null $userId Identifiant de l'utilisateur à l'origine de l'action.
 * @param string $targetType Type d'entité concernée (ex: 'order', 'recipient', 'config').
 * @param int $targetId Identifiant unique de l'entité concernée.
 * @param string $action Code de l'action effectuée (ex: 'CREATE', 'UPDATE', 'DELETE').
 * @param mixed $old Ancienne valeur (pour les modifications). Sera sérialisé en JSON.
 * @param mixed $new Nouvelle valeur. Sera sérialisé en JSON.
 * @param string|null $details Description textuelle complémentaire ou contexte.
 */
function logAction($userId, $targetType, $targetId, $action, $old = null, $new = null, $details = null) {
    global $pdo; // Utilisation de l'instance PDO globale de l'application

    try {
        // --- 1. Détection de l'adresse IP du client ---
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'Inconnue';

        // Gestion des infrastructures derrière un Proxy ou Load Balancer (ex: Nginx, Docker)
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            // La chaîne peut contenir plusieurs IPs (Client, Proxy1, Proxy2...). On extrait la première.
            $ipList = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $ipAddress = trim($ipList[0]);
        } elseif (!empty($_SERVER['HTTP_X_REAL_IP'])) {
            // Header standard pour Nginx
            $ipAddress = $_SERVER['HTTP_X_REAL_IP'];
        }
        
        // Troncature pour respecter la taille de la colonne BDD (VARCHAR 45 pour IPv6)
        $ipAddress = substr($ipAddress, 0, 45);
        
        // Récupération de la signature du navigateur (User Agent)
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'UNKNOWN';
        
        // --- 2. Sérialisation des données ---
        // Conversion automatique des tableaux/objets en JSON pour le stockage en texte
        $oldJson = (is_array($old) || is_object($old)) ? json_encode($old, JSON_UNESCAPED_UNICODE) : $old;
        $newJson = (is_array($new) || is_object($new)) ? json_encode($new, JSON_UNESCAPED_UNICODE) : $new;

        $now = date('Y-m-d H:i:s');

        // --- 3. Persistance ---
        $stmt = $pdo->prepare("
            INSERT INTO audit_logs 
            (user_id, target_type, target_id, action, old_value, new_value, details, ip_address, user_agent, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $userId, 
            $targetType, 
            $targetId, 
            $action, 
            $oldJson, 
            $newJson, 
            $details, 
            $ipAddress,
            $userAgent,
            $now
        ]);

    } catch (Exception $e) {
        // --- 4. Gestion d'erreur silencieuse (Fail-Safe) ---
        // Si le log échoue (ex: BDD pleine), on écrit dans le log erreur du serveur
        // mais on ne lève pas d'exception pour ne pas bloquer l'expérience utilisateur.
        error_log("Erreur Logger SQL : " . $e->getMessage());
    }
}
?>