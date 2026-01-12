<?php
// admin_config.php
require_once 'db.php';
require_once 'auth_check.php';

checkAccess('admin');

// --- FONCTIONS UTILITAIRES ---
function redirect($tab) {
    header("Location: admin_config.php?tab=" . $tab);
    exit;
}

function setToast($type, $msg) {
    $_SESSION['toast'] = ['type' => $type, 'message' => $msg];
}

// --- FONCTION POUR RÉCUPÉRER UN PARAMÈTRE ---
function getGlobalSetting($pdo, $key) {
    $stmt = $pdo->prepare("SELECT setting_value FROM global_settings WHERE setting_key = ?");
    $stmt->execute([$key]);
    return $stmt->fetchColumn();
}

// --- TRAITEMENT AJAX (Drag & Drop - Messages) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reorder_messages') {
    error_reporting(0); 
    ini_set('display_errors', 0);
    if (ob_get_length()) ob_clean(); 
    header('Content-Type: application/json');

    try {
        $order = json_decode($_POST['order'], true);
        if (is_array($order)) {
            $pdo->beginTransaction();
            foreach ($order as $position => $id) {
                $safeId = intval($id);
                if ($safeId > 0) {
                    $stmt = $pdo->prepare("UPDATE predefined_messages SET position = :pos WHERE id = :id");
                    $stmt->execute([':pos' => $position, ':id' => $safeId]);
                }
            }
            $pdo->commit();
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Données invalides']);
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => 'Erreur SQL']);
    }
    exit; 
}

