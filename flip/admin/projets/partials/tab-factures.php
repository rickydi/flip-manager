    <div class="tab-pane fade <?= $tab === 'factures' ? 'show active' : '' ?>" id="factures" role="tabpanel">
        <?php
        $totalFacturesTab = array_sum(array_column($facturesProjet, 'montant_total'));
        $facturesCategories = array_unique(array_filter(array_column($facturesProjet, 'etape_nom')));
        $totalImpayeProjet = array_sum(array_map(function($f) {
            return empty($f['est_payee']) ? $f['montant_total'] : 0;
        }, $facturesProjet));
        sort($facturesCategories);
        $facturesFournisseurs = array_unique(array_filter(array_column($facturesProjet, 'fournisseur')));
        sort($facturesFournisseurs);

        ?>

        <!-- Barre compacte : Total + Filtres + Actions -->
        <div class="d-flex flex-wrap align-items-center gap-2 mb-3 p-2 rounded" style="background: rgba(255,255,255,0.03);">
            <!-- Total -->
            <div class="d-flex align-items-center px-3 py-1 rounded" style="background: rgba(220,53,69,0.15);">
                <i class="bi bi-receipt text-danger me-2"></i>
                <span class="text-muted me-2">Total:</span>
                <strong class="text-danger" id="facturesTotal"><?= formatMoney($totalFacturesTab) ?></strong>
            </div>
