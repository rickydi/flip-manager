<?php
/**
 * Nouvelle facture - Employé
 * Flip Manager
 */

require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/notifications.php';

// Vérifier que l'utilisateur est connecté
requireLogin();

$pageTitle = 'Nouvelle facture';

// Récupérer les projets actifs
$projets = getProjets($pdo);

// Récupérer les catégories groupées
$categoriesGrouped = getCategoriesGrouped($pdo);

// Projet pré-sélectionné
$projetIdSelected = isset($_GET['projet_id']) ? (int)$_GET['projet_id'] : 0;

// Liste des fournisseurs suggérés
$fournisseursSuggeres = [
    'Réno Dépot',
    'Rona',
    'BMR',
    'Patrick Morin',
    'Home Depot',
    'J-Jodoin',
    'Ly Granite',
    'COMMONWEALTH',
    'CJP',
    'Richelieu',
    'Canac',
    'IKEA',
    'Lowes',
    'Canadian Tire'
];

// Récupérer les fournisseurs utilisés récemment
$stmt = $pdo->query("SELECT DISTINCT fournisseur FROM factures ORDER BY fournisseur ASC LIMIT 50");
$fournisseursUtilises = $stmt->fetchAll(PDO::FETCH_COLUMN);
$tousLesFournisseurs = array_unique(array_merge($fournisseursSuggeres, $fournisseursUtilises));
sort($tousLesFournisseurs);

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
            
            // Si remboursement, inverser les montants (valeurs négatives)
            if (isset($_POST['is_remboursement'])) {
                $montantAvantTaxes = -abs($montantAvantTaxes);
                $tps = -abs($tps);
                $tvq = -abs($tvq);
                $montantTotal = -abs($montantTotal);
            }
            
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
                // Envoyer notification Pushover
                $stmt = $pdo->prepare("SELECT nom FROM projets WHERE id = ?");
                $stmt->execute([$projetId]);
                $projetNom = $stmt->fetchColumn();
                notifyNewFacture(getCurrentUserName(), $projetNom, $fournisseur, abs($montantTotal));

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
                <li class="breadcrumb-item"><a href="<?= url('/employe/index.php') ?>"><?= __('dashboard') ?></a></li>
                <li class="breadcrumb-item active"><?= __('new_invoice') ?></li>
            </ol>
        </nav>
        <div class="d-flex justify-content-between align-items-center">
            <h1><i class="bi bi-plus-circle me-2"></i><?= __('new_invoice') ?></h1>
            <?= renderLanguageToggle() ?>
        </div>
    </div>
    
    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger">
            <i class="bi bi-exclamation-triangle me-2"></i>
            <strong><?= __('errors') ?>:</strong>
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
                            <label for="projet_id" class="form-label"><?= __('project') ?> *</label>
                            <select class="form-select" id="projet_id" name="projet_id" required>
                                <option value=""><?= __('select_project') ?></option>
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
                            <label for="fournisseur_select" class="form-label"><?= __('supplier') ?> *</label>
                            <select class="form-select" id="fournisseur_select" onchange="fournisseurSelectChange(this)">
                                <option value=""><?= __('select_supplier') ?></option>
                                <?php foreach ($tousLesFournisseurs as $f): ?>
                                    <option value="<?= e($f) ?>" <?= ($_POST['fournisseur'] ?? '') === $f ? 'selected' : '' ?>><?= e($f) ?></option>
                                <?php endforeach; ?>
                                <option value="__autre__"><?= __('other_new_supplier') ?></option>
                            </select>
                            <input type="text"
                                   class="form-control mt-2"
                                   id="fournisseur_autre"
                                   name="fournisseur_autre"
                                   style="display: none;"
                                   placeholder="<?= __('enter_new_supplier') ?>">
                            <input type="hidden" id="fournisseur" name="fournisseur" value="<?= e($_POST['fournisseur'] ?? '') ?>" required>
                        </div>
                        
                        <!-- Catégorie -->
                        <div class="mb-3">
                            <label for="categorie_id" class="form-label"><?= __('category') ?> *</label>
                            <select class="form-select" id="categorie_id" name="categorie_id" required>
                                <option value=""><?= __('select_category') ?></option>
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
                            <label for="date_facture" class="form-label"><?= __('invoice_date') ?> *</label>
                            <input type="date"
                                   class="form-control"
                                   id="date_facture"
                                   name="date_facture"
                                   value="<?= e($_POST['date_facture'] ?? date('Y-m-d')) ?>"
                                   required>
                        </div>

                        <!-- Description -->
                        <div class="mb-3">
                            <label for="description" class="form-label"><?= __('description') ?></label>
                            <textarea class="form-control"
                                      id="description"
                                      name="description"
                                      rows="2"
                                      placeholder="<?= __('items_description') ?>"><?= e($_POST['description'] ?? '') ?></textarea>
                        </div>
                    </div>
                    
                    <!-- Colonne droite -->
                    <div class="col-md-6">
                        <!-- Type de facture -->
                        <div class="mb-3">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="is_remboursement" name="is_remboursement"
                                       <?= isset($_POST['is_remboursement']) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="is_remboursement">
                                    <i class="bi bi-arrow-return-left text-success me-1"></i>
                                    <strong><?= __('refund_toggle') ?></strong> <small class="text-muted">(<?= __('refund_reduces_cost') ?>)</small>
                                </label>
                            </div>
                        </div>

                        <!-- Montants -->
                        <div class="row mb-3">
                            <div class="col-12">
                                <label for="montant_avant_taxes" class="form-label"><?= __('amount_before_tax') ?> *</label>
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
                            <div class="alert alert-info d-flex justify-content-between align-items-center mb-0">
                                <div>
                                    <strong><?= __('total') ?> : </strong><span id="montant_total_display">0,00 $</span>
                                </div>
                                <div>
                                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="sansTaxes()">
                                        <i class="bi bi-x-circle me-1"></i><?= __('without_taxes') ?>
                                    </button>
                                </div>
                            </div>
                            <small class="text-muted"><?= __('taxes_auto_calc') ?></small>
                            <input type="hidden" id="montant_total" name="montant_total" value="0">
                        </div>

                        <!-- Upload fichier -->
                        <div class="mb-3">
                            <label class="form-label"><?= __('invoice_photo') ?></label>
                            <div class="upload-zone">
                                <i class="bi bi-cloud-arrow-up"></i>
                                <p><?= __('drag_file') ?></p>
                                <small class="text-muted"><?= __('file_formats') ?></small>
                            </div>
                            <input type="file"
                                   class="d-none"
                                   id="fichier"
                                   name="fichier"
                                   accept=".jpg,.jpeg,.png,.gif,.pdf">
                        </div>

                        <!-- Notes -->
                        <div class="mb-3">
                            <label for="notes" class="form-label"><?= __('notes_optional') ?></label>
                            <textarea class="form-control"
                                      id="notes"
                                      name="notes"
                                      rows="2"
                                      placeholder="<?= __('additional_notes') ?>"><?= e($_POST['notes'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>
                
                <hr>

                <div class="d-flex justify-content-between">
                    <a href="<?= url('/employe/index.php') ?>" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left me-1"></i>
                        <?= __('back') ?>
                    </a>
                    <button type="submit" class="btn btn-primary btn-lg">
                        <i class="bi bi-check-circle me-1"></i>
                        <?= __('submit_invoice') ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
let taxesActives = true;

function calculerTaxesAuto() {
    if (!taxesActives) return;
    
    const montant = parseFloat(document.getElementById('montant_avant_taxes').value) || 0;
    const tps = (montant * 0.05).toFixed(2);
    const tvq = (montant * 0.09975).toFixed(2);
    document.getElementById('tps').value = tps;
    document.getElementById('tvq').value = tvq;
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
    const montant = parseFloat(document.getElementById('montant_avant_taxes').value) || 0;
    const tps = parseFloat(document.getElementById('tps').value) || 0;
    const tvq = parseFloat(document.getElementById('tvq').value) || 0;
    const total = montant + tps + tvq;
    document.getElementById('montant_total_display').textContent = total.toLocaleString('fr-CA', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' $';
    document.getElementById('montant_total').value = total;
}

// Calcul automatique des taxes quand on modifie le montant
document.getElementById('montant_avant_taxes').addEventListener('input', calculerTaxesAuto);

// Réactiver les taxes si on modifie manuellement
document.getElementById('tps').addEventListener('focus', function() {
    if (!taxesActives) {
        taxesActives = true;
        this.classList.remove('bg-light');
        document.getElementById('tvq').classList.remove('bg-light');
    }
});
document.getElementById('tvq').addEventListener('focus', function() {
    if (!taxesActives) {
        taxesActives = true;
        this.classList.remove('bg-light');
        document.getElementById('tps').classList.remove('bg-light');
    }
});

document.getElementById('tps').addEventListener('input', calculerTotal);
document.getElementById('tvq').addEventListener('input', calculerTotal);

// Calcul initial
calculerTotal();

// Gestion du fournisseur
function fournisseurSelectChange(select) {
    const autreInput = document.getElementById('fournisseur_autre');
    const fournisseurHidden = document.getElementById('fournisseur');
    
    if (select.value === '__autre__') {
        autreInput.style.display = 'block';
        autreInput.focus();
        autreInput.required = true;
        fournisseurHidden.value = '';
    } else {
        autreInput.style.display = 'none';
        autreInput.required = false;
        autreInput.value = '';
        fournisseurHidden.value = select.value;
    }
}

// Synchroniser l'input "autre" avec le champ hidden
document.getElementById('fournisseur_autre').addEventListener('input', function() {
    document.getElementById('fournisseur').value = this.value;
});

// Initialiser si un fournisseur est déjà sélectionné
(function() {
    const select = document.getElementById('fournisseur_select');
    const fournisseurValue = document.getElementById('fournisseur').value;
    
    if (fournisseurValue) {
        // Vérifier si le fournisseur est dans la liste
        let found = false;
        for (let i = 0; i < select.options.length; i++) {
            if (select.options[i].value === fournisseurValue) {
                select.selectedIndex = i;
                found = true;
                break;
            }
        }
        if (!found && fournisseurValue !== '') {
            // Fournisseur personnalisé, afficher le champ "autre"
            select.value = '__autre__';
            document.getElementById('fournisseur_autre').style.display = 'block';
            document.getElementById('fournisseur_autre').value = fournisseurValue;
        }
    }
})();

</script>

<?php include '../includes/footer.php'; ?>
