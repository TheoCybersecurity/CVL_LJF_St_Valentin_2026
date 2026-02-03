<?php
// stats.php
session_start();
require_once 'db.php';
require_once 'auth_check.php';

// Vérification de connexion
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

// ==========================================
// 1. LOGIQUE COMMUNE (Récupération des dates pour le graphique)
// ==========================================
$availableDates = [];
try {
    $sqlDates = "SELECT DATE(created_at) as d FROM orders WHERE is_paid = 1 GROUP BY d ORDER BY d ASC";
    $availableDates = $pdo->query($sqlDates)->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}

// ==========================================
// 2. TRAITEMENT AJAX (Si on demande juste le graphique)
// ==========================================
if (isset($_GET['ajax']) && $_GET['ajax'] == '1') {
    $viewMode = $_GET['view'] ?? 'global_hours';
    renderChartContent($pdo, $viewMode, $availableDates);
    exit(); 
}

// ==========================================
// 3. RECUPERATION DE TOUTES LES STATS
// ==========================================

// --- FINANCES & VOLUMETRIE ---
$stmtRev = $pdo->query("SELECT SUM(total_price) FROM orders WHERE is_paid = 1");
$totalRevenue = $stmtRev->fetchColumn() ?: 0;

// Compte des commandes
$stmtPaid = $pdo->query("SELECT COUNT(*) FROM orders WHERE is_paid = 1");
$nbPaid = $stmtPaid->fetchColumn(); 

$stmtUnpaid = $pdo->query("SELECT COUNT(*) FROM orders WHERE is_paid = 0");
$nbUnpaid = $stmtUnpaid->fetchColumn();

$totalOrders = $nbPaid + $nbUnpaid;
$percentPaid = ($totalOrders > 0) ? round(($nbPaid / $totalOrders) * 100) : 0;

// --- 1. STATS "VUE D'ENSEMBLE" & ANALYSE ---

// Manque à gagner (Non payé)
$stmtLost = $pdo->query("SELECT SUM(total_price) FROM orders WHERE is_paid = 0");
$potentialRevenue = $stmtLost->fetchColumn() ?: 0;

// Plus grosse commande
$stmtMax = $pdo->query("SELECT MAX(total_price) FROM orders");
$biggestOrder = $stmtMax->fetchColumn() ?: 0;

// Le Casanova (Celui qui envoie à le plus de destinataires différents)
$casanovaName = "N/A";
$casanovaCount = 0;
try {
    $sqlCasanova = "SELECT u.prenom, u.nom, COUNT(DISTINCT orc.recipient_id) as count 
                    FROM orders o 
                    JOIN users u ON o.user_id = u.user_id 
                    JOIN order_recipients orc ON o.id = orc.order_id 
                    GROUP BY u.user_id 
                    ORDER BY count DESC 
                    LIMIT 1";
    $casanova = $pdo->query($sqlCasanova)->fetch(PDO::FETCH_ASSOC);
    if ($casanova) {
        $casanovaName = htmlspecialchars($casanova['prenom'] . ' ' . substr($casanova['nom'], 0, 1) . '.');
        $casanovaCount = $casanova['count'];
    }
} catch (Exception $e) {}


// --- 2. STATS ROSES & COULEURS (Corrigé) ---

// A. Répartition par couleurs (TOTAL ABSOLU - Pour la commande fleuriste)
$statsColors = [];
try {
    $sqlColors = "SELECT p.name, SUM(rr.quantity) as count 
                  FROM recipient_roses rr 
                  JOIN rose_products p ON rr.rose_product_id = p.id 
                  JOIN order_recipients orc ON rr.recipient_id = orc.id 
                  JOIN orders o ON orc.order_id = o.id 
                  GROUP BY p.id 
                  ORDER BY count DESC";
    $statsColors = $pdo->query($sqlColors)->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $statsColors = []; }

// B. Total Roses (Absolu)
$totalRoses = 0;
foreach ($statsColors as $c) { $totalRoses += $c['count']; }

// C. Total Roses Vendues (PAYÉES UNIQUEMENT - Pour les stats financières)
$totalRosesVendues = 0;
try {
    $sqlPaidRoses = "SELECT SUM(rr.quantity) 
                     FROM recipient_roses rr 
                     JOIN order_recipients orc ON rr.recipient_id = orc.id 
                     JOIN orders o ON orc.order_id = o.id";
    $totalRosesVendues = $pdo->query($sqlPaidRoses)->fetchColumn() ?: 0;
} catch (Exception $e) { $totalRosesVendues = 0; }

