    <div class="tab-pane fade <?= $tab === 'financement' ? 'show active' : '' ?>" id="financement" role="tabpanel">

    <?php
    // Calcul du montant requis pour le notaire
    $prixAchatNotaire = (float)($projet['prix_achat'] ?? 0);
    $cessionNotaire = (float)($projet['cession'] ?? 0);
    $soldeVendeurNotaire = (float)($projet['solde_vendeur'] ?? 0);
    $montantRequisNotaire = $prixAchatNotaire + $cessionNotaire + $soldeVendeurNotaire;

    // Cashflow nécessaire (même calcul que page principale)
    $cashFlowNecessaire = $indicateurs['cash_flow_necessaire'] ?? 0;

    // Séparer les prêteurs des investisseurs (basé strictement sur type_financement)
    // Prêteur = reçoit des intérêts (même si 0%)
    // Investisseur = reçoit un % des profits
    $listePreteurs = [];
    $listeInvestisseurs = [];
    $totalPretsCalc = 0;
    $totalInvest = 0;
    $totalPctDirect = 0; // Total des pourcentages entrés directement

    foreach ($preteursProjet as $p) {
        $montant = (float)($p['montant'] ?? $p['mise_de_fonds'] ?? 0);
        $taux = (float)($p['taux_interet'] ?? 0);
        $pctProfit = (float)($p['pourcentage_profit'] ?? 0);
        // Utiliser strictement le type_financement enregistré, défaut à 'preteur'
        $type = $p['type_calc'] ?? 'preteur';

        if ($type === 'preteur') {
            $listePreteurs[] = array_merge($p, ['montant_calc' => $montant, 'taux_calc' => $taux]);
            $totalPretsCalc += $montant;
        } else {
            $listeInvestisseurs[] = array_merge($p, ['montant_calc' => $montant, 'pct_direct' => $pctProfit]);
            $totalInvest += $montant;
            $totalPctDirect += $pctProfit;
        }
    }

    // Calcul des différences
    $diffNotaire = $totalPretsCalc - $montantRequisNotaire;
    $isNotaireBalanced = abs($diffNotaire) < 0.01;
    $diffCashflow = $totalPretsCalc - $cashFlowNecessaire;
    $isCashflowBalanced = abs($diffCashflow) < 0.01;
    ?>

    <!-- RÉSUMÉ FINANCEMENT - DEUX TABLEAUX CÔTE À CÔTE -->
    <div class="row mb-4">
        <!-- Tableau 1: Financement Notaire -->
        <div class="col-md-6">
            <div class="card h-100" style="border-color: #3d4f5f;">
                <div class="card-header py-2 d-flex justify-content-between align-items-center" style="background: #2c3e50; border-bottom: 1px solid #3d4f5f;">
                    <span><i class="bi bi-bank2 me-2 text-info"></i><strong>Financement Notaire</strong></span>
                    <?php if (!$isNotaireBalanced): ?>
                        <?php if ($diffNotaire > 0): ?>
                            <span class="badge" style="background: #3d5a4a; color: #27ae60;">+<?= formatMoney($diffNotaire) ?></span>
                        <?php else: ?>
                            <span class="badge" style="background: #5a3d3d; color: #e74c3c;"><?= formatMoney($diffNotaire) ?></span>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="badge" style="background: #3d5a4a; color: #27ae60;"><i class="bi bi-check-circle me-1"></i>OK</span>
                    <?php endif; ?>
                </div>
                <div class="card-body py-3">
                    <table class="table table-sm table-borderless mb-0" style="color: #ecf0f1;">
                        <tbody>
                            <tr>
                                <td style="color: #95a5a6;">Prix d'achat</td>
                                <td class="text-end"><?= formatMoney($prixAchatNotaire) ?></td>
                            </tr>
                            <?php if ($cessionNotaire > 0): ?>
                            <tr>
                                <td style="color: #95a5a6;">Cession</td>
                                <td class="text-end"><?= formatMoney($cessionNotaire) ?></td>
                            </tr>
                            <?php endif; ?>
                            <?php if ($soldeVendeurNotaire > 0): ?>
                            <tr>
                                <td style="color: #95a5a6;">Solde vendeur</td>
                                <td class="text-end"><?= formatMoney($soldeVendeurNotaire) ?></td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                        <tfoot>
                            <tr style="border-top: 1px solid #3d4f5f;">
                                <td class="fw-bold pt-2">Requis au notaire</td>
                                <td class="text-end fw-bold pt-2 fs-5"><?= formatMoney($montantRequisNotaire) ?></td>
                            </tr>
                            <tr>
                                <td style="color: #95a5a6;">Total des prêts</td>
                                <td class="text-end" style="color: #95a5a6;"><?= formatMoney($totalPretsCalc) ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>

        <!-- Tableau 2: Cashflow (même calcul que page principale) -->
        <div class="col-md-6">
            <div class="card h-100" style="border-color: #3d4f5f;">
                <div class="card-header py-2 d-flex justify-content-between align-items-center" style="background: #2c3e50; border-bottom: 1px solid #3d4f5f;">
                    <span><i class="bi bi-cash-stack me-2 text-info"></i><strong>Cashflow Nécessaire</strong></span>
                    <?php if (!$isCashflowBalanced): ?>
                        <?php if ($diffCashflow > 0): ?>
                            <span class="badge" style="background: #3d5a4a; color: #27ae60;">+<?= formatMoney($diffCashflow) ?></span>
                        <?php else: ?>
                            <span class="badge" style="background: #5a3d3d; color: #e74c3c;"><?= formatMoney($diffCashflow) ?></span>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="badge" style="background: #3d5a4a; color: #27ae60;"><i class="bi bi-check-circle me-1"></i>OK</span>
                    <?php endif; ?>
                </div>
                <div class="card-body py-3">
                    <table class="table table-sm table-borderless mb-0" style="color: #ecf0f1;">
                        <tbody>
                            <tr>
                                <td style="color: #95a5a6;">Cashflow nécessaire</td>
                                <td class="text-end"><?= formatMoney($cashFlowNecessaire) ?></td>
                            </tr>
                            <tr>
                                <td style="color: #95a5a6;">Total des prêts</td>
                                <td class="text-end" style="color: #95a5a6;"><?= formatMoney($totalPretsCalc) ?></td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr style="border-top: 1px solid #3d4f5f;">
                                <td class="fw-bold pt-2">
                                    <?= $diffCashflow >= 0 ? 'Surplus' : 'Cash à sortir' ?>
                                </td>
                                <td class="text-end fw-bold pt-2 fs-5" style="<?= $diffCashflow < 0 ? 'color: #e74c3c;' : '' ?>">
                                    <?= formatMoney(abs($diffCashflow)) ?>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Légende compacte -->
    <div class="d-flex gap-4 mb-4 small text-muted">
        <div><i class="bi bi-bank me-1"></i><strong>Prêteur:</strong> Reçoit des intérêts (coût)</div>
        <div><i class="bi bi-people me-1"></i><strong>Investisseur:</strong> Reçoit un % des profits (partage)</div>
    </div>

    <div class="row">
        <!-- COLONNE PRÊTEURS -->
        <div class="col-lg-6">
            <div class="card mb-4" style="border-color: #3d4f5f;">
                <div class="card-header d-flex justify-content-between align-items-center py-2" style="background: #2c3e50; border-bottom: 1px solid #3d4f5f;">
                    <span><i class="bi bi-bank me-2 text-info"></i><strong>Prêteurs</strong></span>
                    <small class="text-secondary">Coût = Intérêts</small>
                </div>

                <?php if (empty($listePreteurs)): ?>
                    <div class="card-body text-center text-muted py-4">
                        <i class="bi bi-bank" style="font-size: 2rem;"></i>
                        <p class="mb-0 small">Aucun prêteur</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead>
                                <tr style="background: #34495e;">
                                    <th class="text-light">Nom</th>
                                    <th class="text-end text-light">Montant</th>
                                    <th class="text-center text-light">Taux</th>
                                    <th class="text-end text-light">Intérêts</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($listePreteurs as $p):
                                $tauxMensuel = $p['taux_calc'] / 100 / 12;
                                $interets = $p['montant_calc'] * (pow(1 + $tauxMensuel, $dureeReelle) - 1);
                            ?>
                                <tr style="background: #1e2a38;">
                                    <form method="POST" id="form-preteur-<?= $p['id'] ?>">
                                        <?php csrfField(); ?>
                                        <input type="hidden" name="action" value="preteurs">
                                        <input type="hidden" name="sub_action" value="modifier">
                                        <input type="hidden" name="preteur_id" value="<?= $p['id'] ?>">
                                        <td class="align-middle"><i class="bi bi-person-circle text-secondary me-1"></i><?= e($p['investisseur_nom']) ?></td>
                                        <td class="text-end">
                                            <input type="text" class="form-control form-control-sm money-input text-end"
                                                   name="montant_pret" value="<?= number_format($p['montant_calc'], 0, ',', ' ') ?>"
                                                   style="width: 100px; display: inline-block; background: #2c3e50; border-color: #3d4f5f; color: #ecf0f1;">
                                        </td>
                                        <td class="text-center">
                                            <input type="text" class="form-control form-control-sm text-center"
                                                   name="taux_interet_pret" value="<?= $p['taux_calc'] ?>"
                                                   style="width: 60px; display: inline-block; background: #2c3e50; border-color: #3d4f5f; color: #ecf0f1;">%
                                        </td>
                                        <td class="text-end" style="color: #e74c3c;"><?= formatMoney($interets) ?></td>
                                        <td class="text-nowrap">
                                            <button type="submit" class="btn btn-sm py-0 px-1" style="border-color: #3d4f5f; color: #3498db;" title="Sauvegarder">
                                                <i class="bi bi-check"></i>
                                            </button>
                                    </form>
                                            <form method="POST" class="d-inline" title="Convertir en investisseur">
                                                <?php csrfField(); ?>
                                                <input type="hidden" name="action" value="preteurs">
                                                <input type="hidden" name="sub_action" value="convertir">
                                                <input type="hidden" name="preteur_id" value="<?= $p['id'] ?>">
                                                <input type="hidden" name="nouveau_type" value="investisseur">
                                                <button type="submit" class="btn btn-sm py-0 px-1" style="border-color: #3d4f5f; color: #2ecc71;" title="Convertir en investisseur">
                                                    <i class="bi bi-arrow-right-circle"></i>
                                                </button>
                                            </form>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Supprimer?')">
                                                <?php csrfField(); ?>
                                                <input type="hidden" name="action" value="preteurs">
                                                <input type="hidden" name="sub_action" value="supprimer">
                                                <input type="hidden" name="preteur_id" value="<?= $p['id'] ?>">
                                                <button type="submit" class="btn btn-sm py-0 px-1" style="border-color: #3d4f5f; color: #7f8c8d;" title="Supprimer">
                                                    <i class="bi bi-x"></i>
                                                </button>
                                            </form>
                                        </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <!-- Formulaire ajout prêteur -->
                <div class="card-footer" style="background: #243342; border-top: 1px solid #3d4f5f;">
                    <form method="POST" class="row g-2 align-items-end">
                        <?php csrfField(); ?>
                        <input type="hidden" name="action" value="preteurs">
                        <input type="hidden" name="sub_action" value="ajouter">
                        <input type="hidden" name="type_financement" value="preteur">
                        <div class="col-4">
                            <label class="form-label small mb-0 text-secondary">Personne</label>
                            <select class="form-select form-select-sm" name="investisseur_id" required style="background: #2c3e50; border-color: #3d4f5f; color: #ecf0f1;">
                                <option value="">Choisir...</option>
                                <?php foreach ($tousInvestisseurs as $inv): ?>
                                    <option value="<?= $inv['id'] ?>"><?= e($inv['nom']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-3">
                            <label class="form-label small mb-0 text-secondary">Montant $</label>
                            <input type="text" class="form-control form-control-sm money-input" name="montant_pret" required placeholder="0" style="background: #2c3e50; border-color: #3d4f5f; color: #ecf0f1;">
                        </div>
                        <div class="col-3">
                            <label class="form-label small mb-0 text-secondary">Taux %</label>
                            <input type="text" class="form-control form-control-sm" name="taux_interet_pret" value="0" style="background: #2c3e50; border-color: #3d4f5f; color: #ecf0f1;">
                        </div>
                        <div class="col-2">
                            <button type="submit" class="btn btn-sm w-100" style="background: #3498db; border-color: #3498db; color: white;"><i class="bi bi-plus-lg"></i></button>
                        </div>
                    </form>
                    <small class="text-muted">Taux 0% = prêt sans intérêt</small>
                </div>
            </div>

            <!-- Total prêteurs -->
            <div class="card mb-4" style="border-color: #3d4f5f; background: #2c3e50;">
                <div class="card-body py-2">
                    <div class="d-flex justify-content-between">
                        <span class="text-secondary">Total prêts</span>
                        <strong><?= formatMoney($totalPretsCalc) ?></strong>
                    </div>
                    <div class="d-flex justify-content-between" style="color: #e74c3c;">
                        <span>Intérêts (<?= $dureeReelle ?> mois)</span>
                        <strong>
                            <?php
                            $totalInteretsCalc = 0;
                            foreach ($listePreteurs as $p) {
                                $tauxMensuel = $p['taux_calc'] / 100 / 12;
                                $totalInteretsCalc += $p['montant_calc'] * (pow(1 + $tauxMensuel, $dureeReelle) - 1);
                            }
                            echo formatMoney($totalInteretsCalc);
                            ?>
                        </strong>
                    </div>
                </div>
            </div>
        </div>

        <!-- COLONNE INVESTISSEURS -->
        <div class="col-lg-6">
            <div class="card mb-4" style="border-color: #3d4f5f;">
                <div class="card-header d-flex justify-content-between align-items-center py-2" style="background: #2c3e50; border-bottom: 1px solid #3d4f5f;">
                    <span><i class="bi bi-people me-2 text-info"></i><strong>Investisseurs</strong></span>
                    <small class="text-secondary">Partage des profits</small>
                </div>

                <?php if (empty($listeInvestisseurs)): ?>
                    <div class="card-body text-center text-muted py-4">
                        <i class="bi bi-people" style="font-size: 2rem;"></i>
                        <p class="mb-0 small">Aucun investisseur</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead>
                                <tr style="background: #34495e;">
                                    <th class="text-light">Nom</th>
                                    <th class="text-end text-light">Mise $</th>
                                    <th class="text-center text-light">% Direct</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php
                            $totalPctFinal = 0;
                            foreach ($listeInvestisseurs as $inv):
                                // Si pourcentage direct est défini, l'utiliser, sinon calculer selon la mise
                                $pctDirect = (float)($inv['pct_direct'] ?? 0);
                                if ($pctDirect > 0) {
                                    $pctFinal = $pctDirect;
                                } else {
                                    $pctFinal = $totalInvest > 0 ? ($inv['montant_calc'] / $totalInvest) * 100 : 0;
                                }
                                $totalPctFinal += $pctFinal;
                            ?>
                                <tr style="background: #1e2a38;">
                                    <form method="POST" id="form-invest-<?= $inv['id'] ?>">
                                        <?php csrfField(); ?>
                                        <input type="hidden" name="action" value="preteurs">
                                        <input type="hidden" name="sub_action" value="modifier">
                                        <input type="hidden" name="preteur_id" value="<?= $inv['id'] ?>">
                                        <input type="hidden" name="taux_interet_pret" value="0">
                                        <td class="align-middle"><i class="bi bi-person-circle text-secondary me-1"></i><?= e($inv['investisseur_nom']) ?></td>
                                        <td class="text-end">
                                            <input type="text" class="form-control form-control-sm money-input text-end"
                                                   name="montant_pret" value="<?= $inv['montant_calc'] > 0 ? number_format($inv['montant_calc'], 0, ',', ' ') : '' ?>"
                                                   placeholder="0"
                                                   style="width: 90px; display: inline-block; background: #2c3e50; border-color: #3d4f5f; color: #ecf0f1;">
                                        </td>
                                        <td class="text-center">
                                            <div class="input-group input-group-sm" style="width: 80px; display: inline-flex;">
                                                <input type="text" class="form-control form-control-sm text-end"
                                                       name="pourcentage_profit" value="<?= $pctDirect > 0 ? number_format($pctDirect, 1) : '' ?>"
                                                       placeholder="<?= number_format($pctFinal, 1) ?>"
                                                       style="background: #2c3e50; border-color: #3d4f5f; color: <?= $pctDirect > 0 ? '#3498db' : '#95a5a6' ?>;">
                                                <span class="input-group-text" style="background: #34495e; border-color: #3d4f5f; color: #95a5a6; padding: 0 4px;">%</span>
                                            </div>
                                        </td>
                                        <td class="text-nowrap">
                                            <button type="submit" class="btn btn-sm py-0 px-1" style="border-color: #3d4f5f; color: #3498db;" title="Sauvegarder">
                                                <i class="bi bi-check"></i>
                                            </button>
                                    </form>
                                            <form method="POST" class="d-inline" title="Convertir en prêteur">
                                                <?php csrfField(); ?>
                                                <input type="hidden" name="action" value="preteurs">
                                                <input type="hidden" name="sub_action" value="convertir">
                                                <input type="hidden" name="preteur_id" value="<?= $inv['id'] ?>">
                                                <input type="hidden" name="nouveau_type" value="preteur">
                                                <button type="submit" class="btn btn-sm py-0 px-1" style="border-color: #3d4f5f; color: #f39c12;" title="Convertir en prêteur">
                                                    <i class="bi bi-arrow-left-circle"></i>
                                                </button>
                                            </form>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Supprimer?')">
                                                <?php csrfField(); ?>
                                                <input type="hidden" name="action" value="preteurs">
                                                <input type="hidden" name="sub_action" value="supprimer">
                                                <input type="hidden" name="preteur_id" value="<?= $inv['id'] ?>">
                                                <button type="submit" class="btn btn-sm py-0 px-1" style="border-color: #3d4f5f; color: #7f8c8d;" title="Supprimer">
                                                    <i class="bi bi-x"></i>
                                                </button>
                                            </form>
                                        </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <!-- Formulaire ajout investisseur -->
                <div class="card-footer" style="background: #243342; border-top: 1px solid #3d4f5f;">
                    <form method="POST" class="row g-2 align-items-end" id="form-add-investisseur">
                        <?php csrfField(); ?>
                        <input type="hidden" name="action" value="preteurs">
                        <input type="hidden" name="sub_action" value="ajouter">
                        <input type="hidden" name="type_financement" value="investisseur">
                        <input type="hidden" name="taux_interet_pret" value="0">
                        <div class="col-5">
                            <label class="form-label small mb-0 text-secondary">Personne</label>
                            <select class="form-select form-select-sm" name="investisseur_id" required style="background: #2c3e50; border-color: #3d4f5f; color: #ecf0f1;">
                                <option value="">Choisir...</option>
                                <?php foreach ($tousInvestisseurs as $inv): ?>
                                    <option value="<?= $inv['id'] ?>"><?= e($inv['nom']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-3">
                            <label class="form-label small mb-0 text-secondary">Mise $</label>
                            <input type="text" class="form-control form-control-sm money-input" name="montant_pret" placeholder="0" style="background: #2c3e50; border-color: #3d4f5f; color: #ecf0f1;">
                        </div>
                        <div class="col-2">
                            <label class="form-label small mb-0 text-secondary">OU %</label>
                            <input type="text" class="form-control form-control-sm" name="pourcentage_profit" placeholder="%" style="background: #2c3e50; border-color: #3d4f5f; color: #ecf0f1;">
                        </div>
                        <div class="col-2">
                            <button type="submit" class="btn btn-sm w-100" style="background: #3498db; border-color: #3498db; color: white;"><i class="bi bi-plus-lg"></i></button>
                        </div>
                    </form>
                    <small class="text-muted">Entrez une mise $ (% calculé auto) OU un % direct</small>
                </div>
            </div>

            <!-- Total investisseurs et avertissement -->
            <?php
            // Calculer le total final des pourcentages
            $totalPctAffiche = 0;
            foreach ($listeInvestisseurs as $inv) {
                $pctDirect = (float)($inv['pct_direct'] ?? 0);
                if ($pctDirect > 0) {
                    $totalPctAffiche += $pctDirect;
                } else {
                    $totalPctAffiche += $totalInvest > 0 ? ($inv['montant_calc'] / $totalInvest) * 100 : 0;
                }
            }
            $pctManquant = 100 - $totalPctAffiche;
            ?>
            <div class="card mb-4" style="border-color: #3d4f5f; background: #2c3e50;">
                <div class="card-body py-2">
                    <div class="d-flex justify-content-between mb-1">
                        <span class="text-secondary">Total mises</span>
                        <strong><?= formatMoney($totalInvest) ?></strong>
                    </div>
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="text-secondary">Total %</span>
                        <?php if (abs($pctManquant) < 0.1): ?>
                            <span class="badge" style="background: #27ae60;"><i class="bi bi-check-circle me-1"></i>100%</span>
                        <?php elseif ($pctManquant > 0): ?>
                            <span class="badge" style="background: #e74c3c;">
                                <?= number_format($totalPctAffiche, 1) ?>%
                                <i class="bi bi-arrow-right mx-1"></i>
                                Manque <?= number_format($pctManquant, 1) ?>%
                            </span>
                        <?php else: ?>
                            <span class="badge" style="background: #e67e22;">
                                <?= number_format($totalPctAffiche, 1) ?>%
                                <i class="bi bi-exclamation-triangle ms-1"></i>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Lien pour ajouter des personnes -->
    <div class="text-center">
        <a href="<?= url('/admin/investisseurs/liste.php') ?>" class="btn btn-sm" style="border-color: #3d4f5f; color: #7f8c8d;">
            <i class="bi bi-person-plus me-1"></i>Gérer la liste des personnes
        </a>
    </div>

    <!-- Indicateur de sauvegarde -->
    <div id="save-indicator" style="display:none; position:fixed; top:20px; right:20px; padding:10px 20px; border-radius:5px; z-index:9999; font-weight:bold;">
        <i class="bi me-2"></i><span></span>
    </div>

    <!-- Auto-save sur blur pour les champs de financement -->
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const editForms = document.querySelectorAll('form[id^="form-preteur-"], form[id^="form-invest-"]');
        const indicator = document.getElementById('save-indicator');

        function showIndicator(type, message) {
            const icon = indicator.querySelector('i');
            const text = indicator.querySelector('span');
            indicator.style.display = 'block';
            text.textContent = message;

            if (type === 'saving') {
                indicator.style.background = '#f39c12';
                indicator.style.color = 'white';
                icon.className = 'bi bi-arrow-repeat me-2';
                icon.style.animation = 'spin 1s linear infinite';
            } else if (type === 'success') {
                indicator.style.background = '#27ae60';
                indicator.style.color = 'white';
                icon.className = 'bi bi-check-circle me-2';
                icon.style.animation = 'none';
                setTimeout(() => { indicator.style.display = 'none'; }, 1500);
            }
        }

        editForms.forEach(function(form) {
            const inputs = form.querySelectorAll('input[type="text"]');
            inputs.forEach(function(input) {
                input.dataset.initialValue = input.value;

                input.addEventListener('blur', function() {
                    if (this.value !== this.dataset.initialValue) {
                        showIndicator('saving', 'Sauvegarde...');
                        form.submit();
                    }
                });

                input.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        if (this.value !== this.dataset.initialValue) {
                            showIndicator('saving', 'Sauvegarde...');
                            form.submit();
                        }
                    }
                });
            });
        });
    });
    </script>
    <style>
    @keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
    </style>
    </div><!-- Fin TAB FINANCEMENT -->
