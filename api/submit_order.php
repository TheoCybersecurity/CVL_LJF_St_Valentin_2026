<?php
// api/submit_order.php
header('Content-Type: application/json');
ini_set('display_errors', 0); // En prod
error_reporting(E_ALL);

session_start();
require_once '../db.php'; 

function sendError($msg) {
    http_response_code(400); 
    echo json_encode(['success' => false, 'message' => $msg]);
    exit;
}

$current_user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;

// Récupération JSON
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

    // 1. Mise à jour User (Acheteur)
    if ($current_user_id) {
        $stmtUser = $pdo->prepare("INSERT INTO project_users (user_id, nom, prenom, class_id) VALUES (:id, :n, :p, :c) ON DUPLICATE KEY UPDATE nom=:n, prenom=:p, class_id=:c");
        $stmtUser->execute([':id' => $current_user_id, ':n' => $buyerNom, ':p' => $buyerPrenom, ':c' => $buyerClassId]);
    }

    // 2. Calcul Prix Total
    $pricesStmt = $pdo->query("SELECT quantity, price FROM roses_prices");
    $priceList = $pricesStmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    $grandTotalRoses = 0;
    $validProductIds = $pdo->query("SELECT id FROM rose_products")->fetchAll(PDO::FETCH_COLUMN);

    foreach ($cart as $recipient) {
        foreach ($recipient['roses'] as $r) {
            if (in_array($r['id'], $validProductIds)) {
                $qty = intval($r['qty']);
                if ($qty > 0) $grandTotalRoses += $qty;
            }
        }
    }

    $totalOrderPrice = 0.0;
    if ($grandTotalRoses > 0) {
        if (isset($priceList[$grandTotalRoses])) {
            $totalOrderPrice = floatval($priceList[$grandTotalRoses]);
        } else {
            $maxQty = max(array_keys($priceList));
            $maxPrice = floatval($priceList[$maxQty]);
            $diff = $grandTotalRoses - $maxQty;
            $totalOrderPrice = $maxPrice + ($diff * 2.00); 
        }
    }

    // 3. Création de la commande
    $stmtOrder = $pdo->prepare("INSERT INTO orders (user_id, buyer_nom, buyer_prenom, buyer_class_id, total_price, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
    $stmtOrder->execute([$current_user_id, $buyerNom, $buyerPrenom, $buyerClassId, $totalOrderPrice]);
    $orderId = $pdo->lastInsertId();

    // 4. Traitement des Destinataires
    $roomMap = [];
    $stmtRooms = $pdo->query("SELECT id, name FROM rooms");
    while($row = $stmtRooms->fetch()){ $roomMap[$row['id']] = $row['name']; }

    $stmtInsertDest = $pdo->prepare("INSERT INTO order_recipients (order_id, recipient_schedule_id, dest_nom, dest_prenom, class_id, message_id, is_anonymous) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmtRose = $pdo->prepare("INSERT INTO recipient_roses (recipient_id, rose_product_id, quantity) VALUES (?,?,?)");

    // --- REQUÊTES MISES À JOUR (PLUS DE COLONNE 'CLASSE') ---
    
    // Update : on ne met à jour que class_id
    $stmtUpdateClass = $pdo->prepare("UPDATE recipient_schedules SET class_id = ? WHERE id = ?");
    
    // Insert : on insère que class_id
    $stmtNewSchedule = $pdo->prepare("INSERT INTO recipient_schedules (nom, prenom, class_id, h08, h09, h10, h11, h12, h13, h14, h15, h16, h17) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    foreach ($cart as $dest) {
        $finalScheduleId = null;
        $scheduleIdInput = isset($dest['scheduleId']) ? intval($dest['scheduleId']) : 0;
        
        $destClassId = intval($dest['classId']); // ID uniquement

        // A. L'élève existe déjà
        if ($scheduleIdInput > 0) {
            $finalScheduleId = $scheduleIdInput;
            // Si l'utilisateur a spécifié une classe, on met à jour la fiche
            if ($destClassId > 0) {
                $stmtUpdateClass->execute([$destClassId, $finalScheduleId]);
            }
        } 
        // B. Nouvel élève (Manuel)
        else {
            $hours = array_fill(8, 10, null);
            if (isset($dest['schedule']) && is_array($dest['schedule'])) {
                foreach ($dest['schedule'] as $slot) {
                    $h = intval($slot['hour']);
                    $rId = $slot['roomId'];
                    if ($h >= 8 && $h <= 17 && isset($roomMap[$rId])) {
                        $hours[$h] = $roomMap[$rId];
                    }
                }
            }

            $stmtNewSchedule->execute([
                $dest['nom'], 
                $dest['prenom'], 
                ($destClassId > 0 ? $destClassId : null), 
                $hours[8], $hours[9], $hours[10], $hours[11], $hours[12],
                $hours[13], $hours[14], $hours[15], $hours[16], $hours[17]
            ]);
            $finalScheduleId = $pdo->lastInsertId();
        }

        // Lien Commande <-> Destinataire
        $msgId = !empty($dest['messageId']) ? $dest['messageId'] : null;
        $stmtInsertDest->execute([
            $orderId, 
            $finalScheduleId, 
            $dest['nom'], 
            $dest['prenom'], 
            ($destClassId > 0 ? $destClassId : null), 
            $msgId, 
            $dest['isAnonymous'] ? 1 : 0
        ]);
        $destId = $pdo->lastInsertId();

        foreach ($dest['roses'] as $rose) {
            if ($rose['qty'] > 0 && in_array($rose['id'], $validProductIds)) {
                $stmtRose->execute([$destId, $rose['id'], $rose['qty']]);
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