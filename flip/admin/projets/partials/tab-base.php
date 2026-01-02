<?php
$isPartialBase = isset($_GET['partial']) && $_GET['partial'] === 'base';
if ($isPartialBase) {
    ob_start();
}
?>
    <div class="tab-pane fade <?= $tab === 'base' ? 'show active' : '' ?>" id="base" role="tabpanel">

    <!-- CONTENU DYNAMIQUE BASE -->
    <div id="base-dynamic-content">

    <!-- Indicateurs en haut -->
    <?php
    // Déterminer les couleurs pour Extrapolé et Réel
    $extrapoleBgClass = $indicateurs['equite_potentielle'] >= 0 ? 'bg-success' : 'bg-danger';
    $extrapoleTextClass = $indicateurs['equite_potentielle'] >= 0 ? 'text-success' : 'text-danger';
    $reelBgClass = $indicateurs['equite_reelle'] >= 0 ? 'bg-success' : 'bg-danger';
    $reelTextClass = $indicateurs['equite_reelle'] >= 0 ? 'text-success' : 'text-danger';
    ?>
    <div class="row g-2 mb-3">
        <div class="col-6 col-lg">
            <div class="card text-center p-2" style="background: rgba(100, 116, 139, 0.1);" role="button" data-bs-toggle="tooltip" data-bs-placement="bottom" title="Prix de vente estimé de la propriété après rénovations">
                <small class="text-muted">Valeur potentielle <i class="bi bi-info-circle small"></i></small>
                <strong class="fs-5" style="color: #64748b;" id="indValeurPotentielle"><?= formatMoney($indicateurs['valeur_potentielle']) ?></strong>
            </div>
        </div>
        <div class="col-6 col-lg">
            <div class="card text-center p-2 <?= $extrapoleBgClass ?> bg-opacity-10" role="button" data-bs-toggle="tooltip" data-bs-placement="bottom" title="Profit prévu si vous respectez le budget. Calcul: Valeur potentielle - Prix d'achat - Budget total - Frais">
                <small class="text-muted">Extrapolé <i class="bi bi-info-circle small"></i></small>
                <strong class="fs-5 <?= $extrapoleTextClass ?>" id="indEquiteBudget"><?= formatMoney($indicateurs['equite_potentielle']) ?></strong>
            </div>
        </div>
        <div class="col-6 col-lg">
            <div class="card text-center p-2" style="background: rgba(100, 116, 139, 0.1);" role="button" data-bs-toggle="tooltip" data-bs-placement="bottom" title="Cash flow nécessaire. Exclut: courtier, taxes mun/scol, mutation. Sans intérêts: <?= formatMoney($indicateurs['cash_flow_moins_interets'], false) ?>$">
                <small class="text-muted">Cash Flow <i class="bi bi-info-circle small"></i></small>
                <strong class="fs-5" style="color: #64748b;" id="indCashFlow"><?= formatMoney($indicateurs['cash_flow_necessaire']) ?></strong>
            </div>
        </div>
        <div class="col-6 col-lg">
            <div class="card text-center p-2 <?= $reelBgClass ?> bg-opacity-10" role="button" data-bs-toggle="tooltip" data-bs-placement="bottom" title="Profit réel basé sur les dépenses actuelles. Calcul: Valeur potentielle - Prix d'achat - Dépenses réelles - Frais">
                <small class="text-muted">Réel <i class="bi bi-info-circle small"></i></small>
                <strong class="fs-5 <?= $reelTextClass ?>" id="indEquiteReelle"><?= formatMoney($indicateurs['equite_reelle']) ?></strong>
            </div>
        </div>
        <div class="col-6 col-lg">
            <div class="card text-center p-2" style="background: rgba(100, 116, 139, 0.1);" role="button" data-bs-toggle="tooltip" data-bs-placement="bottom" title="Retour sur investissement basé sur votre mise de fonds (cash investi). Calcul: Équité Réelle ÷ Mise de fonds × 100">
                <small class="text-muted">ROI Leverage <i class="bi bi-info-circle small"></i></small>
                <strong class="fs-5" style="color: #64748b;" id="indRoiLeverage"><?= formatPercent($indicateurs['roi_leverage']) ?></strong>
            </div>
        </div>
    </div>

    <!-- FORMULAIRE ÉDITION -->
    <style>
        .compact-form .mb-3 { margin-bottom: 0.5rem !important; }
        .compact-form .form-label { font-size: 0.8rem; margin-bottom: 0.2rem; color: #666; }
        .compact-form .form-control, .compact-form .form-select { font-size: 0.9rem; padding: 0.35rem 0.5rem; }
        .compact-form .input-group-text { font-size: 0.8rem; padding: 0.35rem 0.5rem; }
        .compact-form .card { margin-bottom: 1rem !important; }
        .compact-form .card-header { padding: 0.5rem 1rem; font-size: 0.9rem; }
        .compact-form .card-body { padding: 0.75rem; }

        /* Graphiques modernes */
        .chart-card {
            background: var(--bg-card);
            border: none;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            overflow: hidden;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        .chart-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 30px rgba(0,0,0,0.12);
        }
        .chart-header {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 16px;
            background: rgba(99,102,241,0.08);
            border-bottom: 1px solid rgba(99,102,241,0.1);
        }
        .chart-header.red { background: rgba(239,68,68,0.08); }
        .chart-header.blue { background: rgba(59,130,246,0.08); }
        .chart-header.green { background: rgba(34,197,94,0.08); }
        .chart-header.orange { background: rgba(249,115,22,0.08); }
        .chart-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
        }
        .chart-icon.red { background: #ef4444; color: white; }
        .chart-icon.blue { background: #3b82f6; color: white; }
        .chart-icon.green { background: #22c55e; color: white; }
        .chart-icon.orange { background: #f97316; color: white; }
        .chart-title {
            font-weight: 600;
            font-size: 0.9rem;
            color: var(--text-primary);
        }
        .chart-subtitle {
            font-size: 0.7rem;
            color: var(--text-secondary);
        }
        .chart-body {
            padding: 12px;
            position: relative;
        }
        .chart-body canvas {
            border-radius: 8px;
        }
        /* Budget Gauge - 4 jauges empilées verticalement */
        .budget-gauges-stack {
            display: flex;
            flex-direction: column;
            gap: 8px;
            padding: 5px 10px;
        }
        .mini-gauge {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .mini-gauge-label {
            font-size: 0.7rem;
            font-weight: 600;
            width: 50px;
            flex-shrink: 0;
            opacity: 0.9;
        }
        .mini-gauge-label i { margin-right: 3px; }
        .mini-gauge-bar {
            position: relative;
            flex: 1;
            height: 14px;
            border-radius: 7px;
            overflow: visible;
            background: linear-gradient(90deg, #ef4444 0%, #fbbf24 35%, #22c55e 50%, #22c55e 100%);
        }
        .mini-gauge-center {
            position: absolute;
            left: 50%;
            top: -1px;
            bottom: -1px;
            width: 2px;
            background: #1e293b;
            transform: translateX(-50%);
            border-radius: 1px;
            z-index: 2;
        }
        .mini-gauge-indicator {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 10px;
            height: 10px;
            background: #1e293b;
            border: 2px solid #fff;
            border-radius: 50%;
            box-shadow: 0 1px 4px rgba(0,0,0,0.3);
            z-index: 3;
            transition: left 0.8s cubic-bezier(0.34, 1.56, 0.64, 1);
        }
        .mini-gauge-value {
            font-size: 0.7rem;
            font-weight: 600;
            width: 45px;
            text-align: right;
            flex-shrink: 0;
        }
        .mini-gauge-value.negative { color: #ef4444; }
        .mini-gauge-value.positive { color: #22c55e; }
        .mini-gauge-value.neutral { color: #64748b; }
    </style>

    <!-- Lottie Player -->
    <script src="https://unpkg.com/@lottiefiles/lottie-player@latest/dist/lottie-player.js"></script>

    <!-- GRAPHIQUES MODERNES -->
    <div class="row g-3 mb-3">
        <div class="col-md-4">
            <div class="card chart-card h-100">
                <div class="chart-header blue">
                    <div class="chart-icon blue">
                        <i class="bi bi-clock-history"></i>
                    </div>
                    <div>
                        <div class="chart-title">Heures travaillées</div>
                        <div class="chart-subtitle">Par jour de la semaine</div>
                    </div>
                </div>
                <div class="chart-body"><canvas id="chartBudget" height="150"></canvas></div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card chart-card h-100">
                <div class="chart-header green">
                    <div class="chart-icon green">
                        <i class="bi bi-cart"></i>
                    </div>
                    <div>
                        <div class="chart-title">Achats par jour</div>
                        <div class="chart-subtitle">Montant des factures</div>
                    </div>
                </div>
                <div class="chart-body"><canvas id="chartProfits" height="150"></canvas></div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card chart-card h-100">
                <div class="chart-header orange">
                    <div class="chart-icon orange">
                        <i class="bi bi-graph-up-arrow"></i>
                    </div>
                    <div>
                        <div class="chart-title">Écarts Budget</div>
                        <div class="chart-subtitle">Réel vs Extrapolé</div>
                    </div>
                </div>
                <div class="chart-body d-flex flex-column justify-content-center" style="min-height: 150px;">
                    <div class="budget-gauges-stack">
                        <div class="mini-gauge">
                            <div class="mini-gauge-label"><i class="bi bi-arrow-repeat"></i>Réc.</div>
                            <div class="mini-gauge-bar"><div class="mini-gauge-center"></div><div class="mini-gauge-indicator" id="gaugeIndicatorRecurrent"></div></div>
                            <div class="mini-gauge-value" id="gaugeValueRecurrent">-</div>
                        </div>
                        <div class="mini-gauge">
                            <div class="mini-gauge-label"><i class="bi bi-tools"></i>Réno</div>
                            <div class="mini-gauge-bar"><div class="mini-gauge-center"></div><div class="mini-gauge-indicator" id="gaugeIndicatorRenovation"></div></div>
                            <div class="mini-gauge-value" id="gaugeValueRenovation">-</div>
                        </div>
                        <div class="mini-gauge">
                            <div class="mini-gauge-label"><i class="bi bi-shop"></i>Vente</div>
                            <div class="mini-gauge-bar"><div class="mini-gauge-center"></div><div class="mini-gauge-indicator" id="gaugeIndicatorVente"></div></div>
                            <div class="mini-gauge-value" id="gaugeValueVente">-</div>
                        </div>
                        <div class="mini-gauge">
                            <div class="mini-gauge-label"><i class="bi bi-star-fill"></i>Profit</div>
                            <div class="mini-gauge-bar"><div class="mini-gauge-center"></div><div class="mini-gauge-indicator" id="gaugeIndicatorGeneral"></div></div>
                            <div class="mini-gauge-value" id="gaugeValueGeneral">-</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
    <div class="col-xxl-6">
    <form method="POST" action="" class="compact-form" id="formBase">
        <?php csrfField(); ?>
        <input type="hidden" name="action" value="general">

        <div class="row">
            <!-- Colonne gauche -->
            <div class="col-lg-6 d-flex flex-column gap-3">
                <div class="card">
                    <div class="card-header"><i class="bi bi-info-circle me-1"></i>Infos</div>
                    <div class="card-body">
                        <div class="row g-2">
                            <div class="col-8">
                                <label class="form-label">Nom *</label>
                                <input type="text" class="form-control" name="nom" value="<?= e($projet['nom']) ?>" required>
                            </div>
                            <div class="col-4">
                                <label class="form-label">Statut</label>
                                <select class="form-select" name="statut">
                                    <option value="prospection" <?= $projet['statut'] === 'prospection' ? 'selected' : '' ?>>Prospection</option>
                                    <option value="acquisition" <?= $projet['statut'] === 'acquisition' ? 'selected' : '' ?>>Acquisition</option>
                                    <option value="renovation" <?= $projet['statut'] === 'renovation' ? 'selected' : '' ?>>Réno</option>
                                    <option value="vente" <?= $projet['statut'] === 'vente' ? 'selected' : '' ?>>Vente</option>
                                    <option value="vendu" <?= $projet['statut'] === 'vendu' ? 'selected' : '' ?>>Vendu</option>
                                </select>
                            </div>
                            <div class="col-6">
                                <label class="form-label">Adresse *</label>
                                <input type="text" class="form-control" name="adresse" value="<?= e($projet['adresse']) ?>" required>
                            </div>
                            <div class="col-4">
                                <label class="form-label">Ville *</label>
                                <input type="text" class="form-control" name="ville" value="<?= e($projet['ville']) ?>" required>
                            </div>
                            <div class="col-2">
                                <label class="form-label">Code</label>
                                <input type="text" class="form-control" name="code_postal" value="<?= e($projet['code_postal']) ?>">
                            </div>
                            <div class="col-3">
                                <label class="form-label">Achat</label>
                                <input type="date" class="form-control" name="date_acquisition" id="date_acquisition" value="<?= e($projet['date_acquisition']) ?>" onchange="calculerDuree()">
                            </div>
                            <div class="col-3">
                                <label class="form-label">Début trav.</label>
                                <input type="date" class="form-control" name="date_debut_travaux" value="<?= e($projet['date_debut_travaux']) ?>">
                            </div>
                            <div class="col-3">
                                <label class="form-label">Fin travaux</label>
                                <input type="date" class="form-control" name="date_fin_prevue" id="date_fin_prevue" value="<?= e($projet['date_fin_prevue']) ?>" onchange="calculerDuree()">
                            </div>
                            <div class="col-3">
                                <label class="form-label">Vendu</label>
                                <input type="date" class="form-control" name="date_vente" id="date_vente" value="<?= e($projet['date_vente'] ?? '') ?>" onchange="calculerDuree()">
                            </div>
                            <div class="col-12">
                                <label class="form-label"><i class="bi bi-dropbox me-1"></i>Dropbox</label>
                                <div class="input-group">
                                    <input type="url" class="form-control" name="dropbox_link" id="dropbox_link" value="<?= e($projet['dropbox_link'] ?? '') ?>" placeholder="https://www.dropbox.com/...">
                                    <?php if (!empty($projet['dropbox_link'])): ?>
                                    <a href="<?= e($projet['dropbox_link']) ?>" target="_blank" class="btn btn-outline-primary" title="Ouvrir Dropbox">
                                        <i class="bi bi-box-arrow-up-right"></i>
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header"><i class="bi bi-currency-dollar me-1"></i>Achat</div>
                    <div class="card-body">
                        <div class="row g-2">
                            <div class="col-3">
                                <label class="form-label">Prix achat</label>
                                <div class="input-group"><span class="input-group-text">$</span>
                                    <input type="text" class="form-control money-input" name="prix_achat" id="prix_achat" value="<?= formatMoney($projet['prix_achat'], false) ?>" onchange="calculerTaxeMutation()">
                                </div>
                            </div>
                            <div class="col-3">
                                <label class="form-label">Rôle éval.</label>
                                <div class="input-group"><span class="input-group-text">$</span>
                                    <input type="text" class="form-control money-input" name="role_evaluation" id="role_evaluation" value="<?= formatMoney($projet['role_evaluation'] ?? 0, false) ?>" onchange="calculerTaxeMutation()">
                                </div>
                            </div>
                            <div class="col-3">
                                <label class="form-label">Valeur pot.</label>
                                <div class="input-group"><span class="input-group-text">$</span>
                                    <input type="text" class="form-control money-input" name="valeur_potentielle" value="<?= formatMoney($projet['valeur_potentielle'], false) ?>">
                                </div>
                            </div>
                            <div class="col-3">
                                <label class="form-label">Durée (mois)</label>
                                <input type="number" class="form-control bg-light" name="temps_assume_mois" id="duree_mois" value="<?= (int)$projet['temps_assume_mois'] ?>" readonly title="Calculé automatiquement: Date vente (ou fin travaux) - Date achat">
                            </div>
                            <div class="col-3">
                                <label class="form-label">Cession</label>
                                <div class="input-group"><span class="input-group-text">$</span>
                                    <input type="text" class="form-control money-input" name="cession" value="<?= formatMoney($projet['cession'] ?? 0, false) ?>">
                                </div>
                            </div>
                            <div class="col-3">
                                <label class="form-label">Notaire</label>
                                <div class="input-group"><span class="input-group-text">$</span>
                                    <input type="text" class="form-control money-input" name="notaire" value="<?= formatMoney($projet['notaire'], false) ?>">
                                </div>
                            </div>
                            <div class="col-3">
                                <label class="form-label">Arpenteurs</label>
                                <div class="input-group"><span class="input-group-text">$</span>
                                    <input type="text" class="form-control money-input" name="arpenteurs" value="<?= formatMoney($projet['arpenteurs'], false) ?>">
                                </div>
                            </div>
                            <div class="col-3">
                                <label class="form-label">Ass. titre</label>
                                <div class="input-group"><span class="input-group-text">$</span>
                                    <input type="text" class="form-control money-input" name="assurance_titre" value="<?= formatMoney($projet['assurance_titre'], false) ?>">
                                </div>
                            </div>
                            <div class="col-4">
                                <label class="form-label">Contingence</label>
                                <div class="input-group">
                                    <input type="number" class="form-control" name="taux_contingence" id="taux_contingence" step="0.01" value="<?= $projet['taux_contingence'] ?>">
                                    <span class="input-group-text">%</span>
                                </div>
                            </div>
                            <div class="col-4">
                                <label class="form-label">Solde vendeur</label>
                                <div class="input-group"><span class="input-group-text">$</span>
                                    <input type="text" class="form-control money-input" name="solde_vendeur" value="<?= formatMoney($projet['solde_vendeur'] ?? 0, false) ?>">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>

            <!-- Colonne droite -->
            <div class="col-lg-6 d-flex flex-column gap-3">
                <div class="card flex-grow-1">
                    <div class="card-header">
                        <i class="bi bi-arrow-repeat me-1"></i>Récurrents
                        <a href="<?= url('/admin/recurrents/liste.php') ?>" class="float-end small text-decoration-none" title="Gérer les types">
                            <i class="bi bi-gear"></i>
                        </a>
                    </div>
                    <div class="card-body">
                        <div class="row g-2">
                            <?php foreach ($recurrentsTypes as $type):
                                $valeur = $projetRecurrents[$type['id']] ?? 0;
                                $freq = match($type['frequence']) {
                                    'mensuel' => '/mois',
                                    'saisonnier' => '',
                                    default => '/an'
                                };
                                // Tronquer le nom si trop long
                                $nomCourt = mb_strlen($type['nom']) > 15 ? mb_substr($type['nom'], 0, 12) . '...' : $type['nom'];
                            ?>
                            <div class="col-6">
                                <label class="form-label" title="<?= e($type['nom']) ?>"><?= e($nomCourt) ?><?= $freq ?></label>
                                <div class="input-group"><span class="input-group-text">$</span>
                                    <input type="text" class="form-control money-input" name="recurrents[<?= $type['id'] ?>]" value="<?= formatMoney($valeur, false) ?>">
                                </div>
                            </div>
                            <?php endforeach; ?>
                            <?php if (empty($recurrentsTypes)): ?>
                            <!-- Fallback si pas de types (anciens champs pour compatibilité) -->
                            <div class="col-6">
                                <label class="form-label">Taxes mun. /an</label>
                                <div class="input-group"><span class="input-group-text">$</span>
                                    <input type="text" class="form-control money-input" name="taxes_municipales_annuel" value="<?= formatMoney($projet['taxes_municipales_annuel'], false) ?>">
                                </div>
                            </div>
                            <div class="col-6">
                                <label class="form-label">Taxes scol. /an</label>
                                <div class="input-group"><span class="input-group-text">$</span>
                                    <input type="text" class="form-control money-input" name="taxes_scolaires_annuel" value="<?= formatMoney($projet['taxes_scolaires_annuel'], false) ?>">
                                </div>
                            </div>
                            <div class="col-6">
                                <label class="form-label">Électricité /an</label>
                                <div class="input-group"><span class="input-group-text">$</span>
                                    <input type="text" class="form-control money-input" name="electricite_annuel" value="<?= formatMoney($projet['electricite_annuel'], false) ?>">
                                </div>
                            </div>
                            <div class="col-6">
                                <label class="form-label">Assurances /an</label>
                                <div class="input-group"><span class="input-group-text">$</span>
                                    <input type="text" class="form-control money-input" name="assurances_annuel" value="<?= formatMoney($projet['assurances_annuel'], false) ?>">
                                </div>
                            </div>
                            <div class="col-6">
                                <label class="form-label">Déneigement /an</label>
                                <div class="input-group"><span class="input-group-text">$</span>
                                    <input type="text" class="form-control money-input" name="deneigement_annuel" value="<?= formatMoney($projet['deneigement_annuel'], false) ?>">
                                </div>
                            </div>
                            <div class="col-6">
                                <label class="form-label">Frais condo /an</label>
                                <div class="input-group"><span class="input-group-text">$</span>
                                    <input type="text" class="form-control money-input" name="frais_condo_annuel" value="<?= formatMoney($projet['frais_condo_annuel'], false) ?>">
                                </div>
                            </div>
                            <div class="col-6">
                                <label class="form-label">Hypothèque /mois</label>
                                <div class="input-group"><span class="input-group-text">$</span>
                                    <input type="text" class="form-control money-input" name="hypotheque_mensuel" value="<?= formatMoney($projet['hypotheque_mensuel'], false) ?>">
                                </div>
                            </div>
                            <div class="col-6">
                                <label class="form-label">Loyer reçu /mois</label>
                                <div class="input-group"><span class="input-group-text">$</span>
                                    <input type="text" class="form-control money-input" name="loyer_mensuel" value="<?= formatMoney($projet['loyer_mensuel'], false) ?>">
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Section Vente -->
                <div class="card">
                    <div class="card-header"><i class="bi bi-cash-stack me-1"></i>Vente</div>
                    <div class="card-body">
                        <div class="row g-2">
                            <div class="col-4">
                                <label class="form-label">Courtier</label>
                                <div class="input-group">
                                    <input type="number" class="form-control" name="taux_commission" id="taux_commission" step="0.01" value="<?= $projet['taux_commission'] ?>">
                                    <span class="input-group-text">%</span>
                                </div>
                                <small class="text-muted"><?= formatMoney($indicateurs['couts_vente']['commission']) ?> + TPS/TVQ = <?= formatMoney($indicateurs['couts_vente']['commission_ttc']) ?></small>
                            </div>
                            <div class="col-4">
                                <label class="form-label">Quittance</label>
                                <div class="input-group"><span class="input-group-text">$</span>
                                    <input type="text" class="form-control money-input" name="quittance" value="<?= formatMoney($projet['quittance'] ?? 0, false) ?>">
                                </div>
                            </div>
                            <div class="col-4">
                                <label class="form-label">Mutation</label>
                                <div class="input-group"><span class="input-group-text">$</span>
                                    <input type="text" class="form-control money-input" name="taxe_mutation" id="taxe_mutation" value="<?= formatMoney($projet['taxe_mutation'], false) ?>">
                                    <button type="button" class="btn btn-sm btn-outline-secondary px-1" onclick="calculerTaxeMutation(true)" title="Calculer selon prix achat"><i class="bi bi-calculator"></i></button>
                                </div>
                            </div>
                            <div class="col-4">
                                <label class="form-label">Solde acheteur</label>
                                <div class="input-group"><span class="input-group-text">$</span>
                                    <input type="text" class="form-control money-input" name="solde_acheteur" value="<?= formatMoney($projet['solde_acheteur'] ?? 0, false) ?>">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <input type="hidden" name="notes" value="<?= e($projet['notes']) ?>">
            </div>
        </div>

    <div class="text-end mt-2">
        <div id="baseStatusSave" class="text-muted small">
            <span id="baseIdle"><i class="bi bi-cloud-check me-1"></i>Sauvegarde auto</span>
            <span id="baseSaving" class="d-none"><i class="bi bi-arrow-repeat spin me-1"></i>Enregistrement...</span>
            <span id="baseSaved" class="d-none text-success"><i class="bi bi-check-circle me-1"></i>Enregistré!</span>
        </div>
    </div>

    <script>
    (function () {
        const form = document.getElementById('formBase');
        if (!form) return;

        let timeout = null;

        const idle = document.getElementById('baseIdle');
        const saving = document.getElementById('baseSaving');
        const saved = document.getElementById('baseSaved');

        function setState(state) {
            idle.classList.add('d-none');
            saving.classList.add('d-none');
            saved.classList.add('d-none');
            state.classList.remove('d-none');
        }

        function autoSave() {
            setState(saving);

            fetch(location.href, {
                method: 'POST',
                body: new FormData(form),
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            }).then(() => {
                setState(saved);

                // ✅ LIVE UPDATE SANS RELOAD
                if (window.BudgetBuilder && typeof BudgetBuilder.refreshIndicateurs === 'function') {
                    BudgetBuilder.refreshIndicateurs();
                }
            });
        }

        form.querySelectorAll('input, select').forEach(el => {
            el.addEventListener('change', () => {
                clearTimeout(timeout);
                timeout = setTimeout(autoSave, 400);
            });
        });
    })();
    </script>
    </form>
    </div><!-- Fin col-xxl-6 -->

    <!-- CARD 1: Détail des coûts (Achat -> Rénovation) -->
    <div class="col-lg-6 col-xxl-3">
    <div class="card h-100">
        <div class="card-header py-2">
            <i class="bi bi-calculator me-1"></i> Détail des coûts (<?= $dureeReelle ?> mois)
        </div>
        <div class="table-responsive">
            <table class="cost-table">
                <thead>
                    <tr>
                        <th class="col-label">Poste</th>
                        <th class="col-num text-info">Extrapolé</th>
                        <th class="col-num">Diff</th>
                        <th class="col-num text-success">Réel</th>
                    </tr>
                </thead>
                <tbody>
                    <!-- PRIX D'ACHAT -->
                    <tr class="section-header" data-section="achat">
                        <td colspan="4"><i class="bi bi-house me-1"></i> Achat <i class="bi bi-chevron-down toggle-icon"></i></td>
                    </tr>
                    <tr class="section-achat">
                        <td>Prix d'achat</td>
                        <td class="text-end"><?= formatMoney($projet['prix_achat']) ?></td>
                        <td class="text-end">-</td>
                        <td class="text-end"><?= formatMoney($projet['prix_achat']) ?></td>
                    </tr>
                    
                    <!-- COÛTS D'ACQUISITION -->
                    <tr class="section-header" data-section="acquisition">
                        <td colspan="4"><i class="bi bi-cart me-1"></i> Acquisition <i class="bi bi-chevron-down toggle-icon"></i></td>
                    </tr>
                    <?php if ($indicateurs['couts_acquisition']['cession'] > 0): ?>
                    <tr class="sub-item">
                        <td>Cession</td>
                        <td class="text-end"><?= formatMoney($indicateurs['couts_acquisition']['cession']) ?></td>
                        <td class="text-end">-</td>
                        <td class="text-end"><?= formatMoney($indicateurs['couts_acquisition']['cession']) ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr class="sub-item">
                        <td>Notaire</td>
                        <td class="text-end"><?= formatMoney($indicateurs['couts_acquisition']['notaire']) ?></td>
                        <td class="text-end">-</td>
                        <td class="text-end"><?= formatMoney($indicateurs['couts_acquisition']['notaire']) ?></td>
                    </tr>
                    <tr class="sub-item">
                        <td>Arpenteurs</td>
                        <td class="text-end"><?= formatMoney($indicateurs['couts_acquisition']['arpenteurs']) ?></td>
                        <td class="text-end">-</td>
                        <td class="text-end"><?= formatMoney($indicateurs['couts_acquisition']['arpenteurs']) ?></td>
                    </tr>
                    <tr class="sub-item">
                        <td>Assurance titre</td>
                        <td class="text-end"><?= formatMoney($indicateurs['couts_acquisition']['assurance_titre']) ?></td>
                        <td class="text-end">-</td>
                        <td class="text-end"><?= formatMoney($indicateurs['couts_acquisition']['assurance_titre']) ?></td>
                    </tr>
                    <?php if (($indicateurs['couts_acquisition']['solde_vendeur'] ?? 0) > 0): ?>
                    <tr class="sub-item">
                        <td>Solde vendeur</td>
                        <td class="text-end"><?= formatMoney($indicateurs['couts_acquisition']['solde_vendeur']) ?></td>
                        <td class="text-end">-</td>
                        <td class="text-end"><?= formatMoney($indicateurs['couts_acquisition']['solde_vendeur']) ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr class="total-row">
                        <td>Sous-total Acquisition</td>
                        <td class="text-end" id="detailAcquisitionTotal"><?= formatMoney($indicateurs['couts_acquisition']['total']) ?></td>
                        <td class="text-end">-</td>
                        <td class="text-end"><?= formatMoney($indicateurs['couts_acquisition']['total']) ?></td>
                    </tr>
                    
                    <!-- COÛTS RÉCURRENTS -->
                    <tr class="section-header" data-section="recurrents">
                        <td colspan="4">
                            <i class="bi bi-arrow-repeat me-1"></i> Récurrents (<?= $dureeReelle ?> mois prévu / <?= number_format($moisEcoules, 1) ?> mois écoulés)
                            <i class="bi bi-chevron-down toggle-icon"></i>
                        </td>
                    </tr>
                    <?php
                    // Calcul dynamique des coûts récurrents
                    $totalRecExtrapole = 0;
                    $totalRecReel = 0;
                    $facteurExtrapole = $dureeReelle / 12;
                    $facteurReel = $moisEcoules / 12;

                    foreach ($recurrentsTypes as $type):
                        $montant = $projetRecurrents[$type['id']] ?? 0;

                        // Calculer extrapolé et réel selon la fréquence
                        if ($type['frequence'] === 'mensuel') {
                            $extrapole = $montant * $dureeReelle;
                            $reel = $montant * $moisEcoules;
                        } elseif ($type['frequence'] === 'saisonnier') {
                            // Saisonnier = montant fixe (ex: déneigement, gazon)
                            $extrapole = $montant;
                            $reel = $montant; // Coût fixe peu importe la durée
                        } else {
                            // annuel
                            $extrapole = $montant * $facteurExtrapole;
                            $reel = $montant * $facteurReel;
                        }

                        // Loyer est un revenu (soustraire)
                        $isLoyer = ($type['code'] === 'loyer');
                        if ($isLoyer) {
                            $totalRecExtrapole -= $extrapole;
                            $totalRecReel -= $reel;
                        } else {
                            $totalRecExtrapole += $extrapole;
                            $totalRecReel += $reel;
                        }

                        $ecart = $extrapole - $reel;
                    ?>
                    <tr class="sub-item" data-recurrent-code="<?= e($type['code']) ?>">
                        <td><?= e($type['nom']) ?><?= $isLoyer ? ' <small class="text-success">(revenu)</small>' : '' ?></td>
                        <td class="text-end" id="detailRecurrent_<?= e($type['code']) ?>"><?= $isLoyer ? '-' : '' ?><?= formatMoney($extrapole) ?></td>
                        <td class="text-end <?= $ecart >= 0 ? 'positive' : 'negative' ?>" id="detailRecurrentDiff_<?= e($type['code']) ?>"><?= formatMoney($ecart) ?></td>
                        <td class="text-end" id="detailRecurrentReel_<?= e($type['code']) ?>"><?= $isLoyer ? '-' : '' ?><?= formatMoney($reel) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php
                    $ecartTotalRec = $totalRecExtrapole - $totalRecReel;
                    ?>
                    <tr class="total-row">
                        <td>Sous-total Récurrents</td>
                        <td class="text-end" id="detailRecurrentsTotal"><?= formatMoney($totalRecExtrapole) ?></td>
                        <td class="text-end <?= $ecartTotalRec >= 0 ? 'positive' : 'negative' ?>" id="detailRecurrentsDiff"><?= formatMoney($ecartTotalRec) ?></td>
                        <td class="text-end" id="detailRecurrentsReel"><?= formatMoney($totalRecReel) ?></td>
                    </tr>
                    
<!-- RÉNOVATION -->
<tr class="section-header" data-section="renovation">
    <td colspan="4">
        <i class="bi bi-tools me-1"></i>
        Rénovation (+ <?= $projet['taux_contingence'] ?>% contingence)
        <i class="bi bi-chevron-down toggle-icon"></i>
    </td>
</tr>

<!-- RÉNOVATION_DYNAMIC_START -->
<?php
                    $totalBudgetReno = 0;
                    $totalReelReno = 0;
                    $totalEcartReno = 0; // Somme de tous les écarts (positifs compensent négatifs)

                    // NOUVEAU SYSTÈME: Afficher par étape si le budget-builder est utilisé OU s'il y a des dépenses par étape
                    if (!empty($budgetParEtape) || !empty($depensesParEtape)):
                        // D'abord afficher les étapes avec budget
                        foreach ($budgetParEtape as $etapeId => $etape):
                            $budgetHT = $etape['total'];
                            // Dépenses réelles par étape (factures avec cette étape)
                            $depense = $depensesParEtape[$etapeId]['total'] ?? 0;
                            // Afficher même si budget = 0 mais qu'il y a des dépenses
                            if ($budgetHT == 0 && $depense == 0) continue;
                            $ecart = $budgetHT - $depense;
                            $totalBudgetReno += $budgetHT;
                            $totalReelReno += $depense;
                            $totalEcartReno += $ecart;
                    ?>
                    <tr class="sub-item detail-etape-row" data-etape-id="<?= $etapeId ?>">
                        <td><i class="bi bi-bookmark-fill me-1 text-muted"></i><?= e($etape['nom']) ?></td>
                        <td class="text-end detail-etape-budget"><?= formatMoney($budgetHT) ?></td>
                        <td class="text-end detail-etape-diff <?= $ecart >= 0 ? 'positive' : 'negative' ?>"><?= $ecart != 0 ? formatMoney($ecart) : '-' ?></td>
                        <td class="text-end detail-etape-reel"><?= formatMoney($depense) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php
                    // Afficher aussi les étapes qui ont des dépenses mais pas de budget
                    foreach ($depensesParEtape as $etapeId => $depenseEtape):
                        if (isset($budgetParEtape[$etapeId])) continue; // Déjà affiché
                        $depense = $depenseEtape['total'];
                        if ($depense == 0) continue;
                        $ecart = 0 - $depense;
                        $totalReelReno += $depense;
                        $totalEcartReno += $ecart;
                    ?>
                    <tr class="sub-item detail-etape-row" data-etape-id="<?= $etapeId ?>">
                        <td><i class="bi bi-bookmark-fill me-1 text-muted"></i><?= e($depenseEtape['nom']) ?></td>
                        <td class="text-end detail-etape-budget">-</td>
                        <td class="text-end detail-etape-diff negative"><?= formatMoney($ecart) ?></td>
                        <td class="text-end detail-etape-reel"><?= formatMoney($depense) ?></td>
                    </tr>
                    <?php endforeach; ?>

                    <!-- MAIN D'ŒUVRE (Déplacée après les étapes pour éviter le saut) -->
                    <?php
                    $diffMO = $moExtrapole['cout'] - $moReel['cout'];
                    if ($moExtrapole['heures'] > 0 || $moReel['heures'] > 0):
                    ?>
                    <tr class="sub-item labor-row">
                        <td>
                            <i class="bi bi-person-fill me-1"></i>Main d'œuvre
                            <small class="d-block opacity-75">
                                Planifié: <?= number_format($moExtrapole['heures'], 0) ?>h (<?= $moExtrapole['jours'] ?>j) |
                                Réel: <?= number_format($moReel['heures'], 1) ?>h
                            </small>
                        </td>
                        <td class="text-end"><?= formatMoney($moExtrapole['cout']) ?></td>
                        <td class="text-end <?= $diffMO >= 0 ? 'positive' : 'negative' ?>"><?= formatMoney($diffMO) ?></td>
                        <td class="text-end"><?= formatMoney($moReel['cout']) ?></td>
                    </tr>
                    <?php endif; ?>

                    <?php else: ?>
                    <?php
                    // ANCIEN SYSTÈME: Fallback sur les catégories
                    foreach ($categories as $cat):
                        $budgetUnit = $budgets[$cat['id']] ?? 0;
                        $depense = $depenses[$cat['id']] ?? 0;
                        if ($budgetUnit == 0 && $depense == 0) continue;
                        $qteGroupe = $projetGroupes[$cat['groupe']] ?? 1;

                        // NOTE: $budgetUnit vient de budgets.montant_extrapole qui
                        // contient DÉJÀ le multiplicateur de groupe (via syncBudgetsFromProjetItems)
                        // Donc on NE multiplie PAS à nouveau par $qteGroupe
                        $budgetHT = $budgetUnit;

                        // Afficher en HT car TPS/TVQ sont montrés séparément
                        $budgetAffiche = $budgetHT;
                        $ecart = $budgetAffiche - $depense;
                        $totalBudgetReno += $budgetHT;
                        $totalReelReno += $depense;
                        // Accumuler tous les écarts (positifs et négatifs)
                        $totalEcartReno += $ecart;
                    ?>
                    <tr class="sub-item detail-cat-row" data-cat-id="<?= $cat['id'] ?>">
                        <td>
                            <?= e($cat['nom']) ?>
                            <?php if ($qteGroupe > 1): ?>
                                <small class="text-muted detail-qte-groupe" data-groupe="<?= htmlspecialchars($cat['groupe']) ?>">(×<?= $qteGroupe ?>)</small>
                            <?php endif; ?>
                        </td>
                        <td class="text-end detail-cat-budget" id="detailCatBudget_<?= $cat['id'] ?>"><?= formatMoney($budgetAffiche) ?></td>
                        <td class="text-end detail-cat-diff <?= $ecart >= 0 ? 'positive' : 'negative' ?>"><?= $ecart != 0 ? formatMoney($ecart) : '-' ?></td>
                        <td class="text-end detail-cat-reel"><?= formatMoney($depense) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                    
                    
                    <?php
                    $ecartContingence = $indicateurs['renovation']['contingence'] - $contingenceUtilisee;
                    ?>
                    <tr class="sub-item">
                        <td>Contingence <?= $projet['taux_contingence'] ?>%</td>
                        <td class="text-end" id="detailContingence"><?= formatMoney($indicateurs['renovation']['contingence']) ?></td>
                        <td class="text-end <?= $ecartContingence >= 0 ? 'positive' : 'negative' ?>"><?= formatMoney($ecartContingence) ?></td>
                        <td class="text-end"><?= formatMoney($contingenceUtilisee) ?></td>
                    </tr>

                    <?php
                    $diffTPS = $indicateurs['renovation']['tps'] - $indicateurs['renovation']['reel_tps'];
                    $diffTVQ = $indicateurs['renovation']['tvq'] - $indicateurs['renovation']['reel_tvq'];
                    ?>
                    <tr class="sub-item">
                        <td>TPS 5%</td>
                        <td class="text-end" id="detailTPS"><?= formatMoney($indicateurs['renovation']['tps']) ?></td>
                        <td class="text-end <?= $diffTPS >= 0 ? 'positive' : 'negative' ?>"><?= formatMoney($diffTPS) ?></td>
                        <td class="text-end"><?= formatMoney($indicateurs['renovation']['reel_tps']) ?></td>
                    </tr>

                    <tr class="sub-item">
                        <td>TVQ 9.975%</td>
                        <td class="text-end" id="detailTVQ"><?= formatMoney($indicateurs['renovation']['tvq']) ?></td>
                        <td class="text-end <?= $diffTVQ >= 0 ? 'positive' : 'negative' ?>"><?= formatMoney($diffTVQ) ?></td>
                        <td class="text-end"><?= formatMoney($indicateurs['renovation']['reel_tvq']) ?></td>
                    </tr>

                    <?php
                    // Réel TTC = factures TTC + main d'œuvre réelle
                    $renoReelTTC = $indicateurs['renovation']['reel_ttc'] + $indicateurs['main_doeuvre']['cout'];
                    // Budget TTC = budget extrapolé TTC + main d'œuvre planifiée
                    $renoBudgetTTC = $indicateurs['renovation']['budget_ttc'] + $indicateurs['main_doeuvre_extrapole']['cout'];
                    $diffReno = $renoBudgetTTC - $renoReelTTC;
                    ?>
                    <tr class="total-row">
                        <td>Sous-total Rénovation (avec taxes)</td>
                        <td class="text-end" id="detailRenoTotal"><?= formatMoney($renoBudgetTTC) ?></td>
                        <td class="text-end <?= $diffReno >= 0 ? 'positive' : 'negative' ?>"><?= formatMoney($diffReno) ?></td>
                        <td class="text-end"><?= formatMoney($renoReelTTC) ?></td>
                    </tr>

                </tbody>
            </table>
        </div>
    </div><!-- Fin card Détail des coûts -->
    </div><!-- Fin col-xxl-3 -->

    <!-- CARD 2: Vente séparée (Vente -> Profit) -->
    <div class="col-lg-6 col-xxl-3">
    <div class="card h-100">
        <div class="card-header py-2">
            <i class="bi bi-shop me-1"></i> Vente
        </div>
        <div class="table-responsive">
            <table class="cost-table">
                <thead>
                    <tr>
                        <th class="col-label">Poste</th>
                        <th class="col-num text-info">Extrapolé</th>
                        <th class="col-num">Diff</th>
                        <th class="col-num text-success">Réel</th>
                    </tr>
                </thead>
                <tbody>
                    <!-- COÛTS DE VENTE -->
                    <tr class="section-header" data-section="vente">
                        <td colspan="4"><i class="bi bi-shop me-1"></i> Vente <i class="bi bi-chevron-down toggle-icon"></i></td>
                    </tr>
                    <?php
                    $interetsPrevu = $indicateurs['couts_vente']['interets'];
                    $interetsReel = $indicateurs['couts_vente']['interets_reel'] ?? $interetsPrevu;
                    $ecartInterets = $interetsPrevu - $interetsReel;
                    ?>
                    <tr class="sub-item">
                        <td>Intérêts (<?php
                            if (!empty($indicateurs['preteurs'])) {
                                $tauxList = [];
                                foreach ($indicateurs['preteurs'] as $p) {
                                    $tauxList[] = $p['taux'] . '%';
                                }
                                echo implode(', ', $tauxList);
                            } else {
                                echo $projet['taux_interet'] . '%';
                            }
                        ?>)
                        <small class="d-block opacity-75">
                            Prévu: <?= $indicateurs['mois_prevu'] ?> mois | Réel: <?= $indicateurs['mois_reel'] ?> mois
                        </small>
                        </td>
                        <td class="text-end"><?= formatMoney($interetsPrevu) ?></td>
                        <td class="text-end <?= $ecartInterets >= 0 ? 'positive' : 'negative' ?>"><?= formatMoney($ecartInterets) ?></td>
                        <td class="text-end"><?= formatMoney($interetsReel) ?></td>
                    </tr>
                    <tr class="sub-item">
                        <td>Commission courtier <?= $projet['taux_commission'] ?>% + taxes</td>
                        <td class="text-end"><?= formatMoney($indicateurs['couts_vente']['commission_ttc']) ?></td>
                        <td class="text-end">-</td>
                        <td class="text-end"><?= formatMoney($indicateurs['couts_vente']['commission_ttc']) ?></td>
                    </tr>
                    <tr class="sub-item">
                        <td>Quittance</td>
                        <td class="text-end"><?= formatMoney($indicateurs['couts_vente']['quittance']) ?></td>
                        <td class="text-end">-</td>
                        <td class="text-end"><?= formatMoney($indicateurs['couts_vente']['quittance']) ?></td>
                    </tr>
                    <?php if (($indicateurs['couts_vente']['taxe_mutation'] ?? 0) > 0): ?>
                    <tr class="sub-item">
                        <td>Taxe mutation</td>
                        <td class="text-end"><?= formatMoney($indicateurs['couts_vente']['taxe_mutation']) ?></td>
                        <td class="text-end">-</td>
                        <td class="text-end"><?= formatMoney($indicateurs['couts_vente']['taxe_mutation']) ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if (($indicateurs['couts_vente']['solde_acheteur'] ?? 0) > 0): ?>
                    <tr class="sub-item">
                        <td>Solde acheteur</td>
                        <td class="text-end"><?= formatMoney($indicateurs['couts_vente']['solde_acheteur']) ?></td>
                        <td class="text-end">-</td>
                        <td class="text-end"><?= formatMoney($indicateurs['couts_vente']['solde_acheteur']) ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php
                    // Calculer le sous-total vente réel (avec intérêts réels)
                    $totalVentePrevu = $indicateurs['couts_vente']['total'];
                    $totalVenteReel = $indicateurs['couts_vente']['commission_ttc']
                        + ($indicateurs['couts_vente']['interets_reel'] ?? $indicateurs['couts_vente']['interets'])
                        + $indicateurs['couts_vente']['quittance']
                        + ($indicateurs['couts_vente']['taxe_mutation'] ?? 0)
                        - ($indicateurs['couts_vente']['solde_acheteur'] ?? 0);
                    $ecartVente = $totalVentePrevu - $totalVenteReel;
                    ?>
                    <tr class="total-row">
                        <td>Sous-total Vente</td>
                        <td class="text-end" id="detailVenteTotal"><?= formatMoney($totalVentePrevu) ?></td>
                        <td class="text-end <?= $ecartVente >= 0 ? 'positive' : 'negative' ?>"><?= formatMoney($ecartVente) ?></td>
                        <td class="text-end"><?= formatMoney($totalVenteReel) ?></td>
                    </tr>
                    
                    <!-- GRAND TOTAL -->
                    <?php $diffTotal = $indicateurs['cout_total_projet'] - $indicateurs['cout_total_reel']; ?>
                    <tr class="grand-total">
                        <td>COÛT TOTAL PROJET</td>
                        <td class="text-end" id="detailCoutTotalProjet"><?= formatMoney($indicateurs['cout_total_projet']) ?></td>
                        <td class="text-end" style="color:<?= $diffTotal >= 0 ? '#90EE90' : '#ffcccc' ?>"><?= formatMoney($diffTotal) ?></td>
                        <td class="text-end"><?= formatMoney($indicateurs['cout_total_reel']) ?></td>
                    </tr>
                    
                    <tr>
                        <td>Valeur potentielle de vente</td>
                        <td class="text-end"><?= formatMoney($indicateurs['valeur_potentielle']) ?></td>
                        <td class="text-end">-</td>
                        <td class="text-end"><?= formatMoney($indicateurs['valeur_potentielle']) ?></td>
                    </tr>
                    
                    <?php $diffEquite = $indicateurs['equite_reelle'] - $indicateurs['equite_potentielle']; ?>
                    <tr class="total-row">
                        <td>ÉQUITÉ / PROFIT</td>
                        <td class="text-end"><?= formatMoney($indicateurs['equite_potentielle']) ?></td>
                        <td class="text-end" style="color:<?= $diffEquite >= 0 ? '#90EE90' : '#ffcccc' ?>"><?= $diffEquite >= 0 ? '+' : '' ?><?= formatMoney($diffEquite) ?></td>
                        <td class="text-end"><?= formatMoney($indicateurs['equite_reelle']) ?></td>
                    </tr>
                    
                    <!-- PARTAGE DES PROFITS -->
                    <?php if (!empty($indicateurs['preteurs']) || !empty($indicateurs['investisseurs'])): ?>
                    <tr class="section-header" data-section="partage">
                        <td colspan="4"><i class="bi bi-pie-chart me-1"></i> Partage des profits <i class="bi bi-chevron-down toggle-icon"></i></td>
                    </tr>

                    <?php
                    // Calcul du profit net EXTRAPOLÉ (avant partage) = équité potentielle
                    $profitNetAvantPartage = $indicateurs['equite_potentielle'];
                    // Calcul du profit net RÉEL (avant partage) = équité réelle
                    $profitNetAvantPartageReel = $indicateurs['equite_reelle'];

                    // Déterminer l'année fiscale (basée sur date_vente ou année courante)
                    $anneeFiscale = !empty($projet['date_vente'])
                        ? (int) date('Y', strtotime($projet['date_vente']))
                        : (int) date('Y');

                    // Calculer le profit cumulatif AVANT ce projet (autres projets vendus cette année)
                    $cumulatifAvant = calculerProfitCumulatifAnneeFiscale($pdo, $anneeFiscale, $projet['id']);
                    $profitCumulatifAvant = $cumulatifAvant['total'];

                    // Calcul impôt EXTRAPOLÉ avec taux dynamique selon cumulatif
                    $impotExtrapole = calculerImpotAvecCumulatif($profitNetAvantPartage, $profitCumulatifAvant);
                    $impotAPayer = $impotExtrapole['impot'];
                    $tauxAfficheExtrapole = $impotExtrapole['taux_affiche'];
                    $profitApresImpot = $profitNetAvantPartage - $impotAPayer;

                    // Calcul impôt RÉEL avec taux dynamique selon cumulatif
                    $impotReel = calculerImpotAvecCumulatif($profitNetAvantPartageReel, $profitCumulatifAvant);
                    $impotAPayerReel = $impotReel['impot'];
                    $tauxAfficheReel = $impotReel['taux_affiche'];
                    $profitApresImpotReel = $profitNetAvantPartageReel - $impotAPayerReel;

                    // Info supplémentaire pour tooltip
                    $seuilRestant = $impotExtrapole['detail']['seuil_restant'];
                    ?>

                    <!-- Prêteurs (capital + intérêts à rembourser) -->
                    <?php if (!empty($indicateurs['preteurs'])): ?>
                    <?php foreach ($indicateurs['preteurs'] as $preteur):
                        // Intérêts PRÉVUS (basés sur mois_prevu)
                        $interetsPrevu = $preteur['interets_total'];
                        $totalDuPrevu = $preteur['montant'] + $interetsPrevu;

                        // Intérêts RÉELS (basés sur mois_reel)
                        $tauxMensuel = $preteur['taux'] / 100 / 12;
                        $moisReel = $indicateurs['mois_reel'];
                        $interetsReel = $preteur['montant'] * (pow(1 + $tauxMensuel, $moisReel) - 1);
                        $totalDuReel = $preteur['montant'] + $interetsReel;

                        $ecartPreteur = $totalDuPrevu - $totalDuReel;
                    ?>
                    <tr class="sub-item">
                        <td>
                            <i class="bi bi-bank text-warning me-1"></i><?= e($preteur['nom']) ?>
                            <?php if ($preteur['taux'] > 0): ?>
                                <small class="text-muted">(<?= $preteur['taux'] ?>%)</small>
                            <?php else: ?>
                                <small class="text-muted">(prêt 0%)</small>
                            <?php endif; ?>
                        </td>
                        <td class="text-end" style="color: #e74c3c;">-<?= formatMoney($totalDuPrevu) ?></td>
                        <td class="text-end <?= $ecartPreteur >= 0 ? 'positive' : 'negative' ?>"><?= formatMoney($ecartPreteur) ?></td>
                        <td class="text-end" style="color: #e74c3c;">-<?= formatMoney($totalDuReel) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>

                    <!-- PROFIT NET (avant partage) -->
                    <?php $diffProfitNet = $profitNetAvantPartageReel - $profitNetAvantPartage; ?>
                    <tr class="total-row">
                        <td>PROFIT NET (avant partage)</td>
                        <td class="text-end"><?= formatMoney($profitNetAvantPartage) ?></td>
                        <td class="text-end" style="color:<?= $diffProfitNet >= 0 ? '#90EE90' : '#ffcccc' ?>"><?= $diffProfitNet >= 0 ? '+' : '' ?><?= formatMoney($diffProfitNet) ?></td>
                        <td class="text-end"><?= formatMoney($profitNetAvantPartageReel) ?></td>
                    </tr>

                    <!-- Impôt à payer -->
                    <?php $diffImpot = $impotAPayerReel - $impotAPayer; ?>
                    <tr class="sub-item text-danger">
                        <td>
                            <i class="bi bi-bank2 me-1"></i>Impôt à payer
                            <small class="text-muted">(<?= $tauxAfficheExtrapole ?>)</small>
                            <?php if ($profitCumulatifAvant > 0): ?>
                            <i class="bi bi-info-circle ms-1" data-bs-toggle="tooltip" data-bs-placement="right"
                               title="Profit cumulatif <?= $anneeFiscale ?>: <?= formatMoney($profitCumulatifAvant) ?> | Seuil DPE restant: <?= formatMoney($seuilRestant) ?>"></i>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">-<?= formatMoney($impotAPayer) ?></td>
                        <td class="text-end" style="color:<?= $diffImpot <= 0 ? '#90EE90' : '#ffcccc' ?>"><?= $diffImpot >= 0 ? '+' : '' ?><?= formatMoney($diffImpot) ?></td>
                        <td class="text-end">-<?= formatMoney($impotAPayerReel) ?></td>
                    </tr>

                    <!-- PROFIT APRÈS IMPÔT -->
                    <?php
                    $profitNegatif = $profitApresImpot < 0;
                    $profitReelNegatif = $profitApresImpotReel < 0;
                    $profitRowClass = $profitNegatif ? 'loss-row' : 'profit-row';
                    $diffProfitApres = $profitApresImpotReel - $profitApresImpot;
                    ?>
                    <tr class="<?= $profitRowClass ?>">
                        <td><strong><i class="bi bi-cash-stack me-1"></i>PROFIT APRÈS IMPÔT</strong></td>
                        <td class="text-end"><strong><?= formatMoney($profitApresImpot) ?></strong></td>
                        <td class="text-end" style="color:<?= $diffProfitApres >= 0 ? '#90EE90' : '#ffcccc' ?>"><?= $diffProfitApres >= 0 ? '+' : '' ?><?= formatMoney($diffProfitApres) ?></td>
                        <td class="text-end"><strong><?= formatMoney($profitApresImpotReel) ?></strong></td>
                    </tr>

                    <!-- Ligne miroir de séparation -->
                    <tr>
                        <td colspan="4" style="padding: 0; height: 3px; background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);"></td>
                    </tr>

                    <!-- Division entre investisseurs -->
                    <?php if (!empty($indicateurs['investisseurs'])): ?>
                    <tr class="section-header" data-section="division">
                        <td colspan="4"><i class="bi bi-people me-1"></i> Division entre investisseurs <i class="bi bi-chevron-down toggle-icon"></i></td>
                    </tr>
                    <?php foreach ($indicateurs['investisseurs'] as $inv): ?>
                    <?php
                    // Calculer la part de chaque investisseur sur le profit après impôt (extrapolé et réel)
                    $partInvestisseur = $profitApresImpot * ($inv['pourcentage'] / 100);
                    $partInvestisseurReel = $profitApresImpotReel * ($inv['pourcentage'] / 100);
                    $isNegatif = $partInvestisseur < 0;
                    $invRowClass = $isNegatif ? 'loss-row' : 'profit-row';
                    $prefix = $isNegatif ? '' : '+';
                    $prefixReel = $partInvestisseurReel < 0 ? '' : '+';
                    $diffInv = $partInvestisseurReel - $partInvestisseur;
                    ?>
                    <tr class="<?= $invRowClass ?>">
                        <td><i class="bi bi-person text-info me-1"></i><?= e($inv['nom']) ?> (<?= number_format($inv['pourcentage'], 1) ?>%)</td>
                        <td class="text-end"><?= $prefix ?><?= formatMoney($partInvestisseur) ?></td>
                        <td class="text-end" style="color:<?= $diffInv >= 0 ? '#90EE90' : '#ffcccc' ?>"><?= $diffInv >= 0 ? '+' : '' ?><?= formatMoney($diffInv) ?></td>
                        <td class="text-end"><?= $prefixReel ?><?= formatMoney($partInvestisseurReel) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div><!-- Fin card Vente -->
    </div><!-- Fin col-xxl-3 -->
    </div><!-- Fin row xxl -->

    <!-- Script pour mettre à jour les 4 jauges Écart Budget -->
    <?php
    // Valeurs par défaut si les variables n'existent pas (hors du bloc prêteurs/investisseurs)
    $gaugeRecurrentExtrapole = isset($totalRecExtrapole) ? $totalRecExtrapole : 0;
    $gaugeRecurrentReel = isset($totalRecReel) ? $totalRecReel : 0;
    $gaugeRenovationExtrapole = isset($renoBudgetTTC) ? $renoBudgetTTC : 0;
    $gaugeRenovationReel = isset($renoReelTTC) ? $renoReelTTC : 0;
    $gaugeVenteExtrapole = isset($totalVentePrevu) ? $totalVentePrevu : 0;
    $gaugeVenteReel = isset($totalVenteReel) ? $totalVenteReel : 0;
    // Pour le général (profit), utiliser équité si pas de prêteurs/investisseurs
    $gaugeGeneralExtrapole = isset($profitApresImpot) ? $profitApresImpot : ($indicateurs['equite_potentielle'] ?? 0);
    $gaugeGeneralReel = isset($profitApresImpotReel) ? $profitApresImpotReel : ($indicateurs['equite_reelle'] ?? 0);
    ?>
    <script>
    (function() {
        // Fonction pour mettre à jour une mini-jauge
        // Pour les coûts: écart positif (budget > réel) = économie = bien (droite)
        // Pour le profit: écart positif (réel > budget) = plus de profit = bien (droite)
        function updateMiniGauge(indicatorId, valueId, extrapole, reel, isProfit = false) {
            const indicator = document.getElementById(indicatorId);
            const valueEl = document.getElementById(valueId);
            if (!indicator || !valueEl) return;

            // Pour les coûts: diff = extrapole - reel (positif = économie)
            // Pour le profit: diff = reel - extrapole (positif = plus de profit)
            const diff = isProfit ? (reel - extrapole) : (extrapole - reel);

            // Échelle max
            const maxDiff = Math.max(Math.abs(extrapole) * 0.5, 5000);

            // Position: centre = 50%, droite = bien, gauche = mal
            let position = 50 + (diff / maxDiff) * 45;
            position = Math.max(5, Math.min(95, position));

            // Animer
            setTimeout(() => {
                indicator.style.left = position + '%';
            }, 100);

            // Afficher la valeur
            const absVal = Math.abs(diff);
            let formatted;
            if (absVal >= 1000) {
                formatted = (absVal / 1000).toFixed(1) + 'k';
            } else {
                formatted = Math.round(absVal) + '$';
            }

            if (diff > 0) {
                valueEl.textContent = '+' + formatted;
                valueEl.className = 'mini-gauge-value positive';
            } else if (diff < 0) {
                valueEl.textContent = '-' + formatted;
                valueEl.className = 'mini-gauge-value negative';
            } else {
                valueEl.textContent = '=';
                valueEl.className = 'mini-gauge-value neutral';
            }
        }

        // Données des 4 catégories
        const gaugeData = {
            recurrent: {
                extrapole: <?= json_encode($gaugeRecurrentExtrapole) ?>,
                reel: <?= json_encode($gaugeRecurrentReel) ?>
            },
            renovation: {
                extrapole: <?= json_encode($gaugeRenovationExtrapole) ?>,
                reel: <?= json_encode($gaugeRenovationReel) ?>
            },
            vente: {
                extrapole: <?= json_encode($gaugeVenteExtrapole) ?>,
                reel: <?= json_encode($gaugeVenteReel) ?>
            },
            general: {
                extrapole: <?= json_encode($gaugeGeneralExtrapole) ?>,
                reel: <?= json_encode($gaugeGeneralReel) ?>
            }
        };

        console.log('Gauge data:', gaugeData);

        // Mettre à jour chaque jauge
        updateMiniGauge('gaugeIndicatorRecurrent', 'gaugeValueRecurrent', gaugeData.recurrent.extrapole, gaugeData.recurrent.reel, false);
        updateMiniGauge('gaugeIndicatorRenovation', 'gaugeValueRenovation', gaugeData.renovation.extrapole, gaugeData.renovation.reel, false);
        updateMiniGauge('gaugeIndicatorVente', 'gaugeValueVente', gaugeData.vente.extrapole, gaugeData.vente.reel, false);
        updateMiniGauge('gaugeIndicatorGeneral', 'gaugeValueGeneral', gaugeData.general.extrapole, gaugeData.general.reel, true);
    })();
    </script>

    </div><!-- Fin base-dynamic-content -->
    </div><!-- Fin TAB BASE -->
<?php
if ($isPartialBase) {
    $html = ob_get_clean();

    // extraire uniquement le contenu dynamique
    if (preg_match('/<div id="base-dynamic-content">(.+?)<\/div><!-- Fin base-dynamic-content -->/s', $html, $m)) {
        echo $m[1];
    }
    exit;
}
?>
