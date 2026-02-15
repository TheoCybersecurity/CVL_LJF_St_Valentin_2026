<?php
/**
 * Barre de Navigation Principale (Navbar)
 * navbar.php
 * * Ce composant est inclus sur toutes les pages du site.
 * Il assure :
 * 1. L'identification visuelle de l'utilisateur connecté.
 * 2. La gestion dynamique des liens selon le rôle (Acheteur, Membre CVL, Admin).
 * 3. La navigation responsive (Mobile/Desktop).
 */

// =================================================================
// 1. LOGIQUE D'IDENTIFICATION UTILISATEUR
// =================================================================
// Tente de récupérer l'identité de l'utilisateur via les variables globales ou la session
$nav_user_id = null;
$nav_prenom = 'Toi';

if (isset($current_user_id)) {
    // Cas 1 : Variables globales injectées par auth_check.php
    $nav_user_id = $current_user_id;
    $nav_prenom = $current_user_prenom ?? 'Toi';
} elseif (isset($_SESSION['user_id'])) {
    // Cas 2 : Récupération depuis la session PHP standard
    $nav_user_id = $_SESSION['user_id'];
    $nav_prenom = $_SESSION['prenom'] ?? 'Toi';
}

// =================================================================
// 2. DÉTECTION DU RÔLE (RBAC)
// =================================================================
// Vérifie si l'utilisateur possède des privilèges élevés (CVL ou Admin)
$nav_role = null;

if ($nav_user_id) {
    // Sélectionne l'instance PDO disponible (Locale ou Globale selon le contexte)
    $db = isset($pdo) ? $pdo : (isset($pdo_local) ? $pdo_local : null);
    
    if ($db) {
        try {
            $stmtNav = $db->prepare("SELECT role FROM cvl_members WHERE user_id = ?");
            $stmtNav->execute([$nav_user_id]);
            $res = $stmtNav->fetch(PDO::FETCH_ASSOC);
            if ($res) {
                $nav_role = $res['role']; // 'cvl' ou 'admin'
            }
        } catch (Exception $e) {
            // Silence en cas d'erreur SQL mineure pour ne pas casser l'affichage du menu
        }
    }
}
?>

<nav class="navbar navbar-expand-lg navbar-dark bg-dark shadow-sm mb-4">
  <div class="container">
    
    <a class="navbar-brand fw-bold text-danger brand-bounce" 
        href="#" 
        id="brandLink" 
        title="Retour au portail principal"
        data-bs-toggle="modal" 
        data-bs-target="#exitConfirmationModal">
            🌹 St Valentin 2026
    </a>

    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
      <span class="navbar-toggler-icon"></span>
    </button>
    
    <div class="collapse navbar-collapse" id="navbarNav">
      <ul class="navbar-nav ms-auto align-items-center">
        
        <li class="nav-item nav-item-home">
            <a class="nav-link" href="index.php">
                <i class="fas fa-home me-1 text-primary"></i> <span class="link-text">Accueil</span>
            </a>
        </li>

        <li class="nav-item">
            <a class="nav-link" href="about.php">
                <i class="fas fa-info-circle me-1 text-info"></i> <span class="link-text">À propos</span>
            </a>
        </li>

        <?php if ($nav_user_id): ?>
            
            <?php if ($nav_role): ?>
                <li class="nav-item dropdown nav-item-cvl">
                    <a class="nav-link dropdown-toggle btn btn-outline-light ms-lg-2 px-3 border-0" href="#" role="button" data-bs-toggle="dropdown" data-bs-display="static">
                        <i class="fas fa-users-cog me-1 text-danger"></i> Espace CVL
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end shadow border-0">
                        <li>
                            <a class="dropdown-item" href="manage_orders.php">
                                <i class="fas fa-clipboard-list me-2 text-primary"></i> Gestion Commandes
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="preparation.php">
                                <i class="fas fa-boxes me-2 text-warning"></i> Mode Préparation
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="delivery.php">
                                <i class="fas fa-truck me-2 text-success"></i> Mode Distribution
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="directory.php">
                                <i class="fas fa-address-book me-2 text-info"></i> Annuaire du Lycée
                            </a>
                        </li>
                        
                        <?php if ($nav_role === 'admin'): ?>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item fw-bold text-danger" href="admin.php">
                                    <i class="fas fa-bolt me-2"></i> Super Admin
                                </a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </li>
            <?php endif; ?>

            <li class="nav-item nav-item-user ms-lg-3 d-flex align-items-center justify-content-center">
                <span class="text-white">
                    Bonjour, <strong class="text-info"><?php echo htmlspecialchars($nav_prenom); ?></strong>
                </span>
            </li>

        <?php else: ?>
            <li class="nav-item nav-item-login">
                <a href="https://auth.projets.marescal.fr/" class="btn btn-light ms-2 px-4 fw-bold shadow-sm click-bounce">Connexion</a>
            </li>
        <?php endif; ?>
        
      </ul>
    </div>
  </div>
</nav>

