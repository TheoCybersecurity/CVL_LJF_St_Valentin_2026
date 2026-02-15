<?php
// welcome.php

// 1. Redirection si déjà connecté
if (isset($_COOKIE['jwt'])) {
    header("Location: setup.php");
    exit;
}

// 2. Préparation du lien de redirection pour le SSO / Auth
// L'utilisateur sera redirigé vers setup.php après connexion réussie
$target = 'https://cvl-ljf-st-valentin-2026.projets.marescal.fr/setup.php';
$encoded_target = base64_encode($target);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <title>Saint Valentin 2026 - CVL</title>
    <?php include 'head_imports.php'; ?>
    <style>
        /* --- FOND ET ANIMATIONS GLOBALES --- */
        body {
            background: linear-gradient(-45deg, #ff9a9e, #fad0c4, #fad0c4, #fbc2eb, #a18cd1);
            background-size: 400% 400%;
            animation: gradientBG 15s ease infinite;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            overflow: hidden;
            position: relative;
            margin: 0;
        }

        @keyframes gradientBG {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }

        /* --- PARTICULES D'ARRIÈRE-PLAN --- */
        .floating-container {
            position: absolute;
            top: 0; left: 0; width: 100%; height: 100%;
            overflow: hidden;
            z-index: 0;
            pointer-events: none;
        }
        .floating-item {
            position: absolute;
            bottom: -100px; /* Commence hors écran */
            display: block;
            opacity: 0;
            animation: floatUp linear infinite;
        }
        @keyframes floatUp {
            0% { transform: translateY(0) rotate(0deg); opacity: 0; }
            10% { opacity: 0.6; }
            90% { opacity: 0.3; }
            100% { transform: translateY(-110vh) rotate(360deg); opacity: 0; }
        }

        /* --- FORMES FLOUES (GLOW) --- */
        .bg-shape {
            position: absolute;
            filter: blur(80px);
            z-index: 1;
            opacity: 0.6;
            animation: pulseShape 8s ease-in-out infinite alternate;
        }
        .shape-1 { 
            background: rgba(255, 255, 255, 0.5); 
            width: 400px; height: 400px; 
            border-radius: 50%; 
            top: -100px; left: -100px; 
        }
        .shape-2 { 
            background: rgba(255, 105, 180, 0.4); 
            width: 300px; height: 300px; 
            border-radius: 50%; 
            bottom: -50px; right: -50px; 
            animation-delay: -2s; 
        }

        @keyframes pulseShape {
            0% { transform: scale(1); }
            100% { transform: scale(1.15); }
        }

        /* --- CARTE PRINCIPALE (GLASSMORPHISM) --- */
        .card-welcome {
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(25px);
            -webkit-backdrop-filter: blur(25px);
            border: 1px solid rgba(255, 255, 255, 0.6);
            border-radius: 24px;
            box-shadow: 0 25px 50px rgba(0,0,0,0.1);
            max-width: 420px;
            width: 90%;
            padding: 2.5rem 2rem;
            text-align: center;
            position: relative;
            z-index: 10;
            opacity: 0;
            transform: translateY(20px);
            animation: cardEntrance 0.8s cubic-bezier(0.2, 0.8, 0.2, 1) forwards;
        }

        @keyframes cardEntrance {
            to { opacity: 1; transform: translateY(0); }
        }

        /* --- LOGO --- */
        .logo-img {
            width: 130px;
            height: auto;
            filter: drop-shadow(0 4px 10px rgba(220, 53, 69, 0.2));
            animation: heartBeat 2.5s infinite;
            margin-bottom: 1rem;
        }
        @keyframes heartBeat {
            0%, 100% { transform: scale(1); }
            15% { transform: scale(1.08); }
            30% { transform: scale(1); }
            45% { transform: scale(1.08); }
            60% { transform: scale(1); }
        }

        /* --- TYPOGRAPHIE --- */
        h1 {
            font-weight: 800;
            color: #d63384; 
            margin-bottom: 0.5rem;
            font-size: 1.75rem;
            letter-spacing: -0.5px;
        }
        .subtitle {
            color: #6c757d;
            font-size: 0.95rem;
            margin-bottom: 2rem;
            line-height: 1.6;
        }

        /* --- BOUTONS --- */
        .btn-custom-primary {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #d63384, #dc3545);
            border: none;
            color: white;
            border-radius: 15px;
            padding: 12px 20px;
            box-shadow: 0 4px 15px rgba(214, 51, 132, 0.3);
            transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
            text-decoration: none;
            position: relative;
            overflow: hidden;
        }
        .btn-custom-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(214, 51, 132, 0.5);
            color: white;
        }
        .btn-main-text {
            font-weight: 700;
            font-size: 1.1rem;
            display: block;
            margin-bottom: 2px;
        }
        .btn-sub-text {
            font-size: 0.75rem;
            opacity: 0.9;
            font-weight: 400;
            display: block;
        }

        .btn-custom-secondary {
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(255,255,255,0.7);
            border: 2px solid #e9ecef;
            color: #6c757d;
            font-weight: 600;
            border-radius: 50px;
            padding: 10px 20px;
            transition: all 0.2s ease;
            text-decoration: none;
            font-size: 0.9rem;
        }
        .btn-custom-secondary:hover {
            background: white;
            border-color: #ced4da;
            color: #495057;
            transform: translateY(-2px);
        }

        /* --- LIENS ET DIVIDERS --- */
        .divider {
            display: flex;
            align-items: center;
            text-align: center;
            margin: 1.5rem 0;
            color: #adb5bd;
            font-size: 0.8rem;
            font-weight: 600;
            letter-spacing: 1px;
        }
        .divider::before, .divider::after {
            content: '';
            flex: 1;
            border-bottom: 1px solid rgba(0,0,0,0.1);
        }
        .divider:not(:empty)::before { margin-right: 1em; }
        .divider:not(:empty)::after { margin-left: 1em; }

        .login-link {
            color: #d63384;
            font-weight: 600;
            text-decoration: none;
            border-bottom: 2px solid transparent;
            transition: border-color 0.3s;
        }
        .login-link:hover {
            border-color: #d63384;
            color: #be206b;
        }

        /* --- ANIMATION EN CASCADE (STAGGER) --- */
        .stagger-anim {
            opacity: 0;
            transform: translateY(15px);
            animation: fadeUp 0.6s ease forwards;
        }
        .delay-1 { animation-delay: 0.2s; }
        .delay-2 { animation-delay: 0.4s; }
        .delay-3 { animation-delay: 0.6s; }
        .delay-4 { animation-delay: 0.8s; }
        .delay-5 { animation-delay: 1.0s; }

        @keyframes fadeUp {
            to { opacity: 1; transform: translateY(0); }
        }

        /* --- FOOTER --- */
        .footer-text {
            position: absolute;
            bottom: 15px;
            width: 100%;
            text-align: center;
            color: rgba(255,255,255,0.8);
            font-size: 0.75rem;
            z-index: 10;
        }
    </style>
