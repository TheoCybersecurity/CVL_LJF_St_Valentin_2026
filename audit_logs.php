<?php
// audit_logs.php
require_once 'db.php';
require_once 'auth_check.php';

checkAccess('admin'); // Sécurité maximale

// --- 1. FILTRES ---
$filterAdmin = $_GET['admin'] ?? '';
$filterAction = $_GET['action'] ?? '';
$search = $_GET['q'] ?? '';

// --- 2. REQUÊTE SQL ---
$sql = "
    SELECT 
        al.*,
        u.nom, u.prenom
    FROM audit_logs al
    LEFT JOIN users u ON al.user_id = u.user_id
    WHERE 1=1
";

$params = [];

if (!empty($filterAdmin)) {
    $sql .= " AND al.user_id = ? ";
    $params[] = $filterAdmin;
}

if (!empty($filterAction)) {
    $sql .= " AND al.action = ? ";
    $params[] = $filterAction;
}

if (!empty($search)) {
    $sql .= " AND (al.details LIKE ? OR al.target_id LIKE ? OR al.ip_address LIKE ?) ";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$sql .= " ORDER BY al.created_at DESC LIMIT 200"; // Limite pour la performance

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Listes pour les filtres (select)
$admins = $pdo->query("SELECT DISTINCT u.user_id, u.nom, u.prenom FROM audit_logs al JOIN users u ON al.user_id = u.user_id ORDER BY u.nom")->fetchAll(PDO::FETCH_ASSOC);
$actions = $pdo->query("SELECT DISTINCT action FROM audit_logs ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);

// Fonction simple pour rendre le UserAgent lisible
function parseUserAgent($ua) {
    if (empty($ua)) return 'Inconnu';
    $os = 'Autre';
    if (strpos($ua, 'Windows') !== false) $os = '<i class="fab fa-windows"></i> Windows';
    elseif (strpos($ua, 'iPhone') !== false) $os = '<i class="fab fa-apple"></i> iPhone';
    elseif (strpos($ua, 'Mac') !== false) $os = '<i class="fab fa-apple"></i> Mac';
    elseif (strpos($ua, 'Android') !== false) $os = '<i class="fab fa-android"></i> Android';
    elseif (strpos($ua, 'Linux') !== false) $os = '<i class="fab fa-linux"></i> Linux';
    
    $device = (strpos($ua, 'Mobile') !== false) ? '<i class="fas fa-mobile-alt"></i> Mobile' : '<i class="fas fa-desktop"></i> PC';
    
    return "$device <span class='text-muted'>|</span> $os";
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Audit Technique & Logs</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body { background-color: #f8f9fa; font-size: 0.9rem; }
        .table-logs td { vertical-align: middle; }
        .ua-info { font-size: 0.75rem; color: #666; }
        .badge-action { width: 100%; display: block; }
        pre { background: #f4f4f4; padding: 10px; border-radius: 5px; max-height: 300px; overflow-y: auto; font-size: 0.8rem; }
    </style>
</head>
<body>

<?php include 'navbar.php'; ?>

<div class="container-fluid px-4 mt-4 mb-5">
    
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="fw-bold text-danger"><i class="fas fa-shield-alt"></i> Logs Techniques</h2>
        <a href="logs.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left"></i> Retour Logs Métier</a>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-body py-2">
            <form method="GET" class="row g-2 align-items-center">
                <div class="col-md-3">
                    <select name="admin" class="form-select form-select-sm">
                        <option value="">-- Tous les Admins --</option>
                        <?php foreach($admins as $a): ?>
                            <option value="<?php echo $a['user_id']; ?>" <?php echo $filterAdmin == $a['user_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($a['prenom'] . ' ' . $a['nom']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <select name="action" class="form-select form-select-sm">
                        <option value="">-- Toutes les Actions --</option>
                        <?php foreach($actions as $act): ?>
                            <option value="<?php echo $act; ?>" <?php echo $filterAction == $act ? 'selected' : ''; ?>>
                                <?php echo $act; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <input type="text" name="q" class="form-control form-control-sm" placeholder="Recherche (IP, Détails, ID...)" value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-sm btn-primary w-100">Filtrer</button>
                </div>
                <div class="col-md-1">
                    <a href="audit_logs.php" class="btn btn-sm btn-outline-secondary w-100">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow border-0">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped table-hover table-logs mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th style="width: 140px;">Date</th>
                            <th>Admin / User</th>
                            <th>Action</th>
                            <th>Cible</th>
                            <th>Détails</th>
                            <th>Technique (IP / OS)</th>
                            <th class="text-end">Données</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($logs as $log): ?>
                        <tr>
                            <td class="small fw-bold"><?php echo date('d/m H:i:s', strtotime($log['created_at'])); ?></td>
                            
                            <td>
                                <?php if($log['nom']): ?>
                                    <i class="fas fa-user-circle text-secondary"></i> 
                                    <?php echo htmlspecialchars($log['prenom'] . ' ' . $log['nom']); ?>
                                <?php else: ?>
                                    <span class="text-muted font-italic">Système / Inconnu</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php 
                                    $badgeClass = 'bg-secondary';
                                    if(strpos($log['action'], 'DELETE') !== false) $badgeClass = 'bg-danger';
                                    elseif(strpos($log['action'], 'UPDATE') !== false) $badgeClass = 'bg-warning text-dark';
                                    elseif(strpos($log['action'], 'VALIDATED') !== false) $badgeClass = 'bg-success';
                                    elseif(strpos($log['action'], 'CONFIRMED') !== false) $badgeClass = 'bg-info text-dark';
                                ?>
                                <span class="badge <?php echo $badgeClass; ?> badge-action">
                                    <?php echo $log['action']; ?>
                                </span>
                            </td>

                            <td class="small">
                                <strong><?php echo ucfirst($log['target_type']); ?></strong>
                                <span class="text-muted">#<?php echo $log['target_id']; ?></span>
                            </td>

                            <td class="small text-truncate" style="max-width: 250px;">
                                <?php echo htmlspecialchars($log['details']); ?>
                            </td>

                            <td class="ua-info">
                                <div><i class="fas fa-network-wired"></i> <?php echo $log['ip_address']; ?></div>
                                <div><?php echo parseUserAgent($log['user_agent']); ?></div>
                            </td>

                            <td class="text-end">
                                <?php if(!empty($log['old_value']) || !empty($log['new_value'])): ?>
                                    <button class="btn btn-sm btn-outline-primary btn-view-diff" 
                                            data-bs-toggle="modal" 
                                            data-bs-target="#modalDiff"
                                            /* AJOUT DE "?? ''" POUR EVITER LE NULL */
                                            data-old='<?php echo htmlspecialchars($log['old_value'] ?? '', ENT_QUOTES); ?>'
                                            data-new='<?php echo htmlspecialchars($log['new_value'] ?? '', ENT_QUOTES); ?>'>
                                        <i class="fas fa-code-branch"></i> Diff
                                    </button>
                                <?php else: ?>
                                    <span class="text-muted small">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalDiff" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-light">
                <h5 class="modal-title"><i class="fas fa-exchange-alt"></i> Détails des modifications</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-6 border-end">
                        <h6 class="text-danger border-bottom pb-2">❌ Avant (Old Value)</h6>
                        <pre id="jsonOld"></pre>
                    </div>
                    <div class="col-md-6">
                        <h6 class="text-success border-bottom pb-2">✅ Après (New Value)</h6>
                        <pre id="jsonNew"></pre>
                    </div>
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
    // Petit script JS pour formater le JSON joliment dans la modale
    const diffModal = document.getElementById('modalDiff');
    diffModal.addEventListener('show.bs.modal', function (event) {
        const button = event.relatedTarget;
        
        let oldVal = button.getAttribute('data-old');
        let newVal = button.getAttribute('data-new');

        try {
            // Tente de parser et de "pretty print" le JSON
            const oldObj = oldVal ? JSON.parse(oldVal) : null;
            const newObj = newVal ? JSON.parse(newVal) : null;
            
            document.getElementById('jsonOld').textContent = oldObj ? JSON.stringify(oldObj, null, 4) : "Aucune donnée";
            document.getElementById('jsonNew').textContent = newObj ? JSON.stringify(newObj, null, 4) : "Aucune donnée";
        } catch (e) {
            // Si ce n'est pas du JSON valide, on affiche le texte brut
            document.getElementById('jsonOld').textContent = oldVal || "Aucune donnée";
            document.getElementById('jsonNew').textContent = newVal || "Aucune donnée";
        }
    });
</script>
</body>
</html>