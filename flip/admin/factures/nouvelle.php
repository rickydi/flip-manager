<?php
/**
 * Nouvelle facture / Modifier facture - Admin
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

// Mode édition si ID fourni
$factureId = (int)($_GET['id'] ?? 0);
$isEdit = false;
$facture = null;

if ($factureId) {
    $stmt = $pdo->prepare("SELECT * FROM factures WHERE id = ?");
    $stmt->execute([$factureId]);
    $facture = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($facture) {
        $isEdit = true;
    }
}

// DEBUG: Afficher les infos de la facture
if ($factureId) {
    error_log("FACTURE DEBUG: ID=$factureId, isEdit=" . ($isEdit ? 'true' : 'false') . ", facture=" . json_encode($facture));
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
        $approuverDirect = isset($_POST['approuver_direct']);

        // Validation
        if (!$projetId) $errors[] = 'Veuillez sélectionner un projet.';
        if (!$etapeId) $errors[] = 'Veuillez sélectionner une étape.';
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
        $fichier = $isEdit ? $facture['fichier'] : null; // Garder l'existant en mode édition
        if (isset($_FILES['fichier']) && $_FILES['fichier']['error'] !== UPLOAD_ERR_NO_FILE) {
            $upload = uploadFile($_FILES['fichier']);
            if ($upload['success']) {
                // Supprimer l'ancien fichier en mode édition
                if ($isEdit && $facture['fichier']) {
                    deleteUploadedFile($facture['fichier']);
                }
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

            // Auto-sélectionner l'étape principale basée sur le breakdown
            if (!empty($_POST['breakdown_data']) && (!$etapeId || $etapeId == 0)) {
                $breakdownData = json_decode($_POST['breakdown_data'], true);
                if ($breakdownData && !empty($breakdownData['totaux_par_etape'])) {
                    // Récupérer le mapping nom -> id des étapes
                    $etapesMap = [];
                    $stmtEtapes = $pdo->query("SELECT id, nom FROM budget_etapes");
                    while ($row = $stmtEtapes->fetch()) {
                        $etapesMap[strtolower(trim($row['nom']))] = $row['id'];
                    }

                    // Trouver l'étape avec le plus gros montant
                    $maxMontant = 0;
                    $etapePrincipale = null;
                    foreach ($breakdownData['totaux_par_etape'] as $t) {
                        if ($t['montant'] > $maxMontant) {
                            $maxMontant = $t['montant'];
                            $etapePrincipale = $t['etape_nom'] ?? '';
                        }
                    }

                    if ($etapePrincipale) {
                        $nomLower = strtolower(trim($etapePrincipale));
                        if (isset($etapesMap[$nomLower])) {
                            $etapeId = $etapesMap[$nomLower];
                        } else {
                            foreach ($etapesMap as $nom => $id) {
                                if (strpos($nom, $nomLower) !== false || strpos($nomLower, $nom) !== false) {
                                    $etapeId = $id;
                                    break;
                                }
                            }
                        }
                    }
                }
            }

            if ($isEdit) {
                // Mode édition: UPDATE - utiliser le checkbox pour le statut
                $stmt = $pdo->prepare("
                    UPDATE factures SET
                        projet_id = ?, etape_id = ?, fournisseur = ?, description = ?,
                        date_facture = ?, montant_avant_taxes = ?, tps = ?, tvq = ?,
                        montant_total = ?, fichier = ?, notes = ?, statut = ?,
                        approuve_par = ?, date_approbation = ?
                    WHERE id = ?
                ");
                $success = $stmt->execute([
                    $projetId, $etapeId ?: null, $fournisseur, $description,
                    $dateFacture, $montantAvantTaxes, $tps, $tvq, $montantTotal, $fichier, $notes,
                    $statut, $approuvePar, $dateApprobation, $factureId
                ]);
                $newFactureId = $factureId;
            } else {
                // Mode création: INSERT
                $stmt = $pdo->prepare("
                    INSERT INTO factures (projet_id, etape_id, user_id, fournisseur, description, date_facture,
                                         montant_avant_taxes, tps, tvq, montant_total, fichier, notes, statut,
                                         approuve_par, date_approbation)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $success = $stmt->execute([
                    $projetId, $etapeId ?: null, $_SESSION['user_id'], $fournisseur, $description,
                    $dateFacture, $montantAvantTaxes, $tps, $tvq, $montantTotal, $fichier, $notes,
                    $statut, $approuvePar, $dateApprobation
                ]);
                $newFactureId = $pdo->lastInsertId();
            }

            if ($success) {

                // Sauvegarder les lignes du breakdown si présentes
                if (!empty($_POST['breakdown_data'])) {
                    $breakdownData = json_decode($_POST['breakdown_data'], true);
                    if ($breakdownData && !empty($breakdownData['lignes'])) {
                        // Récupérer le mapping nom -> id des étapes
                        $etapesMap = [];
                        $stmtEtapes = $pdo->query("SELECT id, nom FROM budget_etapes");
                        while ($row = $stmtEtapes->fetch()) {
                            $etapesMap[strtolower(trim($row['nom']))] = $row['id'];
                        }

                        // Supprimer les anciennes lignes (au cas où)
                        $stmtDel = $pdo->prepare("DELETE FROM facture_lignes WHERE facture_id = ?");
                        $stmtDel->execute([$newFactureId]);

                        // Insérer les nouvelles lignes
                        $stmtLigne = $pdo->prepare("
                            INSERT INTO facture_lignes (facture_id, description, quantite, prix_unitaire, total, etape_id, etape_nom, raison)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                        ");

                        foreach ($breakdownData['lignes'] as $ligne) {
                            // Trouver le vrai etape_id par le nom
                            $etapeNom = $ligne['etape_nom'] ?? '';
                            $etapeId = null;

                            // Chercher par nom exact
                            $nomLower = strtolower(trim($etapeNom));
                            if (isset($etapesMap[$nomLower])) {
                                $etapeId = $etapesMap[$nomLower];
                            } else {
                                // Chercher par correspondance partielle
                                foreach ($etapesMap as $nom => $id) {
                                    if (strpos($nom, $nomLower) !== false || strpos($nomLower, $nom) !== false) {
                                        $etapeId = $id;
                                        break;
                                    }
                                }
                            }

                            $stmtLigne->execute([
                                $newFactureId,
                                $ligne['description'] ?? '',
                                $ligne['quantite'] ?? 1,
                                $ligne['prix_unitaire'] ?? 0,
                                $ligne['total'] ?? 0,
                                $etapeId,
                                $etapeNom,
                                $ligne['raison'] ?? ''
                            ]);
                        }
                    }
                }

                if ($isEdit) {
                    $msg = 'Facture mise à jour!';
                } else {
                    $msg = $approuverDirect ? 'Facture ajoutée et approuvée!' : 'Facture ajoutée!';
                }
                setFlashMessage('success', $msg);
                redirect('/admin/projets/detail.php?id=' . $projetId . '&tab=factures');
            } else {
                $errors[] = $isEdit ? 'Erreur lors de la mise à jour de la facture.' : 'Erreur lors de l\'ajout de la facture.';
                if ($fichier) deleteUploadedFile($fichier);
            }
        }
    }
}

// Pré-sélection du projet si passé en paramètre
$selectedProjet = $isEdit ? $facture['projet_id'] : (int)($_GET['projet'] ?? 0);

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
    
    <?php if ($factureId): ?>
        <div class="alert alert-warning">
            <strong>DEBUG:</strong> ID=<?= $factureId ?>, isEdit=<?= $isEdit ? 'OUI' : 'NON' ?>,
            Fournisseur=<?= $facture ? e($facture['fournisseur'] ?? 'N/A') : 'AUCUNE FACTURE' ?>
        </div>
    <?php endif; ?>

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
                        <input type="hidden" name="breakdown_data" id="breakdownData" value="">

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
                        <select class="form-select" name="etape_id" id="etapeSelect" required>
                            <option value="">Sélectionner...</option>
                            <?php foreach ($etapes as $etape): ?>
                                <option value="<?= $etape['id'] ?>" <?= ($isEdit && ($facture['etape_id'] ?? 0) == $etape['id']) ? 'selected' : '' ?>><?= ($etape['ordre'] + 1) ?>. <?= e($etape['nom']) ?></option>
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
                                <option value="<?= e($f) ?>" <?= ($isEdit && $facture['fournisseur'] == $f) ? 'selected' : '' ?>><?= e($f) ?></option>
                            <?php endforeach; ?>
                            <option value="__autre__">➕ Autre (ajouter nouveau)</option>
                        </select>
                    </div>

                    <div class="col-md-6 mb-3">
                        <label class="form-label">Date de la facture *</label>
                        <input type="date" class="form-control" name="date_facture" required
                               value="<?= $isEdit ? e($facture['date_facture']) : date('Y-m-d') ?>">
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Articles <small class="text-muted">(détectés par l'IA)</small></label>
                    <input type="hidden" name="description" id="descriptionHidden">
                    <div id="articlesTableContainer" class="d-none">
                        <div class="table-responsive">
                            <table class="table table-sm table-hover mb-0" id="articlesTable">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width: 40%;">Produit</th>
                                        <th class="text-center" style="width: 8%;">Qté</th>
                                        <th class="text-end" style="width: 12%;">Prix</th>
                                        <th style="width: 25%;">Étape</th>
                                        <th class="text-center" style="width: 15%;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="articlesTableBody">
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div id="noArticlesMsg" class="text-muted small">
                        <i class="bi bi-info-circle me-1"></i>Collez une image pour détecter les articles
                    </div>
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
                                   id="montantAvantTaxes" required placeholder="0.00"
                                   value="<?= $isEdit ? formatMoney($facture['montant_avant_taxes'], false) : '' ?>">
                            <span class="input-group-text">$</span>
                        </div>
                    </div>

                    <div class="col-md-4 mb-3">
                        <label class="form-label">TPS (5%)</label>
                        <div class="input-group">
                            <input type="text" class="form-control money-input" name="tps" id="tps" placeholder="0.00"
                                   value="<?= $isEdit ? formatMoney($facture['tps'], false) : '' ?>">
                            <span class="input-group-text">$</span>
                        </div>
                    </div>

                    <div class="col-md-4 mb-3">
                        <label class="form-label">TVQ (9.975%)</label>
                        <div class="input-group">
                            <input type="text" class="form-control money-input" name="tvq" id="tvq" placeholder="0.00"
                                   value="<?= $isEdit ? formatMoney($facture['tvq'], false) : '' ?>">
                            <span class="input-group-text">$</span>
                        </div>
                    </div>
                </div>

                <div class="mb-3">
                    <div class="alert alert-info d-flex justify-content-between align-items-center mb-0">
                        <div>
                            <strong>Total : </strong><span id="totalFacture"><?= $isEdit ? formatMoney($facture['montant_total']) : '0,00 $' ?></span>
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
                    <?php if ($isEdit && !empty($facture['fichier'])): ?>
                        <div class="mb-2">
                            <a href="<?= url('/uploads/factures/' . e($facture['fichier'])) ?>" target="_blank">
                                <img src="<?= url('/api/thumbnail.php?file=factures/' . e($facture['fichier']) . '&w=100&h=100') ?>"
                                     alt="Facture" style="max-width:100px;max-height:100px;border-radius:4px;border:1px solid #ddd">
                            </a>
                        </div>
                    <?php endif; ?>
                    <input type="file" class="form-control" name="fichier" id="fichierInput" accept=".jpg,.jpeg,.png,.gif,.pdf">
                    <input type="hidden" name="image_base64" id="imageBase64">
                    <div id="pastedImageInfo" class="d-none mt-2">
                        <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Image collée attachée</span>
                        <button type="button" class="btn btn-sm btn-link text-danger" onclick="clearPastedImage()">Retirer</button>
                    </div>
                    <small class="text-muted"><?= $isEdit ? 'Laisser vide pour conserver le fichier actuel. ' : '' ?>Formats acceptés: JPG, PNG, GIF, PDF (max 5MB)</small>
                </div>

                <div class="mb-3">
                    <label class="form-label">Notes</label>
                    <textarea class="form-control" name="notes" rows="2" placeholder="Notes supplémentaires..."><?= $isEdit ? e($facture['notes']) : '' ?></textarea>
                </div>

                <div class="mb-3">
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" name="approuver_direct" id="approuverDirect" <?= !$isEdit || ($isEdit && $facture['statut'] == 'approuvee') ? 'checked' : '' ?>>
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
                <div class="card-header py-2" style="cursor: pointer;" data-bs-toggle="collapse" data-bs-target="#collapsePromptIA" aria-expanded="false">
                    <div class="d-flex justify-content-between align-items-center">
                        <h6 class="mb-0"><i class="bi bi-robot me-2"></i>Prompt IA (éditable)</h6>
                        <i class="bi bi-chevron-down collapse-icon"></i>
                    </div>
                </div>
                <div class="collapse" id="collapsePromptIA">
                    <div class="card-body pt-2">
                        <textarea class="form-control font-monospace" id="promptIA" rows="12" style="font-size: 0.75rem;"><?php
// Récupérer les étapes pour le prompt
$etapesPrompt = [];
try {
    $stmtE = $pdo->query("SELECT id, nom FROM budget_etapes ORDER BY ordre, nom");
    $etapesPrompt = $stmtE->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$etapesListe = "";
foreach ($etapesPrompt as $etape) {
    $etapesListe .= "- id: {$etape['id']}, nom: {$etape['nom']}\n";
}

echo htmlspecialchars("Analyse cette facture de quincaillerie/rénovation.

ÉTAPE 1 - IDENTIFIER LE FOURNISSEUR:
Regarde le LOGO en haut de la facture. Fournisseurs connus: Home Depot, Réno Dépot, Rona, BMR, Patrick Morin, Canac, Canadian Tire, IKEA, Lowes.
Le fournisseur est OBLIGATOIRE - cherche le nom du magasin sur la facture.

ÉTAPE 2 - EXTRAIRE LES MONTANTS:
- Trouve le sous-total (avant taxes)
- Trouve TPS (5%) et TVQ (9.975%)
- Trouve le total
- Trouve la date (format YYYY-MM-DD)

ÉTAPE 3 - EXTRAIRE LES ARTICLES AVEC SKU:
Pour chaque article, trouve:
- Description du produit
- SKU/Code produit (numéro à 6-10 chiffres, souvent près du nom)
- Quantité et prix

ÉTAPE 4 - CATÉGORISER PAR ÉTAPE:
Utilise ces étapes de construction:
{$etapesListe}
Guide: Bois→structure, Tuyaux→plomberie, Fils/NMD→électricité, Isolant→isolation, Gypse→gypse, Peinture→peinture

JSON OBLIGATOIRE:
{
  \"fournisseur\": \"NOM DU MAGASIN\",
  \"date_facture\": \"YYYY-MM-DD\",
  \"sous_total\": 0.00,
  \"tps\": 0.00,
  \"tvq\": 0.00,
  \"total\": 0.00,
  \"lignes\": [{\"description\": \"...\", \"sku\": \"123456\", \"quantite\": 1, \"total\": 0.00, \"etape_id\": 0, \"etape_nom\": \"...\"}],
  \"totaux_par_etape\": [{\"etape_id\": 0, \"etape_nom\": \"...\", \"montant\": 0.00}]
}");
?></textarea>
                        <small class="text-muted">Tu peux modifier ce prompt avant d'analyser une facture</small>
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

            <div class="card mt-3">
                <div class="card-header py-2" style="cursor: pointer;" data-bs-toggle="collapse" data-bs-target="#collapseItemSearch" aria-expanded="false">
                    <div class="d-flex justify-content-between align-items-center">
                        <h6 class="mb-0"><i class="bi bi-search me-2"></i>Résultat IA item recherché et image</h6>
                        <i class="bi bi-chevron-down collapse-icon"></i>
                    </div>
                </div>
                <div class="collapse" id="collapseItemSearch">
                    <div class="card-body pt-2">
                        <textarea class="form-control font-monospace" id="itemSearchLog" rows="8" style="font-size: 0.7rem; background-color: #1a1a2e; color: #0f0;" readonly placeholder="Les détails de recherche d'item apparaîtront ici..."></textarea>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
/* Collapse icon rotation */
.collapse-icon {
    transition: transform 0.3s ease;
}
[aria-expanded="true"] .collapse-icon {
    transform: rotate(180deg);
}
.card-header:hover {
    background-color: rgba(0,0,0,0.03);
}
/* Link preview popup */
#linkPreviewPopup {
    position: fixed;
    z-index: 9999;
    background: white;
    border: 1px solid #ddd;
    border-radius: 8px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.2);
    padding: 10px;
    max-width: 320px;
    display: none;
}
#linkPreviewPopup.show {
    display: block;
    animation: fadeIn 0.2s ease;
}
#linkPreviewPopup img {
    max-width: 100%;
    max-height: 200px;
    border-radius: 4px;
}
#linkPreviewPopup .preview-title {
    font-size: 0.85rem;
    font-weight: 600;
    margin-top: 8px;
    color: #333;
}
#linkPreviewPopup .preview-link {
    font-size: 0.7rem;
    color: #666;
    word-break: break-all;
}
#linkPreviewPopup .preview-loading {
    text-align: center;
    padding: 20px;
    color: #666;
}
@keyframes fadeIn {
    from { opacity: 0; transform: translateY(-5px); }
    to { opacity: 1; transform: translateY(0); }
}
/* Dropdown étape avec flèche blanche */
.etape-select {
    background-color: #6c757d;
    color: white;
    border-color: #6c757d;
    background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3e%3cpath fill='none' stroke='white' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='m2 5 6 6 6-6'/%3e%3c/svg%3e");
}
.etape-select:focus {
    background-color: #5a6268;
    border-color: #545b62;
    color: white;
    box-shadow: 0 0 0 0.2rem rgba(108, 117, 125, 0.5);
}
.etape-select option {
    background-color: white;
    color: #333;
}
</style>

