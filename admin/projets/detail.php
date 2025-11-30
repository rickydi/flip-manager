<?php
/**
 * Détail du projet - Admin
 * Flip Manager
 */

require_once '../../config.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../includes/calculs.php';

// Vérifier que l'utilisateur est admin
requireAdmin();

// Récupérer le projet
$projetId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$projet = getProjetById($pdo, $projetId);

if (!$projet) {
    setFlashMessage('danger', 'Projet non trouvé.');
    redirect('/admin/projets/liste.php');
}

$pageTitle = $projet['nom'];

// Calculer tous les indicateurs
$indicateurs = calculerIndicateursProjet($pdo, $projet);

// Calculer la durée réelle (même logique que dans calculs.php)
$dureeReelle = (int)$projet['temps_assume_mois'];
if (!empty($projet['date_vente']) && !empty($projet['date_acquisition'])) {
    $dateAchat = new DateTime($projet['date_acquisition']);
    $dateVente = new DateTime($projet['date_vente']);
    $diff = $dateAchat->diff($dateVente);
    $dureeReelle = ($diff->y * 12) + $diff->m + ($diff->d > 15 ? 1 : 0);
    $dureeReelle = max(1, $dureeReelle);
}

// Récupérer les catégories avec budgets et dépenses
$categories = getCategories($pdo);
$budgets = getBudgetsParCategorie($pdo, $projetId);
$depenses = calculerDepensesParCategorie($pdo, $projetId);

include '../../includes/header.php';
?>

