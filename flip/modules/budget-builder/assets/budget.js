/**
 * Budget Builder - JavaScript
 */

const BudgetBuilder = {
    projetId: null,
    ajaxUrl: null,
    panierDropInitialized: false, // Flag pour éviter les multiples handlers

    // Undo/Redo stacks
    undoStack: [],
    redoStack: [],
    maxHistorySize: 50,
    isRestoring: false, // Flag pour éviter de sauvegarder pendant une restauration

    init: function(projetId) {
        this.projetId = projetId;

        // Détecter l'URL de base pour l'AJAX
        const path = window.location.pathname;

        if (path.includes('/modules/budget-builder/')) {
            // On est dans le module standalone
            this.ajaxUrl = path.replace(/\/[^\/]*$/, '/') + 'ajax.php';
        } else if (path.includes('/admin/projets/')) {
            // On est dans admin/projets
            this.ajaxUrl = path.replace(/\/admin\/projets\/.*$/, '/modules/budget-builder/ajax.php');
        } else {
            // Fallback
            this.ajaxUrl = '/flip/modules/budget-builder/ajax.php';
        }

        this.initPanierDrop(); // Initialiser UNE SEULE FOIS le drop sur le panier
        this.initCatalogueDrag(); // Initialiser le drag sur les éléments du catalogue
        this.initQuantityChange();
        this.initUndoRedoKeyboard(); // Raccourcis clavier Ctrl+Z / Ctrl+Y

        console.log('Budget Builder initialized', { projetId: this.projetId });
    },

    // ================================
    // DRAG & DROP - Panier (une seule fois)
    // ================================

    initPanierDrop: function() {
        if (this.panierDropInitialized) return; // Ne pas réinitialiser

        const self = this;
        const panierCard = document.getElementById('panier-card');

        if (panierCard) {
            panierCard.addEventListener('dragover', function(e) {
                e.preventDefault();
                e.dataTransfer.dropEffect = 'copy';
                this.classList.add('drag-over');
            });

            panierCard.addEventListener('dragleave', function(e) {
                if (!this.contains(e.relatedTarget)) {
                    this.classList.remove('drag-over');
                }
            });

            panierCard.addEventListener('drop', function(e) {
                e.preventDefault();
                e.stopPropagation();
                this.classList.remove('drag-over');

                try {
                    const data = JSON.parse(e.dataTransfer.getData('text/plain'));
                    console.log('Drop data:', data);

                    if (data.id) {
                        if (data.type === 'folder') {
                            console.log('Adding folder to panier:', data.id);
                            self.addFolderToPanier(parseInt(data.id));
                        } else {
                            console.log('Adding item to panier:', data.id);
                            self.addToPanier(parseInt(data.id));
                        }
                    }
                } catch (err) {
                    console.error('Drop error:', err);
                }
            });

            this.panierDropInitialized = true;
        }
    },

    // ================================
    // DRAG & DROP - Catalogue (réinitialisable)
    // ================================

    initCatalogueDrag: function() {
        const self = this;

        // Tous les éléments du catalogue sont draggables (items ET dossiers)
        document.querySelectorAll('.catalogue-item').forEach(item => {
            item.draggable = true;

            item.addEventListener('dragstart', function(e) {
                // Ne pas permettre le drag depuis un bouton ou le nom (pour permettre édition)
                const target = e.target;
                if (target.tagName === 'BUTTON' || target.tagName === 'I' ||
                    target.classList.contains('item-nom') ||
                    target.classList.contains('btn') ||
                    target.closest('button')) {
                    e.preventDefault();
                    return;
                }

                e.stopPropagation();
                e.dataTransfer.setData('text/plain', JSON.stringify({
                    id: this.dataset.id,
                    type: this.dataset.type
                }));
                e.dataTransfer.effectAllowed = 'copyMove';
                this.classList.add('dragging');

                // Marquer globalement qu'on drag
                document.body.classList.add('is-dragging');
            });

            item.addEventListener('dragend', function() {
                this.classList.remove('dragging');
                document.body.classList.remove('is-dragging');

                // Nettoyer tous les indicateurs
                document.querySelectorAll('.drag-over, .drag-above, .drag-below, .drag-into').forEach(el => {
                    el.classList.remove('drag-over', 'drag-above', 'drag-below', 'drag-into');
                });
            });

            // Drop sur un élément du catalogue (pour réorganiser)
            item.addEventListener('dragover', function(e) {
                e.preventDefault();
                e.stopPropagation();

                // Ignorer les section headers - ils ont leur propre handler
                if (this.classList.contains('is-section-header')) return;

                const dragging = document.querySelector('.catalogue-item.dragging');
                if (!dragging || dragging === this) return;

                const rect = this.getBoundingClientRect();
                const y = e.clientY - rect.top;
                const height = rect.height;

                // Nettoyer les classes
                this.classList.remove('drag-above', 'drag-below', 'drag-into');

                if (this.dataset.type === 'folder') {
                    // Sur un dossier: au-dessus, dedans, ou en-dessous
                    if (y < height * 0.25) {
                        this.classList.add('drag-above');
                    } else if (y > height * 0.75) {
                        this.classList.add('drag-below');
                    } else {
                        this.classList.add('drag-into');
                    }
                } else {
                    // Sur un item: au-dessus ou en-dessous
                    if (y < height / 2) {
                        this.classList.add('drag-above');
                    } else {
                        this.classList.add('drag-below');
                    }
                }
            });

            item.addEventListener('dragleave', function(e) {
                if (this.classList.contains('is-section-header')) return;
                this.classList.remove('drag-above', 'drag-below', 'drag-into');
            });

            item.addEventListener('drop', function(e) {
                e.preventDefault();
                e.stopPropagation();

                // Ignorer les section headers - ils ont leur propre handler
                if (this.classList.contains('is-section-header')) return;

                const data = JSON.parse(e.dataTransfer.getData('text/plain'));
                const draggedId = parseInt(data.id);
                const targetId = parseInt(this.dataset.id);

                if (draggedId === targetId || !targetId) return;

                let position = 'after';
                let newParentId = null;

                if (this.classList.contains('drag-above')) {
                    position = 'before';
                } else if (this.classList.contains('drag-into') && this.dataset.type === 'folder') {
                    position = 'into';
                    newParentId = targetId;
                }

                // Nettoyer
                this.classList.remove('drag-above', 'drag-below', 'drag-into');

                // Appeler l'API pour déplacer
                self.moveItem(draggedId, targetId, position, newParentId);
            });
        });

        // Drop sur les en-têtes de section (pour changer l'étape)
        document.querySelectorAll('.is-section-header').forEach(sectionHeader => {
            sectionHeader.addEventListener('dragover', function(e) {
                e.preventDefault();
                e.stopPropagation();

                const dragging = document.querySelector('.catalogue-item.dragging');
                if (!dragging) return;

                // Ne pas permettre de drag une section sur elle-même
                if (dragging.classList.contains('is-section-header')) return;

                this.classList.add('drag-over');
                e.dataTransfer.dropEffect = 'move';
            });

            sectionHeader.addEventListener('dragleave', function(e) {
                this.classList.remove('drag-over');
            });

            sectionHeader.addEventListener('drop', function(e) {
                e.preventDefault();
                e.stopPropagation();
                this.classList.remove('drag-over');

                try {
                    const data = JSON.parse(e.dataTransfer.getData('text/plain'));
                    if (!data.id) return;

                    // Récupérer l'étape de la section cible
                    const section = this.closest('.etape-section');
                    const targetEtapeId = section ? section.dataset.etapeId : null;

                    // Déplacer l'item vers cette section
                    self.moveToSection(parseInt(data.id), targetEtapeId === 'null' ? null : parseInt(targetEtapeId));
                } catch (err) {
                    console.error('Drop error:', err);
                }
            });
        });

        // Drop sur les zones de contenu des sections
        document.querySelectorAll('.section-children').forEach(sectionContent => {
            sectionContent.addEventListener('dragover', function(e) {
                // Seulement si on survole directement la zone (pas un enfant)
                if (e.target === this) {
                    e.preventDefault();
                    this.classList.add('drag-over');
                }
            });

            sectionContent.addEventListener('dragleave', function(e) {
                if (e.target === this) {
                    this.classList.remove('drag-over');
                }
            });

            sectionContent.addEventListener('drop', function(e) {
                if (e.target !== this) return;

                e.preventDefault();
                e.stopPropagation();
                this.classList.remove('drag-over');

                try {
                    const data = JSON.parse(e.dataTransfer.getData('text/plain'));
                    if (!data.id) return;

                    const targetEtapeId = this.dataset.etape;
                    self.moveToSection(parseInt(data.id), targetEtapeId === 'null' ? null : parseInt(targetEtapeId));
                } catch (err) {
                    console.error('Drop error:', err);
                }
            });
        });
    },

    moveItem: function(itemId, targetId, position, newParentId) {
        const self = this;
        this.ajax('move_catalogue_item', {
            id: itemId,
            target_id: targetId,
            position: position,
            new_parent_id: newParentId
        }).then(response => {
            if (response.success) {
                self.refreshAfterBudgetChange();
            } else {
                alert('Erreur: ' + (response.message || 'Échec du déplacement'));
            }
        });
    },

    moveToSection: function(itemId, etapeId) {
        const self = this;
        this.ajax('move_to_section', {
            id: itemId,
            etape_id: etapeId
        }).then(response => {
            if (response.success) {
                self.refreshAfterBudgetChange();
            } else {
                alert('Erreur: ' + (response.message || 'Échec du déplacement'));
            }
        });
    },

    addFolderToPanier: function(folderId) {
        const self = this;
        console.log('addFolderToPanier called with folderId:', folderId, 'projetId:', this.projetId);

        if (!this.projetId) {
            alert('Sélectionnez d\'abord un projet (projet_id manquant)');
            return;
        }

        // D'abord récupérer les infos du folder
        this.ajax('get_folder_info', {
            projet_id: this.projetId,
            folder_id: folderId
        }).then(response => {
            if (response.success) {
                self.showFolderQuantityModal(folderId, response);
            } else {
                alert('Erreur: ' + (response.message || 'Échec'));
            }
        });
    },

    showFolderQuantityModal: function(folderId, folderInfo) {
        const self = this;

        const existsText = folderInfo.existing_in_cart > 0
            ? `<div class="alert alert-info mb-3">
                 <i class="bi bi-info-circle me-2"></i>
                 Déjà dans le panier: <strong>${folderInfo.existing_quantity}</strong> unité(s)
                 (${folderInfo.existing_in_cart} item(s) différent(s))
               </div>`
            : '';

        const modalHtml = `
            <div class="modal fade" id="folderQuantityModal" tabindex="-1">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header bg-warning text-dark">
                            <h5 class="modal-title"><i class="bi bi-folder-plus me-2"></i>Ajouter le dossier</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <h6 class="mb-3"><i class="bi bi-folder-fill text-warning me-2"></i>${this.escapeHtml(folderInfo.folder_name)}</h6>
                            <p class="text-muted mb-3">Ce dossier contient <strong>${folderInfo.item_count}</strong> item(s)</p>
                            ${existsText}
                            <div class="mb-3">
                                <label class="form-label">Combien de fois ajouter ce dossier?</label>
                                <input type="number" class="form-control" id="folderQuantityInput" value="1" min="1" autofocus>
                                <small class="text-muted">Les quantités des items seront multipliées par ce nombre</small>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                            <button type="button" class="btn btn-warning" id="confirmAddFolderBtn">
                                <i class="bi bi-folder-plus me-1"></i>Ajouter
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        `;

        // Supprimer l'ancien modal s'il existe
        const oldModal = document.getElementById('folderQuantityModal');
        if (oldModal) oldModal.remove();

        // Ajouter le nouveau modal
        document.body.insertAdjacentHTML('beforeend', modalHtml);

        const modal = new bootstrap.Modal(document.getElementById('folderQuantityModal'));
        const confirmBtn = document.getElementById('confirmAddFolderBtn');
        const input = document.getElementById('folderQuantityInput');

        confirmBtn.addEventListener('click', function() {
            const quantite = parseInt(input.value) || 1;
            modal.hide();

            // Sauvegarder l'état AVANT l'ajout
            self.saveStateForUndo().then(() => {
                self.ajax('add_folder_to_panier', {
                    projet_id: self.projetId,
                    folder_id: folderId,
                    quantite: quantite
                }).then(response => {
                    if (response.success) {
                        self.refreshAfterBudgetChange();
                    } else {
                        alert('Erreur: ' + (response.message || 'Échec'));
                    }
                });
            });
        });

        // Permettre Enter pour confirmer
        input.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                confirmBtn.click();
            }
        });

        modal.show();

        // Focus sur l'input après ouverture du modal
        document.getElementById('folderQuantityModal').addEventListener('shown.bs.modal', function() {
            input.select();
        });
    },

    // ================================
    // QUANTITE
    // ================================

    initQuantityChange: function() {
        const self = this;

        // Handlers pour les boutons + et -
        document.querySelectorAll('.qte-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const itemId = this.dataset.id;
                const itemDiv = this.closest('.panier-item');
                const qteSpan = itemDiv.querySelector('.item-qte');
                let currentQte = parseInt(qteSpan.textContent) || 1;

                if (this.classList.contains('qte-minus')) {
                    currentQte = Math.max(1, currentQte - 1);
                } else if (this.classList.contains('qte-plus')) {
                    currentQte++;
                }

                // Mettre à jour visuellement immédiatement
                qteSpan.textContent = currentQte;

                // Sauvegarder pour undo et mettre à jour en base
                self.saveStateForUndo().then(() => {
                    self.updateQuantity(itemId, currentQte);
                });
            });
        });

        // Handler pour les inputs (si utilisés)
        document.querySelectorAll('.panier-item .item-qte').forEach(input => {
            if (input.tagName === 'INPUT') {
                input.addEventListener('change', function() {
                    const itemDiv = this.closest('.panier-item');
                    const itemId = itemDiv.dataset.id;
                    const newQte = parseInt(this.value) || 1;

                    self.saveStateForUndo().then(() => {
                        self.updateQuantity(itemId, newQte);
                    });
                });
            }
        });
    },

    // ================================
    // ACTIONS CATALOGUE
    // ================================

    addItem: function(parentId, type) {
        // Utiliser le modal au lieu de prompt()
        openAddItemModal(parentId, type, null);
    },

    addItemToSection: function(etapeId, type) {
        // Utiliser le modal au lieu de prompt()
        openAddItemModal(null, type, etapeId);
    },

    editItemName: function(element, itemId) {
        const currentName = element.textContent.trim();
        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'inline-edit-input';
        input.value = currentName;

        element.innerHTML = '';
        element.appendChild(input);
        input.focus();
        input.select();

        const save = () => {
            const newName = input.value.trim();
            if (newName && newName !== currentName) {
                this.ajax('update_catalogue_item', {
                    id: itemId,
                    nom: newName
                }).then(response => {
                    element.textContent = response.success ? newName : currentName;
                });
            } else {
                element.textContent = currentName;
            }
        };

        input.addEventListener('blur', save);
        input.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                save();
            } else if (e.key === 'Escape') {
                element.textContent = currentName;
            }
        });
    },

    // Undo system
    lastDeletedItem: null,
    undoToast: null,

    showUndoToast: function(message) {
        if (!this.undoToast) {
            const toastEl = document.getElementById('undoToast');
            if (toastEl) {
                this.undoToast = new bootstrap.Toast(toastEl);
            }
        }
        if (this.undoToast) {
            document.getElementById('undoToastMessage').textContent = message;
            this.undoToast.show();
        }
    },

    deleteItem: function(itemId) {
        const self = this;
        const el = document.querySelector('.catalogue-item[data-id="' + itemId + '"]');

        this.animateRemove(el, () => {
            // Supprimer sans confirmation - on peut annuler avec undo
            self.ajax('delete_catalogue_item', { id: itemId })
                .then(response => {
                    if (response.success) {
                        self.lastDeletedItem = { id: itemId, type: 'catalogue' };
                        self.loadCatalogueByEtape();
                        self.showUndoToast('Élément supprimé');
                    } else {
                        alert('Erreur: ' + (response.message || 'Échec'));
                    }
                });
        });
    },

    restoreLastDeleted: function() {
        if (!this.lastDeletedItem) return;

        const self = this;
        this.ajax('restore_catalogue_item', { id: this.lastDeletedItem.id })
            .then(response => {
                if (response.success) {
                    self.lastDeletedItem = null;
                    self.loadCatalogueByEtape();
                    if (self.undoToast) self.undoToast.hide();
                } else {
                    alert('Erreur: ' + (response.message || 'Impossible de restaurer'));
                }
            });
    },

    // ================================
    // ACTIONS PANIER
    // ================================

    addToPanier: function(catalogueItemId) {
        if (!this.projetId) {
            alert('Sélectionnez d\'abord un projet');
            return;
        }

        const self = this;

        // D'abord vérifier si l'item existe déjà dans le panier
        this.ajax('check_panier_item', {
            projet_id: this.projetId,
            catalogue_item_id: catalogueItemId
        }).then(response => {
            if (response.success) {
                self.showAddQuantityModal(catalogueItemId, response);
            } else {
                alert('Erreur: ' + (response.message || 'Échec'));
            }
        });
    },

    showAddQuantityModal: function(catalogueItemId, itemInfo) {
        const self = this;
        console.log('itemInfo:', itemInfo); // DEBUG
        const existsText = itemInfo.exists
            ? `<div class="alert alert-info mb-3">
                 <i class="bi bi-info-circle me-2"></i>
                 Cet item est déjà dans le panier: <strong>${itemInfo.current_quantity}</strong> unité(s)
               </div>`
            : '';

        const modalHtml = `
            <div class="modal fade" id="addQuantityModal" tabindex="-1">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header bg-primary text-white">
                            <h5 class="modal-title"><i class="bi bi-cart-plus me-2"></i>Ajouter au panier</h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <h6 class="mb-3">${itemInfo.item_name}</h6>
                            <p class="text-muted mb-3">Prix: ${itemInfo.item_price.toFixed(2)} $</p>
                            ${existsText}
                            <div class="mb-3">
                                <label class="form-label">Quantité à ajouter:</label>
                                <input type="number" class="form-control" id="addQuantityInput" value="1" min="1" autofocus>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                            <button type="button" class="btn btn-primary" id="confirmAddBtn">
                                <i class="bi bi-cart-plus me-1"></i>Ajouter
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        `;

        // Supprimer l'ancien modal s'il existe
        const oldModal = document.getElementById('addQuantityModal');
        if (oldModal) oldModal.remove();

        // Ajouter le nouveau modal
        document.body.insertAdjacentHTML('beforeend', modalHtml);

        const modal = new bootstrap.Modal(document.getElementById('addQuantityModal'));
        const input = document.getElementById('addQuantityInput');
        const confirmBtn = document.getElementById('confirmAddBtn');

        confirmBtn.addEventListener('click', function() {
            const quantite = parseInt(input.value) || 1;
            modal.hide();

            // Sauvegarder l'état AVANT l'ajout
            self.saveStateForUndo().then(() => {
                self.ajax('add_to_panier', {
                    projet_id: self.projetId,
                    catalogue_item_id: catalogueItemId,
                    quantite: quantite
                }).then(response => {
                    if (response.success) {
                        self.refreshAfterBudgetChange();
                    } else {
                        alert('Erreur: ' + (response.message || 'Échec'));
                    }
                });
            });
        });

        // Permettre Enter pour confirmer
        input.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                confirmBtn.click();
            }
        });

        modal.show();

        // Focus sur l'input après ouverture du modal
        document.getElementById('addQuantityModal').addEventListener('shown.bs.modal', function() {
            input.select();
        });
    },

    removeFromPanier: function(panierItemId) {
        const self = this;
        const el = document.querySelector('.panier-item[data-id="' + panierItemId + '"]');

        this.animateRemove(el, () => {
            // Sauvegarder l'état AVANT la suppression
            self.saveStateForUndo().then(() => {
                self.ajax('remove_from_panier', { id: panierItemId })
                    .then(response => {
                        if (response.success) {
                            self.refreshAfterBudgetChange();
                        } else {
                            alert('Erreur: ' + (response.message || 'Échec'));
                        }
                    });
            });
        });
    },

    updateQuantity: function(panierItemId, quantite) {
        const self = this;
        this.ajax('update_panier_quantity', {
            id: panierItemId,
            quantite: quantite
        }).then(response => {
            if (response.success) {
                self.updateTotals();
                // Rafraîchir les indicateurs après changement de quantité
                self.refreshIndicateurs();
            }
        });
    },

    /**
     * ❌ DÉSACTIVÉ
     * Les totaux panier sont calculés uniquement côté serveur.
     */
    updateTotals: function() {
        // no-op
    },

    formatMoney: function(amount) {
        return new Intl.NumberFormat('fr-CA', {
            style: 'currency',
            currency: 'CAD',
            minimumFractionDigits: 0,
            maximumFractionDigits: 0
        }).format(amount).replace('CA', '').trim();
    },

    // ================================
    // VUE CATALOGUE PAR ÉTAPE
    // ================================

    initCatalogueViewToggle: function() {
        const self = this;
        document.querySelectorAll('input[name="catalogueView"]').forEach(radio => {
            radio.addEventListener('change', function() {
                if (this.value === 'etape') {
                    self.loadCatalogueByEtape();
                } else {
                    self.restoreNormalCatalogue();
                }
            });
        });
    },

    loadCatalogueByEtape: function() {
        const self = this;
        const container = document.getElementById('catalogue-tree');

        // Afficher un loader
        container.innerHTML = '<div class="text-center py-4"><div class="spinner-border text-primary" role="status"></div></div>';

        this.ajax('get_catalogue_by_etape', {}).then(response => {
            if (response.success && response.grouped) {
                self.renderCatalogueByEtape(response.grouped);
                self.currentCatalogueView = 'etape';
            }
        });
    },

    renderCatalogueByEtape: function(grouped) {
        const container = document.getElementById('catalogue-tree');
        let html = '';

        grouped.forEach((group) => {
            // Compter tous les éléments (dossiers + items)
            const itemCount = group.items.length;
            let etapeLabel;
            const etapeId = group.etape_id || 'null';

            // Utiliser le numéro d'étape réel retourné par le serveur
            if (group.etape_id && group.etape_num) {
                etapeLabel = `N.${group.etape_num} ${this.escapeHtml(group.etape_nom)}`;
            } else {
                etapeLabel = this.escapeHtml(group.etape_nom);
            }

            html += `
                <div class="etape-section mb-3" data-etape-id="${etapeId}">
                    <div class="catalogue-item is-section-header" style="background: rgba(13, 110, 253, 0.1); border-left: 3px solid var(--bs-primary, #0d6efd);">
                        <span class="folder-toggle" onclick="toggleSection(this)">
                            <i class="bi bi-caret-down-fill"></i>
                        </span>
                        <i class="bi bi-list-ol text-primary me-1"></i>
                        <span class="item-nom fw-bold">${etapeLabel}</span>
                        <span class="badge bg-primary ms-2">${itemCount}</span>
                        <div class="btn-group btn-group-sm ms-auto" style="gap: 10px;">
                            <button type="button" class="btn btn-link p-0 text-success" onclick="addItemToSection(${etapeId}, 'folder')" title="Ajouter dossier">
                                <i class="bi bi-folder-plus" style="font-size: 1.25rem;"></i>
                            </button>
                            <button type="button" class="btn btn-link p-0 text-primary" onclick="addItemToSection(${etapeId}, 'item')" title="Ajouter item">
                                <i class="bi bi-plus-circle" style="font-size: 1.25rem;"></i>
                            </button>
                        </div>
                    </div>
                    <div class="section-children folder-children" data-etape="${etapeId}">
                        ${this.renderEtapeItems(group.items)}
                    </div>
                </div>
            `;
        });

        if (grouped.length === 0) {
            html = '<div class="text-center text-muted py-4"><i class="bi bi-inbox" style="font-size: 2rem;"></i><p class="mt-2">Aucun item avec étape définie</p></div>';
        }

        container.innerHTML = html;
        this.reinitDragDropForItems();
    },

    renderEtapeItems: function(items) {
        let html = '';

        items.forEach(item => {
            const isFolder = item.type === 'folder';
            const hasChildren = item.children && item.children.length > 0;

            html += `
                <div class="catalogue-item ${isFolder ? 'is-folder' : 'is-item'}"
                     data-id="${item.id}"
                     data-type="${item.type}"
                     data-prix="${item.prix || 0}">
            `;

            if (isFolder) {
                html += `
                    <span class="folder-toggle ${hasChildren ? '' : 'invisible'}" onclick="toggleFolder(this)">
                        <i class="bi bi-caret-down-fill"></i>
                    </span>
                    <i class="bi bi-folder-fill text-warning me-1 icon-add-to-cart"
                       ondblclick="addFolderToPanier(${item.id})"
                       title="Double-clic pour ajouter au panier"
                       style="cursor: pointer;"></i>
                `;
            } else {
                html += `
                    <span class="folder-toggle invisible"></span>
                    <i class="bi bi-box-seam text-primary me-1 icon-add-to-cart"
                       ondblclick="addToPanier(${item.id})"
                       title="Double-clic pour ajouter au panier"
                       style="cursor: pointer;"></i>
                `;
            }

            html += `
                <span class="item-nom" ondblclick="editItemName(this, ${item.id})">
                    ${this.escapeHtml(item.nom)}
                </span>
                <span class="item-actions">
            `;

            if (!isFolder) {
                // Actions pour les items
                html += `
                    <span class="badge bg-secondary item-prix-badge">${this.formatMoney(item.prix || 0)}</span>
                    <button type="button" class="btn btn-sm btn-link p-0 text-info"
                            onclick="openItemModal(${item.id})" title="Modifier">
                        <i class="bi bi-pencil"></i>
                    </button>
                    <button type="button" class="btn btn-sm btn-link p-0 text-warning"
                            onclick="duplicateItem(${item.id})" title="Dupliquer">
                        <i class="bi bi-copy"></i>
                    </button>
                    <button type="button" class="btn btn-sm btn-link p-0 text-primary add-to-panier"
                            onclick="addToPanier(${item.id})" title="Ajouter au panier">
                        <i class="bi bi-plus-circle"></i>
                    </button>
                `;
            } else {
                // Actions pour les dossiers
                html += `
                    <span class="item-prix-badge"></span>
                    <button type="button" class="btn btn-sm btn-link p-0 text-info" onclick="openFolderModal(${item.id})" title="Modifier">
                        <i class="bi bi-pencil"></i>
                    </button>
                    <button type="button" class="btn btn-sm btn-link p-0 text-success" onclick="addItem(${item.id}, 'folder')" title="Sous-dossier">
                        <i class="bi bi-folder-plus"></i>
                    </button>
                    <button type="button" class="btn btn-sm btn-link p-0 text-warning" onclick="duplicateItem(${item.id})" title="Dupliquer">
                        <i class="bi bi-copy"></i>
                    </button>
                    <button type="button" class="btn btn-sm btn-link p-0 text-primary" onclick="addItem(${item.id}, 'item')" title="Item">
                        <i class="bi bi-plus-circle"></i>
                    </button>
                `;
            }

            html += `
                    <button type="button" class="btn btn-sm btn-link p-0 text-danger"
                            onclick="deleteItem(${item.id})" title="Supprimer">
                        <i class="bi bi-trash"></i>
                    </button>
                </span>
            </div>
            `;

            if (isFolder && hasChildren) {
                html += `<div class="folder-children" data-parent="${item.id}">${this.renderEtapeItems(item.children)}</div>`;
            }
        });

        if (html === '') {
            html = '<div class="text-muted py-2 ps-3"><em>Aucun élément</em></div>';
        }

        return html;
    },

    restoreNormalCatalogue: function() {
        const container = document.getElementById('catalogue-tree');
        container.innerHTML = this.originalCatalogueHtml;
        this.currentCatalogueView = 'normal';
        this.reinitDragDropForItems();
    },

    reinitDragDropForItems: function() {
        // Réinitialiser le drag & drop sur les éléments du catalogue (pas le panier)
        this.initCatalogueDrag();
    },

    escapeHtml: function(text) {
        const div = document.createElement('div');
        div.textContent = text || '';
        return div.innerHTML;
    },

    // ================================
    // ANIMATIONS UI (ajout / suppression)
    // ================================

    injectAnimationStyles: function() {
        if (document.getElementById('bb-animations')) return;

        const style = document.createElement('style');
        style.id = 'bb-animations';
        style.textContent = `
            .bb-animate-add {
                animation: bbFadeIn 250ms ease-out;
            }
            .bb-animate-remove {
                animation: bbFadeOut 200ms ease-in forwards;
            }
            @keyframes bbFadeIn {
                from { opacity: 0; transform: translateY(-6px); }
                to { opacity: 1; transform: translateY(0); }
            }
            @keyframes bbFadeOut {
                from { opacity: 1; transform: translateY(0); }
                to { opacity: 0; transform: translateY(-6px); }
            }
        `;
        document.head.appendChild(style);
    },

    animateAdd: function(el) {
        if (!el) return;
        this.injectAnimationStyles();
        el.classList.add('bb-animate-add');
        setTimeout(() => el.classList.remove('bb-animate-add'), 300);
    },

    animateRemove: function(el, callback) {
        if (!el) return;
        this.injectAnimationStyles();
        el.classList.add('bb-animate-remove');
        setTimeout(() => {
            if (typeof callback === 'function') callback();
        }, 220);
    },

    // ================================
    // REFRESH SANS RELOAD
    // ================================

    // Point central après toute modification du budget
    refreshAfterBudgetChange: function() {
        const self = this;

        // ✅ Recharger le panier et FORCER le repaint immédiat des totaux d’étape
        this.loadPanier().then(() => {
            requestAnimationFrame(() => {
                self.loadPanier().then(() => {
                    self.refreshIndicateurs();
                });
            });
        });
    },

    refreshAll: function() {
        this.loadCatalogueByEtape();
        this.loadPanier();
    },

    loadPanier: function() {
        const self = this;
        const container = document.getElementById('panier-items');
        if (!container || !this.projetId) return Promise.resolve();

        // ✅ retourner la Promise pour chaînage
        return this.ajax('get_panier', { projet_id: this.projetId }).then(response => {
            if (response.success) {
                self.renderPanier(response.sections, response.total);

                // ✅ IMPORTANT : relancer les calculs rénovation / indicateurs
                // (sinon la rénovation ne s’auto‑update plus)
                self.refreshIndicateurs();
            }
        });
    },

    // Rafraîchir les indicateurs du projet (Base tab) après changement du budget
    refreshIndicateurs: function(retryCount = 0) {
        const self = this;
        
        // Token pas encore prêt → retry automatique ou aller le chercher
        if (!window.baseFormCsrfToken) {
            const tokenInput = document.querySelector('input[name="csrf_token"]');
            if (tokenInput) {
                window.baseFormCsrfToken = tokenInput.value;
            } else if (retryCount < 10) {
                setTimeout(() => {
                    self.refreshIndicateurs(retryCount + 1);
                }, 150);
                return;
            } else {
                console.error("CSRF Token manquant pour refreshIndicateurs");
                return;
            }
        }

        const formData = new FormData();
        formData.set('ajax_action', 'get_project_totals');
        formData.set('csrf_token', window.baseFormCsrfToken);

        // Utiliser l'URL actuelle ou celle du détail de projet si on est dans le module standalone
        let fetchUrl = window.location.href;
        if (window.location.pathname.includes('/modules/budget-builder/')) {
             fetchUrl = '/flip/admin/projets/detail.php?id=' + this.projetId;
        }

        fetch(fetchUrl, {
            method: 'POST',
            body: formData
        })
        .then(r => r.json())
        .then(res => {
            if (!res || !res.success) return;

            // ✅ Mise à jour indicateurs simples
            if (res.indicateurs && typeof window.updateIndicateurs === 'function') {
                window.updateIndicateurs(res.indicateurs);
            }

            // ✅ RÉNOVATION – rendu JSON centralisé
            if (res.renovation) {
                if (typeof window.renderRenovationFromJson === 'function') {
                    window.renderRenovationFromJson(
                        res.renovation,
                        res.budget_par_etape || {},
                        res.depenses_par_etape || {}
                    );
                } else if (typeof window.updateRenovation === 'function') {
                    window.updateRenovation(
                        res.renovation,
                        res.budget_par_etape,
                        res.depenses_par_etape
                    );
                }
            }
            
            // ✅ FORCER LA MISE À JOUR DU DOM (au cas où)
            const elRenoTotal = document.getElementById('detailRenoTotal');
            if (elRenoTotal && res.renovation) {
                 // Utiliser reel_ttc_avec_mo si c'est ce qu'on veut afficher, ou total_ttc budget
                 // Dans calculs.php on utilise budget_ttc_avec_mo pour le coût total projet
                 // Mais dans tab-base.php on affiche renoBudgetTTC (qui est total_ttc + mo_extrapole)
                 // Or res.renovation.budget_ttc_avec_mo EST total_ttc + mo_extrapole
                 const montantAffiche = res.renovation.budget_ttc_avec_mo;
                 elRenoTotal.textContent = new Intl.NumberFormat('fr-CA', { style: 'currency', currency: 'CAD' }).format(montantAffiche);
            }

            // ✅ Rebind des graphiques sans détruire le DOM
            if (typeof window.initDetailCharts === 'function') {
                window.initDetailCharts();
            }
        })
        .catch(err => console.error("Erreur refreshIndicateurs", err));
    },

    renderPanier: function(sections, total) {
        const container = document.getElementById('panier-items');

        if (!sections || sections.length === 0) {
            container.innerHTML = `
                <div class="text-center text-muted py-4" id="panier-empty">
                    <i class="bi bi-cart" style="font-size: 2rem;"></i>
                    <p class="mt-2 mb-0">Panier vide</p>
                    <small>Glissez des items depuis le Magasin</small>
                </div>
            `;
        } else {
            let html = '';
            sections.forEach(section => {
                const etapeLabel = section.etape_num
                    ? `N.${section.etape_num} ${this.escapeHtml(section.etape_nom)}`
                    : this.escapeHtml(section.etape_nom);
                // ✅ total section fourni par le serveur
                const sectionTotal = section.total || 0;

                html += `
                    <div class="panier-section" data-etape-id="${section.etape_id || 'null'}">
                        <div class="panier-section-header d-flex align-items-center justify-content-between"
                             style="background: rgba(13, 110, 253, 0.1); border-left: 3px solid var(--bs-primary, #0d6efd); cursor: pointer; padding: 2px 8px;"
                             onclick="togglePanierSection(this)">
                            <span>
                                <i class="bi bi-caret-down-fill section-toggle me-1"></i>
                                <i class="bi bi-list-ol text-primary me-1"></i>
                                <strong>${etapeLabel}</strong>
                                <span class="badge bg-secondary ms-1">${section.items.length}</span>
                            </span>
                            <span class="badge bg-success">${this.formatMoney(sectionTotal)}</span>
                        </div>
                        <div class="panier-section-content">
                            ${this.renderPanierItems(section.items)}
                        </div>
                    </div>
                `;
            });
            container.innerHTML = html;
        }

        // Mettre à jour les totaux
        const totalEl = document.getElementById('panier-total');
        const totalFooter = document.getElementById('panier-total-footer');
        const formatted = this.formatMoney(total);
        if (totalEl) totalEl.textContent = formatted;
        if (totalFooter) totalFooter.textContent = formatted;

        // Réinitialiser les événements
        this.initQuantityChange();
    },

    renderPanierItems: function(items) {
        let html = '';
        items.forEach(item => {
            const isFolder = item.type === 'folder';
            const hasChildren = item.children && item.children.length > 0;
            const itemTotal = (item.prix || 0) * (item.quantite || 1);

            if (isFolder) {
                html += `
                    <div class="panier-item is-folder" data-id="${item.id}" data-type="folder">
                        <span class="folder-toggle ${hasChildren ? '' : 'invisible'}" onclick="togglePanierFolder(this)">
                            <i class="bi bi-caret-down-fill"></i>
                        </span>
                        <i class="bi bi-folder-fill text-warning me-1"></i>
                        <span class="item-nom fw-bold">${this.escapeHtml(item.nom)}</span>
                        <span class="ms-auto">
                            <button type="button" class="btn btn-sm btn-link p-0 text-info me-1" onclick="editPanierFolderName(${item.id})" title="Renommer">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <button type="button" class="btn btn-sm btn-link p-0 text-danger" onclick="removeFromPanier(${item.id})" title="Supprimer">
                                <i class="bi bi-trash"></i>
                            </button>
                        </span>
                    </div>
                `;
                if (hasChildren) {
                    html += `<div class="panier-folder-children" data-parent="${item.id}">${this.renderPanierItems(item.children)}</div>`;
                }
            } else {
                html += `
                    <div class="panier-item is-item" data-id="${item.id}" data-type="item">
                        <span class="folder-toggle invisible"></span>
                        <i class="bi bi-box-seam text-primary me-1"></i>
                        <span class="item-nom">${this.escapeHtml(item.nom)}</span>
                        <span class="item-qte-controls d-flex align-items-center">
                            <button type="button" class="qte-btn qte-minus" data-id="${item.id}">−</button>
                            <span class="item-qte" data-id="${item.id}">${item.quantite || 1}</span>
                            <button type="button" class="qte-btn qte-plus" data-id="${item.id}">+</button>
                        </span>
                        <span class="badge bg-secondary item-prix"
                              data-id="${item.id}"
                              data-prix="${item.prix || 0}"
                              ondblclick="editPanierPrice(this)"
                              style="cursor: pointer;"
                              title="Double-clic pour modifier">${this.formatMoney(item.prix || 0)}</span>
                        <span class="badge bg-success item-total">${this.formatMoney(itemTotal)}</span>
                        <button type="button" class="btn btn-sm btn-link p-0 text-danger ms-1" onclick="removeFromPanier(${item.id})" title="Supprimer">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                `;
            }
        });
        return html;
    },

    /**
     * ❌ DÉSACTIVÉ
     * Les totaux par section sont fournis par le serveur.
     */
    calculateSectionTotal: function(items) {
        return 0;
    },

    formatMoney: function(amount) {
        return new Intl.NumberFormat('fr-CA', { style: 'currency', currency: 'CAD' }).format(amount || 0);
    },

    // ================================
    // UNDO / REDO
    // ================================

    initUndoRedoKeyboard: function() {
        const self = this;
        document.addEventListener('keydown', function(e) {
            // Ctrl+Z = Undo
            if (e.ctrlKey && e.key === 'z' && !e.shiftKey) {
                e.preventDefault();
                self.undo();
            }
            // Ctrl+Y ou Ctrl+Shift+Z = Redo
            if ((e.ctrlKey && e.key === 'y') || (e.ctrlKey && e.shiftKey && e.key === 'z')) {
                e.preventDefault();
                self.redo();
            }
        });
    },

    saveStateForUndo: function() {
        if (this.isRestoring || !this.projetId) return Promise.resolve();

        const self = this;
        return this.ajax('get_panier', { projet_id: this.projetId }).then(response => {
            if (response.success) {
                // Sauvegarder l'état actuel
                self.undoStack.push({
                    sections: response.sections || [],
                    total: response.total || 0
                });

                // Limiter la taille de l'historique
                if (self.undoStack.length > self.maxHistorySize) {
                    self.undoStack.shift();
                }

                // Vider le redo stack quand on fait une nouvelle action
                self.redoStack = [];

                self.updateUndoRedoButtons();
            }
        });
    },

    undo: function() {
        if (this.undoStack.length === 0 || !this.projetId) return;

        const self = this;
        this.isRestoring = true;

        // Sauvegarder l'état actuel dans redoStack avant de restaurer
        this.ajax('get_panier', { projet_id: this.projetId }).then(response => {
            if (response.success) {
                self.redoStack.push({
                    sections: response.sections || [],
                    total: response.total || 0
                });
            }

            // Restaurer l'état précédent
            const previousState = self.undoStack.pop();
            self.restorePanierState(previousState).then(() => {
                self.isRestoring = false;
                self.updateUndoRedoButtons();
            });
        });
    },

    redo: function() {
        if (this.redoStack.length === 0 || !this.projetId) return;

        const self = this;
        this.isRestoring = true;

        // Sauvegarder l'état actuel dans undoStack avant de restaurer
        this.ajax('get_panier', { projet_id: this.projetId }).then(response => {
            if (response.success) {
                self.undoStack.push({
                    sections: response.sections || [],
                    total: response.total || 0
                });
            }

            // Restaurer l'état suivant
            const nextState = self.redoStack.pop();
            self.restorePanierState(nextState).then(() => {
                self.isRestoring = false;
                self.updateUndoRedoButtons();
            });
        });
    },

    restorePanierState: function(state) {
        const self = this;

        // Extraire tous les items de toutes les sections
        const items = [];
        if (state.sections) {
            state.sections.forEach(section => {
                self.extractItemsFromSection(section.items, items);
            });
        }

        return this.ajax('restore_panier', {
            projet_id: this.projetId,
            items: items
        }).then(response => {
            if (response.success) {
                self.loadPanier();
            }
        });
    },

    extractItemsFromSection: function(sectionItems, result) {
        const self = this;
        if (!sectionItems) return;

        sectionItems.forEach(item => {
            result.push({
                id: item.id,
                catalogue_item_id: item.catalogue_item_id,
                parent_budget_id: item.parent_budget_id,
                type: item.type,
                nom: item.nom,
                prix: item.prix,
                quantite: item.quantite,
                ordre: item.ordre
            });

            if (item.children) {
                self.extractItemsFromSection(item.children, result);
            }
        });
    },

    updateUndoRedoButtons: function() {
        const undoBtn = document.getElementById('undoBtn');
        const redoBtn = document.getElementById('redoBtn');

        if (undoBtn) {
            undoBtn.disabled = this.undoStack.length === 0;
        }
        if (redoBtn) {
            redoBtn.disabled = this.redoStack.length === 0;
        }
    },

    // ================================
    // AJAX
    // ================================

    ajax: function(action, data = {}) {
        data.action = action;

        return fetch(this.ajaxUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(data)
        })
        .then(response => response.json())
        .catch(error => {
            console.error('Ajax error:', error);
            return { success: false, message: error.message };
        });
    }
};