</head>
<body>

    <div class="bg-shape shape-1"></div>
    <div class="bg-shape shape-2"></div>
    <div class="floating-container" id="floatingHearts"></div>

    <div class="card-welcome">
        
        <div class="logo-container stagger-anim delay-1">
            <img src="images/CVL_LJF_St_Valentin_2026.png" alt="Logo Saint Valentin" class="logo-img">
        </div>

        <h1 class="stagger-anim delay-2">Saint Valentin 2026</h1>
        <p class="subtitle stagger-anim delay-2">
            La plateforme officielle du CVL.<br>
            Envoyez des roses à ceux que vous aimez ! 🌹
        </p>

        <div class="d-grid gap-3 stagger-anim delay-3 px-2">
            
            <a href="https://auth.projets.marescal.fr/register.php?origin=stvalentin" class="btn-custom-primary">
                <span class="btn-main-text"><i class="fas fa-magic me-2"></i>Commencer</span>
                <span class="btn-sub-text">Créer un compte pour suivre mes commandes</span>
            </a>

            <div class="divider">OU</div>

            <a href="order.php?guest=1" class="btn-custom-secondary">
                <i class="fas fa-user-secret me-2"></i> Mode Invité (Rapide)
            </a>
        </div>

        <div class="mt-4 pt-2 stagger-anim delay-4">
            <p class="small text-muted mb-0">
                Vous avez déjà un compte ? <br>
                <a href="https://auth.projets.marescal.fr/index.php?redirect=<?php echo $encoded_target; ?>" class="login-link">
                    Se connecter <i class="fas fa-arrow-right ms-1"></i>
                </a>
            </p>
        </div>
    </div>
    
    <div class="footer-text stagger-anim delay-5">
        &copy; 2026 CVL - Lycée Jules Fil
    </div>

    <script>
        // Génération des particules
        document.addEventListener("DOMContentLoaded", function() {
            const container = document.getElementById('floatingHearts');
            const symbols = ['❤️', '🌹', '✨', '💖', '💌'];
            const particleCount = 18;

            for (let i = 0; i < particleCount; i++) {
                const span = document.createElement('span');
                span.classList.add('floating-item');
                span.innerText = symbols[Math.floor(Math.random() * symbols.length)];
                
                // Position aléatoire
                span.style.left = Math.random() * 100 + '%';
                
                // Taille variable
                const size = Math.random() * 1.2 + 0.8; 
                span.style.fontSize = size + 'rem';
                
                // Durée et délai d'animation
                const duration = Math.random() * 10 + 10; // 10s à 20s
                span.style.animationDuration = duration + 's';
                
                // Délai négatif pour qu'ils soient déjà un peu partout au chargement
                span.style.animationDelay = '-' + (Math.random() * 20) + 's';
                
                container.appendChild(span);
            }
        });
    </script>

</body>
</html>