<!-- Link Preview Popup -->
<div id="linkPreviewPopup">
    <div id="linkPreviewContent"></div>
</div>

<script>
let taxesActives = true;
let linkPreviewTimeout = null;
let currentPreviewElement = null;

// Démarrer le preview après 2 secondes de survol
function startLinkPreview(element) {
    cancelLinkPreview();
    currentPreviewElement = element;

    linkPreviewTimeout = setTimeout(() => {
        showLinkPreview(element);
    }, 2000); // 2 secondes
}

// Annuler le preview
function cancelLinkPreview() {
    if (linkPreviewTimeout) {
        clearTimeout(linkPreviewTimeout);
        linkPreviewTimeout = null;
    }
    hideLinkPreview();
}

// Afficher le preview
function showLinkPreview(element) {
    const popup = document.getElementById('linkPreviewPopup');
    const content = document.getElementById('linkPreviewContent');
    const link = element.dataset.link;
    const sku = element.dataset.sku;
    const desc = element.dataset.desc;

    // Position du popup près de l'élément
    const rect = element.getBoundingClientRect();
    popup.style.left = Math.min(rect.left, window.innerWidth - 340) + 'px';
    popup.style.top = (rect.bottom + 10) + 'px';

    // Afficher loading
    content.innerHTML = `
        <div class="preview-loading">
            <div class="spinner-border spinner-border-sm text-primary mb-2"></div>
            <div>Chargement aperçu...</div>
        </div>
    `;
    popup.classList.add('show');

    // Essayer de charger l'image du produit via notre API
    fetchProductPreview(link, sku, desc);
}

