<?php
/**
 * Modifier facture - Employé
 * Flip Manager
 */

require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

// Vérifier que l'utilisateur est connecté
requireLogin();

$pageTitle = 'Modifier facture';

// Récupérer l'ID de la facture
$factureId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($factureId <= 0) {
    setFlashMessage('danger', 'Facture non trouvée.');
    redirect('/employe/mes-factures.php');
}

// Récupérer la facture
$stmt = $pdo->prepare("
    SELECT f.*, p.nom as projet_nom
    FROM factures f
    JOIN projets p ON f.projet_id = p.id
    WHERE f.id = ? AND f.user_id = ?
");
$stmt->execute([$factureId, getCurrentUserId()]);
$facture = $stmt->fetch();

if (!$facture) {
    setFlashMessage('danger', 'Facture non trouvée ou vous n\'avez pas les droits.');
    redirect('/employe/mes-factures.php');
}

// Vérifier que la facture peut être modifiée (tant qu'elle n'est pas approuvée/rejetée)
if ($facture['statut'] !== 'en_attente') {
    setFlashMessage('warning', 'Cette facture ne peut plus être modifiée car elle a été traitée.');
    redirect('/employe/mes-factures.php');
}

// Récupérer les projets actifs
$projets = getProjets($pdo);

// Récupérer les étapes du budget-builder
$etapes = [];
try {
    $stmt = $pdo->query("SELECT id, nom, ordre FROM budget_etapes ORDER BY ordre, nom");
    $etapes = $stmt->fetchAll();
} catch (Exception $e) {
    // Table n'existe pas encore
}

$errors = [];

// Traitement du formulaire
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Vérifier l'action
    if (isset($_POST['action']) && $_POST['action'] === 'supprimer') {
        // Suppression
        if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
            $errors[] = 'Token de sécurité invalide.';
        } else {
            // Supprimer le fichier associé
            if ($facture['fichier']) {
                deleteUploadedFile($facture['fichier']);
            }
            
            $stmt = $pdo->prepare("DELETE FROM factures WHERE id = ?");
            $stmt->execute([$factureId]);
            
            setFlashMessage('success', 'Facture supprimée avec succès.');
            redirect('/employe/mes-factures.php');
        }
    } else {
        // Modification
        if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
            $errors[] = 'Token de sécurité invalide. Veuillez réessayer.';
        } else {
            // Récupérer les données
            $projetId = (int)($_POST['projet_id'] ?? 0);
            $etapeId = (int)($_POST['etape_id'] ?? 0);
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
            if ($etapeId <= 0) {
                $errors[] = 'Veuillez sélectionner une étape.';
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
            $filename = $facture['fichier'];
            if (isset($_FILES['fichier']) && $_FILES['fichier']['error'] !== UPLOAD_ERR_NO_FILE) {
                $upload = uploadFile($_FILES['fichier']);
                if ($upload['success']) {
                    // Supprimer l'ancien fichier
                    if ($facture['fichier']) {
                        deleteUploadedFile($facture['fichier']);
                    }
                    $filename = $upload['filename'];
                } else {
                    $errors[] = $upload['error'];
                }
            }
            
            // Si pas d'erreurs, mettre à jour la facture
            if (empty($errors)) {
                $montantTotal = $montantAvantTaxes + $tps + $tvq;
                
                $stmt = $pdo->prepare("
                    UPDATE factures SET
                        projet_id = ?,
                        etape_id = ?,
                        fournisseur = ?,
                        description = ?,
                        date_facture = ?,
                        montant_avant_taxes = ?,
                        tps = ?,
                        tvq = ?,
                        montant_total = ?,
                        fichier = ?,
                        notes = ?,
                        date_modification = NOW()
                    WHERE id = ?
                ");

                $result = $stmt->execute([
                    $projetId,
                    $etapeId,
                    $fournisseur,
                    $description,
                    $dateFacture,
                    $montantAvantTaxes,
                    $tps,
                    $tvq,
                    $montantTotal,
                    $filename,
                    $notes,
                    $factureId
                ]);
                
                if ($result) {
                    setFlashMessage('success', 'Facture modifiée avec succès!');
                    redirect('/employe/mes-factures.php');
                } else {
                    $errors[] = 'Erreur lors de la modification de la facture.';
                }
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
                <li class="breadcrumb-item"><a href="<?= url('/employe/index.php') ?>">Tableau de bord</a></li>
                <li class="breadcrumb-item"><a href="<?= url('/employe/mes-factures.php') ?>">Mes factures</a></li>
                <li class="breadcrumb-item active">Modifier</li>
            </ol>
        </nav>
        <h1><i class="bi bi-pencil me-2"></i>Modifier la facture</h1>
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
                                            <?= $facture['projet_id'] == $projet['id'] ? 'selected' : '' ?>>
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
                                   value="<?= e($facture['fournisseur']) ?>"
                                   placeholder="Nom du fournisseur"
                                   required>
                        </div>
                        
                        <!-- Étape -->
                        <div class="mb-3">
                            <label for="etape_id" class="form-label">Étape *</label>
                            <select class="form-select" id="etape_id" name="etape_id" required>
                                <option value="">Sélectionner une étape...</option>
                                <?php foreach ($etapes as $etape): ?>
                                    <option value="<?= $etape['id'] ?>"
                                            <?= ($facture['etape_id'] ?? 0) == $etape['id'] ? 'selected' : '' ?>>
                                        <?= ($etape['ordre'] + 1) ?>. <?= e($etape['nom']) ?>
                                    </option>
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
                                   value="<?= e($facture['date_facture']) ?>"
                                   required>
                        </div>
                        
                        <!-- Description -->
                        <div class="mb-3">
                            <label for="description" class="form-label">Description</label>
                            <textarea class="form-control" 
                                      id="description" 
                                      name="description" 
                                      rows="2"
                                      placeholder="Description des articles/services"><?= e($facture['description']) ?></textarea>
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
                                           value="<?= e($facture['montant_avant_taxes']) ?>"
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
                                           value="<?= e($facture['tps']) ?>"
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
                                           value="<?= e($facture['tvq']) ?>"
                                           placeholder="0.00">
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <div class="card bg-light">
                                <div class="card-body text-center">
                                    <h5 class="card-title mb-0">TOTAL</h5>
                                    <h2 class="mb-0 text-primary" id="montant_total_display">
                                        <?= formatMoney($facture['montant_total']) ?>
                                    </h2>
                                    <input type="hidden" id="montant_total" name="montant_total" value="<?= $facture['montant_total'] ?>">
                                </div>
                            </div>
                        </div>
                        
                        <!-- Fichier actuel -->
                        <?php if ($facture['fichier']): 
                            $isImage = preg_match('/\.(jpg|jpeg|png|gif)$/i', $facture['fichier']);
                            $isPdf = preg_match('/\.pdf$/i', $facture['fichier']);
                        ?>
                            <div class="mb-3">
                                <label class="form-label">Fichier actuel</label>
                                <div class="d-flex align-items-center gap-3">
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
                                    <a href="<?= url('/uploads/factures/' . e($facture['fichier'])) ?>"
                                       target="_blank"
                                       class="btn btn-outline-secondary btn-sm">
                                        <i class="bi bi-eye me-1"></i>Voir en grand
                                    </a>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Upload fichier -->
                        <div class="mb-3">
                            <label class="form-label">
                                <?= $facture['fichier'] ? 'Remplacer le fichier' : 'Photo/PDF de la facture' ?>
                            </label>
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
                                      placeholder="Notes supplémentaires..."><?= e($facture['notes']) ?></textarea>
                        </div>
                    </div>
                </div>
                
                <hr>
                
                <div class="d-flex justify-content-between">
                    <div>
                        <a href="<?= url('/employe/mes-factures.php') ?>" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-left me-1"></i>
                            Retour
                        </a>
                        <button type="submit" 
                                name="action" 
                                value="supprimer" 
                                class="btn btn-outline-danger ms-2"
                                onclick="return confirm('Êtes-vous sûr de vouloir supprimer cette facture?')">
                            <i class="bi bi-trash me-1"></i>
                            Supprimer
                        </button>
                    </div>
                    <button type="submit" class="btn btn-primary btn-lg">
                        <i class="bi bi-check-circle me-1"></i>
                        Enregistrer les modifications
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
