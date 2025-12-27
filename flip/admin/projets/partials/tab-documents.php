    <div class="tab-pane fade <?= $tab === 'documents' ? 'show active' : '' ?>" id="documents" role="tabpanel">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-folder me-2"></i>Documents du projet</span>
                <span class="badge bg-secondary"><?= count($projetDocuments) ?> document(s)</span>
            </div>
            <div class="card-body">
                <!-- Upload form -->
                <form id="documentUploadForm" enctype="multipart/form-data" class="mb-4">
                    <?php csrfField(); ?>
                    <input type="hidden" name="projet_id" value="<?= $projetId ?>">
                    <label class="form-label">Ajouter des documents</label>
                    <div class="input-group">
                        <input type="file" class="form-control" name="documents[]" id="documentFiles" multiple required>
                        <button type="submit" class="btn btn-primary" id="uploadBtn">
                            <i class="bi bi-upload me-1"></i>Uploader
                        </button>
                    </div>
                    <small class="text-muted">PDF, Word, Excel, Images (max 10 Mo par fichier) - Sélection multiple possible</small>
                    <div id="selectedFiles" class="mt-2 small text-muted"></div>
                </form>

                <!-- Documents list -->
                <?php if (empty($projetDocuments)): ?>
                    <div class="text-center text-muted py-5" id="emptyState">
                        <i class="bi bi-folder" style="font-size: 3rem;"></i>
                        <p class="mb-0 mt-2">Aucun document pour ce projet</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-dark table-hover">
                            <thead>
                                <tr>
                                    <th>Nom</th>
                                    <th>Date</th>
                                    <th>Taille</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="documentsList">
                                <?php foreach ($projetDocuments as $doc): ?>
                                    <tr data-doc-id="<?= $doc['id'] ?>">
                                        <td class="doc-name-cell">
                                            <i class="bi bi-file-earmark me-2"></i>
                                            <span class="doc-name-display">
                                                <a href="<?= url('/uploads/documents/' . $doc['fichier']) ?>" target="_blank" class="text-info doc-link"><?= e($doc['nom']) ?></a>
                                                <button type="button" class="btn btn-sm btn-link text-warning p-0 ms-2 rename-doc-btn" title="Renommer">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                            </span>
                                            <span class="doc-name-edit d-none">
                                                <input type="text" class="form-control form-control-sm d-inline-block bg-dark text-white" style="width: 250px;" value="<?= e($doc['nom']) ?>">
                                                <button type="button" class="btn btn-sm btn-success ms-1 save-rename-btn"><i class="bi bi-check"></i></button>
                                                <button type="button" class="btn btn-sm btn-secondary cancel-rename-btn"><i class="bi bi-x"></i></button>
                                            </span>
                                        </td>
                                        <td><?= date('d/m/Y H:i', strtotime($doc['uploaded_at'])) ?></td>
                                        <td><?= round($doc['taille'] / 1024) ?> Ko</td>
                                        <td class="text-end">
                                            <a href="<?= url('/uploads/documents/' . $doc['fichier']) ?>" download class="btn btn-sm btn-outline-primary me-1" title="Télécharger">
                                                <i class="bi bi-download"></i>
                                            </a>
                                            <button type="button" class="btn btn-sm btn-outline-danger delete-document" data-doc-id="<?= $doc['id'] ?>" title="Supprimer">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <script>
        // Show selected files count
        document.getElementById('documentFiles')?.addEventListener('change', function() {
            const count = this.files.length;
            const filesDiv = document.getElementById('selectedFiles');
            if (count > 0) {
                const names = Array.from(this.files).map(f => f.name).join(', ');
                filesDiv.innerHTML = `<i class="bi bi-check-circle text-success me-1"></i>${count} fichier(s) sélectionné(s): ${names}`;
            } else {
                filesDiv.innerHTML = '';
            }
        });

        // Document upload (multiple)
        document.getElementById('documentUploadForm')?.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            formData.append('ajax_action', 'upload_document');

            const btn = document.getElementById('uploadBtn');
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Upload...';

            fetch('<?= url('/admin/projets/detail.php?id=' . $projetId) ?>', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    window.location.href = '<?= url('/admin/projets/detail.php?id=' . $projetId . '&tab=documents') ?>';
                } else {
                    alert(data.errors?.join('\n') || data.error || 'Erreur lors de l\'upload');
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-upload me-1"></i>Uploader';
                }
            });
        });

        // Rename document
        document.querySelectorAll('.rename-doc-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const cell = this.closest('.doc-name-cell');
                cell.querySelector('.doc-name-display').classList.add('d-none');
                cell.querySelector('.doc-name-edit').classList.remove('d-none');
                cell.querySelector('.doc-name-edit input').focus();
            });
        });

        document.querySelectorAll('.cancel-rename-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const cell = this.closest('.doc-name-cell');
                cell.querySelector('.doc-name-display').classList.remove('d-none');
                cell.querySelector('.doc-name-edit').classList.add('d-none');
            });
        });

        document.querySelectorAll('.save-rename-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const row = this.closest('tr');
                const docId = row.dataset.docId;
                const cell = this.closest('.doc-name-cell');
                const input = cell.querySelector('.doc-name-edit input');
                const newName = input.value.trim();

                if (!newName) {
                    alert('Le nom ne peut pas être vide');
                    return;
                }

                fetch('<?= url('/admin/projets/detail.php?id=' . $projetId) ?>', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: `ajax_action=rename_document&doc_id=${docId}&new_name=${encodeURIComponent(newName)}&csrf_token=<?= generateCSRFToken() ?>`
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        cell.querySelector('.doc-link').textContent = newName;
                        cell.querySelector('.doc-name-display').classList.remove('d-none');
                        cell.querySelector('.doc-name-edit').classList.add('d-none');
                    } else {
                        alert(data.error || 'Erreur');
                    }
                });
            });
        });

        // Enter key to save rename
        document.querySelectorAll('.doc-name-edit input').forEach(input => {
            input.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    this.closest('.doc-name-edit').querySelector('.save-rename-btn').click();
                } else if (e.key === 'Escape') {
                    this.closest('.doc-name-edit').querySelector('.cancel-rename-btn').click();
                }
            });
        });

        // Delete document
        document.querySelectorAll('.delete-document').forEach(btn => {
            btn.addEventListener('click', function() {
                if (!confirm('Supprimer ce document ?')) return;
                const docId = this.dataset.docId;

                fetch('<?= url('/admin/projets/detail.php?id=' . $projetId) ?>', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: `ajax_action=delete_document&doc_id=${docId}&csrf_token=<?= generateCSRFToken() ?>`
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        this.closest('tr').remove();
                    }
                });
            });
        });
        </script>
    </div><!-- Fin TAB DOCUMENTS -->
