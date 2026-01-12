<?php
// preparation.php
require_once 'db.php';
require_once 'auth_check.php';
checkAccess('cvl');

// --- 1. TRAITEMENT DES ACTIONS (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['recipient_ids'])) {
    
    $ids = explode(',', $_POST['recipient_ids']);
    $ids = array_map('intval', $ids);
    
    // Récupération ID session
    $currentCvlId = $_SESSION['user_id'] ?? ($_SESSION['id'] ?? 0);

    $actionType = ''; 

    if (!empty($ids)) {
        $inQuery = implode(',', array_fill(0, count($ids), '?'));
        
        if (isset($_POST['mark_prepared'])) {
            $sql = "UPDATE order_recipients 
                    SET is_prepared = 1, 
                        prepared_at = NOW(), 
                        prepared_by_cvl_id = ? 
                    WHERE id IN ($inQuery)";
            $params = array_merge([$currentCvlId], $ids);
            $actionType = 'marked';
            
        } elseif (isset($_POST['unmark_prepared'])) {
            $sql = "UPDATE order_recipients 
                    SET is_prepared = 0, 
                        prepared_at = NULL, 
                        prepared_by_cvl_id = NULL 
                    WHERE id IN ($inQuery)";
            $params = $ids;
            $actionType = 'unmarked';
        }

        if (isset($sql)) {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
        }
    }
    
    // Redirection avec paramètres pour le Toast (géré par toast_notifications.php)
    $queryParams = $_GET;
    $queryParams['last_action'] = $actionType;
    $queryParams['last_ids'] = implode(',', $ids);
    
    header("Location: preparation.php?" . http_build_query($queryParams));
    exit;
}

// --- 2. GESTION DES VUES & FILTRES ---
$view = isset($_GET['view']) ? $_GET['view'] : 'todo';
$levelFilter = isset($_GET['level']) ? $_GET['level'] : 'all';

// --- 3. REQUÊTE SQL ---
$search = isset($_GET['q']) ? trim($_GET['q']) : '';

$sql = "
    SELECT 
        r.id as recipient_id, r.dest_nom, r.dest_prenom, r.is_anonymous, r.prepared_at, r.prepared_by_cvl_id,
        c.name as class_name,
        cl.group_alias,
        o.buyer_prenom, o.buyer_nom,
        rr.quantity, rp.name as rose_color
    FROM order_recipients r
    JOIN orders o ON r.order_id = o.id
    LEFT JOIN classes c ON r.class_id = c.id
    LEFT JOIN class_levels cl ON c.level_id = cl.id
    LEFT JOIN recipient_roses rr ON r.id = rr.recipient_id
    LEFT JOIN rose_products rp ON rr.rose_product_id = rp.id
    WHERE o.is_paid = 1 
";

// Filtre Vue (A faire / Fait)
if ($view === 'done') {
    $sql .= " AND r.is_prepared = 1 ";
} else {
    $sql .= " AND r.is_prepared = 0 ";
}

// Filtre Niveau (2nde, 1ere...)
if ($levelFilter !== 'all') {
    $sql .= " AND cl.group_alias = :filter ";
}

// Filtre Recherche (Nouveau)
if (!empty($search)) {
    $sql .= " AND (
        r.dest_nom LIKE :s OR 
        r.dest_prenom LIKE :s OR 
        CONCAT(r.dest_prenom, ' ', r.dest_nom) LIKE :s OR
        c.name LIKE :s OR
        o.buyer_nom LIKE :s OR 
        o.buyer_prenom LIKE :s OR
        r.id LIKE :s
    ) ";
}

// Tri
if ($view === 'done') {
    $sql .= " ORDER BY r.prepared_at DESC "; 
} else {
    $sql .= " ORDER BY c.name ASC, r.dest_nom ASC ";
}

// Exécution avec paramètres dynamiques
$stmt = $pdo->prepare($sql);
$params = [];

if ($levelFilter !== 'all') {
    $params[':filter'] = $levelFilter;
}
if (!empty($search)) {
    $params[':s'] = '%' . $search . '%';
}

$stmt->execute($params);
$rawResults = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- 4. REGROUPEMENT ---
$groupedByClass = [];
$totalRoses = 0;

