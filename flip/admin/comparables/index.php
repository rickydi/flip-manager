<?php
/**
 * Module Comparables & Analyse de Marché (IA)
 * Version 2 - Workflow par chunks avec extraction PDF
 * Flip Manager
 */

require_once '../../config.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../includes/AIServiceFactory.php';
require_once '../../includes/PdfExtractorService.php';

requireAdmin();

$pageTitle = 'Comparables & Analyse IA';

// Auto-migration des tables si elles n'existent pas
$tableExists = false;
$checkTable = $pdo->query("SHOW TABLES LIKE 'comparables_chunks'");
$tableExists = $checkTable->rowCount() > 0;

if (!$tableExists) {
    // Exécuter le SQL de migration V2
    $sqlFile = dirname(dirname(__DIR__)) . '/sql/migration_comparables_v2.sql';
    if (file_exists($sqlFile)) {
        $sqlMigration = file_get_contents($sqlFile);
        $queries = explode(';', $sqlMigration);
        foreach ($queries as $query) {
            $query = trim($query);
            if (!empty($query) && substr(trim($query), 0, 2) !== '--') {
                try { $pdo->exec($query); } catch (PDOException $ex) {}
            }
        }
    }
    // Ajouter colonnes à analyses_marche (ignore si existent déjà)
    $cols = ['total_chunks INT DEFAULT 0', 'processed_chunks INT DEFAULT 0', 'photos_analyzed INT DEFAULT 0', 'extraction_path VARCHAR(255)', 'error_log TEXT'];
    foreach ($cols as $col) {
        try { $pdo->exec("ALTER TABLE analyses_marche ADD COLUMN $col"); } catch (PDOException $ex) {}
    }
    // Modifier l'ENUM statut pour ajouter 'extraction'
    try {
        $pdo->exec("ALTER TABLE analyses_marche MODIFY COLUMN statut ENUM('en_cours', 'termine', 'erreur', 'extraction') DEFAULT 'en_cours'");
    } catch (PDOException $ex) {}
}

// S'assurer que l'ENUM statut inclut 'extraction' (migration existante)
try {
    $pdo->exec("ALTER TABLE analyses_marche MODIFY COLUMN statut ENUM('en_cours', 'termine', 'erreur', 'extraction') DEFAULT 'en_cours'");
} catch (PDOException $ex) {}

// Services
$aiService = AIServiceFactory::create($pdo);
$pdfService = new PdfExtractorService($pdo, $aiService);

// Vérifier les dépendances système
$dependencies = PdfExtractorService::checkDependencies();

$errors = [];
$success = '';

// Traitement de la suppression
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token invalide.';
    } else {
        $deleteId = (int)($_POST['analyse_id'] ?? 0);
        if ($deleteId > 0) {
            try {
                // Récupérer le fichier source pour le supprimer aussi
                $stmt = $pdo->prepare("SELECT fichier_source FROM analyses_marche WHERE id = ?");
                $stmt->execute([$deleteId]);
                $analyse = $stmt->fetch();

                // Supprimer les chunks associés
                $pdo->prepare("DELETE FROM comparables_chunks WHERE analyse_id = ?")->execute([$deleteId]);

                // Supprimer les photos associées
                $pdo->prepare("DELETE FROM comparables_photos WHERE chunk_id IN (SELECT id FROM comparables_chunks WHERE analyse_id = ?)")->execute([$deleteId]);

                // Supprimer l'analyse
                $pdo->prepare("DELETE FROM analyses_marche WHERE id = ?")->execute([$deleteId]);

                // Supprimer le fichier PDF si existe
                if ($analyse && !empty($analyse['fichier_source']) && file_exists($analyse['fichier_source'])) {
                    @unlink($analyse['fichier_source']);
                }

                setFlashMessage('Rapport supprimé avec succès.', 'success');
                redirect('/admin/comparables/index.php');
            } catch (Exception $e) {
                $errors[] = 'Erreur lors de la suppression: ' . $e->getMessage();
            }
        }
    }
}

