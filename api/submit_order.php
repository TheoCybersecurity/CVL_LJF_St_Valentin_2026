<?php
// api/submit_order.php

// 1. Configuration
header('Content-Type: application/json');
ini_set('display_errors', 0);
error_reporting(E_ALL);

session_start();
require_once '../db.php'; 

function sendError($msg) {
    http_response_code(400); 
    echo json_encode(['success' => false, 'message' => $msg]);
    exit;
}

$current_user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;

// 2. Récupération des données
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);

if (!$input) sendError('Données JSON invalides.');

$buyerNom = trim($input['buyerNom'] ?? '');
$buyerPrenom = trim($input['buyerPrenom'] ?? '');
$buyerClassId = intval($input['buyerClassId'] ?? 0);
$cart = $input['cart'] ?? [];

if (empty($buyerNom) || empty($buyerPrenom) || $buyerClassId === 0 || empty($cart)) {
    sendError('Informations incomplètes.');
}

try {
    $pdo->beginTransaction();

    // A. Mise à jour User (si connecté)
    if ($current_user_id) {
        $stmtUser = $pdo->prepare("INSERT INTO project_users (user_id, nom, prenom, class_id) VALUES (:id, :n, :p, :c) ON DUPLICATE KEY UPDATE nom=:n, prenom=:p, class_id=:c");
        $stmtUser->execute([':id' => $current_user_id, ':n' => $buyerNom, ':p' => $buyerPrenom, ':c' => $buyerClassId]);
    }

    // ------------------------------------------------------------------
    // B. CALCUL DU PRIX TOTAL (LOGIQUE GLOBALE)
    // ------------------------------------------------------------------
    
    // 1. Récupération de la grille tarifaire
    $pricesStmt = $pdo->query("SELECT quantity, price FROM roses_prices");
    $priceList = $pricesStmt->fetchAll(PDO::FETCH_KEY_PAIR); // [1 => 2.00, 2 => 4.00, ...]

    // 2. Compter le nombre TOTAL de roses dans TOUTE la commande
    $grandTotalRoses = 0;
    $validProductIds = $pdo->query("SELECT id FROM rose_products")->fetchAll(PDO::FETCH_COLUMN);

    foreach ($cart as $recipient) {
        foreach ($recipient['roses'] as $r) {
            if (in_array($r['id'], $validProductIds)) {
                $qty = intval($r['qty']);
                if ($qty > 0) {
                    $grandTotalRoses += $qty;
                }
            }
        }
    }

    // 3. Appliquer le prix sur le volume total
    $totalOrderPrice = 0.0;
    if ($grandTotalRoses > 0) {
        if (isset($priceList[$grandTotalRoses])) {
            $totalOrderPrice = floatval($priceList[$grandTotalRoses]);
        } else {
            // Fallback si quantité hors grille (ex: prix max + 2€ par rose supp)
            $maxQty = max(array_keys($priceList));
            $maxPrice = floatval($priceList[$maxQty]);
            $diff = $grandTotalRoses - $maxQty;
            $totalOrderPrice = $maxPrice + ($diff * 2.00); 
        }
    }

    // C. Création de la commande
    $stmtOrder = $pdo->prepare("INSERT INTO orders (user_id, buyer_nom, buyer_prenom, buyer_class_id, total_price, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
    $stmtOrder->execute([$current_user_id, $buyerNom, $buyerPrenom, $buyerClassId, $totalOrderPrice]);
    $orderId = $pdo->lastInsertId();

    // D. Insertion des Destinataires
    $stmtDest = $pdo->prepare("INSERT INTO order_recipients (order_id, dest_nom, dest_prenom, class_id, message_id, is_anonymous) VALUES (?, ?, ?, ?, ?, ?)");
    $stmtRose = $pdo->prepare("INSERT INTO recipient_roses (recipient_id, rose_product_id, quantity) VALUES (?,?,?)");
    $stmtSchedule = $pdo->prepare("INSERT INTO recipient_schedules (recipient_id, hour_slot, room_id) VALUES (?,?,?)");

    foreach ($cart as $dest) {
        $msgId = !empty($dest['messageId']) ? $dest['messageId'] : null;
        $stmtDest->execute([$orderId, $dest['nom'], $dest['prenom'], $dest['classId'], $msgId, $dest['isAnonymous'] ? 1 : 0]);
        $destId = $pdo->lastInsertId();

        foreach ($dest['roses'] as $rose) {
            if ($rose['qty'] > 0 && in_array($rose['id'], $validProductIds)) {
                $stmtRose->execute([$destId, $rose['id'], $rose['qty']]);
            }
        }

        if (isset($dest['schedule']) && is_array($dest['schedule'])) {
            foreach ($dest['schedule'] as $slot) {
                $stmtSchedule->execute([$destId, $slot['hour'], $slot['roomId']]);
            }
        }
    }

    $pdo->commit();
    echo json_encode(['success' => true, 'orderId' => $orderId]);

} catch (Exception $e) {
    $pdo->rollBack();
    error_log("ERREUR SQL : " . $e->getMessage());
    sendError('Erreur technique : ' . $e->getMessage());
}
?>