// Cacher le preview
function hideLinkPreview() {
    const popup = document.getElementById('linkPreviewPopup');
    popup.classList.remove('show');
}

// Récupérer l'aperçu du produit
function fetchProductPreview(link, sku, desc) {
    const content = document.getElementById('linkPreviewContent');

    // Utiliser notre API pour récupérer les métadonnées
    fetch('<?= url('/api/link-preview.php') ?>?url=' + encodeURIComponent(link))
        .then(r => r.json())
        .then(data => {
            if (data.success && data.image) {
                content.innerHTML = `
                    <img src="${data.image}" alt="${desc}" onerror="this.style.display='none'">
                    <div class="preview-title">${data.title || desc}</div>
                    <div class="preview-link">${link}</div>
                `;
            } else {
                // Fallback: juste afficher les infos
                content.innerHTML = `
                    <div class="text-center py-3">
                        <i class="bi bi-box-seam display-4 text-muted"></i>
                        <div class="preview-title mt-2">${desc}</div>
                        <div class="preview-link">${link}</div>
                        <small class="text-muted d-block mt-2">SKU: ${sku}</small>
                    </div>
                `;
            }
        })
        .catch(err => {
            // Erreur: afficher infos basiques
            content.innerHTML = `
                <div class="text-center py-3">
                    <i class="bi bi-link-45deg display-4 text-muted"></i>
                    <div class="preview-title mt-2">${desc}</div>
                    <div class="preview-link">${link}</div>
                </div>
            `;
        });
}

