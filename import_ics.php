<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Importation EDT - St Valentin 2026</title>
    <style>
        body { font-family: 'Segoe UI', sans-serif; max-width: 900px; margin: 30px auto; background: #f0f2f5; color: #333; }
        .container { display: flex; gap: 20px; flex-direction: column; }
        
        /* CARDS */
        .card { background: white; padding: 25px; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        h2 { margin-top: 0; color: #444; font-size: 1.2rem; display: flex; align-items: center; gap: 10px; }
        
        /* BOUTONS */
        .btn { padding: 10px 20px; border-radius: 6px; border: none; cursor: pointer; font-size: 14px; font-weight: 600; transition: 0.2s; }
        .btn-blue { background: #007bff; color: white; }
        .btn-blue:hover { background: #0056b3; }
        .btn-green { background: #28a745; color: white; }
        .btn-green:hover { background: #218838; }
        .btn-red { background: #dc3545; color: white; }
        .btn-red:hover { background: #c82333; }
        .btn-gray { background: #6c757d; color: white; }
        .btn-gray:hover { background: #5a6268; }
        .btn:disabled { background: #ccc; cursor: not-allowed; }

        /* LISTE FICHIERS */
        .file-list { list-style: none; padding: 0; margin-top: 20px; border: 1px solid #eee; border-radius: 8px; overflow: hidden; max-height: 400px; overflow-y: auto; }
        .file-item { display: flex; justify-content: space-between; align-items: center; padding: 12px 15px; border-bottom: 1px solid #eee; background: #fff; }
        .file-item:last-child { border-bottom: none; }
        
        .file-info { display: flex; flex-direction: column; }
        .student-name { font-weight: bold; color: #2c3e50; }
        .file-name { font-size: 0.85rem; color: #999; }

        /* STATUTS (BADGES) */
        .status-badge { padding: 5px 10px; border-radius: 20px; font-size: 12px; font-weight: bold; text-transform: uppercase; }
        .status-waiting { background: #e2e6ea; color: #6c757d; }
        .status-loading { background: #fff3cd; color: #856404; }
        .status-success { background: #d4edda; color: #155724; }
        .status-error { background: #f8d7da; color: #721c24; }

        /* PROGRESS BAR */
        .progress-wrapper { height: 6px; background: #eee; border-radius: 3px; overflow: hidden; margin-top: 20px; display: none; }
        .progress-bar { height: 100%; background: #28a745; width: 0%; transition: width 0.3s; }

        /* --- MODAL / POPUP STYLES --- */
        .modal-overlay {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.5); z-index: 1000;
            display: none; justify-content: center; align-items: center;
            backdrop-filter: blur(2px);
        }
        .modal-box {
            background: white; width: 400px; padding: 25px; border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            text-align: center; animation: popIn 0.3s ease;
        }
        @keyframes popIn { from { transform: scale(0.8); opacity: 0; } to { transform: scale(1); opacity: 1; } }
        
        .modal-title { font-size: 1.2rem; font-weight: bold; margin-bottom: 10px; display: block; }
        .modal-text { font-size: 0.95rem; color: #555; margin-bottom: 25px; line-height: 1.5; }
        .modal-actions { display: flex; justify-content: center; gap: 10px; }
    </style>
</head>
<body>

<div class="container">
    <div class="card">
        <h2>📂 1. Importer les fichiers (.ics)</h2>
        <input type="file" id="fileInput" multiple accept=".ics" style="margin-bottom: 15px; display:block;">
        <button onclick="uploadFiles()" class="btn btn-blue" id="btnUpload">Envoyer les fichiers</button>
    </div>

    <div class="card" id="processingCard" style="opacity: 0.6; pointer-events: none;">
        <div style="display:flex; justify-content:space-between; align-items:center;">
            <h2>⚙️ 2. Liste des fichiers en attente</h2>
            <button onclick="startIntegration()" class="btn btn-green" id="btnProcess" disabled>Intégrer à la BDD</button>
        </div>

        <div class="progress-wrapper" id="progressWrapper">
            <div class="progress-bar" id="progressBar"></div>
        </div>

        <ul class="file-list" id="fileListContainer">
            <li style="padding:15px; text-align:center; color:#999;">Aucun fichier sur le serveur.</li>
        </ul>
    </div>
</div>

<div id="modalConfirm" class="modal-overlay">
    <div class="modal-box">
        <span class="modal-title" style="color:#d9534f;">⚠️ Doublon détecté</span>
        <p class="modal-text" id="confirmText">L'élève existe déjà.</p>
        <div class="modal-actions">
            <button id="btnConfirmNo" class="btn btn-gray">Ignorer</button>
            <button id="btnConfirmYes" class="btn btn-blue">Remplacer</button>
        </div>
    </div>
</div>

<div id="modalAlert" class="modal-overlay">
    <div class="modal-box">
        <span class="modal-title" id="alertTitle">Information</span>
        <p class="modal-text" id="alertText">Message...</p>
        <div class="modal-actions">
            <button id="btnAlertOk" class="btn btn-blue">D'accord</button>
        </div>
    </div>
</div>

<script>
    let currentFileList = [];

    // --- FONCTIONS MODALES PERSONNALISÉES ---
    
    // Remplace confirm() : retourne une Promesse (true/false)
    function customConfirm(studentName) {
        return new Promise((resolve) => {
            const modal = document.getElementById('modalConfirm');
            const txt = document.getElementById('confirmText');
            const btnYes = document.getElementById('btnConfirmYes');
            const btnNo = document.getElementById('btnConfirmNo');

            txt.innerHTML = `L'élève <strong>${studentName}</strong> est déjà dans la base.<br>Voulez-vous écraser ses horaires pour le 13/02 ?`;
            modal.style.display = 'flex';

            // On définit ce qui se passe au clic
            btnYes.onclick = () => {
                modal.style.display = 'none';
                resolve(true); // L'utilisateur a dit OUI
            };
            btnNo.onclick = () => {
                modal.style.display = 'none';
                resolve(false); // L'utilisateur a dit NON
            };
        });
    }

    // Remplace alert() : retourne une Promesse (juste pour attendre le clic)
    function customAlert(title, message) {
        return new Promise((resolve) => {
            const modal = document.getElementById('modalAlert');
            document.getElementById('alertTitle').innerText = title;
            document.getElementById('alertText').innerText = message;
            const btnOk = document.getElementById('btnAlertOk');

            modal.style.display = 'flex';
            
            btnOk.onclick = () => {
                modal.style.display = 'none';
                resolve();
            };
        });
    }


    // --- 1. UPLOAD ---
    async function uploadFiles() {
        const input = document.getElementById('fileInput');
        if (input.files.length === 0) {
            await customAlert("Attention", "Veuillez sélectionner des fichiers.");
            return;
        }

        const btn = document.getElementById('btnUpload');
        btn.textContent = "Envoi en cours...";
        btn.disabled = true;

        const formData = new FormData();
        for (let i = 0; i < input.files.length; i++) {
            formData.append('files[]', input.files[i]);
        }
        formData.append('action', 'upload');

        try {
            const req = await fetch('apis', { method: 'POST', body: formData });
            const res = await req.json();

            if (res.status === 'success') {
                input.value = ''; 
                fetchFileList();
            } else {
                await customAlert("Erreur", "Erreur upload : " + res.message);
            }
        } catch (e) {
            await customAlert("Erreur", "Erreur réseau upload");
        }
        btn.textContent = "Envoyer les fichiers";
        btn.disabled = false;
    }

    // --- 2. LISTING ---
    async function fetchFileList() {
        const formData = new FormData();
        formData.append('action', 'list');

        const req = await fetch('apis', { method: 'POST', body: formData });
        const res = await req.json();

        if (res.status === 'success') {
            currentFileList = res.files;
            renderList();
            
            const card2 = document.getElementById('processingCard');
            card2.style.opacity = '1';
            card2.style.pointerEvents = 'auto';
            
            const btnProc = document.getElementById('btnProcess');
            btnProc.disabled = (currentFileList.length === 0);
        }
    }

    function renderList() {
        const listContainer = document.getElementById('fileListContainer');
        listContainer.innerHTML = '';

        if (currentFileList.length === 0) {
            listContainer.innerHTML = '<li style="padding:15px; text-align:center; color:#999;">Aucun fichier sur le serveur.</li>';
            return;
        }

        currentFileList.forEach((file, index) => {
            const html = `
                <li class="file-item" id="item-${index}">
                    <div class="file-info">
                        <span class="student-name">${file.student}</span>
                        <span class="file-name">${file.filename}</span>
                    </div>
                    <span class="status-badge status-waiting" id="badge-${index}">En attente</span>
                </li>
            `;
            listContainer.insertAdjacentHTML('beforeend', html);
        });
    }

    // --- 3. INTEGRATION (AVEC POPUP CUSTOM) ---
    async function startIntegration() {
        // Confirmation de départ
        // On pourrait utiliser customConfirm ici aussi, mais restons simple ou utilisons-le :
        /* if (!await customConfirm("Lancer l'intégration ?")) return; 
        Note: ma fonction customConfirm est faite pour les doublons (texte spécifique).
        Pour un "Oui/Non" générique, il faudrait adapter la fonction.
        */

        const btn = document.getElementById('btnProcess');
        const pBar = document.getElementById('progressBar');
        const pWrap = document.getElementById('progressWrapper');
        
        btn.disabled = true;
        pWrap.style.display = 'block';
        
        let processedCount = 0;

        for (let i = 0; i < currentFileList.length; i++) {
            const file = currentFileList[i];
            const badge = document.getElementById(`badge-${i}`);
            
            badge.className = "status-badge status-loading";
            badge.textContent = "Traitement...";

            // Appel API (mode normal)
            let res = await callProcess(file.filename, false);

            // Gestion Conflit via POPUP CUSTOM
            if (res.status === 'conflict') {
                // Le script s'arrête ici et ATTEND que l'utilisateur clique sur la popup
                const userWantsToOverwrite = await customConfirm(res.student);
                
                if (userWantsToOverwrite) {
                    // Si clic sur "Remplacer"
                    res = await callProcess(file.filename, true);
                } else {
                    // Si clic sur "Ignorer"
                    res = { status: 'skipped' };
                }
            }

            // Mise à jour UI
            if (res.status === 'success') {
                badge.className = "status-badge status-success";
                badge.textContent = "OK";
            } else if (res.status === 'skipped') {
                badge.className = "status-badge status-waiting";
                badge.textContent = "Ignoré";
            } else {
                badge.className = "status-badge status-error";
                badge.textContent = "Erreur";
            }

            // Barre de progression
            processedCount++;
            pBar.style.width = ((processedCount / currentFileList.length) * 100) + "%";
            
            // Petite défilement auto vers l'élément traité
            document.getElementById(`item-${i}`).scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }

        // --- 4. NETTOYAGE ---
        await cleanupServer();
        
        await customAlert("Terminé", "Tout est fini ! Le dossier serveur a été nettoyé.");
        
        // Reset
        btn.disabled = false;
        pWrap.style.display = 'none';
        pBar.style.width = '0%';
        document.getElementById('fileListContainer').innerHTML = '<li style="padding:15px; text-align:center; color:#999;">Dossier vidé.</li>';
        currentFileList = [];
    }

    async function callProcess(filename, overwrite) {
        const fd = new FormData();
        fd.append('action', 'process');
        fd.append('filename', filename);
        fd.append('overwrite', overwrite ? 'true' : 'false');
        
        try {
            const r = await fetch('apis', { method: 'POST', body: fd });
            return await r.json();
        } catch (e) {
            return { status: 'error' };
        }
    }

    async function cleanupServer() {
        const fd = new FormData();
        fd.append('action', 'cleanup');
        await fetch('apis', { method: 'POST', body: fd });
    }
    
    fetchFileList();

</script>

</body>
</html>