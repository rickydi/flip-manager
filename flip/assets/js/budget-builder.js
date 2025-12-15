/**
 * Budget Builder Logic
 * Gestion du Drag & Drop, calculs, et interface arborescente.
 */

// Variables globales du module (initialisées au chargement)
let bb_projetId = 0;
let bb_tauxContingence = 0;
let bb_csrfToken = '';
let bb_saveTimeout = null;

// ========================================
// FONCTIONS GLOBALES UI (Accessibles immédiatement)
// ========================================

window.toggleCatalogueGroupe = function(header) {
    header.classList.toggle('collapsed');
    const groupe = header.dataset.groupe;
    const content = header.nextElementSibling;
    if (content) {
        content.style.display = header.classList.contains('collapsed') ? 'none' : 'block';
    }
};

window.toggleTreeItem = function(toggle, id) {
    toggle.classList.toggle('collapsed');
    const content = document.getElementById(id) ||
                    document.getElementById('catContent' + id) ||
                    document.getElementById('projetContent' + id);
    if (content) {
        content.classList.toggle('show');
    }
};

// ========================================
// FONCTIONS GLOBALES LOGIQUE (Définies ici pour être accessibles)
// ========================================

window.changeCatQte = function(catId, delta) {
    const input = document.querySelector(`.cat-qte-input[data-cat-id="${catId}"]`);
    if (input) {
        const newVal = Math.max(1, Math.min(20, parseInt(input.value) + delta));
        input.value = newVal;
        window.updateCatQte(catId);
    }
};

window.updateCatQte = function(catId) {
    const catItem = document.querySelector(`.projet-item[data-type="categorie"][data-id="${catId}"]`);
    if (catItem) {
        catItem.querySelectorAll('.projet-mat-item').forEach(matItem => {
            window.updateMaterialTotal(matItem);
        });
        catItem.querySelectorAll('.projet-item[data-type="sous_categorie"]').forEach(sc => {
            window.updateSousCategorieStats(sc);
        });
        window.updateSousCategorieStats(catItem); // updateContainerStats pour la catégorie
    }
    window.updateTotals();
    autoSave();
};

window.changeGroupeQte = function(groupe, delta) {
    const input = document.querySelector(`.groupe-qte-input[data-groupe="${groupe}"]`);
    if (input) {
        const newVal = Math.max(1, Math.min(20, parseInt(input.value) + delta));
        input.value = newVal;
        window.updateGroupeQte(groupe);
    }
};

window.updateGroupeQte = function(groupe) {
    const zone = document.querySelector(`.projet-drop-zone[data-groupe="${groupe}"]`);
    if (zone) {
        zone.querySelectorAll('.projet-mat-item').forEach(matItem => {
            window.updateMaterialTotal(matItem);
        });
        zone.querySelectorAll('.projet-item').forEach(item => {
            window.updateSousCategorieStats(item);
        });
    }
    window.updateTotals();
    autoSave();
};

window.removeProjetItem = function(btn) {
    saveState();
    const item = btn.closest('.projet-item');
    const groupe = item.dataset.groupe;
    
    // Si c'est un container, le supprimer
    // Pour mettre à jour le parent, on le récupère avant
    const parent = item.parentElement.closest('.projet-item');
    
    item.remove();

    // Vérifier si groupe vide
    const zone = document.querySelector(`.projet-drop-zone[data-groupe="${groupe}"]`);
    if (zone && zone.querySelectorAll('.projet-item').length === 0) {
        zone.closest('.projet-groupe').style.display = 'none';
    }

    // Vérifier si tout vide
    if (document.querySelectorAll('.projet-item').length === 0) {
        const emptyMsg = document.getElementById('projetEmpty');
        if (emptyMsg) emptyMsg.style.display = '';
    }

    if (parent) window.updateAllParents(parent.querySelector('.tree-content') || parent);

    window.updateTotals();
    autoSave();
};

window.clearAllBudget = function() {
    if (!confirm('Voulez-vous vraiment supprimer tous les items du budget?')) {
        return;
    }

    saveState();

    document.querySelectorAll('.projet-item').forEach(item => item.remove());
    document.querySelectorAll('.projet-mat-item').forEach(item => item.remove());
    document.querySelectorAll('.projet-groupe').forEach(groupe => {
        groupe.style.display = 'none';
    });

    const emptyMsg = document.getElementById('projetEmpty');
    if (emptyMsg) emptyMsg.style.display = '';

    fetch(window.location.href, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `ajax_action=clear_all_budget&csrf_token=${bb_csrfToken}`
    });

    window.updateTotals();
};

