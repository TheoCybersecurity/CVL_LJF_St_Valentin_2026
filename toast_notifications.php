<?php
// toast_notifications.php

// 1. Détection du mode
$mode = 'none';
$message = '';
$showUndo = false;

// Cas 1 : Admin / Undo (Paramètres URL existants)
if (isset($_GET['last_action'], $_GET['last_ids'])) {
    $mode = 'undo';
    $lastAction = $_GET['last_action'];
    $lastIds = htmlspecialchars($_GET['last_ids']);
    $showUndo = true;
    
    if($lastAction == 'marked') {
        $message = '<i class="fas fa-check-circle text-success"></i> Action effectuée avec succès.';
    } elseif($lastAction == 'unmarked') {
        $message = '<i class="fas fa-undo text-warning"></i> Action annulée.';
    } else {
        $message = '<i class="fas fa-info-circle"></i> Mise à jour effectuée.';
    }
} 
// Cas 2 : Notification via SESSION (Le nouveau standard pour admin_team.php)
elseif (isset($_SESSION['toast'])) {
    $mode = 'session';
    $type = $_SESSION['toast']['type'] ?? 'success'; // success, warning, danger
    $text = htmlspecialchars($_SESSION['toast']['message'] ?? '');

    // Choix de l'icône selon le type
    if ($type === 'danger' || $type === 'error') {
        $message = '<i class="fas fa-exclamation-circle text-danger"></i> ' . $text;
    } elseif ($type === 'warning') {
        $message = '<i class="fas fa-exclamation-triangle text-warning"></i> ' . $text;
    } else {
        $message = '<i class="fas fa-check-circle text-success"></i> ' . $text;
    }

    // IMPORTANT : On supprime le message de la session pour qu'il ne s'affiche qu'une fois
    unset($_SESSION['toast']);
}
// Cas 3 : Simple Notification URL (Ancienne méthode)
elseif (isset($_GET['msg_success'])) {
    $mode = 'simple';
    $message = '<i class="fas fa-check-circle text-success"></i> ' . htmlspecialchars($_GET['msg_success']);
}
elseif (isset($_GET['msg_error'])) {
    $mode = 'simple';
    $message = '<i class="fas fa-exclamation-circle text-danger"></i> ' . htmlspecialchars($_GET['msg_error']);
}
?>

<style>
    .toast-container {
        overflow: hidden; 
        padding-right: 1rem;
        pointer-events: none;
    }
    .toast {
        pointer-events: auto;
    }

    /* Animation d'entrée et sortie */
    .toast.fade {
        transition: transform 0.5s cubic-bezier(0.23, 1, 0.32, 1), opacity 0.5s ease !important;
        transform: translateX(120%) !important;
        opacity: 0 !important;
        display: block !important;
    }
    
    .toast.fade.show, .toast.fade.showing {
        transform: translateX(0) !important;
        opacity: 1 !important;
    }

    /* Barre de progression */
    .progress-track {
        height: 4px; width: 100%;
        background-color: rgba(255,255,255,0.2);
        position: absolute; bottom: 0; left: 0;
    }
    .progress-fill {
        height: 100%; background-color: rgba(255,255,255,0.9);
        width: 100%;
        animation: countdown 5s linear forwards; 
    }
    .toast:hover .progress-fill { animation-play-state: paused; }
    @keyframes countdown { from { width: 100%; } to { width: 0%; } }
</style>

<?php if ($mode != 'none'): ?>
<div class="toast-container position-fixed bottom-0 end-0 p-3" style="z-index: 9999">
    <div id="phpToast" class="toast fade align-items-center text-white bg-dark border-0 position-relative" role="alert" aria-live="assertive" aria-atomic="true">
        <div class="d-flex">
            <div class="toast-body">
                <?php echo $message; ?>
            </div>
            
            <?php if ($showUndo): ?>
                <form method="POST" class="d-flex align-items-center pe-2">
                    <input type="hidden" name="recipient_ids" value="<?php echo $lastIds; ?>">
                    <?php if($lastAction == 'marked'): ?>
                        <input type="hidden" name="unmark_prepared" value="1">
                        <button type="submit" class="btn btn-sm btn-outline-light">Annuler</button>
                    <?php else: ?>
                        <input type="hidden" name="mark_prepared" value="1">
                        <button type="submit" class="btn btn-sm btn-outline-light">Rétablir</button>
                    <?php endif; ?>
                </form>
            <?php endif; ?>
            
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
        </div>
        <div class="progress-track"><div class="progress-fill"></div></div>
    </div>
</div>
<?php endif; ?>

<div id="js-toast-container" class="toast-container position-fixed bottom-0 end-0 p-3" style="z-index: 9999"></div>

<script>
    // 1. Gestion du Toast PHP (Généré au chargement de la page)
    document.addEventListener('DOMContentLoaded', function () {
        var toastEl = document.getElementById('phpToast');
        if (toastEl) {
            var toast = new bootstrap.Toast(toastEl, { delay: 5000 });
            toast.show();

            toastEl.addEventListener('hidden.bs.toast', function () {
                // Nettoyage visuel de l'URL si on utilise des paramètres GET
                var url = new URL(window.location.href);
                if (url.searchParams.has('last_action') || url.searchParams.has('msg_success')) {
                    url.searchParams.delete('last_action');
                    url.searchParams.delete('last_ids');
                    url.searchParams.delete('msg_success');
                    url.searchParams.delete('msg_error');
                    window.history.replaceState({}, '', url);
                }
            });
        }
    });

    // 2. FONCTION JS POUR AJAX (Appelée dynamiquement)
    function showToast(message, type = 'success') {
        const container = document.getElementById('js-toast-container');
        let icon = type === 'error' ? '<i class="fas fa-exclamation-circle text-danger"></i>' : '<i class="fas fa-check-circle text-success"></i>';
        
        const toastHtml = `
            <div class="toast fade align-items-center text-white bg-dark border-0 position-relative" role="alert" aria-live="assertive" aria-atomic="true">
                <div class="d-flex">
                    <div class="toast-body">
                        ${icon} ${message}
                    </div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
                </div>
                <div class="progress-track"><div class="progress-fill"></div></div>
            </div>
        `;

        const tempDiv = document.createElement('div');
        tempDiv.innerHTML = toastHtml;
        const toastElement = tempDiv.firstElementChild;
        container.appendChild(toastElement);

        const bsToast = new bootstrap.Toast(toastElement, { delay: 5000 });
        
        // Petit délai pour permettre au CSS de voir l'élément avant de lancer l'anim
        requestAnimationFrame(() => {
            bsToast.show();
        });

        toastElement.addEventListener('hidden.bs.toast', function () {
            setTimeout(() => {
                if (toastElement && toastElement.parentNode) {
                    toastElement.remove();
                }
            }, 600);
        });
    }
</script>