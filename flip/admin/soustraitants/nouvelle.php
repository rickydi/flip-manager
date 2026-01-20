<?php
/**
 * Nouveau sous-traitant / Modifier sous-traitant - Admin
 * Flip Manager
 */

require_once '../../config.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

// Permettre aux employés ET admins d'accéder à cette page
requireLogin();

// Déterminer si l'utilisateur est admin (pour afficher certaines options)
$isAdmin = isAdmin();

// Auto-migration: créer la table sous_traitants si elle n'existe pas
try {
    $pdo->query("SELECT 1 FROM sous_traitants LIMIT 1");
} catch (Exception $e) {
    // Exécuter la migration
    $sqlFile = __DIR__ . '/../../sql/migration_sous_traitants.sql';
    if (file_exists($sqlFile)) {
        $sql = file_get_contents($sqlFile);
        $statements = array_filter(array_map('trim', explode(';', $sql)));
        foreach ($statements as $statement) {
            if (!empty($statement) && stripos($statement, '--') !== 0) {
                try {
                    $pdo->exec($statement);
                } catch (Exception $e2) {
                    // Ignorer les erreurs (table existe déjà, etc.)
                }
            }
        }
    }
}

// Migration: ajouter colonne etape_id si elle n'existe pas
try {
    $pdo->query("SELECT etape_id FROM sous_traitants LIMIT 1");
} catch (Exception $e) {
    try {
        $pdo->exec("ALTER TABLE sous_traitants ADD COLUMN etape_id INT DEFAULT NULL");
    } catch (Exception $e2) {}
}

// Mode édition si ID fourni
$soustraitantId = (int)($_GET['id'] ?? 0);
$isEdit = false;
$soustraitant = null;

if ($soustraitantId) {
    $stmt = $pdo->prepare("SELECT * FROM sous_traitants WHERE id = ?");
    $stmt->execute([$soustraitantId]);
    $soustraitant = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($soustraitant) {
        $isEdit = true;
    }
}

$pageTitle = $isEdit ? 'Modifier sous-traitant' : 'Nouveau sous-traitant';
$errors = [];

// Créer la table entreprises_soustraitants si elle n'existe pas
try {
    $tableExists = $pdo->query("SHOW TABLES LIKE 'entreprises_soustraitants'")->rowCount() > 0;
    if (!$tableExists) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS entreprises_soustraitants (
                id INT AUTO_INCREMENT PRIMARY KEY,
                nom VARCHAR(255) NOT NULL UNIQUE,
                contact VARCHAR(255) DEFAULT NULL,
                telephone VARCHAR(50) DEFAULT NULL,
                email VARCHAR(255) DEFAULT NULL,
                specialite VARCHAR(255) DEFAULT NULL,
                actif TINYINT(1) DEFAULT 1,
                date_creation DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        // Insérer les entreprises par défaut
        $entreprisesDefaut = ['Électricien', 'Plombier', 'Couvreur', 'Maçon', 'Peintre', 'Menuisier', 'Carreleur', 'Excavation', 'Béton'];
        $stmtInsert = $pdo->prepare("INSERT IGNORE INTO entreprises_soustraitants (nom) VALUES (?)");
        foreach ($entreprisesDefaut as $e) {
            $stmtInsert->execute([$e]);
        }
    }
} catch (Exception $e) {}

// Récupérer les entreprises depuis la table
$stmt = $pdo->query("SELECT nom, contact, telephone, email FROM entreprises_soustraitants WHERE actif = 1 ORDER BY nom ASC");
$toutesLesEntreprises = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Récupérer les projets actifs
$projets = getProjets($pdo);

// Récupérer les étapes du budget-builder
$etapes = [];
try {
    $stmt = $pdo->query("SELECT id, nom, ordre FROM budget_etapes ORDER BY ordre, nom");
    $etapes = $stmt->fetchAll();
} catch (Exception $e) {}

