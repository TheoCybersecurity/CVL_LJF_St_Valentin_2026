<?php
// Si l'utilisateur est déjà connecté (cookie JWT présent), on le redirige direct vers le setup ou l'accueil
if (isset($_COOKIE['jwt'])) {
    header("Location: setup.php");
    exit;
}

// Préparation du lien de redirection pour la connexion
$target = 'https://cvl-ljf-st-valentin-2026.projets.marescal.fr/setup.php';
$encoded_target = base64_encode($target);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <title>Saint Valentin 2026 - CVL</title>
    <?php include 'head_imports.php'; ?>
    <style>
        /* FOND IMMERSIF ET ANIMÉ */
        body {
            background: linear-gradient(-45deg, #ff9a9e, #fad0c4, #fad0c4, #fbc2eb, #a18cd1);
            background-size: 400% 400%;
            animation: gradientBG 15s ease infinite;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            overflow: hidden; /* Important pour les animations flottantes */
            position: relative;
        }

        @keyframes gradientBG {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }

        /* PARTICULES FLOTTANTES (Cœurs d'arrière-plan) */
        .floating-container {
            position: absolute;
            top: 0; left: 0; width: 100%; height: 100%;
            overflow: hidden;
            z-index: 0;
            pointer-events: none;
        }
        .floating-item {
            position: absolute;
            bottom: -100px;
            font-size: 2rem;
            opacity: 0.3;
            animation: floatUp linear infinite;
        }
        @keyframes floatUp {
            0% { transform: translateY(0) rotate(0deg); opacity: 0; }
            10% { opacity: 0.5; }
            90% { opacity: 0.2; }
            100% { transform: translateY(-120vh) rotate(360deg); opacity: 0; }
        }

        /* FORME FLOUES (GLOW) */
        .bg-shape {
            position: absolute;
            filter: blur(60px);
            z-index: 1;
            opacity: 0.5;
            animation: pulseShape 8s ease-in-out infinite alternate;
        }
        .shape-1 { background: rgba(255, 255, 255, 0.4); width: 350px; height: 350px; border-radius: 50%; top: -80px; left: -80px; }
        .shape-2 { background: rgba(255, 105, 180, 0.3); width: 250px; height: 250px; border-radius: 50%; bottom: 5%; right: -50px; animation-delay: 1s; }

        @keyframes pulseShape {
            0% { transform: scale(1); }
            100% { transform: scale(1.1); }
        }

        /* CARTE GLASSMORPHISM */
        .card-welcome {
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.4);
            border-radius: 24px;
            box-shadow: 0 20px 50px rgba(0,0,0,0.15);
            max-width: 450px;
            width: 100%;
            padding: 2rem;
            text-align: center;
            position: relative;
            z-index: 10;
            
            /* Animation d'entrée globale de la carte */
            opacity: 0;
            animation: cardEntrance 0.8s cubic-bezier(0.2, 0.8, 0.2, 1) forwards;
        }

        @keyframes cardEntrance {
            from { opacity: 0; transform: translateY(30px) scale(0.95); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }

        /* LOGO AVEC BATTEMENT */
        .logo-container {
            margin-bottom: 1.5rem;
            position: relative;
        }
        .logo-img {
            width: 140px;
            height: auto;
            filter: drop-shadow(0 5px 15px rgba(220, 53, 69, 0.2));
            animation: heartBeat 3s infinite; /* Animation continue douce */
        }
        @keyframes heartBeat {
            0% { transform: scale(1); }
            5% { transform: scale(1.05); }
            10% { transform: scale(1); }
            15% { transform: scale(1.05); }
            20% { transform: scale(1); }
            100% { transform: scale(1); }
        }

        /* TEXTE ET BOUTONS (Apparition en cascade) */
        .stagger-anim {
            opacity: 0;
            transform: translateY(15px);
            animation: fadeUp 0.6s ease forwards;
        }
        /* Délais pour l'effet cascade */
        .delay-1 { animation-delay: 0.3s; }
        .delay-2 { animation-delay: 0.5s; }
        .delay-3 { animation-delay: 0.7s; }
        .delay-4 { animation-delay: 0.9s; }
        .delay-5 { animation-delay: 1.1s; }

        @keyframes fadeUp {
            to { opacity: 1; transform: translateY(0); }
        }

        h1 {
            font-weight: 800;
            color: #d63384; 
            margin-bottom: 0.5rem;
            font-size: 1.8rem;
        }
        .subtitle {
            color: #6c757d;
            font-size: 0.95rem;
            margin-bottom: 2rem;
            line-height: 1.5;
        }

        /* BOUTONS STYLISÉS */
        .btn-custom-primary {
            background: linear-gradient(45deg, #d63384, #dc3545);
            border: none;
            color: white;
            font-weight: 600;
            border-radius: 50px;
            padding: 12px 20px;
            box-shadow: 0 4px 15px rgba(214, 51, 132, 0.3);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        /* Effet de brillance au survol */
        .btn-custom-primary::after {
            content: '';
            position: absolute;
            top: 0; left: -100%;
            width: 50%; height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.4), transparent);
            transform: skewX(-20deg);
            transition: 0.5s;
        }
        .btn-custom-primary:hover::after { left: 150%; }
        .btn-custom-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(214, 51, 132, 0.5);
            color: white;
        }

        .btn-custom-secondary {
            background: white;
            border: 2px solid #e9ecef;
            color: #495057;
            font-weight: 600;
            border-radius: 50px;
            padding: 10px 20px;
            transition: all 0.3s ease;
        }
        .btn-custom-secondary:hover {
            background: #f8f9fa;
            border-color: #dee2e6;
            color: #212529;
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
        }

        .login-link {
            color: #d63384;
            font-weight: 600;
            text-decoration: none;
            position: relative;
        }
        .login-link::after {
            content: '';
            position: absolute;
            width: 0; height: 2px;
            bottom: -2px; left: 0;
            background-color: #d63384;
            transition: width 0.3s;
        }
        .login-link:hover::after { width: 100%; }
        
        .divider {
            display: flex;
            align-items: center;
            text-align: center;
            margin: 1.5rem 0;
            color: #adb5bd;
            font-size: 0.85rem;
        }
        .divider::before, .divider::after {
            content: '';
            flex: 1;
            border-bottom: 1px solid #dee2e6;
        }
        .divider:not(:empty)::before { margin-right: .5em; }
        .divider:not(:empty)::after { margin-left: .5em; }

    </style>
