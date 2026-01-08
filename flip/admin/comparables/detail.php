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

            // Infos du projet sujet (enrichies)
            $projetInfo = [
                'adresse' => $analyse['projet_adresse'],
                'ville' => $analyse['projet_ville'],
                'type' => 'Maison unifamiliale',
                'chambres' => 3, // TODO: Récupérer depuis le projet
                'sdb' => 2,
                'superficie' => 'N/A',
                'garage' => 'Non'
            ];

            // Données COMPLÈTES du chunk pour l'analyse IA approfondie
            $chunkData = [
                'no_centris' => $chunk['no_centris'],
                'adresse' => $chunk['adresse'],
                'ville' => $chunk['ville'],
                'prix_vendu' => $chunk['prix_vendu'],
                'date_vente' => $chunk['date_vente'],
                'jours_marche' => $chunk['jours_marche'],
                'type_propriete' => $chunk['type_propriete'],
                'annee_construction' => $chunk['annee_construction'],
                'chambres' => $chunk['chambres'],
                'sdb' => $chunk['sdb'],
                'superficie_terrain' => $chunk['superficie_terrain'],
                'superficie_batiment' => $chunk['superficie_batiment'],
                // Évaluation municipale
                'eval_terrain' => $chunk['eval_terrain'],
                'eval_batiment' => $chunk['eval_batiment'],
                'eval_total' => $chunk['eval_total'],
                // Taxes
                'taxe_municipale' => $chunk['taxe_municipale'],
                'taxe_scolaire' => $chunk['taxe_scolaire'],
                // Caractéristiques
                'fondation' => $chunk['fondation'],
                'toiture' => $chunk['toiture'],
                'revetement' => $chunk['revetement'],
                'garage' => $chunk['garage'],
                'stationnement' => $chunk['stationnement'],
                'piscine' => $chunk['piscine'],
                'sous_sol' => $chunk['sous_sol'],
                'chauffage' => $chunk['chauffage'],
                'energie' => $chunk['energie'],
                // Rénovations
                'renovations_total' => $chunk['renovations_total'],
                'renovations_texte' => $chunk['renovations_texte'],
                // Autres
                'proximites' => $chunk['proximites'],
                'inclusions' => $chunk['inclusions'],
                'exclusions' => $chunk['exclusions'],
                'remarques' => $chunk['remarques']
            ];

            // Analyser avec Claude (analyse texte approfondie)
            $claudeService = new ClaudeService($pdo);
            $result = $claudeService->analyzeChunkText($chunkData, $projetInfo);

            // Mettre à jour le chunk avec les résultats
            $stmtUpdate = $pdo->prepare("
                UPDATE comparables_chunks
                SET etat_note = ?, etat_analyse = ?, ajustement = ?, confiance = ?, commentaire_ia = ?, statut = 'done'
                WHERE id = ?
            ");
            $stmtUpdate->execute([
                $result['etat_note'] ?? 5,
                $result['etat_analyse'] ?? '',
                $result['ajustement'] ?? 0,
                $result['confiance'] ?? 50,
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
            <div class="col-xl-6 col-12 mb-4">
                <div class="card chunk-card <?= $chunk['statut'] === 'done' ? 'done' : '' ?>"
                     id="chunk-<?= $chunk['id'] ?>"
                     data-chunk-id="<?= $chunk['id'] ?>"
                     data-status="<?= $chunk['statut'] ?>">

                    <!-- Header avec résumé -->
                    <div class="card-header">
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <div class="d-flex align-items-center gap-3">
                                <strong class="fs-5">No Centris: <?= e($chunk['no_centris']) ?></strong>
                                <?php if ($chunk['statut'] === 'done'): ?>
                                    <span class="badge bg-success"><i class="bi bi-check-lg"></i> Analysé</span>
                                <?php elseif ($chunk['statut'] === 'error'): ?>
                                    <span class="badge bg-danger"><i class="bi bi-x"></i> Erreur</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">En attente</span>
                                <?php endif; ?>
                            </div>
                            <div class="d-flex align-items-center gap-3">
                                <?php if ($chunk['prix_vendu'] > 0): ?>
                                    <span class="fs-4 fw-bold text-success"><?= formatMoney($chunk['prix_vendu']) ?></span>
                                <?php endif; ?>
                                <?php if ($chunk['statut'] === 'done'): ?>
                                    <?php $confiance = $chunk['confiance'] ?? 0; ?>
                                    <div class="text-center" style="min-width: 70px;">
                                        <div class="progress" style="height: 8px; width: 60px;">
                                            <div class="progress-bar bg-<?= $confiance >= 70 ? 'success' : ($confiance >= 40 ? 'warning' : 'danger') ?>"
                                                 style="width: <?= $confiance ?>%"></div>
                                        </div>
                                        <small class="text-muted"><?= $confiance ?>% conf.</small>
                                    </div>
                                    <span class="badge note-badge bg-<?= $chunk['etat_note'] >= 7 ? 'success' : ($chunk['etat_note'] >= 5 ? 'warning' : 'danger') ?>">
                                        <?= number_format($chunk['etat_note'], 1) ?>/10
                                    </span>
                                    <?php if ($chunk['ajustement'] != 0): ?>
                                        <span class="fs-5 fw-bold <?= $chunk['ajustement'] >= 0 ? 'ajustement-positif' : 'ajustement-negatif' ?>">
                                            <?= $chunk['ajustement'] >= 0 ? '+' : '' ?><?= formatMoney($chunk['ajustement']) ?>
                                        </span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="mt-2">
                            <span class="text-muted"><?= e($chunk['adresse'] ?? 'Adresse inconnue') ?><?= $chunk['ville'] ? ', ' . e($chunk['ville']) : '' ?></span>
                            <?php if ($chunk['jours_marche']): ?>
                                <span class="ms-3 badge bg-light text-dark"><i class="bi bi-calendar3"></i> <?= $chunk['jours_marche'] ?> jours</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="card-body p-0">
                        <!-- Navigation Tabs -->
                        <ul class="nav nav-tabs nav-fill" role="tablist">
                            <li class="nav-item">
                                <a class="nav-link active" data-bs-toggle="tab" href="#tab-caract-<?= $chunk['id'] ?>">
                                    <i class="bi bi-list-ul"></i> Caractéristiques
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" data-bs-toggle="tab" href="#tab-eval-<?= $chunk['id'] ?>">
                                    <i class="bi bi-bank"></i> Évaluation & Taxes
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" data-bs-toggle="tab" href="#tab-construction-<?= $chunk['id'] ?>">
                                    <i class="bi bi-house-gear"></i> Construction
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" data-bs-toggle="tab" href="#tab-reno-<?= $chunk['id'] ?>">
                                    <i class="bi bi-tools"></i> Rénovations
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" data-bs-toggle="tab" href="#tab-autres-<?= $chunk['id'] ?>">
                                    <i class="bi bi-info-circle"></i> Autres
                                </a>
                            </li>
                            <?php if ($chunk['statut'] === 'done'): ?>
                            <li class="nav-item">
                                <a class="nav-link text-primary" data-bs-toggle="tab" href="#tab-ia-<?= $chunk['id'] ?>">
                                    <i class="bi bi-robot"></i> Analyse IA
                                </a>
                            </li>
                            <?php endif; ?>
                        </ul>

                        <!-- Tab Contents -->
                        <div class="tab-content p-3">
                            <!-- Tab Caractéristiques -->
                            <div class="tab-pane fade show active" id="tab-caract-<?= $chunk['id'] ?>">
                                <table class="table table-sm table-striped mb-0">
                                    <tbody>
                                        <tr><th width="40%">Type de propriété</th><td><?= e($chunk['type_propriete'] ?? '-') ?></td></tr>
                                        <tr><th>Année de construction</th><td><?= $chunk['annee_construction'] ?? '-' ?></td></tr>
                                        <tr><th>Chambres</th><td><?= e($chunk['chambres'] ?? '-') ?></td></tr>
                                        <tr><th>Salles de bain</th><td><?= e($chunk['sdb'] ?? '-') ?></td></tr>
                                        <tr><th>Superficie terrain</th><td><?= $chunk['superficie_terrain'] ? e($chunk['superficie_terrain']) . ' pc' : '-' ?></td></tr>
                                        <tr><th>Superficie bâtiment</th><td><?= $chunk['superficie_batiment'] ? e($chunk['superficie_batiment']) . ' pi²' : '-' ?></td></tr>
                                        <tr><th>Date de vente</th><td><?= $chunk['date_vente'] ?? '-' ?></td></tr>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Tab Évaluation & Taxes -->
                            <div class="tab-pane fade" id="tab-eval-<?= $chunk['id'] ?>">
                                <div class="row">
                                    <div class="col-md-6">
                                        <h6 class="border-bottom pb-2"><i class="bi bi-bank me-2"></i>Évaluation municipale</h6>
                                        <table class="table table-sm">
                                            <tbody>
                                                <tr><th>Terrain</th><td class="text-end"><?= $chunk['eval_terrain'] ? formatMoney($chunk['eval_terrain']) : '-' ?></td></tr>
                                                <tr><th>Bâtiment</th><td class="text-end"><?= $chunk['eval_batiment'] ? formatMoney($chunk['eval_batiment']) : '-' ?></td></tr>
                                                <tr class="table-primary"><th>Total</th><td class="text-end fw-bold"><?= $chunk['eval_total'] ? formatMoney($chunk['eval_total']) : '-' ?></td></tr>
                                            </tbody>
                                        </table>
                                    </div>
                                    <div class="col-md-6">
                                        <h6 class="border-bottom pb-2"><i class="bi bi-receipt me-2"></i>Taxes <?= $chunk['taxe_annee'] ? '(' . $chunk['taxe_annee'] . ')' : '' ?></h6>
                                        <table class="table table-sm">
                                            <tbody>
                                                <tr><th>Municipale</th><td class="text-end"><?= $chunk['taxe_municipale'] ? formatMoney($chunk['taxe_municipale']) : '-' ?></td></tr>
                                                <tr><th>Scolaire</th><td class="text-end"><?= $chunk['taxe_scolaire'] ? formatMoney($chunk['taxe_scolaire']) : '-' ?></td></tr>
                                                <tr class="table-warning"><th>Total</th><td class="text-end fw-bold"><?= ($chunk['taxe_municipale'] || $chunk['taxe_scolaire']) ? formatMoney(($chunk['taxe_municipale'] ?? 0) + ($chunk['taxe_scolaire'] ?? 0)) : '-' ?></td></tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>

                            <!-- Tab Construction -->
                            <div class="tab-pane fade" id="tab-construction-<?= $chunk['id'] ?>">
                                <table class="table table-sm table-striped mb-0">
                                    <tbody>
                                        <tr><th width="40%">Fondation</th><td><?= e($chunk['fondation'] ?? '-') ?></td></tr>
                                        <tr><th>Toiture</th><td><?= e($chunk['toiture'] ?? '-') ?></td></tr>
                                        <tr><th>Revêtement</th><td><?= e($chunk['revetement'] ?? '-') ?></td></tr>
                                        <tr><th>Garage</th><td><?= e($chunk['garage'] ?? '-') ?></td></tr>
                                        <tr><th>Stationnement</th><td><?= e($chunk['stationnement'] ?? '-') ?></td></tr>
                                        <tr><th>Piscine</th><td><?= e($chunk['piscine'] ?? '-') ?></td></tr>
                                        <tr><th>Sous-sol</th><td><?= e($chunk['sous_sol'] ?? '-') ?></td></tr>
                                        <tr><th>Chauffage</th><td><?= e($chunk['chauffage'] ?? '-') ?></td></tr>
                                        <tr><th>Énergie</th><td><?= e($chunk['energie'] ?? '-') ?></td></tr>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Tab Rénovations -->
                            <div class="tab-pane fade" id="tab-reno-<?= $chunk['id'] ?>">
                                <?php if ($chunk['renovations_total'] > 0 || $chunk['renovations_texte']): ?>
                                    <?php if ($chunk['renovations_total'] > 0): ?>
                                        <div class="alert alert-success">
                                            <i class="bi bi-tools me-2"></i>
                                            <strong>Total des rénovations:</strong>
                                            <span class="fs-5"><?= formatMoney($chunk['renovations_total']) ?></span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($chunk['renovations_texte']): ?>
                                        <div class="p-3 bg-light rounded">
                                            <?= nl2br(e($chunk['renovations_texte'])) ?>
                                        </div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <div class="text-muted text-center py-4">
                                        <i class="bi bi-tools fs-1 d-block mb-2"></i>
                                        Aucune information sur les rénovations
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Tab Autres -->
                            <div class="tab-pane fade" id="tab-autres-<?= $chunk['id'] ?>">
                                <div class="row">
                                    <div class="col-md-6">
                                        <h6 class="border-bottom pb-2">Proximités</h6>
                                        <p><?= $chunk['proximites'] ? nl2br(e($chunk['proximites'])) : '<span class="text-muted">-</span>' ?></p>

                                        <h6 class="border-bottom pb-2 mt-3">Inclusions</h6>
                                        <p><?= $chunk['inclusions'] ? nl2br(e($chunk['inclusions'])) : '<span class="text-muted">-</span>' ?></p>

                                        <h6 class="border-bottom pb-2 mt-3">Exclusions</h6>
                                        <p><?= $chunk['exclusions'] ? nl2br(e($chunk['exclusions'])) : '<span class="text-muted">-</span>' ?></p>
                                    </div>
                                    <div class="col-md-6">
                                        <h6 class="border-bottom pb-2">Remarques du courtier</h6>
                                        <?php if ($chunk['remarques']): ?>
                                            <div class="p-3 bg-light rounded" style="max-height: 300px; overflow-y: auto;">
                                                <?= nl2br(e($chunk['remarques'])) ?>
                                            </div>
                                        <?php else: ?>
                                            <p class="text-muted">-</p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <!-- Tab Analyse IA -->
                            <?php if ($chunk['statut'] === 'done'): ?>
                            <div class="tab-pane fade" id="tab-ia-<?= $chunk['id'] ?>">
                                <div class="row">
                                    <div class="col-md-4 text-center border-end">
                                        <h6 class="text-muted">Note d'état</h6>
                                        <div class="display-4 fw-bold text-<?= $chunk['etat_note'] >= 7 ? 'success' : ($chunk['etat_note'] >= 5 ? 'warning' : 'danger') ?>">
                                            <?= number_format($chunk['etat_note'], 1) ?><small class="fs-5">/10</small>
                                        </div>
                                    </div>
                                    <div class="col-md-4 text-center border-end">
                                        <h6 class="text-muted">Ajustement</h6>
                                        <div class="display-5 fw-bold <?= $chunk['ajustement'] >= 0 ? 'ajustement-positif' : 'ajustement-negatif' ?>">
                                            <?= $chunk['ajustement'] >= 0 ? '+' : '' ?><?= formatMoney($chunk['ajustement']) ?>
                                        </div>
                                    </div>
                                    <div class="col-md-4 text-center">
                                        <h6 class="text-muted">Confiance</h6>
                                        <?php $confiance = $chunk['confiance'] ?? 0; ?>
                                        <div class="display-5 fw-bold text-<?= $confiance >= 70 ? 'success' : ($confiance >= 40 ? 'warning' : 'danger') ?>">
                                            <?= $confiance ?>%
                                        </div>
                                        <div class="progress mt-2" style="height: 10px;">
                                            <div class="progress-bar bg-<?= $confiance >= 70 ? 'success' : ($confiance >= 40 ? 'warning' : 'danger') ?>"
                                                 style="width: <?= $confiance ?>%"></div>
                                        </div>
                                    </div>
                                </div>
                                <hr>
                                <?php if ($chunk['etat_analyse']): ?>
                                    <div class="mb-3">
                                        <h6><i class="bi bi-eye me-2"></i>Analyse de l'état</h6>
                                        <p class="bg-light p-3 rounded"><?= nl2br(e($chunk['etat_analyse'])) ?></p>
                                    </div>
                                <?php endif; ?>
                                <?php if ($chunk['commentaire_ia']): ?>
                                    <div>
                                        <h6><i class="bi bi-chat-left-text me-2"></i>Commentaire IA</h6>
                                        <p class="bg-info bg-opacity-10 p-3 rounded"><?= nl2br(e($chunk['commentaire_ia'])) ?></p>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

</div>

<!-- Lightbox Modal (kept for future use) -->
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
