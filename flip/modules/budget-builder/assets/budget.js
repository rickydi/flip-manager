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

        // Zone de drop (panier) - accepte items ET dossiers
        const panierZone = document.getElementById('panier-items');
        if (panierZone) {
            panierZone.addEventListener('dragover', function(e) {
                e.preventDefault();
                e.dataTransfer.dropEffect = 'copy';
                this.classList.add('drag-over');
            });

            panierZone.addEventListener('dragleave', function(e) {
                // Vérifier qu'on quitte vraiment la zone
                if (!this.contains(e.relatedTarget)) {
                    this.classList.remove('drag-over');
                }
            });

            panierZone.addEventListener('drop', function(e) {
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
                    alert('Erreur: ' + err.message);
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
                alert('Ajouté: ' + (response.count || 0) + ' item(s) du dossier');
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
        document.querySelectorAll('.panier-item').forEach(item => {
            const prix = parseFloat(item.querySelector('.item-prix')?.textContent.replace(/[^0-9.-]/g, '')) || 0;
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
