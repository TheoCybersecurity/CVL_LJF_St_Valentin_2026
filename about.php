<?php
/**
 * Page "À Propos"
 * about.php
 * * Cette page présente le contexte du projet, le profil du développeur,
 * la stack technique utilisée, ainsi que la liste des membres du CVL (Conseil de la Vie Lycéenne).
 * Elle sert de vitrine technique et de page de crédits.
 */

session_start();
require_once 'db.php';

// --- RÉCUPÉRATION DE L'ÉQUIPE ORGANISATRICE (CVL) ---
$cvlTeam = [];
try {
    // Récupération des membres actifs pour l'affichage public.
    // Exclusion du compte administrateur/développeur (ID 2) pour ne lister que les élèves organisateurs.
    $sqlTeam = "SELECT u.prenom, u.nom 
                FROM cvl_members cm 
                JOIN users u ON cm.user_id = u.user_id 
                WHERE cm.user_id != 2 
                ORDER BY u.nom ASC"; 
    $stmtTeam = $pdo->query($sqlTeam);
    $cvlTeam = $stmtTeam->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // En cas d'erreur SQL, on initialise un tableau vide pour éviter de casser l'affichage HTML
    $cvlTeam = [];
    error_log("Erreur lors de la récupération de l'équipe CVL : " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <title>À propos du projet - St Valentin</title>
    
    <?php include 'head_imports.php'; ?>
    
    <style>
        .profile-section {
            background: linear-gradient(to right, #ffffff, #f8f9fa);
            border-left: 5px solid #0d6efd;
        }
        .ai-section {
            background: linear-gradient(135deg, #e0c3fc 0%, #8ec5fc 100%);
            border: none;
        }
        .cvl-section {
            border-top: 5px solid #dc3545; /* Rouge thématique St Valentin */
        }
        .tech-badge {
            font-size: 0.9rem;
            margin-bottom: 8px;
            padding: 8px 12px;
        }
        .github-box {
            background-color: #24292e;
            color: white;
        }
        .hero-title {
            background: -webkit-linear-gradient(45deg, #0d6efd, #dc3545);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .btn-instagram {
            background: linear-gradient(45deg, #f09433 0%, #e6683c 25%, #dc2743 50%, #cc2366 75%, #bc1888 100%);
            color: white;
            border: none;
            transition: opacity 0.3s ease;
        }
        .btn-instagram:hover {
            opacity: 0.9;
            color: white;
        }
        .member-badge {
            background-color: #fff;
            border: 1px solid #dee2e6;
            transition: all 0.2s;
            height: 100%;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
        }
        .member-badge:hover {
            border-color: #dc3545;
            transform: translateY(-2px);
            box-shadow: 0 .125rem .25rem rgba(0,0,0,.075);
        }
    </style>
</head>
<body class="bg-light">

<?php include 'navbar.php'; ?>

<div class="container mt-5 mb-5">

    <div class="text-center mb-5">
        <h1 class="fw-bold display-4 hero-title">Le Projet St Valentin 2026</h1>
        <p class="text-muted lead">Une initiative numérique au service du lycée Jules Fil, alliant passion et compétences techniques.</p>
    </div>

    <div class="row g-4 mb-4">
        
        <div class="col-lg-7">
            <div class="card shadow-sm border-0 h-100 p-4 profile-section">
                <div class="d-flex align-items-center mb-4">
                    <div class="bg-dark text-white rounded-circle d-flex align-items-center justify-content-center shadow flex-shrink-0" 
                        style="width: 70px; height: 70px; font-size: 1.8rem;">
                        <i class="fas fa-user-tie"></i>
                    </div>
                    
                    <div class="ms-3">
                        <h3 class="fw-bold m-0">Théo Marescal</h3>
                        <div class="text-primary fw-bold">Administrateur d'Infrastructures Sécurisées (AIS)</div>
                        <small class="text-muted">Alternant & Étudiant</small>
                    </div>
                </div>

                <h5 class="fw-bold mb-3"><i class="fas fa-laptop-code me-2"></i>Contexte & Réalisation</h5>
                <p>
                    Ce projet a été réalisé entièrement <strong>sur mon temps personnel</strong>, en parallèle de mon alternance. 
                    Titulaire d'un <strong>BTS CIEL</strong> (Cybersécurité, Informatique & Réseaux, Électronique) et préparant actuellement un <strong>Titre Pro AIS</strong>, j'ai conçu ce site comme une application concrète de mes compétences professionnelles, pour m'exercer sur un cas réel avec de vrais utilisateurs.
                </p>
                <p>
                    J'ai passé environ <strong>70 heures</strong> sur la conception. Ce site s'inscrit dans une démarche plus large : je regroupe d'ailleurs l'ensemble de mes développements et infrastructures sur mon portfolio personnel, <strong>projets.marescal.fr</strong>, qui témoigne de mon évolution technique.
                </p>

                <div class="mt-3 mb-4">
                    <a href="https://linkedin.com/in/theo-marescal" target="_blank" class="btn btn-primary">
                        <i class="fab fa-linkedin me-2"></i>Consulter mon Profil LinkedIn
                    </a>
                </div>

                <h5 class="fw-bold mb-3"><i class="fas fa-server me-2"></i>Stack Technique</h5>
                <div class="d-flex flex-wrap">
                    <span class="badge bg-primary tech-badge me-2"><i class="fab fa-php me-2"></i>PHP 8</span>
                    <span class="badge bg-warning text-dark tech-badge me-2"><i class="fas fa-database me-2"></i>MySQL / MariaDB</span>
                    
                    <span class="badge bg-danger tech-badge me-2"><i class="fab fa-html5 me-2"></i>HTML5 / CSS3</span>
                    <span class="badge bg-info text-dark tech-badge me-2"><i class="fab fa-bootstrap me-2"></i>Bootstrap 5</span>
                    <span class="badge bg-dark tech-badge me-2"><i class="fab fa-js me-2"></i>JavaScript (ES6)</span>
                    
                    <span class="badge bg-success tech-badge me-2"><i class="fas fa-shield-alt me-2"></i>Cybersécurité</span>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="d-flex flex-column gap-3 h-100">

                <div class="card shadow-sm ai-section p-4">
                    <div class="d-flex align-items-start">
                        <i class="fas fa-robot fa-2x me-3 text-white"></i>
                        <div>
                            <h5 class="fw-bold text-white">Assisté par l'IA</h5>
                            <p class="mb-0 text-white opacity-75 small">
                                Ce projet a été codé en collaboration avec Gemini AI (Pair Programming). J'ai piloté l'architecture et la logique métier, tandis que l'IA a servi de co-pilote technique pour accélérer l'écriture, optimiser les requêtes SQL et renforcer la sécurité du code.
                            </p>
                        </div>
                    </div>
                </div>

                <div class="card shadow-sm border-0 github-box p-4 flex-grow-1">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <h4 class="fw-bold"><i class="fab fa-github me-2"></i>Open Source</h4>
                            <p class="small text-light opacity-75">Curieux de voir comment ça marche ? Le code source complet du projet sera rendu public après l'événement.</p>
                            <p class="mb-3"><i class="far fa-calendar-alt me-2"></i>Dispo le : <strong>14 Février 2026</strong></p>
                        </div>
                        <i class="fas fa-code-branch fa-3x opacity-25"></i>
                    </div>
                    
                    <a href="https://github.com/Theo11FRxx/CVL_LJF_St_Valentin_2026" target="_blank" class="btn btn-light w-100 fw-bold">
                        <i class="fab fa-github-alt me-2"></i>Accéder au GitHub
                    </a>
                </div>

                <div class="card shadow-sm border-0 p-3">
                    <h6 class="fw-bold border-bottom pb-2 mb-3">📞 Besoin d'aide ?</h6>
                    
                    <div class="mb-3">
                        <small class="text-muted d-block mb-1">Pour l'événement (Roses, Distribution...)</small>
                        <a href="https://instagram.com/cvl_julesfil" target="_blank" class="btn btn-instagram w-100 text-start">
                            <i class="fab fa-instagram me-2"></i>Contacter le CVL
                        </a>
                    </div>

                    <div>
                        <small class="text-muted d-block mb-1">Pour un bug sur le site</small>
                        <a href="https://instagram.com/theo_cybersecurity" target="_blank" class="btn btn-instagram w-100 text-start">
                            <i class="fab fa-instagram me-2"></i>Contacter Théo (Dev)
                        </a>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <div class="card shadow-sm border-0 cvl-section p-4 bg-white">
                <h4 class="fw-bold mb-3 text-danger"><i class="fas fa-heart me-2"></i>L'Équipe Organisatrice (CVL)</h4>
                <p class="text-muted mb-4">
                    Un grand merci aux membres du Conseil de la Vie Lycéenne qui ont imaginé, organisé et animé cet événement pour le lycée. Sans leur énergie, ce projet technique n'aurait pas d'utilité.
                </p>

                <div class="row g-3">
                    <?php if (count($cvlTeam) > 0): ?>
                        <?php foreach ($cvlTeam as $member): ?>
                            <div class="col-6 col-md-4 col-lg-3">
                                <div class="p-3 rounded text-center member-badge">
                                    <i class="fas fa-user-graduate text-secondary mb-2 fa-lg d-block"></i>
                                    <span class="fw-bold text-dark text-capitalize">
                                        <?php echo htmlspecialchars($member['prenom'] . ' ' . $member['nom']); ?>
                                    </span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="col-12 text-center text-muted">
                            <p>La liste des membres est en cours de mise à jour.</p>
                        </div>
                    <?php endif; ?>
                </div>
                
            </div>
        </div>
    </div>

    <div class="row mt-5">
        <div class="col-12 text-center border-top pt-4">
            <p class="text-muted small mb-2">
                © 2026 Théo Marescal • Tous droits réservés.
            </p>
            
            <p class="mb-3">
                <a href="privacy.php" class="text-muted text-decoration-underline small">
                    <i class="fas fa-user-shield me-1"></i>Politique de Confidentialité & Données
                </a>
            </p>

            <p class="text-muted small fst-italic">
                Développé avec passion et beaucoup de rigueur.
            </p>
        </div>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>