// Fermer le preview si on clique ailleurs
document.addEventListener('click', (e) => {
    if (!e.target.closest('#linkPreviewPopup') && !e.target.closest('.link-preview-trigger')) {
        hideLinkPreview();
    }
});

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

// Variables pour la progression
let analysisProgress = 0;
let analysisInterval = null;

function startProgressAnimation() {
    analysisProgress = 0;
    updateProgressDisplay();

    // Progression simulée: rapide au début, ralentit vers 90%
    analysisInterval = setInterval(() => {
        if (analysisProgress < 30) {
            analysisProgress += Math.random() * 8 + 4; // 4-12% par tick
        } else if (analysisProgress < 60) {
            analysisProgress += Math.random() * 5 + 2; // 2-7% par tick
        } else if (analysisProgress < 85) {
            analysisProgress += Math.random() * 3 + 1; // 1-4% par tick
        } else if (analysisProgress < 95) {
            analysisProgress += Math.random() * 1; // 0-1% par tick
        }
        // Ne jamais dépasser 95% avant la vraie fin
        analysisProgress = Math.min(analysisProgress, 95);
        updateProgressDisplay();
    }, 300);
}

function updateProgressDisplay() {
    const percent = Math.round(analysisProgress);
    analysisStatus.innerHTML = `
        <div class="progress mb-2" style="height: 24px;">
            <div class="progress-bar progress-bar-striped progress-bar-animated bg-primary"
                 role="progressbar"
                 style="width: ${percent}%; transition: width 0.3s ease;"
                 aria-valuenow="${percent}" aria-valuemin="0" aria-valuemax="100">
                <span class="fw-bold">${percent}%</span>
            </div>
        </div>
        <small class="text-muted">
            <i class="bi bi-robot me-1"></i>Claude AI analyse la facture...
        </small>
    `;
}

function completeProgress() {
    if (analysisInterval) {
        clearInterval(analysisInterval);
        analysisInterval = null;
    }
    analysisProgress = 100;
    updateProgressDisplay();

    // Effacer après 500ms
    setTimeout(() => {
        analysisStatus.innerHTML = '';
    }, 500);
}

function cancelProgress() {
    if (analysisInterval) {
        clearInterval(analysisInterval);
        analysisInterval = null;
    }
    analysisStatus.innerHTML = '';
}

function analyzeImage(base64Data, mimeType) {
    startProgressAnimation();

    // Récupérer le prompt personnalisé
    const customPrompt = document.getElementById('promptIA').value;

    // Utiliser directement l'API d'analyse détaillée qui fait tout d'un coup
    fetch('<?= url('/api/analyse-facture-details.php') ?>', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            image: base64Data,
            custom_prompt: customPrompt
        })
    })
    .then(response => response.json())
    .then(data => {
        completeProgress();

        // Afficher le résultat brut dans le debug
        document.getElementById('promptResultat').value = JSON.stringify(data, null, 2);

        if (data.success && data.data) {
            // Remplir tous les champs avec les données détaillées
            fillFormWithDetailedData(data.data);
            aiResult.classList.remove('d-none');

            // Afficher le niveau de confiance basé sur le nombre de lignes trouvées
            const nbLignes = data.data.lignes?.length || 0;
            const confidenceEl = document.getElementById('confidenceLevel');
            confidenceEl.textContent = nbLignes + ' articles détectés';
            confidenceEl.className = 'badge ms-2 ' + (nbLignes > 5 ? 'bg-success' : nbLignes > 0 ? 'bg-warning' : 'bg-danger');

            // Stocker automatiquement le breakdown (pas besoin de modal)
            if (data.data.lignes && data.data.lignes.length > 0) {
                currentBreakdownData = data.data;
                document.getElementById('breakdownData').value = JSON.stringify(data.data);

                // Afficher résumé des étapes
                showBreakdownSummary(data.data);
            }
        } else {
            showError(data.error || 'Erreur lors de l\'analyse');
        }
    })
    .catch(error => {
        cancelProgress();
        showError('Erreur de connexion: ' + error.message);
    });
}

// Données des articles pour le tableau
let currentArticlesData = [];

// Options étapes pour les dropdowns
const etapesOptions = <?= json_encode($etapes) ?>;

