<?php
// admin.php
require_once 'db.php';
require_once 'auth_check.php'; 

// --- 1. Récupération des STATS ---
$totalRevenue = $pdo->query("SELECT SUM(total_price) FROM orders")->fetchColumn() ?: 0;
$totalRoses = $pdo->query("SELECT SUM(quantity) FROM recipient_roses")->fetchColumn() ?: 0;
$totalOrders = $pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn() ?: 0;

// --- 2. Récupération et REGROUPEMENT des données ---

$sql = "
    SELECT 
        o.id as order_id,
        o.buyer_nom, o.buyer_prenom, o.created_at, o.total_price,
        o.is_paid, o.paid_at,
        c_buy.name as buyer_class_name,
        
        r.id as recipient_id,
        r.dest_nom, r.dest_prenom, r.is_anonymous,
        r.is_distributed, r.distributed_at,
        c_dest.name as dest_class_name,
        pm.content as message_content
    FROM orders o
    JOIN order_recipients r ON o.id = r.order_id
    LEFT JOIN classes c_buy ON o.buyer_class_id = c_buy.id
    LEFT JOIN classes c_dest ON r.class_id = c_dest.id
    LEFT JOIN predefined_messages pm ON r.message_id = pm.id
    ORDER BY o.created_at DESC
";

try {
    $stmt = $pdo->query($sql);
    $raw_results = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Erreur SQL : " . $e->getMessage());
}

// ALGORITHME DE REGROUPEMENT
$groupedOrders = [];

foreach ($raw_results as $row) {
    $orderId = $row['order_id'];

    // Si la commande n'existe pas encore dans notre tableau, on l'initialise
    if (!isset($groupedOrders[$orderId])) {
        $groupedOrders[$orderId] = [
            'info' => [
                'id' => $row['order_id'],
                'date' => $row['created_at'],
                'buyer_nom' => $row['buyer_nom'],
                'buyer_prenom' => $row['buyer_prenom'],
                'buyer_class' => $row['buyer_class_name'],
                'total_price' => $row['total_price'],
                'is_paid' => $row['is_paid'],
                'paid_at' => $row['paid_at']
            ],
            'recipients' => []
        ];
    }

    // On récupère les roses pour ce destinataire spécifique
    $stmtRoses = $pdo->prepare("SELECT rr.quantity, rp.name FROM recipient_roses rr JOIN rose_products rp ON rr.rose_product_id = rp.id WHERE rr.recipient_id = ?");
    $stmtRoses->execute([$row['recipient_id']]);
    $roses = $stmtRoses->fetchAll(PDO::FETCH_ASSOC);

    // On récupère le planning pour ce destinataire spécifique
    $stmtSchedule = $pdo->prepare("SELECT rs.hour_slot, rm.name as room_name FROM recipient_schedules rs JOIN rooms rm ON rs.room_id = rm.id WHERE rs.recipient_id = ? ORDER BY rs.hour_slot ASC");
    $stmtSchedule->execute([$row['recipient_id']]);
    $schedule = $stmtSchedule->fetchAll(PDO::FETCH_ASSOC);

    // On ajoute le destinataire à la liste de cette commande
    $groupedOrders[$orderId]['recipients'][] = [
        'id' => $row['recipient_id'],
        'nom' => $row['dest_nom'],
        'prenom' => $row['dest_prenom'],
        'class' => $row['dest_class_name'],
        'is_anonymous' => $row['is_anonymous'],
        'message' => $row['message_content'],
        'is_distributed' => $row['is_distributed'],
        'distributed_at' => $row['distributed_at'],
        'roses' => $roses,
        'schedule' => $schedule
    ];
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administration - Saint Valentin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    
    <style>
        .card-stat { border:none; border-radius: 15px; color: white; transition: transform 0.2s; }
        .card-stat:hover { transform: translateY(-5px); }
        .bg-money { background: linear-gradient(135deg, #11998e, #38ef7d); }
        .bg-roses { background: linear-gradient(135deg, #ff512f, #dd2476); }
        .bg-orders { background: linear-gradient(135deg, #4568DC, #B06AB3); }
        
        /* Table Styles */
        .table-admin th { font-size: 0.85rem; text-transform: uppercase; letter-spacing: 1px; background-color: #f8f9fa; }
        .recipient-row { border-bottom: 1px dashed #dee2e6; padding: 10px 0; }
        .recipient-row:last-child { border-bottom: none; }
        .badge-status { font-size: 0.75rem; }
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
                <span class="text-white-50">Recettes Totales</span>
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
                <span class="text-white-50">Commandes</span>
            </div>
        </div>
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
                                <td class="bg-white">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <span class="badge bg-secondary">#<?php echo $order['info']['id']; ?></span>
                                        <small class="text-muted"><?php echo date('d/m H:i', strtotime($order['info']['date'])); ?></small>
                                    </div>
                                    
                                    <h5 class="fw-bold text-dark mb-1">
                                        <?php echo htmlspecialchars($order['info']['buyer_prenom'] . ' ' . $order['info']['buyer_nom']); ?>
                                    </h5>
                                    <span class="badge bg-light text-dark border mb-3">
                                        <?php echo htmlspecialchars($order['info']['buyer_class'] ?? '?'); ?>
                                    </span>

                                    <div class="card bg-light border-0 p-2 mt-2">
                                        <div class="d-flex justify-content-between fw-bold">
                                            <span>Total :</span>
                                            <span><?php echo number_format($order['info']['total_price'], 2); ?> €</span>
                                        </div>
                                        <div class="mt-2 text-center">
                                            <?php if($order['info']['is_paid']): ?>
                                                <span class="badge bg-success w-100">✅ Payé le <?php echo date('d/m', strtotime($order['info']['paid_at'])); ?></span>
                                            <?php else: ?>
                                                <span class="badge bg-danger w-100">❌ Non Payé</span>
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
                                                <span class="badge bg-info text-dark mb-1"><?php echo htmlspecialchars($dest['class'] ?? '?'); ?></span>
                                                <?php if($dest['is_anonymous']): ?>
                                                    <span class="badge bg-dark" title="L'acheteur est anonyme">🕵️ Anonyme</span>
                                                <?php endif; ?>
                                                
                                                <div class="mt-2">
                                                    <?php if($dest['is_distributed']): ?>
                                                        <span class="badge bg-success badge-status">✅ Livré le <?php echo date('d/m H:i', strtotime($dest['distributed_at'])); ?></span>
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
                                                
                                                <?php if($dest['message']): ?>
                                                    <div class="bg-light p-1 rounded small fst-italic text-muted border">
                                                        "<?php echo htmlspecialchars($dest['message']); ?>"
                                                    </div>
                                                <?php endif; ?>
                                            </div>

                                            <div class="col-md-4">
                                                <strong class="small text-uppercase text-muted">📍 Localisation</strong>
                                                <div class="mt-1">
                                                    <?php if(count($dest['schedule']) > 0): ?>
                                                        <?php foreach($dest['schedule'] as $sch): ?>
                                                            <div class="small mb-1">
                                                                <span class="fw-bold"><?php echo $sch['hour_slot']; ?>h</span> : 
                                                                <?php echo htmlspecialchars($sch['room_name']); ?>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    <?php else: ?>
                                                        <span class="text-danger small">Pas de salle</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </td>
                            </tr>
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