<div class="modal fade" id="exitConfirmationModal" tabindex="-1" aria-labelledby="exitModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-danger">
            <div class="modal-header bg-danger-subtle text-danger">
                <h5 class="modal-title" id="exitModalLabel">⚠️ Quitter le site ?</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <div class="modal-body">
                <p>Vous êtes sur le point de quitter l'application de commande <strong>St Valentin</strong> pour retourner vers le portail principal.</p>
                <p class="mb-0 text-muted small"><i class="fas fa-exclamation-triangle"></i> Si vous avez une commande en cours non validée, elle sera perdue.</p>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler / Rester</button>
                <a href="https://projets.marescal.fr" class="btn btn-danger fw-bold">
                    Quitter vers le portail <i class="fas fa-external-link-alt ms-1"></i>
                </a>
            </div>
        </div>
    </div>
</div>

<script>
    // Initialisation des Tooltips Bootstrap au chargement du DOM
    document.addEventListener("DOMContentLoaded", function(){
        var brandElement = document.getElementById('brandLink');
        var tooltip = new bootstrap.Tooltip(brandElement, {
            placement: 'bottom',
            trigger: 'hover'
        });
    });
</script>

<style>
/* --- ANIMATIONS & INTERACTIVITÉ --- */

@keyframes clickBounce {
    0% { transform: scale(1); }
    50% { transform: scale(0.92); }
    100% { transform: scale(1); }
}

/* Effet tactile au clic */
.nav-link:active, 
.dropdown-item:active, 
.navbar-brand:active, 
.btn:active {
    animation: clickBounce 0.3s ease;
}

/* Transitions douces au survol */
.nav-link {
    transition: all 0.3s ease;
}

.nav-link:hover .link-text {
    color: #fff;
    text-shadow: 0 0 10px rgba(255,255,255,0.2);
}

.nav-link:hover i {
    transform: translateY(-2px) scale(1.2);
    transition: transform 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
}

.dropdown-item {
    transition: all 0.2s ease;
    padding: 10px 20px;
}

.dropdown-item:hover {
    padding-left: 25px; /* UX : Indice visuel de sélection */
    background-color: #f8f9fa;
}

/* Colorisation contextuelle des icônes au survol */
.dropdown-item:hover i.text-primary { color: #0d6efd !important; }
.dropdown-item:hover i.text-danger { color: #dc3545 !important; }
.dropdown-item:hover i.text-success { color: #198754 !important; }
.dropdown-item:hover i.text-secondary { color: #212529 !important; }

/* Contraste amélioré pour le bouton Espace CVL */
.navbar .dropdown-toggle.btn-outline-light:hover,
.navbar .dropdown-toggle.btn-outline-light:focus,
.navbar .dropdown-toggle.show {
    background-color: #fff !important;
    color: #212529 !important;
}

/* --- AFFICHAGE DESKTOP (Largeur >= 992px) --- */
@media (min-width: 992px) {
    .navbar .dropdown-menu {
        display: block;
        opacity: 0;
        visibility: hidden;
        margin-top: 15px !important; 
        transition: opacity 0.3s ease, margin-top 0.3s cubic-bezier(0.2, 0, 0.2, 1), visibility 0.3s;
        border-radius: 12px;
        border: none;
        box-shadow: 0 10px 30px rgba(0,0,0,0.15);
        pointer-events: none;
    }

    .navbar .dropdown-menu.show {
        opacity: 1;
        visibility: visible;
        margin-top: 0 !important;
        pointer-events: auto;
    }
    
    .dropdown-menu-end {
        right: 0;
        left: auto;
    }
}

/* --- AFFICHAGE MOBILE (Largeur < 992px) --- */
@media (max-width: 991.98px) {
    
    .navbar-nav {
        display: flex;
        flex-direction: column;
        width: 100%;
        padding: 10px 0;
    }

    /* Le nom d'utilisateur passe en haut sur mobile */
    .nav-item-user {
        order: -2; 
        width: 100%;
        display: flex;
        justify-content: center;
        align-items: center;
        padding-bottom: 15px;
        margin-bottom: 15px;
        border-bottom: 1px solid rgba(255, 255, 255, 0.15);
    }
    
    .nav-item-user span {
        margin-right: 0 !important;
        font-size: 1.1rem;
        text-align: center;
    }

    /* Animation Accordéon pour le Dropdown Mobile */
    .navbar .dropdown-menu {
        display: block !important;
        max-height: 0;
        overflow: hidden;
        opacity: 0;
        border: none;
        padding: 0;
        margin: 5px 0;
        transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        background-color: rgba(255, 255, 255, 0.08) !important;
        width: 100%;
        border-radius: 10px;
    }

    .navbar .dropdown-menu.show {
        max-height: 500px;
        opacity: 1;
        padding: 10px 0;
    }
    
    /* Adaptation des items pour le tactile */
    .dropdown-item {
        color: #fff !important;
        text-align: left;
        padding: 12px 20px;
        white-space: nowrap;
        font-size: 1rem;
        display: flex;
        align-items: center;
    }
    
    .dropdown-item i {
        width: 25px;
        margin-right: 10px;
        text-align: center;
    }

    .nav-item-home, .nav-item {
        text-align: center;
        width: 100%;
        margin-bottom: 5px;
    }

    .nav-item-cvl {
        width: 100%;
        margin-top: 10px;
    }
    
    .nav-item-cvl .btn {
        width: 100%;
    }
}
</style>