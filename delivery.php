<?php
// delivery.php
require_once 'db.php';
require_once 'auth_check.php'; 
checkAccess('cvl');

// ==============================================================================
// 1. TRAITEMENT AJAX (Si une requête AJAX arrive, on traite et on quitte)
// ==============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => 'Erreur inconnue'];
    
    $id = 0;
    if (isset($_POST['gift_id'])) $id = intval($_POST['gift_id']); 
    elseif (isset($_POST['recipient_id'])) $id = intval($_POST['recipient_id']); 

    if ($id > 0) {
        $adminId = $_SESSION['user_id'] ?? ($_SESSION['id'] ?? null);

        try {
            if (isset($_POST['action']) && $_POST['action'] === 'mark_distributed') {
                $stmt = $pdo->prepare("UPDATE order_recipients SET is_distributed = 1, distributed_at = NOW(), distributed_by_cvl_id = ? WHERE id = ?");
                $stmt->execute([$adminId, $id]);
                
                $response = [
                    'success' => true, 
                    'action' => 'marked',
                    'message' => 'Livraison confirmée !'
                ];
            }
            elseif (isset($_POST['unmark_distributed'])) {
                $stmt = $pdo->prepare("UPDATE order_recipients SET is_distributed = 0, distributed_at = NULL, distributed_by_cvl_id = NULL WHERE id = ?");
                $stmt->execute([$id]);
                
                $response = [
                    'success' => true, 
                    'action' => 'unmarked',
                    'message' => 'Livraison annulée.'
                ];
            }
        } catch (Exception $e) {
            $response['message'] = 'Erreur SQL : ' . $e->getMessage();
        }
    } else {
        $response['message'] = 'ID invalide.';
    }

    echo json_encode($response);
    exit; // IMPORTANT : Arrêt immédiat pour ne pas renvoyer de HTML
}

// ==============================================================================
// 2. TRAITEMENT POST STANDARD (Fallback si JS désactivé)
// ==============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['ajax'])) {
    // Redirection classique vers delivery.php avec paramètres GET pour toast_notifications.php
    $id = intval($_POST['gift_id'] ?? 0);
    if ($id > 0) {
        $actionType = '';
        $adminId = $_SESSION['user_id'] ?? ($_SESSION['id'] ?? null);

        if ((isset($_POST['action']) && $_POST['action'] === 'mark_distributed')) {
            $pdo->prepare("UPDATE order_recipients SET is_distributed = 1, distributed_at = NOW(), distributed_by_cvl_id = ? WHERE id = ?")->execute([$adminId, $id]);
            $actionType = 'marked';
        }
        elseif (isset($_POST['unmark_distributed'])) {
            $pdo->prepare("UPDATE order_recipients SET is_distributed = 0, distributed_at = NULL, distributed_by_cvl_id = NULL WHERE id = ?")->execute([$id]);
            $actionType = 'unmarked';
        }

        if ($actionType) {
            // Ces paramètres seront lus par toast_notifications.php
            header("Location: delivery.php?last_action=$actionType&last_ids=$id" . 
                   (isset($_GET['view']) ? "&view=".$_GET['view'] : "") . 
                   (isset($_GET['building']) ? "&building=".$_GET['building'] : "") . 
                   (isset($_GET['hour']) ? "&hour=".$_GET['hour'] : ""));
            exit;
        }
    }
}

// ==============================================================================
// 3. PRÉPARATION DES DONNÉES (Affichage)
// ==============================================================================

