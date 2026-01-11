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

// --- TRAITEMENT AJAX (Drag & Drop) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reorder_messages') {
    
    // 1. On coupe toutes les erreurs PHP qui pourraient polluer la réponse
    error_reporting(0); 
    ini_set('display_errors', 0);

    // 2. On nettoie le tampon de sortie (buffer)
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
            // La réponse propre
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Données invalides']);
        }

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => 'Erreur SQL']);
    }

    // 3. On tue le script IMMÉDIATEMENT après avoir envoyé le JSON
    exit; 
}

// --- TRAITEMENT DES ACTIONS (POST/GET) ---

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        // --- 1. GESTION DES ROSES ---
        if ($action === 'add_rose') {
            $stmt = $pdo->prepare("INSERT INTO rose_products (name, price, is_active) VALUES (?, ?, 1)");
            $stmt->execute([$_POST['name'], $_POST['price']]);
            setToast('success', 'Nouvelle rose ajoutée.');
            redirect('roses');
        }
        elseif ($action === 'update_rose') {
            $active = isset($_POST['is_active']) ? 1 : 0;
            $stmt = $pdo->prepare("UPDATE rose_products SET name = ?, price = ?, is_active = ? WHERE id = ?");
            $stmt->execute([$_POST['name'], $_POST['price'], $active, $_POST['id']]);
            setToast('success', 'Rose modifiée avec succès.');
            redirect('roses');
        }

        // --- 2. GESTION DES CLASSES & NIVEAUX ---
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

        // --- 3. GESTION DES SALLES, BÂTIMENTS, ÉTAGES ---
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

        // --- 4. GESTION DES MESSAGES ---
        elseif ($action === 'add_message') {
            $stmt = $pdo->prepare("INSERT INTO predefined_messages (content) VALUES (?)");
            $stmt->execute([$_POST['content']]);
            setToast('success', 'Message ajouté.');
            redirect('messages');
        }
        elseif ($action === 'update_message_id') {
            // Changement de l'ID (Risqué mais demandé)
            $oldId = $_POST['old_id'];
            $newId = $_POST['new_id'];
            if ($oldId != $newId) {
                // On vérifie si l'ID est libre
                $check = $pdo->prepare("SELECT id FROM predefined_messages WHERE id = ?");
                $check->execute([$newId]);
                if ($check->rowCount() > 0) {
                    setToast('danger', 'Erreur : Cet ID est déjà pris par un autre message.');
                } else {
                    $stmt = $pdo->prepare("UPDATE predefined_messages SET id = ? WHERE id = ?");
                    $stmt->execute([$newId, $oldId]);
                    setToast('success', 'Ordre du message modifié (ID changé).');
                }
            }
            redirect('messages');
        }
        // --- 5. GESTION DU RÉAGENCEMENT (AJAX) ---
        elseif ($action === 'reorder_messages') {
            // On reçoit une liste d'IDs dans le nouvel ordre
            $order = json_decode($_POST['order'], true);
            
            if (is_array($order)) {
                try {
                    $pdo->beginTransaction();
                    foreach ($order as $position => $id) {
                        // On met à jour la position (0, 1, 2...)
                        $stmt = $pdo->prepare("UPDATE predefined_messages SET position = ? WHERE id = ?");
                        $stmt->execute([$position, $id]);
                    }
                    $pdo->commit();
                    echo json_encode(['status' => 'success']);
                } catch (Exception $e) {
                    $pdo->rollBack();
                    http_response_code(500);
                    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
                }
            }
            exit; // Important : On arrête le script ici car c'est une requête AJAX
        }

    } catch (PDOException $e) {
        setToast('danger', 'Erreur SQL : ' . $e->getMessage());
        // On reste sur la page pour voir l'erreur
    }
}

