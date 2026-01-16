<?php
// logs.php
require_once 'db.php';
require_once 'auth_check.php'; 

checkAccess('admin');

$view = $_GET['view'] ?? 'global'; // 'global' ou 'timeline'

// --- REQUÊTES ---

if ($view === 'global') {
    // VUE 1 : Tableau récapitulatif
    // (Cette partie était déjà presque correcte, je l'ai gardée propre)
    $sql = "
        SELECT 
            o.id as order_id, o.created_at, 
            -- On récupère les infos de l'acheteur via la table users
            u_buyer.nom as buyer_nom, u_buyer.prenom as buyer_prenom,
            
            o.is_paid, o.paid_at, o.paid_by_cvl_id,
            
            (SELECT COUNT(*) FROM order_recipients WHERE order_id = o.id) as nb_dest,
            (SELECT COUNT(*) FROM order_recipients WHERE order_id = o.id AND is_distributed=1) as nb_distrib,
            
            u_pay.prenom as pay_admin_prenom,
            u_pay.nom as pay_admin_nom
            
        FROM orders o
        -- JOINTURE OBLIGATOIRE POUR L'ACHETEUR
        JOIN users u_buyer ON o.user_id = u_buyer.user_id
        
        -- JOINTURE POUR L'ADMIN ENCAISSEUR
        LEFT JOIN users u_pay ON o.paid_by_cvl_id = u_pay.user_id
        
        ORDER BY o.created_at DESC
        LIMIT 100
    ";
    $logs = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

} else {
    // VUE 2 : Timeline Chronologique COMPLÈTE
    // CORRECTIONS :
    // 1. Ajout JOIN users pour la création de commande
    // 2. Remplacement de 'project_users' par 'users'
    
    $sql = "
        /* 1. CRÉATION DE COMMANDE */
        (SELECT 
            o.created_at as event_date, 
            'creation' as type, 
            CONCAT('Commande #', o.id, ' créée') as description,
            CONCAT(u.prenom, ' ', u.nom) as actor,  -- CORRIGÉ ICI
            o.id as ref_id
         FROM orders o
         JOIN users u ON o.user_id = u.user_id      -- AJOUT JOINTURE ICI
        )
         
        UNION
        
        /* 2. PAIEMENT */
        (SELECT 
            o.paid_at as event_date, 
            'payment' as type, 
            CONCAT('Paiement commande #', o.id) as description,
            IF(u.user_id IS NOT NULL, CONCAT(u.prenom, ' ', u.nom), CONCAT('Admin ID: ', o.paid_by_cvl_id)) as actor, 
            o.id as ref_id
         FROM orders o
         LEFT JOIN users u ON o.paid_by_cvl_id = u.user_id -- CORRECTION TABLE users
         WHERE o.is_paid = 1)

        UNION

        /* 3. PRÉPARATION */
        (SELECT 
            ort.prepared_at as event_date, 
            'preparation' as type, 
            CONCAT('Préparation rose pour ', r.prenom, ' ', r.nom) as description,
            'Staff / CVL' as actor, 
            ort.order_id as ref_id
         FROM order_recipients ort
         JOIN recipients r ON ort.recipient_id = r.id
         WHERE ort.is_prepared = 1)
         
        UNION
        
        /* 4. DISTRIBUTION */
        (SELECT 
            ort.distributed_at as event_date, 
            'distribution' as type, 
            CONCAT('Livré à ', r.prenom, ' ', r.nom) as description,
            IF(u.user_id IS NOT NULL, CONCAT(u.prenom, ' ', u.nom), CONCAT('Admin ID: ', ort.distributed_by_cvl_id)) as actor,
            ort.order_id as ref_id
         FROM order_recipients ort
         JOIN recipients r ON ort.recipient_id = r.id
         LEFT JOIN users u ON ort.distributed_by_cvl_id = u.user_id -- CORRECTION TABLE users
         WHERE ort.is_distributed = 1)
        
        ORDER BY event_date DESC
        LIMIT 200
    ";
    
    $timeline = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logs & Audit</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body { background-color: #f4f6f9; }
        .timeline-icon { width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; border-radius: 50%; color: white; font-size: 1.1rem; }
        /* Couleurs des étapes */
        .bg-creation { background-color: #0d6efd; } /* Bleu */
        .bg-payment { background-color: #198754; }  /* Vert */
        .bg-preparation { background-color: #fd7e14; } /* Orange */
        .bg-distribution { background-color: #dc3545; } /* Rouge/Rose */
    </style>
</head>
<body>

<?php include 'navbar.php'; ?>

<div class="container mt-4 mb-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="fw-bold mt-2"><i class="fas fa-clipboard-list"></i> Journal des Opérations</h2>
        </div>
        <div class="btn-group">
            <a href="?view=global" class="btn <?php echo $view === 'global' ? 'btn-dark' : 'btn-outline-dark'; ?>">Vue Globale</a>
            <a href="?view=timeline" class="btn <?php echo $view === 'timeline' ? 'btn-dark' : 'btn-outline-dark'; ?>">Chronologie</a>
        </div>
    </div>

    <?php if ($view === 'global'): ?>
        <div class="card shadow-sm border-0">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th>ID</th>
                                <th>Date Création</th>
                                <th>Acheteur</th>
                                <th>État Paiement</th>
                                <th>Encaissé par</th>
                                <th>Distribution</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($logs as $log): ?>
                            <tr>
                                <td class="fw-bold">#<?php echo $log['order_id']; ?></td>
                                <td class="small text-muted"><?php echo date('d/m H:i', strtotime($log['created_at'])); ?></td>
                                <td><?php echo htmlspecialchars($log['buyer_prenom'] . ' ' . $log['buyer_nom']); ?></td>
                                
                                <td>
                                    <?php if($log['is_paid']): ?>
                                        <span class="badge bg-success">Payé</span>
                                        <div class="small text-muted"><?php echo date('d/m H:i', strtotime($log['paid_at'])); ?></div>
                                    <?php else: ?>
                                        <span class="badge bg-warning text-dark">En attente</span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?php if($log['is_paid']): ?>
                                        <?php if($log['pay_admin_prenom']): ?>
                                            <span class="fw-bold text-primary"><?php echo htmlspecialchars($log['pay_admin_prenom']); ?></span>
                                        <?php else: ?>
                                            <span class="text-muted small">ID: <?php echo $log['paid_by_cvl_id']; ?></span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?php 
                                        $percent = ($log['nb_dest'] > 0) ? ($log['nb_distrib'] / $log['nb_dest']) * 100 : 0;
                                        $color = ($percent == 100) ? 'bg-success' : 'bg-warning';
                                    ?>
                                    <div class="progress" style="height: 6px; width: 100px;">
                                        <div class="progress-bar <?php echo $color; ?>" role="progressbar" style="width: <?php echo $percent; ?>%"></div>
                                    </div>
                                    <small class="text-muted"><?php echo $log['nb_distrib']; ?>/<?php echo $log['nb_dest']; ?> livrés</small>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    <?php else: ?>
        <div class="row justify-content-center">
            <div class="col-md-8">
                <ul class="list-group shadow-sm">
                    <?php foreach($timeline as $event): ?>
                        <?php 
                            $icon = 'fa-circle';
                            $bgClass = 'bg-secondary';
                            
                            if ($event['type'] === 'creation') { 
                                $icon = 'fa-cart-plus'; 
                                $bgClass = 'bg-creation'; 
                            }
                            if ($event['type'] === 'payment') { 
                                $icon = 'fa-check'; 
                                $bgClass = 'bg-payment'; 
                            }
                            if ($event['type'] === 'preparation') { 
                                $icon = 'fa-box-open'; 
                                $bgClass = 'bg-preparation'; 
                            }
                            if ($event['type'] === 'distribution') { 
                                $icon = 'fa-paper-plane'; 
                                $bgClass = 'bg-distribution'; 
                            }
                        ?>
                        <li class="list-group-item d-flex align-items-center py-3">
                            <div class="timeline-icon <?php echo $bgClass; ?> me-3 flex-shrink-0">
                                <i class="fas <?php echo $icon; ?>"></i>
                            </div>
                            <div class="flex-grow-1">
                                <div class="d-flex justify-content-between">
                                    <span class="fw-bold"><?php echo htmlspecialchars($event['description'] ?? ''); ?></span>
                                    <small class="text-muted"><?php echo ($event['event_date']) ? date('d/m à H:i:s', strtotime($event['event_date'])) : '--:--'; ?></small>
                                </div>
                                <div class="small text-muted">
                                    Par : <?php echo htmlspecialchars($event['actor'] ?? 'Inconnu'); ?>
                                </div>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    <?php endif; ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>