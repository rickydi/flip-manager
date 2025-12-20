<?php
/**
 * Mes heures - Employé
 * Flip Manager
 */

require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

// Vérifier que l'utilisateur est connecté
requireLogin();

$pageTitle = 'Mes heures';

// Pagination
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 20;
$offset = getOffset($page, $perPage);

$userId = getCurrentUserId();

// Filtres
$filtreProjet = isset($_GET['projet']) ? (int)$_GET['projet'] : 0;
$filtreStatut = isset($_GET['statut']) ? $_GET['statut'] : '';

// Construire la requête
$where = "WHERE h.user_id = ?";
$params = [$userId];

if ($filtreProjet > 0) {
    $where .= " AND h.projet_id = ?";
    $params[] = $filtreProjet;
}

if ($filtreStatut !== '') {
    $where .= " AND h.statut = ?";
    $params[] = $filtreStatut;
}

// Compter le total
$sql = "SELECT COUNT(*) FROM heures_travaillees h $where";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$totalHeures = $stmt->fetchColumn();
$totalPages = ceil($totalHeures / $perPage);

// Récupérer les heures
$sql = "
    SELECT h.*, p.nom as projet_nom
    FROM heures_travaillees h
    JOIN projets p ON h.projet_id = p.id
    $where
    ORDER BY h.date_travail DESC, h.id DESC
    LIMIT $perPage OFFSET $offset
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$heures = $stmt->fetchAll();

// Récupérer les projets pour le filtre
$projets = getProjets($pdo, false);

// Calculer les totaux
$sqlTotaux = "
    SELECT
        SUM(heures) as total_heures,
        SUM(heures * taux_horaire) as total_montant,
        SUM(CASE WHEN statut = 'en_attente' THEN heures ELSE 0 END) as heures_attente,
        SUM(CASE WHEN statut = 'approuvee' THEN heures ELSE 0 END) as heures_approuvees
    FROM heures_travaillees
    WHERE user_id = ?
";
$stmt = $pdo->prepare($sqlTotaux);
$stmt->execute([$userId]);
$totaux = $stmt->fetch();

include '../includes/header.php';
?>

<div class="container-fluid">
    <!-- En-tête -->
    <div class="page-header">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= url('/employe/index.php') ?>"><?= __('dashboard') ?></a></li>
                <li class="breadcrumb-item active"><?= __('my_hours') ?></li>
            </ol>
        </nav>
        <div class="d-flex justify-content-between align-items-center">
            <h1><i class="bi bi-clock-history me-2"></i><?= __('my_hours') ?></h1>
            <div>
                <?= renderLanguageToggle() ?>
                <a href="<?= url('/employe/feuille-temps.php') ?>" class="btn btn-primary ms-2">
                    <i class="bi bi-plus-circle me-1"></i>
                    <?= __('add_hours') ?>
                </a>
            </div>
        </div>
    </div>

    <?php displayFlashMessage(); ?>

    <!-- Stats -->
    <div class="stats-grid mb-4">
        <div class="stat-card">
            <div class="stat-label"><?= __('total_hours') ?></div>
            <div class="stat-value"><?= number_format($totaux['total_heures'] ?? 0, 1) ?>h</div>
        </div>
        <div class="stat-card warning">
            <div class="stat-label"><?= __('pending') ?></div>
            <div class="stat-value"><?= number_format($totaux['heures_attente'] ?? 0, 1) ?>h</div>
        </div>
        <div class="stat-card success">
            <div class="stat-label"><?= __('approved') ?></div>
            <div class="stat-value"><?= number_format($totaux['heures_approuvees'] ?? 0, 1) ?>h</div>
        </div>
        <div class="stat-card primary">
            <div class="stat-label"><?= __('total_value') ?></div>
            <div class="stat-value"><?= formatMoney($totaux['total_montant'] ?? 0) ?></div>
        </div>
    </div>

    <!-- Filtres -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" action="" class="row g-3">
                <div class="col-md-4">
                    <label for="projet" class="form-label"><?= __('project') ?></label>
                    <select class="form-select" id="projet" name="projet">
                        <option value=""><?= __('all_projects') ?></option>
                        <?php foreach ($projets as $projet): ?>
                            <option value="<?= $projet['id'] ?>" <?= $filtreProjet == $projet['id'] ? 'selected' : '' ?>>
                                <?= e($projet['nom']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label for="statut" class="form-label"><?= __('status') ?></label>
                    <select class="form-select" id="statut" name="statut">
                        <option value=""><?= __('all_statuses') ?></option>
                        <option value="en_attente" <?= $filtreStatut === 'en_attente' ? 'selected' : '' ?>><?= __('pending') ?></option>
                        <option value="approuvee" <?= $filtreStatut === 'approuvee' ? 'selected' : '' ?>><?= __('approved') ?></option>
                    </select>
                </div>
                <div class="col-md-4 d-flex align-items-end">
                    <button type="submit" class="btn btn-outline-primary me-2">
                        <i class="bi bi-search me-1"></i>
                        <?= __('filter') ?>
                    </button>
                    <a href="<?= url('/employe/mes-heures.php') ?>" class="btn btn-outline-secondary">
                        <i class="bi bi-x-circle me-1"></i>
                        <?= __('reset') ?>
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- Liste des heures -->
    <div class="card">
        <div class="card-header">
            <span><?= $totalHeures ?> <?= __('entries_found') ?></span>
        </div>
        <div class="card-body p-0">
            <?php if (empty($heures)): ?>
                <div class="empty-state">
                    <i class="bi bi-clock-history"></i>
                    <h4><?= __('no_hours') ?></h4>
                    <p><?= __('no_match') ?></p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th><?= __('date') ?></th>
                                <th><?= __('project') ?></th>
                                <th class="text-center"><?= __('hours') ?></th>
                                <th class="text-end"><?= __('rate') ?></th>
                                <th class="text-end"><?= __('amount') ?></th>
                                <th class="text-center"><?= __('status') ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($heures as $heure):
                                $montant = $heure['heures'] * $heure['taux_horaire'];
                            ?>
                                <tr>
                                    <td><?= formatDate($heure['date_travail']) ?></td>
                                    <td><?= e($heure['projet_nom']) ?></td>
                                    <td class="text-center">
                                        <strong><?= number_format($heure['heures'], 1) ?>h</strong>
                                    </td>
                                    <td class="text-end"><?= formatMoney($heure['taux_horaire']) ?>/h</td>
                                    <td class="text-end">
                                        <strong><?= formatMoney($montant) ?></strong>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($heure['statut'] === 'approuvee'): ?>
                                            <span class="badge bg-success">
                                                <i class="bi bi-check-circle me-1"></i><?= __('approved') ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-warning text-dark">
                                                <i class="bi bi-hourglass-split me-1"></i><?= __('pending') ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                    <div class="card-footer">
                        <?= generatePagination($page, $totalPages, '/employe/mes-heures.php') ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
