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

// Ajouter colonne sans_taxe si manquante
try {
    $pdo->query("SELECT sans_taxe FROM catalogue_items LIMIT 1");
} catch (Exception $e) {
    $pdo->exec("ALTER TABLE catalogue_items ADD COLUMN sans_taxe TINYINT(1) DEFAULT 0");
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
 * Récupérer les enfants d'un élément (récursif)
 */
function getChildren($pdo, $parentId) {
    $stmt = $pdo->prepare("SELECT * FROM catalogue_items WHERE parent_id = ? AND actif = 1 ORDER BY type DESC, ordre, nom");
    $stmt->execute([$parentId]);
    $items = $stmt->fetchAll();

    foreach ($items as &$item) {
        if ($item['type'] === 'folder') {
            $item['children'] = getChildren($pdo, $item['id']);
        }
    }

    return $items;
}

/**
 * Récupérer le catalogue organisé par sections (étapes)
 */
function getCatalogueBySection($pdo) {
    // Récupérer toutes les étapes
    $stmt = $pdo->query("SELECT * FROM budget_etapes ORDER BY ordre, id");
    $etapes = $stmt->fetchAll();

    $sections = [];
    $etapeNum = 0;

    foreach ($etapes as $etape) {
        $etapeNum++;

        // Récupérer les éléments de premier niveau avec cette étape
        $stmt = $pdo->prepare("
            SELECT * FROM catalogue_items
            WHERE etape_id = ? AND actif = 1 AND (parent_id IS NULL OR parent_id IN (SELECT id FROM catalogue_items WHERE etape_id != ? OR etape_id IS NULL))
            ORDER BY type DESC, ordre, nom
        ");
        $stmt->execute([$etape['id'], $etape['id']]);
        $items = $stmt->fetchAll();

        // Récupérer les enfants pour chaque dossier
        foreach ($items as &$item) {
            if ($item['type'] === 'folder') {
                $item['children'] = getChildren($pdo, $item['id']);
            }
        }

        $sections[] = [
            'etape_id' => $etape['id'],
            'etape_nom' => $etape['nom'],
            'etape_num' => $etapeNum,
            'items' => $items
        ];
    }

    // Section "Non spécifié" pour les éléments sans étape
    $stmt = $pdo->query("
        SELECT * FROM catalogue_items
        WHERE (etape_id IS NULL OR etape_id = 0) AND parent_id IS NULL AND actif = 1
        ORDER BY type DESC, ordre, nom
    ");
    $noEtapeItems = $stmt->fetchAll();

    foreach ($noEtapeItems as &$item) {
        if ($item['type'] === 'folder') {
            $item['children'] = getChildren($pdo, $item['id']);
        }
    }

    if (!empty($noEtapeItems)) {
        $sections[] = [
            'etape_id' => null,
            'etape_nom' => 'Non spécifié',
            'etape_num' => null,
            'items' => $noEtapeItems
        ];
    }

    return $sections;
}

/**
 * Récupérer l'arbre du catalogue (ancienne méthode, gardée pour compatibilité)
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
 * Récupérer le panier groupé par section/étape
 */
function getPanierBySection($pdo, $projetId) {
    if (!$projetId) return [];

    // Récupérer toutes les étapes
    $stmt = $pdo->query("SELECT * FROM budget_etapes ORDER BY ordre, id");
    $etapes = $stmt->fetchAll();

    // Récupérer tous les items du panier avec l'étape
    $stmt = $pdo->prepare("
        SELECT bi.*, ci.nom as catalogue_nom, ci.prix as catalogue_prix, ci.etape_id,
               e.nom as etape_nom, e.ordre as etape_ordre
        FROM budget_items bi
        LEFT JOIN catalogue_items ci ON bi.catalogue_item_id = ci.id
        LEFT JOIN budget_etapes e ON ci.etape_id = e.id
        WHERE bi.projet_id = ?
        ORDER BY e.ordre, e.id, bi.ordre, bi.id
    ");
    $stmt->execute([$projetId]);
    $allItems = $stmt->fetchAll();

    if (empty($allItems)) return [];

    // Grouper par étape
    $sections = [];
    $etapeNum = 0;

    foreach ($etapes as $etape) {
        $etapeNum++;
        $etapeItems = array_filter($allItems, fn($item) => ($item['etape_id'] ?? null) == $etape['id']);
        if (!empty($etapeItems)) {
            $sections[] = [
                'etape_id' => $etape['id'],
                'etape_nom' => $etape['nom'],
                'etape_num' => $etapeNum,
                'items' => buildPanierTree(array_values($etapeItems))
            ];
        }
    }

    // Items sans étape
    $noEtapeItems = array_filter($allItems, fn($item) => empty($item['etape_id']));
    if (!empty($noEtapeItems)) {
        $sections[] = [
            'etape_id' => null,
            'etape_nom' => 'Non spécifié',
            'etape_num' => null,
            'items' => buildPanierTree(array_values($noEtapeItems))
        ];
    }

    return $sections;
}

/**
 * Récupérer le panier en arbre (items du projet) - version plate
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

    // Collecter tous les IDs valides pour détecter les orphelins
    $validIds = [];
    foreach ($items as $item) {
        $validIds[$item['id']] = true;
    }

    foreach ($items as $item) {
        $itemParentId = $item['parent_budget_id'] ?? null;

        // Ajouter si: pas de parent (root) OU parent correspond OU orphelin (parent n'existe pas)
        $isRoot = ($itemParentId == $parentId);
        $isOrphan = ($parentId === null && $itemParentId !== null && !isset($validIds[$itemParentId]));

        if ($isRoot || $isOrphan) {
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

/**
 * Calculer le total du panier par sections
 */
function calculatePanierSectionsTotal($sections) {
    $total = 0;
    foreach ($sections as $section) {
        $total += calculatePanierTotal($section['items']);
    }
    return $total;
}

// ============================================
// Auto-correction des étapes (propage l'étape aux enfants)
// ============================================
function autoFixEtapes($pdo) {
    // Fonction récursive pour propager l'étape
    $propagate = function($pdo, $parentId, $etapeId) use (&$propagate) {
        $stmt = $pdo->prepare("SELECT id FROM catalogue_items WHERE parent_id = ? AND actif = 1");
        $stmt->execute([$parentId]);
        $children = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($children as $childId) {
            $stmt = $pdo->prepare("UPDATE catalogue_items SET etape_id = ? WHERE id = ? AND (etape_id IS NULL OR etape_id != ?)");
            $stmt->execute([$etapeId, $childId, $etapeId]);
            $propagate($pdo, $childId, $etapeId);
        }
    };

    // Pour chaque item racine avec une étape, propager aux enfants
    $stmt = $pdo->query("SELECT id, etape_id FROM catalogue_items WHERE etape_id IS NOT NULL AND actif = 1");
    $items = $stmt->fetchAll();

    foreach ($items as $item) {
        $propagate($pdo, $item['id'], $item['etape_id']);
    }
}

// Exécuter l'auto-correction
autoFixEtapes($pdo);

// ============================================
// Charger les données
// ============================================
$catalogueSections = getCatalogueBySection($pdo);
$panierSections = isset($projetId) ? getPanierBySection($pdo, $projetId) : [];
$panier = isset($projetId) ? getPanier($pdo, $projetId) : [];

// Calculer le total du panier
$totalPanier = calculatePanierSectionsTotal($panierSections);
?>

<link rel="stylesheet" href="<?= url('/modules/budget-builder/assets/budget.css') ?>?v=<?= time() ?>">

<div class="budget-builder-container">
    <!-- Contrôle de taille du texte -->
    <div class="d-flex justify-content-end align-items-center mb-2 gap-1">
        <small class="text-muted me-1">Taille:</small>
        <button type="button" class="btn btn-outline-secondary btn-sm px-2 py-0" onclick="changeFontSize(-1)" title="Réduire">
            <i class="bi bi-dash"></i>
        </button>
        <button type="button" class="btn btn-outline-secondary btn-sm px-2 py-0" onclick="resetFontSize()" title="Réinitialiser">
            <i class="bi bi-arrow-counterclockwise"></i>
        </button>
        <button type="button" class="btn btn-outline-secondary btn-sm px-2 py-0" onclick="changeFontSize(1)" title="Agrandir">
            <i class="bi bi-plus"></i>
        </button>
    </div>

    <div class="row g-3">
        <!-- MAGASIN (gauche) -->
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center" style="min-height: 50px;">
                    <span><i class="bi bi-shop me-2"></i><strong>Magasin</strong></span>
                    <button type="button" class="btn btn-outline-primary btn-sm" onclick="openEtapesModal()" title="Gérer les sections/étapes">
                        <i class="bi bi-list-ol me-1"></i>Sections
                    </button>
                </div>
                <div class="card-body p-2">
                    <div id="catalogue-tree" class="catalogue-tree">
                        <?php renderCatalogueSections($catalogueSections); ?>
                    </div>
                    <?php if (empty($catalogueSections)): ?>
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
                        <?php if (!empty($panierSections)): ?>
                        <button type="button" class="btn btn-outline-danger btn-sm" onclick="clearPanier()" title="Vider le panier" style="height: 31px; width: 31px; padding: 0;">
                            <i class="bi bi-trash"></i>
                        </button>
                        <button type="button" class="btn btn-primary btn-sm" onclick="openOrderModal()" style="height: 31px;">
                            <i class="bi bi-file-earmark-text me-1"></i>Commande
                        </button>
                        <?php endif; ?>
                        <span class="btn btn-primary disabled d-flex align-items-center justify-content-center fw-bold" id="panier-total" style="height: 31px; min-width: 110px; opacity: 1; font-size: 1rem;"><?= formatMoney($totalPanier) ?></span>
                    </div>
                </div>
                <div class="card-body p-2">
                    <div id="panier-items" class="panier-items">
                        <?php if (empty($panierSections)): ?>
                            <div class="text-center text-muted py-4" id="panier-empty">
                                <i class="bi bi-cart" style="font-size: 2rem;"></i>
                                <p class="mt-2 mb-0">Panier vide</p>
                                <small>Glissez des items depuis le Magasin</small>
                            </div>
                        <?php else: ?>
                            <?php foreach ($panierSections as $section):
                                $etapeLabel = $section['etape_num'] ? "N.{$section['etape_num']} " . e($section['etape_nom']) : e($section['etape_nom']);
                                $sectionTotal = calculatePanierTotal($section['items']);
                            ?>
                            <div class="panier-section" data-etape-id="<?= $section['etape_id'] ?? 'null' ?>">
                                <div class="panier-section-header d-flex align-items-center justify-content-between" style="background: rgba(13, 110, 253, 0.1); border-left: 3px solid var(--bs-primary, #0d6efd); cursor: pointer; padding: 2px 8px;" onclick="togglePanierSection(this)">
                                    <span>
                                        <i class="bi bi-caret-down-fill section-toggle me-1"></i>
                                        <i class="bi bi-list-ol text-primary me-1"></i>
                                        <strong><?= $etapeLabel ?></strong>
                                        <span class="badge bg-secondary ms-1"><?= count($section['items']) ?></span>
                                    </span>
                                    <span class="badge bg-success"><?= formatMoney($sectionTotal) ?></span>
                                </div>
                                <div class="panier-section-content">
                                    <?php renderPanierTree($section['items']); ?>
                                </div>
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
 * Afficher le catalogue par sections (étapes)
 */
function renderCatalogueSections($sections) {
    if (empty($sections)) return;

    foreach ($sections as $section):
        $etapeLabel = $section['etape_num'] ? "N.{$section['etape_num']} " . e($section['etape_nom']) : e($section['etape_nom']);
        $etapeId = $section['etape_id'] ?? 'null';
        $itemCount = count($section['items']);
    ?>
        <div class="etape-section mb-3" data-etape-id="<?= $etapeId ?>">
            <div class="catalogue-item is-section-header" style="background: rgba(13, 110, 253, 0.1); border-left: 3px solid var(--bs-primary, #0d6efd);">
                <span class="folder-toggle" onclick="toggleSection(this)">
                    <i class="bi bi-caret-down-fill"></i>
                </span>
                <i class="bi bi-list-ol text-primary me-1"></i>
                <span class="item-nom fw-bold"><?= $etapeLabel ?></span>
                <span class="badge bg-primary ms-2"><?= $itemCount ?></span>
                <div class="btn-group btn-group-sm ms-auto" style="gap: 10px;">
                    <button type="button" class="btn btn-link p-0 text-success" onclick="addItemToSection(<?= $etapeId ?>, 'folder')" title="Ajouter dossier">
                        <i class="bi bi-folder-plus" style="font-size: 1.25rem;"></i>
                    </button>
                    <button type="button" class="btn btn-link p-0 text-primary" onclick="addItemToSection(<?= $etapeId ?>, 'item')" title="Ajouter item">
                        <i class="bi bi-plus-circle" style="font-size: 1.25rem;"></i>
                    </button>
                </div>
            </div>
            <div class="section-children folder-children" data-etape="<?= $etapeId ?>">
                <?php renderSectionItems($section['items']); ?>
            </div>
        </div>
    <?php
    endforeach;
}

/**
 * Afficher les items d'une section
 */
function renderSectionItems($items, $level = 0) {
    if (empty($items)) return;

    foreach ($items as $item):
        $isFolder = $item['type'] === 'folder';
        $hasChildren = !empty($item['children']);
    ?>
        <div class="catalogue-item <?= $isFolder ? 'is-folder' : 'is-item' ?>"
             data-id="<?= $item['id'] ?>"
             data-type="<?= $item['type'] ?>"
             data-prix="<?= $item['prix'] ?? 0 ?>">

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

            <span class="item-actions">
                <?php if (!$isFolder): ?>
                    <span class="badge bg-secondary item-prix-badge"><?= formatMoney($item['prix'] ?? 0) ?></span>
                    <button type="button" class="btn btn-sm btn-link p-0 text-info"
                            onclick="openItemModal(<?= $item['id'] ?>)" title="Modifier">
                        <i class="bi bi-pencil"></i>
                    </button>
                    <button type="button" class="btn btn-sm btn-link p-0 text-warning"
                            onclick="duplicateItem(<?= $item['id'] ?>)" title="Dupliquer">
                        <i class="bi bi-copy"></i>
                    </button>
                    <button type="button" class="btn btn-sm btn-link p-0 text-primary add-to-panier"
                            onclick="addToPanier(<?= $item['id'] ?>)" title="Ajouter au panier">
                        <i class="bi bi-plus-circle"></i>
                    </button>
                <?php else: ?>
                    <span class="item-prix-badge"></span>
                    <button type="button" class="btn btn-sm btn-link p-0 text-success" onclick="addItem(<?= $item['id'] ?>, 'folder')" title="Sous-dossier">
                        <i class="bi bi-folder-plus"></i>
                    </button>
                    <button type="button" class="btn btn-sm btn-link p-0 text-warning" onclick="duplicateItem(<?= $item['id'] ?>)" title="Dupliquer">
                        <i class="bi bi-copy"></i>
                    </button>
                    <button type="button" class="btn btn-sm btn-link p-0 text-primary" onclick="addItem(<?= $item['id'] ?>, 'item')" title="Item">
                        <i class="bi bi-plus-circle"></i>
                    </button>
                <?php endif; ?>
                <button type="button" class="btn btn-sm btn-link p-0 text-danger"
                        onclick="deleteItem(<?= $item['id'] ?>)" title="Supprimer">
                    <i class="bi bi-trash"></i>
                </button>
            </span>
        </div>

        <?php if ($isFolder && $hasChildren): ?>
            <div class="folder-children" data-parent="<?= $item['id'] ?>">
                <?php renderSectionItems($item['children'], $level + 1); ?>
            </div>
        <?php endif; ?>
    <?php
    endforeach;
}

/**
 * Afficher l'arbre du catalogue récursivement (ancienne méthode)
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

            <span class="item-actions">
                <?php if (!$isFolder): ?>
                    <span class="badge bg-secondary item-prix-badge"><?= formatMoney($item['prix']) ?></span>
                    <button type="button" class="btn btn-sm btn-link p-0 text-info"
                            onclick="openItemModal(<?= $item['id'] ?>)" title="Modifier">
                        <i class="bi bi-pencil"></i>
                    </button>
                    <button type="button" class="btn btn-sm btn-link p-0 text-warning"
                            onclick="duplicateItem(<?= $item['id'] ?>)" title="Dupliquer">
                        <i class="bi bi-copy"></i>
                    </button>
                    <button type="button" class="btn btn-sm btn-link p-0 text-primary add-to-panier"
                            onclick="addToPanier(<?= $item['id'] ?>)" title="Ajouter au panier">
                        <i class="bi bi-plus-circle"></i>
                    </button>
                <?php else: ?>
                    <span class="item-prix-badge"></span>
                    <button type="button" class="btn btn-sm btn-link p-0 text-success" onclick="addItem(<?= $item['id'] ?>, 'folder')" title="Sous-dossier">
                        <i class="bi bi-folder-plus"></i>
                    </button>
                    <button type="button" class="btn btn-sm btn-link p-0 text-warning" onclick="duplicateItem(<?= $item['id'] ?>)" title="Dupliquer">
                        <i class="bi bi-copy"></i>
                    </button>
                    <button type="button" class="btn btn-sm btn-link p-0 text-primary" onclick="addItem(<?= $item['id'] ?>, 'item')" title="Item">
                        <i class="bi bi-plus-circle"></i>
                    </button>
                <?php endif; ?>
                <button type="button" class="btn btn-sm btn-link p-0 text-danger"
                        onclick="deleteItem(<?= $item['id'] ?>)" title="Supprimer">
                    <i class="bi bi-trash"></i>
                </button>
            </span>
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
                <span class="item-qte-controls d-flex align-items-center">
                    <button type="button" class="qte-btn qte-minus" onclick="changeQte(<?= $item['id'] ?>, -1)">−</button>
                    <span class="item-qte" data-id="<?= $item['id'] ?>"><?= $item['quantite'] ?? 1 ?></span>
                    <button type="button" class="qte-btn qte-plus" onclick="changeQte(<?= $item['id'] ?>, 1)">+</button>
                </span>
                <span class="badge bg-secondary item-prix"
                      data-id="<?= $item['id'] ?>"
                      data-prix="<?= $item['prix'] ?? $item['catalogue_prix'] ?? 0 ?>"
                      ondblclick="editPanierPrice(this)"
                      style="cursor: pointer;"
                      title="Double-clic pour modifier"><?= formatMoney($item['prix'] ?? $item['catalogue_prix'] ?? 0) ?></span>
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
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-file-earmark-text me-2"></i>Document de commande</h5>
                <div class="ms-auto me-3 d-flex gap-2">
                    <div class="form-check form-check-inline mb-0">
                        <input type="checkbox" class="form-check-input" id="groupByEtape" value="etape" checked>
                        <label class="form-check-label text-white" for="groupByEtape">
                            <i class="bi bi-list-ol me-1"></i>Étape
                        </label>
                    </div>
                    <div class="form-check form-check-inline mb-0">
                        <input type="checkbox" class="form-check-input" id="groupByFournisseur" value="fournisseur">
                        <label class="form-check-label text-white" for="groupByFournisseur">
                            <i class="bi bi-shop me-1"></i>Fournisseur
                        </label>
                    </div>
                </div>
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
    <div class="modal-dialog modal-dialog-centered">
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
                    <select class="form-select" id="item-modal-fournisseur">
                        <option value="">-- Aucun fournisseur --</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label d-flex justify-content-between align-items-center">
                        <span>Étape</span>
                        <button type="button" class="btn btn-link btn-sm p-0" onclick="openEtapesModal()">
                            <i class="bi bi-gear"></i> Gérer
                        </button>
                    </label>
                    <select class="form-select" id="item-modal-etape">
                        <option value="">-- Aucune étape --</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Lien d'achat</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-link-45deg"></i></span>
                        <input type="url" class="form-control" id="item-modal-lien" placeholder="https://...">
                        <button type="button" class="btn btn-outline-primary" id="item-modal-fetch-price" title="Vérifier le prix avec IA">
                            <i class="bi bi-robot"></i>
                        </button>
                        <button type="button" class="btn btn-outline-secondary" id="item-modal-open-link" title="Ouvrir le lien">
                            <i class="bi bi-box-arrow-up-right"></i>
                        </button>
                    </div>
                    <small class="text-muted">Cliquez sur <i class="bi bi-robot"></i> pour récupérer le prix automatiquement</small>
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

<!-- Modal gestion des étapes -->
<div class="modal fade" id="etapesModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-list-ol me-2"></i>Gérer les étapes</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <div class="input-group">
                        <input type="text" class="form-control" id="new-etape-nom" placeholder="Nouvelle étape...">
                        <button type="button" class="btn btn-success" onclick="addEtape()">
                            <i class="bi bi-plus-lg"></i>
                        </button>
                    </div>
                </div>
                <div id="etapes-list" class="list-group">
                    <!-- Liste des étapes -->
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fermer</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal édition dossier -->
<div class="modal fade" id="folderModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-folder-fill text-warning me-2"></i>Modifier le dossier</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="folder-modal-id">
                <div class="mb-3">
                    <label class="form-label">Nom</label>
                    <input type="text" class="form-control" id="folder-modal-nom">
                </div>
                <div class="mb-3">
                    <label class="form-label d-flex justify-content-between align-items-center">
                        <span>Étape</span>
                        <button type="button" class="btn btn-link btn-sm p-0" onclick="openEtapesModal()">
                            <i class="bi bi-gear"></i> Gérer
                        </button>
                    </label>
                    <select class="form-select" id="folder-modal-etape">
                        <option value="">-- Aucune étape --</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                <button type="button" class="btn btn-primary" onclick="saveFolderModal()">
                    <i class="bi bi-check-lg me-1"></i>Enregistrer
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal ajout item/dossier -->
<div class="modal fade" id="addItemModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addItemModalTitle"><i class="bi bi-plus-circle me-2"></i>Ajouter</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="add-item-parent-id">
                <input type="hidden" id="add-item-type">
                <input type="hidden" id="add-item-etape-id">
                <div class="mb-3">
                    <label class="form-label">Nom</label>
                    <input type="text" class="form-control" id="add-item-nom" placeholder="Entrez le nom...">
                </div>
                <div class="mb-3" id="add-item-prix-group" style="display: none;">
                    <label class="form-label">Prix</label>
                    <input type="number" class="form-control" id="add-item-prix" value="0" step="0.01" min="0">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                <button type="button" class="btn btn-primary" onclick="saveAddItemModal()">
                    <i class="bi bi-check-lg me-1"></i>Ajouter
                </button>
            </div>
        </div>
    </div>
</div>

<script src="<?= url('/modules/budget-builder/assets/budget.js') ?>?v=<?= time() ?>"></script>
<script>
    // Initialiser avec l'ID du projet
    BudgetBuilder.init(<?= $projetId ?? 'null' ?>);

    // Contrôle de la taille du texte
    const FONT_SIZE_KEY = 'budget-builder-font-size';
    const DEFAULT_FONT_SIZE = 0.95;
    const MIN_FONT_SIZE = 0.75;
    const MAX_FONT_SIZE = 1.4;
    const FONT_STEP = 0.05;

    function getCurrentFontSize() {
        return parseFloat(localStorage.getItem(FONT_SIZE_KEY)) || DEFAULT_FONT_SIZE;
    }

    function applyFontSize(size) {
        document.querySelector('.budget-builder-container').style.setProperty('--item-font-size', size + 'rem');
        document.querySelectorAll('.catalogue-item, .panier-item, .panier-section-header').forEach(el => {
            el.style.fontSize = size + 'rem';
        });
    }

    function changeFontSize(direction) {
        let size = getCurrentFontSize() + (direction * FONT_STEP);
        size = Math.max(MIN_FONT_SIZE, Math.min(MAX_FONT_SIZE, size));
        localStorage.setItem(FONT_SIZE_KEY, size);
        applyFontSize(size);
    }

    function resetFontSize() {
        localStorage.setItem(FONT_SIZE_KEY, DEFAULT_FONT_SIZE);
        applyFontSize(DEFAULT_FONT_SIZE);
    }

    // Appliquer la taille sauvegardée au chargement
    applyFontSize(getCurrentFontSize());

    // Modal item
    let itemModal = null;

    function openItemModal(itemId) {
        if (!itemModal) {
            itemModal = new bootstrap.Modal(document.getElementById('itemModal'));
        }

        // Charger les fournisseurs, étapes et les données de l'item en parallèle
        Promise.all([
            BudgetBuilder.ajax('get_fournisseurs', {}),
            BudgetBuilder.ajax('get_etapes', {}),
            BudgetBuilder.ajax('get_item', { id: itemId })
        ]).then(([fournisseursResp, etapesResp, itemResp]) => {
            // Remplir le select des fournisseurs
            if (fournisseursResp.success && fournisseursResp.fournisseurs) {
                const select = document.getElementById('item-modal-fournisseur');
                select.innerHTML = '<option value="">-- Aucun fournisseur --</option>' +
                    fournisseursResp.fournisseurs
                        .map(f => `<option value="${escapeHtml(f)}">${escapeHtml(f)}</option>`)
                        .join('');
            }

            // Remplir le select des étapes
            if (etapesResp.success && etapesResp.etapes) {
                const select = document.getElementById('item-modal-etape');
                select.innerHTML = '<option value="">-- Aucune étape --</option>' +
                    etapesResp.etapes.map((e, i) => `<option value="${e.id}">N.${i + 1} ${escapeHtml(e.nom)}</option>`).join('');
            }

            // Remplir les champs de l'item
            if (itemResp.success && itemResp.item) {
                document.getElementById('item-modal-id').value = itemResp.item.id;
                document.getElementById('item-modal-nom').value = itemResp.item.nom || '';
                document.getElementById('item-modal-prix').value = itemResp.item.prix || 0;
                document.getElementById('item-modal-fournisseur').value = itemResp.item.fournisseur || '';
                document.getElementById('item-modal-etape').value = itemResp.item.etape_id || '';
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
            etape_id: document.getElementById('item-modal-etape').value,
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

    // Dupliquer un item
    function duplicateItem(itemId) {
        BudgetBuilder.ajax('duplicate_item', { id: itemId }).then(response => {
            if (response.success) {
                location.reload();
            } else {
                alert('Erreur: ' + (response.message || 'Échec'));
            }
        });
    }

    // ================================
    // MODAL DOSSIER
    // ================================

    let folderModal = null;

    function openFolderModal(folderId) {
        if (!folderModal) {
            folderModal = new bootstrap.Modal(document.getElementById('folderModal'));
        }

        // Charger les étapes et les données du dossier
        Promise.all([
            BudgetBuilder.ajax('get_etapes', {}),
            BudgetBuilder.ajax('get_item', { id: folderId })
        ]).then(([etapesResp, folderResp]) => {
            // Remplir le select des étapes
            if (etapesResp.success && etapesResp.etapes) {
                const select = document.getElementById('folder-modal-etape');
                select.innerHTML = '<option value="">-- Aucune étape --</option>' +
                    etapesResp.etapes.map((e, i) => `<option value="${e.id}">N.${i + 1} ${escapeHtml(e.nom)}</option>`).join('');
            }

            // Remplir les champs du dossier
            if (folderResp.success && folderResp.item) {
                document.getElementById('folder-modal-id').value = folderResp.item.id;
                document.getElementById('folder-modal-nom').value = folderResp.item.nom || '';
                document.getElementById('folder-modal-etape').value = folderResp.item.etape_id || '';
                folderModal.show();
            }
        });
    }

    function saveFolderModal() {
        const id = document.getElementById('folder-modal-id').value;
        const data = {
            id: id,
            nom: document.getElementById('folder-modal-nom').value,
            etape_id: document.getElementById('folder-modal-etape').value
        };

        BudgetBuilder.ajax('update_folder', data).then(response => {
            if (response.success) {
                folderModal.hide();
                location.reload();
            } else {
                alert('Erreur: ' + (response.message || 'Échec'));
            }
        });
    }

    // Enter pour enregistrer le dossier
    document.querySelectorAll('#folderModal input, #folderModal select').forEach(el => {
        el.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                saveFolderModal();
            }
        });
    });

    // ================================
    // ÉTAPES
    // ================================

    let etapesModal = null;

    function openEtapesModal() {
        if (!etapesModal) {
            etapesModal = new bootstrap.Modal(document.getElementById('etapesModal'));
        }
        loadEtapes();
        etapesModal.show();
    }

    function loadEtapes() {
        BudgetBuilder.ajax('get_etapes', {}).then(response => {
            if (response.success && response.etapes) {
                renderEtapesList(response.etapes);
            }
        });
    }

    function renderEtapesList(etapes) {
        const container = document.getElementById('etapes-list');
        if (etapes.length === 0) {
            container.innerHTML = '<div class="text-center text-muted py-3">Aucune étape définie</div>';
            return;
        }

        container.innerHTML = etapes.map((etape, index) => `
            <div class="list-group-item d-flex align-items-center gap-2 etape-drag-item"
                 data-id="${etape.id}" draggable="true" style="cursor: grab;">
                <i class="bi bi-grip-vertical text-muted"></i>
                <span class="badge bg-secondary etape-numero">N.${index + 1}</span>
                <span class="flex-grow-1 etape-nom" style="cursor: pointer;" ondblclick="editEtapeName(this, ${etape.id})" title="Double-clic pour modifier">${escapeHtml(etape.nom)}</span>
                <button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteEtape(${etape.id})">
                    <i class="bi bi-trash"></i>
                </button>
            </div>
        `).join('');

        // Initialiser le drag & drop
        initEtapesDragDrop();
    }

    function addEtape() {
        const input = document.getElementById('new-etape-nom');
        const nom = input.value.trim();
        if (!nom) return;

        BudgetBuilder.ajax('add_etape', { nom: nom }).then(response => {
            if (response.success) {
                input.value = '';
                loadEtapes();
            } else {
                alert('Erreur: ' + (response.message || 'Échec'));
            }
        });
    }

    function deleteEtape(id) {
        if (!confirm('Supprimer cette étape?')) return;

        BudgetBuilder.ajax('delete_etape', { id: id }).then(response => {
            if (response.success) {
                loadEtapes();
            } else {
                alert('Erreur: ' + (response.message || 'Échec'));
            }
        });
    }

    let draggedEtape = null;

    function initEtapesDragDrop() {
        const items = document.querySelectorAll('.etape-drag-item');

        items.forEach(item => {
            item.addEventListener('dragstart', function(e) {
                draggedEtape = this;
                this.style.opacity = '0.5';
                e.dataTransfer.effectAllowed = 'move';
            });

            item.addEventListener('dragend', function() {
                this.style.opacity = '1';
                draggedEtape = null;
                document.querySelectorAll('.etape-drag-item').forEach(el => {
                    el.classList.remove('drag-over');
                });
            });

            item.addEventListener('dragover', function(e) {
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
                if (this !== draggedEtape) {
                    this.classList.add('drag-over');
                }
            });

            item.addEventListener('dragleave', function() {
                this.classList.remove('drag-over');
            });

            item.addEventListener('drop', function(e) {
                e.preventDefault();
                this.classList.remove('drag-over');

                if (draggedEtape && this !== draggedEtape) {
                    const container = document.getElementById('etapes-list');
                    const allItems = [...container.querySelectorAll('.etape-drag-item')];
                    const draggedIndex = allItems.indexOf(draggedEtape);
                    const targetIndex = allItems.indexOf(this);

                    if (draggedIndex < targetIndex) {
                        this.parentNode.insertBefore(draggedEtape, this.nextSibling);
                    } else {
                        this.parentNode.insertBefore(draggedEtape, this);
                    }

                    // Mettre à jour les numéros et sauvegarder
                    updateEtapeNumbers();
                    saveEtapesOrder();
                }
            });
        });
    }

    function updateEtapeNumbers() {
        const items = document.querySelectorAll('.etape-drag-item');
        items.forEach((item, index) => {
            const badge = item.querySelector('.etape-numero');
            if (badge) badge.textContent = `N.${index + 1}`;
        });
    }

    function saveEtapesOrder() {
        const items = document.querySelectorAll('.etape-drag-item');
        const ordre = [...items].map(item => parseInt(item.dataset.id));

        BudgetBuilder.ajax('reorder_etapes', { ordre: ordre }).then(response => {
            if (!response.success) {
                alert('Erreur lors de la sauvegarde');
                loadEtapes();
            }
        });
    }

    function editEtapeName(element, etapeId) {
        const currentName = element.textContent.trim();
        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'form-control form-control-sm';
        input.value = currentName;

        element.innerHTML = '';
        element.appendChild(input);
        input.focus();
        input.select();

        const save = () => {
            const newName = input.value.trim();
            if (newName && newName !== currentName) {
                BudgetBuilder.ajax('update_etape', { id: etapeId, nom: newName }).then(response => {
                    if (response.success) {
                        element.textContent = newName;
                    } else {
                        element.textContent = currentName;
                        alert('Erreur: ' + (response.message || 'Échec'));
                    }
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
    }

    // Enter pour ajouter étape
    document.getElementById('new-etape-nom').addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            addEtape();
        }
    });

    // Enter pour enregistrer l'item
    document.querySelectorAll('#itemModal input, #itemModal select').forEach(el => {
        el.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                saveItemModal();
            }
        });
    });

    // Édition du prix dans le panier
    function editPanierPrice(element) {
        const itemId = element.dataset.id;
        const currentPrice = parseFloat(element.dataset.prix) || 0;

        const input = document.createElement('input');
        input.type = 'number';
        input.className = 'form-control form-control-sm';
        input.style.width = '80px';
        input.value = currentPrice;
        input.step = '0.01';
        input.min = '0';

        const originalHtml = element.innerHTML;
        element.innerHTML = '';
        element.appendChild(input);
        input.focus();
        input.select();

        const save = () => {
            const newPrice = parseFloat(input.value) || 0;
            BudgetBuilder.ajax('update_panier_price', { id: itemId, prix: newPrice }).then(response => {
                if (response.success) {
                    element.dataset.prix = newPrice;
                    element.innerHTML = formatMoney(newPrice);
                    // Mettre à jour le total
                    const row = element.closest('.panier-item');
                    const qte = parseInt(row.querySelector('.item-qte').value) || 1;
                    const totalBadge = row.querySelector('.item-total');
                    if (totalBadge) {
                        totalBadge.innerHTML = formatMoney(newPrice * qte);
                    }
                    BudgetBuilder.updateTotals();
                } else {
                    element.innerHTML = originalHtml;
                    alert('Erreur: ' + (response.message || 'Échec'));
                }
            });
        };

        input.addEventListener('blur', save);
        input.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                save();
            } else if (e.key === 'Escape') {
                element.innerHTML = originalHtml;
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

    // Récupérer le prix depuis l'URL avec IA
    document.getElementById('item-modal-fetch-price').addEventListener('click', function() {
        const lien = document.getElementById('item-modal-lien').value;
        if (!lien) {
            alert('Veuillez entrer un lien d\'achat d\'abord');
            return;
        }

        const btn = this;
        const originalHtml = btn.innerHTML;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
        btn.disabled = true;

        BudgetBuilder.ajax('fetch_price_from_url', { url: lien }).then(response => {
            btn.innerHTML = originalHtml;
            btn.disabled = false;

            if (response.success && response.price) {
                const priceInput = document.getElementById('item-modal-prix');
                const oldPrice = parseFloat(priceInput.value) || 0;
                const newPrice = response.price;

                if (oldPrice !== newPrice) {
                    priceInput.value = newPrice;
                    priceInput.style.backgroundColor = '#d4edda';
                    setTimeout(() => priceInput.style.backgroundColor = '', 2000);
                    alert('Prix trouvé: ' + formatMoney(newPrice));
                } else {
                    alert('Le prix est déjà à jour: ' + formatMoney(newPrice));
                }
            } else {
                alert('Erreur: ' + (response.message || 'Prix non trouvé'));
            }
        }).catch(err => {
            btn.innerHTML = originalHtml;
            btn.disabled = false;
            alert('Erreur de connexion');
        });
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

    // Vider le panier
    function clearPanier() {
        if (!confirm('Vider complètement le panier?')) return;

        BudgetBuilder.ajax('clear_panier', { projet_id: BudgetBuilder.projetId }).then(response => {
            if (response.success) {
                location.reload();
            } else {
                alert('Erreur: ' + (response.message || 'Échec'));
            }
        });
    }

    // Toggle section du panier
    function togglePanierSection(header) {
        const content = header.nextElementSibling;
        const toggle = header.querySelector('.section-toggle');
        if (content.style.display === 'none') {
            content.style.display = '';
            toggle.classList.remove('bi-caret-right-fill');
            toggle.classList.add('bi-caret-down-fill');
        } else {
            content.style.display = 'none';
            toggle.classList.remove('bi-caret-down-fill');
            toggle.classList.add('bi-caret-right-fill');
        }
    }

    // Toggle folder dans le panier
    function togglePanierFolder(toggle) {
        const item = toggle.closest('.panier-item');
        const children = item.nextElementSibling;
        if (children && children.classList.contains('panier-folder-children')) {
            const icon = toggle.querySelector('i');
            if (children.style.display === 'none') {
                children.style.display = '';
                icon.classList.remove('bi-caret-right-fill');
                icon.classList.add('bi-caret-down-fill');
            } else {
                children.style.display = 'none';
                icon.classList.remove('bi-caret-down-fill');
                icon.classList.add('bi-caret-right-fill');
            }
        }
    }

    // Changer la quantité avec +/-
    function changeQte(itemId, delta) {
        const qteEl = document.querySelector(`.item-qte[data-id="${itemId}"]`);
        if (!qteEl) return;

        let newQte = parseInt(qteEl.textContent) + delta;
        if (newQte < 1) newQte = 1;

        qteEl.textContent = newQte;

        BudgetBuilder.ajax('update_panier_quantity', { id: itemId, quantite: newQte }).then(response => {
            if (response.success) {
                BudgetBuilder.updateTotals();
            }
        });
    }

    // Supprimer du panier
    function removeFromPanier(itemId) {
        if (!confirm('Supprimer cet élément?')) return;

        BudgetBuilder.ajax('remove_from_panier', { id: itemId }).then(response => {
            if (response.success) {
                location.reload();
            } else {
                alert('Erreur: ' + (response.message || 'Échec'));
            }
        });
    }

    // Modal commande
    let orderModal = null;

    function getGroupByOptions() {
        return {
            etape: document.getElementById('groupByEtape').checked,
            fournisseur: document.getElementById('groupByFournisseur').checked
        };
    }

    function openOrderModal() {
        if (!orderModal) {
            orderModal = new bootstrap.Modal(document.getElementById('orderModal'));

            // Écouter les changements de groupement
            document.getElementById('groupByEtape').addEventListener('change', loadOrderItems);
            document.getElementById('groupByFournisseur').addEventListener('change', loadOrderItems);
        }

        loadOrderItems();
        orderModal.show();
    }

    function loadOrderItems() {
        const options = getGroupByOptions();

        // Déterminer quelle action appeler
        let action = 'get_order_items_combined';

        BudgetBuilder.ajax(action, {
            projet_id: BudgetBuilder.projetId,
            group_etape: options.etape,
            group_fournisseur: options.fournisseur
        }).then(response => {
            if (response.success && response.grouped) {
                renderOrderContent(response.grouped, options);
            }
        });
    }

    function renderOrderContent(grouped, options = {}) {
        const container = document.getElementById('order-content');
        let html = '';
        let totalItems = 0;
        let checkedItems = 0;

        // Trier les groupes (Non spécifié en dernier)
        const groups = Object.keys(grouped).sort((a, b) => {
            if (a === 'Non spécifié') return 1;
            if (b === 'Non spécifié') return -1;
            return a.localeCompare(b);
        });

        // Déterminer l'icône principale
        const primaryIcon = options.etape ? 'bi-list-ol' : 'bi-shop';

        for (const groupName of groups) {
            const groupData = grouped[groupName];
            let groupTotal = 0;

            // Vérifier si c'est une structure hiérarchique (sous-groupes) ou une liste d'items
            const hasSubgroups = groupData.subgroups !== undefined;

            html += `<div class="order-group mb-4">
                <h5 class="border-bottom pb-2 mb-3" style="background: #f8f9fa; margin: -0.5rem -0.5rem 1rem; padding: 0.75rem;">
                    <i class="bi ${primaryIcon} me-2"></i>${escapeHtml(groupName)}
                </h5>`;

            if (hasSubgroups) {
                // Structure hiérarchique: étape > fournisseur
                const subgroupNames = Object.keys(groupData.subgroups).sort((a, b) => {
                    if (a === 'Non spécifié') return 1;
                    if (b === 'Non spécifié') return -1;
                    return a.localeCompare(b);
                });

                for (const subName of subgroupNames) {
                    const items = groupData.subgroups[subName];
                    let subTotal = 0;

                    html += `<div class="ms-3 mb-3">
                        <h6 class="text-muted mb-2"><i class="bi bi-shop me-1"></i>${escapeHtml(subName)} <span class="badge bg-secondary">${items.length}</span></h6>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover mb-0">
                                <tbody>`;

                    for (const item of items) {
                        totalItems++;
                        const isChecked = item.commande == 1;
                        if (isChecked) checkedItems++;

                        const total = (parseFloat(item.prix) || 0) * (parseInt(item.quantite) || 1);
                        subTotal += total;
                        groupTotal += total;

                        const linkHtml = item.lien_achat
                            ? `<a href="${escapeHtml(item.lien_achat)}" target="_blank" class="text-primary"><i class="bi bi-box-arrow-up-right"></i></a>`
                            : '';

                        html += `<tr class="${isChecked ? 'table-success' : ''}">
                            <td style="width:40px;" class="text-center text-nowrap">
                                <input type="checkbox" class="form-check-input order-check me-1"
                                       data-id="${item.id}" ${isChecked ? 'checked' : ''}
                                       onchange="toggleOrderItem(${item.id}, this.checked)">${linkHtml}
                            </td>
                            <td class="${isChecked ? 'text-decoration-line-through text-muted' : ''}">${escapeHtml(item.nom)}</td>
                            <td style="width:60px;" class="text-center">${item.quantite}</td>
                            <td style="width:80px;" class="text-end">${formatMoney(item.prix)}</td>
                            <td style="width:90px;" class="text-end fw-bold">${formatMoney(total)}</td>
                        </tr>`;
                    }

                    html += `</tbody></table></div></div>`;
                }
            } else {
                // Liste simple d'items
                const items = groupData.items || groupData;
                html += `<div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <tbody>`;

                for (const item of items) {
                    totalItems++;
                    const isChecked = item.commande == 1;
                    if (isChecked) checkedItems++;

                    const total = (parseFloat(item.prix) || 0) * (parseInt(item.quantite) || 1);
                    groupTotal += total;

                    const linkHtml = item.lien_achat
                        ? `<a href="${escapeHtml(item.lien_achat)}" target="_blank" class="text-primary"><i class="bi bi-box-arrow-up-right"></i></a>`
                        : '';

                    html += `<tr class="${isChecked ? 'table-success' : ''}">
                        <td style="width:40px;" class="text-center text-nowrap">
                            <input type="checkbox" class="form-check-input order-check me-1"
                                   data-id="${item.id}" ${isChecked ? 'checked' : ''}
                                   onchange="toggleOrderItem(${item.id}, this.checked)">${linkHtml}
                        </td>
                        <td class="${isChecked ? 'text-decoration-line-through text-muted' : ''}">${escapeHtml(item.nom)}</td>
                        <td style="width:60px;" class="text-center">${item.quantite}</td>
                        <td style="width:80px;" class="text-end">${formatMoney(item.prix)}</td>
                        <td style="width:90px;" class="text-end fw-bold">${formatMoney(total)}</td>
                    </tr>`;
                }

                html += `</tbody></table></div>`;
            }

            html += `<div class="text-end mt-2 fw-bold text-success">Sous-total: ${formatMoney(groupTotal)}</div></div>`;
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

    // ================================
    // MODAL AJOUT ITEM/DOSSIER
    // ================================

    let addItemModal = null;

    function openAddItemModal(parentId, type, etapeId = null) {
        if (!addItemModal) {
            addItemModal = new bootstrap.Modal(document.getElementById('addItemModal'));
        }

        // Réinitialiser les champs
        document.getElementById('add-item-parent-id').value = parentId || '';
        document.getElementById('add-item-type').value = type;
        document.getElementById('add-item-etape-id').value = etapeId || '';
        document.getElementById('add-item-nom').value = '';
        document.getElementById('add-item-prix').value = '0';

        // Mettre à jour le titre et afficher/cacher le prix
        const titleEl = document.getElementById('addItemModalTitle');
        const prixGroup = document.getElementById('add-item-prix-group');

        if (type === 'folder') {
            titleEl.innerHTML = '<i class="bi bi-folder-plus me-2 text-warning"></i>Nouveau dossier';
            prixGroup.style.display = 'none';
        } else {
            titleEl.innerHTML = '<i class="bi bi-box-seam me-2 text-primary"></i>Nouvel item';
            prixGroup.style.display = 'block';
        }

        addItemModal.show();

        // Focus sur le champ nom
        setTimeout(() => document.getElementById('add-item-nom').focus(), 300);
    }

    function saveAddItemModal() {
        const parentId = document.getElementById('add-item-parent-id').value || null;
        const type = document.getElementById('add-item-type').value;
        const etapeId = document.getElementById('add-item-etape-id').value || null;
        const nom = document.getElementById('add-item-nom').value.trim();
        const prix = parseFloat(document.getElementById('add-item-prix').value) || 0;

        if (!nom) {
            alert('Veuillez entrer un nom');
            document.getElementById('add-item-nom').focus();
            return;
        }

        BudgetBuilder.ajax('add_catalogue_item', {
            parent_id: parentId,
            type: type,
            nom: nom,
            prix: prix,
            etape_id: etapeId
        }).then(response => {
            if (response.success) {
                addItemModal.hide();
                location.reload();
            } else {
                alert('Erreur: ' + (response.message || 'Échec'));
            }
        });
    }

    // Enter pour enregistrer
    document.querySelectorAll('#addItemModal input').forEach(el => {
        el.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                saveAddItemModal();
            }
        });
    });
</script>
