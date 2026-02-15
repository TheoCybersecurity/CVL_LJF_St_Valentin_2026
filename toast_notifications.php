<?php
// toast_notifications.php

// Initialisation des variables
$mode = 'none';
$message = '';
$showUndo = false;
$toastType = 'success'; // Pour la couleur de la barre de progression

// ==========================================
// 1. LOGIQUE DE DÉTECTION
// ==========================================

// CAS 1 : Action Admin avec possibilité d'annulation (Undo) via URL
if (isset($_GET['last_action'], $_GET['last_ids'])) {
    $mode = 'undo';
    $lastAction = $_GET['last_action'];
    $lastIds = htmlspecialchars($_GET['last_ids']); // Sécurité
    $showUndo = true;
    
    if($lastAction == 'marked') {
        $message = '<i class="fas fa-check-circle text-success me-2"></i>Marqué comme "Préparé".';
    } elseif($lastAction == 'unmarked') {
        $message = '<i class="fas fa-undo text-warning me-2"></i>Action annulée.';
    } elseif($lastAction == 'distributed') {
        $message = '<i class="fas fa-truck text-success me-2"></i>Marqué comme "Distribué".';
    } else {
        $message = '<i class="fas fa-info-circle me-2"></i>Mise à jour effectuée.';
    }
} 
// CAS 2 : Notification via SESSION (Standard recommandé)
elseif (isset($_SESSION['toast'])) {
    $mode = 'session';
    $type = $_SESSION['toast']['type'] ?? 'success'; // success, warning, danger
    $text = htmlspecialchars($_SESSION['toast']['message'] ?? '');
    $toastType = $type;

    if ($type === 'danger' || $type === 'error') {
        $message = '<i class="fas fa-exclamation-circle text-danger me-2"></i>' . $text;
    } elseif ($type === 'warning') {
        $message = '<i class="fas fa-exclamation-triangle text-warning me-2"></i>' . $text;
    } else {
        $message = '<i class="fas fa-check-circle text-success me-2"></i>' . $text;
    }

    // Nettoyage immédiat de la session
    unset($_SESSION['toast']);
}
// CAS 3 : Notification simple via URL (Legacy)
elseif (isset($_GET['msg_success'])) {
    $mode = 'simple';
    $message = '<i class="fas fa-check-circle text-success me-2"></i>' . htmlspecialchars($_GET['msg_success']);
}
elseif (isset($_GET['msg_error'])) {
    $mode = 'simple';
    $toastType = 'danger';
    $message = '<i class="fas fa-exclamation-circle text-danger me-2"></i>' . htmlspecialchars($_GET['msg_error']);
}
?>

<style>
    /* Conteneur qui laisse passer les clics en dessous */
    .toast-container {
        pointer-events: none;
        padding-bottom: 1rem;
    }
    /* Le toast lui-même doit recevoir les clics (bouton fermer) */
    .toast {
        pointer-events: auto;
        background-color: #343a40; /* Dark bg */
        color: white;
    }

    /* Animation d'entrée personnalisée */
    .toast.fade {
        transition: transform 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275), opacity 0.4s ease;
        transform: translateX(120%); /* Part de la droite */
        opacity: 0;
    }
    
    .toast.fade.show, .toast.show {
        transform: translateX(0) !important;
        opacity: 1 !important;
    }

    /* Barre de progression (Compte à rebours visuel) */
    .progress-track {
        height: 4px;
        width: 100%;
        background-color: rgba(255,255,255,0.1);
        position: absolute;
        bottom: 0;
        left: 0;
        border-bottom-left-radius: 0.25rem;
        border-bottom-right-radius: 0.25rem;
        overflow: hidden;
    }
    .progress-fill {
        height: 100%;
        background-color: rgba(255,255,255,0.8);
        width: 100%;
        animation: toastCountdown 5s linear forwards; 
        transform-origin: left;
    }
    
    /* Pause de l'animation au survol */
    .toast:hover .progress-fill {
        animation-play-state: paused;
    }

    @keyframes toastCountdown {
        from { transform: scaleX(1); }
        to { transform: scaleX(0); }
    }
</style>