// --- TRAITEMENT DES ACTIONS (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        // --- 1. GESTION DES ROSES ---
        if ($action === 'add_rose') {
            // Suppression du prix et de is_active (mis à 1 par défaut)
            $stmt = $pdo->prepare("INSERT INTO rose_products (name, is_active) VALUES (?, 1)");
            $stmt->execute([$_POST['name']]);
            setToast('success', 'Nouvelle variété ajoutée.');
            redirect('roses');
        }
        elseif ($action === 'update_rose') {
            // On ne modifie plus que le nom
            $stmt = $pdo->prepare("UPDATE rose_products SET name = ? WHERE id = ?");
            $stmt->execute([$_POST['name'], $_POST['id']]);
            setToast('success', 'Variété modifiée.');
            redirect('roses');
        }

        // --- 2. GESTION DES TARIFS (NOUVEAU) ---
        elseif ($action === 'add_price_rule') {
            $qty = intval($_POST['quantity']);
            $price = floatval($_POST['price']);
            // Mise à jour si la quantité existe déjà
            $stmt = $pdo->prepare("INSERT INTO roses_prices (quantity, price) VALUES (?, ?) ON DUPLICATE KEY UPDATE price = ?");
            $stmt->execute([$qty, $price, $price]);
            setToast('success', 'Tarif ajouté/mis à jour.');
            redirect('roses');
        }
        elseif ($action === 'update_price_rule') {
            $old_qty = intval($_POST['original_quantity']);
            $new_qty = intval($_POST['quantity']);
            $price = floatval($_POST['price']);
            
            // Si on change la quantité (Clé primaire), on fait un UPDATE avec WHERE
            $stmt = $pdo->prepare("UPDATE roses_prices SET quantity = ?, price = ? WHERE quantity = ?");
            $stmt->execute([$new_qty, $price, $old_qty]);
            setToast('success', 'Tarif modifié.');
            redirect('roses');
        }

        // --- 3. GESTION DES CLASSES & NIVEAUX ---
        elseif ($action === 'add_class') {
            $stmt = $pdo->prepare("INSERT INTO classes (name, level_id) VALUES (?, ?)");
            $stmt->execute([trim($_POST['name']), $_POST['level_id']]);
            setToast('success', 'Classe ajoutée.');
            redirect('classes');
        }
        elseif ($action === 'update_class') {
            $stmt = $pdo->prepare("UPDATE classes SET name = ?, level_id = ? WHERE id = ?");
            $stmt->execute([trim($_POST['name']), $_POST['level_id'], $_POST['id']]);
            setToast('success', 'Classe mise à jour.');
            redirect('classes');
        }
        elseif ($action === 'add_level') {
            $stmt = $pdo->prepare("INSERT INTO class_levels (name, group_alias) VALUES (?, ?)");
            $stmt->execute([$_POST['name'], strtoupper(substr($_POST['name'], 0, 3))]);
            setToast('success', 'Niveau ajouté.');
            redirect('classes');
        }
        elseif ($action === 'update_level') {
            $stmt = $pdo->prepare("UPDATE class_levels SET name = ? WHERE id = ?");
            $stmt->execute([$_POST['name'], $_POST['id']]);
            setToast('success', 'Nom du niveau modifié.');
            redirect('classes');
        }

        // --- 4. GESTION DES SALLES, BÂTIMENTS, ÉTAGES ---
        elseif ($action === 'add_room') {
            $stmt = $pdo->prepare("INSERT INTO rooms (name, building_id, floor_id) VALUES (?, ?, ?)");
            $stmt->execute([trim($_POST['name']), $_POST['building_id'], $_POST['floor_id']]);
            setToast('success', 'Salle ajoutée.');
            redirect('rooms');
        }
        elseif ($action === 'update_room') {
            $stmt = $pdo->prepare("UPDATE rooms SET name = ?, building_id = ?, floor_id = ? WHERE id = ?");
            $stmt->execute([trim($_POST['name']), $_POST['building_id'], $_POST['floor_id'], $_POST['id']]);
            setToast('success', 'Salle modifiée.');
            redirect('rooms');
        }
        elseif ($action === 'add_building') {
            $stmt = $pdo->prepare("INSERT INTO buildings (name) VALUES (?)");
            $stmt->execute([$_POST['name']]);
            setToast('success', 'Bâtiment ajouté.');
            redirect('rooms');
        }
        elseif ($action === 'update_building') {
            $stmt = $pdo->prepare("UPDATE buildings SET name = ? WHERE id = ?");
            $stmt->execute([$_POST['name'], $_POST['id']]);
            setToast('success', 'Bâtiment renommé.');
            redirect('rooms');
        }
        elseif ($action === 'add_floor') {
            $stmt = $pdo->prepare("INSERT INTO floors (name, level_number) VALUES (?, ?)");
            $stmt->execute([$_POST['name'], intval($_POST['level_number'])]);
            setToast('success', 'Étage ajouté.');
            redirect('rooms');
        }
        elseif ($action === 'update_floor') {
            $stmt = $pdo->prepare("UPDATE floors SET name = ? WHERE id = ?");
            $stmt->execute([$_POST['name'], $_POST['id']]);
            setToast('success', 'Étage renommé.');
            redirect('rooms');
        }

        // --- 5. GESTION DES MESSAGES ---
        elseif ($action === 'add_message') {
            $stmt = $pdo->prepare("INSERT INTO predefined_messages (content) VALUES (?)");
            $stmt->execute([$_POST['content']]);
            setToast('success', 'Message ajouté.');
            redirect('messages');
        }
        elseif ($action === 'update_message_id') {
            $oldId = $_POST['old_id'];
            $newId = $_POST['new_id'];
            if ($oldId != $newId) {
                $check = $pdo->prepare("SELECT id FROM predefined_messages WHERE id = ?");
                $check->execute([$newId]);
                if ($check->rowCount() > 0) {
                    setToast('danger', 'Erreur : Cet ID est déjà pris.');
                } else {
                    $stmt = $pdo->prepare("UPDATE predefined_messages SET id = ? WHERE id = ?");
                    $stmt->execute([$newId, $oldId]);
                    setToast('success', 'ID du message modifié.');
                }
            }
            redirect('messages');
        }
        // --- 6. GESTION DE L'OUVERTURE DES VENTES ---
        elseif ($action === 'toggle_sales') {
            // Si la checkbox est cochée, on reçoit '1', sinon on ne reçoit rien donc '0'
            $status = isset($_POST['sales_status']) ? '1' : '0';
            
            $stmt = $pdo->prepare("INSERT INTO global_settings (setting_key, setting_value) VALUES ('sales_open', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
            $stmt->execute([$status, $status]);
            
            $msg = ($status == '1') ? "Les ventes sont OUVERTES." : "Les ventes sont FERMÉES.";
            setToast(($status == '1' ? 'success' : 'warning'), $msg);
            redirect('general'); // On redirige vers un nouvel onglet "Général"
        }

    } catch (PDOException $e) {
        setToast('danger', 'Erreur SQL : ' . $e->getMessage());
    }
}