// D. Panier moyen (Roses vendues / Commandes payées)
// Sécurité : on s'assure que $nbPaid est défini (au cas où il manquerait plus haut)
if (!isset($nbPaid)) {
    $nbPaid = $pdo->query("SELECT COUNT(*) FROM orders WHERE")->fetchColumn();
}
$avgBasket = ($nbPaid > 0) ? number_format($totalRosesVendues / $nbPaid, 1) : 0;


// --- 3. NOUVELLES STATS (Acheteurs & Classe) ---

// Acheteurs uniques (Combien d'élèves différents ont commandé ?)
$uniqueBuyers = 0;
try {
    $uniqueBuyers = $pdo->query("SELECT COUNT(DISTINCT user_id) FROM orders")->fetchColumn();
} catch (Exception $e) { $uniqueBuyers = 0; }

// Top Classe (Quelle classe a le plus de commandes ?)
$topClassName = "Aucune";
$topClassCount = 0;
try {
    // On utilise c.id pour la jointure, comme dans ton code sqlClasses
    $sqlTopClass = "SELECT c.name, COUNT(o.id) as order_count 
                    FROM orders o 
                    JOIN users u ON o.user_id = u.user_id 
                    JOIN classes c ON u.class_id = c.id 
                    GROUP BY c.id 
                    ORDER BY order_count DESC 
                    LIMIT 1";
    $stmtTopClass = $pdo->query($sqlTopClass);
    $topClassData = $stmtTopClass->fetch(PDO::FETCH_ASSOC);
    
    if ($topClassData) {
        $topClassName = htmlspecialchars($topClassData['name']);
        $topClassCount = $topClassData['order_count'];
    }
} catch (Exception $e) { }

// --- POPULATION ---
// Nombre d'acheteurs uniques
$sqlBuyers = "SELECT COUNT(DISTINCT user_id) FROM orders";
$nbUniqueBuyers = $pdo->query($sqlBuyers)->fetchColumn() ?: 0;

// Nombre de destinataires uniques
$sqlRecipients = "SELECT COUNT(DISTINCT orc.recipient_id) FROM order_recipients orc JOIN orders o ON orc.order_id = o.id";
$nbUniqueRecipients = $pdo->query($sqlRecipients)->fetchColumn() ?: 0;


// --- LOGISTIQUE ---
try {
    $sqlTotal = "SELECT COUNT(orc.id) FROM order_recipients orc JOIN orders o ON orc.order_id = o.id";
    $totalPaquets = $pdo->query($sqlTotal)->fetchColumn() ?: 0; // Nombre de paquets à livrer

    $sqlPrep = "SELECT COUNT(orc.id) FROM order_recipients orc JOIN orders o ON orc.order_id = o.id WHERE orc.is_prepared = 1 AND o.is_paid = 1";
    $nbPrepared = $pdo->query($sqlPrep)->fetchColumn() ?: 0;

    $sqlDist = "SELECT COUNT(orc.id) FROM order_recipients orc JOIN orders o ON orc.order_id = o.id WHERE orc.is_distributed = 1 AND o.is_paid = 1";
    $nbDistributed = $pdo->query($sqlDist)->fetchColumn() ?: 0;

    $percentPrep = ($totalPaquets > 0) ? round(($nbPrepared / $totalPaquets) * 100) : 0;
    $percentDist = ($totalPaquets > 0) ? round(($nbDistributed / $totalPaquets) * 100) : 0;
} catch (Exception $e) {
    $totalPaquets = 0; $nbPrepared = 0; $nbDistributed = 0; $percentPrep = 0; $percentDist = 0;
}

// --- COULEURS & TOTAL ROSES ---
$statsColors = [];
try {
    $sqlColors = "SELECT p.name, SUM(rr.quantity) as count 
                  FROM recipient_roses rr 
                  JOIN rose_products p ON rr.rose_product_id = p.id 
                  JOIN order_recipients orc ON rr.recipient_id = orc.id 
                  JOIN orders o ON orc.order_id = o.id 
                  GROUP BY p.id 
                  ORDER BY count DESC";
    $statsColors = $pdo->query($sqlColors)->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $statsColors = []; }

$totalRoses = 0;
foreach ($statsColors as $c) { $totalRoses += $c['count']; }


// --- TOTAL ROSES VENDUES ---
$totalRosesVendues = 0;
try {
    $sqlPaidRoses = "SELECT SUM(rr.quantity) 
                     FROM recipient_roses rr 
                     JOIN order_recipients orc ON rr.recipient_id = orc.id 
                     JOIN orders o ON orc.order_id = o.id 
                     WHERE o.is_paid = 1";
    
    $totalRosesVendues = $pdo->query($sqlPaidRoses)->fetchColumn();

    if (!$totalRosesVendues) { $totalRosesVendues = 0; }

} catch (Exception $e) { $totalRosesVendues = 0; }


