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
        
        <li class="nav-item">
          <a class="nav-link" href="index.php">Accueil</a>
        </li>

        <?php if ($nav_user_id): ?>
            
            <?php if ($nav_role): ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle btn btn-outline-light ms-2 px-3 border-0" href="#" role="button" data-bs-toggle="dropdown">
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

            <li class="nav-item ms-3 d-flex align-items-center">
                <span class="text-white me-3">Bonjour, <?php echo htmlspecialchars($nav_prenom); ?></span>
            </li>

        <?php else: ?>
            <li class="nav-item">
                <a href="https://auth.projets.marescal.fr/" class="btn btn-light ms-2">Connexion</a>
            </li>
        <?php endif; ?>
        
      </ul>
    </div>
  </div>
</nav>