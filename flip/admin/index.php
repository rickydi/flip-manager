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

// Trier par date décroissante et limiter à 20 pour l'affichage
usort($activites, function($a, $b) {
    return strtotime($b['date_activite']) - strtotime($a['date_activite']);
});
$totalActivites = count($activites);
$activites = array_slice($activites, 0, 20);

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
    LIMIT 20
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

/* Dashboard cards égales avec CSS Grid */
.dashboard-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1.5rem;
    align-items: start;
}

.dashboard-grid > div {
    display: flex;
    flex-direction: column;
}

.dashboard-grid > div > h5 {
    min-height: 32px;
    display: flex;
    align-items: center;
}

@media (max-width: 991px) {
    .dashboard-grid {
        grid-template-columns: 1fr;
    }
}

.dashboard-grid .card {
    display: flex;
    flex-direction: column;
    flex: 1;
}

.dashboard-grid .card-body {
    flex: 1;
    display: flex;
    flex-direction: column;
}

.dashboard-grid .card-body .activity-list {
    flex: 1;
}

/* Activités récentes */
.activity-item {
    display: flex;
    align-items: flex-start;
    padding: 0.4rem 0.75rem;
    border-bottom: 1px solid var(--border-color);
    gap: 0.75rem;
}

.activity-item:last-child {
    border-bottom: none;
}

.activity-item:hover {
    background-color: rgba(0, 123, 255, 0.05);
    transition: background-color 0.2s;
}

.activity-icon {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    font-size: 0.85rem;
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
    font-size: 0.85rem;
}

.activity-content small {
    color: var(--text-secondary);
    font-size: 0.75rem;
}

.activity-meta {
    text-align: right;
    flex-shrink: 0;
}

.activity-meta .amount {
    font-weight: 600;
    color: var(--text-primary);
    font-size: 0.8rem;
}

.activity-meta .date {
    font-size: 0.7rem;
    color: var(--text-muted);
}

.activity-meta .date-action {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    justify-content: flex-end;
}

