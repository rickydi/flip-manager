<?php
/**
 * Budget Builder - Interface Drag & Drop (Style identique à Templates)
 * Inclus dans detail.php, onglet Budgets
 * Affichage récursif des sous-catégories et matériaux
 */

// Récupérer les templates en structure arbre pour le catalogue
$catalogueData = [];
foreach ($templatesBudgets as $catId => $cat) {
    $groupe = $cat['groupe'];
    if (!isset($catalogueData[$groupe])) {
        $catalogueData[$groupe] = [
            'label' => $groupeLabels[$groupe] ?? ucfirst($groupe),
            'categories' => []
        ];
    }
    $catalogueData[$groupe]['categories'][$catId] = $cat;
}

/**
 * Compter les matériaux récursivement dans une sous-catégorie
 */
function compterMateriauxRecursif($sousCategories) {
    $count = 0;
    $total = 0;
    foreach ($sousCategories as $sc) {
        foreach ($sc['materiaux'] ?? [] as $mat) {
            $count++;
            $qte = $mat['quantite_defaut'] ?? 1;
            $total += ($mat['prix_defaut'] ?? 0) * $qte;
        }
        if (!empty($sc['enfants'])) {
            $sub = compterMateriauxRecursif($sc['enfants']);
            $count += $sub['count'];
            $total += $sub['total'];
        }
    }
    return ['count' => $count, 'total' => $total];
}

/**
 * Afficher les sous-catégories de façon récursive pour le catalogue (drag & drop)
 */
function afficherSousCategoriesRecursifCatalogue($sousCategories, $catId, $catNom, $groupe, $catOrdre = 0, $niveau = 0) {
    if (empty($sousCategories)) return;

    foreach ($sousCategories as $scIndex => $sc):
        $hasEnfants = !empty($sc['enfants']);
        $hasMateriaux = !empty($sc['materiaux']);
        $isKit = $hasEnfants || $hasMateriaux;
        $uniqueId = 'cat' . $catId . '_sc' . $sc['id'];
        $scOrdre = $sc['ordre'] ?? $scIndex;

        // Calculer totaux pour ce noeud
        $nbItems = count($sc['materiaux'] ?? []);
        $totalSc = 0;
        foreach ($sc['materiaux'] ?? [] as $mat) {
            $totalSc += ($mat['prix_defaut'] ?? 0) * ($mat['quantite_defaut'] ?? 1);
        }
        if ($hasEnfants) {
            $sub = compterMateriauxRecursif($sc['enfants']);
            $nbItems += $sub['count'];
            $totalSc += $sub['total'];
        }
        ?>
        <div class="tree-item mb-1 <?= $isKit ? 'is-kit' : '' ?>" data-sc-id="<?= $sc['id'] ?>">
            <div class="tree-content catalogue-draggable"
                 draggable="true"
                 data-type="sous_categorie"
                 data-id="<?= $sc['id'] ?>"
                 data-cat-id="<?= $catId ?>"
                 data-cat-ordre="<?= $catOrdre ?>"
                 data-sc-ordre="<?= $scOrdre ?>"
                 data-groupe="<?= $groupe ?>"
                 data-nom="<?= e($sc['nom']) ?>"
                 data-prix="<?= $totalSc ?>">

                <span class="tree-connector">└►</span>
                <i class="bi bi-grip-vertical drag-handle"></i>

                <?php if ($isKit): ?>
                <span class="tree-toggle" onclick="event.stopPropagation(); toggleTreeItem(this, '<?= $uniqueId ?>')">
                    <i class="bi bi-caret-down-fill"></i>
                </span>
                <?php else: ?>
                <span class="tree-toggle" style="visibility: hidden;"><i class="bi bi-caret-down-fill"></i></span>
                <?php endif; ?>

                <div class="type-icon">
                    <i class="bi <?= $hasEnfants ? 'bi-folder-fill text-warning' : 'bi-folder text-warning' ?>"></i>
                </div>

                <strong class="flex-grow-1"><?= e($sc['nom']) ?></strong>

                <?php if ($hasEnfants): ?>
                <span class="badge item-badge text-warning me-1">
                    <i class="bi bi-folder-fill me-1"></i><?= count($sc['enfants']) ?>
                </span>
                <?php endif; ?>

                <?php if ($nbItems > 0): ?>
                <span class="badge item-badge text-info me-1">
                    <i class="bi bi-box-seam me-1"></i><?= $nbItems ?>
                </span>
                <?php endif; ?>

                <?php if ($totalSc > 0): ?>
                <span class="badge item-badge text-success">
                    <?= formatMoney($totalSc) ?>
                </span>
                <?php endif; ?>
            </div>

            <?php if ($isKit): ?>
            <div class="collapse show tree-children" id="<?= $uniqueId ?>">
                <?php // Afficher les matériaux de cette sous-catégorie ?>
                <?php foreach ($sc['materiaux'] ?? [] as $matIndex => $mat):
                    $qte = $mat['quantite_defaut'] ?? 1;
                    $total = ($mat['prix_defaut'] ?? 0) * $qte;
                    $matOrdre = $mat['ordre'] ?? $matIndex;
                ?>
                <div class="tree-content mat-item catalogue-draggable"
                     draggable="true"
                     data-type="materiau"
                     data-id="<?= $mat['id'] ?>"
                     data-sc-id="<?= $sc['id'] ?>"
                     data-sc-nom="<?= e($sc['nom']) ?>"
                     data-sc-ordre="<?= $scOrdre ?>"
                     data-cat-id="<?= $catId ?>"
                     data-cat-nom="<?= e($catNom) ?>"
                     data-cat-ordre="<?= $catOrdre ?>"
                     data-mat-ordre="<?= $matOrdre ?>"
                     data-groupe="<?= $groupe ?>"
                     data-nom="<?= e($mat['nom']) ?>"
                     data-prix="<?= $mat['prix_defaut'] ?? 0 ?>"
                     data-qte="<?= $qte ?>"
                     data-sans-taxe="<?= !empty($mat['sans_taxe']) ? 1 : 0 ?>">

                    <span class="tree-connector">└►</span>
                    <i class="bi bi-grip-vertical drag-handle" style="font-size: 0.85em;"></i>
                    <div class="type-icon"><i class="bi bi-box-seam text-primary small"></i></div>
                    <span class="flex-grow-1 small"><?= e($mat['nom']) ?></span>

                    <span class="badge item-badge text-light me-1">x<?= $qte ?></span>
                    <span class="badge item-badge text-info me-1"><?= formatMoney($mat['prix_defaut'] ?? 0) ?></span>
                    <span class="badge item-badge text-success fw-bold"><?= formatMoney($total) ?></span>
                </div>
                <?php endforeach; ?>

                <?php // Récursion pour les sous-sous-catégories ?>
                <?php if ($hasEnfants): ?>
                    <?php afficherSousCategoriesRecursifCatalogue($sc['enfants'], $catId, $catNom, $groupe, $catOrdre, $niveau + 1); ?>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php
    endforeach;
}

// Calculer totaux actuels du projet
$totalProjetHT = 0;
$totalProjetTaxable = 0;
$totalProjetNonTaxable = 0;

foreach ($projetPostes as $catId => $poste) {
    $groupe = $templatesBudgets[$catId]['groupe'] ?? 'autre';
    $qteGroupe = $projetGroupes[$groupe] ?? 1;
    $qteCat = (int)$poste['quantite'];

    if (isset($templatesBudgets[$catId]['sous_categories'])) {
        foreach ($templatesBudgets[$catId]['sous_categories'] as $sc) {
            foreach ($sc['materiaux'] as $mat) {
                if (isset($projetItems[$catId][$mat['id']])) {
                    $item = $projetItems[$catId][$mat['id']];
                    $prix = (float)$item['prix_unitaire'];
                    $qte = (int)($item['quantite'] ?? 1);
                    $sansTaxe = (int)($item['sans_taxe'] ?? 0);
                    $montant = $prix * $qte * $qteCat * $qteGroupe;

                    if ($sansTaxe) {
                        $totalProjetNonTaxable += $montant;
                    } else {
                        $totalProjetTaxable += $montant;
                    }
                }
            }
        }
    }
}
// Note: Le PHP calcule correctement le HT taxable vs non-taxable ici.
// C'est le JS qui doit être aligné.

$totalProjetHT = $totalProjetTaxable + $totalProjetNonTaxable;
$contingence = $totalProjetHT * ((float)$projet['taux_contingence'] / 100);
// Pas de taxe sur la contingence
$tps = $totalProjetTaxable * 0.05;
$tvq = $totalProjetTaxable * 0.09975;
$grandTotal = $totalProjetHT + $contingence + $tps + $tvq;
?>

<!-- SortableJS pour le Drag & Drop et Styles Communs -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.15.0/Sortable.min.js"></script>
<link rel="stylesheet" href="<?= url('/assets/css/tree-style.css') ?>?v=<?= time() ?>">

<!-- Barre de totaux sticky -->
<div class="bg-primary text-white mb-3 rounded" style="font-size: 0.85rem;">
    <div class="px-3 py-2 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span><i class="bi bi-calculator me-2"></i><strong>Budget Rénovation</strong></span>
        <div class="d-flex gap-3 align-items-center flex-wrap">
            <span>
                <span class="opacity-75">HT:</span>
                <strong id="totalHT"><?= formatMoney($totalProjetHT) ?></strong>
            </span>
            <span>
                <span class="opacity-75">Contingence <?= $projet['taux_contingence'] ?>%:</span>
                <strong id="totalContingence"><?= formatMoney($contingence) ?></strong>
            </span>
            <span class="border-start ps-3">
                <span class="opacity-75">Total:</span>
                <strong class="fs-5" id="grandTotal"><?= formatMoney($grandTotal) ?></strong>
            </span>
        </div>
    </div>
