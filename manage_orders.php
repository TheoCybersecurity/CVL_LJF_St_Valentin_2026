<?php
// manage_orders.php
require_once 'db.php';
require_once 'auth_check.php'; 

checkAccess('cvl');

// --- 0. DONNÉES DE RÉFÉRENCE ---
$allClasses = $pdo->query("SELECT id, name FROM classes ORDER BY name")->fetchAll(PDO::FETCH_KEY_PAIR);
$allRooms = $pdo->query("SELECT id, name FROM rooms ORDER BY name")->fetchAll(PDO::FETCH_KEY_PAIR);

// Produits (Roses)
$stmtRoses = $pdo->query("SELECT id, name FROM rose_products");
$allRoseTypes = $stmtRoses->fetchAll(PDO::FETCH_ASSOC);

// Grille tarifaire
$stmtPrices = $pdo->query("SELECT quantity, price FROM roses_prices");
$rosesPriceTable = $stmtPrices->fetchAll(PDO::FETCH_KEY_PAIR); 

$allMessages = $pdo->query("SELECT id, content FROM predefined_messages ORDER BY position ASC, id ASC")->fetchAll(PDO::FETCH_KEY_PAIR);

// --- 1. TRAITEMENT DES ACTIONS (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['order_id'])) {
    $orderId = intval($_POST['order_id']);
    $msgSuccess = "Action effectuée avec succès.";

    try {
        if ($_POST['action'] === 'validate_payment') {
            $adminId = $_SESSION['user_id'] ?? null; 
            $stmt = $pdo->prepare("UPDATE orders SET is_paid = 1, paid_at = NOW(), paid_by_cvl_id = ? WHERE id = ?");
            $stmt->execute([$adminId, $orderId]);
            $msgSuccess = "Paiement validé pour la commande #$orderId !";
        } 
        elseif ($_POST['action'] === 'cancel_payment') {
            $stmt = $pdo->prepare("UPDATE orders SET is_paid = 0, paid_at = NULL, paid_by_cvl_id = NULL WHERE id = ?");
            $stmt->execute([$orderId]);
            $msgSuccess = "Paiement annulé pour la commande #$orderId.";
        }
        elseif ($_POST['action'] === 'edit_order') {
            $pdo->beginTransaction();
            $calculatedTotalPrice = 0.0;

            // =========================================================
            // A. Mise à jour Infos Acheteur (CORRECTION : Table USERS)
            // =========================================================
            // 1. On récupère l'ID de l'utilisateur lié à la commande
            $stmtGetUserId = $pdo->prepare("SELECT user_id FROM orders WHERE id = ?");
            $stmtGetUserId->execute([$orderId]);
            $userId = $stmtGetUserId->fetchColumn();

            if ($userId) {
                // 2. On met à jour la table users
                $buyerClass = !empty($_POST['buyer_class_id']) ? $_POST['buyer_class_id'] : NULL;
                $stmtUserUpdate = $pdo->prepare("UPDATE users SET nom = ?, prenom = ?, class_id = ? WHERE user_id = ?");
                $stmtUserUpdate->execute([$_POST['buyer_nom'], $_POST['buyer_prenom'], $buyerClass, $userId]);
            }

            // =========================================================
            // B. Mise à jour des Destinataires
            // =========================================================
            if (isset($_POST['recipients']) && is_array($_POST['recipients'])) {
                foreach ($_POST['recipients'] as $orderRecipientId => $rData) {
                    $orderRecipientId = intval($orderRecipientId); // ID du cadeau (order_recipients.id)
                    $studentId = intval($rData['student_id']);     // ID de l'élève (recipients.id)
                    
                    // 1. Mise à jour infos Élève (Table RECIPIENTS)
                    $destClass = !empty($rData['class_id']) ? $rData['class_id'] : NULL;
                    $stmtStudent = $pdo->prepare("UPDATE recipients SET nom = ?, prenom = ?, class_id = ? WHERE id = ?");
                    $stmtStudent->execute([$rData['nom'], $rData['prenom'], $destClass, $studentId]);

                    // 2. Mise à jour infos Cadeau (Table ORDER_RECIPIENTS)
                    $isAnon = isset($rData['is_anonymous']) ? 1 : 0;
                    $stmtGift = $pdo->prepare("UPDATE order_recipients SET is_anonymous = ?, message_id = ? WHERE id = ?");
                    $stmtGift->execute([$isAnon, $rData['message_id'], $orderRecipientId]);

                    // 3. Mise à jour Quantités Roses & Calcul Prix
                    $totalRosesForRecipient = 0;
                    if (isset($rData['roses']) && is_array($rData['roses'])) {
                        foreach ($rData['roses'] as $roseLinkId => $qty) {
                            $qty = intval($qty);
                            if ($qty < 0) $qty = 0;
                            
                            $stmtRose = $pdo->prepare("UPDATE recipient_roses SET quantity = ? WHERE id = ?");
                            $stmtRose->execute([$qty, $roseLinkId]);
                            $totalRosesForRecipient += $qty;
                        }
                    }

                    // Calcul prix (Logique conservée)
                    if ($totalRosesForRecipient > 0) {
                        if (isset($rosesPriceTable[$totalRosesForRecipient])) {
                            $calculatedTotalPrice += floatval($rosesPriceTable[$totalRosesForRecipient]);
                        } else {
                            $maxQtyDefined = max(array_keys($rosesPriceTable));
                            $basePrice = $rosesPriceTable[$maxQtyDefined] ?? ($totalRosesForRecipient * 2); 
                            if($totalRosesForRecipient > $maxQtyDefined) {
                                $calculatedTotalPrice += ($totalRosesForRecipient * 2.00); 
                            } else {
                                $calculatedTotalPrice += $basePrice;
                            }
                        }
                    }

                    // 4. Mise à jour Planning (Table SCHEDULES)
                    if (isset($rData['schedule']) && is_array($rData['schedule'])) {
                        $schedUpdates = [];
                        $schedValues = [];
                        
                        foreach ($rData['schedule'] as $hour => $roomName) {
                            if ($hour >= 8 && $hour <= 17) {
                                $colName = 'h' . str_pad($hour, 2, '0', STR_PAD_LEFT);
                                $schedUpdates[] = "$colName = ?";
                                $schedValues[] = $roomName;
                            }
                        }

                        if (!empty($schedUpdates)) {
                            $sqlSched = "UPDATE schedules SET " . implode(', ', $schedUpdates) . " WHERE recipient_id = ?";
                            $schedValues[] = $studentId;
                            $stmtSched = $pdo->prepare($sqlSched);
                            $stmtSched->execute($schedValues);
                        }
                    }
                }
            }

            // 5. Mise à jour du PRIX TOTAL (Table ORDERS)
            $stmtPrice = $pdo->prepare("UPDATE orders SET total_price = ? WHERE id = ?");
            $stmtPrice->execute([$calculatedTotalPrice, $orderId]);

            $pdo->commit();
            $msgSuccess = "Commande #$orderId modifiée avec succès.";
        }
        
        header("Location: manage_orders.php?msg_success=" . urlencode($msgSuccess));
        exit;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        header("Location: manage_orders.php?msg_error=" . urlencode("Erreur : " . $e->getMessage()));
        exit;
    }
}

