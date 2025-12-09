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

<!-- Librairie PDF-Lib pour le découpage côté client -->
<script src="https://unpkg.com/pdf-lib@1.17.1/dist/pdf-lib.min.js"></script>

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
            <form id="formAnalyseJS" onsubmit="handleAnalysis(event)">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-magic me-2"></i>Nouvelle Analyse IA</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        Le système découpera automatiquement votre PDF s'il est trop volumineux pour l'analyser par lots.
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
                        <div class="form-text">Même les fichiers de plus de 100 pages sont acceptés (découpage auto).</div>
                    </div>

                    <!-- Barre de progression -->
                    <div id="progress-container" class="d-none mt-3">
                        <label class="form-label mb-1" id="progress-text">Analyse en cours...</label>
                        <div class="progress" style="height: 25px;">
                            <div id="progress-bar" class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width: 0%">0%</div>
                        </div>
                        <small class="text-muted d-block mt-1">Ne fermez pas cette fenêtre.</small>
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
        
        // Charger le PDF
        progressText.textContent = "Lecture du fichier PDF...";
        const pdfDoc = await PDFLib.PDFDocument.load(arrayBuffer);
        const pageCount = pdfDoc.getPageCount();
        
        // Initialiser l'analyse côté serveur
        progressText.textContent = "Initialisation de l'analyse...";
        const formDataInit = new FormData();
        formDataInit.append('action', 'init');
        formDataInit.append('projet_id', projetId);
        formDataInit.append('nom_rapport', nomRapport);
        
        const resInit = await fetch('traitement_analyse.php', { method: 'POST', body: formDataInit });
        const dataInit = await resInit.json();
        
        if (!dataInit.success) throw new Error(dataInit.error || "Erreur init");
        const analyseId = dataInit.id;
        
        // Découper et envoyer par lots de 50 pages
        const CHUNK_SIZE = 50;
        const totalChunks = Math.ceil(pageCount / CHUNK_SIZE);
        
        for (let i = 0; i < pageCount; i += CHUNK_SIZE) {
            const chunkIndex = Math.floor(i / CHUNK_SIZE) + 1;
            progressText.textContent = `Analyse du lot ${chunkIndex} / ${totalChunks} (IA en cours)...`;
            progressBar.style.width = `${((chunkIndex-1) / totalChunks) * 100}%`;
            progressBar.textContent = `${Math.round(((chunkIndex-1) / totalChunks) * 100)}%`;
            
            // Créer un nouveau PDF avec ce lot de pages
            const subPdf = await PDFLib.PDFDocument.create();
            const pagesIndices = [];
            for (let j = 0; j < CHUNK_SIZE && (i + j) < pageCount; j++) {
                pagesIndices.push(i + j);
            }
            
            const copiedPages = await subPdf.copyPages(pdfDoc, pagesIndices);
            copiedPages.forEach(page => subPdf.addPage(page));
            const pdfBytes = await subPdf.save();
            
            // Envoyer le chunk
            const blob = new Blob([pdfBytes], { type: 'application/pdf' });
            const formDataChunk = new FormData();
            formDataChunk.append('action', 'process_chunk');
            formDataChunk.append('analyse_id', analyseId);
            formDataChunk.append('pdf_chunk', blob, `chunk_${chunkIndex}.pdf`);
            
            const resChunk = await fetch('traitement_analyse.php', { method: 'POST', body: formDataChunk });
            const dataChunk = await resChunk.json();
            
            if (!dataChunk.success) throw new Error(dataChunk.error || `Erreur lot ${chunkIndex}`);
        }
        
        // Finaliser
        progressText.textContent = "Finalisation et calculs...";
        progressBar.style.width = "100%";
        progressBar.textContent = "100%";
        
        const formDataFinish = new FormData();
        formDataFinish.append('action', 'finish');
        formDataFinish.append('analyse_id', analyseId);
        
        await fetch('traitement_analyse.php', { method: 'POST', body: formDataFinish });
        
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