<div class="container-fluid">
    <!-- En-tête -->
    <div class="page-header">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="/admin/index.php">Tableau de bord</a></li>
                <li class="breadcrumb-item"><a href="/admin/projets/liste.php">Projets</a></li>
                <li class="breadcrumb-item active"><?= e($projet['nom']) ?></li>
            </ol>
        </nav>
        <div class="d-flex justify-content-between align-items-start">
            <div>
                <div class="d-flex align-items-center flex-wrap gap-2 mb-1">
                    <h1 class="mb-0"><?= e($projet['nom']) ?></h1>
                    <span class="badge <?= getStatutProjetClass($projet['statut']) ?>">
                        <?= getStatutProjetLabel($projet['statut']) ?>
                    </span>
                    <a href="/admin/projets/modifier.php?id=<?= $projet['id'] ?>" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-pencil me-1"></i>Modifier
                    </a>
                </div>
                <p class="text-muted mb-0">
                    <i class="bi bi-geo-alt me-1"></i>
                    <?= e($projet['adresse']) ?>, <?= e($projet['ville']) ?>
                </p>
            </div>
            <button onclick="window.print()" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-printer me-1"></i>Imprimer
            </button>
        </div>
    </div>
    
    <?php displayFlashMessage(); ?>
    
    <!-- Indicateurs principaux -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-label">Coûts fixes total</div>
            <div class="stat-value"><?= formatMoney($indicateurs['couts_fixes_totaux']) ?></div>
            <div class="stat-percent"><?= formatPercent($indicateurs['pourcentages']['couts_fixes']) ?></div>
        </div>
        <div class="stat-card warning">
            <div class="stat-label">Rénovation extrapolée</div>
            <div class="stat-value"><?= formatMoney($indicateurs['renovation']['budget']) ?></div>
            <div class="stat-percent"><?= formatPercent($indicateurs['pourcentages']['renovation']) ?></div>
        </div>
        <div class="stat-card primary">
            <div class="stat-label">Valeur potentielle</div>
            <div class="stat-value"><?= formatMoney($indicateurs['valeur_potentielle']) ?></div>
        </div>
        <div class="stat-card success">
            <div class="stat-label">Équité potentielle</div>
            <div class="stat-value"><?= formatMoney($indicateurs['equite_potentielle']) ?></div>
        </div>
        <div class="stat-card info">
            <div class="stat-label">ROI @ Leverage</div>
            <div class="stat-value"><?= formatPercent($indicateurs['roi_leverage']) ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">ROI All Cash</div>
            <div class="stat-value"><?= formatPercent($indicateurs['roi_all_cash']) ?></div>
        </div>
    </div>
    
    <!-- GRAPHIQUES VUE D'ENSEMBLE -->
    <div class="financial-section">
        <h5><i class="bi bi-graph-up me-2"></i>Vue d'ensemble du projet</h5>
        <div class="row">
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header text-center">
                        📈 Coûts vs Valeur
                        <small class="d-block text-muted" style="font-size:0.7rem">Rouge = coûts cumulés, Vert = valeur de vente</small>
                    </div>
                    <div class="card-body">
                        <canvas id="chartCouts" height="200"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header text-center">
                        💰 Dépenses mensuelles
                        <small class="d-block text-muted" style="font-size:0.7rem">Factures approuvées par mois</small>
                    </div>
                    <div class="card-body">
                        <canvas id="chartBudget" height="200"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header text-center">
                        📊 Budget vs Dépensé
                        <small class="d-block text-muted" style="font-size:0.7rem">Bleu = prévu, Rouge = réel</small>
                    </div>
                    <div class="card-body">
                        <canvas id="chartProfits" height="200"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="row">
        <!-- Colonne gauche -->
        <div class="col-lg-6">
            <!-- Coûts d'acquisition -->
            <div class="financial-section">
                <h5><i class="bi bi-cart me-2"></i>Coûts d'acquisition</h5>
                <table class="financial-table">
                    <tbody>
                        <?php if ($indicateurs['couts_acquisition']['cession'] > 0): ?>
                        <tr>
                            <td>Achat de cession</td>
                            <td class="amount"><?= formatMoney($indicateurs['couts_acquisition']['cession']) ?></td>
                        </tr>
                        <?php endif; ?>
                        <tr>
                            <td>Notaire</td>
                            <td class="amount"><?= formatMoney($indicateurs['couts_acquisition']['notaire']) ?></td>
                        </tr>
                        <tr>
                            <td>Taxe de mutation</td>
                            <td class="amount"><?= formatMoney($indicateurs['couts_acquisition']['taxe_mutation']) ?></td>
                        </tr>
                        <tr>
                            <td>Arpenteurs</td>
                            <td class="amount"><?= formatMoney($indicateurs['couts_acquisition']['arpenteurs']) ?></td>
                        </tr>
                        <tr>
                            <td>Assurance titre</td>
                            <td class="amount"><?= formatMoney($indicateurs['couts_acquisition']['assurance_titre']) ?></td>
                        </tr>
                        <tr class="total-row">
                            <td><strong>Total</strong></td>
                            <td class="amount"><strong><?= formatMoney($indicateurs['couts_acquisition']['total']) ?></strong></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            <!-- Coûts récurrents -->
            <div class="financial-section">
                <h5><i class="bi bi-arrow-repeat me-2"></i>Coûts récurrents (<?= $dureeReelle ?> mois)</h5>
                <table class="financial-table">
                    <thead>
                        <tr>
                            <th></th>
                            <th class="text-end">Annuel</th>
                            <th class="text-end">Extrapolé</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>Taxes municipales</td>
                            <td class="amount"><?= formatMoney($indicateurs['couts_recurrents']['taxes_municipales']['annuel']) ?></td>
                            <td class="amount"><?= formatMoney($indicateurs['couts_recurrents']['taxes_municipales']['extrapole']) ?></td>
                        </tr>
                        <tr>
                            <td>Taxes scolaires</td>
                            <td class="amount"><?= formatMoney($indicateurs['couts_recurrents']['taxes_scolaires']['annuel']) ?></td>
                            <td class="amount"><?= formatMoney($indicateurs['couts_recurrents']['taxes_scolaires']['extrapole']) ?></td>
                        </tr>
                        <tr>
                            <td>Électricité</td>
                            <td class="amount"><?= formatMoney($indicateurs['couts_recurrents']['electricite']['annuel']) ?></td>
                            <td class="amount"><?= formatMoney($indicateurs['couts_recurrents']['electricite']['extrapole']) ?></td>
                        </tr>
                        <tr>
                            <td>Assurances</td>
                            <td class="amount"><?= formatMoney($indicateurs['couts_recurrents']['assurances']['annuel']) ?></td>
                            <td class="amount"><?= formatMoney($indicateurs['couts_recurrents']['assurances']['extrapole']) ?></td>
                        </tr>
                        <tr>
                            <td>Déneigement</td>
                            <td class="amount"><?= formatMoney($indicateurs['couts_recurrents']['deneigement']['annuel']) ?></td>
                            <td class="amount"><?= formatMoney($indicateurs['couts_recurrents']['deneigement']['extrapole']) ?></td>
                        </tr>
                        <tr>
                            <td>Frais condo</td>
                            <td class="amount"><?= formatMoney($indicateurs['couts_recurrents']['frais_condo']['annuel']) ?></td>
                            <td class="amount"><?= formatMoney($indicateurs['couts_recurrents']['frais_condo']['extrapole']) ?></td>
                        </tr>
                        <tr>
                            <td>Hypothèque</td>
                            <td class="amount"><?= formatMoney($indicateurs['couts_recurrents']['hypotheque']['mensuel']) ?>/mois</td>
                            <td class="amount"><?= formatMoney($indicateurs['couts_recurrents']['hypotheque']['extrapole']) ?></td>
                        </tr>
                        <tr class="total-row">
                            <td><strong>Total</strong></td>
                            <td></td>
                            <td class="amount"><strong><?= formatMoney($indicateurs['couts_recurrents']['total']) ?></strong></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            <!-- Coûts de vente -->
            <div class="financial-section">
                <h5><i class="bi bi-shop me-2"></i>Coûts de vente</h5>
                <table class="financial-table">
                    <tbody>
                        <tr>
                            <td>Intérêts (<?= $dureeReelle ?> mois @ <?= $projet['taux_interet'] ?>%)</td>
                            <td class="amount"><?= formatMoney($indicateurs['couts_vente']['interets']) ?></td>
                        </tr>
                        <?php 
                        $commissionHT = $indicateurs['couts_vente']['commission'];
                        $tpsCommission = $commissionHT * 0.05;
                        $tvqCommission = $commissionHT * 0.09975;
                        $commissionTTC = $commissionHT + $tpsCommission + $tvqCommission;
                        ?>
                        <tr>
                            <td>
                                Commission courtier (<?= $projet['taux_commission'] ?>%)
                                <small class="text-muted d-block">
                                    + TPS <?= formatMoney($tpsCommission) ?> + TVQ <?= formatMoney($tvqCommission) ?>
                                </small>
                            </td>
                            <td class="amount"><?= formatMoney($commissionTTC) ?></td>
                        </tr>
                        <tr>
                            <td>Quittance</td>
                            <td class="amount"><?= formatMoney($indicateurs['couts_vente']['quittance']) ?></td>
                        </tr>
                        <tr class="total-row">
                            <td><strong>Total</strong></td>
                            <td class="amount"><strong><?= formatMoney($indicateurs['couts_vente']['total']) ?></strong></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Colonne droite -->
        <div class="col-lg-6">
            <!-- Rénovation -->
            <div class="financial-section">
                <h5>
                    <i class="bi bi-tools me-2"></i>Rénovation
                    <small class="text-muted">(Contingence: <?= $projet['taux_contingence'] ?>% = <?= formatMoney($indicateurs['contingence']) ?>)</small>
                </h5>
                
                <!-- Barre de progression -->
                <div class="mb-3">
                    <div class="d-flex justify-content-between mb-1">
                        <span>Progression</span>
                        <span><?= formatMoney($indicateurs['renovation']['reel']) ?> / <?= formatMoney($indicateurs['renovation']['budget']) ?></span>
                    </div>
                    <div class="progress-custom">
                        <div class="progress-bar <?= $indicateurs['renovation']['progression'] > 100 ? 'bg-danger' : 'bg-success' ?>" 
                             style="width: <?= min(100, $indicateurs['renovation']['progression']) ?>%">
                            <?= number_format($indicateurs['renovation']['progression'], 1) ?>%
                        </div>
                    </div>
                </div>
                
                <table class="financial-table">
                    <thead>
                        <tr>
                            <th>Catégorie</th>
                            <th class="text-end">Extrapolé</th>
                            <th class="text-end">Réel</th>
                            <th class="text-end">Écart</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $currentGroupe = '';
                        foreach ($categories as $cat): 
                            $budget = $budgets[$cat['id']] ?? 0;
                            $depense = $depenses[$cat['id']] ?? 0;
                            $ecart = $budget - $depense;
                            
                            if ($budget == 0 && $depense == 0) continue; // Ne pas afficher les lignes vides
                            
                            if ($cat['groupe'] !== $currentGroupe):
                                $currentGroupe = $cat['groupe'];
                        ?>
                            <tr class="group-header-row">
                                <td colspan="4"><strong><?= e(getGroupeCategorieLabel($currentGroupe)) ?></strong></td>
                            </tr>
                        <?php endif; ?>
                        <tr>
                            <td><?= e($cat['nom']) ?></td>
                            <td class="amount"><?= formatMoney($budget) ?></td>
                            <td class="amount"><?= formatMoney($depense) ?></td>
                            <td class="amount <?= $ecart >= 0 ? 'positive' : 'negative' ?>">
                                <?= formatMoney($ecart) ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <tr class="total-row">
                            <td><strong>Total rénovation</strong></td>
                            <td class="amount"><strong><?= formatMoney($indicateurs['renovation']['budget']) ?></strong></td>
                            <td class="amount"><strong><?= formatMoney($indicateurs['renovation']['reel']) ?></strong></td>
                            <td class="amount <?= $indicateurs['renovation']['ecart'] >= 0 ? 'positive' : 'negative' ?>">
                                <strong><?= formatMoney($indicateurs['renovation']['ecart']) ?></strong>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <!-- SECTION FINANCEMENT ET PARTICIPANTS -->
    <div class="financial-section">
        <h5><i class="bi bi-people-fill me-2"></i>Financement et Participants</h5>
        
        <div class="row">
            <!-- PRÊTEURS -->
            <div class="col-lg-6">
                <div class="card mb-3 border-warning">
                    <div class="card-header bg-warning text-dark">
                        <i class="bi bi-bank me-2"></i><strong>Prêteurs</strong>
                        <small class="float-end">Coût = Intérêts</small>
                    </div>
                    <?php if (!empty($indicateurs['preteurs'])): ?>
                    <table class="table table-sm mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Nom</th>
                                <th class="text-end">Montant</th>
                                <th class="text-center">Taux</th>
                                <th class="text-end">Intérêts</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($indicateurs['preteurs'] as $p): ?>
                                <tr>
                                    <td><?= e($p['nom']) ?></td>
                                    <td class="text-end"><?= formatMoney($p['montant']) ?></td>
                                    <td class="text-center"><span class="badge bg-warning text-dark"><?= $p['taux'] ?>%</span></td>
                                    <td class="text-end text-danger"><?= formatMoney($p['interets_total']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-warning">
                            <tr>
                                <td><strong>Total</strong></td>
                                <td class="text-end"><strong><?= formatMoney($indicateurs['total_prets']) ?></strong></td>
                                <td></td>
                                <td class="text-end text-danger"><strong><?= formatMoney($indicateurs['total_interets']) ?></strong></td>
                            </tr>
                        </tfoot>
                    </table>
                    <?php else: ?>
                    <div class="card-body text-center text-muted py-4">
                        <i class="bi bi-bank" style="font-size: 2rem;"></i>
                        <p class="mb-0 small">Aucun prêteur</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- INVESTISSEURS -->
            <div class="col-lg-6">
                <div class="card mb-3 border-success">
                    <div class="card-header bg-success text-white">
                        <i class="bi bi-people me-2"></i><strong>Investisseurs</strong>
                        <small class="float-end">Partage des profits</small>
                    </div>
                    <?php if (!empty($indicateurs['investisseurs'])): ?>
                    <?php 
                    $profitNet = $indicateurs['equite_potentielle'] - ($indicateurs['total_interets'] ?? 0);
                    ?>
                    <table class="table table-sm mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Nom</th>
                                <th class="text-end">Mise</th>
                                <th class="text-center">%</th>
                                <th class="text-end">Profit</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $totalPourcentage = 0;
                            $totalProfit = 0;
                            foreach ($indicateurs['investisseurs'] as $inv): 
                                // Utiliser pourcentage_calcule si pourcentage = 0
                                $pct = !empty($inv['pourcentage']) ? $inv['pourcentage'] : ($inv['pourcentage_calcule'] ?? 0);
                                $totalPourcentage += $pct;
                                $totalProfit += $inv['profit_estime'];
                            ?>
                                <tr>
                                    <td><?= e($inv['nom']) ?></td>
                                    <td class="text-end"><?= formatMoney($inv['mise_de_fonds']) ?></td>
                                    <td class="text-center"><span class="badge bg-success"><?= number_format($pct, 1) ?>%</span></td>
                                    <td class="text-end text-success"><?= formatMoney($inv['profit_estime']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-success">
                            <tr>
                                <td><strong>Total</strong></td>
                                <td class="text-end"><strong><?= formatMoney(array_sum(array_column($indicateurs['investisseurs'], 'mise_de_fonds'))) ?></strong></td>
                                <td class="text-center"><strong><?= number_format($totalPourcentage, 1) ?>%</strong></td>
                                <td class="text-end text-success"><strong><?= formatMoney($totalProfit) ?></strong></td>
                            </tr>
                        </tfoot>
                    </table>
                    <?php else: ?>
                    <div class="card-body text-center text-muted py-4">
                        <i class="bi bi-people" style="font-size: 2rem;"></i>
                        <p class="mb-0 small">Aucun investisseur</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <div class="text-center">
            <a href="/admin/projets/modifier.php?id=<?= $projet['id'] ?>&tab=preteurs" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-pencil me-1"></i>Gérer le financement
            </a>
        </div>
    </div>
    
    <!-- Résumé financier -->
    <div class="financial-section">
        <h5><i class="bi bi-calculator me-2"></i>Résumé financier <small class="text-muted fw-normal">(survolez pour voir les formules)</small></h5>
        <div class="row">
            <div class="col-md-6">
                <table class="financial-table">
                    <tbody>
                        <tr>
                            <td class="tooltip-cell" data-bs-toggle="tooltip" data-bs-placement="right" title="Montant payé pour acquérir la propriété">
                                <i class="bi bi-info-circle text-muted me-1"></i>Prix d'achat
                            </td>
                            <td class="amount"><?= formatMoney($projet['prix_achat']) ?></td>
                        </tr>
                        <tr>
                            <td class="tooltip-cell" data-bs-toggle="tooltip" data-bs-placement="right" title="Notaire + Taxe mutation + Arpenteurs + Assurance titre&#10;&#10;<?= formatMoney($indicateurs['couts_acquisition']['notaire']) ?> + <?= formatMoney($indicateurs['couts_acquisition']['taxe_mutation']) ?> + <?= formatMoney($indicateurs['couts_acquisition']['arpenteurs']) ?> + <?= formatMoney($indicateurs['couts_acquisition']['assurance_titre']) ?>">
                                <i class="bi bi-info-circle text-muted me-1"></i>Coûts d'acquisition
                            </td>
                            <td class="amount"><?= formatMoney($indicateurs['couts_acquisition']['total']) ?></td>
                        </tr>
                        <tr>
                            <td class="tooltip-cell" data-bs-toggle="tooltip" data-bs-placement="right" title="(Taxes mun. + Taxes scol. + Électricité + Assurances + Déneigement + Condo) × (<?= $dureeReelle ?> mois / 12) + Hypothèque × <?= $dureeReelle ?> mois">
                                <i class="bi bi-info-circle text-muted me-1"></i>Coûts récurrents
                            </td>
                            <td class="amount"><?= formatMoney($indicateurs['couts_recurrents']['total']) ?></td>
                        </tr>
                        <tr>
                            <td class="tooltip-cell" data-bs-toggle="tooltip" data-bs-placement="right" title="Intérêts (composés) + Commission courtier + Quittance&#10;&#10;Intérêts = Montant × ((1 + Taux/12)^Mois - 1)&#10;Commission = Valeur × <?= $projet['taux_commission'] ?>% + TPS + TVQ">
                                <i class="bi bi-info-circle text-muted me-1"></i>Coûts de vente
                            </td>
                            <td class="amount"><?= formatMoney($indicateurs['couts_vente']['total']) ?></td>
                        </tr>
                        <tr>
                            <td class="tooltip-cell" data-bs-toggle="tooltip" data-bs-placement="right" title="Somme de tous les budgets extrapolés par catégorie">
                                <i class="bi bi-info-circle text-muted me-1"></i>Rénovation
                            </td>
                            <td class="amount"><?= formatMoney($indicateurs['renovation']['budget']) ?></td>
                        </tr>
                        <tr>
                            <td class="tooltip-cell" data-bs-toggle="tooltip" data-bs-placement="right" title="Rénovation × <?= $projet['taux_contingence'] ?>%&#10;&#10;<?= formatMoney($indicateurs['renovation']['budget']) ?> × <?= $projet['taux_contingence'] ?>% = <?= formatMoney($indicateurs['contingence']) ?>">
                                <i class="bi bi-info-circle text-muted me-1"></i>Contingence (<?= $projet['taux_contingence'] ?>%)
                            </td>
                            <td class="amount"><?= formatMoney($indicateurs['contingence']) ?></td>
                        </tr>
                        <tr class="total-row">
                            <td class="tooltip-cell" data-bs-toggle="tooltip" data-bs-placement="right" title="Prix d'achat + Coûts acquisition + Coûts récurrents + Coûts vente + Rénovation + Contingence">
                                <i class="bi bi-info-circle text-white me-1"></i><strong>Coût total projet</strong>
                            </td>
                            <td class="amount"><strong><?= formatMoney($indicateurs['cout_total_projet']) ?></strong></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="col-md-6">
                <table class="financial-table">
                    <tbody>
                        <tr>
                            <td class="tooltip-cell" data-bs-toggle="tooltip" data-bs-placement="right" title="Prix de vente estimé ou réel de la propriété">
                                <i class="bi bi-info-circle text-muted me-1"></i>Valeur potentielle
                            </td>
                            <td class="amount"><?= formatMoney($indicateurs['valeur_potentielle']) ?></td>
                        </tr>
                        <tr>
                            <td class="tooltip-cell" data-bs-toggle="tooltip" data-bs-placement="right" title="Tous les coûts additionnés (voir colonne gauche)">
                                <i class="bi bi-info-circle text-muted me-1"></i>Coût total projet
                            </td>
                            <td class="amount negative">- <?= formatMoney($indicateurs['cout_total_projet']) ?></td>
                        </tr>
                        <tr class="total-row">
                            <td class="tooltip-cell" data-bs-toggle="tooltip" data-bs-placement="right" title="Valeur potentielle - Coût total projet&#10;&#10;<?= formatMoney($indicateurs['valeur_potentielle']) ?> - <?= formatMoney($indicateurs['cout_total_projet']) ?> = <?= formatMoney($indicateurs['equite_potentielle']) ?>&#10;&#10;C'est le PROFIT estimé avant répartition">
                                <i class="bi bi-info-circle text-white me-1"></i><strong>Équité potentielle</strong>
                            </td>
                            <td class="amount <?= $indicateurs['equite_potentielle'] >= 0 ? 'positive' : 'negative' ?>">
                                <strong><?= formatMoney($indicateurs['equite_potentielle']) ?></strong>
                            </td>
                        </tr>
                        <tr>
                            <td colspan="2">&nbsp;</td>
                        </tr>
                        <tr>
                            <td class="tooltip-cell" data-bs-toggle="tooltip" data-bs-placement="right" title="Total des montants investis (prêteurs + investisseurs)&#10;&#10;Utilisé pour calculer le ROI avec leverage">
                                <i class="bi bi-info-circle text-muted me-1"></i>Mise de fonds totale
                            </td>
                            <td class="amount"><?= formatMoney($indicateurs['mise_fonds_totale']) ?></td>
                        </tr>
                        <tr>
                            <td class="tooltip-cell" data-bs-toggle="tooltip" data-bs-placement="right" title="(Équité potentielle / Mise de fonds totale) × 100&#10;&#10;(<?= formatMoney($indicateurs['equite_potentielle']) ?> / <?= formatMoney($indicateurs['mise_fonds_totale']) ?>) × 100 = <?= number_format($indicateurs['roi_leverage'], 2) ?>%&#10;&#10;Rendement sur l'argent réellement investi">
                                <i class="bi bi-info-circle text-muted me-1"></i><strong>ROI (leverage)</strong>
                            </td>
                            <td class="amount <?= $indicateurs['roi_leverage'] >= 0 ? 'positive' : 'negative' ?>">
                                <strong><?= formatPercent($indicateurs['roi_leverage']) ?></strong>
                            </td>
                        </tr>
                        <tr>
                            <td class="tooltip-cell" data-bs-toggle="tooltip" data-bs-placement="right" title="(Équité potentielle / Coût total projet) × 100&#10;&#10;(<?= formatMoney($indicateurs['equite_potentielle']) ?> / <?= formatMoney($indicateurs['cout_total_projet']) ?>) × 100 = <?= number_format($indicateurs['roi_all_cash'], 2) ?>%&#10;&#10;Rendement si vous aviez payé tout en argent comptant">
                                <i class="bi bi-info-circle text-muted me-1"></i><strong>ROI (all cash)</strong>
                            </td>
                            <td class="amount"><?= formatPercent($indicateurs['roi_all_cash']) ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <!-- Initialiser les tooltips Bootstrap -->
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl, { html: true });
        });
    });
    </script>
    
    <!-- Actions -->
    <div class="d-flex justify-content-between mt-4">
        <a href="/admin/projets/liste.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>
            Retour à la liste
        </a>
        <div>
            <a href="/admin/factures/liste.php?projet=<?= $projet['id'] ?>" class="btn btn-outline-primary">
                <i class="bi bi-receipt me-1"></i>
                Voir les factures
            </a>
            <a href="/admin/projets/modifier.php?id=<?= $projet['id'] ?>" class="btn btn-primary">
                <i class="bi bi-pencil me-1"></i>
                Modifier le projet
            </a>
        </div>
    </div>
