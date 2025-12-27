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

// Migration automatique: ajouter colonne est_payee si elle n'existe pas
try {
    $pdo->query("SELECT est_payee FROM factures LIMIT 1");
} catch (Exception $e) {
    $pdo->exec("ALTER TABLE factures ADD COLUMN est_payee TINYINT(1) DEFAULT 0 AFTER statut");
}

// Migration automatique: ajouter colonne etape_id pour le nouveau système budget-builder
try {
    $pdo->query("SELECT etape_id FROM factures LIMIT 1");
} catch (Exception $e) {
    try {
        $pdo->exec("ALTER TABLE factures ADD COLUMN etape_id INT DEFAULT NULL");
    } catch (Exception $e2) {
        // Colonne existe déjà ou autre erreur
    }
}

// Migration: Retirer les anciennes catégories - mettre categorie_id à NULL
// On utilise maintenant seulement les étapes du budget-builder
try {
    // Vérifier s'il reste des factures avec categorie_id non NULL
    $stmt = $pdo->query("SELECT COUNT(*) FROM factures WHERE categorie_id IS NOT NULL");
    if ($stmt->fetchColumn() > 0) {
        // Retirer la contrainte FK si elle existe
        try {
            $pdo->exec("ALTER TABLE factures DROP FOREIGN KEY factures_ibfk_2");
        } catch (Exception $e) {}
        try {
            $pdo->exec("ALTER TABLE factures DROP FOREIGN KEY fk_factures_categorie");
        } catch (Exception $e) {}

        // Mettre tous les categorie_id à NULL
        $pdo->exec("UPDATE factures SET categorie_id = NULL");
    }
} catch (Exception $e) {
    // Ignorer les erreurs
}

// Traitement du toggle paiement (AJAX ou GET)
if (isset($_GET['toggle_paiement']) && isset($_GET['id'])) {
    $factureId = (int)$_GET['id'];
    $stmt = $pdo->prepare("UPDATE factures SET est_payee = NOT est_payee WHERE id = ?");
    $stmt->execute([$factureId]);

    // Si AJAX, renvoyer le nouveau statut
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        $stmt = $pdo->prepare("SELECT est_payee FROM factures WHERE id = ?");
        $stmt->execute([$factureId]);
        header('Content-Type: application/json');
        echo json_encode(['est_payee' => (bool)$stmt->fetchColumn()]);
        exit;
    }

    // Sinon rediriger
    $redirect = $_SERVER['HTTP_REFERER'] ?? url('/admin/factures/liste.php');
    header('Location: ' . $redirect);
    exit;
}

// Traitement de l'approbation rapide (AJAX ou GET)
if (isset($_GET['approuver']) && isset($_GET['id'])) {
    $factureId = (int)$_GET['id'];
    $stmt = $pdo->prepare("UPDATE factures SET statut = 'approuvee' WHERE id = ? AND statut = 'en_attente'");
    $stmt->execute([$factureId]);

    // Si AJAX, renvoyer le nouveau statut
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'statut' => 'approuvee']);
        exit;
    }

    // Sinon rediriger
    $redirect = $_SERVER['HTTP_REFERER'] ?? url('/admin/factures/liste.php');
    header('Location: ' . $redirect);
    exit;
}

