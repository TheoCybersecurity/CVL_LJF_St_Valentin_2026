<?php
// logger.php

/**
 * Enregistre une action dans la table audit_logs
 * * @param int|null $userId ID de l'utilisateur qui fait l'action
 * @param string $targetType Type d'objet (order, recipient...)
 * @param int $targetId ID de l'objet
 * @param string $action Nom de l'action (ex: ORDER_CREATED)
 * @param mixed $old Valeur précédente (sera converti en JSON)
 * @param mixed $new Nouvelle valeur (sera converti en JSON)
 * @param string|null $details Détails texte optionnels
 */
function logAction($userId, $targetType, $targetId, $action, $old = null, $new = null, $details = null) {
    global $pdo; // On récupère l'instance PDO globale

    try {
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'Inconnue';

        // Si on est derrière un proxy (Docker, Nginx, etc.), on regarde les headers forwarded
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            // Cette valeur peut être une liste d'IPs (ex: client, proxy1, proxy2). On prend la première.
            $ipList = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $ipAddress = trim($ipList[0]);
        } elseif (!empty($_SERVER['HTTP_X_REAL_IP'])) {
            // Alternative souvent utilisée par Nginx
            $ipAddress = $_SERVER['HTTP_X_REAL_IP'];
        }
        $ipAddress = substr($ipAddress, 0, 45);
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'UNKNOWN';
        
        // Conversion automatique en JSON si c'est un tableau ou un objet
        $oldJson = (is_array($old) || is_object($old)) ? json_encode($old, JSON_UNESCAPED_UNICODE) : $old;
        $newJson = (is_array($new) || is_object($new)) ? json_encode($new, JSON_UNESCAPED_UNICODE) : $new;

        $stmt = $pdo->prepare("
            INSERT INTO audit_logs 
            (user_id, target_type, target_id, action, old_value, new_value, details, ip_address, user_agent, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
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
            $userAgent
        ]);

    } catch (Exception $e) {
        // IMPORTANT : Si le log plante, on ne veut pas faire planter toute l'application
        // On écrit juste l'erreur dans le fichier error_log du serveur
        error_log("Erreur Logger SQL : " . $e->getMessage());
    }
}
?>