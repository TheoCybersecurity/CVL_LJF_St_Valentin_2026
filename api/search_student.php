<?php
// api/search_student.php
header('Content-Type: application/json');
require_once '../db.php';

$term = $_GET['q'] ?? '';

if (strlen($term) < 2) {
    echo json_encode([]);
    exit;
}

// ON FAIT UNE JOINTURE (LEFT JOIN) pour récupérer le nom de la classe via l'ID
$stmt = $pdo->prepare("
    SELECT 
        rs.id, 
        rs.nom, 
        rs.prenom, 
        rs.class_id, 
        c.name as class_name 
    FROM recipient_schedules rs
    LEFT JOIN classes c ON rs.class_id = c.id
    WHERE rs.nom LIKE :t OR rs.prenom LIKE :t 
    ORDER BY rs.nom ASC, rs.prenom ASC 
    LIMIT 15
");

$stmt->execute([':t' => '%' . $term . '%']);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($results);
?>