// --- PANIER MOYEN (Roses payées / Nombre de commandes payées) ---
// On utilise bien $totalRosesVendues ici
$avgBasket = ($nbPaid > 0) ? number_format($totalRosesVendues / $nbPaid, 1) : 0;

// --- ANONYMAT & STARS ---
try {
    $sqlAnon = "SELECT COUNT(orc.id) FROM order_recipients orc JOIN orders o ON orc.order_id = o.id WHERE orc.is_anonymous = 1";
    $nbAnon = $pdo->query($sqlAnon)->fetchColumn() ?: 0;
    $percentAnon = ($totalPaquets > 0) ? round(($nbAnon / $totalPaquets) * 100) : 0;

    $sqlStars = "SELECT MAX(r.prenom) as prenom, MAX(r.nom) as nom, SUM(rr.quantity) as count FROM recipient_roses rr JOIN order_recipients orc ON rr.recipient_id = orc.id JOIN recipients r ON orc.recipient_id = r.id JOIN orders o ON orc.order_id = o.id GROUP BY r.id ORDER BY count DESC LIMIT 5";
    $topStars = $pdo->query($sqlStars)->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $nbAnon = 0; $percentAnon = 0; $topStars = []; }

// --- MESSAGES & CLASSES ---
$topMessages = [];
try {
    $sqlMsg = "SELECT m.content, COUNT(orc.id) as count FROM order_recipients orc JOIN orders o ON orc.order_id = o.id JOIN predefined_messages m ON orc.message_id = m.id GROUP BY m.id ORDER BY count DESC LIMIT 5";
    $topMessages = $pdo->query($sqlMsg)->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $topMessages = []; }

try {
    $sqlClasses = "SELECT c.name, COUNT(o.id) as count FROM orders o JOIN users u ON o.user_id = u.user_id JOIN classes c ON u.class_id = c.id GROUP BY c.id ORDER BY count DESC LIMIT 5";
    $topClasses = $pdo->query($sqlClasses)->fetchAll(PDO::FETCH_ASSOC);
    $sqlClassesRecipients = "SELECT c.name, SUM(rr.quantity) as count FROM recipient_roses rr JOIN order_recipients orc ON rr.recipient_id = orc.id JOIN orders o ON orc.order_id = o.id JOIN recipients r ON orc.recipient_id = r.id JOIN classes c ON r.class_id = c.id GROUP BY c.id ORDER BY count DESC LIMIT 5";
    $topClassesRecipients = $pdo->query($sqlClassesRecipients)->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $topClasses = []; $topClassesRecipients = []; }


