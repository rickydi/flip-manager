<?php
/**
 * Nouveau sous-traitant / Modifier sous-traitant - Admin
 * Flip Manager - Structure identique à nouvelle facture
 */

require_once '../../config.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

requireLogin();
$isAdmin = isAdmin();

// Auto-migration: créer la table sous_traitants si elle n'existe pas
try {
    $pdo->query("SELECT 1 FROM sous_traitants LIMIT 1");
} catch (Exception $e) {
    $sqlFile = __DIR__ . '/../../sql/migration_sous_traitants.sql';
    if (file_exists($sqlFile)) {
        $sql = file_get_contents($sqlFile);
        $statements = array_filter(array_map('trim', explode(';', $sql)));
        foreach ($statements as $statement) {
            if (!empty($statement) && stripos($statement, '--') !== 0) {
                try { $pdo->exec($statement); } catch (Exception $e2) {}
            }
        }
    }
}

// Migration: ajouter colonne etape_id si elle n'existe pas
try {
    $pdo->query("SELECT etape_id FROM sous_traitants LIMIT 1");
} catch (Exception $e) {
    try { $pdo->exec("ALTER TABLE sous_traitants ADD COLUMN etape_id INT DEFAULT NULL"); } catch (Exception $e2) {}
}

// Mode édition si ID fourni
$soustraitantId = (int)($_GET['id'] ?? 0);
$isEdit = false;
$soustraitant = null;

if ($soustraitantId) {
    $stmt = $pdo->prepare("SELECT * FROM sous_traitants WHERE id = ?");
    $stmt->execute([$soustraitantId]);
    $soustraitant = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($soustraitant) $isEdit = true;
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
        $entreprisesDefaut = ['Électricien', 'Plombier', 'Couvreur', 'Maçon', 'Peintre', 'Menuisier', 'Carreleur', 'Excavation', 'Béton'];
        $stmtInsert = $pdo->prepare("INSERT IGNORE INTO entreprises_soustraitants (nom) VALUES (?)");
        foreach ($entreprisesDefaut as $e) { $stmtInsert->execute([$e]); }
    }
} catch (Exception $e) {}

// Récupérer les entreprises
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
        } elseif (!empty($_POST['image_base64'])) {
            $base64Data = $_POST['image_base64'];
            if (preg_match('/^data:image\/(\w+);base64,(.+)$/', $base64Data, $matches)) {
                $extension = $matches[1] === 'jpeg' ? 'jpg' : $matches[1];
                $imageData = base64_decode($matches[2]);
                if ($imageData !== false) {
                    $fichier = 'soustraitant_' . time() . '_' . uniqid() . '.' . $extension;
                    $uploadDir = __DIR__ . '/../../uploads/soustraitants/';
                    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
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
                    $stmt = $pdo->prepare("
                        UPDATE sous_traitants SET
                            projet_id = ?, etape_id = ?, nom_entreprise = ?, contact = ?, telephone = ?, email = ?,
                            description = ?, date_facture = ?, montant_avant_taxes = ?, tps = ?, tvq = ?,
                            montant_total = ?, fichier = ?, notes = ?,
                            statut = CASE WHEN ? = 'approuvee' THEN 'approuvee' ELSE statut END,
                            date_modification = NOW()
                        WHERE id = ?
                    ");
                    $stmt->execute([
                        $projetId, $etapeId ?: null, $nomEntreprise, $contact, $telephone, $email,
                        $description, $dateFacture, $montantAvantTaxes, $tps, $tvq, $montantTotal,
                        $fichier, $notes, $statut, $soustraitantId
                    ]);
                    setFlashMessage('success', 'Sous-traitant modifié avec succès.');
                } else {
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
                }
                redirect("/admin/projets/detail.php?id={$projetId}&tab=soustraitants");
            } catch (Exception $e) {
                $errors[] = 'Erreur lors de l\'enregistrement : ' . $e->getMessage();
            }
        }
    }
}

$selectedProjet = $isEdit ? $soustraitant['projet_id'] : (int)($_GET['projet'] ?? 0);

include '../../includes/header.php';
?>

