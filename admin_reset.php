<?php
/**
 * Administration - Réinitialisation du Système (Factory Reset)
 * admin_reset.php
 * * Ce script critique permet de vider sélectivement ou totalement la base de données.
 * Il est utilisé pour la maintenance annuelle ou le nettoyage des données de test.
 *
 * @warning ACTION IRRÉVERSIBLE.
 */

require_once 'auth_check.php';
require_once 'db.php';

// Vérification des droits d'administration
checkAccess('admin');

// --- SÉCURITÉ CRITIQUE ---
// Restriction d'accès : Seul le Super Administrateur (ID 2) est autorisé à accéder à cette page.
// Cette mesure protège l'intégrité du système contre les erreurs de manipulation accidentelles.
$current_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? 0;
if ($current_id != 2) {
    // Redirection de sécurité
    header("Location: admin.php"); 
    exit; 
}

// --- CONFIGURATION DES GROUPES DE TABLES ---

$groups = [
    'orders' => [
        'title' => '📦 Commandes & Ventes (Opérationnel)',
        'icon' => 'fa-shopping-cart',
        'desc' => 'Supprime toutes les commandes, les destinataires, les emplois du temps associés et le contenu des paniers.',
        'tables' => [
            'recipient_roses'     => 'Détails des roses (Contenu panier)',
            'order_recipients'    => 'Liaison Commande-Élève',
            'schedules'           => 'Emplois du temps (Liés aux destinataires)',
            'recipients'          => 'Destinataires (Infos élèves ciblés)',
            'orders'              => 'Commandes (Facturation)'
        ]
    ],
    'logs' => [
        'title' => '📜 Logs & Historique',
        'icon' => 'fa-file-alt',
        'desc' => 'Vide l\'historique de sécurité.',
        'tables' => [
            'audit_logs'          => 'Logs d\'audit (Actions système)'
        ]
    ],
    'system' => [
        'title' => '⚙️ Structure Lycée & Catalogue',
        'icon' => 'fa-cogs',
        'class' => 'text-danger',
        'desc' => '⚠️ DANGER : Supprime les classes, salles, bâtiments et produits. À utiliser uniquement pour une remise à zéro totale.',
        'tables' => [
            'users'               => 'Utilisateurs (Voir option spécifique plus bas)', // Indicatif pour la liste interne
            'classes'             => 'Classes',
            'class_levels'        => 'Niveaux de classe',
            'rooms'               => 'Salles',
            'floors'              => 'Étages',
            'buildings'           => 'Bâtiments',
            'rose_products'       => 'Catalogue (Types de roses)',
            'roses_prices'        => 'Grille Tarifaire',
            'predefined_messages' => 'Messages prédéfinis',
            'global_settings'     => 'Paramètres globaux du site'
        ]
    ]
];

$message = '';
$message_type = '';

// --- TRAITEMENT DU FORMULAIRE ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    $tables_to_clean = $_POST['tables'] ?? [];
    $clean_users_only = isset($_POST['clean_users_only']);

    if (!empty($tables_to_clean) || $clean_users_only) {
        try {
            // 1. Désactivation des contraintes de clés étrangères pour permettre le vidage des tables (TRUNCATE)
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 0"); 

            $count = 0;

            // A. Nettoyage des tables standards sélectionnées
            foreach ($tables_to_clean as $table) {
                // Vérification de sécurité : la table doit être explicitement autorisée dans la configuration
                $is_allowed = false;
                foreach ($groups as $g) {
                    if (array_key_exists($table, $g['tables'])) $is_allowed = true;
                }

                // Exception : la table 'users' est gérée séparément si l'option spécifique est active
                if ($table === 'users' && $clean_users_only) {
                    continue; 
                }

                if ($is_allowed) {
                    $pdo->exec("TRUNCATE TABLE $table");
                    $count++;
                }
            }

            // B. Traitement spécifique : Suppression des comptes élèves (Conservation des administrateurs)
            if ($clean_users_only) {
                // Suppression des utilisateurs ne figurant pas dans la liste des membres du CVL.
                // Protection additionnelle du compte Super Admin (ID 2).
                $sql = "DELETE FROM users 
                        WHERE user_id NOT IN (SELECT user_id FROM cvl_members) 
                        AND user_id != 2";
                
                $stmt = $pdo->exec($sql); // Exécution de la requête de suppression
                // (Action non comptabilisée comme un truncate de table entière)
            }

            // 2. Réactivation des clés étrangères
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

            $message = "<strong>Succès !</strong> La base de données a été nettoyée ($count tables réinitialisées).";
            $message_type = "success";

            // Enregistrement dans les logs (si la table d'audit n'a pas été vidée par l'action actuelle)
            if (!in_array('audit_logs', $tables_to_clean)) {
                $details = "Tables: " . implode(', ', $tables_to_clean);
                if ($clean_users_only) $details .= " + Users (Students only)";
                
                // Insertion directe pour éviter les dépendances
                $stmtLog = $pdo->prepare("INSERT INTO audit_logs (user_id, target_type, target_id, action, details, ip_address) VALUES (?, 'system', 0, 'DB_RESET', ?, ?)");
                $stmtLog->execute([$current_id, $details, $_SERVER['REMOTE_ADDR']]);
            }

        } catch (PDOException $e) {
            // Tentative de réactivation des clés étrangères en cas d'échec critique
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
    <title>Nettoyage BDD - Admin</title>
    <?php include 'head_imports.php'; ?>
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
                        Cette page permet de remettre à zéro certaines parties de la base de données.
                        <br><strong class="text-danger"><i class="fas fa-exclamation-triangle"></i> Attention : Les actions sont irréversibles.</strong>
                    </p>

                    <form method="post" onsubmit="return confirm('Êtes-vous ABSOLUMENT sûr de vouloir supprimer ces données ? Cette action est irréversible.');">
                        
                        <div class="mb-4">
                            <h6 class="fw-bold text-primary border-bottom pb-2">
                                <i class="fas <?php echo $groups['orders']['icon']; ?> me-2"></i><?php echo $groups['orders']['title']; ?>
                            </h6>
                            <p class="small text-muted mb-2"><?php echo $groups['orders']['desc']; ?></p>
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
                                        <input class="form-check-input me-2" type="checkbox" name="clean_users_only" value="1">
                                        <strong>Supprimer uniquement les Élèves (Non-CVL)</strong>
                                        <div class="small text-muted mt-1">
                                            Conserve les comptes présents dans la table <code>cvl_members</code> et le Super Admin.
                                            Supprime tous les autres inscrits.
                                        </div>
                                    </div>
                                    <span class="badge bg-warning text-dark font-monospace">users (filtre)</span>
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
                                <i class="fas fa-exclamation-triangle me-1"></i> Ne cochez ceci que si vous devez réimporter toute la structure du lycée (CSV) ou reconfigurer le site de zéro.
                            </div>
                            <div class="list-group">
                                <?php foreach ($groups['system']['tables'] as $tbl => $lbl): ?>
                                    <?php if ($tbl === 'users') continue; // La table 'users' est gérée via l'option dédiée ci-dessus ?>
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