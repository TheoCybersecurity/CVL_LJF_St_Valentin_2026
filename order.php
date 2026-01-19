<?php
// order.php
session_start();
require_once 'db.php';

// ====================================================
// 1. SÉCURITÉ : VÉRIFIER SI LES VENTES SONT OUVERTES
// ====================================================
$stmt = $pdo->prepare("SELECT setting_value FROM global_settings WHERE setting_key = 'sales_open'");
$stmt->execute();
$isSalesOpen = $stmt->fetchColumn() == '1';

if (!$isSalesOpen) {
    header("Location: index.php");
    exit;
}
// ====================================================

// --- LOGIQUE D'AUTHENTIFICATION ---
$is_logged_in = false;
$user_info = null;

if (isset($_COOKIE['jwt'])) {
    try {
        require_once 'auth_check.php'; 
        $is_logged_in = true;
        $_SESSION['user_id'] = $current_user_id;

        $stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
        $stmt->execute([$current_user_id]);
        $user_info = $stmt->fetch();
        
        if (!$user_info) { header("Location: setup.php"); exit; }
    } catch (Exception $e) { $is_logged_in = false; }
} 
elseif (isset($_GET['guest']) && $_GET['guest'] == 1) {
    $_SESSION['is_guest'] = true;
}
elseif (isset($_SESSION['is_guest'])) {
    // On laisse passer
}
else {
    header("Location: welcome.php");
    exit;
}

// --- CHARGEMENT DES DONNÉES ---
$roses = $pdo->query("SELECT * FROM rose_products")->fetchAll();
$rosesPrices = $pdo->query("SELECT * FROM roses_prices ORDER BY quantity ASC")->fetchAll(PDO::FETCH_ASSOC);

$jsPriceTable = [];
foreach($rosesPrices as $rp) {
    $jsPriceTable[$rp['quantity']] = $rp['price'];
}

$messages = $pdo->query("SELECT * FROM predefined_messages ORDER BY position ASC, id ASC")->fetchAll();
$classes = $pdo->query("SELECT * FROM classes ORDER BY name ASC")->fetchAll();
$rooms = $pdo->query("SELECT * FROM rooms ORDER BY name ASC")->fetchAll();
$timeSlots = range(8, 17); 
?>

<script>
    const ROSE_PRICES = <?php echo json_encode($jsPriceTable); ?>;
    function getPriceForQuantity(qty) {
        qty = parseInt(qty);
        if (qty <= 0) return 0;
        if (ROSE_PRICES[qty]) {
            return parseFloat(ROSE_PRICES[qty]);
        } else {
            let maxQty = Math.max(...Object.keys(ROSE_PRICES).map(Number));
            let maxPrice = parseFloat(ROSE_PRICES[maxQty]);
            let diff = qty - maxQty;
            return maxPrice + (diff * 2.00); 
        }
    }