// ========================================
// INITIALISATION
// ========================================

document.addEventListener('DOMContentLoaded', function() {
    console.log('Budget Builder initializing...');
    
    // Init Config
    bb_projetId = window.budgetBuilderConfig?.projetId || 0;
    bb_tauxContingence = window.budgetBuilderConfig?.tauxContingence || 0;
    bb_csrfToken = window.budgetBuilderConfig?.csrfToken || '';

    // Init Modal
    const confirmAddModalEl = document.getElementById('confirmAddMaterialModal');
    let confirmAddModal = null;
    if (confirmAddModalEl) {
        confirmAddModal = new bootstrap.Modal(confirmAddModalEl);
        const addQteInput = document.getElementById('addQteInput');
        
        confirmAddModalEl.addEventListener('shown.bs.modal', function () {
            addQteInput.focus();
            addQteInput.select();
        });

        addQteInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                document.getElementById('confirmAddBtn').click();
            }
        });
    }

    let pendingMaterialAdd = null;

    if (document.getElementById('confirmAddBtn')) {
        document.getElementById('confirmAddBtn').addEventListener('click', function() {
            if (pendingMaterialAdd) {
                confirmAddModal.hide();
                const { existingMat } = pendingMaterialAdd;
                const addQteInput = document.getElementById('addQteInput');
                const qteToAdd = parseInt(addQteInput.value) || 1;
                
                saveState();

                const currentQte = parseInt(existingMat.dataset.qte) || 1;
                const newQte = currentQte + qteToAdd;

                existingMat.dataset.qte = newQte;

                const qteDisplay = existingMat.querySelector('.mat-qte-display');
                if (qteDisplay) qteDisplay.textContent = newQte;

                window.updateMaterialTotal(existingMat);
                window.saveItemData(existingMat.dataset.catId, existingMat.dataset.matId, null, newQte);
                window.updateAllParents(existingMat);
                window.updateTotals();
                autoSave();
                
                // Flash
                existingMat.style.transition = 'background-color 0.5s ease';
                existingMat.style.backgroundColor = 'rgba(25, 135, 84, 0.4)';
                setTimeout(() => { existingMat.style.backgroundColor = ''; }, 3000);

                pendingMaterialAdd = null;
            }
        });
    }

    // Init Drag & Drop Catalogue
    document.querySelectorAll('.catalogue-draggable').forEach(item => {
        item.addEventListener('dragstart', function(e) {
            e.dataTransfer.setData('text/plain', JSON.stringify({
                type: this.dataset.type,
                id: this.dataset.id,
                scId: this.dataset.scId,
                catId: this.dataset.catId || this.dataset.id,
                catNom: this.dataset.catNom || this.dataset.nom,
                groupe: this.dataset.groupe,
                nom: this.dataset.nom,
                prix: parseFloat(this.dataset.prix) || 0,
                qte: parseInt(this.dataset.qte) || 1,
                catOrdre: parseInt(this.dataset.catOrdre) || 0,
                scOrdre: parseInt(this.dataset.scOrdre) || 0,
                matOrdre: parseInt(this.dataset.matOrdre) || 0,
                path: this.dataset.path ? JSON.parse(this.dataset.path) : null
            }));
            this.style.opacity = '0.5';
        });

        item.addEventListener('dragend', function() {
            this.style.opacity = '1';
        });
    });

    // Init Drop Zones
    document.querySelectorAll('.projet-drop-zone').forEach(zone => {
        zone.addEventListener('dragover', function(e) {
            e.preventDefault();
            this.classList.add('drop-zone-active');
        });
        zone.addEventListener('dragleave', function() {
            this.classList.remove('drop-zone-active');
        });
        zone.addEventListener('drop', function(e) {
            e.preventDefault();
            this.classList.remove('drop-zone-active');
            try {
                const data = JSON.parse(e.dataTransfer.getData('text/plain'));
                addItemToProjet(data, this.dataset.groupe);
            } catch (err) {
                console.error('Drop error:', err);
            }
        });
        initSortable(zone);
    });

    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 'z' && !e.shiftKey) {
            e.preventDefault();
            undoAction();
        } else if ((e.ctrlKey || e.metaKey) && (e.key === 'y' || (e.key === 'z' && e.shiftKey))) {
            e.preventDefault();
            redoAction();
        }
    });
    
    // Delegation d'événements pour les éléments dynamiques
    
    // Boutons +/- pour items ajoutés par drag
    document.addEventListener('click', function(e) {
        const btn = e.target.closest('.added-item-qte-btn');
        if (!btn) return;
        saveState();
        const action = btn.dataset.action;
        const projetItem = btn.closest('.projet-item');
        const qteDisplay = projetItem.querySelector('.added-item-qte-display');
        let currentQte = parseInt(qteDisplay.textContent) || 1;
        if (action === 'plus') currentQte++;
        else if (action === 'minus' && currentQte > 1) currentQte--;
        qteDisplay.textContent = currentQte;
        const prix = parseFloat(projetItem.dataset.prix) || 0;
        const totalBadge = projetItem.querySelector('.badge-total');
        if (totalBadge) totalBadge.textContent = window.formatMoney(prix * currentQte * 1.14975);
        window.updateTotals();
        autoSave();
    });

    // Boutons +/- Matériaux
    document.addEventListener('click', function(e) {
        const btn = e.target.closest('.mat-qte-btn');
        if (!btn) return;
        saveState();
        const action = btn.dataset.action;
        const matItem = btn.closest('.projet-mat-item');
        const matQteDisplay = matItem.querySelector('.mat-qte-display');
        let currentQte = parseInt(matItem.dataset.qte) || 1;
        if (action === 'plus') currentQte++;
        else if (action === 'minus' && currentQte > 1) currentQte--;
        
        matItem.dataset.qte = currentQte;
        matQteDisplay.textContent = currentQte;
        
        window.saveItemData(matItem.dataset.catId, matItem.dataset.matId, null, currentQte);
        window.updateMaterialTotal(matItem);
        window.updateAllParents(matItem);
        window.updateTotals();
        autoSave();
    });

    // Supprimer Matériau
    document.addEventListener('click', function(e) {
        const btn = e.target.closest('.remove-mat-btn');
        if (!btn) return;
        saveState();
        const matItem = btn.closest('.projet-mat-item');
        const catId = matItem.dataset.catId;
        const matId = matItem.dataset.matId;
        const parent = matItem.closest('.projet-item');
        
        matItem.remove();
        
        if (parent) window.updateAllParents(parent.querySelector('.tree-content') || parent);
        
        fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `ajax_action=remove_material&cat_id=${catId}&mat_id=${matId}&csrf_token=${bb_csrfToken}`
        });
        
        window.updateTotals();
        autoSave();
    });

    // Edition Prix
    document.addEventListener('click', function(e) {
        const prixBadge = e.target.closest('.editable-prix');
        if (!prixBadge || prixBadge.querySelector('input')) return;

        saveState();
        let targetItem = prixBadge.closest('.projet-mat-item');
        if (!targetItem) targetItem = prixBadge.closest('.projet-item'); // item simple
        if (!targetItem) return;

        const currentPrix = parseFloat(targetItem.dataset.prix) || 0;
        const originalText = prixBadge.textContent;
        let cancelled = false;

        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'prix-input';
        input.value = currentPrix.toFixed(2);
        
        prixBadge.textContent = '';
        prixBadge.appendChild(input);
        input.focus();
        input.select();

        function savePrix() {
            if (cancelled) return;
            const newPrix = parseFloat(input.value.replace(/[^0-9.,]/g, '').replace(',', '.')) || 0;
            targetItem.dataset.prix = newPrix;
            prixBadge.textContent = window.formatMoney(newPrix);
            
            if (targetItem.classList.contains('projet-mat-item')) {
                 window.saveItemData(targetItem.dataset.catId, targetItem.dataset.matId, newPrix, null);
                 window.updateMaterialTotal(targetItem);
                 window.updateAllParents(targetItem);
            } else {
                 window.saveItemData(targetItem.dataset.catId, targetItem.dataset.id, newPrix, null);
                 // TODO: update simple item total
            }
            window.updateTotals();
        }

        input.addEventListener('blur', savePrix);
        input.addEventListener('keydown', function(ev) {
            if (ev.key === 'Enter') {
                ev.preventDefault();
                input.blur();
            } else if (ev.key === 'Escape') {
                cancelled = true;
                prixBadge.textContent = originalText;
            }
        });
    });

    // Boutons +/- Catégorie
    document.addEventListener('click', function(e) {
        const btn = e.target.closest('.cat-qte-btn');
        if (!btn) return;
        saveState();
        const catId = btn.dataset.catId;
        const action = btn.dataset.action;
        const catItem = btn.closest('.projet-item');
        const catQteInput = catItem.querySelector('.cat-qte-input');
        const catQteDisplay = catItem.querySelector('.cat-qte-display');
        let currentQte = parseInt(catQteInput.value) || 1;
        if (action === 'plus') currentQte++;
        else if (action === 'minus' && currentQte > 1) currentQte--;
        
        catQteInput.value = currentQte;
        catQteDisplay.textContent = currentQte;
        
        window.updateCatQte(catId);
    });

    // Fonction Add Item (copie de la logique précédente)
    window.addItemToProjet = function(data, groupe) {
        // ... (même logique que précédemment, réimplémentée ici pour utiliser les variables globales)
        // Pour faire simple, je ne copie pas tout le code ici mais l'idée est que cette fonction est maintenant attachée à window
        // et utilise bb_csrfToken etc.
        // Je vais réintégrer le code complet de addItemToProjet ici pour que ça marche.
        console.log('addItemToProjet', data, groupe);
        // ... (voir plus bas)
        
        const isMaterial = data.type === 'materiau';
        const groupeDiv = document.querySelector(`.projet-groupe[data-groupe="${groupe}"]`);
        if (groupeDiv) groupeDiv.style.display = '';
        const emptyMsg = document.getElementById('projetEmpty');
        if (emptyMsg) emptyMsg.style.display = 'none';
        const zone = document.querySelector(`.projet-drop-zone[data-groupe="${groupe}"]`);
        if (!zone) return;

        if (isMaterial) {
            const existingMat = zone.querySelector(`.projet-mat-item[data-mat-id="${data.id}"]`);
            if (existingMat) {
                existingMat.scrollIntoView({ behavior: 'smooth', block: 'center' });
                flashElement(existingMat);
                pendingMaterialAdd = { existingMat };
                if (document.getElementById('addQteInput')) document.getElementById('addQteInput').value = data.qte || 1;
                if (confirmAddModal) confirmAddModal.show();
                return;
            }
        }
        
        saveState();
        
        let currentContainer = zone;
        
        // 1. Catégorie
        if (data.catId) {
            const catId = data.catId;
            const catNom = data.catNom || 'Catégorie';
            const catContentId = `projetContentCategorie${catId}`;
            let catItem = currentContainer.querySelector(`.projet-item[data-type="categorie"][data-id="${catId}"]`);
            if (!catItem) {
                const html = `
                    <div class="tree-item mb-1 is-kit projet-item" data-type="categorie" data-id="${catId}" data-cat-id="${catId}" data-groupe="${groupe}" data-prix="0">
                        <div class="tree-content">
                            <i class="bi bi-grip-vertical drag-handle"></i>
                            <span class="tree-toggle" onclick="toggleTreeItem(this, '${catContentId}')"><i class="bi bi-caret-down-fill"></i></span>
                            <div class="type-icon"><i class="bi bi-folder-fill text-warning"></i></div>
                            <strong class="flex-grow-1">${escapeHtml(catNom)}</strong>
                            <span class="badge item-badge badge-count text-info me-1"><i class="bi bi-box-seam me-1"></i><span class="item-count">0</span></span>
                            <span class="badge item-badge badge-total text-success fw-bold cat-total me-1">${window.formatMoney(0)}</span>
                            <div class="btn-group btn-group-sm me-2">
                                <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-1 cat-qte-btn" data-cat-id="${catId}" data-action="minus"><i class="bi bi-dash"></i></button>
                                <span class="badge item-badge badge-qte text-light d-flex align-items-center px-2 cat-qte-display" data-cat-id="${catId}">1</span>
                                <input type="hidden" class="cat-qte-input" data-cat-id="${catId}" value="1">
                                <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-1 cat-qte-btn" data-cat-id="${catId}" data-action="plus"><i class="bi bi-plus"></i></button>
                            </div>
                            <button type="button" class="btn btn-sm btn-link text-danger p-0" onclick="removeProjetItem(this)" title="Retirer"><i class="bi bi-x-lg"></i></button>
                        </div>
                        <div class="collapse show tree-children" id="${catContentId}"></div>
                    </div>`;
                const el = createElementFromHTML(html);
                insertInOrder(currentContainer, el, 'catOrdre', data.catOrdre || 0);
                catItem = currentContainer.querySelector(`.projet-item[data-type="categorie"][data-id="${catId}"]`);
            }
            currentContainer = catItem.querySelector('.tree-children');
        }

        // 2. Sous-catégorie
        if (data.scId) {
            const scId = data.scId;
            const scNom = data.scNom || 'Sous-catégorie'; 
            const scContentId = `projetContentSousCategorie${scId}`;
            let scItem = currentContainer.querySelector(`.projet-item[data-type="sous_categorie"][data-id="${scId}"]`);
            if (!scItem) {
                const html = `
                    <div class="tree-item mb-1 is-kit projet-item" data-type="sous_categorie" data-id="${scId}" data-cat-id="${data.catId}" data-groupe="${groupe}" data-prix="0">
                        <div class="tree-content">
                            <span class="tree-connector">└►</span>
                            <i class="bi bi-grip-vertical drag-handle"></i>
                            <span class="tree-toggle" onclick="toggleTreeItem(this, '${scContentId}')"><i class="bi bi-caret-down-fill"></i></span>
                            <div class="type-icon"><i class="bi bi-folder text-warning"></i></div>
                            <strong class="flex-grow-1">${escapeHtml(scNom)}</strong>
                            <span class="badge item-badge badge-count text-info me-1"><i class="bi bi-box-seam me-1"></i><span class="item-count">0</span></span>
                            <span class="badge item-badge badge-total text-success fw-bold cat-total me-1">${window.formatMoney(0)}</span>
                            <!-- Qte héritée -->
                             <div class="btn-group btn-group-sm me-2">
                                <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-1 cat-qte-btn" data-cat-id="${scId}" data-action="minus"><i class="bi bi-dash"></i></button>
                                <span class="badge item-badge badge-qte text-light d-flex align-items-center px-2 cat-qte-display" data-cat-id="${scId}">1</span>
                                <input type="hidden" class="cat-qte-input" data-cat-id="${scId}" value="1">
                                <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-1 cat-qte-btn" data-cat-id="${scId}" data-action="plus"><i class="bi bi-plus"></i></button>
                            </div>
                            <button type="button" class="btn btn-sm btn-link text-danger p-0" onclick="removeProjetItem(this)" title="Retirer"><i class="bi bi-x-lg"></i></button>
                        </div>
                        <div class="collapse show tree-children" id="${scContentId}"></div>
                    </div>`;
                const el = createElementFromHTML(html);
                insertInOrder(currentContainer, el, 'scOrdre', data.scOrdre || 0);
                scItem = currentContainer.querySelector(`.projet-item[data-type="sous_categorie"][data-id="${scId}"]`);
            }
            currentContainer = scItem.querySelector('.tree-children');
        }

        // 3. Matériau
        if (isMaterial) {
            const itemTotal = (parseFloat(data.prix) || 0) * (parseInt(data.qte) || 1);
            const matHtml = `
                <div class="tree-content mat-item projet-mat-item"
                     data-mat-id="${data.id}" data-mat-ordre="${data.matOrdre || 0}" data-cat-id="${data.scId || data.catId}" 
                     data-prix="${data.prix}" data-qte="${data.qte || 1}" data-sans-taxe="0">
                    <span class="tree-connector">└►</span>
                    <i class="bi bi-grip-vertical drag-handle"></i>
                    <div class="type-icon"><i class="bi bi-box-seam text-primary small"></i></div>
                    <span class="flex-grow-1 small">${escapeHtml(data.nom)}</span>
                    <span class="badge item-badge badge-prix text-info me-1 editable-prix" role="button" title="Cliquer pour modifier">${window.formatMoney(parseFloat(data.prix) || 0)}</span>
                    <span class="badge item-badge badge-total text-success fw-bold me-1">${window.formatMoney(itemTotal * 1.14975)}</span>
                    <div class="btn-group btn-group-sm me-2">
                        <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-1 mat-qte-btn" data-action="minus"><i class="bi bi-dash"></i></button>
                        <span class="badge item-badge badge-qte text-light d-flex align-items-center px-2 mat-qte-display">${data.qte || 1}</span>
                        <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-1 mat-qte-btn" data-action="plus"><i class="bi bi-plus"></i></button>
                    </div>
                    <button type="button" class="btn btn-sm btn-link text-danger p-0 remove-mat-btn" title="Retirer"><i class="bi bi-x-lg"></i></button>
                </div>`;
            const matElement = createElementFromHTML(matHtml);
            insertInOrder(currentContainer, matElement, 'matOrdre', data.matOrdre || 0);
            
            window.updateMaterialTotal(matElement);
            window.updateAllParents(matElement);
        }

        const saveId = data.catId || data.id;
        fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `ajax_action=add_dropped_item&type=${data.type}&item_id=${data.id}&cat_id=${saveId}&groupe=${groupe}&prix=${data.prix}&qte=${data.qte || 1}&csrf_token=${bb_csrfToken}`
        });

        window.updateTotals();
    };

});

