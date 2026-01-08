<?php
/**
 * Détail d'une analyse de marché - Comparables IA
 * Version 2 - Workflow par chunks avec galerie photos
 * Flip Manager
 */

require_once '../../config.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../includes/ClaudeService.php';

requireAdmin();

$analyseId = (int)($_GET['id'] ?? 0);
if (!$analyseId) {
    setFlashMessage('Analyse introuvable.', 'danger');
    redirect('/admin/comparables/index.php');
}

// Récupérer l'analyse
$stmt = $pdo->prepare("
    SELECT a.*, p.nom as projet_nom, p.adresse as projet_adresse, p.ville as projet_ville
    FROM analyses_marche a
    LEFT JOIN projets p ON a.projet_id = p.id
    WHERE a.id = ?
");
$stmt->execute([$analyseId]);
$analyse = $stmt->fetch();

if (!$analyse) {
    setFlashMessage('Analyse introuvable.', 'danger');
    redirect('/admin/comparables/index.php');
}

// Récupérer les chunks
$stmtChunks = $pdo->prepare("
    SELECT c.*, (SELECT COUNT(*) FROM comparables_photos WHERE chunk_id = c.id) as nb_photos
    FROM comparables_chunks c
    WHERE c.analyse_id = ?
    ORDER BY c.id
");
$stmtChunks->execute([$analyseId]);
$chunks = $stmtChunks->fetchAll();

// Compter les chunks par statut
$chunksPending = 0;
$chunksDone = 0;
foreach ($chunks as $chunk) {
    if ($chunk['statut'] === 'done') $chunksDone++;
    else $chunksPending++;
}

$pageTitle = $analyse['nom_rapport'];

// Traitement AJAX pour analyser un chunk
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    if ($_POST['action'] === 'analyze_chunk') {
        $chunkId = (int)($_POST['chunk_id'] ?? 0);

        try {
            // Récupérer le chunk
            $stmtChunk = $pdo->prepare("SELECT * FROM comparables_chunks WHERE id = ? AND analyse_id = ?");
            $stmtChunk->execute([$chunkId, $analyseId]);
            $chunk = $stmtChunk->fetch();

            if (!$chunk) {
                echo json_encode(['success' => false, 'error' => 'Chunk introuvable']);
                exit;
            }

            // Récupérer les photos du chunk
            $stmtPhotos = $pdo->prepare("SELECT * FROM comparables_photos WHERE chunk_id = ? ORDER BY ordre");
            $stmtPhotos->execute([$chunkId]);
            $photos = $stmtPhotos->fetchAll();

            // Infos du projet sujet
            $projetInfo = [
                'adresse' => $analyse['projet_adresse'] . ', ' . $analyse['projet_ville'],
                'type' => 'Maison unifamiliale rénové'
            ];

            // Données du chunk pour l'analyse
            $chunkData = [
                'adresse' => $chunk['adresse'],
                'prix_vendu' => $chunk['prix_vendu'],
                'renovations_texte' => $chunk['renovations_texte']
            ];

            // Analyser les photos avec Claude
            $claudeService = new ClaudeService($pdo);
            $result = $claudeService->analyzeChunkPhotos($photos, $chunkData, $projetInfo);

            // Mettre à jour le chunk avec les résultats
            $stmtUpdate = $pdo->prepare("
                UPDATE comparables_chunks
                SET etat_note = ?, etat_analyse = ?, ajustement = ?, commentaire_ia = ?, statut = 'done'
                WHERE id = ?
            ");
            $stmtUpdate->execute([
                $result['etat_note'] ?? 5,
                $result['etat_analyse'] ?? '',
                $result['ajustement'] ?? 0,
                $result['commentaire_ia'] ?? '',
                $chunkId
            ]);

            // Vérifier si tous les chunks sont terminés
            $stmtRemaining = $pdo->prepare("SELECT COUNT(*) FROM comparables_chunks WHERE analyse_id = ? AND statut != 'done'");
            $stmtRemaining->execute([$analyseId]);
            $remaining = $stmtRemaining->fetchColumn();

            echo json_encode([
                'success' => true,
                'result' => $result,
                'remaining' => $remaining
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    if ($_POST['action'] === 'finalize') {
        try {
            // Récupérer tous les chunks terminés
            $stmtChunks = $pdo->prepare("SELECT * FROM comparables_chunks WHERE analyse_id = ? AND statut = 'done'");
            $stmtChunks->execute([$analyseId]);
            $allChunks = $stmtChunks->fetchAll();

            // Infos du projet
            $projetInfo = [
                'adresse' => $analyse['projet_adresse'] . ', ' . $analyse['projet_ville']
            ];

            // Consolider les résultats
            $claudeService = new ClaudeService($pdo);
            $consolidated = $claudeService->consolidateChunksAnalysis($allChunks, $projetInfo);

            // Mettre à jour l'analyse
            $stmtUpdate = $pdo->prepare("
                UPDATE analyses_marche
                SET prix_suggere_ia = ?, fourchette_basse = ?, fourchette_haute = ?,
                    analyse_ia_texte = ?, statut = 'termine'
                WHERE id = ?
            ");
            $stmtUpdate->execute([
                $consolidated['prix_suggere'],
                $consolidated['fourchette_basse'],
                $consolidated['fourchette_haute'],
                $consolidated['commentaire_general'],
                $analyseId
            ]);

            echo json_encode([
                'success' => true,
                'consolidated' => $consolidated
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
}

include '../../includes/header.php';
?>

<style>
.chunk-card {
    transition: all 0.3s ease;
}
.chunk-card.analyzing {
    border-color: #ffc107 !important;
    box-shadow: 0 0 10px rgba(255, 193, 7, 0.3);
}
.chunk-card.done {
    border-color: #198754 !important;
}
.chunk-card.error {
    border-color: #dc3545 !important;
}
.photo-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
    gap: 8px;
}
.photo-grid img {
    width: 100%;
    height: 80px;
    object-fit: cover;
    border-radius: 4px;
    cursor: pointer;
    transition: transform 0.2s;
}
.photo-grid img:hover {
    transform: scale(1.05);
}
.price-summary {
    background: linear-gradient(135deg, #1e3a5f 0%, #2d5a87 100%);
    color: white;
    border-radius: 12px;
    padding: 24px;
}
.price-main {
    font-size: 2.5rem;
    font-weight: bold;
}
.progress-analysis {
    height: 30px;
    font-size: 1rem;
}
.note-badge {
    font-size: 1.2rem;
    padding: 0.5em 0.8em;
}
.ajustement-positif { color: #198754; }
.ajustement-negatif { color: #dc3545; }
</style>

<div class="container-fluid">
    <div class="page-header">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= url('/admin/index.php') ?>">Tableau de bord</a></li>
                <li class="breadcrumb-item"><a href="<?= url('/admin/comparables/index.php') ?>">Comparables</a></li>
                <li class="breadcrumb-item active"><?= e($analyse['nom_rapport']) ?></li>
            </ol>
        </nav>
        <h1><i class="bi bi-robot me-2"></i><?= e($analyse['nom_rapport']) ?></h1>
    </div>

    <!-- Résumé Prix -->
    <?php if ($analyse['statut'] === 'termine' && $analyse['prix_suggere_ia'] > 0): ?>
        <div class="price-summary mb-4">
            <div class="row align-items-center">
                <div class="col-md-4 text-center">
                    <div class="text-uppercase small opacity-75">Prix Suggéré</div>
                    <div class="price-main"><?= formatMoney($analyse['prix_suggere_ia']) ?></div>
                </div>
                <div class="col-md-4 text-center">
                    <div class="text-uppercase small opacity-75">Fourchette</div>
                    <div class="fs-4">
                        <?= formatMoney($analyse['fourchette_basse']) ?> - <?= formatMoney($analyse['fourchette_haute']) ?>
                    </div>
                </div>
                <div class="col-md-4 text-center">
                    <div class="text-uppercase small opacity-75">Comparables</div>
                    <div class="fs-4"><?= count($chunks) ?> propriétés</div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Progression et Actions -->
    <div class="card mb-4">
        <div class="card-body">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <h5 class="mb-3">Progression de l'analyse</h5>
                    <div class="progress progress-analysis mb-2">
                        <div class="progress-bar bg-success" id="progressBar"
                             style="width: <?= count($chunks) > 0 ? round(($chunksDone / count($chunks)) * 100) : 0 ?>%">
                            <span id="progressText"><?= $chunksDone ?>/<?= count($chunks) ?></span>
                        </div>
                    </div>
                    <small class="text-muted" id="statusText">
                        <?php if ($analyse['statut'] === 'termine'): ?>
                            Analyse terminée
                        <?php elseif ($chunksDone === count($chunks) && count($chunks) > 0): ?>
                            Prêt pour finalisation
                        <?php else: ?>
                            <?= $chunksPending ?> propriétés à analyser
                        <?php endif; ?>
                    </small>
                </div>
                <div class="col-md-6 text-end">
                    <?php if ($analyse['statut'] !== 'termine'): ?>
                        <?php if ($chunksDone < count($chunks)): ?>
                            <button class="btn btn-primary btn-lg" id="btnStartAnalysis">
                                <i class="bi bi-play-fill me-2"></i>
                                <?= $chunksDone > 0 ? 'Continuer' : 'Lancer' ?> l'analyse IA
                            </button>
                        <?php else: ?>
                            <button class="btn btn-success btn-lg" id="btnFinalize">
                                <i class="bi bi-check-circle me-2"></i>Finaliser et calculer le prix
                            </button>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Commentaire IA global -->
    <?php if (!empty($analyse['analyse_ia_texte'])): ?>
        <div class="card mb-4">
            <div class="card-header">
                <i class="bi bi-chat-left-text me-2"></i>Analyse consolidée
            </div>
            <div class="card-body">
                <pre class="mb-0" style="white-space: pre-wrap; font-family: inherit;"><?= e($analyse['analyse_ia_texte']) ?></pre>
            </div>
        </div>
    <?php endif; ?>

    <!-- Liste des Chunks (Comparables) -->
    <h4 class="mb-3"><i class="bi bi-houses me-2"></i>Propriétés comparables (<?= count($chunks) ?>)</h4>

    <div class="row" id="chunksContainer">
        <?php foreach ($chunks as $index => $chunk): ?>
            <div class="col-lg-6 mb-4">
                <div class="card chunk-card h-100 <?= $chunk['statut'] === 'done' ? 'done' : '' ?>"
                     id="chunk-<?= $chunk['id'] ?>"
                     data-chunk-id="<?= $chunk['id'] ?>"
                     data-status="<?= $chunk['statut'] ?>">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <div>
                            <strong>No Centris: <?= e($chunk['no_centris']) ?></strong>
                            <?php if ($chunk['statut'] === 'done'): ?>
                                <span class="badge bg-success ms-2"><i class="bi bi-check"></i></span>
                            <?php elseif ($chunk['statut'] === 'error'): ?>
                                <span class="badge bg-danger ms-2"><i class="bi bi-x"></i></span>
                            <?php endif; ?>
                        </div>
                        <span class="badge bg-secondary"><?= $chunk['nb_photos'] ?> photos</span>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-7">
                                <p class="mb-1"><strong><?= e($chunk['adresse'] ?? 'Adresse inconnue') ?></strong></p>
                                <?php if ($chunk['ville']): ?>
                                    <p class="text-muted mb-2"><?= e($chunk['ville']) ?></p>
                                <?php endif; ?>

                                <!-- Prix et jours -->
                                <div class="d-flex gap-3 mb-2">
                                    <?php if ($chunk['prix_vendu'] > 0): ?>
                                        <span class="fw-bold text-success"><i class="bi bi-tag"></i> <?= formatMoney($chunk['prix_vendu']) ?></span>
                                    <?php endif; ?>
                                    <?php if ($chunk['jours_marche']): ?>
                                        <span><i class="bi bi-calendar3 text-muted"></i> <?= $chunk['jours_marche'] ?> jours</span>
                                    <?php endif; ?>
                                </div>

                                <!-- Caractéristiques de base -->
                                <div class="d-flex flex-wrap gap-2 small mb-2">
                                    <?php if ($chunk['chambres']): ?>
                                        <span class="badge bg-light text-dark"><i class="bi bi-door-closed"></i> <?= e($chunk['chambres']) ?> ch</span>
                                    <?php endif; ?>
                                    <?php if ($chunk['sdb']): ?>
                                        <span class="badge bg-light text-dark"><i class="bi bi-droplet"></i> <?= e($chunk['sdb']) ?> sdb</span>
                                    <?php endif; ?>
                                    <?php if ($chunk['annee_construction']): ?>
                                        <span class="badge bg-light text-dark"><i class="bi bi-building"></i> <?= $chunk['annee_construction'] ?></span>
                                    <?php endif; ?>
                                    <?php if ($chunk['type_propriete']): ?>
                                        <span class="badge bg-info text-dark"><?= e($chunk['type_propriete']) ?></span>
                                    <?php endif; ?>
                                    <?php if ($chunk['superficie_terrain']): ?>
                                        <span class="badge bg-light text-dark"><i class="bi bi-rulers"></i> <?= e($chunk['superficie_terrain']) ?> pc</span>
                                    <?php endif; ?>
                                </div>

                                <!-- Évaluation municipale -->
                                <?php if ($chunk['eval_total'] > 0): ?>
                                <div class="small mb-2 p-2 bg-light rounded">
                                    <strong><i class="bi bi-bank"></i> Évaluation:</strong>
                                    <?= formatMoney($chunk['eval_total']) ?>
                                    <?php if ($chunk['eval_terrain'] > 0 || $chunk['eval_batiment'] > 0): ?>
                                        <span class="text-muted">(Terrain: <?= formatMoney($chunk['eval_terrain']) ?>, Bât: <?= formatMoney($chunk['eval_batiment']) ?>)</span>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>

                                <!-- Taxes -->
                                <?php if ($chunk['taxe_municipale'] > 0 || $chunk['taxe_scolaire'] > 0): ?>
                                <div class="small mb-2">
                                    <i class="bi bi-receipt"></i> <strong>Taxes:</strong>
                                    <?php if ($chunk['taxe_municipale'] > 0): ?>
                                        Mun: <?= formatMoney($chunk['taxe_municipale']) ?>
                                    <?php endif; ?>
                                    <?php if ($chunk['taxe_scolaire'] > 0): ?>
                                        | Scol: <?= formatMoney($chunk['taxe_scolaire']) ?>
                                    <?php endif; ?>
                                    <?php if ($chunk['taxe_annee']): ?>
                                        (<?= $chunk['taxe_annee'] ?>)
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>

                                <!-- Rénovations -->
                                <?php if ($chunk['renovations_total'] > 0 || $chunk['renovations_texte']): ?>
                                <div class="small mb-2 p-2 bg-warning bg-opacity-10 rounded">
                                    <strong><i class="bi bi-tools"></i> Rénovations:</strong>
                                    <?php if ($chunk['renovations_total'] > 0): ?>
                                        <span class="text-success fw-bold"><?= formatMoney($chunk['renovations_total']) ?></span>
                                    <?php endif; ?>
                                    <?php if ($chunk['renovations_texte']): ?>
                                        <br><small class="text-muted"><?= e(substr($chunk['renovations_texte'], 0, 200)) ?><?= strlen($chunk['renovations_texte']) > 200 ? '...' : '' ?></small>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>

                                <!-- Caractéristiques détaillées -->
                                <div class="small text-muted mb-2">
                                    <?php if ($chunk['fondation']): ?>
                                        <span class="me-2">🏠 <?= e($chunk['fondation']) ?></span>
                                    <?php endif; ?>
                                    <?php if ($chunk['toiture']): ?>
                                        <span class="me-2">🔺 <?= e($chunk['toiture']) ?></span>
                                    <?php endif; ?>
                                    <?php if ($chunk['garage']): ?>
                                        <span class="me-2">🚗 <?= e($chunk['garage']) ?></span>
                                    <?php endif; ?>
                                    <?php if ($chunk['piscine']): ?>
                                        <span class="me-2">🏊 <?= e($chunk['piscine']) ?></span>
                                    <?php endif; ?>
                                    <?php if ($chunk['sous_sol']): ?>
                                        <span class="me-2">⬇️ <?= e(substr($chunk['sous_sol'], 0, 50)) ?></span>
                                    <?php endif; ?>
                                </div>

                                <!-- Remarques -->
                                <?php if ($chunk['remarques']): ?>
                                <div class="small mb-2">
                                    <details>
                                        <summary class="text-muted cursor-pointer"><i class="bi bi-chat-left-text"></i> Remarques</summary>
                                        <p class="mt-1 p-2 bg-light rounded"><?= nl2br(e(substr($chunk['remarques'], 0, 500))) ?></p>
                                    </details>
                                </div>
                                <?php endif; ?>

                                <!-- Résultats IA -->
                                <?php if ($chunk['statut'] === 'done'): ?>
                                    <hr>
                                    <div class="d-flex align-items-center gap-3 mb-2">
                                        <span class="note-badge badge bg-<?= $chunk['etat_note'] >= 7 ? 'success' : ($chunk['etat_note'] >= 5 ? 'warning' : 'danger') ?>">
                                            <?= number_format($chunk['etat_note'], 1) ?>/10
                                        </span>
                                        <?php if ($chunk['ajustement'] != 0): ?>
                                            <span class="fs-5 fw-bold <?= $chunk['ajustement'] >= 0 ? 'ajustement-positif' : 'ajustement-negatif' ?>">
                                                <?= $chunk['ajustement'] >= 0 ? '+' : '' ?><?= formatMoney($chunk['ajustement']) ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($chunk['etat_analyse']): ?>
                                        <p class="small mb-1"><strong>État:</strong> <?= e($chunk['etat_analyse']) ?></p>
                                    <?php endif; ?>
                                    <?php if ($chunk['commentaire_ia']): ?>
                                        <p class="small text-muted mb-0"><?= e($chunk['commentaire_ia']) ?></p>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-5">
                                <?php
                                // Récupérer les photos du chunk
                                $stmtPhotos = $pdo->prepare("SELECT * FROM comparables_photos WHERE chunk_id = ? ORDER BY ordre LIMIT 6");
                                $stmtPhotos->execute([$chunk['id']]);
                                $photos = $stmtPhotos->fetchAll();
                                ?>
                                <?php if (!empty($photos)): ?>
                                    <div class="photo-grid">
                                        <?php foreach ($photos as $photo): ?>
                                            <?php
                                            // Convertir le chemin absolu en URL relative
                                            $photoUrl = str_replace(dirname(dirname(__DIR__)), '', $photo['file_path']);
                                            ?>
                                            <img src="<?= e($photoUrl) ?>"
                                                 alt="<?= e($photo['label'] ?? 'Photo') ?>"
                                                 onclick="openLightbox('<?= e($photoUrl) ?>')"
                                                 loading="lazy">
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="text-center text-muted py-4">
                                        <i class="bi bi-image fs-1"></i>
                                        <p class="small mb-0">Aucune photo</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Lightbox Modal -->
<div class="modal fade" id="lightboxModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content bg-dark">
            <div class="modal-body p-0 text-center">
                <img src="" id="lightboxImage" class="img-fluid" style="max-height: 90vh;">
                <button type="button" class="btn-close btn-close-white position-absolute top-0 end-0 m-3" data-bs-dismiss="modal"></button>
            </div>
        </div>
    </div>
</div>

<script>
function openLightbox(src) {
    document.getElementById('lightboxImage').src = src;
    new bootstrap.Modal(document.getElementById('lightboxModal')).show();
}

// Analyse des chunks
document.getElementById('btnStartAnalysis')?.addEventListener('click', async function() {
    const btn = this;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Analyse en cours...';

    const chunks = document.querySelectorAll('.chunk-card[data-status="pending"]');
    const totalChunks = <?= count($chunks) ?>;
    let doneCount = <?= $chunksDone ?>;

    for (const card of chunks) {
        const chunkId = card.dataset.chunkId;
        card.classList.add('analyzing');

        try {
            const response = await fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=analyze_chunk&chunk_id=${chunkId}`
            });

            const data = await response.json();

            if (data.success) {
                card.classList.remove('analyzing');
                card.classList.add('done');
                card.dataset.status = 'done';
                doneCount++;

                // Mettre à jour la progression
                const progress = Math.round((doneCount / totalChunks) * 100);
                document.getElementById('progressBar').style.width = progress + '%';
                document.getElementById('progressText').textContent = doneCount + '/' + totalChunks;

                // Recharger la page pour afficher les résultats (simple pour l'instant)
                if (data.remaining === 0) {
                    location.reload();
                }
            } else {
                card.classList.remove('analyzing');
                card.classList.add('error');
                console.error('Erreur:', data.error);
            }
        } catch (err) {
            card.classList.remove('analyzing');
            card.classList.add('error');
            console.error('Erreur réseau:', err);
        }
    }

    // Recharger pour voir le bouton finaliser
    location.reload();
});

// Finalisation
document.getElementById('btnFinalize')?.addEventListener('click', async function() {
    const btn = this;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Calcul en cours...';

    try {
        const response = await fetch('', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=finalize'
        });

        const data = await response.json();

        if (data.success) {
            location.reload();
        } else {
            alert('Erreur: ' + data.error);
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-check-circle me-2"></i>Finaliser et calculer le prix';
        }
    } catch (err) {
        alert('Erreur réseau');
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-check-circle me-2"></i>Finaliser et calculer le prix';
    }
});
</script>

<?php include '../../includes/footer.php'; ?>