// --- 2. STATS (Inchangées) ---
$totalRevenue = $pdo->query("SELECT SUM(total_price) FROM orders WHERE is_paid = 1")->fetchColumn() ?: 0;
$totalRoses = $pdo->query("SELECT SUM(quantity) FROM recipient_roses")->fetchColumn() ?: 0;
$totalOrders = $pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn() ?: 0;
$countUnpaid = $pdo->query("SELECT COUNT(*) FROM orders WHERE is_paid = 0")->fetchColumn();
$percentPaid = ($totalOrders > 0) ? round((($totalOrders - $countUnpaid) / $totalOrders) * 100) : 0;

// --- 3. RECHERCHE ET AFFICHAGE ---
$search = isset($_GET['q']) ? trim($_GET['q']) : '';

// Requête adaptée : Jointure avec la table USERS pour l'acheteur
$sql = "
    SELECT 
        o.id as order_id,
        o.created_at, 
        o.total_price,
        o.is_paid, 
        o.paid_at, 
        
        -- Infos Acheteur (Via Users)
        u.nom as buyer_nom, 
        u.prenom as buyer_prenom,
        u.class_id as buyer_class_id,
        c_buy.name as buyer_class_name,
        
        -- Infos Destinataire
        ort.id as order_recipient_id,
        ort.is_anonymous, 
        ort.message_id,
        ort.is_distributed, 
        ort.distributed_at,
        
        r.id as student_id,
        r.nom as dest_nom, 
        r.prenom as dest_prenom,
        r.class_id as dest_class_id,
        
        c_dest.name as dest_class_name,
        pm.content as message_content
    FROM orders o
    JOIN users u ON o.user_id = u.user_id                   -- Jointure Acheteur
    JOIN order_recipients ort ON o.id = ort.order_id        -- Jointure Liaison Cadeau
    JOIN recipients r ON ort.recipient_id = r.id            -- Jointure Destinataire (Élève)
    LEFT JOIN classes c_buy ON u.class_id = c_buy.id        -- Classe Acheteur
    LEFT JOIN classes c_dest ON r.class_id = c_dest.id      -- Classe Destinataire
    LEFT JOIN predefined_messages pm ON ort.message_id = pm.id
