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
                <li class="breadcrumb-item"><a href="/employe/index.php">Tableau de bord</a></li>
                <li class="breadcrumb-item active">Mes factures</li>
            </ol>
        </nav>
        <div class="d-flex justify-content-between align-items-center">
            <h1><i class="bi bi-receipt me-2"></i>Mes factures</h1>
            <a href="/employe/nouvelle-facture.php" class="btn btn-primary">
                <i class="bi bi-plus-circle me-1"></i>
                Nouvelle facture
            </a>
        </div>
    </div>
    
    <?php displayFlashMessage(); ?>
    
    <!-- Filtres -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" action="" class="row g-3">
                <div class="col-md-4">
                    <label for="projet" class="form-label">Projet</label>
                    <select class="form-select" id="projet" name="projet">
                        <option value="">Tous les projets</option>
                        <?php foreach ($projets as $projet): ?>
                            <option value="<?= $projet['id'] ?>" <?= $filtreProjet == $projet['id'] ? 'selected' : '' ?>>
                                <?= e($projet['nom']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label for="statut" class="form-label">Statut</label>
                    <select class="form-select" id="statut" name="statut">
                        <option value="">Tous les statuts</option>
                        <option value="en_attente" <?= $filtreStatut === 'en_attente' ? 'selected' : '' ?>>En attente</option>
                        <option value="approuvee" <?= $filtreStatut === 'approuvee' ? 'selected' : '' ?>>Approuvée</option>
                        <option value="rejetee" <?= $filtreStatut === 'rejetee' ? 'selected' : '' ?>>Rejetée</option>
                    </select>
                </div>
                <div class="col-md-4 d-flex align-items-end">
                    <button type="submit" class="btn btn-outline-primary me-2">
                        <i class="bi bi-search me-1"></i>
                        Filtrer
                    </button>
                    <a href="/employe/mes-factures.php" class="btn btn-outline-secondary">
                        <i class="bi bi-x-circle me-1"></i>
                        Réinitialiser
                    </a>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Liste des factures -->
    <div class="card">
        <div class="card-header">
            <span><?= $totalFactures ?> facture(s) trouvée(s)</span>
        </div>
        <div class="card-body p-0">
            <?php if (empty($factures)): ?>
                <div class="empty-state">
                    <i class="bi bi-receipt"></i>
                    <h4>Aucune facture</h4>
                    <p>Aucune facture ne correspond à vos critères.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th style="width:50px"></th>
                                <th>Date</th>
                                <th>Projet</th>
                                <th>Fournisseur</th>
                                <th>Catégorie</th>
                                <th class="text-end">Montant</th>
                                <th class="text-center">Statut</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($factures as $facture): 
                                $isImage = $facture['fichier'] && preg_match('/\.(jpg|jpeg|png|gif)$/i', $facture['fichier']);
                                $isPdf = $facture['fichier'] && preg_match('/\.pdf$/i', $facture['fichier']);
                                $isRemboursement = $facture['montant_total'] < 0;
                                $canEdit = $facture['statut'] === 'en_attente' && canEditFacture($facture['date_creation']);
                            ?>
                                <tr onclick="<?= $canEdit ? "window.location='/employe/modifier-facture.php?id={$facture['id']}'" : '' ?>" 
                                    style="<?= $canEdit ? 'cursor:pointer' : '' ?>" 
                                    class="<?= $isRemboursement ? 'table-success' : '' ?>">
                                    <td class="text-center" onclick="event.stopPropagation()">
                                        <?php if ($isImage): ?>
                                            <a href="/uploads/factures/<?= e($facture['fichier']) ?>" target="_blank">
                                                <img src="/uploads/factures/<?= e($facture['fichier']) ?>" 
                                                     alt="Facture" 
                                                     style="width:40px;height:40px;object-fit:cover;border-radius:4px;border:1px solid #ddd">
                                            </a>
                                        <?php elseif ($isPdf): ?>
                                            <a href="/uploads/factures/<?= e($facture['fichier']) ?>" target="_blank" class="text-danger">
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
                                            <span class="badge bg-success ms-1">Remb.</span>
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
                        <?= generatePagination($page, $totalPages, '/employe/mes-factures.php') ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