<div class="toast-container position-fixed bottom-0 end-0 p-3" style="z-index: 1060;">
    
    <?php if ($mode != 'none'): ?>
    <div id="phpToast" class="toast align-items-center border-0 mb-2 position-relative shadow-lg" role="alert" aria-live="assertive" aria-atomic="true">
        <div class="d-flex">
            <div class="toast-body d-flex align-items-center">
                <?php echo $message; ?>
            </div>
            
            <?php if ($showUndo): ?>
                <div class="d-flex align-items-center pe-2 border-start border-secondary ms-auto ps-2">
                    <form method="POST" class="m-0">
                        <input type="hidden" name="recipient_ids" value="<?php echo $lastIds; ?>">
                        
                        <?php if($lastAction == 'marked'): ?>
                            <input type="hidden" name="unmark_prepared" value="1">
                            <button type="submit" class="btn btn-sm btn-link text-white text-decoration-none fw-bold" style="font-size: 0.85rem;">
                                <i class="fas fa-undo me-1"></i>Annuler
                            </button>
                        <?php elseif($lastAction == 'unmarked'): ?>
                            <input type="hidden" name="mark_prepared" value="1">
                            <button type="submit" class="btn btn-sm btn-link text-white text-decoration-none fw-bold" style="font-size: 0.85rem;">
                                Rétablir
                            </button>
                        <?php endif; ?>
                    </form>
                </div>
            <?php endif; ?>
            
            <button type="button" class="btn-close btn-close-white me-2 m-auto <?php echo $showUndo ? '' : 'ms-auto'; ?>" data-bs-dismiss="toast" aria-label="Close"></button>
        </div>
        <div class="progress-track"><div class="progress-fill" style="animation-duration: 5s;"></div></div>
    </div>
    <?php endif; ?>

    <div id="js-toast-anchor"></div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // 1. Initialisation du Toast PHP
    var toastEl = document.getElementById('phpToast');
    if (toastEl) {
        var toast = new bootstrap.Toast(toastEl, { delay: 5000 });
        toast.show();

        // Nettoyage de l'URL à la fermeture pour éviter de ré-afficher le toast au refresh
        toastEl.addEventListener('hidden.bs.toast', function () {
            const url = new URL(window.location.href);
            const params = ['last_action', 'last_ids', 'msg_success', 'msg_error'];
            let changed = false;
            
            params.forEach(p => {
                if(url.searchParams.has(p)) {
                    url.searchParams.delete(p);
                    changed = true;
                }
            });

            if(changed) {
                window.history.replaceState({}, '', url);
            }
        });
    }
});

/**
 * Fonction globale pour afficher un toast dynamiquement via JS (ex: après un fetch)
 * @param {string} message - Le texte ou HTML à afficher
 * @param {string} type - 'success', 'danger', 'warning', 'info'
 */
function showToast(message, type = 'success') {
    const container = document.getElementById('js-toast-anchor');
    let icon = '';
    let barColor = 'rgba(255,255,255,0.8)'; // Blanc par défaut
    
    // Configuration selon le type
    switch(type) {
        case 'danger': 
        case 'error':
            icon = '<i class="fas fa-exclamation-circle text-danger me-2"></i>';
            barColor = '#dc3545'; 
            break;
        case 'warning':
            icon = '<i class="fas fa-exclamation-triangle text-warning me-2"></i>';
            barColor = '#ffc107';
            break;
        case 'info':
            icon = '<i class="fas fa-info-circle text-info me-2"></i>';
            break;
        default: // success
            icon = '<i class="fas fa-check-circle text-success me-2"></i>';
            barColor = '#198754';
    }

    // Template HTML
    const toastHtml = `
        <div class="toast align-items-center border-0 mb-2 position-relative shadow-lg" role="alert" aria-live="assertive" aria-atomic="true">
            <div class="d-flex">
                <div class="toast-body d-flex align-items-center">
                    ${icon} ${message}
                </div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto ms-auto" data-bs-dismiss="toast" aria-label="Close"></button>
            </div>
            <div class="progress-track">
                <div class="progress-fill" style="background-color: ${barColor}; animation-duration: 4s;"></div>
            </div>
        </div>
    `;

    // Création de l'élément DOM
    const tempDiv = document.createElement('div');
    tempDiv.innerHTML = toastHtml.trim();
    const toastElement = tempDiv.firstChild;

    // Ajout au DOM
    container.appendChild(toastElement);

    // Initialisation Bootstrap
    const bsToast = new bootstrap.Toast(toastElement, { delay: 4000 });
    bsToast.show();

    // Suppression du DOM après fermeture
    toastElement.addEventListener('hidden.bs.toast', function () {
        toastElement.remove();
    });
}
</script>