// Générer un lien vers le produit selon le fournisseur
function generateProductLink(fournisseur, sku) {
    if (!sku) return null;

    const f = fournisseur.toLowerCase();
    const skuClean = sku.toString().replace(/\s/g, '');

    if (f.includes('home depot')) {
        return `https://www.homedepot.ca/product/${skuClean}`;
    } else if (f.includes('rona')) {
        return `https://www.rona.ca/fr/produit/${skuClean}`;
    } else if (f.includes('réno') || f.includes('reno depot') || f.includes('renodepot')) {
        return `https://www.renodepot.com/fr/produit/${skuClean}`;
    } else if (f.includes('bmr')) {
        return `https://www.bmr.co/fr/produit/${skuClean}`;
    } else if (f.includes('canac')) {
        return `https://www.canac.ca/fr/produit/${skuClean}`;
    } else if (f.includes('patrick morin')) {
        return `https://www.yourlink.ca/search?q=${skuClean}`;
    } else if (f.includes('canadian tire')) {
        return `https://www.canadiantire.ca/fr/search.html?q=${skuClean}`;
    } else if (f.includes('ikea')) {
        return `https://www.ikea.com/ca/fr/search/?q=${skuClean}`;
    }

    return null;
}

// Générer le tableau des articles
function renderArticlesTable(articles) {
    const tbody = document.getElementById('articlesTableBody');
    tbody.innerHTML = '';

    articles.forEach((article, idx) => {
        const desc = article.description || 'N/A';
        const qty = article.quantite || 1;
        const prix = (article.total || 0).toFixed(2);
        const etapeId = article.etape_id || '';
        const etapeNom = article.etape_nom || '';
        const sku = article.sku || article.code_produit || '';
        const link = article.link || '';

        // Générer les options d'étapes
        let etapeOptionsHtml = '<option value="">--</option>';
        etapesOptions.forEach(e => {
            const selected = (e.id == etapeId || e.nom.toLowerCase() === etapeNom.toLowerCase()) ? 'selected' : '';
            etapeOptionsHtml += `<option value="${e.id}" ${selected}>${e.nom}</option>`;
        });

        const row = document.createElement('tr');
        row.innerHTML = `
            <td>
                <small class="d-block" style="word-wrap: break-word;">${desc}</small>
                ${sku ? `<span class="badge bg-light text-muted" style="font-size: 0.65rem;">${sku}</span>` : ''}
            </td>
            <td class="text-center">${qty}</td>
            <td class="text-end"><strong>${prix}$</strong></td>
            <td>
                <select class="form-select form-select-sm etape-select" onchange="updateArticleEtape(${idx}, this.value)">
                    ${etapeOptionsHtml}
                </select>
            </td>
            <td class="text-center">
                <div class="btn-group btn-group-sm">
                    ${link ? `<a href="${link}" target="_blank" class="btn btn-outline-primary link-preview-trigger"
                        data-link="${link}" data-sku="${sku}" data-desc="${desc}"
                        onmouseenter="startLinkPreview(this)" onmouseleave="cancelLinkPreview()"
                        title="Voir produit"><i class="bi bi-box-arrow-up-right"></i></a>` : ''}
                    <button type="button" class="btn btn-outline-success" onclick="addToBudget(${idx})" title="Ajouter au catalogue">
                        <i class="bi bi-plus-lg"></i>
                    </button>
                </div>
            </td>
        `;
        tbody.appendChild(row);
    });
}

// Mettre à jour l'étape d'un article
function updateArticleEtape(idx, etapeId) {
    if (currentArticlesData[idx]) {
        currentArticlesData[idx].etape_id = etapeId;
        // Trouver le nom de l'étape
        const etape = etapesOptions.find(e => e.id == etapeId);
        if (etape) {
            currentArticlesData[idx].etape_nom = etape.nom;
        }
        updateDescriptionHidden();
        updateBreakdownData();
    }
}

// Mettre à jour le champ hidden de description
function updateDescriptionHidden() {
    const lines = currentArticlesData.map(a => {
        let line = `${a.description || 'N/A'} x${a.quantite || 1} ${(a.total || 0).toFixed(2)}$`;
        if (a.etape_nom) line += ` [${a.etape_nom}]`;
        return line;
    });
    document.getElementById('descriptionHidden').value = lines.join('\n');
}

// Mettre à jour les données de breakdown
function updateBreakdownData() {
    // Recalculer les totaux par étape
    const totauxParEtape = {};
    currentArticlesData.forEach(a => {
        const key = a.etape_nom || 'Non spécifié';
        if (!totauxParEtape[key]) {
            totauxParEtape[key] = { etape_id: a.etape_id, etape_nom: key, montant: 0 };
        }
        totauxParEtape[key].montant += parseFloat(a.total) || 0;
    });

    const breakdownData = {
        lignes: currentArticlesData,
        totaux_par_etape: Object.values(totauxParEtape)
    };

    document.getElementById('breakdownData').value = JSON.stringify(breakdownData);
}

// Ajouter un article au catalogue budget
// Logger dans la zone de debug item
function logItemSearch(message) {
    const logArea = document.getElementById('itemSearchLog');
    const timestamp = new Date().toLocaleTimeString();
    logArea.value += `[${timestamp}] ${message}\n`;
    logArea.scrollTop = logArea.scrollHeight;
}

