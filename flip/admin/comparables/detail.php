<?php
/**
 * Détail d'une analyse de marché (IA)
 * Flip Manager
 */

require_once '../../config.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

requireAdmin();

$analyseId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Récupérer l'analyse
$stmt = $pdo->prepare("
    SELECT a.*, p.nom as projet_nom, p.adresse as projet_adresse
    FROM analyses_marche a 
    LEFT JOIN projets p ON a.projet_id = p.id 
    WHERE a.id = ?
");
$stmt->execute([$analyseId]);
$analyse = $stmt->fetch();

if (!$analyse) {
    setFlashMessage('danger', 'Analyse introuvable.');
    redirect('/admin/comparables/index.php');
}

$pageTitle = 'Rapport: ' . $analyse['nom_rapport'];

// Récupérer les items (comparables)
$stmt = $pdo->prepare("SELECT * FROM comparables_items WHERE analyse_id = ? ORDER BY prix_vendu DESC");
$stmt->execute([$analyseId]);
$items = $stmt->fetchAll();

include '../../includes/header.php';
?>

<div class="container-fluid">
    <div class="page-header d-flex justify-content-between align-items-center no-print">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="<?= url('/admin/index.php') ?>">Tableau de bord</a></li>
                    <li class="breadcrumb-item"><a href="<?= url('/admin/comparables/index.php') ?>">Comparables & IA</a></li>
                    <li class="breadcrumb-item active"><?= e($analyse['nom_rapport']) ?></li>
                </ol>
            </nav>
            <h1><i class="bi bi-file-earmark-bar-graph me-2"></i>Rapport d'analyse</h1>
        </div>
        <div class="d-flex gap-2">
            <form method="POST" action="index.php" onsubmit="return confirm('Supprimer cette analyse définitivement ?');">
                <?php csrfField(); ?>
                <input type="hidden" name="action" value="supprimer">
                <input type="hidden" name="id" value="<?= $analyseId ?>">
                <button type="submit" class="btn btn-danger">
                    <i class="bi bi-trash me-1"></i>Supprimer
                </button>
            </form>
            <button onclick="window.print()" class="btn btn-outline-secondary">
                <i class="bi bi-printer me-1"></i>Imprimer
            </button>
            <a href="index.php" class="btn btn-secondary">Retour</a>
        </div>
    </div>

    <!-- Résumé IA -->
    <div class="row mb-4">
        <div class="col-lg-8">
            <div class="card h-100 border-primary">
                <div class="card-header bg-primary text-white">
                    <i class="bi bi-robot me-2"></i>Analyse de l'IA (Claude)
                </div>
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <div>
                            <h5 class="card-title text-primary">Prix Suggéré : <?= formatMoney($analyse['prix_suggere_ia']) ?></h5>
                            <h6 class="card-subtitle text-muted">
                                Fourchette : <?= formatMoney($analyse['fourchette_basse']) ?> - <?= formatMoney($analyse['fourchette_haute']) ?>
                            </h6>
                        </div>
                        <span class="badge bg-info text-dark">Date : <?= formatDateTime($analyse['date_analyse']) ?></span>
                    </div>
                    <div class="card-text" style="white-space: pre-line;">
                        <?= nl2br(e($analyse['analyse_ia_texte'])) ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card h-100">
                <div class="card-header">
                    <i class="bi bi-info-circle me-2"></i>Détails du sujet
                </div>
                <div class="card-body">
                    <p><strong>Projet :</strong> <?= e($analyse['projet_nom'] ?? 'N/A') ?></p>
                    <p><strong>Adresse :</strong> <?= e($analyse['projet_adresse'] ?? 'N/A') ?></p>
                    <hr>
                    <p class="mb-1"><strong>Statistiques Comparables :</strong></p>
                    <ul class="list-unstyled">
                        <li>Nombre de vendus : <strong><?= count($items) ?></strong></li>
                        <li>Prix moyen : <strong><?= formatMoney($analyse['prix_moyen']) ?></strong></li>
                        <li>Prix médian : <strong><?= formatMoney($analyse['prix_median']) ?></strong></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <!-- Tableau des Comparables -->
    <div class="card">
        <div class="card-header">
            <i class="bi bi-houses me-2"></i>Propriétés comparées
        </div>
        <div class="table-responsive">
            <table class="table table-striped table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width: 20%;">Adresse</th>
                        <th style="width: 10%;">Prix Vendu</th>
                        <th style="width: 10%;">Vendu le</th>
                        <th style="width: 10%;">Délai</th>
                        <th style="width: 10%;">Caractéristiques</th>
                        <th style="width: 15%;">État (Note IA)</th>
                        <th style="width: 10%;" title="Montant ajouté ou soustrait au prix vendu pour équivaloir à votre projet (ex: -20k car il a un garage et vous non)">
                            Ajustement <i class="bi bi-info-circle text-muted small"></i>
                        </th>
                        <th style="width: 15%;">Commentaire</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                    <tr>
                        <td>
                            <strong><?= e($item['adresse']) ?></strong><br>
                            <small class="text-muted">Année <?= $item['annee_construction'] ?></small>
                        </td>
                        <td class="fw-bold text-primary"><?= formatMoney($item['prix_vendu']) ?></td>
                        <td><?= formatDate($item['date_vente']) ?></td>
                        <td><?= $item['delai_vente'] > 0 ? $item['delai_vente'] . ' jours' : '-' ?></td>
                        <td>
                            <small>
                                <i class="bi bi-door-closed me-1"></i><?= e($item['chambres']) ?> ch.<br>
                                <i class="bi bi-droplet me-1"></i><?= e($item['salles_bains']) ?> sdb<br>
                                <i class="bi bi-arrows-fullscreen me-1"></i><?= e($item['superficie_batiment']) ?>
                            </small>
                        </td>
                        <td>
                            <div class="d-flex align-items-center mb-1">
                                <span class="badge bg-<?= $item['etat_general_note'] >= 8 ? 'success' : ($item['etat_general_note'] >= 6 ? 'warning' : 'danger') ?> me-2">
                                    <?= $item['etat_general_note'] ?>/10
                                </span>
                                <small><?= e($item['etat_general_texte']) ?></small>
                            </div>
                            <?php if ($item['renovations_mentionnees']): ?>
                                <small class="text-muted d-block text-truncate" style="max-width: 150px;" title="<?= e($item['renovations_mentionnees']) ?>">
                                    Rénos: <?= e($item['renovations_mentionnees']) ?>
                                </small>
                            <?php endif; ?>
                        </td>
                        <td class="<?= $item['ajustement_ia'] > 0 ? 'text-success' : ($item['ajustement_ia'] < 0 ? 'text-danger' : 'text-muted') ?>">
                            <?= $item['ajustement_ia'] > 0 ? '+' : '' ?><?= formatMoney($item['ajustement_ia']) ?>
                        </td>
                        <td>
                            <small class="text-muted"><?= e($item['commentaire_ia']) ?></small>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
