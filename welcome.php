<?php
// Si l'utilisateur est déjà connecté (cookie JWT présent), on le redirige direct vers le setup ou l'accueil
if (isset($_COOKIE['jwt'])) {
    header("Location: setup.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <title>Saint Valentin - Bienvenue</title>
    <?php include 'head_imports.php'; ?>
    <style>
        body { background: linear-gradient(135deg, #fdfbfb 0%, #ebedee 100%); height: 100vh; display: flex; align-items: center; justify-content: center; }
        .card-welcome { max-width: 500px; width: 100%; border: none; box-shadow: 0 10px 30px rgba(0,0,0,0.1); border-radius: 20px; overflow: hidden; }
        .header-img { background: #d63384; height: 150px; display: flex; align-items: center; justify-content: center; color: white; font-size: 4rem; }
    </style>
</head>
<body>

<div class="container p-3">
    <div class="card card-welcome mx-auto">
        <div class="header-img">🌹</div>
        <div class="card-body p-4 text-center">
            <h2 class="mb-3">Saint Valentin 2026</h2>
            <p class="text-muted mb-4">Bienvenue sur la plateforme de commande du CVL.</p>

            <div class="d-grid gap-3">
                <a href="https://auth.projets.marescal.fr/register.php?origin=stvalentin" class="btn btn-danger btn-lg">
                    ✨ Créer un compte <br><small style="font-size:0.7em">(Suivi des commandes inclus)</small>
                </a>

                <a href="order.php?guest=1" class="btn btn-outline-secondary">
                    👤 Continuer en tant qu'invité <br><small style="font-size:0.7em">(Pas d'historique)</small>
                </a>
            </div>
            
            <?php 
                $target = 'https://cvl-ljf-st-valentin-2026.projets.marescal.fr/setup.php';
                $encoded_target = base64_encode($target);
            ?>

            <div class="mt-4 pt-3 border-top">
                <p class="small text-muted">Déjà un compte ? 
                    <a href="https://auth.projets.marescal.fr/index.php?redirect=<?php echo $encoded_target; ?>">Connexion</a>
                </p>
            </div>
        </div>
    </div>
</div>

</body>
</html>