</div>

<!-- Container principal -->
<div class="budget-builder">

    <!-- ========================================
         COLONNE GAUCHE: CATALOGUE TEMPLATES
         ======================================== -->
    <div class="catalogue-panel builder-panel" id="cataloguePanel">
        <div class="panel-header">
            <i class="bi bi-box-seam"></i>
            Catalogue des Templates
            <span class="badge bg-light text-dark ms-auto"><?= count($templatesBudgets) ?> catégories</span>
        </div>
        <div class="panel-content" id="catalogueContent">
            <?php foreach ($catalogueData as $groupe => $groupeData): ?>
            <div class="mb-3">
                <!-- Header du groupe -->
                <div class="groupe-header" onclick="toggleCatalogueGroupe(this)" data-groupe="<?= $groupe ?>">
                    <i class="bi bi-chevron-down collapse-icon"></i>
                    <i class="bi bi-folder-fill text-warning"></i>
                    <span class="flex-grow-1"><?= e($groupeData['label']) ?></span>
                    <span class="badge bg-secondary"><?= count($groupeData['categories']) ?></span>
                </div>

                <!-- Contenu du groupe -->
                <div class="groupe-content" data-groupe="<?= $groupe ?>">
                    <?php foreach ($groupeData['categories'] as $catId => $cat):
                        $hasContent = !empty($cat['sous_categories']);
                        // Calculer totaux récursivement
                        $stats = compterMateriauxRecursif($cat['sous_categories'] ?? []);
                        $totalCat = $stats['total'];
                        $nbItems = $stats['count'];
                        $nbSousCategories = count($cat['sous_categories'] ?? []);
                        $catOrdre = $cat['ordre'] ?? 0;
                    ?>
                    <div class="tree-item mb-1 <?= $hasContent ? 'is-kit' : '' ?>">
                        <div class="tree-content catalogue-draggable"
                             draggable="true"
                             data-type="categorie"
                             data-id="<?= $catId ?>"
                             data-cat-ordre="<?= $catOrdre ?>"
                             data-groupe="<?= $groupe ?>"
                             data-nom="<?= e($cat['nom']) ?>"
                             data-prix="<?= $totalCat ?>">

                            <i class="bi bi-grip-vertical drag-handle"></i>

                            <?php if ($hasContent): ?>
                            <span class="tree-toggle" onclick="event.stopPropagation(); toggleTreeItem(this, 'catContent<?= $catId ?>')">
                                <i class="bi bi-caret-down-fill"></i>
                            </span>
                            <?php else: ?>
                            <span class="tree-toggle" style="visibility: hidden;"><i class="bi bi-caret-down-fill"></i></span>
                            <?php endif; ?>

                            <div class="type-icon">
                                <i class="bi <?= $hasContent ? 'bi-folder-fill text-warning' : 'bi-folder text-warning' ?>"></i>
                            </div>

                            <strong class="flex-grow-1"><?= e($cat['nom']) ?></strong>

                            <?php if ($nbSousCategories > 0): ?>
                            <span class="badge item-badge text-warning me-1">
                                <i class="bi bi-folder-fill me-1"></i><?= $nbSousCategories ?>
                            </span>
                            <?php endif; ?>

                            <?php if ($nbItems > 0): ?>
                            <span class="badge item-badge text-info me-1">
                                <i class="bi bi-box-seam me-1"></i><?= $nbItems ?>
                            </span>
                            <?php endif; ?>

                            <?php if ($totalCat > 0): ?>
                            <span class="badge item-badge text-success">
                                <?= formatMoney($totalCat) ?>
                            </span>
                            <?php endif; ?>
                        </div>

                        <?php if ($hasContent): ?>
                        <div class="collapse show tree-children" id="catContent<?= $catId ?>">
                            <?php // Affichage récursif des sous-catégories ?>
                            <?php afficherSousCategoriesRecursifCatalogue($cat['sous_categories'], $catId, $cat['nom'], $groupe, $catOrdre); ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Splitter -->
    <div class="splitter" id="splitter"></div>

    <!-- ========================================
         COLONNE DROITE: BUDGET DU PROJET
         ======================================== -->
    <div class="projet-panel builder-panel" id="projetPanel">
        <div class="panel-header">
            <div>
                <i class="bi bi-cart3 me-2"></i>
                Budget du Projet
            </div>
            <div class="d-flex align-items-center gap-3">
                <button type="button" class="btn btn-sm btn-outline-danger py-0 px-2" onclick="clearAllBudget()" title="Tout supprimer">
                    <i class="bi bi-trash me-1"></i>Vider
                </button>
                <div class="btn-group btn-group-sm">
                    <button type="button" class="btn btn-light py-0 px-2" id="undoBtn" onclick="undoAction()" disabled title="Annuler (Ctrl+Z)">
                        <i class="bi bi-arrow-counterclockwise"></i>
                    </button>
                    <button type="button" class="btn btn-light py-0 px-2" id="redoBtn" onclick="redoAction()" disabled title="Rétablir (Ctrl+Y)">
                        <i class="bi bi-arrow-clockwise"></i>
                    </button>
                </div>
                <div id="saveStatus" class="small">
                    <span id="saveIdle"><i class="bi bi-cloud-check me-1"></i>Auto-save</span>
                    <span id="saveSaving" class="d-none"><i class="bi bi-arrow-repeat spin me-1"></i>Sauvegarde...</span>
                    <span id="saveSaved" class="d-none"><i class="bi bi-check-circle me-1"></i>Sauvegardé!</span>
                </div>
            </div>
        </div>
        <div class="panel-content" id="projetContent">
            <?php
            $hasAnyItems = false;
            foreach ($groupeLabels as $groupe => $label):
                // Trouver les items de ce groupe dans le projet
                $groupeItems = [];
                foreach ($projetPostes as $catId => $poste) {
                    if (isset($templatesBudgets[$catId]) && $templatesBudgets[$catId]['groupe'] === $groupe) {
                        $groupeItems[$catId] = [
                            'poste' => $poste,
                            'cat' => $templatesBudgets[$catId]
                        ];
                    }
                }
                if (!empty($groupeItems)) $hasAnyItems = true;
            ?>
            <div class="projet-groupe mb-3" data-groupe="<?= $groupe ?>" style="<?= empty($groupeItems) ? 'display:none;' : '' ?>">
                <!-- Header du groupe -->
                <div class="groupe-header">
                    <i class="bi bi-folder-fill text-warning"></i>
                    <span class="flex-grow-1"><?= e($label) ?></span>
                    <div class="qte-controls me-2" onclick="event.stopPropagation();">
                        <span class="small opacity-75 me-1">Qté:</span>
                        <button type="button" class="btn btn-sm btn-outline-light py-0 px-1" onclick="changeGroupeQte('<?= $groupe ?>', -1)">-</button>
                        <input type="number" class="groupe-qte-input" data-groupe="<?= $groupe ?>"
                               value="<?= $projetGroupes[$groupe] ?? 1 ?>" min="1" max="20"
                               onchange="updateGroupeQte('<?= $groupe ?>')">
                        <button type="button" class="btn btn-sm btn-outline-light py-0 px-1" onclick="changeGroupeQte('<?= $groupe ?>', 1)">+</button>
                    </div>
                </div>

                <!-- Zone de drop (items du projet) -->
                <div class="projet-drop-zone p-2" data-groupe="<?= $groupe ?>">
                    <?php foreach ($groupeItems as $catId => $data):
                        $cat = $data['cat'];
                        $poste = $data['poste'];
                        $qteCat = (int)$poste['quantite'];
                        $qteGroupe = $projetGroupes[$groupe] ?? 1;

                        // Calculer le total
                        $catTotal = 0;
                        $nbItemsCat = 0;
                        foreach ($cat['sous_categories'] ?? [] as $sc) {
                            foreach ($sc['materiaux'] ?? [] as $mat) {
                                if (isset($projetItems[$catId][$mat['id']])) {
                                    $item = $projetItems[$catId][$mat['id']];
                                    $catTotal += (float)$item['prix_unitaire'] * (int)$item['quantite'] * $qteCat * $qteGroupe;
                                    $nbItemsCat++;
                                }
                            }
                        }
                    ?>
                    <div class="tree-item mb-1 is-kit projet-item"
                         data-type="categorie"
                         data-id="<?= $catId ?>"
                         data-cat-ordre="<?= $cat['ordre'] ?? 0 ?>"
                         data-groupe="<?= $groupe ?>"
                         data-prix="<?= $catTotal ?>">
                        <div class="tree-content">
                            <i class="bi bi-grip-vertical drag-handle"></i>

                            <span class="tree-toggle" onclick="toggleTreeItem(this, 'projet<?= $catId ?>')">
                                <i class="bi bi-caret-down-fill"></i>
                            </span>

                            <div class="type-icon">
                                <i class="bi bi-folder-fill text-warning"></i>
                            </div>

                            <strong class="flex-grow-1"><?= e($cat['nom']) ?></strong>

                            <span class="badge item-badge badge-count text-info me-1">
                                <i class="bi bi-box-seam me-1"></i><?= $nbItemsCat ?>
                            </span>

                            <span class="badge item-badge badge-total text-success fw-bold cat-total me-1" data-cat-id="<?= $catId ?>">
                                <?= formatMoney($catTotal * 1.14975) ?>
                            </span>

                            <!-- Quantité catégorie (+/-) -->
                            <div class="btn-group btn-group-sm me-2">
                                <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-1 cat-qte-btn" data-cat-id="<?= $catId ?>" data-action="minus">
                                    <i class="bi bi-dash"></i>
                                </button>
                                <span class="badge item-badge badge-qte text-light d-flex align-items-center px-2 cat-qte-display" data-cat-id="<?= $catId ?>"><?= $qteCat ?></span>
                                <input type="hidden" class="cat-qte-input" data-cat-id="<?= $catId ?>" value="<?= $qteCat ?>">
                                <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-1 cat-qte-btn" data-cat-id="<?= $catId ?>" data-action="plus">
                                    <i class="bi bi-plus"></i>
                                </button>
                            </div>

                            <button type="button" class="btn btn-sm btn-link text-danger p-0" onclick="removeProjetItem(this)" title="Retirer">
                                <i class="bi bi-x-lg"></i>
                            </button>
                        </div>

                        <!-- Détail des items - avec sous-catégories -->
                        <div class="collapse show tree-children" id="projetContent<?= $catId ?>">
                            <?php foreach ($cat['sous_categories'] ?? [] as $sc):
                                // Vérifier si cette sous-catégorie a des items dans le projet
                                $scHasItems = false;
                                $scTotal = 0;
                                $scItemCount = 0;
                                foreach ($sc['materiaux'] ?? [] as $mat) {
                                    if (isset($projetItems[$catId][$mat['id']])) {
                                        $scHasItems = true;
                                        $item = $projetItems[$catId][$mat['id']];
                                        $scTotal += (float)$item['prix_unitaire'] * (int)$item['quantite'];
                                        $scItemCount++;
                                    }
                                }
                                if (!$scHasItems) continue;
                            ?>
                            <!-- Sous-catégorie container -->
                            <div class="tree-item mb-1 is-kit projet-item"
                                 data-type="sous_categorie"
                                 data-id="<?= $sc['id'] ?>"
                                 data-sc-ordre="<?= $sc['ordre'] ?? 0 ?>"
                                 data-cat-id="<?= $catId ?>"
                                 data-unique-id="sous_categorie-<?= $sc['id'] ?>"
                                 data-groupe="<?= $groupe ?>"
                                 data-prix="<?= $scTotal ?>">
                                <div class="tree-content">
                                    <span class="tree-connector">└►</span>
                                    <i class="bi bi-grip-vertical drag-handle"></i>
                                    <span class="tree-toggle" onclick="toggleTreeItem(this, 'projetSc<?= $sc['id'] ?>')">
                                        <i class="bi bi-caret-down-fill"></i>
                                    </span>
                                    <div class="type-icon">
                                        <i class="bi bi-folder text-warning"></i>
                                    </div>
                                    <strong class="flex-grow-1"><?= e($sc['nom']) ?></strong>

                                    <span class="badge item-badge badge-count text-info me-1">
                                        <i class="bi bi-box-seam me-1"></i><span class="item-count"><?= $scItemCount ?></span>
                                    </span>

                                    <span class="badge item-badge badge-total text-success fw-bold cat-total me-1" data-cat-id="<?= $sc['id'] ?>">
                                        <?= formatMoney($scTotal * $qteCat * $qteGroupe * 1.14975) ?>
                                    </span>

                                    <div class="btn-group btn-group-sm me-2">
                                        <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-1 cat-qte-btn" data-cat-id="<?= $sc['id'] ?>" data-action="minus">
                                            <i class="bi bi-dash"></i>
                                        </button>
                                        <span class="badge item-badge badge-qte text-light d-flex align-items-center px-2 cat-qte-display" data-cat-id="<?= $sc['id'] ?>">1</span>
                                        <input type="hidden" class="cat-qte-input" data-cat-id="<?= $sc['id'] ?>" value="1">
                                        <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-1 cat-qte-btn" data-cat-id="<?= $sc['id'] ?>" data-action="plus">
                                            <i class="bi bi-plus"></i>
                                        </button>
                                    </div>

                                    <button type="button" class="btn btn-sm btn-link text-danger p-0" onclick="removeProjetItem(this)" title="Retirer">
                                        <i class="bi bi-x-lg"></i>
                                    </button>
                                </div>

                                <!-- Matériaux de la sous-catégorie -->
                                <div class="collapse show tree-children" id="projetSc<?= $sc['id'] ?>">
                                    <?php foreach ($sc['materiaux'] ?? [] as $mat):
                                        if (!isset($projetItems[$catId][$mat['id']])) continue;
                                        $item = $projetItems[$catId][$mat['id']];
                                        $qteItem = (int)$item['quantite'];
                                        $prixItem = (float)$item['prix_unitaire'];
                                        $totalItem = $prixItem * $qteItem * $qteCat * $qteGroupe;
                                    ?>
                                    <div class="tree-content mat-item projet-mat-item"
                                         data-mat-id="<?= $mat['id'] ?>"
                                         data-mat-ordre="<?= $mat['ordre'] ?? 0 ?>"
                                         data-cat-id="<?= $sc['id'] ?>"
                                         data-prix="<?= $prixItem ?>"
                                         data-qte="<?= $qteItem ?>"
                                         data-sans-taxe="<?= !empty($item['sans_taxe']) ? 1 : 0 ?>">
                                        <span class="tree-connector">└►</span>
                                        <i class="bi bi-grip-vertical drag-handle" style="font-size: 0.85em;"></i>
                                        <div class="type-icon"><i class="bi bi-box-seam text-primary small"></i></div>
                                        <span class="flex-grow-1 small"><?= e($mat['nom']) ?></span>

                                        <span class="badge item-badge badge-prix text-info me-1 editable-prix" role="button" title="Cliquer pour modifier"><?= formatMoney($prixItem) ?></span>
                                        <span class="badge item-badge badge-total text-success fw-bold me-1"><?= formatMoney($totalItem * 1.14975) ?></span>

                                        <div class="btn-group btn-group-sm me-2">
                                            <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-1 mat-qte-btn" data-action="minus">
                                                <i class="bi bi-dash"></i>
                                            </button>
                                            <span class="badge item-badge badge-qte text-light d-flex align-items-center px-2 mat-qte-display"><?= $qteItem ?></span>
                                            <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-1 mat-qte-btn" data-action="plus">
                                                <i class="bi bi-plus"></i>
                                            </button>
                                        </div>

                                        <button type="button" class="btn btn-sm btn-link text-danger p-0 remove-mat-btn" title="Retirer">
                                            <i class="bi bi-x-lg"></i>
                                        </button>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>

            <?php if (!$hasAnyItems): ?>
            <div class="drop-zone-empty" id="projetEmpty">
                <i class="bi bi-cart-plus d-block"></i>
                <h5>Budget vide</h5>
                <p class="mb-0">Glissez des éléments depuis le catalogue à gauche<br>pour construire votre budget de rénovation.</p>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="mt-3 d-flex justify-content-between">
    <a href="<?= url('/admin/templates/liste.php') ?>" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-gear me-1"></i>Gérer les templates
    </a>