/**
 * ================================
 * RÉNOVATION – RENDU CENTRALISÉ JSON
 * ================================
 */
window.renderRenovationFromJson = function (reno, budgetParEtape, depensesParEtape) {
    const tableBody = document.querySelector('.cost-table tbody');
    if (!tableBody) return;

    const marker = tableBody.querySelector('tr.section-header[data-section="renovation"]');
    if (!marker) return;

    // Supprimer uniquement les lignes dynamiques de rénovation (JS)
    tableBody.querySelectorAll('.detail-etape-row').forEach(row => row.remove());

    let totalBudget = 0;
    let totalReel = 0;

    Object.entries(budgetParEtape).forEach(([etapeId, etape]) => {
        const budget = etape.total || 0;
        const dep = depensesParEtape[etapeId]?.total || 0;
        if (budget === 0 && dep === 0) return;

        const diff = budget - dep;
        totalBudget += budget;
        totalReel += dep;

        marker.insertAdjacentHTML('afterend', `
            <tr class="sub-item detail-etape-row" data-etape-id="${etapeId}">
                <td><i class="bi bi-bookmark-fill me-1 text-muted"></i>${etape.nom}</td>
                <td class="text-end">${formatMoneyBase(budget)}</td>
                <td class="text-end ${diff >= 0 ? 'positive' : 'negative'}">${diff !== 0 ? formatMoneyBase(diff) : '-'}</td>
                <td class="text-end">${formatMoneyBase(dep)}</td>
            </tr>
        `);
    });

    // Étapes avec dépenses sans budget
    Object.entries(depensesParEtape).forEach(([etapeId, dep]) => {
        if (budgetParEtape[etapeId]) return;
        if (!dep.total) return;

        totalReel += dep.total;

        marker.insertAdjacentHTML('afterend', `
            <tr class="sub-item detail-etape-row" data-etape-id="${etapeId}">
                <td><i class="bi bi-bookmark-fill me-1 text-muted"></i>${dep.nom}</td>
                <td class="text-end">-</td>
                <td class="text-end negative">${formatMoneyBase(-dep.total)}</td>
                <td class="text-end">${formatMoneyBase(dep.total)}</td>
            </tr>
        `);
    });

    // Totaux / taxes
    const elCont = document.getElementById('detailContingence');
    const elTPS = document.getElementById('detailTPS');
    const elTVQ = document.getElementById('detailTVQ');
    const elTotal = document.getElementById('detailRenoTotal');

    if (elCont) elCont.textContent = formatMoneyBase(reno.contingence);
    if (elTPS) elTPS.textContent = formatMoneyBase(reno.tps);
    if (elTVQ) elTVQ.textContent = formatMoneyBase(reno.tvq);

    /**
     * ✅ CORRECTION DÉFINITIVE (SCÉNARIO A)
     * Le live JS ne doit JAMAIS recalculer le sous‑total rénovation.
     * Il doit AFFICHER EXACTEMENT la valeur serveur.
     */

    // ✅ Valeur serveur déjà TTC + MO
    const totalBudgetReno = reno.total_ttc_avec_mo ?? reno.total_ttc ?? 0;

    // ✅ Valeur réelle serveur déjà TTC + MO
    const totalReelReno = reno.reel_ttc_avec_mo ?? reno.reel_ttc ?? 0;

    if (elTotal) {
        elTotal.textContent = formatMoneyBase(totalBudgetReno);
    }
};

