<?php
// directory.php
require_once 'db.php';
require_once 'auth_check.php';

// On autorise CVL et ADMIN (à toi de voir si les élèves "staff" simple ont le droit)
checkAccess('cvl');

// =============================================================================
// 1. PARTIE AJAX (Récupération des détails d'un élève)
// =============================================================================
if (isset($_POST['ajax_get_details']) && isset($_POST['student_id'])) {
    $studentId = intval($_POST['student_id']);
    
    // A. Infos de l'élève
    $stmt = $pdo->prepare("
        SELECT r.*, c.name as class_name 
        FROM recipients r 
        LEFT JOIN classes c ON r.class_id = c.id 
        WHERE r.id = ?
    ");
    $stmt->execute([$studentId]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$student) {
        echo "<div class='alert alert-danger'>Élève introuvable.</div>";
        exit;
    }

    // B. Historique des cadeaux REÇUS
    // On récupère : ID commande, Acheteur, Message, État, Roses
    $sqlGifts = "
        SELECT 
            ort.id as gift_id,
            ort.is_anonymous, ort.is_prepared, ort.is_distributed,
            ort.distributed_at,
            pm.content as message_content,
            u.prenom as buyer_prenom, u.nom as buyer_nom,
            o.id as order_id, o.is_paid
        FROM order_recipients ort
        JOIN orders o ON ort.order_id = o.id
        JOIN users u ON o.user_id = u.user_id
        LEFT JOIN predefined_messages pm ON ort.message_id = pm.id
        WHERE ort.recipient_id = ?
        ORDER BY o.created_at DESC
    ";
    $stmtGifts = $pdo->prepare($sqlGifts);
    $stmtGifts->execute([$studentId]);
    $gifts = $stmtGifts->fetchAll(PDO::FETCH_ASSOC);

    // Génération du HTML pour la Modale
    ?>
    <div class="text-center mb-4">
        <h3 class="fw-bold text-primary mb-0"><?php echo htmlspecialchars($student['prenom'] . ' ' . $student['nom']); ?></h3>
        <span class="badge bg-secondary">ID Élève: <?php echo $student['id']; ?></span>
        <span class="badge bg-dark">Classe: <?php echo htmlspecialchars($student['class_name'] ?? 'Inconnue'); ?></span>
    </div>

    <h5 class="border-bottom pb-2 mb-3"><i class="fas fa-gift"></i> Historique des Roses Reçues</h5>

    <?php if (empty($gifts)): ?>
        <div class="alert alert-info text-center">
            Cet élève n'a reçu aucune rose pour le moment.
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-bordered">
                <thead class="table-light">
                    <tr>
                        <th>Commande</th>
                        <th>Expéditeur</th>
                        <th>Détails (Roses & Message)</th>
                        <th>Statut</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($gifts as $gift): ?>
                        <?php
                            // Récupérer le détail des roses pour ce cadeau spécifique
                            // (On le fait ici pour simplifier, ou via une jointure plus haut)
                            $stmtRoses = $pdo->prepare("SELECT rr.quantity, rp.name FROM recipient_roses rr JOIN rose_products rp ON rr.rose_product_id = rp.id WHERE rr.recipient_id = ?");
                            $stmtRoses->execute([$gift['gift_id']]);
                            $rosesList = $stmtRoses->fetchAll(PDO::FETCH_ASSOC);
                        ?>
                        <tr>
                            <td>
                                <strong>#<?php echo $gift['order_id']; ?></strong>
                                <?php if (!$gift['is_paid']): ?>
                                    <span class="badge bg-warning text-dark" title="Commande non payée">⚠️ Impayé</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($gift['is_anonymous']): ?>
                                    <span class="badge bg-dark" title="Vrai nom : <?php echo htmlspecialchars($gift['buyer_prenom'].' '.$gift['buyer_nom']); ?>">
                                        <i class="fas fa-user-secret"></i> Anonyme
                                    </span>
                                    <div class="small text-muted fst-italic mt-1">
                                        (De: <?php echo htmlspecialchars($gift['buyer_prenom'] . ' ' . $gift['buyer_nom']); ?>)
                                    </div>
                                <?php else: ?>
                                    <?php echo htmlspecialchars($gift['buyer_prenom'] . ' ' . $gift['buyer_nom']); ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <ul class="list-unstyled mb-1 small">
                                    <?php foreach($rosesList as $r): ?>
                                        <li>• <?php echo $r['quantity']; ?>x <?php echo htmlspecialchars($r['name']); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                                <?php if($gift['message_content']): ?>
                                    <div class="alert alert-secondary p-1 mb-0 small">
                                        <i class="fas fa-quote-left"></i> <em><?php echo htmlspecialchars($gift['message_content']); ?></em>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if($gift['is_distributed']): ?>
                                    <span class="badge bg-success">Distribué</span>
                                    <div class="small text-muted"><?php echo date('d/m H:i', strtotime($gift['distributed_at'])); ?></div>
                                <?php elseif($gift['is_prepared']): ?>
                                    <span class="badge bg-info text-dark">Prêt</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">En attente</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <?php
    exit; // Fin du traitement AJAX
}


// =============================================================================
// 2. LOGIQUE D'AFFICHAGE DE LA PAGE (RECHERCHE & VUES)
// =============================================================================

$view = $_GET['view'] ?? 'list'; // 'list' (élèves) ou 'classes' (par classe)
$search = trim($_GET['q'] ?? '');

$results = [];

// --- REQUÊTES SQL ---

if ($view === 'classes') {
    // VUE PAR CLASSE
    // On récupère d'abord les classes qui correspondent à la recherche (ou toutes)
    $sqlClasses = "SELECT * FROM classes WHERE 1=1 ";
    $paramsC = [];
    
    if ($search) {
        $sqlClasses .= " AND (name LIKE ? OR id LIKE ?) ";
        $paramsC[] = "%$search%";
        $paramsC[] = "%$search%";
    }
    $sqlClasses .= " ORDER BY name ASC";
    
    $stmt = $pdo->prepare($sqlClasses);
    $stmt->execute($paramsC);
    $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Pour chaque classe, on récupère les élèves (seulement si on n'a pas trop de classes affichées, sinon ça rame)
    // Astuce : On récupère TOUS les élèves d'un coup et on trie en PHP pour éviter 50 requêtes SQL
    $sqlAllStudents = "SELECT id, nom, prenom, class_id FROM recipients ORDER BY nom ASC";
    $allStudents = $pdo->query($sqlAllStudents)->fetchAll(PDO::FETCH_GROUP | PDO::FETCH_ASSOC); 
    // FETCH_GROUP regroupe par la 1ère colonne (mais ici l'ID est unique donc ce n'est pas idéal pour class_id)
    
    // On refait une requête propre : 
    // On récupère les élèves dont la class_id est dans la liste des classes trouvées
    // (Pour simplifier ici, on va faire une requête globale filtrée si recherche)
    
} else {
    // VUE LISTE (DÉFAUT)
    $sql = "
        SELECT r.*, c.name as class_name, c.id as class_real_id
        FROM recipients r
        LEFT JOIN classes c ON r.class_id = c.id
        WHERE 1=1
    ";
    $params = [];

    if ($search) {
        $sql .= " AND (
            r.nom LIKE ? OR 
            r.prenom LIKE ? OR 
            CONCAT(r.prenom, ' ', r.nom) LIKE ? OR
            r.id LIKE ? OR
            c.name LIKE ?
        )";
        $term = "%$search%";
        $params = [$term, $term, $term, "%$search%", $term];
    }
    
    // Pagination simple (limite 100 pour ne pas crasher le navigateur si pas de recherche)
    if (empty($search)) {
        $sql .= " ORDER BY r.nom ASC";
    } else {
        $sql .= " ORDER BY r.nom ASC";
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <title>Annuaire Élèves & Classes</title>
    <?php include 'head_imports.php'; ?>
    <style>
        body { background-color: #f4f6f9; }
        .cursor-pointer { cursor: pointer; }
        .hover-shadow:hover { background-color: #f1f1f1; }
    </style>
</head>
<body>

<?php include 'navbar.php'; ?>

<div class="container mt-4 mb-5">

    <div class="row mb-4 align-items-center">
        <div class="col-md-6">
            <h2 class="fw-bold text-info"><i class="fas fa-address-book text-info"></i> Annuaire du Lycée</h2>
        </div>
        <div class="col-md-6 text-md-end">
            <div class="btn-group">
                <a href="?view=list&q=<?php echo urlencode($search); ?>" class="btn <?php echo $view === 'list' ? 'btn-primary' : 'btn-outline-primary'; ?>">
                    <i class="fas fa-list"></i> Liste Élèves
                </a>
                <a href="?view=classes&q=<?php echo urlencode($search); ?>" class="btn <?php echo $view === 'classes' ? 'btn-primary' : 'btn-outline-primary'; ?>">
                    <i class="fas fa-users"></i> Par Classe
                </a>
            </div>
        </div>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" action="directory.php" class="row g-2">
                <input type="hidden" name="view" value="<?php echo htmlspecialchars($view); ?>">
                <div class="col-md-10">
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-search"></i></span>
                        <input type="text" name="q" class="form-control" placeholder="Rechercher un nom, prénom, classe, ID..." value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-dark w-100">Chercher</button>
                </div>
            </form>
        </div>
    </div>

    <?php if ($view === 'list'): ?>
        
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white py-3">
                <h5 class="mb-0">Résultats : <?php echo count($results); ?> élèves affichés</h5>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th>Nom</th>
                            <th>Prénom</th>
                            <th>Classe</th>
                            <th>ID Classe</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($results as $row): ?>
                        <tr class="cursor-pointer hover-shadow" onclick="openStudentModal(<?php echo $row['id']; ?>)">
                            <td><span class="badge bg-secondary"><?php echo $row['id']; ?></span></td>
                            <td class="fw-bold"><?php echo htmlspecialchars($row['nom']); ?></td>
                            <td><?php echo htmlspecialchars($row['prenom']); ?></td>
                            <td>
                                <?php if($row['class_name']): ?>
                                    <span class="badge bg-primary"><?php echo htmlspecialchars($row['class_name']); ?></span>
                                <?php else: ?>
                                    <span class="text-muted small">Sans classe</span>
                                <?php endif; ?>
                            </td>
                            <td class="small text-muted">
                                <?php echo $row['class_real_id'] ? '#' . $row['class_real_id'] : '-'; ?>
                            </td>
                            <td class="text-end">
                                <button class="btn btn-sm btn-outline-info">
                                    <i class="fas fa-eye"></i> Détails
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (count($results) == 0): ?>
                            <tr><td colspan="6" class="text-center py-4">Aucun élève trouvé.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    <?php else: ?>
        <div class="accordion" id="accordionClasses">
            <?php foreach ($classes as $cls): ?>
                <?php 
                    // Récupération "lazy" des élèves de cette classe
                    // Optimisation : On pourrait le faire en une seule grosse requête avant la boucle si c'est trop lent.
                    $stmtS = $pdo->prepare("SELECT * FROM recipients WHERE class_id = ? ORDER BY nom ASC");
                    $stmtS->execute([$cls['id']]);
                    $studentsInClass = $stmtS->fetchAll(PDO::FETCH_ASSOC);
                    $count = count($studentsInClass);
                ?>
                <div class="accordion-item mb-2 shadow-sm border-0">
                    <h2 class="accordion-header" id="heading<?php echo $cls['id']; ?>">
                        <button class="accordion-button collapsed fw-bold" type="button" data-bs-toggle="collapse" data-bs-target="#collapse<?php echo $cls['id']; ?>">
                            <span class="badge bg-primary me-2"><?php echo htmlspecialchars($cls['name']); ?></span>
                            <span class="small text-muted me-auto">ID Classe: <?php echo $cls['id']; ?></span>
                            <span class="badge bg-secondary rounded-pill"><?php echo $count; ?> élèves</span>
                        </button>
                    </h2>
                    <div id="collapse<?php echo $cls['id']; ?>" class="accordion-collapse collapse" data-bs-parent="#accordionClasses">
                        <div class="accordion-body p-0">
                            <ul class="list-group list-group-flush">
                                <?php foreach ($studentsInClass as $stu): ?>
                                    <li class="list-group-item list-group-item-action d-flex justify-content-between align-items-center cursor-pointer"
                                        onclick="openStudentModal(<?php echo $stu['id']; ?>)">
                                        <div>
                                            <span class="badge bg-light text-dark border me-2">#<?php echo $stu['id']; ?></span>
                                            <strong><?php echo htmlspecialchars($stu['nom']); ?></strong> <?php echo htmlspecialchars($stu['prenom']); ?>
                                        </div>
                                        <i class="fas fa-chevron-right text-muted small"></i>
                                    </li>
                                <?php endforeach; ?>
                                <?php if($count == 0): ?>
                                    <li class="list-group-item text-muted font-italic">Aucun élève dans cette classe.</li>
                                <?php endif; ?>
                            </ul>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php if (count($classes) == 0): ?>
                <div class="alert alert-warning">Aucune classe trouvée.</div>
            <?php endif; ?>
        </div>

    <?php endif; ?>

</div>

<div class="modal fade" id="studentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Fiche Élève</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="studentModalBody">
                <div class="text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Chargement...</span>
                    </div>
                    <p class="mt-2 text-muted">Récupération des informations...</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fermer</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    function openStudentModal(id) {
        // 1. Ouvrir la modale
        var myModal = new bootstrap.Modal(document.getElementById('studentModal'));
        myModal.show();

        // 2. Afficher le loader (reset du contenu précédent)
        const modalBody = document.getElementById('studentModalBody');
        modalBody.innerHTML = `
            <div class="text-center py-5">
                <div class="spinner-border text-primary" role="status"></div>
                <p class="mt-2 text-muted">Chargement...</p>
            </div>`;

        // 3. Appel AJAX vers la même page
        fetch('directory', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'ajax_get_details=1&student_id=' + id
        })
        .then(response => response.text())
        .then(html => {
            modalBody.innerHTML = html;
        })
        .catch(error => {
            modalBody.innerHTML = '<div class="alert alert-danger">Erreur de chargement.</div>';
            console.error('Erreur:', error);
        });
    }
</script>
</body>
</html>