async function addToBudget(idx) {
    const article = currentArticlesData[idx];
    if (!article) return;

    const logArea = document.getElementById('itemSearchLog');
    logArea.value = ''; // Clear previous logs

    logItemSearch('=== AJOUT AU CATALOGUE ===');
    logItemSearch(`Article: ${article.description}`);
    logItemSearch(`SKU: ${article.sku || 'N/A'}`);
    logItemSearch(`Prix: ${article.total}$`);
    logItemSearch(`Lien: ${article.link || 'Aucun'}`);

    const btn = document.querySelector(`#articlesTableBody tr:nth-child(${idx + 1}) .btn-outline-success`);
    if (btn) {
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
        btn.disabled = true;
    }

    const fournisseur = document.getElementById('fournisseur').value || article.fournisseur || '';
    logItemSearch(`Fournisseur: ${fournisseur}`);

    // Essayer de récupérer l'image du produit depuis le site fournisseur
    let productImageUrl = null;
    if (article.link) {
        logItemSearch('');
        logItemSearch('--- RECHERCHE IMAGE ---');
        logItemSearch(`Appel API link-preview: ${article.link}`);

        try {
            const previewResponse = await fetch('<?= url('/api/link-preview.php') ?>?url=' + encodeURIComponent(article.link));
            const previewData = await previewResponse.json();

            logItemSearch(`Réponse API: ${JSON.stringify(previewData, null, 2)}`);

            if (previewData.success && previewData.image) {
                productImageUrl = previewData.image;
                logItemSearch(`✓ Image trouvée: ${productImageUrl.substring(0, 100)}...`);
            } else {
                logItemSearch(`✗ Pas d'image trouvée`);
                if (previewData.error) {
                    logItemSearch(`Erreur: ${previewData.error}`);
                }
            }
        } catch (e) {
            logItemSearch(`✗ Erreur fetch: ${e.message}`);
        }
    } else {
        logItemSearch('Pas de lien produit - pas de recherche d\'image');
    }

    // Données à envoyer
    const data = {
        nom: article.description,
        prix: article.total || 0,
        fournisseur: fournisseur,
        etape_id: article.etape_id || null,
        sku: article.sku || article.code_produit || '',
        lien: article.link || '',
        image_url: productImageUrl,
        csrf_token: '<?= generateCSRFToken() ?>'
    };

    logItemSearch('');
    logItemSearch('--- ENVOI AU CATALOGUE ---');
    logItemSearch(`Données envoyées: ${JSON.stringify(data, null, 2)}`);

    // Envoyer à l'API
    fetch('<?= url('/api/budget-materiau-ajouter.php') ?>', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data)
    })
    .then(r => r.json())
    .then(result => {
        logItemSearch('');
        logItemSearch('--- RÉSULTAT ---');
        logItemSearch(`Réponse API: ${JSON.stringify(result, null, 2)}`);

        if (result.success) {
            logItemSearch(`✓ SUCCÈS - ID: ${result.id}`);
            // Marquer comme ajouté
            if (btn) {
                btn.classList.remove('btn-outline-success');
                btn.classList.add('btn-success');
                btn.innerHTML = '<i class="bi bi-check-lg"></i>';
            }
        } else {
            logItemSearch(`✗ ERREUR: ${result.error || 'Inconnue'}`);
            if (btn) {
                btn.classList.remove('btn-success');
                btn.classList.add('btn-outline-success');
                btn.innerHTML = '<i class="bi bi-plus-lg"></i>';
                btn.disabled = false;
            }
            alert('Erreur: ' + (result.error || 'Impossible d\'ajouter'));
        }
    })
    .catch(err => {
        logItemSearch(`✗ ERREUR CONNEXION: ${err.message}`);
        if (btn) {
            btn.classList.add('btn-outline-success');
            btn.innerHTML = '<i class="bi bi-plus-lg"></i>';
            btn.disabled = false;
        }
        alert('Erreur de connexion');
    });
}

// Remplir le formulaire avec les données détaillées
function fillFormWithDetailedData(data) {
    console.log('fillFormWithDetailedData - data reçue:', data);

    // Fournisseur (select dropdown)
    const fournisseurValue = data.fournisseur || data.Fournisseur || data.supplier || '';
    console.log('Fournisseur détecté:', fournisseurValue);

    if (fournisseurValue && fournisseurValue.trim() !== '') {
        const fournisseurSelect = document.getElementById('fournisseur');
        const fournisseurClean = fournisseurValue.trim();

        console.log('Select element:', fournisseurSelect);
        console.log('Options disponibles:', Array.from(fournisseurSelect.options).map(o => o.value));

        // Chercher si le fournisseur existe dans la liste
        let found = false;
        let bestMatch = null;
        let bestMatchScore = 0;

        for (let i = 0; i < fournisseurSelect.options.length; i++) {
            const option = fournisseurSelect.options[i];
            if (!option.value || option.value === '' || option.value === '__autre__') continue;

            const optVal = option.value.toLowerCase().trim();
            const optTxt = option.text.toLowerCase().trim();
            const searchVal = fournisseurClean.toLowerCase();

            // Score de correspondance
            let score = 0;
            if (optVal === searchVal || optTxt === searchVal) {
                score = 100; // Exact match
            } else if (optVal.includes(searchVal) || optTxt.includes(searchVal)) {
                score = 80; // Option contains search
            } else if (searchVal.includes(optVal) || searchVal.includes(optTxt)) {
                score = 60; // Search contains option (ex: "Home Depot Canada" contains "Home Depot")
            }

            if (score > bestMatchScore) {
                bestMatchScore = score;
                bestMatch = i;
                console.log('Meilleur match:', option.value, 'score:', score);
            }
        }

        if (bestMatch !== null) {
            console.log('Match final:', fournisseurSelect.options[bestMatch].value);
            fournisseurSelect.selectedIndex = bestMatch;
            found = true;
        }

        // Si non trouvé, demander à l'utilisateur
        if (!found) {
            console.log('Fournisseur non trouvé, demander:', fournisseurClean);
            showFournisseurMatchModal(fournisseurClean, fournisseurSelect);
        } else {
            // Forcer la mise à jour visuelle
            fournisseurSelect.dispatchEvent(new Event('change'));
        }
    } else {
        console.log('Pas de fournisseur dans les données');
    }

    // Date
    if (data.date_facture) {
        document.querySelector('input[name="date_facture"]').value = data.date_facture;
    }

    // Montants
    if (data.sous_total) {
        document.getElementById('montantAvantTaxes').value = parseFloat(data.sous_total).toFixed(2);
    }
    if (data.tps !== undefined) {
        document.getElementById('tps').value = parseFloat(data.tps).toFixed(2);
        taxesActives = false;
    }
    if (data.tvq !== undefined) {
        document.getElementById('tvq').value = parseFloat(data.tvq).toFixed(2);
    }

    calculerTotal();

    // Afficher les articles dans le tableau
    if (data.lignes && data.lignes.length > 0) {
        const fournisseur = data.fournisseur || '';
        currentArticlesData = data.lignes.map((ligne, idx) => ({
            ...ligne,
            fournisseur: fournisseur,
            link: generateProductLink(fournisseur, ligne.sku || ligne.code_produit || '')
        }));

        renderArticlesTable(currentArticlesData);
        updateDescriptionHidden();

        document.getElementById('articlesTableContainer').classList.remove('d-none');
        document.getElementById('noArticlesMsg').classList.add('d-none');
    }

    // Auto-sélectionner l'étape principale
    if (data.totaux_par_etape && data.totaux_par_etape.length > 0) {
        const etapeSelect = document.querySelector('select[name="etape_id"]');
        if (etapeSelect) {
            let etapePrincipale = data.totaux_par_etape.reduce((max, e) =>
                (e.montant > max.montant) ? e : max
            );

            if (etapePrincipale.etape_nom) {
                const nomRecherche = etapePrincipale.etape_nom.toLowerCase().trim();
                for (let option of etapeSelect.options) {
                    if (!option.value || option.value === '') continue;
                    const optionNom = option.text.toLowerCase().replace(/^\d+\.\s*/, '').trim();
                    if (optionNom === nomRecherche || optionNom.includes(nomRecherche) || nomRecherche.includes(optionNom)) {
                        etapeSelect.value = option.value;
                        break;
                    }
                }
            }
        }
    }

    highlightFilledFields();
}

