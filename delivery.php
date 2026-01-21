<?php
// delivery.php
require_once 'db.php';
require_once 'auth_check.php'; 
require_once 'mail_config.php';
require_once 'logger.php';
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
                // 1. Mise à jour de la base de données (Déjà existant)
                $stmt = $pdo->prepare("UPDATE order_recipients SET is_distributed = 1, distributed_at = NOW(), distributed_by_cvl_id = ? WHERE id = ?");
                $stmt->execute([$adminId, $id]);

                // [LOG] AJOUTER ICI
                logAction($adminId, 'order_recipient', $id, 'DISTRIBUTION_CONFIRMED', ['is_distributed' => 0], ['is_distributed' => 1], "Livraison confirmée (AJAX)");
                
                // ============================================================
                // 2. ENVOI DU MAIL DE CONFIRMATION "LIVRÉ"
                // ============================================================
                try {
                    // Récupération des infos (Email acheteur + Noms)
                    $stmtInfo = $pdo->prepare("
                        SELECT 
                            u.email, u.prenom as buyer_prenom, 
                            r.prenom as dest_prenom, r.nom as dest_nom,
                            rp.name as rose_name
                        FROM order_recipients ort
                        JOIN orders o ON ort.order_id = o.id
                        JOIN users u ON o.user_id = u.user_id
                        JOIN recipients r ON ort.recipient_id = r.id
                        -- Optionnel : pour savoir quel type de fleur (si unique) ou juste générique
                        LEFT JOIN recipient_roses rr ON ort.id = rr.recipient_id
                        LEFT JOIN rose_products rp ON rr.rose_product_id = rp.id
                        WHERE ort.id = ?
                        LIMIT 1
                    ");
                    $stmtInfo->execute([$id]);
                    $info = $stmtInfo->fetch(PDO::FETCH_ASSOC);

                    if ($info && !empty($info['email'])) {
                        $mail = getMailer(); // Votre fonction helpers
                        $mail->addAddress($info['email']);
                        
                        $buyerName = htmlspecialchars($info['buyer_prenom']);
                        $destName  = htmlspecialchars($info['dest_prenom'] . ' ' . $info['dest_nom']);
                        
                        // Contenu du mail
                        $body = "
                        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: auto; border: 1px solid #eee; border-radius: 8px;'>
                            <div style='background-color: #198754; padding: 20px; text-align: center; color: white; border-radius: 8px 8px 0 0;'>
                                <h1 style='margin: 0;'>Mission Accomplie ! 🚀</h1>
                            </div>
                            <div style='padding: 20px; color: #333;'>
                                <p>Salut <strong>$buyerName</strong>,</p>
                                <p>Bonne nouvelle : ta commande pour <strong>$destName</strong> vient d'être distribuée ! 🌹</p>
                                
                                <div style='background-color: #f8f9fa; padding: 15px; border-left: 4px solid #198754; margin: 20px 0; font-style: italic;'>
                                    Le destinataire a bien reçu sa surprise en main propre.
                                </div>

                                <p>Merci d'avoir participé à l'opération Saint-Valentin du CVL !</p>
                                
                                <hr style='border: 0; border-top: 1px solid #eee; margin: 20px 0;'>
                                <p style='font-size: 0.8em; color: #888; text-align: center;'>L'équipe du CVL.</p>
                            </div>
                        </div>";

                        $mail->isHTML(true);
                        $mail->Subject = "✅ C'est livré ! (Pour $destName)";
                        $mail->Body    = $body;
                        $mail->AltBody = "Salut $buyerName, ta commande pour $destName a bien été distribuée ! Merci, Le CVL.";
                        
                        $mail->send();
                    }
                } catch (Exception $e) {
                    // On log l'erreur mais on ne bloque pas la réponse JSON (l'utilisateur ne doit pas voir d'erreur si juste le mail plante)
                    error_log("Erreur envoi mail distribution (ID: $id) : " . $e->getMessage());
                }
                // ============================================================

                $response = [
                    'success' => true, 
                    'action' => 'marked',
                    'message' => 'Livraison confirmée !'
                ];
            }
            elseif (isset($_POST['unmark_distributed'])) {
                $stmt = $pdo->prepare("UPDATE order_recipients SET is_distributed = 0, distributed_at = NULL, distributed_by_cvl_id = NULL WHERE id = ?");
                $stmt->execute([$id]);

                // [LOG] AJOUTER ICI
                logAction($adminId, 'order_recipient', $id, 'DISTRIBUTION_CANCELLED', ['is_distributed' => 1], ['is_distributed' => 0], "Livraison annulée (AJAX)");
                
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
            
            // [LOG] AJOUTER ICI
            logAction($adminId, 'order_recipient', $id, 'DISTRIBUTION_CONFIRMED', ['is_distributed' => 0], ['is_distributed' => 1], "Livraison confirmée");

            $actionType = 'marked';
        }
        elseif (isset($_POST['unmark_distributed'])) {
            $pdo->prepare("UPDATE order_recipients SET is_distributed = 0, distributed_at = NULL, distributed_by_cvl_id = NULL WHERE id = ?")->execute([$id]);
            
            // [LOG] AJOUTER ICI
            logAction($adminId, 'order_recipient', $id, 'DISTRIBUTION_CANCELLED', ['is_distributed' => 1], ['is_distributed' => 0], "Livraison annulée");
            
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

<div class="toast-container position-fixed bottom-0 end-0 p-3" style="z-index: 9999;">
    <div id="deliveryToast" class="toast" role="alert" aria-live="assertive" aria-atomic="true" data-bs-autohide="false">
        <div class="toast-header bg-success text-white">
            <i class="fas fa-truck me-2"></i> <strong class="me-auto">Livraison en cours</strong>
            <small class="text-white-50">Juste un instant...</small>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast" aria-label="Close"></button>
        </div>
        <div class="toast-body bg-white">
            <p class="mb-2 text-dark">L'e-mail de confirmation sera envoyé dans <strong id="toastCountdown" class="text-success fs-5">5</strong> secondes.</p>
            <div class="progress mb-3" style="height: 5px;">
                <div id="toastProgressBar" class="progress-bar bg-success" role="progressbar" style="width: 100%; transition: none;"></div>
            </div>
            <button type="button" id="toastCancelBtn" class="btn btn-outline-danger btn-sm w-100 fw-bold">
                <i class="fas fa-undo me-1"></i> ANNULER L'ENVOI
            </button>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    
    // --- VARIABLES GLOBALES ---
    let timer = null;
    let seconds = 5;
    let pendingFormData = null;
    let pendingButton = null; // Pour réactiver le bouton si on annule

    // --- ÉLÉMENTS DU TOAST ---
    const toastEl = document.getElementById('deliveryToast');
    const toast = new bootstrap.Toast(toastEl);
    const progressBar = document.getElementById('toastProgressBar');
    const countdownSpan = document.getElementById('toastCountdown');
    const cancelBtn = document.getElementById('toastCancelBtn');

    // --- FONCTION QUI ENVOIE RÉELLEMENT AU SERVEUR (Après 5s) ---
    function runDeliveryAjax(formData, btnElement) {
        const giftId = formData.get('gift_id');

        fetch('delivery', { // Assurez-vous que le nom du fichier est bon
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // --- SUCCÈS : ON SUPPRIME LA LIGNE VISUELLEMENT ---
                const row = document.getElementById('recipient-row-' + giftId);
                
                if (row) {
                    // Animation de suppression
                    row.style.transition = 'all 0.5s ease';
                    row.style.transform = 'translateX(100%)';
                    row.style.opacity = '0';
                    row.style.height = '0';
                    row.style.margin = '0';
                    row.style.padding = '0';

                    // Gestion du compteur de la salle
                    const roomBlock = row.closest('.room-block');
                    if (roomBlock) {
                        const badge = roomBlock.querySelector('.js-room-count');
                        // On compte ceux qui ne sont pas effacés
                        const remaining = Array.from(roomBlock.querySelectorAll('.recipient-item')).filter(item => item.style.opacity !== '0').length;
                        
                        if (badge) badge.textContent = remaining - 1; // -1 car celui-ci part

                        // Si plus personne dans la salle, on cache le bloc salle
                        if (remaining - 1 <= 0) {
                            setTimeout(() => {
                                roomBlock.style.transition = 'opacity 0.5s';
                                roomBlock.style.opacity = '0';
                                setTimeout(() => roomBlock.remove(), 500);
                            }, 500);
                        }
                    }

                    // Suppression finale du DOM après l'animation
                    setTimeout(() => row.remove(), 600);
                }
            } else {
                alert("Erreur : " + data.message);
                if(btnElement) {
                    btnElement.disabled = false;
                    btnElement.innerHTML = '<i class="fas fa-check"></i>'; // Remet l'icône
                }
            }
        })
        .catch(error => {
            console.error('Erreur:', error);
            alert("Erreur réseau.");
            if(btnElement) {
                btnElement.disabled = false;
                btnElement.innerHTML = '<i class="fas fa-check"></i>';
            }
        });
    }

    // --- ÉCOUTEUR SUR LES FORMULAIRES ---
    document.body.addEventListener('submit', function(e) {
        // On cible uniquement les form de livraison AJAX
        if (e.target.classList.contains('ajax-delivery-form')) {
            e.preventDefault();
            
            const formData = new FormData(e.target);
            formData.append('ajax', '1');
            
            const action = formData.get('action'); // 'mark_distributed' ou undefined (si c'est 'unmark')
            const btn = e.target.querySelector('.btn-action');

            // CAS 1 : C'est une VALIDATION DE LIVRAISON (Check Vert)
            if (action === 'mark_distributed') {
                
                // 1. On stocke les données pour plus tard
                pendingFormData = formData;
                pendingButton = btn;

                // 2. Feedback visuel immédiat sur le bouton (Optionnel mais conseillé)
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-hourglass-half fa-spin"></i>'; // Sablier
                
                // 3. Reset du Toast
                seconds = 5;
                countdownSpan.innerText = seconds;
                progressBar.style.width = '100%';
                progressBar.style.transition = 'none';
                
                toast.show();
                
                // 4. Lancement animation barre
                setTimeout(() => {
                    progressBar.style.transition = 'width 5s linear';
                    progressBar.style.width = '0%';
                }, 50);

                // 5. Lancement du Timer
                if (timer) clearInterval(timer);
                timer = setInterval(() => {
                    seconds--;
                    countdownSpan.innerText = seconds;
                    
                    if (seconds <= 0) {
                        // --- FIN DU TIMER : ENVOI ---
                        clearInterval(timer);
                        toast.hide();
                        runDeliveryAjax(pendingFormData, pendingButton);
                    }
                }, 1000);

            } 
            // CAS 2 : C'est une ANNULATION D'HISTORIQUE (Bouton Rouge 'Undo')
            else {
                // Pas de timer pour l'annulation historique, on exécute direct
                // Note: Ici on recharge souvent la page pour l'historique, ou on fait un traitement simple
                // Pour faire simple, on envoie direct :
                const giftId = formData.get('gift_id');
                
                fetch('delivery', {
                    method: 'POST',
                    body: formData
                })
                .then(r => r.json())
                .then(d => {
                    if(d.success) location.reload(); // Rechargement simple pour remettre la ligne
                });
            }
        }
    });

    // --- BOUTON ANNULER (DANS LE TOAST) ---
    cancelBtn.addEventListener('click', function() {
        clearInterval(timer); // Stop le temps
        toast.hide();         // Cache la notif
        
        // On réactive le bouton sur lequel l'utilisateur avait cliqué
        if (pendingButton) {
            pendingButton.disabled = false;
            pendingButton.innerHTML = '<i class="fas fa-check"></i>'; // On remet l'icône d'origine
        }
        
        pendingFormData = null; // On vide la mémoire
    });
});
</script>

</body>
</html>