</script>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nouvelle Commande - St Valentin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="assets/css/style.css?v=<?php echo time(); ?>" rel="stylesheet">
    <style>
        .rose-card { border-left: 4px solid #d63384; background-color: #fff0f6; }
        .total-box { font-size: 1.5rem; font-weight: bold; color: #d63384; }
        .schedule-row { font-size: 0.9rem; }
        .search-results {
            position: absolute; top: 100%; left: 0; right: 0;
            background: white; border: 1px solid #ccc;
            border-radius: 0 0 8px 8px; z-index: 1050; max-height: 200px; overflow-y: auto;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1); display: none;
        }
        .search-item { padding: 10px 15px; cursor: pointer; border-bottom: 1px solid #eee; }
        .search-item:hover { background-color: #f8f9fa; color: #d63384; }
        .search-item small { display: block; color: #888; font-size: 0.8em; }
    </style>
</head>
<body class="bg-light">

<?php include 'navbar.php'; ?>

<div class="container pb-5">
    <div class="text-center mb-4">
        <h2 class="text-danger">❤️ Nouvelle Commande</h2>
        <p class="text-muted">Remplissez le formulaire pour offrir des roses.</p>
    </div>

    <div class="card mb-4 shadow-sm">
        <div class="card-header bg-white text-danger fw-bold">
            1. Vos informations (Acheteur)
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-4 mb-2">
                    <label class="form-label">Votre Nom</label>
                    <input type="text" id="buyer_nom" class="form-control" 
                        value="<?php echo $is_logged_in ? htmlspecialchars($user_info['nom']) : ''; ?>" 
                        <?php echo $is_logged_in ? 'readonly style="background-color:#e9ecef;"' : 'required'; ?>>
                </div>
                <div class="col-md-4 mb-2">
                    <label class="form-label">Votre Prénom</label>
                    <input type="text" id="buyer_prenom" class="form-control" 
                        value="<?php echo $is_logged_in ? htmlspecialchars($user_info['prenom']) : ''; ?>" 
                        <?php echo $is_logged_in ? 'readonly style="background-color:#e9ecef;"' : 'required'; ?>>
                </div>
                <div class="col-md-4 mb-2">
                    <label class="form-label">Votre Classe</label>
                    <?php if ($is_logged_in): ?>
                        <?php 
                            $className = "Inconnue";
                            foreach($classes as $c) { if($c['id'] == $user_info['class_id']) $className = $c['name']; }
                        ?>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($className); ?>" readonly style="background-color:#e9ecef;">
                        <input type="hidden" id="buyer_class" value="<?php echo $user_info['class_id']; ?>">
                    <?php else: ?>
                        <select id="buyer_class" class="form-select" required>
                            <option value="">-- Choisir --</option>
                            <?php foreach($classes as $c): ?>
                                <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-4 shadow-sm">
        <div class="card-header bg-white text-danger fw-bold d-flex justify-content-between align-items-center">
            <span>2. Vos Destinataires</span>
            <button type="button" class="btn btn-outline-danger btn-sm" onclick="openAddModal()">+ Ajouter</button>
        </div>
        <div class="card-body">
            <div id="empty-cart-msg" class="text-center text-muted py-4">
                <em>Aucun destinataire ajouté pour le moment.</em>
            </div>
            <div id="recipients-list" class="row g-3"></div>
        </div>
        <div class="card-footer bg-white">
            <div class="d-flex justify-content-between align-items-center">
                <span>Personnes : <strong id="count-people">0</strong></span>
                <span class="total-box">Total : <span id="grand-total">0.00</span> €</span>
            </div>
        </div>
    </div>

    <div class="card mb-4 shadow-sm border-primary">
        <div class="card-header bg-white text-primary fw-bold">
            3. Finalisation & Contact
        </div>
        <div class="card-body">
            
            <div class="alert alert-info small d-flex align-items-start">
                <i class="fas fa-envelope-open-text me-2 mt-1"></i>
                <div>
                    <strong>Pourquoi votre email ?</strong><br>
                    Pour vous envoyer votre ticket récapitulatif, confirmer votre paiement et vous notifier lorsque vos roses seront distribuées.
                </div>
            </div>

            <div class="mb-3">
                <label for="buyer_email" class="form-label fw-bold">Votre adresse mail <span class="text-danger">*</span></label>
                <input type="email" class="form-control" id="buyer_email" 
                       placeholder="exemple@marescal.fr" 
                       value="<?php echo $is_logged_in && !empty($user_info['email']) ? htmlspecialchars($user_info['email']) : ''; ?>"
                       required>
            </div>

            <div class="form-check mb-2">
                <input class="form-check-input" type="checkbox" id="terms_agree">
                <label class="form-check-label small text-muted" for="terms_agree">
                    J'accepte de recevoir des notifications par email <strong>uniquement</strong> liées à l'opération St Valentin du CVL.
                    <a href="#" class="text-decoration-underline" onclick="alert('Page de mentions légales à venir.'); return false;">(Voir mentions légales)</a>
                </label>
            </div>
        </div>
    </div>

    <div class="d-grid gap-2">
        <button id="btn-validate-order" class="btn btn-success btn-lg" disabled>✅ Valider la commande</button>
    </div>
</div>

<div class="modal fade" id="addRecipientModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title" id="modalTitleLabel">Nouveau Destinataire</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="recipientForm">
                    <div class="p-3 mb-4 bg-danger-subtle rounded border border-danger-subtle">
                        <label class="form-label fw-bold text-danger">1. D'abord, recherchez l'élève :</label>
                        <div class="position-relative">
                            <div class="input-group">
                                <span class="input-group-text bg-white text-danger"><i class="fa fa-search"></i></span>
                                <input type="text" class="form-control form-control-lg" id="search_student" placeholder="Tapez un nom (ex: Dupont)..." autocomplete="off">
                                <button class="btn btn-outline-secondary" type="button" id="btn-reset-search" style="display:none;" onclick="fullResetSearch()">✖</button>
                            </div>
                            <div id="searchResults" class="search-results"></div>
                        </div>
                        <small class="text-muted fst-italic">Sélectionnez la personne dans la liste qui s'affiche.</small>
                    </div>

                    <input type="hidden" id="dest_schedule_id" value=""> 

                    <div class="mb-3">
                        <h6 class="fw-bold text-secondary border-bottom pb-2 mb-3">2. Informations du destinataire</h6>
                        <div class="row g-2">
                            <div class="col-md-5">
                                <label class="small text-muted">Nom</label>
                                <input type="text" class="form-control manual-field" id="dest_nom" required placeholder="Recherchez ci-dessus d'abord">
                            </div>
                            <div class="col-md-5">
                                <label class="small text-muted">Prénom</label>
                                <input type="text" class="form-control manual-field" id="dest_prenom" required placeholder="...">
                            </div>
                            <div class="col-md-2">
                                <label class="small text-muted">Classe</label>
                                <select id="dest_classe" class="form-select" required>
                                    <option value="">?</option>
                                    <?php foreach($classes as $c): ?>
                                        <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div id="manual-mode-hint" class="text-muted small mt-2" style="display:none;">
                            ⚠️ <em>Personne introuvable ? Vous pouvez saisir le nom/prénom manuellement ci-dessus.</em>
                        </div>
                    </div>

                    <hr>

                    <div id="scheduleSection">
                        <h6 class="mb-2 fw-bold text-primary">📍 Où trouver cette personne ?</h6>
                        <div class="alert alert-warning py-1 small" id="manualScheduleAlert">
                            <i class="fa fa-info-circle"></i> Cette personne n'est pas dans le fichier automatique.<br>
                            Merci d'indiquer manuellement une salle (Matin ou Soir).
                        </div>
                        <div class="row g-2 bg-light p-2 rounded border mb-3">
                            <?php foreach($timeSlots as $hour): ?>
                            <div class="col-md-6 schedule-row d-flex align-items-center">
                                <span class="fw-bold me-2" style="width: 40px;"><?php echo $hour.'h'; ?></span>
                                <select class="form-select form-select-sm schedule-input" data-hour="<?php echo $hour; ?>">
                                    <option value="">-</option>
                                    <?php foreach($rooms as $r): ?>
                                        <option value="<?php echo $r['id']; ?>"><?php echo htmlspecialchars($r['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <div id="autoScheduleMsg" style="display:none;" class="alert alert-success">
                        ✅ <strong>Emploi du temps synchronisé !</strong><br>
                        Nous savons où livrer la rose.
                    </div>

                    <hr>
                    
                    <h6 class="mb-3">💐 Fleurs & Message</h6>
                    <div class="alert alert-info py-1 small mb-2 d-flex justify-content-between align-items-center">
                         <span>💰 Ajoutez des roses pour voir le prix total.</span>
                    </div>

                    <?php foreach ($roses as $rose): ?>
                    <div class="row align-items-center mb-2">
                        <div class="col-7"><strong><?php echo htmlspecialchars($rose['name']); ?></strong></div>
                        <div class="col-5">
                            <input type="number" class="form-control rose-input" 
                                   data-id="<?php echo $rose['id']; ?>" 
                                   data-name="<?php echo htmlspecialchars($rose['name']); ?>" 
                                   value="0" min="0" oninput="updateLivePrice()">
                        </div>
                    </div>
                    <?php endforeach; ?>

                    <div class="mb-2 mt-3">
                        <select class="form-select form-select-sm" id="dest_message">
                            <option value="">(Choisir un message prédéfini...)</option>
                            <?php foreach ($messages as $msg): ?>
                                <option value="<?php echo $msg['id']; ?>"><?php echo htmlspecialchars($msg['content']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" id="dest_anonyme">
                        <label class="form-check-label small" for="dest_anonyme">🕵️ Offrir anonymement</label>
                    </div>
                </form>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                <button type="button" class="btn btn-danger" id="btn-save-recipient" onclick="addRecipientToCart()">Ajouter</button>
            </div>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const classesMap = <?php 
        $map = []; foreach($classes as $c) $map[$c['id']] = $c['name'];
        echo json_encode($map); 
    ?>;
    const roomsMap = <?php 
        $map = []; foreach($rooms as $r) $map[$r['id']] = $r['name'];
        echo json_encode($map); 
    ?>;
</script>
<script src="assets/js/main.js?v=<?php echo time(); ?>"></script>
</body>
</html>