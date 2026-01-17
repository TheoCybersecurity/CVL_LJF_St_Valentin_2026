<?php
// import_edt.php
session_start();
require_once 'db.php';
require_once 'auth_check.php';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Importation Données - St Valentin 2026</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .step-card { border-left: 5px solid #6c757d; transition: all 0.3s; }
        .step-active { border-left-color: #0d6efd; box-shadow: 0 .5rem 1rem rgba(0,0,0,.15)!important; }
        .file-list-container { max-height: 300px; overflow-y: auto; background-color: #f8f9fa; }
        .toast-container-custom { position: fixed; bottom: 20px; right: 20px; z-index: 1055; }
        .error-log-container { background: #fff3cd; border: 1px solid #ffeeba; border-radius: 5px; padding: 15px; margin-top: 20px; display: none; }
    </style>
</head>
<body class="bg-light">

<?php include 'navbar.php'; ?>
<?php include 'toast_notifications.php'; ?>

<div class="toast-container toast-container-custom" id="ajaxToastContainer"></div>

<div class="container mt-5 mb-5">
    
    <div class="mb-4 text-center">
        <h2 class="fw-bold">Importation des Données</h2>
        <p class="text-muted">Procédure optimisée avec rapport final.</p>
    </div>

    <div class="card shadow-sm mb-4 step-card step-active" id="cardCsv">
        <div class="card-header bg-white py-3">
            <h5 class="mb-0 fw-bold text-primary"><i class="fas fa-users me-2"></i>ÉTAPE 1 : Base Élèves (CSV)</h5>
        </div>
        <div class="card-body">
            <div class="row align-items-end">
                <div class="col-md-8">
                    <label class="form-label">Fichier CSV (3 colonnes : Nom ; Prénom ; Classe)</label>
                    <input type="file" class="form-control" id="csvInput" accept=".csv">
                </div>
                <div class="col-md-4">
                    <button onclick="importCSV()" class="btn btn-primary w-100" id="btnCsv">
                        <i class="fas fa-file-csv me-2"></i>Importer les élèves
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm step-card" id="cardIcs">
        <div class="card-header bg-white py-3">
            <h5 class="mb-0 fw-bold text-success"><i class="fas fa-calendar-alt me-2"></i>ÉTAPE 2 : Emplois du temps (ICS)</h5>
        </div>
        <div class="card-body">
            
            <div class="row g-4">
                <div class="col-md-5">
                    <div class="border rounded p-3 bg-white h-100">
                        <label class="form-label fw-bold">1. Envoyer sur le serveur</label>
                        <input class="form-control mb-3" type="file" id="icsInput" multiple accept=".ics">
                        
                        <div class="progress mb-3 d-none" id="uploadProgressWrapper" style="height: 15px;">
                            <div class="progress-bar progress-bar-striped progress-bar-animated" id="uploadProgressBar" style="width: 0%"></div>
                        </div>

                        <button onclick="uploadFilesBatch()" class="btn btn-success w-100" id="btnUploadIcs">
                            <i class="fas fa-cloud-upload-alt me-2"></i>Envoyer
                        </button>
                    </div>
                </div>

                <div class="col-md-7">
                    <div class="border rounded p-3 bg-white h-100">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <label class="form-label fw-bold mb-0">2. Traitement</label>
                            <div>
                                <button onclick="manualCleanup()" class="btn btn-sm btn-danger me-2">
                                    <i class="fas fa-trash-alt me-1"></i>Vider cache
                                </button>
                                <button onclick="startIntegration()" class="btn btn-outline-success btn-sm" id="btnProcess" disabled>
                                    <i class="fas fa-play me-1"></i>Lancer
                                </button>
                            </div>
                        </div>
                        
                        <div class="progress mb-2 d-none" id="processProgressWrapper" style="height: 10px;">
                            <div class="progress-bar bg-info" id="processProgressBar" style="width: 0%"></div>
                        </div>

                        <ul class="list-group file-list-container" id="fileListContainer">
                            <li class="list-group-item text-center text-muted small py-3">Aucun fichier en attente.</li>
                        </ul>
                    </div>
                </div>
            </div>

            <div id="errorReport" class="error-log-container">
                <h5 class="text-danger fw-bold"><i class="fas fa-exclamation-triangle me-2"></i>Erreurs / Échecs</h5>
                <ul class="list-group list-group-flush small" id="errorLogList"></ul>
            </div>

        </div>
    </div>

</div>

<div class="modal fade" id="modalReport" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="fas fa-check-circle me-2"></i>Traitement terminé</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center">
                <h4 class="fw-bold mb-4">Rapport d'importation</h4>
                
                <div class="row g-2">
                    <div class="col-4">
                        <div class="p-3 border rounded bg-light">
                            <div class="h2 text-success fw-bold" id="countInserted">0</div>
                            <small class="text-uppercase fw-bold text-muted">Insérés</small>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="p-3 border rounded bg-light">
                            <div class="h2 text-warning fw-bold" id="countUpdated">0</div>
                            <small class="text-uppercase fw-bold text-muted">Modifiés</small>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="p-3 border rounded bg-light">
                            <div class="h2 text-secondary fw-bold" id="countUnchanged">0</div>
                            <small class="text-uppercase fw-bold text-muted">Identiques</small>
                        </div>
                    </div>
                </div>
                
                <div class="mt-4 pt-3 border-top">
                    <div class="d-flex justify-content-between text-muted small px-4">
                        <span>Ignorés (PAEN) : <strong id="countSkipped">0</strong></span>
                        <span>Erreurs : <strong id="countErrors" class="text-danger">0</strong></span>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary w-100" data-bs-dismiss="modal">Fermer</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // --- TOASTS ---
    function showToast(message, type = 'info') {
        const container = document.getElementById('ajaxToastContainer');
        const id = 'toast-' + Date.now();
        let bg = type === 'success' ? 'text-bg-success' : (type === 'error' ? 'text-bg-danger' : 'text-bg-primary');
        const html = `
            <div id="${id}" class="toast align-items-center ${bg} border-0 mb-2" role="alert" aria-live="assertive" aria-atomic="true">
                <div class="d-flex"><div class="toast-body">${message}</div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>
            </div>`;
        container.insertAdjacentHTML('beforeend', html);
        new bootstrap.Toast(document.getElementById(id), { delay: 5000 }).show();
    }

    // --- ETAPE 1 : IMPORT CSV ---
    async function importCSV() {
        const input = document.getElementById('csvInput');
        const btn = document.getElementById('btnCsv');

        if (input.files.length === 0) { showToast("Sélectionnez un fichier CSV.", "error"); return; }

        const fd = new FormData();
        fd.append('action', 'import_csv');
        fd.append('csv_file', input.files[0]);

        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Traitement...';

        try {
            const req = await fetch('backend_import', { method: 'POST', body: fd });
            const res = await req.json();
            if (res.status === 'success') {
                showToast(res.message, "success");
                document.getElementById('cardCsv').classList.remove('step-active');
                document.getElementById('cardIcs').classList.add('step-active');
            } else {
                showToast(res.message, "error");
            }
        } catch (e) {
            showToast("Erreur serveur lors de l'import CSV.", "error");
        }
        
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-file-csv me-2"></i>Importer les élèves';
    }

    // --- ETAPE 2 : UPLOAD BATCH ---
    async function uploadFilesBatch() {
        const input = document.getElementById('icsInput');
        const files = input.files;
        if (files.length === 0) { showToast("Sélectionnez des fichiers .ics", "error"); return; }

        const btn = document.getElementById('btnUploadIcs');
        const pWrapper = document.getElementById('uploadProgressWrapper');
        const pBar = document.getElementById('uploadProgressBar');

        btn.disabled = true;
        pWrapper.classList.remove('d-none');
        
        const BATCH_SIZE = 10;
        let uploaded = 0;
        let success = 0;

        for (let i = 0; i < files.length; i += BATCH_SIZE) {
            const chunk = Array.from(files).slice(i, i + BATCH_SIZE);
            const fd = new FormData();
            chunk.forEach(f => fd.append('files[]', f));
            fd.append('action', 'upload');

            try {
                const req = await fetch('backend_import', { method: 'POST', body: fd });
                if (req.ok) success += chunk.length;
            } catch(e) { console.error(e); }

            uploaded += chunk.length;
            pBar.style.width = Math.round((uploaded/files.length)*100) + '%';
        }

        showToast(`${success} fichiers envoyés.`, "success");
        input.value = '';
        setTimeout(() => pWrapper.classList.add('d-none'), 1000);
        btn.disabled = false;
        fetchFileList();
    }

    // --- CLEANUP ---
    async function manualCleanup() {
        if(!confirm("Supprimer TOUS les fichiers du dossier temporaire ?")) return;
        const fd = new FormData(); fd.append('action', 'cleanup');
        const res = await fetch('backend_import', { method: 'POST', body: fd });
        const data = await res.json();
        showToast(`${data.count} fichiers supprimés.`, 'success');
        fetchFileList();
    }

    // --- LISTING ---
    let currentFiles = [];
    async function fetchFileList() {
        const fd = new FormData(); fd.append('action', 'list');
        const res = await fetch('backend_import', { method: 'POST', body: fd });
        const data = await res.json();
        currentFiles = data.files || [];
        renderList();
        
        document.getElementById('errorReport').style.display = 'none';
        document.getElementById('errorLogList').innerHTML = '';
    }

    function renderList() {
        const container = document.getElementById('fileListContainer');
        const btnProc = document.getElementById('btnProcess');
        container.innerHTML = '';
        
        if (currentFiles.length === 0) {
            container.innerHTML = '<li class="list-group-item text-center text-muted small py-3">Aucun fichier en attente.</li>';
            btnProc.disabled = true;
            return;
        }
        btnProc.disabled = false;

        currentFiles.forEach((f, idx) => {
            container.insertAdjacentHTML('beforeend', `
                <li class="list-group-item d-flex justify-content-between align-items-center p-2" id="row-${idx}">
                    <div class="small text-truncate" style="max-width: 70%;" title="${f.filename}">
                        <i class="far fa-file-alt me-2 text-muted"></i>${f.student}
                    </div>
                    <span class="badge bg-secondary" id="badge-${idx}">Attente</span>
                </li>
            `);
        });
    }

    // --- TRAITEMENT & RAPPORT ---
    async function startIntegration() {
        const btn = document.getElementById('btnProcess');
        const pWrapper = document.getElementById('processProgressWrapper');
        const pBar = document.getElementById('processProgressBar');
        const errorReport = document.getElementById('errorReport');
        const errorList = document.getElementById('errorLogList');
        
        btn.disabled = true;
        pWrapper.classList.remove('d-none');
        errorReport.style.display = 'none';
        errorList.innerHTML = '';
        
        // COMPTEURS RAPPORT
        let stats = { inserted: 0, updated: 0, unchanged: 0, skipped: 0, errors: 0 };
        let count = 0;

        for (let i = 0; i < currentFiles.length; i++) {
            const f = currentFiles[i];
            const badge = document.getElementById(`badge-${i}`);
            badge.className = 'badge bg-warning text-dark'; badge.innerText = '...';

            const fd = new FormData();
            fd.append('action', 'process');
            fd.append('filename', f.filename);
            
            try {
                const req = await fetch('backend_import', { method: 'POST', body: fd });
                const res = await req.json();
                
                if (res.status === 'inserted') {
                    badge.className = 'badge bg-success'; badge.innerText = 'Ajouté';
                    stats.inserted++;
                } else if (res.status === 'updated') {
                    badge.className = 'badge bg-warning text-dark'; badge.innerText = 'Modifié';
                    stats.updated++;
                } else if (res.status === 'unchanged') {
                    badge.className = 'badge bg-info text-dark'; badge.innerText = 'Identique';
                    stats.unchanged++;
                } else if (res.status === 'skipped') {
                    badge.className = 'badge bg-secondary'; badge.innerText = 'Ignoré';
                    stats.skipped++;
                } else {
                    // ERROR
                    badge.className = 'badge bg-danger'; badge.innerText = 'Erreur';
                    stats.errors++;
                    errorList.insertAdjacentHTML('beforeend', `
                        <li class="list-group-item list-group-item-danger">
                            <strong>${f.filename}</strong> : ${res.message}
                        </li>
                    `);
                }
            } catch(e) {
                badge.className = 'badge bg-danger'; badge.innerText = 'Crash';
                stats.errors++;
            }

            count++;
            pBar.style.width = Math.round((count/currentFiles.length)*100) + '%';
            document.getElementById(`row-${i}`).scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
        
        pWrapper.classList.add('d-none');
        
        if (stats.errors > 0) errorReport.style.display = 'block';

        // AFFICHAGE MODAL
        document.getElementById('countInserted').innerText = stats.inserted;
        document.getElementById('countUpdated').innerText = stats.updated;
        document.getElementById('countUnchanged').innerText = stats.unchanged;
        document.getElementById('countSkipped').innerText = stats.skipped;
        document.getElementById('countErrors').innerText = stats.errors;

        const reportModal = new bootstrap.Modal(document.getElementById('modalReport'));
        reportModal.show();

        // Rafraichir liste si nécessaire (pour enlever ceux qui sont faits)
        // On ne le fait pas tout de suite pour laisser l'utilisateur voir les badges
    }
    
    // Init
    fetchFileList();
</script>
</body>
</html>