<?php
// admin.php
require_once 'db.php';
require_once 'auth_check.php'; 

checkAccess('cvl');

// --- 0. DONNÉES DE RÉFÉRENCE ---
$allClasses = $pdo->query("SELECT id, name FROM classes ORDER BY name")->fetchAll(PDO::FETCH_KEY_PAIR);
$allRooms = $pdo->query("SELECT id, name FROM rooms ORDER BY name")->fetchAll(PDO::FETCH_KEY_PAIR);
// On récupère aussi le prix pour le calcul automatique
$stmtRoses = $pdo->query("SELECT id, name, price FROM rose_products ORDER BY price");
$allRoseTypes = $stmtRoses->fetchAll(PDO::FETCH_ASSOC);

// Création d'un tableau simple [id => prix] pour le calcul rapide
$rosePrices = [];
foreach($allRoseTypes as $rt) {
    $rosePrices[$rt['id']] = $rt['price'];
}

$allMessages = $pdo->query("SELECT id, content FROM predefined_messages ORDER BY id")->fetchAll(PDO::FETCH_KEY_PAIR);

// --- 1. TRAITEMENT DES ACTIONS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['order_id'])) {
    $orderId = intval($_POST['order_id']);

    if ($_POST['action'] === 'validate_payment') {
        $stmt = $pdo->prepare("UPDATE orders SET is_paid = 1, paid_at = NOW() WHERE id = ?");
        $stmt->execute([$orderId]);
    } 
    elseif ($_POST['action'] === 'cancel_payment') {
        $stmt = $pdo->prepare("UPDATE orders SET is_paid = 0, paid_at = NULL WHERE id = ?");
        $stmt->execute([$orderId]);
    }
    elseif ($_POST['action'] === 'edit_order') {
        try {
            $pdo->beginTransaction();

            $calculatedTotalPrice = 0.0;

            // 1. Mise à jour Infos Acheteur
            $buyerClass = !empty($_POST['buyer_class_id']) ? $_POST['buyer_class_id'] : NULL;
            $stmt = $pdo->prepare("UPDATE orders SET buyer_nom = ?, buyer_prenom = ?, buyer_class_id = ? WHERE id = ?");
            $stmt->execute([$_POST['buyer_nom'], $_POST['buyer_prenom'], $buyerClass, $orderId]);

            // 2. Mise à jour des Destinataires
            if (isset($_POST['recipients']) && is_array($_POST['recipients'])) {
                foreach ($_POST['recipients'] as $rId => $rData) {
                    $rId = intval($rId);
                    $destClass = !empty($rData['class_id']) ? $rData['class_id'] : NULL;
                    $isAnon = isset($rData['is_anonymous']) ? 1 : 0;
                    
                    // Update table order_recipients
                    $stmtR = $pdo->prepare("UPDATE order_recipients SET dest_nom = ?, dest_prenom = ?, class_id = ?, is_anonymous = ?, message_id = ? WHERE id = ? AND order_id = ?");
                    $stmtR->execute([
                        $rData['nom'], 
                        $rData['prenom'], 
                        $destClass, 
                        $isAnon, 
                        $rData['message_id'], // On sauvegarde l'ID du message choisi
                        $rId, 
                        $orderId
                    ]);

                    // 3. Mise à jour Quantités Roses + Calcul Prix
                    if (isset($rData['roses']) && is_array($rData['roses'])) {
                        foreach ($rData['roses'] as $roseLinkId => $qty) {
                            $qty = intval($qty);
                            if ($qty < 0) $qty = 0;

                            // Update DB
                            $stmtRose = $pdo->prepare("UPDATE recipient_roses SET quantity = ? WHERE id = ?");
                            $stmtRose->execute([$qty, $roseLinkId]);

                            // Récupérer le type de rose associé à ce lien pour avoir le prix
                            // On fait une petite requête pour être sûr de l'ID produit
                            $stmtType = $pdo->prepare("SELECT rose_product_id FROM recipient_roses WHERE id = ?");
                            $stmtType->execute([$roseLinkId]);
                            $prodId = $stmtType->fetchColumn();

                            if ($prodId && isset($rosePrices[$prodId])) {
                                $calculatedTotalPrice += ($qty * $rosePrices[$prodId]);
                            }
                        }
                    }

                    // 4. Mise à jour Planning (Gestion complete 8h-18h)
                    // Le formulaire envoie schedule[heure] = room_id
                    // On a aussi schedule_ids[heure] = id_table (si existant)
                    if (isset($rData['schedule']) && is_array($rData['schedule'])) {
                        foreach ($rData['schedule'] as $hour => $roomId) {
                            $existingId = $rData['schedule_ids'][$hour] ?? null;

                            if (!empty($existingId)) {
                                // Cas A : Une ligne existe déjà en base
                                if (!empty($roomId)) {
                                    // Update salle
                                    $stmtUpd = $pdo->prepare("UPDATE recipient_schedules SET room_id = ? WHERE id = ?");
                                    $stmtUpd->execute([$roomId, $existingId]);
                                } else {
                                    // Suppression (l'admin a mis "Pas de cours")
                                    $stmtDel = $pdo->prepare("DELETE FROM recipient_schedules WHERE id = ?");
                                    $stmtDel->execute([$existingId]);
                                }
                            } else {
                                // Cas B : Aucune ligne n'existait
                                if (!empty($roomId)) {
                                    // Insertion nouvelle ligne
                                    $stmtIns = $pdo->prepare("INSERT INTO recipient_schedules (recipient_id, room_id, hour_slot) VALUES (?, ?, ?)");
                                    $stmtIns->execute([$rId, $roomId, $hour]);
                                }
                            }
                        }
                    }
                }
            }

            // 5. Mise à jour du PRIX TOTAL CALCULÉ
            $stmtPrice = $pdo->prepare("UPDATE orders SET total_price = ? WHERE id = ?");
            $stmtPrice->execute([$calculatedTotalPrice, $orderId]);

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            die("Erreur lors de la modification : " . $e->getMessage());
        }
    }

    header("Location: admin.php");
    exit;
}