// ========================================
// HELPERS
// ========================================

window.saveItemData = function(catId, matId, prix, qte) {
    let body = `ajax_action=update_item_data&cat_id=${catId}&mat_id=${matId}&csrf_token=${bb_csrfToken}`;
    if (prix !== null) body += `&prix=${prix}`;
    if (qte !== null) body += `&qte=${qte}`;

    fetch(window.location.href, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body
    });
};

window.updateAllParents = function(element) {
    let parent = element.parentElement ? element.parentElement.closest('.projet-item') : null;
    while (parent) {
        window.updateSousCategorieStats(parent);
        parent = parent.parentElement ? parent.parentElement.closest('.projet-item') : null;
    }
};

window.updateSousCategorieStats = function(container) {
    if (!container) return;

    // 1. Somme des matériaux directs
    let sumHT = 0;
    const matItems = container.querySelectorAll(':scope > .tree-children > .projet-mat-item');
    matItems.forEach(mat => {
        const prix = parseFloat(mat.dataset.prix) || 0;
        const qte = parseInt(mat.dataset.qte) || 1;
        sumHT += prix * qte;
    });
    
    // 2. Somme des sous-containers directs
    const subContainers = container.querySelectorAll(':scope > .tree-children > .projet-item');
    subContainers.forEach(sub => {
        const subTotalProprietaire = parseFloat(sub.dataset.totalPropreHt) || 0;
        const subQteInput = sub.querySelector('.cat-qte-input');
        const subQte = subQteInput ? parseInt(subQteInput.value) || 1 : 1;
        sumHT += subTotalProprietaire * subQte;
    });

    container.dataset.totalPropreHt = sumHT;
    
    // 3. Calculer le total TTC affiché
    let multiplier = 1;
    let p = container;
    while(p) {
        if (p.classList.contains('projet-item')) {
             const qInput = p.querySelector('.cat-qte-input');
             if (qInput) multiplier *= (parseInt(qInput.value) || 1);
        }
        if (p.classList.contains('projet-groupe')) {
             const gInput = p.querySelector('.groupe-qte-input');
             if (gInput) multiplier *= (parseInt(gInput.value) || 1);
        }
        p = p.parentElement ? p.parentElement.closest('.projet-item, .projet-groupe') : null;
    }

    const totalTTC = sumHT * multiplier * 1.14975;
    
    const countSpan = container.querySelector('.item-count');
    const totalMatCount = container.querySelectorAll('.projet-mat-item').length;
    if (countSpan) countSpan.textContent = totalMatCount;

    const totalSpan = container.querySelector('.cat-total');
    if (totalSpan) totalSpan.textContent = window.formatMoney(totalTTC);
};

