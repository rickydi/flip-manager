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

// Créer la table fournisseurs si elle n'existe pas (sans réinsérer les défauts)
try {
    $tableExists = $pdo->query("SHOW TABLES LIKE 'fournisseurs'")->rowCount() > 0;
    if (!$tableExists) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS fournisseurs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                nom VARCHAR(255) NOT NULL UNIQUE,
                actif TINYINT(1) DEFAULT 1,
                date_creation DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        // Insérer les fournisseurs par défaut seulement à la création
        $fournisseursDefaut = ['Réno Dépot', 'Rona', 'BMR', 'Patrick Morin', 'Home Depot',
            'J-Jodoin', 'Ly Granite', 'COMMONWEALTH', 'CJP', 'Richelieu', 'Canac', 'IKEA', 'Lowes', 'Canadian Tire'];
        $stmtInsert = $pdo->prepare("INSERT IGNORE INTO fournisseurs (nom) VALUES (?)");
        foreach ($fournisseursDefaut as $f) {
            $stmtInsert->execute([$f]);
        }
    }
} catch (Exception $e) {
    // Ignorer
}

// Récupérer les fournisseurs depuis la table
$stmt = $pdo->query("SELECT nom FROM fournisseurs WHERE actif = 1 ORDER BY nom ASC");
$tousLesFournisseurs = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Récupérer les projets actifs
$projets = getProjets($pdo);

// Récupérer les catégories groupées (ancien système - fallback)
$categoriesGroupees = getCategoriesGrouped($pdo);

// Récupérer les étapes du budget-builder (nouveau système)
$etapes = [];
try {
    $stmt = $pdo->query("SELECT id, nom FROM budget_etapes ORDER BY ordre, nom");
    $etapes = $stmt->fetchAll();
} catch (Exception $e) {
    // Table n'existe pas encore
}