// Traitement du formulaire
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token de sécurité invalide.';
    } else {
        $projetId = (int)($_POST['projet_id'] ?? 0);
        $etapeId = (int)($_POST['etape_id'] ?? 0);
        $nomEntreprise = trim($_POST['nom_entreprise'] ?? '');
        $contact = trim($_POST['contact'] ?? '');
        $telephone = trim($_POST['telephone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $dateFacture = $_POST['date_facture'] ?? '';
        $montantAvantTaxes = parseNumber($_POST['montant_avant_taxes'] ?? 0);
        $tps = parseNumber($_POST['tps'] ?? 0);
        $tvq = parseNumber($_POST['tvq'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');
        $approuverDirect = $isAdmin && isset($_POST['approuver_direct']);

        // Validation
        if (!$projetId) $errors[] = 'Veuillez sélectionner un projet.';
        if (!$etapeId) $errors[] = 'Veuillez sélectionner une catégorie.';
        if (empty($nomEntreprise)) $errors[] = 'Le nom de l\'entreprise est requis.';
        if (empty($dateFacture)) $errors[] = 'La date de la facture est requise.';
        if ($montantAvantTaxes <= 0) $errors[] = 'Le montant avant taxes doit être supérieur à 0.';

        // Calculer le total
        $montantTotal = $montantAvantTaxes + $tps + $tvq;

        // Upload de fichier
        $fichier = $isEdit ? $soustraitant['fichier'] : null;
        if (isset($_FILES['fichier']) && $_FILES['fichier']['error'] !== UPLOAD_ERR_NO_FILE) {
            $upload = uploadFile($_FILES['fichier'], 'soustraitants');
            if ($upload['success']) {
                if ($isEdit && $soustraitant['fichier']) {
                    deleteUploadedFile($soustraitant['fichier'], 'soustraitants');
                }
                $fichier = $upload['filename'];
            } else {
                $errors[] = $upload['error'];
            }
        }
        // Si pas de fichier uploadé mais image collée (base64)
        elseif (!empty($_POST['image_base64'])) {
            $base64Data = $_POST['image_base64'];
            if (preg_match('/^data:image\/(\w+);base64,(.+)$/', $base64Data, $matches)) {
                $extension = $matches[1] === 'jpeg' ? 'jpg' : $matches[1];
                $imageData = base64_decode($matches[2]);

                if ($imageData !== false) {
                    $fichier = 'soustraitant_' . time() . '_' . uniqid() . '.' . $extension;
                    $uploadDir = __DIR__ . '/../../uploads/soustraitants/';

                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0755, true);
                    }

                    if (!file_put_contents($uploadDir . $fichier, $imageData)) {
                        $fichier = null;
                        $errors[] = 'Erreur lors de la sauvegarde de l\'image collée.';
                    }
                }
            }
        }

        if (empty($errors)) {
            try {
                $statut = $approuverDirect ? 'approuvee' : 'en_attente';

                if ($isEdit) {
                    // Mise à jour
                    $stmt = $pdo->prepare("
                        UPDATE sous_traitants SET
                            projet_id = ?,
                            etape_id = ?,
                            nom_entreprise = ?,
                            contact = ?,
                            telephone = ?,
                            email = ?,
                            description = ?,
                            date_facture = ?,
                            montant_avant_taxes = ?,
                            tps = ?,
                            tvq = ?,
                            montant_total = ?,
                            fichier = ?,
                            notes = ?,
                            statut = CASE WHEN ? = 'approuvee' THEN 'approuvee' ELSE statut END,
                            date_modification = NOW()
                        WHERE id = ?
                    ");
                    $stmt->execute([
                        $projetId, $etapeId ?: null, $nomEntreprise, $contact, $telephone, $email,
                        $description, $dateFacture, $montantAvantTaxes, $tps, $tvq, $montantTotal,
                        $fichier, $notes, $statut, $soustraitantId
                    ]);

                    $redirectUrl = isset($_GET['redirect']) ? $_GET['redirect'] : "/admin/projets/detail.php?id={$projetId}&tab=soustraitants";
                    setFlashMessage('success', 'Sous-traitant modifié avec succès.');
                    redirect($redirectUrl);
                } else {
                    // Création
                    $stmt = $pdo->prepare("
                        INSERT INTO sous_traitants (
                            projet_id, etape_id, user_id, nom_entreprise, contact, telephone, email,
                            description, date_facture, montant_avant_taxes, tps, tvq, montant_total,
                            fichier, notes, statut, date_creation
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                    ");
                    $stmt->execute([
                        $projetId, $etapeId ?: null, $_SESSION['user_id'], $nomEntreprise, $contact,
                        $telephone, $email, $description, $dateFacture, $montantAvantTaxes, $tps, $tvq,
                        $montantTotal, $fichier, $notes, $statut
                    ]);

                    // Ajouter l'entreprise à la liste si elle n'existe pas
                    try {
                        $stmtCheck = $pdo->prepare("SELECT id FROM entreprises_soustraitants WHERE nom = ?");
                        $stmtCheck->execute([$nomEntreprise]);
                        if (!$stmtCheck->fetch()) {
                            $stmtInsert = $pdo->prepare("INSERT INTO entreprises_soustraitants (nom, contact, telephone, email) VALUES (?, ?, ?, ?)");
                            $stmtInsert->execute([$nomEntreprise, $contact, $telephone, $email]);
                        }
                    } catch (Exception $e) {}

                    setFlashMessage('success', 'Sous-traitant ajouté avec succès.');
                    redirect("/admin/projets/detail.php?id={$projetId}&tab=soustraitants");
                }
            } catch (Exception $e) {
                $errors[] = 'Erreur lors de l\'enregistrement : ' . $e->getMessage();
            }
        }
    }
}

// Pré-remplir le projet si fourni dans l'URL
$preselectedProjet = (int)($_GET['projet'] ?? ($isEdit ? $soustraitant['projet_id'] : 0));

include '../../includes/header.php';
?>

<div class="container-fluid">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= url('/admin/index.php') ?>">Tableau de bord</a></li>
            <?php if ($preselectedProjet): ?>
                <li class="breadcrumb-item"><a href="<?= url('/admin/projets/detail.php?id=' . $preselectedProjet . '&tab=soustraitants') ?>">Projet</a></li>
            <?php endif; ?>
            <li class="breadcrumb-item active"><?= $isEdit ? 'Modifier' : 'Nouveau' ?> sous-traitant</li>
        </ol>
    </nav>

    <div class="row">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="bi bi-building me-2"></i><?= $isEdit ? 'Modifier le sous-traitant' : 'Nouveau sous-traitant' ?>
                    </h5>
                </div>
                <div class="card-body">
                    <?php if (!empty($errors)): ?>
                        <div class="alert alert-danger">
                            <ul class="mb-0">
                                <?php foreach ($errors as $error): ?>
                                    <li><?= e($error) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <form method="POST" enctype="multipart/form-data" id="soustraitantForm">
                        <?php csrfField(); ?>
                        <input type="hidden" name="image_base64" id="imageBase64">

                        <div class="row g-3">
                            <!-- Projet -->
                            <div class="col-md-6">
                                <label for="projet_id" class="form-label">Projet <span class="text-danger">*</span></label>
                                <select class="form-select" id="projet_id" name="projet_id" required>
                                    <option value="">Sélectionner un projet...</option>
                                    <?php foreach ($projets as $p): ?>
                                        <option value="<?= $p['id'] ?>" <?= $preselectedProjet == $p['id'] ? 'selected' : '' ?>>
                                            <?= e($p['nom']) ?> - <?= e($p['ville']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Catégorie -->
                            <div class="col-md-6">
                                <label for="etape_id" class="form-label">Catégorie <span class="text-danger">*</span></label>
                                <select class="form-select" id="etape_id" name="etape_id" required>
                                    <option value="">Sélectionner une catégorie...</option>
                                    <?php foreach ($etapes as $etape): ?>
                                        <option value="<?= $etape['id'] ?>" <?= ($isEdit && $soustraitant['etape_id'] == $etape['id']) ? 'selected' : '' ?>>
                                            <?= e($etape['nom']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Entreprise -->
                            <div class="col-md-6">
                                <label for="nom_entreprise" class="form-label">Entreprise / Sous-traitant <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="nom_entreprise" name="nom_entreprise"
                                       value="<?= e($isEdit ? $soustraitant['nom_entreprise'] : '') ?>"
                                       list="entreprisesList" required autocomplete="off">
                                <datalist id="entreprisesList">
                                    <?php foreach ($toutesLesEntreprises as $ent): ?>
                                        <option value="<?= e($ent['nom']) ?>" data-contact="<?= e($ent['contact'] ?? '') ?>" data-telephone="<?= e($ent['telephone'] ?? '') ?>" data-email="<?= e($ent['email'] ?? '') ?>">
                                    <?php endforeach; ?>
                                </datalist>
                            </div>

                            <!-- Contact -->
                            <div class="col-md-6">
                                <label for="contact" class="form-label">Nom du contact</label>
                                <input type="text" class="form-control" id="contact" name="contact"
                                       value="<?= e($isEdit ? $soustraitant['contact'] : '') ?>">
                            </div>

                            <!-- Téléphone -->
                            <div class="col-md-6">
                                <label for="telephone" class="form-label">Téléphone</label>
                                <input type="tel" class="form-control" id="telephone" name="telephone"
                                       value="<?= e($isEdit ? $soustraitant['telephone'] : '') ?>">
                            </div>

                            <!-- Email -->
                            <div class="col-md-6">
                                <label for="email" class="form-label">Email</label>
                                <input type="email" class="form-control" id="email" name="email"
                                       value="<?= e($isEdit ? $soustraitant['email'] : '') ?>">
                            </div>

                            <!-- Date -->
                            <div class="col-md-4">
                                <label for="date_facture" class="form-label">Date de la facture <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="date_facture" name="date_facture"
                                       value="<?= e($isEdit ? $soustraitant['date_facture'] : date('Y-m-d')) ?>" required>
                            </div>

                            <!-- Montants -->
                            <div class="col-md-4">
                                <label for="montant_avant_taxes" class="form-label">Montant avant taxes <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text">$</span>
                                    <input type="text" class="form-control money-input" id="montant_avant_taxes" name="montant_avant_taxes"
                                           value="<?= $isEdit ? number_format($soustraitant['montant_avant_taxes'], 2, '.', '') : '' ?>"
                                           required>
                                </div>
                            </div>

                            <div class="col-md-2">
                                <label for="tps" class="form-label">TPS</label>
                                <div class="input-group">
                                    <span class="input-group-text">$</span>
                                    <input type="text" class="form-control money-input" id="tps" name="tps"
                                           value="<?= $isEdit ? number_format($soustraitant['tps'], 2, '.', '') : '' ?>">
                                </div>
                            </div>

                            <div class="col-md-2">
                                <label for="tvq" class="form-label">TVQ</label>
                                <div class="input-group">
                                    <span class="input-group-text">$</span>
                                    <input type="text" class="form-control money-input" id="tvq" name="tvq"
                                           value="<?= $isEdit ? number_format($soustraitant['tvq'], 2, '.', '') : '' ?>">
                                </div>
                            </div>

                            <!-- Total calculé -->
                            <div class="col-md-4">
                                <label class="form-label">Total (calculé)</label>
                                <div class="input-group">
                                    <span class="input-group-text">$</span>
                                    <input type="text" class="form-control" id="montant_total" readonly
                                           value="<?= $isEdit ? number_format($soustraitant['montant_total'], 2, '.', '') : '0.00' ?>">
                                </div>
                            </div>

                            <!-- Bouton calcul taxes -->
                            <div class="col-md-4 d-flex align-items-end">
                                <button type="button" class="btn btn-outline-secondary" onclick="calculerTaxes()">
                                    <i class="bi bi-calculator me-1"></i>Calculer taxes (QC)
                                </button>
                            </div>

                            <!-- Description -->
                            <div class="col-12">
                                <label for="description" class="form-label">Description des travaux</label>
                                <textarea class="form-control" id="description" name="description" rows="3"><?= e($isEdit ? $soustraitant['description'] : '') ?></textarea>
                            </div>

                            <!-- Fichier -->
                            <div class="col-12">
                                <label for="fichier" class="form-label">Facture / Soumission (PDF ou image)</label>
                                <?php if ($isEdit && $soustraitant['fichier']): ?>
                                    <div class="mb-2">
                                        <a href="<?= url('/uploads/soustraitants/' . $soustraitant['fichier']) ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                            <i class="bi bi-file-earmark me-1"></i>Voir le fichier actuel
                                        </a>
                                        <button type="button" class="btn btn-sm btn-outline-info ms-2" onclick="analyserFichierExistant()">
                                            <i class="bi bi-robot me-1"></i>Analyser avec l'IA
                                        </button>
                                    </div>
                                <?php endif; ?>
                                <div id="dropZone" class="border border-2 border-dashed rounded p-4 text-center mb-2"
                                     style="border-color: #6c757d !important; cursor: pointer;">
                                    <i class="bi bi-cloud-arrow-up display-6 text-muted"></i>
                                    <p class="mb-0 mt-2">Glissez un fichier ici, collez une image (Ctrl+V), ou cliquez pour sélectionner</p>
                                    <small class="text-muted">Formats acceptés: PDF, JPG, PNG (max 5 MB)</small>
                                </div>
                                <input type="file" class="form-control d-none" id="fichier" name="fichier" accept=".pdf,.jpg,.jpeg,.png">
                                <div id="filePreview" class="mt-2" style="display: none;">
                                    <div class="alert alert-success d-flex align-items-center">
                                        <i class="bi bi-check-circle me-2"></i>
                                        <span id="fileName"></span>
                                        <button type="button" class="btn btn-sm btn-info ms-2" onclick="analyserAvecIA()" id="btnAnalyserIA" title="Analyser avec l'IA">
                                            <i class="bi bi-robot"></i>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-danger ms-auto" onclick="clearFile()">
                                            <i class="bi bi-x"></i>
                                        </button>
                                    </div>
                                </div>
                                <!-- Indicateur de chargement IA -->
                                <div id="iaLoading" class="mt-2" style="display: none;">
                                    <div class="alert alert-info d-flex align-items-center">
                                        <div class="spinner-border spinner-border-sm me-2" role="status"></div>
                                        <span>Analyse en cours par l'IA...</span>
                                    </div>
                                </div>
                                <!-- Résultat IA -->
                                <div id="iaResult" class="mt-2" style="display: none;"></div>
                            </div>

                            <!-- Notes -->
                            <div class="col-12">
                                <label for="notes" class="form-label">Notes internes</label>
                                <textarea class="form-control" id="notes" name="notes" rows="2"><?= e($isEdit ? $soustraitant['notes'] : '') ?></textarea>
                            </div>

                            <?php if ($isAdmin): ?>
                            <!-- Approuver directement -->
                            <div class="col-12">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="approuver_direct" name="approuver_direct">
                                    <label class="form-check-label" for="approuver_direct">
                                        <i class="bi bi-check-circle text-success me-1"></i>Approuver directement
                                    </label>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>

                        <div class="mt-4 d-flex gap-2">
                            <button type="submit" class="btn btn-success">
                                <i class="bi bi-check-lg me-1"></i><?= $isEdit ? 'Enregistrer' : 'Créer' ?>
                            </button>
                            <a href="<?= $preselectedProjet ? url('/admin/projets/detail.php?id=' . $preselectedProjet . '&tab=soustraitants') : url('/admin/index.php') ?>" class="btn btn-outline-secondary">
                                <i class="bi bi-x me-1"></i>Annuler
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Aide -->
        <div class="col-lg-4">
            <div class="card">
                <div class="card-header">
                    <h6 class="mb-0"><i class="bi bi-info-circle me-2"></i>Aide</h6>
                </div>
                <div class="card-body">
                    <h6><i class="bi bi-robot text-info me-1"></i>Analyse IA</h6>
                    <p class="small text-muted">
                        Téléchargez une image ou PDF de la soumission, puis cliquez sur
                        <span class="badge bg-info"><i class="bi bi-robot"></i></span>
                        pour remplir automatiquement les champs avec l'IA.
                    </p>
                    <hr>
                    <h6>Sous-traitants</h6>
                    <p class="small text-muted">
                        Utilisez ce formulaire pour enregistrer les factures de vos sous-traitants
                        (électriciens, plombiers, couvreurs, etc.).
                    </p>
                    <h6>Catégories</h6>
                    <p class="small text-muted">
                        Sélectionnez la catégorie correspondant au type de travaux effectués.
                        Cela permettra de suivre les dépenses par catégorie.
                    </p>
                    <h6>Fichiers</h6>
                    <p class="small text-muted">
                        Vous pouvez joindre une copie de la facture ou de la soumission
                        au format PDF ou image.
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Auto-remplissage des informations de l'entreprise
document.getElementById('nom_entreprise').addEventListener('input', function() {
    const options = document.querySelectorAll('#entreprisesList option');
    options.forEach(option => {
        if (option.value === this.value) {
            document.getElementById('contact').value = option.dataset.contact || '';
            document.getElementById('telephone').value = option.dataset.telephone || '';
            document.getElementById('email').value = option.dataset.email || '';
        }
    });
});

// Calcul automatique du total
function calculerTotal() {
    const montant = parseFloat(document.getElementById('montant_avant_taxes').value.replace(/[^0-9.-]/g, '')) || 0;
    const tps = parseFloat(document.getElementById('tps').value.replace(/[^0-9.-]/g, '')) || 0;
    const tvq = parseFloat(document.getElementById('tvq').value.replace(/[^0-9.-]/g, '')) || 0;
    const total = montant + tps + tvq;
    document.getElementById('montant_total').value = total.toFixed(2);
}

// Calcul des taxes (Québec)
function calculerTaxes() {
    const montant = parseFloat(document.getElementById('montant_avant_taxes').value.replace(/[^0-9.-]/g, '')) || 0;
    const tps = montant * 0.05;
    const tvq = montant * 0.09975;
    document.getElementById('tps').value = tps.toFixed(2);
    document.getElementById('tvq').value = tvq.toFixed(2);
    calculerTotal();
}

// Écouter les changements sur les montants
['montant_avant_taxes', 'tps', 'tvq'].forEach(id => {
    document.getElementById(id).addEventListener('input', calculerTotal);
});

// Drag & Drop et Coller
const dropZone = document.getElementById('dropZone');
const fileInput = document.getElementById('fichier');
const filePreview = document.getElementById('filePreview');
const fileName = document.getElementById('fileName');
const imageBase64 = document.getElementById('imageBase64');

dropZone.addEventListener('click', () => fileInput.click());

dropZone.addEventListener('dragover', (e) => {
    e.preventDefault();
    dropZone.style.borderColor = '#198754';
    dropZone.style.background = 'rgba(25, 135, 84, 0.1)';
});

dropZone.addEventListener('dragleave', (e) => {
    e.preventDefault();
    dropZone.style.borderColor = '#6c757d';
    dropZone.style.background = 'transparent';
});

dropZone.addEventListener('drop', (e) => {
    e.preventDefault();
    dropZone.style.borderColor = '#6c757d';
    dropZone.style.background = 'transparent';
    if (e.dataTransfer.files.length > 0) {
        const file = e.dataTransfer.files[0];
        fileInput.files = e.dataTransfer.files;
        showFilePreview(file);
        storeImageForAnalysis(file);
    }
});

fileInput.addEventListener('change', () => {
    if (fileInput.files.length > 0) {
        const file = fileInput.files[0];
        showFilePreview(file);
        storeImageForAnalysis(file);
    }
});

// Coller une image
document.addEventListener('paste', (e) => {
    const items = e.clipboardData?.items;
    if (!items) return;

    for (let item of items) {
        if (item.type.startsWith('image/')) {
            e.preventDefault();
            const file = item.getAsFile();
            const reader = new FileReader();
            reader.onload = function(event) {
                imageBase64.value = event.target.result;
                currentImageData = event.target.result;
                currentMimeType = file.type || 'image/png';
                fileName.textContent = 'Image collée';
                filePreview.style.display = 'block';
                dropZone.style.display = 'none';
            };
            reader.readAsDataURL(file);
            break;
        }
    }
});

function showFilePreview(file) {
    fileName.textContent = file.name;
    filePreview.style.display = 'block';
    dropZone.style.display = 'none';
    imageBase64.value = ''; // Clear pasted image
}

function clearFile() {
    fileInput.value = '';
    imageBase64.value = '';
    filePreview.style.display = 'none';
    dropZone.style.display = 'block';
    document.getElementById('iaResult').style.display = 'none';
}

// Variable pour stocker les données de l'image pour l'analyse
let currentImageData = null;
let currentMimeType = null;

// Fonction appelée après upload/paste pour stocker les données
function storeImageForAnalysis(file) {
    const reader = new FileReader();
    reader.onload = function(e) {
        currentImageData = e.target.result;
        // Extraire le mime type
        const match = currentImageData.match(/^data:([^;]+);/);
        currentMimeType = match ? match[1] : (file.type || 'image/png');
    };
    reader.readAsDataURL(file);
}

// Analyser avec l'IA
async function analyserAvecIA() {
    if (!currentImageData && !imageBase64.value) {
        alert('Veuillez d\'abord sélectionner un fichier.');
        return;
    }

    const iaLoading = document.getElementById('iaLoading');
    const iaResult = document.getElementById('iaResult');
    const btnAnalyser = document.getElementById('btnAnalyserIA');

    iaLoading.style.display = 'block';
    iaResult.style.display = 'none';
    btnAnalyser.disabled = true;

    try {
        const dataToSend = imageBase64.value || currentImageData;

        const response = await fetch('<?= url('/api/analyse-soumission.php') ?>', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                image: dataToSend,
                mime_type: currentMimeType || 'image/png'
            })
        });

        const result = await response.json();
        iaLoading.style.display = 'none';
        btnAnalyser.disabled = false;

        if (result.success && result.data) {
            remplirFormulaire(result.data);

            // Afficher le message de succès
            const confiance = result.data.confiance ? Math.round(result.data.confiance * 100) : 'N/A';
            iaResult.innerHTML = `
                <div class="alert alert-success">
                    <i class="bi bi-check-circle me-2"></i>
                    <strong>Analyse terminée!</strong> Confiance: ${confiance}%
                    ${result.data.notes ? `<br><small class="text-muted">${result.data.notes}</small>` : ''}
                </div>
            `;
            iaResult.style.display = 'block';
        } else {
            iaResult.innerHTML = `
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    ${result.error || 'Erreur lors de l\'analyse'}
                </div>
            `;
            iaResult.style.display = 'block';
        }
    } catch (error) {
        iaLoading.style.display = 'none';
        btnAnalyser.disabled = false;
        iaResult.innerHTML = `
            <div class="alert alert-danger">
                <i class="bi bi-exclamation-triangle me-2"></i>
                Erreur de connexion: ${error.message}
            </div>
        `;
        iaResult.style.display = 'block';
    }
}

// Remplir le formulaire avec les données extraites
function remplirFormulaire(data) {
    // Nom de l'entreprise
    if (data.nom_entreprise) {
        document.getElementById('nom_entreprise').value = data.nom_entreprise;
    }

    // Contact
    if (data.contact) {
        document.getElementById('contact').value = data.contact;
    }

    // Téléphone
    if (data.telephone) {
        document.getElementById('telephone').value = data.telephone;
    }

    // Email
    if (data.email) {
        document.getElementById('email').value = data.email;
    }

    // Date
    if (data.date_facture) {
        document.getElementById('date_facture').value = data.date_facture;
    }

    // Description
    if (data.description) {
        document.getElementById('description').value = data.description;
    }

    // Montants
    if (data.montant_avant_taxes) {
        document.getElementById('montant_avant_taxes').value = parseFloat(data.montant_avant_taxes).toFixed(2);
    }
    if (data.tps) {
        document.getElementById('tps').value = parseFloat(data.tps).toFixed(2);
    }
    if (data.tvq) {
        document.getElementById('tvq').value = parseFloat(data.tvq).toFixed(2);
    }

    // Calculer le total
    calculerTotal();

    // Catégorie/Étape
    if (data.etape_id) {
        const etapeSelect = document.getElementById('etape_id');
        const option = etapeSelect.querySelector(`option[value="${data.etape_id}"]`);
        if (option) {
            etapeSelect.value = data.etape_id;
        }
    }

    // Notes (combiner avec notes existantes si présentes)
    if (data.notes) {
        const notesField = document.getElementById('notes');
        const existingNotes = notesField.value.trim();
        if (existingNotes) {
            notesField.value = existingNotes + '\n\n[IA] ' + data.notes;
        } else {
            notesField.value = '[IA] ' + data.notes;
        }
    }

    // Flash animation sur les champs remplis
    const fieldsToHighlight = ['nom_entreprise', 'contact', 'telephone', 'email',
                               'date_facture', 'description', 'montant_avant_taxes',
                               'tps', 'tvq', 'etape_id', 'notes'];
    fieldsToHighlight.forEach(fieldId => {
        const field = document.getElementById(fieldId);
        if (field && field.value) {
            field.classList.add('bg-success-subtle');
            setTimeout(() => {
                field.classList.remove('bg-success-subtle');
            }, 2000);
        }
    });
}

// Analyser un fichier existant (mode édition)
<?php if ($isEdit && $soustraitant['fichier']): ?>
async function analyserFichierExistant() {
    const iaLoading = document.getElementById('iaLoading');
    const iaResult = document.getElementById('iaResult');

    iaLoading.style.display = 'block';
    iaResult.style.display = 'none';

    try {
        // Charger le fichier existant
        const fileUrl = '<?= url('/uploads/soustraitants/' . $soustraitant['fichier']) ?>';
        const response = await fetch(fileUrl);
        const blob = await response.blob();

        // Convertir en base64
        const reader = new FileReader();
        reader.onload = async function(e) {
            currentImageData = e.target.result;
            const match = currentImageData.match(/^data:([^;]+);/);
            currentMimeType = match ? match[1] : 'application/octet-stream';

            // Lancer l'analyse
            const analyseResponse = await fetch('<?= url('/api/analyse-soumission.php') ?>', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    image: currentImageData,
                    mime_type: currentMimeType
                })
            });

            const result = await analyseResponse.json();
            iaLoading.style.display = 'none';

            if (result.success && result.data) {
                remplirFormulaire(result.data);

                const confiance = result.data.confiance ? Math.round(result.data.confiance * 100) : 'N/A';
                iaResult.innerHTML = `
                    <div class="alert alert-success">
                        <i class="bi bi-check-circle me-2"></i>
                        <strong>Analyse terminée!</strong> Confiance: ${confiance}%
                        ${result.data.notes ? `<br><small class="text-muted">${result.data.notes}</small>` : ''}
                    </div>
                `;
                iaResult.style.display = 'block';
            } else {
                iaResult.innerHTML = `
                    <div class="alert alert-danger">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        ${result.error || 'Erreur lors de l\'analyse'}
                    </div>
                `;
                iaResult.style.display = 'block';
            }
        };
        reader.readAsDataURL(blob);
    } catch (error) {
        iaLoading.style.display = 'none';
        iaResult.innerHTML = `
            <div class="alert alert-danger">
                <i class="bi bi-exclamation-triangle me-2"></i>
                Erreur: ${error.message}
            </div>
        `;
        iaResult.style.display = 'block';
    }
}
<?php endif; ?>
</script>

<?php include '../../includes/footer.php'; ?>