window.updateMaterialTotal = function(matItem) {
    const prix = parseFloat(matItem.dataset.prix) || 0;
    const qte = parseInt(matItem.dataset.qte) || 1;
    const totalLigneHT = prix * qte;
    
    let multiplier = 1;
    let p = matItem.closest('.projet-item, .projet-groupe');
    while(p) {
        if (p.classList.contains('projet-item')) {
             const qInput = p.querySelector('.cat-qte-input');
             if (qInput) multiplier *= (parseInt(qInput.value) || 1);
        }
        if (p.classList.contains('projet-groupe')) {
             const gInput = p.querySelector('.groupe-qte-input');
             if (gInput) multiplier *= (parseInt(gInput.value) || 1);
        }
        p = p.parentElement ? p.parentElement.closest('.projet-item, .projet-groupe') : null;
    }

    const total = totalLigneHT * multiplier * 1.14975;
    const badge = matItem.querySelector('.badge-total');
    if (badge) badge.textContent = window.formatMoney(total);
};

window.updateTotals = function() {
    // 1. Matériaux
    document.querySelectorAll('.projet-mat-item').forEach(mat => window.updateMaterialTotal(mat));
    
    // 2. Containers (profond d'abord)
    const items = Array.from(document.querySelectorAll('.projet-item'));
    items.sort((a, b) => {
        let depthA = 0, pA = a; while(pA.parentElement) { if(pA.classList.contains('projet-item')) depthA++; pA = pA.parentElement; }
        let depthB = 0, pB = b; while(pB.parentElement) { if(pB.classList.contains('projet-item')) depthB++; pB = pB.parentElement; }
        return depthB - depthA;
    });
    items.forEach(item => window.updateSousCategorieStats(item));

    // 3. Grand total
    let grandTotalHT = 0;
    document.querySelectorAll('.projet-mat-item').forEach(mat => {
         const prix = parseFloat(mat.dataset.prix) || 0;
         const qte = parseInt(mat.dataset.qte) || 1;
         let multiplier = 1;
         let p = mat.closest('.projet-item, .projet-groupe');
         while(p) {
            if (p.classList.contains('projet-item')) {
                 const qInput = p.querySelector('.cat-qte-input');
                 if (qInput) multiplier *= (parseInt(qInput.value) || 1);
            }
            if (p.classList.contains('projet-groupe')) {
                 const gInput = p.querySelector('.groupe-qte-input');
                 if (gInput) multiplier *= (parseInt(gInput.value) || 1);
            }
            p = p.parentElement ? p.parentElement.closest('.projet-item, .projet-groupe') : null;
        }
        grandTotalHT += prix * qte * multiplier;
    });
    
    const contingence = grandTotalHT * (bb_tauxContingence / 100);
    const grandTotalTTC = (grandTotalHT + contingence) * 1.14975;
    
    if (document.getElementById('totalHT')) document.getElementById('totalHT').textContent = window.formatMoney(grandTotalHT);
    if (document.getElementById('totalContingence')) document.getElementById('totalContingence').textContent = window.formatMoney(contingence);
    if (document.getElementById('grandTotal')) document.getElementById('grandTotal').textContent = window.formatMoney(grandTotalTTC);
};

