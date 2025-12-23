<?php
/**
 * Historique paye d'un employé
 * Flip Manager
 */

require_once '../../config.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

requireAdmin();

$userId = (int)($_GET['user_id'] ?? 0);

if (!$userId) {
    redirect('/admin/paye/liste.php');
}

// Récupérer l'employé
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$userId]);
$employe = $stmt->fetch();

if (!$employe) {
    setFlashMessage('danger', 'Employé introuvable.');
    redirect('/admin/paye/liste.php');
}

$pageTitle = 'Historique paye - ' . $employe['prenom'] . ' ' . $employe['nom'];

// Récupérer toutes les avances de cet employé
$avances = $pdo->prepare("
    SELECT
        a.*,
        CONCAT(admin.prenom, ' ', admin.nom) AS cree_par_nom
    FROM avances_employes a
    LEFT JOIN users admin ON a.cree_par = admin.id
    WHERE a.user_id = ?
    ORDER BY a.date_avance DESC, a.id DESC
");
$avances->execute([$userId]);
$avances = $avances->fetchAll();

// Récupérer tous les paiements de cet employé
$paiements = $pdo->prepare("
    SELECT
        p.*,
        CONCAT(admin.prenom, ' ', admin.nom) AS paye_par_nom
    FROM paiements_employes p
    LEFT JOIN users admin ON p.paye_par = admin.id
    WHERE p.user_id = ?
    ORDER BY p.semaine_debut DESC
");
$paiements->execute([$userId]);
$paiements = $paiements->fetchAll();

// Calculer les totaux
$totalAvancesActives = 0;
$totalAvancesDeduites = 0;
$totalPaye = 0;

foreach ($avances as $av) {
    if ($av['statut'] === 'active') {
        $totalAvancesActives += $av['montant'];
    } elseif ($av['statut'] === 'deduite') {
        $totalAvancesDeduites += $av['montant'];
    }
}

foreach ($paiements as $p) {
    $totalPaye += $p['montant_net'];
}

include '../../includes/header.php';
?>

<div class="container-fluid">
    <div class="page-header">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= url('/admin/index.php') ?>">Tableau de bord</a></li>
                <li class="breadcrumb-item"><a href="<?= url('/admin/paye/liste.php') ?>">Paye Employés</a></li>
                <li class="breadcrumb-item active">Historique</li>
            </ol>
        </nav>
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h1>
                <i class="bi bi-clock-history me-2"></i>
                <?= e($employe['prenom'] . ' ' . $employe['nom']) ?>
            </h1>
            <a href="<?= url('/admin/paye/liste.php') ?>" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i>Retour
            </a>
        </div>
    </div>

    <?php displayFlashMessage(); ?>

    <!-- Résumé -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <h6 class="text-muted">Taux horaire</h6>
                    <h3 class="text-primary"><?= formatMoney($employe['taux_horaire']) ?>/h</h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <h6 class="text-muted">Avances actives</h6>
                    <h3 class="<?= $totalAvancesActives > 0 ? 'text-danger' : 'text-success' ?>">
                        <?= formatMoney($totalAvancesActives) ?>
                    </h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <h6 class="text-muted">Avances déduites</h6>
                    <h3 class="text-secondary"><?= formatMoney($totalAvancesDeduites) ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <h6 class="text-muted">Total payé</h6>
                    <h3 class="text-success"><?= formatMoney($totalPaye) ?></h3>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Avances -->
        <div class="col-lg-6 mb-4">
            <div class="card h-100">
                <div class="card-header">
                    <i class="bi bi-cash-stack me-2"></i>Avances
                    <span class="badge bg-secondary float-end"><?= count($avances) ?></span>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($avances)): ?>
                        <div class="text-center py-4 text-muted">
                            <p>Aucune avance enregistrée</p>
                        </div>
                    <?php else: ?>
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th class="text-end">Montant</th>
                                    <th>Statut</th>
                                    <th>Note</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($avances as $av): ?>
                                <tr>
                                    <td><?= formatDate($av['date_avance']) ?></td>
                                    <td class="text-end fw-bold"><?= formatMoney($av['montant']) ?></td>
                                    <td>
                                        <?php if ($av['statut'] === 'active'): ?>
                                            <span class="badge bg-warning text-dark">Active</span>
                                        <?php elseif ($av['statut'] === 'deduite'): ?>
                                            <span class="badge bg-success">Déduite</span>
                                            <?php if ($av['deduite_semaine']): ?>
                                                <small class="text-muted d-block">
                                                    Sem. <?= formatDate($av['deduite_semaine']) ?>
                                                </small>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Annulée</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <small class="text-muted">
                                            <?= e($av['raison'] ?: '-') ?>
                                        </small>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Paiements -->
        <div class="col-lg-6 mb-4">
            <div class="card h-100">
                <div class="card-header">
                    <i class="bi bi-wallet2 me-2"></i>Paiements
                    <span class="badge bg-secondary float-end"><?= count($paiements) ?></span>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($paiements)): ?>
                        <div class="text-center py-4 text-muted">
                            <p>Aucun paiement enregistré</p>
                        </div>
                    <?php else: ?>
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Semaine</th>
                                    <th class="text-end">Heures</th>
                                    <th class="text-end">Avances</th>
                                    <th class="text-end">Net</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($paiements as $p): ?>
                                <tr>
                                    <td>
                                        <?= formatDate($p['semaine_debut']) ?>
                                        <?php if ($p['mode_paiement']): ?>
                                            <small class="text-muted d-block">
                                                <?= ucfirst($p['mode_paiement']) ?>
                                                <?= $p['reference_paiement'] ? ' #' . e($p['reference_paiement']) : '' ?>
                                            </small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end"><?= formatMoney($p['montant_heures']) ?></td>
                                    <td class="text-end text-danger">
                                        <?= $p['montant_avances'] > 0 ? '-' . formatMoney($p['montant_avances']) : '-' ?>
                                    </td>
                                    <td class="text-end fw-bold text-success">
                                        <?= formatMoney($p['montant_net']) ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
