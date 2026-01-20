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
                <?php if (isAdmin()): ?>
                <button type="button" class="btn btn-outline-success btn-sm" data-bs-toggle="modal" data-bs-target="#modalMultiSousTraitants">
                    <i class="bi bi-files me-1"></i>Plusieurs
                </button>
                <?php endif; ?>
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
                            <td><?= e($st['nom_entreprise'] ?? 'N/A') ?></td>
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

<?php if (isAdmin()): ?>
<!-- Modal Multi-SousTraitants -->
<div class="modal fade" id="modalMultiSousTraitants" tabindex="-1" aria-labelledby="modalMultiSousTraitantsLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content bg-dark">
            <div class="modal-header border-secondary">
                <h5 class="modal-title" id="modalMultiSousTraitantsLabel">
                    <i class="bi bi-files me-2"></i>Ajouter plusieurs sous-traitants
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <!-- Drop Zone avec animation porte de garage -->
                <div id="multiDropZoneWrapperST" style="max-height: 300px; overflow: hidden; transition: max-height 1.5s ease-in-out, margin-bottom 1.5s ease-in-out, opacity 1s ease-in-out; margin-bottom: 1rem; opacity: 1;">
                    <div id="multiDropZoneST" class="border border-2 border-dashed rounded p-5 text-center"
                         style="border-color: #6c757d !important; transition: border-color 0.3s, background 0.3s; cursor: pointer;">
                        <i class="bi bi-cloud-arrow-up display-3 text-muted mb-3 d-block"></i>
                        <p class="mb-1">Glissez-déposez vos soumissions ici</p>
                        <p class="text-muted small mb-3">ou cliquez pour sélectionner</p>
                        <p class="text-muted small mb-0">Formats acceptés: PDF, JPG, PNG (max 5 MB chacun)</p>
                        <input type="file" id="multiFileInputST" multiple accept=".pdf,.jpg,.jpeg,.png" class="d-none">
                    </div>
                </div>

                <!-- Liste des fichiers -->
                <div id="multiFilesListST" class="mb-3" style="display: none;">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h6 class="mb-0"><i class="bi bi-list-ul me-2"></i>Fichiers à traiter</h6>
                        <div class="d-flex align-items-center gap-3">
                            <!-- Options de statut -->
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" id="multiSTApprouvee">
                                <label class="form-check-label small" for="multiSTApprouvee">
                                    <i class="bi bi-check-circle text-success"></i> Approuvée
                                </label>
                            </div>
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" id="multiSTPayee">
                                <label class="form-check-label small" for="multiSTPayee">
                                    <i class="bi bi-cash-stack text-primary"></i> Payée
                                </label>
                            </div>
                            <button type="button" class="btn btn-outline-danger btn-sm" id="clearFilesBtnST">
                                <i class="bi bi-trash me-1"></i>Tout effacer
                            </button>
                        </div>
                    </div>
                    <div id="filesContainerST" class="border rounded" style="max-height: 300px; overflow-y: auto;"></div>
                </div>

                <!-- Progress global -->
                <div id="multiProgressSectionST" style="display: none;">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span id="progressTextST">Traitement en cours...</span>
                        <span id="progressCountST">0/0</span>
                    </div>
                    <div class="progress" style="height: 8px;">
                        <div class="progress-bar progress-bar-striped progress-bar-animated" id="multiProgressBarST" style="width: 0%"></div>
                    </div>
                </div>

                <!-- Résumé final -->
                <div id="multiResultSectionST" style="display: none;">
                    <div class="alert alert-success mb-2" id="resultSuccessST" style="display: none;">
                        <i class="bi bi-check-circle me-2"></i><span id="successCountST">0</span> sous-traitant(s) créé(s) avec succès
                    </div>
                    <div class="alert alert-danger mb-2" id="resultErrorsST" style="display: none;">
                        <i class="bi bi-exclamation-triangle me-2"></i><span id="errorCountST">0</span> erreur(s)
                    </div>
                </div>
            </div>
            <div class="modal-footer border-secondary">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fermer</button>
                <button type="button" class="btn btn-success" id="startProcessingBtnST" disabled>
                    <i class="bi bi-play-fill me-1"></i>Démarrer le traitement
                </button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

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