.activity-user {
    display: inline-flex;
    align-items: center;
    gap: 0.2rem;
    font-size: 0.7rem;
    color: var(--text-secondary);
    background: var(--bg-table-hover);
    padding: 0.1rem 0.4rem;
    border-radius: 1rem;
    margin-top: 0.15rem;
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

/* Bouton approuver dans la colonne À approuver */
.approve-btn {
    margin-top: 0.25rem;
    padding: 0.25rem 0.5rem;
    font-size: 0.75rem;
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
                    <h3 class="counter" data-target="<?= $totalProjets ?>">0</h3>
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
                    <h3 class="counter" data-target="<?= $facturesEnAttente ?>">0</h3>
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
                    <h3 class="counter" data-target="<?= $facturesApprouvees ?>">0</h3>
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
                    <h3 class="counter-money" data-target="<?= $totalDepenses ?>">0,00 $</h3>
                    <p>Total dépensé</p>
                </div>
            </div>
        </div>
    </div>
    
    <div class="dashboard-grid mb-4">
        <!-- Colonne Activités récentes -->
        <div>
            <h5 class="mb-3"><i class="bi bi-activity me-2"></i>Dernières activités</h5>
            <div class="card">
                <div class="card-body p-0">
                    <div class="activity-list">
                    <?php if (empty($activites)): ?>
                        <div class="text-center py-4">
                            <i class="bi bi-inbox text-secondary" style="font-size: 3rem;"></i>
                            <p class="text-muted mt-2">Aucune activité</p>
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
        </div>

        <!-- Colonne À approuver -->
        <div>
            <h5 class="mb-3">
                <i class="bi bi-clock-history me-2"></i>À approuver
                <?php if ($facturesEnAttente > 0): ?>
                    <span class="badge bg-danger ms-2"><?= $facturesEnAttente ?></span>
                <?php endif; ?>
            </h5>
            <div class="card">
                <div class="card-body p-0">
                    <div class="activity-list">
                    <?php if (empty($facturesAttente)): ?>
                        <div class="text-center py-4">
                            <i class="bi bi-check-circle text-success" style="font-size: 3rem;"></i>
                            <p class="text-muted mt-2">Tout est approuvé!</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($facturesAttente as $facture): ?>
                            <a href="<?= url('/admin/factures/modifier.php?id=' . $facture['id']) ?>" class="activity-item" style="text-decoration: none; color: inherit; cursor: pointer;">
                                <div class="activity-icon facture">
                                    <i class="bi bi-receipt"></i>
                                </div>
                                <div class="activity-content">
                                    <strong><?= e($facture['fournisseur']) ?></strong>
                                    <small><?= e($facture['projet_nom']) ?></small>
                                    <div class="activity-user">
                                        <i class="bi bi-person-fill"></i>
                                        <?= e($facture['employe_nom']) ?>
                                    </div>
                                </div>
                                <div class="activity-meta">
                                    <div class="amount"><?= formatMoney($facture['montant_total']) ?></div>
                                    <div class="date-action">
                                        <span class="date"><?= formatDate($facture['date_creation']) ?></span>
                                        <span class="btn btn-success btn-sm approve-btn"
                                              onclick="event.preventDefault(); event.stopPropagation(); window.location.href='<?= url('/admin/factures/approuver.php?action=approuver&id=' . $facture['id']) ?>';"
                                              title="Approuver">
                                            <i class="bi bi-check"></i>
                                        </span>
                                    </div>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Actions rapides -->
    <div class="row">
        <div class="col-12">
            <div class="section-title">
                <h4><i class="bi bi-lightning me-2"></i>Actions rapides</h4>
            </div>
            <div class="card">
                <div class="card-body">
                    <div class="d-flex flex-wrap gap-2">
                        <a href="<?= url('/admin/projets/liste.php') ?>" class="btn btn-outline-primary">
                            <i class="bi bi-building me-2"></i>Voir les projets
                        </a>
                        <a href="<?= url('/admin/factures/nouvelle.php') ?>" class="btn btn-outline-secondary">
                            <i class="bi bi-receipt me-2"></i>Nouvelle facture
                        </a>
                        <a href="<?= url('/admin/temps/liste.php') ?>" class="btn btn-outline-secondary">
                            <i class="bi bi-clock me-2"></i>Feuilles de temps
                        </a>
                        <a href="<?= url('/admin/photos/liste.php') ?>" class="btn btn-outline-secondary">
                            <i class="bi bi-camera me-2"></i>Photos
                        </a>
                        <a href="<?= url('/admin/rapports/paie-hebdo.php') ?>" class="btn btn-outline-secondary">
                            <i class="bi bi-calendar-week me-2"></i>Paie hebdo
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Motion One (animations) -->
<script src="https://cdn.jsdelivr.net/npm/motion@11.11.13/dist/motion.min.js"></script>

<script>
// Animation des compteurs et cartes
document.addEventListener('DOMContentLoaded', function() {
    const { animate, stagger } = Motion;
    const duration = 1500; // Durée de l'animation en ms

    // Animation des stat-cards en cascade
    animate('.stat-card-modern',
        { opacity: [0, 1], y: [20, 0], scale: [0.95, 1] },
        { duration: 0.5, delay: stagger(0.1), easing: [0.22, 1, 0.36, 1] }
    );

    // Animation des cards principales
    animate('.dashboard-grid .card, .row > .col-12 > .card',
        { opacity: [0, 1], y: [30, 0] },
        { duration: 0.6, delay: stagger(0.15, { start: 0.3 }), easing: 'ease-out' }
    );

    // Animation des activity items
    animate('.activity-item',
        { opacity: [0, 1], x: [-10, 0] },
        { duration: 0.3, delay: stagger(0.05, { start: 0.5 }) }
    );

    // Animation des boutons d'action rapide
    animate('.btn-outline-primary, .btn-outline-secondary',
        { opacity: [0, 1], scale: [0.9, 1] },
        { duration: 0.3, delay: stagger(0.08, { start: 0.8 }) }
    );

    // Compteurs simples (nombres entiers)
    document.querySelectorAll('.counter').forEach(counter => {
        const target = parseInt(counter.dataset.target) || 0;
        const startTime = performance.now();

        function updateCounter(currentTime) {
            const elapsed = currentTime - startTime;
            const progress = Math.min(elapsed / duration, 1);

            // Easing function pour un effet plus naturel
            const easeOut = 1 - Math.pow(1 - progress, 3);
            const current = Math.floor(easeOut * target);

            counter.textContent = current;

            if (progress < 1) {
                requestAnimationFrame(updateCounter);
            } else {
                counter.textContent = target;
            }
        }

        requestAnimationFrame(updateCounter);
    });

    // Compteur argent (avec format monétaire)
    document.querySelectorAll('.counter-money').forEach(counter => {
        const target = parseFloat(counter.dataset.target) || 0;
        const startTime = performance.now();

        function formatMoney(value) {
            return value.toLocaleString('fr-CA', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            }) + ' $';
        }

        function updateCounter(currentTime) {
            const elapsed = currentTime - startTime;
            const progress = Math.min(elapsed / duration, 1);

            // Easing function
            const easeOut = 1 - Math.pow(1 - progress, 3);
            const current = easeOut * target;

            counter.textContent = formatMoney(current);

            if (progress < 1) {
                requestAnimationFrame(updateCounter);
            } else {
                counter.textContent = formatMoney(target);
            }
        }

        requestAnimationFrame(updateCounter);
    });
});
</script>

<?php include '../includes/footer.php'; ?>
