<?php
// admin_reset.php
require_once 'auth_check.php'; // On s'assure qu'on est connecté
require_once 'db.php';

// --- CONFIGURATION ---
// Liste des tables qu'on a le droit de vider
$tables_config = [
    'project_users'       => '👥 Utilisateurs (Inscriptions locales)',
    'orders'              => '🛒 Commandes (En-têtes)',
    'order_recipients'    => '💌 Destinataires',
    'recipient_roses'     => '🌹 Détails des roses (Couleurs/Quantités)',
    'recipient_schedules' => '📅 Emplois du temps des destinataires'
];

$message = '';
$message_type = ''; // success, danger

// --- TRAITEMENT DU FORMULAIRE ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['tables'])) {
    
    $tables_to_clean = $_POST['tables']; // Tableau des tables cochées

    if (!empty($tables_to_clean)) {
        try {
            // 1. On désactive la sécu des clés étrangères
            // Pas de transaction ici car TRUNCATE ne les supporte pas sous MySQL
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 0"); 

            $count = 0;
            foreach ($tables_to_clean as $table) {
                // Sécurité : On vérifie que la table est autorisée
                if (array_key_exists($table, $tables_config)) {
                    $pdo->exec("TRUNCATE TABLE $table");
                    $count++;
                }
            }

            // 2. On réactive la sécu (Très important de le faire à la fin)
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

            $message = "Succès ! $count table(s) ont été vidées et remises à zéro.";
            $message_type = "success";

        } catch (PDOException $e) {
            // En cas d'erreur, on essaie quand même de remettre la sécurité
            try { $pdo->exec("SET FOREIGN_KEY_CHECKS = 1"); } catch(Exception $x) {}
            
            $message = "Erreur SQL : " . $e->getMessage();
            $message_type = "danger";
        }
    } else {
        $message = "Aucune table sélectionnée.";
        $message_type = "warning";
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Administration - Nettoyage BDD</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">

<div class="container py-5">
    <div class="card shadow-lg mx-auto" style="max-width: 600px;">
        <div class="card-header bg-danger text-white">
            <h4 class="mb-0">🧹 Zone de Nettoyage (Dev)</h4>
        </div>
        <div class="card-body">
            
            <p class="text-muted">Sélectionnez les données à supprimer pour remettre à zéro les tests.</p>

            <?php if ($message): ?>
                <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
                    <?php echo $message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <form method="post">
                <div class="mb-3">
                    <div class="d-flex justify-content-between mb-2">
                        <label class="fw-bold">Tables disponibles :</label>
                        <div>
                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="toggleAll(true)">Tout cocher</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="toggleAll(false)">Tout décocher</button>
                        </div>
                    </div>

                    <div class="list-group">
                        <?php foreach ($tables_config as $table_sql => $label): ?>
                            <label class="list-group-item">
                                <input class="form-check-input me-1 table-checkbox" type="checkbox" name="tables[]" value="<?php echo $table_sql; ?>">
                                <?php echo htmlspecialchars($label); ?>
                                <small class="text-muted d-block" style="font-size: 0.8em; margin-left: 1.7em;">Table : <?php echo $table_sql; ?></small>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="d-grid gap-2">
                    <button type="submit" class="btn btn-danger fw-bold">🗑️ VIDER LA SÉLECTION</button>
                    <a href="index.php" class="btn btn-light">Retour au site</a>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    function toggleAll(check) {
        document.querySelectorAll('.table-checkbox').forEach(cb => cb.checked = check);
    }
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>