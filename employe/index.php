<?php
/**
 * Dashboard Employé
 * Flip Manager
 */

require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

// Vérifier que l'utilisateur est connecté
requireLogin();

$pageTitle = 'Tableau de bord';

// Récupérer les projets actifs
$projets = getProjets($pdo);

// Récupérer les dernières factures de l'employé
$userId = getCurrentUserId();
$stmt = $pdo->prepare("
    SELECT f.*, p.nom as projet_nom, c.nom as categorie_nom
    FROM factures f
    JOIN projets p ON f.projet_id = p.id
    JOIN categories c ON f.categorie_id = c.id
    WHERE f.user_id = ?
    ORDER BY f.date_creation DESC
    LIMIT 10
");
$stmt->execute([$userId]);
$mesFactures = $stmt->fetchAll();

// Statistiques
$stmt = $pdo->prepare("SELECT COUNT(*) FROM factures WHERE user_id = ?");
$stmt->execute([$userId]);
$totalFactures = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM factures WHERE user_id = ? AND statut = 'en_attente'");
$stmt->execute([$userId]);
$facturesEnAttente = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM factures WHERE user_id = ? AND statut = 'approuvee'");
$stmt->execute([$userId]);
$facturesApprouvees = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT SUM(montant_total) FROM factures WHERE user_id = ? AND statut = 'approuvee'");
$stmt->execute([$userId]);
$totalMontant = $stmt->fetchColumn() ?: 0;

include '../includes/header.php';
?>

<div class="container-fluid">
    <!-- En-tête -->
    <div class="page-header">
        <h1><i class="bi bi-speedometer2 me-2"></i>Tableau de bord</h1>
        <p class="text-muted">Bonjour, <?= e(getCurrentUserName()) ?></p>
    </div>
    
    <?php displayFlashMessage(); ?>
    
    <!-- Statistiques -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value"><?= $totalFactures ?></div>
            <div class="stat-label">Factures soumises</div>
        </div>
        <div class="stat-card warning">
            <div class="stat-value"><?= $facturesEnAttente ?></div>
            <div class="stat-label">En attente</div>
        </div>
        <div class="stat-card success">
            <div class="stat-value"><?= $facturesApprouvees ?></div>
            <div class="stat-label">Approuvées</div>
        </div>
        <div class="stat-card primary">
            <div class="stat-value"><?= formatMoney($totalMontant) ?></div>
            <div class="stat-label">Total approuvé</div>
        </div>
    </div>
    
    <!-- Projets actifs -->
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-building me-2"></i>Projets actifs</span>
        </div>
        <div class="card-body">
            <?php if (empty($projets)): ?>
                <div class="empty-state">
                    <i class="bi bi-building"></i>
                    <h4>Aucun projet actif</h4>
                    <p>Il n'y a pas de projet en cours pour le moment.</p>
                </div>
            <?php else: ?>
                <div class="row">
                    <?php foreach ($projets as $projet): ?>
                        <div class="col-md-6 col-lg-4 mb-3">
                            <div class="card h-100">
                                <div class="card-body">
                                    <h5 class="card-title">
                                        <i class="bi bi-geo-alt me-1"></i>
                                        <?= e($projet['nom']) ?>
                                    </h5>
                                    <p class="card-text text-muted mb-2">
                                        <?= e($projet['adresse']) ?>, <?= e($projet['ville']) ?>
                                    </p>
                                    <span class="badge <?= getStatutProjetClass($projet['statut']) ?>">
                                        <?= getStatutProjetLabel($projet['statut']) ?>
                                    </span>
                                </div>
                                <div class="card-footer bg-transparent">
                                    <a href="/employe/nouvelle-facture.php?projet_id=<?= $projet['id'] ?>" 
                                       class="btn btn-primary btn-sm">
                                        <i class="bi bi-plus-circle me-1"></i>
                                        Nouvelle facture
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Dernières factures -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-receipt me-2"></i>Mes dernières factures</span>
            <a href="/employe/mes-factures.php" class="btn btn-outline-primary btn-sm">
                Voir tout
            </a>
        </div>
        <div class="card-body p-0">
            <?php if (empty($mesFactures)): ?>
                <div class="empty-state">
                    <i class="bi bi-receipt"></i>
                    <h4>Aucune facture</h4>
                    <p>Vous n'avez pas encore soumis de facture.</p>
                    <a href="/employe/nouvelle-facture.php" class="btn btn-primary">
                        <i class="bi bi-plus-circle me-1"></i>
                        Soumettre une facture
                    </a>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Projet</th>
                                <th>Fournisseur</th>
                                <th>Catégorie</th>
                                <th class="text-end">Montant</th>
                                <th class="text-center">Statut</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($mesFactures as $facture): ?>
                                <tr>
                                    <td><?= formatDate($facture['date_facture']) ?></td>
                                    <td><?= e($facture['projet_nom']) ?></td>
                                    <td><?= e($facture['fournisseur']) ?></td>
                                    <td><?= e($facture['categorie_nom']) ?></td>
                                    <td class="text-end"><?= formatMoney($facture['montant_total']) ?></td>
                                    <td class="text-center">
                                        <span class="badge <?= getStatutFactureClass($facture['statut']) ?>">
                                            <?= getStatutFactureIcon($facture['statut']) ?>
                                            <?= getStatutFactureLabel($facture['statut']) ?>
                                        </span>
                                    </td>
                                    <td class="action-buttons">
                                        <?php if ($facture['statut'] === 'en_attente' && canEditFacture($facture['date_creation'])): ?>
                                            <a href="/employe/modifier-facture.php?id=<?= $facture['id'] ?>" 
                                               class="btn btn-outline-primary btn-sm"
                                               title="Modifier">
                                                <i class="bi bi-pencil"></i>
                                            </a>
                                        <?php endif; ?>
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

<?php include '../includes/footer.php'; ?>