// Répondre aux requêtes AJAX pour le comptage (sans vérification header)
if (isset($_GET['check_count'])) {
    header('Content-Type: text/plain');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    
    $filtreProjet = isset($_GET['projet']) ? (int)$_GET['projet'] : 0;
    $filtreStatut = isset($_GET['statut']) ? $_GET['statut'] : '';
    $filtreCategorie = isset($_GET['categorie']) ? (int)$_GET['categorie'] : 0;
    $filtrePaiement = isset($_GET['paiement']) ? $_GET['paiement'] : '';

    $where = "WHERE 1=1";
    $params = [];
    if ($filtreProjet > 0) { $where .= " AND projet_id = ?"; $params[] = $filtreProjet; }
    if ($filtreStatut !== '') { $where .= " AND statut = ?"; $params[] = $filtreStatut; }
    if ($filtreCategorie > 0) { $where .= " AND categorie_id = ?"; $params[] = $filtreCategorie; }
    if ($filtrePaiement !== '') { $where .= " AND est_payee = ?"; $params[] = ($filtrePaiement === 'paye' ? 1 : 0); }
    
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
$filtrePaiement = isset($_GET['paiement']) ? $_GET['paiement'] : '';

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

if ($filtrePaiement !== '') {
    $where .= " AND f.est_payee = ?";
    $params[] = ($filtrePaiement === 'paye' ? 1 : 0);
}

// Compter le total
$sql = "SELECT COUNT(*) FROM factures f $where";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$totalFactures = $stmt->fetchColumn();
$totalPages = ceil($totalFactures / $perPage);

// Calculer le total impayé (sans le filtre paiement pour avoir le vrai total impayé)
$whereImpaye = "WHERE f.est_payee = 0";
$paramsImpaye = [];
if ($filtreProjet > 0) {
    $whereImpaye .= " AND f.projet_id = ?";
    $paramsImpaye[] = $filtreProjet;
}
if ($filtreStatut !== '') {
    $whereImpaye .= " AND f.statut = ?";
    $paramsImpaye[] = $filtreStatut;
}
if ($filtreCategorie > 0) {
    $whereImpaye .= " AND f.categorie_id = ?";
    $paramsImpaye[] = $filtreCategorie;
}
$sqlImpaye = "SELECT COALESCE(SUM(f.montant_total), 0) FROM factures f $whereImpaye";
$stmtImpaye = $pdo->prepare($sqlImpaye);
$stmtImpaye->execute($paramsImpaye);
$totalImpaye = $stmtImpaye->fetchColumn();

// Récupérer les factures
$sql = "
    SELECT f.*, p.nom as projet_nom, e.nom as etape_nom,
           CONCAT(u.prenom, ' ', u.nom) as employe_nom
    FROM factures f
    JOIN projets p ON f.projet_id = p.id
    LEFT JOIN budget_etapes e ON f.etape_id = e.id
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

// Meta refresh pour auto-reload
$refreshInterval = 15; // secondes

include '../../includes/header.php';
?>

<!-- Auto-refresh simple via meta tag -->
<meta http-equiv="refresh" content="<?= $refreshInterval ?>">

<div class="container-fluid">
    <!-- En-tête -->
    <div class="page-header d-flex justify-content-between align-items-start flex-wrap">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="<?= url('/admin/index.php') ?>">Tableau de bord</a></li>
                    <li class="breadcrumb-item active">Factures</li>
                </ol>
            </nav>
            <h1><i class="bi bi-receipt me-2"></i>Factures</h1>
        </div>
        <div class="d-flex gap-2 mt-2 mt-md-0">
            <a href="<?= url('/admin/factures/approuver.php') ?>" class="btn btn-warning">
                <i class="bi bi-check2-square me-1"></i>À approuver
                <?php 
                $countEnAttente = getFacturesEnAttenteCount($pdo);
                if ($countEnAttente > 0): 
                ?>
                    <span class="badge bg-danger"><?= $countEnAttente ?></span>
                <?php endif; ?>
            </a>
            <a href="<?= url('/admin/factures/nouvelle.php') ?>" class="btn btn-primary">
                <i class="bi bi-plus-circle me-1"></i>Nouvelle facture
            </a>
        </div>
    </div>
    
    <?php displayFlashMessage(); ?>
    
    <!-- Filtres -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" action="" class="row g-3" id="filterForm">
                <div class="col-md-2">
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
                <div class="col-md-2">
                    <label for="statut" class="form-label">Statut</label>
                    <select class="form-select auto-submit" id="statut" name="statut">
                        <option value="">Tous les statuts</option>
                        <option value="en_attente" <?= $filtreStatut === 'en_attente' ? 'selected' : '' ?>>En attente</option>
                        <option value="approuvee" <?= $filtreStatut === 'approuvee' ? 'selected' : '' ?>>Approuvée</option>
                        <option value="rejetee" <?= $filtreStatut === 'rejetee' ? 'selected' : '' ?>>Rejetée</option>
                    </select>
                </div>
                <div class="col-md-2">
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
                <div class="col-md-2">
                    <label for="paiement" class="form-label">Paiement</label>
                    <select class="form-select auto-submit" id="paiement" name="paiement">
                        <option value="">Tous</option>
                        <option value="paye" <?= $filtrePaiement === 'paye' ? 'selected' : '' ?>>Payé</option>
                        <option value="non_paye" <?= $filtrePaiement === 'non_paye' ? 'selected' : '' ?>>Non payé</option>
                    </select>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <a href="<?= url('/admin/factures/liste.php') ?>" class="btn btn-outline-secondary">
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
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><?= $totalFactures ?> facture(s)</span>
            <?php if ($totalImpaye > 0): ?>
                <span class="badge bg-danger">
                    <i class="bi bi-exclamation-circle me-1"></i>Impayé: <?= formatMoney($totalImpaye) ?>
                </span>
            <?php endif; ?>
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
                                <th class="text-center">Paiement</th>
                                <th style="width:50px"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($factures as $facture): 
                                $isImage = $facture['fichier'] && preg_match('/\.(jpg|jpeg|png|gif)$/i', $facture['fichier']);
                                $isPdf = $facture['fichier'] && preg_match('/\.pdf$/i', $facture['fichier']);
                                $isRemboursement = $facture['montant_total'] < 0;
                            ?>
                                <tr onclick="window.location='<?= url('/admin/factures/modifier.php?id=' . $facture['id']) ?>'" style="cursor:pointer" class="<?= $isRemboursement ? 'table-success' : '' ?>">
                                    <td class="text-center" onclick="event.stopPropagation()">
                                        <?php if ($isImage): ?>
                                            <a href="javascript:void(0)" onclick="openImageModal('<?= url('/uploads/factures/' . $facture['fichier']) ?>', '<?= e($facture['fournisseur']) ?>')">
                                                <img src="<?= url('/uploads/factures/' . $facture['fichier']) ?>"
                                                     alt="Facture"
                                                     style="width:40px;height:40px;object-fit:cover;border-radius:4px;border:1px solid #ddd">
                                            </a>
                                        <?php elseif ($isPdf): ?>
                                            <a href="<?= url('/uploads/factures/' . $facture['fichier']) ?>" target="_blank" class="text-danger">
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
                                    <td class="text-center" onclick="event.stopPropagation()">
                                        <?php if ($facture['statut'] === 'en_attente'): ?>
                                            <a href="#" class="badge <?= getStatutFactureClass($facture['statut']) ?>" style="text-decoration:none; cursor:pointer;"
                                               onclick="event.preventDefault(); confirmerApprobation(<?= $facture['id'] ?>, '<?= e(addslashes($facture['fournisseur'])) ?>', '<?= formatMoney($facture['montant_total']) ?>')">
                                                <?= getStatutFactureIcon($facture['statut']) ?>
                                                <?= getStatutFactureLabel($facture['statut']) ?>
                                            </a>
                                        <?php else: ?>
                                            <span class="badge <?= getStatutFactureClass($facture['statut']) ?>">
                                                <?= getStatutFactureIcon($facture['statut']) ?>
                                                <?= getStatutFactureLabel($facture['statut']) ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center" onclick="event.stopPropagation()">
                                        <a href="?toggle_paiement=1&id=<?= $facture['id'] ?>"
                                           class="badge <?= !empty($facture['est_payee']) ? 'bg-success' : 'bg-primary' ?> text-white"
                                           style="cursor:pointer; text-decoration:none;"
                                           title="Cliquer pour changer le statut de paiement">
                                            <?php if (!empty($facture['est_payee'])): ?>
                                                <i class="bi bi-check-circle me-1"></i>Payé
                                            <?php else: ?>
                                                <i class="bi bi-clock me-1"></i>Non payé
                                            <?php endif; ?>
                                        </a>
                                    </td>
                                    <td class="text-center" onclick="event.stopPropagation()">
                                        <button type="button" class="btn btn-outline-danger btn-sm"
                                                onclick="confirmerSuppression(<?= $facture['id'] ?>, '<?= e(addslashes($facture['fournisseur'])) ?>', '<?= formatMoney($facture['montant_total']) ?>')">
                                            <i class="bi bi-trash"></i>
                                        </button>
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

<!-- Style pour centrer les modals -->
<style>
.modal.centered-modal .modal-dialog {
    display: flex;
    align-items: center;
    min-height: 100vh;
    margin: 0 auto;
}
</style>

<!-- Modal de suppression -->
<div class="modal fade centered-modal" id="deleteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="bi bi-trash me-2"></i>Supprimer la facture</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Supprimer cette facture de <strong id="deleteFournisseur"></strong> ?</p>
                <p><strong>Montant :</strong> <span id="deleteMontant"></span></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                <button type="button" class="btn btn-danger" onclick="supprimerFacture()">
                    <i class="bi bi-trash me-1"></i>Oui, supprimer
                </button>
                <form id="deleteForm" action="<?= url('/admin/factures/supprimer.php') ?>" method="POST" style="display:none;">
                    <?php csrfField(); ?>
                    <input type="hidden" name="facture_id" id="deleteFactureId">
                    <input type="hidden" name="redirect" value="/admin/factures/liste.php">
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Modal d'approbation -->
<div class="modal fade centered-modal" id="approveModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="bi bi-check-circle me-2"></i>Approuver la facture</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Approuver cette facture de <strong id="approveFournisseur"></strong> ?</p>
                <p><strong>Montant :</strong> <span id="approveMontant"></span></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                <button type="button" class="btn btn-success" onclick="approuverFacture()">
                    <i class="bi bi-check-circle me-1"></i>Oui, approuver
                </button>
            </div>
        </div>
    </div>
</div>

<script>
let approveFactureId = null;
let approveModal = null;

function confirmerApprobation(id, fournisseur, montant) {
    approveFactureId = id;
    document.getElementById('approveFournisseur').textContent = fournisseur;
    document.getElementById('approveMontant').textContent = montant;
    approveModal = new bootstrap.Modal(document.getElementById('approveModal'));
    approveModal.show();
}

function approuverFacture() {
    if (!approveFactureId) return;

    fetch('?approuver=1&id=' + approveFactureId, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            approveModal.hide();
            window.location.reload();
        }
    })
    .catch(err => {
        window.location.href = '?approuver=1&id=' + approveFactureId;
    });
}

let deleteModal = null;

function confirmerSuppression(id, fournisseur, montant) {
    document.getElementById('deleteFactureId').value = id;
    document.getElementById('deleteFournisseur').textContent = fournisseur;
    document.getElementById('deleteMontant').textContent = montant;
    deleteModal = new bootstrap.Modal(document.getElementById('deleteModal'));
    deleteModal.show();
}

function supprimerFacture() {
    document.getElementById('deleteForm').submit();
}
</script>

<!-- Info: Page auto-refresh toutes les 15 secondes -->

<!-- Modal pour afficher les images de factures -->
<div class="modal fade" id="imageModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="imageModalTitle">Facture</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <div class="modal-body text-center p-0">
                <img src="" id="imageModalImg" style="max-width:100%; max-height:80vh; object-fit:contain;">
            </div>
        </div>
    </div>
</div>

<script>
function openImageModal(imageUrl, fournisseur) {
    document.getElementById('imageModalImg').src = imageUrl;
    document.getElementById('imageModalTitle').textContent = 'Facture - ' + fournisseur;
    new bootstrap.Modal(document.getElementById('imageModal')).show();
}
</script>

<?php include '../../includes/footer.php'; ?>
