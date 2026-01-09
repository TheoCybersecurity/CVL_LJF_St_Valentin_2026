<?php
// api/get_order_details.php
header('Content-Type: application/json');
ini_set('display_errors', 0);
error_reporting(E_ALL);

session_start();
require_once '../db.php';

// 1. Vérification sécurité
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Non connecté']);
    exit;
}

$orderId = isset($_GET['id']) ? intval($_GET['id']) : 0;
$userId = $_SESSION['user_id'];

if ($orderId === 0) {
    http_response_code(400);
    echo json_encode(['error' => 'ID manquant']);
    exit;
}

try {
    // 2. Récupérer la commande
    $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ? AND user_id = ?");
    $stmt->execute([$orderId, $userId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        echo json_encode(['error' => 'Commande introuvable ou accès refusé.']);
        exit;
    }

    // 3. Récupérer les destinataires ET le nom de leur classe
    // MODIFICATION ICI : On ajoute c.name (alias class_name) et le LEFT JOIN classes
    $stmtDest = $pdo->prepare("
        SELECT 
            r.*, 
            m.content as message_text,
            c.name as class_name 
        FROM order_recipients r 
        LEFT JOIN predefined_messages m ON r.message_id = m.id 
        LEFT JOIN classes c ON r.class_id = c.id
        WHERE r.order_id = ?
    ");
    $stmtDest->execute([$orderId]);
    $recipients = $stmtDest->fetchAll(PDO::FETCH_ASSOC);

    // 4. Détails (Roses et Planning)
    foreach ($recipients as &$dest) {
        // Roses
        $stmtRoses = $pdo->prepare("
            SELECT rr.quantity, p.name 
            FROM recipient_roses rr
            JOIN rose_products p ON rr.rose_product_id = p.id
            WHERE rr.recipient_id = ?
        ");
        $stmtRoses->execute([$dest['id']]);
        $dest['roses'] = $stmtRoses->fetchAll(PDO::FETCH_ASSOC);

        // Emploi du temps
        $stmtSched = $pdo->prepare("
            SELECT rs.hour_slot, rm.name as room_name
            FROM recipient_schedules rs
            JOIN rooms rm ON rs.room_id = rm.id
            WHERE rs.recipient_id = ?
            ORDER BY rs.hour_slot ASC
        ");
        $stmtSched->execute([$dest['id']]);
        $dest['schedule'] = $stmtSched->fetchAll(PDO::FETCH_ASSOC);
    }

    // 5. Récupérer TOUTES les salles pour le mode édition
    $stmtAllRooms = $pdo->query("SELECT id, name FROM rooms ORDER BY name ASC");
    $allRooms = $stmtAllRooms->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'order' => $order,
        'recipients' => $recipients,
        'all_rooms' => $allRooms
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erreur serveur: ' . $e->getMessage()]);
}
?>