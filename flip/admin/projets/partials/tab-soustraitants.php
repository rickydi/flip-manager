    <div class="tab-pane fade <?= $tab === 'soustraitants' ? 'show active' : '' ?>" id="soustraitants" role="tabpanel">
        <?php
        $totalSousTraitantsTab = array_sum(array_column($sousTraitantsProjet, 'montant_total'));
        $sousTraitantsCategories = array_unique(array_filter(array_column($sousTraitantsProjet, 'etape_nom')));
        $totalImpayeST = array_sum(array_map(function($st) {
            return empty($st['est_payee']) ? $st['montant_total'] : 0;
        }, $sousTraitantsProjet));
        sort($sousTraitantsCategories);
        $sousTraitantsEntreprises = array_unique(array_filter(array_column($sousTraitantsProjet, 'nom_entreprise')));
        sort($sousTraitantsEntreprises);
        ?>

        <!-- Barre compacte : Total + Filtres + Actions -->
        <div class="d-flex flex-wrap align-items-center gap-2 mb-3 p-2 rounded" style="background: rgba(255,255,255,0.03);">
            <!-- Total -->
            <div class="d-flex align-items-center px-3 py-1 rounded" style="background: rgba(13,110,253,0.15);">
                <i class="bi bi-building text-primary me-2"></i>
                <span class="text-muted me-2">Total:</span>
                <strong class="text-primary" id="sousTraitantsTotal"><?= formatMoney($totalSousTraitantsTab) ?></strong>
            </div>
            <?php if ($totalImpayeST > 0): ?>
            <!-- Impayé -->
            <div class="d-flex align-items-center px-3 py-1 rounded" style="background: rgba(255,193,7,0.15);">
                <i class="bi bi-exclamation-circle text-warning me-2"></i>
                <span class="text-muted me-2">Impayé:</span>
                <strong class="text-warning"><?= formatMoney($totalImpayeST) ?></strong>
            </div>
            <?php endif; ?>

            <!-- Séparateur -->
            <div class="vr mx-1 d-none d-md-block" style="height: 24px;"></div>

            <!-- Filtres -->
            <select class="form-select form-select-sm" id="filtreSousTraitantsStatut" onchange="filtrerSousTraitants()" style="width: auto; min-width: 130px;">
                <option value="">Tous statuts</option>
                <option value="en_attente">En attente</option>
                <option value="approuvee">Approuvée</option>
                <option value="rejetee">Rejetée</option>
            </select>

            <select class="form-select form-select-sm" id="filtreSousTraitantsCategorie" onchange="filtrerSousTraitants()" style="width: auto; min-width: 150px;">
                <option value="">Toutes catégories</option>
                <?php foreach ($sousTraitantsCategories as $cat): ?>
                    <option value="<?= e($cat) ?>"><?= e($cat) ?></option>
                <?php endforeach; ?>
            </select>

            <select class="form-select form-select-sm" id="filtreSousTraitantsEntreprise" onchange="filtrerSousTraitants()" style="width: auto; min-width: 150px;">
                <option value="">Toutes entreprises</option>
                <?php foreach ($sousTraitantsEntreprises as $ent): ?>
                    <option value="<?= e($ent) ?>"><?= e($ent) ?></option>
                <?php endforeach; ?>
            </select>

            <!-- Séparateur -->
            <div class="vr mx-1 d-none d-lg-block" style="height: 24px;"></div>

            <!-- Filtre par date -->
            <div class="d-flex align-items-center gap-1">
                <i class="bi bi-calendar3 text-muted" title="Filtrer par date"></i>
                <input type="date" class="form-control form-control-sm" id="filtreSousTraitantsDateDebut" onchange="filtrerSousTraitants()" style="width: 130px;" title="Date début">
                <span class="text-muted">-</span>
                <input type="date" class="form-control form-control-sm" id="filtreSousTraitantsDateFin" onchange="filtrerSousTraitants()" style="width: 130px;" title="Date fin">
            </div>

            <!-- Filtre par montant -->
            <div class="d-flex align-items-center gap-1">
                <i class="bi bi-currency-dollar text-muted" title="Filtrer par montant"></i>
                <input type="number" class="form-control form-control-sm" id="filtreSousTraitantsMontantMin" onchange="filtrerSousTraitants()" placeholder="Min $" style="width: 80px;" min="0" step="0.01">
                <span class="text-muted">-</span>
                <input type="number" class="form-control form-control-sm" id="filtreSousTraitantsMontantMax" onchange="filtrerSousTraitants()" placeholder="Max $" style="width: 80px;" min="0" step="0.01">
            </div>

            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="resetFiltresSousTraitants()" title="Réinitialiser">
                <i class="bi bi-x-circle"></i>
            </button>

            <!-- Barre de recherche -->
            <div class="position-relative" style="min-width: 200px;">
                <i class="bi bi-search position-absolute" style="left: 10px; top: 50%; transform: translateY(-50%); color: #6c757d;"></i>
                <input type="text" class="form-control form-control-sm" id="searchSousTraitants"
                       placeholder="Rechercher entreprise, description..."
                       oninput="filtrerSousTraitants()"
                       style="padding-left: 32px;">
            </div>

            <!-- Spacer + Actions à droite -->
            <div class="ms-auto d-flex align-items-center gap-2">
                <!-- Menu actions en masse (caché par défaut) -->
                <div id="bulkActionsMenuST" class="d-none">
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge bg-primary" id="selectedCountST">0 sélectionnée(s)</span>
                        <div class="dropdown">
                            <button class="btn btn-warning btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                <i class="bi bi-gear me-1"></i>Actions
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li><h6 class="dropdown-header">Paiement</h6></li>
                                <li><a class="dropdown-item bulk-action-st" href="#" data-action="payer"><i class="bi bi-check-circle text-success me-2"></i>Marquer payée</a></li>
                                <li><a class="dropdown-item bulk-action-st" href="#" data-action="non_payer"><i class="bi bi-clock text-primary me-2"></i>Marquer non payée</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><h6 class="dropdown-header">Statut</h6></li>
                                <li><a class="dropdown-item bulk-action-st" href="#" data-action="approuver"><i class="bi bi-check-circle text-success me-2"></i>Approuver</a></li>
                                <li><a class="dropdown-item bulk-action-st" href="#" data-action="en_attente"><i class="bi bi-clock text-warning me-2"></i>En attente</a></li>
                                <li><a class="dropdown-item bulk-action-st" href="#" data-action="rejeter"><i class="bi bi-x-circle text-danger me-2"></i>Rejeter</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item bulk-action-st text-danger" href="#" data-action="supprimer"><i class="bi bi-trash me-2"></i>Supprimer</a></li>
                            </ul>
                        </div>
                    </div>
                </div>
                <!-- Bouton tout sélectionner/désélectionner -->
                <?php if (!empty($sousTraitantsProjet)): ?>
                <button type="button" class="btn btn-outline-secondary btn-sm" id="toggleSelectAllBtnST" onclick="toggleSelectAllSousTraitants()" title="Tout sélectionner">
                    <i class="bi bi-check2-square" id="toggleSelectAllIconST"></i>
                </button>
                <?php endif; ?>
                <span class="badge bg-secondary" id="sousTraitantsCount"><?= count($sousTraitantsProjet) ?> sous-traitants</span>
                <a href="<?= url('/admin/soustraitants/nouvelle.php?projet=' . $projetId) ?>" class="btn btn-success btn-sm">
                    <i class="bi bi-plus me-1"></i>Nouveau
                </a>
            </div>
        </div>

        <?php if (empty($sousTraitantsProjet)): ?>
            <div class="alert alert-info">
                <i class="bi bi-info-circle me-2"></i>Aucun sous-traitant pour ce projet. Cliquez sur "Nouveau" pour en ajouter.
            </div>
        <?php else: ?>
            <div class="table-responsive" style="overflow: visible;">
                <table class="table table-sm table-hover" id="sousTraitantsTable">
                    <thead class="table-dark">
                        <tr>
                            <th style="width: 40px;" class="text-center">
                                <input type="checkbox" class="form-check-input" id="selectAllSousTraitants" title="Tout sélectionner">
                            </th>
                            <th class="sortable-header-st" data-sort="date" style="cursor: pointer;" title="Cliquer pour trier">
                                Date <i class="bi bi-arrow-down-up text-muted ms-1 sort-icon-st"></i>
                            </th>
                            <th class="sortable-header-st" data-sort="entreprise" style="cursor: pointer;" title="Cliquer pour trier">
                                Entreprise <i class="bi bi-arrow-down-up text-muted ms-1 sort-icon-st"></i>
                            </th>
                            <th class="sortable-header-st" data-sort="categorie" style="cursor: pointer;" title="Cliquer pour trier">
                                Catégorie <i class="bi bi-arrow-down-up text-muted ms-1 sort-icon-st"></i>
                            </th>
                            <th class="text-end sortable-header-st" data-sort="montant" style="cursor: pointer;" title="Cliquer pour trier">
                                Montant <i class="bi bi-arrow-down-up text-muted ms-1 sort-icon-st"></i>
                            </th>
                            <th>Statut</th>
                            <th class="sortable-header-st" data-sort="paiement" style="cursor: pointer;" title="Cliquer pour trier">
                                Paiement <i class="bi bi-arrow-down-up text-muted ms-1 sort-icon-st"></i>
                            </th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sousTraitantsProjet as $st): ?>
                        <tr class="soustraitant-row" data-id="<?= $st['id'] ?>" data-statut="<?= e($st['statut']) ?>" data-categorie="<?= e($st['etape_nom'] ?? '') ?>" data-entreprise="<?= e($st['nom_entreprise'] ?? '') ?>" data-montant="<?= $st['montant_total'] ?>" data-date="<?= e($st['date_facture'] ?? '') ?>" data-description="<?= e(strtolower($st['description'] ?? '')) ?>" data-paiement="<?= !empty($st['est_payee']) ? '1' : '0' ?>" data-href="<?= url('/admin/soustraitants/modifier.php?id=' . $st['id']) ?>" style="cursor: pointer;">
                            <td class="text-center" onclick="event.stopPropagation();">
                                <input type="checkbox" class="form-check-input soustraitant-checkbox" value="<?= $st['id'] ?>">
                            </td>
                            <td><?= formatDate($st['date_facture']) ?></td>
                            <td>
                                <strong><?= e($st['nom_entreprise'] ?? 'N/A') ?></strong>
                                <?php if (!empty($st['contact'])): ?>
                                    <br><small class="text-muted"><?= e($st['contact']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td><?php if (empty($st['etape_nom'])): ?><span class="text-danger fw-bold">N/A</span><?php else: ?><?= e($st['etape_nom']) ?><?php endif; ?></td>
                            <td class="text-end fw-bold"><?= formatMoney($st['montant_total']) ?></td>
                            <td>
                                <?php
                                $statusClass = match($st['statut']) {
                                    'approuvee' => 'bg-success',
                                    'rejetee' => 'bg-danger',
                                    default => 'bg-warning text-dark'
                                };
                                ?>
                                <div class="dropdown">
                                    <button class="btn btn-sm <?= $statusClass ?> dropdown-toggle" type="button" data-bs-toggle="dropdown" data-bs-strategy="fixed" aria-expanded="false">
                                        <?= getStatutFactureLabel($st['statut']) ?>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end">
                                        <li><a class="dropdown-item change-soustraitant-status <?= $st['statut'] === 'en_attente' ? 'active' : '' ?>" href="#" data-soustraitant-id="<?= $st['id'] ?>" data-status="en_attente"><i class="bi bi-clock text-warning me-2"></i>En attente</a></li>
                                        <li><a class="dropdown-item change-soustraitant-status <?= $st['statut'] === 'approuvee' ? 'active' : '' ?>" href="#" data-soustraitant-id="<?= $st['id'] ?>" data-status="approuvee"><i class="bi bi-check-circle text-success me-2"></i>Approuver</a></li>
                                        <li><a class="dropdown-item change-soustraitant-status <?= $st['statut'] === 'rejetee' ? 'active' : '' ?>" href="#" data-soustraitant-id="<?= $st['id'] ?>" data-status="rejetee"><i class="bi bi-x-circle text-danger me-2"></i>Rejeter</a></li>
                                    </ul>
                                </div>
                            </td>
                            <td>
                                <a href="#"
                                   class="badge <?= !empty($st['est_payee']) ? 'bg-success' : 'bg-primary' ?> text-white"
                                   style="cursor:pointer; text-decoration:none;"
                                   title="Cliquer pour changer le statut"
                                   onclick="event.preventDefault(); togglePaiementSousTraitant(<?= $st['id'] ?>, this);">
                                    <?php if (!empty($st['est_payee'])): ?>
                                        <i class="bi bi-check-circle me-1"></i>Payé
                                    <?php else: ?>
                                        <i class="bi bi-clock me-1"></i>Non payé
                                    <?php endif; ?>
                                </a>
                            </td>
                            <td>
                                <a href="<?= url('/admin/soustraitants/modifier.php?id=' . $st['id']) ?>" class="btn btn-sm btn-outline-primary" title="Modifier">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <form action="<?= url('/admin/soustraitants/supprimer.php') ?>" method="POST" class="d-inline" onsubmit="return confirm('Supprimer ce sous-traitant?')">
                                    <?php csrfField(); ?>
                                    <input type="hidden" name="soustraitant_id" value="<?= $st['id'] ?>">
                                    <input type="hidden" name="redirect" value="/admin/projets/detail.php?id=<?= $projetId ?>&tab=soustraitants">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Supprimer">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div><!-- Fin TAB SOUS-TRAITANTS -->

<script>
// Clic sur ligne pour ouvrir le sous-traitant
document.querySelectorAll('#sousTraitantsTable .soustraitant-row[data-href]').forEach(row => {
    row.addEventListener('click', function(e) {
        // Ne pas naviguer si on clique sur un bouton, lien, dropdown ou formulaire
        if (e.target.closest('button, a, .dropdown, form, input')) return;
        window.location.href = this.dataset.href;
    });
});

// Filtrage des sous-traitants
function filtrerSousTraitants() {
    const statut = document.getElementById('filtreSousTraitantsStatut').value.toLowerCase();
    const categorie = document.getElementById('filtreSousTraitantsCategorie').value.toLowerCase();
    const entreprise = document.getElementById('filtreSousTraitantsEntreprise').value.toLowerCase();
    const dateDebut = document.getElementById('filtreSousTraitantsDateDebut').value;
    const dateFin = document.getElementById('filtreSousTraitantsDateFin').value;
    const montantMin = parseFloat(document.getElementById('filtreSousTraitantsMontantMin').value) || 0;
    const montantMax = parseFloat(document.getElementById('filtreSousTraitantsMontantMax').value) || Infinity;
    const search = document.getElementById('searchSousTraitants').value.toLowerCase();

    let totalVisible = 0;
    let countVisible = 0;

    document.querySelectorAll('.soustraitant-row').forEach(row => {
        const rowStatut = row.dataset.statut.toLowerCase();
        const rowCategorie = row.dataset.categorie.toLowerCase();
        const rowEntreprise = row.dataset.entreprise.toLowerCase();
        const rowDate = row.dataset.date;
        const rowMontant = parseFloat(row.dataset.montant) || 0;
        const rowDescription = row.dataset.description;

        let visible = true;

        // Filtre statut
        if (statut && rowStatut !== statut) visible = false;

        // Filtre catégorie
        if (categorie && rowCategorie !== categorie) visible = false;

        // Filtre entreprise
        if (entreprise && rowEntreprise !== entreprise) visible = false;

        // Filtre date
        if (dateDebut && rowDate < dateDebut) visible = false;
        if (dateFin && rowDate > dateFin) visible = false;

        // Filtre montant
        if (rowMontant < montantMin || rowMontant > montantMax) visible = false;

        // Filtre recherche texte
        if (search && !rowEntreprise.includes(search) && !rowDescription.includes(search) && !rowCategorie.includes(search)) {
            visible = false;
        }

        row.style.display = visible ? '' : 'none';
        if (visible) {
            totalVisible += rowMontant;
            countVisible++;
        }
    });

    // Mettre à jour les totaux affichés
    document.getElementById('sousTraitantsTotal').textContent = new Intl.NumberFormat('fr-CA', { style: 'currency', currency: 'CAD' }).format(totalVisible);
    document.getElementById('sousTraitantsCount').textContent = countVisible + ' sous-traitants';
}

function resetFiltresSousTraitants() {
    document.getElementById('filtreSousTraitantsStatut').value = '';
    document.getElementById('filtreSousTraitantsCategorie').value = '';
    document.getElementById('filtreSousTraitantsEntreprise').value = '';
    document.getElementById('filtreSousTraitantsDateDebut').value = '';
    document.getElementById('filtreSousTraitantsDateFin').value = '';
    document.getElementById('filtreSousTraitantsMontantMin').value = '';
    document.getElementById('filtreSousTraitantsMontantMax').value = '';
    document.getElementById('searchSousTraitants').value = '';
    filtrerSousTraitants();
}

// Tri des colonnes
(function() {
    let currentSort = { column: null, asc: true };

    document.querySelectorAll('.sortable-header-st').forEach(header => {
        header.addEventListener('click', function() {
            const column = this.dataset.sort;
            const asc = currentSort.column === column ? !currentSort.asc : true;
            currentSort = { column, asc };

            // Mettre à jour les icônes
            document.querySelectorAll('.sort-icon-st').forEach(icon => {
                icon.className = 'bi bi-arrow-down-up text-muted ms-1 sort-icon-st';
            });
            const icon = this.querySelector('.sort-icon-st');
            icon.className = `bi bi-arrow-${asc ? 'up' : 'down'} text-white ms-1 sort-icon-st`;

            // Trier les lignes
            const tbody = document.querySelector('#sousTraitantsTable tbody');
            const rows = Array.from(tbody.querySelectorAll('.soustraitant-row'));

            rows.sort((a, b) => {
                let valA, valB;

                switch(column) {
                    case 'date':
                        valA = a.dataset.date || '';
                        valB = b.dataset.date || '';
                        break;
                    case 'entreprise':
                        valA = a.dataset.entreprise.toLowerCase();
                        valB = b.dataset.entreprise.toLowerCase();
                        break;
                    case 'categorie':
                        valA = a.dataset.categorie.toLowerCase();
                        valB = b.dataset.categorie.toLowerCase();
                        break;
                    case 'montant':
                        valA = parseFloat(a.dataset.montant) || 0;
                        valB = parseFloat(b.dataset.montant) || 0;
                        break;
                    case 'paiement':
                        valA = a.dataset.paiement === '1' ? 1 : 0;
                        valB = b.dataset.paiement === '1' ? 1 : 0;
                        break;
                    default:
                        return 0;
                }

                if (valA < valB) return asc ? -1 : 1;
                if (valA > valB) return asc ? 1 : -1;
                return 0;
            });

            rows.forEach(row => tbody.appendChild(row));
        });
    });
})();

// Sélection multiple de sous-traitants
(function() {
    const selectAll = document.getElementById('selectAllSousTraitants');
    const bulkMenu = document.getElementById('bulkActionsMenuST');
    const selectedCountBadge = document.getElementById('selectedCountST');
    const checkboxes = document.querySelectorAll('.soustraitant-checkbox');

    if (!selectAll || !bulkMenu) return;

    function updateBulkMenu() {
        const checked = document.querySelectorAll('.soustraitant-checkbox:checked');
        const count = checked.length;

        if (count > 0) {
            bulkMenu.classList.remove('d-none');
            selectedCountBadge.textContent = count + ' sélectionnée(s)';
        } else {
            bulkMenu.classList.add('d-none');
        }

        // Mettre à jour le checkbox "tout sélectionner"
        const visibleCheckboxes = document.querySelectorAll('.soustraitant-row:not([style*="display: none"]) .soustraitant-checkbox');
        const visibleChecked = document.querySelectorAll('.soustraitant-row:not([style*="display: none"]) .soustraitant-checkbox:checked');
        selectAll.checked = visibleCheckboxes.length > 0 && visibleCheckboxes.length === visibleChecked.length;
        selectAll.indeterminate = visibleChecked.length > 0 && visibleChecked.length < visibleCheckboxes.length;
    }

    // Tout sélectionner / désélectionner
    selectAll.addEventListener('change', function() {
        const visibleCheckboxes = document.querySelectorAll('.soustraitant-row:not([style*="display: none"]) .soustraitant-checkbox');
        visibleCheckboxes.forEach(cb => cb.checked = this.checked);
        updateBulkMenu();
        updateToggleButtonST();
    });

    // Checkbox individuel
    checkboxes.forEach(cb => {
        cb.addEventListener('change', function() {
            updateBulkMenu();
            updateToggleButtonST();
        });
    });

    // Toggle tout sélectionner / désélectionner
    window.toggleSelectAllSousTraitants = function() {
        const visibleCheckboxes = document.querySelectorAll('.soustraitant-row:not([style*="display: none"]) .soustraitant-checkbox');
        const visibleChecked = document.querySelectorAll('.soustraitant-row:not([style*="display: none"]) .soustraitant-checkbox:checked');
        const allSelected = visibleCheckboxes.length > 0 && visibleCheckboxes.length === visibleChecked.length;

        visibleCheckboxes.forEach(cb => cb.checked = !allSelected);
        selectAll.checked = !allSelected;
        updateBulkMenu();
        updateToggleButtonST();
    };

    function updateToggleButtonST() {
        const toggleBtn = document.getElementById('toggleSelectAllBtnST');
        const toggleIcon = document.getElementById('toggleSelectAllIconST');
        if (!toggleBtn || !toggleIcon) return;

        const visibleCheckboxes = document.querySelectorAll('.soustraitant-row:not([style*="display: none"]) .soustraitant-checkbox');
        const visibleChecked = document.querySelectorAll('.soustraitant-row:not([style*="display: none"]) .soustraitant-checkbox:checked');
        const allSelected = visibleCheckboxes.length > 0 && visibleCheckboxes.length === visibleChecked.length;

        if (allSelected) {
            toggleIcon.className = 'bi bi-x-square';
            toggleBtn.title = 'Tout désélectionner';
        } else {
            toggleIcon.className = 'bi bi-check2-square';
            toggleBtn.title = 'Tout sélectionner';
        }
    }

    // Actions en masse
    document.querySelectorAll('.bulk-action-st').forEach(btn => {
        btn.addEventListener('click', async function(e) {
            e.preventDefault();
            const action = this.dataset.action;
            const checkedBoxes = document.querySelectorAll('.soustraitant-checkbox:checked');
            const ids = Array.from(checkedBoxes).map(cb => parseInt(cb.value));

            if (ids.length === 0) return;

            // Confirmation
            const actionLabels = {
                'payer': 'marquer comme payée(s)',
                'non_payer': 'marquer comme non payée(s)',
                'approuver': 'approuver',
                'en_attente': 'mettre en attente',
                'rejeter': 'rejeter',
                'supprimer': 'SUPPRIMER définitivement'
            };
            const label = actionLabels[action] || action;
            if (!confirm(`${label.toUpperCase()} ${ids.length} sous-traitant(s) ?`)) {
                return;
            }

            // Exécuter l'action
            try {
                const response = await fetch('<?= url('/api/soustraitants/bulk-action.php') ?>', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: action, ids: ids })
                });

                const text = await response.text();
                let result;
                try {
                    result = JSON.parse(text);
                } catch (e) {
                    console.error('Réponse non-JSON:', text);
                    window.location.reload();
                    return;
                }

                if (result.success) {
                    window.location.reload();
                } else {
                    alert('Erreur: ' + (result.error || 'Une erreur est survenue'));
                }
            } catch (err) {
                console.error('Erreur:', err);
                window.location.reload();
            }
        });
    });
})();

