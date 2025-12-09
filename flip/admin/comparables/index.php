<?php
/**
 * Module Comparables & Analyse de Marché (IA)
 * Flip Manager
 */

require_once '../../config.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

requireAdmin();

$pageTitle = 'Comparables & Analyse IA';

// Auto-migration des tables analyses si elles n'existent pas
try {
    $pdo->query("SELECT 1 FROM analyses_marche LIMIT 1");
} catch (Exception $e) {
    // Exécuter le SQL de migration
    $sqlMigration = file_get_contents('../../sql/migration_comparables_ai.sql');
    $queries = explode(';', $sqlMigration);
    foreach ($queries as $query) {
        $query = trim($query);
        if (!empty($query)) {
            try { $pdo->exec($query); } catch (Exception $ex) {}
        }
    }
}

// Traitement suppression
if (isset($_POST['action']) && $_POST['action'] === 'supprimer') {
    if (verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $id = (int)$_POST['id'];
        $pdo->prepare("DELETE FROM analyses_marche WHERE id = ?")->execute([$id]);
        setFlashMessage('success', 'Analyse supprimée.');
        redirect('index.php');
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

<!-- Librairie PDF.js pour extraction de texte -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.16.105/pdf.min.js"></script>
<script>pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.16.105/pdf.worker.min.js';</script>

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
                                        <a href="detail.php?id=<?= $analyse['id'] ?>" class="btn btn-sm btn-outline-primary me-1">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Supprimer cette analyse ?');">
                                            <?php csrfField(); ?>
                                            <input type="hidden" name="action" value="supprimer">
                                            <input type="hidden" name="id" value="<?= $analyse['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">
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
            <form id="formAnalyseJS" onsubmit="handleAnalysis(event)">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-magic me-2"></i>Nouvelle Analyse IA</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        Le système va extraire le texte de votre PDF (rapide et illimité en pages) et l'envoyer à l'IA pour analyse comparative.
                    </div>

                    <div id="error-msg" class="alert alert-danger d-none"></div>

                    <div class="mb-3">
                        <label class="form-label">Projet sujet (le vôtre)</label>
                        <select class="form-select" name="projet_id" id="projet_id" required>
                            <?php foreach ($projets as $p): ?>
                                <option value="<?= $p['id'] ?>"><?= e($p['nom']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Nom du rapport</label>
                        <input type="text" class="form-control" name="nom_rapport" id="nom_rapport" placeholder="Ex: Comparables Rue Barbeau" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Fichier PDF Centris</label>
                        <input type="file" class="form-control" name="fichier_pdf" id="fichier_pdf" accept="application/pdf" required>
                    </div>

                    <!-- Barre de progression -->
                    <div id="progress-container" class="d-none mt-3">
                        <label class="form-label mb-1" id="progress-text">Démarrage...</label>
                        <div class="progress" style="height: 25px;">
                            <div id="progress-bar" class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width: 0%">0%</div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" id="btnAnnuler">Annuler</button>
                    <button type="submit" class="btn btn-primary" id="btnAnalyser">
                        <i class="bi bi-play-fill me-1"></i>Lancer l'analyse
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
async function handleAnalysis(e) {
    e.preventDefault();
    
    const fileInput = document.getElementById('fichier_pdf');
    const projetId = document.getElementById('projet_id').value;
    const nomRapport = document.getElementById('nom_rapport').value;
    const btn = document.getElementById('btnAnalyser');
    const progressContainer = document.getElementById('progress-container');
    const progressBar = document.getElementById('progress-bar');
    const progressText = document.getElementById('progress-text');
    const errorMsg = document.getElementById('error-msg');

    if (fileInput.files.length === 0) return;
    
    // UI Init
    btn.disabled = true;
    document.getElementById('btnAnnuler').disabled = true;
    progressContainer.classList.remove('d-none');
    errorMsg.classList.add('d-none');
    
    try {
        const file = fileInput.files[0];
        const arrayBuffer = await file.arrayBuffer();
        
        // 1. Extraction du texte côté client (Browser)
        progressText.textContent = "Extraction du texte du PDF...";
        progressBar.style.width = "10%";
        
        const pdf = await pdfjsLib.getDocument(arrayBuffer).promise;
        let fullText = "";
        
        for (let i = 1; i <= pdf.numPages; i++) {
            const page = await pdf.getPage(i);
            const textContent = await page.getTextContent();
            const pageText = textContent.items.map(item => item.str).join(" ");
            fullText += `--- PAGE ${i} ---\n${pageText}\n\n`;
            
            // Mise à jour progression extraction
            const percent = 10 + Math.round((i / pdf.numPages) * 20); // 10% à 30%
            progressBar.style.width = `${percent}%`;
            progressText.textContent = `Lecture page ${i}/${pdf.numPages}...`;
        }
        
        console.log("Texte extrait (taille):", fullText.length);
        
        // 2. Initialisation Analyse DB
        progressText.textContent = "Envoi des données au serveur...";
        const formDataInit = new FormData();
        formDataInit.append('action', 'init');
        formDataInit.append('projet_id', projetId);
        formDataInit.append('nom_rapport', nomRapport);
        
        const resInit = await fetch('traitement_analyse.php', { method: 'POST', body: formDataInit });
        const dataInit = await resInit.json();
        
        if (!dataInit.success) throw new Error(dataInit.error || "Erreur init");
        const analyseId = dataInit.id;
        
        // 3. Envoi du texte à l'IA (via PHP)
        progressBar.style.width = "40%";
        progressText.innerHTML = "Analyse IA en cours...<br><small class='text-muted'>Comparaison des données textuelles. Veuillez patienter.</small>";
        
        const formDataProcess = new FormData();
        formDataProcess.append('action', 'process_text');
        formDataProcess.append('analyse_id', analyseId);
        formDataProcess.append('full_text', fullText);
        
        const resProcess = await fetch('traitement_analyse.php', { method: 'POST', body: formDataProcess });
        const dataProcess = await resProcess.json();
        
        if (!dataProcess.success) throw new Error(dataProcess.error || "Erreur analyse");
        
        // 4. Finalisation
        progressBar.style.width = "100%";
        progressText.textContent = "Terminé ! Redirection...";
        
        // Redirection
        window.location.href = `detail.php?id=${analyseId}`;
        
    } catch (error) {
        console.error(error);
        errorMsg.textContent = "Erreur : " + error.message;
        errorMsg.classList.remove('d-none');
        btn.disabled = false;
        document.getElementById('btnAnnuler').disabled = false;
        progressContainer.classList.add('d-none');
    }
}
</script>

<?php include '../../includes/footer.php'; ?>
