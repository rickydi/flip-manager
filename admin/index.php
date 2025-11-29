<?php
/**
 * Dashboard Admin
 * Flip Manager
 */

require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/calculs.php';

// Vérifier que l'utilisateur est admin
requireAdmin();

$pageTitle = 'Tableau de bord';

// Récupérer les projets actifs
$projets = getProjets($pdo);

// Statistiques globales
$stmt = $pdo->query("SELECT COUNT(*) FROM projets WHERE statut != 'archive'");
$totalProjets = $stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) FROM factures WHERE statut = 'en_attente'");
$facturesEnAttente = $stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) FROM factures WHERE statut = 'approuvee'");
$facturesApprouvees = $stmt->fetchColumn();

$stmt = $pdo->query("SELECT SUM(montant_total) FROM factures WHERE statut = 'approuvee'");
$totalDepenses = $stmt->fetchColumn() ?: 0;

// Factures en attente d'approbation
$stmt = $pdo->query("
    SELECT f.*, p.nom as projet_nom, c.nom as categorie_nom, 
           CONCAT(u.prenom, ' ', u.nom) as employe_nom
    FROM factures f
    JOIN projets p ON f.projet_id = p.id
    JOIN categories c ON f.categorie_id = c.id
    JOIN users u ON f.user_id = u.id
    WHERE f.statut = 'en_attente'
    ORDER BY f.date_creation ASC
    LIMIT 10
");
$facturesAttente = $stmt->fetchAll();

include '../includes/header.php';
?>

<div class="container-fluid">
    <!-- En-tête -->
    <div class="page-header">
        <h1><i class="bi bi-speedometer2 me-2"></i>Tableau de bord</h1>
        <p class="text-muted">Vue d'ensemble de vos projets de flip</p>
    </div>
    
    <?php displayFlashMessage(); ?>
    
    <!-- Statistiques globales -->
    <div class="stats-grid">
        <div class="stat-card primary">
            <div class="stat-value"><?= $totalProjets ?></div>
            <div class="stat-label">Projets actifs</div>
        </div>
        <div class="stat-card warning">
            <div class="stat-value"><?= $facturesEnAttente ?></div>
            <div class="stat-label">Factures en attente</div>
        </div>
        <div class="stat-card success">
            <div class="stat-value"><?= $facturesApprouvees ?></div>
            <div class="stat-label">Factures approuvées</div>
        </div>
        <div class="stat-card info">
            <div class="stat-value"><?= formatMoney($totalDepenses) ?></div>
            <div class="stat-label">Dépenses totales</div>
        </div>
    </div>
    
    <!-- Projets actifs -->
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4><i class="bi bi-building me-2"></i>Projets actifs</h4>
        <a href="/admin/projets/nouveau.php" class="btn btn-primary">
            <i class="bi bi-plus-circle me-1"></i>
            Nouveau projet
        </a>
    </div>
    
    <?php if (empty($projets)): ?>
        <div class="card mb-4">
            <div class="card-body">
                <div class="empty-state">
                    <i class="bi bi-building"></i>
                    <h4>Aucun projet</h4>
                    <p>Commencez par créer votre premier projet de flip.</p>
                    <a href="/admin/projets/nouveau.php" class="btn btn-primary">
                        <i class="bi bi-plus-circle me-1"></i>
                        Créer un projet
                    </a>
                </div>
            </div>
        </div>
    <?php else: ?>
        <?php foreach ($projets as $projet): 
            $indicateurs = calculerIndicateursProjet($pdo, $projet);
        ?>
            <div class="card projet-card" style="cursor: pointer;" onclick="window.location='/admin/projets/detail.php?id=<?= $projet['id'] ?>'">
                <div class="card-header">
                    <div>
                        <h5 class="mb-0"><?= e($projet['nom']) ?></h5>
                        <small class="text-muted">
                            <i class="bi bi-geo-alt me-1"></i>
                            <?= e($projet['adresse']) ?>, <?= e($projet['ville']) ?>
                        </small>
                    </div>
                    <span class="badge statut-badge <?= getStatutProjetClass($projet['statut']) ?>">
                        <?= getStatutProjetLabel($projet['statut']) ?>
                    </span>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-3 col-6 mb-3">
                            <div class="indicator-card primary">
                                <small class="text-muted">Coûts fixes</small>
                                <h5 class="mb-0"><?= formatMoney($indicateurs['couts_fixes_totaux']) ?></h5>
                                <small class="text-muted"><?= formatPercent($indicateurs['pourcentages']['couts_fixes']) ?></small>
                            </div>
                        </div>
                        <div class="col-md-3 col-6 mb-3">
                            <div class="indicator-card warning">
                                <small class="text-muted">Rénovation</small>
                                <h5 class="mb-0"><?= formatMoney($indicateurs['renovation']['budget']) ?></h5>
                                <small class="text-muted"><?= formatPercent($indicateurs['pourcentages']['renovation']) ?></small>
                            </div>
                        </div>
                        <div class="col-md-3 col-6 mb-3">
                            <div class="indicator-card success">
                                <small class="text-muted">Équité potentielle</small>
                                <h5 class="mb-0"><?= formatMoney($indicateurs['equite_potentielle']) ?></h5>
                            </div>
                        </div>
                        <div class="col-md-3 col-6 mb-3">
                            <div class="indicator-card info">
                                <small class="text-muted">ROI</small>
                                <h5 class="mb-0"><?= formatPercent($indicateurs['roi_leverage']) ?></h5>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Barre de progression -->
                    <div class="mb-3">
                        <div class="d-flex justify-content-between mb-1">
                            <small class="text-muted">Budget rénovation</small>
                            <small class="text-muted">
                                <?= formatMoney($indicateurs['renovation']['reel']) ?> / <?= formatMoney($indicateurs['renovation']['budget']) ?>
                                (<?= number_format($indicateurs['renovation']['progression'], 1) ?>%)
                            </small>
                        </div>
                        <div class="progress">
                            <div class="progress-bar <?= $indicateurs['renovation']['progression'] > 100 ? 'bg-danger' : 'bg-primary' ?>" 
                                 style="width: <?= min(100, $indicateurs['renovation']['progression']) ?>%">
                            </div>
                        </div>
                    </div>
                    
                    <!-- Actions -->
                    <div class="d-flex gap-2 flex-wrap" onclick="event.stopPropagation()">
                        <a href="/admin/factures/liste.php?projet=<?= $projet['id'] ?>" class="btn btn-outline-secondary btn-sm">
                            <i class="bi bi-receipt me-1"></i>Factures
                        </a>
                        <a href="/admin/projets/financement.php?id=<?= $projet['id'] ?>" class="btn btn-outline-primary btn-sm">
                            <i class="bi bi-bank me-1"></i>Financement
                        </a>
                        <a href="/admin/projets/modifier.php?id=<?= $projet['id'] ?>" class="btn btn-outline-secondary btn-sm">
                            <i class="bi bi-pencil me-1"></i>Modifier
                        </a>
                        <button type="button" class="btn btn-outline-danger btn-sm" 
                                data-bs-toggle="modal" data-bs-target="#deleteModal<?= $projet['id'] ?>">
                            <i class="bi bi-trash me-1"></i>
                            Supprimer
                        </button>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
    
    <!-- Factures en attente -->
    <?php if (!empty($facturesAttente)): ?>
        <div class="card mt-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>
                    <i class="bi bi-clock-history me-2"></i>
                    Factures en attente d'approbation
                    <span class="badge bg-danger ms-1"><?= count($facturesAttente) ?></span>
                </span>
                <a href="/admin/factures/approuver.php" class="btn btn-outline-primary btn-sm">
                    Voir tout
                </a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Projet</th>
                                <th>Fournisseur</th>
                                <th>Catégorie</th>
                                <th>Employé</th>
                                <th class="text-end">Montant</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($facturesAttente as $facture): ?>
                                <tr>
                                    <td><?= formatDate($facture['date_facture']) ?></td>
                                    <td><?= e($facture['projet_nom']) ?></td>
                                    <td><?= e($facture['fournisseur']) ?></td>
                                    <td><?= e($facture['categorie_nom']) ?></td>
                                    <td><?= e($facture['employe_nom']) ?></td>
                                    <td class="text-end"><strong><?= formatMoney($facture['montant_total']) ?></strong></td>
                                    <td class="action-buttons">
                                        <a href="/admin/factures/approuver.php?action=approuver&id=<?= $facture['id'] ?>" 
                                           class="btn btn-success btn-sm"
                                           title="Approuver">
                                            <i class="bi bi-check-lg"></i>
                                        </a>
                                        <a href="/admin/factures/approuver.php?action=rejeter&id=<?= $facture['id'] ?>" 
                                           class="btn btn-danger btn-sm"
                                           title="Rejeter">
                                            <i class="bi bi-x-lg"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Modals de suppression -->
<?php if (!empty($projets)): ?>
    <?php foreach ($projets as $projet): ?>
    <div class="modal fade" id="deleteModal<?= $projet['id'] ?>" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">
                        <i class="bi bi-exclamation-triangle me-2"></i>Confirmer la suppression
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Supprimer le projet <strong><?= e($projet['nom']) ?></strong> ?</p>
                    <div class="alert alert-warning mb-0">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        Toutes les factures et budgets seront supprimés.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                    <form action="/admin/projets/supprimer.php" method="POST" class="d-inline">
                        <?php csrfField(); ?>
                        <input type="hidden" name="projet_id" value="<?= $projet['id'] ?>">
                        <button type="submit" class="btn btn-danger">
                            <i class="bi bi-trash me-1"></i>Supprimer
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php include '../includes/footer.php'; ?>
