<?php
// api/get_order_details.php
header('Content-Type: application/json');
ini_set('display_errors', 0);
error_reporting(E_ALL);

session_start();
require_once '../db.php';

// 1. Sécurité
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
    // 2. Récupérer la commande principale
    $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ? AND user_id = ?");
    $stmt->execute([$orderId, $userId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        echo json_encode(['error' => 'Commande introuvable ou accès refusé.']);
        exit;
    }

    // 3. Récupérer les destinataires (Nouvelle structure)
    // On part de order_recipients (le lien)
    // On joint recipients (pour le nom/prénom)
    // On joint classes (pour le nom de la classe)
    // On joint predefined_messages (pour le texte du message)
    $stmtDest = $pdo->prepare("
        SELECT 
            ort.id as order_recipient_id, -- L'ID de la ligne de commande
            ort.is_anonymous,
            ort.message_id,
            r.id as student_id,           -- L'ID de la personne (pour chercher l'emploi du temps)
            r.nom, 
            r.prenom, 
            c.id as class_id,
            c.name as class_name,
            m.content as message_text 
        FROM order_recipients ort
        JOIN recipients r ON ort.recipient_id = r.id
        LEFT JOIN classes c ON r.class_id = c.id
        LEFT JOIN predefined_messages m ON ort.message_id = m.id 
        WHERE ort.order_id = ?
    ");
    $stmtDest->execute([$orderId]);
    $recipients = $stmtDest->fetchAll(PDO::FETCH_ASSOC);

    // Prépare la requête pour les Roses (liées à la ligne de commande)
    // Note : dans recipient_roses, la colonne recipient_id correspond à order_recipients.id
    $stmtRoses = $pdo->prepare("
        SELECT rr.quantity, p.name 
        FROM recipient_roses rr
        JOIN rose_products p ON rr.rose_product_id = p.id
        WHERE rr.recipient_id = ?
    ");

    // Prépare la requête pour l'Emploi du temps (liée à la personne)
    // On récupère les colonnes h08 à h17 directement
    $stmtSched = $pdo->prepare("
        SELECT h08, h09, h10, h11, h12, h13, h14, h15, h16, h17
        FROM schedules
        WHERE recipient_id = ?
    ");

    // 4. Boucle pour enrichir chaque destinataire
    foreach ($recipients as &$dest) {
        // A. Récupération des Roses
        $stmtRoses->execute([$dest['order_recipient_id']]);
        $dest['roses'] = $stmtRoses->fetchAll(PDO::FETCH_ASSOC);

        // B. Récupération de l'Emploi du temps
        $stmtSched->execute([$dest['student_id']]);
        $schedRow = $stmtSched->fetch(PDO::FETCH_ASSOC);
        
        $scheduleList = [];
        if ($schedRow) {
            // On transforme la ligne horizontale (h08, h09...) en liste verticale pour le JS
            // Ex: [{hour: 8, room_name: "B102"}, {hour: 10, room_name: "CDI"}]
            foreach ($schedRow as $col => $roomName) {
                if (!empty($roomName)) {
                    // $col est "h08", on enlève le 'h' pour avoir 08
                    $hour = intval(substr($col, 1));
                    $scheduleList[] = [
                        'hour_slot' => $hour, // Je garde 'hour_slot' pour compatibilité avec ton JS existant
                        'room_name' => $roomName
                    ];
                }
            }
        }
        $dest['schedule'] = $scheduleList;
    }

    // 5. Récupérer TOUTES les salles (pour le select du mode édition)
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
