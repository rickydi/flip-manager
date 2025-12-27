    <div class="tab-pane fade <?= $tab === 'checklist' ? 'show active' : '' ?>" id="checklist" role="tabpanel">
        <?php
        // Auto-créer les tables si nécessaire
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS checklist_templates (id INT AUTO_INCREMENT PRIMARY KEY, nom VARCHAR(255) NOT NULL, description TEXT, ordre INT DEFAULT 0, actif TINYINT(1) DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $pdo->exec("CREATE TABLE IF NOT EXISTS checklist_template_items (id INT AUTO_INCREMENT PRIMARY KEY, template_id INT NOT NULL, nom VARCHAR(255) NOT NULL, description TEXT, ordre INT DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $pdo->exec("CREATE TABLE IF NOT EXISTS projet_checklists (id INT AUTO_INCREMENT PRIMARY KEY, projet_id INT NOT NULL, template_item_id INT NOT NULL, complete TINYINT(1) DEFAULT 0, complete_date DATETIME NULL, complete_by VARCHAR(100) NULL, notes TEXT, UNIQUE KEY unique_projet_item (projet_id, template_item_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $pdo->exec("CREATE TABLE IF NOT EXISTS projet_documents (id INT AUTO_INCREMENT PRIMARY KEY, projet_id INT NOT NULL, nom VARCHAR(255) NOT NULL, fichier VARCHAR(500) NOT NULL, type VARCHAR(100), taille INT, uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Exception $e) {}

        // Récupérer les templates actifs avec leurs items
        $checklistTemplates = [];
        try {
            $stmt = $pdo->query("SELECT * FROM checklist_templates WHERE actif = 1 ORDER BY ordre, nom");
            $checklistTemplates = $stmt->fetchAll();
            foreach ($checklistTemplates as &$tpl) {
                $stmt = $pdo->prepare("SELECT * FROM checklist_template_items WHERE template_id = ? ORDER BY ordre, nom");
                $stmt->execute([$tpl['id']]);
                $tpl['items'] = $stmt->fetchAll();
            }
            unset($tpl);
        } catch (Exception $e) {}

        // Récupérer l'état des checklists pour ce projet
        $projetChecklists = [];
        try {
            $stmt = $pdo->prepare("SELECT * FROM projet_checklists WHERE projet_id = ?");
            $stmt->execute([$projetId]);
            foreach ($stmt->fetchAll() as $pc) {
                $projetChecklists[$pc['template_item_id']] = $pc;
            }
        } catch (Exception $e) {}

        // Récupérer les documents du projet
        $projetDocuments = [];
        try {
            $stmt = $pdo->prepare("SELECT * FROM projet_documents WHERE projet_id = ? ORDER BY uploaded_at DESC");
            $stmt->execute([$projetId]);
            $projetDocuments = $stmt->fetchAll();
        } catch (Exception $e) {}

        // Récupérer les Google Sheets du projet
        $googleSheets = [];
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS projet_google_sheets (id INT AUTO_INCREMENT PRIMARY KEY, projet_id INT NOT NULL, nom VARCHAR(255) NOT NULL, url VARCHAR(500) NOT NULL, ordre INT DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $stmt = $pdo->prepare("SELECT * FROM projet_google_sheets WHERE projet_id = ? ORDER BY ordre, created_at");
            $stmt->execute([$projetId]);
            $googleSheets = $stmt->fetchAll();
        } catch (Exception $e) {}
        ?>

        <style>
            /* Tooltips plus grands */
            .tooltip {
                font-size: 1rem !important;
            }
            .tooltip-inner {
                max-width: 350px !important;
                padding: 10px 15px !important;
                font-size: 1rem !important;
                line-height: 1.5 !important;
            }

            /* Animation pulse pour checkbox complétée */
            @keyframes checkPulse {
                0%, 100% {
                    box-shadow: 0 0 0 0 rgba(25, 135, 84, 0.7);
                }
                50% {
                    box-shadow: 0 0 0 6px rgba(25, 135, 84, 0);
                }
            }
            .checklist-item:checked {
                background-color: #198754 !important;
                border-color: #198754 !important;
                animation: checkPulse 2s ease-in-out infinite;
            }
            .checklist-item:checked::after {
                content: '';
                position: absolute;
            }
        </style>

        <div class="row">
            <!-- Checklists -->
            <div class="col-12">
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-list-check me-2"></i>Checklists</span>
                        <a href="<?= url('/admin/checklists/liste.php') ?>" class="btn btn-sm btn-outline-secondary" title="Gérer les templates">
                            <i class="bi bi-gear"></i>
                        </a>
                    </div>
                    <div class="card-body">
                        <?php if (empty($checklistTemplates)): ?>
                            <div class="text-center text-muted py-4">
                                <i class="bi bi-list-check" style="font-size: 2rem;"></i>
                                <p class="mb-0">Aucune checklist configurée.</p>
                                <a href="<?= url('/admin/checklists/liste.php') ?>" class="btn btn-primary btn-sm mt-2">Créer des checklists</a>
                            </div>
                        <?php else: ?>
                            <div class="accordion" id="checklistAccordion">
                                <?php foreach ($checklistTemplates as $idx => $tpl): ?>
                                    <?php
                                    $totalItems = count($tpl['items']);
                                    $completedItems = 0;
                                    foreach ($tpl['items'] as $item) {
                                        if (!empty($projetChecklists[$item['id']]['complete'])) {
                                            $completedItems++;
                                        }
                                    }
                                    $pctComplete = $totalItems > 0 ? round(($completedItems / $totalItems) * 100) : 0;
                                    ?>
                                    <div class="accordion-item">
                                        <h2 class="accordion-header">
                                            <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#checklist<?= $tpl['id'] ?>">
                                                <span class="me-auto"><?= e($tpl['nom']) ?></span>
                                                <span class="badge <?= $pctComplete == 100 ? 'bg-success' : 'bg-secondary' ?> me-2"><?= $completedItems ?>/<?= $totalItems ?></span>
                                            </button>
                                        </h2>
                                        <div id="checklist<?= $tpl['id'] ?>" class="accordion-collapse collapse show">
                                            <div class="accordion-body p-0">
                                                <?php if (empty($tpl['items'])): ?>
                                                    <p class="text-muted small p-3 mb-0">Aucun item dans cette checklist.</p>
                                                <?php else: ?>
                                                    <ul class="list-group list-group-flush">
                                                        <?php foreach ($tpl['items'] as $item): ?>
                                                            <?php
                                                            $isComplete = !empty($projetChecklists[$item['id']]['complete']);
                                                            $completeDate = $projetChecklists[$item['id']]['complete_date'] ?? null;
                                                            $itemNotes = $projetChecklists[$item['id']]['notes'] ?? '';
                                                            ?>
                                                            <li class="list-group-item d-flex align-items-center <?= $isComplete ? 'bg-success bg-opacity-10' : '' ?>">
                                                                <div class="form-check flex-grow-1">
                                                                    <input class="form-check-input checklist-item" type="checkbox"
                                                                           id="item<?= $item['id'] ?>"
                                                                           data-item-id="<?= $item['id'] ?>"
                                                                           <?= $isComplete ? 'checked' : '' ?>>
                                                                    <label class="form-check-label <?= $isComplete ? 'text-success fw-semibold' : '' ?>" for="item<?= $item['id'] ?>">
                                                                        <?= $isComplete ? '<i class="bi bi-check-lg me-1"></i>' : '' ?><?= e($item['nom']) ?>
                                                                    </label>
                                                                    <?php if ($itemNotes): ?>
                                                                        <i class="bi bi-info-circle text-info ms-2"
                                                                           data-bs-toggle="tooltip"
                                                                           data-bs-placement="top"
                                                                           title="<?= e($itemNotes) ?>"></i>
                                                                    <?php endif; ?>
                                                                </div>
                                                                <button type="button" class="btn btn-sm btn-link text-secondary p-0 me-2 edit-note-btn"
                                                                        data-bs-toggle="modal"
                                                                        data-bs-target="#editNoteModal"
                                                                        data-item-id="<?= $item['id'] ?>"
                                                                        data-item-nom="<?= e($item['nom']) ?>"
                                                                        data-notes="<?= e($itemNotes) ?>"
                                                                        title="Ajouter/modifier une note">
                                                                    <i class="bi bi-pencil-square"></i>
                                                                </button>
                                                                <button type="button" class="btn btn-sm btn-link text-danger p-0 me-2 delete-checklist-btn"
                                                                        data-item-id="<?= $item['id'] ?>"
                                                                        data-item-nom="<?= e($item['nom']) ?>"
                                                                        title="Réinitialiser cet item">
                                                                    <i class="bi bi-x-circle"></i>
                                                                </button>
                                                                <?php if ($isComplete && $completeDate): ?>
                                                                    <small class="text-success"><?= date('d/m/Y', strtotime($completeDate)) ?></small>
                                                                <?php endif; ?>
                                                            </li>
                                                        <?php endforeach; ?>
                                                    </ul>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <script>
        // Toggle checklist items
        document.querySelectorAll('.checklist-item').forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                const itemId = this.dataset.itemId;
                const isComplete = this.checked;
                const label = this.nextElementSibling;
                const listItem = this.closest('.list-group-item');

                fetch('<?= url('/admin/projets/detail.php?id=' . $projetId) ?>', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: `ajax_action=toggle_checklist&item_id=${itemId}&complete=${isComplete ? 1 : 0}&csrf_token=<?= generateCSRFToken() ?>`
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        // Toggle green styling
                        label.classList.toggle('text-success', isComplete);
                        label.classList.toggle('fw-semibold', isComplete);
                        listItem.classList.toggle('bg-success', isComplete);
                        listItem.classList.toggle('bg-opacity-10', isComplete);

                        // Add/remove checkmark icon
                        if (isComplete) {
                            if (!label.querySelector('.bi-check-lg')) {
                                label.insertAdjacentHTML('afterbegin', '<i class="bi bi-check-lg me-1"></i>');
                            }
                        } else {
                            const icon = label.querySelector('.bi-check-lg');
                            if (icon) icon.remove();
                        }

                        // Update badge count
                        const accordion = checkbox.closest('.accordion-item');
                        if (accordion) {
                            const badge = accordion.querySelector('.badge');
                            const checkboxes = accordion.querySelectorAll('.checklist-item');
                            const checked = accordion.querySelectorAll('.checklist-item:checked').length;
                            badge.textContent = `${checked}/${checkboxes.length}`;
                            badge.className = `badge ${checked === checkboxes.length ? 'bg-success' : 'bg-secondary'} me-2`;
                        }
                    }
                });
            });
        });

        </script>
    </div><!-- Fin TAB CHECKLIST -->
