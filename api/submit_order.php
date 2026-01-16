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

// Vérification de la session
$current_user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;

if (!$current_user_id) {
    sendError('Session expirée. Veuillez vous reconnecter.');
}

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

    // =================================================================
    // 1. MISE À JOUR DE L'ACHETEUR (Source Unique de Vérité)
    // =================================================================
    // On met à jour la table 'users' au lieu de stocker le nom dans 'orders'
    // Note : ON DUPLICATE KEY UPDATE est utile si l'ID existe déjà (ce qui est le cas ici)
    $stmtUser = $pdo->prepare("
        INSERT INTO users (user_id, nom, prenom, class_id) 
        VALUES (:id, :n, :p, :c) 
        ON DUPLICATE KEY UPDATE nom=:n, prenom=:p, class_id=:c
    ");
    $stmtUser->execute([
        ':id' => $current_user_id, 
        ':n' => $buyerNom, 
        ':p' => $buyerPrenom, 
        ':c' => $buyerClassId
    ]);

    // =================================================================
    // 2. CALCUL DU PRIX (Logique inchangée)
    // =================================================================
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

    // =================================================================
    // 3. CRÉATION DE LA COMMANDE (CORRECTION ICI)
    // =================================================================
    // On ne met PLUS buyer_nom, buyer_prenom, etc.
    // On lie simplement à l'utilisateur via user_id.
    
    $stmtOrder = $pdo->prepare("INSERT INTO orders (user_id, total_price, created_at) VALUES (?, ?, NOW())");
    $stmtOrder->execute([$current_user_id, $totalOrderPrice]);
    $orderId = $pdo->lastInsertId();

    // =================================================================
    // 4. TRAITEMENT DES DESTINATAIRES
    // =================================================================
    $roomMap = [];
    $stmtRooms = $pdo->query("SELECT id, name FROM rooms");
    while($row = $stmtRooms->fetch()){ $roomMap[$row['id']] = $row['name']; }

    // Préparation des requêtes SQL
    $stmtNewRecipient = $pdo->prepare("INSERT INTO recipients (nom, prenom, class_id) VALUES (?, ?, ?)");
    $stmtUpdateClass = $pdo->prepare("UPDATE recipients SET class_id = ? WHERE id = ?");
    
    // Gestion horaires
    $stmtDeleteSchedule = $pdo->prepare("DELETE FROM schedules WHERE recipient_id = ?");
    $stmtInsertSchedule = $pdo->prepare("INSERT INTO schedules (recipient_id, h08, h09, h10, h11, h12, h13, h14, h15, h16, h17) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    // Liaison Commande & Roses
    $stmtInsertOrderDest = $pdo->prepare("INSERT INTO order_recipients (order_id, recipient_id, message_id, is_anonymous) VALUES (?, ?, ?, ?)");
    $stmtRose = $pdo->prepare("INSERT INTO recipient_roses (recipient_id, rose_product_id, quantity) VALUES (?,?,?)");

    foreach ($cart as $dest) {
        // 'scheduleId' contient l'ID du recipient s'il a été sélectionné dans l'autocomplétion
        $existingRecipientId = isset($dest['scheduleId']) ? intval($dest['scheduleId']) : 0; 
        $destClassId = intval($dest['classId']);
        $finalRecipientId = 0;

        // A. GESTION DE L'IDENTITÉ (Table recipients)
        if ($existingRecipientId > 0) {
            // C'est un élève existant
            $finalRecipientId = $existingRecipientId;
            if ($destClassId > 0) {
                // On met à jour sa classe au cas où elle a changé
                $stmtUpdateClass->execute([$destClassId, $finalRecipientId]);
            }
        } else {
            // C'est un nouvel élève
            $stmtNewRecipient->execute([
                $dest['nom'], 
                $dest['prenom'], 
                ($destClassId > 0 ? $destClassId : null)
            ]);
            $finalRecipientId = $pdo->lastInsertId();
        }

        // B. GESTION DES HORAIRES (Table schedules)
        // On met à jour l'emploi du temps SI on a reçu des données manuelles
        if (isset($dest['schedule']) && is_array($dest['schedule']) && count($dest['schedule']) > 0) {
            $hours = array_fill(8, 10, null);
            foreach ($dest['schedule'] as $slot) {
                $h = intval($slot['hour']);
                $rId = $slot['roomId'];
                if ($h >= 8 && $h <= 17 && isset($roomMap[$rId])) {
                    $hours[$h] = $roomMap[$rId];
                }
            }
            
            // On supprime l'ancien emploi du temps pour éviter les conflits
            $stmtDeleteSchedule->execute([$finalRecipientId]);
            
            // On insère le nouveau
            $stmtInsertSchedule->execute([
                $finalRecipientId,
                $hours[8], $hours[9], $hours[10], $hours[11], $hours[12],
                $hours[13], $hours[14], $hours[15], $hours[16], $hours[17]
            ]);
        }

        // C. LIEN COMMANDE (Table order_recipients)
        // C'est ici qu'on crée l'étiquette (le paquet à livrer)
        $msgId = !empty($dest['messageId']) ? $dest['messageId'] : null;
        $stmtInsertOrderDest->execute([
            $orderId, 
            $finalRecipientId, 
            $msgId, 
            $dest['isAnonymous'] ? 1 : 0
        ]);
        
        // On récupère l'ID de cette livraison spécifique (pour y attacher les roses)
        $orderRecipientId = $pdo->lastInsertId(); 

        // D. ROSES (Table recipient_roses)
        foreach ($dest['roses'] as $rose) {
            if ($rose['qty'] > 0 && in_array($rose['id'], $validProductIds)) {
                // Attention : ici le champ s'appelle 'recipient_id' dans la table recipient_roses
                // mais il fait référence à la table 'order_recipients' (la livraison), pas à l'élève directement.
                $stmtRose->execute([$orderRecipientId, $rose['id'], $rose['qty']]);
            }
        }
    }

    $pdo->commit();
    echo json_encode(['success' => true, 'orderId' => $orderId]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("ERREUR SQL submit_order : " . $e->getMessage());
    sendError('Erreur technique : ' . $e->getMessage());
}
?>