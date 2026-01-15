// assets/js/main.js

let cart = [];
let editingIndex = null;

// --- GESTION DE LA RECHERCHE (AUTOCOMPLETION) ---
const searchInput = document.getElementById('search_student');
const searchResults = document.getElementById('searchResults');
let debounceTimeout = null;

if (searchInput) {
    searchInput.addEventListener('input', function() {
        const query = this.value.trim();
        
        // Si le champ est vide, on cache les résultats mais on ne reset pas tout 
        // (l'utilisateur utilise la croix X pour reset)
        if (query.length === 0) {
            document.getElementById('searchResults').style.display = 'none';
            return;
        }

        clearTimeout(debounceTimeout);
        debounceTimeout = setTimeout(() => {
            fetch(`api/search_student?q=${encodeURIComponent(query)}`) // .php ajouté par sécurité
                .then(r => r.json())
                .then(data => {
                    const searchResults = document.getElementById('searchResults');
                    searchResults.innerHTML = '';
                    
                    if (data.length > 0) {
                        searchResults.style.display = 'block';
                        
                        data.forEach(student => {
                            const div = document.createElement('div');
                            // J'ajoute des classes Bootstrap pour l'espacement et le curseur
                            div.className = 'search-result-item p-2 border-bottom action-pointer';
                            div.style.cursor = 'pointer'; 
                            
                            // LOGIQUE D'AFFICHAGE DE LA CLASSE
                            let classBadge = '';
                            
                            // Note : on utilise student.class_name (renvoyé par le JOIN en PHP)
                            if (student.class_name) {
                                classBadge = `<span class="badge bg-success ms-2">${student.class_name}</span>`;
                            } else {
                                classBadge = `<span class="badge bg-warning text-dark ms-2">Classe à définir</span>`;
                            }

                            // Structure Flexbox : Nom à gauche, Badge à droite
                            div.innerHTML = `
                                <div class="d-flex justify-content-between align-items-center">
                                    <span><strong>${student.nom}</strong> ${student.prenom}</span>
                                    ${classBadge}
                                </div>
                            `;
                            
                            // Au clic, on lance la fonction de sélection
                            div.onclick = () => selectStudent(student);
                            
                            // Effet de survol (hover) simple en JS
                            div.onmouseover = () => div.classList.add('bg-light');
                            div.onmouseout = () => div.classList.remove('bg-light');
                            
                            searchResults.appendChild(div);
                        });
                    } else {
                        searchResults.style.display = 'none';
                    }
                })
                .catch(err => console.error("Erreur recherche:", err));
        }, 300); // Délai de 300ms
    });

    // Cacher si on clique ailleurs
    document.addEventListener('click', function(e) {
        const searchResults = document.getElementById('searchResults');
        if (e.target !== searchInput && e.target !== searchResults && !searchResults.contains(e.target)) {
            searchResults.style.display = 'none';
        }
    });
}

// A. Update de la fonction selectStudent
function selectStudent(student) {
    // 1. Remplir Nom/Prénom
    const elNom = document.getElementById('dest_nom');
    const elPrenom = document.getElementById('dest_prenom');
    const selectClasse = document.getElementById('dest_classe'); // Cible le select
    
    elNom.value = student.nom;
    elPrenom.value = student.prenom;
    document.getElementById('dest_schedule_id').value = student.id;

    // 2. VERROUILLAGE (Nom, Prénom ET Classe)
    elNom.readOnly = true;
    elPrenom.readOnly = true;
    
    // Ajout du style gris
    elNom.classList.add('bg-secondary-subtle');
    elPrenom.classList.add('bg-secondary-subtle');
    selectClasse.classList.add('bg-secondary-subtle'); 
    
    // On affiche le bouton RESET
    document.getElementById('btn-reset-search').style.display = 'block';

    // 3. SELECTION ET VERROUILLAGE DE LA CLASSE
    if (student.class_id && student.class_id > 0) {
        selectClasse.value = student.class_id;
        selectClasse.disabled = true; // <-- ON BLOQUE LA MODIFICATION
    } else {
        // Si l'élève est trouvé mais n'a pas de classe en base (ex: prof ou erreur import)
        // On laisse le choix libre, mais on ne grise pas
        selectClasse.value = "";
        selectClasse.disabled = false; 
        selectClasse.classList.remove('bg-secondary-subtle'); 
    }
    
    // 4. Interface
    // Mise à jour de la barre de recherche
    let displayName = student.nom + ' ' + student.prenom;
    if (student.class_name) {
        displayName += ' (' + student.class_name + ')';
    }
    
    document.getElementById('searchResults').style.display = 'none';
    document.getElementById('search_student').value = displayName;
    
    document.getElementById('scheduleSection').style.display = 'none';
    document.getElementById('autoScheduleMsg').style.display = 'block';
    document.getElementById('manual-mode-hint').style.display = 'none';
}

