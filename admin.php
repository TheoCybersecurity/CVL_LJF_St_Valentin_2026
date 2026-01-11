<?php
// admin.php (HUB CENTRAL - DESIGN UNIFIÉ)
require_once 'db.php';
require_once 'auth_check.php'; 

// Seuls les "admin" (pas juste CVL) peuvent accéder à ce Hub
checkAccess('admin');

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Super Admin - Hub</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo time(); ?>">
</head>
<body class="bg-light">

<?php include 'navbar.php'; ?>

<div class="container mt-5 pb-5">
    <div class="text-center mb-5">
        <h1 class="fw-bold text-dark">⚡ Administration Centrale</h1>
        <p class="text-muted">Gérez l'équipe, surveillez les logs et supervisez les opérations.</p>
    </div>

    <div class="row justify-content-center g-4 mb-4">
        
        <div class="col-md-4">
            <div class="card shadow h-100 text-center hover-card">
                <div class="card-body">
                    <div class="text-success">
                        <i class="fas fa-boxes fa-4x"></i>
                    </div>
                    <h3 class="card-title fw-bold">Commandes</h3>
                    <p class="card-text text-muted">Accès rapide à la gestion des commandes (modification, suppression).</p>
                    <a href="manage_orders.php" class="btn btn-outline-success w-100 stretched-link rounded-pill">Gérer les commandes</a>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card shadow h-100 text-center hover-card">
                <div class="card-body">
                    <div class="text-primary">
                        <i class="fas fa-users-cog fa-4x"></i>
                    </div>
                    <h3 class="card-title fw-bold">Équipe CVL</h3>
                    <p class="card-text text-muted">Gérer les membres, ajouter des admins et modifier les droits d'accès.</p>
                    <a href="admin_team.php" class="btn btn-outline-primary w-100 stretched-link rounded-pill">Gérer l'équipe</a>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card shadow h-100 text-center hover-card">
                <div class="card-body">
                    <div class="text-warning">
                        <i class="fas fa-history fa-4x"></i>
                    </div>
                    <h3 class="card-title fw-bold">Logs & Activité</h3>
                    <p class="card-text text-muted">Voir l'historique des actions, validations de paiement et distributions.</p>
                    <a href="logs.php" class="btn btn-outline-warning text-dark w-100 stretched-link rounded-pill">Voir les Logs</a>
                </div>
            </div>
        </div>

    </div>

    <div class="row justify-content-center g-4">

        <div class="col-md-4">
            <div class="card shadow h-100 text-center hover-card">
                <div class="card-body">
                    <div class="text-secondary">
                        <i class="fas fa-cogs fa-4x"></i>
                    </div>
                    <h3 class="card-title fw-bold">Configuration</h3>
                    <p class="card-text text-muted">Prix des roses, gestion des salles, des classes et messages prédéfinis.</p>
                    <a href="admin_config.php" class="btn btn-outline-secondary w-100 stretched-link rounded-pill">Paramètres</a>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card shadow h-100 text-center hover-card">
                <div class="card-body">
                    <div class="text-danger">
                        <i class="fas fa-trash-alt fa-4x"></i>
                    </div>
                    <h3 class="card-title fw-bold text-danger">Zone Reset</h3>
                    <p class="card-text text-muted">Zone de danger : Vider les commandes et réinitialiser la base de données.</p>
                    <a href="admin_reset.php" class="btn btn-outline-danger w-100 stretched-link rounded-pill">Accéder</a>
                </div>
            </div>
        </div>

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>