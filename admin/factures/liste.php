<?php
/**
 * Liste des factures - Admin
 * Flip Manager
 */

require_once '../../config.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

// Vérifier que l'utilisateur est admin
requireAdmin();

// Répondre aux requêtes AJAX pour le comptage (sans vérification header)
if (isset($_GET['check_count'])) {
    header('Content-Type: text/plain');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    
    $filtreProjet = isset($_GET['projet']) ? (int)$_GET['projet'] : 0;
    $filtreStatut = isset($_GET['statut']) ? $_GET['statut'] : '';
    $filtreCategorie = isset($_GET['categorie']) ? (int)$_GET['categorie'] : 0;
    
    $where = "WHERE 1=1";
    $params = [];
    if ($filtreProjet > 0) { $where .= " AND projet_id = ?"; $params[] = $filtreProjet; }
    if ($filtreStatut !== '') { $where .= " AND statut = ?"; $params[] = $filtreStatut; }
    if ($filtreCategorie > 0) { $where .= " AND categorie_id = ?"; $params[] = $filtreCategorie; }
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM factures $where");
    $stmt->execute($params);
    echo $stmt->fetchColumn();
    exit;
}

$pageTitle = 'Factures';

// Pagination
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 20;
$offset = getOffset($page, $perPage);

// Filtres
$filtreProjet = isset($_GET['projet']) ? (int)$_GET['projet'] : 0;
$filtreStatut = isset($_GET['statut']) ? $_GET['statut'] : '';
$filtreCategorie = isset($_GET['categorie']) ? (int)$_GET['categorie'] : 0;

// Construire la requête
$where = "WHERE 1=1";
$params = [];

if ($filtreProjet > 0) {
    $where .= " AND f.projet_id = ?";
    $params[] = $filtreProjet;
}

if ($filtreStatut !== '') {
    $where .= " AND f.statut = ?";
    $params[] = $filtreStatut;
}

if ($filtreCategorie > 0) {
    $where .= " AND f.categorie_id = ?";
    $params[] = $filtreCategorie;
}

// Compter le total
$sql = "SELECT COUNT(*) FROM factures f $where";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$totalFactures = $stmt->fetchColumn();
$totalPages = ceil($totalFactures / $perPage);

// Récupérer les factures
$sql = "
    SELECT f.*, p.nom as projet_nom, c.nom as categorie_nom,
           CONCAT(u.prenom, ' ', u.nom) as employe_nom
    FROM factures f
    JOIN projets p ON f.projet_id = p.id
    JOIN categories c ON f.categorie_id = c.id
    JOIN users u ON f.user_id = u.id
    $where
    ORDER BY f.date_creation DESC
    LIMIT $perPage OFFSET $offset
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$factures = $stmt->fetchAll();

// Récupérer les projets et catégories pour les filtres
$projets = getProjets($pdo, false);
$categories = getCategories($pdo);

include '../../includes/header.php';
?>

