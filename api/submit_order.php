<?php
header('Content-Type: application/json');

// Désactiver l'affichage des erreurs HTML, on veut du JSON uniquement
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once '../auth_check.php'; 
require_once '../db.php'; 

// Fonction pour renvoyer une erreur JSON propre
function sendError($msg) {
    echo json_encode(['success' => false, 'message' => $msg]);
    exit;
}

// 1. Réception JSON
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);

if (!$input) { 
    sendError('Données JSON invalides ou vides.');
}

$buyerNom = trim($input['buyerNom'] ?? '');
$buyerPrenom = trim($input['buyerPrenom'] ?? '');
$buyerClassId = intval($input['buyerClassId'] ?? 0);
$cart = $input['cart'] ?? [];

// 2. Validation de base
if (empty($buyerNom) || empty($buyerPrenom) || $buyerClassId === 0 || empty($cart)) {
    sendError('Informations incomplètes (Acheteur ou Panier vide).');
}

try {
    $pdo->beginTransaction();

    // ------------------------------------------------------------------
    // A. Mettre à jour l'utilisateur local (avec ID de CLASSE maintenant)
    // ------------------------------------------------------------------
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

    // ------------------------------------------------------------------
    // B. Calculer le total et créer la commande
    // ------------------------------------------------------------------
    // On récupère les prix produits
    $products = $pdo->query("SELECT id, price FROM rose_products")->fetchAll(PDO::FETCH_KEY_PAIR);
    
    $totalOrder = 0;
    foreach ($cart as $item) {
        foreach ($item['roses'] as $r) {
            if (isset($products[$r['id']])) {
                $totalOrder += $products[$r['id']] * $r['qty'];
            }
        }
    }

    $stmtOrder = $pdo->prepare("INSERT INTO orders (buyer_id, total_price) VALUES (?, ?)");
    $stmtOrder->execute([$current_user_id, $totalOrder]);
    $orderId = $pdo->lastInsertId();

    // ------------------------------------------------------------------
    // C. Insérer les destinataires et leurs emplois du temps
    // ------------------------------------------------------------------
    $stmtDest = $pdo->prepare("
        INSERT INTO order_recipients 
        (order_id, dest_nom, dest_prenom, class_id, message_id, is_anonymous) 
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    
    // Prépa pour les roses
    $stmtRose = $pdo->prepare("INSERT INTO recipient_roses (recipient_id, rose_product_id, quantity) VALUES (?,?,?)");
    
    // Prépa pour l'emploi du temps
    $stmtSchedule = $pdo->prepare("INSERT INTO recipient_schedules (recipient_id, hour_slot, room_id) VALUES (?,?,?)");

    foreach ($cart as $dest) {
        $msgId = !empty($dest['messageId']) ? $dest['messageId'] : null;
        
        // 1. Le destinataire
        $stmtDest->execute([
            $orderId, 
            $dest['nom'], 
            $dest['prenom'], 
            $dest['classId'], 
            $msgId, 
            $dest['isAnonymous'] ? 1 : 0
        ]);
        $destId = $pdo->lastInsertId();

        // 2. Ses roses
        foreach ($dest['roses'] as $rose) {
            if ($rose['qty'] > 0) {
                $stmtRose->execute([$destId, $rose['id'], $rose['qty']]);
            }
        }

        // 3. Son emploi du temps (Nouveauté)
        if (isset($dest['schedule']) && is_array($dest['schedule'])) {
            foreach ($dest['schedule'] as $slot) {
                $stmtSchedule->execute([
                    $destId,
                    $slot['hour'],   // ex: 8
                    $slot['roomId']  // ex: 5
                ]);
            }
        }
    }

    $pdo->commit();
    echo json_encode(['success' => true, 'orderId' => $orderId]);

} catch (Exception $e) {
    $pdo->rollBack();
    // On log l'erreur serveur pour toi (regarde /var/log/apache2/error.log)
    error_log("ERREUR SQL ST VALENTIN : " . $e->getMessage());
    sendError('Erreur interne serveur. Contactez le CVL.');
}
?>