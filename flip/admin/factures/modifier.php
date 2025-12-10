<?php
/**
 * Modifier facture - Admin
 * Flip Manager
 */

require_once '../../config.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

requireAdmin();

$factureId = (int)($_GET['id'] ?? 0);
if (!$factureId) {
    setFlashMessage('danger', 'Facture non trouvée.');
    redirect('/admin/factures/liste.php');
}

// Récupérer la facture
$stmt = $pdo->prepare("SELECT * FROM factures WHERE id = ?");
$stmt->execute([$factureId]);
$facture = $stmt->fetch();

if (!$facture) {
    setFlashMessage('danger', 'Facture non trouvée.');
    redirect('/admin/factures/liste.php');
}

$pageTitle = 'Modifier facture #' . $factureId;
$errors = [];

// Liste des fournisseurs suggérés
$fournisseursSuggeres = [
    'Réno Dépot', 'Rona', 'BMR', 'Patrick Morin', 'Home Depot',
    'J-Jodoin', 'Ly Granite', 'COMMONWEALTH', 'CJP', 'Richelieu',
    'Canac', 'IKEA', 'Lowes', 'Canadian Tire'
];
$stmt = $pdo->query("SELECT DISTINCT fournisseur FROM factures ORDER BY fournisseur ASC LIMIT 50");
$fournisseursUtilises = $stmt->fetchAll(PDO::FETCH_COLUMN);
$tousLesFournisseurs = array_unique(array_merge($fournisseursSuggeres, $fournisseursUtilises));
sort($tousLesFournisseurs);

// Récupérer les projets actifs
$projets = getProjets($pdo);

// Récupérer les catégories groupées
$categoriesGroupees = getCategoriesGrouped($pdo);