<?php
$returnUrl = $selectedProjet ? url('/admin/projets/detail.php?id=' . $selectedProjet . '&tab=soustraitants') : url('/admin/index.php');
$returnLabel = $selectedProjet ? 'Projet' : 'Tableau de bord';
?>
<div class="container-fluid">
    <div class="page-header">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= url('/admin/index.php') ?>">Tableau de bord</a></li>
                <li class="breadcrumb-item"><a href="<?= $returnUrl ?>"><?= $returnLabel ?></a></li>
                <li class="breadcrumb-item active"><?= $isEdit ? 'Modifier' : 'Nouveau' ?> sous-traitant</li>
            </ol>
        </nav>
        <h1><i class="bi bi-<?= $isEdit ? 'pencil-square' : 'plus-circle' ?> me-2"></i><?= $isEdit ? 'Modifier le sous-traitant' : 'Nouveau sous-traitant' ?></h1>
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
                    <i class="bi bi-pencil-square me-2"></i>Informations du sous-traitant
                </div>
                <div class="card-body">
                    <form method="POST" enctype="multipart/form-data" id="soustraitantForm">
                        <?php csrfField(); ?>
                        <input type="hidden" name="image_base64" id="imageBase64">

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
                                <select class="form-select" name="etape_id" id="etape_id" required>
                                    <option value="">Sélectionner...</option>
                                    <?php foreach ($etapes as $etape): ?>
                                        <option value="<?= $etape['id'] ?>" <?= ($isEdit && $soustraitant['etape_id'] == $etape['id']) ? 'selected' : '' ?>>
                                            <?= e($etape['nom']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Entreprise / Sous-traitant *</label>
                                <input type="text" class="form-control" name="nom_entreprise" id="nom_entreprise"
                                       value="<?= e($isEdit ? $soustraitant['nom_entreprise'] : '') ?>"
                                       list="entreprisesList" required autocomplete="off">
                                <datalist id="entreprisesList">
                                    <?php foreach ($toutesLesEntreprises as $ent): ?>
                                        <option value="<?= e($ent['nom']) ?>" data-contact="<?= e($ent['contact'] ?? '') ?>" data-telephone="<?= e($ent['telephone'] ?? '') ?>" data-email="<?= e($ent['email'] ?? '') ?>">
                                    <?php endforeach; ?>
                                </datalist>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Date de la facture *</label>
                                <input type="date" class="form-control" name="date_facture" id="date_facture"
                                       value="<?= e($isEdit ? $soustraitant['date_facture'] : date('Y-m-d')) ?>" required>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Description des travaux</label>
                            <textarea class="form-control" name="description" id="description" rows="2"><?= e($isEdit ? $soustraitant['description'] : '') ?></textarea>
                        </div>

                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Montant avant taxes *</label>
                                <div class="input-group">
                                    <input type="text" class="form-control money-input" name="montant_avant_taxes" id="montant_avant_taxes"
                                           value="<?= $isEdit ? number_format($soustraitant['montant_avant_taxes'], 2, '.', '') : '' ?>" required>
                                    <span class="input-group-text">$</span>
                                </div>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">TPS (5%)</label>
                                <div class="input-group">
                                    <input type="text" class="form-control money-input" name="tps" id="tps"
                                           value="<?= $isEdit ? number_format($soustraitant['tps'], 2, '.', '') : '' ?>">
                                    <span class="input-group-text">$</span>
                                </div>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">TVQ (9.975%)</label>
                                <div class="input-group">
                                    <input type="text" class="form-control money-input" name="tvq" id="tvq"
                                           value="<?= $isEdit ? number_format($soustraitant['tvq'], 2, '.', '') : '' ?>">
                                    <span class="input-group-text">$</span>
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <div class="alert alert-info d-flex justify-content-between align-items-center mb-0">
                                <div>
                                    <strong>Total : </strong><span id="totalSoustraitant"><?= $isEdit ? formatMoney($soustraitant['montant_total']) : '0,00 $' ?></span>
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
                            <label class="form-label">Photo/PDF de la soumission</label>
                            <?php if ($isEdit && !empty($soustraitant['fichier'])): ?>
                                <?php
                                $fichierExt = strtolower(pathinfo($soustraitant['fichier'], PATHINFO_EXTENSION));
                                $isPdfFichier = ($fichierExt === 'pdf');
                                ?>
                                <div class="mb-2">
                                    <a href="<?= url('/uploads/soustraitants/' . e($soustraitant['fichier'])) ?>" target="_blank">
                                        <?php if ($isPdfFichier): ?>
                                            <div class="d-inline-flex align-items-center gap-2 p-2 border rounded" style="background: rgba(220,53,69,0.1);">
                                                <i class="bi bi-file-pdf text-danger fs-2"></i>
                                                <span class="text-muted small"><?= e($soustraitant['fichier']) ?></span>
                                            </div>
                                        <?php else: ?>
                                            <img src="<?= url('/api/thumbnail.php?file=soustraitants/' . e($soustraitant['fichier']) . '&w=100&h=100') ?>"
                                                 alt="Soumission" style="max-width:100px;max-height:100px;border-radius:4px;border:1px solid #ddd">
                                        <?php endif; ?>
                                    </a>
                                </div>
                            <?php endif; ?>
                            <input type="file" class="form-control" name="fichier" id="fichierInput" accept=".jpg,.jpeg,.png,.gif,.pdf">
                            <div id="pastedImageInfo" class="d-none mt-2">
                                <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Image collée attachée</span>
                                <button type="button" class="btn btn-sm btn-link text-danger" onclick="clearPastedImage()">Retirer</button>
                            </div>
                            <small class="text-muted"><?= $isEdit ? 'Laisser vide pour conserver le fichier actuel. ' : '' ?>Formats acceptés: JPG, PNG, GIF, PDF (max 5MB)</small>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Notes</label>
                            <textarea class="form-control" name="notes" id="notes" rows="2" placeholder="Notes supplémentaires..."><?= $isEdit ? e($soustraitant['notes']) : '' ?></textarea>
                        </div>

                        <?php if ($isAdmin): ?>
                        <div class="mb-3">
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" name="approuver_direct" id="approuverDirect" <?= !$isEdit || ($isEdit && $soustraitant['statut'] == 'approuvee') ? 'checked' : '' ?>>
                                <label class="form-check-label" for="approuverDirect">
                                    <i class="bi bi-check-circle text-success"></i> Approuver directement
                                </label>
                            </div>
                        </div>
                        <?php endif; ?>

                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-circle me-1"></i><?= $isEdit ? 'Enregistrer' : 'Ajouter' ?>
                            </button>
                            <a href="<?= $returnUrl ?>" class="btn btn-secondary">Annuler</a>
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
                            <h5 class="mt-3 text-muted">Collez une soumission ici</h5>
                            <p class="text-muted mb-0">
                                <kbd>Ctrl</kbd> + <kbd>V</kbd> pour coller une image<br>
                                <small>ou cliquez pour sélectionner un fichier (image ou PDF)</small>
                            </p>
                            <input type="file" id="fileInput" accept="image/*,.pdf" class="d-none">
                        </div>
                        <div id="pastePreview" class="d-none">
                            <img id="previewImage" src="" alt="Aperçu" class="img-fluid rounded mb-3" style="max-height: 250px;">
                            <div id="pdfPreview" class="d-none text-center">
                                <i class="bi bi-file-pdf text-danger display-1"></i>
                                <p class="text-muted" id="pdfFileName"></p>
                            </div>
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
                <div class="card-header py-2" style="cursor: pointer;" data-bs-toggle="collapse" data-bs-target="#collapsePromptIA" aria-expanded="false">
                    <div class="d-flex justify-content-between align-items-center">
                        <h6 class="mb-0"><i class="bi bi-robot me-2"></i>Prompt IA (éditable)</h6>
                        <i class="bi bi-chevron-down collapse-icon"></i>
                    </div>
                </div>
                <div class="collapse" id="collapsePromptIA">
                    <div class="card-body pt-2">
                        <textarea class="form-control font-monospace" id="promptIA" rows="12" style="font-size: 0.75rem;"><?php
$etapesPrompt = [];
try {
    $stmtE = $pdo->query("SELECT id, nom FROM budget_etapes ORDER BY ordre, nom");
    $etapesPrompt = $stmtE->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$etapesListe = "";
foreach ($etapesPrompt as $etape) {
    $etapesListe .= "- id: {$etape['id']}, nom: {$etape['nom']}\n";
}

echo htmlspecialchars("Analyse cette soumission de sous-traitant (électricien, plombier, couvreur, etc.).

ÉTAPE 1 - IDENTIFIER L'ENTREPRISE:
Cherche le nom de l'entreprise/sous-traitant en haut du document.
Extrait aussi: nom du contact, téléphone, email si présents.

ÉTAPE 2 - EXTRAIRE LES MONTANTS:
- Trouve le sous-total (avant taxes)
- Trouve TPS (5%) et TVQ (9.975%)
- Trouve le total
- Trouve la date (format YYYY-MM-DD)

ÉTAPE 3 - CATÉGORISER PAR ÉTAPE:
Utilise ces étapes de construction:
{$etapesListe}
Guide: Électricien→électricité, Plombier→plomberie, Couvreur→toiture, Maçon→fondations, Peintre→peinture

ÉTAPE 4 - DESCRIPTION:
Résume brièvement les travaux décrits.

JSON OBLIGATOIRE:
{
  \"nom_entreprise\": \"NOM DE L'ENTREPRISE\",
  \"contact\": \"Nom du contact\",
  \"telephone\": \"514-XXX-XXXX\",
  \"email\": \"email@exemple.com\",
  \"date_facture\": \"YYYY-MM-DD\",
  \"montant_avant_taxes\": 0.00,
  \"tps\": 0.00,
  \"tvq\": 0.00,
  \"total\": 0.00,
  \"etape_id\": 0,
  \"etape_nom\": \"...\",
  \"description\": \"Description des travaux\"
}");
?></textarea>
                        <small class="text-muted">Tu peux modifier ce prompt avant d'analyser une soumission</small>
                    </div>
                </div>
            </div>

            <div class="card mt-3">
                <div class="card-header py-2" style="cursor: pointer;" data-bs-toggle="collapse" data-bs-target="#collapseResultatIA" aria-expanded="false">
                    <div class="d-flex justify-content-between align-items-center">
                        <h6 class="mb-0"><i class="bi bi-terminal me-2"></i>Résultat IA détail</h6>
                        <i class="bi bi-chevron-down collapse-icon"></i>
                    </div>
                </div>
                <div class="collapse" id="collapseResultatIA">
                    <div class="card-body pt-2">
                        <textarea class="form-control font-monospace" id="promptResultat" rows="10" style="font-size: 0.7rem; background-color: #1a1a2e; color: #0f0;" readonly placeholder="Le résultat JSON de l'IA apparaîtra ici..."></textarea>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.collapse-icon { transition: transform 0.3s ease; }
[aria-expanded="true"] .collapse-icon { transform: rotate(180deg); }
.card-header:hover { background-color: rgba(0,0,0,0.03); }
.ai-loader { height: 6px; background: #e9ecef; border-radius: 3px; overflow: hidden; margin: 10px 0; }
.ai-loader-bar { height: 100%; background: linear-gradient(90deg, #007bff, #00d4ff, #007bff); background-size: 200% 100%; animation: aiLoading 1.5s infinite linear; }
@keyframes aiLoading { 0% { background-position: 200% 0; } 100% { background-position: 0 0; } }
#pasteZone:hover { border-color: #007bff !important; background: rgba(0,123,255,0.05); }
#pasteZone.dragover { border-color: #28a745 !important; background: rgba(40,167,69,0.1); }
</style>

<script>
// Variables globales
let currentImageData = null;
let currentMimeType = null;

// Calcul du total
function calculerTotal() {
    const montant = parseFloat(document.getElementById('montant_avant_taxes').value.replace(/[^0-9.-]/g, '')) || 0;
    const tps = parseFloat(document.getElementById('tps').value.replace(/[^0-9.-]/g, '')) || 0;
    const tvq = parseFloat(document.getElementById('tvq').value.replace(/[^0-9.-]/g, '')) || 0;
    const total = montant + tps + tvq;
    document.getElementById('totalSoustraitant').textContent = new Intl.NumberFormat('fr-CA', {style: 'currency', currency: 'CAD'}).format(total);
}

// Recalculer les taxes
function recalculerTaxes() {
    const montant = parseFloat(document.getElementById('montant_avant_taxes').value.replace(/[^0-9.-]/g, '')) || 0;
    document.getElementById('tps').value = (montant * 0.05).toFixed(2);
    document.getElementById('tvq').value = (montant * 0.09975).toFixed(2);
    calculerTotal();
}

// Sans taxes
function sansTaxes() {
    document.getElementById('tps').value = '0.00';
    document.getElementById('tvq').value = '0.00';
    calculerTotal();
}

// Écouter les changements sur les montants
['montant_avant_taxes', 'tps', 'tvq'].forEach(id => {
    document.getElementById(id).addEventListener('input', calculerTotal);
});

// Auto-remplissage des informations de l'entreprise
document.getElementById('nom_entreprise').addEventListener('input', function() {
    const options = document.querySelectorAll('#entreprisesList option');
    options.forEach(option => {
        if (option.value === this.value) {
            // On ne remplit plus automatiquement - l'IA le fera
        }
    });
});

// Zone de collage IA
const pasteZone = document.getElementById('pasteZone');
const fileInput = document.getElementById('fileInput');
const pasteInstructions = document.getElementById('pasteInstructions');
const pastePreview = document.getElementById('pastePreview');
const previewImage = document.getElementById('previewImage');
const pdfPreview = document.getElementById('pdfPreview');
const pdfFileName = document.getElementById('pdfFileName');
const analysisStatus = document.getElementById('analysisStatus');
const aiResult = document.getElementById('aiResult');
const aiError = document.getElementById('aiError');

// Clic sur la zone = ouvrir sélecteur de fichier
pasteZone.addEventListener('click', () => fileInput.click());

// Drag & Drop
pasteZone.addEventListener('dragover', (e) => {
    e.preventDefault();
    pasteZone.classList.add('dragover');
});

pasteZone.addEventListener('dragleave', () => {
    pasteZone.classList.remove('dragover');
});

pasteZone.addEventListener('drop', (e) => {
    e.preventDefault();
    pasteZone.classList.remove('dragover');
    if (e.dataTransfer.files.length > 0) {
        handleFile(e.dataTransfer.files[0]);
    }
});

// Sélection de fichier
fileInput.addEventListener('change', () => {
    if (fileInput.files.length > 0) {
        handleFile(fileInput.files[0]);
    }
});

// Coller une image (Ctrl+V)
document.addEventListener('paste', (e) => {
    const items = e.clipboardData?.items;
    if (!items) return;

    for (let item of items) {
        if (item.type.startsWith('image/')) {
            e.preventDefault();
            const file = item.getAsFile();
            handleFile(file, true);
            break;
        }
    }
});

// Gérer un fichier
function handleFile(file, isPasted = false) {
    const isPdf = file.type === 'application/pdf';

    // Afficher l'aperçu
    pasteInstructions.classList.add('d-none');
    pastePreview.classList.remove('d-none');

    if (isPdf) {
        previewImage.classList.add('d-none');
        pdfPreview.classList.remove('d-none');
        pdfFileName.textContent = file.name;

        // Convertir le PDF en image pour l'analyse
        convertPdfAndAnalyze(file);
    } else {
        previewImage.classList.remove('d-none');
        pdfPreview.classList.add('d-none');

        const reader = new FileReader();
        reader.onload = (e) => {
            currentImageData = e.target.result;
            currentMimeType = file.type || 'image/png';
            previewImage.src = currentImageData;

            // Mettre l'image dans le champ caché pour le formulaire
            if (isPasted) {
                document.getElementById('imageBase64').value = currentImageData;
                document.getElementById('pastedImageInfo').classList.remove('d-none');
            }

            // Lancer l'analyse automatiquement
            analyzeWithAI();
        };
        reader.readAsDataURL(file);
    }
}

// Convertir PDF et analyser
async function convertPdfAndAnalyze(file) {
    analysisStatus.innerHTML = `
        <div class="ai-loader"><div class="ai-loader-bar"></div></div>
        <p class="text-muted mb-0">Conversion du PDF...</p>
    `;

    try {
        const formData = new FormData();
        formData.append('fichier', file);

        const response = await fetch('<?= url('/api/factures/convertir-pdf.php') ?>', {
            method: 'POST',
            body: formData
        });
        const result = await response.json();

        if (result.success && result.image) {
            currentImageData = result.image;
            currentMimeType = 'image/png';
            analyzeWithAI();
        } else {
            throw new Error(result.error || 'Erreur conversion PDF');
        }
    } catch (error) {
        analysisStatus.innerHTML = '';
        document.getElementById('errorMessage').textContent = error.message;
        aiError.classList.remove('d-none');
    }
}

// Analyser avec l'IA
async function analyzeWithAI() {
    if (!currentImageData) return;

    analysisStatus.innerHTML = `
        <div class="ai-loader"><div class="ai-loader-bar"></div></div>
        <p class="text-muted mb-0">Analyse en cours par l'IA...</p>
    `;

    try {
        const customPrompt = document.getElementById('promptIA').value;

        const response = await fetch('<?= url('/api/analyse-soumission.php') ?>', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                image: currentImageData,
                mime_type: currentMimeType,
                prompt: customPrompt
            })
        });

        const result = await response.json();

        // Afficher le résultat brut
        document.getElementById('promptResultat').value = JSON.stringify(result, null, 2);

        if (result.success && result.data) {
            analysisStatus.innerHTML = '';
            fillForm(result.data);

            const confidence = result.data.confiance ? Math.round(result.data.confiance * 100) : 'N/A';
            document.getElementById('confidenceLevel').textContent = confidence + '%';
            aiResult.classList.remove('d-none');
        } else {
            throw new Error(result.error || 'Erreur analyse IA');
        }
    } catch (error) {
        analysisStatus.innerHTML = '';
        document.getElementById('errorMessage').textContent = error.message;
        aiError.classList.remove('d-none');
    }
}

// Remplir le formulaire avec les données extraites
function fillForm(data) {
    const fields = {
        'nom_entreprise': data.nom_entreprise || data.fournisseur,
        'contact': data.contact,
        'date_facture': data.date_facture || data.date_soumission,
        'description': data.description,
        'montant_avant_taxes': data.montant_avant_taxes || data.sous_total,
        'tps': data.tps,
        'tvq': data.tvq,
        'notes': data.notes
    };

    // Remplir les champs avec animation
    for (const [fieldId, value] of Object.entries(fields)) {
        if (value) {
            const field = document.getElementById(fieldId);
            if (field) {
                field.value = typeof value === 'number' ? value.toFixed(2) : value;
                field.classList.add('bg-success-subtle');
                setTimeout(() => field.classList.remove('bg-success-subtle'), 2000);
            }
        }
    }

    // Sélectionner l'étape
    if (data.etape_id) {
        const etapeSelect = document.getElementById('etape_id');
        if (etapeSelect.querySelector(`option[value="${data.etape_id}"]`)) {
            etapeSelect.value = data.etape_id;
            etapeSelect.classList.add('bg-success-subtle');
            setTimeout(() => etapeSelect.classList.remove('bg-success-subtle'), 2000);
        }
    }

    // Recalculer le total
    calculerTotal();
}

// Réinitialiser la zone de collage
function resetPasteZone() {
    currentImageData = null;
    currentMimeType = null;
    pasteInstructions.classList.remove('d-none');
    pastePreview.classList.add('d-none');
    previewImage.src = '';
    analysisStatus.innerHTML = '';
    aiResult.classList.add('d-none');
    aiError.classList.add('d-none');
    fileInput.value = '';
}

// Retirer l'image collée
function clearPastedImage() {
    document.getElementById('imageBase64').value = '';
    document.getElementById('pastedImageInfo').classList.add('d-none');
}
</script>

<?php include '../../includes/footer.php'; ?>