// Changement de statut rapide
document.querySelectorAll('.change-soustraitant-status').forEach(link => {
    link.addEventListener('click', async function(e) {
        e.preventDefault();
        const soustraitantId = this.dataset.soustraitantId;
        const newStatus = this.dataset.status;

        try {
            const response = await fetch('<?= url('/api/soustraitants/change-status.php') ?>', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: soustraitantId, statut: newStatus })
            });

            const result = await response.json();
            if (result.success) {
                window.location.reload();
            } else {
                alert('Erreur: ' + (result.error || 'Une erreur est survenue'));
            }
        } catch (err) {
            console.error('Erreur:', err);
            window.location.reload();
        }
    });
});

// Toggle paiement
window.togglePaiementSousTraitant = async function(id, element) {
    try {
        const response = await fetch('<?= url('/api/soustraitants/toggle-paiement.php') ?>', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: id })
        });

        const result = await response.json();
        if (result.success) {
            // Mettre à jour l'affichage
            if (result.est_payee) {
                element.className = 'badge bg-success text-white';
                element.innerHTML = '<i class="bi bi-check-circle me-1"></i>Payé';
            } else {
                element.className = 'badge bg-primary text-white';
                element.innerHTML = '<i class="bi bi-clock me-1"></i>Non payé';
            }
            // Mettre à jour le data attribute pour le tri
            element.closest('.soustraitant-row').dataset.paiement = result.est_payee ? '1' : '0';
        } else {
            alert('Erreur: ' + (result.error || 'Une erreur est survenue'));
        }
    } catch (err) {
        console.error('Erreur:', err);
        alert('Erreur de connexion');
    }
};
</script>
