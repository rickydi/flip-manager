    <div class="tab-pane fade <?= $tab === 'temps' ? 'show active' : '' ?>" id="temps" role="tabpanel">
        <?php
        $totalHeuresTab = array_sum(array_column($heuresProjet, 'heures'));
        $totalCoutTab = 0;
        foreach ($heuresProjet as $h) {
            $taux = $h['taux_horaire'] > 0 ? $h['taux_horaire'] : $h['taux_actuel'];
            $totalCoutTab += $h['heures'] * $taux;
        }
        $totalAvancesActives = array_sum(array_column($avancesListe, 'montant'));

        // Calculer les journées travaillées (dates distinctes où des heures ont été enregistrées)
        $datesDistinctes = array_unique(array_column($heuresProjet, 'date_travail'));
        $journeesTravaillees = count($datesDistinctes);

        // Calculer tous les jours du projet (incluant samedi et dimanche)
        $dateDebutTravaux = $projet['date_debut_travaux'] ?? $projet['date_acquisition'] ?? null;
        $dateFinPrevue = $projet['date_fin_prevue'] ?? null;
        $joursTotaux = 0;
        $journeesNonTravaillees = 0;

        if ($dateDebutTravaux && $dateFinPrevue) {
            $debut = new DateTime($dateDebutTravaux);
            $fin = new DateTime($dateFinPrevue);
            $aujourdhui = new DateTime();

            // Utiliser la date la plus petite entre aujourd'hui et la fin prévue
            $finCalcul = $fin < $aujourdhui ? $fin : $aujourdhui;

            if ($debut <= $finCalcul) {
                $interval = new DateInterval('P1D');
                $periode = new DatePeriod($debut, $interval, $finCalcul->modify('+1 day'));

                foreach ($periode as $date) {
                    // Compter tous les jours (lundi à dimanche)
                    $joursTotaux++;
                }
            }

            $journeesNonTravaillees = max(0, $joursTotaux - $journeesTravaillees);
        }
        ?>

        <!-- Barre compacte : Stats -->
        <div class="d-flex flex-wrap align-items-center gap-2 mb-3 p-2 rounded" style="background: rgba(255,255,255,0.03);">
            <div class="d-flex align-items-center px-3 py-1 rounded" style="background: rgba(13,110,253,0.15);">
                <i class="bi bi-clock text-primary me-2"></i>
                <span class="text-muted me-1">Heures:</span>
                <strong class="text-primary"><?= number_format($totalHeuresTab, 1) ?> h</strong>
            </div>
            <div class="d-flex align-items-center px-3 py-1 rounded" style="background: rgba(25,135,84,0.15);">
                <i class="bi bi-cash text-success me-2"></i>
                <span class="text-muted me-1">Coût:</span>
                <strong class="text-success"><?= formatMoney($totalCoutTab) ?></strong>
            </div>
            <?php if ($totalAvancesActives > 0): ?>
            <div class="d-flex align-items-center px-3 py-1 rounded" style="background: rgba(220,53,69,0.15);">
                <i class="bi bi-wallet2 text-danger me-2"></i>
                <span class="text-muted me-1">Avances:</span>
                <strong class="text-danger"><?= formatMoney($totalAvancesActives) ?></strong>
            </div>
            <?php endif; ?>
            <?php if ($joursTotaux > 0): ?>
            <div class="d-flex align-items-center px-3 py-1 rounded" style="background: rgba(13,202,240,0.15);">
                <i class="bi bi-calendar-week text-info me-2"></i>
                <span class="text-muted me-1">Jours:</span>
                <strong class="text-info"><?= $journeesTravaillees ?></strong>
                <span class="text-muted mx-1">/</span>
                <span class="text-muted"><?= $joursTotaux ?></span>
            </div>
            <?php else: ?>
            <div class="d-flex align-items-center px-3 py-1 rounded" style="background: rgba(13,202,240,0.15);">
                <i class="bi bi-calendar-check text-info me-2"></i>
                <span class="text-muted me-1">Jours travaillés:</span>
                <strong class="text-info"><?= $journeesTravaillees ?></strong>
            </div>
            <?php endif; ?>
            <div class="ms-auto d-flex gap-2">
                <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalHeures">
                    <i class="bi bi-clock me-1"></i>Ajouter des heures
                </button>
                <button type="button" class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#modalAvance">
                    <i class="bi bi-wallet2 me-1"></i>Nouvelle avance
                </button>
                <span class="badge bg-secondary align-self-center"><?= count($heuresProjet) ?> entrées</span>
            </div>
        </div>

        <div class="row">
            <!-- Résumé par employé -->
            <div class="col-lg-7 mb-3">
                <div class="card">
                    <div class="card-header py-2">
                        <i class="bi bi-people me-2"></i>Résumé par employé
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($resumeEmployes)): ?>
                            <div class="text-center py-3 text-muted">Aucune heure enregistrée</div>
                        <?php else: ?>
                        <table class="table table-sm table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Employé</th>
                                    <th class="text-end">Heures</th>
                                    <th class="text-end">Brut</th>
                                    <th class="text-end text-danger">Avances</th>
                                    <th class="text-end" style="background: rgba(25,135,84,0.1);">Net</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($resumeEmployes as $emp):
                                    $avEmp = $avancesParEmploye[$emp['user_id']] ?? ['total' => 0, 'nb' => 0];
                                    $netEmp = $emp['montant_approuve'] - $avEmp['total'];
                                ?>
                                <tr>
                                    <td>
                                        <?= e($emp['nom_complet']) ?>
                                        <?php if ($emp['heures_attente'] > 0): ?>
                                            <small class="text-warning">(+<?= number_format($emp['heures_attente'], 1) ?>h en attente)</small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end"><?= number_format($emp['heures_approuvees'], 1) ?>h</td>
                                    <td class="text-end"><?= formatMoney($emp['montant_approuve']) ?></td>
                                    <td class="text-end">
                                        <?php if ($avEmp['total'] > 0): ?>
                                            <span class="text-danger">-<?= formatMoney($avEmp['total']) ?></span>
                                            <small class="text-muted">(<?= $avEmp['nb'] ?>)</small>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end fw-bold" style="background: rgba(25,135,84,0.1);">
                                        <?= formatMoney($netEmp) ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Avances actives -->
            <div class="col-lg-5 mb-3">
                <div class="card">
                    <div class="card-header py-2 d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-wallet2 me-2"></i>Avances actives</span>
                        <?php if ($totalAvancesActives > 0): ?>
                            <span class="badge bg-danger"><?= formatMoney($totalAvancesActives) ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="card-body p-0" style="max-height: 250px; overflow-y: auto;">
                        <?php if (empty($avancesListe)): ?>
                            <div class="text-center py-3 text-muted">
                                <i class="bi bi-check-circle"></i> Aucune avance
                            </div>
                        <?php else: ?>
                        <table class="table table-sm table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Employé</th>
                                    <th class="text-end">Montant</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($avancesListe as $av): ?>
                                <tr>
                                    <td><small><?= formatDate($av['date_avance']) ?></small></td>
                                    <td><?= e($av['employe_nom']) ?></td>
                                    <td class="text-end text-danger fw-bold"><?= formatMoney($av['montant']) ?></td>
                                    <td>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Annuler cette avance?');">
                                            <?php csrfField(); ?>
                                            <input type="hidden" name="action" value="annuler_avance">
                                            <input type="hidden" name="avance_id" value="<?= $av['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-1" title="Annuler">
                                                <i class="bi bi-x"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                                <?php if ($av['raison']): ?>
                                <tr>
                                    <td colspan="4" class="py-0 ps-4 border-0">
                                        <small class="text-muted"><?= e($av['raison']) ?></small>
                                    </td>
                                </tr>
                                <?php endif; ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tableau détaillé des heures -->
        <div class="card">
            <div class="card-header py-2">
                <i class="bi bi-clock-history me-2"></i>Détail des heures
            </div>
            <div class="card-body p-0">
                <?php if (empty($heuresProjet)): ?>
                    <div class="text-center py-3 text-muted">
                        <i class="bi bi-info-circle me-2"></i>Aucune heure enregistrée pour ce projet.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0">
                            <thead class="table-dark">
                                <tr>
                                    <th>Date</th>
                                    <th>Employé</th>
                                    <th class="text-end">Heures</th>
                                    <th class="text-end">Taux</th>
                                    <th class="text-end">Montant</th>
                                    <th>Statut</th>
                                    <th>Description</th>
                                    <th class="text-center" style="width: 80px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($heuresProjet as $h):
                                    $taux = $h['taux_horaire'] > 0 ? $h['taux_horaire'] : $h['taux_actuel'];
                                    $montant = $h['heures'] * $taux;
                                ?>
                                <tr>
                                    <td><?= formatDate($h['date_travail']) ?></td>
                                    <td><?= e($h['employe_nom']) ?></td>
                                    <td class="text-end"><?= number_format($h['heures'], 1) ?></td>
                                    <td class="text-end"><?= formatMoney($taux) ?>/h</td>
                                    <td class="text-end fw-bold"><?= formatMoney($montant) ?></td>
                                    <td>
                                        <?php
                                        $statusClass = match($h['statut']) {
                                            'approuvee' => 'bg-success',
                                            'rejetee' => 'bg-danger',
                                            default => 'bg-warning text-dark'
                                        };
                                        $statusLabel = match($h['statut']) {
                                            'approuvee' => 'Approuvée',
                                            'rejetee' => 'Rejetée',
                                            default => 'En attente'
                                        };
                                        ?>
                                        <?php if ($h['statut'] === 'en_attente'): ?>
                                        <div class="dropdown">
                                            <span class="badge <?= $statusClass ?> dropdown-toggle" role="button" data-bs-toggle="dropdown" style="cursor: pointer;">
                                                <?= $statusLabel ?>
                                            </span>
                                            <ul class="dropdown-menu dropdown-menu-end">
                                                <li>
                                                    <form method="POST" class="d-inline">
                                                        <?php csrfField(); ?>
                                                        <input type="hidden" name="action" value="approuver_heures">
                                                        <input type="hidden" name="heures_id" value="<?= $h['id'] ?>">
                                                        <button type="submit" class="dropdown-item text-success">
                                                            <i class="bi bi-check-circle me-2"></i>Approuver
                                                        </button>
                                                    </form>
                                                </li>
                                                <li>
                                                    <form method="POST" class="d-inline">
                                                        <?php csrfField(); ?>
                                                        <input type="hidden" name="action" value="rejeter_heures">
                                                        <input type="hidden" name="heures_id" value="<?= $h['id'] ?>">
                                                        <button type="submit" class="dropdown-item text-danger">
                                                            <i class="bi bi-x-circle me-2"></i>Rejeter
                                                        </button>
                                                    </form>
                                                </li>
                                            </ul>
                                        </div>
                                        <?php else: ?>
                                        <span class="badge <?= $statusClass ?>"><?= $statusLabel ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><small class="text-muted"><?= e($h['description'] ?? '') ?></small></td>
                                    <td class="text-center">
                                        <?php
                                        // Formater la date pour l'input type="date" (YYYY-MM-DD)
                                        $dateForInput = '';
                                        if (!empty($h['date_travail'])) {
                                            // Si c'est déjà au format YYYY-MM-DD, l'utiliser directement
                                            if (preg_match('/^\d{4}-\d{2}-\d{2}/', $h['date_travail'])) {
                                                $dateForInput = substr($h['date_travail'], 0, 10);
                                            } else {
                                                $ts = strtotime($h['date_travail']);
                                                if ($ts !== false) {
                                                    $dateForInput = date('Y-m-d', $ts);
                                                }
                                            }
                                        }
                                        if (empty($dateForInput)) {
                                            $dateForInput = date('Y-m-d');
                                        }
                                        ?>
                                        <button type="button" class="btn btn-sm btn-outline-primary py-0 px-1 btn-edit-heures"
                                                data-id="<?= $h['id'] ?>"
                                                data-user="<?= $h['user_id'] ?>"
                                                data-date="<?= $dateForInput ?>"
                                                data-heures="<?= $h['heures'] ?>"
                                                data-taux="<?= $taux ?>"
                                                data-statut="<?= $h['statut'] ?>"
                                                data-description="<?= e($h['description'] ?? '') ?>"
                                                title="Modifier">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Supprimer cette entrée?');">
                                            <?php csrfField(); ?>
                                            <input type="hidden" name="action" value="supprimer_heures">
                                            <input type="hidden" name="heures_id" value="<?= $h['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-1" title="Supprimer">
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
            </div>
        </div>
    </div><!-- Fin TAB TEMPS -->
