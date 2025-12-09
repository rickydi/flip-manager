<?php
/**
 * Module Comparables & Analyse de Marché (IA)
 * Flip Manager
 */

require_once '../../config.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../includes/ClaudeService.php';

requireAdmin();

$pageTitle = 'Comparables & Analyse IA';
$claudeService = new ClaudeService($pdo);

// Auto-migration des tables analyses si elles n'existent pas
try {
    $pdo->query("SELECT 1 FROM analyses_marche LIMIT 1");
} catch (Exception $e) {
    // Exécuter le SQL de migration
    $sqlMigration = file_get_contents('../../sql/migration_comparables_ai.sql');
    // Nettoyer les commentaires et exécuter
    $queries = explode(';', $sqlMigration);
    foreach ($queries as $query) {
        $query = trim($query);
        if (!empty($query)) {
            try { $pdo->exec($query); } catch (Exception $ex) {}
        }
    }
}

$errors = [];
$success = '';

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
                    // Récupérer les infos du projet sujet
                    $projet = getProjetById($pdo, $projetId);
                    $projetInfo = [
                        'adresse' => $projet['adresse'] . ', ' . $projet['ville'],
                        'type' => 'Maison unifamiliale', // À raffiner si dispo
                        'chambres' => 'N/A', // Idéalement ajouter ces champs dans la table projets
                        'sdb' => 'N/A',
                        'superficie' => 'N/A',
                        'garage' => 'N/A'
                    ];

                    // Appel à l'IA
                    $resultats = $claudeService->analyserComparables($filePath, $projetInfo);

                    // Sauvegarder l'analyse
                    $stmt = $pdo->prepare("
                        INSERT INTO analyses_marche (projet_id, nom_rapport, fichier_source, statut, prix_suggere_ia, fourchette_basse, fourchette_haute, analyse_ia_texte)
                        VALUES (?, ?, ?, 'termine', ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $projetId,
                        $nomRapport,
                        $filePath,
                        $resultats['analyse_globale']['prix_suggere'] ?? 0,
                        $resultats['analyse_globale']['fourchette_basse'] ?? 0,
                        $resultats['analyse_globale']['fourchette_haute'] ?? 0,
                        $resultats['analyse_globale']['commentaire_general'] ?? ''
                    ]);
                    $analyseId = $pdo->lastInsertId();

                    // Sauvegarder les items
                    $stmtItem = $pdo->prepare("
                        INSERT INTO comparables_items (analyse_id, adresse, prix_vendu, date_vente, chambres, salles_bains, superficie_batiment, annee_construction, etat_general_note, etat_general_texte, renovations_mentionnees, ajustement_ia, commentaire_ia)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");

                    foreach ($resultats['comparables'] as $comp) {
                        $stmtItem->execute([
                            $analyseId,
                            $comp['adresse'] ?? '',
                            $comp['prix_vendu'] ?? 0,
                            $comp['date_vente'] ?? null,
                            $comp['chambres'] ?? '',
                            $comp['sdb'] ?? '',
                            $comp['superficie'] ?? '',
                            $comp['annee'] ?? null,
                            $comp['etat_note'] ?? 0,
                            $comp['etat_texte'] ?? '',
                            $comp['renovations'] ?? '',
                            $comp['ajustement'] ?? 0,
                            $comp['commentaire'] ?? ''
                        ]);
                    }

                    redirect('/admin/comparables/detail.php?id=' . $analyseId);

                } else {
                    $errors[] = 'Erreur lors du téléchargement du fichier.';
                }
            } catch (Exception $e) {
                $errors[] = 'Erreur lors de l\'analyse : ' . $e->getMessage();
            }
        }
    }
}

// Récupérer les analyses existantes
$stmt = $pdo->query("
    SELECT a.*, p.nom as projet_nom 
    FROM analyses_marche a 
    LEFT JOIN projets p ON a.projet_id = p.id 
    ORDER BY a.date_analyse DESC
");
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
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalNouveau">
            <i class="bi bi-plus-lg me-1"></i>Nouvelle Analyse
        </button>
    </div>

    <?php displayFlashMessage(); ?>

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
                            <th>Prix Suggéré (IA)</th>
                            <th>Statut</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($analyses)): ?>
                            <tr><td colspan="6" class="text-center py-4 text-muted">Aucune analyse effectuée.</td></tr>
                        <?php else: ?>
                            <?php foreach ($analyses as $analyse): ?>
                                <tr>
                                    <td><?= formatDateTime($analyse['date_analyse']) ?></td>
                                    <td>
                                        <a href="detail.php?id=<?= $analyse['id'] ?>" class="fw-bold text-decoration-none">
                                            <?= e($analyse['nom_rapport']) ?>
                                        </a>
                                    </td>
                                    <td><?= e($analyse['projet_nom'] ?? 'Aucun') ?></td>
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
                                            <span class="badge bg-danger">Erreur</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning text-dark">En cours</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <a href="detail.php?id=<?= $analyse['id'] ?>" class="btn btn-sm btn-outline-primary">
                                            <i class="bi bi-eye"></i> Voir
                                        </a>
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
                        L'IA va lire votre PDF Centris, extraire les données, analyser les photos pour juger de l'état des rénovations, et estimer la valeur de votre projet.
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Projet sujet (le vôtre)</label>
                        <select class="form-select" name="projet_id" required>
                            <?php foreach ($projets as $p): ?>
                                <option value="<?= $p['id'] ?>"><?= e($p['nom']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Nom du rapport</label>
                        <input type="text" class="form-control" name="nom_rapport" placeholder="Ex: Comparables Rue Barbeau - Octobre 2025" required>
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
                        <span id="btnText">Lancer l'analyse</span>
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
    txt.textContent = 'Analyse en cours par l\'IA... (Patientez)';
    spin.classList.remove('d-none');
});
</script>

<?php include '../../includes/footer.php'; ?>
