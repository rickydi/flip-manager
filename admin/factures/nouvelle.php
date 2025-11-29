<?php
/**
 * Nouvelle facture - Admin
 * Flip Manager
 */

require_once '../../config.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

requireAdmin();

$pageTitle = 'Nouvelle facture';
$errors = [];

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
        $approuverDirect = isset($_POST['approuver_direct']);
        
        // Validation
        if (!$projetId) $errors[] = 'Veuillez sélectionner un projet.';
        if (!$categorieId) $errors[] = 'Veuillez sélectionner une catégorie.';
        if (empty($fournisseur)) $errors[] = 'Le fournisseur est requis.';
        if (empty($dateFacture)) $errors[] = 'La date de la facture est requise.';
        if ($montantAvantTaxes <= 0) $errors[] = 'Le montant avant taxes doit être supérieur à 0.';
        
        // Calculer le total
        $montantTotal = $montantAvantTaxes + $tps + $tvq;
        
        // Upload de fichier
        $fichier = null;
        if (isset($_FILES['fichier']) && $_FILES['fichier']['error'] !== UPLOAD_ERR_NO_FILE) {
            $upload = uploadFile($_FILES['fichier']);
            if ($upload['success']) {
                $fichier = $upload['filename'];
            } else {
                $errors[] = $upload['error'];
            }
        }
        
        // Si pas d'erreur, insérer la facture
        if (empty($errors)) {
            $statut = $approuverDirect ? 'approuvee' : 'en_attente';
            $approuvePar = $approuverDirect ? $_SESSION['user_id'] : null;
            $dateApprobation = $approuverDirect ? date('Y-m-d H:i:s') : null;
            
            $stmt = $pdo->prepare("
                INSERT INTO factures (projet_id, categorie_id, user_id, fournisseur, description, date_facture, 
                                     montant_avant_taxes, tps, tvq, montant_total, fichier, notes, statut, 
                                     approuve_par, date_approbation)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            if ($stmt->execute([
                $projetId, $categorieId, $_SESSION['user_id'], $fournisseur, $description,
                $dateFacture, $montantAvantTaxes, $tps, $tvq, $montantTotal, $fichier, $notes,
                $statut, $approuvePar, $dateApprobation
            ])) {
                $msg = $approuverDirect ? 'Facture ajoutée et approuvée!' : 'Facture ajoutée!';
                setFlashMessage('success', $msg);
                redirect('/admin/factures/liste.php?projet=' . $projetId);
            } else {
                $errors[] = 'Erreur lors de l\'ajout de la facture.';
                if ($fichier) deleteUploadedFile($fichier);
            }
        }
    }
}

// Pré-sélection du projet si passé en paramètre
$selectedProjet = (int)($_GET['projet'] ?? 0);

include '../../includes/header.php';
?>

