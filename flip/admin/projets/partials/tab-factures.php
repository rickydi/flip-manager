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
                        <tr class="facture-row" data-statut="<?= e($f['statut']) ?>" data-categorie="<?= e($f['etape_nom'] ?? '') ?>" data-fournisseur="<?= e($f['fournisseur'] ?? '') ?>" data-montant="<?= $f['montant_total'] ?>" data-href="<?= url('/admin/factures/modifier.php?id=' . $f['id']) ?>" style="cursor: pointer;">
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
                <!-- Drop Zone -->
                <div id="multiDropZone" class="border border-2 border-dashed rounded p-5 text-center mb-3"
                     style="border-color: #6c757d !important; transition: all 0.3s; cursor: pointer;">
                    <i class="bi bi-cloud-arrow-up display-3 text-muted mb-3 d-block"></i>
                    <p class="mb-1">Glissez-déposez vos factures ici</p>
                    <p class="text-muted small mb-3">ou cliquez pour sélectionner</p>
                    <p class="text-muted small mb-0">Formats acceptés: PDF, JPG, PNG (max 5 MB chacun)</p>
                    <input type="file" id="multiFileInput" multiple accept=".pdf,.jpg,.jpeg,.png" class="d-none">
                </div>

                <!-- Liste des fichiers -->
                <div id="multiFilesList" class="mb-3" style="display: none;">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h6 class="mb-0"><i class="bi bi-list-ul me-2"></i>Fichiers à traiter</h6>
                        <button type="button" class="btn btn-outline-danger btn-sm" id="clearFilesBtn">
                            <i class="bi bi-trash me-1"></i>Tout effacer
                        </button>
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

// Multi-factures upload (Admin only)
<?php if (isAdmin()): ?>
(function() {
    const projetId = <?= (int)$projetId ?>;
    const dropZone = document.getElementById('multiDropZone');
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
            switch(item.status) {
                case 'pending':
                    statusBadge = '<span class="badge bg-secondary">En attente</span>';
                    break;
                case 'processing':
                    statusBadge = '<span class="badge bg-warning text-dark"><span class="spinner-border spinner-border-sm me-1"></span>Analyse...</span>';
                    break;
                case 'success':
                    statusBadge = `<span class="badge bg-success"><i class="bi bi-check me-1"></i>${item.result?.data?.fournisseur || 'OK'}</span>`;
                    break;
                case 'error':
                    statusBadge = `<span class="badge bg-danger" title="${item.error}"><i class="bi bi-x me-1"></i>Erreur</span>`;
                    break;
            }

            const removeBtn = item.status === 'pending' && !isProcessing
                ? `<button type="button" class="btn btn-sm btn-outline-danger ms-2" onclick="removeMultiFile(${index})"><i class="bi bi-x"></i></button>`
                : '';

            return `
                <div class="d-flex align-items-center p-2 border-bottom" style="background: ${item.status === 'processing' ? 'rgba(255,193,7,0.1)' : 'transparent'}">
                    <i class="bi ${icon} me-2"></i>
                    <div class="flex-grow-1">
                        <div class="text-truncate" style="max-width: 300px;" title="${item.file.name}">${item.file.name}</div>
                        <small class="text-muted">${size}</small>
                        ${item.result?.data?.montant_total ? `<small class="text-success ms-2">${formatMoney(item.result.data.montant_total)}</small>` : ''}
                    </div>
                    <div class="ms-auto d-flex align-items-center">
                        ${statusBadge}
                        ${removeBtn}
                    </div>
                </div>
            `;
        }).join('');
    }

    window.removeMultiFile = function(index) {
        filesToProcess.splice(index, 1);
        updateFilesList();
    };

    async function processQueue() {
        isProcessing = true;
        startBtn.disabled = true;
        dropZone.style.opacity = '0.5';
        dropZone.style.pointerEvents = 'none';

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
                const formData = new FormData();
                formData.append('fichier', item.file);
                formData.append('projet_id', projetId);

                const response = await fetch('/api/factures/upload-auto.php', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();

                if (result.success) {
                    item.status = 'success';
                    item.result = result;
                    successTotal++;
                } else {
                    item.status = 'error';
                    item.error = result.error || 'Erreur inconnue';
                    errorTotal++;
                }
            } catch (err) {
                item.status = 'error';
                item.error = err.message || 'Erreur réseau';
                errorTotal++;
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

        dropZone.style.opacity = '1';
        dropZone.style.pointerEvents = 'auto';
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
            startBtn.innerHTML = '<i class="bi bi-play-fill me-1"></i>Démarrer le traitement';
            startBtn.onclick = () => { if (filesToProcess.length > 0 && !isProcessing) processQueue(); };
        }
    });
})();
<?php endif; ?>
</script>
