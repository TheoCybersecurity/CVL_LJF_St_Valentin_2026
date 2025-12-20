<?php
// api/submit_order.php

// 1. Entêtes API (JSON)
header('Content-Type: application/json');
ini_set('display_errors', 0); // On cache les erreurs HTML pour ne pas casser le JSON
error_reporting(E_ALL);

// 2. Inclusions
require_once '/var/www/config/config.php';
require_once '../db.php'; 
// NOTE : On n'inclut PAS auth_check.php ici car il redirigerait les invités.

// Fonction d'erreur rapide
function sendError($msg) {
    http_response_code(400); // Bad Request
    echo json_encode(['success' => false, 'message' => $msg]);
    exit;
}

// -----------------------------------------------------------
// 3. LOGIQUE D'IDENTIFICATION (Invité vs Connecté)
// -----------------------------------------------------------
$current_user_id = null;

// On regarde si un cookie JWT est présent (comme dans auth_check)
if (isset($_COOKIE['jwt'])) {
    try {
        // On décode manuellement pour récupérer l'ID sans rediriger
        $decoded = \Firebase\JWT\JWT::decode($_COOKIE['jwt'], new \Firebase\JWT\Key(JWT_SECRET, 'HS256'));
        
        // On récupère l'ID s'il est dans le token. 
        // Note : Adapte 'id' selon comment tu l'as stocké dans register.php (ex: $decoded->user->id ou $decoded->sub)
        // Si tu ne l'as pas stocké, on fera une requête SQL plus bas.
        if (isset($decoded->user->id)) {
            $current_user_id = $decoded->user->id;
        } else {
            // Fallback : On cherche l'ID via le pseudo du token dans la base principale
            // Attention : il faut que l'user 'web' ait le droit SELECT sur Projets.users
            // Si c'est trop compliqué, assure-toi juste d'ajouter l'ID dans le payload du token dans register.php
            // Pour l'instant, on suppose que le token est valide.
        }
        
        // Si vraiment on ne trouve pas l'ID dans le token, on peut utiliser $_SESSION['user_id'] si dispo
        if (!$current_user_id && isset($_SESSION['user_id'])) {
            $current_user_id = $_SESSION['user_id'];
        }

    } catch (Exception $e) {
        // Token invalide ou expiré : On considère que c'est un invité
        $current_user_id = null;
    }
}

// -----------------------------------------------------------
// 4. RÉCUPÉRATION DES DONNÉES
// -----------------------------------------------------------
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);

if (!$input) { 
    sendError('Données JSON invalides ou vides.');
}

$buyerNom = trim($input['buyerNom'] ?? '');
$buyerPrenom = trim($input['buyerPrenom'] ?? '');
$buyerClassId = intval($input['buyerClassId'] ?? 0);
$cart = $input['cart'] ?? [];

if (empty($buyerNom) || empty($buyerPrenom) || $buyerClassId === 0 || empty($cart)) {
    sendError('Informations incomplètes (Acheteur ou Panier vide).');
}

try {
    $pdo->beginTransaction();

    // ------------------------------------------------------------------
    // A. Mise à jour User Local (UNIQUEMENT SI CONNECTÉ)
    // ------------------------------------------------------------------
    // Un invité n'a pas d'ID, donc on ne peut pas l'insérer dans project_users
    if ($current_user_id) {
        $stmtUser = $pdo->prepare("
            INSERT INTO project_users (user_id, nom, prenom, class_id) 
            VALUES (:id, :n, :p, :c) 
            ON DUPLICATE KEY UPDATE nom=:n, prenom=:p, class_id=:c
        ");
        $stmtUser->execute([
            ':id' => $current_user_id,
            ':n' => $buyerNom,
            ':p' => $buyerPrenom,
            ':c' => $buyerClassId
        ]);
    }

    // ------------------------------------------------------------------
    // B. Création de la commande
    // ------------------------------------------------------------------
    // 1. Calcul du total
    $products = $pdo->query("SELECT id, price FROM rose_products")->fetchAll(PDO::FETCH_KEY_PAIR);
    
    $totalOrder = 0;
    foreach ($cart as $item) {
        foreach ($item['roses'] as $r) {
            if (isset($products[$r['id']])) {
                $totalOrder += $products[$r['id']] * $r['qty'];
            }
        }
    }

    // 2. Insertion Commande
    // IMPORTANT : Si $current_user_id est null (invité), la colonne buyer_id doit accepter NULL en BDD
    $stmtOrder = $pdo->prepare("
        INSERT INTO orders (buyer_id, buyer_nom, buyer_prenom, buyer_class_id, total_price) 
        VALUES (?, ?, ?, ?, ?)
    ");
    
    $stmtOrder->execute([
        $current_user_id, // Sera NULL si invité, ou l'ID si connecté
        $buyerNom,       
        $buyerPrenom,     
        $buyerClassId,    
        $totalOrder       
    ]);
    
    $orderId = $pdo->lastInsertId();

    // ------------------------------------------------------------------
    // C. Destinataires (Code inchangé)
    // ------------------------------------------------------------------
    $stmtDest = $pdo->prepare("INSERT INTO order_recipients (order_id, dest_nom, dest_prenom, class_id, message_id, is_anonymous) VALUES (?, ?, ?, ?, ?, ?)");
    $stmtRose = $pdo->prepare("INSERT INTO recipient_roses (recipient_id, rose_product_id, quantity) VALUES (?,?,?)");
    $stmtSchedule = $pdo->prepare("INSERT INTO recipient_schedules (recipient_id, hour_slot, room_id) VALUES (?,?,?)");

    foreach ($cart as $dest) {
        $msgId = !empty($dest['messageId']) ? $dest['messageId'] : null;
        
        $stmtDest->execute([
            $orderId, 
            $dest['nom'], 
            $dest['prenom'], 
            $dest['classId'], 
            $msgId, 
            $dest['isAnonymous'] ? 1 : 0
        ]);
        $destId = $pdo->lastInsertId();

        // Roses
        foreach ($dest['roses'] as $rose) {
            if ($rose['qty'] > 0) {
                $stmtRose->execute([$destId, $rose['id'], $rose['qty']]);
            }
        }

        // Emploi du temps
        if (isset($dest['schedule']) && is_array($dest['schedule'])) {
            foreach ($dest['schedule'] as $slot) {
                $stmtSchedule->execute([
                    $destId,
                    $slot['hour'],   
                    $slot['roomId']  
                ]);
            }
        }
    }

    $pdo->commit();
    echo json_encode(['success' => true, 'orderId' => $orderId]);

} catch (Exception $e) {
    $pdo->rollBack();
    // On log l'erreur serveur pour toi, mais on renvoie un message générique au client
    error_log("ERREUR SQL ST VALENTIN : " . $e->getMessage());
    sendError('Erreur lors de l\'enregistrement : ' . $e->getMessage());
}
?>