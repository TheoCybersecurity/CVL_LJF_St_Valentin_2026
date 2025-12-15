<?php
require_once 'db.php';
require_once 'auth_check.php';

// --- 1. Récupération des STATS ---

// TOTAL EUROS
$stmt = $pdo->query("SELECT SUM(total_price) FROM orders");
$totalRevenue = $stmt->fetchColumn() ?: 0;

// TOTAL ROSES
$stmt = $pdo->query("SELECT SUM(quantity) FROM recipient_roses");
$totalRoses = $stmt->fetchColumn() ?: 0;

// TOTAL COMMANDES
$stmt = $pdo->query("SELECT COUNT(*) FROM orders");
$totalOrders = $stmt->fetchColumn() ?: 0;

// --- 2. Récupération de la LISTE COMPLETE ---

$sql = "
    SELECT 
        o.id as order_id,
        o.buyer_nom, 
        o.buyer_prenom, 
        c_buy.name as buyer_class_name,
        o.created_at,
        r.id as recipient_id,
        r.dest_nom, 
        r.dest_prenom, 
        c_dest.name as dest_class_name,
        r.is_anonymous,
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
    $deliveries = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Erreur SQL (Liste commandes) : " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Admin - Saint Valentin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .card-stat { border:none; border-radius: 10px; color: white; }
        .bg-money { background: linear-gradient(45deg, #11998e, #38ef7d); }
        .bg-roses { background: linear-gradient(45deg, #ff512f, #dd2476); }
        .bg-orders { background: linear-gradient(45deg, #4568DC, #B06AB3); }
        .schedule-badge { font-size: 0.8em; margin-right: 2px; }
    </style>
</head>
<body class="bg-light">

<nav class="navbar navbar-dark bg-dark mb-4">
    <div class="container">
        <a class="navbar-brand" href="#">🕵️ Admin Panel</a>
        <div>
            <a href="index.php" class="btn btn-outline-light btn-sm me-2">Voir le site</a>
            <a href="logout.php" class="btn btn-danger btn-sm">Déconnexion</a>
        </div>
    </div>
</nav>

<div class="container">
    
    <div class="row mb-4">
        <div class="col-md-4">
            <div class="card card-stat bg-money p-3 mb-2">
                <h3><?php echo number_format($totalRevenue, 2); ?> €</h3>
                <span>Recettes Totales</span>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card card-stat bg-roses p-3 mb-2">
                <h3><?php echo $totalRoses; ?> 🌹</h3>
                <span>Roses à commander</span>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card card-stat bg-orders p-3 mb-2">
                <h3><?php echo $totalOrders; ?></h3>
                <span>Commandes</span>
            </div>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-header bg-white">
            <h5 class="mb-0">Dernières livraisons prévues</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped table-hover mb-0 align-middle" style="font-size: 0.9rem;">
                    <thead class="table-light">
                        <tr>
                            <th>#CMD</th>
                            <th>Acheteur</th>
                            <th>Destinataire</th>
                            <th>Fleurs</th>
                            <th>Où livrer ?</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($deliveries)): ?>
                            <tr><td colspan="5" class="text-center p-3">Aucune commande pour le moment.</td></tr>
                        <?php else: ?>
                            <?php foreach($deliveries as $row): 
                                // 1. Récupérer les fleurs
                                $stmtRoses = $pdo->prepare("
                                    SELECT rr.quantity, rp.name 
                                    FROM recipient_roses rr
                                    JOIN rose_products rp ON rr.rose_product_id = rp.id
                                    WHERE rr.recipient_id = ?
                                ");
                                $stmtRoses->execute([$row['recipient_id']]);
                                $rosesList = $stmtRoses->fetchAll(PDO::FETCH_ASSOC);

                                // 2. Récupérer l'emploi du temps (CORRECTION ICI : hour_slot)
                                // J'utilise "as hour" pour ne pas changer le code HTML en dessous
                                $stmtSchedule = $pdo->prepare("
                                    SELECT rs.hour_slot as hour, rm.name as room_name
                                    FROM recipient_schedules rs
                                    JOIN rooms rm ON rs.room_id = rm.id
                                    WHERE rs.recipient_id = ?
                                    ORDER BY rs.hour_slot ASC
                                ");
                                $stmtSchedule->execute([$row['recipient_id']]);
                                $schedules = $stmtSchedule->fetchAll(PDO::FETCH_ASSOC);
                            ?>
                            <tr>
                                <td><?php echo $row['order_id']; ?></td>
                                
                                <td>
                                    <strong><?php echo htmlspecialchars($row['buyer_prenom'] . ' ' . $row['buyer_nom']); ?></strong><br>
                                    <span class="badge bg-secondary"><?php echo htmlspecialchars($row['buyer_class_name'] ?? '?'); ?></span>
                                </td>

                                <td>
                                    <strong><?php echo htmlspecialchars($row['dest_prenom'] . ' ' . $row['dest_nom']); ?></strong><br>
                                    <span class="badge bg-primary"><?php echo htmlspecialchars($row['dest_class_name'] ?? '?'); ?></span>
                                    
                                    <?php if($row['is_anonymous']): ?>
                                        <span class="badge bg-dark ms-1">Anonyme</span>
                                    <?php endif; ?>

                                    <?php if($row['message_content']): ?>
                                        <div class="text-muted small fst-italic mt-1">
                                            💌 "<?php echo htmlspecialchars($row['message_content']); ?>"
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <ul class="list-unstyled mb-0">
                                    <?php foreach($rosesList as $rose): ?>
                                        <li><?php echo $rose['quantity']; ?> x <?php echo htmlspecialchars($rose['name']); ?></li>
                                    <?php endforeach; ?>
                                    </ul>
                                </td>

                                <td>
                                    <?php if(count($schedules) > 0): ?>
                                        <?php foreach($schedules as $sch): ?>
                                            <div class="mb-1">
                                                <span class="badge bg-info text-dark schedule-badge"><?php echo $sch['hour']; ?>h</span>
                                                <?php echo htmlspecialchars($sch['room_name']); ?>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <span class="text-danger small">⚠ Aucune salle</span>
                                    <?php endif; ?>
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

</body>
</html>