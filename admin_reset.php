<?php
// admin_reset.php
require_once 'auth_check.php';
require_once 'db.php';

checkAccess('admin');

if ($current_user_id != 2) {
    // Si ce n'est pas toi, on redirige vers le menu principal
    header("Location: admin.php"); 
    exit; // On arrête tout immédiatement
}

// --- CONFIGURATION DES GROUPES DE TABLES ---

$groups = [
    'orders' => [
        'title' => '📦 Commandes & Ventes (Opérationnel)',
        'icon' => 'fa-shopping-cart',
        'desc' => 'Supprime toutes les commandes, les destinataires et les détails des roses vendues.',
        'tables' => [
            'recipient_roses'     => 'Détails des roses (Couleurs)',
            'recipient_schedules' => 'Emplois du temps copiés',
            'order_recipients'    => 'Destinataires',
            'orders'              => 'Commandes (En-têtes)'
        ]
    ],
    'logs' => [
        'title' => '📜 Logs & Communications',
        'icon' => 'fa-file-alt',
        'desc' => 'Vide l\'historique des actions et les messages de contact.',
        'tables' => [
            'contact_messages' => 'Messages reçus (Contact)',
            'audit_logs'       => 'Logs d\'audit technique' // Si tu as créé cette table
        ]
    ],
    'system' => [
        'title' => '⚙️ Configuration (Salles, Classes...)',
        'icon' => 'fa-cogs',
        'class' => 'text-danger',
        'desc' => '⚠️ ATTENTION : Supprime la structure du lycée. À n\'utiliser que si vous allez réimporter les CSV.',
        'tables' => [
            'classes'             => 'Classes',
            'class_levels'        => 'Niveaux de classe',
            'rooms'               => 'Salles',
            'floors'              => 'Étages',
            'buildings'           => 'Bâtiments',
            'rose_products'       => 'Catalogue (Produits)',
            'predefined_messages' => 'Messages prédéfinis'
        ]
    ]
];

// Gestion spéciale pour les utilisateurs
// On ne met pas project_users dans le TRUNCATE standard pour ne pas tuer l'admin connecté.

$message = '';
$message_type = '';

