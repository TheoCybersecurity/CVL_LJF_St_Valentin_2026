<?php
// navbar.php

// 1. DÉTECTION INTELLIGENTE DE L'UTILISATEUR
$nav_user_id = null;
$nav_prenom = 'Toi';

if (isset($current_user_id)) {
    $nav_user_id = $current_user_id;
    $nav_prenom = $current_user_prenom ?? 'Toi';
} elseif (isset($_SESSION['user_id'])) {
    $nav_user_id = $_SESSION['user_id'];
    $nav_prenom = $_SESSION['prenom'] ?? 'Toi';
}

// 2. DÉTECTION DU RÔLE
$nav_role = null;

if ($nav_user_id) {
    $db = isset($pdo) ? $pdo : (isset($pdo_local) ? $pdo_local : null);
    if ($db) {
        try {
            $stmtNav = $db->prepare("SELECT role FROM cvl_members WHERE user_id = ?");
            $stmtNav->execute([$nav_user_id]);
            $res = $stmtNav->fetch(PDO::FETCH_ASSOC);
            if ($res) {
                $nav_role = $res['role'];
            }
        } catch (Exception $e) {}
    }
}
?>

<nav class="navbar navbar-expand-lg navbar-dark bg-dark shadow-sm mb-4">
  <div class="container">
    <a class="navbar-brand fw-bold text-danger" href="index.php">🌹 St Valentin 2026</a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
      <span class="navbar-toggler-icon"></span>
    </button>
    
    <div class="collapse navbar-collapse" id="navbarNav">
      <ul class="navbar-nav ms-auto align-items-center">
        
        <li class="nav-item nav-item-home">
          <a class="nav-link" href="index.php">Accueil</a>
        </li>

        <li class="nav-item">
            <a class="nav-link" href="about.php"><i class="fas fa-info-circle me-1"></i> À propos</a>
        </li>

        <?php if ($nav_user_id): ?>
            
            <?php if ($nav_role): ?>
                <li class="nav-item dropdown nav-item-cvl">
                    <a class="nav-link dropdown-toggle btn btn-outline-light ms-lg-2 px-3 border-0" href="#" role="button" data-bs-toggle="dropdown">
                        🕵️ Espace CVL
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="manage_orders.php">📦 Gestion Commandes</a></li>
                        <li><a class="dropdown-item" href="preparation.php">🎁 Mode Préparation</a></li>
                        <li><a class="dropdown-item" href="delivery.php">🚚 Mode Distribution</a></li>
                        
                        <?php if ($nav_role === 'admin'): ?>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item fw-bold text-danger" href="admin.php">⚡ Super Admin</a></li>
                        <?php endif; ?>
                    </ul>
                </li>
            <?php endif; ?>

            <li class="nav-item nav-item-user ms-3 d-flex align-items-center">
                <span class="text-white me-3">Bonjour, <?php echo htmlspecialchars($nav_prenom); ?></span>
            </li>

        <?php else: ?>
            <li class="nav-item nav-item-login">
                <a href="https://auth.projets.marescal.fr/" class="btn btn-light ms-2">Connexion</a>
            </li>
        <?php endif; ?>
        
      </ul>
    </div>
  </div>
</nav>

<style>
/* --- FIX CONTRASTE BOUTON ESPACE CVL --- */
.navbar .dropdown-toggle.btn-outline-light:hover,
.navbar .dropdown-toggle.btn-outline-light:focus,
.navbar .dropdown-toggle.show {
    background-color: #fff !important;
    color: #212529 !important;
}

/* --- ANIMATION DESKTOP (Largeur >= 992px) --- */
@media (min-width: 992px) {
    .navbar .dropdown-menu {
        display: block;
        opacity: 0;
        visibility: hidden;
        transform: translateY(15px);
        transition: all 0.3s cubic-bezier(0.2, 0, 0.2, 1);
        border: none;
        box-shadow: 0 10px 30px rgba(0,0,0,0.15);
        pointer-events: none;
        background-color: white;
    }

    .navbar .dropdown-menu.show {
        opacity: 1;
        visibility: visible;
        transform: translateY(0);
        pointer-events: auto;
    }
    
    .dropdown-item {
        color: #212529; 
        transition: transform 0.2s, color 0.2s;
    }
    
    .dropdown-item:hover {
        background-color: transparent;
        color: #dc3545;
        transform: translateX(5px);
    }
}

/* --- OPTIMISATION & ANIMATION MOBILE (Largeur < 992px) --- */
@media (max-width: 991.98px) {
    
    /* 1. REORGANISATION DE L'ORDRE D'AFFICHAGE */
    .navbar-nav {
        display: flex;
        flex-direction: column;
        padding-top: 10px;
        width: 100%;
    }

    /* Le Bonjour passe tout en haut */
    .nav-item-user {
        order: -1; 
        width: 100%;
        justify-content: center;
        margin-left: 0 !important; /* On annule le ms-3 du PC */
        margin-bottom: 15px;
        padding-bottom: 15px;
        border-bottom: 1px solid rgba(255,255,255,0.1); /* Petit trait de séparation */
    }
    
    .nav-item-user span {
        margin-right: 0 !important; /* Centrage du texte */
        font-weight: bold;
        color: #adb5bd !important; /* Un gris un peu plus clair pour distinguer */
    }

    /* L'Accueil en deuxième */
    .nav-item-home {
        order: 0;
        text-align: center;
        margin-bottom: 10px;
    }

    /* Le Bouton Espace CVL en dernier */
    .nav-item-cvl {
        order: 1;
        width: 100%;
        text-align: center;
        margin-bottom: 20px;
    }
    
    /* Bouton Connexion (si pas connecté) */
    .nav-item-login {
        margin-left: 0 !important;
        text-align: center;
        margin-top: 10px;
    }
    .nav-item-login a {
        width: 100%; /* Bouton pleine largeur */
        display: block;
    }


    /* 2. ANIMATION DROPDOWN MOBILE */
    .navbar .dropdown-menu {
        display: block;
        max-height: 0;
        overflow: hidden;
        opacity: 0;
        border: none;
        padding: 0;
        margin: 0;
        transition: all 0.3s ease-in-out;
        transform: translateY(-10px);
        background-color: transparent !important;
        text-align: center; /* On centre aussi les sous-menus */
    }

    .navbar .dropdown-menu.show {
        max-height: 500px;
        opacity: 1;
        padding: 0.5rem 0;
        margin-top: 0.5rem;
        transform: translateY(0);
    }
    
    .dropdown-item {
        color: rgba(255,255,255,0.8);
        padding: 10px 0; /* Plus d'espace pour le doigt */
    }
    
    .dropdown-item:hover, .dropdown-item:focus {
        background-color: transparent;
        color: #fff;
    }
    
    .dropdown-divider {
        border-top: 1px solid rgba(255,255,255,0.2);
        margin: 10px auto;
        width: 50%; /* Le diviseur ne prend que la moitié de la largeur */
    }
}
</style>