// B. Update de la fonction resetToManualMode
function resetToManualMode() {
    document.getElementById('dest_schedule_id').value = "";
    
    const elNom = document.getElementById('dest_nom');
    const elPrenom = document.getElementById('dest_prenom');
    const selectClasse = document.getElementById('dest_classe');

    // DÉVERROUILLAGE TOTAL
    elNom.readOnly = false;
    elPrenom.readOnly = false;
    selectClasse.disabled = false; // <-- ON DÉBLOQUE

    // On retire le gris
    elNom.classList.remove('bg-secondary-subtle');
    elPrenom.classList.remove('bg-secondary-subtle');
    selectClasse.classList.remove('bg-secondary-subtle');

    // Vidage des champs
    elNom.value = "";
    elPrenom.value = "";
    selectClasse.value = "";

    // Interface
    document.getElementById('scheduleSection').style.display = 'block';
    document.getElementById('autoScheduleMsg').style.display = 'none';
    document.getElementById('btn-reset-search').style.display = 'none';
    document.getElementById('manual-mode-hint').style.display = 'block';
}

// C. Nouvelle fonction pour le bouton "X"
function fullResetSearch() {
    document.getElementById('search_student').value = "";
    resetToManualMode();
}

// --- GESTION DE LA MODALE ---

function openAddModal() {
    editingIndex = null;
    resetModalForm();
    document.getElementById('modalTitleLabel').innerText = "Ajouter un destinataire";
    document.getElementById('btn-save-recipient').innerText = "Ajouter cette personne";
    
    // Reset spécifique recherche
    resetToManualMode();
    if(searchInput) searchInput.value = "";

    const modal = new bootstrap.Modal(document.getElementById('addRecipientModal'));
    modal.show();
}

function editRecipient(index) {
    editingIndex = index;
    const item = cart[index];
    
    document.getElementById('dest_nom').value = item.nom;
    document.getElementById('dest_prenom').value = item.prenom;
    document.getElementById('dest_classe').value = item.classId;
    document.getElementById('dest_schedule_id').value = item.scheduleId || ""; // Important
    
    document.getElementById('dest_message').value = item.messageId;
    document.getElementById('dest_anonyme').checked = item.isAnonymous;

    // Gestion Affichage Horaire
    if (item.scheduleId) {
        // C'est un profil BDD
        document.getElementById('scheduleSection').style.display = 'none';
        document.getElementById('autoScheduleMsg').style.display = 'block';
    } else {
        // C'est un profil Manuel
        document.getElementById('scheduleSection').style.display = 'block';
        document.getElementById('autoScheduleMsg').style.display = 'none';
        
        // Remplir les horaires manuels
        document.querySelectorAll('.schedule-input').forEach(select => select.value = "");
        item.schedule.forEach(slot => {
            const select = document.querySelector(`.schedule-input[data-hour="${slot.hour}"]`);
            if (select) select.value = slot.roomId;
        });
    }

    // Remplissage Roses
    document.querySelectorAll('.rose-input').forEach(input => input.value = 0);
    item.roses.forEach(rose => {
        const input = document.querySelector(`.rose-input[data-id="${rose.id}"]`);
        if (input) input.value = rose.qty;
    });

    document.getElementById('modalTitleLabel').innerText = "Modifier le destinataire";
    document.getElementById('btn-save-recipient').innerText = "Mettre à jour";

    const modal = new bootstrap.Modal(document.getElementById('addRecipientModal'));
    modal.show();
}