</div>

<?php
// Calculer la durée réelle du projet en mois
$moisProjet = (int)$projet['temps_assume_mois'];

// Si date de vente ET date acquisition existent, calculer la durée réelle
if (!empty($projet['date_vente']) && !empty($projet['date_acquisition'])) {
    $dateAchat = new DateTime($projet['date_acquisition']);
    $dateVente = new DateTime($projet['date_vente']);
    $diff = $dateAchat->diff($dateVente);
    $moisProjet = ($diff->y * 12) + $diff->m + ($diff->d > 15 ? 1 : 0);
    $moisProjet = max(1, $moisProjet);
}

// Préparer les labels mensuels (Mois 1, Mois 2, etc.)
$labelsTimeline = [];
$coutsTimeline = [];

// Calculer les coûts progressifs avec intérêts qui s'accumulent
$baseAchat = (float)$projet['prix_achat'] + $indicateurs['couts_acquisition']['total'];
$budgetReno = $indicateurs['renovation']['budget'];
$contingence = $indicateurs['contingence'];

// Recalculer les intérêts et récurrents avec la vraie durée
$totalPrets = $indicateurs['total_prets'] ?? 0;
$tauxInteret = (float)($projet['taux_interet'] ?? 10);
$interetsMensuel = $totalPrets * ($tauxInteret / 100) / 12;

