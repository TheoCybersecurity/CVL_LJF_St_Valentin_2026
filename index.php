<?php
// index.php
session_start();
require_once 'db.php';
require_once 'auth_check.php'; 

// --- VERIFICATION AUTH ---
$is_logged_in = false;
$user_info = null;

if (isset($_COOKIE['jwt'])) {
    $is_logged_in = true;
    $stmt = $pdo->prepare("SELECT * FROM project_users WHERE user_id = ?");
    $stmt->execute([$current_user_id]);
    $user_info = $stmt->fetch();
} else {
    header("Location: welcome.php");
    exit;
}

// --- RECUPERATION DES COMMANDES ---
// On utilise SUM pour compter combien de destinataires ont passé chaque étape
$sql = "
    SELECT 
        o.*, 
        COUNT(r.id) as total_recipients,
        SUM(r.is_prepared) as total_prepared,
        SUM(r.is_distributed) as total_distributed
    FROM orders o
    LEFT JOIN order_recipients r ON o.id = r.order_id
    WHERE o.user_id = ? 
    GROUP BY o.id 
    ORDER BY o.created_at DESC
";
$stmt = $pdo->prepare($sql);
$stmt->execute([$current_user_id]);
$orders = $stmt->fetchAll();

// Vérification de l'ouverture des ventes
$stmt = $pdo->prepare("SELECT setting_value FROM global_settings WHERE setting_key = 'sales_open'");
$stmt->execute();
$isSalesOpen = $stmt->fetchColumn() == '1';
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mes Commandes - St Valentin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css?v=<?php echo time(); ?>" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-light">

<?php include 'navbar.php'; ?>

<div class="container py-5">
    <div class="row mb-4 align-items-center">
        <div class="col-md-8">
            <h1 class="display-6 fw-bold text-dark">Mon Tableau de Bord</h1>
            <p class="text-muted">Suivez l'état de vos commandes en temps réel.</p>
        </div>
        <div class="col-md-4 text-end">
            <?php if ($isSalesOpen): ?>
                <a href="order.php" class="btn btn-danger btn-lg shadow rounded-pill px-4">
                    <i class="fas fa-heart me-2"></i>Nouvelle commande
                </a>
            <?php else: ?>
                <button class="btn btn-secondary btn-lg shadow rounded-pill px-4" disabled>
                    <i class="fas fa-lock me-2"></i>Ventes terminées
                </button>
            <?php endif; ?>
        </div>
    </div>

    <?php if (empty($orders)): ?>
        <div class="card shadow-sm border-0 text-center py-5">
            <div class="card-body">
                <div class="mb-3 text-muted display-1"><i class="fas fa-box-open"></i></div>
                
                <?php if ($isSalesOpen): ?>
                    <h4>Vous n'avez passé aucune commande.</h4>
                    <p class="text-muted">La Saint-Valentin approche !</p>
                    <a href="order.php" class="btn btn-primary mt-3 rounded-pill px-4">Commencer</a>
                <?php else: ?>
                    <h4>Les ventes sont momentanément fermées.</h4>
                    <p class="text-muted">Il n'est plus possible de passer de nouvelles commandes.</p>
                    <button class="btn btn-secondary mt-3 rounded-pill px-4" disabled>Ventes terminées</button>
                <?php endif; ?>
                
            </div>
        </div>
    <?php else: ?>
        <div class="card shadow-sm border-0 overflow-hidden" style="border-radius: 15px;">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th class="ps-4 py-3 text-secondary text-uppercase small fw-bold">Commande</th>
                            <th class="py-3 text-secondary text-uppercase small fw-bold">Date</th>
                            <th class="py-3 text-secondary text-uppercase small fw-bold text-center">Qté</th>
                            <th class="py-3 text-secondary text-uppercase small fw-bold">Montant</th>
                            <th class="py-3 text-secondary text-uppercase small fw-bold">État Global</th>
                            <th class="py-3 text-secondary text-uppercase small fw-bold text-end pe-4">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orders as $order): ?>
                            <?php
                                // Calcul du statut global
                                $statusBadge = '';
                                if (!$order['is_paid']) {
                                    $statusBadge = '<span class="badge bg-warning text-dark"><i class="fas fa-clock"></i> En attente paiement</span>';
                                } elseif ($order['total_distributed'] == $order['total_recipients'] && $order['total_recipients'] > 0) {
                                    $statusBadge = '<span class="badge bg-success"><i class="fas fa-check-circle"></i> Tout Livré</span>';
                                } elseif ($order['total_distributed'] > 0) {
                                    $statusBadge = '<span class="badge bg-info text-dark"><i class="fas fa-truck"></i> En cours (' . $order['total_distributed'] . '/' . $order['total_recipients'] . ')</span>';
                                } elseif ($order['total_prepared'] == $order['total_recipients']) {
                                    $statusBadge = '<span class="badge bg-primary"><i class="fas fa-gift"></i> Prêt à livrer</span>';
                                } else {
                                    $statusBadge = '<span class="badge bg-secondary"><i class="fas fa-check"></i> Validé</span>';
                                }
                            ?>
                            <tr>
                                <td class="ps-4 fw-bold text-dark">#<?php echo str_pad($order['id'], 4, '0', STR_PAD_LEFT); ?></td>
                                <td class="text-muted"><?php echo date('d/m/Y', strtotime($order['created_at'])); ?></td>
                                <td class="text-center">
                                    <span class="badge bg-light text-dark border rounded-pill">
                                        <?php echo $order['total_recipients']; ?> <i class="fas fa-user small"></i>
                                    </span>
                                </td>
                                <td class="fw-bold text-success"><?php echo number_format($order['total_price'], 2); ?> €</td>
                                <td><?php echo $statusBadge; ?></td>
                                <td class="text-end pe-4">
                                    <button class="btn-details small" onclick="showOrderDetails(<?php echo $order['id']; ?>)">
                                        <i class="fas fa-eye me-1"></i> Voir
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<div class="modal fade" id="orderDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header border-bottom-0 pb-0">
                <h5 class="modal-title fw-bold">Commande #<span id="modal-order-id"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <div class="modal-body pt-2" id="modal-order-content">
                <div class="text-center py-5">
                    <div class="spinner-border text-danger" role="status"></div>
                </div>
            </div>
            <div class="modal-footer border-top-0">
                <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Fermer</button>
            </div>
        </div>
    </div>
