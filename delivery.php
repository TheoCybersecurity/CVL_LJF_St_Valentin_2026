<?php
// delivery.php
require_once 'db.php';
require_once 'auth_check.php'; 
checkAccess('cvl');

// --- 1. TRAITEMENT DES ACTIONS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    $id = 0;
    if (isset($_POST['recipient_id'])) $id = intval($_POST['recipient_id']);
    elseif (isset($_POST['recipient_ids'])) $id = intval($_POST['recipient_ids']);

    if ($id > 0) {
        $actionType = '';

        if ((isset($_POST['action']) && $_POST['action'] === 'mark_distributed') || isset($_POST['mark_prepared'])) {
            $stmt = $pdo->prepare("UPDATE order_recipients SET is_distributed = 1, distributed_at = NOW() WHERE id = ?");
            $stmt->execute([$id]);
            $actionType = 'marked';
        }
        elseif (isset($_POST['unmark_prepared'])) {
            $stmt = $pdo->prepare("UPDATE order_recipients SET is_distributed = 0, distributed_at = NULL WHERE id = ?");
            $stmt->execute([$id]);
            $actionType = 'unmarked';
        }

        if ($actionType) {
            $queryParams = $_GET;
            $queryParams['last_action'] = $actionType;
            $queryParams['last_ids'] = $id;
            header("Location: delivery.php?" . http_build_query($queryParams));
            exit;
        }
    }
}

