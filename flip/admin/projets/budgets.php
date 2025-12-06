<?php
/**
 * Budgets du projet - Admin
 * Flip Manager
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

$pageTitle = $projet['nom'] . ' - Budgets';
$indicateurs = calculerIndicateursProjet($pdo, $projet);
$categories = getCategories($pdo);
$budgets = getBudgetsParCategorie($pdo, $projetId);
$depenses = calculerDepensesParCategorie($pdo, $projetId);

include '../../includes/header.php';
?>

<style>
.budget-progress {
    height: 8px;
    border-radius: 4px;
}
.budget-card {
    transition: transform 0.2s;
}
.budget-card:hover {
    transform: translateY(-2px);
}
</style>

<div class="container-fluid">
    <!-- En-tête -->
    <div class="page-header">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= url('/admin/index.php') ?>">Tableau de bord</a></li>
                <li class="breadcrumb-item"><a href="<?= url('/admin/projets/liste.php') ?>">Projets</a></li>
                <li class="breadcrumb-item active"><?= e($projet['nom']) ?></li>
            </ol>
        </nav>
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
            <div>
                <div class="d-flex align-items-center flex-wrap gap-2 mb-1">
                    <h1 class="mb-0 fs-4"><?= e($projet['nom']) ?></h1>
                    <span class="badge <?= getStatutProjetClass($projet['statut']) ?>"><?= getStatutProjetLabel($projet['statut']) ?></span>
                    <a href="<?= url('/admin/projets/modifier.php?id=' . $projet['id']) ?>" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-pencil"></i>
                    </a>
                </div>
                <small class="text-muted"><i class="bi bi-geo-alt"></i> <?= e($projet['adresse']) ?>, <?= e($projet['ville']) ?></small>
            </div>
        </div>
    </div>

    <!-- Onglets de navigation -->
    <ul class="nav nav-tabs mb-3">
        <li class="nav-item">
            <a class="nav-link" href="<?= url('/admin/projets/detail.php?id=' . $projet['id']) ?>">
                <i class="bi bi-house-door me-1"></i>Base
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="<?= url('/admin/projets/financement.php?id=' . $projet['id']) ?>">
                <i class="bi bi-bank me-1"></i>Financement
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link active" href="<?= url('/admin/projets/budgets.php?id=' . $projet['id']) ?>">
                <i class="bi bi-wallet2 me-1"></i>Budgets
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="<?= url('/admin/projets/main-doeuvre.php?id=' . $projet['id']) ?>">
                <i class="bi bi-people me-1"></i>Main-d'œuvre
            </a>
        </li>
    </ul>

    <?php displayFlashMessage(); ?>

    <!-- Résumé budget total -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card text-center p-3">
                <small class="text-muted">Budget total</small>
                <h4 class="mb-0 text-primary"><?= formatMoney($indicateurs['renovation']['budget']) ?></h4>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center p-3">
                <small class="text-muted">Dépensé</small>
                <h4 class="mb-0 text-danger"><?= formatMoney($indicateurs['renovation']['reel']) ?></h4>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center p-3">
                <small class="text-muted">Restant</small>
                <?php $restant = $indicateurs['renovation']['budget'] - $indicateurs['renovation']['reel']; ?>
                <h4 class="mb-0 <?= $restant >= 0 ? 'text-success' : 'text-danger' ?>"><?= formatMoney($restant) ?></h4>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center p-3">
                <small class="text-muted">Contingence <?= $projet['taux_contingence'] ?>%</small>
                <h4 class="mb-0 text-warning"><?= formatMoney($indicateurs['contingence']) ?></h4>
            </div>
        </div>
    </div>

    <!-- Budgets par catégorie -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-list-check me-2"></i>Budgets par catégorie</span>
            <a href="<?= url('/admin/projets/modifier.php?id=' . $projet['id'] . '#budgets') ?>" class="btn btn-sm btn-primary">
                <i class="bi bi-pencil"></i> Modifier les budgets
            </a>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <?php
                $hasAnyBudget = false;
                foreach ($categories as $cat):
                    $budget = $budgets[$cat['id']] ?? 0;
                    $depense = $depenses[$cat['id']] ?? 0;
                    if ($budget == 0 && $depense == 0) continue;
                    $hasAnyBudget = true;
                    $pct = $budget > 0 ? min(100, ($depense / $budget) * 100) : ($depense > 0 ? 100 : 0);
                    $ecart = $budget - $depense;
                    $progressClass = $pct > 100 ? 'bg-danger' : ($pct > 80 ? 'bg-warning' : 'bg-success');
                ?>
                <div class="col-md-6 col-lg-4">
                    <div class="card budget-card h-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <h6 class="mb-0"><?= e($cat['nom']) ?></h6>
                                <span class="badge <?= $ecart >= 0 ? 'bg-success' : 'bg-danger' ?>">
                                    <?= $ecart >= 0 ? '+' : '' ?><?= formatMoney($ecart) ?>
                                </span>
                            </div>
                            <div class="progress budget-progress mb-2">
                                <div class="progress-bar <?= $progressClass ?>" style="width: <?= min(100, $pct) ?>%"></div>
                            </div>
                            <div class="d-flex justify-content-between small">
                                <span class="text-muted">Dépensé: <?= formatMoney($depense) ?></span>
                                <span class="text-muted">Budget: <?= formatMoney($budget) ?></span>
                            </div>
                            <div class="text-center mt-2">
                                <a href="<?= url('/admin/factures/liste.php?projet=' . $projet['id'] . '&categorie=' . $cat['id']) ?>" class="btn btn-sm btn-outline-secondary">
                                    <i class="bi bi-receipt"></i> Voir factures
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>

                <?php if (!$hasAnyBudget): ?>
                <div class="col-12 text-center py-4">
                    <i class="bi bi-wallet2 text-muted" style="font-size: 3rem;"></i>
                    <p class="text-muted mt-2">Aucun budget configuré</p>
                    <a href="<?= url('/admin/projets/modifier.php?id=' . $projet['id'] . '#budgets') ?>" class="btn btn-primary">
                        <i class="bi bi-plus"></i> Configurer les budgets
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Dernières factures -->
    <div class="card mt-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-receipt me-2"></i>Dernières factures</span>
            <a href="<?= url('/admin/factures/liste.php?projet=' . $projet['id']) ?>" class="btn btn-sm btn-outline-primary">
                Voir toutes
            </a>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Fournisseur</th>
                        <th>Catégorie</th>
                        <th class="text-end">Montant</th>
                        <th class="text-center">Statut</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $stmt = $pdo->prepare("
                    SELECT f.*, c.nom as categorie_nom
                    FROM factures f
                    JOIN categories c ON f.categorie_id = c.id
                    WHERE f.projet_id = ?
                    ORDER BY f.date_facture DESC
                    LIMIT 10
                ");
                $stmt->execute([$projetId]);
                $factures = $stmt->fetchAll();

                if (empty($factures)):
                ?>
                <tr>
                    <td colspan="5" class="text-center text-muted py-4">Aucune facture</td>
                </tr>
                <?php else: ?>
                <?php foreach ($factures as $f): ?>
                <tr>
                    <td><?= formatDate($f['date_facture']) ?></td>
                    <td>
                        <a href="<?= url('/admin/factures/modifier.php?id=' . $f['id']) ?>">
                            <?= e($f['fournisseur']) ?>
                        </a>
                    </td>
                    <td><span class="badge bg-secondary"><?= e($f['categorie_nom']) ?></span></td>
                    <td class="text-end"><?= formatMoney($f['montant_total']) ?></td>
                    <td class="text-center">
                        <?php if ($f['statut'] === 'approuvee'): ?>
                            <span class="badge bg-success">Approuvée</span>
                        <?php elseif ($f['statut'] === 'en_attente'): ?>
                            <span class="badge bg-warning text-dark">En attente</span>
                        <?php else: ?>
                            <span class="badge bg-secondary"><?= ucfirst($f['statut']) ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Actions -->
    <div class="d-flex justify-content-between mt-3 mb-4">
        <a href="<?= url('/admin/projets/liste.php') ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left"></i> Retour à la liste
        </a>
        <div>
            <a href="<?= url('/admin/factures/nouvelle.php?projet=' . $projet['id']) ?>" class="btn btn-success btn-sm">
                <i class="bi bi-plus"></i> Nouvelle facture
            </a>
            <a href="<?= url('/admin/projets/modifier.php?id=' . $projet['id']) ?>" class="btn btn-primary btn-sm">
                <i class="bi bi-pencil"></i> Modifier le projet
            </a>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
