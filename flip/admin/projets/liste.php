<?php
/**
 * Liste des projets - Admin
 * Flip Manager
 */

require_once '../../config.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../includes/calculs.php';

// Vérifier que l'utilisateur est admin
requireAdmin();

$pageTitle = 'Liste des projets';

// Filtres
$filtreStatut = isset($_GET['statut']) ? $_GET['statut'] : '';
$showArchives = isset($_GET['archives']) && $_GET['archives'] == '1';

// Construire la requête
$where = "WHERE 1=1";
$params = [];

if ($filtreStatut !== '') {
    $where .= " AND statut = ?";
    $params[] = $filtreStatut;
} elseif (!$showArchives) {
    $where .= " AND statut != 'archive'";
}

// Récupérer les projets
$sql = "SELECT * FROM projets $where ORDER BY date_creation DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$projets = $stmt->fetchAll();

include '../../includes/header.php';
?>

<div class="container-fluid">
    <!-- En-tête -->
    <div class="page-header">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= url('/admin/index.php') ?>">Tableau de bord</a></li>
                <li class="breadcrumb-item active">Projets</li>
            </ol>
        </nav>
        <div class="d-flex justify-content-between align-items-center">
            <h1><i class="bi bi-building me-2"></i>Projets</h1>
            <a href="<?= url('/admin/projets/nouveau.php') ?>" class="btn btn-primary">
                <i class="bi bi-plus-circle me-1"></i>
                Nouveau projet
            </a>
        </div>
    </div>
    
    <?php displayFlashMessage(); ?>
    
    <!-- Filtres -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" action="" class="row g-3">
                <div class="col-md-4">
                    <label for="statut" class="form-label">Statut</label>
                    <select class="form-select" id="statut" name="statut">
                        <option value="">Tous les statuts</option>
                        <option value="acquisition" <?= $filtreStatut === 'acquisition' ? 'selected' : '' ?>>Acquisition</option>
                        <option value="renovation" <?= $filtreStatut === 'renovation' ? 'selected' : '' ?>>Rénovation</option>
                        <option value="vente" <?= $filtreStatut === 'vente' ? 'selected' : '' ?>>En vente</option>
                        <option value="vendu" <?= $filtreStatut === 'vendu' ? 'selected' : '' ?>>Vendu</option>
                        <option value="archive" <?= $filtreStatut === 'archive' ? 'selected' : '' ?>>Archivé</option>
                    </select>
                </div>
                <div class="col-md-4 d-flex align-items-end">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="archives" name="archives" 
                               value="1" <?= $showArchives ? 'checked' : '' ?>>
                        <label class="form-check-label" for="archives">
                            Afficher les projets archivés
                        </label>
                    </div>
                </div>
                <div class="col-md-4 d-flex align-items-end">
                    <button type="submit" class="btn btn-outline-primary me-2">
                        <i class="bi bi-search me-1"></i>
                        Filtrer
                    </button>
                    <a href="<?= url('/admin/projets/liste.php') ?>" class="btn btn-outline-secondary">
                        <i class="bi bi-x-circle me-1"></i>
                        Réinitialiser
                    </a>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Liste des projets -->
    <div class="card">
        <div class="card-header">
            <span><?= count($projets) ?> projet(s)</span>
        </div>
        <div class="card-body p-0">
            <?php if (empty($projets)): ?>
                <div class="empty-state">
                    <i class="bi bi-building"></i>
                    <h4>Aucun projet</h4>
                    <p>Aucun projet ne correspond à vos critères.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Projet</th>
                                <th>Adresse</th>
                                <th>Statut</th>
                                <th class="text-end">Prix d'achat</th>
                                <th class="text-end">Valeur potentielle</th>
                                <th class="text-end">Rénovation</th>
                                <th class="text-end">Équité</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($projets as $projet): 
                                $indicateurs = calculerIndicateursProjet($pdo, $projet);
                            ?>
                                <tr style="cursor: pointer;" onclick="window.location='/admin/projets/detail.php?id=<?= $projet['id'] ?>'">
                                    <td>
                                        <strong><?= e($projet['nom']) ?></strong>
                                        <br>
                                        <small class="text-muted">
                                            Créé le <?= formatDate($projet['date_creation']) ?>
                                        </small>
                                    </td>
                                    <td>
                                        <?= e($projet['adresse']) ?>
                                        <br>
                                        <small class="text-muted"><?= e($projet['ville']) ?></small>
                                    </td>
                                    <td>
                                        <span class="badge <?= getStatutProjetClass($projet['statut']) ?>">
                                            <?= getStatutProjetLabel($projet['statut']) ?>
                                        </span>
                                    </td>
                                    <td class="text-end"><?= formatMoney($projet['prix_achat']) ?></td>
                                    <td class="text-end"><?= formatMoney($projet['valeur_potentielle']) ?></td>
                                    <td class="text-end">
                                        <?= formatMoney($indicateurs['renovation']['reel'] + $indicateurs['main_doeuvre']['cout']) ?>
                                        <br>
                                        <small class="text-muted">/ <?= formatMoney($indicateurs['renovation']['budget']) ?></small>
                                        <?php if ($indicateurs['main_doeuvre']['cout'] > 0): ?>
                                            <br><small class="text-info"><i class="bi bi-person-fill"></i> <?= formatMoney($indicateurs['main_doeuvre']['cout']) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <strong class="<?= $indicateurs['equite_potentielle'] >= 0 ? 'text-success' : 'text-danger' ?>">
                                            <?= formatMoney($indicateurs['equite_potentielle']) ?>
                                        </strong>
                                    </td>
                                    <td class="action-buttons" onclick="event.stopPropagation()">
                                        <a href="<?= url('/admin/projets/detail.php?id=<?= $projet['id'] ?>" 
                                           class="btn btn-outline-primary btn-sm"
                                           title="Voir détails">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        <a href="<?= url('/admin/projets/modifier.php?id=<?= $projet['id'] ?>" 
                                           class="btn btn-outline-secondary btn-sm"
                                           title="Modifier">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
