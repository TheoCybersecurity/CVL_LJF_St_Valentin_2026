<?php
/**
 * Politique de Confidentialité & Transparence
 * privacy.php
 * Ce fichier est accessible publiquement et sert de référence RGPD simplifiée.
 * Il détaille :
 * 1. La nature et la source des données collectées (Import Pronote/Utilisateurs).
 * 2. L'infrastructure technique et les mesures de sécurité (Souveraineté, Chiffrement).
 * 3. La matrice des droits d'accès (Qui voit quoi ?).
 * 4. Les aspects légaux (Propriété intellectuelle et cycle de vie des données).
 */

session_start();
require_once 'db.php';
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <title>Confidentialité & Données - St Valentin</title>
    <?php include 'head_imports.php'; ?>
    <style>
        /* --- Styles Généraux --- */
        body { background-color: #f8f9fa; }
        
        /* Titre avec dégradé */
        .hero-title {
            background: -webkit-linear-gradient(45deg, #198754, #0d6efd);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        /* Cartes interactives */
        .card-custom {
            border: none;
            border-radius: 12px;
            transition: transform 0.2s, box-shadow 0.2s;
            height: 100%;
            background: white;
        }
        .card-custom:hover {
            transform: translateY(-3px);
            box-shadow: 0 .5rem 1rem rgba(0,0,0,.15)!important;
        }

        /* --- Codes Couleurs & Bordures --- */
        .section-data { border-left: 5px solid #0d6efd; }       /* Bleu : Données */
        .section-security { border-left: 5px solid #198754; }    /* Vert : Sécurité */
        .section-access { border-left: 5px solid #dc3545; }      /* Rouge : Accès */
        .section-license { border-left: 5px solid #6610f2; }     /* Violet : Droit/Licence */

        /* --- Icônes --- */
        .icon-box {
            width: 50px; 
            height: 50px; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            border-radius: 10px;
            margin-right: 15px;
            font-size: 1.5rem;
            flex-shrink: 0;
        }

        .bg-blue-light { background-color: #e7f1ff; color: #0d6efd; }
        .bg-green-light { background-color: #d1e7dd; color: #0f5132; }
        .bg-red-light { background-color: #f8d7da; color: #842029; }
        .bg-purple-light { background-color: #e0cffc; color: #6610f2; }

        /* Badge Source de données */
        .pronote-badge {
            background-color: #6f42c1;
            color: white;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 0.8em;
            font-weight: bold;
            letter-spacing: 0.5px;
        }
    </style>
</head>
<body class="bg-light">

<?php include 'navbar.php'; ?>

<div class="container mt-5 mb-5">

    <div class="text-center mb-5">
        <h1 class="fw-bold display-5 hero-title">Transparence & Protection des Données</h1>
        <p class="text-muted lead">
            Une gestion rigoureuse, sécurisée et validée par l'établissement.
            <br>Voici tout ce que vous devez savoir sur vos informations.
        </p>
    </div>

    <div class="row mb-4">
        <div class="col-12">
            <div class="card card-custom shadow-sm p-4 section-data">
                <div class="d-flex align-items-center mb-3">
                    <div class="icon-box bg-blue-light">
                        <i class="fas fa-database"></i>
                    </div>
                    <h4 class="fw-bold mb-0">1. Collecte : Quoi et Pourquoi ?</h4>
                </div>
                
                <div class="row g-4">
                    <div class="col-md-6 border-end-md">
                        <h6 class="fw-bold text-primary">Sur l'Acheteur (Vous)</h6>
                        <ul class="text-muted small mb-0">
                            <li><strong>Identité :</strong> Nom, Prénom, Classe.</li>
                            <li><strong>Objectif :</strong> Assurer la traçabilité financière de la commande et vous contacter en cas de problème de paiement.</li>
                            <li><strong>Conservation :</strong> Jusqu'à la fin de l'événement.</li>
                        </ul>
                    </div>
                    <div class="col-md-6">
                        <h6 class="fw-bold text-danger">Sur le Destinataire</h6>
                        <ul class="text-muted small mb-2">
                            <li><strong>Identité :</strong> Nom, Prénom, Classe.</li>
                            <li><strong>Logistique :</strong> Emploi du temps (Salle/Heure) pour la livraison.</li>
                            <li><strong>Statut :</strong> Choix de l'anonymat (Optionnel).</li>
                        </ul>
                    </div>
                </div>

                <div class="alert alert-primary mt-3 mb-0 d-flex align-items-center">
                    <i class="fas fa-info-circle fa-2x me-3"></i>
                    <div>
                        <strong>Source des données : <span class="pronote-badge">PRONOTE</span></strong><br>
                        Afin de garantir que chaque élève puisse recevoir sa rose, la base de données des élèves, des classes et des emplois du temps a été importée depuis les services du lycée. 
                        Cette extraction a été réalisée <strong>avec l'accord explicite de la direction</strong> et est utilisée strictement dans le périmètre de cet événement.
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        
        <div class="col-lg-6">
            <div class="card card-custom shadow-sm p-4 section-security h-100">
                <div class="d-flex align-items-center mb-3">
                    <div class="icon-box bg-green-light">
                        <i class="fas fa-server"></i>
                    </div>
                    <h4 class="fw-bold mb-0">2. Infra & Sécurité</h4>
                </div>
                
                <p class="small text-muted">
                    Nous avons fait le choix de la souveraineté numérique. Vos données ne sont pas hébergées chez les géants du web (GAFAM).
                </p>

                <ul class="list-group list-group-flush small">
                    <li class="list-group-item bg-transparent px-0 border-0 mb-2">
                        <i class="fas fa-hdd text-success me-2"></i>
                        <strong>Auto-Hébergement :</strong> Les données sont stockées sur un serveur privé sécurisé, administré par le développeur (Théo Marescal).
                    </li>
                    <li class="list-group-item bg-transparent px-0 border-0 mb-2">
                        <i class="fas fa-lock text-success me-2"></i>
                        <strong>Protection :</strong> Mots de passe hachés (bcrypt), pare-feu strict et connexion chiffrée (HTTPS/SSL).
                    </li>
                    <li class="list-group-item bg-transparent px-0 border-0">
                        <i class="fas fa-cloud-upload-alt text-success me-2"></i>
                        <strong>Sauvegardes :</strong> Les backups sont <strong>chiffrés</strong> avant d'être envoyés sur un stockage de secours, garantissant qu'ils sont illisibles par autrui.
                    </li>
                </ul>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card card-custom shadow-sm p-4 section-access h-100">
                <div class="d-flex align-items-center mb-3">
                    <div class="icon-box bg-red-light">
                        <i class="fas fa-user-shield"></i>
                    </div>
                    <h4 class="fw-bold mb-0">3. Qui voit quoi ?</h4>
                </div>

                <div class="accordion accordion-flush" id="accordionAccess">
                    <div class="accordion-item bg-transparent">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed bg-transparent shadow-none px-0 fw-bold" type="button" data-bs-toggle="collapse" data-bs-target="#access1">
                                <span class="badge bg-danger me-2">CVL</span> Membres Organisateurs
                            </button>
                        </h2>
                        <div id="access1" class="accordion-collapse collapse show" data-bs-parent="#accordionAccess">
                            <div class="accordion-body px-0 py-2 small text-muted">
                                Ils ont accès aux listes de distribution (Nom, Prénom, Classe, Salle) pour préparer les commandes et livrer les roses. Ils ne voient pas les messages si l'option "Anonyme" est activée.
                            </div>
                        </div>
                    </div>

                    <div class="accordion-item bg-transparent">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed bg-transparent shadow-none px-0 fw-bold" type="button" data-bs-toggle="collapse" data-bs-target="#access2">
                                <span class="badge bg-dark me-2">ADMIN</span> Théo (Développeur)
                            </button>
                        </h2>
                        <div id="access2" class="accordion-collapse collapse" data-bs-parent="#accordionAccess">
                            <div class="accordion-body px-0 py-2 small text-muted">
                                En tant que responsable de l'infrastructure, j'ai un accès technique global pour assurer la maintenance du site. Cet accès n'est pas utilisé pour consulter les données personnelles, sauf nécessité technique absolue (bug critique).
                            </div>
                        </div>
                    </div>

                    <div class="accordion-item bg-transparent">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed bg-transparent shadow-none px-0 fw-bold" type="button" data-bs-toggle="collapse" data-bs-target="#access3">
                                <span class="badge bg-secondary me-2">LYCÉE</span> Administration / Vie Sco.
                            </button>
                        </h2>
                        <div id="access3" class="accordion-collapse collapse" data-bs-parent="#accordionAccess">
                            <div class="accordion-body px-0 py-2 small text-muted">
                                Ils n'ont <strong>aucun accès direct</strong> à la base de données. L'anonymat est protégé. Une levée d'anonymat ne peut être faite que sur demande formelle en cas de problème disciplinaire grave (harcèlement, insulte).
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <div class="card card-custom shadow-sm p-4 section-license">
                <div class="d-flex align-items-center mb-3">
                    <div class="icon-box bg-purple-light">
                        <i class="fas fa-balance-scale"></i>
                    </div>
                    <h4 class="fw-bold mb-0">4. Propriété Intellectuelle & Avenir</h4>
                </div>

                <div class="row">
                    <div class="col-md-7 mb-3 mb-md-0">
                        <h6 class="fw-bold">Cycle de vie des données</h6>
                        <p class="small text-muted">
                            Ce projet est éphémère. À l'issue de l'événement (fin février) :
                        </p>
                        <ul class="small text-muted">
                            <li>Les accès des membres du CVL seront révoqués.</li>
                            <li>Les données réelles seront archivées hors-ligne par l'administrateur à des fins de sécurité (historique comptable), puis supprimées du serveur.</li>
                            <li>Le code source sera rendu public (Open Source) avec uniquement des données fictives.</li>
                        </ul>
                    </div>
                    
                    <div class="col-md-5 border-start-md">
                        <div class="bg-light p-3 rounded border h-100">
                            <h6 class="fw-bold text-dark"><i class="fas fa-copyright me-2"></i>Licence d'utilisation</h6>
                            <p class="small mb-2" style="font-size: 0.85rem; text-align: justify;">
                                Le code source de ce site est la propriété intellectuelle de <strong>Théo Marescal</strong>.
                                Sa reproduction, sa modification ou son utilisation pour un autre établissement est autorisée 
                                <strong>sous condition stricte de notification</strong>.
                            </p>
                            <div class="alert alert-warning py-2 small mb-0">
                                <i class="fas fa-exclamation-triangle me-1"></i>
                                Avant toute utilisation, vous devez impérativement en informer l'auteur par email :
                                <br>
                                <a href="mailto:theo@marescal.fr" class="fw-bold text-dark text-decoration-underline">theo@marescal.fr</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>