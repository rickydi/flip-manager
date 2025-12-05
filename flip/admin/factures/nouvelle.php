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

// Créer la table fournisseurs si elle n'existe pas
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS fournisseurs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nom VARCHAR(255) NOT NULL UNIQUE,
            actif TINYINT(1) DEFAULT 1,
            date_creation DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Exception $e) {
    // Ignorer
}

// Récupérer les fournisseurs depuis la table
$stmt = $pdo->query("SELECT nom FROM fournisseurs WHERE actif = 1 ORDER BY nom ASC");
$tousLesFournisseurs = $stmt->fetchAll(PDO::FETCH_COLUMN);

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
        
        // Si remboursement, inverser les montants (valeurs négatives)
        if (isset($_POST['is_remboursement'])) {
            $montantAvantTaxes = -abs($montantAvantTaxes);
            $tps = -abs($tps);
            $tvq = -abs($tvq);
            $montantTotal = -abs($montantTotal);
        }
        
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
                <li class="breadcrumb-item"><a href="<?= url('/admin/index.php') ?>">Tableau de bord</a></li>
                <li class="breadcrumb-item"><a href="<?= url('/admin/factures/liste.php') ?>">Factures</a></li>
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
                            <option value="">Sélectionner...</option>
                            <?php foreach ($projets as $projet): ?>
                                <option value="<?= $projet['id'] ?>" <?= $selectedProjet == $projet['id'] ? 'selected' : '' ?>>
                                    <?= e($projet['nom']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-6 mb-3">
                        <label class="form-label">Catégorie *</label>
                        <select class="form-select" name="categorie_id" required>
                            <option value="">Sélectionner...</option>
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
                        <select class="form-select" name="fournisseur" id="fournisseur" required>
                            <option value="">Sélectionner...</option>
                            <?php foreach ($tousLesFournisseurs as $f): ?>
                                <option value="<?= e($f) ?>"><?= e($f) ?></option>
                            <?php endforeach; ?>
                            <option value="__autre__">➕ Autre (ajouter nouveau)</option>
                        </select>
                    </div>

                    <div class="col-md-6 mb-3">
                        <label class="form-label">Date de la facture *</label>
                        <input type="date" class="form-control" name="date_facture" required
                               value="<?= date('Y-m-d') ?>">
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Description</label>
                    <input type="text" class="form-control" name="description"
                           placeholder="Description des achats...">
                </div>

                <!-- Type de facture -->
                <div class="mb-3">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="is_remboursement" name="is_remboursement">
                        <label class="form-check-label" for="is_remboursement">
                            <i class="bi bi-arrow-return-left text-success me-1"></i>
                            <strong>Remboursement</strong> <small class="text-muted">(réduit le coût du projet)</small>
                        </label>
                    </div>
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
                    <div class="alert alert-info d-flex justify-content-between align-items-center mb-0">
                        <div>
                            <strong>Total : </strong><span id="totalFacture">0,00 $</span>
                        </div>
                        <div>
                            <button type="button" class="btn btn-sm btn-outline-danger" onclick="sansTaxes()">
                                <i class="bi bi-x-circle me-1"></i>Sans taxes
                            </button>
                        </div>
                    </div>
                    <small class="text-muted">Les taxes sont calculées automatiquement. Utilisez "Sans taxes" pour les cas particuliers.</small>
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
                    <a href="<?= url('/admin/factures/liste.php') ?>" class="btn btn-secondary">Annuler</a>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
let taxesActives = true;

function calculerTaxesAuto() {
    if (!taxesActives) return;
    
    const montant = parseFloat(document.getElementById('montantAvantTaxes').value.replace(',', '.').replace(/\s/g, '')) || 0;
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

function activerTaxes() {
    taxesActives = true;
    document.getElementById('tps').classList.remove('bg-light');
    document.getElementById('tvq').classList.remove('bg-light');
    calculerTaxesAuto();
}

function calculerTotal() {
    const montant = parseFloat(document.getElementById('montantAvantTaxes').value.replace(',', '.').replace(/\s/g, '')) || 0;
    const tps = parseFloat(document.getElementById('tps').value.replace(',', '.').replace(/\s/g, '')) || 0;
    const tvq = parseFloat(document.getElementById('tvq').value.replace(',', '.').replace(/\s/g, '')) || 0;
    const total = montant + tps + tvq;
    document.getElementById('totalFacture').textContent = total.toLocaleString('fr-CA', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' $';
}

// Calcul automatique des taxes quand on modifie le montant
document.getElementById('montantAvantTaxes').addEventListener('input', calculerTaxesAuto);

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

// Gestion du fournisseur "Autre"
document.getElementById('fournisseur').addEventListener('change', function() {
    if (this.value === '__autre__') {
        this.value = ''; // Reset la sélection
        new bootstrap.Modal(document.getElementById('nouveauFournisseurModal')).show();
    }
});

function ajouterFournisseur() {
    const nom = document.getElementById('nouveauFournisseurNom').value.trim();
    if (!nom) {
        alert('Veuillez entrer le nom du fournisseur');
        return;
    }

    // Envoyer en AJAX
    fetch('<?= url('/api/fournisseur-ajouter.php') ?>', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'nom=' + encodeURIComponent(nom) + '&csrf_token=<?= getCSRFToken() ?>'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Ajouter le nouveau fournisseur au dropdown
            const select = document.getElementById('fournisseur');
            const newOption = document.createElement('option');
            newOption.value = nom;
            newOption.textContent = nom;

            // Insérer avant "Autre"
            const autreOption = select.querySelector('option[value="__autre__"]');
            select.insertBefore(newOption, autreOption);

            // Sélectionner le nouveau fournisseur
            select.value = nom;

            // Fermer le modal
            bootstrap.Modal.getInstance(document.getElementById('nouveauFournisseurModal')).hide();
            document.getElementById('nouveauFournisseurNom').value = '';
        } else {
            alert(data.error || 'Erreur lors de l\'ajout');
        }
    })
    .catch(error => {
        alert('Erreur de connexion');
    });
}
</script>

<!-- Modal Nouveau Fournisseur -->
<div class="modal fade" id="nouveauFournisseurModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>Nouveau fournisseur</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Nom du fournisseur *</label>
                    <input type="text" class="form-control" id="nouveauFournisseurNom"
                           placeholder="Ex: Home Depot" autofocus>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                <button type="button" class="btn btn-primary" onclick="ajouterFournisseur()">
                    <i class="bi bi-plus-circle me-1"></i>Ajouter
                </button>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
