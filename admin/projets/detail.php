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
                        <tr>
                            <td>Commission courtier (<?= $projet['taux_commission'] ?>%)</td>
                            <td class="amount"><?= formatMoney($indicateurs['couts_vente']['commission']) ?></td>
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
    
    <!-- Investisseurs -->
    <?php if (!empty($indicateurs['investisseurs'])): ?>
        <div class="financial-section">
            <h5><i class="bi bi-people me-2"></i>Investisseurs</h5>
            <table class="financial-table">
                <thead>
                    <tr>
                        <th>Nom</th>
                        <th>Type</th>
                        <th class="text-end">Mise de fonds</th>
                        <th class="text-end">% Profit</th>
                        <th class="text-end">Profit estimé</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($indicateurs['investisseurs'] as $inv): ?>
                        <tr>
                            <td><?= e($inv['nom']) ?></td>
                            <td><?= e($inv['type_investissement']) ?></td>
                            <td class="amount"><?= formatMoney($inv['mise_de_fonds']) ?></td>
                            <td class="amount"><?= formatPercent($inv['pourcentage_calcule']) ?></td>
                            <td class="amount positive"><?= formatMoney($inv['profit_estime']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr class="total-row">
                        <td colspan="2"><strong>Total</strong></td>
                        <td class="amount"><strong><?= formatMoney($indicateurs['mise_fonds_totale']) ?></strong></td>
                        <td class="amount"><strong>100 %</strong></td>
                        <td class="amount positive"><strong><?= formatMoney($indicateurs['equite_potentielle']) ?></strong></td>
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

<?php include '../../includes/footer.php'; ?>