// Afficher un résumé du breakdown
function showBreakdownSummary(data) {
    const existingAlert = document.getElementById('breakdownConfirm');
    if (existingAlert) existingAlert.remove();

    let etapesHtml = '';
    if (data.totaux_par_etape) {
        etapesHtml = data.totaux_par_etape.map(e =>
            `<span class="badge bg-secondary me-1">${e.etape_nom}: ${formatMoney(e.montant)}</span>`
        ).join('');
    }

    const alertHtml = `
        <div class="alert alert-success alert-dismissible fade show mt-3" id="breakdownConfirm">
            <i class="bi bi-check-circle me-2"></i>
            <strong>Analyse complète!</strong> ${data.lignes?.length || 0} articles répartis automatiquement:<br>
            <div class="mt-2">${etapesHtml}</div>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    `;

    const aiResultDiv = document.getElementById('aiResult');
    aiResultDiv.insertAdjacentHTML('afterend', alertHtml);
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
        document.querySelector('textarea[name="description"]').value = data.description;
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
    const fields = ['#fournisseur', 'input[name="date_facture"]', 'textarea[name="description"]',
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
    // Réinitialiser le breakdown
    currentBreakdownData = null;
    document.getElementById('breakdownData').value = '';
}

// =============================================
// BREAKDOWN PAR ÉTAPE (AUTOMATIQUE)
// =============================================

let currentBreakdownData = null;
let currentImageBase64 = null;

// Lancer automatiquement le breakdown après l'analyse rapide
async function analyserBreakdownAuto(base64Data) {
    currentImageBase64 = base64Data;

    const contentDiv = document.getElementById('detailsContent');
    const modal = new bootstrap.Modal(document.getElementById('detailsModal'));

    // Cacher le bouton save et reset data
    document.getElementById('btnSaveBreakdown').style.display = 'none';
    currentBreakdownData = null;

    // Afficher le modal avec loading
    contentDiv.innerHTML = `
        <div class="text-center py-5">
            <div class="spinner-border text-info" role="status"></div>
            <p class="mt-2 text-muted">Analyse détaillée en cours... (10-15 secondes)</p>
        </div>
    `;
    modal.show();

    try {
        const apiResponse = await fetch('<?= url('/api/analyse-facture-details.php') ?>', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({image: base64Data})
        });

        const data = await apiResponse.json();

        if (data.success && data.data) {
            displayDetailsResults(data.data);
        } else {
            contentDiv.innerHTML = `
                <div class="alert alert-warning">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    ${data.error || 'Impossible d\'analyser les détails. La facture sera créée sans répartition.'}
                </div>
            `;
        }
    } catch (err) {
        console.error('Erreur breakdown:', err);
        contentDiv.innerHTML = `
            <div class="alert alert-warning">
                <i class="bi bi-exclamation-triangle me-2"></i>
                Erreur: ${err.message}. La facture sera créée sans répartition.
            </div>
        `;
    }
}

