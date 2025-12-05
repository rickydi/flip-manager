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

// Statistiques globales
$stmt = $pdo->query("SELECT COUNT(*) FROM projets WHERE statut != 'archive'");
$totalProjets = $stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) FROM factures WHERE statut = 'en_attente'");
$facturesEnAttente = $stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) FROM factures WHERE statut = 'approuvee'");
$facturesApprouvees = $stmt->fetchColumn();

$stmt = $pdo->query("SELECT SUM(montant_total) FROM factures WHERE statut = 'approuvee'");
$totalDepenses = $stmt->fetchColumn() ?: 0;

// Statistiques photos
$totalPhotos = 0;
try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM photos_projet");
    $totalPhotos = $stmt->fetchColumn() ?: 0;
} catch (Exception $e) {
    // Table n'existe pas
}

// Dernières activités (factures, heures, photos)
$activites = [];

// Récupérer les dernières factures
$stmt = $pdo->query("
    SELECT
        'facture' as type,
        f.id,
        f.fournisseur as description,
        f.montant_total as montant,
        f.statut,
        p.nom as projet_nom,
        CONCAT(u.prenom, ' ', u.nom) as user_nom,
        f.date_creation as date_activite
    FROM factures f
    JOIN projets p ON f.projet_id = p.id
    JOIN users u ON f.user_id = u.id
    ORDER BY f.date_creation DESC
    LIMIT 10
");
$activites = array_merge($activites, $stmt->fetchAll());

// Essayer de récupérer les heures si la table existe
try {
    $stmt = $pdo->query("
        SELECT
            'heures' as type,
            h.id,
            CONCAT(h.heures, 'h - ', IFNULL(h.description, 'Travail')) as description,
            NULL as montant,
            h.statut,
            p.nom as projet_nom,
            CONCAT(u.prenom, ' ', u.nom) as user_nom,
            h.date_travail as date_activite
        FROM heures_travail h
        JOIN projets p ON h.projet_id = p.id
        JOIN users u ON h.user_id = u.id
        ORDER BY h.date_travail DESC
        LIMIT 10
    ");
    $activites = array_merge($activites, $stmt->fetchAll());
} catch (Exception $e) {
    // Table heures_travail n'existe pas, ignorer
}

// Récupérer les photos uploadées
try {
    $stmt = $pdo->query("
        SELECT
            'photo' as type,
            ph.id,
            IFNULL(ph.description, 'Photo') as description,
            NULL as montant,
            'photo' as statut,
            p.nom as projet_nom,
            CONCAT(u.prenom, ' ', u.nom) as user_nom,
            ph.date_prise as date_activite
        FROM photos_projet ph
        JOIN projets p ON ph.projet_id = p.id
        JOIN users u ON ph.user_id = u.id
        ORDER BY ph.date_prise DESC
        LIMIT 10
    ");
    $activites = array_merge($activites, $stmt->fetchAll());
} catch (Exception $e) {
    // Table photos_projet n'existe pas, ignorer
}

// Récupérer les projets créés/modifiés
try {
    $stmt = $pdo->query("
        SELECT
            'projet' as type,
            p.id,
            p.nom as description,
            p.budget_total as montant,
            p.statut,
            p.nom as projet_nom,
            'Admin' as user_nom,
            p.date_creation as date_activite
        FROM projets p
        ORDER BY p.date_creation DESC
        LIMIT 10
    ");
    $activites = array_merge($activites, $stmt->fetchAll());
} catch (Exception $e) {
    // Ignorer
}

// Trier par date décroissante et limiter à 15
usort($activites, function($a, $b) {
    return strtotime($b['date_activite']) - strtotime($a['date_activite']);
});
$activites = array_slice($activites, 0, 15);

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

/* Activités récentes */
.activity-item {
    display: flex;
    align-items: flex-start;
    padding: 1rem;
    border-bottom: 1px solid var(--border-color);
    gap: 1rem;
}

.activity-item:last-child {
    border-bottom: none;
}

.activity-item:hover {
    background-color: rgba(0, 123, 255, 0.05);
    transition: background-color 0.2s;
}

.activity-icon {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.activity-icon.facture { background: rgba(37, 99, 235, 0.15); color: var(--primary-color); }
.activity-icon.heures { background: rgba(34, 197, 94, 0.15); color: var(--success-color); }
.activity-icon.photo { background: rgba(168, 85, 247, 0.15); color: #a855f7; }
.activity-icon.projet { background: rgba(245, 158, 11, 0.15); color: var(--warning-color); }

.bg-purple { background-color: #a855f7 !important; }

.activity-content {
    flex: 1;
    min-width: 0;
}

.activity-content strong {
    color: var(--text-primary);
    display: block;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.activity-content small {
    color: var(--text-secondary);
}

.activity-meta {
    text-align: right;
    flex-shrink: 0;
}

.activity-meta .amount {
    font-weight: 600;
    color: var(--text-primary);
}

.activity-meta .date {
    font-size: 0.75rem;
    color: var(--text-muted);
}

.activity-user {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    font-size: 0.8rem;
    color: var(--text-secondary);
    background: var(--bg-table-hover);
    padding: 0.15rem 0.5rem;
    border-radius: 1rem;
    margin-top: 0.25rem;
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
        <!-- Colonne principale - Activités récentes -->
        <div class="col-lg-8">
            <div class="section-title">
                <h4><i class="bi bi-activity me-2"></i>Dernières activités</h4>
            </div>
            
            <div class="card">
                <div class="card-body p-0">
                    <?php if (empty($activites)): ?>
                        <div class="text-center py-5">
                            <i class="bi bi-inbox text-secondary" style="font-size: 4rem;"></i>
                            <h4 class="mt-3">Aucune activité</h4>
                            <p class="text-muted">Les dernières entrées apparaîtront ici</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($activites as $activite):
                            // Déterminer le lien selon le type
                            $activityLink = '#';
                            $activityIcon = 'activity';
                            switch ($activite['type']) {
                                case 'facture':
                                    $activityLink = url('/admin/factures/modifier.php?id=' . $activite['id']);
                                    $activityIcon = 'receipt';
                                    break;
                                case 'heures':
                                    $activityLink = url('/admin/temps/liste.php');
                                    $activityIcon = 'clock';
                                    break;
                                case 'photo':
                                    $activityLink = url('/admin/photos/liste.php');
                                    $activityIcon = 'camera';
                                    break;
                                case 'projet':
                                    $activityLink = url('/admin/projets/modifier.php?id=' . $activite['id']);
                                    $activityIcon = 'building';
                                    break;
                            }
                        ?>
                            <a href="<?= $activityLink ?>" class="activity-item" style="text-decoration: none; color: inherit; cursor: pointer;">
                                <div class="activity-icon <?= $activite['type'] ?>">
                                    <i class="bi bi-<?= $activityIcon ?>"></i>
                                </div>
                                <div class="activity-content">
                                    <strong><?= e($activite['description']) ?></strong>
                                    <small><?= e($activite['projet_nom']) ?></small>
                                    <div class="activity-user">
                                        <i class="bi bi-person-fill"></i>
                                        <?= e($activite['user_nom']) ?>
                                    </div>
                                </div>
                                <div class="activity-meta">
                                    <?php if ($activite['montant']): ?>
                                        <div class="amount"><?= formatMoney($activite['montant']) ?></div>
                                    <?php endif; ?>
                                    <div class="date"><?= formatDate($activite['date_activite']) ?></div>
                                    <?php
                                    // Badge selon le type et statut
                                    $badgeClass = 'bg-secondary';
                                    $badgeText = '';
                                    switch ($activite['type']) {
                                        case 'facture':
                                            if ($activite['statut'] === 'approuvee') {
                                                $badgeClass = 'bg-success';
                                                $badgeText = 'Approuvé';
                                            } elseif ($activite['statut'] === 'en_attente') {
                                                $badgeClass = 'bg-warning text-dark';
                                                $badgeText = 'En attente';
                                            } else {
                                                $badgeText = ucfirst($activite['statut']);
                                            }
                                            break;
                                        case 'heures':
                                            if ($activite['statut'] === 'approuve') {
                                                $badgeClass = 'bg-success';
                                                $badgeText = 'Approuvé';
                                            } elseif ($activite['statut'] === 'en_attente') {
                                                $badgeClass = 'bg-warning text-dark';
                                                $badgeText = 'En attente';
                                            } else {
                                                $badgeText = ucfirst($activite['statut']);
                                            }
                                            break;
                                        case 'photo':
                                            $badgeClass = 'bg-purple';
                                            $badgeText = 'Photo';
                                            break;
                                        case 'projet':
                                            if ($activite['statut'] === 'en_cours') {
                                                $badgeClass = 'bg-primary';
                                                $badgeText = 'En cours';
                                            } elseif ($activite['statut'] === 'termine') {
                                                $badgeClass = 'bg-success';
                                                $badgeText = 'Terminé';
                                            } else {
                                                $badgeText = ucfirst(str_replace('_', ' ', $activite['statut']));
                                            }
                                            break;
                                    }
                                    ?>
                                    <span class="badge <?= $badgeClass ?>" style="font-size: 0.65rem;">
                                        <?= $badgeText ?>
                                    </span>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Colonne latérale -->
        <div class="col-lg-4">
            <!-- Factures en attente -->
            <div class="section-title">
                <h4><i class="bi bi-clock-history me-2"></i>À approuver</h4>
                <?php if ($facturesEnAttente > 0): ?>
                    <span class="badge bg-danger"><?= $facturesEnAttente ?></span>
                <?php endif; ?>
            </div>

            <div class="card">
                <div class="card-body p-0">
                    <?php if (empty($facturesAttente)): ?>
                        <div class="text-center py-5">
                            <i class="bi bi-check-circle text-success" style="font-size: 4rem;"></i>
                            <h4 class="mt-3">Aucune facture</h4>
                            <p class="text-muted">Toutes les factures sont approuvées</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($facturesAttente as $facture): ?>
                            <div class="activity-item">
                                <div class="activity-icon facture">
                                    <i class="bi bi-receipt"></i>
                                </div>
                                <div class="activity-content">
                                    <strong><?= e($facture['fournisseur']) ?></strong>
                                    <small><?= e($facture['projet_nom']) ?></small>
                                </div>
                                <div class="activity-meta">
                                    <div class="amount"><?= formatMoney($facture['montant_total']) ?></div>
                                    <a href="<?= url('/admin/factures/approuver.php?action=approuver&id=' . $facture['id']) ?>"
                                       class="btn btn-success btn-sm mt-1" title="Approuver">
                                        <i class="bi bi-check"></i>
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>

                        <?php if ($facturesEnAttente > 5): ?>
                            <div class="text-center py-3">
                                <a href="<?= url('/admin/factures/approuver.php') ?>" class="btn btn-outline-primary btn-sm">
                                    Voir les <?= $facturesEnAttente ?> factures
                                </a>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Actions rapides -->
            <div class="section-title mt-4">
                <h4><i class="bi bi-lightning me-2"></i>Actions rapides</h4>
            </div>
            <div class="card">
                <div class="card-body">
                    <div class="d-grid gap-2">
                        <a href="<?= url('/admin/projets/liste.php') ?>" class="btn btn-outline-primary">
                            <i class="bi bi-building me-2"></i>Voir les projets
                        </a>
                        <a href="<?= url('/admin/factures/nouvelle.php') ?>" class="btn btn-outline-secondary">
                            <i class="bi bi-receipt me-2"></i>Nouvelle facture
                        </a>
                        <a href="<?= url('/admin/temps/liste.php') ?>" class="btn btn-outline-secondary">
                            <i class="bi bi-clock me-2"></i>Feuilles de temps
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
