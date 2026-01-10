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

// Migration: créer table facture_lignes pour stocker le breakdown par étape
try {
    $pdo->query("SELECT 1 FROM facture_lignes LIMIT 1");
} catch (Exception $e) {
    $pdo->exec("
        CREATE TABLE facture_lignes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            facture_id INT NOT NULL,
            description VARCHAR(500),
            quantite DECIMAL(10,2) DEFAULT 1,
            prix_unitaire DECIMAL(10,2) DEFAULT 0,
            total DECIMAL(10,2) DEFAULT 0,
            etape_id INT DEFAULT NULL,
            etape_nom VARCHAR(100),
            raison VARCHAR(255),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_facture (facture_id),
            INDEX idx_etape (etape_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
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
                        <div class="input-group">
                            <select class="form-select" name="etape_id" id="etapeSelect" required>
                                <option value="">Sélectionner...</option>
                                <?php foreach ($etapes as $etape): ?>
                                    <option value="<?= $etape['id'] ?>" <?= ($facture['etape_id'] ?? 0) == $etape['id'] ? 'selected' : '' ?>>
                                        <?= ($etape['ordre'] + 1) ?>. <?= e($etape['nom']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($facture['fichier'] && preg_match('/\.(jpg|jpeg|png|gif)$/i', $facture['fichier'])): ?>
                            <button type="button" class="btn btn-outline-primary" onclick="autoDetectEtape()" id="btnAutoDetect" title="Analyser avec IA">
                                <i class="bi bi-magic"></i>
                            </button>
                            <?php endif; ?>
                        </div>
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
                                    <div class="position-relative d-flex align-items-center justify-content-center"
                                         style="width:200px;height:200px;cursor:pointer;overflow:hidden;border-radius:8px;border:2px solid #ddd;background:#f8f9fa"
                                         onclick="openImageModal()">
                                        <img src="<?= url('/api/thumbnail.php?file=factures/' . e($facture['fichier']) . '&w=200&h=200') ?>"
                                             alt="Facture" id="factureImage" loading="lazy"
                                             style="max-width:180px;max-height:180px;object-fit:contain;transform:rotate(<?= $currentRotation ?>deg);transition:transform 0.3s">
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
                                        <button type="button" class="btn btn-outline-info btn-sm" onclick="analyserDetails()" id="btnAnalyseDetails" title="Décortiquer par étape">
                                            <i class="bi bi-list-check"></i>
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

<!-- Modal Image Viewer avec rotation et zoom -->
<div class="modal fade" id="imageModal" tabindex="-1">
    <div class="modal-dialog modal-fullscreen">
        <div class="modal-content bg-dark">
            <div class="modal-header border-0 py-2">
                <div class="d-flex gap-2">
                    <div class="btn-group">
                        <button type="button" class="btn btn-outline-light btn-sm" onclick="rotateModalImage(-90)" title="Rotation gauche">
                            <i class="bi bi-arrow-counterclockwise"></i>
                        </button>
                        <button type="button" class="btn btn-outline-light btn-sm" onclick="rotateModalImage(90)" title="Rotation droite">
                            <i class="bi bi-arrow-clockwise"></i>
                        </button>
                    </div>
                    <div class="btn-group">
                        <button type="button" class="btn btn-outline-light btn-sm" onclick="zoomImage(-0.25)" title="Zoom -">
                            <i class="bi bi-zoom-out"></i>
                        </button>
                        <button type="button" class="btn btn-outline-light btn-sm" onclick="zoomImage(0.25)" title="Zoom +">
                            <i class="bi bi-zoom-in"></i>
                        </button>
                        <button type="button" class="btn btn-outline-light btn-sm" onclick="resetZoom()" title="Reset">
                            <i class="bi bi-arrows-angle-contract"></i>
                        </button>
                    </div>
                    <span class="text-white-50 small align-self-center ms-2" id="zoomLevel">100%</span>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body d-flex align-items-center justify-content-center p-0"
                 style="overflow:auto;cursor:grab" id="imageContainer">
                <img src="" id="modalImage" style="transition:transform 0.2s">
            </div>
        </div>
    </div>
</div>

<!-- Modal Analyse Détaillée par Étape -->
<div class="modal fade" id="detailsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title"><i class="bi bi-list-check me-2"></i>Breakdown par étape</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="detailsContent">
                <div class="text-center py-5">
                    <div class="spinner-border text-info" role="status"></div>
                    <p class="mt-2 text-muted">Analyse en cours...</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fermer</button>
                <button type="button" class="btn btn-success" id="btnSaveBreakdown" onclick="saveBreakdown()" style="display:none">
                    <i class="bi bi-check-circle me-1"></i>Enregistrer le breakdown
                </button>
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

// Variables pour zoom
let currentZoom = 1;
let modalRotation = 0;

// Ouvrir le modal avec l'image
function openImageModal() {
    const imageUrl = document.getElementById('imageUrl')?.value;
    const rotation = parseInt(document.getElementById('currentRotation')?.value) || 0;
    if (!imageUrl) return;

    currentZoom = 1;
    modalRotation = rotation;

    const modalImg = document.getElementById('modalImage');
    const modal = document.getElementById('imageModal');

    // Appliquer la rotation après chargement de l'image
    modalImg.onload = function() {
        updateModalTransform();
    };

    // Si image déjà en cache, appliquer immédiatement
    if (modalImg.src === imageUrl && modalImg.complete) {
        updateModalTransform();
    } else {
        modalImg.src = imageUrl;
    }

    document.getElementById('zoomLevel').textContent = '100%';
    updateModalTransform(); // Appliquer immédiatement aussi

    new bootstrap.Modal(modal).show();
}

// Appliquer la rotation quand le modal s'ouvre complètement
document.getElementById('imageModal')?.addEventListener('shown.bs.modal', function() {
    updateModalTransform();
});

// Mettre à jour le transform (rotation + zoom)
function updateModalTransform() {
    const modalImg = document.getElementById('modalImage');
    modalImg.style.transform = `rotate(${modalRotation}deg) scale(${currentZoom})`;
}

// Zoom
function zoomImage(delta) {
    currentZoom = Math.max(0.25, Math.min(4, currentZoom + delta));
    updateModalTransform();
    document.getElementById('zoomLevel').textContent = Math.round(currentZoom * 100) + '%';
}

// Reset zoom
function resetZoom() {
    currentZoom = 1;
    updateModalTransform();
    document.getElementById('zoomLevel').textContent = '100%';
}

// Rotation dans le modal (et sauvegarde)
function rotateModalImage(degrees) {
    const rotationInput = document.getElementById('currentRotation');
    const previewImg = document.getElementById('factureImage');

    let currentRotation = parseInt(rotationInput?.value) || 0;
    currentRotation = (currentRotation + degrees + 360) % 360;

    if (rotationInput) rotationInput.value = currentRotation;
    modalRotation = currentRotation;

    // Appliquer au modal
    updateModalTransform();

    // Appliquer au preview aussi
    if (previewImg) previewImg.style.transform = 'rotate(' + currentRotation + 'deg)';

    // Sauvegarder
    saveRotation(currentRotation);
}

// Zoom avec molette souris
document.getElementById('imageModal')?.addEventListener('wheel', function(e) {
    if (e.target.id === 'modalImage' || e.target.id === 'imageContainer') {
        e.preventDefault();
        zoomImage(e.deltaY > 0 ? -0.1 : 0.1);
    }
});

// Auto-détection de l'étape par IA
async function autoDetectEtape() {
    const btn = document.getElementById('btnAutoDetect');
    const imageUrl = document.getElementById('imageUrl')?.value;

    if (!imageUrl) {
        alert('Aucune image disponible');
        return;
    }

    // Animation du bouton
    const originalHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

    try {
        // Charger l'image et la compresser si nécessaire
        const response = await fetch(imageUrl);
        const blob = await response.blob();

        // Compresser l'image si > 4MB
        let finalBlob = blob;
        if (blob.size > 4 * 1024 * 1024) {
            finalBlob = await compressImage(blob, 0.7, 1600);
        }

        const reader = new FileReader();
        reader.onloadend = async function() {
            const base64 = reader.result;

            // Appeler l'API d'analyse
            const apiResponse = await fetch('<?= url('/api/analyse-facture.php') ?>', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({image: base64})
            });

            const data = await apiResponse.json();

            if (data.success && data.data) {
                // Remplir l'étape si trouvée
                if (data.data.categorie_id) {
                    document.getElementById('etapeSelect').value = data.data.categorie_id;
                }

                // Remplir autres champs si vides
                const fournisseurInput = document.querySelector('input[name="fournisseur"]');
                const descriptionInput = document.querySelector('textarea[name="description"]');
                const dateInput = document.querySelector('input[name="date_facture"]');
                const montantInput = document.getElementById('montantAvantTaxes');

                if (fournisseurInput && !fournisseurInput.value && data.data.fournisseur) {
                    fournisseurInput.value = data.data.fournisseur;
                }
                if (descriptionInput && !descriptionInput.value && data.data.description) {
                    descriptionInput.value = data.data.description;
                }
                if (dateInput && !dateInput.value && data.data.date_facture) {
                    dateInput.value = data.data.date_facture;
                }
                if (montantInput && (!montantInput.value || montantInput.value === '0') && data.data.montant_avant_taxes) {
                    montantInput.value = data.data.montant_avant_taxes.toFixed(2);
                    if (data.data.tps) document.getElementById('tps').value = data.data.tps.toFixed(2);
                    if (data.data.tvq) document.getElementById('tvq').value = data.data.tvq.toFixed(2);
                    calculerTotal();
                }

                // Notification visuelle
                btn.classList.remove('btn-outline-primary');
                btn.classList.add('btn-success');
                setTimeout(() => {
                    btn.classList.remove('btn-success');
                    btn.classList.add('btn-outline-primary');
                }, 2000);
            } else {
                alert('Erreur: ' + (data.error || 'Analyse impossible'));
            }

            btn.disabled = false;
            btn.innerHTML = originalHtml;
        };

        reader.readAsDataURL(finalBlob);
    } catch (err) {
        console.error('Erreur:', err);
        alert('Erreur: ' + err.message);
        btn.disabled = false;
        btn.innerHTML = originalHtml;
    }
}

// Compresser une image
function compressImage(blob, quality, maxSize) {
    return new Promise((resolve) => {
        const img = new Image();
        img.onload = function() {
            const canvas = document.createElement('canvas');
            let width = img.width;
            let height = img.height;

            // Réduire si trop grand
            if (width > maxSize || height > maxSize) {
                if (width > height) {
                    height = (height / width) * maxSize;
                    width = maxSize;
                } else {
                    width = (width / height) * maxSize;
                    height = maxSize;
                }
            }

            canvas.width = width;
            canvas.height = height;
            const ctx = canvas.getContext('2d');
            ctx.drawImage(img, 0, 0, width, height);

            canvas.toBlob(resolve, 'image/jpeg', quality);
        };
        img.src = URL.createObjectURL(blob);
    });
}

// Variable pour stocker le breakdown courant
let currentBreakdownData = null;

// Analyse détaillée avec breakdown par étape
async function analyserDetails() {
    const btn = document.getElementById('btnAnalyseDetails');
    const imageUrl = document.getElementById('imageUrl')?.value;
    const modal = new bootstrap.Modal(document.getElementById('detailsModal'));
    const contentDiv = document.getElementById('detailsContent');

    if (!imageUrl) {
        alert('Aucune image disponible');
        return;
    }

    // Cacher le bouton save et reset data
    document.getElementById('btnSaveBreakdown').style.display = 'none';
    currentBreakdownData = null;

    // Afficher le modal avec loading
    contentDiv.innerHTML = `
        <div class="text-center py-5">
            <div class="spinner-border text-info" role="status"></div>
            <p class="mt-2 text-muted">Analyse en cours... (peut prendre 10-15 secondes)</p>
        </div>
    `;
    modal.show();

    try {
        // Charger et compresser l'image
        const response = await fetch(imageUrl);
        const blob = await response.blob();

        let finalBlob = blob;
        if (blob.size > 4 * 1024 * 1024) {
            finalBlob = await compressImage(blob, 0.7, 1600);
        }

        const reader = new FileReader();
        reader.onloadend = async function() {
            const base64 = reader.result;

            // Appeler l'API d'analyse détaillée
            const apiResponse = await fetch('<?= url('/api/analyse-facture-details.php') ?>', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({image: base64})
            });

            const data = await apiResponse.json();

            if (data.success && data.data) {
                displayDetailsResults(data.data);
            } else {
                contentDiv.innerHTML = `
                    <div class="alert alert-danger">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        ${data.error || 'Erreur lors de l\'analyse'}
                    </div>
                `;
            }
        };

        reader.readAsDataURL(finalBlob);
    } catch (err) {
        console.error('Erreur:', err);
        contentDiv.innerHTML = `
            <div class="alert alert-danger">
                <i class="bi bi-exclamation-triangle me-2"></i>
                Erreur: ${err.message}
            </div>
        `;
    }
}

// Afficher les résultats de l'analyse détaillée
function displayDetailsResults(data) {
    const contentDiv = document.getElementById('detailsContent');

    // Stocker les données pour sauvegarde
    currentBreakdownData = data;

    // Afficher le bouton de sauvegarde
    document.getElementById('btnSaveBreakdown').style.display = 'inline-block';

    let html = '';

    // Info facture
    html += `
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <strong>${data.fournisseur || 'Fournisseur inconnu'}</strong>
                <span class="text-muted ms-2">${data.date_facture || ''}</span>
            </div>
            <div class="text-end">
                <span class="badge bg-success fs-6">${formatMoney(data.total || 0)}</span>
            </div>
        </div>
    `;

    // Totaux par étape
    if (data.totaux_par_etape && data.totaux_par_etape.length > 0) {
        html += `<h6 class="mt-4 mb-3"><i class="bi bi-pie-chart me-2"></i>Répartition par étape</h6>`;
        html += `<div class="row g-2 mb-4">`;
        data.totaux_par_etape.forEach(t => {
            const percent = data.sous_total > 0 ? Math.round((t.montant / data.sous_total) * 100) : 0;
            html += `
                <div class="col-md-6">
                    <div class="card border-0 bg-light">
                        <div class="card-body py-2 px-3">
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="fw-medium">${t.etape_nom}</span>
                                <span class="badge bg-primary">${formatMoney(t.montant)}</span>
                            </div>
                            <div class="progress mt-1" style="height: 4px;">
                                <div class="progress-bar" style="width: ${percent}%"></div>
                            </div>
                        </div>
                    </div>
                </div>
            `;
        });
        html += `</div>`;
    }

    // Liste des articles
    if (data.lignes && data.lignes.length > 0) {
        html += `<h6 class="mt-4 mb-3"><i class="bi bi-list-ul me-2"></i>Détail des articles (${data.lignes.length})</h6>`;
        html += `<div class="table-responsive"><table class="table table-sm table-hover">`;
        html += `<thead class="table-light"><tr>
            <th>Article</th>
            <th>Qté</th>
            <th class="text-end">Prix</th>
            <th>Étape</th>
        </tr></thead><tbody>`;

        data.lignes.forEach(l => {
            html += `<tr>
                <td>
                    <small>${l.description}</small>
                    ${l.raison ? `<br><span class="text-muted" style="font-size:0.7rem">${l.raison}</span>` : ''}
                </td>
                <td>${l.quantite || 1}</td>
                <td class="text-end">${formatMoney(l.total || 0)}</td>
                <td><span class="badge bg-secondary">${l.etape_nom || 'N/A'}</span></td>
            </tr>`;
        });

        html += `</tbody></table></div>`;
    }

    // Résumé taxes
    html += `
        <div class="border-top pt-3 mt-3">
            <div class="row text-end">
                <div class="col-8 text-muted">Sous-total:</div>
                <div class="col-4">${formatMoney(data.sous_total || 0)}</div>
            </div>
            <div class="row text-end">
                <div class="col-8 text-muted">TPS (5%):</div>
                <div class="col-4">${formatMoney(data.tps || 0)}</div>
            </div>
            <div class="row text-end">
                <div class="col-8 text-muted">TVQ (9.975%):</div>
                <div class="col-4">${formatMoney(data.tvq || 0)}</div>
            </div>
            <div class="row text-end fw-bold">
                <div class="col-8">Total:</div>
                <div class="col-4">${formatMoney(data.total || 0)}</div>
            </div>
        </div>
    `;

    contentDiv.innerHTML = html;
}

// Formater montant en argent
function formatMoney(amount) {
    return new Intl.NumberFormat('fr-CA', {
        style: 'currency',
        currency: 'CAD'
    }).format(amount);
}

// Sauvegarder le breakdown dans la BD
async function saveBreakdown() {
    if (!currentBreakdownData || !currentBreakdownData.lignes) {
        alert('Aucune donnée à sauvegarder');
        return;
    }

    const btn = document.getElementById('btnSaveBreakdown');
    const originalHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Sauvegarde...';

    try {
        const response = await fetch('<?= url('/api/save-facture-lignes.php') ?>', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                facture_id: <?= $factureId ?>,
                lignes: currentBreakdownData.lignes
            })
        });

        const data = await response.json();

        if (data.success) {
            btn.innerHTML = '<i class="bi bi-check-circle me-1"></i>Enregistré!';
            btn.classList.remove('btn-success');
            btn.classList.add('btn-outline-success');

            // Afficher message de succès
            const contentDiv = document.getElementById('detailsContent');
            const alertHtml = `
                <div class="alert alert-success alert-dismissible fade show mb-3">
                    <i class="bi bi-check-circle me-2"></i>
                    ${data.count} lignes enregistrées! Le breakdown est visible sur la page projet.
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            `;
            contentDiv.insertAdjacentHTML('afterbegin', alertHtml);

            setTimeout(() => {
                btn.innerHTML = originalHtml;
                btn.classList.remove('btn-outline-success');
                btn.classList.add('btn-success');
                btn.disabled = false;
            }, 3000);
        } else {
            alert('Erreur: ' + (data.error || 'Sauvegarde impossible'));
            btn.innerHTML = originalHtml;
            btn.disabled = false;
        }
    } catch (err) {
        console.error('Erreur:', err);
        alert('Erreur: ' + err.message);
        btn.innerHTML = originalHtml;
        btn.disabled = false;
    }
}
</script>

<?php include '../../includes/footer.php'; ?>