// ==========================================
// FONCTION DE GENERATION DU GRAPHIQUE HTML
// ==========================================
function renderChartContent($pdo, $viewMode, $availableDates) {
    $chartData = [];
    $chartTitle = "Activité Horaire (Cumul)";
    $chartMax = 0;

    try {
        if ($viewMode == 'global_days') {
            $chartTitle = "Activité par Jour";
            $sqlChart = "SELECT DATE(created_at) as d, COUNT(*) as c FROM orders GROUP BY d ORDER BY d ASC";
            $stmtChart = $pdo->query($sqlChart);
            while($row = $stmtChart->fetch(PDO::FETCH_ASSOC)) {
                $dateFr = date('d/m', strtotime($row['d']));
                $chartData[$dateFr] = $row['c'];
            }
        } elseif (strpos($viewMode, 'date_') === 0) {
            $targetDate = substr($viewMode, 5);
            $chartTitle = "Activité du " . date('d/m/Y', strtotime($targetDate));
            for($i=0; $i<=23; $i++) { $chartData[$i.'h'] = 0; }
            $sqlChart = "SELECT HOUR(created_at) as h, COUNT(*) as c FROM orders AND DATE(created_at) = ? GROUP BY h";
            $stmtChart = $pdo->prepare($sqlChart);
            $stmtChart->execute([$targetDate]);
            while($row = $stmtChart->fetch(PDO::FETCH_ASSOC)) {
                $chartData[$row['h'].'h'] = $row['c'];
            }
        } else {
            $chartTitle = "Activité Horaire (Global)";
            for($i=0; $i<=23; $i++) { $chartData[$i.'h'] = 0; }
            $sqlChart = "SELECT HOUR(created_at) as h, COUNT(*) as c FROM orders GROUP BY h";
            $stmtChart = $pdo->query($sqlChart);
            while($row = $stmtChart->fetch(PDO::FETCH_ASSOC)) {
                $chartData[$row['h'].'h'] = $row['c'];
            }
        }
        if (!empty($chartData)) { $chartMax = max($chartData); }
    } catch (Exception $e) { }

    ?>
    <div class="card-header bg-white py-3 border-bottom-0 d-flex flex-column flex-md-row justify-content-between align-items-center gap-2">
        <h5 class="fw-bold m-0 text-primary fs-6 fs-md-5"><i class="fas fa-chart-bar me-2"></i><?php echo $chartTitle; ?></h5>
        
        <div class="w-100 w-md-auto d-flex justify-content-md-end justify-content-center">
            <div class="btn-group btn-group-sm" role="group">
                <button type="button" class="btn btn-outline-primary js-view-btn <?php echo ($viewMode == 'global_hours') ? 'active' : ''; ?>" data-view="global_hours">Heures</button>
                <button type="button" class="btn btn-outline-primary js-view-btn <?php echo ($viewMode == 'global_days') ? 'active' : ''; ?>" data-view="global_days">Jours</button>
                
                <div class="btn-group btn-group-sm" role="group">
                    <button type="button" class="btn btn-outline-primary dropdown-toggle <?php echo (strpos($viewMode, 'date_') === 0) ? 'active' : ''; ?>" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="far fa-calendar-alt"></i> <span class="d-none d-sm-inline">Date</span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end bg-white" style="max-height: 250px; overflow-y:auto;">
                        <?php foreach($availableDates as $date): ?>
                            <li>
                                <button class="dropdown-item js-view-btn text-dark" style="color: #000000 !important;" type="button" data-view="date_<?php echo $date; ?>">
                                    <?php echo date('d/m/Y', strtotime($date)); ?>
                                </button>
                            </li>
                        <?php endforeach; ?>
                        <?php if(empty($availableDates)): ?><li><span class="dropdown-item disabled" style="color: #999 !important;">Aucune commande</span></li><?php endif; ?>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <div class="card-body pt-0 px-2">
        <div class="chart-container">
            <?php if(empty($chartData)): ?>
                <div class="w-100 text-center text-muted align-self-center">Aucune donnée.</div>
            <?php else: ?>
                <?php foreach($chartData as $label => $count): 
                    $heightPercent = ($chartMax > 0) ? ($count / $chartMax * 100) : 0;
                    $barHeight = max(2, $heightPercent); 
                ?>
                <div class="chart-col">
                    <?php if($count > 0): ?>
                        <div class="chart-value"><?php echo $count; ?></div>
                    <?php endif; ?>
                    <div class="chart-bar" style="height: <?php echo $barHeight; ?>%; opacity: <?php echo ($count > 0 ? 1 : 0.2); ?>"></div>
                    <div class="chart-label"><?php echo $label; ?></div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    <?php
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <title>Statistiques Détaillées - St Valentin</title>
    <?php include 'head_imports.php'; ?>
    <style>
        .card-stat-mini { transition: transform 0.2s; }
        .card-stat-mini:hover { transform: translateY(-3px); }
        .bg-gradient-gold { background: linear-gradient(135deg, #FFD700 0%, #FDB931 100%); color: #5e4b00; }
        .bg-gradient-mystery { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; }
        .table-sm td, .table-sm th { padding: 0.5rem; font-size: 0.9rem; }
        
        /* CORRECTION MOBILE FORCEE */
        .dropdown-menu { background-color: #ffffff !important; }
        .dropdown-item { color: #000000 !important; }
        .dropdown-item:hover { background-color: #f0f0f0 !important; color: #000000 !important; }

        /* Styles Graphique */
        .chart-container {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            height: 220px;
            padding: 20px 5px 10px 5px;
            border-bottom: 1px solid #dee2e6;
            width: 100%;
            overflow-x: auto; 
            overflow-y: hidden;
        }
        .chart-container::-webkit-scrollbar { height: 4px; }
        .chart-container::-webkit-scrollbar-thumb { background: #ccc; border-radius: 4px; }
        .chart-col { flex: 1; display: flex; flex-direction: column; justify-content: flex-end; align-items: center; position: relative; margin: 0 1px; min-width: 12px; }
        .chart-bar { width: 80%; max-width: 12px; background-color: #0d6efd; border-radius: 4px 4px 0 0; transition: height 0.5s ease; min-height: 2px; }
        .chart-bar:hover { opacity: 0.7; cursor: help; }
        .chart-label { margin-top: 8px; font-size: 0.65rem; color: #6c757d; text-align: center; transform: rotate(-45deg); white-space: nowrap; transform-origin: center top; }
        .chart-value { font-size: 0.65rem; font-weight: bold; color: #0d6efd; margin-bottom: 4px; }
        @media (max-width: 768px) {
            .chart-label { font-size: 0.5rem; margin-top: 4px; transform: rotate(-90deg); transform-origin: center top; width: 10px; }
            .chart-value { font-size: 0.5rem; }
            .chart-container { height: 180px; }
        }
    </style>
</head>
<body class="bg-light">

<?php include 'navbar.php'; ?>

<div class="container mt-4 mb-5">
    
    <div class="d-flex flex-wrap align-items-center justify-content-between mb-4 gap-2">
        <div class="d-flex align-items-center">
            <a href="manage_orders.php" class="btn btn-outline-secondary me-3"><i class="fas fa-arrow-left"></i> Retour</a>
            <div>
                <h2 class="fw-bold m-0 fs-3">📊 Statistiques</h2>
                <p class="text-muted m-0 small d-none d-sm-block">Vue d'ensemble de l'opération</p>
            </div>
        </div>
        <button onclick="window.print()" class="btn btn-sm btn-outline-dark d-none d-md-block"><i class="fas fa-print me-2"></i>Imprimer</button>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-lg-3 col-6">
            <div class="card border-0 shadow-sm p-3 h-100 bg-white">
                <div class="d-flex align-items-center mb-1">
                    <div class="bg-dark bg-opacity-10 p-2 rounded me-2 text-dark"><i class="fas fa-file-invoice"></i></div>
                    <small class="text-uppercase text-muted fw-bold" style="font-size: 0.75rem;">Commandes</small>
                </div>
                <h3 class="fw-bold text-dark mb-0"><?php echo $totalOrders; ?></h3>
                <small class="text-muted" style="font-size: 0.75rem;"><?php echo $nbUnpaid; ?> en attente</small>
            </div>
        </div>

        <div class="col-lg-3 col-6">
            <div class="card border-0 shadow-sm p-3 h-100 bg-white">
                <div class="d-flex align-items-center mb-1">
                    <div class="bg-warning bg-opacity-10 p-2 rounded me-2 text-warning"><i class="fas fa-piggy-bank"></i></div>
                    <small class="text-uppercase text-muted fw-bold" style="font-size: 0.75rem;">Cagnotte totale</small>
                </div>
                <h3 class="fw-bold text-warning mb-0"><?php echo number_format($totalRevenue + $potentialRevenue, 2); ?> €</h3>
                <small class="text-muted" style="font-size: 0.75rem;">(<?php echo $potentialRevenue; ?> € en attente de paiement)</small>
            </div>
        </div>

        <div class="col-lg-3 col-6">
            <div class="card border-0 shadow-sm p-3 h-100 bg-white">
                <div class="d-flex align-items-center mb-1">
                    <div class="bg-danger bg-opacity-10 p-2 rounded me-2 text-danger"><i class="fas fa-piggy-bank"></i></div>
                    <small class="text-uppercase text-muted fw-bold" style="font-size: 0.75rem;">Roses totales</small>
                </div>
                <h3 class="fw-bold text-danger mb-0"><?php echo number_format($totalRoses); ?></h3>
                <small class="text-muted" style="font-size: 0.75rem;">(<?php echo $totalRoses - $totalRosesVendues; ?> en attente de vente)</small>
            </div>
        </div>

        <div class="col-lg-3 col-6">
            <div class="card border-0 shadow-sm p-3 h-100 bg-white">
                <div class="d-flex align-items-center mb-1">
                    <div class="bg-info bg-opacity-10 p-2 rounded me-2 text-info"><i class="fas fa-users"></i></div>
                    <small class="text-uppercase text-muted fw-bold" style="font-size: 0.75rem;">Participants</small>
                </div>
                <h3 class="fw-bold text-info mb-0"><?php echo $uniqueBuyers; ?></h3>
                <small class="text-muted" style="font-size: 0.75rem;">Élèves différents</small>
            </div>
        </div>

        <div class="col-lg-3 col-6">
            <div class="card border-0 shadow-sm p-3 h-100 bg-white">
                <div class="d-flex align-items-center mb-1">
                    <div class="bg-success bg-opacity-10 p-2 rounded me-2 text-success"><i class="fas fa-trophy"></i></div>
                    <small class="text-uppercase text-muted fw-bold" style="font-size: 0.75rem;">Record</small>
                </div>
                <h3 class="fw-bold text-success mb-0"><?php echo number_format($biggestOrder, 2); ?> €</h3>
                <small class="text-muted" style="font-size: 0.75rem;">Plus grosse commande</small>
            </div>
        </div>

        <div class="col-lg-3 col-6">
            <div class="card border-0 shadow-sm p-3 h-100 bg-white">
                <div class="d-flex align-items-center mb-1">
                    <div class="bg-primary bg-opacity-10 p-2 rounded me-2 text-primary"><i class="fas fa-shopping-basket"></i></div>
                    <small class="text-uppercase text-muted fw-bold" style="font-size: 0.75rem;">Panier Moyen</small>
                </div>
                <h3 class="fw-bold text-primary mb-0"><?php echo $avgBasket; ?></h3>
                <small class="text-muted" style="font-size: 0.75rem;">Roses par commande</small>
            </div>
        </div>

        <div class="col-lg-3 col-6">
            <div class="card border-0 shadow-sm p-3 h-100 bg-white">
                <div class="d-flex align-items-center mb-1">
                    <div class="bg-danger bg-opacity-10 p-2 rounded me-2 text-danger"><i class="fas fa-heart-circle-check"></i></div>
                    <small class="text-uppercase text-muted fw-bold" style="font-size: 0.75rem;">Casanova</small>
                </div>
                <h3 class="fw-bold text-dark mb-0 fs-5 text-truncate" title="<?php echo $casanovaName; ?>"><?php echo $casanovaName; ?></h3>
                <small class="text-muted" style="font-size: 0.75rem;"><?php echo $casanovaCount; ?> destinataires diff.</small>
            </div>
        </div>

        <div class="col-lg-3 col-6">
            <div class="card border-0 shadow-sm p-3 h-100 bg-white">
                <div class="d-flex align-items-center mb-1">
                    <div class="bg-secondary bg-opacity-10 p-2 rounded me-2 text-secondary"><i class="fas fa-school"></i></div>
                    <small class="text-uppercase text-muted fw-bold" style="font-size: 0.75rem;">Top Classe</small>
                </div>
                <h3 class="fw-bold text-secondary mb-0 fs-5 text-truncate"><?php echo $topClassName; ?></h3>
                <small class="text-muted" style="font-size: 0.75rem;"><?php echo $topClassCount; ?> commandes</small>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-6 col-md-4">
            <div class="card border-0 shadow-sm p-3 h-100 bg-white border-bottom border-primary border-4 card-stat-mini">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <small class="text-uppercase text-muted fw-bold d-none d-md-block">Paiements Validés</small>
                    <small class="text-uppercase text-muted fw-bold d-md-none">Payé</small>
                    <i class="fas fa-clipboard-list text-primary fa-lg"></i>
                </div>
                <h3 class="fw-bold text-dark mb-0"><?php echo $percentPaid; ?>%</h3>
                <div class="progress mt-2" style="height: 6px;">
                    <div class="progress-bar bg-primary" style="width: <?php echo $percentPaid; ?>%"></div>
                </div>
                <small class="text-muted mt-1 d-block small"><?php echo $nbPaid; ?> / <?php echo $totalOrders; ?></small>
            </div>
        </div>

        <div class="col-6 col-md-4">
            <div class="card border-0 shadow-sm p-3 h-100 bg-white border-bottom border-warning border-4 card-stat-mini">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <small class="text-uppercase text-muted fw-bold d-none d-md-block">Commandes Préparées</small>
                    <small class="text-uppercase text-muted fw-bold d-md-none">Prêt</small>
                    <i class="fas fa-boxes text-warning fa-lg"></i>
                </div>
                <h3 class="fw-bold text-dark mb-0"><?php echo $percentPrep; ?>%</h3>
                <div class="progress mt-2" style="height: 6px;">
                    <div class="progress-bar bg-warning" style="width: <?php echo $percentPrep; ?>%"></div>
                </div>
                <small class="text-muted mt-1 d-block small"><?php echo $nbPrepared; ?> / <?php echo $totalOrders; ?></small>
            </div>
        </div>

        <div class="col-12 col-md-4">
            <div class="card border-0 shadow-sm p-3 h-100 bg-white border-bottom border-success border-4 card-stat-mini">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <small class="text-uppercase text-muted fw-bold">Distribution</small>
                    <i class="fas fa-truck text-success fa-lg"></i>
                </div>
                <h3 class="fw-bold text-dark mb-0"><?php echo $percentDist; ?>%</h3>
                <div class="progress mt-2" style="height: 6px;">
                    <div class="progress-bar bg-success" style="width: <?php echo $percentDist; ?>%"></div>
                </div>
                <small class="text-muted mt-1 d-block small"><?php echo $nbDistributed; ?> / <?php echo $totalOrders; ?></small>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100 bg-white p-3 d-flex flex-column justify-content-center">
                <div class="d-flex align-items-center mb-3">
                    <div class="bg-success bg-opacity-10 p-3 rounded-circle me-3 text-success">
                        <i class="fas fa-sack-dollar fa-2x"></i>
                    </div>
                    <div>
                        <h6 class="text-muted mb-0">Recettes (Encaissées)</h6>
                        <h3 class="fw-bold text-success mb-0"><?php echo number_format($totalRevenue, 2); ?> €</h3>
                    </div>
                </div>
                
                <div class="d-flex align-items-center mb-3">
                     <div class="bg-danger bg-opacity-10 p-3 rounded-circle me-3 text-danger">
                        <i class="fas fa-fan fa-2x"></i>
                    </div>
                    <div>
                        <h6 class="text-muted mb-0">Roses Vendues (Encaissées)</h6>
                        <h3 class="fw-bold text-danger mb-0"><?php echo $totalRosesVendues; ?></h3>
                    </div>
                </div>

                <div class="d-flex align-items-center">
                    <div class="bg-primary bg-opacity-10 p-3 rounded-circle me-3 text-primary">
                        <i class="fas fa-shopping-basket fa-2x"></i>
                    </div>
                    <div>
                        <h6 class="text-muted mb-0">Panier Moyen (Encaissées)</h6>
                        <h3 class="fw-bold text-primary mb-0"><?php echo $avgBasket; ?> <small class="fs-6 text-muted">roses/cmd</small></h3>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100 bg-gradient-mystery text-white">
                <div class="card-body">
                    <div class="row text-center mb-3">
                        <div class="col-6 border-end border-white border-opacity-25">
                            <h2 class="fw-bold mb-0"><?php echo $nbUniqueBuyers; ?></h2>
                            <small class="opacity-75">Acheteurs</small>
                        </div>
                        <div class="col-6">
                            <h2 class="fw-bold mb-0"><?php echo $nbUniqueRecipients; ?></h2>
                            <small class="opacity-75">Destinataires</small>
                        </div>
                    </div>
                    <hr class="border-white opacity-25">
                    <div class="d-flex flex-column justify-content-center align-items-center text-center mt-3">
                        <div class="mb-1"><i class="fas fa-user-secret fa-2x opacity-75"></i></div>
                        <h3 class="fw-bold mb-0 display-6"><?php echo $percentAnon; ?>%</h3>
                        <p class="small opacity-75 mb-0">des roses sont anonymes</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-white py-3 border-bottom-0">
                    <h6 class="fw-bold m-0 text-info text-uppercase"><i class="fas fa-comment-dots me-2"></i>Top Messages</h6>
                </div>
                <div class="card-body pt-0 overflow-auto" style="max-height: 220px;">
                    <?php 
                    $maxMsg = !empty($topMessages) ? $topMessages[0]['count'] : 0;
                    foreach($topMessages as $tm): 
                        $percent = ($maxMsg > 0) ? round(($tm['count'] / $maxMsg) * 100) : 0;
                    ?>
                    <div class="mb-2">
                        <div class="d-flex justify-content-between mb-1 small">
                            <span class="fw-bold text-dark text-truncate" style="max-width: 80%;" title="<?php echo htmlspecialchars($tm['content']); ?>">
                                <?php echo htmlspecialchars($tm['content']); ?>
                            </span>
                            <span class="fw-bold"><?php echo $tm['count']; ?></span>
                        </div>
                        <div class="progress" style="height: 6px;">
                            <div class="progress-bar bg-info" style="width: <?php echo $percent; ?>%"></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php if(empty($topMessages)): ?><p class="text-muted text-center py-4 small">Aucun message</p><?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <div class="row g-3 mb-4">
        <div class="col-lg-3 col-md-6">
            <div class="card border-0 shadow-sm h-100 bg-gradient-gold">
                <div class="card-body">
                    <h6 class="fw-bold text-uppercase mb-3 text-center"><i class="fas fa-crown me-2"></i>Le Podium</h6>
                    <ul class="list-unstyled mb-0">
                        <?php $rank=1; foreach($topStars as $star): ?>
                        <li class="d-flex justify-content-between align-items-center mb-2 bg-white bg-opacity-25 rounded p-2">
                            <span class="fw-bold small text-truncate">#<?php echo $rank; ?> <?php echo htmlspecialchars($star['prenom'] . ' ' . substr($star['nom'], 0, 1) . '.'); ?></span>
                            <span class="badge bg-white text-dark"><?php echo $star['count']; ?></span>
                        </li>
                        <?php $rank++; endforeach; ?>
                        <?php if(empty($topStars)): ?><li class="small text-center opacity-75">En attente...</li><?php endif; ?>
                    </ul>
                </div>
            </div>
        </div>

        <div class="col-lg-3 col-md-6">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-white py-3 border-bottom-0">
                    <h6 class="fw-bold m-0 text-danger text-uppercase"><i class="fas fa-palette me-2"></i>Couleurs</h6>
                </div>
                <div class="card-body pt-0 overflow-auto" style="max-height: 250px;">
                    <?php 
                    $maxColor = !empty($statsColors) ? max(array_column($statsColors, 'count')) : 0;
                    foreach($statsColors as $stat): 
                        $percent = ($maxColor > 0) ? round(($stat['count'] / $maxColor) * 100) : 0;
                        $barColor = stripos($stat['name'], 'Rouge') !== false ? 'bg-danger' : (stripos($stat['name'], 'Blanche') !== false ? 'bg-secondary' : 'bg-primary');
                    ?>
                    <div class="mb-2">
                        <div class="d-flex justify-content-between mb-1 small">
                            <span class="fw-bold text-dark text-capitalize"><?php echo htmlspecialchars($stat['name']); ?></span>
                            <span class="fw-bold"><?php echo $stat['count']; ?></span>
                        </div>
                        <div class="progress" style="height: 6px;">
                            <div class="progress-bar <?php echo $barColor; ?>" style="width: <?php echo $percent; ?>%"></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-3 col-md-6">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-white py-3 border-bottom-0">
                    <h6 class="fw-bold m-0 text-primary text-uppercase"><i class="fas fa-shopping-cart me-2"></i>Acheteurs</h6>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover table-sm align-middle mb-0">
                            <thead class="table-light"><tr><th class="ps-3">#</th><th>Classe</th><th class="text-end pe-3">Cmd</th></tr></thead>
                            <tbody>
                                <?php $rank = 1; foreach($topClasses as $tc): ?>
                                <tr>
                                    <td class="ps-3 fw-bold text-muted"><?php echo $rank; ?></td>
                                    <td class="fw-bold"><?php echo htmlspecialchars($tc['name']); ?></td>
                                    <td class="text-end pe-3"><span class="badge bg-primary rounded-pill"><?php echo $tc['count']; ?></span></td>
                                </tr>
                                <?php $rank++; endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-3 col-md-6">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-white py-3 border-bottom-0">
                    <h6 class="fw-bold m-0 text-danger text-uppercase"><i class="fas fa-heart me-2"></i>Reçu</h6>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover table-sm align-middle mb-0">
                            <thead class="table-light"><tr><th class="ps-3">#</th><th>Classe</th><th class="text-end pe-3">Reçu</th></tr></thead>
                            <tbody>
                                <?php $rank = 1; foreach($topClassesRecipients as $tcr): ?>
                                <tr>
                                    <td class="ps-3 fw-bold text-muted"><?php echo $rank; ?></td>
                                    <td class="fw-bold"><?php echo htmlspecialchars($tcr['name']); ?></td>
                                    <td class="text-end pe-3"><span class="badge bg-danger bg-opacity-75 rounded-pill"><?php echo $tcr['count']; ?></span></td>
                                </tr>
                                <?php $rank++; endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="row mb-4">
        <div class="col-12">
            <div class="card shadow-sm border-0" id="activity-chart-wrapper">
                <?php 
                $defaultView = $_GET['view'] ?? 'global_hours';
                renderChartContent($pdo, $defaultView, $availableDates); 
                ?>
            </div>
        </div>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    function attachChartListeners() {
        const buttons = document.querySelectorAll('.js-view-btn');
        const container = document.getElementById('activity-chart-wrapper');

        buttons.forEach(btn => {
            btn.onclick = function(e) {
                e.preventDefault();
                const view = this.getAttribute('data-view');
                container.style.opacity = '0.6';
                
                fetch('stats?ajax=1&view=' + view)
                    .then(response => response.text())
                    .then(html => {
                        container.innerHTML = html;
                        container.style.opacity = '1';
                        attachChartListeners();
                    })
                    .catch(error => {
                        console.error('Erreur:', error);
                        container.style.opacity = '1';
                        alert('Erreur lors du chargement des données.');
                    });
            };
        });
    }
    attachChartListeners();
});
</script>

</body>
</html>