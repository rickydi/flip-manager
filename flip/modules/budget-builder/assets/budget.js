/**
 * Budget Builder - JavaScript
 */

const BudgetBuilder = {
    projetId: null,
    ajaxUrl: null,

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

        this.initDragDrop();
        this.initQuantityChange();

        console.log('Budget Builder initialized', { projetId: this.projetId });
    },

    // ================================
    // DRAG & DROP
    // ================================

    initDragDrop: function() {
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

        // Zone de drop (toute la carte panier) - accepte items ET dossiers
        const panierCard = document.getElementById('panier-card');
        if (panierCard) {
            panierCard.addEventListener('dragover', function(e) {
                e.preventDefault();
                e.dataTransfer.dropEffect = 'copy';
                this.classList.add('drag-over');
            });

            panierCard.addEventListener('dragleave', function(e) {
                // Vérifier qu'on quitte vraiment la zone
                if (!this.contains(e.relatedTarget)) {
                    this.classList.remove('drag-over');
                }
            });

            panierCard.addEventListener('drop', function(e) {
                e.preventDefault();
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
        }

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
                self.refreshAll();
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
                self.refreshAll();
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

        this.ajax('add_folder_to_panier', {
            projet_id: this.projetId,
            folder_id: folderId
        }).then(response => {
            console.log('add_folder_to_panier response:', response);
            if (response.success) {
                self.loadPanier();
            } else {
                alert('Erreur: ' + (response.message || 'Échec'));
            }
        });
    },

    // ================================
    // QUANTITE
    // ================================

    initQuantityChange: function() {
        const self = this;

        document.querySelectorAll('.panier-item .item-qte').forEach(input => {
            input.addEventListener('change', function() {
                const itemDiv = this.closest('.panier-item');
                const itemId = itemDiv.dataset.id;
                const newQte = parseInt(this.value) || 1;

                self.updateQuantity(itemId, newQte);
            });
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

    deleteItem: function(itemId) {
        if (!confirm('Supprimer cet élément et tout son contenu?')) return;

        const self = this;
        this.ajax('delete_catalogue_item', { id: itemId })
            .then(response => {
                if (response.success) {
                    self.loadCatalogueByEtape();
                } else {
                    alert('Erreur: ' + (response.message || 'Échec'));
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

            self.ajax('add_to_panier', {
                projet_id: self.projetId,
                catalogue_item_id: catalogueItemId,
                quantite: quantite
            }).then(response => {
                if (response.success) {
                    self.loadPanier();
                } else {
                    alert('Erreur: ' + (response.message || 'Échec'));
                }
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
        this.ajax('remove_from_panier', { id: panierItemId })
            .then(response => {
                if (response.success) {
                    self.loadPanier();
                } else {
                    alert('Erreur: ' + (response.message || 'Échec'));
                }
            });
    },

    updateQuantity: function(panierItemId, quantite) {
        this.ajax('update_panier_quantity', {
            id: panierItemId,
            quantite: quantite
        }).then(response => {
            if (response.success) {
                this.updateTotals();
            }
        });
    },

    updateTotals: function() {
        const self = this;
        let grandTotal = 0;

        // Mettre à jour chaque section
        document.querySelectorAll('.panier-section').forEach(section => {
            let sectionTotal = 0;

            // Calculer le total de cette section
            section.querySelectorAll('.panier-item.is-item').forEach(item => {
                const prixEl = item.querySelector('.item-prix');
                const qteEl = item.querySelector('.item-qte');
                const prix = parseFloat(prixEl?.dataset?.prix) || 0;
                const qte = parseInt(qteEl?.textContent || qteEl?.value) || 1;
                const itemTotal = prix * qte;
                sectionTotal += itemTotal;

                const totalEl = item.querySelector('.item-total');
                if (totalEl) {
                    totalEl.textContent = self.formatMoney(itemTotal);
                }
            });

            // Mettre à jour le badge de la section
            const sectionBadge = section.querySelector('.panier-section-header .badge.bg-success');
            if (sectionBadge) {
                sectionBadge.textContent = self.formatMoney(sectionTotal);
            }

            grandTotal += sectionTotal;
        });

        // Si pas de sections (ancien format), calculer directement
        if (grandTotal === 0) {
            document.querySelectorAll('.panier-item.is-item').forEach(item => {
                const prixEl = item.querySelector('.item-prix');
                const qteEl = item.querySelector('.item-qte');
                const prix = parseFloat(prixEl?.dataset?.prix) || 0;
                const qte = parseInt(qteEl?.textContent || qteEl?.value) || 1;
                const itemTotal = prix * qte;
                grandTotal += itemTotal;

                const totalEl = item.querySelector('.item-total');
                if (totalEl) {
                    totalEl.textContent = self.formatMoney(itemTotal);
                }
            });
        }

        // Mettre à jour le total général
        document.querySelectorAll('#panier-total, #panier-total-footer').forEach(el => {
            el.textContent = self.formatMoney(grandTotal);
        });
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

            // Utiliser le numéro d'étape réel retourné par le serveur
            if (group.etape_id && group.etape_num) {
                etapeLabel = `N.${group.etape_num} ${this.escapeHtml(group.etape_nom)}`;
            } else {
                etapeLabel = this.escapeHtml(group.etape_nom);
            }

            html += `
                <div class="etape-group mb-3">
                    <div class="catalogue-item is-etape-header" style="background: rgba(13, 110, 253, 0.1); border-left: 3px solid var(--primary-color);">
                        <span class="folder-toggle" onclick="toggleEtapeGroup(this)">
                            <i class="bi bi-caret-down-fill"></i>
                        </span>
                        <i class="bi bi-list-ol text-primary me-1"></i>
                        <span class="item-nom fw-bold">${etapeLabel}</span>
                        <span class="badge bg-primary ms-auto">${itemCount} item(s)</span>
                    </div>
                    <div class="etape-group-children folder-children" style="margin-left: 16px;">
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

            if (isFolder) {
                // Rendre un dossier avec ses enfants
                html += `
                    <div class="catalogue-item is-folder"
                         data-id="${item.id}"
                         data-type="folder">
                        <span class="folder-toggle ${hasChildren ? '' : 'invisible'}" onclick="toggleFolder(this)">
                            <i class="bi bi-caret-down-fill"></i>
                        </span>
                        <i class="bi bi-folder-fill text-warning me-1"></i>
                        <span class="item-nom" ondblclick="editItemName(this, ${item.id})">${this.escapeHtml(item.nom)}</span>
                        <div class="btn-group btn-group-sm">
                            <button type="button" class="btn btn-link p-0 text-info" onclick="openFolderModal(${item.id})" title="Modifier étape">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <button type="button" class="btn btn-link p-0 text-success" onclick="addItem(${item.id}, 'folder')" title="Sous-dossier">
                                <i class="bi bi-folder-plus"></i>
                            </button>
                            <button type="button" class="btn btn-link p-0 text-primary" onclick="addItem(${item.id}, 'item')" title="Item">
                                <i class="bi bi-plus-circle"></i>
                            </button>
                        </div>
                        <button type="button" class="btn btn-sm btn-link p-0 text-danger ms-1" onclick="deleteItem(${item.id})" title="Supprimer">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                `;

                if (hasChildren) {
                    html += `<div class="folder-children" data-parent="${item.id}">${this.renderEtapeItems(item.children)}</div>`;
                }
            } else {
                // Rendre un item
                const folderPath = item.folder_path ? `<span class="text-muted small me-1"><i class="bi bi-folder text-warning"></i> ${this.escapeHtml(item.folder_path)} /</span>` : '';

                html += `
                    <div class="catalogue-item is-item"
                         data-id="${item.id}"
                         data-type="item"
                         data-prix="${item.prix || 0}">
                        <span class="folder-toggle invisible"></span>
                        <i class="bi bi-box-seam text-primary me-1"></i>
                        ${folderPath}
                        <span class="item-nom" ondblclick="editItemName(this, ${item.id})">${this.escapeHtml(item.nom)}</span>
                        <span class="badge bg-secondary me-1">${this.formatMoney(item.prix || 0)}</span>
                        <button type="button" class="btn btn-sm btn-link p-0 text-info me-1"
                                onclick="openItemModal(${item.id})" title="Modifier">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <button type="button" class="btn btn-sm btn-link p-0 text-success add-to-panier"
                                onclick="addToPanier(${item.id})" title="Ajouter au panier">
                            <i class="bi bi-plus-circle-fill"></i>
                        </button>
                        <button type="button" class="btn btn-sm btn-link p-0 text-danger ms-1" onclick="deleteItem(${item.id})" title="Supprimer">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                `;
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
        // Réinitialiser le drag & drop sur les nouveaux éléments
        this.initDragDrop();
    },

    escapeHtml: function(text) {
        const div = document.createElement('div');
        div.textContent = text || '';
        return div.innerHTML;
    },

    // ================================
    // REFRESH SANS RELOAD
    // ================================

    refreshAll: function() {
        this.loadCatalogueByEtape();
        this.loadPanier();
    },

    loadPanier: function() {
        const self = this;
        const container = document.getElementById('panier-items');
        if (!container || !this.projetId) return;

        this.ajax('get_panier', { projet_id: this.projetId }).then(response => {
            if (response.success) {
                self.renderPanier(response.sections, response.total);
            }
        });
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
                const sectionTotal = this.calculateSectionTotal(section.items);

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
                    <div class="panier-item is-folder" data-id="${item.id}">
                        <span class="folder-toggle ${hasChildren ? '' : 'invisible'}" onclick="togglePanierFolder(this)">
                            <i class="bi bi-caret-down-fill"></i>
                        </span>
                        <i class="bi bi-folder-fill text-warning me-1"></i>
                        <span class="item-nom">${this.escapeHtml(item.nom)}</span>
                        <button type="button" class="btn btn-sm btn-link p-0 text-danger ms-auto" onclick="removeFromPanier(${item.id})" title="Supprimer">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                `;
                if (hasChildren) {
                    html += `<div class="panier-folder-children" data-parent="${item.id}">${this.renderPanierItems(item.children)}</div>`;
                }
            } else {
                html += `
                    <div class="panier-item" data-id="${item.id}" data-prix="${item.prix || 0}">
                        <span class="folder-toggle invisible"></span>
                        <i class="bi bi-box-seam text-primary me-1"></i>
                        <span class="item-nom">${this.escapeHtml(item.nom)}</span>
                        <div class="item-qte-controls">
                            <button type="button" class="qte-btn qte-minus" data-id="${item.id}">−</button>
                            <span class="item-qte">${item.quantite || 1}</span>
                            <button type="button" class="qte-btn qte-plus" data-id="${item.id}">+</button>
                        </div>
                        <span class="item-prix badge bg-secondary">${this.formatMoney(item.prix || 0)}</span>
                        <span class="item-total badge bg-info">${this.formatMoney(itemTotal)}</span>
                        <button type="button" class="btn btn-sm btn-link p-0 text-danger ms-1" onclick="removeFromPanier(${item.id})" title="Supprimer">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                `;
            }
        });
        return html;
    },

    calculateSectionTotal: function(items) {
        let total = 0;
        items.forEach(item => {
            if (item.type !== 'folder') {
                total += (item.prix || 0) * (item.quantite || 1);
            }
            if (item.children) {
                total += this.calculateSectionTotal(item.children);
            }
        });
        return total;
    },

    formatMoney: function(amount) {
        return new Intl.NumberFormat('fr-CA', { style: 'currency', currency: 'CAD' }).format(amount || 0);
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
