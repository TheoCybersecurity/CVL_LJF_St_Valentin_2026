let cart = [];

function addRecipientToCart() {
    console.log("Tentative d'ajout au panier...");

    // --- DIAGNOSTIC (Pour vérifier que le HTML est bien chargé) ---
    const elNom = document.getElementById('dest_nom');
    const elPrenom = document.getElementById('dest_prenom');
    const elClasse = document.getElementById('dest_classe');

    if (!elNom || !elPrenom || !elClasse) {
        console.error("ERREUR CRITIQUE : Un des champs du formulaire est introuvable dans le HTML.");
        if(!elNom) console.error("Manquant: id='dest_nom'");
        if(!elPrenom) console.error("Manquant: id='dest_prenom'");
        if(!elClasse) console.error("Manquant: id='dest_classe'");
        alert("Erreur technique : Le formulaire est incomplet. Essayez de rafraîchir la page (Ctrl+F5).");
        return;
    }

    // 1. Infos de base
    const nom = elNom.value;
    const prenom = elPrenom.value;
    const classId = elClasse.value;
    const className = elClasse.options[elClasse.selectedIndex].text;

    // 2. Message et Anonymat
    const messageSelect = document.getElementById('dest_message');
    const messageId = messageSelect.value;
    const messageText = messageSelect.options[messageSelect.selectedIndex].text;
    const isAnonymous = document.getElementById('dest_anonyme').checked;

    if (!nom || !prenom || !classId) {
        alert("Merci de remplir le nom, prénom et la classe.");
        return;
    }

    // 3. Récupération de l'emploi du temps
    let schedule = [];
    let hasAtLeastOneSlot = false;
    document.querySelectorAll('.schedule-input').forEach(select => {
        const roomId = select.value;
        if(roomId) {
            hasAtLeastOneSlot = true;
            schedule.push({
                hour: parseInt(select.dataset.hour),
                roomId: roomId,
                // On vérifie que roomsMap existe bien (chargé depuis le PHP)
                roomName: (typeof roomsMap !== 'undefined') ? roomsMap[roomId] : 'Salle inconnue' 
            });
        }
    });

    if (!hasAtLeastOneSlot) {
        alert("Merci d'indiquer au moins une salle dans l'emploi du temps !");
        return;
    }

    // 4. Roses
    let roses = [];
    let rosesTotal = 0;
    document.querySelectorAll('.rose-input').forEach(input => {
        const qty = parseInt(input.value);
        if (qty > 0) {
            roses.push({
                id: input.dataset.id,
                name: input.dataset.name,
                price: parseFloat(input.dataset.price),
                qty: qty
            });
            rosesTotal += qty * parseFloat(input.dataset.price);
        }
    });

    if (roses.length === 0) {
        alert("Sélectionnez au moins une rose.");
        return;
    }

    // 5. Ajout au panier
    cart.push({
        tempId: Date.now(),
        nom, prenom, classId, className,
        schedule,
        messageId, messageText, isAnonymous, roses, totalPrice: rosesTotal
    });
    
    renderCart();
    
    // Reset form
    document.getElementById('recipientForm').reset();
    document.querySelectorAll('.rose-input').forEach(i => i.value = 0);
    document.querySelectorAll('.schedule-input').forEach(s => s.value = "");
    
    // Fermeture de la modale
    const modalEl = document.getElementById('addRecipientModal');
    const modal = bootstrap.Modal.getInstance(modalEl);
    modal.hide();
}

function renderCart() {
    const container = document.getElementById('recipients-list');
    const emptyMsg = document.getElementById('empty-cart-msg');
    const btnValidate = document.getElementById('btn-validate-order');
    
    container.innerHTML = '';
    let grandTotal = 0;

    if (cart.length === 0) {
        emptyMsg.style.display = 'block';
        btnValidate.disabled = true;
    } else {
        emptyMsg.style.display = 'none';
        btnValidate.disabled = false;
        
        cart.forEach((item, index) => {
            grandTotal += item.totalPrice;
            let rosesSummary = item.roses.map(r => `${r.qty} x ${r.name}`).join(', ');
            
            let scheduleHtml = '<ul class="mb-0 ps-3 small">';
            item.schedule.forEach(slot => {
                scheduleHtml += `<li><strong>${slot.hour}h-${slot.hour+1}h :</strong> ${slot.roomName}</li>`;
            });
            scheduleHtml += '</ul>';

            container.innerHTML += `
                <div class="col-md-6">
                    <div class="card rose-card h-100">
                        <div class="card-body">
                            <h5 class="card-title">${item.prenom} ${item.nom} <small class="text-muted">(${item.className})</small></h5>
                            <div class="card-text mb-1">
                                <strong>Fleurs :</strong> ${rosesSummary}<br>
                                <strong>Message :</strong> ${item.messageId ? item.messageText : 'Aucun'}<br>
                                <div class="mt-2"><strong>📍 Emploi du temps :</strong> ${scheduleHtml}</div>
                                ${item.isAnonymous ? '<span class="badge bg-dark mt-2">Anonyme</span>' : ''}
                            </div>
                            <div class="d-flex justify-content-between align-items-center mt-3 border-top pt-2">
                                <span class="fw-bold text-danger">${item.totalPrice.toFixed(2)} €</span>
                                <button class="btn btn-outline-danger btn-sm" onclick="removeRecipient(${index})">Supprimer</button>
                            </div>
                        </div>
                    </div>
                </div>`;
        });
    }
    document.getElementById('count-people').innerText = cart.length;
    document.getElementById('grand-total').innerText = grandTotal.toFixed(2);
}

function removeRecipient(index) {
    cart.splice(index, 1);
    renderCart();
}

document.getElementById('btn-validate-order').addEventListener('click', function() {
    const buyerNom = document.getElementById('buyer_nom').value.trim();
    const buyerPrenom = document.getElementById('buyer_prenom').value.trim();
    const buyerClassEl = document.getElementById('buyer_class');
    const buyerClassId = buyerClassEl ? buyerClassEl.value : "";

    if(!buyerNom || !buyerPrenom || !buyerClassId) {
        alert("Veuillez remplir VOS informations (Nom, Prénom, Classe) en haut de page.");
        window.scrollTo(0,0);
        return;
    }
    
    if(!confirm("Valider la commande ?")) return;
    
    const btn = this;
    btn.disabled = true;
    btn.innerHTML = "⏳ Enregistrement...";

    fetch('api/submit_order', {
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
                window.location.reload(); 
            } else {
                alert("Erreur Serveur : " + data.message);
                btn.disabled = false;
                btn.innerHTML = "✅ Valider la commande";
            }
        } catch (e) {
            console.error("Réponse serveur invalide:", text);
            alert("Erreur technique (Réponse invalide). Vérifiez la console.");
            btn.disabled = false;
        }
    })
    .catch(e => {
        console.error(e);
        alert("Erreur réseau.");
        btn.disabled = false;
    });
});