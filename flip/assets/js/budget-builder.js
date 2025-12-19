/**
 * Budget Builder Logic
 * Gestion du Drag & Drop, calculs, et interface arborescente.
 */

// Fonction utilitaire pour échapper les caractères HTML
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text || '';
    return div.innerHTML;
}

// Variables globales du module (pour être accessibles si besoin, mais gérées dans DOMContentLoaded)
let bb_projetId = 0;
let bb_tauxContingence = 0;
let bb_csrfToken = '';

document.addEventListener('DOMContentLoaded', function() {
    console.log('Budget Builder initializing...');
    
    // Init Config
    bb_projetId = window.budgetBuilderConfig?.projetId || 0;
    bb_tauxContingence = window.budgetBuilderConfig?.tauxContingence || 0;
    bb_csrfToken = window.budgetBuilderConfig?.csrfToken || '';

    let saveTimeout = null;
    let pendingMaterialAdd = null;
    
    const historyStack = [];
    const redoStack = [];
    const maxHistory = 50;

    // Variables pour la modal d'ajout
    const confirmAddModalEl = document.getElementById('confirmAddMaterialModal');
    let confirmAddModal = null;

    // ========================================
    // FONCTIONS INTERNES (Accessibles par closure)
    // ========================================

    function saveState() {
        const state = getState();
        if (!state) return;
        historyStack.push(state);
        if (historyStack.length > maxHistory) historyStack.shift();
        redoStack.length = 0;
        updateUndoRedoButtons();
    }

    function getState() {
        const content = document.getElementById('projetContent');
        if (!content) return null;
        const state = {
            html: content.innerHTML,
            groupeVisibility: {}
        };
        document.querySelectorAll('.projet-groupe').forEach(g => {
            state.groupeVisibility[g.dataset.groupe] = g.style.display;
        });
        return state;
    }

    function restoreState(state) {
        if (!state) return;
        document.getElementById('projetContent').innerHTML = state.html;
        Object.keys(state.groupeVisibility).forEach(groupe => {
            const g = document.querySelector(`.projet-groupe[data-groupe="${groupe}"]`);
            if (g) g.style.display = state.groupeVisibility[groupe];
        });
        document.querySelectorAll('.projet-drop-zone').forEach(zone => {
            initSortable(zone);
        });
        window.updateTotals();
    }

    function updateUndoRedoButtons() {
        const undoBtn = document.getElementById('undoBtn');
        const redoBtn = document.getElementById('redoBtn');
        if (undoBtn) undoBtn.disabled = historyStack.length === 0;
        if (redoBtn) redoBtn.disabled = redoStack.length === 0;
    }

    function autoSave() {
        if (saveTimeout) clearTimeout(saveTimeout);
        saveTimeout = setTimeout(function() {
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
    
    function flashElement(element) {
        element.style.transition = 'background-color 0.5s ease';
        element.style.backgroundColor = 'rgba(25, 135, 84, 0.4)';
        setTimeout(() => {
            element.style.backgroundColor = '';
        }, 3000);
    }

    function getDepth(element) {
        let depth = 0;
        let p = element.parentElement;
        while(p) {
            if (p.classList.contains('projet-item')) depth++;
            p = p.parentElement;
        }
        return depth;
    }

    function initSortable(element) {
        if (typeof Sortable === 'undefined') return;
        new Sortable(element, {
            group: 'projet-items',
            animation: 150,
            handle: '.drag-handle',
            ghostClass: 'sortable-ghost',
            onStart: function() {
                // saveState();
            },
            onEnd: function() {
                autoSave();
            }
        });
    }
    
    function insertInOrder(container, newElement, orderAttr, orderValue) {
        const children = container.querySelectorAll(':scope > .tree-item, :scope > .projet-item');
        let inserted = false;
        for (const child of children) {
            const childOrder = parseInt(child.dataset[orderAttr]) || 0;
            if (orderValue < childOrder) {
                container.insertBefore(newElement, child);
                inserted = true;
                break;
            }
        }
        if (!inserted) {
            container.appendChild(newElement);
        }
    }

    function createElementFromHTML(htmlString) {
        const div = document.createElement('div');
        div.innerHTML = htmlString.trim();
        return div.firstChild;
    }

    // ========================================
    // EXPOSITION DES FONCTIONS GLOBALES (WINDOW)
    // Définies ici pour avoir accès aux fonctions internes via la closure
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

    window.formatMoney = function(val) {
        return val.toLocaleString('fr-CA', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' $';
    };

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

    window.updateContainerStats = window.updateSousCategorieStats;

    window.updateAllParents = function(element) {
        let parent = element.parentElement ? element.parentElement.closest('.projet-item') : null;
        while (parent) {
            window.updateSousCategorieStats(parent);
            parent = parent.parentElement ? parent.parentElement.closest('.projet-item') : null;
        }
    };

    window.updateTotals = function() {
        // 1. Matériaux
        document.querySelectorAll('.projet-mat-item').forEach(mat => window.updateMaterialTotal(mat));
        
        // 2. Containers (profond d'abord)
        const items = Array.from(document.querySelectorAll('.projet-item'));
        items.sort((a, b) => {
            const depthA = getDepth(a);
            const depthB = getDepth(b);
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

    window.changeCatQte = function(catId, delta) {
        const input = document.querySelector(`.cat-qte-input[data-cat-id="${catId}"]`);
        if (input) {
            saveState();
            const newVal = Math.max(1, Math.min(20, parseInt(input.value) + delta));
            input.value = newVal;
            window.updateCatQte(catId);
        }
    };

    window.updateCatQte = function(catId) {
        const catItem = document.querySelector(`.projet-item[data-type="categorie"][data-id="${catId}"]`);
        if (catItem) {
            // Mettre à jour récursivement
            window.updateAllParents(catItem.querySelector('.cat-qte-input') || catItem); 
        }
        window.updateTotals();
        autoSave();
    };

    window.changeGroupeQte = function(groupe, delta) {
        const input = document.querySelector(`.groupe-qte-input[data-groupe="${groupe}"]`);
        if (input) {
            saveState();
            const newVal = Math.max(1, Math.min(20, parseInt(input.value) + delta));
            input.value = newVal;
            window.updateGroupeQte(groupe);
        }
    };

    window.updateGroupeQte = function(groupe) {
        window.updateTotals();
        autoSave();
    };

    window.removeProjetItem = function(btn) {
        saveState();
        const item = btn.closest('.projet-item');
        const groupe = item.dataset.groupe;
        const parent = item.parentElement.closest('.projet-item');
        
        item.remove();

        const zone = document.querySelector(`.projet-drop-zone[data-groupe="${groupe}"]`);
        if (zone && zone.querySelectorAll('.projet-item').length === 0) {
            zone.closest('.projet-groupe').style.display = 'none';
        }

        if (document.querySelectorAll('.projet-item').length === 0) {
            document.getElementById('projetEmpty').style.display = '';
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
        document.getElementById('projetEmpty').style.display = '';

        fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `ajax_action=clear_all_budget&csrf_token=${bb_csrfToken}`
        });

        window.updateTotals();
    };

    window.undoAction = function() {
        if (historyStack.length === 0) return;
        const currentState = getState();
        redoStack.push(currentState);
        const previousState = historyStack.pop();
        restoreState(previousState);
        updateUndoRedoButtons();
        autoSave();
    };

    window.redoAction = function() {
        if (redoStack.length === 0) return;
        const currentState = getState();
        historyStack.push(currentState);
        const nextState = redoStack.pop();
        restoreState(nextState);
        updateUndoRedoButtons();
        autoSave();
    };

    window.addItemToProjet = function(data, groupe) {
        console.log('addItemToProjet', data, groupe);
        
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
                    <div class="tree-item mb-1 is-kit projet-item" data-type="categorie" data-id="${catId}" data-cat-id="${catId}" data-groupe="${groupe}" data-prix="0" data-total-propre-ht="0">
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
            const scNom = data.scNom || 'Sous-catégorie'; // Note: scNom manquant si drop matériel
            const scContentId = `projetContentSousCategorie${scId}`;
            let scItem = currentContainer.querySelector(`.projet-item[data-type="sous_categorie"][data-id="${scId}"]`);
            if (!scItem) {
                const html = `
                    <div class="tree-item mb-1 is-kit projet-item" data-type="sous_categorie" data-id="${scId}" data-cat-id="${data.catId}" data-groupe="${groupe}" data-prix="0" data-total-propre-ht="0">
                        <div class="tree-content">
                            <span class="tree-connector">└►</span>
                            <i class="bi bi-grip-vertical drag-handle"></i>
                            <span class="tree-toggle" onclick="toggleTreeItem(this, '${scContentId}')"><i class="bi bi-caret-down-fill"></i></span>
                            <div class="type-icon"><i class="bi bi-folder text-warning"></i></div>
                            <strong class="flex-grow-1">${escapeHtml(scNom)}</strong>
                            <span class="badge item-badge badge-count text-info me-1"><i class="bi bi-box-seam me-1"></i><span class="item-count">0</span></span>
                            <span class="badge item-badge badge-total text-success fw-bold cat-total me-1">${window.formatMoney(0)}</span>
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
                    <i class="bi bi-grip-vertical drag-handle" style="font-size: 0.85em;"></i>
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

    // INIT MODAL CONFIRM (si présente)
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
                
                flashElement(existingMat);

                pendingMaterialAdd = null;
            }
        });
    }

    // INIT DRAG & DROP
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
                window.addItemToProjet(data, this.dataset.groupe);
            } catch (err) {
                console.error('Drop error:', err);
            }
        });
        initSortable(zone);
    });

    // EVENT DELEGATION
    document.addEventListener('click', function(e) {
        // +/- Catégorie/Sous-catégorie (delegated)
        if (e.target.closest('.cat-qte-btn')) {
            const btn = e.target.closest('.cat-qte-btn');
            saveState();
            const action = btn.dataset.action;
            const catId = btn.dataset.catId;
            const catItem = btn.closest('.projet-item');
            const catQteInput = catItem.querySelector('.cat-qte-input');
            const catQteDisplay = catItem.querySelector('.cat-qte-display');

            let currentQte = parseInt(catQteInput.value) || 1;
            if (action === 'plus') currentQte = Math.min(20, currentQte + 1);
            else if (action === 'minus' && currentQte > 1) currentQte--;

            catQteInput.value = currentQte;
            catQteDisplay.textContent = currentQte;

            window.updateAllParents(catItem);
            window.updateTotals();
            autoSave();
        }

        // +/- Matériau (delegated)
        if (e.target.closest('.mat-qte-btn')) {
            const btn = e.target.closest('.mat-qte-btn');
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
        }

        // Remove Matériau (delegated)
        if (e.target.closest('.remove-mat-btn')) {
             const btn = e.target.closest('.remove-mat-btn');
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
        }
    });

    console.log('Budget Builder Initialized');
});