</div>

<script>
/**
 * Formate une date string en "14/02/2024 à 10:30"
 */
function formatDateFR(dateString) {
    if (!dateString) return '--';
    const date = new Date(dateString);
    return date.toLocaleDateString('fr-FR', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric'
    }) + ' à ' + date.toLocaleTimeString('fr-FR', {
        hour: '2-digit',
        minute: '2-digit'
    });
}

/**
 * Formate en "14/02 10:30" (Pour la Timeline)
 */
function formatDateTimeShort(dateString) {
    if (!dateString) return '';
    const date = new Date(dateString);
    return date.toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit' }) + 
           ' ' + 
           date.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
}

function showOrderDetails(orderId) {
    const modalContent = document.getElementById('modal-order-content');
    const modalOrderId = document.getElementById('modal-order-id');
    
    modalOrderId.innerText = String(orderId).padStart(4, '0');
    modalContent.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-danger" role="status"></div><br><span class="text-muted mt-2 d-block">Chargement des informations...</span></div>';
    
    // Ouverture Modale Bootstrap
    const modalElement = document.getElementById('orderDetailsModal');
    if (modalElement) {
        let modalInstance = bootstrap.Modal.getInstance(modalElement);
        if (!modalInstance) {
            modalInstance = new bootstrap.Modal(modalElement);
        }
        if (!modalElement.classList.contains('show')) {
            modalInstance.show();
        }
    }

    // Appel API
    fetch(`api/get_order_details?id=${orderId}`)
    .then(response => {
        if (!response.ok) { throw new Error("Erreur HTTP " + response.status); }
        return response.json();
    })
    .then(data => {
        if (!data.success) {
            modalContent.innerHTML = `<div class="alert alert-danger rounded-3"><i class="fas fa-exclamation-triangle me-2"></i> ${data.error}</div>`;
            return;
        }

        // --- EN-TÊTE ---
        const isPaid = (data.order.is_paid == 1);
        const labelTotal = isPaid ? "Total Payé" : "Total à Payer";
        const colorTotal = isPaid ? "text-success" : "text-danger";

        let html = `
            <div class="alert alert-light border d-flex justify-content-between align-items-center mb-4 rounded-3 shadow-sm">
                <div>
                    <div class="text-muted small text-uppercase fw-bold">Date de commande</div>
                    <div class="fw-bold text-dark">${formatDateFR(data.order.created_at)}</div>
                </div>
                <div class="text-end">
                    <div class="text-muted small text-uppercase fw-bold">${labelTotal}</div>
                    <div class="${colorTotal} fs-4 fw-bold">${parseFloat(data.order.total_price).toFixed(2)} €</div>
                </div>
            </div>
            
            <h6 class="fw-bold mb-3 text-secondary"><i class="fas fa-users me-2"></i>Destinataires</h6>
            <div class="d-flex flex-column gap-3">
        `;

        // --- BOUCLE DESTINATAIRES ---
        data.recipients.forEach(dest => {
            
            // Roses Badges
            let rosesHtml = dest.roses.map(r => {
                let nameLower = r.name.toLowerCase();
                let badgeClass = "bg-secondary text-white";
                let emoji = "🌹";
                
                if (nameLower.includes('rouge')) { badgeClass = "badge-rose-rouge"; emoji = "🌹"; }
                else if (nameLower.includes('blanche')) { badgeClass = "badge-rose-blanche"; emoji = "🤍"; }
                else if (nameLower.includes('rose')) { badgeClass = "badge-rose-pink"; emoji = "🌸"; }

                return `<span class="badge rounded-pill ${badgeClass} me-1 p-2 border">
                            <span class="fw-bold">${r.quantity}</span> ${emoji} 
                            <span class="d-none d-sm-inline ms-1">${r.name}</span>
                        </span>`;
            }).join('');

            // Timeline Logic
            const isPrepared = (dest.is_prepared == 1);
            const isDistributed = (dest.is_distributed == 1);

            const step1Class = "completed";
            const step2Class = isPaid ? "completed" : "";
            const step3Class = isPrepared || isDistributed ? "completed" : "";
            const step4Class = isDistributed ? "completed" : "";

            const timeOrdered = formatDateTimeShort(data.order.created_at);
            const timePaid = isPaid ? "Validé" : "--"; 
            const timePrepared = (isPrepared && dest.prepared_at) ? formatDateTimeShort(dest.prepared_at) : "";
            const timeDistributed = (isDistributed && dest.distributed_at) ? formatDateTimeShort(dest.distributed_at) : "";

            let timelineHtml = `
                <div class="stepper-wrapper">
                    <div class="stepper-item ${step1Class}">
                        <div class="step-counter"><i class="fas fa-shopping-cart small"></i></div>
                        <div class="step-name">Commandé<br><span class="small text-muted fw-normal">${timeOrdered}</span></div>
                    </div>
                    <div class="stepper-item ${step2Class}">
                        <div class="step-counter"><i class="fas fa-credit-card small"></i></div>
                        <div class="step-name">Payé<br><span class="small text-muted fw-normal">${timePaid}</span></div>
                    </div>
                    <div class="stepper-item ${step3Class}">
                        <div class="step-counter"><i class="fas fa-box small"></i></div>
                        <div class="step-name">Préparé<br><span class="small text-muted fw-normal">${timePrepared}</span></div>
                    </div>
                    <div class="stepper-item ${step4Class}">
                        <div class="step-counter"><i class="fas fa-check small"></i></div>
                        <div class="step-name">Livré<br><span class="small text-muted fw-normal">${timeDistributed}</span></div>
                    </div>
                </div>
            `;

            // Data Adaptation (New DB Structure)
            // L'API renvoie maintenant "nom" et "prenom" (pas dest_nom)
            let fullName = `${dest.prenom} ${dest.nom}`;
            let className = dest.class_name ? dest.class_name : "Classe inconnue";
            let anonBadge = dest.is_anonymous == 1 ? '<span class="badge bg-dark ms-2"><i class="fas fa-user-secret"></i> Anonyme</span>' : '';

            html += `
                <div class="card border-0 shadow-sm bg-white rounded-3">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-3">
                            <div>
                                <h5 class="mb-1 fw-bold text-dark">
                                    ${fullName}
                                    ${anonBadge}
                                </h5>
                                <div class="badge bg-light text-secondary border">${className}</div>
                            </div>
                            <div class="text-end">
                                ${rosesHtml}
                            </div>
                        </div>

                        ${timelineHtml}

                        ${dest.message_text ? 
                            `<div class="bg-light p-3 rounded-3 mt-3">
                                <p class="mb-0 small fst-italic text-muted"><i class="fas fa-quote-left me-2 text-primary"></i>${dest.message_text}</p>
                             </div>` : ''
                        }
                    </div>
                </div>
            `;
        });

        html += '</div>';
        modalContent.innerHTML = html;
    })
    .catch(err => {
        console.error(err);
        modalContent.innerHTML = '<div class="alert alert-danger">Impossible de charger les détails.</div>';
    });
}
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php include 'toast_notifications.php'; ?>
</body>
</html>