/**
 * Budget Builder Logic
 * Gestion du Drag & Drop, calculs, et interface arborescente.
 */

document.addEventListener('DOMContentLoaded', function() {
    // Configuration globale (devra être définie avant ce script ou via data attributes)
    // On suppose que ces variables sont disponibles globalement ou passées autrement
    const projetId = window.budgetBuilderConfig?.projetId || 0;
    const tauxContingence = window.budgetBuilderConfig?.tauxContingence || 0;
    const csrfToken = window.budgetBuilderConfig?.csrfToken || '';
    let saveTimeout = null;

    // Variables pour la modal d'ajout
    const confirmAddModalEl = document.getElementById('confirmAddMaterialModal');
    let confirmAddModal = null;
    if (confirmAddModalEl) {
        confirmAddModal = new bootstrap.Modal(confirmAddModalEl);
        const addQteInput = document.getElementById('addQteInput');
        
        // Focus sur l'input quand la modal s'ouvre
        confirmAddModalEl.addEventListener('shown.bs.modal', function () {
            addQteInput.focus();
            addQteInput.select();
        });

        // Support Entrée dans l'input
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
                
                // Undo/redo: on sauvegarde l'état avant modification
                saveState();

                const currentQte = parseInt(existingMat.dataset.qte) || 1;
                const newQte = currentQte + qteToAdd;

                existingMat.dataset.qte = newQte;

                const qteDisplay = existingMat.querySelector('.mat-qte-display');
                if (qteDisplay) qteDisplay.textContent = newQte;

                // Mettre à jour le total (via helper)
                updateMaterialTotal(existingMat);

                // Sauvegarder en base
                saveItemData(existingMat.dataset.catId, existingMat.dataset.matId, null, newQte);

                // Recalcul global
                updateAllParents(existingMat);
                updateTotals();
                autoSave();
                
                // Flash visuel
                flashElement(existingMat);

                // Reset state
                pendingMaterialAdd = null;
            }
        });
    }

    function flashElement(element) {
        element.style.transition = 'background-color 0.5s ease';
        element.style.backgroundColor = 'rgba(25, 135, 84, 0.4)'; // Vert succès
        setTimeout(() => {
            element.style.backgroundColor = '';
        }, 3000);
    }

    // ========================================
    // UNDO/REDO SYSTEM
    // ========================================
    const historyStack = [];
    const redoStack = [];
    const maxHistory = 50;

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

    function saveState() {
        const state = getState();
        if (!state) return;
        historyStack.push(state);
        if (historyStack.length > maxHistory) {
            historyStack.shift();
        }
        redoStack.length = 0; // Clear redo stack on new action
        updateUndoRedoButtons();
    }

    function restoreState(state) {
        if (!state) return;
        document.getElementById('projetContent').innerHTML = state.html;
        // Restore groupe visibility
        Object.keys(state.groupeVisibility).forEach(groupe => {
            const g = document.querySelector(`.projet-groupe[data-groupe="${groupe}"]`);
            if (g) g.style.display = state.groupeVisibility[groupe];
        });
        // Reinitialize sortable on drop zones
        document.querySelectorAll('.projet-drop-zone').forEach(zone => {
            initSortable(zone);
        });
        updateTotals();
    }

    function updateUndoRedoButtons() {
        const undoBtn = document.getElementById('undoBtn');
        const redoBtn = document.getElementById('redoBtn');
        if (undoBtn) undoBtn.disabled = historyStack.length === 0;
        if (redoBtn) redoBtn.disabled = redoStack.length === 0;
    }

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

    // ========================================
    // SPLITTER RESIZABLE
    // ========================================
    const splitter = document.getElementById('splitter');
    const cataloguePanel = document.getElementById('cataloguePanel');
    let isResizing = false;

    if (splitter && cataloguePanel) {
        splitter.addEventListener('mousedown', function(e) {
            isResizing = true;
            splitter.classList.add('dragging');
            document.body.style.cursor = 'col-resize';
            document.body.style.userSelect = 'none';
        });

        document.addEventListener('mousemove', function(e) {
            if (!isResizing) return;
            const container = document.querySelector('.budget-builder');
            const containerRect = container.getBoundingClientRect();
            const newWidth = e.clientX - containerRect.left;
            const minWidth = 300;
            const maxWidth = containerRect.width * 0.6;

            if (newWidth >= minWidth && newWidth <= maxWidth) {
                cataloguePanel.style.width = newWidth + 'px';
            }
        });

        document.addEventListener('mouseup', function() {
            if (isResizing) {
                isResizing = false;
                splitter.classList.remove('dragging');
                document.body.style.cursor = '';
                document.body.style.userSelect = '';
            }
        });
    }

    // ========================================
    // TOGGLE FUNCTIONS
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
        // Supporter les deux formats: ID numérique ou ID string complet
        const content = document.getElementById(id) ||
                        document.getElementById('catContent' + id) ||
                        document.getElementById('projetContent' + id);
        if (content) {
            content.classList.toggle('show');
        }
    };

    // ========================================
    // DRAG & DROP
    // ========================================
    document.querySelectorAll('.catalogue-draggable').forEach(item => {
        item.addEventListener('dragstart', function(e) {
            // Récupérer le chemin complet des parents (si disponible dans data-path)
            // Pour l'instant, on utilise les data attributes existants
            // TODO: Améliorer le catalogue PHP pour fournir data-path complet
            
            e.dataTransfer.setData('text/plain', JSON.stringify({
                type: this.dataset.type,
                id: this.dataset.id,
                // Héritage existant (limité à 2 niveaux pour l'instant)
                scId: this.dataset.scId, 
                catId: this.dataset.catId || this.dataset.id,
                catNom: this.dataset.catNom || this.dataset.nom,
                groupe: this.dataset.groupe,
                nom: this.dataset.nom,
                prix: parseFloat(this.dataset.prix) || 0,
                qte: parseInt(this.dataset.qte) || 1,
                // Ordre
                catOrdre: parseInt(this.dataset.catOrdre) || 0,
                scOrdre: parseInt(this.dataset.scOrdre) || 0,
                matOrdre: parseInt(this.dataset.matOrdre) || 0,
                // Nouveau : Path complet (à implémenter côté PHP)
                path: this.dataset.path ? JSON.parse(this.dataset.path) : null
            }));
            this.style.opacity = '0.5';
        });

        item.addEventListener('dragend', function() {
            this.style.opacity = '1';
        });
    });

    // Initialiser sortable
    function initSortable(element) {
        if (typeof Sortable === 'undefined') return;
        new Sortable(element, {
            group: 'projet-items',
            animation: 150,
            handle: '.drag-handle',
            ghostClass: 'sortable-ghost',
            onStart: function() {
                saveState();
            },
            onEnd: function() {
                autoSave();
            }
        });
    }

    // Zones de drop
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
        
        // Init sortable on load
        initSortable(zone);
    });

    // ========================================
    // HELPER: INSÉRER DANS L'ORDRE
    // ========================================
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

    // Créer élément depuis HTML string
    function createElementFromHTML(htmlString) {
        const div = document.createElement('div');
        div.innerHTML = htmlString.trim();
        return div.firstChild;
    }

    // ========================================
    // AJOUTER AU PROJET (AVEC SUPPORT HIÉRARCHIE)
    // ========================================
    function addItemToProjet(data, groupe) {
        console.log('addItemToProjet called:', { data, groupe });

        const isMaterial = data.type === 'materiau';

        // Afficher le groupe s'il est masqué
        const groupeDiv = document.querySelector(`.projet-groupe[data-groupe="${groupe}"]`);
        if (groupeDiv) {
            groupeDiv.style.display = '';
        }

        // Masquer le message vide
        const emptyMsg = document.getElementById('projetEmpty');
        if (emptyMsg) emptyMsg.style.display = 'none';

        const zone = document.querySelector(`.projet-drop-zone[data-groupe="${groupe}"]`);
        if (!zone) {
            console.error('Drop zone not found for groupe:', groupe);
            return;
        }

        // 1. Vérifier si l'élément final existe déjà (pour les matériaux)
        if (isMaterial) {
            const existingMat = zone.querySelector(`.projet-mat-item[data-mat-id="${data.id}"]`);
            if (existingMat) {
                // Scroll et highlight
                existingMat.scrollIntoView({ behavior: 'smooth', block: 'center' });
                flashElement(existingMat);
                
                // Préparer modal
                pendingMaterialAdd = { existingMat };
                
                // Set qte input
                const addQteInput = document.getElementById('addQteInput');
                if (addQteInput) addQteInput.value = data.qte || 1;

                if (confirmAddModal) confirmAddModal.show();
                return;
            }
        }

        saveState();

        // 2. Construire la hiérarchie parente
        // Si on a data.path (structure complète depuis PHP), on l'utilise.
        // Sinon on fallback sur la logique catId/scId existante (2 niveaux max).
        
        let currentContainer = zone;
        
        // TODO: Implémenter la logique générique avec data.path quand le PHP sera prêt
        // Pour l'instant, on garde la logique hardcodée 2 niveaux, mais structurée pour être extensible
        
        // Niveau 1: Catégorie
        if (data.catId) {
            const catId = data.catId;
            const catNom = data.catNom || 'Catégorie';
            const catOrdre = data.catOrdre || 0;
            const catUniqueId = `categorie-${catId}`;
            const catContentId = `projetContentCategorie${catId}`;
            
            let catItem = currentContainer.querySelector(`.projet-item[data-type="categorie"][data-id="${catId}"]`);
            
            if (!catItem) {
                const html = `
                    <div class="tree-item mb-1 is-kit projet-item"
                         data-type="categorie"
                         data-id="${catId}"
                         data-cat-id="${catId}"
                         data-cat-ordre="${catOrdre}"
                         data-unique-id="${catUniqueId}"
                         data-groupe="${groupe}"
                         data-prix="0">
                        <div class="tree-content">
                            <i class="bi bi-grip-vertical drag-handle"></i>
                            <span class="tree-toggle" onclick="toggleTreeItem(this, '${catContentId}')">
                                <i class="bi bi-caret-down-fill"></i>
                            </span>
                            <div class="type-icon">
                                <i class="bi bi-folder-fill text-warning"></i>
                            </div>
                            <strong class="flex-grow-1">${escapeHtml(catNom)}</strong>
                            <span class="badge item-badge badge-count text-info me-1"><i class="bi bi-box-seam me-1"></i><span class="item-count">0</span></span>
                            <span class="badge item-badge badge-total text-success fw-bold cat-total me-1" data-cat-id="${catId}">${formatMoney(0)}</span>
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
                insertInOrder(currentContainer, el, 'catOrdre', catOrdre);
                catItem = currentContainer.querySelector(`.projet-item[data-type="categorie"][data-id="${catId}"]`);
            }
            currentContainer = catItem.querySelector('.tree-children');
        }

        // Niveau 2: Sous-catégorie (si présente)
        if (data.scId) {
            const scId = data.scId;
            // Note: data.nom contient le nom de l'élément dropé. Si on drop un matériau, on n'a pas le nom de la sous-catégorie ici dans data.nom !
            // Il faut que data.scNom soit passé.
            const scNom = data.scNom || 'Sous-catégorie'; 
            const scOrdre = data.scOrdre || 0;
            const scUniqueId = `sous_categorie-${scId}`;
            const scContentId = `projetContentSousCategorie${scId}`;
            
            let scItem = currentContainer.querySelector(`.projet-item[data-type="sous_categorie"][data-id="${scId}"]`);
            
            if (!scItem) {
                const html = `
                    <div class="tree-item mb-1 is-kit projet-item"
                         data-type="sous_categorie"
                         data-id="${scId}"
                         data-sc-ordre="${scOrdre}"
                         data-unique-id="${scUniqueId}"
                         data-groupe="${groupe}"
                         data-prix="0">
                        <div class="tree-content">
                            <span class="tree-connector">└►</span>
                            <i class="bi bi-grip-vertical drag-handle"></i>
                            <span class="tree-toggle" onclick="toggleTreeItem(this, '${scContentId}')">
                                <i class="bi bi-caret-down-fill"></i>
                            </span>
                            <div class="type-icon"><i class="bi bi-folder text-warning"></i></div>
                            <strong class="flex-grow-1">${escapeHtml(scNom)}</strong>
                            <span class="badge item-badge badge-count text-info me-1"><i class="bi bi-box-seam me-1"></i><span class="item-count">0</span></span>
                            <span class="badge item-badge badge-total text-success fw-bold cat-total me-1">${formatMoney(0)}</span>
                            <!-- Pas de qte pour sous-catégorie pour l'instant, hérite de catégorie -->
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
                insertInOrder(currentContainer, el, 'scOrdre', scOrdre);
                scItem = currentContainer.querySelector(`.projet-item[data-type="sous_categorie"][data-id="${scId}"]`);
            }
            currentContainer = scItem.querySelector('.tree-children');
        }

        // 3. Ajouter l'élément final (Matériau)
        if (isMaterial) {
            const itemTotal = (parseFloat(data.prix) || 0) * (parseInt(data.qte) || 1);
            const matOrdre = data.matOrdre || 0;
            const matHtml = `
                <div class="tree-content mat-item projet-mat-item"
                     data-mat-id="${data.id}"
                     data-mat-ordre="${matOrdre}"
                     data-cat-id="${data.scId || data.catId}" 
                     data-prix="${data.prix}"
                     data-qte="${data.qte || 1}"
                     data-sans-taxe="0">
                    <span class="tree-connector">└►</span>
                    <i class="bi bi-grip-vertical drag-handle" style="font-size: 0.85em;"></i>
                    <div class="type-icon"><i class="bi bi-box-seam text-primary small"></i></div>
                    <span class="flex-grow-1 small">${escapeHtml(data.nom)}</span>

                    <span class="badge item-badge badge-prix text-info me-1 editable-prix" role="button" title="Cliquer pour modifier">${formatMoney(parseFloat(data.prix) || 0)}</span>
                    <span class="badge item-badge badge-total text-success fw-bold me-1">${formatMoney(itemTotal * 1.14975)}</span>

                    <div class="btn-group btn-group-sm me-2">
                        <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-1 mat-qte-btn" data-action="minus"><i class="bi bi-dash"></i></button>
                        <span class="badge item-badge badge-qte text-light d-flex align-items-center px-2 mat-qte-display">${data.qte || 1}</span>
                        <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-1 mat-qte-btn" data-action="plus"><i class="bi bi-plus"></i></button>
                    </div>

                    <button type="button" class="btn btn-sm btn-link text-danger p-0 remove-mat-btn" title="Retirer"><i class="bi bi-x-lg"></i></button>
                </div>
            `;
            const matElement = createElementFromHTML(matHtml);
            
            // Insérer dans l'ordre
            const existingMats = currentContainer.querySelectorAll('.projet-mat-item');
            let inserted = false;
            for (const existing of existingMats) {
                const existingOrdre = parseInt(existing.dataset.matOrdre) || 0;
                if (matOrdre < existingOrdre) {
                    currentContainer.insertBefore(matElement, existing);
                    inserted = true;
                    break;
                }
            }
            if (!inserted) {
                currentContainer.appendChild(matElement);
            }
            
            // Recalculer
            updateMaterialTotal(matElement); // Calculer total ligne TTC avec parents
            updateAllParents(matElement);    // Remonter les totaux
        }
        // Si ce n'est pas un matériau (ex: drop d'une catégorie entière), gérer ici (pas implémenté full recursive drop yet)
        
        console.log('Item added successfully');

        // Sauvegarder en base de données
        const saveId = data.catId || data.id; // ID de la catégorie racine pour l'instant (legacy)
        
        fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `ajax_action=add_dropped_item&type=${data.type}&item_id=${data.id}&cat_id=${saveId}&groupe=${groupe}&prix=${data.prix}&qte=${data.qte || 1}&csrf_token=${csrfToken}`
        })
        .then(r => r.json())
        .then(result => {
             // ... gestion erreur ...
             updateTotals();
        })
        .catch(err => {
             // ...
        });

        updateTotals();
    }

    // ========================================
    // MISES À JOUR & CALCULS
    // ========================================

    // Met à jour récursivement les totaux des parents
    function updateAllParents(element) {
        let parent = element.parentElement ? element.parentElement.closest('.projet-item') : null;
        while (parent) {
            updateContainerStats(parent);
            parent = parent.parentElement ? parent.parentElement.closest('.projet-item') : null;
        }
    }

    // Met à jour le total affiché d'un CONTAINER (Catégorie ou Sous-catégorie)
    function updateContainerStats(container) {
        if (!container) return;

        // Calculer la somme des enfants directs (matériaux et sous-containers)
        // Attention: il faut prendre le HT de chaque enfant pour faire la somme, puis appliquer les multiplicateurs DU container
        
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
            // Ici on a besoin du total HT du sous-container (SANS ses propres multiplicateurs parents, mais AVEC ses propres quantités)
            // On va stocker le "total propre HT" dans un data attribute pour faciliter
            const subTotalProprietaire = parseFloat(sub.dataset.totalPropreHt) || 0;
            
            // Le sub-container a sa propre quantité ?
            const subQteInput = sub.querySelector('.cat-qte-input'); // On utilise la même classe pour l'instant
            const subQte = subQteInput ? parseInt(subQteInput.value) || 1 : 1;
            
            sumHT += subTotalProprietaire * subQte;
        });

        // Stocker le total propre HT de ce container (pour son parent)
        container.dataset.totalPropreHt = sumHT;
        
        // 3. Calculer le total à afficher (TTC avec TOUS les multiplicateurs parents)
        // On doit remonter pour trouver tous les multiplicateurs
        let multiplier = 1;
        let p = container;
        while(p) {
            // Si c'est un projet-item, chercher sa qte
            if (p.classList.contains('projet-item')) {
                 const qInput = p.querySelector('.cat-qte-input');
                 if (qInput) multiplier *= (parseInt(qInput.value) || 1);
            }
            // Si c'est un projet-groupe
            if (p.classList.contains('projet-groupe')) {
                 const gInput = p.querySelector('.groupe-qte-input');
                 if (gInput) multiplier *= (parseInt(gInput.value) || 1);
            }
            p = p.parentElement ? p.parentElement.closest('.projet-item, .projet-groupe') : null;
        }

        // Affichage (TTC approximatif pour l'UI)
        const totalTTC = sumHT * multiplier * 1.14975;
        
        const countSpan = container.querySelector('.item-count');
        // Compter tous les matériaux descendants pour l'info
        const totalMatCount = container.querySelectorAll('.projet-mat-item').length;
        if (countSpan) countSpan.textContent = totalMatCount;

        const totalSpan = container.querySelector('.cat-total');
        if (totalSpan) totalSpan.textContent = formatMoney(totalTTC);
    }
    
    // Helper pour mettre à jour le total d'un matériau
    function updateMaterialTotal(matItem) {
        const prix = parseFloat(matItem.dataset.prix) || 0;
        const qte = parseInt(matItem.dataset.qte) || 1;
        const totalLigneHT = prix * qte;
        
        // Calculer multiplicateurs parents
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
        if (badge) badge.textContent = formatMoney(total);
    }

    // GLOBAL: Recalculer TOUT (utile après chargement ou gros changement)
    function updateTotals() {
        // 1. Mettre à jour tous les matériaux
        document.querySelectorAll('.projet-mat-item').forEach(mat => updateMaterialTotal(mat));
        
        // 2. Mettre à jour les containers, du plus profond au plus haut
        // On sélectionne tous les items et on trie par profondeur (nombre de parents) décroissant
        const items = Array.from(document.querySelectorAll('.projet-item'));
        items.sort((a, b) => {
            const depthA = getDepth(a);
            const depthB = getDepth(b);
            return depthB - depthA; // Les plus profonds en premier
        });
        
        items.forEach(item => updateContainerStats(item));

        // 3. Calculer grand total (somme des totaux affichés des groupes racines ?)
        // Ou refaire le calcul global
        let grandTotalHT = 0;
        // ... (logique existante pour grand total, simplifiée) ...
        // Pour faire simple et robuste, on somme tous les matériaux * leurs multiplicateurs
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
        
        const contingence = grandTotalHT * (tauxContingence / 100);
        const grandTotalTTC = (grandTotalHT + contingence) * 1.14975; // Simplifié (taxes sur tout)
        
        document.getElementById('totalHT').textContent = formatMoney(grandTotalHT);
        document.getElementById('totalContingence').textContent = formatMoney(contingence);
        document.getElementById('grandTotal').textContent = formatMoney(grandTotalTTC);
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

    // Expose helpers globally
    window.updateMaterialTotal = updateMaterialTotal;
    window.updateAllParents = updateAllParents;
    window.updateTotals = updateTotals;
    window.saveItemData = saveItemData;
    window.formatMoney = formatMoney;
    
    // LISTENERS (Qté Catégorie, Groupe, etc.)
    // ... (Adapter les listeners existants pour appeler updateAllParents ou updateTotals) ...
    // Note: Avec updateTotals() qui refait tout intelligemment, on peut juste appeler updateTotals() sur les changements de structure/groupe
    // Et updateAllParents(item) sur changement item spécifique.

    document.addEventListener('click', function(e) {
        // ... (logique boutons +/- existante, pointer vers les nouvelles fonctions) ...
        const btn = e.target.closest('.cat-qte-btn');
        if (btn) {
            // ... logique +/- ...
            // Après modif valeur input :
            updateTotals(); // Recalcul global car un multiplicateur a changé
            autoSave();
        }
    });

    // ... (Reste des fonctions utilitaires formatMoney, etc.) ...
    function formatMoney(val) {
        return val.toLocaleString('fr-CA', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' $';
    }
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
});