// --- SUPPRESSIONS (GET) ---
if (isset($_GET['delete'])) {
    $type = $_GET['type'];
    $id = intval($_GET['delete']);
    $tab = 'roses'; // default

    try {
        if ($type === 'rose') {
            $pdo->prepare("DELETE FROM rose_products WHERE id = ?")->execute([$id]);
            setToast('warning', 'Rose supprimée.');
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
        setToast('danger', 'Impossible de supprimer : Donnée liée à des commandes ou des élèves existants.');
    }
    redirect($tab);
}

// --- RECUPERATION DES DONNEES ---

$roses = $pdo->query("SELECT * FROM rose_products ORDER BY price ASC")->fetchAll(PDO::FETCH_ASSOC);

$classes = $pdo->query("SELECT c.*, cl.name as level_name FROM classes c LEFT JOIN class_levels cl ON c.level_id = cl.id ORDER BY cl.id ASC, c.name ASC")->fetchAll(PDO::FETCH_ASSOC);
$levels = $pdo->query("SELECT * FROM class_levels ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

$rooms = $pdo->query("SELECT r.*, b.name as bat_name, f.name as floor_name FROM rooms r LEFT JOIN buildings b ON r.building_id = b.id LEFT JOIN floors f ON r.floor_id = f.id ORDER BY b.name ASC, f.level_number ASC, r.name ASC")->fetchAll(PDO::FETCH_ASSOC);
$buildings = $pdo->query("SELECT * FROM buildings ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$floors = $pdo->query("SELECT * FROM floors ORDER BY level_number ASC")->fetchAll(PDO::FETCH_ASSOC);

$messages = $pdo->query("SELECT * FROM predefined_messages ORDER BY position ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);

$activeTab = isset($_GET['tab']) ? $_GET['tab'] : 'roses';
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
                <li class="nav-item"><a class="nav-link <?php echo $activeTab == 'roses' ? 'active' : ''; ?>" href="#tab-roses" data-bs-toggle="tab">🌹 Roses & Prix</a></li>
                <li class="nav-item"><a class="nav-link <?php echo $activeTab == 'classes' ? 'active' : ''; ?>" href="#tab-classes" data-bs-toggle="tab">🎓 Classes & Niveaux</a></li>
                <li class="nav-item"><a class="nav-link <?php echo $activeTab == 'rooms' ? 'active' : ''; ?>" href="#tab-rooms" data-bs-toggle="tab">🏢 Salles & Bâtiments</a></li>
                <li class="nav-item"><a class="nav-link <?php echo $activeTab == 'messages' ? 'active' : ''; ?>" href="#tab-messages" data-bs-toggle="tab">💌 Messages</a></li>
            </ul>
        </div>
        
        <div class="card-body">
            <div class="tab-content">
                
                <div class="tab-pane fade <?php echo $activeTab == 'roses' ? 'show active' : ''; ?>" id="tab-roses">
                    <div class="row">
                        <div class="col-md-8">
                            <h5 class="fw-bold mb-3">Catalogue</h5>
                            <table class="table align-middle table-hover">
                                <thead class="table-light"><tr><th>Nom</th><th>Prix</th><th>Actif</th><th class="text-end">Actions</th></tr></thead>
                                <tbody>
                                    <?php foreach($roses as $r): ?>
                                    <tr>
                                        <td class="fw-bold"><?php echo htmlspecialchars($r['name']); ?></td>
                                        <td><?php echo number_format($r['price'], 2); ?> €</td>
                                        <td>
                                            <?php if($r['is_active']): ?>
                                                <span class="badge bg-success">Oui</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Non</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <button class="btn btn-sm btn-outline-primary table-action-btn me-1" 
                                                    onclick="editRose(<?php echo htmlspecialchars(json_encode($r)); ?>)">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <a href="?delete=<?php echo $r['id']; ?>&type=rose" class="btn btn-sm btn-outline-danger table-action-btn" onclick="return confirm('Supprimer cette rose ?');">
                                                <i class="fas fa-trash-alt"></i>
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="col-md-4">
                            <div class="card bg-light border-0">
                                <div class="card-body">
                                    <h6 class="fw-bold"><i class="fas fa-plus-circle me-2"></i>Ajouter une rose</h6>
                                    <form method="POST">
                                        <input type="hidden" name="action" value="add_rose">
                                        <div class="mb-2"><input type="text" name="name" class="form-control" placeholder="Nom (ex: Rose Bleue)" required></div>
                                        <div class="mb-3"><input type="number" step="0.01" name="price" class="form-control" placeholder="Prix (ex: 1.50)" required></div>
                                        <button type="submit" class="btn btn-dark w-100">Ajouter</button>
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
                                            <td class="text-end pe-3">
                                                <button class="btn btn-sm text-primary p-0 me-2" onclick="editClass(<?php echo $c['id']; ?>, '<?php echo addslashes($c['name']); ?>', <?php echo $c['level_id']; ?>)">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <a href="?delete=<?php echo $c['id']; ?>&type=class" class="text-danger" onclick="return confirm('Supprimer ?');"><i class="fas fa-trash-alt"></i></a>
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
                                        <button class="btn btn-sm btn-link text-decoration-none p-0 me-2" 
                                                onclick="editLevel(<?php echo $l['id']; ?>, '<?php echo addslashes($l['name']); ?>')">Edit</button>
                                        <a href="?delete=<?php echo $l['id']; ?>&type=level" class="text-danger small" onclick="return confirm('Supprimer ce niveau ?');"><i class="fas fa-times"></i></a>
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
                                            <td class="text-end pe-3">
                                                <button class="btn btn-sm text-primary p-0 me-2" 
                                                        onclick="editRoom(<?php echo $r['id']; ?>, '<?php echo addslashes($r['name']); ?>', <?php echo $r['building_id']; ?>, <?php echo $r['floor_id']; ?>)">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <a href="?delete=<?php echo $r['id']; ?>&type=room" class="text-danger" onclick="return confirm('Supprimer ?');"><i class="fas fa-trash-alt"></i></a>
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
                                    <li class="list-group-item d-flex justify-content-between py-1">
                                        <?php echo htmlspecialchars($b['name']); ?>
                                        <div>
                                            <a href="#" onclick="editBuilding(<?php echo $b['id']; ?>, '<?php echo addslashes($b['name']); ?>')" class="text-primary me-2"><i class="fas fa-edit"></i></a>
                                            <a href="?delete=<?php echo $b['id']; ?>&type=building" class="text-danger" onclick="return confirm('Supprimer ?')"><i class="fas fa-times"></i></a>
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
                                    <li class="list-group-item d-flex justify-content-between py-1">
                                        <?php echo htmlspecialchars($f['name']); ?>
                                        <div>
                                            <a href="#" onclick="editFloor(<?php echo $f['id']; ?>, '<?php echo addslashes($f['name']); ?>')" class="text-primary me-2"><i class="fas fa-edit"></i></a>
                                            <a href="?delete=<?php echo $f['id']; ?>&type=floor" class="text-danger" onclick="return confirm('Supprimer ?')"><i class="fas fa-times"></i></a>
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
                                                class="btn btn-sm btn-outline-danger border-0" 
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
            <div class="modal-header"><h5 class="modal-title">Modifier la Rose</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <input type="hidden" name="action" value="update_rose">
                <input type="hidden" name="id" id="rose_id">
                <div class="mb-3"><label>Nom</label><input type="text" name="name" id="rose_name" class="form-control" required></div>
                <div class="mb-3"><label>Prix</label><input type="number" step="0.01" name="price" id="rose_price" class="form-control" required></div>
                <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="is_active" id="rose_active"><label class="form-check-label">En vente</label></div>
            </div>
            <div class="modal-footer"><button type="submit" class="btn btn-primary">Enregistrer</button></div>
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
    
    // --- Vérification que la librairie est bien là ---
    if (typeof Sortable === 'undefined') {
        alert("Erreur : La librairie SortableJS ne s'est pas chargée. Vérifiez votre connexion internet.");
        return;
    }

    // --- Initialisation du Drag & Drop ---
    var el = document.getElementById('sortable-messages');
    if (el) {
        Sortable.create(el, {
            animation: 150,
            handle: '.grab-handle', // On attrape par la poignée
            ghostClass: 'bg-light', // Couleur de l'élément fantôme
            
            onEnd: function (evt) {
                // Récupération de l'ordre
                var order = [];
                var rows = el.querySelectorAll('tr');
                rows.forEach(function(row) {
                    order.push(row.getAttribute('data-id'));
                });

                // Envoi AJAX
                var formData = new FormData();
                formData.append('action', 'reorder_messages');
                formData.append('order', JSON.stringify(order));

                // On utilise 'window.location.href' pour être sûr de rester en HTTPS
                fetch(window.location.href, { 
                    method: 'POST',
                    body: formData
                })
                .then(function(response) {
                    // On vérifie si la réponse est OK
                    if (!response.ok) {
                        throw new Error("Erreur serveur ou réseau");
                    }
                    return response.json();
                })
                .then(function(data) {
                    if (data.status === 'success') {
                        console.log("Ordre sauvegardé avec succès.");
                        // Optionnel : un petit effet visuel vert
                        evt.item.style.backgroundColor = "#d4edda";
                        setTimeout(() => { evt.item.style.backgroundColor = ""; }, 1000);
                    } else {
                        alert("Erreur de sauvegarde : " + (data.message || "Inconnue"));
                    }
                })
                .catch(function(error) {
                    console.error('Erreur:', error);
                    alert("Impossible de sauvegarder l'ordre (Problème de connexion ou HTTPS).");
                });
            }
        });
    }

    // --- JS POUR LES MODALES (Ton code existant) ---

    // 1. Roses
    window.editRose = function(rose) { // 'window.' rend la fonction accessible globalement
        document.getElementById('rose_id').value = rose.id;
        document.getElementById('rose_name').value = rose.name;
        document.getElementById('rose_price').value = rose.price;
        document.getElementById('rose_active').checked = rose.is_active == 1;
        new bootstrap.Modal(document.getElementById('editRoseModal')).show();
    };

    // 2. Classes
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

    // 3. Salles
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

    // 4. Éditions Simples
    window.openSimpleEdit = function(action, id, name) {
        document.getElementById('simple_action').value = action;
        document.getElementById('simple_id').value = id;
        document.getElementById('simple_name').value = name;
        new bootstrap.Modal(document.getElementById('simpleEditModal')).show();
    };

    window.editLevel = function(id, name) { openSimpleEdit('update_level', id, name); };
    window.editBuilding = function(id, name) { openSimpleEdit('update_building', id, name); };
    window.editFloor = function(id, name) { openSimpleEdit('update_floor', id, name); };

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