<?php if ($totalImpayeProjet > 0): ?>            <!-- Impayé -->            <div class="d-flex align-items-center px-3 py-1 rounded" style="background: rgba(255,193,7,0.15);">                <i class="bi bi-exclamation-circle text-warning me-2"></i>                <span class="text-muted me-2">Impayé:</span>                <strong class="text-warning"><?= formatMoney($totalImpayeProjet) ?></strong>            </div>            <?php endif; ?>

            <!-- Séparateur -->
            <div class="vr mx-1 d-none d-md-block" style="height: 24px;"></div>

            <!-- Filtres -->
            <select class="form-select form-select-sm" id="filtreFacturesStatut" onchange="filtrerFactures()" style="width: auto; min-width: 130px;">
                <option value="">Tous statuts</option>
                <option value="en_attente">En attente</option>
                <option value="approuvee">Approuvée</option>
                <option value="rejetee">Rejetée</option>
            </select>

            <select class="form-select form-select-sm" id="filtreFacturesCategorie" onchange="filtrerFactures()" style="width: auto; min-width: 150px;">
                <option value="">Toutes catégories</option>
                <?php foreach ($facturesCategories as $cat): ?>
                    <option value="<?= e($cat) ?>"><?= e($cat) ?></option>
                <?php endforeach; ?>
            </select>

            <select class="form-select form-select-sm" id="filtreFacturesFournisseur" onchange="filtrerFactures()" style="width: auto; min-width: 150px;">
                <option value="">Tous fournisseurs</option>
                <?php foreach ($facturesFournisseurs as $four): ?>
                    <option value="<?= e($four) ?>"><?= e($four) ?></option>
                <?php endforeach; ?>
            </select>

            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="resetFiltresFactures()" title="Réinitialiser">
                <i class="bi bi-x-circle"></i>
            </button>

            <!-- Spacer + Actions à droite -->
            <div class="ms-auto d-flex align-items-center gap-2">
                <!-- Menu actions en masse (caché par défaut) -->
                <div id="bulkActionsMenu" class="d-none">
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge bg-primary" id="selectedCount">0 sélectionnée(s)</span>
                        <div class="dropdown">
                            <button class="btn btn-warning btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                <i class="bi bi-gear me-1"></i>Actions
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li><h6 class="dropdown-header">Paiement</h6></li>
                                <li><a class="dropdown-item bulk-action" href="#" data-action="payer"><i class="bi bi-check-circle text-success me-2"></i>Marquer payée</a></li>
                                <li><a class="dropdown-item bulk-action" href="#" data-action="non_payer"><i class="bi bi-clock text-primary me-2"></i>Marquer non payée</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><h6 class="dropdown-header">Statut</h6></li>
                                <li><a class="dropdown-item bulk-action" href="#" data-action="approuver"><i class="bi bi-check-circle text-success me-2"></i>Approuver</a></li>
                                <li><a class="dropdown-item bulk-action" href="#" data-action="en_attente"><i class="bi bi-clock text-warning me-2"></i>En attente</a></li>
                                <li><a class="dropdown-item bulk-action" href="#" data-action="rejeter"><i class="bi bi-x-circle text-danger me-2"></i>Rejeter</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item bulk-action text-danger" href="#" data-action="supprimer"><i class="bi bi-trash me-2"></i>Supprimer</a></li>
                            </ul>
                        </div>
                    </div>
                </div>
                <!-- Bouton tout sélectionner/désélectionner -->
                <?php if (!empty($facturesProjet)): ?>
                <button type="button" class="btn btn-outline-secondary btn-sm" id="toggleSelectAllBtn" onclick="toggleSelectAllFactures()" title="Tout sélectionner">
                    <i class="bi bi-check2-square" id="toggleSelectAllIcon"></i>
                </button>
                <?php endif; ?>
                <span class="badge bg-secondary" id="facturesCount"><?= count($facturesProjet) ?> factures</span>
                <?php if (isAdmin()): ?>
                <button type="button" class="btn btn-outline-success btn-sm" data-bs-toggle="modal" data-bs-target="#modalMultiFactures">
                    <i class="bi bi-files me-1"></i>Plusieurs
                </button>
                <?php endif; ?>
                <a href="<?= url('/admin/factures/nouvelle.php?projet=' . $projetId) ?>" class="btn btn-success btn-sm">
                    <i class="bi bi-plus me-1"></i>Nouvelle
                </a>
            </div>
        </div>

        <?php if (empty($facturesProjet)): ?>
            <div class="alert alert-info">
                <i class="bi bi-info-circle me-2"></i>Aucune facture pour ce projet. Cliquez sur "Nouvelle" pour en ajouter.
            </div>
        <?php else: ?>
            <div class="table-responsive" style="overflow: visible;">
                <table class="table table-sm table-hover" id="facturesTable">
                    <thead class="table-dark">
                        <tr>
                            <th style="width: 40px;" class="text-center">
                                <input type="checkbox" class="form-check-input" id="selectAllFactures" title="Tout sélectionner">
                            </th>
                            <th>Date</th>
                            <th>Fournisseur</th>
                            <th class="text-center" style="width:30px" title="Articles détectés par IA"><i class="bi bi-robot"></i></th>
                            <th>Catégorie</th>
                            <th class="text-end">Montant</th>
                            <th>Statut</th>
                            <th>Paiement</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($facturesProjet as $f): ?>
                        <tr class="facture-row" data-id="<?= $f['id'] ?>" data-statut="<?= e($f['statut']) ?>" data-categorie="<?= e($f['etape_nom'] ?? '') ?>" data-fournisseur="<?= e($f['fournisseur'] ?? '') ?>" data-montant="<?= $f['montant_total'] ?>" data-href="<?= url('/admin/factures/modifier.php?id=' . $f['id']) ?>" style="cursor: pointer;">
                            <td class="text-center" onclick="event.stopPropagation();">
                                <input type="checkbox" class="form-check-input facture-checkbox" value="<?= $f['id'] ?>">
                            </td>
                            <td><?= formatDate($f['date_facture']) ?></td>
                            <td><?= e($f['fournisseur'] ?? 'N/A') ?></td>
                            <td class="text-center">
                                <?php if (!empty($f['nb_articles_ia']) && $f['nb_articles_ia'] > 0): ?>
                                    <i class="bi bi-check-circle-fill text-success" title="<?= $f['nb_articles_ia'] ?> article(s) détecté(s) par IA"></i>
                                <?php endif; ?>
                            </td>
                            <td><?php if (empty($f['etape_nom'])): ?><span class="text-danger fw-bold">N/A</span><?php else: ?><?= e($f['etape_nom']) ?><?php endif; ?></td>
                            <td class="text-end fw-bold"><?= formatMoney($f['montant_total']) ?></td>
                            <td>
                                <?php
                                $statusClass = match($f['statut']) {
                                    'approuvee' => 'bg-success',
                                    'rejetee' => 'bg-danger',
                                    default => 'bg-warning text-dark'
                                };
                                ?>
                                <div class="dropdown">
                                    <button class="btn btn-sm <?= $statusClass ?> dropdown-toggle" type="button" data-bs-toggle="dropdown" data-bs-strategy="fixed" aria-expanded="false">
                                        <?= getStatutFactureLabel($f['statut']) ?>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end">
                                        <li><a class="dropdown-item change-facture-status <?= $f['statut'] === 'en_attente' ? 'active' : '' ?>" href="#" data-facture-id="<?= $f['id'] ?>" data-status="en_attente"><i class="bi bi-clock text-warning me-2"></i>En attente</a></li>
                                        <li><a class="dropdown-item change-facture-status <?= $f['statut'] === 'approuvee' ? 'active' : '' ?>" href="#" data-facture-id="<?= $f['id'] ?>" data-status="approuvee"><i class="bi bi-check-circle text-success me-2"></i>Approuver</a></li>
                                        <li><a class="dropdown-item change-facture-status <?= $f['statut'] === 'rejetee' ? 'active' : '' ?>" href="#" data-facture-id="<?= $f['id'] ?>" data-status="rejetee"><i class="bi bi-x-circle text-danger me-2"></i>Rejeter</a></li>
                                    </ul>
                                </div>
                            </td>
                            <td>
                                <a href="<?= url('/admin/factures/liste.php?toggle_paiement=1&id=' . $f['id']) ?>"
                                   class="badge <?= !empty($f['est_payee']) ? 'bg-success' : 'bg-primary' ?> text-white"
                                   style="cursor:pointer; text-decoration:none;"
                                   title="Cliquer pour changer le statut"
                                   onclick="event.preventDefault(); togglePaiementFacture(<?= $f['id'] ?>, this);">
                                    <?php if (!empty($f['est_payee'])): ?>
                                        <i class="bi bi-check-circle me-1"></i>Payé
                                    <?php else: ?>
                                        <i class="bi bi-clock me-1"></i>Non payé
                                    <?php endif; ?>
                                </a>
                            </td>
                            <td>
                                <a href="<?= url('/admin/factures/modifier.php?id=' . $f['id']) ?>" class="btn btn-sm btn-outline-primary" title="Modifier">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <form action="<?= url('/admin/factures/supprimer.php') ?>" method="POST" class="d-inline" onsubmit="return confirm('Supprimer cette facture?')">
                                    <?php csrfField(); ?>
                                    <input type="hidden" name="facture_id" value="<?= $f['id'] ?>">
                                    <input type="hidden" name="redirect" value="/admin/projets/detail.php?id=<?= $projetId ?>&tab=factures">
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
    </div><!-- Fin TAB FACTURES -->

