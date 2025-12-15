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
function afficherSousCategoriesRecursifCatalogue($sousCategories, $catId, $catNom, $groupe, $catOrdre = 0, $niveau = 0, $parentPath = []) {
    if (empty($sousCategories)) return;

    foreach ($sousCategories as $scIndex => $sc):
        $hasEnfants = !empty($sc['enfants']);
        $hasMateriaux = !empty($sc['materiaux']);
        $isKit = $hasEnfants || $hasMateriaux;
        $uniqueId = 'cat' . $catId . '_sc' . $sc['id'];
        $scOrdre = $sc['ordre'] ?? $scIndex;

        // Construire le chemin complet pour cet item
        $currentPath = $parentPath;
        $currentPath[] = [
            'type' => 'sous_categorie',
            'id' => $sc['id'],
            'nom' => $sc['nom'],
            'ordre' => $scOrdre
        ];

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
                 data-prix="<?= $totalSc ?>"
                 data-path='<?= json_encode($currentPath) ?>'>

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
                     data-sans-taxe="<?= !empty($mat['sans_taxe']) ? 1 : 0 ?>"
                     data-path='<?= json_encode($currentPath) // Le path du matériau est celui de son parent ?>'>

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
                    <?php afficherSousCategoriesRecursifCatalogue($sc['enfants'], $catId, $catNom, $groupe, $catOrdre, $niveau + 1, $currentPath); ?>
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
                        
                        // Initial path for recursive function
                        $initialPath = [];
                    ?>
                    <div class="tree-item mb-1 <?= $hasContent ? 'is-kit' : '' ?>">
                        <div class="tree-content catalogue-draggable"
                             draggable="true"
                             data-type="categorie"
                             data-id="<?= $catId ?>"
                             data-cat-ordre="<?= $catOrdre ?>"
                             data-groupe="<?= $groupe ?>"
                             data-nom="<?= e($cat['nom']) ?>"
                             data-prix="<?= $totalCat ?>"
                             data-path='<?= json_encode($initialPath) ?>'>

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
                            <?php afficherSousCategoriesRecursifCatalogue($cat['sous_categories'], $catId, $cat['nom'], $groupe, $catOrdre, 0, $initialPath); ?>
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
            // NOTE: Le rendu initial du contenu du projet est généré en PHP pour la performance et le SEO (si pertinent)
            // Mais pour simplifier la maintenance et éviter la duplication de logique, 
            // on pourrait envisager de le charger en JS ou d'utiliser les mêmes templates.
            // Pour l'instant, on garde la structure générée par PHP telle quelle.
            // La logique JS prendra le relais pour les interactions.
            ?>
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
    // Configuration globale pour le fichier JS externe
    window.budgetBuilderConfig = {
        projetId: <?= $projetId ?>,
        tauxContingence: <?= (float)$projet['taux_contingence'] ?>,
        csrfToken: '<?= generateCSRFToken() ?>'
    };
</script>
<script src="<?= url('/assets/js/budget-builder.js') ?>?v=<?= time() ?>"></script>

</final_file_content>

IMPORTANT: For any future changes to this file, use the final_file_content shown above as your reference. This content reflects the current state of the file, including any auto-formatting (e.g., if you used single quotes but the formatter converted them to double quotes). Always base your SEARCH/REPLACE operations on this final version to ensure accuracy.

<environment_details>
# Visual Studio Code Visible Files
flip/assets/js/budget-builder.js

# Visual Studio Code Open Tabs
flip/admin/templates/liste.php
flip/assets/css/tree-style.css
flip/admin/projets/detail.php
flip/admin/projets/budget-builder-content.php
flip/assets/js/budget-builder.js

# Recently Modified Files
These files have been modified since you last accessed them (file was just edited so you may need to re-read it before editing):
flip/assets/js/budget-builder.js

# Current Time
12/14/2025, 11:27:06 PM (America/Toronto, UTC-5:00)

# Context Window Usage
616,193 / 1,048.576K tokens used (59%)

# Current Mode
ACT MODE
</environment_details>
