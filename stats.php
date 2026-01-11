<?php
// stats.php
session_start();
require_once 'db.php';
require_once 'auth_check.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

// ==========================================
// 1. STATS FINANCIÈRES (Globales)
// ==========================================
$stmtRev = $pdo->query("SELECT SUM(total_price) FROM orders WHERE is_paid = 1");
$totalRevenue = $stmtRev->fetchColumn() ?: 0;

$stmtPaid = $pdo->query("SELECT COUNT(*) FROM orders WHERE is_paid = 1");
$nbPaid = $stmtPaid->fetchColumn();

$stmtUnpaid = $pdo->query("SELECT COUNT(*) FROM orders WHERE is_paid = 0");
$nbUnpaid = $stmtUnpaid->fetchColumn();
$totalOrders = $nbPaid + $nbUnpaid;
$percentPaid = ($totalOrders > 0) ? round(($nbPaid / $totalOrders) * 100) : 0;

// ==========================================
// 2. STATS LOGISTIQUES (Préparation & Distribution)
// ==========================================
// On ne compte QUE les destinataires liés à des COMMANDES PAYÉES
try {
    // Total des paquets à faire (Commandes payées uniquement)
    $sqlTotal = "SELECT COUNT(orc.id) FROM order_recipients orc 
                 JOIN orders o ON orc.order_id = o.id 
                 WHERE o.is_paid = 1";
    $totalPaquets = $pdo->query($sqlTotal)->fetchColumn() ?: 0;

    // Préparés
    $sqlPrep = "SELECT COUNT(orc.id) FROM order_recipients orc 
                JOIN orders o ON orc.order_id = o.id 
                WHERE orc.is_prepared = 1 AND o.is_paid = 1";
    $nbPrepared = $pdo->query($sqlPrep)->fetchColumn() ?: 0;

    // Distribués
    $sqlDist = "SELECT COUNT(orc.id) FROM order_recipients orc 
                JOIN orders o ON orc.order_id = o.id 
                WHERE orc.is_distributed = 1 AND o.is_paid = 1";
    $nbDistributed = $pdo->query($sqlDist)->fetchColumn() ?: 0;

    // Calcul pourcentages
    $percentPrep = ($totalPaquets > 0) ? round(($nbPrepared / $totalPaquets) * 100) : 0;
    $percentDist = ($totalPaquets > 0) ? round(($nbDistributed / $totalPaquets) * 100) : 0;

} catch (Exception $e) {
    $totalPaquets = 0; $nbPrepared = 0; $nbDistributed = 0;
    $percentPrep = 0; $percentDist = 0;
}

// ==========================================
// 3. STATS "FUN" (Anonymat & Stars)
// ==========================================
try {
    // Taux d'anonymat (Correction ici : is_anonymous)
    $sqlAnon = "SELECT COUNT(orc.id) FROM order_recipients orc 
                JOIN orders o ON orc.order_id = o.id 
                WHERE orc.is_anonymous = 1 AND o.is_paid = 1";
    $nbAnon = $pdo->query($sqlAnon)->fetchColumn() ?: 0;
    
    $percentAnon = ($totalPaquets > 0) ? round(($nbAnon / $totalPaquets) * 100) : 0;

    // Top 3 des Stars (Correction de la requête)
    // On groupe par Nom ET Prénom pour éviter les doublons homonymes
    $sqlStars = "SELECT orc.dest_prenom, orc.dest_nom, COUNT(orc.id) as count 
                 FROM order_recipients orc
                 JOIN orders o ON orc.order_id = o.id
                 WHERE o.is_paid = 1
                 GROUP BY orc.dest_nom, orc.dest_prenom 
                 ORDER BY count DESC 
                 LIMIT 3";
    $topStars = $pdo->query($sqlStars)->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    $nbAnon = 0; $percentAnon = 0; $topStars = [];
}