// Afficher les résultats de l'analyse détaillée
function displayDetailsResults(data) {
    const contentDiv = document.getElementById('detailsContent');

    // Stocker les données pour sauvegarde
    currentBreakdownData = data;

    // Afficher le bouton de confirmation
    document.getElementById('btnSaveBreakdown').style.display = 'inline-block';

    let html = '';

    // Info facture
    html += `
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <strong>${data.fournisseur || 'Fournisseur'}</strong>
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

// Confirmer le breakdown et stocker dans le champ hidden
function confirmBreakdown() {
    if (!currentBreakdownData) {
        alert('Aucune donnée à sauvegarder');
        return;
    }

    // Stocker dans le champ hidden
    document.getElementById('breakdownData').value = JSON.stringify(currentBreakdownData);

    // Formater les articles en tableau pour la description
    // Format: Article | Qté | Prix | Étape
    if (currentBreakdownData.lignes && currentBreakdownData.lignes.length > 0) {
        let descriptionTable = "ARTICLES DÉTECTÉS PAR IA:\n";
        descriptionTable += "─".repeat(60) + "\n";

        currentBreakdownData.lignes.forEach((ligne, idx) => {
            const desc = (ligne.description || 'N/A').substring(0, 35).padEnd(35);
            const qty = String(ligne.quantite || 1).padStart(4);
            const prix = (ligne.total || 0).toFixed(2).padStart(8);
            const etape = (ligne.etape_nom || 'N/A').substring(0, 15);
            descriptionTable += `${desc} x${qty} ${prix}$ [${etape}]\n`;
        });

        descriptionTable += "─".repeat(60) + "\n";
        descriptionTable += `TOTAL: ${currentBreakdownData.lignes.length} articles | Sous-total: ${(currentBreakdownData.sous_total || 0).toFixed(2)}$`;

        // Remplir le champ description
        const descriptionField = document.querySelector('textarea[name="description"]');
        if (descriptionField) {
            descriptionField.value = descriptionTable;
            descriptionField.classList.add('border-success');
            setTimeout(() => descriptionField.classList.remove('border-success'), 3000);
        }
    }

    // Sélectionner automatiquement l'étape principale (celle avec le plus gros montant)
    const etapeSelect = document.querySelector('select[name="etape_id"]');
    if (etapeSelect && currentBreakdownData.totaux_par_etape && currentBreakdownData.totaux_par_etape.length > 0) {
        // Trouver l'étape avec le montant le plus élevé
        let etapePrincipale = currentBreakdownData.totaux_par_etape.reduce((max, e) =>
            (e.montant > max.montant) ? e : max
        );

        console.log('Étape principale:', etapePrincipale);
        let found = false;

        // Chercher par nom (plus fiable que par ID)
        if (etapePrincipale.etape_nom) {
            const nomRecherche = etapePrincipale.etape_nom.toLowerCase().trim();

            for (let option of etapeSelect.options) {
                if (!option.value || option.value === '') continue;

                // Extraire le nom sans le numéro (ex: "1. Démolition" -> "démolition")
                const optionNom = option.text.toLowerCase().replace(/^\d+\.\s*/, '').trim();

                console.log('Comparaison:', nomRecherche, 'vs', optionNom);

                // Correspondance exacte ou partielle
                if (optionNom === nomRecherche ||
                    optionNom.includes(nomRecherche) ||
                    nomRecherche.includes(optionNom)) {
                    etapeSelect.value = option.value;
                    found = true;
                    console.log('Trouvé! Option:', option.value, option.text);
                    break;
                }
            }
        }

        // Fallback: prendre la première option valide
        if (!found) {
            console.log('Pas trouvé par nom, fallback sur première option');
            for (let option of etapeSelect.options) {
                if (option.value && option.value !== '') {
                    etapeSelect.value = option.value;
                    found = true;
                    break;
                }
            }
        }

        if (found) {
            etapeSelect.classList.add('border-success');
            setTimeout(() => etapeSelect.classList.remove('border-success'), 3000);
        }
    }

    // Fermer le modal
    bootstrap.Modal.getInstance(document.getElementById('detailsModal')).hide();

    // Afficher confirmation visuelle avec détail des étapes
    let etapesDetail = '';
    if (currentBreakdownData.totaux_par_etape) {
        etapesDetail = currentBreakdownData.totaux_par_etape.map(e =>
            `<span class="badge bg-secondary me-1">${e.etape_nom}: ${formatMoney(e.montant)}</span>`
        ).join('');
    }

    const alertHtml = `
        <div class="alert alert-success alert-dismissible fade show mt-3" id="breakdownConfirm">
            <i class="bi bi-check-circle me-2"></i>
            <strong>Répartition prête!</strong> ${currentBreakdownData.lignes?.length || 0} articles répartis:<br>
            <div class="mt-2">${etapesDetail}</div>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    `;

    // Ajouter après le résultat AI
    const aiResultDiv = document.getElementById('aiResult');
    const existingAlert = document.getElementById('breakdownConfirm');
    if (existingAlert) existingAlert.remove();
    aiResultDiv.insertAdjacentHTML('afterend', alertHtml);
}

// =============================================
// MODAL MATCHING FOURNISSEUR
// =============================================

let pendingFournisseurSelect = null;
let pendingFournisseurValue = null;

function showFournisseurMatchModal(detectedName, selectElement) {
    pendingFournisseurSelect = selectElement;
    pendingFournisseurValue = detectedName;

    // Remplir le nom détecté
    document.getElementById('detectedFournisseurName').textContent = detectedName;
    document.getElementById('newFournisseurName').value = detectedName;

    // Générer la liste des fournisseurs existants
    const listDiv = document.getElementById('existingFournisseursList');
    let html = '';

    for (let option of selectElement.options) {
        if (!option.value || option.value === '' || option.value === '__autre__') continue;
        html += `
            <button type="button" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center"
                    onclick="selectExistingFournisseur('${option.value.replace(/'/g, "\\'")}')">
                <span>${option.text}</span>
                <i class="bi bi-chevron-right text-muted"></i>
            </button>
        `;
    }

    listDiv.innerHTML = html || '<p class="text-muted p-3">Aucun fournisseur existant</p>';

    // Ouvrir le modal
    new bootstrap.Modal(document.getElementById('fournisseurMatchModal')).show();
}

function selectExistingFournisseur(value) {
    if (pendingFournisseurSelect) {
        pendingFournisseurSelect.value = value;
        pendingFournisseurSelect.dispatchEvent(new Event('change'));
        pendingFournisseurSelect.classList.add('border-success');
        setTimeout(() => pendingFournisseurSelect.classList.remove('border-success'), 3000);
    }
    bootstrap.Modal.getInstance(document.getElementById('fournisseurMatchModal')).hide();
}

function addNewFournisseur() {
    const newName = document.getElementById('newFournisseurName').value.trim();
    if (!newName) {
        alert('Veuillez entrer un nom');
        return;
    }

    if (pendingFournisseurSelect) {
        // Ajouter au dropdown
        const newOption = document.createElement('option');
        newOption.value = newName;
        newOption.text = newName;
        newOption.selected = true;

        const autreOption = pendingFournisseurSelect.querySelector('option[value="__autre__"]');
        if (autreOption) {
            pendingFournisseurSelect.insertBefore(newOption, autreOption);
        } else {
            pendingFournisseurSelect.appendChild(newOption);
        }
        pendingFournisseurSelect.value = newName;
        pendingFournisseurSelect.dispatchEvent(new Event('change'));
        pendingFournisseurSelect.classList.add('border-success');
        setTimeout(() => pendingFournisseurSelect.classList.remove('border-success'), 3000);

        // Sauvegarder en base de données
        fetch('<?= url('/api/fournisseur-ajouter.php') ?>', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'nom=' + encodeURIComponent(newName) + '&csrf_token=<?= generateCSRFToken() ?>'
        }).then(r => r.json()).then(result => {
            console.log('Fournisseur sauvegardé:', result);
        }).catch(err => console.log('Erreur sauvegarde:', err));
    }

    bootstrap.Modal.getInstance(document.getElementById('fournisseurMatchModal')).hide();
}
</script>

<!-- Modal Breakdown par Étape -->
<div class="modal fade" id="detailsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title"><i class="bi bi-list-check me-2"></i>Répartition par étape</h5>
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
                <button type="button" class="btn btn-success" id="btnSaveBreakdown" onclick="confirmBreakdown()" style="display:none">
                    <i class="bi bi-check-circle me-1"></i>Utiliser cette répartition
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal Matching Fournisseur IA -->
<div class="modal fade" id="fournisseurMatchModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-warning">
                <h5 class="modal-title"><i class="bi bi-question-circle me-2"></i>Fournisseur non reconnu</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info mb-3">
                    <i class="bi bi-robot me-2"></i>
                    L'IA a détecté: <strong id="detectedFournisseurName"></strong>
                </div>

                <h6 class="mb-2">Choisir un fournisseur existant:</h6>
                <div class="list-group mb-3" id="existingFournisseursList" style="max-height: 200px; overflow-y: auto;">
                    <!-- Rempli dynamiquement -->
                </div>

                <hr>

                <h6 class="mb-2">Ou ajouter comme nouveau:</h6>
                <div class="input-group">
                    <input type="text" class="form-control" id="newFournisseurName" placeholder="Nom du fournisseur">
                    <button class="btn btn-success" type="button" onclick="addNewFournisseur()">
                        <i class="bi bi-plus-circle me-1"></i>Ajouter
                    </button>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Ignorer</button>
            </div>
        </div>
    </div>
</div>

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