<div class="container-fluid">
    <!-- En-tête -->
    <div class="page-header d-flex justify-content-between align-items-start flex-wrap">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="/admin/index.php">Tableau de bord</a></li>
                    <li class="breadcrumb-item active">Factures</li>
                </ol>
            </nav>
            <h1><i class="bi bi-receipt me-2"></i>Factures</h1>
        </div>
        <div class="d-flex gap-2 mt-2 mt-md-0">
            <a href="/admin/factures/approuver.php" class="btn btn-warning">
                <i class="bi bi-check2-square me-1"></i>À approuver
                <?php 
                $countEnAttente = getFacturesEnAttenteCount($pdo);
                if ($countEnAttente > 0): 
                ?>
                    <span class="badge bg-danger"><?= $countEnAttente ?></span>
                <?php endif; ?>
            </a>
            <a href="/admin/factures/nouvelle.php" class="btn btn-primary">
                <i class="bi bi-plus-circle me-1"></i>Nouvelle facture
            </a>
        </div>
    </div>
    
    <?php displayFlashMessage(); ?>
    
    <!-- Filtres -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" action="" class="row g-3" id="filterForm">
                <div class="col-md-3">
                    <label for="projet" class="form-label">Projet</label>
                    <select class="form-select auto-submit" id="projet" name="projet">
                        <option value="">Tous les projets</option>
                        <?php foreach ($projets as $projet): ?>
                            <option value="<?= $projet['id'] ?>" <?= $filtreProjet == $projet['id'] ? 'selected' : '' ?>>
                                <?= e($projet['nom']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="statut" class="form-label">Statut</label>
                    <select class="form-select auto-submit" id="statut" name="statut">
                        <option value="">Tous les statuts</option>
                        <option value="en_attente" <?= $filtreStatut === 'en_attente' ? 'selected' : '' ?>>En attente</option>
                        <option value="approuvee" <?= $filtreStatut === 'approuvee' ? 'selected' : '' ?>>Approuvée</option>
                        <option value="rejetee" <?= $filtreStatut === 'rejetee' ? 'selected' : '' ?>>Rejetée</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="categorie" class="form-label">Catégorie</label>
                    <select class="form-select auto-submit" id="categorie" name="categorie">
                        <option value="">Toutes les catégories</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat['id'] ?>" <?= $filtreCategorie == $cat['id'] ? 'selected' : '' ?>>
                                <?= e($cat['nom']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <a href="/admin/factures/liste.php" class="btn btn-outline-secondary">
                        <i class="bi bi-x-circle me-1"></i>Réinitialiser
                    </a>
                </div>
            </form>
        </div>
    </div>
    
    <script>
    document.querySelectorAll('.auto-submit').forEach(function(select) {
        select.addEventListener('change', function() {
            document.getElementById('filterForm').submit();
        });
    });
    </script>
    
    <!-- Liste des factures -->
    <div class="card">
        <div class="card-header">
            <span><?= $totalFactures ?> facture(s)</span>
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
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th style="width:50px"></th>
                                <th>Date</th>
                                <th>Projet</th>
                                <th>Fournisseur</th>
                                <th>Catégorie</th>
                                <th>Employé</th>
                                <th class="text-end">Montant</th>
                                <th class="text-center">Statut</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($factures as $facture): 
                                $isImage = $facture['fichier'] && preg_match('/\.(jpg|jpeg|png|gif)$/i', $facture['fichier']);
                                $isPdf = $facture['fichier'] && preg_match('/\.pdf$/i', $facture['fichier']);
                                $isRemboursement = $facture['montant_total'] < 0;
                            ?>
                                <tr onclick="window.location='/admin/factures/modifier.php?id=<?= $facture['id'] ?>'" style="cursor:pointer" class="<?= $isRemboursement ? 'table-success' : '' ?>">
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
                                    <td><?= formatDate($facture['date_facture']) ?></td>
                                    <td><?= e($facture['projet_nom']) ?></td>
                                    <td>
                                        <?= e($facture['fournisseur']) ?>
                                        <?php if ($isRemboursement): ?>
                                            <span class="badge bg-success ms-1">Remb.</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= e($facture['categorie_nom']) ?></td>
                                    <td><?= e($facture['employe_nom']) ?></td>
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
                
                <?php if ($totalPages > 1): ?>
                    <div class="card-footer">
                        <?= generatePagination($page, $totalPages, '/admin/factures/liste.php') ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Auto-refresh toutes les 10 secondes -->
<script>
(function() {
    var lastCount = <?= (int)$totalFactures ?>;
    var baseUrl = '/admin/factures/liste.php?check_count=1';
    
    console.log('[Auto-refresh] Démarré - count initial: ' + lastCount);
    
    function checkForUpdates() {
        var url = baseUrl + '&t=' + new Date().getTime();
        
        fetch(url)
            .then(function(response) { 
                return response.text(); 
            })
            .then(function(text) {
                var newCount = parseInt(text.trim(), 10);
                console.log('[Auto-refresh] Serveur: ' + newCount + ' | Local: ' + lastCount);
                
                if (!isNaN(newCount) && newCount !== lastCount) {
                    console.log('[Auto-refresh] CHANGEMENT! Rechargement...');
                    window.location.reload();
                }
            })
            .catch(function(err) {
                console.log('[Auto-refresh] Erreur:', err);
            });
    }
    
    // Vérifier toutes les 10 secondes
    setInterval(checkForUpdates, 10000);
})();
</script>

<?php include '../../includes/footer.php'; ?>
