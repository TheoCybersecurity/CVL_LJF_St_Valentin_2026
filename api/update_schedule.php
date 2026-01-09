<?php
// api/update_schedule.php
header('Content-Type: application/json');
session_start();
require_once '../db.php';

// Sécurité
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Non connecté']);
    exit;
}

// Récupération des données JSON
$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['recipient_id']) || !isset($data['schedule'])) {
    echo json_encode(['success' => false, 'error' => 'Données manquantes']);
    exit;
}

$recipientId = intval($data['recipient_id']);
$newSchedule = $data['schedule']; // Format attendu: [{hour: 8, room_id: 12}, ...]
$userId = $_SESSION['user_id'];

try {
    // 1. Vérifier que le destinataire appartient bien à une commande de l'utilisateur
    $checkStmt = $pdo->prepare("
        SELECT r.id 
        FROM order_recipients r
        JOIN orders o ON r.order_id = o.id
        WHERE r.id = ? AND o.user_id = ?
    ");
    $checkStmt->execute([$recipientId, $userId]);
    if (!$checkStmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Action non autorisée']);
        exit;
    }

    $pdo->beginTransaction();

    // 2. On supprime les anciens horaires pour ce destinataire
    $delStmt = $pdo->prepare("DELETE FROM recipient_schedules WHERE recipient_id = ?");
    $delStmt->execute([$recipientId]);

    // 3. On insère les nouveaux
    $insStmt = $pdo->prepare("INSERT INTO recipient_schedules (recipient_id, hour_slot, room_id) VALUES (?, ?, ?)");
    
    foreach ($newSchedule as $slot) {
        $hour = intval($slot['hour']);
        $roomId = intval($slot['room_id']);
        // Petite sécurité : on vérifie que les données sont cohérentes
        if ($hour >= 8 && $hour <= 17 && $roomId > 0) {
            $insStmt->execute([$recipientId, $hour, $roomId]);
        }
    }

    $pdo->commit();
    echo json_encode(['success' => true]);

} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>