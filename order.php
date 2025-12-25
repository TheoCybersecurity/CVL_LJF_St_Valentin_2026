<?php
session_start();
require_once 'db.php';

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
$roses = $pdo->query("SELECT * FROM rose_products WHERE is_active = 1")->fetchAll();
$messages = $pdo->query("SELECT * FROM predefined_messages")->fetchAll();
$classes = $pdo->query("SELECT * FROM classes ORDER BY name ASC")->fetchAll();
$rooms = $pdo->query("SELECT * FROM rooms ORDER BY name ASC")->fetchAll();
$timeSlots = range(8, 17); 
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nouvelle Commande - St Valentin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
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
            <button type="button" class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#addRecipientModal">+ Ajouter</button>
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
                <h5 class="modal-title">Nouveau Destinataire</h5>
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
                    <?php foreach ($roses as $rose): ?>
                    <div class="row align-items-center mb-2">
                        <div class="col-6"><strong><?php echo htmlspecialchars($rose['name']); ?></strong> (<?php echo number_format($rose['price'], 2); ?> €)</div>
                        <div class="col-4"><input type="number" class="form-control rose-input" data-id="<?php echo $rose['id']; ?>" data-name="<?php echo htmlspecialchars($rose['name']); ?>" data-price="<?php echo $rose['price']; ?>" value="0" min="0"></div>
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
                <button type="button" class="btn btn-danger" onclick="addRecipientToCart()">Ajouter</button>
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