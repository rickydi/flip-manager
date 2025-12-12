<?php
/**
 * Budget Builder - Interface Drag & Drop
 * Inclus dans detail.php, onglet Budgets
 *
 * Variables disponibles depuis detail.php:
 * - $templatesBudgets, $projetPostes, $projetItems, $projetGroupes
 * - $groupeLabels, $projet, $projetId
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

/* Colonne gauche - Catalogue */
.catalogue-panel {
    width: 35%;
    min-width: 250px;
    max-width: 50%;
    background: var(--bg-card, #fff);
    display: flex;
    flex-direction: column;
    border-right: 1px solid var(--border-color, #dee2e6);
    overflow: hidden;
}

.catalogue-header {
    padding: 12px 16px;
    background: linear-gradient(135deg, #1e3a5f 0%, #2d4a6f 100%);
    color: white;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 8px;
    flex-shrink: 0;
}

.catalogue-content {
    flex: 1;
    overflow-y: auto;
    padding: 8px;
}

/* Splitter/Resizer */
.splitter {
    width: 6px;
    background: var(--border-color, #dee2e6);
    cursor: col-resize;
    flex-shrink: 0;
    transition: background 0.2s;
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
    font-size: 14px;
}
.splitter:hover::after, .splitter.dragging::after {
    color: white;
}

/* Colonne droite - Budget Projet */
.projet-panel {
    flex: 1;
    min-width: 300px;
    background: var(--bg-card, #fff);
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

.projet-header {
    padding: 12px 16px;
    background: linear-gradient(135deg, #059669 0%, #10b981 100%);
    color: white;
    font-weight: 600;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-shrink: 0;
}

.projet-totaux {
    background: rgba(0,0,0,0.1);
    padding: 8px 16px;
    display: flex;
    gap: 20px;
    font-size: 0.85rem;
    flex-shrink: 0;
}

.projet-totaux .total-item {
    display: flex;
    flex-direction: column;
    align-items: center;
}

.projet-totaux .total-label {
    opacity: 0.8;
    font-size: 0.7rem;
}

.projet-totaux .total-value {
    font-weight: 700;
}

.projet-content {
    flex: 1;
    overflow-y: auto;
    padding: 8px;
}

.projet-empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    height: 100%;
    color: var(--text-muted, #6c757d);
    text-align: center;
    padding: 40px;
}

.projet-empty i {
    font-size: 4rem;
    margin-bottom: 16px;
    opacity: 0.3;
}

/* Items du catalogue (draggable) */
.catalogue-groupe {
    margin-bottom: 8px;
}

.catalogue-groupe-header {
    padding: 8px 12px;
    background: rgba(30, 58, 95, 0.1);
    border-radius: 6px;
    font-weight: 600;
    font-size: 0.85rem;
    color: var(--text-primary);
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 8px;
}

.catalogue-groupe-header:hover {
    background: rgba(30, 58, 95, 0.15);
}

.catalogue-groupe-header i.toggle {
    transition: transform 0.2s;
}

.catalogue-groupe.collapsed .catalogue-groupe-header i.toggle {
    transform: rotate(-90deg);
}

.catalogue-groupe.collapsed .catalogue-groupe-content {
    display: none;
}

.catalogue-groupe-content {
    padding-left: 12px;
    margin-top: 4px;
}

.catalogue-item {
    padding: 6px 10px;
    margin: 2px 0;
    background: var(--bg-card, #f8f9fa);
    border: 1px solid var(--border-color, #e9ecef);
    border-radius: 4px;
    font-size: 0.8rem;
    cursor: grab;
    display: flex;
    align-items: center;
    gap: 8px;
    transition: all 0.2s;
}

.catalogue-item:hover {
    background: rgba(13, 110, 253, 0.1);
    border-color: var(--primary-color, #0d6efd);
    transform: translateX(4px);
}

.catalogue-item:active {
    cursor: grabbing;
}

.catalogue-item .item-icon {
    color: var(--text-muted);
    font-size: 0.9rem;
}

.catalogue-item .item-name {
    flex: 1;
}

.catalogue-item .item-prix {
    color: var(--success-color, #198754);
    font-weight: 500;
    font-size: 0.75rem;
}

.catalogue-item.is-kit {
    background: linear-gradient(135deg, rgba(13, 110, 253, 0.05) 0%, rgba(13, 110, 253, 0.02) 100%);
    border-left: 3px solid var(--primary-color, #0d6efd);
}

.catalogue-item.is-kit .item-icon {
    color: var(--warning-color, #ffc107);
}

/* Sous-items (matériaux) */
.catalogue-subitems {
    padding-left: 20px;
    margin-top: 2px;
}

.catalogue-subitem {
    padding: 4px 8px;
    margin: 1px 0;
    background: transparent;
    border: 1px dashed var(--border-color, #dee2e6);
    border-radius: 3px;
    font-size: 0.75rem;
    cursor: grab;
    display: flex;
    align-items: center;
    gap: 6px;
}

.catalogue-subitem:hover {
    background: rgba(13, 110, 253, 0.05);
    border-style: solid;
}

/* Items dans le projet */
.projet-groupe {
    margin-bottom: 12px;
    background: var(--bg-card);
    border-radius: 8px;
    border: 1px solid var(--border-color);
    overflow: hidden;
}

.projet-groupe-header {
    padding: 10px 12px;
    background: rgba(30, 58, 95, 0.9);
    color: white;
    font-weight: 600;
    font-size: 0.85rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.projet-groupe-header .groupe-qte {
    display: flex;
    align-items: center;
    gap: 4px;
}

.projet-groupe-header .groupe-qte input {
    width: 50px;
    text-align: center;
    background: rgba(255,255,255,0.1);
    border: 1px solid rgba(255,255,255,0.3);
    color: white;
    border-radius: 4px;
    padding: 2px 4px;
    font-size: 0.8rem;
}

.projet-groupe-content {
    padding: 8px;
    min-height: 40px;
}

.projet-item {
    padding: 8px 10px;
    margin: 4px 0;
    background: var(--bg-card, #f8f9fa);
    border: 1px solid var(--border-color, #e9ecef);
    border-radius: 6px;
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.8rem;
}

.projet-item:hover {
    background: rgba(30, 58, 95, 0.05);
}

.projet-item .item-drag {
    cursor: grab;
    color: var(--text-muted);
    padding: 4px;
}

.projet-item .item-drag:active {
    cursor: grabbing;
}

.projet-item .item-name {
    flex: 1;
    font-weight: 500;
}

.projet-item .item-controls {
    display: flex;
    align-items: center;
    gap: 8px;
}

.projet-item .item-qte {
    width: 60px;
}

.projet-item .item-prix {
    width: 80px;
}

.projet-item .item-total {
    min-width: 80px;
    text-align: right;
    font-weight: 600;
    color: var(--success-color, #198754);
}

.projet-item .item-remove {
    color: var(--danger-color, #dc3545);
    cursor: pointer;
    padding: 4px;
    opacity: 0.6;
}

.projet-item .item-remove:hover {
    opacity: 1;
}

/* Drag & Drop feedback */
.sortable-ghost {
    opacity: 0.4;
    background: rgba(13, 110, 253, 0.15) !important;
    border: 2px dashed var(--primary-color, #0d6efd) !important;
}

.sortable-drag {
    background: var(--bg-card, #fff) !important;
    box-shadow: 0 8px 25px rgba(0,0,0,0.15);
}

.drop-zone-active {
    background: rgba(5, 150, 105, 0.1) !important;
    border: 2px dashed #059669 !important;
}

/* Responsive */
@media (max-width: 768px) {
    .budget-builder {
        flex-direction: column;
        height: auto;
    }
    .catalogue-panel, .projet-panel {
        width: 100% !important;
        max-width: 100% !important;
        min-width: 100% !important;
    }
    .splitter {
        width: 100%;
        height: 6px;
        cursor: row-resize;
    }
}
</style>

<!-- Barre de totaux sticky -->
<div class="bg-primary text-white mb-3 rounded" style="font-size: 0.85rem;">
    <div class="px-3 py-2 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span><i class="bi bi-calculator me-2"></i>Budget Rénovation</span>
        <div class="d-flex gap-3 align-items-center">
            <span class="px-2">
                <span class="opacity-75">HT:</span>
                <strong id="totalHT"><?= formatMoney($totalProjetHT) ?></strong>
            </span>
            <span class="px-2">
                <span class="opacity-75">Contingence <?= $projet['taux_contingence'] ?>%:</span>
                <strong id="totalContingence"><?= formatMoney($contingence) ?></strong>
            </span>
            <span class="px-2">
                <span class="opacity-75">TPS:</span>
                <strong id="totalTPS"><?= formatMoney($tps) ?></strong>
            </span>
            <span class="px-2">
                <span class="opacity-75">TVQ:</span>
                <strong id="totalTVQ"><?= formatMoney($tvq) ?></strong>
            </span>
            <span class="px-2 border-start ps-3">
                <span class="opacity-75">Total TTC:</span>
                <strong class="fs-5" id="grandTotal"><?= formatMoney($grandTotal) ?></strong>
            </span>
        </div>
    </div>
</div>

<!-- Container principal -->
<div class="budget-builder">
    <!-- Colonne Gauche: Catalogue Templates -->
    <div class="catalogue-panel" id="cataloguePanel">
        <div class="catalogue-header">
            <i class="bi bi-box-seam"></i>
            Catalogue des Templates
        </div>
        <div class="catalogue-content" id="catalogueContent">
            <?php foreach ($catalogueData as $groupe => $groupeData): ?>
            <div class="catalogue-groupe" data-groupe="<?= $groupe ?>">
                <div class="catalogue-groupe-header" onclick="toggleGroupe(this)">
                    <i class="bi bi-chevron-down toggle"></i>
                    <i class="bi bi-folder-fill text-warning"></i>
                    <?= e($groupeData['label']) ?>
                    <span class="badge bg-secondary ms-auto"><?= count($groupeData['categories']) ?></span>
                </div>
                <div class="catalogue-groupe-content">
                    <?php foreach ($groupeData['categories'] as $catId => $cat): ?>
                    <?php
                        $hasContent = !empty($cat['sous_categories']);
                        $totalCat = 0;
                        foreach ($cat['sous_categories'] ?? [] as $sc) {
                            foreach ($sc['materiaux'] ?? [] as $mat) {
                                $totalCat += $mat['prix_defaut'] * ($mat['quantite_defaut'] ?? 1);
                            }
                        }
                    ?>
                    <div class="catalogue-item <?= $hasContent ? 'is-kit' : '' ?>"
                         data-type="categorie"
                         data-id="<?= $catId ?>"
                         data-groupe="<?= $groupe ?>"
                         data-nom="<?= e($cat['nom']) ?>"
                         data-prix="<?= $totalCat ?>"
                         draggable="true">
                        <i class="bi <?= $hasContent ? 'bi-folder-fill' : 'bi-folder' ?> item-icon"></i>
                        <span class="item-name"><?= e($cat['nom']) ?></span>
                        <?php if ($totalCat > 0): ?>
                        <span class="item-prix"><?= formatMoney($totalCat) ?></span>
                        <?php endif; ?>
                    </div>

                    <?php if ($hasContent): ?>
                    <div class="catalogue-subitems">
                        <?php foreach ($cat['sous_categories'] as $scId => $sc): ?>
                            <?php foreach ($sc['materiaux'] ?? [] as $mat): ?>
                            <div class="catalogue-subitem"
                                 data-type="materiau"
                                 data-id="<?= $mat['id'] ?>"
                                 data-cat-id="<?= $catId ?>"
                                 data-groupe="<?= $groupe ?>"
                                 data-nom="<?= e($mat['nom']) ?>"
                                 data-prix="<?= $mat['prix_defaut'] ?>"
                                 data-qte="<?= $mat['quantite_defaut'] ?? 1 ?>"
                                 draggable="true">
                                <i class="bi bi-box-seam text-primary"></i>
                                <span class="item-name"><?= e($mat['nom']) ?></span>
                                <span class="item-prix"><?= formatMoney($mat['prix_defaut']) ?></span>
                            </div>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Splitter -->
    <div class="splitter" id="splitter"></div>

    <!-- Colonne Droite: Budget Projet -->
    <div class="projet-panel" id="projetPanel">
        <div class="projet-header">
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
        <div class="projet-content" id="projetContent">
            <?php
            $hasItems = false;
            foreach ($groupeLabels as $groupe => $label):
                $groupeItems = [];
                foreach ($projetPostes as $catId => $poste) {
                    if (isset($templatesBudgets[$catId]) && $templatesBudgets[$catId]['groupe'] === $groupe) {
                        $groupeItems[$catId] = $poste;
                    }
                }
                if (!empty($groupeItems)) $hasItems = true;
            ?>
            <div class="projet-groupe" data-groupe="<?= $groupe ?>" style="<?= empty($groupeItems) ? 'display:none;' : '' ?>">
                <div class="projet-groupe-header">
                    <span><i class="bi bi-folder-fill text-warning me-2"></i><?= e($label) ?></span>
                    <div class="groupe-qte">
                        <span class="small opacity-75">Qté:</span>
                        <button type="button" class="btn btn-sm btn-outline-light py-0 px-1" onclick="changeGroupeQte('<?= $groupe ?>', -1)">-</button>
                        <input type="number" class="groupe-qte-input" data-groupe="<?= $groupe ?>"
                               value="<?= $projetGroupes[$groupe] ?? 1 ?>" min="1" max="20"
                               onchange="updateGroupeQte('<?= $groupe ?>')">
                        <button type="button" class="btn btn-sm btn-outline-light py-0 px-1" onclick="changeGroupeQte('<?= $groupe ?>', 1)">+</button>
                    </div>
                </div>
                <div class="projet-groupe-content sortable-projet" data-groupe="<?= $groupe ?>">
                    <?php foreach ($groupeItems as $catId => $poste):
                        $cat = $templatesBudgets[$catId] ?? null;
                        if (!$cat) continue;

                        // Calculer le total de cette catégorie
                        $catTotal = 0;
                        $qteCat = (int)$poste['quantite'];
                        $qteGroupe = $projetGroupes[$groupe] ?? 1;

                        foreach ($cat['sous_categories'] ?? [] as $sc) {
                            foreach ($sc['materiaux'] ?? [] as $mat) {
                                if (isset($projetItems[$catId][$mat['id']])) {
                                    $item = $projetItems[$catId][$mat['id']];
                                    $catTotal += (float)$item['prix_unitaire'] * (int)$item['quantite'] * $qteCat * $qteGroupe;
                                }
                            }
                        }
                    ?>
                    <div class="projet-item" data-type="categorie" data-id="<?= $catId ?>" data-groupe="<?= $groupe ?>">
                        <i class="bi bi-grip-vertical item-drag"></i>
                        <i class="bi bi-folder-fill text-warning"></i>
                        <span class="item-name"><?= e($cat['nom']) ?></span>
                        <div class="item-controls">
                            <div class="input-group input-group-sm item-qte">
                                <button class="btn btn-outline-secondary py-0" type="button" onclick="changeItemQte(this, -1)">-</button>
                                <input type="number" class="form-control text-center px-0" value="<?= $qteCat ?>" min="1"
                                       onchange="updateItemQte(this)" data-cat-id="<?= $catId ?>">
                                <button class="btn btn-outline-secondary py-0" type="button" onclick="changeItemQte(this, 1)">+</button>
                            </div>
                        </div>
                        <span class="item-total"><?= formatMoney($catTotal * 1.14975) ?></span>
                        <i class="bi bi-x-lg item-remove" onclick="removeItem(this)"></i>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>

            <?php if (!$hasItems): ?>
            <div class="projet-empty" id="projetEmpty">
                <i class="bi bi-cart-plus"></i>
                <h5>Budget vide</h5>
                <p>Glissez des éléments depuis le catalogue à gauche pour construire votre budget de rénovation.</p>
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

<style>
.spin { animation: spin 1s linear infinite; }
@keyframes spin { 100% { transform: rotate(360deg); } }
</style>

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
    const projetPanel = document.getElementById('projetPanel');
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
        const minWidth = 250;
        const maxWidth = containerRect.width * 0.5;

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
    // TOGGLE GROUPES CATALOGUE
    // ========================================
    window.toggleGroupe = function(header) {
        header.closest('.catalogue-groupe').classList.toggle('collapsed');
    };

    // ========================================
    // DRAG & DROP
    // ========================================
    // Rendre les items du catalogue draggables
    document.querySelectorAll('.catalogue-item, .catalogue-subitem').forEach(item => {
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
            this.classList.add('dragging');
        });

        item.addEventListener('dragend', function() {
            this.classList.remove('dragging');
        });
    });

    // Zones de drop (groupes du projet)
    document.querySelectorAll('.sortable-projet').forEach(zone => {
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

    // Drop sur le panel projet entier (si vide)
    document.getElementById('projetContent').addEventListener('dragover', function(e) {
        e.preventDefault();
    });

    document.getElementById('projetContent').addEventListener('drop', function(e) {
        e.preventDefault();

        try {
            const data = JSON.parse(e.dataTransfer.getData('text/plain'));
            // Trouver le bon groupe
            const groupe = data.groupe;
            const groupeZone = document.querySelector(`.sortable-projet[data-groupe="${groupe}"]`);
            if (groupeZone) {
                addItemToProjet(data, groupe);
            }
        } catch (err) {
            console.error('Drop error:', err);
        }
    });

    // ========================================
    // AJOUTER ITEM AU PROJET
    // ========================================
    function addItemToProjet(data, groupe) {
        // Vérifier si déjà présent
        const existingItem = document.querySelector(`.projet-item[data-id="${data.catId || data.id}"][data-type="${data.type}"]`);
        if (existingItem) {
            // Incrémenter la quantité
            const qteInput = existingItem.querySelector('input[type="number"]');
            if (qteInput) {
                qteInput.value = parseInt(qteInput.value) + 1;
                updateItemQte(qteInput);
            }
            return;
        }

        // Afficher le groupe s'il est masqué
        const groupeDiv = document.querySelector(`.projet-groupe[data-groupe="${groupe}"]`);
        if (groupeDiv) {
            groupeDiv.style.display = '';
        }

        // Masquer le message "vide"
        const emptyMsg = document.getElementById('projetEmpty');
        if (emptyMsg) emptyMsg.style.display = 'none';

        // Créer l'élément
        const itemHtml = `
            <div class="projet-item" data-type="${data.type}" data-id="${data.catId || data.id}" data-groupe="${groupe}">
                <i class="bi bi-grip-vertical item-drag"></i>
                <i class="bi ${data.type === 'categorie' ? 'bi-folder-fill text-warning' : 'bi-box-seam text-primary'}"></i>
                <span class="item-name">${escapeHtml(data.nom)}</span>
                <div class="item-controls">
                    <div class="input-group input-group-sm item-qte">
                        <button class="btn btn-outline-secondary py-0" type="button" onclick="changeItemQte(this, -1)">-</button>
                        <input type="number" class="form-control text-center px-0" value="${data.qte || 1}" min="1"
                               onchange="updateItemQte(this)" data-cat-id="${data.catId || data.id}">
                        <button class="btn btn-outline-secondary py-0" type="button" onclick="changeItemQte(this, 1)">+</button>
                    </div>
                </div>
                <span class="item-total">${formatMoney(data.prix * (data.qte || 1) * 1.14975)}</span>
                <i class="bi bi-x-lg item-remove" onclick="removeItem(this)"></i>
            </div>
        `;

        const zone = document.querySelector(`.sortable-projet[data-groupe="${groupe}"]`);
        if (zone) {
            zone.insertAdjacentHTML('beforeend', itemHtml);
        }

        updateTotals();
        autoSave();
    }

    // ========================================
    // FONCTIONS UTILITAIRES
    // ========================================
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function formatMoney(val) {
        return val.toLocaleString('fr-CA', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' $';
    }

    window.changeItemQte = function(btn, delta) {
        const input = btn.parentElement.querySelector('input');
        const newVal = Math.max(1, parseInt(input.value) + delta);
        input.value = newVal;
        updateItemQte(input);
    };

    window.updateItemQte = function(input) {
        const item = input.closest('.projet-item');
        // Recalculer le total de cet item
        updateTotals();
        autoSave();
    };

    window.changeGroupeQte = function(groupe, delta) {
        const input = document.querySelector(`.groupe-qte-input[data-groupe="${groupe}"]`);
        const newVal = Math.max(1, Math.min(20, parseInt(input.value) + delta));
        input.value = newVal;
        updateGroupeQte(groupe);
    };

    window.updateGroupeQte = function(groupe) {
        updateTotals();
        autoSave();
    };

    window.removeItem = function(btn) {
        const item = btn.closest('.projet-item');
        const groupe = item.dataset.groupe;
        item.remove();

        // Vérifier si le groupe est vide
        const zone = document.querySelector(`.sortable-projet[data-groupe="${groupe}"]`);
        if (zone && zone.children.length === 0) {
            zone.closest('.projet-groupe').style.display = 'none';
        }

        // Vérifier si tout est vide
        const allItems = document.querySelectorAll('.projet-item');
        if (allItems.length === 0) {
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

        document.querySelectorAll('.projet-item').forEach(item => {
            const catId = item.dataset.id;
            const groupe = item.dataset.groupe;
            const qteInput = item.querySelector('input[type="number"]');
            const qteCat = qteInput ? parseInt(qteInput.value) || 1 : 1;
            const groupeQteInput = document.querySelector(`.groupe-qte-input[data-groupe="${groupe}"]`);
            const qteGroupe = groupeQteInput ? parseInt(groupeQteInput.value) || 1 : 1;

            // TODO: Récupérer le prix réel depuis les données
            // Pour l'instant on utilise un prix estimé
            const prixBase = parseFloat(item.dataset.prix) || 0;
            const itemTotal = prixBase * qteCat * qteGroupe;

            totalHT += itemTotal;
            totalTaxable += itemTotal; // Simplification: tout taxable

            // Mettre à jour l'affichage
            const totalSpan = item.querySelector('.item-total');
            if (totalSpan) {
                totalSpan.textContent = formatMoney(itemTotal * 1.14975);
            }
        });

        const contingence = totalHT * (tauxContingence / 100);
        const baseTaxable = totalTaxable + contingence;
        const tps = baseTaxable * 0.05;
        const tvq = baseTaxable * 0.09975;
        const grandTotal = totalHT + contingence + tps + tvq;

        document.getElementById('totalHT').textContent = formatMoney(totalHT);
        document.getElementById('totalContingence').textContent = formatMoney(contingence);
        document.getElementById('totalTPS').textContent = formatMoney(tps);
        document.getElementById('totalTVQ').textContent = formatMoney(tvq);
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

            // Collecter les données
            const items = [];
            const groupes = {};

            document.querySelectorAll('.projet-item').forEach(item => {
                const qteInput = item.querySelector('input[type="number"]');
                items.push({
                    type: item.dataset.type,
                    id: item.dataset.id,
                    groupe: item.dataset.groupe,
                    quantite: qteInput ? parseInt(qteInput.value) : 1
                });
            });

            document.querySelectorAll('.groupe-qte-input').forEach(input => {
                groupes[input.dataset.groupe] = parseInt(input.value) || 1;
            });

            // Envoyer au serveur
            fetch(window.location.href, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
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

    // Initialiser SortableJS pour réorganiser les items dans le projet
    document.querySelectorAll('.sortable-projet').forEach(zone => {
        new Sortable(zone, {
            group: 'projet-items',
            animation: 150,
            handle: '.item-drag',
            ghostClass: 'sortable-ghost',
            onEnd: function() {
                autoSave();
            }
        });
    });
});
</script>