// --- AJOUT AU PANIER ---

function addRecipientToCart() {
    const elNom = document.getElementById('dest_nom');
    const elPrenom = document.getElementById('dest_prenom');
    const elClasse = document.getElementById('dest_classe');
    const scheduleId = document.getElementById('dest_schedule_id').value; // Peut être vide

    if (!elNom || !elPrenom || !elClasse) return;

    const nom = elNom.value.trim();
    const prenom = elPrenom.value.trim();
    const classId = elClasse.value;
    const className = elClasse.options[elClasse.selectedIndex].text;
    const messageSelect = document.getElementById('dest_message');
    const messageId = messageSelect.value;
    const messageText = messageSelect.options[messageSelect.selectedIndex].text;
    const isAnonymous = document.getElementById('dest_anonyme').checked;

    if (!nom || !prenom || !classId) {
        alert("Merci de remplir le nom, prénom et la classe.");
        return;
    }

    // Gestion Horaire (Seulement si PAS d'ID BDD)
    let schedule = [];
    if (!scheduleId) {
        let hasAtLeastOneSlot = false;
        document.querySelectorAll('.schedule-input').forEach(select => {
            const roomId = select.value;
            if(roomId) {
                hasAtLeastOneSlot = true;
                schedule.push({
                    hour: parseInt(select.dataset.hour),
                    roomId: roomId,
                    roomName: (typeof roomsMap !== 'undefined') ? roomsMap[roomId] : 'Salle inconnue'
                });
            }
        });
        if (!hasAtLeastOneSlot && !confirm("Aucune salle indiquée.\nContinuer sans savoir où trouver la personne ?")) return;
    }

    // Gestion Roses
    let roses = [];
    let totalQtyRecipient = 0;
    document.querySelectorAll('.rose-input').forEach(input => {
        const qty = parseInt(input.value);
        if (qty > 0) {
            roses.push({ id: input.dataset.id, name: input.dataset.name, qty: qty });
            totalQtyRecipient += qty;
        }
    });

    if (roses.length === 0) {
        alert("Sélectionnez au moins une rose.");
        return;
    }

    // OBJET FINAL
    const recipientObj = {
        tempId: (editingIndex !== null) ? cart[editingIndex].tempId : Date.now(),
        nom, prenom, classId, className,
        scheduleId: scheduleId, // L'ID de la BDD (si trouvé)
        schedule, // L'horaire manuel (si pas trouvé)
        messageId, messageText, isAnonymous, roses, totalQty: totalQtyRecipient
    };

    if (editingIndex !== null) cart[editingIndex] = recipientObj;
    else cart.push(recipientObj);
    
    editingIndex = null;
    renderCart();
    resetModalForm();
    
    const modalEl = document.getElementById('addRecipientModal');
    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    modal.hide();
}