<?php if (isAdmin()): ?>
<!-- Modal Multi-Factures -->
<div class="modal fade" id="modalMultiFactures" tabindex="-1" aria-labelledby="modalMultiFacturesLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content bg-dark">
            <div class="modal-header border-secondary">
                <h5 class="modal-title" id="modalMultiFacturesLabel">
                    <i class="bi bi-files me-2"></i>Ajouter plusieurs factures
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <!-- Drop Zone avec animation porte de garage -->
                <div id="multiDropZoneWrapper" style="max-height: 300px; overflow: hidden; transition: max-height 1.5s ease-in-out, margin-bottom 1.5s ease-in-out, opacity 1s ease-in-out; margin-bottom: 1rem; opacity: 1;">
                    <div id="multiDropZone" class="border border-2 border-dashed rounded p-5 text-center"
                         style="border-color: #6c757d !important; transition: border-color 0.3s, background 0.3s; cursor: pointer;">
                        <i class="bi bi-cloud-arrow-up display-3 text-muted mb-3 d-block"></i>
                        <p class="mb-1">Glissez-déposez vos factures ici</p>
                        <p class="text-muted small mb-3">ou cliquez pour sélectionner</p>
                        <p class="text-muted small mb-0">Formats acceptés: PDF, JPG, PNG (max 5 MB chacun)</p>
                        <input type="file" id="multiFileInput" multiple accept=".pdf,.jpg,.jpeg,.png" class="d-none">
                    </div>
                </div>

                <!-- Liste des fichiers -->
                <div id="multiFilesList" class="mb-3" style="display: none;">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h6 class="mb-0"><i class="bi bi-list-ul me-2"></i>Fichiers à traiter</h6>
                        <div class="d-flex align-items-center gap-3">
                            <!-- Options de statut -->
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" id="multiFactureApprouvee">
                                <label class="form-check-label small" for="multiFactureApprouvee">
                                    <i class="bi bi-check-circle text-success"></i> Approuvée
                                </label>
                            </div>
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" id="multiFacturePayee">
                                <label class="form-check-label small" for="multiFacturePayee">
                                    <i class="bi bi-cash-stack text-primary"></i> Payée
                                </label>
                            </div>
                            <button type="button" class="btn btn-outline-danger btn-sm" id="clearFilesBtn">
                                <i class="bi bi-trash me-1"></i>Tout effacer
                            </button>
                        </div>
                    </div>
                    <div id="filesContainer" class="border rounded" style="max-height: 300px; overflow-y: auto;"></div>
                </div>

                <!-- Progress global -->
                <div id="multiProgressSection" style="display: none;">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span id="progressText">Traitement en cours...</span>
                        <span id="progressCount">0/0</span>
                    </div>
                    <div class="progress" style="height: 8px;">
                        <div class="progress-bar progress-bar-striped progress-bar-animated" id="multiProgressBar" style="width: 0%"></div>
                    </div>
                </div>

                <!-- Résumé final -->
                <div id="multiResultSection" style="display: none;">
                    <div class="alert alert-success mb-2" id="resultSuccess" style="display: none;">
                        <i class="bi bi-check-circle me-2"></i><span id="successCount">0</span> facture(s) créée(s) avec succès
                    </div>
                    <div class="alert alert-danger mb-2" id="resultErrors" style="display: none;">
                        <i class="bi bi-exclamation-triangle me-2"></i><span id="errorCount">0</span> erreur(s)
                    </div>
                </div>
            </div>
            <div class="modal-footer border-secondary">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fermer</button>
                <button type="button" class="btn btn-success" id="startProcessingBtn" disabled>
                    <i class="bi bi-play-fill me-1"></i>Démarrer le traitement
                </button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
