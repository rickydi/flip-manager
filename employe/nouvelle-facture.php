<?php
/**
 * Nouvelle facture - Employé
 * Flip Manager
 */

require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

// Vérifier que l'utilisateur est connecté
requireLogin();

$pageTitle = 'Nouvelle facture';

// Récupérer les projets actifs
$projets = getProjets($pdo);

// Récupérer les catégories groupées
$categoriesGrouped = getCategoriesGrouped($pdo);

// Projet pré-sélectionné
$projetIdSelected = isset($_GET['projet_id']) ? (int)$_GET['projet_id'] : 0;

$errors = [];
$success = false;

// Traitement du formulaire
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validation CSRF
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token de sécurité invalide. Veuillez réessayer.';
    } else {
        // Récupérer les données
        $projetId = (int)($_POST['projet_id'] ?? 0);
        $categorieId = (int)($_POST['categorie_id'] ?? 0);
        $fournisseur = trim($_POST['fournisseur'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $dateFacture = $_POST['date_facture'] ?? '';
        $montantAvantTaxes = (float)($_POST['montant_avant_taxes'] ?? 0);
        $tps = (float)($_POST['tps'] ?? 0);
        $tvq = (float)($_POST['tvq'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');
        
        // Validation
        if ($projetId <= 0) {
            $errors[] = 'Veuillez sélectionner un projet.';
        }
        if ($categorieId <= 0) {
            $errors[] = 'Veuillez sélectionner une catégorie.';
        }
        if (empty($fournisseur)) {
            $errors[] = 'Veuillez entrer le nom du fournisseur.';
        }
        if (empty($dateFacture)) {
            $errors[] = 'Veuillez entrer la date de la facture.';
        }
        if ($montantAvantTaxes <= 0) {
            $errors[] = 'Le montant doit être supérieur à 0.';
        }
        
        // Gérer l'upload du fichier
        $filename = null;
        if (isset($_FILES['fichier']) && $_FILES['fichier']['error'] !== UPLOAD_ERR_NO_FILE) {
            $upload = uploadFile($_FILES['fichier']);
            if ($upload['success']) {
                $filename = $upload['filename'];
            } else {
                $errors[] = $upload['error'];
            }
        }
        
        // Si pas d'erreurs, insérer la facture
        if (empty($errors)) {
            $montantTotal = $montantAvantTaxes + $tps + $tvq;
            
            $stmt = $pdo->prepare("
                INSERT INTO factures (
                    projet_id, categorie_id, user_id, fournisseur, description,
                    date_facture, montant_avant_taxes, tps, tvq, montant_total,
                    fichier, notes, statut
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'en_attente')
            ");
            
            $result = $stmt->execute([
                $projetId,
                $categorieId,
                getCurrentUserId(),
                $fournisseur,
                $description,
                $dateFacture,
                $montantAvantTaxes,
                $tps,
                $tvq,
                $montantTotal,
                $filename,
                $notes
            ]);
            
            if ($result) {
                setFlashMessage('success', 'Facture soumise avec succès!');
                redirect('/employe/index.php');
            } else {
                $errors[] = 'Erreur lors de l\'enregistrement de la facture.';
            }
        }
    }
}

include '../includes/header.php';
?>

<div class="container-fluid">
    <!-- En-tête -->
    <div class="page-header">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="/employe/index.php">Tableau de bord</a></li>
                <li class="breadcrumb-item active">Nouvelle facture</li>
            </ol>
        </nav>
        <h1><i class="bi bi-plus-circle me-2"></i>Nouvelle facture</h1>
    </div>
    
    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger">
            <i class="bi bi-exclamation-triangle me-2"></i>
            <strong>Erreur(s):</strong>
            <ul class="mb-0 mt-2">
                <?php foreach ($errors as $error): ?>
                    <li><?= e($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
    
    <div class="card">
        <div class="card-body">
            <form method="POST" action="" enctype="multipart/form-data" id="factureForm">
                <?php csrfField(); ?>
                
                <div class="row">
                    <!-- Colonne gauche -->
                    <div class="col-md-6">
                        <!-- Projet -->
                        <div class="mb-3">
                            <label for="projet_id" class="form-label">Projet *</label>
                            <select class="form-select" id="projet_id" name="projet_id" required>
                                <option value="">Sélectionner un projet...</option>
                                <?php foreach ($projets as $projet): ?>
                                    <option value="<?= $projet['id'] ?>" 
                                            <?= ($projetIdSelected == $projet['id'] || ($_POST['projet_id'] ?? 0) == $projet['id']) ? 'selected' : '' ?>>
                                        <?= e($projet['nom']) ?> - <?= e($projet['adresse']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <!-- Fournisseur -->
                        <div class="mb-3">
                            <label for="fournisseur" class="form-label">Fournisseur *</label>
                            <input type="text" 
                                   class="form-control" 
                                   id="fournisseur" 
                                   name="fournisseur" 
                                   value="<?= e($_POST['fournisseur'] ?? '') ?>"
                                   placeholder="Nom du fournisseur"
                                   required>
                        </div>
                        
                        <!-- Catégorie -->
                        <div class="mb-3">
                            <label for="categorie_id" class="form-label">Catégorie *</label>
                            <select class="form-select" id="categorie_id" name="categorie_id" required>
                                <option value="">Sélectionner une catégorie...</option>
                                <?php foreach ($categoriesGrouped as $groupe => $cats): ?>
                                    <optgroup label="<?= e(getGroupeCategorieLabel($groupe)) ?>">
                                        <?php foreach ($cats as $cat): ?>
                                            <option value="<?= $cat['id'] ?>" 
                                                    <?= ($_POST['categorie_id'] ?? 0) == $cat['id'] ? 'selected' : '' ?>>
                                                <?= e($cat['nom']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <!-- Date de la facture -->
                        <div class="mb-3">
                            <label for="date_facture" class="form-label">Date de la facture *</label>
                            <input type="date" 
                                   class="form-control" 
                                   id="date_facture" 
                                   name="date_facture" 
                                   value="<?= e($_POST['date_facture'] ?? date('Y-m-d')) ?>"
                                   required>
                        </div>
                        
                        <!-- Description -->
                        <div class="mb-3">
                            <label for="description" class="form-label">Description</label>
                            <textarea class="form-control" 
                                      id="description" 
                                      name="description" 
                                      rows="2"
                                      placeholder="Description des articles/services"><?= e($_POST['description'] ?? '') ?></textarea>
                        </div>
                    </div>
                    
                    <!-- Colonne droite -->
                    <div class="col-md-6">
                        <!-- Montants -->
                        <div class="row mb-3">
                            <div class="col-12">
                                <label for="montant_avant_taxes" class="form-label">Montant avant taxes *</label>
                                <div class="input-group">
                                    <span class="input-group-text">$</span>
                                    <input type="number" 
                                           class="form-control" 
                                           id="montant_avant_taxes" 
                                           name="montant_avant_taxes" 
                                           step="0.01" 
                                           min="0"
                                           value="<?= e($_POST['montant_avant_taxes'] ?? '') ?>"
                                           placeholder="0.00"
                                           required>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-6">
                                <label for="tps" class="form-label">TPS (5%)</label>
                                <div class="input-group">
                                    <span class="input-group-text">$</span>
                                    <input type="number" 
                                           class="form-control" 
                                           id="tps" 
                                           name="tps" 
                                           step="0.01" 
                                           min="0"
                                           value="<?= e($_POST['tps'] ?? '') ?>"
                                           placeholder="0.00">
                                </div>
                            </div>
                            <div class="col-6">
                                <label for="tvq" class="form-label">TVQ (9.975%)</label>
                                <div class="input-group">
                                    <span class="input-group-text">$</span>
                                    <input type="number" 
                                           class="form-control" 
                                           id="tvq" 
                                           name="tvq" 
                                           step="0.01" 
                                           min="0"
                                           value="<?= e($_POST['tvq'] ?? '') ?>"
                                           placeholder="0.00">
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <div class="card bg-light">
                                <div class="card-body text-center">
                                    <h5 class="card-title mb-0">TOTAL</h5>
                                    <h2 class="mb-0 text-primary" id="montant_total_display">0,00 $</h2>
                                    <input type="hidden" id="montant_total" name="montant_total" value="0">
                                </div>
                            </div>
                        </div>
                        
                        <!-- Upload fichier -->
                        <div class="mb-3">
                            <label class="form-label">Photo/PDF de la facture</label>
                            <div class="upload-zone">
                                <i class="bi bi-cloud-arrow-up"></i>
                                <p>Glisser un fichier ou cliquer pour sélectionner</p>
                                <small class="text-muted">JPG, PNG, PDF - Max 5 MB</small>
                            </div>
                            <input type="file" 
                                   class="d-none" 
                                   id="fichier" 
                                   name="fichier" 
                                   accept=".jpg,.jpeg,.png,.gif,.pdf">
                        </div>
                        
                        <!-- Notes -->
                        <div class="mb-3">
                            <label for="notes" class="form-label">Notes (optionnel)</label>
                            <textarea class="form-control" 
                                      id="notes" 
                                      name="notes" 
                                      rows="2"
                                      placeholder="Notes supplémentaires..."><?= e($_POST['notes'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>
                
                <hr>
                
                <div class="d-flex justify-content-between">
                    <a href="/employe/index.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left me-1"></i>
                        Retour
                    </a>
                    <button type="submit" class="btn btn-primary btn-lg">
                        <i class="bi bi-check-circle me-1"></i>
                        Soumettre la facture
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