// --- 2. GESTION DES FILTRES ---
$buildings = $pdo->query("SELECT id, name FROM buildings ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$currentHour = intval(date('H'));
if ($currentHour < 8 || $currentHour > 17) $currentHour = 8;
$selectedHour = isset($_GET['hour']) ? $_GET['hour'] : $currentHour; 

$defaultBuildingId = count($buildings) > 0 ? $buildings[0]['id'] : 0;
$selectedBuilding = isset($_GET['building']) ? $_GET['building'] : $defaultBuildingId;

$searchQuery = isset($_GET['q']) ? trim($_GET['q']) : '';
$view = isset($_GET['view']) ? $_GET['view'] : 'todo';

// --- 3. REQUÊTE SQL ---
$params = [];
$sql = "";

if ($searchQuery) {
    // --- MODE RECHERCHE ---
    $sql = "
        SELECT 
            r.id as recipient_id, r.dest_nom, r.dest_prenom, r.is_anonymous, r.message_id, r.distributed_at,
            c.name as class_name,
            pm.content as message_content,
            'Recherche' as room_name, 
            'Résultats' as floor_name, 
            '' as building_name,
            o.buyer_prenom, o.buyer_nom
        FROM order_recipients r
        JOIN orders o ON r.order_id = o.id
        LEFT JOIN classes c ON r.class_id = c.id
        LEFT JOIN predefined_messages pm ON r.message_id = pm.id
        WHERE o.is_paid = 1 
        AND (r.dest_nom LIKE :q OR r.dest_prenom LIKE :q)
    ";
    
    if ($view === 'done') {
        $sql .= " AND r.is_distributed = 1 ORDER BY r.distributed_at DESC ";
    } else {
        $sql .= " AND r.is_distributed = 0 ";
    }
    $sql .= " LIMIT 50";
    $params['q'] = "%$searchQuery%";

} else {
    // --- MODE STANDARD ---
    $sql = "
        SELECT 
            r.id as recipient_id, r.dest_nom, r.dest_prenom, r.is_anonymous, r.message_id, r.distributed_at,
            c.name as class_name,
            pm.content as message_content,
            rs.room_id, 
            rm.name as room_name,
            f.name as floor_name,
            f.level_number,
            b.name as building_name,
            o.buyer_prenom, o.buyer_nom
        FROM order_recipients r
        JOIN orders o ON r.order_id = o.id
        LEFT JOIN classes c ON r.class_id = c.id
        LEFT JOIN predefined_messages pm ON r.message_id = pm.id
        JOIN recipient_schedules rs ON (r.id = rs.recipient_id) 
        JOIN rooms rm ON rs.room_id = rm.id
        JOIN floors f ON rm.floor_id = f.id
        JOIN buildings b ON rm.building_id = b.id
        WHERE o.is_paid = 1 
    ";

    if ($selectedHour !== 'all') {
        $sql .= " AND rs.hour_slot = :hour ";
        $params['hour'] = $selectedHour;
    } else {
        $sql .= " AND rs.hour_slot IS NOT NULL ";
    }

    if ($selectedBuilding !== 'all') {
        $sql .= " AND rm.building_id = :building ";
        $params['building'] = $selectedBuilding;
    }

    if ($view === 'done') {
        $sql .= " AND r.is_distributed = 1 ORDER BY r.distributed_at DESC ";
    } else {
        $sql .= " AND r.is_distributed = 0 ORDER BY b.name ASC, f.level_number ASC, rm.name ASC, r.dest_nom ASC ";
    }
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
        if ($selectedBuilding === 'all') {
            $floorLabel = $recip['building_name'] . ' - ' . ($recip['floor_name'] ?? 'Étage Inconnu');
        } else {
            $floorLabel = $recip['floor_name'] ?? 'Étage Inconnu';
        }
        
        $room = $recip['room_name'];
        $groupedData[$floorLabel][$room][] = $recip;
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
        
        .time-btn, .building-btn {
            display: inline-block; padding: 8px 16px; margin-right: 8px;
            border-radius: 20px; background: white; color: #555; text-decoration: none;
            font-size: 0.9rem; font-weight: 600; border: 1px solid #dee2e6;
        }
        .building-btn { border-radius: 8px; background: #e9ecef; }
        .active { background: #dc3545; color: white; border-color: #dc3545; }
        .building-btn.active { background: #0d6efd; border-color: #0d6efd; }

        .floor-header {
            color: #495057; font-size: 0.9rem; font-weight: 800; text-transform: uppercase;
            margin-top: 25px; margin-bottom: 10px; border-bottom: 2px solid #dee2e6; padding-bottom: 5px;
        }
        .room-block { background: white; border-radius: 10px; margin-bottom: 15px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); overflow: hidden; border: 1px solid #e9ecef; }
        .room-title { background: #f8f9fa; padding: 10px 15px; font-weight: bold; border-bottom: 1px solid #e9ecef; display: flex; justify-content: space-between; }
        
        .recipient-item { padding: 15px; border-bottom: 1px solid #f1f3f5; }
        .recipient-item.done { background-color: #f8fff9; opacity: 0.8; }
        .recipient-item:last-child { border-bottom: none; }
        
        /* --- NOUVEAUX STYLES POUR LES BADGES ROSES --- */
        .badge-rose-rouge { background-color: #dc3545; color: white; }
        .badge-rose-blanche { background-color: #ffffff; color: #212529; border: 1px solid #ced4da; }
        .badge-rose-pink { background-color: #ffc2d1; color: #880e4f; }
        
        .nav-tabs .nav-link { color: #495057; font-weight: bold; }
        .nav-tabs .nav-link.active { color: #dc3545; border-bottom: 3px solid #dc3545; }
    </style>
</head>
<body>

<?php include 'navbar.php'; ?>

<div class="container mt-3">

    <form action="" method="GET" class="mb-3">
        <input type="hidden" name="view" value="<?php echo $view; ?>">
        <div class="input-group shadow-sm">
            <span class="input-group-text bg-white border-end-0"><i class="fas fa-search text-muted"></i></span>
            <input type="text" name="q" class="form-control border-start-0 ps-0" placeholder="Chercher un élève..." value="<?php echo htmlspecialchars($searchQuery); ?>">
            <?php if($searchQuery): ?>
                <a href="delivery.php?view=<?php echo $view; ?>" class="btn btn-outline-secondary bg-white border-start-0">X</a>
            <?php endif; ?>
        </div>
    </form>

    <ul class="nav nav-tabs mb-3">
        <li class="nav-item">
            <a class="nav-link <?php echo $view === 'todo' ? 'active' : ''; ?>" 
               href="?view=todo&building=<?php echo $selectedBuilding; ?>&hour=<?php echo $selectedHour; ?>">
               <i class="fas fa-truck"></i> À LIVRER
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $view === 'done' ? 'active' : ''; ?>" 
               href="?view=done&building=<?php echo $selectedBuilding; ?>&hour=<?php echo $selectedHour; ?>">
               <i class="fas fa-history"></i> HISTORIQUE
            </a>
        </li>
    </ul>

    <?php if(!$searchQuery): ?>
        
        <div class="mb-2">
            <label class="small text-muted fw-bold ms-1">📍 BÂTIMENT</label>
            <div class="scrolling-wrapper">
                <a href="?building=all&hour=<?php echo $selectedHour; ?>&view=<?php echo $view; ?>" 
                   class="building-btn <?php echo ($selectedBuilding === 'all') ? 'active' : ''; ?>">
                   Tout
                </a>
                
                <?php foreach($buildings as $b): ?>
                    <a href="?building=<?php echo $b['id']; ?>&hour=<?php echo $selectedHour; ?>&view=<?php echo $view; ?>" 
                       class="building-btn <?php echo ($selectedBuilding == $b['id']) ? 'active' : ''; ?>">
                        <?php echo htmlspecialchars($b['name']); ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="mb-3">
            <label class="small text-muted fw-bold ms-1">🕒 HEURE</label>
            <div class="scrolling-wrapper">
                <a href="?building=<?php echo $selectedBuilding; ?>&hour=all&view=<?php echo $view; ?>" 
                   class="time-btn <?php echo ($selectedHour === 'all') ? 'active' : ''; ?>">
                   Tout
                </a>

                <?php for($h=8; $h<=17; $h++): ?>
                    <a href="?building=<?php echo $selectedBuilding; ?>&hour=<?php echo $h; ?>&view=<?php echo $view; ?>" 
                       class="time-btn <?php echo ($selectedHour == $h) ? 'active' : ''; ?>">
                        <?php echo $h; ?>h
                    </a>
                <?php endfor; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if(empty($groupedData)): ?>
        <div class="text-center py-5">
            <h5 class="text-muted">
                <?php echo ($view === 'todo') ? 'Aucune livraison à faire avec ces filtres.' : 'Aucune livraison terminée.'; ?>
            </h5>
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
                            <div class="recipient-item <?php echo $view === 'done' ? 'done' : ''; ?>">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <div>
                                        <div class="fw-bold"><?php echo htmlspecialchars($dest['dest_prenom'] . ' ' . $dest['dest_nom']); ?></div>
                                        <div class="small text-muted"><?php echo htmlspecialchars($dest['class_name'] ?? ''); ?></div>
                                        
                                        <?php if($view === 'done'): ?>
                                            <div class="small text-success">
                                                <i class="fas fa-check-double"></i> Livré à <?php echo date('H:i', strtotime($dest['distributed_at'])); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <form method="POST">
                                        <input type="hidden" name="recipient_id" value="<?php echo $dest['recipient_id']; ?>">
                                        
                                        <?php if($view === 'todo'): ?>
                                            <input type="hidden" name="action" value="mark_distributed">
                                            <button type="submit" class="btn btn-success btn-sm rounded-pill px-3 py-2 shadow-sm">
                                                <i class="fas fa-check"></i>
                                            </button>
                                        <?php else: ?>
                                            <input type="hidden" name="unmark_prepared" value="1">
                                            <button type="submit" class="btn btn-outline-danger btn-sm rounded-pill px-3 py-2 shadow-sm" onclick="return confirm('Annuler cette livraison ?')">
                                                <i class="fas fa-undo"></i>
                                            </button>
                                        <?php endif; ?>
                                    </form>
                                </div>
                                
                                <div class="mb-2">
                                    <?php if(isset($rosesMap[$dest['recipient_id']])): ?>
                                        <?php foreach($rosesMap[$dest['recipient_id']] as $rose): ?>
                                            
                                            <?php
                                                // Logique de couleur
                                                $colorName = mb_strtolower($rose['name']);
                                                $badgeClass = "bg-secondary text-white"; 
                                                $emoji = "🌹";

                                                if (strpos($colorName, 'rouge') !== false) {
                                                    $badgeClass = "badge-rose-rouge";
                                                    $emoji = "🌹";
                                                } elseif (strpos($colorName, 'blanche') !== false) {
                                                    $badgeClass = "badge-rose-blanche";
                                                    $emoji = "🤍";
                                                } elseif (strpos($colorName, 'rose') !== false) {
                                                    $badgeClass = "badge-rose-pink";
                                                    $emoji = "🌸";
                                                }
                                            ?>

                                            <span class="badge rounded-pill <?php echo $badgeClass; ?> mb-1 p-2">
                                                <span class="fw-bold"><?php echo $rose['quantity']; ?></span>
                                                <?php echo $emoji; ?> 
                                                <span class="small"><?php echo htmlspecialchars($rose['name']); ?></span>
                                            </span>

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

<?php include 'toast_notifications.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>