// Clic sur ligne pour ouvrir la facture
document.querySelectorAll('#facturesTable .facture-row[data-href]').forEach(row => {
    row.addEventListener('click', function(e) {
        // Ne pas naviguer si on clique sur un bouton, lien, dropdown ou formulaire
        if (e.target.closest('button, a, .dropdown, form, input')) return;
        window.location.href = this.dataset.href;
    });
});

// Sélection multiple de factures
(function() {
    const selectAll = document.getElementById('selectAllFactures');
    const bulkMenu = document.getElementById('bulkActionsMenu');
    const selectedCountBadge = document.getElementById('selectedCount');
    const checkboxes = document.querySelectorAll('.facture-checkbox');

    if (!selectAll || !bulkMenu) return;

    function updateBulkMenu() {
        const checked = document.querySelectorAll('.facture-checkbox:checked');
        const count = checked.length;

        if (count > 0) {
            bulkMenu.classList.remove('d-none');
            selectedCountBadge.textContent = count + ' sélectionnée(s)';
        } else {
            bulkMenu.classList.add('d-none');
        }

        // Mettre à jour le checkbox "tout sélectionner"
        const visibleCheckboxes = document.querySelectorAll('.facture-row:not([style*="display: none"]) .facture-checkbox');
        const visibleChecked = document.querySelectorAll('.facture-row:not([style*="display: none"]) .facture-checkbox:checked');
        selectAll.checked = visibleCheckboxes.length > 0 && visibleCheckboxes.length === visibleChecked.length;
        selectAll.indeterminate = visibleChecked.length > 0 && visibleChecked.length < visibleCheckboxes.length;
    }

    // Tout sélectionner / désélectionner
    selectAll.addEventListener('change', function() {
        const visibleCheckboxes = document.querySelectorAll('.facture-row:not([style*="display: none"]) .facture-checkbox');
        visibleCheckboxes.forEach(cb => cb.checked = this.checked);
        updateBulkMenu();
        updateToggleButton();
    });

    // Checkbox individuel
    checkboxes.forEach(cb => {
        cb.addEventListener('change', function() {
            updateBulkMenu();
            updateToggleButton();
        });
    });

    // Désélectionner tout
    window.deselectAllFactures = function() {
        checkboxes.forEach(cb => cb.checked = false);
        selectAll.checked = false;
        updateBulkMenu();
        updateToggleButton();
    };

    // Toggle tout sélectionner / désélectionner
    window.toggleSelectAllFactures = function() {
        const visibleCheckboxes = document.querySelectorAll('.facture-row:not([style*="display: none"]) .facture-checkbox');
        const visibleChecked = document.querySelectorAll('.facture-row:not([style*="display: none"]) .facture-checkbox:checked');
        const allSelected = visibleCheckboxes.length > 0 && visibleCheckboxes.length === visibleChecked.length;

        // Si tout est sélectionné, on désélectionne. Sinon, on sélectionne tout
        visibleCheckboxes.forEach(cb => cb.checked = !allSelected);
        selectAll.checked = !allSelected;
        updateBulkMenu();
        updateToggleButton();
    };

    // Mettre à jour le bouton toggle
    function updateToggleButton() {
        const toggleBtn = document.getElementById('toggleSelectAllBtn');
        const toggleIcon = document.getElementById('toggleSelectAllIcon');
        if (!toggleBtn || !toggleIcon) return;

        const visibleCheckboxes = document.querySelectorAll('.facture-row:not([style*="display: none"]) .facture-checkbox');
        const visibleChecked = document.querySelectorAll('.facture-row:not([style*="display: none"]) .facture-checkbox:checked');
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
    document.querySelectorAll('.bulk-action').forEach(btn => {
        btn.addEventListener('click', async function(e) {
            e.preventDefault();
            const action = this.dataset.action;
            const checkedBoxes = document.querySelectorAll('.facture-checkbox:checked');
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
            if (!confirm(`${label.toUpperCase()} ${ids.length} facture(s) ?`)) {
                return;
            }

            // Exécuter l'action
            try {
                const response = await fetch('<?= url('/api/factures/bulk-action.php') ?>', {
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
                    console.log('IDs envoyés:', ids);
                    console.log('IDs reçus par API:', result.ids_received);
                    console.log('Résultat complet:', result);
                    alert(`Action: ${action}\nIDs envoyés: ${ids.length}\nModifiées: ${result.affected}\nVoir console (F12) pour détails`);
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

// Multi-factures upload (Admin only)
<?php if (isAdmin()): ?>
(function() {
    const projetId = <?= (int)$projetId ?>;
    const dropZone = document.getElementById('multiDropZone');
    const dropZoneWrapper = document.getElementById('multiDropZoneWrapper');
    const fileInput = document.getElementById('multiFileInput');
    const filesList = document.getElementById('multiFilesList');
    const filesContainer = document.getElementById('filesContainer');
    const clearFilesBtn = document.getElementById('clearFilesBtn');
    const startBtn = document.getElementById('startProcessingBtn');
    const progressSection = document.getElementById('multiProgressSection');
    const progressBar = document.getElementById('multiProgressBar');
    const progressText = document.getElementById('progressText');
    const progressCount = document.getElementById('progressCount');
    const resultSection = document.getElementById('multiResultSection');
    const resultSuccess = document.getElementById('resultSuccess');
    const resultErrors = document.getElementById('resultErrors');
    const successCount = document.getElementById('successCount');
    const errorCount = document.getElementById('errorCount');
    const checkApprouvee = document.getElementById('multiFactureApprouvee');
    const checkPayee = document.getElementById('multiFacturePayee');

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
                    statusBadge = '<span class="badge bg-success"><i class="bi bi-check me-1"></i>Créée</span>';
                    // Afficher les données analysées par l'IA
                    if (item.analyseData) {
                        const data = item.analyseData;
                        const nbLignes = data.lignes?.length || 0;
                        const etapes = data.totaux_par_etape?.map(e => e.etape_nom).join(', ') || '-';
                        aiDetails = `
                            <div class="mt-2 p-2 rounded" style="background: rgba(25,135,84,0.1); font-size: 0.8rem;">
                                <div class="row g-2">
                                    <div class="col-6">
                                        <strong class="text-success"><i class="bi bi-shop me-1"></i>Fournisseur:</strong><br>
                                        ${data.fournisseur || 'N/A'}
                                    </div>
                                    <div class="col-6">
                                        <strong class="text-success"><i class="bi bi-calendar me-1"></i>Date:</strong><br>
                                        ${data.date_facture || 'N/A'}
                                    </div>
                                    <div class="col-4">
                                        <strong>Sous-total:</strong><br>
                                        ${formatMoney(data.sous_total || 0)}
                                    </div>
                                    <div class="col-4">
                                        <strong>TPS/TVQ:</strong><br>
                                        ${formatMoney((data.tps || 0) + (data.tvq || 0))}
                                    </div>
                                    <div class="col-4">
                                        <strong class="text-primary">Total:</strong><br>
                                        <span class="text-primary fw-bold">${formatMoney(data.total || 0)}</span>
                                    </div>
                                    <div class="col-12">
                                        <strong><i class="bi bi-list-ul me-1"></i>${nbLignes} article(s)</strong>
                                        ${nbLignes > 0 ? `<span class="text-muted ms-2">→ ${etapes}</span>` : ''}
                                    </div>
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
                ? `<button type="button" class="btn btn-sm btn-outline-danger ms-2" onclick="removeMultiFile(${index})"><i class="bi bi-x"></i></button>`
                : '';

            const errorMsg = item.status === 'error' ? `<div class="text-danger small mt-1"><i class="bi bi-exclamation-triangle me-1"></i>${item.error}</div>` : '';

            // Ajouter un attribut data-index pour l'auto-scroll
            const isCurrentItem = item.status === 'processing' ||
                                  (item.status === 'success' && filesToProcess.slice(index + 1).every(f => f.status === 'pending'));

            return `
                <div class="p-2 border-bottom multi-file-item" data-index="${index}" style="background: ${item.status === 'processing' ? 'rgba(255,193,7,0.1)' : item.status === 'error' ? 'rgba(220,53,69,0.1)' : item.status === 'success' ? 'rgba(25,135,84,0.05)' : 'transparent'}">
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

        // Auto-scroll vers le fichier en cours de traitement ou le dernier traité
        setTimeout(() => {
            // Trouver l'index du fichier en cours de traitement ou le dernier traité
            let targetIndex = -1;

            for (let i = 0; i < filesToProcess.length; i++) {
                if (filesToProcess[i].status === 'processing') {
                    targetIndex = i;
                    break;
                }
                if (filesToProcess[i].status === 'success' || filesToProcess[i].status === 'error') {
                    targetIndex = i; // Garde le dernier traité
                }
            }

            if (targetIndex >= 0) {
                const targetItem = filesContainer.querySelector(`.multi-file-item[data-index="${targetIndex}"]`);
                if (targetItem) {
                    targetItem.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                }
            }
        }, 50);
    }

    window.removeMultiFile = function(index) {
        filesToProcess.splice(index, 1);
        updateFilesList();
    };

    // Convertir fichier en base64 (comme le formulaire simple)
    function fileToBase64(file) {
        return new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.onload = () => resolve(reader.result);
            reader.onerror = reject;
            reader.readAsDataURL(file);
        });
    }

    // Traiter un fichier - MÊME FLUX QUE LE FORMULAIRE SIMPLE
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
            // Image: lire directement en base64 (comme le formulaire simple)
            imageBase64 = await fileToBase64(item.file);
        }

        // Étape 2: Analyser avec l'IA - EXACTEMENT COMME LE FORMULAIRE SIMPLE
        const analyseResponse = await fetch('<?= url('/api/analyse-facture-details.php') ?>', {
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

        // Étape 3: Créer la facture avec les données analysées
        const createResponse = await fetch('<?= url('/api/factures/creer-depuis-analyse.php') ?>', {
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
            throw new Error(createResult.error || 'Erreur création facture');
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
                // Utiliser le même flux que le formulaire simple
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

        // Garder la dropzone cachée, proposer de recharger la page
        startBtn.innerHTML = '<i class="bi bi-arrow-clockwise me-1"></i>Recharger la page';
        startBtn.disabled = false;
        startBtn.onclick = () => window.location.reload();
    }

    function formatMoney(amount) {
        return new Intl.NumberFormat('fr-CA', { style: 'currency', currency: 'CAD' }).format(amount);
    }

    function showToast(message, type = 'info') {
        // Simple alert fallback
        console.log(`[${type}] ${message}`);
    }

    // Reset modal on close
    document.getElementById('modalMultiFactures').addEventListener('hidden.bs.modal', () => {
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
