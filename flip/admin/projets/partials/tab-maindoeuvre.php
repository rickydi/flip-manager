    <div class="tab-pane fade <?= $tab === 'maindoeuvre' ? 'show active' : '' ?>" id="maindoeuvre" role="tabpanel">
    <?php
    // Calculer la durée en jours ouvrables
    $dureeJoursTab = 0;
    $dureeSemainesTab = 0;
    $joursFermesTab = 0;
    $dateDebutTab = $projet['date_debut_travaux'] ?? $projet['date_acquisition'];
    $dateFinTab = $projet['date_fin_prevue'];

    if ($dateDebutTab && $dateFinTab) {
        $d1 = new DateTime($dateDebutTab);
        $d2 = new DateTime($dateFinTab);

        $d2Inclusive = clone $d2;
        $d2Inclusive->modify('+1 day');

        $period = new DatePeriod($d1, new DateInterval('P1D'), $d2Inclusive);

        foreach ($period as $dt) {
            $dayOfWeek = (int)$dt->format('N');
            if ($dayOfWeek >= 6) {
                $joursFermesTab++;
            } else {
                $dureeJoursTab++;
            }
        }

        $dureeJoursTab = max(1, $dureeJoursTab);
        $dureeSemainesTab = ceil($dureeJoursTab / 5);
    }

    // Calculer le total estimé
    $totalHeuresEstimeesTab = 0;
    $totalCoutEstimeTab = 0;
    foreach ($employes as $emp) {
        $heuresSemaine = $planifications[$emp['id']] ?? 0;
        $heuresJour = $heuresSemaine / 5;
        $totalHeures = $heuresJour * $dureeJoursTab;
        $cout = $totalHeures * (float)$emp['taux_horaire'];
        $totalHeuresEstimeesTab += $totalHeures;
        $totalCoutEstimeTab += $cout;
    }
    ?>

    <!-- Résumé du projet -->
    <div class="alert alert-info mb-4">
        <div class="row align-items-center">
            <div class="col-md-4">
                <strong><i class="bi bi-calendar3 me-1"></i> Début travaux:</strong>
                <?= $dateDebutTab ? formatDate($dateDebutTab) : '<span class="text-warning">Non défini</span>' ?>
            </div>
            <div class="col-md-4">
                <strong><i class="bi bi-calendar-check me-1"></i> Fin prévue:</strong>
                <?= $dateFinTab ? formatDate($dateFinTab) : '<span class="text-warning">Non défini</span>' ?>
            </div>
            <div class="col-md-4">
                <strong><i class="bi bi-clock me-1"></i> Durée estimée:</strong>
                <?php if ($dureeJoursTab > 0): ?>
                    <span class="badge bg-primary fs-6"><?= $dureeJoursTab ?> jours ouvrables</span>
                    <span class="badge bg-primary fs-6 ms-1"><?= $joursFermesTab ?> jours fermés</span>
                <?php else: ?>
                    <span class="text-warning">Définir les dates dans l'onglet Base</span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($dureeSemainesTab == 0): ?>
        <div class="alert alert-warning">
            <i class="bi bi-exclamation-triangle me-2"></i>
            <strong>Attention:</strong> Vous devez d'abord définir les dates de début et fin de travaux dans l'onglet "Base" pour pouvoir calculer les coûts de main-d'œuvre.
        </div>
    <?php endif; ?>

    <!-- TOTAL EN HAUT - STICKY -->
    <div class="card bg-success text-white mb-3 sticky-top" style="top: 60px; z-index: 100;">
        <div class="card-body py-2">
            <div class="row align-items-center">
                <div class="col-auto">
                    <i class="bi bi-people fs-4"></i>
                </div>
                <div class="col">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <small class="opacity-75">Total Heures Estimées</small>
                            <h4 class="mb-0" id="totalHeures"><?= number_format($totalHeuresEstimeesTab, 1) ?> h</h4>
                        </div>
                        <div class="text-end border-start ps-3 ms-3">
                            <small class="opacity-75">Coût Main-d'œuvre Estimé</small>
                            <h4 class="mb-0" id="totalCout"><?= formatMoney($totalCoutEstimeTab) ?></h4>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <form method="POST" action="" id="formPlanification">
        <?php csrfField(); ?>
        <input type="hidden" name="action" value="planification">

        <div class="card">
            <div class="card-header">
                <i class="bi bi-person-lines-fill me-1"></i> Planification par employé
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Employé</th>
                            <th class="text-center" style="width: 100px;">Taux/h</th>
                            <th class="text-center" style="width: 140px;">Heures/semaine</th>
                            <th class="text-center" style="width: 100px;">Jours</th>
                            <th class="text-end" style="width: 100px;">Total heures</th>
                            <th class="text-end" style="width: 120px;">Coût estimé</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($employes as $emp):
                            $heuresSemaine = $planifications[$emp['id']] ?? 0;
                            $tauxHoraire = (float)$emp['taux_horaire'];
                            $heuresJour = $heuresSemaine / 5;
                            $totalHeures = $heuresJour * $dureeJoursTab;
                            $coutEstime = $totalHeures * $tauxHoraire;
                        ?>
                        <tr class="<?= $heuresSemaine > 0 ? 'table-success' : '' ?>">
                            <td>
                                <i class="bi bi-person me-1"></i>
                                <?= e($emp['nom_complet']) ?>
                                <?php if ($emp['role'] === 'admin'): ?>
                                    <span class="badge bg-secondary ms-1">Admin</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php if ($tauxHoraire > 0): ?>
                                    <?= formatMoney($tauxHoraire) ?>
                                <?php else: ?>
                                    <span class="text-warning" title="Définir dans Gestion des utilisateurs">
                                        <i class="bi bi-exclamation-triangle"></i> 0$
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <input type="number"
                                       class="form-control form-control-sm text-center heures-input"
                                       name="heures[<?= $emp['id'] ?>]"
                                       value="<?= $heuresSemaine ?>"
                                       min="0"
                                       max="80"
                                       step="0.5"
                                       data-taux="<?= $tauxHoraire ?>"
                                       data-jours="<?= $dureeJoursTab ?>"
                                       onfocus="this.select()">
                            </td>
                            <td class="text-center text-muted"><?= $dureeJoursTab ?></td>
                            <td class="text-end total-heures"><?= number_format($totalHeures, 1) ?> h</td>
                            <td class="text-end fw-bold cout-estime"><?= formatMoney($coutEstime) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="text-end mt-2">
            <button type="submit" class="btn btn-success">
                <i class="bi bi-check-circle me-1"></i>Enregistrer la planification
            </button>
        </div>
    </form>

    <div class="mt-3 text-center">
        <a href="<?= url('/admin/utilisateurs/liste.php') ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-gear me-1"></i>Modifier les taux horaires des employés
        </a>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const inputs = document.querySelectorAll('.heures-input');
        const totalHeuresEl = document.getElementById('totalHeures');
        const totalCoutEl = document.getElementById('totalCout');

        function formatMoney(val) {
            return val.toLocaleString('fr-CA', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' $';
        }

        function updateTotals() {
            let grandTotalHeures = 0;
            let grandTotalCout = 0;

            inputs.forEach(input => {
                const row = input.closest('tr');
                const heuresSemaine = parseFloat(input.value) || 0;
                const taux = parseFloat(input.dataset.taux) || 0;
                const jours = parseInt(input.dataset.jours) || 0;

                const heuresJour = heuresSemaine / 5;
                const totalHeures = heuresJour * jours;
                const cout = totalHeures * taux;

                row.querySelector('.total-heures').textContent = totalHeures.toFixed(1) + ' h';
                row.querySelector('.cout-estime').textContent = formatMoney(cout);

                if (heuresSemaine > 0) {
                    row.classList.add('table-success');
                } else {
                    row.classList.remove('table-success');
                }

                grandTotalHeures += totalHeures;
                grandTotalCout += cout;
            });

            totalHeuresEl.textContent = grandTotalHeures.toFixed(1) + ' h';
            totalCoutEl.textContent = formatMoney(grandTotalCout);
        }

        inputs.forEach(input => {
            input.addEventListener('input', updateTotals);
            input.addEventListener('change', updateTotals);
        });
    });
    </script>
    </div><!-- Fin TAB MAIN-D'ŒUVRE -->