$recurrentsAnnuel = (float)$projet['taxes_municipales_annuel'] + (float)$projet['taxes_scolaires_annuel'] 
    + (float)$projet['electricite_annuel'] + (float)$projet['assurances_annuel']
    + (float)$projet['deneigement_annuel'] + (float)$projet['frais_condo_annuel'];
$recurrentsMensuel = $recurrentsAnnuel / 12 + (float)$projet['hypotheque_mensuel'];

$commission = $indicateurs['couts_vente']['commission'];

// Générer les points pour chaque mois
for ($m = 0; $m <= $moisProjet; $m++) {
    if ($m == 0) {
        $labelsTimeline[] = 'Achat';
    } else {
        $labelsTimeline[] = 'Mois ' . $m;
    }
    
    // Progression de la réno (linéaire sur la durée)
    $pctReno = min(1, $m / max(1, $moisProjet - 1));
    
    // Coût à ce mois
    $cout = $baseAchat 
        + ($budgetReno * $pctReno)
        + ($recurrentsMensuel * $m) 
        + ($interetsMensuel * $m);
    
    // Au dernier mois, ajouter contingence et commission
    if ($m == $moisProjet) {
        $cout += $contingence + $commission;
    }
    
    $coutsTimeline[] = round($cout, 2);
}