// --- SUPPRESSIONS (GET) ---
if (isset($_GET['delete']) && isset($_GET['type'])) {
    $type = $_GET['type'];
    $id = intval($_GET['delete']); // Sert aussi pour la quantité
    $tab = 'roses';

    try {
        if ($type === 'rose') {
            $pdo->prepare("DELETE FROM rose_products WHERE id = ?")->execute([$id]);
            setToast('warning', 'Variété supprimée.');
            $tab = 'roses';
        }
        elseif ($type === 'price_rule') {
            $pdo->prepare("DELETE FROM roses_prices WHERE quantity = ?")->execute([$id]);
            setToast('warning', 'Tarif supprimé.');
            $tab = 'roses';
        }
        elseif ($type === 'class') {
            $pdo->prepare("DELETE FROM classes WHERE id = ?")->execute([$id]);
            setToast('warning', 'Classe supprimée.');
            $tab = 'classes';
        }
        elseif ($type === 'level') {
            $pdo->prepare("DELETE FROM class_levels WHERE id = ?")->execute([$id]);
            setToast('warning', 'Niveau supprimé.');
            $tab = 'classes';
        }
        elseif ($type === 'room') {
            $pdo->prepare("DELETE FROM rooms WHERE id = ?")->execute([$id]);
            setToast('warning', 'Salle supprimée.');
            $tab = 'rooms';
        }
        elseif ($type === 'building') {
            $pdo->prepare("DELETE FROM buildings WHERE id = ?")->execute([$id]);
            setToast('warning', 'Bâtiment supprimé.');
            $tab = 'rooms';
        }
        elseif ($type === 'floor') {
            $pdo->prepare("DELETE FROM floors WHERE id = ?")->execute([$id]);
            setToast('warning', 'Étage supprimé.');
            $tab = 'rooms';
        }
        elseif ($type === 'message') {
            $pdo->prepare("DELETE FROM predefined_messages WHERE id = ?")->execute([$id]);
            setToast('warning', 'Message supprimé.');
            $tab = 'messages';
        }
    } catch (PDOException $e) {
        setToast('danger', 'Impossible de supprimer : Donnée liée à des commandes existantes.');
    }
    redirect($tab);
}

