<?php
session_start();
require_once 'db.php';

// --- VERIFICATION AUTH ---
$is_logged_in = false;
$user_info = null;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// On vérifie le cookie JWT
if (isset($_COOKIE['jwt'])) {
    try {
        require_once 'auth_check.php';
        $is_logged_in = true;
        // Récup infos
        $stmt = $pdo->prepare("SELECT * FROM project_users WHERE user_id = ?");
        $stmt->execute([$current_user_id]);
        $user_info = $stmt->fetch();
    } catch (Exception $e) {
        $is_logged_in = false;
        session_unset();
        session_destroy();
    }
} else {
    $is_logged_in = false;

    if (isset($_SESSION['user_id']) || isset($_SESSION['prenom'])) {
        session_unset();
        session_destroy();
        session_start(); 
    }
}

// Si pas connecté -> Redirection vers l'accueil pour choisir (Connexion ou Invité)
if (!$is_logged_in) {
    header("Location: welcome.php");
    exit;
}

// --- RECUPERATION DES COMMANDES ---
// On récupère les commandes + le nombre de destinataires pour chaque commande
$sql = "
    SELECT o.*, COUNT(r.id) as total_recipients 
    FROM orders o
    LEFT JOIN order_recipients r ON o.id = r.order_id
    WHERE o.user_id = ? 
    GROUP BY o.id 
    ORDER BY o.created_at DESC
";
$stmt = $pdo->prepare($sql);
$stmt->execute([$current_user_id]);
$orders = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mes Commandes - St Valentin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body class="bg-light">

<?php include 'navbar.php'; ?>

<div class="container py-5">
    <div class="row mb-4">
        <div class="col-md-8">
            <h1 class="display-6">Mon Tableau de Bord</h1>
            <p class="text-muted">Retrouvez ici l'historique de vos achats.</p>
        </div>
        <div class="col-md-4 text-end">
            <a href="order.php" class="btn btn-danger btn-lg shadow">
                + Nouvelle commande
            </a>
        </div>
    </div>

    <?php if (empty($orders)): ?>
        <div class="alert alert-info text-center py-5">
            <h4>Vous n'avez passé aucune commande pour le moment.</h4>
            <p>C'est le moment de faire plaisir à vos proches !</p>
            <a href="order.php" class="btn btn-primary mt-3">Commencer</a>
        </div>
    <?php else: ?>
        <div class="card shadow-sm border-0">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-4">N° Commande</th>
                                <th>Date</th>
                                <th>Destinataires</th>
                                <th>Montant</th>
                                <th>Statut</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($orders as $order): ?>
                                <tr>
                                    <td class="ps-4 fw-bold">#<?php echo str_pad($order['id'], 4, '0', STR_PAD_LEFT); ?></td>
                                    <td><?php echo date('d/m/Y à H:i', strtotime($order['created_at'])); ?></td>
                                    <td>
                                        <span class="badge bg-secondary rounded-pill">
                                            <?php echo $order['total_recipients']; ?> personne(s)
                                        </span>
                                    </td>
                                    <td class="fw-bold text-success"><?php echo number_format($order['total_price'], 2); ?> €</td>
                                    <td>
                                        <?php if ($order['is_paid']): ?>
                                            <span class="badge bg-success">Payé ✅</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning text-dark">En attente ⏳</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <button class="btn btn-sm btn-info text-white" onclick="showOrderDetails(<?php echo $order['id']; ?>)">
                                            ℹ️ Détails
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<div class="modal fade" id="orderDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Détails de la commande #<span id="modal-order-id"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <div class="modal-body" id="modal-order-content">
                <div class="text-center"><div class="spinner-border" role="status"></div></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fermer</button>
            </div>
        </div>
    </div>
</div>

<script>
function showOrderDetails(orderId) {
    const modalContent = document.getElementById('modal-order-content');
    const modalOrderId = document.getElementById('modal-order-id');
    
    // 1. Ouvrir la modale et afficher un chargement
    modalOrderId.innerText = orderId;
    modalContent.innerHTML = '<div class="text-center py-3"><div class="spinner-border text-danger" role="status"></div><br>Chargement...</div>';
    
    const myModal = new bootstrap.Modal(document.getElementById('orderDetailsModal'));
    myModal.show();

    // 2. Appel API
    fetch(`api/get_order_details?id=${orderId}`)
    .then(response => response.json())
    .then(data => {
        if (!data.success) {
            modalContent.innerHTML = `<div class="alert alert-danger">${data.error}</div>`;
            return;
        }

        // 3. Construction du HTML
        let html = `
            <div class="d-flex justify-content-between mb-3 border-bottom pb-2">
                <strong>Date: ${data.order.created_at}</strong>
                <strong class="text-danger fs-5">Total: ${parseFloat(data.order.total_price).toFixed(2)} €</strong>
            </div>
            <h5>Destinataires (${data.recipients.length}) :</h5>
            <div class="list-group">
        `;

        data.recipients.forEach(dest => {
            // Liste des roses
            let rosesHtml = dest.roses.map(r => 
                `<span class="badge bg-danger me-1">${r.quantity} x ${r.name}</span>`
            ).join('');

            // Liste planning
            let scheduleHtml = dest.schedule.map(s => 
                `<small class="d-block text-muted">🕒 ${s.hour_slot}h - ${parseInt(s.hour_slot)+1}h : ${s.room_name}</small>`
            ).join('');

            // Badge anonyme
            let anonBadge = dest.is_anonymous == 1 ? '<span class="badge bg-dark ms-2">Anonyme</span>' : '';

            html += `
                <div class="list-group-item">
                    <div class="d-flex w-100 justify-content-between">
                        <h6 class="mb-1 fw-bold">
                            ${dest.dest_prenom} ${dest.dest_nom} <small class="text-muted">(${dest.class_id})</small>
                            ${anonBadge}
                        </h6>
                    </div>
                    <div class="mb-2">${rosesHtml}</div>
                    <p class="mb-1 small fst-italic">✉️ Message : "${dest.message_text || 'Aucun message'}"</p>
                    <div class="mt-2 border-top pt-1">
                        <strong>📍 Localisation :</strong>
                        ${scheduleHtml || '<span class="text-danger small">Aucune salle définie</span>'}
                    </div>
                </div>
            `;
        });

        html += '</div>'; // Fin list-group
        modalContent.innerHTML = html;
    })
    .catch(err => {
        console.error(err);
        modalContent.innerHTML = '<div class="alert alert-danger">Erreur technique lors du chargement.</div>';
    });
}
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>