function renderCart() {
    const container = document.getElementById('recipients-list');
    const emptyMsg = document.getElementById('empty-cart-msg');
    const btnValidate = document.getElementById('btn-validate-order');
    const countSpan = document.getElementById('count-people');
    const totalSpan = document.getElementById('grand-total');
    
    container.innerHTML = '';
    
    let grandTotalRoses = 0;
    cart.forEach(item => grandTotalRoses += item.totalQty);

    let grandTotalPrice = 0;
    if (typeof getPriceForQuantity === "function") {
        grandTotalPrice = getPriceForQuantity(grandTotalRoses);
    }

    if (cart.length === 0) {
        emptyMsg.style.display = 'block';
        btnValidate.disabled = true;
    } else {
        emptyMsg.style.display = 'none';
        btnValidate.disabled = false;
        
        cart.forEach((item, index) => {
            let rosesSummary = item.roses.map(r => `<span class="badge bg-pink text-dark border border-danger-subtle me-1">${r.qty} x ${r.name}</span>`).join(' ');
            
            // Affichage de la localisation
            let locHtml = "";
            if (item.scheduleId) {
                locHtml = '<span class="badge bg-success">📅 Horaire importé</span>';
            } else {
                if(item.schedule.length > 0) {
                    locHtml = '<ul class="mb-0 ps-3 small text-muted">';
                    item.schedule.forEach(slot => { locHtml += `<li>${slot.hour}h : ${slot.roomName}</li>`; });
                    locHtml += '</ul>';
                } else {
                    locHtml = '<em class="small text-muted">Aucune info</em>';
                }
            }

            container.innerHTML += `
                <div class="col-md-6">
                    <div class="card rose-card h-100 shadow-sm border-0">
                        <div class="card-body position-relative">
                            <div class="position-absolute top-0 end-0 m-2">
                                <button class="btn btn-sm btn-outline-secondary me-1" onclick="editRecipient(${index})" title="Modifier">✏️</button>
                                <button class="btn-close" onclick="removeRecipient(${index})" aria-label="Supprimer"></button>
                            </div>
                            <h5 class="card-title text-danger fw-bold pe-5">${item.prenom} ${item.nom}</h5>
                            <h6 class="card-subtitle mb-2 text-muted">${item.className}</h6>
                            <div class="card-text mt-3">
                                <div class="mb-2">${rosesSummary}</div>
                                <div class="mb-2"><strong>📍 :</strong> ${locHtml}</div>
                                ${item.isAnonymous ? '<span class="badge bg-dark">🕵️ Anonyme</span>' : ''}
                            </div>
                        </div>
                    </div>
                </div>`;
        });
    }

    countSpan.textContent = cart.length;
    totalSpan.textContent = grandTotalPrice.toFixed(2);
}

function removeRecipient(index) {
    if(confirm("Supprimer ce destinataire ?")) {
        cart.splice(index, 1);
        renderCart();
    }
}

function resetModalForm() {
    const form = document.getElementById('recipientForm');
    if (form) form.reset();
    document.querySelectorAll('.rose-input').forEach(i => i.value = 0);
    // On vide l'ID caché pour revenir en mode manuel par défaut
    document.getElementById('dest_schedule_id').value = ""; 
    document.getElementById('scheduleSection').style.display = 'block';
    document.getElementById('autoScheduleMsg').style.display = 'none';
    if(searchInput) searchInput.value = "";
    if(searchResults) searchResults.style.display = 'none';
}

// Validation Commande
document.getElementById('btn-validate-order').addEventListener('click', function() {
    const buyerNom = document.getElementById('buyer_nom').value.trim();
    const buyerPrenom = document.getElementById('buyer_prenom').value.trim();
    const buyerClassEl = document.getElementById('buyer_class');
    const buyerClassId = buyerClassEl ? buyerClassEl.value : "";

    if(!buyerNom || !buyerPrenom || !buyerClassId) {
        alert("Veuillez remplir VOS informations en haut de page.");
        window.scrollTo(0,0);
        return;
    }
    
    if(!confirm(`Confirmer la commande pour un total de ${document.getElementById('grand-total').innerText} € ?`)) return;
    
    const btn = this;
    btn.disabled = true;
    btn.innerHTML = "⏳ Enregistrement...";

    const currentPath = window.location.pathname.substring(0, window.location.pathname.lastIndexOf('/'));
    const apiUrl = window.location.origin + currentPath + '/api/submit_order'; // .php ajouté par sécurité

    fetch(apiUrl, { 
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ buyerNom, buyerPrenom, buyerClassId, cart })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert("Commande validée avec succès !");
            window.location.href = 'index.php?msg_success=Commande enregistrée !'; 
        } else {
            alert("Erreur Serveur : " + (data.message || "Erreur inconnue"));
            btn.disabled = false;
            btn.innerHTML = "✅ Valider et Payer";
        }
    })
    .catch(e => {
        console.error(e);
        alert("Erreur réseau ou réponse invalide.");
        btn.disabled = false;
        btn.innerHTML = "✅ Valider et Payer";
    });
});