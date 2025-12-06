<?php
/**
 * Financement du projet - Admin
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

$pageTitle = $projet['nom'] . ' - Financement';
$indicateurs = calculerIndicateursProjet($pdo, $projet);

include '../../includes/header.php';
?>

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
            <a class="nav-link active" href="<?= url('/admin/projets/financement.php?id=' . $projet['id']) ?>">
                <i class="bi bi-bank me-1"></i>Financement
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="<?= url('/admin/projets/budgets.php?id=' . $projet['id']) ?>">
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

    <!-- Contenu Financement -->
    <div class="row g-3">
        <!-- Prêteurs -->
        <div class="col-md-6">
            <div class="card border-warning h-100">
                <div class="card-header bg-warning text-dark d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-bank me-2"></i>Prêteurs</span>
                    <a href="<?= url('/admin/projets/modifier.php?id=' . $projet['id'] . '#preteurs') ?>" class="btn btn-sm btn-outline-dark">
                        <i class="bi bi-plus"></i> Ajouter
                    </a>
                </div>
                <?php if (!empty($indicateurs['preteurs'])): ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
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
                </div>
                <?php else: ?>
                <div class="card-body text-center text-muted py-4">
                    <i class="bi bi-bank" style="font-size: 3rem;"></i>
                    <p class="mt-2">Aucun prêteur configuré</p>
                    <a href="<?= url('/admin/projets/modifier.php?id=' . $projet['id'] . '#preteurs') ?>" class="btn btn-warning btn-sm">
                        <i class="bi bi-plus"></i> Ajouter un prêteur
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Investisseurs -->
        <div class="col-md-6">
            <div class="card border-success h-100">
                <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-people me-2"></i>Investisseurs</span>
                    <a href="<?= url('/admin/projets/modifier.php?id=' . $projet['id'] . '#investisseurs') ?>" class="btn btn-sm btn-outline-light">
                        <i class="bi bi-plus"></i> Ajouter
                    </a>
                </div>
                <?php if (!empty($indicateurs['investisseurs'])): ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Nom</th>
                                <th class="text-end">Mise de fonds</th>
                                <th class="text-center">Part %</th>
                                <th class="text-end">Profit estimé</th>
                            </tr>
                        </thead>
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
                <div class="card-body text-center text-muted py-4">
                    <i class="bi bi-people" style="font-size: 3rem;"></i>
                    <p class="mt-2">Aucun investisseur configuré</p>
                    <a href="<?= url('/admin/projets/modifier.php?id=' . $projet['id'] . '#investisseurs') ?>" class="btn btn-success btn-sm">
                        <i class="bi bi-plus"></i> Ajouter un investisseur
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Résumé financement -->
    <div class="card mt-3">
        <div class="card-header">
            <i class="bi bi-calculator me-2"></i>Résumé du financement
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3">
                    <div class="text-center p-3 bg-light rounded">
                        <small class="text-muted">Prix d'achat</small>
                        <h4 class="mb-0"><?= formatMoney($projet['prix_achat']) ?></h4>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="text-center p-3 bg-warning bg-opacity-25 rounded">
                        <small class="text-muted">Total prêts</small>
                        <h4 class="mb-0"><?= formatMoney($indicateurs['total_prets']) ?></h4>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="text-center p-3 bg-danger bg-opacity-25 rounded">
                        <small class="text-muted">Intérêts totaux</small>
                        <h4 class="mb-0 text-danger"><?= formatMoney($indicateurs['total_interets']) ?></h4>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="text-center p-3 bg-success bg-opacity-25 rounded">
                        <small class="text-muted">Équité potentielle</small>
                        <h4 class="mb-0 text-success"><?= formatMoney($indicateurs['equite_potentielle']) ?></h4>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Actions -->
    <div class="d-flex justify-content-between mt-3 mb-4">
        <a href="<?= url('/admin/projets/liste.php') ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left"></i> Retour à la liste
        </a>
        <a href="<?= url('/admin/projets/modifier.php?id=' . $projet['id']) ?>" class="btn btn-primary btn-sm">
            <i class="bi bi-pencil"></i> Modifier le projet
        </a>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