";

$params = [];
if (!empty($search)) {
    // Filtres mis à jour : on cherche sur 'u.' (users) au lieu de 'o.'
    $sql .= " WHERE 
        o.id LIKE :s OR 
        u.nom LIKE :s OR 
        u.prenom LIKE :s OR 
        CONCAT(u.prenom, ' ', u.nom) LIKE :s OR 
        c_buy.name LIKE :s OR 
        
        r.nom LIKE :s OR 
        r.prenom LIKE :s OR 
        CONCAT(r.prenom, ' ', r.nom) LIKE :s OR 
        c_dest.name LIKE :s
    ";
    $params[':s'] = '%' . $search . '%';
}

$sql .= " ORDER BY o.is_paid ASC, o.created_at DESC";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $raw_results = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { die("Erreur SQL : " . $e->getMessage()); }

// --- 4. REGROUPEMENT DES DONNÉES ---
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

    // Récupérer les roses liées
    $stmtRoses = $pdo->prepare("SELECT rr.id as rose_link_id, rr.quantity, rr.rose_product_id, rp.name FROM recipient_roses rr JOIN rose_products rp ON rr.rose_product_id = rp.id WHERE rr.recipient_id = ?");
    $stmtRoses->execute([$row['order_recipient_id']]);
    $roses = $stmtRoses->fetchAll(PDO::FETCH_ASSOC);

    // Récupérer l'emploi du temps
    $stmtSchedule = $pdo->prepare("SELECT h08, h09, h10, h11, h12, h13, h14, h15, h16, h17 FROM schedules WHERE recipient_id = ?");
    $stmtSchedule->execute([$row['student_id']]);
    $schedRow = $stmtSchedule->fetch(PDO::FETCH_ASSOC);

    $scheduleMap = [];
    if ($schedRow) {
        foreach ($schedRow as $col => $roomName) {
            $hour = intval(substr($col, 1)); 
            $scheduleMap[$hour] = $roomName;
        }
    }

    $groupedOrders[$orderId]['recipients'][] = [
        'order_recipient_id' => $row['order_recipient_id'],
        'student_id' => $row['student_id'],
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
        'schedule_map' => $scheduleMap
    ];
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
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
        .pulse-button { animation: pulse 2s infinite; }
        @keyframes pulse { 0% { transform: scale(1); box-shadow: 0 0 0 0 rgba(220, 53, 69, 0.7); } 70% { transform: scale(1.02); box-shadow: 0 0 0 10px rgba(220, 53, 69, 0); } 100% { transform: scale(1); box-shadow: 0 0 0 0 rgba(220, 53, 69, 0); } }
    </style>
</head>
<body class="bg-light">

<?php include 'navbar.php'; ?>
<?php include 'toast_notifications.php'; ?>