// Multi-soustraitants upload (Admin only)
<?php if (isAdmin()): ?>
(function() {
    const projetId = <?= (int)$projetId ?>;
    const dropZone = document.getElementById('multiDropZoneST');
    const dropZoneWrapper = document.getElementById('multiDropZoneWrapperST');
    const fileInput = document.getElementById('multiFileInputST');
    const filesList = document.getElementById('multiFilesListST');
    const filesContainer = document.getElementById('filesContainerST');
    const clearFilesBtn = document.getElementById('clearFilesBtnST');
    const startBtn = document.getElementById('startProcessingBtnST');
    const progressSection = document.getElementById('multiProgressSectionST');
    const progressBar = document.getElementById('multiProgressBarST');
    const progressText = document.getElementById('progressTextST');
    const progressCount = document.getElementById('progressCountST');
    const resultSection = document.getElementById('multiResultSectionST');
    const resultSuccess = document.getElementById('resultSuccessST');
    const resultErrors = document.getElementById('resultErrorsST');
    const successCount = document.getElementById('successCountST');
    const errorCount = document.getElementById('errorCountST');
    const checkApprouvee = document.getElementById('multiSTApprouvee');
    const checkPayee = document.getElementById('multiSTPayee');

    let filesToProcess = [];
    let isProcessing = false;

    // Drag & Drop events
    dropZone.addEventListener('dragover', (e) => {
        e.preventDefault();
        dropZone.style.borderColor = '#198754';
        dropZone.style.background = 'rgba(25, 135, 84, 0.1)';
    });

    dropZone.addEventListener('dragleave', (e) => {
        e.preventDefault();
        dropZone.style.borderColor = '#6c757d';
        dropZone.style.background = 'transparent';
    });

    dropZone.addEventListener('drop', (e) => {
        e.preventDefault();
        dropZone.style.borderColor = '#6c757d';
        dropZone.style.background = 'transparent';
        handleFiles(e.dataTransfer.files);
    });

    // Click to select
    dropZone.addEventListener('click', () => {
        if (!isProcessing) fileInput.click();
    });

    fileInput.addEventListener('change', () => {
        handleFiles(fileInput.files);
        fileInput.value = '';
    });

    // Clear files
    clearFilesBtn.addEventListener('click', () => {
        filesToProcess = [];
        updateFilesList();
    });

    // Start processing
    startBtn.addEventListener('click', () => {
        if (filesToProcess.length > 0 && !isProcessing) {
            processQueue();
        }
    });

    function handleFiles(files) {
        const allowedTypes = ['image/jpeg', 'image/png', 'application/pdf'];
        const maxSize = 5 * 1024 * 1024;

        Array.from(files).forEach(file => {
            // Check type
            if (!allowedTypes.includes(file.type)) {
                showToast(`${file.name}: format non supporté`, 'warning');
                return;
            }
            // Check size
            if (file.size > maxSize) {
                showToast(`${file.name}: trop volumineux (max 5 MB)`, 'warning');
                return;
            }
            // Check duplicate
            if (filesToProcess.some(f => f.file.name === file.name && f.file.size === file.size)) {
                return;
            }
            // Add to queue
            filesToProcess.push({
                file: file,
                status: 'pending',
                result: null
            });
        });

        updateFilesList();
    }

    function updateFilesList() {
        if (filesToProcess.length === 0) {
            filesList.style.display = 'none';
            startBtn.disabled = true;
            return;
        }

        filesList.style.display = 'block';
        startBtn.disabled = isProcessing;

        filesContainer.innerHTML = filesToProcess.map((item, index) => {
            const icon = item.file.type === 'application/pdf' ? 'bi-file-pdf text-danger' : 'bi-file-image text-primary';
            const size = (item.file.size / 1024).toFixed(0) + ' KB';

            let statusBadge = '';
            let aiDetails = '';

            switch(item.status) {
                case 'pending':
                    statusBadge = '<span class="badge bg-secondary">En attente</span>';
                    break;
                case 'processing':
                    statusBadge = '<span class="badge bg-warning text-dark"><span class="spinner-border spinner-border-sm me-1"></span>Analyse IA...</span>';
                    break;
                case 'success':
                    statusBadge = '<span class="badge bg-success"><i class="bi bi-check me-1"></i>Créé</span>';
                    // Afficher les données analysées par l'IA
                    if (item.analyseData) {
                        const data = item.analyseData;
                        aiDetails = `
                            <div class="mt-2 p-2 rounded" style="background: rgba(25,135,84,0.1); font-size: 0.8rem;">
                                <div class="row g-2">
                                    <div class="col-6">
                                        <strong class="text-success"><i class="bi bi-building me-1"></i>Entreprise:</strong><br>
                                        ${data.nom_entreprise || data.fournisseur || 'N/A'}
                                    </div>
                                    <div class="col-6">
                                        <strong class="text-success"><i class="bi bi-calendar me-1"></i>Date:</strong><br>
                                        ${data.date_facture || data.date_soumission || 'N/A'}
                                    </div>
                                    <div class="col-4">
                                        <strong>Sous-total:</strong><br>
                                        ${formatMoney(data.sous_total || data.montant_avant_taxes || 0)}
                                    </div>
                                    <div class="col-4">
                                        <strong>TPS/TVQ:</strong><br>
                                        ${formatMoney((data.tps || 0) + (data.tvq || 0))}
                                    </div>
                                    <div class="col-4">
                                        <strong class="text-primary">Total:</strong><br>
                                        <span class="text-primary fw-bold">${formatMoney(data.total || data.montant_total || 0)}</span>
                                    </div>
                                    ${data.description ? `<div class="col-12"><strong>Description:</strong><br><small class="text-muted">${data.description.substring(0, 100)}${data.description.length > 100 ? '...' : ''}</small></div>` : ''}
                                </div>
                            </div>
                        `;
                    }
                    break;
                case 'error':
                    statusBadge = `<span class="badge bg-danger" title="${item.error}"><i class="bi bi-x me-1"></i>Erreur</span>`;
                    break;
            }

            const removeBtn = item.status === 'pending' && !isProcessing
                ? `<button type="button" class="btn btn-sm btn-outline-danger ms-2" onclick="removeMultiFileST(${index})"><i class="bi bi-x"></i></button>`
                : '';

            const errorMsg = item.status === 'error' ? `<div class="text-danger small mt-1"><i class="bi bi-exclamation-triangle me-1"></i>${item.error}</div>` : '';

            return `
                <div class="p-2 border-bottom multi-file-item-st" data-index="${index}" style="background: ${item.status === 'processing' ? 'rgba(255,193,7,0.1)' : item.status === 'error' ? 'rgba(220,53,69,0.1)' : item.status === 'success' ? 'rgba(25,135,84,0.05)' : 'transparent'}">
                    <div class="d-flex align-items-center">
                        <i class="bi ${icon} me-2 fs-5"></i>
                        <div class="flex-grow-1">
                            <div class="text-truncate" style="max-width: 350px;" title="${item.file.name}">
                                <strong>${item.file.name}</strong>
                            </div>
                            <small class="text-muted">${size}</small>
                        </div>
                        <div class="ms-auto d-flex align-items-center">
                            ${statusBadge}
                            ${removeBtn}
                        </div>
                    </div>
                    ${aiDetails}
                    ${errorMsg}
                </div>
            `;
        }).join('');

        // Auto-scroll vers le fichier en cours de traitement
        setTimeout(() => {
            let targetIndex = -1;
            for (let i = 0; i < filesToProcess.length; i++) {
                if (filesToProcess[i].status === 'processing') {
                    targetIndex = i;
                    break;
                }
                if (filesToProcess[i].status === 'success' || filesToProcess[i].status === 'error') {
                    targetIndex = i;
                }
            }
            if (targetIndex >= 0) {
                const targetItem = filesContainer.querySelector(`.multi-file-item-st[data-index="${targetIndex}"]`);
                if (targetItem) {
                    targetItem.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                }
            }
        }, 50);
    }

    window.removeMultiFileST = function(index) {
        filesToProcess.splice(index, 1);
        updateFilesList();
    };

    // Convertir fichier en base64
    function fileToBase64(file) {
        return new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.onload = () => resolve(reader.result);
            reader.onerror = reject;
            reader.readAsDataURL(file);
        });
    }

    // Traiter un fichier
    async function processFile(item) {
        let imageBase64 = null;

        // Étape 1: Convertir en image base64
        if (item.file.type === 'application/pdf') {
            // PDF: utiliser l'API de conversion
            const formData = new FormData();
            formData.append('fichier', item.file);

            const convResponse = await fetch('<?= url('/api/factures/convertir-pdf.php') ?>', {
                method: 'POST',
                body: formData
            });
            const convResult = await convResponse.json();

            if (!convResult.success) {
                throw new Error(convResult.error || 'Erreur conversion PDF');
            }
            imageBase64 = convResult.image;
        } else {
            // Image: lire directement en base64
            imageBase64 = await fileToBase64(item.file);
        }

        // Étape 2: Analyser avec l'IA (utiliser l'API d'analyse de soumission)
        const analyseResponse = await fetch('<?= url('/api/analyse-soumission.php') ?>', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ image: imageBase64 })
        });
        const analyseResult = await analyseResponse.json();

        if (!analyseResult.success || !analyseResult.data) {
            throw new Error(analyseResult.error || 'Erreur analyse IA');
        }

        // Stocker les données analysées pour l'affichage
        item.analyseData = analyseResult.data;

        // Étape 3: Créer le sous-traitant avec les données analysées
        const createResponse = await fetch('<?= url('/api/soustraitants/creer-depuis-analyse.php') ?>', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                projet_id: projetId,
                data: analyseResult.data,
                fichier_base64: imageBase64,
                fichier_nom: item.file.name,
                statut: checkApprouvee.checked ? 'approuvee' : 'en_attente',
                est_payee: checkPayee.checked ? 1 : 0
            })
        });
        const createResult = await createResponse.json();

        if (!createResult.success) {
            throw new Error(createResult.error || 'Erreur création sous-traitant');
        }

        return createResult;
    }

    async function processQueue() {
        isProcessing = true;
        startBtn.disabled = true;

        // Animation porte de garage - fermeture smooth
        dropZoneWrapper.style.maxHeight = '0';
        dropZoneWrapper.style.marginBottom = '0';
        dropZoneWrapper.style.opacity = '0';

        progressSection.style.display = 'block';
        resultSection.style.display = 'none';

        let successTotal = 0;
        let errorTotal = 0;

        for (let i = 0; i < filesToProcess.length; i++) {
            const item = filesToProcess[i];
            item.status = 'processing';
            updateFilesList();

            progressText.textContent = `Traitement: ${item.file.name}`;
            progressCount.textContent = `${i + 1}/${filesToProcess.length}`;
            progressBar.style.width = ((i + 1) / filesToProcess.length * 100) + '%';

            try {
                const result = await processFile(item);
                item.status = 'success';
                item.result = result;
                successTotal++;
            } catch (err) {
                item.status = 'error';
                item.error = err.message || 'Erreur inconnue';
                errorTotal++;
                console.error('Erreur traitement:', item.file.name, err);
            }

            updateFilesList();
        }

        // Show results
        isProcessing = false;
        progressSection.style.display = 'none';
        resultSection.style.display = 'block';

        if (successTotal > 0) {
            resultSuccess.style.display = 'block';
            successCount.textContent = successTotal;
        } else {
            resultSuccess.style.display = 'none';
        }

        if (errorTotal > 0) {
            resultErrors.style.display = 'block';
            errorCount.textContent = errorTotal;
        } else {
            resultErrors.style.display = 'none';
        }

        // Proposer de recharger la page
        startBtn.innerHTML = '<i class="bi bi-arrow-clockwise me-1"></i>Recharger la page';
        startBtn.disabled = false;
        startBtn.onclick = () => window.location.reload();
    }

    function formatMoney(amount) {
        return new Intl.NumberFormat('fr-CA', { style: 'currency', currency: 'CAD' }).format(amount);
    }

    function showToast(message, type = 'info') {
        console.log(`[${type}] ${message}`);
    }

    // Reset modal on close
    document.getElementById('modalMultiSousTraitants').addEventListener('hidden.bs.modal', () => {
        if (!isProcessing) {
            filesToProcess = [];
            updateFilesList();
            progressSection.style.display = 'none';
            resultSection.style.display = 'none';
            // Réouvrir la dropzone avec animation
            dropZoneWrapper.style.maxHeight = '300px';
            dropZoneWrapper.style.marginBottom = '1rem';
            dropZoneWrapper.style.opacity = '1';
            startBtn.innerHTML = '<i class="bi bi-play-fill me-1"></i>Démarrer le traitement';
            startBtn.onclick = () => { if (filesToProcess.length > 0 && !isProcessing) processQueue(); };
        }
    });
})();
<?php endif; ?>
</script>
