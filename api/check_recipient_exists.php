<?php
// api/check_recipient_exists.php
require_once '../db.php';
header('Content-Type: application/json');

$nom = $_GET['nom'] ?? '';
$prenom = $_GET['prenom'] ?? '';

if (empty($nom) || empty($prenom)) {
    echo json_encode(['found' => false]);
    exit;
}

// Recherche stricte
$stmt = $pdo->prepare("SELECT r.id, r.nom, r.prenom, c.name as class_name 
                       FROM recipients r 
                       LEFT JOIN classes c ON r.class_id = c.id 
                       WHERE r.nom = ? AND r.prenom = ? LIMIT 1");
$stmt->execute([$nom, $prenom]);
$res = $stmt->fetch(PDO::FETCH_ASSOC);

if ($res) {
    echo json_encode(['found' => true, 'data' => $res]);
} else {
    echo json_encode(['found' => false]);
}
?>