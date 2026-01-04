<?php
// preparation.php
require_once 'db.php';
require_once 'auth_check.php';
checkAccess('cvl');

// --- TRAITEMENT DU FORMULAIRE "MARQUER COMME PRÊT" ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_prepared'], $_POST['recipient_ids'])) {
    $ids = explode(',', $_POST['recipient_ids']);
    $ids = array_map('intval', $ids); // Sécurisation
    
    if(!empty($ids)) {
        $inQuery = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("UPDATE order_recipients SET is_prepared = 1 WHERE id IN ($inQuery)");
        $stmt->execute($ids);
    }
    
    // Redirection pour éviter la resoumission
    $params = $_GET;
    header("Location: preparation.php?" . http_build_query($params));
    exit;
}

// --- RECUPERATION DU FILTRE ---
// Par défaut, on montre 'all'. Options : '2nde', '1ere', 'term', 'autre', 'all'
$levelFilter = isset($_GET['level']) ? $_GET['level'] : 'all';

// --- REQUÊTE SQL AVEC JOINTURE SUR LES NIVEAUX ---
$sql = "
    SELECT 
        r.id as recipient_id, r.dest_nom, r.dest_prenom, r.is_anonymous,
        c.name as class_name,
        cl.group_alias, -- On récupère le groupe (2nde, term, autre...)
        o.buyer_prenom, o.buyer_nom,
        rr.quantity, rp.name as rose_color
    FROM order_recipients r
    JOIN orders o ON r.order_id = o.id
    LEFT JOIN classes c ON r.class_id = c.id
    LEFT JOIN class_levels cl ON c.level_id = cl.id -- Jointure avec la nouvelle table
    LEFT JOIN recipient_roses rr ON r.id = rr.recipient_id
    LEFT JOIN rose_products rp ON rr.rose_product_id = rp.id
    WHERE o.is_paid = 1 
    AND r.is_prepared = 0
";

// Application du filtre via la colonne group_alias
if ($levelFilter !== 'all') {
    $sql .= " AND cl.group_alias = :filter ";
}

// Tri : Par Alias de groupe (optionnel mais propre), puis Nom de Classe, puis Nom élève
$sql .= " ORDER BY c.name ASC, r.dest_nom ASC";

$stmt = $pdo->prepare($sql);
if ($levelFilter !== 'all') {
    $stmt->execute(['filter' => $levelFilter]);
} else {
    $stmt->execute();
}
$rawResults = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- REGROUPEMENT PAR CLASSE ---
$groupedByClass = [];
$totalRoses = 0;

