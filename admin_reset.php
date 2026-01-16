<?php
// admin_reset.php
require_once 'auth_check.php';
require_once 'db.php';

checkAccess('admin');

// Sécurité supplémentaire : seul l'utilisateur avec l'ID 2 (Super Admin) peut accéder à cette page
// Assurez-vous que votre ID est bien le 2, sinon changez ce chiffre.
$current_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? 0;
if ($current_id != 2) {
    header("Location: admin.php"); 
    exit; 
}

// --- CONFIGURATION DES GROUPES DE TABLES ---

$groups = [
    'orders' => [
        'title' => '📦 Commandes & Ventes (Opérationnel)',
        'icon' => 'fa-shopping-cart',
        'desc' => 'Supprime toutes les commandes, les destinataires, les emplois du temps liés et les détails des roses.',
        'tables' => [
            'recipient_roses'     => 'Détails des roses (Couleurs)',
            'schedules'           => 'Emplois du temps (Table pivot)', // Mise à jour ici
            'order_recipients'    => 'Liaison Commande-Élève (Etiquettes)',
            'recipients'          => 'Élèves Destinataires (Infos brutes)', // Ajout important
            'orders'              => 'Commandes (En-têtes)'
        ]
    ],
    'logs' => [
        'title' => '📜 Logs & Communications',
        'icon' => 'fa-file-alt',
        'desc' => 'Vide l\'historique des actions et les messages de contact.',
        'tables' => [
            'contact_messages' => 'Messages reçus (Contact)',
            // 'audit_logs'    => 'Logs d\'audit' // Décommentez si vous avez créé cette table
        ]
    ],
    'system' => [
        'title' => '⚙️ Configuration (Structure Lycée)',
        'icon' => 'fa-cogs',
        'class' => 'text-danger',
        'desc' => '⚠️ DANGER : Supprime les classes, salles et bâtiments. À utiliser uniquement avant une réimportation CSV complète.',
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

$message = '';
$message_type = '';

// --- TRAITEMENT ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    $tables_to_clean = $_POST['tables'] ?? [];
    $clean_users = isset($_POST['clean_users']);

    if (!empty($tables_to_clean) || $clean_users) {
        try {
            // 1. Désactiver les vérifications de clés étrangères pour permettre le TRUNCATE
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 0"); 

            $count = 0;

            // A. Traitement des tables classiques (TRUNCATE)
            foreach ($tables_to_clean as $table) {
                // Vérification de sécurité : la table doit exister dans notre config
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
                // Supprime les utilisateurs qui n'ont pas le rôle 'admin' ou 'cvl'
                // On suppose ici que la colonne 'role' existe dans project_users
                // OU on utilise la logique de la table cvl_members si elle existe.
                
                // Option 1 : Si vous avez une table cvl_members
                // $sql = "DELETE FROM project_users WHERE user_id NOT IN (SELECT user_id FROM cvl_members)";
                
                // Option 2 (Plus générique basée sur les rôles) :
                $sql = "DELETE FROM project_users WHERE role NOT IN ('admin', 'cvl')";
                
                $stmt = $pdo->exec($sql);
                $count++;
            }

            // 2. Réactiver les Foreign Keys
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

            $message = "<strong>Succès !</strong> La base de données a été nettoyée ($count tables/opérations traitées).";
            $message_type = "success";

        } catch (PDOException $e) {
            // En cas d'erreur, on tente de réactiver les FK quand même
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nettoyage BDD - Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
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
                        Cette page permet de remettre à zéro certaines parties de la base de données (par exemple après une phase de test).
                        <br><strong class="text-danger">Note : Les actions sont irréversibles.</strong>
                    </p>

                    <form method="post" onsubmit="return confirm('Êtes-vous ABSOLUMENT sûr de vouloir supprimer ces données ? Cette action est irréversible.');">
                        
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
                                        <strong>Supprimer les comptes Élèves uniquement</strong>
                                        <div class="small text-muted mt-1">
                                            Conserve les comptes Admin et CVL.
                                        </div>
                                    </div>
                                    <span class="badge bg-warning text-dark font-monospace">project_users (filtre)</span>
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