// Valeur potentielle (ligne horizontale)
$valeurPotentielle = $indicateurs['valeur_potentielle'];

// Récupérer les dépenses par mois
$depensesParMois = [];
try {
    $stmt = $pdo->prepare("
        SELECT DATE_FORMAT(date_facture, '%Y-%m') as mois, SUM(montant_total) as total
        FROM factures 
        WHERE projet_id = ? AND statut = 'approuvee'
        GROUP BY DATE_FORMAT(date_facture, '%Y-%m')
        ORDER BY mois
    ");
    $stmt->execute([$projetId]);
    $depensesParMois = $stmt->fetchAll();
} catch (Exception $e) {}

$moisLabels = [];
$moisData = [];
foreach ($depensesParMois as $d) {
    $moisLabels[] = date('M Y', strtotime($d['mois'] . '-01'));
    $moisData[] = (float)$d['total'];
}
?>
<!-- Chart.js CDN + Adapter pour les dates -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// Style actions/trading avec lignes
Chart.defaults.color = '#666';
Chart.defaults.font.family = "'Segoe UI', sans-serif";

// Graphique 1: Timeline du projet - Coûts qui montent par mois
const dataTimeline = {
    labels: <?= json_encode($labelsTimeline) ?>,
    datasets: [{
        label: 'Coûts cumulés',
        data: <?= json_encode($coutsTimeline) ?>,
        borderColor: '#e74a3b',
        backgroundColor: 'rgba(231, 74, 59, 0.1)',
        fill: true,
        tension: 0.3,
        pointRadius: 4,
        pointBackgroundColor: '#e74a3b',
        pointBorderColor: '#fff',
        pointBorderWidth: 2
    }, {
        label: 'Valeur potentielle',
        data: <?= json_encode(array_fill(0, count($labelsTimeline), $valeurPotentielle)) ?>,
        borderColor: '#1cc88a',
        borderDash: [5, 5],
        pointRadius: 0,
        fill: false
    }]
};

// Graphique 2: Dépenses par mois (données réelles des factures)
<?php if (!empty($moisLabels)): ?>
const dataDepenses = {
    labels: <?= json_encode($moisLabels) ?>,
    datasets: [{
        label: 'Dépenses',
        data: <?= json_encode($moisData) ?>,
        borderColor: '#f6c23e',
        backgroundColor: 'rgba(246, 194, 62, 0.3)',
        fill: true,
        tension: 0.3,
        pointRadius: 5,
        pointBackgroundColor: '#f6c23e',
        pointBorderColor: '#fff',
        pointBorderWidth: 2
    }]
};
<?php else: ?>
const dataDepenses = {
    labels: ['Aucune facture'],
    datasets: [{
        label: 'Dépenses',
        data: [0],
        borderColor: '#ccc',
        backgroundColor: 'rgba(200, 200, 200, 0.2)',
        fill: true
    }]
};
<?php endif; ?>

// Graphique 3: Prévision vs Réel
const budgetTotal = <?= $indicateurs['renovation']['budget'] ?: 1 ?>;
const depenseReelle = <?= $indicateurs['renovation']['reel'] ?>;
const dataComparaison = {
    labels: ['Début', 'Milieu', 'Fin'],
    datasets: [{
        label: 'Budget prévu',
        data: [0, budgetTotal * 0.5, budgetTotal],
        borderColor: '#36b9cc',
        backgroundColor: 'rgba(54, 185, 204, 0.1)',
        fill: true,
        tension: 0.3,
        borderWidth: 2
    }, {
        label: 'Dépensé réel',
        data: [0, depenseReelle * 0.5, depenseReelle],
        borderColor: '#e74a3b',
        backgroundColor: 'rgba(231, 74, 59, 0.2)',
        fill: true,
        tension: 0.3,
        borderWidth: 2,
        pointRadius: 5,
        pointBackgroundColor: '#e74a3b'
    }]
};

// Options style trading/actions
const optionsLine = {
    responsive: true,
    maintainAspectRatio: false,
    interaction: { intersect: false, mode: 'index' },
    plugins: {
        legend: { position: 'top', labels: { boxWidth: 12, font: { size: 11 } } },
        tooltip: {
            backgroundColor: 'rgba(0,0,0,0.85)',
            callbacks: {
                label: ctx => ctx.dataset.label + ': ' + ctx.parsed.y.toLocaleString('fr-CA') + ' $'
            }
        }
    },
    scales: {
        x: { grid: { color: 'rgba(0,0,0,0.03)' }, ticks: { font: { size: 10 } } },
        y: {
            grid: { color: 'rgba(0,0,0,0.05)' },
            ticks: { callback: val => (val/1000).toFixed(0) + 'k$', font: { size: 10 } }
        }
    }
};

// Créer les graphiques
if (document.getElementById('chartCouts')) {
    new Chart(document.getElementById('chartCouts'), { type: 'line', data: dataTimeline, options: optionsLine });
}
if (document.getElementById('chartBudget')) {
    new Chart(document.getElementById('chartBudget'), { type: 'line', data: dataDepenses, options: optionsLine });
}
if (document.getElementById('chartProfits')) {
    new Chart(document.getElementById('chartProfits'), { type: 'line', data: dataComparaison, options: optionsLine });
}
</script>

<?php include '../../includes/footer.php'; ?>