// Traitement du formulaire
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token de sécurité invalide.';
    } else {
        $projetId = (int)($_POST['projet_id'] ?? 0);
        $categorieId = (int)($_POST['categorie_id'] ?? 0);
        $fournisseur = trim($_POST['fournisseur'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $dateFacture = $_POST['date_facture'] ?? '';
        $montantAvantTaxes = parseNumber($_POST['montant_avant_taxes'] ?? 0);
        $tps = parseNumber($_POST['tps'] ?? 0);
        $tvq = parseNumber($_POST['tvq'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');
        $statut = $_POST['statut'] ?? $facture['statut'];
        
        // Validation
        if (!$projetId) $errors[] = 'Veuillez sélectionner un projet.';
        if (!$categorieId) $errors[] = 'Veuillez sélectionner une catégorie.';
        if (empty($fournisseur)) $errors[] = 'Le fournisseur est requis.';
        if (empty($dateFacture)) $errors[] = 'La date de la facture est requise.';
        if ($montantAvantTaxes <= 0) $errors[] = 'Le montant avant taxes doit être supérieur à 0.';
        
        // Calculer le total
        $montantTotal = $montantAvantTaxes + $tps + $tvq;
        
        // Nouveau fichier uploadé ?
        $fichier = $facture['fichier'];
        if (isset($_FILES['fichier']) && $_FILES['fichier']['error'] !== UPLOAD_ERR_NO_FILE) {
            $upload = uploadFile($_FILES['fichier']);
            if ($upload['success']) {
                // Supprimer l'ancien fichier
                if ($facture['fichier']) {
                    deleteUploadedFile($facture['fichier']);
                }
                $fichier = $upload['filename'];
            } else {
                $errors[] = $upload['error'];
            }
        }
        
        // Si pas d'erreur, mettre à jour
        if (empty($errors)) {
            $stmt = $pdo->prepare("
                UPDATE factures SET
                    projet_id = ?, categorie_id = ?, fournisseur = ?, description = ?,
                    date_facture = ?, montant_avant_taxes = ?, tps = ?, tvq = ?,
                    montant_total = ?, fichier = ?, notes = ?, statut = ?
                WHERE id = ?
            ");
            
            if ($stmt->execute([
                $projetId, $categorieId, $fournisseur, $description,
                $dateFacture, $montantAvantTaxes, $tps, $tvq,
                $montantTotal, $fichier, $notes, $statut,
                $factureId
            ])) {
                setFlashMessage('success', 'Facture mise à jour!');
                redirect('/admin/factures/liste.php?projet=' . $projetId);
            } else {
                $errors[] = 'Erreur lors de la mise à jour.';
            }
        }
    }
}

include '../../includes/header.php';
?>

<div class="container-fluid">
    <div class="page-header">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= url('/admin/index.php') ?>">Tableau de bord</a></li>
                <li class="breadcrumb-item"><a href="<?= url('/admin/factures/liste.php') ?>">Factures</a></li>
                <li class="breadcrumb-item active">Modifier #<?= $factureId ?></li>
            </ol>
        </nav>
        <h1><i class="bi bi-pencil me-2"></i>Modifier facture #<?= $factureId ?></h1>
    </div>
    
    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger">
            <ul class="mb-0">
                <?php foreach ($errors as $error): ?>
                    <li><?= e($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
    
    <div class="card">
        <div class="card-body">
            <form method="POST" enctype="multipart/form-data">
                <?php csrfField(); ?>
                
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Projet *</label>
                        <select class="form-select" name="projet_id" required>
                            <option value="">Sélectionner...</option>
                            <?php foreach ($projets as $projet): ?>
                                <option value="<?= $projet['id'] ?>" <?= $facture['projet_id'] == $projet['id'] ? 'selected' : '' ?>>
                                    <?= e($projet['nom']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Catégorie *</label>
                        <select class="form-select" name="categorie_id" required>
                            <option value="">Sélectionner...</option>
                            <?php foreach ($categoriesGroupees as $groupe => $cats): ?>
                                <optgroup label="<?= getGroupeCategorieLabel($groupe) ?>">
                                    <?php foreach ($cats as $cat): ?>
                                        <option value="<?= $cat['id'] ?>" <?= $facture['categorie_id'] == $cat['id'] ? 'selected' : '' ?>>
                                            <?= e($cat['nom']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Statut</label>
                        <select class="form-select" name="statut">
                            <option value="en_attente" <?= $facture['statut'] == 'en_attente' ? 'selected' : '' ?>>En attente</option>
                            <option value="approuvee" <?= $facture['statut'] == 'approuvee' ? 'selected' : '' ?>>Approuvée</option>
                            <option value="rejetee" <?= $facture['statut'] == 'rejetee' ? 'selected' : '' ?>>Rejetée</option>
                        </select>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Fournisseur *</label>
                        <input type="text" class="form-control" name="fournisseur" required 
                               list="listeFournisseurs" value="<?= e($facture['fournisseur']) ?>">
                        <datalist id="listeFournisseurs">
                            <?php foreach ($tousLesFournisseurs as $f): ?>
                                <option value="<?= e($f) ?>">
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                    
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Date de la facture *</label>
                        <input type="date" class="form-control" name="date_facture" required 
                               value="<?= e($facture['date_facture']) ?>">
                    </div>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Description</label>
                    <textarea class="form-control" name="description" rows="2"><?= e($facture['description']) ?></textarea>
                </div>
                
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Montant avant taxes *</label>
                        <div class="input-group">
                            <input type="text" class="form-control money-input" name="montant_avant_taxes" 
                                   id="montantAvantTaxes" required value="<?= formatMoney($facture['montant_avant_taxes'], false) ?>">
                            <span class="input-group-text">$</span>
                        </div>
                    </div>
                    
                    <div class="col-md-4 mb-3">
                        <label class="form-label">TPS (5%)</label>
                        <div class="input-group">
                            <input type="text" class="form-control money-input" name="tps" id="tps" 
                                   value="<?= formatMoney($facture['tps'], false) ?>">
                            <span class="input-group-text">$</span>
                        </div>
                    </div>
                    
                    <div class="col-md-4 mb-3">
                        <label class="form-label">TVQ (9.975%)</label>
                        <div class="input-group">
                            <input type="text" class="form-control money-input" name="tvq" id="tvq" 
                                   value="<?= formatMoney($facture['tvq'], false) ?>">
                            <span class="input-group-text">$</span>
                        </div>
                    </div>
                </div>
                
                <div class="mb-3">
                    <div class="alert alert-info d-flex justify-content-between align-items-center mb-0">
                        <div><strong>Total : </strong><span id="totalFacture"><?= formatMoney($facture['montant_total']) ?></span></div>
                        <div>
                            <button type="button" class="btn btn-sm btn-outline-primary me-2" onclick="calculerTaxesAuto()">
                                <i class="bi bi-calculator me-1"></i>Recalculer taxes
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-danger" onclick="sansTaxes()">
                                <i class="bi bi-x-circle me-1"></i>Sans taxes
                            </button>
                        </div>
                    </div>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Photo/PDF de la facture</label>
                    <?php if ($facture['fichier']): 
                        $isImage = preg_match('/\.(jpg|jpeg|png|gif)$/i', $facture['fichier']);
                        $isPdf = preg_match('/\.pdf$/i', $facture['fichier']);
                    ?>
                        <div class="mb-2 d-flex align-items-center gap-3">
                            <?php if ($isImage): ?>
                                <a href="<?= url('/uploads/factures/' . e($facture['fichier'])) ?>" target="_blank">
                                    <img src="<?= url('/uploads/factures/' . e($facture['fichier'])) ?>"
                                         alt="Facture"
                                         style="max-width:150px;max-height:150px;object-fit:contain;border-radius:8px;border:2px solid #ddd">
                                </a>
                            <?php elseif ($isPdf): ?>
                                <a href="<?= url('/uploads/factures/' . e($facture['fichier'])) ?>" target="_blank" class="text-danger">
                                    <i class="bi bi-file-pdf" style="font-size:4rem"></i>
                                </a>
                            <?php endif; ?>
                            <a href="<?= url('/uploads/factures/' . e($facture['fichier'])) ?>" target="_blank" class="btn btn-outline-secondary btn-sm">
                                <i class="bi bi-eye me-1"></i>Voir en grand
                            </a>
                        </div>
                    <?php endif; ?>
                    <input type="file" class="form-control" name="fichier" accept=".jpg,.jpeg,.png,.gif,.pdf">
                    <small class="text-muted">Laisser vide pour conserver le fichier actuel</small>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Notes</label>
                    <textarea class="form-control" name="notes" rows="2"><?= e($facture['notes']) ?></textarea>
                </div>
                
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-check-circle me-1"></i>Enregistrer
                    </button>
                    <a href="<?= url('/admin/factures/liste.php') ?>" class="btn btn-secondary">Annuler</a>
                    <button type="button" class="btn btn-outline-danger ms-auto" data-bs-toggle="modal" data-bs-target="#deleteModal">
                        <i class="bi bi-trash me-1"></i>Supprimer
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal de suppression -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="bi bi-exclamation-triangle me-2"></i>Supprimer la facture</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Supprimer cette facture de <strong><?= e($facture['fournisseur']) ?></strong> ?</p>
                <p><strong>Montant :</strong> <?= formatMoney($facture['montant_total']) ?></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                <form action="<?= url('/admin/factures/supprimer.php') ?>" method="POST" class="d-inline">
                    <?php csrfField(); ?>
                    <input type="hidden" name="facture_id" value="<?= $factureId ?>">
                    <input type="hidden" name="redirect" value="<?= url('/admin/projets/detail.php?id=' . $facture['projet_id'] . '&tab=factures') ?>">
                    <button type="submit" class="btn btn-danger">
                        <i class="bi bi-trash me-1"></i>Supprimer
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
let taxesActives = true;

function calculerTaxesAuto() {
    const montant = parseFloat(document.getElementById('montantAvantTaxes').value.replace(',', '.').replace(/\s/g, '')) || 0;
    document.getElementById('tps').value = (montant * 0.05).toFixed(2);
    document.getElementById('tvq').value = (montant * 0.09975).toFixed(2);
    taxesActives = true;
    document.getElementById('tps').classList.remove('bg-light');
    document.getElementById('tvq').classList.remove('bg-light');
    calculerTotal();
}

function sansTaxes() {
    taxesActives = false;
    document.getElementById('tps').value = '0';
    document.getElementById('tvq').value = '0';
    document.getElementById('tps').classList.add('bg-light');
    document.getElementById('tvq').classList.add('bg-light');
    calculerTotal();
}

function calculerTotal() {
    const montant = parseFloat(document.getElementById('montantAvantTaxes').value.replace(',', '.').replace(/\s/g, '')) || 0;
    const tps = parseFloat(document.getElementById('tps').value.replace(',', '.').replace(/\s/g, '')) || 0;
    const tvq = parseFloat(document.getElementById('tvq').value.replace(',', '.').replace(/\s/g, '')) || 0;
    const total = montant + tps + tvq;
    document.getElementById('totalFacture').textContent = total.toLocaleString('fr-CA', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' $';
}

document.getElementById('montantAvantTaxes').addEventListener('input', function() {
    if (taxesActives) calculerTaxesAuto();
    else calculerTotal();
});
document.getElementById('tps').addEventListener('input', calculerTotal);
document.getElementById('tvq').addEventListener('input', calculerTotal);
</script>

<?php include '../../includes/footer.php'; ?>
