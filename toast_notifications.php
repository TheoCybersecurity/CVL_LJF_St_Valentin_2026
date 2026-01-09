<?php
// toast_notifications.php

if (isset($_GET['last_action'], $_GET['last_ids'])): 
    $lastAction = $_GET['last_action'];
    $lastIds = htmlspecialchars($_GET['last_ids']);
?>

<style>
    .toast-container {
        overflow: hidden; 
        padding-right: 1rem;
    }

    /* État caché initial */
    .toast.fade {
        transition: transform 0.5s cubic-bezier(0.23, 1, 0.32, 1), opacity 0.5s ease !important;
        transform: translateX(120%) !important;
        opacity: 0 !important;
        display: block !important;
    }
    
    /* État visible */
    .toast.fade.show, .toast.fade.showing {
        transform: translateX(0) !important;
        opacity: 1 !important;
    }

    /* État de sortie (glissement) */
    .toast.fade.hiding {
        transform: translateX(120%) !important;
        opacity: 0 !important;
        transition: transform 0.5s cubic-bezier(0.23, 1, 0.32, 1), opacity 0.5s ease !important;
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
        animation: countdown 10s linear forwards; 
    }
    .toast:hover .progress-fill { animation-play-state: paused; }
    @keyframes countdown { from { width: 100%; } to { width: 0%; } }
</style>

<div class="toast-container position-fixed bottom-0 end-0 p-3" style="z-index: 11">
    <div id="undoToast" class="toast fade align-items-center text-white bg-dark border-0 position-relative" role="alert" aria-live="assertive" aria-atomic="true">
        <div class="d-flex">
            <div class="toast-body">
                <?php if($lastAction == 'marked'): ?>
                    <i class="fas fa-check-circle text-success"></i> Action effectuée avec succès.
                <?php elseif($lastAction == 'unmarked'): ?>
                    <i class="fas fa-undo text-warning"></i> Action annulée.
                <?php else: ?>
                    <i class="fas fa-info-circle"></i> Mise à jour effectuée.
                <?php endif; ?>
            </div>
            
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
            
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
        </div>
        
        <div class="progress-track">
            <div class="progress-fill"></div>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        var toastEl = document.getElementById('undoToast');
        if (toastEl) {
            var toast = new bootstrap.Toast(toastEl, { delay: 10000 });
            toast.show();

            // --- NOUVEAU : Nettoyage de l'URL à la fermeture ---
            // On écoute l'événement Bootstrap "hidden.bs.toast" (quand le toast a fini de disparaître)
            toastEl.addEventListener('hidden.bs.toast', function () {
                // On récupère l'URL actuelle
                var url = new URL(window.location.href);
                
                // On supprime les paramètres gênants
                url.searchParams.delete('last_action');
                url.searchParams.delete('last_ids');
                
                // On met à jour la barre d'adresse sans recharger la page
                window.history.replaceState({}, '', url);
            });
        }
    });
</script>

<?php endif; ?>