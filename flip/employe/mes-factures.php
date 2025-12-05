<?php
/**
 * Mes factures - Employé
 * Flip Manager
 */

require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

// Vérifier que l'utilisateur est connecté
requireLogin();

$pageTitle = 'Mes factures';

// Pagination
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 20;
$offset = getOffset($page, $perPage);

$userId = getCurrentUserId();

// Filtres
$filtreProjet = isset($_GET['projet']) ? (int)$_GET['projet'] : 0;
$filtreStatut = isset($_GET['statut']) ? $_GET['statut'] : '';

// Construire la requête
$where = "WHERE f.user_id = ?";
$params = [$userId];

if ($filtreProjet > 0) {
    $where .= " AND f.projet_id = ?";
    $params[] = $filtreProjet;
}

if ($filtreStatut !== '') {
    $where .= " AND f.statut = ?";
    $params[] = $filtreStatut;
}

// Compter le total
$sql = "SELECT COUNT(*) FROM factures f $where";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$totalFactures = $stmt->fetchColumn();
$totalPages = ceil($totalFactures / $perPage);

// Récupérer les factures
$sql = "
    SELECT f.*, p.nom as projet_nom, c.nom as categorie_nom
    FROM factures f
    JOIN projets p ON f.projet_id = p.id
    JOIN categories c ON f.categorie_id = c.id
    $where
    ORDER BY f.date_creation DESC
    LIMIT $perPage OFFSET $offset
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$factures = $stmt->fetchAll();

// Récupérer les projets pour le filtre
$projets = getProjets($pdo, false);

include '../includes/header.php';
?>

<div class="container-fluid">
    <!-- En-tête -->
    <div class="page-header">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= url('/employe/index.php') ?>"><?= __('dashboard') ?></a></li>
                <li class="breadcrumb-item active"><?= __('my_invoices') ?></li>
            </ol>
        </nav>
        <div class="d-flex justify-content-between align-items-center">
            <h1><i class="bi bi-receipt me-2"></i><?= __('my_invoices') ?></h1>
            <div>
                <?= renderLanguageToggle() ?>
                <a href="<?= url('/employe/nouvelle-facture.php') ?>" class="btn btn-primary ms-2">
                    <i class="bi bi-plus-circle me-1"></i>
                    <?= __('new_invoice') ?>
                </a>
            </div>
        </div>
    </div>
    
    <?php displayFlashMessage(); ?>
    
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
                        <option value="rejetee" <?= $filtreStatut === 'rejetee' ? 'selected' : '' ?>><?= __('rejected') ?></option>
                    </select>
                </div>
                <div class="col-md-4 d-flex align-items-end">
                    <button type="submit" class="btn btn-outline-primary me-2">
                        <i class="bi bi-search me-1"></i>
                        <?= __('filter') ?>
                    </button>
                    <a href="<?= url('/employe/mes-factures.php') ?>" class="btn btn-outline-secondary">
                        <i class="bi bi-x-circle me-1"></i>
                        <?= __('reset') ?>
                    </a>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Liste des factures -->
    <div class="card">
        <div class="card-header">
            <span><?= $totalFactures ?> <?= __('invoices_found') ?></span>
        </div>
        <div class="card-body p-0">
            <?php if (empty($factures)): ?>
                <div class="empty-state">
                    <i class="bi bi-receipt"></i>
                    <h4><?= __('no_invoices') ?></h4>
                    <p><?= __('no_match') ?></p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th style="width:50px"></th>
                                <th><?= __('date') ?></th>
                                <th><?= __('project') ?></th>
                                <th><?= __('supplier') ?></th>
                                <th><?= __('category') ?></th>
                                <th class="text-end"><?= __('amount') ?></th>
                                <th class="text-center"><?= __('status') ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($factures as $facture):
                                $isImage = $facture['fichier'] && preg_match('/\.(jpg|jpeg|png|gif)$/i', $facture['fichier']);
                                $isPdf = $facture['fichier'] && preg_match('/\.pdf$/i', $facture['fichier']);
                                $isRemboursement = $facture['montant_total'] < 0;
                                $canEdit = $facture['statut'] === 'en_attente';
                            ?>
                                <tr onclick="<?= $canEdit ? "window.location='" . url('/employe/modifier-facture.php?id=' . $facture['id']) . "'" : '' ?>"
                                    style="<?= $canEdit ? 'cursor:pointer' : '' ?>"
                                    class="<?= $isRemboursement ? 'table-success' : '' ?>">
                                    <td class="text-center" onclick="event.stopPropagation()">
                                        <?php if ($isImage): ?>
                                            <a href="<?= url('/uploads/factures/' . e($facture['fichier'])) ?>" target="_blank">
                                                <img src="<?= url('/uploads/factures/' . e($facture['fichier'])) ?>"
                                                     alt="Facture"
                                                     style="width:40px;height:40px;object-fit:cover;border-radius:4px;border:1px solid #ddd">
                                            </a>
                                        <?php elseif ($isPdf): ?>
                                            <a href="<?= url('/uploads/factures/' . e($facture['fichier'])) ?>" target="_blank" class="text-danger">
                                                <i class="bi bi-file-pdf" style="font-size:1.5rem"></i>
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted"><i class="bi bi-image" style="font-size:1.2rem"></i></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?= formatDate($facture['date_facture']) ?>
                                    </td>
                                    <td><?= e($facture['projet_nom']) ?></td>
                                    <td>
                                        <?= e($facture['fournisseur']) ?>
                                        <?php if ($isRemboursement): ?>
                                            <span class="badge bg-success ms-1"><?= __('refund') ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= e($facture['categorie_nom']) ?></td>
                                    <td class="text-end">
                                        <strong class="<?= $isRemboursement ? 'text-success' : '' ?>">
                                            <?= $isRemboursement ? '+' : '' ?><?= formatMoney(abs($facture['montant_total'])) ?>
                                        </strong>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge <?= getStatutFactureClass($facture['statut']) ?>">
                                            <?= getStatutFactureIcon($facture['statut']) ?>
                                            <?= getStatutFactureLabel($facture['statut']) ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                    <div class="card-footer">
                        <?= generatePagination($page, $totalPages, url('/employe/mes-factures.php')) ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
