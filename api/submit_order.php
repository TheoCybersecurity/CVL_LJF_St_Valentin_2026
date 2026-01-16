<?php
// api/submit_order.php
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

    // 2. Calcul Prix (Identique)
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

    // 3. Création Commande
    $stmtOrder = $pdo->prepare("INSERT INTO orders (user_id, buyer_nom, buyer_prenom, buyer_class_id, total_price, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
    $stmtOrder->execute([$current_user_id, $buyerNom, $buyerPrenom, $buyerClassId, $totalOrderPrice]);
    $orderId = $pdo->lastInsertId();

    // 4. Traitement des Destinataires (NOUVELLE LOGIQUE)
    $roomMap = [];
    $stmtRooms = $pdo->query("SELECT id, name FROM rooms");
    while($row = $stmtRooms->fetch()){ $roomMap[$row['id']] = $row['name']; }

    // Préparation des requêtes
    // A. IDENTITÉ
    $stmtNewRecipient = $pdo->prepare("INSERT INTO recipients (nom, prenom, class_id) VALUES (?, ?, ?)");
    $stmtUpdateClass = $pdo->prepare("UPDATE recipients SET class_id = ? WHERE id = ?");
    
    // B. HORAIRES (On supprime les anciens horaires pour cet ID et on remet les nouveaux, c'est plus simple que l'update colonne par colonne)
    $stmtDeleteSchedule = $pdo->prepare("DELETE FROM schedules WHERE recipient_id = ?");
    $stmtInsertSchedule = $pdo->prepare("INSERT INTO schedules (recipient_id, h08, h09, h10, h11, h12, h13, h14, h15, h16, h17) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    // C. LIEN COMMANDE
    $stmtInsertOrderDest = $pdo->prepare("INSERT INTO order_recipients (order_id, recipient_id, message_id, is_anonymous) VALUES (?, ?, ?, ?)");
    $stmtRose = $pdo->prepare("INSERT INTO recipient_roses (recipient_id, rose_product_id, quantity) VALUES (?,?,?)");

    foreach ($cart as $dest) {
        $recipientId = isset($dest['scheduleId']) ? intval($dest['scheduleId']) : 0; // scheduleId est en fait l'ID de la personne (ex-recipient_schedules)
        $destClassId = intval($dest['classId']);
        
        // --- 1. GESTION DE L'IDENTITÉ ---
        if ($recipientId > 0) {
            // L'élève existe déjà
            if ($destClassId > 0) {
                $stmtUpdateClass->execute([$destClassId, $recipientId]);
            }
        } else {
            // Nouvel élève
            $stmtNewRecipient->execute([
                $dest['nom'], 
                $dest['prenom'], 
                ($destClassId > 0 ? $destClassId : null)
            ]);
            $recipientId = $pdo->lastInsertId();
        }

        // --- 2. GESTION DES HORAIRES (Uniquement si saisie manuelle) ---
        // Si c'est un nouvel élève OU si on veut écraser l'emploi du temps (optionnel, ici on le fait pour les nouveaux)
        // Dans ton JS actuel, si on sélectionne un élève existant, on n'envoie pas de 'schedule' array.
        
        if (isset($dest['schedule']) && is_array($dest['schedule']) && count($dest['schedule']) > 0) {
            $hours = array_fill(8, 10, null);
            foreach ($dest['schedule'] as $slot) {
                $h = intval($slot['hour']);
                $rId = $slot['roomId'];
                if ($h >= 8 && $h <= 17 && isset($roomMap[$rId])) {
                    $hours[$h] = $roomMap[$rId];
                }
            }
            
            // On nettoie s'il y avait un vieux schedule (cas rare mais propre)
            $stmtDeleteSchedule->execute([$recipientId]);
            
            // On insère le nouveau
            $stmtInsertSchedule->execute([
                $recipientId,
                $hours[8], $hours[9], $hours[10], $hours[11], $hours[12],
                $hours[13], $hours[14], $hours[15], $hours[16], $hours[17]
            ]);
        }
        // NOTE : Si l'élève existe déjà et qu'on n'a pas touché au planning, on ne fait rien dans la table schedules, on garde l'ancien.

        // --- 3. LIEN COMMANDE ---
        $msgId = !empty($dest['messageId']) ? $dest['messageId'] : null;
        $stmtInsertOrderDest->execute([
            $orderId, 
            $recipientId, 
            $msgId, 
            $dest['isAnonymous'] ? 1 : 0
        ]);
        $destId = $pdo->lastInsertId(); // ID dans la table de liaison order_recipients

        // --- 4. ROSES ---
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