</head>
<body>

<div class="floating-container" id="floatingHearts">
    </div>

<div class="bg-shape shape-1"></div>
<div class="bg-shape shape-2"></div>

<div class="container p-3">
    <div class="card-welcome mx-auto">
        
        <div class="logo-container stagger-anim delay-1">
            <img src="images/CVL_LJF_St_Valentin_2026.png" alt="Logo Saint Valentin" class="logo-img">
        </div>

        <h1 class="stagger-anim delay-2">Saint Valentin 2026</h1>
        <p class="subtitle stagger-anim delay-2">
            Bienvenue sur la plateforme du CVL.<br>
            Faites plaisir à vos amis (ou plus) ! 🌹
        </p>

        <div class="d-grid gap-3 stagger-anim delay-3">
            
            <a href="https://auth.projets.marescal.fr/register.php?origin=stvalentin" class="btn btn-custom-primary">
                <i class="fas fa-magic me-2"></i> Créer mon compte
                <div style="font-size: 0.75rem; opacity: 0.9; font-weight: normal;">Pour suivre mes commandes</div>
            </a>

            <div class="divider">OU</div>

            <a href="order.php?guest=1" class="btn btn-custom-secondary">
                <i class="fas fa-user-secret me-2"></i> Mode Invité Rapide
                <div style="font-size: 0.75rem; color: #adb5bd; font-weight: normal;">Aucun suivi</div>
            </a>
        </div>

        <div class="mt-4 pt-2 stagger-anim delay-4">
            <p class="small text-muted mb-0">
                Vous avez déjà un compte ? <br>
                <a href="https://auth.projets.marescal.fr/index.php?redirect=<?php echo $encoded_target; ?>" class="login-link">
                    Se connecter ici <i class="fas fa-arrow-right ms-1"></i>
                </a>
            </p>
        </div>
    </div>
    
    <div class="text-center mt-3 text-white small opacity-75 stagger-anim delay-5">
        &copy; 2026 CVL - Lycée Jules Fil
    </div>
</div>

<script>
    document.addEventListener("DOMContentLoaded", function() {
        const container = document.getElementById('floatingHearts');
        const symbols = ['❤️', '🌹', '✨', '💖'];
        const particleCount = 15; // Nombre de particules

        for (let i = 0; i < particleCount; i++) {
            const span = document.createElement('span');
            span.classList.add('floating-item');
            
            // Symbole aléatoire
            span.innerText = symbols[Math.floor(Math.random() * symbols.length)];
            
            // Position horizontale aléatoire (0 à 100%)
            span.style.left = Math.random() * 100 + '%';
            
            // Taille aléatoire (plus ou moins gros)
            const size = Math.random() * 1.5 + 1; // entre 1rem et 2.5rem
            span.style.fontSize = size + 'rem';
            
            // Durée d'animation aléatoire (entre 10s et 25s pour varier les vitesses)
            const duration = Math.random() * 15 + 10;
            span.style.animationDuration = duration + 's';
            
            // Délai aléatoire pour qu'ils ne partent pas tous en même temps
            span.style.animationDelay = '-' + (Math.random() * 20) + 's'; // Le "-" fait commencer l'anim au milieu
            
            container.appendChild(span);
        }
    });
</script>

</body>
</html>