// --- TRAITEMENT ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    $tables_to_clean = $_POST['tables'] ?? [];
    $clean_users = isset($_POST['clean_users']);

    if (!empty($tables_to_clean) || $clean_users) {
        try {
            // 1. Désactiver les Foreign Keys
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 0"); 

            $count = 0;

            // A. Traitement des tables classiques (TRUNCATE)
            foreach ($tables_to_clean as $table) {
                // Vérification de sécurité (doit exister dans notre config)
                $is_allowed = false;
                foreach ($groups as $g) {
                    if (array_key_exists($table, $g['tables'])) $is_allowed = true;
                }

                if ($is_allowed) {
                    $pdo->exec("TRUNCATE TABLE $table");
                    $count++;
                }
            }

            // B. Traitement spécial Utilisateurs (DELETE intelligent)
            if ($clean_users) {
                // On supprime tous les utilisateurs QUI NE SONT PAS dans cvl_members
                // Cela protège les admins et le compte Super Admin
                $sql = "DELETE FROM project_users WHERE user_id NOT IN (SELECT user_id FROM cvl_members)";
                $stmt = $pdo->exec($sql);
                $count++;
                // On ne compte pas cvl_members car on n'y touche pas
            }

            // 2. Réactiver les Foreign Keys
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

            $message = "<strong>Succès !</strong> La base de données a été nettoyée ($count opérations effectuées).";
            $message_type = "success";

        } catch (PDOException $e) {
            try { $pdo->exec("SET FOREIGN_KEY_CHECKS = 1"); } catch(Exception $x) {}
            $message = "<strong>Erreur SQL :</strong> " . $e->getMessage();
            $message_type = "danger";
        }
    } else {
        $message = "Aucune action sélectionnée.";
        $message_type = "warning";
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Nettoyage BDD - Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-light">

<?php include 'navbar.php'; ?>

<div class="container mt-4 mb-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <a href="admin.php" class="text-decoration-none text-muted"><i class="fas fa-arrow-left"></i> Retour Hub</a>
            <h2 class="fw-bold mt-2 text-danger"><i class="fas fa-radiation me-2"></i>Zone de Réinitialisation</h2>
        </div>
    </div>

    <div class="row justify-content-center">
        <div class="col-lg-8">
            
            <?php if ($message): ?>
                <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show shadow-sm" role="alert">
                    <?php echo $message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <div class="card shadow border-danger">
                <div class="card-header bg-danger text-white py-3">
                    <h5 class="mb-0 fw-bold"><i class="fas fa-trash-alt me-2"></i>Nettoyage des données</h5>
                </div>
                <div class="card-body">
                    <p class="text-muted mb-4">
                        Cette page permet de remettre à zéro certaines parties de la base de données pour les tests.
                        <br><strong>Note :</strong> Les actions sont irréversibles.
                    </p>

                    <form method="post" onsubmit="return confirm('Êtes-vous ABSOLUMENT sûr de vouloir supprimer ces données ?');">
                        
                        <div class="mb-4">
                            <h6 class="fw-bold text-primary border-bottom pb-2">
                                <i class="fas <?php echo $groups['orders']['icon']; ?> me-2"></i><?php echo $groups['orders']['title']; ?>
                            </h6>
                            <p class="small text-muted"><?php echo $groups['orders']['desc']; ?></p>
                            <div class="list-group">
                                <?php foreach ($groups['orders']['tables'] as $tbl => $lbl): ?>
                                    <label class="list-group-item d-flex justify-content-between align-items-center">
                                        <div>
                                            <input class="form-check-input me-2" type="checkbox" name="tables[]" value="<?php echo $tbl; ?>" checked>
                                            <?php echo htmlspecialchars($lbl); ?>
                                        </div>
                                        <span class="badge bg-light text-muted font-monospace"><?php echo $tbl; ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="mb-4">
                            <h6 class="fw-bold text-warning text-dark border-bottom pb-2">
                                <i class="fas fa-users me-2"></i>Utilisateurs & Élèves
                            </h6>
                            <div class="list-group">
                                <label class="list-group-item list-group-item-warning d-flex justify-content-between align-items-center">
                                    <div>
                                        <input class="form-check-input me-2" type="checkbox" name="clean_users" value="1">
                                        <strong>Supprimer les inscrits (Élèves)</strong>
                                        <div class="small text-muted mt-1">
                                            Conserve uniquement les membres de l'équipe CVL/Admin.
                                        </div>
                                    </div>
                                    <span class="badge bg-warning text-dark font-monospace">project_users (partial)</span>
                                </label>
                            </div>
                        </div>

                        <div class="mb-4">
                            <h6 class="fw-bold text-secondary border-bottom pb-2">
                                <i class="fas <?php echo $groups['logs']['icon']; ?> me-2"></i><?php echo $groups['logs']['title']; ?>
                            </h6>
                            <div class="list-group">
                                <?php foreach ($groups['logs']['tables'] as $tbl => $lbl): ?>
                                    <label class="list-group-item d-flex justify-content-between align-items-center">
                                        <div>
                                            <input class="form-check-input me-2" type="checkbox" name="tables[]" value="<?php echo $tbl; ?>">
                                            <?php echo htmlspecialchars($lbl); ?>
                                        </div>
                                        <span class="badge bg-light text-muted font-monospace"><?php echo $tbl; ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="mb-4">
                            <h6 class="fw-bold text-danger border-bottom pb-2">
                                <i class="fas <?php echo $groups['system']['icon']; ?> me-2"></i><?php echo $groups['system']['title']; ?>
                            </h6>
                            <div class="alert alert-danger py-2 small">
                                <i class="fas fa-exclamation-triangle me-1"></i> Ne cochez ceci que si vous devez réimporter toute la structure du lycée via CSV.
                            </div>
                            <div class="list-group">
                                <?php foreach ($groups['system']['tables'] as $tbl => $lbl): ?>
                                    <label class="list-group-item d-flex justify-content-between align-items-center bg-light">
                                        <div>
                                            <input class="form-check-input me-2" type="checkbox" name="tables[]" value="<?php echo $tbl; ?>">
                                            <?php echo htmlspecialchars($lbl); ?>
                                        </div>
                                        <span class="badge bg-danger text-white font-monospace"><?php echo $tbl; ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="d-grid gap-2 mt-5">
                            <button type="submit" class="btn btn-danger btn-lg fw-bold shadow">
                                <i class="fas fa-dumpster-fire me-2"></i>EXÉCUTER LE NETTOYAGE
                            </button>
                            <a href="admin.php" class="btn btn-outline-secondary">Annuler</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>