// --- 2. STATS ---
$totalRevenue = $pdo->query("SELECT SUM(total_price) FROM orders WHERE is_paid = 1")->fetchColumn() ?: 0;
$totalRoses = $pdo->query("SELECT SUM(quantity) FROM recipient_roses")->fetchColumn() ?: 0;
$totalOrders = $pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn() ?: 0;

// --- 3. REQUÊTE PRINCIPALE ---
$sql = "
    SELECT 
        o.id as order_id,
        o.buyer_nom, o.buyer_prenom, o.created_at, o.total_price,
        o.is_paid, o.paid_at, o.buyer_class_id,
        c_buy.name as buyer_class_name,
        
        r.id as recipient_id,
        r.dest_nom, r.dest_prenom, r.is_anonymous, r.message_id,
        r.is_distributed, r.distributed_at, r.class_id as dest_class_id,
        c_dest.name as dest_class_name,
        pm.content as message_content
    FROM orders o
    JOIN order_recipients r ON o.id = r.order_id
    LEFT JOIN classes c_buy ON o.buyer_class_id = c_buy.id
    LEFT JOIN classes c_dest ON r.class_id = c_dest.id
    LEFT JOIN predefined_messages pm ON r.message_id = pm.id
    ORDER BY o.is_paid ASC, o.created_at DESC 
"; 