// --- Filtres ---
$buildings = $pdo->query("SELECT id, name FROM buildings ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$currentHour = intval(date('H'));
if ($currentHour < 8 || $currentHour > 17) $currentHour = 8;
$selectedHour = isset($_GET['hour']) ? intval($_GET['hour']) : $currentHour;
if ($selectedHour < 8 || $selectedHour > 17) $selectedHour = 8;

$hourColumn = 'h' . str_pad($selectedHour, 2, '0', STR_PAD_LEFT);

$defaultBuildingId = count($buildings) > 0 ? $buildings[0]['id'] : 0;
$selectedBuilding = isset($_GET['building']) ? $_GET['building'] : $defaultBuildingId;

$searchQuery = isset($_GET['q']) ? trim($_GET['q']) : '';
$view = isset($_GET['view']) ? $_GET['view'] : 'todo';

// --- Requête SQL ---
$params = [];
$sql = "";

if ($searchQuery) {
    // Mode Recherche
    $sql = "
        SELECT 
            ort.id as unique_gift_id, ort.is_anonymous, ort.message_id, ort.distributed_at,
            r.nom as dest_nom, r.prenom as dest_prenom,
            c.name as class_name,
            pm.content as message_content,
            'Recherche' as room_name, 'Résultats' as floor_name, '' as building_name,
            u.prenom as buyer_prenom, u.nom as buyer_nom
        FROM order_recipients ort
        JOIN recipients r ON ort.recipient_id = r.id
        JOIN orders o ON ort.order_id = o.id
        JOIN users u ON o.user_id = u.user_id 
        LEFT JOIN classes c ON r.class_id = c.id
        LEFT JOIN predefined_messages pm ON ort.message_id = pm.id
        WHERE o.is_paid = 1 
        AND (
            r.nom LIKE :q OR r.prenom LIKE :q OR CONCAT(r.prenom, ' ', r.nom) LIKE :q OR
            u.nom LIKE :q OR u.prenom LIKE :q
        )
    ";
    
    if ($view === 'done') {
        $sql .= " AND ort.is_distributed = 1 ORDER BY ort.distributed_at DESC ";
    } else {
        $sql .= " AND ort.is_distributed = 0 ";
    }
    $sql .= " LIMIT 50";
    $params['q'] = "%$searchQuery%";

} else {
    // Mode Par Salle/Heure
    $sql = "
        SELECT 
            ort.id as unique_gift_id, ort.is_anonymous, ort.message_id, ort.distributed_at,
            r.nom as dest_nom, r.prenom as dest_prenom,
            c.name as class_name,
            pm.content as message_content,
            rm.name as room_name, rm.id as room_id,
            f.name as floor_name, f.level_number,
            b.name as building_name,
            u.prenom as buyer_prenom, u.nom as buyer_nom
        FROM order_recipients ort
        JOIN recipients r ON ort.recipient_id = r.id
        JOIN orders o ON ort.order_id = o.id
        JOIN users u ON o.user_id = u.user_id 
        LEFT JOIN classes c ON r.class_id = c.id
        LEFT JOIN predefined_messages pm ON ort.message_id = pm.id
        JOIN schedules s ON r.id = s.recipient_id
        JOIN rooms rm ON s.$hourColumn = rm.id
        JOIN floors f ON rm.floor_id = f.id
        JOIN buildings b ON rm.building_id = b.id
        WHERE o.is_paid = 1 
        AND s.$hourColumn IS NOT NULL AND s.$hourColumn != ''
    ";

    if ($selectedBuilding !== 'all') {
        $sql .= " AND rm.building_id = :building ";
        $params['building'] = $selectedBuilding;
    }

    if ($view === 'done') {
        $sql .= " AND ort.is_distributed = 1 ORDER BY ort.distributed_at DESC ";
    } else {
        $sql .= " AND ort.is_distributed = 0 ORDER BY b.name ASC, f.level_number ASC, rm.name ASC, r.nom ASC ";
    }
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- Chargement des Roses ---
$giftIds = array_column($recipients, 'unique_gift_id');
$rosesMap = [];

if (!empty($giftIds)) {
    $inQuery = implode(',', array_fill(0, count($giftIds), '?'));
    $stmtRoses = $pdo->prepare("SELECT rr.recipient_id as gift_ref_id, rr.quantity, rp.name FROM recipient_roses rr JOIN rose_products rp ON rr.rose_product_id = rp.id WHERE rr.recipient_id IN ($inQuery)");
    $stmtRoses->execute($giftIds);
    while ($row = $stmtRoses->fetch(PDO::FETCH_ASSOC)) {
        $rosesMap[$row['gift_ref_id']][] = $row;
    }
}

// --- Regroupement ---
$groupedData = [];
if ($searchQuery) {
    $groupedData['Résultats']['🔍 Recherche'] = $recipients;
} else {
    foreach ($recipients as $recip) {
        $floorLabel = ($selectedBuilding === 'all') ? $recip['building_name'] . ' - ' . ($recip['floor_name'] ?? '?') : ($recip['floor_name'] ?? 'Étage Inconnu');
        $groupedData[$floorLabel][$recip['room_name']][] = $recip;
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Livraison - St Valentin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body { background-color: #f4f6f9; padding-bottom: 80px; }
        .scrolling-wrapper { overflow-x: auto; white-space: nowrap; padding: 5px 0; -webkit-overflow-scrolling: touch; }
        .scrolling-wrapper::-webkit-scrollbar { display: none; }
        
        .time-btn, .building-btn {
            display: inline-block; padding: 8px 16px; margin-right: 8px;
            border-radius: 20px; background: white; color: #555; text-decoration: none;
            font-size: 0.9rem; font-weight: 600; border: 1px solid #dee2e6;
        }
        .building-btn { border-radius: 8px; background: #e9ecef; }
        .active { background: #dc3545; color: white; border-color: #dc3545; }
        .building-btn.active { background: #0d6efd; border-color: #0d6efd; }

        .floor-header { color: #495057; font-size: 0.9rem; font-weight: 800; text-transform: uppercase; margin-top: 25px; margin-bottom: 10px; border-bottom: 2px solid #dee2e6; padding-bottom: 5px; }
        .room-block { background: white; border-radius: 10px; margin-bottom: 15px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); overflow: hidden; border: 1px solid #e9ecef; }
        .room-title { background: #f8f9fa; padding: 10px 15px; font-weight: bold; border-bottom: 1px solid #e9ecef; display: flex; justify-content: space-between; }
        
        .recipient-item { padding: 15px; border-bottom: 1px solid #f1f3f5; transition: background-color 0.3s, opacity 0.3s; }
        .recipient-item.done { background-color: #f8fff9; opacity: 0.8; }
        .recipient-item:last-child { border-bottom: none; }
        
        .badge-rose-rouge { background-color: #dc3545; color: white; }
        .badge-rose-blanche { background-color: #ffffff; color: #212529; border: 1px solid #ced4da; }
        .badge-rose-pink { background-color: #ffc2d1; color: #880e4f; }
    </style>
</head>
<body>

<?php include 'navbar.php'; ?>

<div class="container mt-3">

    <div class="row mb-4 align-items-center">
        <div class="col-12">
            <a href="manage_orders.php" class="text-decoration-none text-muted"><i class="fas fa-arrow-left"></i> Retour Tableau de bord</a>
            <h2 class="fw-bold text-success mt-2"><i class="fas fa-truck"></i> Distribution</h2>
            <p class="text-muted">
                <?php if(!empty($searchQuery)): ?> Résultats : <strong><?php echo count($recipients); ?></strong> trouvé(s).
                <?php else: ?> À livrer à <strong><?php echo $selectedHour; ?>h</strong> : <strong><?php echo count($recipients); ?></strong>.
                <?php endif; ?>
            </p>
        </div>
    </div>

    <ul class="nav nav-tabs mb-3">
        <li class="nav-item"><a class="nav-link <?php echo $view === 'todo' ? 'active' : ''; ?>" href="?view=todo&building=<?php echo $selectedBuilding; ?>&hour=<?php echo $selectedHour; ?>"><i class="fas fa-truck"></i> À LIVRER</a></li>
        <li class="nav-item"><a class="nav-link <?php echo $view === 'done' ? 'active' : ''; ?>" href="?view=done&building=<?php echo $selectedBuilding; ?>&hour=<?php echo $selectedHour; ?>"><i class="fas fa-history"></i> HISTORIQUE</a></li>
    </ul>

    <div class="d-flex justify-content-center justify-content-md-end mb-3">
        <form method="GET" action="delivery.php" class="d-flex" style="max-width: 350px; width: 100%;">
            <input type="hidden" name="view" value="<?php echo htmlspecialchars($view); ?>">
            <div class="input-group shadow-sm">
                <input type="text" name="q" class="form-control rounded-start-pill border-end-0 ps-3 bg-white" placeholder="Chercher (Nom...)" value="<?php echo htmlspecialchars($searchQuery); ?>">
                <?php if($searchQuery): ?>
                    <a href="delivery.php?view=<?php echo $view; ?>" class="btn btn-white bg-white border border-start-0 border-end-0 text-danger"><i class="fas fa-times"></i></a>
                <?php endif; ?>
                <button class="btn btn-primary rounded-end-pill px-3" type="submit"><i class="fas fa-search"></i></button>
            </div>
        </form>
    </div>

    <?php if(!$searchQuery): ?>
        <div class="mb-2">
            <label class="small text-muted fw-bold ms-1">📍 BÂTIMENT</label>
            <div class="scrolling-wrapper">
                <a href="?building=all&hour=<?php echo $selectedHour; ?>&view=<?php echo $view; ?>" class="building-btn <?php echo ($selectedBuilding === 'all') ? 'active' : ''; ?>">Tout</a>
                <?php foreach($buildings as $b): ?>
                    <a href="?building=<?php echo $b['id']; ?>&hour=<?php echo $selectedHour; ?>&view=<?php echo $view; ?>" class="building-btn <?php echo ($selectedBuilding == $b['id']) ? 'active' : ''; ?>"><?php echo htmlspecialchars($b['name']); ?></a>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="mb-3">
            <label class="small text-muted fw-bold ms-1">🕒 HEURE</label>
            <div class="scrolling-wrapper">
                <?php for($h=8; $h<=17; $h++): ?>
                    <a href="?building=<?php echo $selectedBuilding; ?>&hour=<?php echo $h; ?>&view=<?php echo $view; ?>" class="time-btn <?php echo ($selectedHour == $h) ? 'active' : ''; ?>"><?php echo $h; ?>h</a>
                <?php endfor; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if(empty($groupedData)): ?>
        <div class="text-center py-5">
            <h5 class="text-muted"><?php echo ($view === 'todo') ? 'Aucune livraison ici à cette heure.' : 'Aucune livraison terminée.'; ?></h5>
        </div>
    <?php else: ?>
        <?php foreach($groupedData as $floorName => $roomsInFloor): ?>
            <div class="floor-header"><i class="fas fa-level-up-alt me-2"></i><?php echo htmlspecialchars($floorName); ?></div>
            <?php foreach($roomsInFloor as $roomName => $dests): ?>
                <div class="room-block">
                    <div class="room-title">
                        <span><?php echo htmlspecialchars($roomName); ?></span>
                        <span class="badge bg-secondary rounded-pill js-room-count"><?php echo count($dests); ?></span>
                    </div>
                    <div>
                        <?php foreach($dests as $dest): ?>
                            <div class="recipient-item <?php echo $view === 'done' ? 'done' : ''; ?>" id="recipient-row-<?php echo $dest['unique_gift_id']; ?>">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <div>
                                        <div class="fw-bold"><?php echo htmlspecialchars($dest['dest_prenom'] . ' ' . $dest['dest_nom']); ?></div>
                                        <div class="small text-muted"><?php echo htmlspecialchars($dest['class_name'] ?? ''); ?></div>
                                        <?php if($view === 'done'): ?>
                                            <div class="small text-success"><i class="fas fa-check-double"></i> <?php echo date('H:i', strtotime($dest['distributed_at'])); ?></div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <form method="POST" action="delivery.php" class="ajax-delivery-form">
                                        <input type="hidden" name="gift_id" value="<?php echo $dest['unique_gift_id']; ?>">
                                        <?php if($view === 'todo'): ?>
                                            <input type="hidden" name="action" value="mark_distributed">
                                            <button type="submit" class="btn btn-success btn-sm rounded-pill px-3 py-2 shadow-sm btn-action"><i class="fas fa-check"></i></button>
                                        <?php else: ?>
                                            <input type="hidden" name="unmark_distributed" value="1">
                                            <button type="submit" class="btn btn-outline-danger btn-sm rounded-pill px-3 py-2 shadow-sm btn-action"><i class="fas fa-undo"></i></button>
                                        <?php endif; ?>
                                    </form>
                                </div>
                                
                                <div class="mb-2">
                                    <?php if(isset($rosesMap[$dest['unique_gift_id']])): 
                                        foreach($rosesMap[$dest['unique_gift_id']] as $rose): 
                                            $colorName = mb_strtolower($rose['name']);
                                            $badgeClass = (strpos($colorName, 'rouge') !== false) ? "badge-rose-rouge" : 
                                                         ((strpos($colorName, 'blanche') !== false) ? "badge-rose-blanche" : "badge-rose-pink");
                                            $emoji = (strpos($colorName, 'rouge') !== false) ? "🌹" : 
                                                     ((strpos($colorName, 'blanche') !== false) ? "🤍" : "🌸");
                                    ?>
                                        <span class="badge rounded-pill <?php echo $badgeClass; ?> mb-1 p-2">
                                            <span class="fw-bold"><?php echo $rose['quantity']; ?></span> <?php echo $emoji; ?> 
                                            <span class="small"><?php echo htmlspecialchars($rose['name']); ?></span>
                                        </span>
                                    <?php endforeach; endif; ?>
                                </div>

                                <div class="d-flex align-items-center gap-2 mt-2">
                                    <?php if($dest['is_anonymous']): ?> <span class="badge bg-dark small">Anonyme</span>
                                    <?php else: ?> <span class="small text-muted fst-italic">De : <?php echo htmlspecialchars(($dest['buyer_prenom'] ?? '') . ' ' . ($dest['buyer_nom'] ?? '')); ?></span>
                                    <?php endif; ?>
                                    <?php if($dest['message_content']): ?>
                                        <button class="btn btn-sm btn-light border py-0 px-2 small text-primary" type="button" data-bs-toggle="collapse" data-bs-target="#m<?php echo $dest['unique_gift_id']; ?>">Msg</button>
                                    <?php endif; ?>
                                </div>
                                <?php if($dest['message_content']): ?>
                                    <div class="collapse mt-2" id="m<?php echo $dest['unique_gift_id']; ?>">
                                        <div class="bg-light p-2 rounded small border-start border-4 border-primary">"<?php echo htmlspecialchars($dest['message_content']); ?>"</div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php include 'toast_notifications.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
// =================================================================
// JAVASCRIPT SPÉCIFIQUE POUR DELIVERY.PHP
// =================================================================

document.addEventListener('DOMContentLoaded', function() {
    
    // 1. Gestion des clics sur les formulaires de livraison
    const forms = document.querySelectorAll('.ajax-delivery-form');
    
    forms.forEach(form => {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const btn = this.querySelector('.btn-action');
            const originalIcon = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
            btn.disabled = true;

            const formData = new FormData(this);
            formData.append('ajax', '1'); // Dit au PHP de répondre en JSON
            const giftId = formData.get('gift_id');

            fetch('delivery', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // --- GESTION DE LA SUPPRESSION VISUELLE INTELLIGENTE ---
                    const row = document.getElementById('recipient-row-' + giftId);
                    
                    if (row) {
                        // On trouve le conteneur de la salle (room-block)
                        const roomBlock = row.closest('.room-block');
                        
                        if (roomBlock) {
                            // On compte combien d'élèves il reste VISUELLEMENT (avant suppression)
                            // On exclut ceux qui sont déjà en train d'être supprimés (si clic très rapide)
                            const remainingItems = Array.from(roomBlock.querySelectorAll('.recipient-item')).filter(item => item.style.opacity !== '0');
                            
                            if (remainingItems.length <= 1) {
                                // C'était le dernier de la salle ! On supprime TOUT le bloc
                                roomBlock.style.transition = 'all 0.5s ease';
                                roomBlock.style.opacity = '0';
                                roomBlock.style.height = '0'; // Pour replier l'espace
                                roomBlock.style.marginTop = '0';
                                roomBlock.style.marginBottom = '0';
                                
                                setTimeout(() => roomBlock.remove(), 500);
                            } else {
                                // Il reste du monde, on supprime juste la ligne
                                row.style.transition = 'all 0.5s ease';
                                row.style.opacity = '0';
                                row.style.transform = 'translateX(20px)';
                                
                                // Mise à jour immédiate du compteur
                                const badge = roomBlock.querySelector('.js-room-count');
                                if(badge) {
                                    badge.textContent = remainingItems.length - 1;
                                }

                                setTimeout(() => row.remove(), 500);
                            }
                        }
                    }
                    // -------------------------------------------------------

                    // Afficher le Toast avec option Annuler
                    if (data.action === 'marked') {
                        showAjaxToastWithUndo("Livraison confirmée !", 'unmark_distributed', giftId);
                    } else {
                        showAjaxToastWithUndo("Livraison annulée.", 'mark_distributed', giftId);
                    }
                } else {
                    alert('Erreur: ' + data.message);
                    btn.innerHTML = originalIcon;
                    btn.disabled = false;
                }
            })
            .catch(error => {
                console.error('Erreur:', error);
                alert('Erreur réseau.');
                btn.innerHTML = originalIcon;
                btn.disabled = false;
            });
        });
    });
});

/**
 * Fonction personnalisée pour afficher un Toast avec un bouton Undo
 * Elle utilise le conteneur #js-toast-container défini dans toast_notifications.php
 */
function showAjaxToastWithUndo(message, undoActionName, id) {
    const container = document.getElementById('js-toast-container');
    if(!container) return; 

    const toastHtml = `
        <div class="toast fade align-items-center text-white bg-dark border-0 position-relative" role="alert" aria-live="assertive" aria-atomic="true">
            <div class="d-flex w-100 justify-content-between align-items-center p-0">
                <div class="toast-body d-flex align-items-center">
                    <i class="fas fa-check-circle text-success me-2"></i> ${message}
                </div>
                
                <div class="d-flex align-items-center pe-2">
                    <button type="button" class="btn btn-sm btn-outline-light me-2" onclick="performUndo('${undoActionName}', ${id})">
                        Annuler
                    </button>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast" aria-label="Close"></button>
                </div>
            </div>
            <div class="progress-track"><div class="progress-fill"></div></div>
        </div>
    `;

    const tempDiv = document.createElement('div');
    tempDiv.innerHTML = toastHtml;
    const toastElement = tempDiv.firstElementChild;
    container.appendChild(toastElement);

    const bsToast = new bootstrap.Toast(toastElement, { delay: 5000 });
    
    requestAnimationFrame(() => {
        bsToast.show();
    });

    toastElement.addEventListener('hidden.bs.toast', function () {
        setTimeout(() => { if (toastElement.parentNode) toastElement.remove(); }, 600);
    });
}

/**
 * Exécute l'action inverse (Undo) via AJAX
 */
function performUndo(actionName, id) {
    const formData = new FormData();
    formData.append('ajax', '1');
    formData.append('gift_id', id);
    
    if(actionName === 'mark_distributed') {
        formData.append('action', 'mark_distributed');
    } else {
        formData.append(actionName, '1');
    }

    fetch('delivery', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            if(typeof showToast === 'function') {
                showToast("Action annulée. Rechargement...", 'success');
            }
            setTimeout(() => location.reload(), 1000); 
        } else {
            alert('Impossible d\'annuler : ' + data.message);
        }
    })
    .catch(error => console.error('Erreur undo:', error));
}
</script>

</body>
</html>