<div class="container-fluid">
    <div class="page-header">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="/admin/index.php">Tableau de bord</a></li>
                <li class="breadcrumb-item"><a href="/admin/factures/liste.php">Factures</a></li>
                <li class="breadcrumb-item active">Nouvelle facture</li>
            </ol>
        </nav>
        <h1><i class="bi bi-plus-circle me-2"></i>Nouvelle facture</h1>
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
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Projet *</label>
                        <select class="form-select" name="projet_id" required>
                            <option value="">Sélectionner un projet...</option>
                            <?php foreach ($projets as $projet): ?>
                                <option value="<?= $projet['id'] ?>" <?= $selectedProjet == $projet['id'] ? 'selected' : '' ?>>
                                    <?= e($projet['nom']) ?> - <?= e($projet['adresse']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Catégorie *</label>
                        <select class="form-select" name="categorie_id" required>
                            <option value="">Sélectionner une catégorie...</option>
                            <?php foreach ($categoriesGroupees as $groupe => $cats): ?>
                                <optgroup label="<?= getGroupeCategorieLabel($groupe) ?>">
                                    <?php foreach ($cats as $cat): ?>
                                        <option value="<?= $cat['id'] ?>"><?= e($cat['nom']) ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Fournisseur *</label>
                        <input type="text" class="form-control" name="fournisseur" required 
                               placeholder="Ex: Rona, BMR, Home Depot...">
                    </div>
                    
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Date de la facture *</label>
                        <input type="date" class="form-control" name="date_facture" required 
                               value="<?= date('Y-m-d') ?>">
                    </div>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Description</label>
                    <textarea class="form-control" name="description" rows="2" 
                              placeholder="Description des achats..."></textarea>
                </div>
                
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Montant avant taxes *</label>
                        <div class="input-group">
                            <input type="text" class="form-control money-input" name="montant_avant_taxes" 
                                   id="montantAvantTaxes" required placeholder="0.00">
                            <span class="input-group-text">$</span>
                        </div>
                    </div>
                    
                    <div class="col-md-4 mb-3">
                        <label class="form-label">TPS (5%)</label>
                        <div class="input-group">
                            <input type="text" class="form-control money-input" name="tps" id="tps" placeholder="0.00">
                            <span class="input-group-text">$</span>
                        </div>
                    </div>
                    
                    <div class="col-md-4 mb-3">
                        <label class="form-label">TVQ (9.975%)</label>
                        <div class="input-group">
                            <input type="text" class="form-control money-input" name="tvq" id="tvq" placeholder="0.00">
                            <span class="input-group-text">$</span>
                        </div>
                    </div>
                </div>
                
                <div class="mb-3">
                    <div class="alert alert-info mb-0">
                        <strong>Total : </strong><span id="totalFacture">0,00 $</span>
                        <button type="button" class="btn btn-sm btn-outline-primary ms-3" onclick="calculerTaxesAuto()">
                            <i class="bi bi-calculator"></i> Calculer taxes automatiquement
                        </button>
                    </div>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Photo/PDF de la facture</label>
                    <input type="file" class="form-control" name="fichier" accept=".jpg,.jpeg,.png,.gif,.pdf">
                    <small class="text-muted">Formats acceptés: JPG, PNG, GIF, PDF (max 5MB)</small>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Notes</label>
                    <textarea class="form-control" name="notes" rows="2" placeholder="Notes supplémentaires..."></textarea>
                </div>
                
                <div class="mb-3">
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" name="approuver_direct" id="approuverDirect" checked>
                        <label class="form-check-label" for="approuverDirect">
                            <i class="bi bi-check-circle text-success"></i> Approuver directement la facture
                        </label>
                    </div>
                </div>
                
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-circle me-1"></i>Ajouter la facture
                    </button>
                    <a href="/admin/factures/liste.php" class="btn btn-secondary">Annuler</a>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function calculerTaxesAuto() {
    const montant = parseFloat(document.getElementById('montantAvantTaxes').value.replace(',', '.').replace(/\s/g, '')) || 0;
    const tps = (montant * 0.05).toFixed(2);
    const tvq = (montant * 0.09975).toFixed(2);
    document.getElementById('tps').value = tps;
    document.getElementById('tvq').value = tvq;
    calculerTotal();
}

function calculerTotal() {
    const montant = parseFloat(document.getElementById('montantAvantTaxes').value.replace(',', '.').replace(/\s/g, '')) || 0;
    const tps = parseFloat(document.getElementById('tps').value.replace(',', '.').replace(/\s/g, '')) || 0;
    const tvq = parseFloat(document.getElementById('tvq').value.replace(',', '.').replace(/\s/g, '')) || 0;
    const total = montant + tps + tvq;
    document.getElementById('totalFacture').textContent = total.toLocaleString('fr-CA', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' $';
}

document.getElementById('montantAvantTaxes').addEventListener('input', calculerTotal);
document.getElementById('tps').addEventListener('input', calculerTotal);
document.getElementById('tvq').addEventListener('input', calculerTotal);
</script>

<?php include '../../includes/footer.php'; ?>