// Fonctions globales pour les onclick
function toggleFolder(el) {
    el.classList.toggle('collapsed');
    const parent = el.closest('.catalogue-item');
    const children = parent.nextElementSibling;
    if (children && children.classList.contains('folder-children')) {
        children.classList.toggle('collapsed');
    }
}

function addItem(parentId, type) {
    BudgetBuilder.addItem(parentId, type);
}

function editItemName(el, id) {
    BudgetBuilder.editItemName(el, id);
}

function deleteItem(id) {
    BudgetBuilder.deleteItem(id);
}

function addToPanier(id) {
    BudgetBuilder.addToPanier(id);
}

function addFolderToPanier(id) {
    BudgetBuilder.addFolderToPanier(id);
}

function removeFromPanier(id) {
    BudgetBuilder.removeFromPanier(id);
}

function togglePanierFolder(el) {
    el.classList.toggle('collapsed');
    const parent = el.closest('.panier-item');
    const children = parent.nextElementSibling;
    if (children && children.classList.contains('panier-folder-children')) {
        children.classList.toggle('collapsed');
    }
}

function toggleEtapeGroup(el) {
    el.classList.toggle('collapsed');
    const parent = el.closest('.catalogue-item');
    const children = parent.nextElementSibling;
    if (children && children.classList.contains('etape-group-children')) {
        children.classList.toggle('collapsed');
    }
}

function toggleSection(el) {
    el.classList.toggle('collapsed');
    const parent = el.closest('.catalogue-item');
    const children = parent.nextElementSibling;
    if (children && children.classList.contains('section-children')) {
        children.classList.toggle('collapsed');
    }
}

function addItemToSection(etapeId, type) {
    BudgetBuilder.addItemToSection(etapeId, type);
}