foreach ($rawResults as $row) {
    $className = $row['class_name'] ?? 'Sans Classe';
    $studentKey = $row['dest_nom'] . '_' . $row['dest_prenom'];
    
    if (!isset($groupedByClass[$className][$studentKey])) {
        $groupedByClass[$className][$studentKey] = [
            'name' => $row['dest_prenom'] . ' ' . $row['dest_nom'],
            'prepared_at' => $row['prepared_at'], 
            'preparator_id' => $row['prepared_by_cvl_id'],
            'ids' => [], 
            'items' => [] 
        ];
    }
    if (!in_array($row['recipient_id'], $groupedByClass[$className][$studentKey]['ids'])) {
        $groupedByClass[$className][$studentKey]['ids'][] = $row['recipient_id'];
    }
    $groupedByClass[$className][$studentKey]['items'][] = $row;
    $totalRoses += $row['quantity'];
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Preparation - CVL</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body { background: #f0f2f5; padding-bottom: 50px; overflow-x: hidden; }
        .class-header { background: #4a4e69; color: white; padding: 10px 15px; border-radius: 8px 8px 0 0; margin-top: 30px; font-weight: bold; letter-spacing: 1px; }
        .class-header.history { background: #6c757d; }
        .student-card { background: white; border: none; border-left: 5px solid #d63384; margin-bottom: 15px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
        .student-card.done { border-left-color: #198754; opacity: 0.9; }
        .badge-id { font-family: monospace; font-size: 0.9em; background: #e9ecef; color: #333; border: 1px solid #ccc; }
        .rose-item { border-bottom: 1px dashed #eee; padding: 8px 0; }
        .rose-item:last-child { border-bottom: none; }
        .badge-rose-rouge { background-color: #dc3545; color: white; }
        .badge-rose-blanche { background-color: #ffffff; color: #212529; border: 1px solid #ced4da; }
        .badge-rose-pink { background-color: #ffc2d1; color: #880e4f; }
        .nav-tabs .nav-link { color: #495057; font-weight: bold; cursor: pointer; }
        .nav-tabs .nav-link.active { color: #d63384; border-bottom: 3px solid #d63384; background: transparent; }
    </style>
</head>
<body>

<?php include 'navbar.php'; ?>

<div class="container my-4">
    
    <div class="row mb-4 align-items-center">
       <div class="col-md-7">
            <h2 class="fw-bold text-primary"><i class="fas fa-boxes"></i> Préparation</h2>
            
            <?php if($view === 'todo'): ?>
                <p class="text-muted">Reste à faire : <strong><?php echo $totalRoses; ?></strong> roses.</p>
            <?php else: ?>
                <p class="text-muted">Déjà préparées : <strong><?php echo $totalRoses; ?></strong> roses.</p>
            <?php endif; ?>
        </div>
        <div class="col-md-5 text-end">
            <div class="dropdown d-inline-block">
                <button class="btn btn-dark dropdown-toggle" type="button" data-bs-toggle="dropdown">
                    <i class="fas fa-print"></i> Télécharger PDF
                </button>
                <ul class="dropdown-menu">
                    <li><a class="dropdown-item" target="_blank" href="print_labels.php?level=2nde">Secondes</a></li>
                    <li><a class="dropdown-item" target="_blank" href="print_labels.php?level=1ere">Premières</a></li>
                    <li><a class="dropdown-item" target="_blank" href="print_labels.php?level=term">Terminales</a></li>
                    <li><a class="dropdown-item" target="_blank" href="print_labels.php?level=autre">Autres</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item fw-bold" target="_blank" href="print_labels.php?level=all">TOUT IMPRIMER</a></li>
                </ul>
            </div>
        </div>
    </div>

    <ul class="nav nav-tabs mb-3">
        <li class="nav-item">
            <a class="nav-link <?php echo $view === 'todo' ? 'active' : ''; ?>" 
               href="?view=todo&level=<?php echo $levelFilter; ?>">
               <i class="fas fa-clipboard-list"></i> À FAIRE
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $view === 'done' ? 'active' : ''; ?>" 
               href="?view=done&level=<?php echo $levelFilter; ?>">
               <i class="fas fa-history"></i> TERMINÉES
            </a>
        </li>
    </ul>

    <div class="d-flex justify-content-center justify-content-md-end mb-3">
        <form method="GET" action="preparation.php" class="d-flex" style="max-width: 350px; width: 100%;">
            <input type="hidden" name="view" value="<?php echo htmlspecialchars($view); ?>">
            <input type="hidden" name="level" value="<?php echo htmlspecialchars($levelFilter); ?>">

            <div class="input-group shadow-sm">
                <input type="text" 
                       name="q" 
                       class="form-control rounded-start-pill border-end-0 ps-3 bg-white" 
                       placeholder="Chercher (Nom, Classe...)" 
                       value="<?php echo htmlspecialchars($search); ?>">
                
                <?php if(!empty($search)): ?>
                    <a href="preparation.php?view=<?php echo $view; ?>&level=<?php echo $levelFilter; ?>" 
                       class="btn btn-white bg-white border border-start-0 border-end-0 text-danger" 
                       title="Effacer">
                        <i class="fas fa-times"></i>
                    </a>
                <?php endif; ?>

                <button class="btn btn-primary rounded-end-pill px-3" type="submit">
                    <i class="fas fa-search"></i>
                </button>
            </div>
        </form>
    </div>

    <div class="btn-group mb-4 w-100 shadow-sm">
        <a href="?view=<?php echo $view; ?>&level=all" class="btn btn-light <?php echo $levelFilter=='all'?'active fw-bold border-primary':''; ?>">Tout</a>
        <a href="?view=<?php echo $view; ?>&level=2nde" class="btn btn-light <?php echo $levelFilter=='2nde'?'active fw-bold border-primary':''; ?>">Secondes</a>
        <a href="?view=<?php echo $view; ?>&level=1ere" class="btn btn-light <?php echo $levelFilter=='1ere'?'active fw-bold border-primary':''; ?>">Premières</a>
        <a href="?view=<?php echo $view; ?>&level=term" class="btn btn-light <?php echo $levelFilter=='term'?'active fw-bold border-primary':''; ?>">Terminales</a>
        <a href="?view=<?php echo $view; ?>&level=autre" class="btn btn-light <?php echo $levelFilter=='autre'?'active fw-bold border-primary':''; ?>">Autres</a>
    </div>

    <?php if (empty($groupedByClass)): ?>
        <div class="alert alert-secondary text-center py-5 rounded-3 shadow-sm">
            <?php if($view === 'todo'): ?>
                <h4><i class="fas fa-check-circle"></i> Tout est prêt !</h4>
            <?php else: ?>
                <h4><i class="fas fa-info-circle"></i> Historique vide.</h4>
            <?php endif; ?>
        </div>
    <?php else: ?>
        
        <?php foreach ($groupedByClass as $className => $students): ?>
            <div class="class-header <?php echo $view === 'done' ? 'history' : ''; ?>">
                <i class="fas fa-users me-2"></i> <?php echo htmlspecialchars($className); ?>
                <span class="badge bg-light text-dark float-end"><?php echo count($students); ?> élèves</span>
            </div>
            
            <div class="bg-white p-3 border rounded-bottom mb-4">
                <?php foreach ($students as $student): ?>
                    <div class="card student-card <?php echo $view === 'done' ? 'done' : ''; ?>">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <div>
                                    <h5 class="card-title fw-bold mb-0 text-uppercase">
                                        <?php echo htmlspecialchars($student['name']); ?>
                                    </h5>
                                    <?php if($view === 'done'): ?>
                                        <small class="text-muted" style="font-size: 0.8rem;">
                                            <i class="fas fa-check"></i> Fait le <?php echo date('d/m H:i', strtotime($student['prepared_at'])); ?>
                                            (ID: <?php echo htmlspecialchars($student['preparator_id'] ?? '?'); ?>)
                                        </small>
                                    <?php endif; ?>
                                </div>
                                <form method="POST">
                                    <input type="hidden" name="recipient_ids" value="<?php echo implode(',', $student['ids']); ?>">
                                    <?php if ($view === 'todo'): ?>
                                        <input type="hidden" name="mark_prepared" value="1">
                                        <button type="submit" class="btn btn-success shadow-sm px-4"><i class="fas fa-check"></i> OK</button>
                                    <?php else: ?>
                                        <input type="hidden" name="unmark_prepared" value="1">
                                        <button type="submit" class="btn btn-outline-danger shadow-sm px-3" onclick="return confirm('Remettre en attente ?')"><i class="fas fa-undo"></i> ANNULER</button>
                                    <?php endif; ?>
                                </form>
                            </div>
                            <div class="bg-light p-2 rounded border">
                                <?php foreach ($student['items'] as $item): ?>
                                    <?php
                                        $colorName = mb_strtolower($item['rose_color']);
                                        $badgeClass = "bg-secondary text-white"; $emoji = "🌹";
                                        if (strpos($colorName, 'rouge') !== false) { $badgeClass = "badge-rose-rouge"; $emoji = "🌹"; }
                                        elseif (strpos($colorName, 'blanche') !== false) { $badgeClass = "badge-rose-blanche"; $emoji = "🤍"; }
                                        elseif (strpos($colorName, 'rose') !== false) { $badgeClass = "badge-rose-pink"; $emoji = "🌸"; }
                                    ?>
                                    <div class="rose-item d-flex align-items-center justify-content-between">
                                        <div class="d-flex align-items-center">
                                            <span class="badge rounded-pill <?php echo $badgeClass; ?> me-3 p-2" style="font-size: 0.95rem;">
                                                <span class="fw-bold me-1"><?php echo $item['quantity']; ?></span><?php echo $emoji; ?>
                                                <span class="d-none d-sm-inline ms-1"><?php echo htmlspecialchars($item['rose_color']); ?></span>
                                            </span>
                                            <small class="text-muted">
                                                <?php if($item['is_anonymous']): ?> <i class="fas fa-user-secret"></i> Anonyme
                                                <?php else: ?> De : <strong><?php echo htmlspecialchars($item['buyer_prenom']); ?></strong> <?php endif; ?>
                                            </small>
                                        </div>
                                        <span class="badge badge-id">#<?php echo $item['recipient_id']; ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>

    <?php endif; ?>
</div>

<?php include 'toast_notifications.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>