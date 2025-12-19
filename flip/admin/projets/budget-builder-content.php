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
 * Afficher les sous-catégories de façon récursive pour le PROJET (côté droit)
 * $projetDirectDrops est passé pour EXCLURE les sous-catégories qui sont des direct drops (affichées séparément)
 * MAIS seulement si on n'est PAS dans le contexte d'une catégorie parente (sinon c'est une sous-cat normale)
 */
function afficherSousCategoriesRecursifProjet($sousCategories, $catId, $groupe, $projetItems, $projetSousCategories, $niveau = 0, $projetDirectDrops = []) {
    global $pdo, $projetId, $projetPostes;
    if (empty($sousCategories)) return;

    foreach ($sousCategories as $sc):
        // Si cette sous-catégorie est un direct drop ET que sa catégorie parente N'EST PAS dans le projet,
        // alors ne pas l'afficher ici (elle sera affichée comme entrée autonome)
        // MAIS si la catégorie parente EST dans le projet, c'est une sous-cat normale, on l'affiche
        if (isset($projetDirectDrops[$sc['id']]) && !isset($projetPostes[$catId])) continue;

        // Vérifier si cette sous-catégorie ou ses enfants ont des items dans le projet
        $scHasItems = false;
        $scBaseTotal = 0;
        $scItemCount = 0;
        $scQte = $projetSousCategories[$sc['id']] ?? 1;

        // Vérifier les matériaux directs
        foreach ($sc['materiaux'] ?? [] as $mat) {
            if (isset($projetItems[$catId][$mat['id']])) {
                $scHasItems = true;
                $item = $projetItems[$catId][$mat['id']];
                $scBaseTotal += (float)$item['prix_unitaire'] * (int)$item['quantite'];
                $scItemCount++;
            }
        }

        // Vérifier récursivement les enfants
        $hasChildrenWithItems = false;
        if (!empty($sc['enfants'])) {
            foreach ($sc['enfants'] as $enfant) {
                // Exclure les enfants qui sont des direct drops SEULEMENT si la catégorie parente n'est pas dans le projet
                if (isset($projetDirectDrops[$enfant['id']]) && !isset($projetPostes[$catId])) continue;
                if (sousCategorieHasItems($enfant, $catId, $projetItems, $projetSousCategories, $projetDirectDrops, $projetPostes)) {
                    $hasChildrenWithItems = true;
                    break;
                }
            }
        }

        // Vérifier si cette sous-catégorie est explicitement sauvegardée
        // (mais pas en direct drop autonome - si catégorie parente existe, c'est OK)
        $isExplicitlySaved = isset($projetSousCategories[$sc['id']]) &&
                             (!isset($projetDirectDrops[$sc['id']]) || isset($projetPostes[$catId]));

        // Si ni cette sc ni ses enfants n'ont d'items ET qu'elle n'est pas explicitement sauvegardée, skip
        if (!$scHasItems && !$hasChildrenWithItems && !$isExplicitlySaved) continue;

        $scTotal = $scBaseTotal * $scQte;
        ?>
        <!-- Sous-catégorie container (niveau <?= $niveau ?>) -->
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
                    <?= formatMoney($scTotal) ?>
                </span>

                <div class="btn-group btn-group-sm me-2">
                    <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-1 cat-qte-btn" data-cat-id="<?= $sc['id'] ?>" data-action="minus">
                        <i class="bi bi-dash"></i>
                    </button>
                    <span class="badge item-badge badge-qte text-light d-flex align-items-center px-2 cat-qte-display" data-cat-id="<?= $sc['id'] ?>"><?= $scQte ?></span>
                    <input type="hidden" class="cat-qte-input" data-cat-id="<?= $sc['id'] ?>" value="<?= $scQte ?>">
                    <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-1 cat-qte-btn" data-cat-id="<?= $sc['id'] ?>" data-action="plus">
                        <i class="bi bi-plus"></i>
                    </button>
                </div>

                <button type="button" class="btn btn-sm btn-link text-danger p-0" onclick="removeProjetItem(this)" title="Retirer">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>

            <!-- Contenu de la sous-catégorie -->
            <div class="collapse show tree-children" id="projetSc<?= $sc['id'] ?>">
                <?php // Matériaux de cette sous-catégorie ?>
                <?php foreach ($sc['materiaux'] ?? [] as $mat):
                    if (!isset($projetItems[$catId][$mat['id']])) continue;
                    $item = $projetItems[$catId][$mat['id']];
                    $qteItem = (int)$item['quantite'];
                    $prixItem = (float)$item['prix_unitaire'];
                    $totalItem = $prixItem * $qteItem;
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
                    <span class="badge item-badge badge-total text-success fw-bold me-1"><?= formatMoney($totalItem) ?></span>

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

                <?php // Récursion pour les sous-sous-catégories ?>
                <?php if (!empty($sc['enfants'])): ?>
                    <?php afficherSousCategoriesRecursifProjet($sc['enfants'], $catId, $groupe, $projetItems, $projetSousCategories, $niveau + 1, $projetDirectDrops); ?>
                <?php endif; ?>
            </div>
        </div>
        <?php
    endforeach;
}

/**
 * Vérifier si une sous-catégorie ou ses enfants ont des items dans le projet
 * OU si la sous-catégorie est enregistrée dans projet_sous_categories
 * Exclut les sous-catégories qui sont des direct drops AUTONOMES (sans catégorie parente dans le projet)
 */
function sousCategorieHasItems($sc, $catId, $projetItems, $projetSousCategories = [], $projetDirectDrops = [], $projetPostes = []) {
    // Si c'est un direct drop ET que la catégorie parente n'est pas dans le projet,
    // on retourne false (sera affiché séparément comme entrée autonome)
    if (isset($projetDirectDrops[$sc['id']]) && !isset($projetPostes[$catId])) {
        return false;
    }
    // Vérifier si cette sous-catégorie est dans projet_sous_categories
    if (isset($projetSousCategories[$sc['id']])) {
        return true;
    }
    // Vérifier les matériaux directs
    foreach ($sc['materiaux'] ?? [] as $mat) {
        if (isset($projetItems[$catId][$mat['id']])) {
            return true;
        }
    }
    // Vérifier récursivement les enfants
    foreach ($sc['enfants'] ?? [] as $enfant) {
        if (sousCategorieHasItems($enfant, $catId, $projetItems, $projetSousCategories, $projetDirectDrops, $projetPostes)) {
            return true;
        }
    }
    return false;
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
// IMPORTANT: On doit exclure les matériaux des direct drops du calcul des catégories
// car ils sont comptés séparément
$totalProjetHT = 0;
$totalProjetTaxable = 0;
$totalProjetNonTaxable = 0;

// S'assurer que $projetDirectDrops existe
if (!isset($projetDirectDrops)) $projetDirectDrops = [];

// Fonction helper pour vérifier si une sous-catégorie (ou un de ses parents) est un direct drop
function isOrHasDirectDropParent($scId, $templatesBudgets, $projetDirectDrops) {
    // Vérifier si cette sous-catégorie est un direct drop
    if (isset($projetDirectDrops[$scId])) {
        return true;
    }
    return false;
}

foreach ($projetPostes as $catId => $poste) {
    $groupe = $templatesBudgets[$catId]['groupe'] ?? 'autre';
    $qteGroupe = $projetGroupes[$groupe] ?? 1;
    $qteCat = (int)$poste['quantite'];

    if (isset($templatesBudgets[$catId]['sous_categories'])) {
        foreach ($templatesBudgets[$catId]['sous_categories'] as $sc) {
            // Les sous-catégories qui sont des direct drops AUTONOMES (sans catégorie parente)
            // sont comptées séparément. Mais ici on a une catégorie parente ($catId est dans $projetPostes),
            // donc on inclut TOUTES les sous-catégories de cette catégorie.

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

// Ajouter les totaux des direct drops AUTONOMES (ceux dont la catégorie parente N'EST PAS dans le projet)
foreach ($projetDirectDrops as $scId => $dropInfo) {
    $scQte = (int)$dropInfo['quantite'];
    $scGroupe = $dropInfo['groupe'];
    $qteGroupe = $projetGroupes[$scGroupe] ?? 1;

    // Trouver la catégorie parente et les matériaux de cette sous-catégorie
    foreach ($templatesBudgets as $cId => $cat) {
        // Si la catégorie parente EST dans le projet, ne pas compter ici (déjà compté ci-dessus)
        if (isset($projetPostes[$cId])) continue;

        foreach ($cat['sous_categories'] ?? [] as $sc) {
            if ($sc['id'] == $scId) {
                foreach ($sc['materiaux'] as $mat) {
                    if (isset($projetItems[$cId][$mat['id']])) {
                        $item = $projetItems[$cId][$mat['id']];
                        $prix = (float)$item['prix_unitaire'];
                        $qte = (int)($item['quantite'] ?? 1);
                        $sansTaxe = (int)($item['sans_taxe'] ?? 0);
                        $montant = $prix * $qte * $scQte * $qteGroupe;

                        if ($sansTaxe) {
                            $totalProjetNonTaxable += $montant;
                        } else {
                            $totalProjetTaxable += $montant;
                        }
                    }
                }
                break 2;
            }
            // Chercher dans les enfants
            foreach ($sc['enfants'] ?? [] as $enfant) {
                if ($enfant['id'] == $scId) {
                    foreach ($enfant['materiaux'] as $mat) {
                        if (isset($projetItems[$cId][$mat['id']])) {
                            $item = $projetItems[$cId][$mat['id']];
                            $prix = (float)$item['prix_unitaire'];
                            $qte = (int)($item['quantite'] ?? 1);
                            $sansTaxe = (int)($item['sans_taxe'] ?? 0);
                            $montant = $prix * $qte * $scQte * $qteGroupe;

                            if ($sansTaxe) {
                                $totalProjetNonTaxable += $montant;
                            } else {
                                $totalProjetTaxable += $montant;
                            }
                        }
                    }
                    break 3;
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

<!-- Espace réservé pour compenser la barre fixe -->
<div id="budgetBarPlaceholder" style="height: 42px; margin-bottom: 0.5rem;"></div>

<!-- Barre de totaux fixe -->
<div id="budgetTotalsBar" class="bg-primary text-white" style="font-size: 0.85rem; position: fixed; left: 0; right: 0; z-index: 1000;">
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

<script>
(function() {
    const bar = document.getElementById('budgetTotalsBar');
    const tabs = document.getElementById('projetTabs');
    const spacing = 8;

    function positionBar() {
        if (!bar || !tabs) return;

        const tabsRect = tabs.getBoundingClientRect();

        // Positionner juste sous les onglets avec un petit espace
        if (tabsRect.bottom > 0) {
            bar.style.top = (tabsRect.bottom + spacing) + 'px';
        } else {
            bar.style.top = spacing + 'px';
        }
    }

    // Attendre que la page soit complètement chargée
    if (document.readyState === 'complete') {
        positionBar();
    } else {
        window.addEventListener('load', positionBar);
    }

    // Aussi positionner après un court délai pour être sûr
    setTimeout(positionBar, 100);
    setTimeout(positionBar, 300);

    window.addEventListener('scroll', positionBar);
    window.addEventListener('resize', positionBar);
})();
</script>

<!-- Container principal -->
<div class="budget-builder">

    <!-- ========================================
         COLONNE GAUCHE: CATALOGUE TEMPLATES
         ======================================== -->
    <div class="catalogue-panel builder-panel" id="cataloguePanel">
        <div class="panel-header">
            <i class="bi bi-shop"></i>
            Magasin
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
                Panier
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
                    <span id="saveIndicator" style="color: #ffffff;"><i class="bi bi-cloud-check me-1"></i><span id="saveText">Auto-save</span></span>
                </div>
                <style>
                    #saveIndicator {
                        color: #ffffff !important;
                        padding: 2px 8px;
                        border-radius: 4px;
                        transition: background-color 0.5s ease;
                    }
                    #saveIndicator.saving {
                        color: #ffc107 !important;
                    }
                    #saveIndicator.saved {
                        background-color: rgba(255, 255, 255, 0.3);
                        font-weight: bold;
                    }

                    /* Flash blanc sur les éléments modifiés */
                    .flash-modified {
                        animation: flashModified 0.5s ease-in-out 3;
                    }
                    @keyframes flashModified {
                        0% { background-color: transparent; }
                        50% { background-color: rgba(255, 255, 255, 0.3); }
                        100% { background-color: transparent; }
                    }
                </style>
            </div>
        </div>
        <div class="panel-content" id="projetContent">
            <?php
            // Assurer que $projetDirectDrops existe
            if (!isset($projetDirectDrops)) $projetDirectDrops = [];

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

                // Trouver les direct drops pour ce groupe
                $directDropsForGroupe = [];
                foreach ($projetDirectDrops as $scId => $dropInfo) {
                    if ($dropInfo['groupe'] === $groupe) {
                        $directDropsForGroupe[$scId] = $dropInfo;
                    }
                }

                if (!empty($groupeItems) || !empty($directDropsForGroupe)) $hasAnyItems = true;
            ?>
            <div class="projet-groupe mb-3" data-groupe="<?= $groupe ?>" style="<?= (empty($groupeItems) && empty($directDropsForGroupe)) ? 'display:none;' : '' ?>">
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

                        // Calculer le total de la catégorie
                        // Total = (somme des sous-catégories avec leur qté) × sa propre qté (PAS de multiplicateur groupe)
                        // Comme cette catégorie est dans le projet, on inclut TOUTES ses sous-catégories
                        $catTotal = 0;
                        $nbItemsCat = 0;
                        foreach ($cat['sous_categories'] ?? [] as $sc) {
                            $scQteCalc = $projetSousCategories[$sc['id']] ?? 1;
                            $scSubTotal = 0;
                            foreach ($sc['materiaux'] ?? [] as $mat) {
                                if (isset($projetItems[$catId][$mat['id']])) {
                                    $item = $projetItems[$catId][$mat['id']];
                                    $scSubTotal += (float)$item['prix_unitaire'] * (int)$item['quantite'];
                                    $nbItemsCat++;
                                }
                            }
                            $catTotal += $scSubTotal * $scQteCalc; // Multiplier par la qté de la sous-catégorie
                        }
                        $catTotal *= $qteCat; // Multiplier par la qté de la catégorie
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
                                <?= formatMoney($catTotal) ?>
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

                        <!-- Détail des items - avec sous-catégories (récursif) -->
                        <div class="collapse show tree-children" id="projetContent<?= $catId ?>">
                            <?php afficherSousCategoriesRecursifProjet($cat['sous_categories'] ?? [], $catId, $groupe, $projetItems, $projetSousCategories, 0, $projetDirectDrops); ?>
                        </div>
                    </div>
                    <?php endforeach; ?>

                    <?php
                    // Afficher les sous-catégories droppées directement (direct drops) comme entrées autonomes
                    // SEULEMENT si leur catégorie parente N'EST PAS dans le projet (sinon elles sont déjà affichées dans la catégorie)
                    foreach ($directDropsForGroupe as $scId => $dropInfo):
                        // Récupérer les infos de la sous-catégorie depuis le catalogue
                        $scData = null;
                        $parentCatId = null;
                        foreach ($templatesBudgets as $cId => $cat) {
                            foreach ($cat['sous_categories'] ?? [] as $sc) {
                                if ($sc['id'] == $scId) {
                                    $scData = $sc;
                                    $parentCatId = $cId;
                                    break 2;
                                }
                                // Chercher aussi dans les enfants
                                foreach ($sc['enfants'] ?? [] as $enfant) {
                                    if ($enfant['id'] == $scId) {
                                        $scData = $enfant;
                                        $parentCatId = $cId;
                                        break 3;
                                    }
                                    // Niveau 3
                                    foreach ($enfant['enfants'] ?? [] as $enfant2) {
                                        if ($enfant2['id'] == $scId) {
                                            $scData = $enfant2;
                                            $parentCatId = $cId;
                                            break 4;
                                        }
                                    }
                                }
                            }
                        }

                        if (!$scData) continue;

                        // Si la catégorie parente EST dans le projet, ne pas afficher ici (déjà dans la catégorie)
                        if (isset($projetPostes[$parentCatId])) continue;

                        $scQte = $dropInfo['quantite'];
                        $scTotal = 0;
                        $nbItemsSc = 0;

                        // Calculer le total de cette sous-catégorie
                        foreach ($scData['materiaux'] ?? [] as $mat) {
                            if (isset($projetItems[$parentCatId][$mat['id']])) {
                                $item = $projetItems[$parentCatId][$mat['id']];
                                $scTotal += (float)$item['prix_unitaire'] * (int)$item['quantite'];
                                $nbItemsSc++;
                            }
                        }
                        $scTotal *= $scQte;
                    ?>
                    <div class="tree-item mb-1 is-kit projet-item"
                         data-type="sous_categorie"
                         data-id="<?= $scId ?>"
                         data-cat-id="<?= $parentCatId ?>"
                         data-sc-ordre="<?= $scData['ordre'] ?? 0 ?>"
                         data-unique-id="sous_categorie-<?= $scId ?>"
                         data-groupe="<?= $groupe ?>"
                         data-prix="<?= $scTotal ?>">
                        <div class="tree-content">
                            <i class="bi bi-grip-vertical drag-handle"></i>
                            <span class="tree-toggle" onclick="toggleTreeItem(this, 'projetDirectDrop<?= $scId ?>')">
                                <i class="bi bi-caret-down-fill"></i>
                            </span>
                            <div class="type-icon">
                                <i class="bi bi-folder text-warning"></i>
                            </div>
                            <strong class="flex-grow-1"><?= e($scData['nom']) ?></strong>

                            <span class="badge item-badge badge-count text-info me-1">
                                <i class="bi bi-box-seam me-1"></i><span class="item-count"><?= $nbItemsSc ?></span>
                            </span>

                            <span class="badge item-badge badge-total text-success fw-bold cat-total me-1" data-cat-id="<?= $scId ?>">
                                <?= formatMoney($scTotal) ?>
                            </span>

                            <div class="btn-group btn-group-sm me-2">
                                <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-1 cat-qte-btn" data-cat-id="<?= $scId ?>" data-action="minus">
                                    <i class="bi bi-dash"></i>
                                </button>
                                <span class="badge item-badge badge-qte text-light d-flex align-items-center px-2 cat-qte-display" data-cat-id="<?= $scId ?>"><?= $scQte ?></span>
                                <input type="hidden" class="cat-qte-input" data-cat-id="<?= $scId ?>" value="<?= $scQte ?>">
                                <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-1 cat-qte-btn" data-cat-id="<?= $scId ?>" data-action="plus">
                                    <i class="bi bi-plus"></i>
                                </button>
                            </div>

                            <button type="button" class="btn btn-sm btn-link text-danger p-0" onclick="removeProjetItem(this)" title="Retirer">
                                <i class="bi bi-x-lg"></i>
                            </button>
                        </div>

                        <!-- Contenu de la sous-catégorie direct drop -->
                        <div class="collapse show tree-children" id="projetDirectDrop<?= $scId ?>">
                            <?php
                            // Afficher les matériaux de cette sous-catégorie
                            foreach ($scData['materiaux'] ?? [] as $mat):
                                if (!isset($projetItems[$parentCatId][$mat['id']])) continue;
                                $item = $projetItems[$parentCatId][$mat['id']];
                                $qteItem = (int)$item['quantite'];
                                $prixItem = (float)$item['prix_unitaire'];
                                $totalItem = $prixItem * $qteItem;
                            ?>
                            <div class="tree-content mat-item projet-mat-item"
                                 data-mat-id="<?= $mat['id'] ?>"
                                 data-mat-ordre="<?= $mat['ordre'] ?? 0 ?>"
                                 data-cat-id="<?= $scId ?>"
                                 data-prix="<?= $prixItem ?>"
                                 data-qte="<?= $qteItem ?>"
                                 data-sans-taxe="<?= !empty($item['sans_taxe']) ? 1 : 0 ?>">
                                <span class="tree-connector">└►</span>
                                <i class="bi bi-grip-vertical drag-handle" style="font-size: 0.85em;"></i>
                                <div class="type-icon"><i class="bi bi-box-seam text-primary small"></i></div>
                                <span class="flex-grow-1 small"><?= e($mat['nom']) ?></span>

                                <span class="badge item-badge badge-prix text-info me-1 editable-prix" role="button" title="Cliquer pour modifier"><?= formatMoney($prixItem) ?></span>
                                <span class="badge item-badge badge-total text-success fw-bold me-1"><?= formatMoney($totalItem) ?></span>

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

                            <?php
                            // Afficher les sous-sous-catégories (enfants)
                            if (!empty($scData['enfants'])):
                                afficherSousCategoriesRecursifProjet($scData['enfants'], $parentCatId, $groupe, $projetItems, $projetSousCategories, 0, $projetDirectDrops);
                            endif;
                            ?>
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

            const total = prix * newQte;
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

    // Fonction pour collecter tous les descendants d'un élément du catalogue
    function collectDescendants(element) {
        const descendants = [];
        const treeItem = element.closest('.tree-item');
        if (!treeItem) return descendants;

        const childrenContainer = treeItem.querySelector('.tree-children');
        if (!childrenContainer) return descendants;

        // Collecter les sous-catégories
        childrenContainer.querySelectorAll(':scope > .tree-item').forEach(subItem => {
            const subDraggable = subItem.querySelector(':scope > .tree-content.catalogue-draggable');
            if (subDraggable && subDraggable.dataset.type === 'sous_categorie') {
                const subData = {
                    type: 'sous_categorie',
                    id: subDraggable.dataset.id,
                    nom: subDraggable.dataset.nom,
                    scOrdre: parseInt(subDraggable.dataset.scOrdre) || 0,
                    materiaux: [],
                    enfants: []
                };

                // Collecter les matériaux de cette sous-catégorie
                const subChildren = subItem.querySelector('.tree-children');
                if (subChildren) {
                    subChildren.querySelectorAll(':scope > .mat-item.catalogue-draggable').forEach(matEl => {
                        subData.materiaux.push({
                            type: 'materiau',
                            id: matEl.dataset.id,
                            nom: matEl.dataset.nom,
                            prix: parseFloat(matEl.dataset.prix) || 0,
                            qte: parseInt(matEl.dataset.qte) || 1,
                            matOrdre: parseInt(matEl.dataset.matOrdre) || 0,
                            sansTaxe: matEl.dataset.sansTaxe === '1'
                        });
                    });

                    // Récursion pour les sous-sous-catégories
                    subChildren.querySelectorAll(':scope > .tree-item').forEach(subSubItem => {
                        const subSubDraggable = subSubItem.querySelector(':scope > .tree-content.catalogue-draggable');
                        if (subSubDraggable && subSubDraggable.dataset.type === 'sous_categorie') {
                            subData.enfants.push(collectSousCategorie(subSubItem));
                        }
                    });
                }

                descendants.push(subData);
            }
        });

        // Collecter les matériaux directs (à ce niveau)
        childrenContainer.querySelectorAll(':scope > .mat-item.catalogue-draggable').forEach(matEl => {
            descendants.push({
                type: 'materiau',
                id: matEl.dataset.id,
                nom: matEl.dataset.nom,
                prix: parseFloat(matEl.dataset.prix) || 0,
                qte: parseInt(matEl.dataset.qte) || 1,
                matOrdre: parseInt(matEl.dataset.matOrdre) || 0,
                sansTaxe: matEl.dataset.sansTaxe === '1'
            });
        });

        return descendants;
    }

    // Fonction récursive pour collecter une sous-catégorie et ses enfants
    function collectSousCategorie(treeItem) {
        const draggable = treeItem.querySelector(':scope > .tree-content.catalogue-draggable');
        if (!draggable) return null;

        const data = {
            type: 'sous_categorie',
            id: draggable.dataset.id,
            nom: draggable.dataset.nom,
            scOrdre: parseInt(draggable.dataset.scOrdre) || 0,
            materiaux: [],
            enfants: []
        };

        const childrenContainer = treeItem.querySelector('.tree-children');
        if (childrenContainer) {
            // Matériaux
            childrenContainer.querySelectorAll(':scope > .mat-item.catalogue-draggable').forEach(matEl => {
                data.materiaux.push({
                    type: 'materiau',
                    id: matEl.dataset.id,
                    nom: matEl.dataset.nom,
                    prix: parseFloat(matEl.dataset.prix) || 0,
                    qte: parseInt(matEl.dataset.qte) || 1,
                    matOrdre: parseInt(matEl.dataset.matOrdre) || 0,
                    sansTaxe: matEl.dataset.sansTaxe === '1'
                });
            });

            // Sous-sous-catégories (récursion)
            childrenContainer.querySelectorAll(':scope > .tree-item').forEach(subItem => {
                const subDraggable = subItem.querySelector(':scope > .tree-content.catalogue-draggable');
                if (subDraggable && subDraggable.dataset.type === 'sous_categorie') {
                    data.enfants.push(collectSousCategorie(subItem));
                }
            });
        }

        return data;
    }

    document.querySelectorAll('.catalogue-draggable').forEach(item => {
        item.addEventListener('dragstart', function(e) {
            const dragData = {
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
            };

            // Si c'est une catégorie ou sous-catégorie, collecter les descendants
            if (this.dataset.type === 'categorie' || this.dataset.type === 'sous_categorie') {
                dragData.descendants = collectDescendants(this);
                console.log('Collected descendants:', dragData.descendants);
            }

            console.log('Drag data:', dragData);
            e.dataTransfer.setData('text/plain', JSON.stringify(dragData));
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
    // CRÉER LES DESCENDANTS RÉCURSIVEMENT
    // ========================================
    function createDescendantsInContainer(container, descendants, parentCatId, groupe) {
        if (!container || !descendants || descendants.length === 0) return;

        descendants.forEach(item => {
            if (item.type === 'sous_categorie') {
                // Créer la sous-catégorie
                const scContentId = `projetContentSousCategorie${item.id}_${Date.now()}`;
                const scHtml = `
                    <div class="tree-item mb-1 is-kit projet-item"
                         data-type="sous_categorie"
                         data-id="${item.id}"
                         data-cat-id="${parentCatId}"
                         data-sc-ordre="${item.scOrdre || 0}"
                         data-unique-id="sous_categorie-${item.id}"
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
                            <strong class="flex-grow-1">${escapeHtml(item.nom)}</strong>

                            <span class="badge item-badge badge-count text-info me-1">
                                <i class="bi bi-box-seam me-1"></i><span class="item-count">0</span>
                            </span>

                            <span class="badge item-badge badge-total text-success fw-bold cat-total me-1" data-cat-id="${item.id}">
                                ${formatMoney(0)}
                            </span>

                            <div class="btn-group btn-group-sm me-2">
                                <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-1 cat-qte-btn" data-cat-id="${item.id}" data-action="minus">
                                    <i class="bi bi-dash"></i>
                                </button>
                                <span class="badge item-badge badge-qte text-light d-flex align-items-center px-2 cat-qte-display" data-cat-id="${item.id}">1</span>
                                <input type="hidden" class="cat-qte-input" data-cat-id="${item.id}" value="1">
                                <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-1 cat-qte-btn" data-cat-id="${item.id}" data-action="plus">
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
                container.appendChild(scElement);

                const scChildrenContainer = scElement.querySelector('.tree-children');

                // Créer les matériaux de cette sous-catégorie
                if (item.materiaux && item.materiaux.length > 0) {
                    item.materiaux.forEach(mat => {
                        createMaterialElement(scChildrenContainer, mat, item.id);
                    });
                }

                // Récursion pour les sous-sous-catégories
                if (item.enfants && item.enfants.length > 0) {
                    createDescendantsInContainer(scChildrenContainer, item.enfants, parentCatId, groupe);
                }

                // Mettre à jour les stats de la sous-catégorie
                updateSousCategorieStats(scElement);

            } else if (item.type === 'materiau') {
                // Créer le matériau directement dans le container
                createMaterialElement(container, item, parentCatId);
            }
        });
    }

    // Créer un élément matériau
    function createMaterialElement(container, mat, catId) {
        const itemTotal = (parseFloat(mat.prix) || 0) * (parseInt(mat.qte) || 1);
        const matHtml = `
            <div class="tree-content mat-item projet-mat-item"
                 data-mat-id="${mat.id}"
                 data-mat-ordre="${mat.matOrdre || 0}"
                 data-cat-id="${catId}"
                 data-prix="${mat.prix || 0}"
                 data-qte="${mat.qte || 1}"
                 data-sans-taxe="${mat.sansTaxe ? 1 : 0}">
                <span class="tree-connector">└►</span>
                <i class="bi bi-grip-vertical drag-handle" style="font-size: 0.85em;"></i>
                <div class="type-icon"><i class="bi bi-box-seam text-primary small"></i></div>
                <span class="flex-grow-1 small">${escapeHtml(mat.nom)}</span>

                <span class="badge item-badge badge-prix text-info me-1 editable-prix" role="button" title="Cliquer pour modifier">${formatMoney(parseFloat(mat.prix) || 0)}</span>
                <span class="badge item-badge badge-total text-success fw-bold me-1">${formatMoney(itemTotal)}</span>

                <div class="btn-group btn-group-sm me-2">
                    <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-1 mat-qte-btn" data-action="minus">
                        <i class="bi bi-dash"></i>
                    </button>
                    <span class="badge item-badge badge-qte text-light d-flex align-items-center px-2 mat-qte-display">${mat.qte || 1}</span>
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

            // Créer la catégorie avec ses descendants
            const hasDescendants = data.descendants && data.descendants.length > 0;
            const itemHtml = `
                <div class="tree-item mb-1 is-kit projet-item"
                     data-type="${data.type}"
                     data-id="${data.id}"
                     data-cat-id="${containerId}"
                     data-cat-ordre="${data.catOrdre || 0}"
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
                    <div class="collapse show tree-children" id="${contentId}"></div>
                </div>
            `;

            const catElement = createElementFromHTML(itemHtml);
            insertInOrder(zone, catElement, 'catOrdre', data.catOrdre || 0);

            // Si on a des descendants, les créer immédiatement
            if (hasDescendants) {
                const createdCat = zone.querySelector(`.projet-item[data-unique-id="${uniqueId}"]`);
                const childrenContainer = createdCat.querySelector('.tree-children');

                // Créer tous les descendants récursivement
                createDescendantsInContainer(childrenContainer, data.descendants, data.id, groupe);

                // Mettre à jour les stats
                updateCategoryStats(createdCat);
            }
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
                        <span class="badge item-badge badge-total text-success fw-bold me-1">${formatMoney(itemTotal)}</span>

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

        // Préparer les données avec les descendants si présents
        const postData = {
            ajax_action: 'add_dropped_item',
            type: data.type,
            item_id: data.id,
            cat_id: saveId,
            groupe: groupe,
            prix: data.prix || 0,
            qte: data.qte || 1,
            csrf_token: csrfToken
        };

        // Si on a des descendants, les inclure
        if (data.descendants && data.descendants.length > 0) {
            postData.descendants = JSON.stringify(data.descendants);
            console.log('Sending descendants to server:', data.descendants);
        }

        console.log('POST data being sent:', postData);
        fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(postData)
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

            // Si on avait des descendants, ils ont déjà été créés par createDescendantsInContainer
            // On ne doit PAS écraser le container avec les items retournés par le serveur
            const hasDescendants = data.descendants && data.descendants.length > 0;

            if (isCategory && !hasDescendants && result.added_items && result.added_items.length > 0) {
                // PAS de descendants - utiliser la réponse du serveur pour créer les items
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
                                <span class="badge item-badge badge-total text-success fw-bold me-1">${formatMoney(itemTotal)}</span>

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

                    console.log(`Added ${result.added_items.length} sub-items to category (no descendants)`);
                }
            } else if (isCategory && hasDescendants) {
                // Descendants déjà créés par createDescendantsInContainer - juste mettre à jour les stats
                const projetItem = zone.querySelector(`.projet-item[data-unique-id="${uniqueId}"]`);
                if (projetItem) {
                    updateCategoryStats(projetItem);
                }
                console.log(`Descendants already created, saved ${result.added_items.length} items to DB`);
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
        if (totalSpan) totalSpan.textContent = formatMoney(catTotal);

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

    // Collecter tous les multiplicateurs de la chaîne de parents
    function getChainMultipliers(element) {
        let multiplier = 1;
        let current = element.closest('.projet-item');

        // Parcourir tous les parents projet-item pour collecter leurs quantités
        while (current) {
            const qteInput = current.querySelector(':scope > .tree-content .cat-qte-input');
            if (qteInput) {
                multiplier *= parseInt(qteInput.value) || 1;
            }
            current = current.parentElement ? current.parentElement.closest('.projet-item') : null;
        }

        return multiplier;
    }

    // Met à jour le total affiché d'un container (catégorie ou sous-catégorie)
    // Total = (somme des enfants directs) × sa propre qté
    // PAS de multiplicateur des parents, PAS de taxes
    function updateSousCategorieStats(scContainer) {
        if (!scContainer) return;

        const directChildren = scContainer.querySelector('.tree-children');

        // Somme des matériaux directs (leur prix × leur qté)
        let childrenTotal = 0;
        const directMatItems = directChildren ? directChildren.querySelectorAll(':scope > .projet-mat-item') : [];
        directMatItems.forEach(matItem => {
            const prix = parseFloat(matItem.dataset.prix) || 0;
            const qte = parseInt(matItem.dataset.qte) || 1;
            childrenTotal += prix * qte;
        });

        // Ajouter les totaux des sous-catégories enfants (leur total affiché × leur qté)
        const childContainers = directChildren ? directChildren.querySelectorAll(':scope > .projet-item') : [];
        childContainers.forEach(childContainer => {
            const childTotal = getContainerTotal(childContainer);
            const childQteInput = childContainer.querySelector(':scope > .tree-content .cat-qte-input');
            const childQte = childQteInput ? parseInt(childQteInput.value) || 1 : 1;
            childrenTotal += childTotal * childQte;
        });

        // Le total de CE container = somme des enfants × SA propre qté
        const myQteInput = scContainer.querySelector(':scope > .tree-content .cat-qte-input');
        const myQte = myQteInput ? parseInt(myQteInput.value) || 1 : 1;
        const myTotal = childrenTotal * myQte;

        // Compter tous les matériaux (y compris dans les sous-catégories)
        const allMatItems = scContainer.querySelectorAll('.projet-mat-item');
        const countSpan = scContainer.querySelector(':scope > .tree-content .item-count');
        if (countSpan) countSpan.textContent = allMatItems.length;

        const totalSpan = scContainer.querySelector(':scope > .tree-content .cat-total');
        if (totalSpan) totalSpan.textContent = formatMoney(myTotal);

        // Flash sur le tree-content de la catégorie/sous-catégorie
        const treeContent = scContainer.querySelector(':scope > .tree-content');
        if (treeContent) flashElement(treeContent);
    }

    // Obtenir le total brut d'un container (somme enfants SANS multiplier par sa propre qté)
    // Le parent est responsable de multiplier par la qté de l'enfant
    function getContainerTotal(container) {
        const directChildren = container.querySelector('.tree-children');

        let childrenTotal = 0;

        // Matériaux directs
        const directMatItems = directChildren ? directChildren.querySelectorAll(':scope > .projet-mat-item') : [];
        directMatItems.forEach(matItem => {
            const prix = parseFloat(matItem.dataset.prix) || 0;
            const qte = parseInt(matItem.dataset.qte) || 1;
            childrenTotal += prix * qte;
        });

        // Sous-containers (on multiplie par leur qté car c'est nous le parent)
        const childContainers = directChildren ? directChildren.querySelectorAll(':scope > .projet-item') : [];
        childContainers.forEach(childContainer => {
            const childRawTotal = getContainerTotal(childContainer);
            const childQteInput = childContainer.querySelector(':scope > .tree-content .cat-qte-input');
            const childQte = childQteInput ? parseInt(childQteInput.value) || 1 : 1;
            childrenTotal += childRawTotal * childQte;
        });

        // NE PAS multiplier par sa propre qté ici - c'est le parent qui le fera
        return childrenTotal;
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

    // Helper pour mettre à jour le total d'un matériau (sans multiplicateurs parents)
    // Le matériau affiche son propre total: prix × sa qté
    // Les parents sont responsables de multiplier par leur qté
    function updateMaterialTotal(matItem) {
        const prix = parseFloat(matItem.dataset.prix) || 0;
        const qte = parseInt(matItem.dataset.qte) || 1;

        // Total = prix × qté (PAS de multiplicateur parent)
        const total = prix * qte;
        const badge = matItem.querySelector('.badge-total');
        if (badge) badge.textContent = formatMoney(total);

        // Flash sur le matériau modifié
        flashElement(matItem);
    }

    // Fonction pour faire flasher un élément en blanc
    function flashElement(el) {
        if (!el) return;
        el.classList.remove('flash-modified');
        el.offsetHeight; // Force reflow
        el.classList.add('flash-modified');
        // Nettoyer après l'animation
        setTimeout(() => el.classList.remove('flash-modified'), 1500);
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

    // Calculer le total HT pour le volet Détail (avec tous les multiplicateurs de la chaîne)
    // Cette fonction multiplie par tous les parents + groupe
    function calculateTotalHTForDetail(container, parentMultiplier) {
        const directChildren = container.querySelector('.tree-children');
        let totalTaxable = 0;
        let totalNonTaxable = 0;

        const qteInput = container.querySelector(':scope > .tree-content .cat-qte-input');
        const containerQte = qteInput ? parseInt(qteInput.value) || 1 : 1;
        const currentMultiplier = parentMultiplier * containerQte;

        if (directChildren) {
            directChildren.querySelectorAll(':scope > .projet-mat-item').forEach(matItem => {
                const prix = parseFloat(matItem.dataset.prix) || 0;
                const qte = parseInt(matItem.dataset.qte) || 1;
                const sansTaxe = matItem.dataset.sansTaxe === '1';
                const ht = prix * qte * currentMultiplier;

                if (sansTaxe) {
                    totalNonTaxable += ht;
                } else {
                    totalTaxable += ht;
                }
            });

            directChildren.querySelectorAll(':scope > .projet-item').forEach(childContainer => {
                const childTotals = calculateTotalHTForDetail(childContainer, currentMultiplier);
                totalTaxable += childTotals.taxable;
                totalNonTaxable += childTotals.nonTaxable;
            });
        }

        return { taxable: totalTaxable, nonTaxable: totalNonTaxable };
    }

    function updateTotals() {

        let totalHT = 0;
        let totalTaxable = 0;
        let totalNonTaxable = 0;

        // Parcourir les catégories de premier niveau
        const allCatItems = document.querySelectorAll('.projet-drop-zone > .projet-item');

        allCatItems.forEach(catItem => {
            const groupe = catItem.dataset.groupe;

            const groupeQteInput = document.querySelector(`.groupe-qte-input[data-groupe="${groupe}"]`);
            const qteGroupe = groupeQteInput ? parseInt(groupeQteInput.value) || 1 : 1;

            // Pour le volet Détail: calculer avec TOUS les multiplicateurs (groupe inclus)
            const catTotals = calculateTotalHTForDetail(catItem, qteGroupe);

            totalTaxable += catTotals.taxable;
            totalNonTaxable += catTotals.nonTaxable;
            totalHT += catTotals.taxable + catTotals.nonTaxable;

            // Pour le volet Budget: afficher le total du container (somme enfants × sa qté)
            // PAS de multiplicateur groupe, PAS de taxes
            updateSousCategorieStats(catItem);
        });

        // Calculer contingence et taxes pour le volet Détail
        const contingence = totalHT * (tauxContingence / 100);
        const tps = totalTaxable * 0.05;
        const tvq = totalTaxable * 0.09975;
        const grandTotal = totalHT + contingence + tps + tvq;

        const totalHTEl = document.getElementById('totalHT');
        totalHTEl.textContent = formatMoney(totalHT);
        // Flash le total pour montrer qu'il a été mis à jour
        flashElement(totalHTEl);

        document.getElementById('totalContingence').textContent = formatMoney(contingence);
        document.getElementById('grandTotal').textContent = formatMoney(grandTotal);

        // Mettre à jour aussi "Détail des coûts" si présent
        // Calculer les totaux pour les catégories racines (enfants directs de drop-zone)
        const categoryTotals = {};
        document.querySelectorAll('.projet-drop-zone > .projet-item[data-type="categorie"]').forEach(catItem => {
            const catId = catItem.dataset.id;
            const groupe = catItem.dataset.groupe;
            const groupeQteInput = document.querySelector(`.groupe-qte-input[data-groupe="${groupe}"]`);
            const qteGroupe = groupeQteInput ? parseInt(groupeQteInput.value) || 1 : 1;

            // Utiliser la fonction récursive pour calculer avec tous les multiplicateurs
            const catTotals = calculateTotalHTForDetail(catItem, qteGroupe);
            const catTotal = catTotals.taxable + catTotals.nonTaxable;

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
        const indicator = document.getElementById('saveIndicator');
        const text = document.getElementById('saveText');

        // Enlever toutes les classes
        indicator.classList.remove('saving', 'saved');

        if (status === 'saving') {
            indicator.classList.add('saving');
            text.textContent = 'Sauvegarde...';
        } else if (status === 'saved') {
            indicator.classList.add('saved');
            text.textContent = 'Sauvegardé!';
        } else {
            // Retour à l'état normal avec fade out du background
            indicator.style.backgroundColor = '';
            text.textContent = 'Auto-save';
        }
    }

    function autoSave() {
        if (saveTimeout) clearTimeout(saveTimeout);

        // Délai réduit à 200ms pour réponse plus rapide
        saveTimeout = setTimeout(function() {
            showSaveStatus('saving');

            const items = [];
            const groupes = {};

            document.querySelectorAll('.projet-item').forEach(item => {
                // Utiliser :scope pour cibler l'input direct de cet item, pas celui d'un enfant
                const catQteInput = item.querySelector(':scope > .tree-content .cat-qte-input');
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
                    // Flash rouge pendant 3 secondes
                    setTimeout(() => showSaveStatus('idle'), 3000);
                } else {
                    console.error('Save error:', data.error);
                    showSaveStatus('idle');
                }
            })
            .catch(error => {
                console.error('Network error:', error);
                showSaveStatus('idle');
            });
        }, 200);
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
            totalBadge.textContent = formatMoney(prix * currentQte);
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

        // Mettre à jour le total de la ligne (avec tous les multiplicateurs de la chaîne)
        updateMaterialTotal(matItem);

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

                const total = newPrix * qte;
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

                const total = newPrix * qte;
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
        // Trouver l'input et display DIRECTEMENT dans le tree-content de cet item (pas des enfants)
        const catQteInput = catItem.querySelector(':scope > .tree-content .cat-qte-input');
        const catQteDisplay = catItem.querySelector(':scope > .tree-content .cat-qte-display');

        let currentQte = parseInt(catQteInput.value) || 1;

        if (action === 'plus') {
            currentQte++;
        } else if (action === 'minus' && currentQte > 1) {
            currentQte--;
        }

        catQteInput.value = currentQte;
        catQteDisplay.textContent = currentQte;

        // Mettre à jour les totaux de tous les matériaux (directs et dans sous-catégories)
        const matItems = catItem.querySelectorAll('.projet-mat-item');
        matItems.forEach(matItem => {
            updateMaterialTotal(matItem);
        });

        // Mettre à jour les sous-catégories enfants (de bas en haut pour les totaux corrects)
        const sousCategories = Array.from(catItem.querySelectorAll('.projet-item[data-type="sous_categorie"]'));
        // Trier par profondeur (plus profond d'abord)
        sousCategories.reverse().forEach(sc => {
            updateSousCategorieStats(sc);
        });

        // Mettre à jour la catégorie/sous-catégorie elle-même
        updateSousCategorieStats(catItem);

        // Mettre à jour les parents de cet élément (si c'est une sous-catégorie dans une catégorie)
        updateAllParents(catItem);

        // Recalculer les totaux et sauvegarder
        updateTotals();
        autoSave();
    });

    function saveItemData(catId, matId, prix, qte) {
        let body = `ajax_action=update_item_data&cat_id=${catId}&mat_id=${matId}&csrf_token=${csrfToken}`;
        if (prix !== null) body += `&prix=${prix}`;
        if (qte !== null) body += `&qte=${qte}`;

        fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body
        })
        .then(r => r.json())
        .then(data => {
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

    // Appeler updateTotals() au chargement pour synchroniser le Total HT avec les calculs JavaScript
    // Ceci assure que le total en haut correspond aux données affichées dans la liste
    updateTotals();
});
</script>
