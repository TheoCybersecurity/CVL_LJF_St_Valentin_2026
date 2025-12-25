<?php
// api/submit_order.php

// 1. Configuration
header('Content-Type: application/json');
ini_set('display_errors', 0); // On cache les erreurs HTML pour ne pas casser le JSON
error_reporting(E_ALL);

// 2. Démarrage session & Base de données
session_start();

// Chemin vers db.php (ajuster si nécessaire selon ton arborescence)
require_once '../db.php'; 

function sendError($msg) {
    http_response_code(400); 
    echo json_encode(['success' => false, 'message' => $msg]);
    exit;
}

// -----------------------------------------------------------
// 3. LOGIQUE D'IDENTIFICATION SIMPLIFIÉE
// -----------------------------------------------------------
// Au lieu d'utiliser la librairie JWT qui manque, on regarde la session PHP.
// Si l'utilisateur est connecté, index.php/auth_check.php a dû définir $_SESSION['user_id'].

$current_user_id = null;

if (isset($_SESSION['user_id'])) {
    $current_user_id = $_SESSION['user_id'];
}
// Si pas de session user_id, on considère que c'est un invité ($current_user_id reste null)


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
    // A. Mise à jour User Local (SI CONNECTÉ)
    // ------------------------------------------------------------------
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
    
    // Calcul du prix total côté serveur (sécurité)
    $products = $pdo->query("SELECT id, price FROM rose_products")->fetchAll(PDO::FETCH_KEY_PAIR);
    
    $totalOrder = 0;
    foreach ($cart as $item) {
        foreach ($item['roses'] as $r) {
            if (isset($products[$r['id']])) {
                $totalOrder += $products[$r['id']] * $r['qty'];
            }
        }
    }

    // Insertion Commande
    $stmtOrder = $pdo->prepare("
        INSERT INTO orders (user_id, buyer_nom, buyer_prenom, buyer_class_id, total_price) 
        VALUES (?, ?, ?, ?, ?)
    ");
    
    $stmtOrder->execute([
        $current_user_id, // NULL ou ID
        $buyerNom,       
        $buyerPrenom,     
        $buyerClassId,    
        $totalOrder       
    ]);
    
    $orderId = $pdo->lastInsertId();

    // ------------------------------------------------------------------
    // C. Destinataires
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
    error_log("ERREUR SQL ST VALENTIN : " . $e->getMessage());
    sendError('Erreur technique : ' . $e->getMessage());
}
?>