// Traitement du formulaire de nouvelle analyse
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'analyser') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token invalide.';
    } else {
        $projetId = (int)$_POST['projet_id'];
        $nomRapport = trim($_POST['nom_rapport']);

        if (empty($nomRapport)) $errors[] = 'Le nom du rapport est requis.';
        if (!isset($_FILES['fichier_pdf']) || $_FILES['fichier_pdf']['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Veuillez sélectionner un fichier PDF valide.';
        }

        if (empty($errors)) {
            try {
                // Upload du fichier
                $uploadDir = '../../uploads/comparables/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

                $fileName = time() . '_' . preg_replace('/[^a-zA-Z0-9\._-]/', '', $_FILES['fichier_pdf']['name']);
                $filePath = $uploadDir . $fileName;

                if (move_uploaded_file($_FILES['fichier_pdf']['tmp_name'], $filePath)) {
                    // Créer l'analyse avec statut "extraction"
                    $stmt = $pdo->prepare("
                        INSERT INTO analyses_marche (projet_id, nom_rapport, fichier_source, statut)
                        VALUES (?, ?, ?, 'extraction')
                    ");
                    $stmt->execute([$projetId, $nomRapport, $filePath]);
                    $analyseId = $pdo->lastInsertId();

                    // Extraire et organiser le PDF
                    $result = $pdfService->extractAndOrganize($filePath, $analyseId);

                    if (!$result['success']) {
                        // Mettre à jour le statut en erreur
                        $pdo->prepare("UPDATE analyses_marche SET statut = 'erreur', error_log = ? WHERE id = ?")
                            ->execute([$result['error'], $analyseId]);

                        // Debug: sauvegarder le texte extrait pour analyse
                        if (isset($result['debug_text'])) {
                            $debugFile = $uploadDir . 'debug_' . $analyseId . '.txt';
                            file_put_contents($debugFile, $result['debug_text']);
                            $errors[] = $result['error'] . ' <a href="' . $debugFile . '" target="_blank">Voir le texte extrait</a>';
                        } else {
                            $errors[] = $result['error'];
                        }
                    } else {
                        // Sauvegarder les chunks en DB
                        $pdfService->saveChunksToDb($analyseId, $result['chunks'], $result['path']);

                        // Mettre à jour le statut
                        $pdo->prepare("UPDATE analyses_marche SET statut = 'en_cours' WHERE id = ?")
                            ->execute([$analyseId]);

                        // Rediriger vers la page de détail pour l'analyse IA
                        redirect('/admin/comparables/detail.php?id=' . $analyseId);
                    }
                } else {
                    $errors[] = 'Erreur lors du téléchargement du fichier.';
                }
            } catch (Exception $e) {
                $errors[] = 'Erreur : ' . $e->getMessage();
            }
        }
    }
}

// Récupérer les analyses existantes
$chunksTableCheck = $pdo->query("SHOW TABLES LIKE 'comparables_chunks'")->rowCount() > 0;
if ($chunksTableCheck) {
    $stmt = $pdo->query("
        SELECT a.*, p.nom as projet_nom,
               (SELECT COUNT(*) FROM comparables_chunks WHERE analyse_id = a.id) as nb_chunks,
               (SELECT COUNT(*) FROM comparables_chunks WHERE analyse_id = a.id AND statut = 'done') as chunks_done
        FROM analyses_marche a
        LEFT JOIN projets p ON a.projet_id = p.id
        ORDER BY a.date_analyse DESC
    ");
} else {
    $stmt = $pdo->query("
        SELECT a.*, p.nom as projet_nom, 0 as nb_chunks, 0 as chunks_done
        FROM analyses_marche a
        LEFT JOIN projets p ON a.projet_id = p.id
        ORDER BY a.date_analyse DESC
    ");
}
$analyses = $stmt->fetchAll();

// Récupérer les projets pour le formulaire
$projets = getProjets($pdo);

include '../../includes/header.php';
?>

<div class="container-fluid">
    <div class="page-header d-flex justify-content-between align-items-center">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="<?= url('/admin/index.php') ?>">Tableau de bord</a></li>
                    <li class="breadcrumb-item active">Comparables & IA</li>
                </ol>
            </nav>
            <h1><i class="bi bi-robot me-2"></i>Analyse de Marché (IA)</h1>
        </div>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalNouveau" <?= !$dependencies['ready'] ? 'disabled' : '' ?>>
            <i class="bi bi-plus-lg me-1"></i>Nouvelle Analyse
        </button>
    </div>

    <?php displayFlashMessage(); ?>

    <?php if (!$dependencies['ready']): ?>
        <div class="alert alert-danger">
            <i class="bi bi-exclamation-triangle me-2"></i>
            <strong>Configuration requise:</strong> Aucun outil d'extraction PDF n'est disponible.
            <div class="mt-2">
                Installer la librairie PHP: <code>cd flip && composer require smalot/pdfparser</code>
            </div>
        </div>
    <?php elseif ($dependencies['php_parser'] && !$dependencies['pdftotext']): ?>
        <div class="alert alert-info">
            <i class="bi bi-info-circle me-2"></i>
            <strong>Mode PHP Pure activé</strong> - L'extraction utilise la librairie smalot/pdfparser.
            <?php if (!$dependencies['pdfimages']): ?>
            <br><small class="text-muted">Note: Les images ne seront pas extraites (pdfimages non disponible). L'analyse se fera sur le texte uniquement.</small>
            <?php endif; ?>
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

    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Date</th>
                            <th>Rapport</th>
                            <th>Projet associé</th>
                            <th>Progression</th>
                            <th>Prix Suggéré (IA)</th>
                            <th>Statut</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($analyses)): ?>
                            <tr><td colspan="7" class="text-center py-4 text-muted">Aucune analyse effectuée.</td></tr>
                        <?php else: ?>
                            <?php foreach ($analyses as $analyse): ?>
                                <?php
                                $progress = 0;
                                if ($analyse['nb_chunks'] > 0) {
                                    $progress = round(($analyse['chunks_done'] / $analyse['nb_chunks']) * 100);
                                }
                                ?>
                                <tr>
                                    <td><?= formatDateTime($analyse['date_analyse']) ?></td>
                                    <td>
                                        <a href="detail.php?id=<?= $analyse['id'] ?>" class="fw-bold text-decoration-none">
                                            <?= e($analyse['nom_rapport']) ?>
                                        </a>
                                    </td>
                                    <td><?= e($analyse['projet_nom'] ?? 'Aucun') ?></td>
                                    <td style="min-width: 120px;">
                                        <?php if ($analyse['nb_chunks'] > 0): ?>
                                            <div class="progress" style="height: 20px;">
                                                <div class="progress-bar <?= $progress === 100 ? 'bg-success' : 'bg-primary' ?>"
                                                     style="width: <?= $progress ?>%">
                                                    <?= $analyse['chunks_done'] ?>/<?= $analyse['nb_chunks'] ?>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($analyse['prix_suggere_ia'] > 0): ?>
                                            <span class="badge bg-success fs-6"><?= formatMoney($analyse['prix_suggere_ia']) ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($analyse['statut'] === 'termine'): ?>
                                            <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Terminé</span>
                                        <?php elseif ($analyse['statut'] === 'erreur'): ?>
                                            <span class="badge bg-danger" title="<?= e($analyse['error_log'] ?? '') ?>">Erreur</span>
                                        <?php elseif ($analyse['statut'] === 'extraction'): ?>
                                            <span class="badge bg-info"><i class="bi bi-file-earmark-text me-1"></i>Extraction</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning text-dark"><i class="bi bi-hourglass-split me-1"></i>En cours</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <a href="detail.php?id=<?= $analyse['id'] ?>" class="btn btn-sm btn-outline-primary">
                                            <i class="bi bi-eye"></i> Voir
                                        </a>
                                        <form method="POST" action="" style="display: inline;">
                                            <?php csrfField(); ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="analyse_id" value="<?= $analyse['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger ms-1">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Modal Nouvelle Analyse -->
<div class="modal fade" id="modalNouveau" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="" enctype="multipart/form-data" id="formAnalyse">
                <?php csrfField(); ?>
                <input type="hidden" name="action" value="analyser">

                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-magic me-2"></i>Nouvelle Analyse IA</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        <strong>Comment ça fonctionne:</strong>
                        <ol class="mb-0 mt-2">
                            <li>Le PDF est analysé et découpé par propriété (No Centris)</li>
                            <li>Les photos sont extraites et organisées par propriété</li>
                            <li>L'IA analyse chaque comparable (texte + photos)</li>
                            <li>Un prix de vente suggéré est calculé avec ajustements</li>
                        </ol>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Projet sujet (le vôtre)</label>
                        <select class="form-select" name="projet_id" required>
                            <?php foreach ($projets as $p): ?>
                                <option value="<?= $p['id'] ?>"><?= e($p['nom']) ?> - <?= e($p['adresse']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Nom du rapport</label>
                        <input type="text" class="form-control" name="nom_rapport" placeholder="Ex: Comparables Rue Barbeau - Janvier 2026" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Fichier PDF Centris</label>
                        <input type="file" class="form-control" name="fichier_pdf" accept="application/pdf" required>
                        <div class="form-text">Téléchargez le rapport PDF généré par Centris/Matrix contenant les comparables vendus.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-primary" id="btnAnalyser">
                        <span id="btnText">Lancer l'extraction</span>
                        <span id="btnSpinner" class="spinner-border spinner-border-sm d-none"></span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.getElementById('formAnalyse').addEventListener('submit', function() {
    var btn = document.getElementById('btnAnalyser');
    var txt = document.getElementById('btnText');
    var spin = document.getElementById('btnSpinner');

    btn.disabled = true;
    txt.textContent = 'Extraction en cours...';
    spin.classList.remove('d-none');
});
</script>

<?php include '../../includes/footer.php'; ?>
