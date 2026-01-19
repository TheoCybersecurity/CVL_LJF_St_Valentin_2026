<?php
// api/submit_order.php
header('Content-Type: application/json');
ini_set('display_errors', 0);
error_reporting(E_ALL);

session_start();
require_once '../db.php'; 
require_once '../mail_config.php'; 

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
$buyerEmail = trim($input['buyerEmail'] ?? '');
$cart = $input['cart'] ?? [];

if (empty($buyerNom) || empty($buyerPrenom) || $buyerClassId === 0 || empty($cart)) {
    sendError('Informations incomplètes.');
}

if (empty($buyerEmail) || !filter_var($buyerEmail, FILTER_VALIDATE_EMAIL)) {
    sendError("L'adresse email n'est pas valide.");
}

try {
    $pdo->beginTransaction();

    // 1. MISE À JOUR DE L'ACHETEUR
    $stmtUser = $pdo->prepare("
        INSERT INTO users (user_id, nom, prenom, class_id, email) 
        VALUES (:id, :n, :p, :c, :e) 
        ON DUPLICATE KEY UPDATE nom=:n, prenom=:p, class_id=:c, email=:e
    ");
    $stmtUser->execute([
        ':id' => $current_user_id, 
        ':n' => $buyerNom, 
        ':p' => $buyerPrenom, 
        ':c' => $buyerClassId,
        ':e' => $buyerEmail
    ]);

    // 2. CALCUL DU PRIX
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

    // 3. CRÉATION DE LA COMMANDE
    $stmtOrder = $pdo->prepare("INSERT INTO orders (user_id, total_price, created_at) VALUES (?, ?, NOW())");
    $stmtOrder->execute([$current_user_id, $totalOrderPrice]);
    $orderId = $pdo->lastInsertId();

    // 4. TRAITEMENT DES DESTINATAIRES
    // Chargement des références (Salles et Messages) pour éviter les requêtes dans la boucle
    $roomMap = [];
    $stmtRooms = $pdo->query("SELECT id, name FROM rooms");
    while($row = $stmtRooms->fetch()){ $roomMap[$row['id']] = $row['name']; }

    // --- NOUVEAU : Chargement des messages ---
    $msgMap = [];
    $stmtMsgs = $pdo->query("SELECT id, content FROM predefined_messages");
    while($row = $stmtMsgs->fetch()){ $msgMap[$row['id']] = $row['content']; }
    // ----------------------------------------

    $stmtNewRecipient = $pdo->prepare("INSERT INTO recipients (nom, prenom, class_id) VALUES (?, ?, ?)");
    $stmtUpdateClass = $pdo->prepare("UPDATE recipients SET class_id = ? WHERE id = ?");
    $stmtDeleteSchedule = $pdo->prepare("DELETE FROM schedules WHERE recipient_id = ?");
    $stmtInsertSchedule = $pdo->prepare("INSERT INTO schedules (recipient_id, h08, h09, h10, h11, h12, h13, h14, h15, h16, h17) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmtInsertOrderDest = $pdo->prepare("INSERT INTO order_recipients (order_id, recipient_id, message_id, is_anonymous) VALUES (?, ?, ?, ?)");
    $stmtRose = $pdo->prepare("INSERT INTO recipient_roses (recipient_id, rose_product_id, quantity) VALUES (?,?,?)");

    $mailRecipientsHtml = "";

    foreach ($cart as $dest) {
        $existingRecipientId = isset($dest['scheduleId']) ? intval($dest['scheduleId']) : 0; 
        $destClassId = intval($dest['classId']);
        $finalRecipientId = 0;

        if ($existingRecipientId > 0) {
            $finalRecipientId = $existingRecipientId;
            if ($destClassId > 0) $stmtUpdateClass->execute([$destClassId, $finalRecipientId]);
        } else {
            $stmtNewRecipient->execute([$dest['nom'], $dest['prenom'], ($destClassId > 0 ? $destClassId : null)]);
            $finalRecipientId = $pdo->lastInsertId();
        }

        if (isset($dest['schedule']) && is_array($dest['schedule']) && count($dest['schedule']) > 0) {
            $hours = array_fill(8, 10, null);
            foreach ($dest['schedule'] as $slot) {
                $h = intval($slot['hour']);
                $rId = $slot['roomId'];
                if ($h >= 8 && $h <= 17 && isset($roomMap[$rId])) {
                    $hours[$h] = $roomMap[$rId];
                }
            }
            $stmtDeleteSchedule->execute([$finalRecipientId]);
            $stmtInsertSchedule->execute([$finalRecipientId, $hours[8], $hours[9], $hours[10], $hours[11], $hours[12], $hours[13], $hours[14], $hours[15], $hours[16], $hours[17]]);
        }

        $msgId = !empty($dest['messageId']) ? $dest['messageId'] : null;
        $stmtInsertOrderDest->execute([$orderId, $finalRecipientId, $msgId, $dest['isAnonymous'] ? 1 : 0]);
        $orderRecipientId = $pdo->lastInsertId(); 

        $rosesSummaryArray = [];
        foreach ($dest['roses'] as $rose) {
            if ($rose['qty'] > 0 && in_array($rose['id'], $validProductIds)) {
                $stmtRose->execute([$orderRecipientId, $rose['id'], $rose['qty']]);
                $rosesSummaryArray[] = $rose['qty'] . " x " . htmlspecialchars($rose['name']);
            }
        }

        // --- Construction de la ligne pour le mail ---
        $recipientName = htmlspecialchars($dest['nom'] . ' ' . $dest['prenom']);
        $recipientClass = htmlspecialchars($dest['className']);
        $rosesString = implode(', ', $rosesSummaryArray);
        $anonString = $dest['isAnonymous'] ? " (Anonyme)" : "";
        
        // Récupération du texte du message
        $messageDisplay = "";
        if ($msgId && isset($msgMap[$msgId])) {
            $messageDisplay = "<br><span style='color: #666; font-style: italic; font-size: 0.9em;'>📝 Message : &laquo; " . htmlspecialchars($msgMap[$msgId]) . " &raquo;</span>";
        }
        
        $mailRecipientsHtml .= "<li style='border-bottom: 1px solid #eee; padding: 10px 0;'>
            <strong>$recipientName</strong> <small>($recipientClass)</small>$anonString<br>
            <span style='color:#d63384;'>💐 $rosesString</span>
            $messageDisplay
        </li>";
    }

    $pdo->commit();

    // =================================================================
    // 5. ENVOI DU MAIL
    // =================================================================
    $mailSent = false;
    try {
        $mail = getMailer(); 
        $mail->addAddress($buyerEmail);
        
        // Contenu HTML
        $body = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: auto; border: 1px solid #eee; border-radius: 8px;'>
            <div style='background-color: #d63384; padding: 20px; text-align: center; color: white; border-radius: 8px 8px 0 0;'>
                <h1 style='margin: 0;'>Commande Reçue ! 🌹</h1>
            </div>
            <div style='padding: 20px; color: #333;'>
                <p>Bonjour <strong>".htmlspecialchars($buyerPrenom)."</strong>,</p>
                <p>Votre commande <strong>#$orderId</strong> est bien enregistrée.</p>
                
                <div style='background-color: #fff0f6; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #d63384;'>
                    <h3 style='margin: 0; color: #d63384;'>À payer : " . number_format($totalOrderPrice, 2) . " €</h3>
                    <p style='margin: 5px 0 0 0; font-size: 0.9em;'>Veuillez régler auprès du CVL pour valider la livraison.</p>
                </div>

                <h3>Détail de vos envois :</h3>
                <ul style='list-style: none; padding: 0;'>
                    $mailRecipientsHtml
                </ul>
                
                <hr style='border: 0; border-top: 1px solid #eee; margin: 20px 0;'>
                <p style='font-size: 0.8em; color: #888; text-align: center;'>
                    Ceci est un mail automatique. Merci de ne pas répondre.<br>
                    L'équipe du CVL.
                </p>
            </div>
        </div>";

        $mail->isHTML(true);
        $mail->Subject = "Votre commande St Valentin #$orderId";
        $mail->Body    = $body;
        $mail->AltBody = "Commande #$orderId reçue. Total: $totalOrderPrice EUR.";

        $mail->send();
        $mailSent = true;

    } catch (Exception $e) {
        error_log("Erreur envoi mail : " . $e->getMessage());
    }

    echo json_encode(['success' => true, 'orderId' => $orderId, 'mailSent' => $mailSent]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("ERREUR SQL submit_order : " . $e->getMessage());
    sendError('Erreur technique : ' . $e->getMessage());
}
?>