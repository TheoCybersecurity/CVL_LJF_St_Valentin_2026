<?php
// delivery.php
require_once 'db.php';
require_once 'auth_check.php'; 
checkAccess('cvl');

// --- 1. ACTION : MARQUER LIVRÉ ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['recipient_id'])) {
    if ($_POST['action'] === 'mark_distributed') {
        $stmt = $pdo->prepare("UPDATE order_recipients SET is_distributed = 1, distributed_at = NOW() WHERE id = ?");
        $stmt->execute([intval($_POST['recipient_id'])]);
        // Redirection propre
        header("Location: delivery.php?" . http_build_query($_GET));
        exit;
    }
}

// --- 2. RÉCUPÉRATION DES FILTRES ---
// Liste des Bâtiments
$buildings = $pdo->query("SELECT id, name FROM buildings ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Paramètres URL
$currentHour = intval(date('H'));
if ($currentHour < 8 || $currentHour > 17) $currentHour = 8;
$selectedHour = isset($_GET['hour']) ? intval($_GET['hour']) : $currentHour;

// Bâtiment par défaut (Le premier de la liste)
$defaultBuildingId = count($buildings) > 0 ? $buildings[0]['id'] : 0;
$selectedBuilding = isset($_GET['building']) ? intval($_GET['building']) : $defaultBuildingId;

$searchQuery = isset($_GET['q']) ? trim($_GET['q']) : '';

// --- 3. REQUÊTE PRINCIPALE ---
$params = [];

if ($searchQuery) {
    // MODE RECHERCHE GLOBALE
    $sql = "
        SELECT 
            r.id as recipient_id, r.dest_nom, r.dest_prenom, r.is_anonymous, r.message_id,
            c.name as class_name,
            pm.content as message_content,
            'Recherche' as room_name, 
            'Résultats' as floor_name, -- Nom fictif pour l'affichage
            o.buyer_prenom, o.buyer_nom
        FROM order_recipients r
        JOIN orders o ON r.order_id = o.id
        LEFT JOIN classes c ON r.class_id = c.id
        LEFT JOIN predefined_messages pm ON r.message_id = pm.id
        WHERE o.is_paid = 1 
        AND r.is_distributed = 0
        AND (r.dest_nom LIKE :q OR r.dest_prenom LIKE :q)
        LIMIT 50
    ";
    $params['q'] = "%$searchQuery%";

} else {
    // MODE STANDARD (Tri par Level Number des étages)
    // MODE STANDARD (Tri par Level Number des étages)
    $sql = "
        SELECT 
            r.id as recipient_id, r.dest_nom, r.dest_prenom, r.is_anonymous, r.message_id,
            c.name as class_name,
            pm.content as message_content,
            rs.room_id, 
            rm.name as room_name,
            f.name as floor_name,
            f.level_number,
            o.buyer_prenom, o.buyer_nom
        FROM order_recipients r
        JOIN orders o ON r.order_id = o.id
        LEFT JOIN classes c ON r.class_id = c.id
        LEFT JOIN predefined_messages pm ON r.message_id = pm.id
        -- Jointures Planning / Salle / Etage
        JOIN recipient_schedules rs ON (r.id = rs.recipient_id AND rs.hour_slot = :hour)
        JOIN rooms rm ON rs.room_id = rm.id
        JOIN floors f ON rm.floor_id = f.id
        WHERE o.is_paid = 1 
        AND r.is_distributed = 0
        AND rm.building_id = :building
        ORDER BY f.level_number ASC, rm.name ASC, r.dest_nom ASC
    ";
    $params['hour'] = $selectedHour;
    $params['building'] = $selectedBuilding;
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- 4. CHARGEMENT DES ROSES ---
$recipientIds = array_column($recipients, 'recipient_id');
$rosesMap = [];
if (!empty($recipientIds)) {
    $inQuery = implode(',', array_fill(0, count($recipientIds), '?'));
    $stmtRoses = $pdo->prepare("SELECT rr.recipient_id, rr.quantity, rp.name FROM recipient_roses rr JOIN rose_products rp ON rr.rose_product_id = rp.id WHERE rr.recipient_id IN ($inQuery)");
    $stmtRoses->execute($recipientIds);
    while ($row = $stmtRoses->fetch(PDO::FETCH_ASSOC)) {
        $rosesMap[$row['recipient_id']][] = $row;
    }
}

// --- 5. REGROUPEMENT ---
$groupedData = [];
if ($searchQuery) {
    $groupedData['Résultats']['🔍 Recherche'] = $recipients;
} else {
    foreach ($recipients as $recip) {
        $floor = $recip['floor_name'] ?? 'Étage Inconnu';
        $room = $recip['room_name'];
        $groupedData[$floor][$room][] = $recip;
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Livraison - St Valentin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body { background-color: #f4f6f9; padding-bottom: 80px; }
        .scrolling-wrapper { overflow-x: auto; white-space: nowrap; padding: 5px 0; -webkit-overflow-scrolling: touch; }
        .scrolling-wrapper::-webkit-scrollbar { display: none; }
        
        /* Boutons */
        .time-btn, .building-btn {
            display: inline-block; padding: 8px 16px; margin-right: 8px;
            border-radius: 20px; background: white; color: #555; text-decoration: none;
            font-size: 0.9rem; font-weight: 600; border: 1px solid #dee2e6;
        }
        .building-btn { border-radius: 8px; background: #e9ecef; }
        .active { background: #dc3545; color: white; border-color: #dc3545; }
        .building-btn.active { background: #0d6efd; border-color: #0d6efd; }

        /* Design Cartes */
        .floor-header {
            color: #495057; font-size: 0.9rem; font-weight: 800; text-transform: uppercase;
            margin-top: 25px; margin-bottom: 10px; border-bottom: 2px solid #dee2e6; padding-bottom: 5px;
        }
        .room-block { background: white; border-radius: 10px; margin-bottom: 15px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); overflow: hidden; border: 1px solid #e9ecef; }
        .room-title { background: #f8f9fa; padding: 10px 15px; font-weight: bold; border-bottom: 1px solid #e9ecef; display: flex; justify-content: space-between; }
        .recipient-item { padding: 15px; border-bottom: 1px solid #f1f3f5; }
        .recipient-item:last-child { border-bottom: none; }
        .rose-badge { background-color: #fff0f3; color: #d63384; border: 1px solid #ffdeeb; padding: 2px 8px; border-radius: 12px; font-size: 0.8rem; margin-right: 4px; }
    </style>
</head>
<body>

<?php include 'navbar.php'; ?>

<div class="container mt-3">

    <form action="" method="GET" class="mb-3">
        <div class="input-group shadow-sm">
            <span class="input-group-text bg-white border-end-0"><i class="fas fa-search text-muted"></i></span>
            <input type="text" name="q" class="form-control border-start-0 ps-0" placeholder="Chercher un élève..." value="<?php echo htmlspecialchars($searchQuery); ?>">
            <?php if($searchQuery): ?>
                <a href="delivery.php" class="btn btn-outline-secondary bg-white border-start-0">X</a>
            <?php endif; ?>
        </div>
    </form>

    <?php if(!$searchQuery): ?>
        <div class="mb-2">
            <label class="small text-muted fw-bold ms-1">📍 BÂTIMENT</label>
            <div class="scrolling-wrapper">
                <?php foreach($buildings as $b): ?>
                    <a href="?building=<?php echo $b['id']; ?>&hour=<?php echo $selectedHour; ?>" 
                       class="building-btn <?php echo ($selectedBuilding == $b['id']) ? 'active' : ''; ?>">
                        <?php echo htmlspecialchars($b['name']); ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="mb-3">
            <label class="small text-muted fw-bold ms-1">🕒 HEURE</label>
            <div class="scrolling-wrapper">
                <?php for($h=8; $h<=17; $h++): ?>
                    <a href="?building=<?php echo $selectedBuilding; ?>&hour=<?php echo $h; ?>" 
                       class="time-btn <?php echo ($selectedHour == $h) ? 'active' : ''; ?>">
                        <?php echo $h; ?>h
                    </a>
                <?php endfor; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if(empty($groupedData)): ?>
        <div class="text-center py-5">
            <h5 class="text-muted">Aucune livraison ici.</h5>
        </div>
    <?php else: ?>
        <?php foreach($groupedData as $floorName => $roomsInFloor): ?>
            
            <div class="floor-header">
                <i class="fas fa-level-up-alt me-2"></i><?php echo htmlspecialchars($floorName); ?>
            </div>

            <?php foreach($roomsInFloor as $roomName => $dests): ?>
                <div class="room-block">
                    <div class="room-title">
                        <span><?php echo htmlspecialchars($roomName); ?></span>
                        <span class="badge bg-secondary rounded-pill"><?php echo count($dests); ?></span>
                    </div>
                    <div>
                        <?php foreach($dests as $dest): ?>
                            <div class="recipient-item">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <div>
                                        <div class="fw-bold"><?php echo htmlspecialchars($dest['dest_prenom'] . ' ' . $dest['dest_nom']); ?></div>
                                        <div class="small text-muted"><?php echo htmlspecialchars($dest['class_name'] ?? ''); ?></div>
                                    </div>
                                    <form method="POST">
                                        <input type="hidden" name="action" value="mark_distributed">
                                        <input type="hidden" name="recipient_id" value="<?php echo $dest['recipient_id']; ?>">
                                        <button type="submit" class="btn btn-success btn-sm rounded-pill px-3 py-2 shadow-sm" onclick="return confirm('Livrer ?')">
                                            <i class="fas fa-check"></i>
                                        </button>
                                    </form>
                                </div>
                                
                                <div class="mb-2">
                                    <?php if(isset($rosesMap[$dest['recipient_id']])): ?>
                                        <?php foreach($rosesMap[$dest['recipient_id']] as $rose): ?>
                                            <span class="rose-badge"><?php echo $rose['quantity']; ?>x <?php echo htmlspecialchars($rose['name']); ?></span>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>

                                <div class="d-flex align-items-center gap-2 mt-2">
                                    <?php if($dest['is_anonymous']): ?>
                                        <span class="badge bg-dark small">Anonyme</span>
                                    <?php else: ?>
                                        <span class="small text-muted fst-italic">De : <?php echo htmlspecialchars(($dest['buyer_prenom'] ?? '') . ' ' . ($dest['buyer_nom'] ?? '')); ?></span>
                                    <?php endif; ?>

                                    <?php if($dest['message_content']): ?>
                                        <button class="btn btn-sm btn-light border py-0 px-2 small text-primary" type="button" data-bs-toggle="collapse" data-bs-target="#m<?php echo $dest['recipient_id']; ?>">Msg</button>
                                    <?php endif; ?>
                                </div>
                                <?php if($dest['message_content']): ?>
                                    <div class="collapse mt-2" id="m<?php echo $dest['recipient_id']; ?>">
                                        <div class="bg-light p-2 rounded small border-start border-4 border-primary">"<?php echo htmlspecialchars($dest['message_content']); ?>"</div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endforeach; ?>
    <?php endif; ?>

</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>