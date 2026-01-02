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
                            <td><?= e($f['etape_nom'] ?? 'N/A') ?></td>
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

<script>
// Clic sur ligne pour ouvrir la facture
document.querySelectorAll('#facturesTable .facture-row[data-href]').forEach(row => {
    row.addEventListener('click', function(e) {
        // Ne pas naviguer si on clique sur un bouton, lien, dropdown ou formulaire
        if (e.target.closest('button, a, .dropdown, form, input')) return;
        window.location.href = this.dataset.href;
    });
});
</script>