<div class="container mt-4 mb-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="fw-bold text-dark mb-0">
                Tableau de bord CVL <i class="fas fa-clipboard-list ms-2"></i>
            </h2>
            <span class="text-muted small">Bienvenue, <?php echo htmlspecialchars($_SESSION['prenom'] ?? 'Admin'); ?></span>
        </div>
        <div class="d-flex gap-2">
            <a href="stats.php" class="btn btn-outline-primary shadow-sm">
                <i class="fas fa-chart-pie me-2"></i>Stats Détaillées
            </a>
            <?php if(isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
                <a href="admin.php" class="btn btn-dark shadow-sm"><i class="fas fa-cogs"></i></a>
            <?php endif; ?>
        </div>
    </div>

    <div class="row mb-4 g-3">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100" style="background: linear-gradient(135deg, #198754 0%, #20c997 100%); color: white;">
                <div class="card-body p-3 d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-white-50 text-uppercase mb-1" style="font-size: 0.8rem; letter-spacing: 1px;">Cagnotte Totale</h6>
                        <h2 class="fw-bold mb-0"><?php echo number_format($totalRevenue, 2); ?> €</h2>
                    </div>
                    <div class="rounded-circle bg-white bg-opacity-25 d-flex align-items-center justify-content-center flex-shrink-0" style="width: 60px; height: 60px;">
                        <i class="fas fa-euro-sign fa-2x"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100 bg-white">
                <div class="card-body p-3 border-start border-5 border-<?php echo ($countUnpaid > 0) ? 'warning' : 'success'; ?>">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <div>
                            <h6 class="text-muted text-uppercase mb-1" style="font-size: 0.8rem; letter-spacing: 1px;">En attente paiement</h6>
                            <h2 class="fw-bold mb-0 text-dark"><?php echo $countUnpaid; ?> <small class="text-muted fs-6">commandes</small></h2>
                        </div>
                        <div class="text-<?php echo ($countUnpaid > 0) ? 'warning' : 'success'; ?> flex-shrink-0">
                            <i class="fas fa-exclamation-circle fa-2x"></i>
                        </div>
                    </div>
                    <div class="progress" style="height: 4px;">
                        <div class="progress-bar bg-success" role="progressbar" style="width: <?php echo $percentPaid; ?>%"></div>
                        <div class="progress-bar bg-warning" role="progressbar" style="width: <?php echo 100 - $percentPaid; ?>%"></div>
                    </div>
                    <small class="text-muted" style="font-size: 0.7rem;"><?php echo $percentPaid; ?>% payées</small>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100" style="background: linear-gradient(135deg, #dc3545 0%, #ff6b6b 100%); color: white;">
                <div class="card-body p-3 d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-white-50 text-uppercase mb-1" style="font-size: 0.8rem; letter-spacing: 1px;">Volume Roses</h6>
                        <h2 class="fw-bold mb-0"><?php echo $totalRoses; ?> 🌹</h2>
                    </div>
                    <div class="rounded-circle bg-white bg-opacity-25 d-flex align-items-center justify-content-center flex-shrink-0" style="width: 60px; height: 60px;">
                        <i class="fas fa-box-open fa-2x"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex gap-3 mb-4">
        <a href="preparation.php" class="btn btn-lg btn-primary fw-bold shadow-sm flex-grow-1">
            <i class="fas fa-boxes"></i> Mode Préparation
        </a>
        <a href="delivery.php" class="btn btn-lg btn-success fw-bold shadow-sm flex-grow-1">
            <i class="fas fa-truck"></i> Mode Distribution
        </a>
    </div>

    <div class="card shadow border-0 rounded-3">
        <div class="card-header bg-white py-3 d-flex flex-wrap justify-content-center justify-content-md-between align-items-center gap-3">
            <div class="d-flex align-items-center gap-3">
                <h5 class="mb-0 fw-bold text-primary">📦 Commandes & Livraisons</h5>
                <?php if(!empty($search)): ?>
                    <span class="badge bg-warning text-dark animate__animated animate__fadeIn shadow-sm">
                        <?php echo count($groupedOrders); ?> résultat(s)
                    </span>
                <?php endif; ?>
            </div>

            <form method="GET" action="manage_orders.php" class="d-flex mt-2 mt-md-0 mx-auto mx-md-0 ms-md-auto" style="max-width: 350px; width: 100%;">
                <div class="input-group">
                    <input type="text" name="q" class="form-control rounded-start-pill border-end-0 ps-3 bg-light" placeholder="Rechercher..." value="<?php echo htmlspecialchars($search); ?>">
                    <?php if(!empty($search)): ?>
                        <a href="manage_orders.php" class="btn btn-light border border-start-0 border-end-0 text-danger" title="Effacer"><i class="fas fa-times"></i></a>
                    <?php endif; ?>
                    <button class="btn btn-primary rounded-end-pill px-3" type="submit"><i class="fas fa-search"></i></button>
                </div>
            </form>
        </div>

        <div class="card-body p-0">
            <?php if (!empty($search) && empty($groupedOrders)): ?>
                <div class="alert alert-warning m-3 border-0 shadow-sm">
                    <i class="fas fa-search mb-0 me-2"></i> Aucune commande ne correspond à votre recherche "<strong><?php echo htmlspecialchars($search); ?></strong>".
                    <a href="manage_orders.php" class="alert-link">Tout afficher</a>.
                </div>
            <?php endif; ?>

            <div class="table-responsive">
                <table class="table table-bordered mb-0 align-middle table-admin">
                    <thead>
                        <tr>
                            <th style="width: 30%;">Commande & Paiement</th>
                            <th style="width: 70%;">Destinataires & Livraison</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($groupedOrders) && empty($search)): ?>
                            <tr><td colspan="2" class="text-center p-5 text-muted">Aucune commande enregistrée pour le moment.</td></tr>
                        <?php else: ?>
                            <?php foreach($groupedOrders as $order): ?>
                            <tr>
                                <td class="bg-white <?php echo $order['info']['is_paid'] ? '' : 'table-warning'; ?>">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <span class="badge bg-secondary">#<?php echo str_pad($order['info']['id'], 4, '0', STR_PAD_LEFT); ?></span>
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
                                                        $hasSchedule = false;
                                                        ksort($dest['schedule_map']);
                                                        foreach($dest['schedule_map'] as $hour => $roomName) {
                                                            if(!empty($roomName)) {
                                                                echo "<div class='small mb-1'><span class='fw-bold'>{$hour}h</span> : ".htmlspecialchars($roomName)."</div>";
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
                                <div class="modal-dialog modal-xl"> 
                                    <div class="modal-content">
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
                                                            
                                                            <input type="hidden" name="recipients[<?php echo $dest['order_recipient_id']; ?>][student_id]" value="<?php echo $dest['student_id']; ?>">

                                                            <div class="row g-2 mb-3">
                                                                <div class="col-md-3">
                                                                    <label class="small">Prénom</label>
                                                                    <input type="text" name="recipients[<?php echo $dest['order_recipient_id']; ?>][prenom]" class="form-control form-control-sm" value="<?php echo htmlspecialchars($dest['prenom']); ?>">
                                                                </div>
                                                                <div class="col-md-3">
                                                                    <label class="small">Nom</label>
                                                                    <input type="text" name="recipients[<?php echo $dest['order_recipient_id']; ?>][nom]" class="form-control form-control-sm" value="<?php echo htmlspecialchars($dest['nom']); ?>">
                                                                </div>
                                                                <div class="col-md-3">
                                                                    <label class="small">Classe</label>
                                                                    <select name="recipients[<?php echo $dest['order_recipient_id']; ?>][class_id]" class="form-select form-select-sm">
                                                                        <option value="">-- Inconnue --</option>
                                                                        <?php foreach($allClasses as $id => $name): ?>
                                                                            <option value="<?php echo $id; ?>" <?php if($dest['class_id'] == $id) echo 'selected'; ?>><?php echo htmlspecialchars($name); ?></option>
                                                                        <?php endforeach; ?>
                                                                    </select>
                                                                </div>
                                                                <div class="col-md-3 d-flex align-items-center">
                                                                    <div class="form-check form-switch pt-4">
                                                                        <input class="form-check-input" type="checkbox" role="switch" 
                                                                            name="recipients[<?php echo $dest['order_recipient_id']; ?>][is_anonymous]" 
                                                                            value="1" 
                                                                            id="anonSwitch_<?php echo $dest['order_recipient_id']; ?>"
                                                                            <?php if($dest['is_anonymous']) echo 'checked'; ?>>
                                                                        <label class="form-check-label small" for="anonSwitch_<?php echo $dest['order_recipient_id']; ?>">Anonyme</label>
                                                                    </div>
                                                                </div>
                                                            </div>

                                                            <div class="mb-3 border p-2 bg-white rounded">
                                                                <label class="small fw-bold">🌹 Quantités (Prix recalculé auto)</label>
                                                                <div class="row">
                                                                    <?php foreach($dest['roses'] as $rose): ?>
                                                                        <div class="col-4">
                                                                            <label class="small text-muted"><?php echo htmlspecialchars($rose['name']); ?></label>
                                                                            <input type="number" min="0" name="recipients[<?php echo $dest['order_recipient_id']; ?>][roses][<?php echo $rose['rose_link_id']; ?>]" class="form-control form-control-sm" value="<?php echo $rose['quantity']; ?>">
                                                                        </div>
                                                                    <?php endforeach; ?>
                                                                </div>
                                                            </div>

                                                            <div class="mb-3">
                                                                <label class="small fw-bold">Message</label>
                                                                <select name="recipients[<?php echo $dest['order_recipient_id']; ?>][message_id]" class="form-select form-select-sm">
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
                                                                    for ($h = 8; $h <= 17; $h++): 
                                                                        $currentRoomName = $dest['schedule_map'][$h] ?? '';
                                                                    ?>
                                                                        <div class="col-md-2 col-4 mb-2">
                                                                            <label class="small text-muted" style="font-size: 0.75rem;"><?php echo $h; ?>h-<?php echo $h+1; ?>h</label>
                                                                            
                                                                            <select name="recipients[<?php echo $dest['order_recipient_id']; ?>][schedule][<?php echo $h; ?>]" class="form-select form-select-sm" style="font-size: 0.75rem;">
                                                                                <option value="" class="text-muted">- Vide -</option>
                                                                                <?php foreach($allRooms as $rId => $rName): ?>
                                                                                    <option value="<?php echo htmlspecialchars($rName); ?>" <?php if($currentRoomName === $rName) echo 'selected'; ?>>
                                                                                        <?php echo htmlspecialchars($rName); ?>
                                                                                    </option>
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