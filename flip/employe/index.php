<?php
/**
 * Dashboard Employé
 * Flip Manager
 */

require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

// Vérifier que l'utilisateur est connecté
requireLogin();

$pageTitle = __('dashboard');

// Récupérer les projets actifs
$projets = getProjets($pdo);

// Récupérer les dernières factures de l'employé
$userId = getCurrentUserId();
$stmt = $pdo->prepare("
    SELECT f.*, p.nom as projet_nom, c.nom as categorie_nom
    FROM factures f
    JOIN projets p ON f.projet_id = p.id
    JOIN categories c ON f.categorie_id = c.id
    WHERE f.user_id = ?
    ORDER BY f.date_creation DESC
    LIMIT 10
");
$stmt->execute([$userId]);
$mesFactures = $stmt->fetchAll();

// Statistiques
$stmt = $pdo->prepare("SELECT COUNT(*) FROM factures WHERE user_id = ?");
$stmt->execute([$userId]);
$totalFactures = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM factures WHERE user_id = ? AND statut = 'en_attente'");
$stmt->execute([$userId]);
$facturesEnAttente = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM factures WHERE user_id = ? AND statut = 'approuvee'");
$stmt->execute([$userId]);
$facturesApprouvees = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT SUM(montant_total) FROM factures WHERE user_id = ? AND statut = 'approuvee'");
$stmt->execute([$userId]);
$totalMontant = $stmt->fetchColumn() ?: 0;

include '../includes/header.php';
?>

<div class="container-fluid">
    <!-- ========================================== -->
    <!-- INTERFACE MOBILE - Deux gros boutons -->
    <!-- ========================================== -->
    <div class="d-md-none mobile-action-menu">
        <div class="text-center mb-4">
            <h4 class="mb-1"><i class="bi bi-person-circle me-2"></i><?= __('hello') ?>, <?= e(getCurrentUserName()) ?></h4>
            <p class="text-muted small mb-0"><?= __('what_to_do') ?></p>
        </div>

        <div class="d-grid gap-3">
            <a href="<?= url('/employe/nouvelle-facture.php') ?>" class="btn btn-primary btn-lg py-4">
                <i class="bi bi-receipt" style="font-size: 2.5rem;"></i>
                <div class="mt-2 fw-bold" style="font-size: 1.2rem;"><?= __('add_invoice') ?></div>
            </a>
            <a href="<?= url('/employe/feuille-temps.php') ?>" class="btn btn-success btn-lg py-4">
                <i class="bi bi-clock-history" style="font-size: 2.5rem;"></i>
                <div class="mt-2 fw-bold" style="font-size: 1.2rem;"><?= __('add_hours') ?></div>
            </a>
        </div>

        <hr class="my-4">

        <div class="d-flex justify-content-center gap-3">
            <a href="<?= url('/employe/mes-factures.php') ?>" class="btn btn-outline-secondary">
                <i class="bi bi-list me-1"></i><?= __('my_invoices') ?>
            </a>
        </div>
    </div>
    
    <!-- ========================================== -->
    <!-- INTERFACE DESKTOP - Dashboard complet -->
    <!-- ========================================== -->
    <div class="d-none d-md-block">
    <!-- En-tête -->
    <div class="page-header d-flex justify-content-between align-items-start">
        <div>
            <h1><i class="bi bi-speedometer2 me-2"></i><?= __('dashboard') ?></h1>
            <p class="text-muted"><?= __('hello') ?>, <?= e(getCurrentUserName()) ?></p>
        </div>
        <div><?= renderLanguageToggle() ?></div>
    </div>

    <?php displayFlashMessage(); ?>

    <!-- Statistiques -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value"><?= $totalFactures ?></div>
            <div class="stat-label"><?= __('submitted_invoices') ?></div>
        </div>
        <div class="stat-card warning">
            <div class="stat-value"><?= $facturesEnAttente ?></div>
            <div class="stat-label"><?= __('pending') ?></div>
        </div>
        <div class="stat-card success">
            <div class="stat-value"><?= $facturesApprouvees ?></div>
            <div class="stat-label"><?= __('approved') ?></div>
        </div>
        <div class="stat-card primary">
            <div class="stat-value"><?= formatMoney($totalMontant) ?></div>
            <div class="stat-label"><?= __('total_approved') ?></div>
        </div>
    </div>
    
    <!-- Projets actifs -->
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-building me-2"></i><?= __('active_projects') ?></span>
        </div>
        <div class="card-body">
            <?php if (empty($projets)): ?>
                <div class="empty-state">
                    <i class="bi bi-building"></i>
                    <h4><?= __('no_active_projects') ?></h4>
                    <p><?= __('no_projects_msg') ?></p>
                </div>
            <?php else: ?>
                <div class="row">
                    <?php foreach ($projets as $projet): ?>
                        <div class="col-md-6 col-lg-4 mb-3">
                            <div class="card h-100">
                                <div class="card-body">
                                    <h5 class="card-title">
                                        <i class="bi bi-geo-alt me-1"></i>
                                        <?= e($projet['nom']) ?>
                                    </h5>
                                    <p class="card-text text-muted mb-2">
                                        <?= e($projet['adresse']) ?>, <?= e($projet['ville']) ?>
                                    </p>
                                    <span class="badge <?= getStatutProjetClass($projet['statut']) ?>">
                                        <?= getStatutProjetLabel($projet['statut']) ?>
                                    </span>
                                </div>
                                <div class="card-footer bg-transparent">
                                    <a href="<?= url('/employe/nouvelle-facture.php?projet_id=' . $projet['id']) ?>"
                                       class="btn btn-primary btn-sm">
                                        <i class="bi bi-plus-circle me-1"></i>
                                        <?= __('new_invoice') ?>
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Dernières factures -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-receipt me-2"></i><?= __('my_last_invoices') ?></span>
            <a href="<?= url('/employe/mes-factures.php') ?>" class="btn btn-outline-primary btn-sm">
                <?= __('see_all') ?>
            </a>
        </div>
        <div class="card-body p-0">
            <?php if (empty($mesFactures)): ?>
                <div class="empty-state">
                    <i class="bi bi-receipt"></i>
                    <h4><?= __('no_invoices') ?></h4>
                    <p><?= __('no_invoice_yet') ?></p>
                    <a href="<?= url('/employe/nouvelle-facture.php') ?>" class="btn btn-primary">
                        <i class="bi bi-plus-circle me-1"></i>
                        <?= __('submit_invoice') ?>
                    </a>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th><?= __('date') ?></th>
                                <th><?= __('project') ?></th>
                                <th><?= __('supplier') ?></th>
                                <th><?= __('category') ?></th>
                                <th class="text-end"><?= __('amount') ?></th>
                                <th class="text-center"><?= __('status') ?></th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($mesFactures as $facture): ?>
                                <tr>
                                    <td><?= formatDate($facture['date_facture']) ?></td>
                                    <td><?= e($facture['projet_nom']) ?></td>
                                    <td><?= e($facture['fournisseur']) ?></td>
                                    <td><?= e($facture['categorie_nom']) ?></td>
                                    <td class="text-end"><?= formatMoney($facture['montant_total']) ?></td>
                                    <td class="text-center">
                                        <span class="badge <?= getStatutFactureClass($facture['statut']) ?>">
                                            <?= getStatutFactureIcon($facture['statut']) ?>
                                            <?= getStatutFactureLabel($facture['statut']) ?>
                                        </span>
                                    </td>
                                    <td class="action-buttons">
                                        <?php if ($facture['statut'] === 'en_attente' && canEditFacture($facture['date_creation'])): ?>
                                            <a href="<?= url('/employe/modifier-facture.php?id=' . $facture['id']) ?>" 
                                               class="btn btn-outline-primary btn-sm"
                                               title="Modifier">
                                                <i class="bi bi-pencil"></i>
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    </div><!-- Fin interface desktop -->
</div>

<style>
/* Style pour l'interface mobile */
.mobile-action-menu {
    padding: 1.5rem 0;
    min-height: calc(100vh - 120px);
    display: flex;
    flex-direction: column;
    justify-content: center;
}

.mobile-action-menu .btn-lg {
    border-radius: 1rem;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.15);
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.mobile-action-menu .btn-lg:active {
    transform: scale(0.98);
}

[data-theme="dark"] .mobile-action-menu .btn-lg {
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.4);
}
</style>

<?php include '../includes/footer.php'; ?>