// --- RECUPERATION DES DONNEES ---
// Roses triées par Nom (plus de prix)
$roses = $pdo->query("SELECT * FROM rose_products ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
// Prix triés par Quantité
$rosesPrices = $pdo->query("SELECT * FROM roses_prices ORDER BY quantity ASC")->fetchAll(PDO::FETCH_ASSOC);

$classes = $pdo->query("SELECT c.*, cl.name as level_name FROM classes c LEFT JOIN class_levels cl ON c.level_id = cl.id ORDER BY cl.id ASC, c.name ASC")->fetchAll(PDO::FETCH_ASSOC);
$levels = $pdo->query("SELECT * FROM class_levels ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

$rooms = $pdo->query("SELECT r.*, b.name as bat_name, f.name as floor_name FROM rooms r LEFT JOIN buildings b ON r.building_id = b.id LEFT JOIN floors f ON r.floor_id = f.id ORDER BY b.name ASC, f.level_number ASC, r.name ASC")->fetchAll(PDO::FETCH_ASSOC);
$buildings = $pdo->query("SELECT * FROM buildings ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$floors = $pdo->query("SELECT * FROM floors ORDER BY level_number ASC")->fetchAll(PDO::FETCH_ASSOC);

$messages = $pdo->query("SELECT * FROM predefined_messages ORDER BY position ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);

$activeTab = isset($_GET['tab']) ? $_GET['tab'] : 'general';

// Chargement de l'état des ventes
$salesOpen = getGlobalSetting($pdo, 'sales_open') == '1';
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configuration Système - Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .scrollable-table { max-height: 500px; overflow-y: auto; }
        .nav-tabs .nav-link { color: #495057; }
        .nav-tabs .nav-link.active { font-weight: bold; color: #dc3545; border-top: 3px solid #dc3545; }
        .table-action-btn { width: 32px; height: 32px; padding: 0; line-height: 32px; text-align: center; }
    </style>
</head>
<body class="bg-light">

<?php include 'navbar.php'; ?>

<div class="container mt-4 mb-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <a href="admin.php" class="text-decoration-none text-muted"><i class="fas fa-arrow-left"></i> Retour Hub</a>
            <h2 class="fw-bold mt-2">⚙️ Configuration Statique</h2>
        </div>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-header bg-white pt-3 pb-0">
            <ul class="nav nav-tabs card-header-tabs" id="configTabs">
                <li class="nav-item">
                    <a class="nav-link <?php echo $activeTab == 'general' ? 'active' : ''; ?>" 
                    href="?tab=general">
                    <i class="fas fa-cogs me-2"></i>Général
                    </a>
                </li>

                <li class="nav-item">
                    <a class="nav-link <?php echo $activeTab == 'roses' ? 'active' : ''; ?>" 
                    href="?tab=roses">
                    🌹 Roses & Prix
                    </a>
                </li>

                <li class="nav-item">
                    <a class="nav-link <?php echo $activeTab == 'classes' ? 'active' : ''; ?>" 
                    href="?tab=classes">
                    🎓 Classes & Niveaux
                    </a>
                </li>

                <li class="nav-item">
                    <a class="nav-link <?php echo $activeTab == 'rooms' ? 'active' : ''; ?>" 
                    href="?tab=rooms">
                    🏢 Salles & Bâtiments
                    </a>
                </li>

                <li class="nav-item">
                    <a class="nav-link <?php echo $activeTab == 'messages' ? 'active' : ''; ?>" 
                    href="?tab=messages">
                    💌 Messages
                    </a>
                </li>
            </ul>
        </div>
        
        <div class="card-body">
            <div class="tab-content">

                <div class="tab-pane fade <?php echo $activeTab == 'general' ? 'show active' : ''; ?>" id="tab-general">
                    <div class="row justify-content-center">
                        <div class="col-md-6">
                            <div class="card shadow-sm border-0">
                                <div class="card-header bg-dark text-white fw-bold">
                                    <i class="fas fa-store-slash me-2"></i>État de la boutique
                                </div>
                                <div class="card-body text-center py-5">
                                    
                                    <h5 class="mb-4">Autoriser les élèves à commander ?</h5>

                                    <form method="POST">
                                        <input type="hidden" name="action" value="toggle_sales">
                                        
                                        <div class="form-check form-switch d-flex justify-content-center mb-4 ps-0">
                                            <input class="form-check-input ms-0 me-3" type="checkbox" name="sales_status" value="1" 
                                                id="salesSwitch" style="transform: scale(1.5);" 
                                                <?php echo $salesOpen ? 'checked' : ''; ?> 
                                                onchange="this.form.submit()">
                                            
                                            <label class="form-check-label fw-bold lead" for="salesSwitch">
                                                <?php if($salesOpen): ?>
                                                    <span class="text-success"><i class="fas fa-check-circle"></i> Ventes Ouvertes</span>
                                                <?php else: ?>
                                                    <span class="text-danger"><i class="fas fa-ban"></i> Ventes Fermées</span>
                                                <?php endif; ?>
                                            </label>
                                        </div>
                                        
                                        <p class="text-muted small">
                                            Si vous désactivez cette option, la page de commande affichera un message indiquant que les ventes sont closes.
                                            L'interface administrateur reste accessible.
                                        </p>
                                    </form>

                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="tab-pane fade <?php echo $activeTab == 'roses' ? 'show active' : ''; ?>" id="tab-roses">
                    <div class="row">
                        
                        <div class="col-lg-6 mb-4">
                            <div class="card h-100 shadow-sm border-0">
                                <div class="card-header bg-danger text-white fw-bold">
                                    <i class="fas fa-flower"></i> Variétés de Roses
                                </div>
                                <div class="card-body">
                                    <table class="table table-sm align-middle table-hover mb-4">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Nom de la variété</th>
                                                <th class="text-end">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach($roses as $r): ?>
                                            <tr>
                                                <td class="fw-bold"><?php echo htmlspecialchars($r['name']); ?></td>
                                                <td class="text-end">
                                                    <button class="btn btn-sm btn-outline-primary me-1" 
                                                            onclick="editRose('<?php echo $r['id']; ?>', '<?php echo addslashes($r['name']); ?>')">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <a href="?delete=<?php echo $r['id']; ?>&type=rose" 
                                                    class="btn btn-sm btn-outline-danger" 
                                                    onclick="return confirm('Supprimer cette rose ?');">
                                                        <i class="fas fa-trash-alt"></i>
                                                    </a>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>

                                    <hr>
                                    <h6 class="fw-bold text-muted mb-2"><i class="fas fa-plus-circle me-1"></i>Ajouter une variété</h6>
                                    <form method="POST" class="row g-2">
                                        <input type="hidden" name="action" value="add_rose">
                                        <div class="col-8">
                                            <input type="text" name="name" class="form-control form-control-sm" placeholder="Nom (ex: Rose Rouge)" required>
                                        </div>
                                        <div class="col-4">
                                            <button type="submit" class="btn btn-sm btn-dark w-100">Ajouter</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-6 mb-4">
                            <div class="card h-100 shadow-sm border-0">
                                <div class="card-header bg-primary text-white fw-bold">
                                    <i class="fas fa-tags"></i> Grille Tarifaire
                                </div>
                                <div class="card-body">
                                    <table class="table table-sm align-middle table-striped mb-4">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Quantité</th>
                                                <th>Prix Total</th>
                                                <th class="text-end">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if(empty($rosesPrices)): ?>
                                                <tr><td colspan="3" class="text-center text-muted">Aucun tarif défini</td></tr>
                                            <?php endif; ?>

                                            <?php foreach($rosesPrices as $rp): ?>
                                            <tr>
                                                <td class="fw-bold"><?php echo $rp['quantity']; ?> rose(s)</td>
                                                <td class="text-primary fw-bold"><?php echo number_format($rp['price'], 2); ?> €</td>
                                                <td class="text-end">
                                                    <button class="btn btn-sm btn-outline-primary me-1" 
                                                            onclick="editPrice(<?php echo $rp['quantity']; ?>, <?php echo $rp['price']; ?>)">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <a href="?delete=<?php echo $rp['quantity']; ?>&type=price_rule" 
                                                    class="btn btn-sm btn-outline-danger" 
                                                    onclick="return confirm('Supprimer ce tarif ?');">
                                                        <i class="fas fa-trash-alt"></i>
                                                    </a>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>

                                    <hr>
                                    <h6 class="fw-bold text-muted mb-2"><i class="fas fa-plus-circle me-1"></i>Ajouter un tarif</h6>
                                    <form method="POST" class="row g-2 align-items-end">
                                        <input type="hidden" name="action" value="add_price_rule">
                                        <div class="col-5">
                                            <label class="small text-muted">Qté</label>
                                            <input type="number" name="quantity" class="form-control form-control-sm" placeholder="Ex: 10" required min="1">
                                        </div>
                                        <div class="col-4">
                                            <label class="small text-muted">Prix (€)</label>
                                            <input type="number" step="0.01" name="price" class="form-control form-control-sm" placeholder="Ex: 12.50" required min="0">
                                        </div>
                                        <div class="col-3">
                                            <button type="submit" class="btn btn-sm btn-success w-100">OK</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade <?php echo $activeTab == 'classes' ? 'show active' : ''; ?>" id="tab-classes">
                    <div class="row g-4">
                        <div class="col-md-7 border-end">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="fw-bold m-0">Liste des Classes</h6>
                                <button class="btn btn-sm btn-dark" data-bs-toggle="modal" data-bs-target="#addClassModal"><i class="fas fa-plus"></i></button>
                            </div>
                            <div class="scrollable-table border rounded">
                                <table class="table table-sm table-striped mb-0">
                                    <thead class="sticky-top table-light"><tr><th class="ps-3">Nom</th><th>Niveau</th><th class="text-end pe-3">Actions</th></tr></thead>
                                    <tbody>
                                        <?php foreach($classes as $c): ?>
                                        <tr>
                                            <td class="ps-3 fw-bold"><?php echo htmlspecialchars($c['name']); ?></td>
                                            <td><span class="badge bg-secondary"><?php echo htmlspecialchars($c['level_name']); ?></span></td>
                                            <td class="text-end">
                                                <button class="btn btn-sm btn-outline-primary me-1" 
                                                        onclick="editClass(<?php echo $c['id']; ?>, '<?php echo addslashes($c['name']); ?>', <?php echo $c['level_id']; ?>)">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <a href="?delete=<?php echo $c['id']; ?>&type=class" 
                                                class="btn btn-sm btn-outline-danger" 
                                                onclick="return confirm('Supprimer ?');">
                                                    <i class="fas fa-trash-alt"></i>
                                                </a>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div class="col-md-5">
                            <h6 class="fw-bold mb-3 text-primary">Gestion des Niveaux</h6>
                            <ul class="list-group mb-3">
                                <?php foreach($levels as $l): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <span><?php echo htmlspecialchars($l['name']); ?></span>
                                    <div>
                                        <button class="btn btn-sm btn-outline-primary me-1" 
                                                onclick="editLevel(<?php echo $l['id']; ?>, '<?php echo addslashes($l['name']); ?>')">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <a href="?delete=<?php echo $l['id']; ?>&type=level" 
                                        class="btn btn-sm btn-outline-danger" 
                                        onclick="return confirm('Supprimer ce niveau ?');">
                                            <i class="fas fa-trash-alt"></i>
                                        </a>
                                    </div>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                            <form method="POST" class="d-flex gap-2">
                                <input type="hidden" name="action" value="add_level">
                                <input type="text" name="name" class="form-control form-control-sm" placeholder="Nouveau niveau..." required>
                                <button type="submit" class="btn btn-sm btn-primary">Ajouter</button>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade <?php echo $activeTab == 'rooms' ? 'show active' : ''; ?>" id="tab-rooms">
                    <div class="row g-4">
                        <div class="col-md-7 border-end">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="fw-bold m-0">Liste des Salles</h6>
                                <button class="btn btn-sm btn-dark" data-bs-toggle="modal" data-bs-target="#addRoomModal"><i class="fas fa-plus"></i></button>
                            </div>
                            <div class="scrollable-table border rounded">
                                <table class="table table-sm table-striped mb-0">
                                    <thead class="sticky-top table-light"><tr><th class="ps-3">Nom</th><th>Lieu</th><th class="text-end pe-3">Actions</th></tr></thead>
                                    <tbody>
                                        <?php foreach($rooms as $r): ?>
                                        <tr>
                                            <td class="ps-3 fw-bold"><?php echo htmlspecialchars($r['name']); ?></td>
                                            <td class="small text-muted"><?php echo htmlspecialchars($r['bat_name'] . ' - ' . $r['floor_name']); ?></td>
                                            <td class="text-end">
                                                <button class="btn btn-sm btn-outline-primary me-1" 
                                                        onclick="editRoom(<?php echo $r['id']; ?>, '<?php echo addslashes($r['name']); ?>', <?php echo $r['building_id']; ?>, <?php echo $r['floor_id']; ?>)">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <a href="?delete=<?php echo $r['id']; ?>&type=room" 
                                                class="btn btn-sm btn-outline-danger" 
                                                onclick="return confirm('Supprimer ?');">
                                                    <i class="fas fa-trash-alt"></i>
                                                </a>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        
                        <div class="col-md-5">
                            <div class="mb-4">
                                <h6 class="fw-bold text-primary">Bâtiments</h6>
                                <ul class="list-group mb-2">
                                    <?php foreach($buildings as $b): ?>
                                    <li class="list-group-item d-flex justify-content-between align-items-center py-2">
                                        <?php echo htmlspecialchars($b['name']); ?>
                                        <div>
                                            <button class="btn btn-sm btn-outline-primary me-1" 
                                                    onclick="editBuilding(<?php echo $b['id']; ?>, '<?php echo addslashes($b['name']); ?>')">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <a href="?delete=<?php echo $b['id']; ?>&type=building" 
                                            class="btn btn-sm btn-outline-danger" 
                                            onclick="return confirm('Supprimer ?')">
                                                <i class="fas fa-trash-alt"></i>
                                            </a>
                                        </div>
                                    </li>
                                    <?php endforeach; ?>
                                </ul>
                                <form method="POST" class="input-group input-group-sm">
                                    <input type="hidden" name="action" value="add_building">
                                    <input type="text" name="name" class="form-control" placeholder="Nouveau Bâtiment" required>
                                    <button class="btn btn-outline-primary" type="submit">Ajouter</button>
                                </form>
                            </div>

                            <div>
                                <h6 class="fw-bold text-primary">Étages</h6>
                                <ul class="list-group mb-2">
                                    <?php foreach($floors as $f): ?>
                                    <li class="list-group-item d-flex justify-content-between align-items-center py-2">
                                        <?php echo htmlspecialchars($f['name']); ?>
                                        <div>
                                            <button class="btn btn-sm btn-outline-primary me-1" 
                                                    onclick="editFloor(<?php echo $f['id']; ?>, '<?php echo addslashes($f['name']); ?>')">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <a href="?delete=<?php echo $f['id']; ?>&type=floor" 
                                            class="btn btn-sm btn-outline-danger" 
                                            onclick="return confirm('Supprimer ?')">
                                                <i class="fas fa-trash-alt"></i>
                                            </a>
                                        </div>
                                    </li>
                                    <?php endforeach; ?>
                                </ul>
                                <form method="POST" class="d-flex gap-1">
                                    <input type="hidden" name="action" value="add_floor">
                                    <input type="text" name="name" class="form-control form-control-sm" placeholder="Nom (ex: RDC)" required>
                                    <input type="number" name="level_number" class="form-control form-control-sm" placeholder="N° (0)" style="width:60px" required>
                                    <button class="btn btn-sm btn-outline-primary" type="submit">OK</button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade <?php echo $activeTab == 'messages' ? 'show active' : ''; ?>" id="tab-messages">
                    <div class="row">
                        <div class="col-md-12">
                            <h5 class="fw-bold mb-3">Messages Prédéfinis</h5>
                            <p class="text-muted small"><i class="fas fa-info-circle me-1"></i>Faites glisser les lignes pour changer l'ordre d'affichage.</p>
                            
                            <div class="table-responsive">
                                <table class="table align-middle table-hover border">
                                    <thead class="table-light">
                                        <tr>
                                            <th style="width: 50px;"></th> 
                                            <th>Message</th>
                                            <th class="text-end" style="width: 100px;">Actions</th>
                                        </tr>
                                    </thead>
                                    
                                    <tbody id="sortable-messages">
                                        <?php foreach($messages as $msg): ?>
                                        <tr data-id="<?php echo $msg['id']; ?>" style="cursor: move;">
                                            <td class="text-center text-muted grab-handle">
                                                <i class="fas fa-grip-vertical"></i>
                                            </td>
                                            <td>
                                                <?php echo htmlspecialchars($msg['content']); ?>
                                            </td>
                                            <td class="text-end">
                                                <a href="?delete=<?php echo $msg['id']; ?>&type=message" 
                                                class="btn btn-sm btn-outline-danger" 
                                                onclick="return confirm('Supprimer ce message ?');">
                                                    <i class="fas fa-trash-alt"></i>
                                                </a>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <hr>
                            <h6 class="fw-bold mt-4">Ajouter un nouveau message</h6>
                            <form method="POST" class="d-flex gap-2">
                                <input type="hidden" name="action" value="add_message">
                                <input type="text" name="content" class="form-control" placeholder="Votre message ici..." required>
                                <button type="submit" class="btn btn-dark">Ajouter</button>
                            </form>

                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="editRoseModal" tabindex="-1">
    <div class="modal-dialog">
        <form class="modal-content" method="POST">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">Modifier la variété</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="action" value="update_rose">
                <input type="hidden" name="id" id="edit_rose_id">
                
                <div class="mb-3">
                    <label class="form-label">Nom de la variété</label>
                    <input type="text" name="name" id="edit_rose_name" class="form-control" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                <button type="submit" class="btn btn-danger">Enregistrer</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="editPriceModal" tabindex="-1">
    <div class="modal-dialog">
        <form class="modal-content" method="POST">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">Modifier le tarif</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="action" value="update_price_rule">
                <input type="hidden" name="original_quantity" id="edit_price_original_qty">
                
                <div class="mb-3">
                    <label class="form-label">Quantité (Nombre de roses)</label>
                    <input type="number" name="quantity" id="edit_price_qty" class="form-control" required min="1">
                    <div class="form-text text-muted small">Attention : ne mettez pas une quantité qui existe déjà ailleurs.</div>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Prix Total (€)</label>
                    <input type="number" step="0.01" name="price" id="edit_price_val" class="form-control" required min="0">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                <button type="submit" class="btn btn-primary">Mettre à jour</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="addClassModal" tabindex="-1">
    <div class="modal-dialog">
        <form class="modal-content" method="POST">
            <div class="modal-header"><h5 class="modal-title" id="classModalTitle">Ajouter une classe</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <input type="hidden" name="action" value="add_class" id="class_action">
                <input type="hidden" name="id" id="class_id">
                <div class="mb-3"><label>Nom</label><input type="text" name="name" id="class_name" class="form-control" required></div>
                <div class="mb-3"><label>Niveau</label>
                    <select name="level_id" id="class_level" class="form-select" required>
                        <?php foreach($levels as $l): echo "<option value='{$l['id']}'>{$l['name']}</option>"; endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="modal-footer"><button type="submit" class="btn btn-primary">Enregistrer</button></div>
        </form>
    </div>
</div>

<div class="modal fade" id="addRoomModal" tabindex="-1">
    <div class="modal-dialog">
        <form class="modal-content" method="POST">
            <div class="modal-header"><h5 class="modal-title" id="roomModalTitle">Ajouter une salle</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <input type="hidden" name="action" value="add_room" id="room_action">
                <input type="hidden" name="id" id="room_id">
                <div class="mb-3"><label>Nom</label><input type="text" name="name" id="room_name" class="form-control" required></div>
                <div class="row">
                    <div class="col-6 mb-3"><label>Bâtiment</label>
                        <select name="building_id" id="room_building" class="form-select" required>
                            <?php foreach($buildings as $b): echo "<option value='{$b['id']}'>{$b['name']}</option>"; endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6 mb-3"><label>Étage</label>
                        <select name="floor_id" id="room_floor" class="form-select" required>
                            <?php foreach($floors as $f): echo "<option value='{$f['id']}'>{$f['name']}</option>"; endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer"><button type="submit" class="btn btn-primary">Enregistrer</button></div>
        </form>
    </div>
</div>

<div class="modal fade" id="simpleEditModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <form class="modal-content" method="POST">
            <div class="modal-header"><h5 class="modal-title">Renommer</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <input type="hidden" name="action" id="simple_action">
                <input type="hidden" name="id" id="simple_id">
                <div class="mb-3"><input type="text" name="name" id="simple_name" class="form-control" required></div>
            </div>
            <div class="modal-footer"><button type="submit" class="btn btn-primary btn-sm w-100">Valider</button></div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.15.0/Sortable.min.js"></script>
<?php include 'toast_notifications.php'; ?>

<script>
document.addEventListener("DOMContentLoaded", function() {
    
    // ==========================================
    // 1. DRAG & DROP (MESSAGES)
    // ==========================================
    
    // Vérification que la librairie est bien là
    if (typeof Sortable === 'undefined') {
        console.warn("Info : La librairie SortableJS n'est pas chargée (normal si pas sur l'onglet messages).");
    } else {
        var el = document.getElementById('sortable-messages');
        if (el) {
            Sortable.create(el, {
                animation: 150,
                handle: '.grab-handle',
                ghostClass: 'bg-light',
                
                onEnd: function (evt) {
                    var order = [];
                    var rows = el.querySelectorAll('tr');
                    rows.forEach(function(row) {
                        order.push(row.getAttribute('data-id'));
                    });

                    var formData = new FormData();
                    formData.append('action', 'reorder_messages');
                    formData.append('order', JSON.stringify(order));

                    fetch(window.location.href, { 
                        method: 'POST',
                        body: formData
                    })
                    .then(function(response) {
                        if (!response.ok) throw new Error("Erreur serveur ou réseau");
                        return response.json();
                    })
                    .then(function(data) {
                        if (data.status === 'success') {
                            console.log("Ordre sauvegardé.");
                            evt.item.style.backgroundColor = "#d4edda";
                            setTimeout(() => { evt.item.style.backgroundColor = ""; }, 1000);
                        } else {
                            alert("Erreur : " + (data.message || "Inconnue"));
                        }
                    })
                    .catch(function(error) {
                        console.error('Erreur:', error);
                    });
                }
            });
        }
    }

    // ==========================================
    // 2. GESTION DES MODALES (ROSES & PRIX)
    // ==========================================

    // Modifier une ROSE (Version Corrigée : Nom uniquement)
    window.editRose = function(id, name) {
        // On cible les IDs de la nouvelle modale HTML
        var idInput = document.getElementById('edit_rose_id');
        var nameInput = document.getElementById('edit_rose_name');
        
        if (idInput && nameInput) {
            idInput.value = id;
            nameInput.value = name;
            new bootstrap.Modal(document.getElementById('editRoseModal')).show();
        } else {
            console.error("Erreur : Impossible de trouver les champs de la modale Rose (edit_rose_id/name).");
        }
    };

    // Modifier un PRIX (Nouvelle fonction)
    window.editPrice = function(qty, price) {
        var qtyOrigInput = document.getElementById('edit_price_original_qty');
        var qtyInput = document.getElementById('edit_price_qty');
        var priceInput = document.getElementById('edit_price_val');

        if (qtyOrigInput && qtyInput && priceInput) {
            qtyOrigInput.value = qty;
            qtyInput.value = qty;
            priceInput.value = price;
            new bootstrap.Modal(document.getElementById('editPriceModal')).show();
        } else {
            console.error("Erreur : Impossible de trouver les champs de la modale Prix.");
        }
    };


    // ==========================================
    // 3. GESTION DES AUTRES MODALES (CLASSES, SALLES...)
    // ==========================================

    // Classes
    window.editClass = function(id, name, levelId) {
        document.getElementById('classModalTitle').innerText = 'Modifier la classe';
        document.getElementById('class_action').value = 'update_class';
        document.getElementById('class_id').value = id;
        document.getElementById('class_name').value = name;
        document.getElementById('class_level').value = levelId;
        new bootstrap.Modal(document.getElementById('addClassModal')).show();
    };
    
    var classModal = document.getElementById('addClassModal');
    if(classModal) {
        classModal.addEventListener('hidden.bs.modal', function () {
            document.getElementById('classModalTitle').innerText = 'Ajouter une classe';
            document.getElementById('class_action').value = 'add_class';
            document.getElementById('class_id').value = '';
            document.getElementById('class_name').value = '';
        });
    }

    // Salles
    window.editRoom = function(id, name, buildId, floorId) {
        document.getElementById('roomModalTitle').innerText = 'Modifier la salle';
        document.getElementById('room_action').value = 'update_room';
        document.getElementById('room_id').value = id;
        document.getElementById('room_name').value = name;
        document.getElementById('room_building').value = buildId;
        document.getElementById('room_floor').value = floorId;
        new bootstrap.Modal(document.getElementById('addRoomModal')).show();
    };
    
    var roomModal = document.getElementById('addRoomModal');
    if(roomModal) {
        roomModal.addEventListener('hidden.bs.modal', function () {
            document.getElementById('roomModalTitle').innerText = 'Ajouter une salle';
            document.getElementById('room_action').value = 'add_room';
            document.getElementById('room_id').value = '';
            document.getElementById('room_name').value = '';
        });
    }

    // Éditions Simples (Niveaux, Bâtiments, Étages)
    window.openSimpleEdit = function(action, id, name) {
        document.getElementById('simple_action').value = action;
        document.getElementById('simple_id').value = id;
        document.getElementById('simple_name').value = name;
        new bootstrap.Modal(document.getElementById('simpleEditModal')).show();
    };

    window.editLevel = function(id, name) { window.openSimpleEdit('update_level', id, name); };
    window.editBuilding = function(id, name) { window.openSimpleEdit('update_building', id, name); };
    window.editFloor = function(id, name) { window.openSimpleEdit('update_floor', id, name); };

});
</script>

<style>
    /* Empêche la sélection de texte sur l'icône de drag */
    .grab-handle {
        cursor: grab;
        user-select: none; /* Crucial pour éviter de sélectionner le texte */
    }
    .grab-handle:active {
        cursor: grabbing;
    }
</style>

</body>
</html>