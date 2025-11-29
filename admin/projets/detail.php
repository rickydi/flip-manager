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
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h1><?= e($projet['nom']) ?></h1>
                <p class="text-muted mb-0">
                    <i class="bi bi-geo-alt me-1"></i>
                    <?= e($projet['adresse']) ?>, <?= e($projet['ville']) ?>
                    <span class="badge ms-2 <?= getStatutProjetClass($projet['statut']) ?>">
                        <?= getStatutProjetLabel($projet['statut']) ?>
                    </span>
                </p>
            </div>
            <div>
                <a href="/admin/projets/modifier.php?id=<?= $projet['id'] ?>" class="btn btn-outline-primary">
                    <i class="bi bi-pencil me-1"></i>Modifier
                </a>
                <button onclick="window.print()" class="btn btn-outline-secondary">
                    <i class="bi bi-printer me-1"></i>Imprimer
                </button>
            </div>
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
                <h5><i class="bi bi-arrow-repeat me-2"></i>Coûts récurrents (<?= $projet['temps_assume_mois'] ?> mois)</h5>
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
                            <td>Intérêts (<?= $projet['temps_assume_mois'] ?> mois @ <?= $projet['taux_interet'] ?>%)</td>
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
                            <tr style="background-color: #f8fafc;">
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
    
    <!-- PRÊTEURS (Intérêts) -->
    <?php if (!empty($indicateurs['preteurs'])): ?>
        <div class="financial-section">
            <h5><i class="bi bi-bank me-2"></i>Prêteurs (Intérêts à payer)</h5>
            <table class="financial-table">
                <thead>
                    <tr>
                        <th>Nom</th>
                        <th class="text-end">Montant prêté</th>
                        <th class="text-center">Taux annuel</th>
                        <th class="text-end">Intérêts (<?= $projet['temps_assume_mois'] ?> mois)</th>
                        <th class="text-end">Total à rembourser</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($indicateurs['preteurs'] as $p): ?>
                        <tr>
                            <td><strong><?= e($p['nom']) ?></strong></td>
                            <td class="amount"><?= formatMoney($p['montant']) ?></td>
                            <td class="text-center"><span class="badge bg-info"><?= $p['taux'] ?>%</span></td>
                            <td class="amount text-warning"><?= formatMoney($p['interets_total']) ?></td>
                            <td class="amount"><strong><?= formatMoney($p['total_du']) ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr class="total-row">
                        <td><strong>Total Prêts</strong></td>
                        <td class="amount"><strong><?= formatMoney($indicateurs['total_prets']) ?></strong></td>
                        <td></td>
                        <td class="amount text-warning"><strong><?= formatMoney($indicateurs['total_interets']) ?></strong></td>
                        <td class="amount"><strong><?= formatMoney($indicateurs['total_prets'] + $indicateurs['total_interets']) ?></strong></td>
                    </tr>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    
    <!-- INVESTISSEURS (% des profits) -->
    <?php if (!empty($indicateurs['investisseurs'])): ?>
        <?php 
        $profitNet = $indicateurs['equite_potentielle'] - ($indicateurs['total_interets'] ?? 0);
        ?>
        <div class="financial-section">
            <h5><i class="bi bi-people me-2"></i>Investisseurs (Partage des profits)</h5>
            <div class="alert alert-info mb-3">
                <strong>Profit net à partager :</strong> <?= formatMoney($profitNet) ?>
                <small class="text-muted">(Équité <?= formatMoney($indicateurs['equite_potentielle']) ?> - Intérêts <?= formatMoney($indicateurs['total_interets'] ?? 0) ?>)</small>
            </div>
            <table class="financial-table">
                <thead>
                    <tr>
                        <th>Nom</th>
                        <th class="text-end">Mise de fonds</th>
                        <th class="text-center">% des profits</th>
                        <th class="text-end">Profit estimé</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $totalPourcentage = 0;
                    $totalProfit = 0;
                    foreach ($indicateurs['investisseurs'] as $inv): 
                        $pct = $inv['pourcentage'] ?? $inv['pourcentage_calcule'] ?? 0;
                        $totalPourcentage += $pct;
                        $totalProfit += $inv['profit_estime'];
                    ?>
                        <tr>
                            <td><strong><?= e($inv['nom']) ?></strong></td>
                            <td class="amount"><?= formatMoney($inv['mise_de_fonds']) ?></td>
                            <td class="text-center"><span class="badge bg-success"><?= number_format($pct, 1) ?>%</span></td>
                            <td class="amount positive"><strong><?= formatMoney($inv['profit_estime']) ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr class="total-row">
                        <td><strong>Total</strong></td>
                        <td class="amount"><strong><?= formatMoney(array_sum(array_column($indicateurs['investisseurs'], 'mise_de_fonds'))) ?></strong></td>
                        <td class="text-center"><strong><?= number_format($totalPourcentage, 1) ?>%</strong></td>
                        <td class="amount positive"><strong><?= formatMoney($totalProfit) ?></strong></td>
                    </tr>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    
    <!-- Résumé financier -->
    <div class="financial-section">
        <h5><i class="bi bi-calculator me-2"></i>Résumé financier</h5>
        <div class="row">
            <div class="col-md-6">
                <table class="financial-table">
                    <tbody>
                        <tr>
                            <td>Prix d'achat</td>
                            <td class="amount"><?= formatMoney($projet['prix_achat']) ?></td>
                        </tr>
                        <tr>
                            <td>Coûts d'acquisition</td>
                            <td class="amount"><?= formatMoney($indicateurs['couts_acquisition']['total']) ?></td>
                        </tr>
                        <tr>
                            <td>Coûts récurrents</td>
                            <td class="amount"><?= formatMoney($indicateurs['couts_recurrents']['total']) ?></td>
                        </tr>
                        <tr>
                            <td>Coûts de vente</td>
                            <td class="amount"><?= formatMoney($indicateurs['couts_vente']['total']) ?></td>
                        </tr>
                        <tr>
                            <td>Rénovation</td>
                            <td class="amount"><?= formatMoney($indicateurs['renovation']['budget']) ?></td>
                        </tr>
                        <tr>
                            <td>Contingence (<?= $projet['taux_contingence'] ?>%)</td>
                            <td class="amount"><?= formatMoney($indicateurs['contingence']) ?></td>
                        </tr>
                        <tr class="total-row">
                            <td><strong>Coût total projet</strong></td>
                            <td class="amount"><strong><?= formatMoney($indicateurs['cout_total_projet']) ?></strong></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="col-md-6">
                <table class="financial-table">
                    <tbody>
                        <tr>
                            <td>Valeur potentielle</td>
                            <td class="amount"><?= formatMoney($indicateurs['valeur_potentielle']) ?></td>
                        </tr>
                        <tr>
                            <td>Coût total projet</td>
                            <td class="amount negative">- <?= formatMoney($indicateurs['cout_total_projet']) ?></td>
                        </tr>
                        <tr class="total-row">
                            <td><strong>Équité potentielle</strong></td>
                            <td class="amount <?= $indicateurs['equite_potentielle'] >= 0 ? 'positive' : 'negative' ?>">
                                <strong><?= formatMoney($indicateurs['equite_potentielle']) ?></strong>
                            </td>
                        </tr>
                        <tr>
                            <td colspan="2">&nbsp;</td>
                        </tr>
                        <tr>
                            <td>Mise de fonds totale</td>
                            <td class="amount"><?= formatMoney($indicateurs['mise_fonds_totale']) ?></td>
                        </tr>
                        <tr>
                            <td><strong>ROI (leverage)</strong></td>
                            <td class="amount <?= $indicateurs['roi_leverage'] >= 0 ? 'positive' : 'negative' ?>">
                                <strong><?= formatPercent($indicateurs['roi_leverage']) ?></strong>
                            </td>
                        </tr>
                        <tr>
                            <td><strong>ROI (all cash)</strong></td>
                            <td class="amount"><?= formatPercent($indicateurs['roi_all_cash']) ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
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
