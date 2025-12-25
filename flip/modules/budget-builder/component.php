<?php
/**
 * Budget Builder - Composant intégrable
 * Magasin (éditable) + Panier
 */

// S'assurer que les dépendances sont chargées
if (!isset($pdo)) {
    require_once __DIR__ . '/../../config.php';
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
 * Récupérer le panier (items du projet)
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
    return $stmt->fetchAll();
}

// ============================================
// Charger les données
// ============================================
$catalogue = getCatalogueTree($pdo);
$panier = isset($projetId) ? getPanier($pdo, $projetId) : [];

// Calculer le total du panier
$totalPanier = 0;
foreach ($panier as $item) {
    $totalPanier += ($item['prix'] ?? $item['catalogue_prix'] ?? 0) * ($item['quantite'] ?? 1);
}
?>

<link rel="stylesheet" href="<?= url('/modules/budget-builder/assets/budget.css') ?>?v=<?= time() ?>">

<div class="budget-builder-container">
    <div class="row g-3">
        <!-- MAGASIN (gauche) -->
        <div class="col-md-5">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center py-2">
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
                <div class="card-body p-2" style="max-height: 70vh; overflow-y: auto;">
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
        <div class="col-md-7">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center py-2">
                    <span>
                        <i class="bi bi-cart3 me-2"></i><strong>Panier</strong>
                        <?php if (isset($projet)): ?>
                            <span class="text-muted">- <?= e($projet['nom'] ?? 'Projet') ?></span>
                        <?php endif; ?>
                    </span>
                    <span class="badge bg-primary fs-6" id="panier-total"><?= formatMoney($totalPanier) ?></span>
                </div>
                <div class="card-body p-2" style="max-height: 70vh; overflow-y: auto;">
                    <div id="panier-items" class="panier-items">
                        <?php if (empty($panier)): ?>
                            <div class="text-center text-muted py-4" id="panier-empty">
                                <i class="bi bi-cart" style="font-size: 2rem;"></i>
                                <p class="mt-2 mb-0">Panier vide</p>
                                <small>Glissez des items depuis le Magasin</small>
                            </div>
                        <?php else: ?>
                            <?php foreach ($panier as $item): ?>
                                <div class="panier-item" data-id="<?= $item['id'] ?>">
                                    <i class="bi bi-grip-vertical drag-handle"></i>
                                    <span class="item-nom"><?= e($item['nom'] ?? $item['catalogue_nom']) ?></span>
                                    <input type="number" class="form-control form-control-sm item-qte"
                                           value="<?= $item['quantite'] ?? 1 ?>" min="1" style="width: 60px;">
                                    <span class="item-prix"><?= formatMoney($item['prix'] ?? $item['catalogue_prix'] ?? 0) ?></span>
                                    <span class="item-total fw-bold"><?= formatMoney(($item['prix'] ?? $item['catalogue_prix'] ?? 0) * ($item['quantite'] ?? 1)) ?></span>
                                    <button type="button" class="btn btn-link text-danger p-0" onclick="removeFromPanier(<?= $item['id'] ?>)">
                                        <i class="bi bi-x-lg"></i>
                                    </button>
                                </div>
                            <?php endforeach; ?>
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
        $indent = $level * 20;
    ?>
        <div class="catalogue-item <?= $isFolder ? 'is-folder' : 'is-item' ?>"
             data-id="<?= $item['id'] ?>"
             data-type="<?= $item['type'] ?>"
             data-prix="<?= $item['prix'] ?>"
             style="padding-left: <?= $indent + 8 ?>px;">

            <?php if ($isFolder): ?>
                <span class="folder-toggle <?= $hasChildren ? '' : 'invisible' ?>" onclick="toggleFolder(this)">
                    <i class="bi bi-caret-down-fill"></i>
                </span>
                <i class="bi bi-folder-fill text-warning me-1"></i>
            <?php else: ?>
                <span class="folder-toggle invisible"></span>
                <i class="bi bi-box-seam text-primary me-1"></i>
            <?php endif; ?>

            <span class="item-nom flex-grow-1" ondblclick="editItemName(this, <?= $item['id'] ?>)">
                <?= e($item['nom']) ?>
            </span>

            <?php if (!$isFolder): ?>
                <span class="badge bg-secondary me-1"><?= formatMoney($item['prix']) ?></span>
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
?>

<script src="<?= url('/modules/budget-builder/assets/budget.js') ?>?v=<?= time() ?>"></script>
<script>
    // Initialiser avec l'ID du projet
    BudgetBuilder.init(<?= $projetId ?? 'null' ?>);
</script>