// ==========================================
// 4. STATS PAR COULEUR (Roses)
// ==========================================
$statsColors = [];
try {
    // Note : Si cette requête échoue encore, c'est peut-être le nom 'rose_product_id'
    // Vérifie ta table recipient_roses avec un DESCRIBE si besoin.
    $sqlColors = "
        SELECT p.name, SUM(rr.quantity) as count
        FROM recipient_roses rr
        JOIN rose_products p ON rr.rose_product_id = p.id 
        JOIN order_recipients orc ON rr.recipient_id = orc.id
        JOIN orders o ON orc.order_id = o.id
        WHERE o.is_paid = 1
        GROUP BY p.id
        ORDER BY count DESC
    ";
    $statsColors = $pdo->query($sqlColors)->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $statsColors = []; 
}
$totalRosesVendues = 0;
foreach ($statsColors as $c) { $totalRosesVendues += $c['count']; }

// ==========================================
// 5. TOP CLASSES ACHETEUSES
// ==========================================
try {
    $sqlClasses = "
        SELECT c.name, COUNT(o.id) as count, SUM(o.total_price) as total
        FROM orders o
        JOIN classes c ON o.buyer_class_id = c.id
        WHERE o.is_paid = 1
        GROUP BY c.id
        ORDER BY count DESC LIMIT 5
    ";
    $topClasses = $pdo->query($sqlClasses)->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $topClasses = []; }

