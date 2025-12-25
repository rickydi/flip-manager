/**
 * Budget Builder - JavaScript
 */

const BudgetBuilder = {
    projetId: null,
    ajaxUrl: null,

    init: function(projetId) {
        this.projetId = projetId;
        this.ajaxUrl = window.location.pathname.replace(/\/[^\/]*$/, '') + '/modules/budget-builder/ajax.php';

        // Si on est dans un sous-dossier, ajuster l'URL
        if (window.location.pathname.includes('/admin/projets/')) {
            this.ajaxUrl = window.location.pathname.replace(/\/admin\/projets\/.*$/, '') + '/modules/budget-builder/ajax.php';
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

        // Items draggables dans le catalogue
        document.querySelectorAll('.catalogue-item.is-item').forEach(item => {
            item.draggable = true;

            item.addEventListener('dragstart', function(e) {
                e.dataTransfer.setData('text/plain', this.dataset.id);
                e.dataTransfer.effectAllowed = 'copy';
                this.classList.add('dragging');
            });

            item.addEventListener('dragend', function() {
                this.classList.remove('dragging');
            });
        });

        // Zone de drop (panier)
        const panierZone = document.getElementById('panier-items');
        if (panierZone) {
            panierZone.addEventListener('dragover', function(e) {
                e.preventDefault();
                e.dataTransfer.dropEffect = 'copy';
                this.classList.add('drag-over');
            });

            panierZone.addEventListener('dragleave', function() {
                this.classList.remove('drag-over');
            });

            panierZone.addEventListener('drop', function(e) {
                e.preventDefault();
                this.classList.remove('drag-over');

                const itemId = e.dataTransfer.getData('text/plain');
                if (itemId) {
                    self.addToPanier(parseInt(itemId));
                }
            });
        }
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
