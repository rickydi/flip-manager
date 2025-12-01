<?php
/**
 * Détail du projet - Admin
 * Flip Manager - Vue compacte 3 colonnes
 */

require_once '../../config.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../includes/calculs.php';

requireAdmin();

$projetId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$projet = getProjetById($pdo, $projetId);

if (!$projet) {
    setFlashMessage('danger', 'Projet non trouvé.');
    redirect('/admin/projets/liste.php');
}

$pageTitle = $projet['nom'];
$indicateurs = calculerIndicateursProjet($pdo, $projet);

// Durée réelle (cohérent avec calculs.php)
$dureeReelle = (int)$projet['temps_assume_mois'];
if (!empty($projet['date_vente']) && !empty($projet['date_acquisition'])) {
    $dateAchat = new DateTime($projet['date_acquisition']);
    $dateVente = new DateTime($projet['date_vente']);
    $diff = $dateAchat->diff($dateVente);
    $dureeReelle = ($diff->y * 12) + $diff->m;
    if ((int)$dateVente->format('d') > (int)$dateAchat->format('d')) {
        $dureeReelle++;
    }
    $dureeReelle = max(1, $dureeReelle);
}

$categories = getCategories($pdo);
$budgets = getBudgetsParCategorie($pdo, $projetId);
$depenses = calculerDepensesParCategorie($pdo, $projetId);

// ========================================
// CALCUL MAIN D'ŒUVRE EXTRAPOLÉE (depuis planification)
// ========================================
$moExtrapole = ['heures' => 0, 'cout' => 0, 'jours' => 0];
$dateDebutTravaux = $projet['date_debut_travaux'] ?? $projet['date_acquisition'];
$dateFinPrevue = $projet['date_fin_prevue'];

