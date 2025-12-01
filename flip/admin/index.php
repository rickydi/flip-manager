<?php
/**
 * Dashboard Admin - Design moderne
 * Flip Manager
 */

require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/calculs.php';

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

// Factures en attente
$stmt = $pdo->query("
    SELECT f.*, p.nom as projet_nom, c.nom as categorie_nom, 
           CONCAT(u.prenom, ' ', u.nom) as employe_nom
    FROM factures f
    JOIN projets p ON f.projet_id = p.id
    JOIN categories c ON f.categorie_id = c.id
    JOIN users u ON f.user_id = u.id
    WHERE f.statut = 'en_attente'
    ORDER BY f.date_creation ASC
    LIMIT 5
");
$facturesAttente = $stmt->fetchAll();

include '../includes/header.php';
?>

<style>
/* Dashboard moderne */
.dashboard-header {
    background: linear-gradient(135deg, var(--primary-color) 0%, #1d4ed8 100%);
    color: white;
    padding: 2rem;
    border-radius: 1rem;
    margin-bottom: 2rem;
    position: relative;
    overflow: hidden;
}

.dashboard-header::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -20%;
    width: 50%;
    height: 200%;
    background: rgba(255,255,255,0.1);
    transform: rotate(25deg);
}

.dashboard-header h1 {
    margin: 0;
    font-size: 2rem;
    font-weight: 700;
}

.dashboard-header p {
    opacity: 0.9;
    margin: 0.5rem 0 0;
}

/* Stats cards modernes */
.stat-card-modern {
    background: var(--bg-card);
    border-radius: 1rem;
    padding: 1.5rem;
    display: flex;
    align-items: center;
    gap: 1rem;
    box-shadow: 0 4px 6px var(--shadow-color);
    transition: transform 0.2s, box-shadow 0.2s;
    height: 100%;
}

.stat-card-modern:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 15px var(--shadow-color);
}

.stat-icon {
    width: 60px;
    height: 60px;
    border-radius: 1rem;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    flex-shrink: 0;
}

.stat-icon.primary { background: rgba(37, 99, 235, 0.15); color: var(--primary-color); }
.stat-icon.warning { background: rgba(245, 158, 11, 0.15); color: var(--warning-color); }
.stat-icon.success { background: rgba(34, 197, 94, 0.15); color: var(--success-color); }
.stat-icon.info { background: rgba(6, 182, 212, 0.15); color: var(--info-color); }

.stat-content h3 {
    font-size: 1.75rem;
    font-weight: 700;
    margin: 0;
    color: var(--text-primary);
}

.stat-content p {
    margin: 0;
    color: var(--text-secondary);
    font-size: 0.875rem;
}

/* Project cards modernes */
.project-card {
    background: var(--bg-card);
    border-radius: 1rem;
    overflow: hidden;
    box-shadow: 0 4px 6px var(--shadow-color);
    transition: all 0.2s;
    cursor: pointer;
    height: 100%;
}

.project-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 12px 24px var(--shadow-color);
}

.project-card-header {
    padding: 1.25rem;
    border-bottom: 1px solid var(--border-color);
}

.project-card-header h5 {
    margin: 0 0 0.25rem;
    font-weight: 600;
    color: var(--text-primary);
}

.project-card-header small {
    color: var(--text-secondary);
}

.project-card-body {
    padding: 1.25rem;
}

.project-metrics {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 1rem;
    margin-bottom: 1rem;
}

.metric-item {
    text-align: center;
    padding: 0.75rem;
    background: var(--bg-table-hover);
    border-radius: 0.5rem;
}

.metric-value {
    font-size: 1.1rem;
    font-weight: 700;
    color: var(--text-primary);
}

