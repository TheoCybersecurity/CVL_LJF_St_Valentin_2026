<?php
// navbar.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>
<nav class="navbar navbar-expand-lg navbar-dark bg-danger shadow-sm mb-4">
    <div class="container">
        <a class="navbar-brand fw-bold" href="index.php">
            🌹 St Valentin
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav me-auto">
                <li class="nav-item">
                    <a class="nav-link" href="index.php">Mes Commandes</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link active" href="order.php">Passer une commande</a>
                </li>
            </ul>
            <div class="d-flex align-items-center text-white">
                <?php if (isset($is_logged_in) && $is_logged_in && isset($user_info)): ?>
                    <span class="me-3">Bonjour, <?php echo htmlspecialchars($user_info['prenom']); ?></span>
                    <a href="https://projets.marescal.fr" class="btn btn-sm btn-outline-light">Portail</a>
                <?php else: ?>
                    <span class="badge bg-warning text-dark">Mode Invité</span>
                <?php endif; ?>
            </div>
        </div>
    </div>
</nav>