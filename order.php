<?php
// order.php
session_start();
require_once 'db.php';

// ====================================================
// 1. SÉCURITÉ : VÉRIFIER SI LES VENTES SONT OUVERTES
// ====================================================
$stmt = $pdo->prepare("SELECT setting_value FROM global_settings WHERE setting_key = 'sales_open'");
$stmt->execute();
// Si la ligne n'existe pas ou n'est pas à 1, c'est fermé.
$isSalesOpen = $stmt->fetchColumn() == '1';

if (!$isSalesOpen) {
    // Si c'est fermé, on redirige vers l'accueil (qui affichera le bouton grisé)
    header("Location: index.php");
    exit;
}
// ====================================================

// --- LOGIQUE D'AUTHENTIFICATION ---
$is_logged_in = false;
$user_info = null;

// 1. Vérif Cookie JWT
if (isset($_COOKIE['jwt'])) {
    try {
        require_once 'auth_check.php'; 
        $is_logged_in = true;
        $_SESSION['user_id'] = $current_user_id;

        $stmt = $pdo->prepare("SELECT * FROM project_users WHERE user_id = ?");
        $stmt->execute([$current_user_id]);
        $user_info = $stmt->fetch();
        
        if (!$user_info) { header("Location: setup.php"); exit; }
    } catch (Exception $e) { $is_logged_in = false; }
} 
// 2. Vérif Mode Invité
elseif (isset($_GET['guest']) && $_GET['guest'] == 1) {
    $_SESSION['is_guest'] = true;
}
// 3. Vérif Session existante
elseif (isset($_SESSION['is_guest'])) {
    // On laisse passer
}
// 4. Sinon -> Dehors
else {
    header("Location: welcome.php");
    exit;
}

// --- CHARGEMENT DES DONNÉES ---
$roses = $pdo->query("SELECT * FROM rose_products")->fetchAll();

// Chargement des prix dégressifs
$rosesPrices = $pdo->query("SELECT * FROM roses_prices ORDER BY quantity ASC")->fetchAll(PDO::FETCH_ASSOC);

// On transforme ça en JSON pour le JS plus tard
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
    // Tableau des prix généré par PHP pour le JS
    const ROSE_PRICES = <?php echo json_encode($jsPriceTable); ?>;
    
    // Fonction d'aide pour calculer le prix selon la quantité totale
    function getPriceForQuantity(qty) {
        qty = parseInt(qty);
        if (qty <= 0) return 0;
        
        // Si la quantité est dans la table (ex: 1, 2, 3...)
        if (ROSE_PRICES[qty]) {
            return parseFloat(ROSE_PRICES[qty]);
        } else {
            // Si la quantité dépasse notre grille (ex: 50 roses)
            // On prend le prix max connu + 2€ par rose supplémentaire (exemple de logique)
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

    <div class="d-grid gap-2">
        <button id="btn-validate-order" class="btn btn-success btn-lg" disabled>✅ Valider et Payer</button>
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
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label>Nom</label>
                            <input type="text" class="form-control" id="dest_nom" required>
                        </div>
                        <div class="col-md-4">
                            <label>Prénom</label>
                            <input type="text" class="form-control" id="dest_prenom" required>
                        </div>
                        <div class="col-md-4">
                            <label>Classe</label>
                            <select id="dest_classe" class="form-select" required>
                                <option value="">-- Choisir --</option>
                                <?php foreach($classes as $c): ?>
                                    <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <hr>
                    <h6 class="mb-2 fw-bold text-primary">📍 Où trouver cette personne le Vendredi 13 ?</h6>
                    <p class="small text-muted mb-3">Remplissez au moins un créneau (M = Matin, S = Soir).</p>
                    
                    <div class="row g-2 bg-light p-2 rounded border mb-3">
                        <?php foreach($timeSlots as $hour): ?>
                        <div class="col-md-6 schedule-row d-flex align-items-center">
                            <span class="fw-bold me-2" style="width: 80px;"><?php echo $hour.'h'; ?> :</span>
                            <select class="form-select form-select-sm schedule-input" data-hour="<?php echo $hour; ?>">
                                <option value="">(Pas de cours)</option>
                                <?php foreach($rooms as $r): ?>
                                    <option value="<?php echo $r['id']; ?>"><?php echo htmlspecialchars($r['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <hr>
                    <h6 class="mb-3">💐 Fleurs</h6>
                    
                    <div class="alert alert-info py-2 small mb-3">
                        <strong>💰 Tarifs : </strong>
                        <?php 
                        $displayPrices = [];
                        $count = 0;
                        foreach($rosesPrices as $rp) {
                            if ($count < 6) { // On affiche les 6 premiers tarifs pour l'exemple
                                $displayPrices[] = $rp['quantity'] . " rose(s) = " . number_format($rp['price'], 2) . "€";
                            }
                            $count++;
                        }
                        echo implode(' | ', $displayPrices);
                        if(count($rosesPrices) > 6) echo " ...";
                        ?>
                    </div>

                    <?php foreach ($roses as $rose): ?>
                    <div class="row align-items-center mb-2">
                        <div class="col-6">
                            <strong><?php echo htmlspecialchars($rose['name']); ?></strong>
                        </div>
                        <div class="col-4">
                            <input type="number" class="form-control rose-input" 
                                   data-id="<?php echo $rose['id']; ?>" 
                                   data-name="<?php echo htmlspecialchars($rose['name']); ?>" 
                                   value="0" min="0"
                                   oninput="updateLivePrice()">
                        </div>
                    </div>
                    <?php endforeach; ?>

                    <div class="mb-3 mt-3">
                        <label>💌 Message</label>
                        <select class="form-select" id="dest_message">
                            <option value="">(Aucun message)</option>
                            <?php foreach ($messages as $msg): ?>
                                <option value="<?php echo $msg['id']; ?>"><?php echo htmlspecialchars($msg['content']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" id="dest_anonyme">
                        <label class="form-check-label" for="dest_anonyme">🕵️ Offrir anonymement ?</label>
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