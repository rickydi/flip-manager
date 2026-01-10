<?php
/**
 * Modifier facture - Admin
 * Flip Manager
 */

require_once '../../config.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

requireAdmin();

// Migration automatique: ajouter colonne etape_id si elle n'existe pas
try {
    $pdo->query("SELECT etape_id FROM factures LIMIT 1");
} catch (Exception $e) {
    try {
        $pdo->exec("ALTER TABLE factures ADD COLUMN etape_id INT DEFAULT NULL");
    } catch (Exception $e2) {
        // Colonne existe déjà ou autre erreur
    }
}

// Migration: ajouter colonne rotation si elle n'existe pas
try {
    $pdo->query("SELECT rotation FROM factures LIMIT 1");
} catch (Exception $e) {
    try {
        $pdo->exec("ALTER TABLE factures ADD COLUMN rotation INT DEFAULT 0");
    } catch (Exception $e2) {}
}

$factureId = (int)($_GET['id'] ?? 0);

// AJAX: Sauvegarder la rotation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'rotate') {
    header('Content-Type: application/json');
    $rotation = (int)($_POST['rotation'] ?? 0) % 360;
    $id = (int)($_POST['facture_id'] ?? 0);
    try {
        $stmt = $pdo->prepare("UPDATE factures SET rotation = ? WHERE id = ?");
        $stmt->execute([$rotation, $id]);
        echo json_encode(['success' => true, 'rotation' => $rotation]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

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

// Récupérer les étapes du budget-builder
$etapes = [];
try {
    $stmt = $pdo->query("SELECT id, nom, ordre FROM budget_etapes ORDER BY ordre, nom");
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
        if (!$etapeId) $errors[] = 'Veuillez sélectionner une étape.';
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
            try {
                $stmt = $pdo->prepare("
                    UPDATE factures SET
                        projet_id = ?, etape_id = ?, fournisseur = ?, description = ?,
                        date_facture = ?, montant_avant_taxes = ?, tps = ?, tvq = ?,
                        montant_total = ?, fichier = ?, notes = ?, statut = ?
                    WHERE id = ?
                ");

                if ($stmt->execute([
                    $projetId, $etapeId ?: null, $fournisseur, $description,
                    $dateFacture, $montantAvantTaxes, $tps, $tvq,
                    $montantTotal, $fichier, $notes, $statut,
                    $factureId
                ])) {
                    setFlashMessage('success', 'Facture mise à jour!');
                    redirect('/admin/projets/detail.php?id=' . $projetId . '&tab=factures');
                } else {
                    $errors[] = 'Erreur SQL: ' . implode(' - ', $stmt->errorInfo());
                }
            } catch (Exception $e) {
                $errors[] = 'Exception: ' . $e->getMessage();
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
            <form method="POST" enctype="multipart/form-data" id="factureForm">
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
                        <label class="form-label">Étape *</label>
                        <select class="form-select" name="etape_id" required>
                            <option value="">Sélectionner...</option>
                            <?php foreach ($etapes as $etape): ?>
                                <option value="<?= $etape['id'] ?>" <?= ($facture['etape_id'] ?? 0) == $etape['id'] ? 'selected' : '' ?>>
                                    <?= ($etape['ordre'] + 1) ?>. <?= e($etape['nom']) ?>
                                </option>
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
                        $currentRotation = (int)($facture['rotation'] ?? 0);
                    ?>
                        <div class="mb-2">
                            <?php if ($isImage): ?>
                                <div class="d-flex align-items-start gap-3">
                                    <div class="position-relative" style="display:inline-block;cursor:pointer" onclick="openImageModal()">
                                        <img src="<?= url('/uploads/factures/' . e($facture['fichier'])) ?>"
                                             alt="Facture" id="factureImage"
                                             style="max-width:200px;max-height:200px;object-fit:contain;border-radius:8px;border:2px solid #ddd;transform:rotate(<?= $currentRotation ?>deg);transition:transform 0.3s">
                                    </div>
                                    <div class="d-flex flex-column gap-2">
                                        <div class="btn-group-vertical">
                                            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="rotateImage(-90)" title="Rotation gauche">
                                                <i class="bi bi-arrow-counterclockwise"></i>
                                            </button>
                                            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="rotateImage(90)" title="Rotation droite">
                                                <i class="bi bi-arrow-clockwise"></i>
                                            </button>
                                        </div>
                                        <button type="button" class="btn btn-outline-primary btn-sm" onclick="openImageModal()">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                    </div>
                                </div>
                                <input type="hidden" id="currentRotation" value="<?= $currentRotation ?>">
                                <input type="hidden" id="imageUrl" value="<?= url('/uploads/factures/' . e($facture['fichier'])) ?>">
                            <?php elseif ($isPdf): ?>
                                <div class="d-flex align-items-center gap-3">
                                    <a href="<?= url('/uploads/factures/' . e($facture['fichier'])) ?>" target="_blank" class="text-danger">
                                        <i class="bi bi-file-pdf" style="font-size:4rem"></i>
                                    </a>
                                    <a href="<?= url('/uploads/factures/' . e($facture['fichier'])) ?>" target="_blank" class="btn btn-outline-secondary btn-sm">
                                        <i class="bi bi-eye me-1"></i>Voir PDF
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <input type="file" class="form-control" name="fichier" accept=".jpg,.jpeg,.png,.gif,.pdf">
                    <small class="text-muted">Laisser vide pour conserver le fichier actuel</small>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Notes</label>
                    <textarea class="form-control" name="notes" rows="2"><?= e($facture['notes']) ?></textarea>
                </div>
                
                <div class="d-flex gap-2 mb-5">
                    <a href="<?= url('/admin/factures/liste.php') ?>" class="btn btn-secondary">Annuler</a>
                    <button type="button" class="btn btn-outline-danger ms-auto" data-bs-toggle="modal" data-bs-target="#deleteModal">
                        <i class="bi bi-trash me-1"></i>Supprimer
                    </button>
                </div>

                <!-- Spacer pour le bouton fixe -->
                <div style="height: 70px;"></div>
            </form>
        </div>
    </div>
</div>

<!-- Bouton Enregistrer fixe en bas -->
<div class="fixed-bottom bg-white border-top shadow-lg py-3" style="z-index: 1030;">
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <strong>Total : </strong><span id="totalFactureFixed"><?= formatMoney($facture['montant_total']) ?></span>
            </div>
            <button type="submit" form="factureForm" class="btn btn-success btn-lg px-5">
                <i class="bi bi-check-circle me-2"></i>Enregistrer
            </button>
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
                    <input type="hidden" name="redirect" value="/admin/projets/detail.php?id=<?= $facture['projet_id'] ?>&tab=factures">
                    <button type="submit" class="btn btn-danger">
                        <i class="bi bi-trash me-1"></i>Supprimer
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Modal Image Viewer avec rotation -->
<div class="modal fade" id="imageModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content bg-dark">
            <div class="modal-header border-0 py-2">
                <div class="btn-group">
                    <button type="button" class="btn btn-outline-light btn-sm" onclick="rotateModalImage(-90)">
                        <i class="bi bi-arrow-counterclockwise"></i>
                    </button>
                    <button type="button" class="btn btn-outline-light btn-sm" onclick="rotateModalImage(90)">
                        <i class="bi bi-arrow-clockwise"></i>
                    </button>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center p-2">
                <img src="" id="modalImage" style="max-width:100%;max-height:80vh;transition:transform 0.3s">
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
    const formatted = total.toLocaleString('fr-CA', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' $';
    document.getElementById('totalFacture').textContent = formatted;
    document.getElementById('totalFactureFixed').textContent = formatted;
}

document.getElementById('montantAvantTaxes').addEventListener('input', function() {
    if (taxesActives) calculerTaxesAuto();
    else calculerTotal();
});
document.getElementById('tps').addEventListener('input', calculerTotal);
document.getElementById('tvq').addEventListener('input', calculerTotal);

// Rotation de l'image (preview)
function rotateImage(degrees) {
    const img = document.getElementById('factureImage');
    const rotationInput = document.getElementById('currentRotation');
    if (!img || !rotationInput) return;

    let currentRotation = parseInt(rotationInput.value) || 0;
    currentRotation = (currentRotation + degrees + 360) % 360;
    rotationInput.value = currentRotation;

    img.style.transform = 'rotate(' + currentRotation + 'deg)';

    // Sauvegarder via AJAX
    saveRotation(currentRotation);
}

// Sauvegarder la rotation en DB
function saveRotation(rotation) {
    fetch('', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'ajax_action=rotate&facture_id=<?= $factureId ?>&rotation=' + rotation
    })
    .then(r => r.json())
    .then(data => {
        if (!data.success) console.error('Erreur rotation:', data.error);
    })
    .catch(err => console.error('Erreur:', err));
}

// Ouvrir le modal avec l'image
function openImageModal() {
    const imageUrl = document.getElementById('imageUrl')?.value;
    const rotation = parseInt(document.getElementById('currentRotation')?.value) || 0;
    if (!imageUrl) return;

    const modalImg = document.getElementById('modalImage');
    modalImg.src = imageUrl;
    modalImg.style.transform = 'rotate(' + rotation + 'deg)';

    new bootstrap.Modal(document.getElementById('imageModal')).show();
}

// Rotation dans le modal (et sauvegarde)
function rotateModalImage(degrees) {
    const modalImg = document.getElementById('modalImage');
    const rotationInput = document.getElementById('currentRotation');
    const previewImg = document.getElementById('factureImage');

    let currentRotation = parseInt(rotationInput?.value) || 0;
    currentRotation = (currentRotation + degrees + 360) % 360;

    if (rotationInput) rotationInput.value = currentRotation;

    // Appliquer au modal
    modalImg.style.transform = 'rotate(' + currentRotation + 'deg)';

    // Appliquer au preview aussi
    if (previewImg) previewImg.style.transform = 'rotate(' + currentRotation + 'deg)';

    // Sauvegarder
    saveRotation(currentRotation);
}
</script>

<?php include '../../includes/footer.php'; ?>
