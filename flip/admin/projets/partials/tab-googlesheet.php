    <div class="tab-pane fade <?= $tab === 'googlesheet' ? 'show active' : '' ?>" id="googlesheet" role="tabpanel">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-table me-2"></i>Google Sheets</span>
                <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addSheetModal">
                    <i class="bi bi-plus-lg me-1"></i>Ajouter
                </button>
            </div>
            <div class="card-body">
                <?php if (empty($googleSheets)): ?>
                    <div class="text-center text-muted py-5" id="noSheetState">
                        <i class="bi bi-table" style="font-size: 3rem;"></i>
                        <p class="mb-0 mt-2">Aucun Google Sheet configuré</p>
                        <p class="small">Cliquez sur "Ajouter" pour lier un Google Sheet</p>
                    </div>
                <?php else: ?>
                    <div class="row g-3" id="sheetsList">
                        <?php foreach ($googleSheets as $sheet): ?>
                            <?php
                            // Créer l'URL d'édition
                            $editUrl = $sheet['url'];
                            if (preg_match('/\/d\/([a-zA-Z0-9-_]+)/', $sheet['url'], $matches)) {
                                $sheetId = $matches[1];
                                $editUrl = "https://docs.google.com/spreadsheets/d/{$sheetId}/edit";
                            }
                            ?>
                            <div class="col-3 col-md-2 col-lg-1" data-sheet-id="<?= $sheet['id'] ?>">
                                <div class="sheet-card position-relative" style="border: 1px solid #3a3a3a; border-radius: 6px; overflow: hidden; transition: all 0.2s;"
                                     onmouseover="this.style.borderColor='#0d6efd'; this.style.transform='translateY(-2px)';"
                                     onmouseout="this.style.borderColor='#3a3a3a'; this.style.transform='translateY(0)';">
                                    <!-- Action buttons -->
                                    <div class="position-absolute top-0 end-0" style="z-index: 2;">
                                        <button type="button" class="btn btn-sm btn-dark p-0 px-1 edit-sheet-btn" style="font-size: 0.65rem;"
                                                data-id="<?= $sheet['id'] ?>"
                                                data-nom="<?= e($sheet['nom']) ?>"
                                                data-url="<?= e($sheet['url']) ?>"
                                                title="Modifier">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-dark p-0 px-1 delete-sheet-btn" style="font-size: 0.65rem;"
                                                data-id="<?= $sheet['id'] ?>" title="Supprimer">
                                            <i class="bi bi-trash text-danger"></i>
                                        </button>
                                    </div>
                                    <!-- Square clickable area -->
                                    <a href="<?= e($editUrl) ?>" target="_blank" class="d-block text-decoration-none">
                                        <div class="bg-success bg-opacity-10 d-flex align-items-center justify-content-center" style="aspect-ratio: 1; min-height: 60px;">
                                            <i class="bi bi-file-earmark-spreadsheet text-success" style="font-size: 1.5rem;"></i>
                                        </div>
                                        <!-- Name -->
                                        <div class="p-1 bg-dark text-center">
                                            <small class="text-white text-truncate d-block" style="font-size: 0.7rem;" title="<?= e($sheet['nom']) ?>"><?= e($sheet['nom']) ?></small>
                                        </div>
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Modal Ajouter -->
        <div class="modal fade" id="addSheetModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content bg-dark text-white">
                    <div class="modal-header border-secondary">
                        <h5 class="modal-title"><i class="bi bi-plus-lg me-2"></i>Ajouter un Google Sheet</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Nom</label>
                            <input type="text" class="form-control bg-dark text-white border-secondary" id="newSheetNom" placeholder="Ex: Budget cuisine">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Lien Google Sheet</label>
                            <input type="url" class="form-control bg-dark text-white border-secondary" id="newSheetUrl" placeholder="https://docs.google.com/spreadsheets/d/...">
                            <small class="text-muted">Assurez-vous que le sheet est partagé (en lecture ou édition)</small>
                        </div>
                    </div>
                    <div class="modal-footer border-secondary">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="button" class="btn btn-primary" id="confirmAddSheet">
                            <i class="bi bi-plus-lg me-1"></i>Ajouter
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal Modifier -->
        <div class="modal fade" id="editSheetModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content bg-dark text-white">
                    <div class="modal-header border-secondary">
                        <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Modifier le Google Sheet</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" id="editSheetId">
                        <div class="mb-3">
                            <label class="form-label">Nom</label>
                            <input type="text" class="form-control bg-dark text-white border-secondary" id="editSheetNom">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Lien Google Sheet</label>
                            <input type="url" class="form-control bg-dark text-white border-secondary" id="editSheetUrl">
                        </div>
                    </div>
                    <div class="modal-footer border-secondary">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="button" class="btn btn-primary" id="confirmEditSheet">
                            <i class="bi bi-check-lg me-1"></i>Sauvegarder
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <script>
        // Ajouter un sheet
        document.getElementById('confirmAddSheet')?.addEventListener('click', function() {
            const nom = document.getElementById('newSheetNom').value.trim();
            const url = document.getElementById('newSheetUrl').value.trim();

            if (!nom || !url) {
                alert('Veuillez remplir tous les champs');
                return;
            }

            fetch('<?= url('/admin/projets/detail.php?id=' . $projetId) ?>', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `ajax_action=add_google_sheet&nom=${encodeURIComponent(nom)}&url=${encodeURIComponent(url)}&csrf_token=<?= generateCSRFToken() ?>`
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert('Erreur: ' + (data.error || 'Erreur inconnue'));
                }
            });
        });

        // Ouvrir modal modifier
        document.querySelectorAll('.edit-sheet-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                document.getElementById('editSheetId').value = this.dataset.id;
                document.getElementById('editSheetNom').value = this.dataset.nom;
                document.getElementById('editSheetUrl').value = this.dataset.url;
                new bootstrap.Modal(document.getElementById('editSheetModal')).show();
            });
        });

        // Sauvegarder modification
        document.getElementById('confirmEditSheet')?.addEventListener('click', function() {
            const id = document.getElementById('editSheetId').value;
            const nom = document.getElementById('editSheetNom').value.trim();
            const url = document.getElementById('editSheetUrl').value.trim();

            fetch('<?= url('/admin/projets/detail.php?id=' . $projetId) ?>', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `ajax_action=edit_google_sheet&sheet_id=${id}&nom=${encodeURIComponent(nom)}&url=${encodeURIComponent(url)}&csrf_token=<?= generateCSRFToken() ?>`
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert('Erreur: ' + (data.error || 'Erreur inconnue'));
                }
            });
        });

        // Supprimer
        document.querySelectorAll('.delete-sheet-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                if (!confirm('Supprimer ce Google Sheet?')) return;
                const id = this.dataset.id;

                fetch('<?= url('/admin/projets/detail.php?id=' . $projetId) ?>', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: `ajax_action=delete_google_sheet&sheet_id=${id}&csrf_token=<?= generateCSRFToken() ?>`
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        this.closest('[data-sheet-id]').remove();
                    }
                });
            });
        });
        </script>
    </div><!-- Fin TAB GOOGLE SHEET -->
