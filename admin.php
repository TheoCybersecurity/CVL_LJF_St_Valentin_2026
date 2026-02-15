<?php
/**
 * Tableau de bord Administrateur (Hub Central)
 * admin.php
 * * Ce fichier sert de portail d'accès à l'ensemble des modules d'administration.
 * Il présente une interface sous forme de tuiles (Dashboard) redirigeant vers
 * les fonctionnalités spécifiques (Gestion des commandes, Configuration, Logs, etc.).
 */

require_once 'db.php';
require_once 'auth_check.php'; 

// Vérification stricte des droits d'accès (Rôle 'admin' requis)
checkAccess('admin');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <title>Super Admin - Hub</title>
    <?php include 'head_imports.php'; ?>
    <style>
        /* Styles : Comportement des liens-blocs (Cards cliquables) */
        .block-link {
            text-decoration: none; /* Suppression du soulignement standard */
            color: inherit; /* Héritage de la couleur du texte parent */
            display: block; /* La zone cliquable occupe tout le conteneur */
        }
        .block-link:hover {
            color: inherit; /* Maintien de la couleur au survol */
        }

        /* Styles : Animations et transitions visuelles */
        .hover-card {
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            border: none;
        }

        /* Déclencheur : Effet d'élévation et d'ombre au survol de la carte complète */
        .block-link:hover .hover-card {
            transform: translateY(-5px); 
            box-shadow: 0 10px 20px rgba(0,0,0,0.15) !important;
        }
    </style>
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
            <a href="manage_orders.php" class="block-link">
                <div class="card shadow h-100 text-center hover-card">
                    <div class="card-body">
                        <div class="text-success mb-3">
                            <i class="fas fa-boxes fa-4x"></i>
                        </div>
                        <h3 class="card-title fw-bold">Commandes</h3>
                        <p class="card-text text-muted">Accès rapide à la gestion des commandes (modification, suppression).</p>
                        <div class="btn btn-outline-success w-100 rounded-pill">Gérer les commandes</div>
                    </div>
                </div>
            </a>
        </div>

        <div class="col-md-4">
            <a href="admin_team.php" class="block-link">
                <div class="card shadow h-100 text-center hover-card">
                    <div class="card-body">
                        <div class="text-primary mb-3">
                            <i class="fas fa-users-cog fa-4x"></i>
                        </div>
                        <h3 class="card-title fw-bold">Équipe CVL</h3>
                        <p class="card-text text-muted">Gérer les membres, ajouter des admins et modifier les droits d'accès.</p>
                        <div class="btn btn-outline-primary w-100 rounded-pill">Gérer l'équipe</div>
                    </div>
                </div>
            </a>
        </div>

        <div class="col-md-4">
            <a href="logs.php" class="block-link">
                <div class="card shadow h-100 text-center hover-card">
                    <div class="card-body">
                        <div class="text-warning mb-3">
                            <i class="fas fa-history fa-4x"></i>
                        </div>
                        <h3 class="card-title fw-bold">Logs & Activité</h3>
                        <p class="card-text text-muted">Voir l'historique des actions, validations de paiement et distributions.</p>
                        <div class="btn btn-outline-warning text-dark w-100 rounded-pill">Voir les Logs</div>
                    </div>
                </div>
            </a>
        </div>

    </div>

    <div class="row justify-content-center g-4">

        <div class="col-md-4">
            <a href="import_edt.php" class="block-link">
                <div class="card shadow h-100 text-center hover-card">
                    <div class="card-body">
                        <div class="text-info mb-3">
                            <i class="fas fa-file-import fa-4x"></i>
                        </div>
                        <h3 class="card-title fw-bold">Import Données</h3>
                        <p class="card-text text-muted">Mettre à jour la base des élèves (CSV) et les emplois du temps (ICS).</p>
                        <div class="btn btn-outline-info w-100 rounded-pill">Importer EDT</div>
                    </div>
                </div>
            </a>
        </div>

        <div class="col-md-4">
            <a href="admin_config.php" class="block-link">
                <div class="card shadow h-100 text-center hover-card">
                    <div class="card-body">
                        <div class="text-secondary mb-3">
                            <i class="fas fa-cogs fa-4x"></i>
                        </div>
                        <h3 class="card-title fw-bold">Configuration</h3>
                        <p class="card-text text-muted">Prix des roses, gestion des salles, des classes et messages prédéfinis.</p>
                        <div class="btn btn-outline-secondary w-100 rounded-pill">Paramètres</div>
                    </div>
                </div>
            </a>
        </div>

        <div class="col-md-4">
            <a href="admin_emails.php" class="block-link">
                <div class="card shadow h-100 text-center hover-card">
                    <div class="card-body">
                        <div class="text-indigo mb-3">
                            <i class="fas fa-paper-plane fa-4x"></i>
                        </div>
                        <h3 class="card-title fw-bold">Emails & Rappels</h3>
                        <p class="card-text text-muted">Envoyer les rappels d'impayés et les messages d'info aux élèves.</p>
                        <div class="btn btn-outline-secondary w-100 rounded-pill">Campagnes Email</div>
                    </div>
                </div>
            </a>
        </div>

        <div class="col-md-4">
            <a href="admin_reset.php" class="block-link">
                <div class="card shadow h-100 text-center hover-card">
                    <div class="card-body">
                        <div class="text-danger mb-3">
                            <i class="fas fa-trash-alt fa-4x"></i>
                        </div>
                        <h3 class="card-title fw-bold text-danger">Zone Reset</h3>
                        <p class="card-text text-muted">Zone de danger : Vider les commandes et réinitialiser la base de données.</p>
                        <div class="btn btn-outline-danger w-100 rounded-pill">Accéder</div>
                    </div>
                </div>
            </a>
        </div>

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>