try {
    $stmt = $pdo->query($sql);
    $raw_results = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { die("Erreur SQL : " . $e->getMessage()); }

// --- 4. REGROUPEMENT ---
$groupedOrders = [];
foreach ($raw_results as $row) {
    $orderId = $row['order_id'];

    if (!isset($groupedOrders[$orderId])) {
        $groupedOrders[$orderId] = [
            'info' => [
                'id' => $row['order_id'],
                'date' => $row['created_at'],
                'buyer_nom' => $row['buyer_nom'],
                'buyer_prenom' => $row['buyer_prenom'],
                'buyer_class_id' => $row['buyer_class_id'],
                'buyer_class_name' => $row['buyer_class_name'],
                'total_price' => $row['total_price'],
                'is_paid' => $row['is_paid'],
                'paid_at' => $row['paid_at']
            ],
            'recipients' => []
        ];
    }

    // Roses
    $stmtRoses = $pdo->prepare("SELECT rr.id as rose_link_id, rr.quantity, rr.rose_product_id, rp.name FROM recipient_roses rr JOIN rose_products rp ON rr.rose_product_id = rp.id WHERE rr.recipient_id = ?");
    $stmtRoses->execute([$row['recipient_id']]);
    $roses = $stmtRoses->fetchAll(PDO::FETCH_ASSOC);

    // Schedule (Récupération brute)
    $stmtSchedule = $pdo->prepare("SELECT rs.id as schedule_id, rs.hour_slot, rs.room_id, rm.name as room_name FROM recipient_schedules rs JOIN rooms rm ON rs.room_id = rm.id WHERE rs.recipient_id = ?");
    $stmtSchedule->execute([$row['recipient_id']]);
    $rawSchedule = $stmtSchedule->fetchAll(PDO::FETCH_ASSOC);
    
    // Transformation schedule pour accès rapide par heure [8 => ['id'=>1, 'room'=>5], 9 => ...]
    $scheduleMap = [];
    foreach($rawSchedule as $sch) {
        $scheduleMap[$sch['hour_slot']] = [
            'db_id' => $sch['schedule_id'],
            'room_id' => $sch['room_id'],
            'room_name' => $sch['room_name']
        ];
    }

    $groupedOrders[$orderId]['recipients'][] = [
        'id' => $row['recipient_id'],
        'nom' => $row['dest_nom'],
        'prenom' => $row['dest_prenom'],
        'class_id' => $row['dest_class_id'],
        'class_name' => $row['dest_class_name'],
        'is_anonymous' => $row['is_anonymous'],
        'message_id' => $row['message_id'],
        'message_content' => $row['message_content'],
        'is_distributed' => $row['is_distributed'],
        'distributed_at' => $row['distributed_at'],
        'roses' => $roses,
        'schedule_map' => $scheduleMap // On passe la map structurée
    ];
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Admin - Saint Valentin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .card-stat { border:none; border-radius: 15px; color: white; transition: transform 0.2s; }
        .card-stat:hover { transform: translateY(-5px); }
        .bg-money { background: linear-gradient(135deg, #11998e, #38ef7d); }
        .bg-roses { background: linear-gradient(135deg, #ff512f, #dd2476); }
        .bg-orders { background: linear-gradient(135deg, #4568DC, #B06AB3); }
        .table-admin th { font-size: 0.85rem; text-transform: uppercase; background-color: #f8f9fa; }
        .recipient-row { border-bottom: 1px dashed #dee2e6; padding: 10px 0; }
        .recipient-row:last-child { border-bottom: none; }
    </style>
</head>
<body class="bg-light">

<?php include 'navbar.php'; ?>

<div class="container mt-4 mb-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="fw-bold text-dark">Tableau de bord Admin 🕵️</h2>
        <span class="badge bg-dark">Admin: <?php echo $_SESSION['prenom'] ?? 'Moi'; ?></span>
    </div>

    <div class="row mb-4">
        <div class="col-md-4">
            <div class="card card-stat bg-money p-4 mb-3 shadow">
                <h3 class="fw-bold"><?php echo number_format($totalRevenue, 2); ?> €</h3>
                <span class="text-white-50">Recettes Encaissées</span>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card card-stat bg-roses p-4 mb-3 shadow">
                <h3 class="fw-bold"><?php echo $totalRoses; ?> 🌹</h3>
                <span class="text-white-50">Roses à distribuer</span>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card card-stat bg-orders p-4 mb-3 shadow">
                <h3 class="fw-bold"><?php echo $totalOrders; ?></h3>
                <span class="text-white-50">Commandes Totales</span>
            </div>
        </div>
    </div>

    <div class="d-flex gap-2 mb-4">
        <a href="delivery.php" class="btn btn-lg btn-success fw-bold shadow-sm flex-grow-1">
            <i class="fas fa-truck"></i> Mode Distribution
        </a>
    </div>

    <div class="card shadow border-0 rounded-3">
        <div class="card-header bg-white py-3">
            <h5 class="mb-0 fw-bold text-primary">📦 Commandes & Livraisons</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-bordered mb-0 align-middle table-admin">
                    <thead>
                        <tr>
                            <th style="width: 30%;">Commande & Paiement</th>
                            <th style="width: 70%;">Destinataires & Livraison</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($groupedOrders)): ?>
                            <tr><td colspan="2" class="text-center p-5 text-muted">Aucune commande.</td></tr>
                        <?php else: ?>
                            <?php foreach($groupedOrders as $order): ?>
                            <tr>
                                <td class="bg-white <?php echo $order['info']['is_paid'] ? '' : 'table-warning'; ?>">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <span class="badge bg-secondary">#<?php echo $order['info']['id']; ?></span>
                                        <button class="btn btn-sm btn-outline-dark" data-bs-toggle="modal" data-bs-target="#editModal<?php echo $order['info']['id']; ?>">
                                            <i class="fas fa-pencil-alt"></i> Éditer
                                        </button>
                                    </div>
                                    <small class="text-muted d-block mb-2"><?php echo date('d/m H:i', strtotime($order['info']['date'])); ?></small>
                                    
                                    <h5 class="fw-bold text-dark mb-1">
                                        <?php echo htmlspecialchars($order['info']['buyer_prenom'] . ' ' . $order['info']['buyer_nom']); ?>
                                    </h5>
                                    <span class="badge bg-light text-dark border mb-3">
                                        <?php echo htmlspecialchars($order['info']['buyer_class_name'] ?? '?'); ?>
                                    </span>

                                    <div class="card bg-white border shadow-sm p-3 mt-2">
                                        <div class="d-flex justify-content-between fw-bold mb-2">
                                            <span>À payer :</span>
                                            <span class="text-primary fs-5"><?php echo number_format($order['info']['total_price'], 2); ?> €</span>
                                        </div>
                                        <div class="text-center">
                                            <?php if($order['info']['is_paid']): ?>
                                                <div class="alert alert-success py-1 px-2 mb-1 d-flex align-items-center justify-content-center" style="font-size: 0.9em;">
                                                    <i class="fas fa-check-circle me-2"></i> 
                                                    Payé le <?php echo date('d/m à H:i', strtotime($order['info']['paid_at'])); ?>
                                                </div>
                                                <form method="POST" onsubmit="return confirm('⚠️ Attention : Annuler le paiement ?');">
                                                    <input type="hidden" name="action" value="cancel_payment">
                                                    <input type="hidden" name="order_id" value="<?php echo $order['info']['id']; ?>">
                                                    <button type="submit" class="btn btn-link btn-sm text-muted text-decoration-none" style="font-size: 0.8em;">Annuler</button>
                                                </form>
                                            <?php else: ?>
                                                <form method="POST">
                                                    <input type="hidden" name="action" value="validate_payment">
                                                    <input type="hidden" name="order_id" value="<?php echo $order['info']['id']; ?>">
                                                    <button type="submit" class="btn btn-danger w-100 fw-bold shadow-sm pulse-button">
                                                        <i class="fas fa-hand-holding-dollar"></i> Valider Encaissement
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>

                                <td class="p-0">
                                    <?php foreach($order['recipients'] as $dest): ?>
                                    <div class="recipient-row p-3">
                                        <div class="row">
                                            <div class="col-md-4 border-end">
                                                <div class="fw-bold text-primary">
                                                    <?php echo htmlspecialchars($dest['prenom'] . ' ' . $dest['nom']); ?>
                                                </div>
                                                <span class="badge bg-info text-dark mb-1"><?php echo htmlspecialchars($dest['class_name'] ?? '?'); ?></span>
                                                <?php if($dest['is_anonymous']): ?>
                                                    <span class="badge bg-dark">🕵️ Anonyme</span>
                                                <?php endif; ?>
                                                <div class="mt-2">
                                                    <?php if($dest['is_distributed']): ?>
                                                        <span class="badge bg-success badge-status">✅ Livré</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-warning text-dark badge-status">⏳ À livrer</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>

                                            <div class="col-md-4 border-end">
                                                <ul class="list-unstyled mb-2">
                                                <?php foreach($dest['roses'] as $rose): ?>
                                                    <li class="small">
                                                        <span class="fw-bold text-danger"><?php echo $rose['quantity']; ?>x</span> 
                                                        <?php echo htmlspecialchars($rose['name']); ?>
                                                    </li>
                                                <?php endforeach; ?>
                                                </ul>
                                                <?php if($dest['message_content']): ?>
                                                    <div class="bg-light p-1 rounded small fst-italic text-muted border">
                                                        "<?php echo htmlspecialchars($dest['message_content']); ?>"
                                                    </div>
                                                <?php endif; ?>
                                            </div>

                                            <div class="col-md-4">
                                                <strong class="small text-uppercase text-muted">📍 Localisation</strong>
                                                <div class="mt-1">
                                                    <?php 
                                                        // Affichage simple pour le tableau (seulement les slots remplis)
                                                        $hasSchedule = false;
                                                        ksort($dest['schedule_map']);
                                                        foreach($dest['schedule_map'] as $hour => $slot) {
                                                            if(!empty($slot['room_name'])) {
                                                                echo "<div class='small mb-1'><span class='fw-bold'>{$hour}h</span> : ".htmlspecialchars($slot['room_name'])."</div>";
                                                                $hasSchedule = true;
                                                            }
                                                        }
                                                        if(!$hasSchedule) echo "<span class='text-danger small'>Pas de salle</span>";
                                                    ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </td>
                            </tr>

                            <div class="modal fade" id="editModal<?php echo $order['info']['id']; ?>" tabindex="-1" aria-hidden="true">
                                <div class="modal-dialog modal-xl"> <div class="modal-content">
                                        <div class="modal-header bg-dark text-white">
                                            <h5 class="modal-title"><i class="fas fa-edit"></i> Éditer la commande #<?php echo $order['info']['id']; ?></h5>
                                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>
                                        <form method="POST">
                                            <div class="modal-body">
                                                <input type="hidden" name="action" value="edit_order">
                                                <input type="hidden" name="order_id" value="<?php echo $order['info']['id']; ?>">

                                                <h6 class="text-primary fw-bold border-bottom pb-2">👤 Informations Acheteur</h6>
                                                <div class="row g-3 mb-3">
                                                    <div class="col-md-4">
                                                        <label class="form-label small">Prénom</label>
                                                        <input type="text" name="buyer_prenom" class="form-control" value="<?php echo htmlspecialchars($order['info']['buyer_prenom']); ?>" required>
                                                    </div>
                                                    <div class="col-md-4">
                                                        <label class="form-label small">Nom</label>
                                                        <input type="text" name="buyer_nom" class="form-control" value="<?php echo htmlspecialchars($order['info']['buyer_nom']); ?>" required>
                                                    </div>
                                                    <div class="col-md-4">
                                                        <label class="form-label small">Classe</label>
                                                        <select name="buyer_class_id" class="form-select">
                                                            <option value="">-- Choisir --</option>
                                                            <?php foreach($allClasses as $id => $name): ?>
                                                                <option value="<?php echo $id; ?>" <?php if($order['info']['buyer_class_id'] == $id) echo 'selected'; ?>>
                                                                    <?php echo htmlspecialchars($name); ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                </div>

                                                <?php foreach($order['recipients'] as $index => $dest): ?>
                                                    <div class="card mb-3 bg-light border-0">
                                                        <div class="card-body">
                                                            <h6 class="text-danger fw-bold border-bottom pb-2">❤️ Destinataire <?php echo $index + 1; ?></h6>
                                                            
                                                            <div class="row g-2 mb-3">
                                                                <div class="col-md-3">
                                                                    <label class="small">Prénom</label>
                                                                    <input type="text" name="recipients[<?php echo $dest['id']; ?>][prenom]" class="form-control form-control-sm" value="<?php echo htmlspecialchars($dest['prenom']); ?>">
                                                                </div>
                                                                <div class="col-md-3">
                                                                    <label class="small">Nom</label>
                                                                    <input type="text" name="recipients[<?php echo $dest['id']; ?>][nom]" class="form-control form-control-sm" value="<?php echo htmlspecialchars($dest['nom']); ?>">
                                                                </div>
                                                                <div class="col-md-3">
                                                                    <label class="small">Classe</label>
                                                                    <select name="recipients[<?php echo $dest['id']; ?>][class_id]" class="form-select form-select-sm">
                                                                        <option value="">-- Inconnue --</option>
                                                                        <?php foreach($allClasses as $id => $name): ?>
                                                                            <option value="<?php echo $id; ?>" <?php if($dest['class_id'] == $id) echo 'selected'; ?>><?php echo htmlspecialchars($name); ?></option>
                                                                        <?php endforeach; ?>
                                                                    </select>
                                                                </div>
                                                                <div class="col-md-3 d-flex align-items-center">
                                                                    <div class="form-check form-switch pt-4">
                                                                        <input class="form-check-input" type="checkbox" role="switch" 
                                                                            name="recipients[<?php echo $dest['id']; ?>][is_anonymous]" 
                                                                            value="1" 
                                                                            id="anonSwitch_<?php echo $dest['id']; ?>"
                                                                            <?php if($dest['is_anonymous']) echo 'checked'; ?>>
                                                                        <label class="form-check-label small" for="anonSwitch_<?php echo $dest['id']; ?>">Anonyme</label>
                                                                    </div>
                                                                </div>
                                                            </div>

                                                            <div class="mb-3 border p-2 bg-white rounded">
                                                                <label class="small fw-bold">🌹 Quantités (Prix recalculé auto)</label>
                                                                <div class="row">
                                                                    <?php foreach($dest['roses'] as $rose): ?>
                                                                        <div class="col-4">
                                                                            <label class="small text-muted"><?php echo htmlspecialchars($rose['name']); ?></label>
                                                                            <input type="number" min="0" name="recipients[<?php echo $dest['id']; ?>][roses][<?php echo $rose['rose_link_id']; ?>]" class="form-control form-control-sm" value="<?php echo $rose['quantity']; ?>">
                                                                        </div>
                                                                    <?php endforeach; ?>
                                                                </div>
                                                            </div>

                                                            <div class="mb-3">
                                                                <label class="small fw-bold">Message</label>
                                                                <select name="recipients[<?php echo $dest['id']; ?>][message_id]" class="form-select form-select-sm">
                                                                    <?php foreach($allMessages as $msgId => $msgContent): ?>
                                                                        <option value="<?php echo $msgId; ?>" <?php if($dest['message_id'] == $msgId) echo 'selected'; ?>>
                                                                            <?php echo htmlspecialchars($msgContent); ?>
                                                                        </option>
                                                                    <?php endforeach; ?>
                                                                </select>
                                                            </div>

                                                            <div class="mb-2 border p-2 bg-white rounded">
                                                                <label class="small fw-bold">📅 Emploi du temps complet (8h - 17h)</label>
                                                                <div class="row g-1">
                                                                    <?php 
                                                                    // BOUCLE DE 8H A 17H POUR AFFICHER TOUS LES CRENEAUX
                                                                    for ($h = 8; $h <= 17; $h++): 
                                                                        // Vérifie si un cours existe déjà à cette heure
                                                                        $currentRoomId = $dest['schedule_map'][$h]['room_id'] ?? '';
                                                                        $currentDbId = $dest['schedule_map'][$h]['db_id'] ?? '';
                                                                    ?>
                                                                        <div class="col-md-2 col-4 mb-2">
                                                                            <label class="small text-muted" style="font-size: 0.75rem;"><?php echo $h; ?>h-<?php echo $h+1; ?>h</label>
                                                                            <input type="hidden" name="recipients[<?php echo $dest['id']; ?>][schedule_ids][<?php echo $h; ?>]" value="<?php echo $currentDbId; ?>">
                                                                            
                                                                            <select name="recipients[<?php echo $dest['id']; ?>][schedule][<?php echo $h; ?>]" class="form-select form-select-sm" style="font-size: 0.75rem;">
                                                                                <option value="" class="text-muted">- Vide -</option>
                                                                                <?php foreach($allRooms as $rId => $rName): ?>
                                                                                    <option value="<?php echo $rId; ?>" <?php if($currentRoomId == $rId) echo 'selected'; ?>><?php echo htmlspecialchars($rName); ?></option>
                                                                                <?php endforeach; ?>
                                                                            </select>
                                                                        </div>
                                                                    <?php endfor; ?>
                                                                </div>
                                                            </div>

                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>

                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fermer</button>
                                                <button type="submit" class="btn btn-primary" onclick="return confirm('Confirmer les modifications ? Le prix total sera recalculé.');">Enregistrer</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>