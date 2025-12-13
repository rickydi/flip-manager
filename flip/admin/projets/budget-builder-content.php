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
function afficherSousCategoriesRecursifCatalogue($sousCategories, $catId, $groupe, $niveau = 0) {
    if (empty($sousCategories)) return;

    foreach ($sousCategories as $sc):
        $hasEnfants = !empty($sc['enfants']);
        $hasMateriaux = !empty($sc['materiaux']);
        $isKit = $hasEnfants || $hasMateriaux;
        $uniqueId = 'cat' . $catId . '_sc' . $sc['id'];

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
                 data-groupe="<?= $groupe ?>"
                 data-nom="<?= e($sc['nom']) ?>"
                 data-prix="<?= $totalSc ?>">

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
                <?php foreach ($sc['materiaux'] ?? [] as $mat):
                    $qte = $mat['quantite_defaut'] ?? 1;
                    $total = ($mat['prix_defaut'] ?? 0) * $qte;
                ?>
                <div class="tree-content mat-item catalogue-draggable"
                     draggable="true"
                     data-type="materiau"
                     data-id="<?= $mat['id'] ?>"
                     data-sc-id="<?= $sc['id'] ?>"
                     data-cat-id="<?= $catId ?>"
                     data-groupe="<?= $groupe ?>"
                     data-nom="<?= e($mat['nom']) ?>"
                     data-prix="<?= $mat['prix_defaut'] ?? 0 ?>"
                     data-qte="<?= $qte ?>">

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
                    <?php afficherSousCategoriesRecursifCatalogue($sc['enfants'], $catId, $groupe, $niveau + 1); ?>
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

$totalProjetHT = $totalProjetTaxable + $totalProjetNonTaxable;
$contingence = $totalProjetHT * ((float)$projet['taux_contingence'] / 100);
$contingenceTaxable = $totalProjetHT > 0 ? $contingence * ($totalProjetTaxable / $totalProjetHT) : 0;
$baseTaxable = $totalProjetTaxable + $contingenceTaxable;
$tps = $baseTaxable * 0.05;
$tvq = $baseTaxable * 0.09975;
$grandTotal = $totalProjetHT + $contingence + $tps + $tvq;
?>

<!-- SortableJS pour le Drag & Drop -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.15.0/Sortable.min.js"></script>

<style>
/* ========================================
   STYLES IDENTIQUES À TEMPLATES
   ======================================== */

/* Container principal avec splitter */
.budget-builder {
    display: flex;
    height: calc(100vh - 250px);
    min-height: 500px;
    gap: 0;
    background: var(--bg-body, #f8f9fa);
    border-radius: 8px;
    overflow: hidden;
    border: 1px solid var(--border-color, #dee2e6);
}

/* Panneaux */
.builder-panel {
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

.catalogue-panel {
    width: 40%;
    min-width: 300px;
    max-width: 60%;
    background: var(--bg-card, #fff);
    border-right: 1px solid var(--border-color, #dee2e6);
}

.projet-panel {
    flex: 1;
    min-width: 300px;
    background: var(--bg-card, #fff);
}

.panel-header {
    padding: 12px 16px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 8px;
    flex-shrink: 0;
    border-bottom: 1px solid var(--border-color);
}

.catalogue-panel .panel-header {
    background: linear-gradient(135deg, #1e3a5f 0%, #2d4a6f 100%);
    color: white;
}

.projet-panel .panel-header {
    background: linear-gradient(135deg, #059669 0%, #10b981 100%);
    color: white;
    justify-content: space-between;
}

.panel-content {
    flex: 1;
    overflow-y: auto;
    padding: 12px;
}

/* Splitter */
.splitter {
    width: 8px;
    background: var(--border-color, #dee2e6);
    cursor: col-resize;
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center;
}
.splitter:hover, .splitter.dragging {
    background: var(--primary-color, #0d6efd);
}
.splitter::after {
    content: '⋮';
    color: var(--text-muted, #6c757d);
    font-size: 16px;
}
.splitter:hover::after, .splitter.dragging::after {
    color: white;
}

/* ========================================
   STYLES ARBRE (copié de templates)
   ======================================== */
.tree-item {
    border-left: 2px solid var(--border-color, #dee2e6);
}

.tree-content {
    display: flex;
    align-items: center;
    padding: 8px 12px;
    background: var(--bg-card, #f8f9fa);
    border: 1px solid var(--border-color, #e9ecef);
    margin-bottom: 3px;
    border-radius: 6px;
    position: relative;
}

.tree-content:hover {
    background: rgba(30, 58, 95, 0.8) !important;
    border-color: var(--primary-color, #0d6efd);
}

.tree-toggle {
    cursor: pointer;
    width: 24px;
    height: 24px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    margin-right: 6px;
    color: var(--text-muted, #6c757d);
    border-radius: 4px;
}

.tree-toggle:hover {
    color: var(--primary-color, #0d6efd);
    background: rgba(13, 110, 253, 0.1);
}

.tree-toggle.collapsed i,
[aria-expanded="false"] .tree-toggle i {
    transform: rotate(-90deg);
}

.tree-children {
    padding-left: 25px;
    min-height: 5px;
}

/* Drag styles */
.sortable-ghost {
    opacity: 0.4;
    background: rgba(13, 110, 253, 0.15) !important;
    border: 2px dashed var(--primary-color, #0d6efd) !important;
    border-radius: 6px;
}

.sortable-drag {
    background: var(--bg-card, #f8f9fa) !important;
    box-shadow: 0 8px 25px rgba(0,0,0,0.15);
    cursor: grabbing;
    border-radius: 6px;
}

.drag-handle {
    cursor: grab;
    color: var(--text-muted, #adb5bd);
    margin-right: 8px;
    padding: 4px;
    border-radius: 4px;
}
.drag-handle:hover {
    color: var(--primary-color, #0d6efd);
    background: rgba(13, 110, 253, 0.1);
}
.drag-handle:active {
    cursor: grabbing;
}

.type-icon {
    width: 24px;
    text-align: center;
    margin-right: 8px;
}

.is-kit .tree-content {
    background: linear-gradient(135deg, rgba(13, 110, 253, 0.05) 0%, rgba(13, 110, 253, 0.02) 100%);
    border-left: 3px solid var(--primary-color, #0d6efd);
}

/* Matériaux */
.tree-content.mat-item {
    background: var(--bg-card, #f8f9fa);
    border: 1px dashed var(--border-color, #dee2e6);
    padding: 6px 10px;
}
.tree-content.mat-item:hover {
    background: rgba(30, 58, 95, 0.8) !important;
    border-style: solid;
}

/* Groupes header */
.groupe-header {
    background: rgba(30, 58, 95, 0.6) !important;
    color: #94a3b8 !important;
    padding: 10px 12px;
    margin-bottom: 8px;
    border-radius: 6px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
}
.groupe-header:hover {
    background: rgba(30, 58, 95, 0.8) !important;
}
.groupe-header.collapsed .collapse-icon {
    transform: rotate(-90deg);
}

/* Badges */
.item-badge {
    background: rgba(30, 58, 95, 0.9);
    font-size: 0.75rem;
}

/* Alignement des badges dans le projet */
.projet-panel .badge-qte {
    min-width: 35px;
    text-align: center;
}
.projet-panel .badge-count {
    min-width: 40px;
    text-align: center;
}
.projet-panel .badge-prix {
    min-width: 85px;
    text-align: right;
}
.projet-panel .badge-qte.editable-qte,
.projet-panel .badge-prix.editable-prix {
    cursor: pointer;
    transition: background-color 0.2s;
}
.projet-panel .badge-qte.editable-qte:hover {
    background-color: rgba(255, 255, 255, 0.3) !important;
}
.projet-panel .badge-prix.editable-prix:hover {
    background-color: rgba(13, 202, 240, 0.3) !important;
}
.projet-panel .qte-input {
    width: 45px;
    padding: 2px 4px;
    font-size: 0.75rem;
    text-align: center;
    border: 1px solid #adb5bd;
    border-radius: 4px;
    background: #1a1a2e;
    color: #fff;
}
.projet-panel .prix-input {
    width: 80px;
    padding: 2px 6px;
    font-size: 0.75rem;
    text-align: right;
    border: 1px solid #0dcaf0;
    border-radius: 4px;
    background: #1a1a2e;
    color: #0dcaf0;
}
.projet-panel .badge-total {
    min-width: 100px;
    text-align: right;
}

/* Contrôles quantité alignés */
.qte-controls {
    min-width: 90px;
    justify-content: center;
}

/* Zone de drop vide */
.drop-zone-empty {
    border: 2px dashed var(--border-color);
    border-radius: 8px;
    padding: 40px;
    text-align: center;
    color: var(--text-muted);
}
.drop-zone-empty i {
    font-size: 3rem;
    opacity: 0.3;
    margin-bottom: 16px;
}

.drop-zone-active {
    background: rgba(5, 150, 105, 0.1) !important;
    border-color: #059669 !important;
}

/* Contrôles quantité dans le projet */
.qte-controls {
    display: flex;
    align-items: center;
    gap: 2px;
}
.qte-controls input {
    width: 40px;
    text-align: center;
    border: 1px solid var(--border-color);
    border-radius: 4px;
    padding: 2px 4px;
    font-size: 0.85rem;
}
.qte-controls button {
    padding: 2px 6px;
    font-size: 0.75rem;
}

/* Animation */
.spin { animation: spin 1s linear infinite; }
@keyframes spin { 100% { transform: rotate(360deg); } }

/* Désactiver animations */
.collapse, .collapsing {
    transition: none !important;
}
.collapsing {
    height: auto !important;
}

/* Responsive */
@media (max-width: 992px) {
    .budget-builder {
        flex-direction: column;
        height: auto;
    }
    .catalogue-panel, .projet-panel {
        width: 100% !important;
        max-width: 100% !important;
        min-width: 100% !important;
        min-height: 400px;
    }
    .splitter {
        width: 100%;
        height: 8px;
        cursor: row-resize;
    }
}
</style>

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
                    ?>
                    <div class="tree-item mb-1 <?= $hasContent ? 'is-kit' : '' ?>">
                        <div class="tree-content catalogue-draggable"
                             draggable="true"
                             data-type="categorie"
                             data-id="<?= $catId ?>"
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
                            <?php afficherSousCategoriesRecursifCatalogue($cat['sous_categories'], $catId, $groupe); ?>
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
            <div id="saveStatus" class="small">
                <span id="saveIdle"><i class="bi bi-cloud-check me-1"></i>Auto-save</span>
                <span id="saveSaving" class="d-none"><i class="bi bi-arrow-repeat spin me-1"></i>Sauvegarde...</span>
                <span id="saveSaved" class="d-none"><i class="bi bi-check-circle me-1"></i>Sauvegardé!</span>
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

                            <!-- Quantité catégorie (+/-) -->
                            <div class="btn-group btn-group-sm me-1">
                                <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-1 cat-qte-btn" data-cat-id="<?= $catId ?>" data-action="minus">
                                    <i class="bi bi-dash"></i>
                                </button>
                                <span class="badge item-badge badge-qte text-light d-flex align-items-center px-2 cat-qte-display" data-cat-id="<?= $catId ?>">x<?= $qteCat ?></span>
                                <input type="hidden" class="cat-qte-input" data-cat-id="<?= $catId ?>" value="<?= $qteCat ?>">
                                <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-1 cat-qte-btn" data-cat-id="<?= $catId ?>" data-action="plus">
                                    <i class="bi bi-plus"></i>
                                </button>
                            </div>

                            <span class="badge item-badge badge-count text-info me-1">
                                <i class="bi bi-box-seam me-1"></i><?= $nbItemsCat ?>
                            </span>

                            <span class="badge item-badge badge-total text-success fw-bold cat-total" data-cat-id="<?= $catId ?>">
                                <?= formatMoney($catTotal * 1.14975) ?>
                            </span>

                            <button type="button" class="btn btn-sm btn-link text-danger ms-2 p-0" onclick="removeProjetItem(this)" title="Retirer">
                                <i class="bi bi-x-lg"></i>
                            </button>
                        </div>

                        <!-- Détail des items -->
                        <div class="collapse show tree-children" id="projetContent<?= $catId ?>">
                            <?php foreach ($cat['sous_categories'] ?? [] as $sc): ?>
                                <?php foreach ($sc['materiaux'] ?? [] as $mat):
                                    if (!isset($projetItems[$catId][$mat['id']])) continue;
                                    $item = $projetItems[$catId][$mat['id']];
                                    $qteItem = (int)$item['quantite'];
                                    $prixItem = (float)$item['prix_unitaire'];
                                    $totalItem = $prixItem * $qteItem * $qteCat * $qteGroupe;
                                ?>
                                <div class="tree-content mat-item projet-mat-item"
                                     data-mat-id="<?= $mat['id'] ?>"
                                     data-cat-id="<?= $catId ?>"
                                     data-prix="<?= $prixItem ?>"
                                     data-qte="<?= $qteItem ?>">

                                    <i class="bi bi-grip-vertical drag-handle" style="font-size: 0.85em;"></i>
                                    <div class="type-icon"><i class="bi bi-box-seam text-primary small"></i></div>
                                    <span class="flex-grow-1 small"><?= e($mat['nom']) ?></span>

                                    <div class="btn-group btn-group-sm me-1">
                                        <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-1 mat-qte-btn" data-action="minus">
                                            <i class="bi bi-dash"></i>
                                        </button>
                                        <span class="badge item-badge badge-qte text-light d-flex align-items-center px-2 mat-qte-display">x<?= $qteItem ?></span>
                                        <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-1 mat-qte-btn" data-action="plus">
                                            <i class="bi bi-plus"></i>
                                        </button>
                                    </div>
                                    <span class="badge item-badge badge-prix text-info me-1 editable-prix" role="button" title="Cliquer pour modifier"><?= formatMoney($prixItem) ?></span>
                                    <span class="badge item-badge badge-total text-success fw-bold"><?= formatMoney($totalItem * 1.14975) ?></span>
                                </div>
                                <?php endforeach; ?>
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

<script>
document.addEventListener('DOMContentLoaded', function() {
    const projetId = <?= $projetId ?>;
    const tauxContingence = <?= (float)$projet['taux_contingence'] ?>;
    const csrfToken = '<?= generateCSRFToken() ?>';
    let saveTimeout = null;

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
                catId: this.dataset.catId || this.dataset.id,
                groupe: this.dataset.groupe,
                nom: this.dataset.nom,
                prix: parseFloat(this.dataset.prix) || 0,
                qte: parseInt(this.dataset.qte) || 1
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
    document.getElementById('projetContent').addEventListener('dragover', function(e) {
        e.preventDefault();
    });

    document.getElementById('projetContent').addEventListener('drop', function(e) {
        e.preventDefault();
        try {
            const data = JSON.parse(e.dataTransfer.getData('text/plain'));
            addItemToProjet(data, data.groupe);
        } catch (err) {
            console.error('Drop error:', err);
        }
    });

    // ========================================
    // AJOUTER AU PROJET
    // ========================================
    function addItemToProjet(data, groupe) {
        // Vérifier si catégorie déjà présente
        const existingItem = document.querySelector(`.projet-item[data-id="${data.catId || data.id}"]`);
        if (existingItem) {
            // Flash pour indiquer déjà présent
            existingItem.querySelector('.tree-content').style.background = 'rgba(13, 110, 253, 0.3)';
            setTimeout(() => {
                existingItem.querySelector('.tree-content').style.background = '';
            }, 500);
            return;
        }

        // Afficher le groupe s'il est masqué
        const groupeDiv = document.querySelector(`.projet-groupe[data-groupe="${groupe}"]`);
        if (groupeDiv) {
            groupeDiv.style.display = '';
        }

        // Masquer le message vide
        const emptyMsg = document.getElementById('projetEmpty');
        if (emptyMsg) emptyMsg.style.display = 'none';

        // Créer l'élément (version simplifiée)
        const itemHtml = `
            <div class="tree-item mb-1 is-kit projet-item"
                 data-type="${data.type}"
                 data-id="${data.catId || data.id}"
                 data-groupe="${groupe}"
                 data-prix="${data.prix}">
                <div class="tree-content">
                    <i class="bi bi-grip-vertical drag-handle"></i>
                    <span class="tree-toggle" style="visibility: hidden;"><i class="bi bi-caret-down-fill"></i></span>
                    <div class="type-icon">
                        <i class="bi ${data.type === 'categorie' ? 'bi-folder-fill text-warning' : 'bi-box-seam text-primary'}"></i>
                    </div>
                    <strong class="flex-grow-1">${escapeHtml(data.nom)}</strong>
                    <div class="qte-controls me-2">
                        <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1" onclick="changeCatQte(${data.catId || data.id}, -1)">-</button>
                        <input type="number" class="cat-qte-input" data-cat-id="${data.catId || data.id}"
                               value="${data.qte || 1}" min="1" max="20"
                               onchange="updateCatQte(${data.catId || data.id})">
                        <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1" onclick="changeCatQte(${data.catId || data.id}, 1)">+</button>
                    </div>
                    <span class="badge item-badge badge-total text-success fw-bold cat-total" data-cat-id="${data.catId || data.id}">
                        ${formatMoney(data.prix * (data.qte || 1) * 1.14975)}
                    </span>
                    <button type="button" class="btn btn-sm btn-link text-danger ms-2 p-0" onclick="removeProjetItem(this)" title="Retirer">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>
            </div>
        `;

        const zone = document.querySelector(`.projet-drop-zone[data-groupe="${groupe}"]`);
        if (zone) {
            zone.insertAdjacentHTML('beforeend', itemHtml);
        }

        updateTotals();
        autoSave();
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function formatMoney(val) {
        return val.toLocaleString('fr-CA', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' $';
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
        updateTotals();
        autoSave();
    };

    window.removeProjetItem = function(btn) {
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

        // Parcourir toutes les catégories
        document.querySelectorAll('.projet-item').forEach(catItem => {
            const groupe = catItem.dataset.groupe;
            let catTotal = 0;

            const catQteInput = catItem.querySelector('.cat-qte-input');
            const qteCat = catQteInput ? parseInt(catQteInput.value) || 1 : 1;

            const groupeQteInput = document.querySelector(`.groupe-qte-input[data-groupe="${groupe}"]`);
            const qteGroupe = groupeQteInput ? parseInt(groupeQteInput.value) || 1 : 1;

            // Parcourir tous les matériaux de cette catégorie
            catItem.querySelectorAll('.projet-mat-item').forEach(matItem => {
                const prix = parseFloat(matItem.dataset.prix) || 0;
                const qte = parseInt(matItem.dataset.qte) || 1;
                catTotal += prix * qte;
            });

            const itemTotal = catTotal * qteCat * qteGroupe;
            totalHT += itemTotal;

            // Mettre à jour affichage du total catégorie
            const totalSpan = catItem.querySelector('.cat-total');
            if (totalSpan) {
                totalSpan.textContent = formatMoney(itemTotal * 1.14975);
            }
        });

        const contingence = totalHT * (tauxContingence / 100);
        const baseTaxable = totalHT + contingence;
        const tps = baseTaxable * 0.05;
        const tvq = baseTaxable * 0.09975;
        const grandTotal = totalHT + contingence + tps + tvq;

        document.getElementById('totalHT').textContent = formatMoney(totalHT);
        document.getElementById('totalContingence').textContent = formatMoney(contingence);
        document.getElementById('grandTotal').textContent = formatMoney(grandTotal);
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
            onEnd: function() {
                autoSave();
            }
        });
    });

    // ========================================
    // BOUTONS +/- POUR QUANTITÉS DE MATÉRIAUX
    // ========================================
    document.addEventListener('click', function(e) {
        const btn = e.target.closest('.mat-qte-btn');
        if (!btn) return;

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
        matQteDisplay.textContent = 'x' + currentQte;

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

        // Recalculer les totaux
        updateTotals();
    });

    // ========================================
    // ÉDITION INLINE DES PRIX
    // ========================================
    document.addEventListener('click', function(e) {
        const prixBadge = e.target.closest('.editable-prix');
        if (!prixBadge) return;

        // Éviter les clics multiples
        if (prixBadge.querySelector('input')) return;

        const matItem = prixBadge.closest('.projet-mat-item');
        const currentPrix = parseFloat(matItem.dataset.prix) || 0;
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
            matItem.dataset.prix = newPrix;
            prixBadge.textContent = formatMoney(newPrix);

            // Mettre à jour le total de la ligne
            const qte = parseInt(matItem.dataset.qte) || 1;

            // Récupérer les quantités de catégorie et groupe
            const catContainer = matItem.closest('.projet-item');
            const catQte = catContainer ? parseInt(catContainer.querySelector('.cat-qte-input')?.value || 1) : 1;
            const groupeContainer = matItem.closest('.projet-groupe');
            const groupeQte = groupeContainer ? parseInt(groupeContainer.querySelector('.groupe-qte-input')?.value || 1) : 1;

            const total = newPrix * qte * catQte * groupeQte * 1.14975;
            matItem.querySelector('.badge-total').textContent = formatMoney(total);

            // Sauvegarder via AJAX
            saveItemData(matItem.dataset.catId, matItem.dataset.matId, newPrix, null);

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
        catQteDisplay.textContent = 'x' + currentQte;

        // Mettre à jour les totaux de tous les matériaux de cette catégorie
        catItem.querySelectorAll('.projet-mat-item').forEach(matItem => {
            const prix = parseFloat(matItem.dataset.prix) || 0;
            const qte = parseInt(matItem.dataset.qte) || 1;
            const groupeContainer = matItem.closest('.projet-groupe');
            const groupeQte = groupeContainer ? parseInt(groupeContainer.querySelector('.groupe-qte-input')?.value || 1) : 1;
            const total = prix * qte * currentQte * groupeQte * 1.14975;
            matItem.querySelector('.badge-total').textContent = formatMoney(total);
        });

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
});
</script>
