<?php
/**
 * Budget Builder - Composant intégrable
 * Magasin (éditable) + Panier
 */

// S'assurer que les dépendances sont chargées
if (!isset($pdo)) {
    require_once __DIR__ . '/../../config.php';
}
if (!function_exists('e')) {
    require_once __DIR__ . '/../../includes/functions.php';
}

// ============================================
// AUTO-MIGRATION: Table catalogue simplifiée
// ============================================
try {
    $pdo->query("SELECT 1 FROM catalogue_items LIMIT 1");
} catch (Exception $e) {
    // Créer la table
    $pdo->exec("
        CREATE TABLE catalogue_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            parent_id INT NULL,
            type ENUM('folder', 'item') NOT NULL DEFAULT 'folder',
            nom VARCHAR(255) NOT NULL,
            prix DECIMAL(10,2) DEFAULT 0,
            quantite_defaut INT DEFAULT 1,
            ordre INT DEFAULT 0,
            actif TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_parent (parent_id),
            INDEX idx_type (type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Migrer les données existantes depuis l'ancien système
    migrateOldData($pdo);
}

// Ajouter colonnes fournisseur et lien_achat si manquantes
try {
    $pdo->query("SELECT fournisseur FROM catalogue_items LIMIT 1");
} catch (Exception $e) {
    $pdo->exec("ALTER TABLE catalogue_items ADD COLUMN fournisseur VARCHAR(255) DEFAULT NULL");
    $pdo->exec("ALTER TABLE catalogue_items ADD COLUMN lien_achat VARCHAR(500) DEFAULT NULL");
}

// ============================================
// AUTO-MIGRATION: Table budget_items (panier)
// ============================================
try {
    $pdo->query("SELECT 1 FROM budget_items LIMIT 1");
} catch (Exception $e) {
    $pdo->exec("
        CREATE TABLE budget_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            projet_id INT NOT NULL,
            catalogue_item_id INT NULL,
            nom VARCHAR(255) NOT NULL,
            prix DECIMAL(10,2) DEFAULT 0,
            quantite INT DEFAULT 1,
            ordre INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_projet (projet_id),
            INDEX idx_catalogue (catalogue_item_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

/**
 * Migration des anciennes données (categories/sous_categories/materiaux)
 */
function migrateOldData($pdo) {
    // Vérifier si anciennes tables existent
    try {
        $pdo->query("SELECT 1 FROM categories LIMIT 1");
    } catch (Exception $e) {
        return; // Pas d'anciennes données
    }

    // Migrer les catégories comme dossiers racine
    $stmt = $pdo->query("SELECT * FROM categories ORDER BY groupe, ordre");
    $categories = $stmt->fetchAll();

    $catMapping = []; // old_id => new_id

    foreach ($categories as $cat) {
        $pdo->prepare("INSERT INTO catalogue_items (type, nom, ordre) VALUES ('folder', ?, ?)")
            ->execute([$cat['nom'], $cat['ordre']]);
        $catMapping[$cat['id']] = $pdo->lastInsertId();
    }

    // Migrer les sous-catégories récursivement
    migrateSubcategories($pdo, $catMapping);
}

function migrateSubcategories($pdo, $catMapping, $oldParentId = null, $newParentId = null) {
    if ($oldParentId === null) {
        // Premier niveau - sous-catégories directes des catégories
        foreach ($catMapping as $oldCatId => $newCatId) {
            $stmt = $pdo->prepare("SELECT * FROM sous_categories WHERE categorie_id = ? AND parent_id IS NULL ORDER BY ordre");
            $stmt->execute([$oldCatId]);
            $sousCategories = $stmt->fetchAll();

            foreach ($sousCategories as $sc) {
                $pdo->prepare("INSERT INTO catalogue_items (parent_id, type, nom, ordre) VALUES (?, 'folder', ?, ?)")
                    ->execute([$newCatId, $sc['nom'], $sc['ordre']]);
                $newScId = $pdo->lastInsertId();

                // Migrer les matériaux de cette sous-catégorie
                migrateMaterials($pdo, $sc['id'], $newScId);

                // Récursion pour les enfants
                migrateChildSubcategories($pdo, $sc['id'], $newScId);
            }
        }
    }
}

function migrateChildSubcategories($pdo, $oldParentId, $newParentId) {
    $stmt = $pdo->prepare("SELECT * FROM sous_categories WHERE parent_id = ? ORDER BY ordre");
    $stmt->execute([$oldParentId]);
    $children = $stmt->fetchAll();

    foreach ($children as $child) {
        $pdo->prepare("INSERT INTO catalogue_items (parent_id, type, nom, ordre) VALUES (?, 'folder', ?, ?)")
            ->execute([$newParentId, $child['nom'], $child['ordre']]);
        $newChildId = $pdo->lastInsertId();

        // Migrer les matériaux
        migrateMaterials($pdo, $child['id'], $newChildId);

        // Récursion
        migrateChildSubcategories($pdo, $child['id'], $newChildId);
    }
}

function migrateMaterials($pdo, $oldScId, $newParentId) {
    $stmt = $pdo->prepare("SELECT * FROM materiaux WHERE sous_categorie_id = ? ORDER BY ordre");
    $stmt->execute([$oldScId]);
    $materiaux = $stmt->fetchAll();

    foreach ($materiaux as $mat) {
        $pdo->prepare("INSERT INTO catalogue_items (parent_id, type, nom, prix, quantite_defaut, ordre) VALUES (?, 'item', ?, ?, ?, ?)")
            ->execute([$newParentId, $mat['nom'], $mat['prix_defaut'], $mat['quantite_defaut'] ?? 1, $mat['ordre']]);
    }
}

// ============================================
// FONCTIONS HELPERS
// ============================================

/**
 * Récupérer l'arbre du catalogue
 */
function getCatalogueTree($pdo, $parentId = null) {
    if ($parentId === null) {
        $stmt = $pdo->prepare("SELECT * FROM catalogue_items WHERE parent_id IS NULL AND actif = 1 ORDER BY type DESC, ordre, nom");
        $stmt->execute();
    } else {
        $stmt = $pdo->prepare("SELECT * FROM catalogue_items WHERE parent_id = ? AND actif = 1 ORDER BY type DESC, ordre, nom");
        $stmt->execute([$parentId]);
    }

    $items = $stmt->fetchAll();

    foreach ($items as &$item) {
        if ($item['type'] === 'folder') {
            $item['children'] = getCatalogueTree($pdo, $item['id']);
        }
    }

    return $items;
}

/**
 * Récupérer le panier en arbre (items du projet)
 */
function getPanier($pdo, $projetId) {
    if (!$projetId) return [];

    $stmt = $pdo->prepare("
        SELECT bi.*, ci.nom as catalogue_nom, ci.prix as catalogue_prix
        FROM budget_items bi
        LEFT JOIN catalogue_items ci ON bi.catalogue_item_id = ci.id
        WHERE bi.projet_id = ?
        ORDER BY bi.ordre, bi.id
    ");
    $stmt->execute([$projetId]);
    $allItems = $stmt->fetchAll();

    // Construire l'arbre
    return buildPanierTree($allItems);
}

function buildPanierTree($items, $parentId = null) {
    $tree = [];
    foreach ($items as $item) {
        $itemParentId = $item['parent_budget_id'] ?? null;
        if ($itemParentId == $parentId) {
            $itemType = $item['type'] ?? 'item';
            if ($itemType === 'folder') {
                $item['children'] = buildPanierTree($items, $item['id']);
            }
            $tree[] = $item;
        }
    }
    return $tree;
}

/**
 * Calculer le total du panier récursivement (inclut items dans les dossiers)
 */
function calculatePanierTotal($items) {
    $total = 0;
    foreach ($items as $item) {
        $itemType = $item['type'] ?? 'item';
        if ($itemType === 'item') {
            $prix = $item['prix'] ?? $item['catalogue_prix'] ?? 0;
            $quantite = $item['quantite'] ?? 1;
            $total += $prix * $quantite;
        }
        // Récursion pour les enfants (dossiers)
        if (!empty($item['children'])) {
            $total += calculatePanierTotal($item['children']);
        }
    }
    return $total;
}

// ============================================
// Charger les données
// ============================================
$catalogue = getCatalogueTree($pdo);
$panier = isset($projetId) ? getPanier($pdo, $projetId) : [];

// Calculer le total du panier (récursif)
$totalPanier = calculatePanierTotal($panier);
?>

<link rel="stylesheet" href="<?= url('/modules/budget-builder/assets/budget.css') ?>?v=<?= time() ?>">

<div class="budget-builder-container">
    <div class="row g-3">
        <!-- MAGASIN (gauche) -->
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center" style="min-height: 50px;">
                    <span><i class="bi bi-shop me-2"></i><strong>Magasin</strong></span>
                    <div class="btn-group btn-group-sm">
                        <button type="button" class="btn btn-success btn-sm" onclick="addItem(null, 'folder')" title="Nouveau dossier">
                            <i class="bi bi-folder-plus"></i>
                        </button>
                        <button type="button" class="btn btn-primary btn-sm" onclick="addItem(null, 'item')" title="Nouvel item">
                            <i class="bi bi-plus-circle"></i>
                        </button>
                    </div>
                </div>
                <div class="card-body p-2">
                    <div id="catalogue-tree" class="catalogue-tree">
                        <?php renderCatalogueTree($catalogue); ?>
                    </div>
                    <?php if (empty($catalogue)): ?>
                        <div class="text-center text-muted py-4">
                            <i class="bi bi-inbox" style="font-size: 2rem;"></i>
                            <p class="mt-2 mb-0">Catalogue vide</p>
                            <small>Ajoutez des dossiers et items</small>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- PANIER (droite) -->
        <div class="col-md-6">
            <div class="card h-100" id="panier-card">
                <div class="card-header d-flex justify-content-between align-items-center" style="min-height: 50px;">
                    <span>
                        <i class="bi bi-cart3 me-2"></i><strong>Panier</strong>
                        <?php if (isset($projet)): ?>
                            <span class="text-muted ms-1">- <?= e($projet['nom'] ?? 'Projet') ?></span>
                        <?php endif; ?>
                    </span>
                    <div class="d-flex align-items-center gap-2">
                        <?php if (!empty($panier)): ?>
                        <button type="button" class="btn btn-primary btn-sm" onclick="openOrderModal()">
                            <i class="bi bi-file-earmark-text me-1"></i>Commande
                        </button>
                        <?php endif; ?>
                        <span class="badge bg-primary fs-6" id="panier-total"><?= formatMoney($totalPanier) ?></span>
                    </div>
                </div>
                <div class="card-body p-2">
                    <div id="panier-items" class="panier-items">
                        <?php if (empty($panier)): ?>
                            <div class="text-center text-muted py-4" id="panier-empty">
                                <i class="bi bi-cart" style="font-size: 2rem;"></i>
                                <p class="mt-2 mb-0">Panier vide</p>
                                <small>Glissez des items depuis le Magasin</small>
                            </div>
                        <?php else: ?>
                            <?php renderPanierTree($panier); ?>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-footer py-2">
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="text-muted">Total estimé</span>
                        <span class="fs-5 fw-bold text-success" id="panier-total-footer"><?= formatMoney($totalPanier) ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
/**
 * Afficher l'arbre du catalogue récursivement
 */
function renderCatalogueTree($items, $level = 0) {
    if (empty($items)) return;

    foreach ($items as $item):
        $isFolder = $item['type'] === 'folder';
        $hasChildren = !empty($item['children']);
    ?>
        <div class="catalogue-item <?= $isFolder ? 'is-folder' : 'is-item' ?>"
             data-id="<?= $item['id'] ?>"
             data-type="<?= $item['type'] ?>"
             data-prix="<?= $item['prix'] ?>">

            <?php if ($isFolder): ?>
                <span class="folder-toggle <?= $hasChildren ? '' : 'invisible' ?>" onclick="toggleFolder(this)">
                    <i class="bi bi-caret-down-fill"></i>
                </span>
                <i class="bi bi-folder-fill text-warning me-1"></i>
            <?php else: ?>
                <span class="folder-toggle invisible"></span>
                <i class="bi bi-box-seam text-primary me-1"></i>
            <?php endif; ?>

            <span class="item-nom" ondblclick="editItemName(this, <?= $item['id'] ?>)">
                <?= e($item['nom']) ?>
            </span>

            <?php if (!$isFolder): ?>
                <span class="badge bg-secondary me-1"><?= formatMoney($item['prix']) ?></span>
                <button type="button" class="btn btn-sm btn-link p-0 text-info me-1"
                        onclick="openItemModal(<?= $item['id'] ?>)" title="Modifier">
                    <i class="bi bi-pencil"></i>
                </button>
                <button type="button" class="btn btn-sm btn-link p-0 text-success add-to-panier"
                        onclick="addToPanier(<?= $item['id'] ?>)" title="Ajouter au panier">
                    <i class="bi bi-plus-circle-fill"></i>
                </button>
            <?php else: ?>
                <div class="btn-group btn-group-sm">
                    <button type="button" class="btn btn-link p-0 text-success" onclick="addItem(<?= $item['id'] ?>, 'folder')" title="Sous-dossier">
                        <i class="bi bi-folder-plus"></i>
                    </button>
                    <button type="button" class="btn btn-link p-0 text-primary" onclick="addItem(<?= $item['id'] ?>, 'item')" title="Item">
                        <i class="bi bi-plus-circle"></i>
                    </button>
                </div>
            <?php endif; ?>

            <button type="button" class="btn btn-sm btn-link p-0 text-danger ms-1" onclick="deleteItem(<?= $item['id'] ?>)" title="Supprimer">
                <i class="bi bi-trash"></i>
            </button>
        </div>

        <?php if ($isFolder && $hasChildren): ?>
            <div class="folder-children" data-parent="<?= $item['id'] ?>">
                <?php renderCatalogueTree($item['children'], $level + 1); ?>
            </div>
        <?php endif; ?>
    <?php
    endforeach;
}

/**
 * Afficher l'arbre du panier récursivement
 */
function renderPanierTree($items, $level = 0) {
    if (empty($items)) return;

    foreach ($items as $item):
        $isFolder = ($item['type'] ?? 'item') === 'folder';
        $hasChildren = !empty($item['children']);
    ?>
        <div class="panier-item <?= $isFolder ? 'is-folder' : 'is-item' ?>"
             data-id="<?= $item['id'] ?>"
             data-type="<?= $item['type'] ?? 'item' ?>">

            <?php if ($isFolder): ?>
                <span class="folder-toggle <?= $hasChildren ? '' : 'invisible' ?>" onclick="togglePanierFolder(this)">
                    <i class="bi bi-caret-down-fill"></i>
                </span>
                <i class="bi bi-folder-fill text-warning me-1"></i>
                <span class="item-nom fw-bold"><?= e($item['nom'] ?? $item['catalogue_nom']) ?></span>
            <?php else: ?>
                <span class="folder-toggle invisible"></span>
                <i class="bi bi-box-seam text-primary me-1"></i>
                <span class="item-nom"><?= e($item['nom'] ?? $item['catalogue_nom']) ?></span>
                <input type="number" class="form-control form-control-sm item-qte"
                       value="<?= $item['quantite'] ?? 1 ?>" min="1">
                <span class="badge bg-secondary item-prix"><?= formatMoney($item['prix'] ?? $item['catalogue_prix'] ?? 0) ?></span>
                <span class="badge bg-success item-total"><?= formatMoney(($item['prix'] ?? $item['catalogue_prix'] ?? 0) * ($item['quantite'] ?? 1)) ?></span>
            <?php endif; ?>

            <button type="button" class="btn btn-sm btn-link p-0 text-danger ms-1" onclick="removeFromPanier(<?= $item['id'] ?>)" title="Supprimer">
                <i class="bi bi-trash"></i>
            </button>
        </div>

        <?php if ($isFolder && $hasChildren): ?>
            <div class="panier-folder-children" data-parent="<?= $item['id'] ?>">
                <?php renderPanierTree($item['children'], $level + 1); ?>
            </div>
        <?php endif; ?>
    <?php
    endforeach;
}
?>

<!-- Bouton retour en haut -->
<button type="button" id="scroll-to-top" class="scroll-to-top" title="Retour en haut">
    <i class="bi bi-arrow-up"></i>
</button>

<!-- Modal commande groupée par fournisseur -->
<div class="modal fade" id="orderModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-file-earmark-text me-2"></i>Document de commande</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="order-content">
                <div class="text-center py-4">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Chargement...</span>
                    </div>
                </div>
            </div>
            <div class="modal-footer justify-content-between">
                <div>
                    <span class="text-muted" id="order-checked-count">0 / 0 commandés</span>
                </div>
                <div>
                    <button type="button" class="btn btn-outline-secondary" onclick="printOrder()">
                        <i class="bi bi-printer me-1"></i>Imprimer
                    </button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fermer</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal édition item -->
<div class="modal fade" id="itemModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-box-seam me-2"></i>Modifier l'item</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="item-modal-id">
                <div class="mb-3">
                    <label class="form-label">Nom</label>
                    <input type="text" class="form-control" id="item-modal-nom">
                </div>
                <div class="mb-3">
                    <label class="form-label">Prix</label>
                    <div class="input-group">
                        <input type="number" class="form-control" id="item-modal-prix" step="0.01" min="0">
                        <span class="input-group-text">$</span>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Fournisseur</label>
                    <input type="text" class="form-control" id="item-modal-fournisseur" list="fournisseurs-list" placeholder="Choisir ou saisir...">
                    <datalist id="fournisseurs-list"></datalist>
                </div>
                <div class="mb-3">
                    <label class="form-label">Lien d'achat</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-link-45deg"></i></span>
                        <input type="url" class="form-control" id="item-modal-lien" placeholder="https://...">
                        <button type="button" class="btn btn-outline-secondary" id="item-modal-open-link" title="Ouvrir le lien">
                            <i class="bi bi-box-arrow-up-right"></i>
                        </button>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                <button type="button" class="btn btn-primary" onclick="saveItemModal()">
                    <i class="bi bi-check-lg me-1"></i>Enregistrer
                </button>
            </div>
        </div>
    </div>
</div>

<script src="<?= url('/modules/budget-builder/assets/budget.js') ?>?v=<?= time() ?>"></script>
<script>
    // Initialiser avec l'ID du projet
    BudgetBuilder.init(<?= $projetId ?? 'null' ?>);

    // Modal item
    let itemModal = null;

    function openItemModal(itemId) {
        if (!itemModal) {
            itemModal = new bootstrap.Modal(document.getElementById('itemModal'));
        }

        // Charger les fournisseurs et les données de l'item en parallèle
        Promise.all([
            BudgetBuilder.ajax('get_fournisseurs', {}),
            BudgetBuilder.ajax('get_item', { id: itemId })
        ]).then(([fournisseursResp, itemResp]) => {
            // Remplir la datalist des fournisseurs
            if (fournisseursResp.success && fournisseursResp.fournisseurs) {
                const datalist = document.getElementById('fournisseurs-list');
                datalist.innerHTML = fournisseursResp.fournisseurs
                    .map(f => `<option value="${f}">`)
                    .join('');
            }

            // Remplir les champs de l'item
            if (itemResp.success && itemResp.item) {
                document.getElementById('item-modal-id').value = itemResp.item.id;
                document.getElementById('item-modal-nom').value = itemResp.item.nom || '';
                document.getElementById('item-modal-prix').value = itemResp.item.prix || 0;
                document.getElementById('item-modal-fournisseur').value = itemResp.item.fournisseur || '';
                document.getElementById('item-modal-lien').value = itemResp.item.lien_achat || '';
                itemModal.show();
            }
        });
    }

    function saveItemModal() {
        const id = document.getElementById('item-modal-id').value;
        const data = {
            id: id,
            nom: document.getElementById('item-modal-nom').value,
            prix: document.getElementById('item-modal-prix').value,
            fournisseur: document.getElementById('item-modal-fournisseur').value,
            lien_achat: document.getElementById('item-modal-lien').value
        };

        BudgetBuilder.ajax('update_item', data).then(response => {
            if (response.success) {
                itemModal.hide();
                location.reload();
            } else {
                alert('Erreur: ' + (response.message || 'Échec'));
            }
        });
    }

    // Ouvrir le lien dans un nouvel onglet
    document.getElementById('item-modal-open-link').addEventListener('click', function() {
        const lien = document.getElementById('item-modal-lien').value;
        if (lien) {
            window.open(lien, '_blank');
        }
    });

    // Bouton scroll to top
    (function() {
        const btn = document.getElementById('scroll-to-top');

        window.addEventListener('scroll', function() {
            if (window.scrollY > 300) {
                btn.classList.add('visible');
            } else {
                btn.classList.remove('visible');
            }
        });

        btn.addEventListener('click', function() {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
    })();

    // Modal commande
    let orderModal = null;

    function openOrderModal() {
        if (!orderModal) {
            orderModal = new bootstrap.Modal(document.getElementById('orderModal'));
        }

        // Charger les items groupés par fournisseur
        BudgetBuilder.ajax('get_order_items', { projet_id: BudgetBuilder.projetId }).then(response => {
            if (response.success && response.grouped) {
                renderOrderContent(response.grouped);
                orderModal.show();
            }
        });
    }

    function renderOrderContent(grouped) {
        const container = document.getElementById('order-content');
        let html = '';
        let totalItems = 0;
        let checkedItems = 0;

        // Trier les fournisseurs (Non spécifié en dernier)
        const suppliers = Object.keys(grouped).sort((a, b) => {
            if (a === 'Non spécifié') return 1;
            if (b === 'Non spécifié') return -1;
            return a.localeCompare(b);
        });

        for (const fournisseur of suppliers) {
            const items = grouped[fournisseur];
            let supplierTotal = 0;

            html += `<div class="order-supplier mb-4">
                <h5 class="border-bottom pb-2 mb-3">
                    <i class="bi bi-shop me-2"></i>${escapeHtml(fournisseur)}
                    <span class="badge bg-secondary float-end">${items.length} item(s)</span>
                </h5>
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 40px;"></th>
                                <th>Article</th>
                                <th class="text-center" style="width: 60px;">Qté</th>
                                <th class="text-end" style="width: 80px;">Prix</th>
                                <th class="text-end" style="width: 90px;">Total</th>
                            </tr>
                        </thead>
                        <tbody>`;

            for (const item of items) {
                totalItems++;
                const isChecked = item.commande == 1;
                if (isChecked) checkedItems++;

                const total = (parseFloat(item.prix) || 0) * (parseInt(item.quantite) || 1);
                supplierTotal += total;

                const linkHtml = item.lien_achat
                    ? `<a href="${escapeHtml(item.lien_achat)}" target="_blank" class="ms-2" title="${escapeHtml(item.lien_achat)}"><i class="bi bi-box-arrow-up-right"></i></a>`
                    : '';

                html += `<tr class="${isChecked ? 'table-success' : ''}">
                    <td class="text-center">
                        <input type="checkbox" class="form-check-input order-check"
                               data-id="${item.id}" ${isChecked ? 'checked' : ''}
                               onchange="toggleOrderItem(${item.id}, this.checked)">
                    </td>
                    <td class="${isChecked ? 'text-decoration-line-through text-muted' : ''}">${escapeHtml(item.nom)}${linkHtml}</td>
                    <td class="text-center">${item.quantite}</td>
                    <td class="text-end">${formatMoney(item.prix)}</td>
                    <td class="text-end fw-bold">${formatMoney(total)}</td>
                </tr>`;
            }

            html += `</tbody>
                    <tfoot class="table-light">
                        <tr>
                            <td colspan="4" class="text-end fw-bold">Sous-total ${escapeHtml(fournisseur)}:</td>
                            <td class="text-end fw-bold text-success">${formatMoney(supplierTotal)}</td>
                        </tr>
                    </tfoot>
                </table>
                </div>
            </div>`;
        }

        if (Object.keys(grouped).length === 0) {
            html = '<div class="text-center text-muted py-4"><i class="bi bi-inbox" style="font-size: 2rem;"></i><p class="mt-2">Aucun item dans le panier</p></div>';
        }

        container.innerHTML = html;
        updateOrderCount(checkedItems, totalItems);
    }

    function toggleOrderItem(itemId, checked) {
        BudgetBuilder.ajax('toggle_order_item', { item_id: itemId, checked: checked }).then(response => {
            if (response.success) {
                // Mettre à jour l'affichage
                const row = document.querySelector(`input[data-id="${itemId}"]`).closest('tr');
                const nameCell = row.querySelector('td:nth-child(2)');

                if (checked) {
                    row.classList.add('table-success');
                    nameCell.classList.add('text-decoration-line-through', 'text-muted');
                } else {
                    row.classList.remove('table-success');
                    nameCell.classList.remove('text-decoration-line-through', 'text-muted');
                }

                // Recalculer le compteur
                const allChecks = document.querySelectorAll('.order-check');
                const checked_count = document.querySelectorAll('.order-check:checked').length;
                updateOrderCount(checked_count, allChecks.length);
            }
        });
    }

    function updateOrderCount(checked, total) {
        document.getElementById('order-checked-count').textContent = `${checked} / ${total} commandés`;
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text || '';
        return div.innerHTML;
    }

    function formatMoney(amount) {
        return new Intl.NumberFormat('fr-CA', { style: 'currency', currency: 'CAD' }).format(amount || 0);
    }

    function printOrder() {
        const content = document.getElementById('order-content').innerHTML;
        const printWindow = window.open('', '_blank');
        printWindow.document.write(`
            <!DOCTYPE html>
            <html>
            <head>
                <title>Document de commande</title>
                <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
                <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
                <style>
                    body { padding: 20px; }
                    @media print {
                        .btn { display: none !important; }
                        a { text-decoration: none !important; color: inherit !important; }
                    }
                </style>
            </head>
            <body>
                <h3 class="mb-4"><i class="bi bi-file-earmark-text me-2"></i>Document de commande</h3>
                ${content}
                <script>setTimeout(() => window.print(), 500);<\/script>
            </body>
            </html>
        `);
        printWindow.document.close();
    }
</script>
