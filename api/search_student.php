<?php
// api/search_student.php
header('Content-Type: application/json');
require_once '../db.php';

$term = $_GET['q'] ?? '';

if (strlen($term) < 2) {
    echo json_encode([]);
    exit;
}

// Recherche simple sur la table identité
$stmt = $pdo->prepare("
    SELECT 
        r.id, 
        r.nom, 
        r.prenom, 
        r.class_id, 
        c.name as class_name 
    FROM recipients r
    LEFT JOIN classes c ON r.class_id = c.id
    WHERE r.nom LIKE :t OR r.prenom LIKE :t 
    ORDER BY r.nom ASC, r.prenom ASC 
    LIMIT 15
");

$stmt->execute([':t' => '%' . $term . '%']);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($results);
?>