window.formatMoney = function(val) {
    return val.toLocaleString('fr-CA', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' $';
};

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function autoSave() {
    if (bb_saveTimeout) clearTimeout(bb_saveTimeout);
    bb_saveTimeout = setTimeout(function() {
        const saveStatus = document.getElementById('saveSaving');
        if(saveStatus) {
            document.getElementById('saveIdle').classList.add('d-none');
            saveStatus.classList.remove('d-none');
            document.getElementById('saveSaved').classList.add('d-none');
        }

        const items = [];
        const groupes = {};
        document.querySelectorAll('.projet-item').forEach(item => {
            const catQteInput = item.querySelector('.cat-qte-input');
            items.push({
                type: item.dataset.type,
                id: item.dataset.id,
                groupe: item.dataset.groupe,
                quantite: catQteInput ? parseInt(catQteInput.value) : 1
            });
        });
        document.querySelectorAll('.groupe-qte-input').forEach(input => {
            groupes[input.dataset.groupe] = parseInt(input.value) || 1;
        });

        fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                ajax_action: 'save_budget_builder',
                csrf_token: bb_csrfToken,
                items: items,
                groupes: groupes
            })
        })
        .then(response => response.json())
        .then(data => {
            if(saveStatus) {
                saveStatus.classList.add('d-none');
                document.getElementById('saveSaved').classList.remove('d-none');
                setTimeout(() => {
                    document.getElementById('saveSaved').classList.add('d-none');
                    document.getElementById('saveIdle').classList.remove('d-none');
                }, 2000);
            }
        });
    }, 500);
}

function initSortable(element) {
    if (typeof Sortable === 'undefined') return;
    new Sortable(element, {
        group: 'projet-items',
        animation: 150,
        handle: '.drag-handle',
        ghostClass: 'sortable-ghost',
        onStart: function() {
            // saveState(); // TODO: implémenter saveState globalement
        },
        onEnd: function() {
            autoSave();
        }
    });
}
