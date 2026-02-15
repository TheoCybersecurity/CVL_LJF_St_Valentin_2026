<?php
/**
 * Administration - Journal des Opérations Métier
 * logs.php
 * * Ce module permet de suivre l'activité "Business" de l'application.
 * Contrairement aux logs techniques (audit_logs.php), ici on s'intéresse aux événements
 * fonctionnels : Création de commande, Encaissement, Préparation, Distribution.
 *
 * Il propose deux vues :
 * 1. Global : Tableau récapitulatif des commandes.
 * 2. Timeline : Flux chronologique de tous les événements confondus.
 */

require_once 'db.php';
require_once 'auth_check.php'; 

// Accès réservé aux administrateurs
checkAccess('admin');

$view = $_GET['view'] ?? 'global'; // Mode d'affichage par défaut

// =================================================================
// RÉCUPÉRATION DES DONNÉES
// =================================================================

if ($view === 'global') {
    // --- VUE 1 : TABLEAU RÉCAPITULATIF DES COMMANDES ---
    // Affiche les commandes avec les infos acheteur, encaisseur et avancement
    $sql = "
        SELECT 
            o.id as order_id, 
            o.created_at, 
            o.is_paid, 
            o.paid_at, 
            o.paid_by_cvl_id,
            
            -- Infos Acheteur
            u_buyer.nom as buyer_nom, 
            u_buyer.prenom as buyer_prenom,
            
            -- Infos Encaisseur (Admin)
            u_pay.prenom as pay_admin_prenom,
            u_pay.nom as pay_admin_nom,

            -- Métriques d'avancement (Sous-requêtes pour compter les produits)
            (SELECT COUNT(*) FROM order_recipients WHERE order_id = o.id) as nb_dest,
            (SELECT COUNT(*) FROM order_recipients WHERE order_id = o.id AND is_distributed=1) as nb_distrib
            
        FROM orders o
        -- Jointure interne : Une commande a toujours un acheteur
        JOIN users u_buyer ON o.user_id = u_buyer.user_id
        
        -- Jointure gauche : Une commande n'est pas forcément payée/encaissée
        LEFT JOIN users u_pay ON o.paid_by_cvl_id = u_pay.user_id
        
        ORDER BY o.created_at DESC
        LIMIT 100
    ";
    $logs = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

} else {
    // --- VUE 2 : TIMELINE CHRONOLOGIQUE ---
    // Agrégation de 4 tables/événements différents via UNION ALL
    
    $sql = "
        /* 1. Événements : CRÉATION DE COMMANDE */
        SELECT 
            o.created_at as event_date, 
            'creation' as type, 
            CONCAT('Commande #', o.id, ' créée') as description,
            CONCAT(u.prenom, ' ', u.nom) as actor,
            o.id as ref_id
        FROM orders o
        JOIN users u ON o.user_id = u.user_id

        UNION ALL
        
        /* 2. Événements : PAIEMENT */
        SELECT 
            o.paid_at as event_date, 
            'payment' as type, 
            CONCAT('Paiement commande #', o.id) as description,
            -- Si l'admin encaisseur a été supprimé, on affiche son ID
            IF(u.user_id IS NOT NULL, CONCAT(u.prenom, ' ', u.nom), CONCAT('Admin ID: ', o.paid_by_cvl_id)) as actor, 
            o.id as ref_id
        FROM orders o
        LEFT JOIN users u ON o.paid_by_cvl_id = u.user_id
        WHERE o.is_paid = 1

        UNION ALL

        /* 3. Événements : PRÉPARATION */
        SELECT 
            ort.prepared_at as event_date, 
            'preparation' as type, 
            CONCAT('Préparation rose pour ', r.prenom, ' ', r.nom) as description,
            'Staff / CVL' as actor, -- Pas d'ID user stocké pour la prépa (Action anonyme staff)
            ort.order_id as ref_id
        FROM order_recipients ort
        JOIN recipients r ON ort.recipient_id = r.id
        WHERE ort.is_prepared = 1
         
        UNION ALL
        
        /* 4. Événements : DISTRIBUTION */
        SELECT 
            ort.distributed_at as event_date, 
            'distribution' as type, 
            CONCAT('Livré à ', r.prenom, ' ', r.nom) as description,
            IF(u.user_id IS NOT NULL, CONCAT(u.prenom, ' ', u.nom), CONCAT('Admin ID: ', ort.distributed_by_cvl_id)) as actor,
            ort.order_id as ref_id
        FROM order_recipients ort
        JOIN recipients r ON ort.recipient_id = r.id
        LEFT JOIN users u ON ort.distributed_by_cvl_id = u.user_id
        WHERE ort.is_distributed = 1
        
        -- Tri global par date décroissante
        ORDER BY event_date DESC
        LIMIT 200
    ";
    
    $timeline = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <title>Logs & Audit Métier</title>
    <?php include 'head_imports.php'; ?>
    <style>
        body { background-color: #f4f6f9; }
        
        /* Styles spécifiques à la Timeline */
        .timeline-list { padding-left: 0; list-style: none; }
        .timeline-item { position: relative; padding-left: 50px; margin-bottom: 20px; }
        
        /* Icône circulaire */
        .timeline-icon { 
            position: absolute; left: 0; top: 0;
            width: 40px; height: 40px; 
            display: flex; align-items: center; justify-content: center; 
            border-radius: 50%; color: white; font-size: 1.1rem; 
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        
        /* Code couleur sémantique */
        .bg-creation { background-color: #0d6efd; }      /* Bleu : Commande */
        .bg-payment { background-color: #198754; }       /* Vert : Argent */
        .bg-preparation { background-color: #fd7e14; }   /* Orange : Logistique */
        .bg-distribution { background-color: #dc3545; }  /* Rouge : Finalité */
        
        /* Barre de progression dans le tableau global */
        .progress-slim { height: 6px; width: 100px; border-radius: 3px; }
    </style>
</head>
<body>

<?php include 'navbar.php'; ?>

<div class="container mt-4 mb-5">
    
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="fw-bold mt-2"><i class="fas fa-clipboard-list me-2"></i>Journal des Opérations</h2>
            <p class="text-muted mb-0">Suivi de l'activité commerciale et logistique.</p>
        </div>
        <div class="btn-group shadow-sm">
            <a href="?view=global" class="btn <?php echo $view === 'global' ? 'btn-dark' : 'btn-outline-dark'; ?>">
                <i class="fas fa-table me-1"></i> Vue Globale
            </a>
            <a href="?view=timeline" class="btn <?php echo $view === 'timeline' ? 'btn-dark' : 'btn-outline-dark'; ?>">
                <i class="fas fa-stream me-1"></i> Chronologie
            </a>
            <a href="audit_logs.php" class="btn btn-outline-danger ms-2 border-start" title="Logs techniques">
                <i class="fas fa-shield-alt me-1"></i> Audit Technique
            </a>
        </div>
    </div>

    <?php if ($view === 'global'): ?>
        <div class="card shadow-sm border-0">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th class="ps-4">ID</th>
                                <th>Date Création</th>
                                <th>Acheteur</th>
                                <th>État Paiement</th>
                                <th>Encaissé par</th>
                                <th>Avancement Livraison</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($logs as $log): ?>
                            <tr>
                                <td class="ps-4 fw-bold">#<?php echo str_pad($log['order_id'], 4, '0', STR_PAD_LEFT); ?></td>
                                <td class="small text-muted"><?php echo date('d/m H:i', strtotime($log['created_at'])); ?></td>
                                
                                <td>
                                    <i class="fas fa-user-circle text-secondary me-1"></i>
                                    <?php echo htmlspecialchars($log['buyer_prenom'] . ' ' . $log['buyer_nom']); ?>
                                </td>
                                
                                <td>
                                    <?php if($log['is_paid']): ?>
                                        <span class="badge bg-success"><i class="fas fa-check me-1"></i> Payé</span>
                                        <div class="small text-muted mt-1"><?php echo date('d/m H:i', strtotime($log['paid_at'])); ?></div>
                                    <?php else: ?>
                                        <span class="badge bg-warning text-dark"><i class="fas fa-hourglass-half me-1"></i> En attente</span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?php if($log['is_paid']): ?>
                                        <?php if($log['pay_admin_prenom']): ?>
                                            <span class="fw-bold text-primary small"><?php echo htmlspecialchars($log['pay_admin_prenom']); ?></span>
                                        <?php else: ?>
                                            <span class="text-muted small fst-italic">ID: <?php echo $log['paid_by_cvl_id']; ?></span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?php 
                                        $percent = ($log['nb_dest'] > 0) ? ($log['nb_distrib'] / $log['nb_dest']) * 100 : 0;
                                        $color = ($percent == 100) ? 'bg-success' : 'bg-warning';
                                    ?>
                                    <div class="d-flex align-items-center">
                                        <div class="progress progress-slim me-2">
                                            <div class="progress-bar <?php echo $color; ?>" role="progressbar" style="width: <?php echo $percent; ?>%"></div>
                                        </div>
                                        <small class="text-muted"><?php echo $log['nb_distrib']; ?>/<?php echo $log['nb_dest']; ?></small>
                                    </div>
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
            <div class="col-lg-8">
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <ul class="timeline-list mb-0">
                            <?php foreach($timeline as $event): ?>
                                <?php 
                                    // Définition des icônes et couleurs selon le type d'événement
                                    $icon = 'fa-circle';
                                    $bgClass = 'bg-secondary';
                                    
                                    switch ($event['type']) {
                                        case 'creation':
                                            $icon = 'fa-cart-plus'; $bgClass = 'bg-creation'; break;
                                        case 'payment':
                                            $icon = 'fa-check'; $bgClass = 'bg-payment'; break;
                                        case 'preparation':
                                            $icon = 'fa-box-open'; $bgClass = 'bg-preparation'; break;
                                        case 'distribution':
                                            $icon = 'fa-paper-plane'; $bgClass = 'bg-distribution'; break;
                                    }
                                ?>
                                <li class="timeline-item">
                                    <div class="timeline-icon <?php echo $bgClass; ?>">
                                        <i class="fas <?php echo $icon; ?>"></i>
                                    </div>
                                    <div class="card border-light bg-light">
                                        <div class="card-body py-2 px-3">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <span class="fw-bold text-dark">
                                                    <?php echo htmlspecialchars($event['description'] ?? ''); ?>
                                                </span>
                                                <small class="text-muted font-monospace">
                                                    <?php echo ($event['event_date']) ? date('d/m H:i', strtotime($event['event_date'])) : '--:--'; ?>
                                                </small>
                                            </div>
                                            <div class="small text-muted mt-1">
                                                <i class="fas fa-user-tag me-1"></i>
                                                Acteur : <strong><?php echo htmlspecialchars($event['actor'] ?? 'Système'); ?></strong>
                                            </div>
                                        </div>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                            
                            <?php if(empty($timeline)): ?>
                                <li class="text-center text-muted py-5">Aucune activité enregistrée pour le moment.</li>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>