?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Statistiques Détaillées - St Valentin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .card-stat-mini { transition: transform 0.2s; }
        .card-stat-mini:hover { transform: translateY(-3px); }
        .bg-gradient-gold { background: linear-gradient(135deg, #FFD700 0%, #FDB931 100%); color: #5e4b00; }
        .bg-gradient-mystery { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; }
    </style>
</head>
<body class="bg-light">

<?php include 'navbar.php'; ?>

<div class="container mt-4 mb-5">
    
    <div class="d-flex align-items-center justify-content-between mb-4">
        <div class="d-flex align-items-center">
            <a href="manage_orders.php" class="btn btn-outline-secondary me-3"><i class="fas fa-arrow-left"></i> Retour</a>
            <div>
                <h2 class="fw-bold m-0">📊 Statistiques & Pilotage</h2>
                <p class="text-muted m-0 small">Vue d'ensemble de l'opération St Valentin</p>
            </div>
        </div>
        <button onclick="window.print()" class="btn btn-sm btn-outline-dark d-none d-md-block"><i class="fas fa-print me-2"></i>Imprimer</button>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm p-3 h-100 bg-white border-bottom border-success border-4 card-stat-mini">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <small class="text-uppercase text-muted fw-bold">Paiements Validés</small>
                    <i class="fas fa-check-circle text-success fa-lg"></i>
                </div>
                <h3 class="fw-bold text-dark mb-0"><?php echo $percentPaid; ?>%</h3>
                <div class="progress mt-2" style="height: 6px;">
                    <div class="progress-bar bg-success" style="width: <?php echo $percentPaid; ?>%"></div>
                </div>
                <small class="text-muted mt-1 d-block"><?php echo $nbUnpaid; ?> commandes en attente</small>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card border-0 shadow-sm p-3 h-100 bg-white border-bottom border-primary border-4 card-stat-mini">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <small class="text-uppercase text-muted fw-bold">Commandes Préparées</small>
                    <i class="fas fa-gift text-primary fa-lg"></i>
                </div>
                <h3 class="fw-bold text-dark mb-0"><?php echo $percentPrep; ?>%</h3>
                <div class="progress mt-2" style="height: 6px;">
                    <div class="progress-bar bg-primary" style="width: <?php echo $percentPrep; ?>%"></div>
                </div>
                <small class="text-muted mt-1 d-block"><?php echo $nbPrepared; ?> / <?php echo $totalPaquets; ?> paquets prêts</small>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card border-0 shadow-sm p-3 h-100 bg-white border-bottom border-warning border-4 card-stat-mini">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <small class="text-uppercase text-muted fw-bold">Distribution</small>
                    <i class="fas fa-shipping-fast text-warning fa-lg"></i>
                </div>
                <h3 class="fw-bold text-dark mb-0"><?php echo $percentDist; ?>%</h3>
                <div class="progress mt-2" style="height: 6px;">
                    <div class="progress-bar bg-warning" style="width: <?php echo $percentDist; ?>%"></div>
                </div>
                <small class="text-muted mt-1 d-block"><?php echo $nbDistributed; ?> livrés</small>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100 bg-gradient-mystery">
                <div class="card-body d-flex flex-column justify-content-center align-items-center text-center">
                    <div class="mb-2"><i class="fas fa-user-secret fa-3x opacity-75"></i></div>
                    <h5 class="fw-bold mb-0">Admirateurs Secrets</h5>
                    <h2 class="display-4 fw-bold mb-0"><?php echo $percentAnon; ?>%</h2>
                    <p class="small opacity-75 mb-0">des roses sont anonymes</p>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100 bg-gradient-gold">
                <div class="card-body">
                    <h6 class="fw-bold text-uppercase mb-3 text-center"><i class="fas fa-crown me-2"></i>Le Podium des Stars</h6>
                    <ul class="list-unstyled mb-0">
                        <?php $rank=1; foreach($topStars as $star): ?>
                        <li class="d-flex justify-content-between align-items-center mb-2 bg-white bg-opacity-25 rounded p-2">
                            <span class="fw-bold">#<?php echo $rank; ?> <?php echo htmlspecialchars($star['dest_prenom'] . ' ' . substr($star['dest_nom'], 0, 1) . '.'); ?></span>
                            <span class="badge bg-white text-dark"><?php echo $star['count']; ?> 🌹</span>
                        </li>
                        <?php $rank++; endforeach; ?>
                        <?php if(empty($topStars)): ?><li class="small text-center opacity-75">En attente de commandes...</li><?php endif; ?>
                    </ul>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100 bg-white p-4 d-flex flex-column justify-content-center">
                <div class="d-flex align-items-center mb-3">
                    <div class="bg-success bg-opacity-10 p-3 rounded-circle me-3 text-success">
                        <i class="fas fa-sack-dollar fa-2x"></i>
                    </div>
                    <div>
                        <h6 class="text-muted mb-0">Recettes Totales</h6>
                        <h3 class="fw-bold text-success mb-0"><?php echo number_format($totalRevenue, 2); ?> €</h3>
                    </div>
                </div>
                <hr>
                <div class="d-flex align-items-center">
                    <div class="bg-danger bg-opacity-10 p-3 rounded-circle me-3 text-danger">
                        <i class="fas fa-box-open fa-2x"></i>
                    </div>
                    <div>
                        <h6 class="text-muted mb-0">Total Roses</h6>
                        <h3 class="fw-bold text-danger mb-0"><?php echo $totalRosesVendues; ?></h3>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-md-6">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-white py-3 border-bottom-0">
                    <h5 class="fw-bold m-0 text-danger"><i class="fas fa-palette me-2"></i>Répartition des Couleurs</h5>
                </div>
                <div class="card-body pt-0">
                    <?php foreach($statsColors as $stat): 
                        $percent = ($totalRosesVendues > 0) ? round(($stat['count'] / $totalRosesVendues) * 100) : 0;
                        $barColor = stripos($stat['name'], 'Rouge') !== false ? 'bg-danger' : (stripos($stat['name'], 'Blanche') !== false ? 'bg-secondary' : 'bg-primary');
                    ?>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between mb-1">
                            <span class="fw-bold text-dark"><?php echo htmlspecialchars($stat['name']); ?></span>
                            <span class="fw-bold"><?php echo $stat['count']; ?></span>
                        </div>
                        <div class="progress" style="height: 8px;">
                            <div class="progress-bar <?php echo $barColor; ?>" style="width: <?php echo $percent; ?>%"></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php if(empty($statsColors)): ?><p class="text-muted text-center py-4">Pas encore de données.</p><?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-white py-3 border-bottom-0">
                    <h5 class="fw-bold m-0 text-primary"><i class="fas fa-users me-2"></i>Meilleures Classes (Acheteurs)</h5>
                </div>
                <div class="card-body p-0">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light"><tr><th class="ps-4">#</th><th>Classe</th><th class="text-end pe-4">Commandes</th></tr></thead>
                        <tbody>
                            <?php $rank = 1; foreach($topClasses as $tc): ?>
                            <tr>
                                <td class="ps-4 fw-bold text-muted"><?php echo $rank; ?></td>
                                <td class="fw-bold"><?php echo htmlspecialchars($tc['name']); ?></td>
                                <td class="text-end pe-4"><span class="badge bg-primary rounded-pill"><?php echo $tc['count']; ?></span></td>
                            </tr>
                            <?php $rank++; endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>