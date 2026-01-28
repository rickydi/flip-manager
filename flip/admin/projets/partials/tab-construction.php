    <div class="tab-pane fade <?= $tab === 'construction' ? 'show active' : '' ?>" id="construction" role="tabpanel">
        <?php
        // Récupérer le breakdown par étape (depuis facture_lignes)
        $breakdownEtapes = [];
        $totalBreakdown = 0;
        try {
            // Grouper par etape_nom seulement (plus fiable car etape_id peut être NULL)
            $stmtBreakdown = $pdo->prepare("
                SELECT
                    COALESCE(fl.etape_nom, 'Non spécifié') as etape_nom,
                    fl.etape_id,
                    SUM(fl.total) as total_etape,
                    COUNT(*) as nb_lignes
                FROM facture_lignes fl
                JOIN factures f ON fl.facture_id = f.id
                WHERE f.projet_id = ?
                GROUP BY fl.etape_nom
                ORDER BY total_etape DESC
            ");
            $stmtBreakdown->execute([$projetId]);
            $breakdownEtapes = $stmtBreakdown->fetchAll();
            $totalBreakdown = array_sum(array_column($breakdownEtapes, 'total_etape'));
        } catch (Exception $e) {
            // Table n'existe pas encore
        }

        ?>
        <div class="card">
            <div class="card-header">
                <ul class="nav nav-tabs card-header-tabs" id="constructionSubTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="depenses-tab" data-bs-toggle="tab" data-bs-target="#depenses-content" type="button" role="tab">
                            <i class="bi bi-pie-chart me-1"></i>Dépenses
                            <?php if ($totalBreakdown > 0): ?>
                            <span class="badge bg-danger ms-1"><?= formatMoney($totalBreakdown) ?></span>
                            <?php endif; ?>
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="electrical-tab" data-bs-toggle="tab" data-bs-target="#electrical-content" type="button" role="tab">
                            <i class="bi bi-lightning-charge me-1"></i>Électrique
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="drawing-tab" data-bs-toggle="tab" data-bs-target="#drawing-content" type="button" role="tab">
                            <i class="bi bi-pencil-square me-1"></i>Plan 2D
                        </button>
                    </li>
                </ul>
            </div>
            <div class="card-body">
                <div class="tab-content" id="constructionSubTabsContent">
                    <!-- Onglet Dépenses par étape -->
                    <div class="tab-pane fade show active" id="depenses-content" role="tabpanel">
                        <?php if (empty($breakdownEtapes)): ?>
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle me-2"></i>
                                Aucune dépense analysée. Pour voir les coûts par étape:
                                <ol class="mb-0 mt-2">
                                    <li>Va sur une facture avec image</li>
                                    <li>Clique sur <i class="bi bi-list-check"></i> pour analyser</li>
                                    <li>Clique sur "Enregistrer le breakdown"</li>
                                </ol>
                            </div>
                        <?php else: ?>
                            <!-- Résumé total -->
                            <div class="row mb-4">
                                <div class="col-md-4">
                                    <div class="card bg-danger bg-opacity-10 border-danger">
                                        <div class="card-body text-center py-3">
                                            <h3 class="text-danger mb-0"><?= formatMoney($totalBreakdown) ?></h3>
                                            <small class="text-muted">Total analysé</small>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="card bg-info bg-opacity-10 border-info">
                                        <div class="card-body text-center py-3">
                                            <h3 class="text-info mb-0"><?= count($breakdownEtapes) ?></h3>
                                            <small class="text-muted">Étapes de construction</small>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="card bg-secondary bg-opacity-10 border-secondary">
                                        <div class="card-body text-center py-3">
                                            <h3 class="text-secondary mb-0"><?= array_sum(array_column($breakdownEtapes, 'nb_lignes')) ?></h3>
                                            <small class="text-muted">Articles analysés</small>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Breakdown par étape -->
                            <h5 class="mb-3"><i class="bi bi-bar-chart me-2"></i>Dépenses par étape</h5>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead class="table-dark">
                                        <tr>
                                            <th>Étape de construction</th>
                                            <th class="text-center">Articles</th>
                                            <th class="text-end">Montant</th>
                                            <th style="width: 30%;">Répartition</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($breakdownEtapes as $be):
                                            $percent = $totalBreakdown > 0 ? round(($be['total_etape'] / $totalBreakdown) * 100) : 0;
                                            // Couleur selon le pourcentage
                                            $barColor = $percent > 30 ? 'bg-danger' : ($percent > 15 ? 'bg-warning' : 'bg-info');
                                        ?>
                                        <tr>
                                            <td>
                                                <i class="bi bi-tools text-muted me-2"></i>
                                                <strong><?= e($be['etape_nom'] ?? 'Non spécifié') ?></strong>
                                            </td>
                                            <td class="text-center">
                                                <span class="badge bg-secondary"><?= $be['nb_lignes'] ?></span>
                                            </td>
                                            <td class="text-end">
                                                <strong><?= formatMoney($be['total_etape']) ?></strong>
                                            </td>
                                            <td>
                                                <div class="d-flex align-items-center gap-2">
                                                    <div class="progress flex-grow-1" style="height: 20px;">
                                                        <div class="progress-bar <?= $barColor ?>" style="width: <?= $percent ?>%">
                                                            <?= $percent ?>%
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot class="table-light">
                                        <tr>
                                            <th>TOTAL</th>
                                            <th class="text-center"><?= array_sum(array_column($breakdownEtapes, 'nb_lignes')) ?></th>
                                            <th class="text-end text-danger"><?= formatMoney($totalBreakdown) ?></th>
                                            <th></th>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                    <!-- Onglet Électrique -->
                    <div class="tab-pane fade" id="electrical-content" role="tabpanel">
                        <?php include __DIR__ . '/../../../modules/construction/electrical/component.php'; ?>
                    </div>
                    <!-- Onglet Plan 2D -->
                    <div class="tab-pane fade" id="drawing-content" role="tabpanel">
                        <?php include __DIR__ . '/../../../modules/construction/electrical/drawing.php'; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
