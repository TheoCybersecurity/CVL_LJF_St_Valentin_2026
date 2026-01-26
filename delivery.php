<?php
// delivery.php
require_once 'db.php';
require_once 'auth_check.php'; 
require_once 'mail_config.php';
require_once 'logger.php';

checkAccess('cvl');

// ==============================================================================
// 1. TRAITEMENT AJAX (Validation par lot pour un destinataire)
// ==============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => 'Erreur inconnue'];
    
    // On reçoit maintenant l'ID du destinataire (l'élève), pas juste une commande unique
    $recipientId = isset($_POST['recipient_id']) ? intval($_POST['recipient_id']) : 0;
    $giftId      = isset($_POST['gift_id']) ? intval($_POST['gift_id']) : 0; // Fallback pour compatibilité historique

    // Si on a un recipient_id, on priorise le traitement par lot
    $targetId = ($recipientId > 0) ? $recipientId : 0;

    if ($targetId > 0) {
        $adminId = $_SESSION['user_id'] ?? ($_SESSION['id'] ?? null);

        try {
            if (isset($_POST['action']) && $_POST['action'] === 'mark_distributed') {
                
                // A. Récupérer toutes les commandes NON distribuées pour cet élève
                // Si on a reçu un recipient_id, on prend tout. Si c'est un gift_id (cas rare), on prend juste lui.
                $sqlGifts = "SELECT id FROM order_recipients WHERE is_distributed = 0 ";
                $params = [];
                
                if ($recipientId > 0) {
                    $sqlGifts .= "AND recipient_id = ?";
                    $params[] = $recipientId;
                } else {
                    $sqlGifts .= "AND id = ?";
                    $params[] = $giftId;
                }

                $stmtFind = $pdo->prepare($sqlGifts);
                $stmtFind->execute($params);
                $giftsToUpdate = $stmtFind->fetchAll(PDO::FETCH_COLUMN);

                $countUpdated = 0;

                // B. Boucle sur chaque cadeau pour update + mail individuel
                foreach ($giftsToUpdate as $ortId) {
                    
                    // 1. Update BDD
                    $stmtUpdate = $pdo->prepare("UPDATE order_recipients SET is_distributed = 1, distributed_at = NOW(), distributed_by_cvl_id = ? WHERE id = ?");
                    $stmtUpdate->execute([$adminId, $ortId]);
                    $countUpdated++;

                    // Log
                    logAction($adminId, 'order_recipient', $ortId, 'DISTRIBUTION_CONFIRMED', ['is_distributed' => 0], ['is_distributed' => 1], "Livraison groupée confirmée");

                    // 2. Envoi Mail (Copier-coller de la logique précédente, mais dans la boucle)
                    try {
                        $stmtInfo = $pdo->prepare("
                            SELECT 
                                u.email, u.prenom as buyer_prenom, 
                                r.prenom as dest_prenom, r.nom as dest_nom
                            FROM order_recipients ort
                            JOIN orders o ON ort.order_id = o.id
                            JOIN users u ON o.user_id = u.user_id
                            JOIN recipients r ON ort.recipient_id = r.id
                            WHERE ort.id = ?
                            LIMIT 1
                        ");
                        $stmtInfo->execute([$ortId]);
                        $info = $stmtInfo->fetch(PDO::FETCH_ASSOC);

                        if ($info && !empty($info['email'])) {
                            $mail = getMailer();
                            // Reset des destinataires pour éviter d'envoyer à l'acheteur précédent dans la boucle
                            $mail->clearAddresses(); 
                            $mail->addAddress($info['email']);
                            
                            $buyerName = htmlspecialchars($info['buyer_prenom']);
                            $destName  = htmlspecialchars($info['dest_prenom'] . ' ' . $info['dest_nom']);
                            
                            $body = "
                            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: auto; border: 1px solid #eee; border-radius: 8px;'>
                                <div style='background-color: #d63384; padding: 20px; text-align: center; color: white; border-radius: 8px 8px 0 0;'>
                                    <h1 style='margin: 0;'>Mission Accomplie ! 🚀</h1>
                                </div>
                                <div style='padding: 20px; color: #333;'>
                                    <p>Bonjour <strong>$buyerName</strong>,</p>
                                    <p>Bonne nouvelle : ta commande pour <strong>$destName</strong> vient d'être distribuée ! 🌹</p>
                                    <div style='background-color: #f8f9fa; padding: 15px; border-left: 4px solid #d63384; margin: 20px 0; font-style: italic;'>
                                        Le destinataire a bien reçu sa surprise en main propre.
                                    </div>
                                    
                                    <p>Merci d'avoir participé à l'opération Saint-Valentin du CVL !</p>

                                    <hr style='border: 0; border-top: 1px solid #eee; margin: 20px 0;'>
                                    <p style='font-size: 0.8em; color: #888; text-align: center;'>
                                        Ceci est un mail automatique. Merci de ne pas répondre.<br>
                                        L'équipe du CVL.
                                    </p>
                                </div>
                            </div>";

                            $mail->isHTML(true);
                            $mail->Subject = "✅ C'est livré ! (Pour $destName)";
                            $mail->Body    = $body;
                            $mail->AltBody = "Salut $buyerName, ta commande pour $destName a bien été distribuée !";
                            $mail->send();
                        }
                    } catch (Exception $e) {
                        error_log("Erreur mail distribution (ID: $ortId) : " . $e->getMessage());
                    }
                }

                $response = [
                    'success' => true, 
                    'action' => 'marked',
                    'message' => "$countUpdated cadeaux confirmés !"
                ];
            }
            elseif (isset($_POST['unmark_distributed'])) {
                // Annulation groupée
                $sqlUnmark = "UPDATE order_recipients SET is_distributed = 0, distributed_at = NULL, distributed_by_cvl_id = NULL WHERE is_distributed = 1 ";
                $paramsUnmark = [];

                if ($recipientId > 0) {
                    $sqlUnmark .= "AND recipient_id = ?";
                    $paramsUnmark[] = $recipientId;
                } else {
                    $sqlUnmark .= "AND id = ?";
                    $paramsUnmark[] = $giftId;
                }

                $stmt = $pdo->prepare($sqlUnmark);
                $stmt->execute($paramsUnmark);
                
                // On log juste une fois générique pour l'annulation de groupe
                logAction($adminId, 'recipient_group', $targetId, 'DISTRIBUTION_CANCELLED', [], [], "Annulation livraison groupée");

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
    exit;
}

// ==============================================================================
// 2. TRAITEMENT POST STANDARD (Fallback)
// ==============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['ajax'])) {
    // Redirection simple si JS désactivé (ne gère pas le groupage complexe ici pour simplifier, renvoie vers l'accueil)
    header("Location: delivery.php");
    exit;
}

// ==============================================================================
// 3. PRÉPARATION DES DONNÉES
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
            ort.id as unique_gift_id, ort.is_anonymous, ort.message_id, ort.distributed_at, ort.recipient_id,
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
            ort.id as unique_gift_id, ort.is_anonymous, ort.message_id, ort.distributed_at, ort.recipient_id,
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
$rawRecipients = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- Chargement des Roses ---
// On récupère les détails des roses pour tous les IDs de cadeaux trouvés
$giftIds = array_column($rawRecipients, 'unique_gift_id');
$rosesMap = [];

if (!empty($giftIds)) {
    $inQuery = implode(',', array_fill(0, count($giftIds), '?'));
    // Note: rr.recipient_id ici correspond à order_recipients.id (clé étrangère un peu mal nommée dans la structure, mais c'est le lien)
    $stmtRoses = $pdo->prepare("SELECT rr.recipient_id as gift_ref_id, rr.quantity, rp.name FROM recipient_roses rr JOIN rose_products rp ON rr.rose_product_id = rp.id WHERE rr.recipient_id IN ($inQuery)");
    $stmtRoses->execute($giftIds);
    while ($row = $stmtRoses->fetch(PDO::FETCH_ASSOC)) {
        $rosesMap[$row['gift_ref_id']][] = $row;
    }
}

// --- REGROUPEMENT PAR ÉLÈVE (NOUVEAU) ---
// On transforme la liste plate (1 ligne = 1 commande) en liste hiérarchique (1 ligne = 1 élève avec N commandes)
$groupedRecipients = [];

foreach ($rawRecipients as $row) {
    $rId = $row['recipient_id'];
    
    // Si cet élève n'est pas encore dans la liste, on l'initialise
    if (!isset($groupedRecipients[$rId])) {
        $groupedRecipients[$rId] = [
            'info' => [
                'recipient_id' => $rId,
                'nom' => $row['dest_nom'],
                'prenom' => $row['dest_prenom'],
                'class_name' => $row['class_name'],
                'room_name' => $row['room_name'],
                'floor_name' => $row['floor_name'],
                'building_name' => $row['building_name'],
                'distributed_at' => $row['distributed_at'] // Pour l'historique
            ],
            'orders' => []
        ];
    }
    
    // On ajoute la commande spécifique à cet élève
    $groupedRecipients[$rId]['orders'][] = [
        'gift_id' => $row['unique_gift_id'],
        'buyer_prenom' => $row['buyer_prenom'],
        'buyer_nom' => $row['buyer_nom'],
        'is_anonymous' => $row['is_anonymous'],
        'message' => $row['message_content'],
        'roses' => $rosesMap[$row['unique_gift_id']] ?? []
    ];
}

// --- REGROUPEMENT PAR SALLE POUR L'AFFICHAGE ---
// Maintenant qu'on a regroupé par élève, on regroupe par Salle/Étage pour l'affichage visuel
$displayData = [];
if ($searchQuery) {
    $displayData['Résultats']['🔍 Recherche'] = $groupedRecipients;
} else {
    foreach ($groupedRecipients as $rId => $data) {
        $floorLabel = ($selectedBuilding === 'all') ? $data['info']['building_name'] . ' - ' . ($data['info']['floor_name'] ?? '?') : ($data['info']['floor_name'] ?? 'Étage Inconnu');
        $roomLabel = $data['info']['room_name'];
        $displayData[$floorLabel][$roomLabel][] = $data;
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <title>Livraison - St Valentin</title>
    <?php include 'head_imports.php'; ?>
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

        .order-detail-line { padding: 8px; background: #f8f9fa; border-radius: 6px; margin-bottom: 6px; border-left: 3px solid #dee2e6; }
        .order-detail-line:last-child { margin-bottom: 0; }
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
                <?php if(!empty($searchQuery)): ?> Résultats : <strong><?php echo count($groupedRecipients); ?></strong> élève(s) trouvé(s).
                <?php else: ?> À livrer à <strong><?php echo $selectedHour; ?>h</strong> : <strong><?php echo count($groupedRecipients); ?></strong> élève(s).
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

    <?php if(empty($displayData)): ?>
        <div class="text-center py-5">
            <h5 class="text-muted"><?php echo ($view === 'todo') ? 'Aucune livraison ici à cette heure.' : 'Aucune livraison terminée.'; ?></h5>
        </div>
    <?php else: ?>
        <?php foreach($displayData as $floorName => $roomsInFloor): ?>
            <div class="floor-header"><i class="fas fa-level-up-alt me-2"></i><?php echo htmlspecialchars($floorName); ?></div>
            <?php foreach($roomsInFloor as $roomName => $recipientsList): ?>
                <div class="room-block">
                    <div class="room-title">
                        <span><?php echo htmlspecialchars($roomName); ?></span>
                        <span class="badge bg-secondary rounded-pill js-room-count"><?php echo count($recipientsList); ?></span>
                    </div>
                    <div>
                        <?php foreach($recipientsList as $recipientData): 
                            $info = $recipientData['info'];
                            $orders = $recipientData['orders'];
                        ?>
                            <div class="recipient-item <?php echo $view === 'done' ? 'done' : ''; ?>" id="recipient-row-<?php echo $info['recipient_id']; ?>">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <div>
                                        <h5 class="fw-bold mb-0 text-dark"><?php echo htmlspecialchars($info['prenom'] . ' ' . $info['nom']); ?></h5>
                                        <div class="small text-muted"><?php echo htmlspecialchars($info['class_name'] ?? ''); ?></div>
                                        <?php if($view === 'done' && !empty($info['distributed_at'])): ?>
                                            <div class="small text-success"><i class="fas fa-check-double"></i> <?php echo date('H:i', strtotime($info['distributed_at'])); ?></div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <form method="POST" action="delivery.php" class="ajax-delivery-form">
                                        <input type="hidden" name="recipient_id" value="<?php echo $info['recipient_id']; ?>">
                                        <?php if($view === 'todo'): ?>
                                            <input type="hidden" name="action" value="mark_distributed">
                                            <button type="submit" class="btn btn-success rounded-pill px-3 py-2 shadow-sm btn-action fw-bold">
                                                <i class="fas fa-check me-1"></i> Livrer
                                            </button>
                                        <?php else: ?>
                                            <input type="hidden" name="unmark_distributed" value="1">
                                            <button type="submit" class="btn btn-outline-danger btn-sm rounded-pill px-3 py-2 shadow-sm btn-action">
                                                <i class="fas fa-undo"></i> Annuler
                                            </button>
                                        <?php endif; ?>
                                    </form>
                                </div>
                                
                                <div class="mt-2">
                                    <?php foreach($orders as $order): ?>
                                        <div class="order-detail-line">
                                            <div class="mb-1">
                                                <?php 
                                                foreach($order['roses'] as $rose): 
                                                    $colorName = mb_strtolower($rose['name']);
                                                    $badgeClass = (strpos($colorName, 'rouge') !== false) ? "badge-rose-rouge" : 
                                                                 ((strpos($colorName, 'blanche') !== false) ? "badge-rose-blanche" : "badge-rose-pink");
                                                    $emoji = (strpos($colorName, 'rouge') !== false) ? "🌹" : 
                                                             ((strpos($colorName, 'blanche') !== false) ? "🤍" : "🌸");
                                                ?>
                                                    <span class="badge rounded-pill <?php echo $badgeClass; ?> me-1">
                                                        <?php echo $rose['quantity']; ?> <?php echo $emoji; ?> 
                                                        <?php echo htmlspecialchars($rose['name']); ?>
                                                    </span>
                                                <?php endforeach; ?>
                                            </div>

                                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                                <?php if($order['is_anonymous']): ?> 
                                                    <span class="badge bg-dark small" style="font-size: 0.7rem;">Anonyme</span>
                                                <?php else: ?> 
                                                    <span class="small text-muted fst-italic">De : <?php echo htmlspecialchars(($order['buyer_prenom'] ?? '') . ' ' . ($order['buyer_nom'] ?? '')); ?></span>
                                                <?php endif; ?>

                                                <?php if($order['message']): ?>
                                                    <span class="text-secondary small ms-1">|</span>
                                                    <span class="small text-dark fw-bold">"<?php echo htmlspecialchars($order['message']); ?>"</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<div class="toast-container position-fixed bottom-0 end-0 p-3" style="z-index: 9999;">
    <div id="deliveryToast" class="toast" role="alert" aria-live="assertive" aria-atomic="true" data-bs-autohide="false">
        <div class="toast-header bg-success text-white">
            <i class="fas fa-truck me-2"></i> <strong class="me-auto">Livraison en cours</strong>
            <small class="text-white-50">Validation groupée...</small>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast" aria-label="Close"></button>
        </div>
        <div class="toast-body bg-white">
            <p class="mb-2 text-dark">Les e-mails de confirmation partiront dans <strong id="toastCountdown" class="text-success fs-5">5</strong> secondes.</p>
            <div class="progress mb-3" style="height: 5px;">
                <div id="toastProgressBar" class="progress-bar bg-success" role="progressbar" style="width: 100%; transition: none;"></div>
            </div>
            <button type="button" id="toastCancelBtn" class="btn btn-outline-danger btn-sm w-100 fw-bold">
                <i class="fas fa-undo me-1"></i> ANNULER
            </button>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    
    // --- VARIABLES GLOBALES ---
    let timer = null;
    let seconds = 5;
    let pendingFormData = null;
    let pendingButton = null;

    const toastEl = document.getElementById('deliveryToast');
    const toast = new bootstrap.Toast(toastEl);
    const progressBar = document.getElementById('toastProgressBar');
    const countdownSpan = document.getElementById('toastCountdown');
    const cancelBtn = document.getElementById('toastCancelBtn');

    function runDeliveryAjax(formData, btnElement) {
        // Ici on récupère le recipient_id pour masquer la ligne de l'élève
        const recipientId = formData.get('recipient_id');

        fetch('delivery', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // On cible la ligne de l'élève (tout le bloc)
                const row = document.getElementById('recipient-row-' + recipientId);
                
                if (row) {
                    row.style.transition = 'all 0.5s ease';
                    row.style.transform = 'translateX(100%)';
                    row.style.opacity = '0';
                    row.style.height = '0';
                    row.style.margin = '0';
                    row.style.padding = '0';

                    const roomBlock = row.closest('.room-block');
                    if (roomBlock) {
                        const badge = roomBlock.querySelector('.js-room-count');
                        const remaining = Array.from(roomBlock.querySelectorAll('.recipient-item')).filter(item => item.style.opacity !== '0').length;
                        
                        if (badge) badge.textContent = remaining - 1;

                        if (remaining - 1 <= 0) {
                            setTimeout(() => {
                                roomBlock.style.transition = 'opacity 0.5s';
                                roomBlock.style.opacity = '0';
                                setTimeout(() => roomBlock.remove(), 500);
                            }, 500);
                        }
                    }
                    setTimeout(() => row.remove(), 600);
                }
            } else {
                alert("Erreur : " + data.message);
                if(btnElement) {
                    btnElement.disabled = false;
                    btnElement.innerHTML = '<i class="fas fa-check me-1"></i> Livrer';
                }
            }
        })
        .catch(error => {
            console.error('Erreur:', error);
            alert("Erreur réseau.");
            if(btnElement) {
                btnElement.disabled = false;
                btnElement.innerHTML = '<i class="fas fa-check me-1"></i> Livrer';
            }
        });
    }

    document.body.addEventListener('submit', function(e) {
        if (e.target.classList.contains('ajax-delivery-form')) {
            e.preventDefault();
            
            const formData = new FormData(e.target);
            formData.append('ajax', '1');
            
            const action = formData.get('action'); 
            const btn = e.target.querySelector('.btn-action');

            if (action === 'mark_distributed') {
                pendingFormData = formData;
                pendingButton = btn;

                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-hourglass-half fa-spin"></i>'; 
                
                seconds = 5;
                countdownSpan.innerText = seconds;
                progressBar.style.width = '100%';
                progressBar.style.transition = 'none';
                
                toast.show();
                
                setTimeout(() => {
                    progressBar.style.transition = 'width 5s linear';
                    progressBar.style.width = '0%';
                }, 50);

                if (timer) clearInterval(timer);
                timer = setInterval(() => {
                    seconds--;
                    countdownSpan.innerText = seconds;
                    
                    if (seconds <= 0) {
                        clearInterval(timer);
                        toast.hide();
                        runDeliveryAjax(pendingFormData, pendingButton);
                    }
                }, 1000);

            } else {
                // Pour l'historique (Undo), on reload pour rafraîchir la liste
                fetch('delivery', {
                    method: 'POST',
                    body: formData
                })
                .then(r => r.json())
                .then(d => {
                    if(d.success) location.reload();
                });
            }
        }
    });

    cancelBtn.addEventListener('click', function() {
        clearInterval(timer);
        toast.hide();
        
        if (pendingButton) {
            pendingButton.disabled = false;
            pendingButton.innerHTML = '<i class="fas fa-check me-1"></i> Livrer';
        }
        pendingFormData = null;
    });
});
</script>

</body>
</html>