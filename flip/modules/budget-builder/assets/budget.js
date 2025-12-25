/**
 * Budget Builder - JavaScript
 */

const BudgetBuilder = {
    projetId: null,
    ajaxUrl: null,
    originalCatalogueHtml: null,
    currentCatalogueView: 'normal',

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
        this.initCatalogueViewToggle();

        // Sauvegarder le HTML original du catalogue
        this.originalCatalogueHtml = document.getElementById('catalogue-tree').innerHTML;

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
                this.classList.remove('drag-above', 'drag-below', 'drag-into');
            });

            item.addEventListener('drop', function(e) {
                e.preventDefault();
                e.stopPropagation();

                const data = JSON.parse(e.dataTransfer.getData('text/plain'));
                const draggedId = parseInt(data.id);
                const targetId = parseInt(this.dataset.id);

                if (draggedId === targetId) return;

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
    },

    moveItem: function(itemId, targetId, position, newParentId) {
        this.ajax('move_catalogue_item', {
            id: itemId,
            target_id: targetId,
            position: position,
            new_parent_id: newParentId
        }).then(response => {
            if (response.success) {
                location.reload();
            } else {
                alert('Erreur: ' + (response.message || 'Échec du déplacement'));
            }
        });
    },

    addFolderToPanier: function(folderId) {
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
                location.reload();
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
        const nom = prompt(type === 'folder' ? 'Nom du dossier:' : 'Nom de l\'item:');
        if (!nom || !nom.trim()) return;

        let prix = 0;
        if (type === 'item') {
            const prixStr = prompt('Prix:', '0');
            prix = parseFloat(prixStr) || 0;
        }

        this.ajax('add_catalogue_item', {
            parent_id: parentId,
            type: type,
            nom: nom.trim(),
            prix: prix
        }).then(response => {
            if (response.success) {
                location.reload();
            } else {
                alert('Erreur: ' + (response.message || 'Échec'));
            }
        });
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

        this.ajax('delete_catalogue_item', { id: itemId })
            .then(response => {
                if (response.success) {
                    location.reload();
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

        this.ajax('add_to_panier', {
            projet_id: this.projetId,
            catalogue_item_id: catalogueItemId
        }).then(response => {
            if (response.success) {
                location.reload();
            } else {
                alert('Erreur: ' + (response.message || 'Échec'));
            }
        });
    },

    removeFromPanier: function(panierItemId) {
        this.ajax('remove_from_panier', { id: panierItemId })
            .then(response => {
                if (response.success) {
                    location.reload();
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
        let total = 0;
        document.querySelectorAll('.panier-item.is-item').forEach(item => {
            const prixEl = item.querySelector('.item-prix');
            // Utiliser data-prix pour le prix brut (évite les problèmes de parsing du format monétaire)
            const prix = parseFloat(prixEl?.dataset?.prix) || 0;
            const qte = parseInt(item.querySelector('.item-qte')?.value) || 1;
            const itemTotal = prix * qte;
            total += itemTotal;

            const totalEl = item.querySelector('.item-total');
            if (totalEl) {
                totalEl.textContent = this.formatMoney(itemTotal);
            }
        });

        document.querySelectorAll('#panier-total, #panier-total-footer').forEach(el => {
            el.textContent = this.formatMoney(total);
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
            // Compter seulement les items (pas les dossiers)
            const itemCount = group.items.filter(i => i.type === 'item').length;
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

        // Filtrer pour ne montrer que les items (pas les dossiers) dans la vue par étape
        items.filter(item => item.type === 'item').forEach(item => {
            // Afficher le chemin du dossier si présent
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
        });

        if (html === '') {
            html = '<div class="text-muted py-2 ps-3"><em>Aucun item</em></div>';
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