.metric-label {
    font-size: 0.7rem;
    color: var(--text-secondary);
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

.metric-item.success .metric-value { color: var(--success-color); }
.metric-item.danger .metric-value { color: var(--danger-color); }
.metric-item.warning .metric-value { color: var(--warning-color); }
.metric-item.info .metric-value { color: var(--info-color); }

/* Section title */
.section-title {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1.5rem;
}

.section-title h4 {
    margin: 0;
    font-weight: 600;
    color: var(--text-primary);
}

/* Quick actions */
.quick-actions {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
    padding-top: 1rem;
    border-top: 1px solid var(--border-color);
}

/* Pending factures mini */
.pending-facture-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.75rem 0;
    border-bottom: 1px solid var(--border-color);
}

.pending-facture-item:last-child {
    border-bottom: none;
}

.pending-facture-info {
    flex: 1;
}

.pending-facture-info strong {
    color: var(--text-primary);
    display: block;
}

.pending-facture-info small {
    color: var(--text-secondary);
}

.pending-facture-amount {
    font-weight: 600;
    color: var(--text-primary);
    margin-right: 1rem;
}
</style>

<div class="container-fluid">
    <?php displayFlashMessage(); ?>
    
    <!-- Statistiques -->
    <div class="row mb-4">
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="stat-card-modern">
                <div class="stat-icon primary">
                    <i class="bi bi-building"></i>
                </div>
                <div class="stat-content">
                    <h3><?= $totalProjets ?></h3>
                    <p>Projets actifs</p>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="stat-card-modern">
                <div class="stat-icon warning">
                    <i class="bi bi-clock-history"></i>
                </div>
                <div class="stat-content">
                    <h3><?= $facturesEnAttente ?></h3>
                    <p>Factures en attente</p>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="stat-card-modern">
                <div class="stat-icon success">
                    <i class="bi bi-check-circle"></i>
                </div>
                <div class="stat-content">
                    <h3><?= $facturesApprouvees ?></h3>
                    <p>Factures approuvées</p>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="stat-card-modern">
                <div class="stat-icon info">
                    <i class="bi bi-cash-stack"></i>
                </div>
                <div class="stat-content">
                    <h3><?= formatMoney($totalDepenses) ?></h3>
                    <p>Total dépensé</p>
                </div>
            </div>
        </div>
    </div>
    
    <div class="row">
        <!-- Colonne principale - Projets -->
        <div class="col-lg-8">
            <div class="section-title">
                <h4><i class="bi bi-building me-2"></i>Projets actifs</h4>
                <a href="<?= url('/admin/projets/nouveau.php') ?>" class="btn btn-primary btn-sm">
                    <i class="bi bi-plus-lg me-1"></i>Nouveau
                </a>
            </div>
            
            <?php if (empty($projets)): ?>
                <div class="card">
                    <div class="card-body text-center py-5">
                        <i class="bi bi-building text-secondary" style="font-size: 4rem;"></i>
                        <h4 class="mt-3">Aucun projet</h4>
                        <p class="text-muted">Créez votre premier projet de flip</p>
                        <a href="<?= url('/admin/projets/nouveau.php') ?>" class="btn btn-primary">
                            <i class="bi bi-plus-circle me-1"></i>Créer un projet
                        </a>
                    </div>
                </div>
            <?php else: ?>
                <div class="row">
                    <?php foreach ($projets as $projet): 
                        $indicateurs = calculerIndicateursProjet($pdo, $projet);
                        $progression = $indicateurs['renovation']['progression'];
                    ?>
                        <div class="col-md-6 mb-4">
                            <div class="project-card" onclick="window.location='/admin/projets/detail.php?id=<?= $projet['id'] ?>'">
                                <div class="project-card-header">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <h5><?= e($projet['nom']) ?></h5>
                                            <small><i class="bi bi-geo-alt me-1"></i><?= e($projet['ville']) ?></small>
                                        </div>
                                        <span class="badge <?= getStatutProjetClass($projet['statut']) ?>">
                                            <?= getStatutProjetLabel($projet['statut']) ?>
                                        </span>
                                    </div>
                                </div>
                                <div class="project-card-body">
                                    <div class="project-metrics">
                                        <div class="metric-item">
                                            <div class="metric-value"><?= formatMoney($indicateurs['valeur_potentielle']) ?></div>
                                            <div class="metric-label">Valeur pot.</div>
                                        </div>
                                        <div class="metric-item <?= $indicateurs['equite_potentielle'] >= 0 ? 'success' : 'danger' ?>">
                                            <div class="metric-value"><?= formatMoney($indicateurs['equite_potentielle']) ?></div>
                                            <div class="metric-label">Profit</div>
                                        </div>
                                        <div class="metric-item info">
                                            <div class="metric-value"><?= formatPercent($indicateurs['roi_leverage']) ?></div>
                                            <div class="metric-label">ROI</div>
                                        </div>
                                        <div class="metric-item">
                                            <div class="metric-value"><?= number_format($progression, 0) ?>%</div>
                                            <div class="metric-label">Budget</div>
                                        </div>
                                    </div>
                                    
                                    <div class="progress" style="height: 6px;">
                                        <div class="progress-bar <?= $progression > 100 ? 'bg-danger' : 'bg-primary' ?>" 
                                             style="width: <?= min(100, $progression) ?>%"></div>
                                    </div>
                                    
                                    <div class="quick-actions" onclick="event.stopPropagation()">
                                        <a href="<?= url('/admin/projets/detail.php?id=<?= $projet['id'] ?>" class="btn btn-outline-primary btn-sm">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        <a href="<?= url('/admin/factures/liste.php?projet=<?= $projet['id'] ?>" class="btn btn-outline-secondary btn-sm">
                                            <i class="bi bi-receipt"></i>
                                        </a>
                                        <a href="<?= url('/admin/projets/modifier.php?id=<?= $projet['id'] ?>" class="btn btn-outline-secondary btn-sm">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Colonne latérale -->
        <div class="col-lg-4">
            <!-- Factures en attente -->
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-clock-history me-2"></i>À approuver</span>
                    <?php if ($facturesEnAttente > 0): ?>
                        <span class="badge bg-danger"><?= $facturesEnAttente ?></span>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <?php if (empty($facturesAttente)): ?>
                        <p class="text-muted text-center mb-0 py-3">
                            <i class="bi bi-check-circle text-success" style="font-size: 2rem;"></i><br>
                            Aucune facture en attente
                        </p>
                    <?php else: ?>
                        <?php foreach ($facturesAttente as $facture): ?>
                            <div class="pending-facture-item">
                                <div class="pending-facture-info">
                                    <strong><?= e($facture['fournisseur']) ?></strong>
                                    <small><?= e($facture['projet_nom']) ?></small>
                                </div>
                                <div class="pending-facture-amount">
                                    <?= formatMoney($facture['montant_total']) ?>
                                </div>
                                <div>
                                    <a href="<?= url('/admin/factures/approuver.php?action=approuver&id=<?= $facture['id'] ?>" 
                                       class="btn btn-success btn-sm" title="Approuver">
                                        <i class="bi bi-check"></i>
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        
                        <?php if ($facturesEnAttente > 5): ?>
                            <div class="text-center pt-3">
                                <a href="<?= url('/admin/factures/approuver.php') ?>" class="btn btn-outline-primary btn-sm">
                                    Voir les <?= $facturesEnAttente ?> factures
                                </a>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Actions rapides -->
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-lightning me-2"></i>Actions rapides
                </div>
                <div class="card-body">
                    <div class="d-grid gap-2">
                        <a href="<?= url('/admin/projets/nouveau.php') ?>" class="btn btn-outline-primary">
                            <i class="bi bi-plus-circle me-2"></i>Nouveau projet
                        </a>
                        <a href="<?= url('/admin/factures/nouvelle.php') ?>" class="btn btn-outline-secondary">
                            <i class="bi bi-receipt me-2"></i>Nouvelle facture
                        </a>
                        <a href="<?= url('/admin/investisseurs/liste.php') ?>" class="btn btn-outline-secondary">
                            <i class="bi bi-people me-2"></i>Investisseurs
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