if ($dateDebutTravaux && $dateFinPrevue) {
    $d1 = new DateTime($dateDebutTravaux);
    $d2 = new DateTime($dateFinPrevue);
    
    // Calcul des jours ouvrables (Lundi-Vendredi)
    $d2Inclusive = clone $d2;
    $d2Inclusive->modify('+1 day');
    $period = new DatePeriod($d1, new DateInterval('P1D'), $d2Inclusive);
    
    $joursOuvrables = 0;
    foreach ($period as $dt) {
        if ((int)$dt->format('N') < 6) $joursOuvrables++;
    }
    $moExtrapole['jours'] = max(1, $joursOuvrables);
    
    // Récupérer les planifications avec taux horaire
    try {
        $stmt = $pdo->prepare("
            SELECT p.heures_semaine_estimees, u.taux_horaire
            FROM projet_planification_heures p
            JOIN users u ON p.user_id = u.id
            WHERE p.projet_id = ?
        ");
        $stmt->execute([$projetId]);
        
        foreach ($stmt->fetchAll() as $row) {
            $heuresSemaine = (float)$row['heures_semaine_estimees'];
            $tauxHoraire = (float)$row['taux_horaire'];
            // heures/jour = heures/semaine ÷ 5
            $heuresJour = $heuresSemaine / 5;
            $totalHeures = $heuresJour * $moExtrapole['jours'];
            $moExtrapole['heures'] += $totalHeures;
            $moExtrapole['cout'] += $totalHeures * $tauxHoraire;
        }
    } catch (Exception $e) {}
}

// ========================================
// CALCUL MAIN D'ŒUVRE RÉELLE (heures travaillées)
// Utilise le taux stocké dans la ligne (comme temps/liste.php)
// Si taux_horaire = 0, fallback sur le taux actuel de l'utilisateur
// ========================================
$moReel = ['heures' => 0, 'cout' => 0];
try {
    $stmt = $pdo->prepare("
        SELECT SUM(h.heures) as total_heures, 
               SUM(h.heures * IF(h.taux_horaire > 0, h.taux_horaire, u.taux_horaire)) as total_cout 
        FROM heures_travaillees h 
        JOIN users u ON h.user_id = u.id 
        WHERE h.projet_id = ? AND h.statut != 'rejetee'
    ");
    $stmt->execute([$projetId]);
    $res = $stmt->fetch();
    $moReel['heures'] = (float)($res['total_heures'] ?? 0);
    $moReel['cout'] = (float)($res['total_cout'] ?? 0);
} catch (Exception $e) {}

include '../../includes/header.php';
?>

<style>
/* Tableau compact 3 colonnes - compatible dark mode */
.cost-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
.cost-table th, .cost-table td { padding: 6px 10px; border-bottom: 1px solid var(--bs-border-color, #dee2e6); }
.cost-table thead th { background: #2d3748; color: white; font-weight: 600; position: sticky; top: 0; }
.cost-table .section-header { background: #1e3a5f; color: white; font-weight: 600; cursor: pointer; user-select: none; }
.cost-table .section-header:hover { background: #254a73; }
.cost-table .section-header .toggle-icon { float: right; opacity: 0.5; font-size: 0.75rem; transition: transform 0.2s; }
.cost-table .section-header.collapsed .toggle-icon { transform: rotate(-90deg); }
.cost-table .labor-row { background: #1e40af !important; color: white; }
.cost-table .section-header td { padding: 8px 10px; }
.cost-table .sub-item td:first-child { padding-left: 25px; }
.cost-table .total-row { background: #374151; color: white; font-weight: 600; }
.cost-table .grand-total { background: #1e3a5f; color: white; font-weight: 700; }
.cost-table .profit-row { background: #198754; color: white; font-weight: 700; }
.cost-table .text-end { text-align: right; }
.cost-table .positive { color: #198754; }
.cost-table .negative { color: #dc3545; }
.cost-table .col-label { width: 40%; }
.cost-table .col-num { width: 20%; text-align: right; }
@media (max-width: 768px) {
    .cost-table { font-size: 0.75rem; }
    .cost-table th, .cost-table td { padding: 4px 6px; }
}
</style>

<meta http-equiv="refresh" content="30">

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
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
            <div>
                <div class="d-flex align-items-center flex-wrap gap-2 mb-1">
                    <h1 class="mb-0 fs-4"><?= e($projet['nom']) ?></h1>
                    <span class="badge <?= getStatutProjetClass($projet['statut']) ?>"><?= getStatutProjetLabel($projet['statut']) ?></span>
                    <a href="/admin/projets/modifier.php?id=<?= $projet['id'] ?>" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-pencil"></i>
                    </a>
                </div>
                <small class="text-muted"><i class="bi bi-geo-alt"></i> <?= e($projet['adresse']) ?>, <?= e($projet['ville']) ?></small>
            </div>
            <button onclick="window.print()" class="btn btn-outline-secondary btn-sm"><i class="bi bi-printer"></i></button>
        </div>
    </div>
    
    <?php displayFlashMessage(); ?>
    
    <!-- Indicateurs rapides -->
    <div class="row g-2 mb-3">
        <div class="col-6 col-md-3">
            <div class="card text-center p-2">
                <small class="text-muted">Valeur potentielle</small>
                <strong class="fs-5 text-primary"><?= formatMoney($indicateurs['valeur_potentielle']) ?></strong>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card text-center p-2">
                <small class="text-muted">Équité Budget</small>
                <strong class="fs-5 text-warning"><?= formatMoney($indicateurs['equite_potentielle']) ?></strong>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card text-center p-2">
                <small class="text-muted">Équité Réelle</small>
                <strong class="fs-5 text-success"><?= formatMoney($indicateurs['equite_reelle']) ?></strong>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card text-center p-2">
                <small class="text-muted">ROI Leverage</small>
                <strong class="fs-5"><?= formatPercent($indicateurs['roi_leverage']) ?></strong>
            </div>
        </div>
    </div>
    
    <!-- GRAPHIQUES -->
    <div class="row g-2 mb-3">
        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-header py-1 text-center small">📈 Coûts vs Valeur</div>
                <div class="card-body p-2"><canvas id="chartCouts" height="150"></canvas></div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-header py-1 text-center small">⏱️ Heures travaillées</div>
                <div class="card-body p-2"><canvas id="chartBudget" height="150"></canvas></div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-header py-1 text-center small">📊 Budget vs Dépensé</div>
                <div class="card-body p-2"><canvas id="chartProfits" height="150"></canvas></div>
            </div>
        </div>
    </div>
    
    <!-- TABLEAU UNIFIÉ : EXTRAPOLÉ | DIFF | RÉEL -->
    <div class="card">
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
                        <td>Taxe mutation</td>
                        <td class="text-end"><?= formatMoney($indicateurs['couts_acquisition']['taxe_mutation']) ?></td>
                        <td class="text-end">-</td>
                        <td class="text-end"><?= formatMoney($indicateurs['couts_acquisition']['taxe_mutation']) ?></td>
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
                    <tr class="total-row">
                        <td>Sous-total Acquisition</td>
                        <td class="text-end"><?= formatMoney($indicateurs['couts_acquisition']['total']) ?></td>
                        <td class="text-end">-</td>
                        <td class="text-end"><?= formatMoney($indicateurs['couts_acquisition']['total']) ?></td>
                    </tr>
                    
                    <!-- COÛTS RÉCURRENTS -->
                    <tr class="section-header" data-section="recurrents">
                        <td colspan="4"><i class="bi bi-arrow-repeat me-1"></i> Récurrents (<?= $dureeReelle ?> mois) <i class="bi bi-chevron-down toggle-icon"></i></td>
                    </tr>
                    <tr class="sub-item">
                        <td>Taxes municipales</td>
                        <td class="text-end"><?= formatMoney($indicateurs['couts_recurrents']['taxes_municipales']['extrapole']) ?></td>
                        <td class="text-end">-</td>
                        <td class="text-end"><?= formatMoney($indicateurs['couts_recurrents']['taxes_municipales']['extrapole']) ?></td>
                    </tr>
                    <tr class="sub-item">
                        <td>Taxes scolaires</td>
                        <td class="text-end"><?= formatMoney($indicateurs['couts_recurrents']['taxes_scolaires']['extrapole']) ?></td>
                        <td class="text-end">-</td>
                        <td class="text-end"><?= formatMoney($indicateurs['couts_recurrents']['taxes_scolaires']['extrapole']) ?></td>
                    </tr>
                    <tr class="sub-item">
                        <td>Électricité</td>
                        <td class="text-end"><?= formatMoney($indicateurs['couts_recurrents']['electricite']['extrapole']) ?></td>
                        <td class="text-end">-</td>
                        <td class="text-end"><?= formatMoney($indicateurs['couts_recurrents']['electricite']['extrapole']) ?></td>
                    </tr>
                    <tr class="sub-item">
                        <td>Assurances</td>
                        <td class="text-end"><?= formatMoney($indicateurs['couts_recurrents']['assurances']['extrapole']) ?></td>
                        <td class="text-end">-</td>
                        <td class="text-end"><?= formatMoney($indicateurs['couts_recurrents']['assurances']['extrapole']) ?></td>
                    </tr>
                    <tr class="sub-item">
                        <td>Déneigement</td>
                        <td class="text-end"><?= formatMoney($indicateurs['couts_recurrents']['deneigement']['extrapole']) ?></td>
                        <td class="text-end">-</td>
                        <td class="text-end"><?= formatMoney($indicateurs['couts_recurrents']['deneigement']['extrapole']) ?></td>
                    </tr>
                    <tr class="sub-item">
                        <td>Frais condo</td>
                        <td class="text-end"><?= formatMoney($indicateurs['couts_recurrents']['frais_condo']['extrapole']) ?></td>
                        <td class="text-end">-</td>
                        <td class="text-end"><?= formatMoney($indicateurs['couts_recurrents']['frais_condo']['extrapole']) ?></td>
                    </tr>
                    <tr class="sub-item">
                        <td>Hypothèque</td>
                        <td class="text-end"><?= formatMoney($indicateurs['couts_recurrents']['hypotheque']['extrapole']) ?></td>
                        <td class="text-end">-</td>
                        <td class="text-end"><?= formatMoney($indicateurs['couts_recurrents']['hypotheque']['extrapole']) ?></td>
                    </tr>
                    <tr class="total-row">
                        <td>Sous-total Récurrents</td>
                        <td class="text-end"><?= formatMoney($indicateurs['couts_recurrents']['total']) ?></td>
                        <td class="text-end">-</td>
                        <td class="text-end"><?= formatMoney($indicateurs['couts_recurrents']['total']) ?></td>
                    </tr>
                    
                    <!-- RÉNOVATION -->
                    <tr class="section-header" data-section="renovation">
                        <td colspan="4"><i class="bi bi-tools me-1"></i> Rénovation (+ <?= $projet['taux_contingence'] ?>% contingence) <i class="bi bi-chevron-down toggle-icon"></i></td>
                    </tr>
                    <?php 
                    $totalBudgetReno = 0;
                    $totalReelReno = 0;
                    foreach ($categories as $cat): 
                        $budget = $budgets[$cat['id']] ?? 0;
                        $depense = $depenses[$cat['id']] ?? 0;
                        if ($budget == 0 && $depense == 0) continue;
                        $ecart = $budget - $depense;
                        $totalBudgetReno += $budget;
                        $totalReelReno += $depense;
                    ?>
                    <tr class="sub-item">
                        <td><?= e($cat['nom']) ?></td>
                        <td class="text-end"><?= formatMoney($budget) ?></td>
                        <td class="text-end <?= $ecart >= 0 ? 'positive' : 'negative' ?>"><?= $ecart != 0 ? formatMoney($ecart) : '-' ?></td>
                        <td class="text-end"><?= formatMoney($depense) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    
                    <!-- MAIN D'ŒUVRE -->
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
                    
                    <tr class="sub-item">
                        <td>Contingence <?= $projet['taux_contingence'] ?>%</td>
                        <td class="text-end"><?= formatMoney($indicateurs['contingence']) ?></td>
                        <td class="text-end">-</td>
                        <td class="text-end">-</td>
                    </tr>
                    
                    <?php 
                    $renoReel = $indicateurs['renovation']['reel'] + $indicateurs['main_doeuvre']['cout'];
                    $diffReno = $indicateurs['renovation']['budget'] - $renoReel;
                    ?>
                    <tr class="total-row">
                        <td>Sous-total Rénovation</td>
                        <td class="text-end"><?= formatMoney($indicateurs['renovation']['budget']) ?></td>
                        <td class="text-end <?= $diffReno >= 0 ? 'positive' : 'negative' ?>"><?= formatMoney($diffReno) ?></td>
                        <td class="text-end"><?= formatMoney($renoReel) ?></td>
                    </tr>
                    
                    <!-- COÛTS DE VENTE -->
                    <tr class="section-header" data-section="vente">
                        <td colspan="4"><i class="bi bi-shop me-1"></i> Vente <i class="bi bi-chevron-down toggle-icon"></i></td>
                    </tr>
                    <tr class="sub-item">
                        <td>Intérêts (<?= $projet['taux_interet'] ?>% sur <?= $dureeReelle ?> mois)</td>
                        <td class="text-end"><?= formatMoney($indicateurs['couts_vente']['interets']) ?></td>
                        <td class="text-end">-</td>
                        <td class="text-end"><?= formatMoney($indicateurs['couts_vente']['interets']) ?></td>
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
                    <tr class="total-row">
                        <td>Sous-total Vente</td>
                        <td class="text-end"><?= formatMoney($indicateurs['couts_vente']['total']) ?></td>
                        <td class="text-end">-</td>
                        <td class="text-end"><?= formatMoney($indicateurs['couts_vente']['total']) ?></td>
                    </tr>
                    
                    <!-- GRAND TOTAL -->
                    <?php $diffTotal = $indicateurs['cout_total_projet'] - $indicateurs['cout_total_reel']; ?>
                    <tr class="grand-total">
                        <td>COÛT TOTAL PROJET</td>
                        <td class="text-end"><?= formatMoney($indicateurs['cout_total_projet']) ?></td>
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
                    <tr class="profit-row">
                        <td>ÉQUITÉ / PROFIT</td>
                        <td class="text-end"><?= formatMoney($indicateurs['equite_potentielle']) ?></td>
                        <td class="text-end" style="color:<?= $diffEquite >= 0 ? '#90EE90' : '#ffcccc' ?>"><?= $diffEquite >= 0 ? '+' : '' ?><?= formatMoney($diffEquite) ?></td>
                        <td class="text-end"><?= formatMoney($indicateurs['equite_reelle']) ?></td>
                    </tr>
                    
                    <tr>
                        <td><strong>ROI @ Leverage</strong></td>
                        <td class="text-end"><?= formatPercent($indicateurs['roi_leverage']) ?></td>
                        <td class="text-end">-</td>
                        <td class="text-end"><?= formatPercent($indicateurs['roi_leverage_reel']) ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
    
    <!-- FINANCEMENT -->
    <div class="row g-2 mt-3">
        <div class="col-md-6">
            <div class="card border-warning h-100">
                <div class="card-header py-1 bg-warning text-dark small">
                    <i class="bi bi-bank me-1"></i> Prêteurs
                </div>
                <?php if (!empty($indicateurs['preteurs'])): ?>
                <div class="table-responsive">
                    <table class="table table-sm mb-0 small">
                        <thead><tr><th>Nom</th><th class="text-end">Montant</th><th class="text-center">%</th><th class="text-end">Intérêts</th></tr></thead>
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
                        <tfoot class="table-warning"><tr>
                            <td><strong>Total</strong></td>
                            <td class="text-end"><strong><?= formatMoney($indicateurs['total_prets']) ?></strong></td>
                            <td></td>
                            <td class="text-end text-danger"><strong><?= formatMoney($indicateurs['total_interets']) ?></strong></td>
                        </tr></tfoot>
                    </table>
                </div>
                <?php else: ?>
                <div class="card-body text-center text-muted py-2"><small>Aucun prêteur</small></div>
                <?php endif; ?>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card border-success h-100">
                <div class="card-header py-1 bg-success text-white small">
                    <i class="bi bi-people me-1"></i> Investisseurs
                </div>
                <?php if (!empty($indicateurs['investisseurs'])): ?>
                <div class="table-responsive">
                    <table class="table table-sm mb-0 small">
                        <thead><tr><th>Nom</th><th class="text-end">Mise</th><th class="text-center">%</th><th class="text-end">Profit</th></tr></thead>
                        <tbody>
                        <?php foreach ($indicateurs['investisseurs'] as $inv): 
                            $pct = !empty($inv['pourcentage']) ? $inv['pourcentage'] : ($inv['pourcentage_calcule'] ?? 0);
                        ?>
                        <tr>
                            <td><?= e($inv['nom']) ?></td>
                            <td class="text-end"><?= formatMoney($inv['mise_de_fonds']) ?></td>
                            <td class="text-center"><span class="badge bg-success"><?= number_format($pct, 1) ?>%</span></td>
                            <td class="text-end text-success"><?= formatMoney($inv['profit_estime']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="card-body text-center text-muted py-2"><small>Aucun investisseur</small></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Actions -->
    <div class="d-flex justify-content-between mt-3 mb-4">
        <a href="/admin/projets/liste.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Retour</a>
        <div>
            <a href="/admin/factures/liste.php?projet=<?= $projet['id'] ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-receipt"></i> Factures</a>
            <a href="/admin/projets/modifier.php?id=<?= $projet['id'] ?>" class="btn btn-primary btn-sm"><i class="bi bi-pencil"></i> Modifier</a>
        </div>
    </div>
</div>

<?php
// Données pour les graphiques (cohérent avec calculs.php)
$moisProjet = (int)$projet['temps_assume_mois'];
if (!empty($projet['date_vente']) && !empty($projet['date_acquisition'])) {
    $dateAchat = new DateTime($projet['date_acquisition']);
    $dateVente = new DateTime($projet['date_vente']);
    $diff = $dateAchat->diff($dateVente);
    $moisProjet = ($diff->y * 12) + $diff->m;
    if ((int)$dateVente->format('d') > (int)$dateAchat->format('d')) {
        $moisProjet++;
    }
    $moisProjet = max(1, $moisProjet);
}

$labelsTimeline = [];
$coutsTimeline = [];
$baseAchat = (float)$projet['prix_achat'] + $indicateurs['couts_acquisition']['total'];
$budgetReno = $indicateurs['renovation']['budget'];
$contingence = $indicateurs['contingence'];
$totalPrets = $indicateurs['total_prets'] ?? 0;
$tauxInteret = (float)($projet['taux_interet'] ?? 10);
$tauxMensuel = $tauxInteret / 100 / 12;  // Pour intérêts composés
$recurrentsAnnuel = (float)$projet['taxes_municipales_annuel'] + (float)$projet['taxes_scolaires_annuel']
    + (float)$projet['electricite_annuel'] + (float)$projet['assurances_annuel']
    + (float)$projet['deneigement_annuel'] + (float)$projet['frais_condo_annuel'];
$recurrentsMensuel = $recurrentsAnnuel / 12 + (float)$projet['hypotheque_mensuel'] - (float)$projet['loyer_mensuel'];
$commissionTTC = $indicateurs['couts_vente']['commission_ttc'];

for ($m = 0; $m <= $moisProjet; $m++) {
    $labelsTimeline[] = $m == 0 ? 'Achat' : 'M' . $m;
    $pctReno = min(1, $m / max(1, $moisProjet - 1));
    $interetsCumules = $totalPrets * (pow(1 + $tauxMensuel, $m) - 1);  // Intérêts composés
    $cout = $baseAchat + ($budgetReno * $pctReno) + ($recurrentsMensuel * $m) + $interetsCumules;
    if ($m == $moisProjet) $cout += $contingence + $commissionTTC;
    $coutsTimeline[] = round($cout, 2);
}

$valeurPotentielle = $indicateurs['valeur_potentielle'];

// Heures travaillées
$heuresParJour = [];
try {
    $stmt = $pdo->prepare("SELECT date_travail as jour, SUM(heures) as total FROM heures_travaillees WHERE projet_id = ? AND statut != 'rejetee' GROUP BY date_travail ORDER BY date_travail");
    $stmt->execute([$projetId]);
    foreach ($stmt->fetchAll() as $row) $heuresParJour[$row['jour']] = (float)$row['total'];
} catch (Exception $e) {}

$jourLabelsHeures = [];
$jourDataHeures = [];
foreach ($heuresParJour as $jour => $heures) {
    $jourLabelsHeures[] = date('d M', strtotime($jour));
    $jourDataHeures[] = $heures;
}

// Budget vs Dépensé
$dateDebut = !empty($projet['date_acquisition']) ? $projet['date_acquisition'] : date('Y-m-d');
$dateFin = !empty($projet['date_vente']) ? $projet['date_vente'] : date('Y-m-d', strtotime('+' . $moisProjet . ' months', strtotime($dateDebut)));
$budgetTotal = $indicateurs['renovation']['budget'] ?: 1;

$depensesCumulees = [];
try {
    $stmt = $pdo->prepare("SELECT date_facture as jour, SUM(montant_total) as total FROM factures WHERE projet_id = ? AND statut != 'rejetee' GROUP BY date_facture ORDER BY date_facture");
    $stmt->execute([$projetId]);
    $cumul = 0;
    foreach ($stmt->fetchAll() as $row) {
        $cumul += (float)$row['total'];
        $depensesCumulees[$row['jour']] = $cumul;
    }
} catch (Exception $e) {}

$jourLabels = [];
$dataExtrapole = [];
$dataReel = [];
$dateStart = new DateTime($dateDebut);
$dateEnd = new DateTime($dateFin);
$joursTotal = max(1, $dateStart->diff($dateEnd)->days);
$dernierCumul = 0;

$interval = new DateInterval('P7D');
$period = new DatePeriod($dateStart, $interval, $dateEnd);
$points = iterator_to_array($period);
$points[] = $dateEnd;

foreach ($points as $date) {
    $dateStr = $date->format('Y-m-d');
    $joursEcoules = $dateStart->diff($date)->days;
    $pctProgression = $joursEcoules / $joursTotal;
    $jourLabels[] = $date->format('d M');
    $dataExtrapole[] = round($budgetTotal * $pctProgression, 2);
    foreach ($depensesCumulees as $jour => $cumul) {
        if ($jour <= $dateStr) $dernierCumul = $cumul;
    }
    $dataReel[] = $dernierCumul;
}
$dataReel[count($dataReel) - 1] += $indicateurs['main_doeuvre']['cout'];
?>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
Chart.defaults.color = '#666';
const optionsLine = {
    responsive: true, maintainAspectRatio: false,
    plugins: { legend: { position: 'top', labels: { boxWidth: 10, font: { size: 10 } } } },
    scales: { x: { ticks: { font: { size: 9 } } }, y: { ticks: { callback: v => (v/1000).toFixed(0)+'k', font: { size: 9 } } } }
};
const optionsBar = {
    responsive: true, maintainAspectRatio: false,
    plugins: { legend: { display: false } },
    scales: { x: { ticks: { font: { size: 9 } } }, y: { ticks: { callback: v => v+'h', font: { size: 9 } } } }
};

new Chart(document.getElementById('chartCouts'), {
    type: 'line',
    data: {
        labels: <?= json_encode($labelsTimeline) ?>,
        datasets: [
            { label: 'Coûts', data: <?= json_encode($coutsTimeline) ?>, borderColor: '#e74a3b', backgroundColor: 'rgba(231,74,59,0.1)', fill: true, tension: 0.3, pointRadius: 2 },
            { label: 'Valeur', data: <?= json_encode(array_fill(0, count($labelsTimeline), $valeurPotentielle)) ?>, borderColor: '#1cc88a', borderDash: [5,5], pointRadius: 0 }
        ]
    },
    options: optionsLine
});

new Chart(document.getElementById('chartBudget'), {
    type: 'bar',
    data: {
        labels: <?= json_encode($jourLabelsHeures ?: ['Aucune']) ?>,
        datasets: [{ data: <?= json_encode($jourDataHeures ?: [0]) ?>, backgroundColor: 'rgba(78,115,223,0.6)' }]
    },
    options: optionsBar
});

new Chart(document.getElementById('chartProfits'), {
    type: 'line',
    data: {
        labels: <?= json_encode($jourLabels) ?>,
        datasets: [
            { label: 'Budget', data: <?= json_encode($dataExtrapole) ?>, borderColor: '#36b9cc', fill: true, backgroundColor: 'rgba(54,185,204,0.1)', tension: 0.3, pointRadius: 1 },
            { label: 'Réel', data: <?= json_encode($dataReel) ?>, borderColor: '#e74a3b', fill: true, backgroundColor: 'rgba(231,74,59,0.2)', stepped: true, pointRadius: 2 }
        ]
    },
    options: optionsLine
});
</script>
<script>
// Toggle sections avec affichage Extrapolé | Diff | Réel alignés sur les colonnes
document.querySelectorAll('.section-header[data-section]').forEach(header => {
    // Sauvegarder le HTML original
    const originalTd = header.querySelector('td');
    const originalHTML = originalTd.innerHTML;
    const originalColspan = originalTd.getAttribute('colspan');
    
    // Trouver la ligne total-row associée et stocker les montants
    let row = header.nextElementSibling;
    let totalRow = null;
    while (row && !row.classList.contains('section-header')) {
        if (row.classList.contains('total-row')) {
            totalRow = row;
        }
        row = row.nextElementSibling;
    }
    
    // Extraire les 3 montants du total-row
    if (totalRow) {
        const cells = totalRow.querySelectorAll('td');
        if (cells.length >= 4) {
            header.dataset.extrapole = cells[1].textContent.trim();
            header.dataset.diff = cells[2].textContent.trim();
            header.dataset.reel = cells[3].textContent.trim();
            header.dataset.diffClass = cells[2].classList.contains('positive') ? 'positive' : 
                                       cells[2].classList.contains('negative') ? 'negative' : '';
        }
    }
    
    header.addEventListener('click', function() {
        this.classList.toggle('collapsed');
        const isCollapsed = this.classList.contains('collapsed');
        const existingTd = this.querySelector('td');
        
        if (isCollapsed && this.dataset.reel) {
            // Transformer en 4 colonnes
            existingTd.setAttribute('colspan', '1');
            existingTd.classList.add('col-label');
            
            // Ajouter les 3 cellules de montant
            const extTd = document.createElement('td');
            extTd.className = 'text-end col-num';
            extTd.style.color = '#87CEEB';
            extTd.textContent = this.dataset.extrapole;
            
            const diffTd = document.createElement('td');
            diffTd.className = 'text-end col-num';
            if (this.dataset.diffClass === 'positive') diffTd.style.color = '#90EE90';
            else if (this.dataset.diffClass === 'negative') diffTd.style.color = '#ff6b6b';
            else diffTd.style.opacity = '0.7';
            diffTd.textContent = this.dataset.diff;
            
            const reelTd = document.createElement('td');
            reelTd.className = 'text-end col-num';
            reelTd.style.color = '#90EE90';
            reelTd.textContent = this.dataset.reel;
            
            this.appendChild(extTd);
            this.appendChild(diffTd);
            this.appendChild(reelTd);
        } else {
            // Restaurer le colspan original
            existingTd.setAttribute('colspan', originalColspan);
            existingTd.classList.remove('col-label');
            // Supprimer les cellules ajoutées
            while (this.children.length > 1) {
                this.removeChild(this.lastChild);
            }
        }
        
        // Toggle les lignes
        let nextRow = this.nextElementSibling;
        while (nextRow && !nextRow.classList.contains('section-header')) {
            nextRow.style.display = isCollapsed ? 'none' : '';
            nextRow = nextRow.nextElementSibling;
        }
    });
});
</script>
<?php include '../../includes/footer.php'; ?>