// Traitement du formulaire
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token de sécurité invalide.';
    } else {
        $projetId = (int)($_POST['projet_id'] ?? 0);
        $etapeId = (int)($_POST['etape_id'] ?? 0);
        $categorieId = (int)($_POST['categorie_id'] ?? 0); // Fallback ancien système
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
        if (!$etapeId && !$categorieId) $errors[] = 'Veuillez sélectionner une étape.';
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
        // Si pas de fichier uploadé mais image collée (base64)
        elseif (!empty($_POST['image_base64'])) {
            $base64Data = $_POST['image_base64'];
            // Extraire le type et les données
            if (preg_match('/^data:image\/(\w+);base64,(.+)$/', $base64Data, $matches)) {
                $extension = $matches[1] === 'jpeg' ? 'jpg' : $matches[1];
                $imageData = base64_decode($matches[2]);

                if ($imageData !== false) {
                    // Générer un nom de fichier unique
                    $fichier = 'facture_' . time() . '_' . uniqid() . '.' . $extension;
                    $uploadDir = __DIR__ . '/../../uploads/factures/';

                    // Créer le dossier s'il n'existe pas
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0755, true);
                    }

                    // Sauvegarder l'image
                    if (!file_put_contents($uploadDir . $fichier, $imageData)) {
                        $fichier = null;
                        $errors[] = 'Erreur lors de la sauvegarde de l\'image collée.';
                    }
                }
            }
        }
        
        // Si pas d'erreur, insérer la facture
        if (empty($errors)) {
            $statut = $approuverDirect ? 'approuvee' : 'en_attente';
            $approuvePar = $approuverDirect ? $_SESSION['user_id'] : null;
            $dateApprobation = $approuverDirect ? date('Y-m-d H:i:s') : null;
            
            $stmt = $pdo->prepare("
                INSERT INTO factures (projet_id, categorie_id, etape_id, user_id, fournisseur, description, date_facture,
                                     montant_avant_taxes, tps, tvq, montant_total, fichier, notes, statut,
                                     approuve_par, date_approbation)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            if ($stmt->execute([
                $projetId, $categorieId ?: null, $etapeId ?: null, $_SESSION['user_id'], $fournisseur, $description,
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
    
    <div class="row">
        <!-- Colonne gauche: Formulaire -->
        <div class="col-lg-7">
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-pencil-square me-2"></i>Informations de la facture
                </div>
                <div class="card-body">
                    <form method="POST" enctype="multipart/form-data" id="factureForm">
                        <?php csrfField(); ?>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Projet *</label>
                                <select class="form-select" name="projet_id" id="projet_id" required>
                            <option value="">Sélectionner...</option>
                            <?php foreach ($projets as $projet): ?>
                                <option value="<?= $projet['id'] ?>" <?= $selectedProjet == $projet['id'] ? 'selected' : '' ?>>
                                    <?= e($projet['nom']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-6 mb-3">
                        <label class="form-label">Étape *</label>
                        <?php if (!empty($etapes)): ?>
                        <select class="form-select" name="etape_id" required>
                            <option value="">Sélectionner...</option>
                            <?php foreach ($etapes as $etape): ?>
                                <option value="<?= $etape['id'] ?>"><?= e($etape['nom']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php else: ?>
                        <!-- Fallback sur catégories si pas d'étapes -->
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
                        <?php endif; ?>
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
                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="recalculerTaxes()">
                                <i class="bi bi-calculator me-1"></i>Recalculer taxes
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-danger" onclick="sansTaxes()">
                                <i class="bi bi-x-circle me-1"></i>Sans taxes
                            </button>
                        </div>
                    </div>
                    <small class="text-muted">Les taxes sont calculées automatiquement. Utilisez "Recalculer taxes" si vous modifiez le montant.</small>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Photo/PDF de la facture</label>
                    <input type="file" class="form-control" name="fichier" id="fichierInput" accept=".jpg,.jpeg,.png,.gif,.pdf">
                    <input type="hidden" name="image_base64" id="imageBase64">
                    <div id="pastedImageInfo" class="d-none mt-2">
                        <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Image collée attachée</span>
                        <button type="button" class="btn btn-sm btn-link text-danger" onclick="clearPastedImage()">Retirer</button>
                    </div>
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

        <!-- Colonne droite: Zone de collage IA -->
        <div class="col-lg-5">
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <i class="bi bi-magic me-2"></i>Remplissage automatique par IA
                </div>
                <div class="card-body">
                    <div id="pasteZone" class="border border-2 border-dashed rounded p-4 text-center"
                         style="min-height: 300px; cursor: pointer; border-color: #6c757d !important; transition: all 0.3s;">
                        <div id="pasteInstructions">
                            <i class="bi bi-clipboard-plus display-1 text-muted"></i>
                            <h5 class="mt-3 text-muted">Collez une image de facture ici</h5>
                            <p class="text-muted mb-0">
                                <kbd>Ctrl</kbd> + <kbd>V</kbd> pour coller<br>
                                <small>ou cliquez pour sélectionner un fichier</small>
                            </p>
                            <input type="file" id="fileInput" accept="image/*" class="d-none">
                        </div>
                        <div id="pastePreview" class="d-none">
                            <img id="previewImage" src="" alt="Aperçu" class="img-fluid rounded mb-3" style="max-height: 250px;">
                            <div id="analysisStatus"></div>
                        </div>
                    </div>

                    <div id="aiResult" class="mt-3 d-none">
                        <div class="alert alert-success">
                            <i class="bi bi-check-circle me-2"></i>
                            <strong>Données extraites!</strong>
                            <span id="confidenceLevel" class="badge bg-success ms-2"></span>
                        </div>
                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="resetPasteZone()">
                            <i class="bi bi-arrow-counterclockwise me-1"></i>Nouvelle image
                        </button>
                    </div>

                    <div id="aiError" class="mt-3 d-none">
                        <div class="alert alert-danger">
                            <i class="bi bi-exclamation-triangle me-2"></i>
                            <span id="errorMessage"></span>
                        </div>
                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="resetPasteZone()">
                            <i class="bi bi-arrow-counterclockwise me-1"></i>Réessayer
                        </button>
                    </div>
                </div>
            </div>

            <div class="card mt-3">
                <div class="card-body">
                    <h6><i class="bi bi-info-circle me-2"></i>Comment ça marche?</h6>
                    <ol class="small mb-0">
                        <li>Faites une capture d'écran de votre facture (<kbd>Win</kbd>+<kbd>Shift</kbd>+<kbd>S</kbd>)</li>
                        <li>Collez l'image dans la zone ci-dessus (<kbd>Ctrl</kbd>+<kbd>V</kbd>)</li>
                        <li>L'IA Claude analyse la facture et remplit automatiquement le formulaire</li>
                        <li>Vérifiez les données et ajustez si nécessaire</li>
                    </ol>
                </div>
            </div>
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

function recalculerTaxes() {
    taxesActives = true;
    document.getElementById('tps').classList.remove('bg-light');
    document.getElementById('tvq').classList.remove('bg-light');
    calculerTaxesAuto();
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
document.getElementById('montantAvantTaxes').addEventListener('input', function() {
    // Réactiver le calcul auto des taxes quand l'utilisateur modifie le montant
    taxesActives = true;
    document.getElementById('tps').classList.remove('bg-light');
    document.getElementById('tvq').classList.remove('bg-light');
    calculerTaxesAuto();
});

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
        body: 'nom=' + encodeURIComponent(nom) + '&csrf_token=<?= generateCSRFToken() ?>'
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

// =============================================
// GESTION DU COLLAGE D'IMAGE ET ANALYSE IA
// =============================================

const pasteZone = document.getElementById('pasteZone');
const pasteInstructions = document.getElementById('pasteInstructions');
const pastePreview = document.getElementById('pastePreview');
const previewImage = document.getElementById('previewImage');
const analysisStatus = document.getElementById('analysisStatus');
const aiResult = document.getElementById('aiResult');
const aiError = document.getElementById('aiError');
const fileInput = document.getElementById('fileInput');

// Clic sur la zone = ouvrir sélecteur de fichier
pasteZone.addEventListener('click', () => fileInput.click());

// Sélection de fichier
fileInput.addEventListener('change', function(e) {
    if (e.target.files && e.target.files[0]) {
        handleImageFile(e.target.files[0]);
    }
});

// Gestion du collage (Ctrl+V)
document.addEventListener('paste', function(e) {
    const items = e.clipboardData?.items;
    if (!items) return;

    for (let item of items) {
        if (item.type.startsWith('image/')) {
            e.preventDefault();
            const file = item.getAsFile();
            handleImageFile(file);
            break;
        }
    }
});

// Drag & Drop
pasteZone.addEventListener('dragover', function(e) {
    e.preventDefault();
    this.style.borderColor = '#0d6efd';
    this.style.backgroundColor = 'rgba(13, 110, 253, 0.1)';
});

pasteZone.addEventListener('dragleave', function(e) {
    e.preventDefault();
    this.style.borderColor = '#6c757d';
    this.style.backgroundColor = '';
});

pasteZone.addEventListener('drop', function(e) {
    e.preventDefault();
    this.style.borderColor = '#6c757d';
    this.style.backgroundColor = '';

    const files = e.dataTransfer?.files;
    if (files && files[0] && files[0].type.startsWith('image/')) {
        handleImageFile(files[0]);
    }
});

function handleImageFile(file) {
    const reader = new FileReader();
    reader.onload = function(e) {
        // Afficher l'aperçu
        previewImage.src = e.target.result;
        pasteInstructions.classList.add('d-none');
        pastePreview.classList.remove('d-none');
        aiResult.classList.add('d-none');
        aiError.classList.add('d-none');

        // Stocker l'image base64 pour l'attacher à la facture
        document.getElementById('imageBase64').value = e.target.result;
        document.getElementById('pastedImageInfo').classList.remove('d-none');

        // Lancer l'analyse
        analyzeImage(e.target.result, file.type);
    };
    reader.readAsDataURL(file);
}

function clearPastedImage() {
    document.getElementById('imageBase64').value = '';
    document.getElementById('pastedImageInfo').classList.add('d-none');
}

function analyzeImage(base64Data, mimeType) {
    analysisStatus.innerHTML = `
        <div class="spinner-border spinner-border-sm text-primary me-2" role="status"></div>
        <span class="text-primary">Analyse en cours par Claude AI...</span>
    `;

    fetch('<?= url('/api/analyse-facture.php') ?>', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            image: base64Data,
            mime_type: mimeType
        })
    })
    .then(response => response.json())
    .then(data => {
        analysisStatus.innerHTML = '';

        if (data.success && data.data) {
            fillFormWithData(data.data);
            aiResult.classList.remove('d-none');

            // Afficher le niveau de confiance
            const confidence = data.data.confiance || 0;
            const confidencePercent = Math.round(confidence * 100);
            const confidenceEl = document.getElementById('confidenceLevel');
            confidenceEl.textContent = confidencePercent + '% confiance';
            confidenceEl.className = 'badge ms-2 ' + (confidencePercent >= 80 ? 'bg-success' : confidencePercent >= 50 ? 'bg-warning' : 'bg-danger');
        } else {
            showError(data.error || 'Erreur lors de l\'analyse');
        }
    })
    .catch(error => {
        analysisStatus.innerHTML = '';
        showError('Erreur de connexion: ' + error.message);
    });
}

function fillFormWithData(data) {
    // Fournisseur
    if (data.fournisseur) {
        const fournisseurSelect = document.getElementById('fournisseur');
        const fournisseurValue = data.fournisseur;

        // Chercher si le fournisseur existe dans la liste
        let found = false;
        for (let option of fournisseurSelect.options) {
            if (option.value.toLowerCase() === fournisseurValue.toLowerCase() ||
                option.text.toLowerCase() === fournisseurValue.toLowerCase()) {
                fournisseurSelect.value = option.value;
                found = true;
                break;
            }
        }

        // Si non trouvé, ajouter à la base de données ET au dropdown
        if (!found && fournisseurValue !== '__autre__') {
            // Ajouter au dropdown
            const newOption = document.createElement('option');
            newOption.value = fournisseurValue;
            newOption.textContent = fournisseurValue + ' (nouveau)';
            const autreOption = fournisseurSelect.querySelector('option[value="__autre__"]');
            fournisseurSelect.insertBefore(newOption, autreOption);
            fournisseurSelect.value = fournisseurValue;

            // Sauvegarder dans la base de données
            fetch('<?= url('/api/fournisseur-ajouter.php') ?>', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'nom=' + encodeURIComponent(fournisseurValue) + '&csrf_token=<?= generateCSRFToken() ?>'
            })
            .then(r => r.json())
            .then(result => {
                if (result.success) {
                    newOption.textContent = fournisseurValue; // Enlever "(nouveau)"
                    console.log('Fournisseur ajouté:', fournisseurValue);
                }
            })
            .catch(err => console.log('Erreur ajout fournisseur:', err));
        }
    }

    // Date
    if (data.date_facture) {
        document.querySelector('input[name="date_facture"]').value = data.date_facture;
    }

    // Description
    if (data.description) {
        document.querySelector('input[name="description"]').value = data.description;
    }

    // Montants
    if (data.montant_avant_taxes) {
        document.getElementById('montantAvantTaxes').value = parseFloat(data.montant_avant_taxes).toFixed(2);
    }
    if (data.tps !== undefined) {
        document.getElementById('tps').value = parseFloat(data.tps).toFixed(2);
        taxesActives = false; // Désactiver le calcul auto
    }
    if (data.tvq !== undefined) {
        document.getElementById('tvq').value = parseFloat(data.tvq).toFixed(2);
    }

    // Catégorie
    if (data.categorie_id) {
        const categorieSelect = document.querySelector('select[name="categorie_id"]');
        if (categorieSelect) {
            categorieSelect.value = data.categorie_id;
        }
    }

    // Notes
    if (data.notes) {
        document.querySelector('textarea[name="notes"]').value = data.notes;
    }

    // Recalculer le total affiché
    calculerTotal();

    // Highlight les champs remplis
    highlightFilledFields();
}

function highlightFilledFields() {
    const fields = ['#fournisseur', 'input[name="date_facture"]', 'input[name="description"]',
                   '#montantAvantTaxes', '#tps', '#tvq', 'select[name="categorie_id"]', 'textarea[name="notes"]'];

    fields.forEach(selector => {
        const el = document.querySelector(selector);
        if (el && el.value) {
            el.classList.add('border-success');
            setTimeout(() => el.classList.remove('border-success'), 3000);
        }
    });
}

function showError(message) {
    aiError.classList.remove('d-none');
    document.getElementById('errorMessage').textContent = message;
}

function resetPasteZone() {
    pasteInstructions.classList.remove('d-none');
    pastePreview.classList.add('d-none');
    aiResult.classList.add('d-none');
    aiError.classList.add('d-none');
    previewImage.src = '';
    fileInput.value = '';
    clearPastedImage();
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
