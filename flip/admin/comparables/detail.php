<?php
/**
 * Détail d'une analyse de marché - Comparables IA
 * Version 2 - Workflow par chunks avec galerie photos
 * Flip Manager
 */

require_once '../../config.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../includes/AIServiceFactory.php';

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

            // Analyser avec l'IA configurée (analyse texte approfondie)
            $aiService = AIServiceFactory::create($pdo);
            $result = $aiService->analyzeChunkText($chunkData, $projetInfo);

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
            $aiService = AIServiceFactory::create($pdo);
            $consolidated = $aiService->consolidateChunksAnalysis($allChunks, $projetInfo);

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

    <!-- Tableau Comparables style Spreadsheet - COLONNES -->
    <h4 class="mb-3"><i class="bi bi-table me-2"></i>Grille des comparables (<?= count($chunks) ?>)</h4>

    <div class="table-responsive" id="chunksContainer">
        <table class="table table-bordered table-sm" style="font-size: 0.8rem;">
            <!-- En-tête: Numéros des comparables en colonnes -->
            <thead class="table-dark">
                <tr>
                    <th class="bg-secondary" style="min-width: 140px;">Propriété</th>
                    <?php foreach ($chunks as $index => $chunk): ?>
                    <th class="text-center" style="min-width: 130px;">
                        <span class="chunk-header" id="chunk-<?= $chunk['id'] ?>" data-chunk-id="<?= $chunk['id'] ?>" data-status="<?= $chunk['statut'] ?>">
                            Comp. #<?= $index + 1 ?>
                            <?php if ($chunk['statut'] === 'done'): ?><span class="badge bg-success ms-1">✓</span><?php endif; ?>
                        </span>
                    </th>
                    <?php endforeach; ?>
                    <th class="bg-warning text-dark text-center" style="min-width: 100px;">Moyenne</th>
                </tr>
            </thead>
            <tbody>
                <!-- No Centris -->
                <tr>
                    <th class="table-light">No Centris</th>
                    <?php foreach ($chunks as $chunk): ?>
                    <td class="text-center"><code><?= e($chunk['no_centris']) ?></code></td>
                    <?php endforeach; ?>
                    <td class="table-warning"></td>
                </tr>
                <!-- Adresse -->
                <tr>
                    <th class="table-light">Adresse</th>
                    <?php foreach ($chunks as $chunk): ?>
                    <td class="small"><?= e($chunk['adresse'] ?? '-') ?><br><em class="text-muted"><?= e($chunk['ville'] ?? '') ?></em></td>
                    <?php endforeach; ?>
                    <td class="table-warning"></td>
                </tr>
                <!-- Prix vendu -->
                <tr class="table-primary">
                    <th>Prix vendu</th>
                    <?php foreach ($chunks as $chunk): ?>
                    <td class="text-end fw-bold"><?= $chunk['prix_vendu'] ? formatMoney($chunk['prix_vendu']) : '-' ?></td>
                    <?php endforeach; ?>
                    <td class="table-warning text-end fw-bold"><?php
                        $prix = array_filter(array_column($chunks, 'prix_vendu'));
                        echo count($prix) > 0 ? formatMoney(array_sum($prix) / count($prix)) : '-';
                    ?></td>
                </tr>
                <!-- Date de vente -->
                <tr>
                    <th class="table-light">Date de vente</th>
                    <?php foreach ($chunks as $chunk): ?>
                    <td class="text-center"><?= $chunk['date_vente'] ? date('Y-m-d', strtotime($chunk['date_vente'])) : '-' ?></td>
                    <?php endforeach; ?>
                    <td class="table-warning"></td>
                </tr>
                <!-- Jours sur le marché -->
                <tr>
                    <th class="table-light">Jours marché</th>
                    <?php foreach ($chunks as $chunk): ?>
                    <td class="text-center"><?= $chunk['jours_marche'] ?? '-' ?></td>
                    <?php endforeach; ?>
                    <td class="table-warning text-center"><?php
                        $jours = array_filter(array_column($chunks, 'jours_marche'));
                        echo count($jours) > 0 ? round(array_sum($jours) / count($jours)) : '-';
                    ?></td>
                </tr>
                <!-- Type -->
                <tr>
                    <th class="table-light">Type</th>
                    <?php foreach ($chunks as $chunk): ?>
                    <td class="small"><?= e($chunk['type_propriete'] ?? '-') ?></td>
                    <?php endforeach; ?>
                    <td class="table-warning"></td>
                </tr>
                <!-- Année construction -->
                <tr>
                    <th class="table-light">Année constr.</th>
                    <?php foreach ($chunks as $chunk): ?>
                    <td class="text-center"><?= $chunk['annee_construction'] ?? '-' ?></td>
                    <?php endforeach; ?>
                    <td class="table-warning text-center"><?php
                        $annees = array_filter(array_column($chunks, 'annee_construction'));
                        echo count($annees) > 0 ? round(array_sum($annees) / count($annees)) : '-';
                    ?></td>
                </tr>
                <!-- Chambres -->
                <tr>
                    <th class="table-light">Chambres</th>
                    <?php foreach ($chunks as $chunk): ?>
                    <td class="text-center"><?= $chunk['chambres'] ?? '-' ?></td>
                    <?php endforeach; ?>
                    <td class="table-warning text-center"><?php
                        $ch = array_filter(array_column($chunks, 'chambres'));
                        echo count($ch) > 0 ? number_format(array_sum($ch) / count($ch), 1) : '-';
                    ?></td>
                </tr>
                <!-- SdB -->
                <tr>
                    <th class="table-light">Salles de bain</th>
                    <?php foreach ($chunks as $chunk): ?>
                    <td class="text-center"><?= $chunk['sdb'] ?? '-' ?></td>
                    <?php endforeach; ?>
                    <td class="table-warning text-center"><?php
                        $sdb = array_filter(array_column($chunks, 'sdb'));
                        echo count($sdb) > 0 ? number_format(array_sum($sdb) / count($sdb), 1) : '-';
                    ?></td>
                </tr>
                <!-- Superficie terrain -->
                <tr>
                    <th class="table-light">Sup. terrain</th>
                    <?php foreach ($chunks as $chunk): ?>
                    <td class="text-center"><?= $chunk['superficie_terrain'] ?: '-' ?></td>
                    <?php endforeach; ?>
                    <td class="table-warning"></td>
                </tr>
                <!-- Superficie bâtiment -->
                <tr>
                    <th class="table-light">Sup. bâtiment</th>
                    <?php foreach ($chunks as $chunk): ?>
                    <td class="text-center"><?= $chunk['superficie_batiment'] ?: '-' ?></td>
                    <?php endforeach; ?>
                    <td class="table-warning"></td>
                </tr>
                <!-- Évaluation municipale -->
                <tr class="table-info">
                    <th>Éval. municipale</th>
                    <?php foreach ($chunks as $chunk): ?>
                    <td class="text-end"><?= $chunk['eval_total'] ? formatMoney($chunk['eval_total']) : '-' ?></td>
                    <?php endforeach; ?>
                    <td class="table-warning text-end"><?php
                        $eval = array_filter(array_column($chunks, 'eval_total'));
                        echo count($eval) > 0 ? formatMoney(array_sum($eval) / count($eval)) : '-';
                    ?></td>
                </tr>
                <!-- Taxes -->
                <tr>
                    <th class="table-light">Taxes totales</th>
                    <?php foreach ($chunks as $chunk): ?>
                    <td class="text-end"><?= ($chunk['taxe_municipale'] || $chunk['taxe_scolaire']) ? formatMoney(($chunk['taxe_municipale'] ?? 0) + ($chunk['taxe_scolaire'] ?? 0)) : '-' ?></td>
                    <?php endforeach; ?>
                    <td class="table-warning text-end"><?php
                        $taxes = array_map(fn($c) => ($c['taxe_municipale'] ?? 0) + ($c['taxe_scolaire'] ?? 0), $chunks);
                        $taxes = array_filter($taxes);
                        echo count($taxes) > 0 ? formatMoney(array_sum($taxes) / count($taxes)) : '-';
                    ?></td>
                </tr>
                <!-- Garage -->
                <tr>
                    <th class="table-light">Garage</th>
                    <?php foreach ($chunks as $chunk): ?>
                    <td class="text-center"><?= e($chunk['garage'] ?? '-') ?></td>
                    <?php endforeach; ?>
                    <td class="table-warning"></td>
                </tr>
                <!-- Piscine -->
                <tr>
                    <th class="table-light">Piscine</th>
                    <?php foreach ($chunks as $chunk): ?>
                    <td class="text-center"><?= $chunk['piscine'] ? 'Oui' : '-' ?></td>
                    <?php endforeach; ?>
                    <td class="table-warning"></td>
                </tr>
                <!-- Sous-sol -->
                <tr>
                    <th class="table-light">Sous-sol</th>
                    <?php foreach ($chunks as $chunk): ?>
                    <td class="text-center"><?= $chunk['sous_sol'] ? 'Oui' : '-' ?></td>
                    <?php endforeach; ?>
                    <td class="table-warning"></td>
                </tr>
                <!-- Fondation -->
                <tr>
                    <th class="table-light">Fondation</th>
                    <?php foreach ($chunks as $chunk): ?>
                    <td class="small"><?= e($chunk['fondation'] ?? '-') ?></td>
                    <?php endforeach; ?>
                    <td class="table-warning"></td>
                </tr>
                <!-- Toiture -->
                <tr>
                    <th class="table-light">Toiture</th>
                    <?php foreach ($chunks as $chunk): ?>
                    <td class="small"><?= e($chunk['toiture'] ?? '-') ?></td>
                    <?php endforeach; ?>
                    <td class="table-warning"></td>
                </tr>
                <!-- Chauffage -->
                <tr>
                    <th class="table-light">Chauffage</th>
                    <?php foreach ($chunks as $chunk): ?>
                    <td class="small"><?= e($chunk['chauffage'] ?? '-') ?></td>
                    <?php endforeach; ?>
                    <td class="table-warning"></td>
                </tr>
                <!-- Rénovations -->
                <tr class="table-success">
                    <th>Rénovations</th>
                    <?php foreach ($chunks as $chunk): ?>
                    <td class="text-end"><?= $chunk['renovations_total'] ? formatMoney($chunk['renovations_total']) : '-' ?></td>
                    <?php endforeach; ?>
                    <td class="table-warning text-end"><?php
                        $renos = array_filter(array_column($chunks, 'renovations_total'));
                        echo count($renos) > 0 ? formatMoney(array_sum($renos) / count($renos)) : '-';
                    ?></td>
                </tr>
                <!-- SECTION IA -->
                <tr class="table-dark">
                    <th colspan="<?= count($chunks) + 2 ?>"><i class="bi bi-robot me-2"></i>Analyse IA</th>
                </tr>
                <!-- Note IA -->
                <tr>
                    <th class="table-light">Note état</th>
                    <?php foreach ($chunks as $chunk): ?>
                    <td class="text-center">
                        <?php if ($chunk['statut'] === 'done'): ?>
                        <span class="badge bg-<?= $chunk['etat_note'] >= 7 ? 'success' : ($chunk['etat_note'] >= 5 ? 'warning' : 'danger') ?>"><?= number_format($chunk['etat_note'], 1) ?>/10</span>
                        <?php else: ?><span class="badge bg-secondary">-</span><?php endif; ?>
                    </td>
                    <?php endforeach; ?>
                    <td class="table-warning text-center"><?php
                        $done = array_filter($chunks, fn($c) => $c['statut'] === 'done');
                        if (count($done) > 0):
                            $avg = array_sum(array_column($done, 'etat_note')) / count($done);
                            ?><span class="badge bg-<?= $avg >= 7 ? 'success' : ($avg >= 5 ? 'warning' : 'danger') ?>"><?= number_format($avg, 1) ?></span><?php
                        else: echo '-'; endif;
                    ?></td>
                </tr>
                <!-- Ajustement -->
                <tr>
                    <th class="table-light">Ajustement</th>
                    <?php foreach ($chunks as $chunk): ?>
                    <td class="text-end">
                        <?php if ($chunk['statut'] === 'done'): ?>
                        <span class="fw-bold <?= $chunk['ajustement'] >= 0 ? 'text-success' : 'text-danger' ?>"><?= $chunk['ajustement'] >= 0 ? '+' : '' ?><?= formatMoney($chunk['ajustement']) ?></span>
                        <?php else: ?>-<?php endif; ?>
                    </td>
                    <?php endforeach; ?>
                    <td class="table-warning text-end"><?php
                        if (count($done) > 0):
                            $avgAdj = array_sum(array_column($done, 'ajustement')) / count($done);
                            ?><span class="fw-bold <?= $avgAdj >= 0 ? 'text-success' : 'text-danger' ?>"><?= $avgAdj >= 0 ? '+' : '' ?><?= formatMoney($avgAdj) ?></span><?php
                        else: echo '-'; endif;
                    ?></td>
                </tr>
                <!-- Confiance -->
                <tr>
                    <th class="table-light">Confiance</th>
                    <?php foreach ($chunks as $chunk): ?>
                    <td class="text-center">
                        <?php if ($chunk['statut'] === 'done'): $conf = $chunk['confiance'] ?? 0; ?>
                        <span class="badge bg-<?= $conf >= 70 ? 'success' : ($conf >= 40 ? 'warning' : 'danger') ?>"><?= $conf ?>%</span>
                        <?php else: ?>-<?php endif; ?>
                    </td>
                    <?php endforeach; ?>
                    <td class="table-warning text-center"><?php
                        if (count($done) > 0):
                            $avgConf = array_sum(array_column($done, 'confiance')) / count($done);
                            ?><span class="badge bg-<?= $avgConf >= 70 ? 'success' : ($avgConf >= 40 ? 'warning' : 'danger') ?>"><?= round($avgConf) ?>%</span><?php
                        else: echo '-'; endif;
                    ?></td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Détails des commentaires IA (optionnel, en accordéon) -->
    <?php if (count(array_filter($chunks, fn($c) => $c['statut'] === 'done' && $c['commentaire_ia'])) > 0): ?>
    <div class="accordion mt-3" id="accordionCommentaires">
        <div class="accordion-item">
            <h2 class="accordion-header">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseCommentaires">
                    <i class="bi bi-chat-left-text me-2"></i>Commentaires IA détaillés
                </button>
            </h2>
            <div id="collapseCommentaires" class="accordion-collapse collapse" data-bs-parent="#accordionCommentaires">
                <div class="accordion-body">
                    <?php foreach ($chunks as $index => $chunk): ?>
                        <?php if ($chunk['statut'] === 'done' && $chunk['commentaire_ia']): ?>
                        <div class="mb-3 p-2 bg-light rounded">
                            <strong>#<?= $index + 1 ?> - <?= e($chunk['no_centris']) ?></strong>
                            <p class="mb-0 small"><?= e($chunk['commentaire_ia']) ?></p>
                        </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

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

    // Sélectionner les headers des chunks qui ne sont pas encore analysés
    const chunkHeaders = document.querySelectorAll('.chunk-header[data-status="pending"]');
    const totalChunks = <?= count($chunks) ?>;
    let doneCount = <?= $chunksDone ?>;

    if (chunkHeaders.length === 0) {
        btn.innerHTML = 'Aucun chunk à analyser';
        location.reload();
        return;
    }

    for (const header of chunkHeaders) {
        const chunkId = header.dataset.chunkId;

        // Ajouter un indicateur de chargement
        const originalHTML = header.innerHTML;
        header.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Analyse...';

        try {
            const response = await fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=analyze_chunk&chunk_id=${chunkId}`
            });

            const data = await response.json();

            if (data.success) {
                header.dataset.status = 'done';
                header.innerHTML = originalHTML.replace('Comp.', '<span class="badge bg-success me-1">✓</span>Comp.');
                doneCount++;

                // Mettre à jour la progression
                const progress = Math.round((doneCount / totalChunks) * 100);
                document.getElementById('progressBar').style.width = progress + '%';
                document.getElementById('progressText').textContent = doneCount + '/' + totalChunks;

                // Recharger la page si tous sont terminés
                if (data.remaining === 0) {
                    location.reload();
                }
            } else {
                header.innerHTML = originalHTML + ' <span class="badge bg-danger">Erreur</span>';
                console.error('Erreur:', data.error);
                alert('Erreur: ' + data.error);
            }
        } catch (err) {
            header.innerHTML = originalHTML + ' <span class="badge bg-danger">Erreur</span>';
            console.error('Erreur réseau:', err);
            alert('Erreur réseau: ' + err.message);
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