foreach ($rawResults as $row) {
    $className = $row['class_name'] ?? 'Sans Classe';
    $studentKey = $row['dest_nom'] . '_' . $row['dest_prenom'];
    
    if (!isset($groupedByClass[$className][$studentKey])) {
        $groupedByClass[$className][$studentKey] = [
            'name' => $row['dest_prenom'] . ' ' . $row['dest_nom'],
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
        body { background: #f0f2f5; padding-bottom: 50px; }
        .class-header {
            background: #4a4e69; color: white;
            padding: 10px 15px; border-radius: 8px 8px 0 0;
            margin-top: 30px; font-weight: bold; letter-spacing: 1px;
        }
        .student-card {
            background: white; border: none; border-left: 5px solid #d63384;
            margin-bottom: 15px; box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        .badge-id { font-family: monospace; font-size: 0.9em; background: #e9ecef; color: #333; border: 1px solid #ccc; }
        .rose-item { border-bottom: 1px dashed #eee; padding: 8px 0; }
        .rose-item:last-child { border-bottom: none; }
        .badge-rose-rouge { background-color: #dc3545; color: white; } /* Rouge vif */
        .badge-rose-blanche { background-color: #ffffff; color: #212529; border: 1px solid #ced4da; } /* Blanc avec bordure */
        .badge-rose-pink { background-color: #ffc2d1; color: #880e4f; } /* Rose bonbon lisible */
    </style>
</head>
<body>

<?php include 'navbar.php'; ?>

<div class="container my-4">
    
    <div class="row mb-4 align-items-center">
        <div class="col-md-7">
            <h2 class="fw-bold"><i class="fas fa-boxes"></i> Préparation</h2>
            <p class="text-muted">Reste à faire : <strong><?php echo $totalRoses; ?></strong> roses.</p>
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
                    <li><a class="dropdown-item" target="_blank" href="print_labels.php?level=autre">Autres (BTS/Profs...)</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item fw-bold" target="_blank" href="print_labels.php?level=all">TOUT IMPRIMER</a></li>
                </ul>
            </div>
        </div>
    </div>

    <div class="btn-group mb-4 w-100 shadow-sm">
        <a href="?level=all" class="btn btn-light <?php echo $levelFilter=='all'?'active fw-bold border-primary':''; ?>">Tout</a>
        <a href="?level=2nde" class="btn btn-light <?php echo $levelFilter=='2nde'?'active fw-bold border-primary':''; ?>">Secondes</a>
        <a href="?level=1ere" class="btn btn-light <?php echo $levelFilter=='1ere'?'active fw-bold border-primary':''; ?>">Premières</a>
        <a href="?level=term" class="btn btn-light <?php echo $levelFilter=='term'?'active fw-bold border-primary':''; ?>">Terminales</a>
        <a href="?level=autre" class="btn btn-light <?php echo $levelFilter=='autre'?'active fw-bold border-primary':''; ?>">Autres (BTS...)</a>
    </div>

    <?php if (empty($groupedByClass)): ?>
        <div class="alert alert-success text-center py-5 rounded-3 shadow-sm">
            <h4><i class="fas fa-check-circle"></i> Terminé !</h4>
            <p>Toutes les commandes de cette catégorie sont prêtes.</p>
        </div>
    <?php else: ?>
        
        <?php foreach ($groupedByClass as $className => $students): ?>
            <div class="class-header">
                <i class="fas fa-users me-2"></i> <?php echo htmlspecialchars($className); ?>
                <span class="badge bg-light text-dark float-end"><?php echo count($students); ?> élèves</span>
            </div>
            
            <div class="bg-white p-3 border rounded-bottom mb-4">
                <?php foreach ($students as $student): ?>
                    <div class="card student-card">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h5 class="card-title fw-bold mb-0 text-uppercase">
                                    <?php echo htmlspecialchars($student['name']); ?>
                                </h5>
                                <form method="POST">
                                    <input type="hidden" name="mark_prepared" value="1">
                                    <input type="hidden" name="recipient_ids" value="<?php echo implode(',', $student['ids']); ?>">
                                    <button type="submit" class="btn btn-success shadow-sm px-4">
                                        <i class="fas fa-check"></i> OK
                                    </button>
                                </form>
                            </div>

                            <div class="bg-light p-2 rounded border">
                                <?php foreach ($student['items'] as $item): ?>
                                    
                                    <?php
                                        // LOGIQUE VISUELLE COULEURS & EMOJIS
                                        $colorName = mb_strtolower($item['rose_color']);
                                        $badgeClass = "bg-secondary text-white"; // Par défaut (Gris)
                                        $emoji = "🌹"; // Par défaut

                                        if (strpos($colorName, 'rouge') !== false) {
                                            $badgeClass = "badge-rose-rouge";
                                            $emoji = "🌹";
                                        } elseif (strpos($colorName, 'blanche') !== false) {
                                            $badgeClass = "badge-rose-blanche";
                                            $emoji = "🤍";
                                        } elseif (strpos($colorName, 'rose') !== false) { // Pour la rose "Rose"
                                            $badgeClass = "badge-rose-pink";
                                            $emoji = "🌸";
                                        }
                                    ?>

                                    <div class="rose-item d-flex align-items-center justify-content-between">
                                        <div class="d-flex align-items-center">
                                            <span class="badge rounded-pill <?php echo $badgeClass; ?> me-3 p-2" style="font-size: 0.95rem;">
                                                <span class="fw-bold me-1"><?php echo $item['quantity']; ?></span>
                                                <?php echo $emoji; ?> 
                                                <span class="d-none d-sm-inline ms-1"><?php echo htmlspecialchars($item['rose_color']); ?></span>
                                            </span>
                                            
                                            <small class="text-muted">
                                                <?php if($item['is_anonymous']): ?>
                                                    <i class="fas fa-user-secret" title="Anonyme"></i> <span class="d-none d-md-inline">Anonyme</span>
                                                <?php else: ?>
                                                    De : <strong><?php echo htmlspecialchars($item['buyer_prenom']); ?></strong>
                                                <?php endif; ?>
                                            </small>
                                        </div>

                                        <span class="badge badge-id" title="Chercher ce numéro sur les étiquettes">
                                            #<?php echo $item['recipient_id']; ?>
                                        </span>
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
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>