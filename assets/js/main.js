// assets/js/main.js

let cart = [];
let editingIndex = null; // NULL = Mode Création, Entier = Mode Édition

// --- 1. GESTION DE L'OUVERTURE DE LA MODALE ---

// Cette fonction doit être appelée par le bouton "Ajouter une personne" dans order.php
// Elle garantit qu'on part d'un formulaire vide.
function openAddModal() {
    editingIndex = null; // On est en mode création
    resetModalForm();
    
    // On change le titre et le bouton pour l'UX
    document.getElementById('modalTitleLabel').innerText = "Ajouter un destinataire";
    document.getElementById('btn-save-recipient').innerText = "Ajouter cette personne";
    
    const modal = new bootstrap.Modal(document.getElementById('addRecipientModal'));
    modal.show();
}

// Cette fonction est appelée quand on clique sur le bouton "Modifier" d'une carte
function editRecipient(index) {
    editingIndex = index; // On sauvegarde qui on modifie
    const item = cart[index]; // On récupère les données
    
    // A. Remplissage Infos de base
    document.getElementById('dest_nom').value = item.nom;
    document.getElementById('dest_prenom').value = item.prenom;
    document.getElementById('dest_classe').value = item.classId;
    
    // B. Remplissage Message
    document.getElementById('dest_message').value = item.messageId;
    document.getElementById('dest_anonyme').checked = item.isAnonymous;

    // C. Remplissage Roses
    // D'abord on remet tout à 0
    document.querySelectorAll('.rose-input').forEach(input => input.value = 0);
    // Ensuite on remplit celles du destinataire
    item.roses.forEach(rose => {
        // On cherche l'input qui a le data-id correspondant
        const input = document.querySelector(`.rose-input[data-id="${rose.id}"]`);
        if (input) {
            input.value = rose.qty;
        }
    });

    // D. Remplissage Emploi du temps
    // D'abord on vide tout
    document.querySelectorAll('.schedule-input').forEach(select => select.value = "");
    // Ensuite on remplit
    item.schedule.forEach(slot => {
        // On cherche le select qui correspond à l'heure (data-hour)
        const select = document.querySelector(`.schedule-input[data-hour="${slot.hour}"]`);
        if (select) {
            select.value = slot.roomId;
        }
    });

    // E. UX : Mise à jour des textes
    document.getElementById('modalTitleLabel').innerText = "Modifier le destinataire";
    document.getElementById('btn-save-recipient').innerText = "Mettre à jour";

    // F. Ouverture Modale
    const modal = new bootstrap.Modal(document.getElementById('addRecipientModal'));
    modal.show();
}

// --- 2. SAUVEGARDE (AJOUT ou MODIFICATION) ---

function addRecipientToCart() {
    // 1. Validation basique
    const elNom = document.getElementById('dest_nom');
    const elPrenom = document.getElementById('dest_prenom');
    const elClasse = document.getElementById('dest_classe');

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

    // 2. Emploi du temps
    let schedule = [];
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

    if (!hasAtLeastOneSlot && !confirm("Aucune salle indiquée.\nContinuer sans savoir où trouver la personne ?")) {
        return;
    }

    // 3. Roses
    let roses = [];
    let totalQtyRecipient = 0;
    document.querySelectorAll('.rose-input').forEach(input => {
        const qty = parseInt(input.value);
        if (qty > 0) {
            roses.push({
                id: input.dataset.id,
                name: input.dataset.name,
                qty: qty
            });
            totalQtyRecipient += qty;
        }
    });

    if (roses.length === 0) {
        alert("Sélectionnez au moins une rose.");
        return;
    }

    // 4. CRÉATION DE L'OBJET
    const recipientObj = {
        tempId: (editingIndex !== null) ? cart[editingIndex].tempId : Date.now(), // On garde l'ID si modif
        nom, prenom, classId, className,
        schedule,
        messageId, messageText, isAnonymous, 
        roses, 
        totalQty: totalQtyRecipient 
    };

    // 5. LOGIQUE AJOUT vs MODIFICATION
    if (editingIndex !== null) {
        // Mode ÉDITION : On remplace l'existant
        cart[editingIndex] = recipientObj;
        editingIndex = null; // On reset l'index
    } else {
        // Mode CRÉATION : On ajoute à la fin
        cart.push(recipientObj);
    }
    
    // 6. Finalisation
    renderCart();
    resetModalForm();
    
    // Fermeture propre de la modale
    const modalEl = document.getElementById('addRecipientModal');
    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    modal.hide();
}

// --- 3. AFFICHAGE DU PANIER ---

function renderCart() {
    const container = document.getElementById('recipients-list');
    const emptyMsg = document.getElementById('empty-cart-msg');
    const btnValidate = document.getElementById('btn-validate-order');
    const countSpan = document.getElementById('count-people');
    const totalSpan = document.getElementById('grand-total');
    
    container.innerHTML = '';
    
    // Calcul Global
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
            
            let scheduleHtml = '<ul class="mb-0 ps-3 small text-muted">';
            item.schedule.forEach(slot => {
                scheduleHtml += `<li>${slot.hour}h : ${slot.roomName}</li>`;
            });
            if(item.schedule.length === 0) scheduleHtml = '<em class="small text-muted">Aucun créneau</em>';
            else scheduleHtml += '</ul>';

            container.innerHTML += `
                <div class="col-md-6">
                    <div class="card rose-card h-100 shadow-sm border-0">
                        <div class="card-body position-relative">
                            
                            <div class="position-absolute top-0 end-0 m-2">
                                <button class="btn btn-sm btn-outline-secondary me-1" onclick="editRecipient(${index})" title="Modifier">
                                    ✏️
                                </button>
                                <button class="btn-close" onclick="removeRecipient(${index})" aria-label="Supprimer"></button>
                            </div>
                            
                            <h5 class="card-title text-danger fw-bold pe-5">${item.prenom} ${item.nom}</h5>
                            <h6 class="card-subtitle mb-2 text-muted">${item.className}</h6>
                            
                            <div class="card-text mt-3">
                                <div class="mb-2">${rosesSummary}</div>
                                <div class="mb-2"><strong>💌 Message :</strong> ${item.messageId ? item.messageText : '<span class="text-muted">Aucun</span>'}</div>
                                <div class="mb-2"><strong>📍 Où le trouver :</strong> ${scheduleHtml}</div>
                                ${item.isAnonymous ? '<span class="badge bg-dark">🕵️ Anonyme</span>' : '<span class="badge bg-success">✍️ Signé</span>'}
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
    document.querySelectorAll('.schedule-input').forEach(s => s.value = "");
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
    const apiUrl = window.location.origin + currentPath + '/api/submit_order';

    fetch(apiUrl, { 
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ buyerNom, buyerPrenom, buyerClassId, cart })
    })
    .then(r => r.text())
    .then(text => {
        try {
            const data = JSON.parse(text);
            if (data.success) {
                alert("Commande validée avec succès !");
                window.location.href = 'index.php?msg_success=Commande enregistrée !'; 
            } else {
                alert("Erreur Serveur : " + (data.message || "Erreur inconnue"));
                btn.disabled = false;
                btn.innerHTML = "✅ Valider et Payer";
            }
        } catch (e) {
            console.error("Réponse invalide:", text);
            alert("Erreur technique serveur.");
            btn.disabled = false;
            btn.innerHTML = "✅ Valider et Payer";
        }
    })
    .catch(e => {
        console.error(e);
        alert("Erreur réseau.");
        btn.disabled = false;
        btn.innerHTML = "✅ Valider et Payer";
    });
});