</div>

<!-- Modal Confirmation Ajout Matériau -->
<div class="modal fade" id="confirmAddMaterialModal" tabindex="-1">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content bg-dark text-white border-secondary">
            <div class="modal-header border-secondary">
                <h5 class="modal-title fs-6">Matériau existant</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center">
                <p class="mb-3">Ce matériau est déjà dans le budget.</p>
                <div class="mb-3">
                    <label for="addQteInput" class="form-label text-muted small">Quantité à ajouter</label>
                    <div class="input-group justify-content-center">
                        <button class="btn btn-outline-secondary btn-sm" type="button" onclick="document.getElementById('addQteInput').stepDown()">-</button>
                        <input type="number" class="form-control form-control-sm text-center bg-dark text-white border-secondary" id="addQteInput" value="1" min="1" style="max-width: 80px;">
                        <button class="btn btn-outline-secondary btn-sm" type="button" onclick="document.getElementById('addQteInput').stepUp()">+</button>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-secondary justify-content-center">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Annuler</button>
                <button type="button" class="btn btn-success btn-sm" id="confirmAddBtn">Ajouter</button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const projetId = <?= $projetId ?>;
    const tauxContingence = <?= (float)$projet['taux_contingence'] ?>;
    const csrfToken = '<?= generateCSRFToken() ?>';
    let saveTimeout = null;

    // Variables pour la modal
    const confirmAddModalEl = document.getElementById('confirmAddMaterialModal');
    const confirmAddModal = new bootstrap.Modal(confirmAddModalEl);
    const addQteInput = document.getElementById('addQteInput');
    let pendingMaterialAdd = null;

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

    document.getElementById('confirmAddBtn').addEventListener('click', function() {
        if (pendingMaterialAdd) {
            confirmAddModal.hide();
            const { existingMat } = pendingMaterialAdd;
            const qteToAdd = parseInt(addQteInput.value) || 1;
            
            // Undo/redo: on sauvegarde l'état avant modification
            saveState();

            const currentQte = parseInt(existingMat.dataset.qte) || 1;
            const newQte = currentQte + qteToAdd;

            existingMat.dataset.qte = newQte;

            const qteDisplay = existingMat.querySelector('.mat-qte-display');
            if (qteDisplay) qteDisplay.textContent = newQte;

            // Mettre à jour le total de la ligne (TTC) selon les multiplicateurs actuels
            const prix = parseFloat(existingMat.dataset.prix) || 0;
            const catContainer = existingMat.closest('.projet-item');
            const catQte = catContainer ? parseInt(catContainer.querySelector('.cat-qte-input')?.value || 1) : 1;
            const groupeContainer = existingMat.closest('.projet-groupe');
            const groupeQte = groupeContainer ? parseInt(groupeContainer.querySelector('.groupe-qte-input')?.value || 1) : 1;

            const total = prix * newQte * catQte * groupeQte * 1.14975;
            const totalBadge = existingMat.querySelector('.badge-total');
            if (totalBadge) totalBadge.textContent = formatMoney(total);

            // Sauvegarder en base (update_item_data)
            saveItemData(existingMat.dataset.catId, existingMat.dataset.matId, null, newQte);

            // Recalcul global
            updateAllParents(existingMat);

            updateTotals();
            autoSave();
            
            // Flash visuel long (3 sec)
            existingMat.style.transition = 'background-color 0.5s ease';
            existingMat.style.backgroundColor = 'rgba(25, 135, 84, 0.4)'; // Vert succès
            setTimeout(() => {
                existingMat.style.backgroundColor = '';
            }, 3000);

            // Reset state
            pendingMaterialAdd = null;
        }
    });

    // ========================================
    // UNDO/REDO SYSTEM
    // ========================================
    const historyStack = [];
    const redoStack = [];
    const maxHistory = 50;

    function getState() {
        const state = {
            html: document.getElementById('projetContent').innerHTML,
            groupeVisibility: {}
        };
        document.querySelectorAll('.projet-groupe').forEach(g => {
            state.groupeVisibility[g.dataset.groupe] = g.style.display;
        });
        return state;
    }

    function saveState() {
        const state = getState();
        historyStack.push(state);
        if (historyStack.length > maxHistory) {
            historyStack.shift();
        }
        redoStack.length = 0; // Clear redo stack on new action
        updateUndoRedoButtons();
    }

    function restoreState(state) {
        document.getElementById('projetContent').innerHTML = state.html;
        // Restore groupe visibility
        Object.keys(state.groupeVisibility).forEach(groupe => {
            const g = document.querySelector(`.projet-groupe[data-groupe="${groupe}"]`);
            if (g) g.style.display = state.groupeVisibility[groupe];
        });
        // Reinitialize sortable on drop zones
        document.querySelectorAll('.projet-drop-zone').forEach(zone => {
            new Sortable(zone, {
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
        });
        updateTotals();
    }

    function updateUndoRedoButtons() {
        document.getElementById('undoBtn').disabled = historyStack.length === 0;
        document.getElementById('redoBtn').disabled = redoStack.length === 0;
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
            e.dataTransfer.setData('text/plain', JSON.stringify({
                type: this.dataset.type,
                id: this.dataset.id,
                scId: this.dataset.scId,  // ID de la sous-catégorie (pour matériaux)
                scNom: this.dataset.scNom, // Nom de la sous-catégorie (pour matériaux)
                catId: this.dataset.catId || this.dataset.id,
                catNom: this.dataset.catNom || this.dataset.nom,
                groupe: this.dataset.groupe,
                nom: this.dataset.nom,
                prix: parseFloat(this.dataset.prix) || 0,
                qte: parseInt(this.dataset.qte) || 1,
                catOrdre: parseInt(this.dataset.catOrdre) || 0,
                scOrdre: parseInt(this.dataset.scOrdre) || 0,
                matOrdre: parseInt(this.dataset.matOrdre) || 0
            }));
            this.style.opacity = '0.5';
        });

        item.addEventListener('dragend', function() {
            this.style.opacity = '1';
        });
    });

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
    });

    // Drop sur contenu projet entier
    const projetContent = document.getElementById('projetContent');

    projetContent.addEventListener('dragover', function(e) {
        e.preventDefault();
        this.classList.add('drag-over');
    });

    projetContent.addEventListener('dragleave', function(e) {
        // Only remove if leaving the element entirely
        if (!this.contains(e.relatedTarget)) {
            this.classList.remove('drag-over');
        }
    });

    projetContent.addEventListener('drop', function(e) {
        e.preventDefault();
        this.classList.remove('drag-over');
        try {
            const rawData = e.dataTransfer.getData('text/plain');
            console.log('Drop raw data:', rawData);
            const data = JSON.parse(rawData);
            console.log('Drop parsed data:', data);
            addItemToProjet(data, data.groupe);
        } catch (err) {
            console.error('Drop error:', err);
        }
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
    // AJOUTER AU PROJET
    // ========================================
    function addItemToProjet(data, groupe) {
        console.log('addItemToProjet called:', { data, groupe });

        const isCategory = data.type === 'categorie' || data.type === 'sous_categorie';
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

        // Pour les matériaux: vérifier si le matériau existe déjà
        if (isMaterial) {
            const existingMat = zone.querySelector(`.projet-mat-item[data-mat-id="${data.id}"]`);
            if (existingMat) {
                // Scroll vers l'élément existant pour montrer où il est
                existingMat.scrollIntoView({ behavior: 'smooth', block: 'center' });
                
                // Effet visuel
                existingMat.style.background = 'rgba(13, 110, 253, 0.3)';
                existingMat.style.transition = 'background 0.5s';
                
                // Préparer l'état pour la modal
                pendingMaterialAdd = { existingMat };
                
                // Initialiser l'input avec la quantité dropée ou 1
                document.getElementById('addQteInput').value = data.qte || 1;

                // Ouvrir la modal
                confirmAddModal.show();
                
                // Nettoyer l'effet visuel initial après 1s
                setTimeout(() => { existingMat.style.background = ''; }, 1000);
                return;
            }
        }

        saveState();

        // Pour les matériaux: créer la CHAÎNE COMPLÈTE (Catégorie > Sous-catégorie > Matériau)
        let scContainer = null;
        if (isMaterial && data.scId) {
            const catId = data.catId;
            const catNom = data.catNom || 'Catégorie';
            const scId = data.scId;
            const scNom = data.scNom || 'Sous-catégorie';

            // 1. Vérifier/créer la CATÉGORIE parent
            let catContainer = zone.querySelector(`.projet-item[data-type="categorie"][data-id="${catId}"]`);
            const catContentId = `projetContentCategorie${catId}`;

            if (!catContainer) {
                const catOrdre = data.catOrdre || 0;
                const catHtml = `
                    <div class="tree-item mb-1 is-kit projet-item"
                         data-type="categorie"
                         data-id="${catId}"
                         data-cat-id="${catId}"
                         data-cat-ordre="${catOrdre}"
                         data-unique-id="categorie-${catId}"
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

                            <span class="badge item-badge badge-count text-info me-1">
                                <i class="bi bi-box-seam me-1"></i><span class="item-count">0</span>
                            </span>

                            <span class="badge item-badge badge-total text-success fw-bold cat-total me-1" data-cat-id="${catId}">
                                ${formatMoney(0)}
                            </span>

                            <div class="btn-group btn-group-sm me-2">
                                <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-1 cat-qte-btn" data-cat-id="${catId}" data-action="minus">
                                    <i class="bi bi-dash"></i>
                                </button>
                                <span class="badge item-badge badge-qte text-light d-flex align-items-center px-2 cat-qte-display" data-cat-id="${catId}">1</span>
                                <input type="hidden" class="cat-qte-input" data-cat-id="${catId}" value="1">
                                <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-1 cat-qte-btn" data-cat-id="${catId}" data-action="plus">
                                    <i class="bi bi-plus"></i>
                                </button>
                            </div>

                            <button type="button" class="btn btn-sm btn-link text-danger p-0" onclick="removeProjetItem(this)" title="Retirer">
                                <i class="bi bi-x-lg"></i>
                            </button>
                        </div>
                        <div class="collapse show tree-children" id="${catContentId}"></div>
                    </div>
                `;
                const catElement = createElementFromHTML(catHtml);
                insertInOrder(zone, catElement, 'catOrdre', catOrdre);
                catContainer = zone.querySelector(`.projet-item[data-type="categorie"][data-id="${catId}"]`);
                console.log('Created category container:', catNom);
            }

            // 2. Vérifier/créer la SOUS-CATÉGORIE dans la catégorie
            const catChildren = catContainer.querySelector('.tree-children');
            scContainer = catChildren.querySelector(`.projet-item[data-type="sous_categorie"][data-id="${scId}"]`);
            const scContentId = `projetContentSousCategorie${scId}`;

            if (!scContainer && catChildren) {
                const scOrdre = data.scOrdre || 0;
                const scHtml = `
                    <div class="tree-item mb-1 is-kit projet-item"
                         data-type="sous_categorie"
                         data-id="${scId}"
                         data-cat-id="${catId}"
                         data-sc-ordre="${scOrdre}"
                         data-unique-id="sous_categorie-${scId}"
                         data-groupe="${groupe}"
                         data-prix="0">
                        <div class="tree-content">
                            <span class="tree-connector">└►</span>
                            <i class="bi bi-grip-vertical drag-handle"></i>
                            <span class="tree-toggle" onclick="toggleTreeItem(this, '${scContentId}')">
                                <i class="bi bi-caret-down-fill"></i>
                            </span>
                            <div class="type-icon">
                                <i class="bi bi-folder text-warning"></i>
                            </div>
                            <strong class="flex-grow-1">${escapeHtml(scNom)}</strong>

                            <span class="badge item-badge badge-count text-info me-1">
                                <i class="bi bi-box-seam me-1"></i><span class="item-count">0</span>
                            </span>

                            <span class="badge item-badge badge-total text-success fw-bold cat-total me-1" data-cat-id="${scId}">
                                ${formatMoney(0)}
                            </span>

                            <div class="btn-group btn-group-sm me-2">
                                <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-1 cat-qte-btn" data-cat-id="${scId}" data-action="minus">
                                    <i class="bi bi-dash"></i>
                                </button>
                                <span class="badge item-badge badge-qte text-light d-flex align-items-center px-2 cat-qte-display" data-cat-id="${scId}">1</span>
                                <input type="hidden" class="cat-qte-input" data-cat-id="${scId}" value="1">
                                <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-1 cat-qte-btn" data-cat-id="${scId}" data-action="plus">
                                    <i class="bi bi-plus"></i>
                                </button>
                            </div>

                            <button type="button" class="btn btn-sm btn-link text-danger p-0" onclick="removeProjetItem(this)" title="Retirer">
                                <i class="bi bi-x-lg"></i>
                            </button>
                        </div>
                        <div class="collapse show tree-children" id="${scContentId}"></div>
                    </div>
                `;
                const scElement = createElementFromHTML(scHtml);
                insertInOrder(catChildren, scElement, 'scOrdre', scOrdre);
                scContainer = catChildren.querySelector(`.projet-item[data-type="sous_categorie"][data-id="${scId}"]`);
                console.log('Created sous-category container:', scNom);

                // Mettre à jour le compteur de la catégorie parent
                updateCategoryStats(catContainer);
            }
        }

        // Pour catégories/sous-catégories dropées directement
        const containerId = data.catId || data.id;
        const containerNom = data.catNom || data.nom;
        const uniqueId = isCategory ? `${data.type}-${data.id}` : `sous_categorie-${data.scId}`;
        const contentId = `projetContent${uniqueId.replace(/[^a-zA-Z0-9]/g, '')}`;

        // Si c'est une catégorie/sous-catégorie qui existe déjà, flash et stop
        if (isCategory) {
            const existingCat = zone.querySelector(`.projet-item[data-unique-id="${uniqueId}"]`);
            if (existingCat) {
                console.log('Category already exists, flashing');
                existingCat.scrollIntoView({ behavior: 'smooth', block: 'center' });
                existingCat.querySelector('.tree-content').style.background = 'rgba(13, 110, 253, 0.3)';
                setTimeout(() => { existingCat.querySelector('.tree-content').style.background = ''; }, 1000);
                return;
            }

            // Créer la catégorie
            const itemHtml = `
                <div class="tree-item mb-1 is-kit projet-item"
                     data-type="${data.type}"
                     data-id="${data.id}"
                     data-cat-id="${containerId}"
                     data-unique-id="${uniqueId}"
                     data-groupe="${groupe}"
                     data-prix="${data.prix}">
                    <div class="tree-content">
                        <i class="bi bi-grip-vertical drag-handle"></i>
                        <span class="tree-toggle" onclick="toggleTreeItem(this, '${contentId}')">
                            <i class="bi bi-caret-down-fill"></i>
                        </span>
                        <div class="type-icon">
                            <i class="bi bi-folder-fill text-warning"></i>
                        </div>
                        <strong class="flex-grow-1">${escapeHtml(data.nom)}</strong>

                        <span class="badge item-badge badge-count text-info me-1">
                            <i class="bi bi-box-seam me-1"></i><span class="item-count">0</span>
                        </span>

                        <span class="badge item-badge badge-total text-success fw-bold cat-total me-1" data-cat-id="${data.id}">
                            ${formatMoney(0)}
                        </span>

                        <div class="btn-group btn-group-sm me-2">
                            <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-1 cat-qte-btn" data-cat-id="${data.id}" data-action="minus">
                                <i class="bi bi-dash"></i>
                            </button>
                            <span class="badge item-badge badge-qte text-light d-flex align-items-center px-2 cat-qte-display" data-cat-id="${data.id}">1</span>
                            <input type="hidden" class="cat-qte-input" data-cat-id="${data.id}" value="1">
                            <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-1 cat-qte-btn" data-cat-id="${data.id}" data-action="plus">
                                <i class="bi bi-plus"></i>
                            </button>
                        </div>

                        <button type="button" class="btn btn-sm btn-link text-danger p-0" onclick="removeProjetItem(this)" title="Retirer">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </div>
                    <div class="collapse show tree-children" id="${contentId}">
                        <div class="text-muted small p-2"><i class="bi bi-hourglass-split me-1"></i>Chargement...</div>
                    </div>
                </div>
            `;
            zone.insertAdjacentHTML('beforeend', itemHtml);
        }

        // Pour les matériaux: ajouter dans le container de la sous-catégorie
        if (isMaterial && scContainer) {
            const matContainer = scContainer.querySelector('.tree-children');
            if (matContainer) {
                const itemTotal = (parseFloat(data.prix) || 0) * (parseInt(data.qte) || 1);
                const matOrdre = data.matOrdre || 0;
                const matHtml = `
                    <div class="tree-content mat-item projet-mat-item"
                         data-mat-id="${data.id}"
                         data-mat-ordre="${matOrdre}"
                         data-cat-id="${data.scId}"
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
                            <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-1 mat-qte-btn" data-action="minus">
                                <i class="bi bi-dash"></i>
                            </button>
                            <span class="badge item-badge badge-qte text-light d-flex align-items-center px-2 mat-qte-display">${data.qte || 1}</span>
                            <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-1 mat-qte-btn" data-action="plus">
                                <i class="bi bi-plus"></i>
                            </button>
                        </div>

                        <button type="button" class="btn btn-sm btn-link text-danger p-0 remove-mat-btn" title="Retirer">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </div>
                `;
                // Insérer dans l'ordre
                const matElement = createElementFromHTML(matHtml);
                const existingMats = matContainer.querySelectorAll('.projet-mat-item');
                let inserted = false;
                for (const existing of existingMats) {
                    const existingOrdre = parseInt(existing.dataset.matOrdre) || 0;
                    if (matOrdre < existingOrdre) {
                        matContainer.insertBefore(matElement, existing);
                        inserted = true;
                        break;
                    }
                }
                if (!inserted) {
                    matContainer.appendChild(matElement);
                }

                // Mettre à jour le compteur et total de la sous-catégorie (avec multiplicateurs)
                updateSousCategorieStats(scContainer);

                // Mettre à jour aussi la catégorie parent
                const parentCat = zone.querySelector(`.projet-item[data-type="categorie"][data-id="${data.catId}"]`);
                if (parentCat) {
                    updateCategoryStats(parentCat);
                }
            }
        }

        console.log('Item added successfully');

        // Sauvegarder en base de données
        // TOUJOURS envoyer l'ID de la CATÉGORIE (pas sous-catégorie) pour la cohérence BD
        const saveId = data.catId || data.id;
        fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `ajax_action=add_dropped_item&type=${data.type}&item_id=${data.id}&cat_id=${saveId}&groupe=${groupe}&prix=${data.prix}&qte=${data.qte || 1}&csrf_token=${csrfToken}`
        })
        .then(r => r.json())
        .then(result => {
            console.log('Save result:', result);
            if (!result.success) {
                console.error('Erreur sauvegarde:', result.error);
                if (isCategory) {
                    const container = document.getElementById(contentId);
                    if (container) {
                        container.innerHTML = `<div class="text-danger small p-2"><i class="bi bi-exclamation-triangle me-1"></i>Erreur: ${result.error}</div>`;
                    }
                }
                return;
            }

            // Si c'est une catégorie et qu'il y a des sous-items retournés, les afficher
            if (isCategory && result.added_items && result.added_items.length > 0) {
                const container = document.getElementById(contentId);
                if (container) {
                    // Vider le message de chargement
                    container.innerHTML = '';

                    // Calculer le total de la catégorie
                    let catTotalCalc = 0;

                    // Ajouter chaque sous-item
                    result.added_items.forEach(item => {
                        const itemTotal = (parseFloat(item.prix) || 0) * (parseInt(item.qte) || 1);
                        catTotalCalc += itemTotal;

                        const matHtml = `
                            <div class="tree-content mat-item projet-mat-item"
                                 data-mat-id="${item.mat_id}"
                                 data-cat-id="${containerId}"
                                 data-prix="${item.prix}"
                                 data-qte="${item.qte}"
                                 data-sans-taxe="${item.sans_taxe ? 1 : 0}">
                                <span class="tree-connector">└►</span>
                                <i class="bi bi-grip-vertical drag-handle" style="font-size: 0.85em;"></i>
                                <div class="type-icon"><i class="bi bi-box-seam text-primary small"></i></div>
                                <span class="flex-grow-1 small">${escapeHtml(item.nom)}</span>

                                <span class="badge item-badge badge-prix text-info me-1 editable-prix" role="button" title="Cliquer pour modifier">${formatMoney(parseFloat(item.prix) || 0)}</span>
                                <span class="badge item-badge badge-total text-success fw-bold me-1">${formatMoney(itemTotal * 1.14975)}</span>

                                <div class="btn-group btn-group-sm me-2">
                                    <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-1 mat-qte-btn" data-action="minus">
                                        <i class="bi bi-dash"></i>
                                    </button>
                                    <span class="badge item-badge badge-qte text-light d-flex align-items-center px-2 mat-qte-display">${item.qte}</span>
                                    <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-1 mat-qte-btn" data-action="plus">
                                        <i class="bi bi-plus"></i>
                                    </button>
                                </div>

                                <button type="button" class="btn btn-sm btn-link text-danger p-0 remove-mat-btn" title="Retirer">
                                    <i class="bi bi-x-lg"></i>
                                </button>
                            </div>
                        `;
                        container.insertAdjacentHTML('beforeend', matHtml);
                    });

                    // Mettre à jour le compteur et total dans le header de la catégorie
                    const projetItem = zone.querySelector(`.projet-item[data-unique-id="${uniqueId}"]`);
                    if (projetItem) {
                        updateCategoryStats(projetItem);
                    }

                    console.log(`Added ${result.added_items.length} sub-items to category`);
                }
            } else if (isCategory) {
                // Catégorie vide - afficher un message
                const container = document.getElementById(contentId);
                if (container) {
                    container.innerHTML = '<div class="text-muted small p-2"><i class="bi bi-info-circle me-1"></i>Aucun matériau</div>';
                }
            }

            // Recalculer les totaux après l'ajout
            updateTotals();
        })
        .catch(err => {
            console.error('Network error:', err);
            if (isCategory) {
                const container = document.getElementById(contentId);
                if (container) {
                    container.innerHTML = `<div class="text-danger small p-2"><i class="bi bi-wifi-off me-1"></i>Erreur réseau</div>`;
                }
            }
        });

        updateTotals();
    }

    // Mettre à jour les stats (compteur et total) d'une catégorie
    function updateCategoryStats(categoryContainer) {
        const matItems = categoryContainer.querySelectorAll('.projet-mat-item');
        let catTotal = 0;

        matItems.forEach(matItem => {
            const prix = parseFloat(matItem.dataset.prix) || 0;
            const qte = parseInt(matItem.dataset.qte) || 1;
            catTotal += prix * qte;
        });

        const countSpan = categoryContainer.querySelector('.item-count');
        if (countSpan) countSpan.textContent = matItems.length;

        const totalSpan = categoryContainer.querySelector('.cat-total');
        if (totalSpan) totalSpan.textContent = formatMoney(catTotal * 1.14975);

        categoryContainer.dataset.prix = catTotal;
    }

    // Met à jour récursivement les totaux des parents
    function updateAllParents(element) {
        let parent = element.parentElement ? element.parentElement.closest('.projet-item') : null;
        while (parent) {
            updateSousCategorieStats(parent);
            parent = parent.parentElement ? parent.parentElement.closest('.projet-item') : null;
        }
    }

    // Met à jour le total affiché d'une SOUS-CATÉGORIE (avec multiplicateurs)
    function updateSousCategorieStats(scContainer) {
        if (!scContainer) return;

        const matItems = scContainer.querySelectorAll('.projet-mat-item');
        let totalTaxable = 0;
        let totalNonTaxable = 0;

        matItems.forEach(matItem => {
            const prix = parseFloat(matItem.dataset.prix) || 0;
            const qte = parseInt(matItem.dataset.qte) || 1;
            const sansTaxe = matItem.dataset.sansTaxe === '1';
            const ht = prix * qte;

            if (sansTaxe) totalNonTaxable += ht;
            else totalTaxable += ht;
        });

        const groupeContainer = scContainer.closest('.projet-groupe');
        const groupeQte = groupeContainer ? parseInt(groupeContainer.querySelector('.groupe-qte-input')?.value || 1) : 1;

        // Le parent "catégorie" (poste) du projet
        const catContainer = scContainer.closest('.projet-drop-zone')?.querySelector('.projet-item[data-type="categorie"]')
            ? scContainer.closest('.projet-item[data-type="categorie"]')
            : scContainer.closest('.projet-item[data-type="categorie"]');
        const catQte = catContainer ? parseInt(catContainer.querySelector('.cat-qte-input')?.value || 1) : 1;

        const totalNonTaxableMult = totalNonTaxable * catQte * groupeQte;
        const totalTaxableMult = totalTaxable * catQte * groupeQte;

        const totalTTC = totalNonTaxableMult + (totalTaxableMult * 1.14975);

        const countSpan = scContainer.querySelector('.item-count');
        if (countSpan) countSpan.textContent = matItems.length;

        const totalSpan = scContainer.querySelector('.cat-total');
        if (totalSpan) totalSpan.textContent = formatMoney(totalTTC);
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function formatMoney(val) {
        return val.toLocaleString('fr-CA', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' $';
    }

    function parseMoneyText(text) {
        if (!text) return 0;
        // Supporte "1 234,56 $" / NBSP / etc.
        const cleaned = text
            .replace(/\u00A0/g, ' ')
            .replace(/[^\d,-]/g, '')
            .replace(',', '.');
        return parseFloat(cleaned) || 0;
    }

    function parseMoneyFromCell(cell) {
        return cell ? parseMoneyText(cell.textContent) : 0;
    }

    // ========================================
    // QUANTITÉS
    // ========================================
    window.changeCatQte = function(catId, delta) {
        const input = document.querySelector(`.cat-qte-input[data-cat-id="${catId}"]`);
        if (input) {
            const newVal = Math.max(1, Math.min(20, parseInt(input.value) + delta));
            input.value = newVal;
            updateCatQte(catId);
        }
    };

    window.updateCatQte = function(catId) {
        // Mettre à jour les sous-catégories de cette catégorie
        const catItem = document.querySelector(`.projet-item[data-type="categorie"][data-id="${catId}"]`);
        if (catItem) {
            // Mettre à jour les totaux des matériaux individuels
            catItem.querySelectorAll('.projet-mat-item').forEach(matItem => {
                updateMaterialTotal(matItem);
            });
            // Mettre à jour les totaux des sous-catégories (récursivement)
            catItem.querySelectorAll('.projet-item[data-type="sous_categorie"]').forEach(sc => {
                updateSousCategorieStats(sc);
            });
            // Mettre à jour le total de la catégorie elle-même
            updateSousCategorieStats(catItem);
        }
        updateTotals();
        autoSave();
    };

    window.changeGroupeQte = function(groupe, delta) {
        const input = document.querySelector(`.groupe-qte-input[data-groupe="${groupe}"]`);
        if (input) {
            const newVal = Math.max(1, Math.min(20, parseInt(input.value) + delta));
            input.value = newVal;
            updateGroupeQte(groupe);
        }
    };

    window.updateGroupeQte = function(groupe) {
        // Mettre à jour toutes les sous-catégories et matériaux de ce groupe
        const zone = document.querySelector(`.projet-drop-zone[data-groupe="${groupe}"]`);
        if (zone) {
            zone.querySelectorAll('.projet-mat-item').forEach(matItem => {
                updateMaterialTotal(matItem);
            });
            zone.querySelectorAll('.projet-item').forEach(item => {
                updateSousCategorieStats(item);
            });
        }
        updateTotals();
        autoSave();
    };

    // Helper pour mettre à jour le total d'un matériau
    function updateMaterialTotal(matItem) {
        const prix = parseFloat(matItem.dataset.prix) || 0;
        const qte = parseInt(matItem.dataset.qte) || 1;
        
        const catContainer = matItem.closest('.projet-item[data-type="categorie"]');
        const catQteInput = catContainer ? catContainer.querySelector('.cat-qte-input') : null;
        const catQte = catQteInput ? parseInt(catQteInput.value) || 1 : 1;
        
        const groupeContainer = matItem.closest('.projet-groupe');
        const groupeQteInput = groupeContainer ? groupeContainer.querySelector('.groupe-qte-input') : null;
        const groupeQte = groupeQteInput ? parseInt(groupeQteInput.value) || 1 : 1;

        const total = prix * qte * catQte * groupeQte * 1.14975;
        const badge = matItem.querySelector('.badge-total');
        if (badge) badge.textContent = formatMoney(total);
    }

    window.clearAllBudget = function() {
        if (!confirm('Voulez-vous vraiment supprimer tous les items du budget?')) {
            return;
        }

        saveState();

        // Supprimer tous les items du DOM
        document.querySelectorAll('.projet-item').forEach(item => item.remove());
        document.querySelectorAll('.projet-mat-item').forEach(item => item.remove());

        // Masquer tous les groupes
        document.querySelectorAll('.projet-groupe').forEach(groupe => {
            groupe.style.display = 'none';
        });

        // Afficher le message vide
        const emptyMsg = document.getElementById('projetEmpty');
        if (emptyMsg) emptyMsg.style.display = '';

        // Supprimer en base de données
        fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `ajax_action=clear_all_budget&csrf_token=${csrfToken}`
        })
        .then(r => r.json())
        .then(result => {
            if (!result.success) {
                console.error('Erreur suppression:', result.error);
            }
        })
        .catch(err => console.error('Network error:', err));

        updateTotals();
    };

    window.removeProjetItem = function(btn) {
        saveState();
        const item = btn.closest('.projet-item');
        const groupe = item.dataset.groupe;
        item.remove();

        // Vérifier si groupe vide
        const zone = document.querySelector(`.projet-drop-zone[data-groupe="${groupe}"]`);
        if (zone && zone.querySelectorAll('.projet-item').length === 0) {
            zone.closest('.projet-groupe').style.display = 'none';
        }

        // Vérifier si tout vide
        if (document.querySelectorAll('.projet-item').length === 0) {
            const emptyMsg = document.getElementById('projetEmpty');
            if (emptyMsg) emptyMsg.style.display = '';
        }

        updateTotals();
        autoSave();
    };

    // ========================================
    // CALCULS
    // ========================================
    function updateTotals() {
        let totalHT = 0;
        let totalTaxable = 0;
        let totalNonTaxable = 0;

        // Parcourir SEULEMENT les catégories de premier niveau (enfants directs de drop-zone)
        // Évite le double comptage des sous-catégories imbriquées
        document.querySelectorAll('.projet-drop-zone > .projet-item').forEach(catItem => {
            const groupe = catItem.dataset.groupe;
            let catTotalHT = 0;
            let catTotalTaxable = 0;
            let catTotalNonTaxable = 0;

            const groupeQteInput = document.querySelector(`.groupe-qte-input[data-groupe="${groupe}"]`);
            const qteGroupe = groupeQteInput ? parseInt(groupeQteInput.value) || 1 : 1;

            // Vérifier si c'est un item avec sous-items (matériaux)
            const matItems = catItem.querySelectorAll('.projet-mat-item');
            if (matItems.length > 0) {
                // Item avec sous-matériaux
                const catQteInput = catItem.querySelector('.cat-qte-input');
                const qteCat = catQteInput ? parseInt(catQteInput.value) || 1 : 1;

                matItems.forEach(matItem => {
                    const prix = parseFloat(matItem.dataset.prix) || 0;
                    const qte = parseInt(matItem.dataset.qte) || 1;
                    const sansTaxe = matItem.dataset.sansTaxe === '1';
                    
                    const ligneTotal = prix * qte;
                    catTotalHT += ligneTotal;
                    
                    if (sansTaxe) {
                        catTotalNonTaxable += ligneTotal;
                    } else {
                        catTotalTaxable += ligneTotal;
                    }
                });
                
                // Appliquer multiplicateurs
                catTotalHT *= qteCat * qteGroupe;
                catTotalTaxable *= qteCat * qteGroupe;
                catTotalNonTaxable *= qteCat * qteGroupe;

                // Mettre à jour affichage du total catégorie (TTC approximatif pour UI, ou HT + taxes selon préférence, ici on garde TTC pour cohérence visuelle)
                // Note: Pour être précis, on recalcule le TTC de la catégorie
                const catTTC = catTotalNonTaxable + (catTotalTaxable * 1.14975);
                const totalSpan = catItem.querySelector('.cat-total');
                if (totalSpan) {
                    totalSpan.textContent = formatMoney(catTTC);
                }

            } else {
                // Item simple (ajouté par drag-drop, par défaut taxable sauf si spécifié autrement)
                const prix = parseFloat(catItem.dataset.prix) || 0;
                const qteDisplay = catItem.querySelector('.added-item-qte-display');
                const qte = qteDisplay ? parseInt(qteDisplay.textContent) || 1 : 1;
                const sansTaxe = catItem.dataset.sansTaxe === '1'; // Si jamais on ajoute cette option au drop

                const itemTotal = prix * qte * qteGroupe;
                catTotalHT += itemTotal;
                
                if (sansTaxe) {
                    catTotalNonTaxable += itemTotal;
                } else {
                    catTotalTaxable += itemTotal;
                }

                const itemTTC = sansTaxe ? itemTotal : (itemTotal * 1.14975);
                const totalSpan = catItem.querySelector('.badge-total');
                if (totalSpan) {
                    totalSpan.textContent = formatMoney(itemTTC);
                }
            }
            
            totalHT += catTotalHT;
            totalTaxable += catTotalTaxable;
            totalNonTaxable += catTotalNonTaxable;
        });

        const contingence = totalHT * (tauxContingence / 100);
        // Pas de taxe sur la contingence
        const tps = totalTaxable * 0.05;
        const tvq = totalTaxable * 0.09975;
        const grandTotal = totalHT + contingence + tps + tvq;

        document.getElementById('totalHT').textContent = formatMoney(totalHT);
        document.getElementById('totalContingence').textContent = formatMoney(contingence);
        document.getElementById('grandTotal').textContent = formatMoney(grandTotal);

        // Mettre à jour aussi "Détail des coûts" si présent
        // Calculer les totaux pour les catégories dans le Budget Builder
        const categoryTotals = {};
        document.querySelectorAll('.projet-item[data-type="categorie"]').forEach(catItem => {
            const catId = catItem.dataset.id;
            const groupe = catItem.dataset.groupe;
            const groupeQteInput = document.querySelector(`.groupe-qte-input[data-groupe="${groupe}"]`);
            const qteGroupe = groupeQteInput ? parseInt(groupeQteInput.value) || 1 : 1;

            let catTotal = 0;
            const matItems = catItem.querySelectorAll('.projet-mat-item');
            if (matItems.length > 0) {
                const catQteInput = catItem.querySelector('.cat-qte-input');
                const qteCat = catQteInput ? parseInt(catQteInput.value) || 1 : 1;
                matItems.forEach(matItem => {
                    const prix = parseFloat(matItem.dataset.prix) || 0;
                    const qte = parseInt(matItem.dataset.qte) || 1;
                    catTotal += prix * qte;
                });
                catTotal = catTotal * qteCat * qteGroupe;
            } else {
                const prix = parseFloat(catItem.dataset.prix) || 0;
                const qteDisplay = catItem.querySelector('.added-item-qte-display');
                const qte = qteDisplay ? parseInt(qteDisplay.textContent) || 1 : 1;
                catTotal = prix * qte * qteGroupe;
            }

            categoryTotals[catId] = (categoryTotals[catId] || 0) + catTotal;
        });

        // Pour chaque ligne de catégorie, décider si on l'affiche ou pas
        document.querySelectorAll('.detail-cat-row').forEach(row => {
            const catId = row.dataset.catId;
            const budgetCell = row.querySelector('.detail-cat-budget');
            const diffCell = row.querySelector('.detail-cat-diff');
            const reelCell = row.querySelector('.detail-cat-reel') || row.querySelectorAll('td')[3];

            const reelValue = parseMoneyFromCell(reelCell);
            const budgetValue = categoryTotals[catId] || 0;

            // Mettre à jour la valeur du budget
            if (budgetCell) {
                budgetCell.textContent = formatMoney(budgetValue);
            }

            // Mettre à jour la colonne Diff (budget - réel)
            if (diffCell) {
                const diffValue = budgetValue - reelValue;
                diffCell.textContent = diffValue !== 0 ? formatMoney(diffValue) : '-';
                diffCell.classList.remove('positive', 'negative');
                diffCell.classList.add(diffValue >= 0 ? 'positive' : 'negative');
            }

            // Afficher si budget > 0 OU dépenses réelles > 0
            if (budgetValue > 0 || reelValue > 0) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });

        // Mettre à jour contingence, taxes et total
        const detailContingence = document.getElementById('detailContingence');
        if (detailContingence) detailContingence.textContent = formatMoney(contingence);

        const detailTPS = document.getElementById('detailTPS');
        if (detailTPS) detailTPS.textContent = formatMoney(tps);

        const detailTVQ = document.getElementById('detailTVQ');
        if (detailTVQ) detailTVQ.textContent = formatMoney(tvq);

        const detailRenoTotal = document.getElementById('detailRenoTotal');
        if (detailRenoTotal) detailRenoTotal.textContent = formatMoney(grandTotal);

        // Mettre à jour aussi le total-row de la section Rénovation
        // Base inclut la main-d'œuvre dans ce sous-total, donc on l'ajoute ici pour rester cohérent.
        const renoTotalRow = detailRenoTotal ? detailRenoTotal.closest('tr') : null;
        if (renoTotalRow) {
            const cells = renoTotalRow.querySelectorAll('td');

            const laborRow = document.querySelector('.labor-row');
            const laborCells = laborRow ? laborRow.querySelectorAll('td') : null;
            const laborBudget = laborCells && laborCells.length >= 2 ? parseMoneyFromCell(laborCells[1]) : 0;

            const newRenoBudgetTTC = grandTotal + laborBudget;

            if (cells.length >= 4) {
                const reelRenoValue = parseMoneyFromCell(cells[3]);
                const diffRenoValue = newRenoBudgetTTC - reelRenoValue;

                cells[1].textContent = formatMoney(newRenoBudgetTTC);
                cells[2].textContent = formatMoney(diffRenoValue);
                cells[2].classList.remove('positive', 'negative');
                cells[2].classList.add(diffRenoValue >= 0 ? 'positive' : 'negative');
            }
        }

        // Mettre à jour les valeurs stockées dans le header de section pour le toggle
        const renoHeader = document.querySelector('.section-header[data-section="renovation"]');
        if (renoHeader) {
            // On expose la même valeur que la cellule Extrapolé du sous-total rénovation
            const renoTotalRowNow = detailRenoTotal ? detailRenoTotal.closest('tr') : null;
            const renoCellsNow = renoTotalRowNow ? renoTotalRowNow.querySelectorAll('td') : null;
            if (renoCellsNow && renoCellsNow.length >= 2) {
                renoHeader.dataset.extrapole = renoCellsNow[1].textContent.trim();
            }
        }

        // Mettre à jour les connecteurs d'arbre (Désactivé)
        // updateTreeConnectors();
    }

    // ========================================
    // AUTO-SAVE
    // ========================================
    function showSaveStatus(status) {
        document.getElementById('saveIdle').classList.add('d-none');
        document.getElementById('saveSaving').classList.add('d-none');
        document.getElementById('saveSaved').classList.add('d-none');
        document.getElementById('save' + status.charAt(0).toUpperCase() + status.slice(1)).classList.remove('d-none');
    }

    function autoSave() {
        if (saveTimeout) clearTimeout(saveTimeout);

        saveTimeout = setTimeout(function() {
            showSaveStatus('saving');

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
                    csrf_token: csrfToken,
                    items: items,
                    groupes: groupes
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showSaveStatus('saved');
                    setTimeout(() => showSaveStatus('idle'), 2000);
                } else {
                    console.error('Save error:', data.error);
                    showSaveStatus('idle');
                }
            })
            .catch(error => {
                console.error('Network error:', error);
                showSaveStatus('idle');
            });
        }, 500);
    }

    // ========================================
    // SORTABLE (réorganisation dans projet)
    // ========================================
    document.querySelectorAll('.projet-drop-zone').forEach(zone => {
        new Sortable(zone, {
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
    });

    // ========================================
    // BOUTONS +/- POUR ITEMS AJOUTÉS PAR DRAG
    // ========================================
    document.addEventListener('click', function(e) {
        const btn = e.target.closest('.added-item-qte-btn');
        if (!btn) return;

        saveState();
        const action = btn.dataset.action;
        const projetItem = btn.closest('.projet-item');
        const qteDisplay = projetItem.querySelector('.added-item-qte-display');

        let currentQte = parseInt(qteDisplay.textContent) || 1;

        if (action === 'plus') {
            currentQte++;
        } else if (action === 'minus' && currentQte > 1) {
            currentQte--;
        }

        qteDisplay.textContent = currentQte;

        // Mettre à jour le total
        const prix = parseFloat(projetItem.dataset.prix) || 0;
        const totalBadge = projetItem.querySelector('.badge-total');
        if (totalBadge) {
            totalBadge.textContent = formatMoney(prix * currentQte * 1.14975);
        }

        updateTotals();
        autoSave();
    });

    // ========================================
    // BOUTONS +/- POUR QUANTITÉS DE MATÉRIAUX
    // ========================================
    document.addEventListener('click', function(e) {
        const btn = e.target.closest('.mat-qte-btn');
        if (!btn) return;

        saveState();
        const action = btn.dataset.action;
        const matItem = btn.closest('.projet-mat-item');
        const matQteDisplay = matItem.querySelector('.mat-qte-display');

        let currentQte = parseInt(matItem.dataset.qte) || 1;

        if (action === 'plus') {
            currentQte++;
        } else if (action === 'minus' && currentQte > 1) {
            currentQte--;
        }

        matItem.dataset.qte = currentQte;
        matQteDisplay.textContent = currentQte;

        // Mettre à jour le total de la ligne
        const prix = parseFloat(matItem.dataset.prix) || 0;
        const catContainer = matItem.closest('.projet-item');
        const catQte = catContainer ? parseInt(catContainer.querySelector('.cat-qte-input')?.value || 1) : 1;
        const groupeContainer = matItem.closest('.projet-groupe');
        const groupeQte = groupeContainer ? parseInt(groupeContainer.querySelector('.groupe-qte-input')?.value || 1) : 1;

        const total = prix * currentQte * catQte * groupeQte * 1.14975;
        matItem.querySelector('.badge-total').textContent = formatMoney(total);

        // Sauvegarder via AJAX
        saveItemData(matItem.dataset.catId, matItem.dataset.matId, null, currentQte);

        // Recalculer les totaux + mettre à jour récursivement
        updateAllParents(matItem);

        updateTotals();
    });

    // ========================================
    // SUPPRIMER UN MATÉRIAU
    // ========================================
    document.addEventListener('click', function(e) {
        const btn = e.target.closest('.remove-mat-btn');
        if (!btn) return;

        saveState();
        const matItem = btn.closest('.projet-mat-item');
        const catId = matItem.dataset.catId;
        const matId = matItem.dataset.matId;

        // Mettre à jour le total affiché de la sous-catégorie AVANT de supprimer (pour avoir le parent)
        // En fait non, on doit supprimer puis recalculer le parent
        const parentItem = matItem.closest('.projet-item');

        // Supprimer l'élément du DOM
        matItem.remove();

        // Mettre à jour le total affiché du parent
        if (parentItem) updateAllParents(parentItem.querySelector('.tree-content')); // Hack pour passer un élément enfant du parent

        // Supprimer via AJAX
        fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `ajax_action=remove_material&cat_id=${catId}&mat_id=${matId}&csrf_token=${csrfToken}`
        })
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                console.error('Erreur suppression:', data.error);
            }
        });

        // Recalculer les totaux
        updateTotals();
        autoSave();
    });

    // ========================================
    // ÉDITION INLINE DES PRIX
    // ========================================
    document.addEventListener('click', function(e) {
        const prixBadge = e.target.closest('.editable-prix');
        if (!prixBadge) return;

        // Éviter les clics multiples
        if (prixBadge.querySelector('input')) return;

        saveState();

        // Supporter les deux types: matériau dans catégorie (.projet-mat-item) ou matériau simple dropé (.projet-item)
        let targetItem = prixBadge.closest('.projet-mat-item');
        let isSimpleItem = false;

        if (!targetItem) {
            targetItem = prixBadge.closest('.projet-item');
            isSimpleItem = true;
        }

        if (!targetItem) return;

        const currentPrix = parseFloat(targetItem.dataset.prix) || 0;
        const originalText = prixBadge.textContent;
        let cancelled = false;

        // Créer l'input
        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'prix-input';
        input.value = currentPrix.toFixed(2);

        prixBadge.textContent = '';
        prixBadge.appendChild(input);
        input.focus();
        input.select();

        function savePrix() {
            if (cancelled) return;

            const newPrix = parseFloat(input.value.replace(/[^0-9.,]/g, '').replace(',', '.')) || 0;
            targetItem.dataset.prix = newPrix;
            prixBadge.textContent = formatMoney(newPrix);

            if (isSimpleItem) {
                // Matériau simple dropé directement
                const qteDisplay = targetItem.querySelector('.added-item-qte-display');
                const qte = qteDisplay ? parseInt(qteDisplay.textContent) || 1 : 1;
                const groupeContainer = targetItem.closest('.projet-groupe');
                const groupeQte = groupeContainer ? parseInt(groupeContainer.querySelector('.groupe-qte-input')?.value || 1) : 1;

                const total = newPrix * qte * groupeQte * 1.14975;
                targetItem.querySelector('.badge-total').textContent = formatMoney(total);

                // Sauvegarder via AJAX - pour items simples, on utilise data-id comme mat_id
                saveItemData(targetItem.dataset.catId, targetItem.dataset.id, newPrix, null);
            } else {
                // Matériau dans une catégorie
                const qte = parseInt(targetItem.dataset.qte) || 1;
                const catContainer = targetItem.closest('.projet-item');
                const catQte = catContainer ? parseInt(catContainer.querySelector('.cat-qte-input')?.value || 1) : 1;
                const groupeContainer = targetItem.closest('.projet-groupe');
                const groupeQte = groupeContainer ? parseInt(groupeContainer.querySelector('.groupe-qte-input')?.value || 1) : 1;

                const total = newPrix * qte * catQte * groupeQte * 1.14975;
                targetItem.querySelector('.badge-total').textContent = formatMoney(total);

                // Sauvegarder via AJAX
                saveItemData(targetItem.dataset.catId, targetItem.dataset.matId, newPrix, null);
            }

            // Mettre à jour le total affiché de la sous-catégorie
            updateAllParents(targetItem);

            // Recalculer les totaux
            updateTotals();
        }

        input.addEventListener('blur', savePrix);
        input.addEventListener('keydown', function(ev) {
            if (ev.key === 'Enter') {
                ev.preventDefault();
                input.blur();
            } else if (ev.key === 'Escape') {
                cancelled = true;
                prixBadge.textContent = originalText;
            }
        });
    });

    // ========================================
    // BOUTONS +/- POUR QUANTITÉS DE CATÉGORIE
    // ========================================
    document.addEventListener('click', function(e) {
        const btn = e.target.closest('.cat-qte-btn');
        if (!btn) return;

        saveState();
        const catId = btn.dataset.catId;
        const action = btn.dataset.action;
        const catItem = btn.closest('.projet-item');
        const catQteInput = catItem.querySelector('.cat-qte-input');
        const catQteDisplay = catItem.querySelector('.cat-qte-display');

        let currentQte = parseInt(catQteInput.value) || 1;

        if (action === 'plus') {
            currentQte++;
        } else if (action === 'minus' && currentQte > 1) {
            currentQte--;
        }

        catQteInput.value = currentQte;
        catQteDisplay.textContent = currentQte;

        // Mettre à jour les totaux de tous les matériaux
        catItem.querySelectorAll('.projet-mat-item').forEach(matItem => {
            updateMaterialTotal(matItem);
        });

        // Mettre à jour les sous-catégories
        catItem.querySelectorAll('.projet-item[data-type="sous_categorie"]').forEach(sc => {
            updateSousCategorieStats(sc);
        });
        
        // Mettre à jour la catégorie elle-même (si elle contient des sous-catégories)
        updateSousCategorieStats(catItem);

        // Recalculer les totaux et sauvegarder
        updateTotals();
        autoSave();
    });

    function saveItemData(catId, matId, prix, qte) {
        let body = `ajax_action=update_item_data&cat_id=${catId}&mat_id=${matId}&csrf_token=${csrfToken}`;
        if (prix !== null) body += `&prix=${prix}`;
        if (qte !== null) body += `&qte=${qte}`;

        console.log('Saving item:', { catId, matId, prix, qte, body });

        fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body
        })
        .then(r => r.json())
        .then(data => {
            console.log('Save response:', data);
            if (!data.success) {
                console.error('Erreur sauvegarde:', data.error);
                alert('Erreur: ' + (data.error || 'Sauvegarde échouée'));
            }
        })
        .catch(err => {
            console.error('Network error:', err);
            alert('Erreur réseau: ' + err.message);
        });
    }

    // NOTE: On n'appelle PAS updateTotals() au chargement car:
    // 1. PHP rend déjà "Détail des coûts" depuis la table budgets
    // 2. La table budgets est synchronisée via syncBudgetsFromProjetItems()
    // 3. Appeler updateTotals() ici écraserait les valeurs PHP avec 0 si le budget est vide
    // updateTotals() est appelé seulement après les interactions utilisateur
});
</script>
