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
            (h.heures * h.taux_horaire) as montant,
            h.statut,
            p.nom as projet_nom,
            CONCAT(u.prenom, ' ', u.nom) as user_nom,
            h.date_creation as date_activite
        FROM heures_travaillees h
        JOIN projets p ON h.projet_id = p.id
        JOIN users u ON h.user_id = u.id
        ORDER BY h.date_creation DESC
        LIMIT 10
    ");
    $activites = array_merge($activites, $stmt->fetchAll());
} catch (Exception $e) {
    // Table heures_travaillees n'existe pas, ignorer
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
    SELECT f.*, p.nom as projet_nom, e.nom as etape_nom,
           CONCAT(u.prenom, ' ', u.nom) as employe_nom
    FROM factures f
    JOIN projets p ON f.projet_id = p.id
    LEFT JOIN budget_etapes e ON f.etape_id = e.id
    JOIN users u ON f.user_id = u.id
    WHERE f.statut = 'en_attente'
    ORDER BY f.date_creation ASC
    LIMIT 20
");
$facturesAttente = $stmt->fetchAll();

// Données fiscales - années disponibles basées sur les dates de vente
$anneesDisponibles = [];
$stmtAnnees = $pdo->query("
    SELECT DISTINCT YEAR(date_vente) as annee
    FROM projets
    WHERE date_vente IS NOT NULL
    ORDER BY annee DESC
");
foreach ($stmtAnnees->fetchAll() as $row) {
    $anneesDisponibles[] = (int) $row['annee'];
}
// Ajouter l'année courante si pas déjà présente
$anneeActuelle = (int) date('Y');
if (!in_array($anneeActuelle, $anneesDisponibles)) {
    array_unshift($anneesDisponibles, $anneeActuelle);
}

// Année sélectionnée (par défaut: année courante ou dernière année avec ventes)
$anneeFiscale = isset($_GET['annee']) ? (int) $_GET['annee'] : $anneeActuelle;
if (!in_array($anneeFiscale, $anneesDisponibles)) {
    $anneeFiscale = $anneeActuelle;
}
$resumeFiscal = obtenirResumeAnneeFiscale($pdo, $anneeFiscale);

// Calcul de la vélocité du profit (basé sur 40h/semaine, 52 semaines)
$profitNetAnnuel = $resumeFiscal['profit_net_realise'] ?? 0;
$profitParMois = $profitNetAnnuel / 12;
$profitParSemaine = $profitNetAnnuel / 52;
$profitParHeure = $profitNetAnnuel / (52 * 40); // 40h/semaine
$profitParSeconde = $profitNetAnnuel / (52 * 40 * 3600); // en secondes

include '../includes/header.php';
?>

<style>
/* === TACHYMÈTRE PROFIT - SPEEDOMETER DESIGN === */
.profit-velocity {
    background: linear-gradient(180deg, #0c1929 0%, #132743 100%);
    border-radius: 1rem;
    padding: 1rem 1.5rem;
    margin-bottom: 1rem;
    box-shadow: 0 4px 20px rgba(0,0,0,0.3);
    border: 1px solid rgba(255,255,255,0.08);
    display: flex;
    align-items: center;
    gap: 2rem;
}

.velocity-header {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
    flex-shrink: 0;
}

.velocity-title {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.75rem;
    font-weight: 600;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 1px;
    white-space: nowrap;
}

.velocity-title i {
    color: #10b981;
}

.velocity-year-select {
    background: rgba(255,255,255,0.08);
    border: 1px solid rgba(255,255,255,0.15);
    color: #fff;
    padding: 0.35rem 0.6rem;
    border-radius: 0.5rem;
    font-size: 0.8rem;
    cursor: pointer;
}

.velocity-year-select option {
    background: #1a2744;
    color: #fff;
}

/* Speedometer Container */
.speedometer-container {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 3rem;
    flex: 1;
}

/* All Gauges Same Style */
.speedometer-container {
    display: flex;
    justify-content: center;
    align-items: flex-end;
    gap: 2rem;
    flex: 1;
}

.gauge-item {
    text-align: center;
    flex: 1;
    min-width: 160px;
    max-width: 220px;
}

.gauge-speedometer {
    position: relative;
    width: 160px;
    height: 95px;
    margin: 0 auto;
}

.gauge-speedometer svg {
    width: 100%;
    height: 100%;
}

.gauge-bg {
    fill: none;
    stroke: rgba(255,255,255,0.08);
    stroke-width: 10;
    stroke-linecap: round;
}

.gauge-progress {
    fill: none;
    stroke-width: 10;
    stroke-linecap: round;
    filter: drop-shadow(0 0 6px var(--gauge-glow));
    transition: stroke-dashoffset 1s ease-out;
}

.gauge-item.second .gauge-progress {
    stroke: url(#secondGradient);
    --gauge-glow: rgba(239, 68, 68, 0.5);
}
.gauge-item.hour .gauge-progress {
    stroke: url(#hourGradient);
    --gauge-glow: rgba(245, 158, 11, 0.5);
}
.gauge-item.week .gauge-progress {
    stroke: url(#weekGradient);
    --gauge-glow: rgba(16, 185, 129, 0.5);
}
.gauge-item.month .gauge-progress {
    stroke: url(#monthGradient);
    --gauge-glow: rgba(139, 92, 246, 0.5);
}

.gauge-center {
    position: absolute;
    bottom: 0;
    left: 50%;
    transform: translateX(-50%);
    text-align: center;
}

.gauge-value {
    font-size: 1.1rem;
    font-weight: 700;
    color: #fff;
    line-height: 1;
    white-space: nowrap;
}

.gauge-value sup {
    font-size: 0.6rem;
    font-weight: 600;
}

.gauge-unit {
    font-size: 0.7rem;
    color: #64748b;
    text-transform: uppercase;
}

.gauge-percent-arc {
    font-size: 0.55rem;
    font-weight: 700;
    fill: #fff;
    text-anchor: middle;
    dominant-baseline: middle;
    filter: drop-shadow(0 1px 2px rgba(0,0,0,0.8));
}

.gauge-percent-dot {
    filter: drop-shadow(0 0 4px currentColor);
}

.gauge-percent-dot, .gauge-percent-arc {
    opacity: 0;
}

.gauge-percent-dot.visible, .gauge-percent-arc.visible {
    opacity: 1;
    transition: opacity 0.2s ease;
}

.gauge-item:hover {
    transform: scale(1.05);
    transition: transform 0.2s ease;
}

.gauge-label {
    font-size: 0.8rem;
    color: #94a3b8;
    font-weight: 500;
    margin-top: 0.5rem;
}

/* Responsive */
@media (max-width: 992px) {
    .profit-velocity {
        flex-direction: column;
        gap: 1rem;
        padding: 1rem;
    }
    .velocity-header {
        flex-direction: row;
        justify-content: space-between;
        width: 100%;
    }
    .speedometer-container {
        gap: 1.5rem;
    }
}

@media (max-width: 768px) {
    .speedometer-container {
        flex-wrap: wrap;
        gap: 1rem;
    }
    .gauge-item {
        min-width: 140px;
        max-width: 160px;
    }
    .gauge-speedometer {
        width: 140px;
        height: 85px;
    }
    .gauge-value {
        font-size: 1rem;
    }
}

@media (max-width: 576px) {
    .speedometer-container {
        gap: 0.75rem;
    }
    .gauge-item {
        min-width: 120px;
        max-width: 140px;
    }
    .gauge-speedometer {
        width: 120px;
        height: 75px;
    }
    .gauge-value {
        font-size: 0.9rem;
    }
    .gauge-label {
        font-size: 0.65rem;
    }
}

/* === MINI STATS CARDS === */
.mini-stats {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 0.75rem;
}

.mini-stat-link {
    text-decoration: none;
    color: inherit;
}

.mini-stat-link:hover {
    text-decoration: none;
    color: inherit;
}

@media (max-width: 992px) {
    .mini-stats {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 480px) {
    .mini-stats {
        gap: 0.4rem;
    }
    .mini-stat {
        padding: 0.5rem;
        min-height: 60px;
    }
    .mini-stat-icon {
        width: 28px;
        height: 28px;
        font-size: 0.8rem;
        border-radius: 0.4rem;
    }
    .mini-stat-content h4 {
        font-size: 0.85rem;
    }
    .mini-stat-content p {
        font-size: 0.55rem;
    }
}

.mini-stat {
    background: var(--bg-card);
    border-radius: 0.75rem;
    padding: 0.75rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    box-shadow: 0 2px 8px var(--shadow-color);
    height: 100%;
    min-height: 70px;
    transition: all 0.2s;
    border: 1px solid transparent;
    cursor: pointer;
}

.mini-stat:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px var(--shadow-color);
    border-color: var(--primary-color, #4a90a4);
}

.mini-stat-icon {
    width: 36px;
    height: 36px;
    border-radius: 0.5rem;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1rem;
    flex-shrink: 0;
}

.mini-stat-icon.primary { background: rgba(59, 130, 246, 0.15); color: #3b82f6; }
.mini-stat-icon.warning { background: rgba(245, 158, 11, 0.15); color: #f59e0b; }
.mini-stat-icon.success { background: rgba(16, 185, 129, 0.15); color: #10b981; }
.mini-stat-icon.info { background: rgba(99, 102, 241, 0.15); color: #6366f1; }

.mini-stat-content {
    flex: 1;
    min-width: 0;
    overflow: hidden;
}

.mini-stat-content h4 {
    margin: 0;
    font-size: clamp(0.85rem, 3vw, 1.1rem);
    font-weight: 700;
    color: var(--text-primary);
    line-height: 1.2;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.mini-stat-content p {
    margin: 0;
    font-size: 0.65rem;
    color: var(--text-secondary);
}

/* Stats cards cliquables - legacy */
.stat-card-link {
    display: block;
    text-decoration: none;
    color: inherit;
    height: 100%;
}

.stat-card-link:hover {
    text-decoration: none;
    color: inherit;
}

.stat-card-link .stat-card-modern {
    cursor: pointer;
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
    transition: transform 0.2s, box-shadow 0.2s, border-color 0.2s;
    height: 100%;
    border: 2px solid transparent;
}

.stat-card-modern:hover {
    transform: translateY(-4px);
    box-shadow: 0 12px 20px var(--shadow-color);
    border-color: var(--primary-color, #4a90a4);
}

/* Responsive velocity */
@media (max-width: 576px) {
    .velocity-gauges {
        gap: 1rem;
    }
    .velocity-item {
        min-width: 80px;
    }
    .velocity-circle {
        width: 70px;
        height: 70px;
    }
    .velocity-item.main .velocity-circle {
        width: 90px;
        height: 90px;
    }
    .velocity-value .amount {
        font-size: 0.85rem;
    }
    .velocity-item.main .velocity-value .amount {
        font-size: 1.1rem;
    }
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

/* Section Fiscalité - Design épuré */
.fiscal-section {
    background: var(--bg-card);
    border-radius: 1rem;
    overflow: hidden;
    box-shadow: 0 2px 8px var(--shadow-color);
}

.fiscal-header {
    background: linear-gradient(135deg, #1e3a5f 0%, #2d5a87 100%);
    color: white;
    padding: 1rem 1.25rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 0.75rem;
}

.fiscal-header h5 {
    margin: 0;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.fiscal-header select {
    background: rgba(255,255,255,0.15);
    border: 1px solid rgba(255,255,255,0.3);
    color: white;
    padding: 0.25rem 0.5rem;
    border-radius: 0.5rem;
    font-size: 0.9rem;
}

.fiscal-header select option {
    background: #1e3a5f;
    color: white;
}

.fiscal-header-right {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.fiscal-dpe {
    text-align: right;
    line-height: 1.2;
}

.fiscal-dpe-label {
    font-size: 0.7rem;
    opacity: 0.8;
}

.fiscal-dpe-value {
    font-size: 1.25rem;
    font-weight: 700;
}

/* Mobile responsive */
@media (max-width: 576px) {
    .fiscal-header {
        flex-direction: column;
        align-items: stretch;
        text-align: center;
        padding: 1rem;
    }

    .fiscal-header h5 {
        justify-content: center;
        margin-bottom: 0.5rem;
    }

    .fiscal-header-right {
        justify-content: center;
        flex-wrap: wrap;
    }

    .fiscal-dpe {
        text-align: center;
    }
}

.fiscal-body {
    padding: 1.5rem;
}

.fiscal-gauge {
    position: relative;
    height: 12px;
    background: rgba(100, 116, 139, 0.15);
    border-radius: 6px;
    overflow: hidden;
    margin-bottom: 0.5rem;
}

.fiscal-gauge-fill {
    height: 100%;
    border-radius: 6px;
    transition: width 1.5s cubic-bezier(0.4, 0, 0.2, 1);
}

.fiscal-gauge-fill.safe { background: linear-gradient(90deg, #10b981, #34d399); }
.fiscal-gauge-fill.warning { background: linear-gradient(90deg, #f59e0b, #fbbf24); }
.fiscal-gauge-fill.danger { background: linear-gradient(90deg, #ef4444, #f87171); }

.fiscal-numbers {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 1rem;
    margin-top: 1.5rem;
}

.fiscal-number {
    text-align: center;
    padding: 1rem;
    background: var(--bg-table-hover);
    border-radius: 0.75rem;
    border-left: 3px solid transparent;
}

.fiscal-number.highlight-green { border-left-color: #10b981; }
.fiscal-number.highlight-red { border-left-color: #ef4444; }
.fiscal-number.highlight-blue { border-left-color: #3b82f6; }

.fiscal-number .num {
    font-size: 1.4rem;
    font-weight: 700;
    color: var(--text-primary);
}

.fiscal-number .lbl {
    font-size: 0.7rem;
    color: var(--text-secondary);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-top: 0.25rem;
}

.fiscal-projects {
    margin-top: 1.5rem;
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1.5rem;
}

@media (max-width: 768px) {
    .fiscal-numbers { grid-template-columns: repeat(2, 1fr); }
    .fiscal-projects { grid-template-columns: 1fr; }
}

.fiscal-project-list h6 {
    font-size: 0.8rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--text-secondary);
    margin-bottom: 0.75rem;
    padding-bottom: 0.5rem;
    border-bottom: 1px solid var(--border-color);
}

.fiscal-project-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.6rem 0;
    border-bottom: 1px dashed var(--border-color);
}

.fiscal-project-item:last-child { border-bottom: none; }

.fiscal-project-item .name {
    font-weight: 500;
    color: var(--text-primary);
}

.fiscal-project-item .name a {
    color: inherit;
    text-decoration: none;
}

.fiscal-project-item .name a:hover {
    color: var(--primary-color);
}

.fiscal-project-item .date {
    font-size: 0.75rem;
    color: var(--text-muted);
}

.fiscal-project-item .amount {
    font-weight: 600;
    font-size: 0.9rem;
}

.fiscal-project-item .amount.positive { color: #10b981; }
.fiscal-project-item .amount.negative { color: #ef4444; }

.fiscal-project-item .tax-rate {
    font-size: 0.7rem;
    padding: 0.15rem 0.4rem;
    border-radius: 0.25rem;
    background: rgba(100, 116, 139, 0.1);
    color: var(--text-secondary);
}

.fiscal-empty {
    text-align: center;
    padding: 2rem;
    color: var(--text-muted);
}

.fiscal-summary {
    margin-top: 1rem;
    padding: 1rem;
    background: rgba(59, 130, 246, 0.08);
    border-radius: 0.75rem;
    border: 1px dashed rgba(59, 130, 246, 0.3);
}

.fiscal-summary-row {
    display: flex;
    justify-content: space-between;
    font-size: 0.85rem;
}

.fiscal-summary-row .label { color: var(--text-secondary); }
.fiscal-summary-row .value { font-weight: 600; color: var(--text-primary); }
</style>

<div class="container-fluid">
    <?php displayFlashMessage(); ?>

    <!-- Tachymètre Vélocité Profit -->
    <?php
    // Calcul pour les demi-cercles (arc de 180 degrés)
    $arcRadius = 60;
    $arcCircum = M_PI * $arcRadius; // Demi-circonférence

    // Pourcentages pour chaque jauge
    $pctSecond = min(100, max(0, ($profitParSeconde / 0.05) * 100)); // Objectif 0.05$/seconde
    $pctHour = min(100, max(0, ($profitParHeure / 150) * 100)); // Objectif 150$/h
    $pctWeek = min(100, max(0, ($profitParSemaine / 5000) * 100)); // Objectif 5000$/semaine
    $pctMonth = min(100, max(0, ($profitParMois / 20000) * 100)); // Objectif 20000$/mois

    // Offsets pour les arcs SVG
    $offsetSecond = $arcCircum - ($arcCircum * $pctSecond / 100);
    $offsetHour = $arcCircum - ($arcCircum * $pctHour / 100);
    $offsetWeek = $arcCircum - ($arcCircum * $pctWeek / 100);
    $offsetMonth = $arcCircum - ($arcCircum * $pctMonth / 100);

    // Fonction pour calculer position du % sur l'arc
    function getArcEndPosition($pct, $centerX = 70, $centerY = 75, $radius = 60) {
        $angle = M_PI * (1 - $pct / 100); // 0% = gauche (π), 100% = droite (0)
        return [
            'x' => $centerX + $radius * cos($angle),
            'y' => $centerY - $radius * sin($angle)
        ];
    }
    $posSecond = getArcEndPosition($pctSecond);
    $posHour = getArcEndPosition($pctHour);
    $posWeek = getArcEndPosition($pctWeek);
    $posMonth = getArcEndPosition($pctMonth);
    ?>
    <div class="profit-velocity">
        <div class="velocity-header">
            <div class="velocity-title">
                <i class="bi bi-speedometer2"></i>
                Vélocité Profit Net
            </div>
            <select class="velocity-year-select" onchange="window.location.href='?annee='+this.value">
                <?php foreach ($anneesDisponibles as $annee): ?>
                <option value="<?= $annee ?>" <?= $annee == $anneeFiscale ? 'selected' : '' ?>><?= $annee ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="speedometer-container">
            <!-- Defs pour les gradients -->
            <svg style="position:absolute;width:0;height:0;">
                <defs>
                    <linearGradient id="secondGradient" x1="0%" y1="0%" x2="100%" y2="0%">
                        <stop offset="0%" style="stop-color:#ef4444"/>
                        <stop offset="100%" style="stop-color:#f87171"/>
                    </linearGradient>
                    <linearGradient id="hourGradient" x1="0%" y1="0%" x2="100%" y2="0%">
                        <stop offset="0%" style="stop-color:#f59e0b"/>
                        <stop offset="100%" style="stop-color:#fbbf24"/>
                    </linearGradient>
                    <linearGradient id="weekGradient" x1="0%" y1="0%" x2="100%" y2="0%">
                        <stop offset="0%" style="stop-color:#10b981"/>
                        <stop offset="100%" style="stop-color:#06b6d4"/>
                    </linearGradient>
                    <linearGradient id="monthGradient" x1="0%" y1="0%" x2="100%" y2="0%">
                        <stop offset="0%" style="stop-color:#8b5cf6"/>
                        <stop offset="100%" style="stop-color:#a78bfa"/>
                    </linearGradient>
                </defs>
            </svg>

            <!-- Jauge Seconde -->
            <div class="gauge-item second" onclick="openGaugeModal('second', 'Par seconde', <?= $profitParSeconde ?>, 0.05, 3)" style="cursor:pointer">
                <div class="gauge-speedometer">
                    <svg viewBox="0 0 140 85" preserveAspectRatio="xMidYMid meet">
                        <path class="gauge-bg"
                              d="M 10 75 A <?= $arcRadius ?> <?= $arcRadius ?> 0 0 1 130 75"
                              stroke-dasharray="<?= $arcCircum ?>"
                              stroke-dashoffset="0"/>
                        <path class="gauge-progress" id="gauge-progress-second"
                              d="M 10 75 A <?= $arcRadius ?> <?= $arcRadius ?> 0 0 1 130 75"
                              stroke="url(#secondGradient)"
                              stroke-dasharray="<?= $arcCircum ?>"
                              stroke-dashoffset="<?= $offsetSecond ?>"
                              data-circumference="<?= $arcCircum ?>"
                              data-centerx="70" data-centery="75" data-radius="60"/>
                        <!-- Point % sur l'arc -->
                        <circle class="gauge-percent-dot" cx="<?= $posSecond['x'] ?>" cy="<?= $posSecond['y'] ?>" r="12" fill="#ef4444" id="gauge-dot-second"/>
                        <text class="gauge-percent-arc" x="<?= $posSecond['x'] ?>" y="<?= $posSecond['y'] ?>" id="gauge-percent-second"><?= number_format($pctSecond, 0) ?>%</text>
                    </svg>
                    <div class="gauge-center">
                        <div class="gauge-value"><?= number_format($profitParSeconde, 3, ',', ' ') ?><sup>$</sup></div>
                    </div>
                </div>
                <div class="gauge-label">Par seconde</div>
            </div>

            <!-- Jauge Heure -->
            <div class="gauge-item hour" onclick="openGaugeModal('hour', 'Par heure', <?= $profitParHeure ?>, 150, 0)" style="cursor:pointer">
                <div class="gauge-speedometer">
                    <svg viewBox="0 0 140 85" preserveAspectRatio="xMidYMid meet">
                        <path class="gauge-bg"
                              d="M 10 75 A <?= $arcRadius ?> <?= $arcRadius ?> 0 0 1 130 75"
                              stroke-dasharray="<?= $arcCircum ?>"
                              stroke-dashoffset="0"/>
                        <path class="gauge-progress" id="gauge-progress-hour"
                              d="M 10 75 A <?= $arcRadius ?> <?= $arcRadius ?> 0 0 1 130 75"
                              stroke="url(#hourGradient)"
                              stroke-dasharray="<?= $arcCircum ?>"
                              stroke-dashoffset="<?= $offsetHour ?>"
                              data-circumference="<?= $arcCircum ?>"
                              data-centerx="70" data-centery="75" data-radius="60"/>
                        <circle class="gauge-percent-dot" cx="<?= $posHour['x'] ?>" cy="<?= $posHour['y'] ?>" r="12" fill="#f59e0b" id="gauge-dot-hour"/>
                        <text class="gauge-percent-arc" x="<?= $posHour['x'] ?>" y="<?= $posHour['y'] ?>" id="gauge-percent-hour"><?= number_format($pctHour, 0) ?>%</text>
                    </svg>
                    <div class="gauge-center">
                        <div class="gauge-value"><?= number_format($profitParHeure, 0, ',', ' ') ?><sup>$</sup></div>
                    </div>
                </div>
                <div class="gauge-label">Par heure</div>
            </div>

            <!-- Jauge Semaine -->
            <div class="gauge-item week" onclick="openGaugeModal('week', 'Par semaine', <?= $profitParSemaine ?>, 5000, 0)" style="cursor:pointer">
                <div class="gauge-speedometer">
                    <svg viewBox="0 0 140 85" preserveAspectRatio="xMidYMid meet">
                        <path class="gauge-bg"
                              d="M 10 75 A <?= $arcRadius ?> <?= $arcRadius ?> 0 0 1 130 75"
                              stroke-dasharray="<?= $arcCircum ?>"
                              stroke-dashoffset="0"/>
                        <path class="gauge-progress" id="gauge-progress-week"
                              d="M 10 75 A <?= $arcRadius ?> <?= $arcRadius ?> 0 0 1 130 75"
                              stroke="url(#weekGradient)"
                              stroke-dasharray="<?= $arcCircum ?>"
                              stroke-dashoffset="<?= $offsetWeek ?>"
                              data-circumference="<?= $arcCircum ?>"
                              data-centerx="70" data-centery="75" data-radius="60"/>
                        <circle class="gauge-percent-dot" cx="<?= $posWeek['x'] ?>" cy="<?= $posWeek['y'] ?>" r="12" fill="#10b981" id="gauge-dot-week"/>
                        <text class="gauge-percent-arc" x="<?= $posWeek['x'] ?>" y="<?= $posWeek['y'] ?>" id="gauge-percent-week"><?= number_format($pctWeek, 0) ?>%</text>
                    </svg>
                    <div class="gauge-center">
                        <div class="gauge-value"><?= number_format($profitParSemaine, 0, ',', ' ') ?><sup>$</sup></div>
                    </div>
                </div>
                <div class="gauge-label">Par semaine</div>
            </div>

            <!-- Jauge Mois -->
            <div class="gauge-item month" onclick="openGaugeModal('month', 'Par mois', <?= $profitParMois ?>, 20000, 0)" style="cursor:pointer">
                <div class="gauge-speedometer">
                    <svg viewBox="0 0 140 85" preserveAspectRatio="xMidYMid meet">
                        <path class="gauge-bg"
                              d="M 10 75 A <?= $arcRadius ?> <?= $arcRadius ?> 0 0 1 130 75"
                              stroke-dasharray="<?= $arcCircum ?>"
                              stroke-dashoffset="0"/>
                        <path class="gauge-progress" id="gauge-progress-month"
                              d="M 10 75 A <?= $arcRadius ?> <?= $arcRadius ?> 0 0 1 130 75"
                              stroke="url(#monthGradient)"
                              stroke-dasharray="<?= $arcCircum ?>"
                              stroke-dashoffset="<?= $offsetMonth ?>"
                              data-circumference="<?= $arcCircum ?>"
                              data-centerx="70" data-centery="75" data-radius="60"/>
                        <circle class="gauge-percent-dot" cx="<?= $posMonth['x'] ?>" cy="<?= $posMonth['y'] ?>" r="12" fill="#8b5cf6" id="gauge-dot-month"/>
                        <text class="gauge-percent-arc" x="<?= $posMonth['x'] ?>" y="<?= $posMonth['y'] ?>" id="gauge-percent-month"><?= number_format($pctMonth, 0) ?>%</text>
                    </svg>
                    <div class="gauge-center">
                        <div class="gauge-value"><?= number_format($profitParMois, 0, ',', ' ') ?><sup>$</sup></div>
                    </div>
                </div>
                <div class="gauge-label">Par mois</div>
            </div>
        </div>
    </div>

    <!-- Modal pour objectif jauge -->
    <div class="modal fade" id="gaugeModal" tabindex="-1">
        <div class="modal-dialog modal-sm modal-dialog-centered">
            <div class="modal-content" style="background: #1a2744; border: 1px solid rgba(255,255,255,0.1);">
                <div class="modal-header border-0">
                    <h5 class="modal-title text-white" id="gaugeModalTitle">Objectif</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-3">
                        <div class="text-muted small">Valeur actuelle</div>
                        <div class="text-white fs-4 fw-bold" id="gaugeCurrentValue">0$</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label text-muted small">Objectif visé (100%)</label>
                        <div class="input-group">
                            <input type="number" step="any" class="form-control bg-dark text-white border-secondary" id="gaugeTargetInput">
                            <span class="input-group-text bg-dark text-white border-secondary">$</span>
                        </div>
                    </div>
                    <div class="text-center p-3 rounded" style="background: rgba(255,255,255,0.05);">
                        <div class="text-muted small">Pourcentage atteint</div>
                        <div class="text-success fs-2 fw-bold" id="gaugePercentDisplay">0%</div>
                    </div>
                </div>
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-outline-light btn-sm" data-bs-dismiss="modal">Fermer</button>
                    <button type="button" class="btn btn-primary btn-sm" onclick="saveGaugeTarget()">Sauvegarder</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Mini Stats Cards -->
    <div class="mini-stats mb-4">
        <a href="<?= url('/admin/projets/liste.php') ?>" class="mini-stat-link">
            <div class="mini-stat">
                <div class="mini-stat-icon primary"><i class="bi bi-building"></i></div>
                <div class="mini-stat-content">
                    <h4><?= $totalProjets ?></h4>
                    <p>Projets actifs</p>
                </div>
            </div>
        </a>
        <a href="<?= url('/admin/factures/approuver.php') ?>" class="mini-stat-link">
            <div class="mini-stat">
                <div class="mini-stat-icon warning"><i class="bi bi-clock-history"></i></div>
                <div class="mini-stat-content">
                    <h4><?= $facturesEnAttente ?></h4>
                    <p>Factures en attente</p>
                </div>
            </div>
        </a>
        <a href="<?= url('/admin/factures/liste.php?statut=approuve') ?>" class="mini-stat-link">
            <div class="mini-stat">
                <div class="mini-stat-icon success"><i class="bi bi-check-circle"></i></div>
                <div class="mini-stat-content">
                    <h4><?= $facturesApprouvees ?></h4>
                    <p>Factures approuvées</p>
                </div>
            </div>
        </a>
        <a href="<?= url('/admin/factures/liste.php') ?>" class="mini-stat-link">
            <div class="mini-stat">
                <div class="mini-stat-icon info"><i class="bi bi-cash-stack"></i></div>
                <div class="mini-stat-content">
                    <h4><?= formatMoney($totalDepenses) ?></h4>
                    <p>Total dépensé</p>
                </div>
            </div>
        </a>
    </div>

    <!-- Section Fiscalité -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="fiscal-section">
                <!-- Header avec gradient -->
                <div class="fiscal-header">
                    <h5>
                        <i class="bi bi-graph-up-arrow"></i>
                        Fiscalité
                        <select onchange="window.location.href='?annee='+this.value">
                            <?php foreach ($anneesDisponibles as $annee): ?>
                            <option value="<?= $annee ?>" <?= $annee == $anneeFiscale ? 'selected' : '' ?>><?= $annee ?></option>
                            <?php endforeach; ?>
                        </select>
                    </h5>
                    <div class="fiscal-header-right">
                        <a href="<?= url('/admin/rapports/fiscal-pdf.php?annee=' . $anneeFiscale) ?>"
                           class="btn btn-sm"
                           style="background: rgba(255,255,255,0.15); border: 1px solid rgba(255,255,255,0.3); color: white;"
                           title="Télécharger le rapport PDF">
                            <i class="bi bi-file-pdf"></i> PDF
                        </a>
                        <div class="fiscal-dpe">
                            <div class="fiscal-dpe-label">Seuil DPE utilisé</div>
                            <div class="fiscal-dpe-value"><?= number_format($resumeFiscal['pourcentage_utilise'], 0) ?>%</div>
                        </div>
                    </div>
                </div>

                <div class="fiscal-body">
                    <!-- Jauge de progression -->
                    <?php
                    $gaugeClass = 'safe';
                    if ($resumeFiscal['pourcentage_utilise'] >= 75) $gaugeClass = 'warning';
                    if ($resumeFiscal['pourcentage_utilise'] >= 100) $gaugeClass = 'danger';
                    ?>
                    <div class="fiscal-gauge">
                        <div class="fiscal-gauge-fill <?= $gaugeClass ?>" style="width: <?= min(100, $resumeFiscal['pourcentage_utilise']) ?>%"></div>
                    </div>
                    <div style="display: flex; justify-content: space-between; font-size: 0.75rem; color: var(--text-muted);">
                        <span>0 $</span>
                        <span><?= $resumeFiscal['seuil_restant'] > 0 ? 'Reste ' . formatMoney($resumeFiscal['seuil_restant']) . ' à 12,2%' : 'Seuil atteint - 26,5%' ?></span>
                        <span><?= formatMoney($resumeFiscal['seuil_dpe']) ?></span>
                    </div>

                    <!-- Chiffres clés -->
                    <div class="fiscal-numbers">
                        <div class="fiscal-number">
                            <div class="num"><?= count($resumeFiscal['projets_vendus']) ?></div>
                            <div class="lbl">Flips vendus</div>
                        </div>
                        <div class="fiscal-number highlight-green">
                            <div class="num"><?= formatMoney($resumeFiscal['profit_realise']) ?></div>
                            <div class="lbl">Profit brut</div>
                        </div>
                        <div class="fiscal-number highlight-red">
                            <div class="num"><?= formatMoney($resumeFiscal['impot_realise']) ?></div>
                            <div class="lbl">Impôts</div>
                        </div>
                        <div class="fiscal-number highlight-blue">
                            <div class="num"><?= formatMoney($resumeFiscal['profit_net_realise']) ?></div>
                            <div class="lbl">Profit net</div>
                        </div>
                    </div>

                    <?php if (!empty($resumeFiscal['projets_vendus']) || !empty($resumeFiscal['projets_en_cours'])): ?>
                    <div class="fiscal-projects">
                        <!-- Projets vendus -->
                        <?php if (!empty($resumeFiscal['projets_vendus'])): ?>
                        <div class="fiscal-project-list">
                            <h6><i class="bi bi-check2-circle me-1" style="color: #10b981;"></i> Vendus</h6>
                            <?php
                            $profitCumul = 0;
                            foreach ($resumeFiscal['projets_vendus'] as $pv):
                                $impotProjet = calculerImpotAvecCumulatif($pv['profit'], $profitCumul);
                                $profitCumul = $pv['profit_cumulatif'];
                            ?>
                            <div class="fiscal-project-item">
                                <div class="name">
                                    <a href="<?= url('/admin/projets/detail.php?id=' . $pv['id']) ?>"><?= e($pv['nom']) ?></a>
                                    <div class="date"><?= date('d M', strtotime($pv['date_vente'])) ?></div>
                                </div>
                                <div style="text-align: right;">
                                    <div class="amount <?= $pv['profit'] >= 0 ? 'positive' : 'negative' ?>"><?= formatMoney($pv['profit']) ?></div>
                                    <div class="tax-rate"><?= $impotProjet['taux_affiche'] ?></div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>

                        <!-- Projets en cours -->
                        <?php if (!empty($resumeFiscal['projets_en_cours'])): ?>
                        <div class="fiscal-project-list">
                            <h6><i class="bi bi-hourglass-split me-1" style="color: #f59e0b;"></i> En cours</h6>
                            <?php
                            $profitCumulProjection = $resumeFiscal['profit_realise'];
                            $hasProjects = false;
                            foreach ($resumeFiscal['projets_en_cours'] as $pc):
                                if ($pc['profit_estime'] <= 0) continue;
                                $hasProjects = true;
                                $impotProjection = calculerImpotAvecCumulatif($pc['profit_estime'], $profitCumulProjection);
                            ?>
                            <div class="fiscal-project-item">
                                <div class="name">
                                    <a href="<?= url('/admin/projets/detail.php?id=' . $pc['id']) ?>"><?= e($pc['nom']) ?></a>
                                </div>
                                <div style="text-align: right;">
                                    <div class="amount positive"><?= formatMoney($pc['profit_estime']) ?></div>
                                    <div class="tax-rate"><?= $impotProjection['taux_affiche'] ?></div>
                                </div>
                            </div>
                            <?php
                                $profitCumulProjection += $pc['profit_estime'];
                            endforeach;
                            if (!$hasProjects): ?>
                            <div class="fiscal-empty" style="padding: 1rem;">Aucun projet rentable en cours</div>
                            <?php endif; ?>

                            <?php if ($resumeFiscal['profit_projete'] > 0): ?>
                            <div class="fiscal-summary">
                                <div class="fiscal-summary-row">
                                    <span class="label">Si tous vendus en <?= $anneeFiscale ?></span>
                                    <span class="value"><?= formatMoney($resumeFiscal['profit_total_projection']) ?></span>
                                </div>
                                <div class="fiscal-summary-row">
                                    <span class="label">Impôts estimés</span>
                                    <span class="value" style="color: #ef4444;"><?= formatMoney($resumeFiscal['impot_projection']) ?></span>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php else: ?>
                    <div class="fiscal-empty">
                        <i class="bi bi-calendar-x" style="font-size: 2rem; margin-bottom: 0.5rem;"></i>
                        <div>Aucun projet vendu en <?= $anneeFiscale ?></div>
                    </div>
                    <?php endif; ?>
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
                                            if ($activite['statut'] === 'approuvee') {
                                                $badgeClass = 'bg-success';
                                                $badgeText = 'Approuvé';
                                            } elseif ($activite['statut'] === 'en_attente') {
                                                $badgeClass = 'bg-warning text-dark';
                                                $badgeText = 'En attente';
                                            } elseif ($activite['statut'] === 'rejetee') {
                                                $badgeClass = 'bg-danger';
                                                $badgeText = 'Rejeté';
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

    // Charger les objectifs sauvegardés et mettre à jour les jauges
    loadSavedTargets();

    // Animation d'entrée des jauges
    setTimeout(() => {
        animateGaugesOnLoad();
    }, 300);
});

// Variables globales pour le modal jauge
let currentGaugeType = '';
let currentGaugeValue = 0;
let currentGaugeDecimals = 0;
const currentYear = <?= $anneeFiscale ?>;

// Objectifs par défaut
const defaultTargets = {
    second: 0.05,
    hour: 150,
    week: 5000,
    month: 20000
};

// Clé localStorage avec l'année
function getStorageKey() {
    return 'gaugeTargets_' + currentYear;
}

function openGaugeModal(type, label, value, defaultTarget, decimals) {
    currentGaugeType = type;
    currentGaugeValue = value;
    currentGaugeDecimals = decimals;

    // Récupérer l'objectif sauvegardé ou utiliser le défaut (par année)
    const savedTargets = JSON.parse(localStorage.getItem(getStorageKey()) || '{}');
    const target = savedTargets[type] || defaultTarget;

    // Mettre à jour le modal
    document.getElementById('gaugeModalTitle').textContent = 'Objectif ' + label;
    document.getElementById('gaugeCurrentValue').textContent = formatGaugeValue(value, decimals) + '$';
    document.getElementById('gaugeTargetInput').value = target;

    // Calculer et afficher le pourcentage
    updatePercentDisplay(value, target);

    // Ouvrir le modal
    const modal = new bootstrap.Modal(document.getElementById('gaugeModal'));
    modal.show();

    // Mettre à jour en temps réel quand l'utilisateur tape
    document.getElementById('gaugeTargetInput').oninput = function() {
        const newTarget = parseFloat(this.value) || 0;
        updatePercentDisplay(value, newTarget);
    };
}

function updatePercentDisplay(value, target) {
    let percent = 0;
    if (target > 0) {
        percent = Math.min(100, (value / target) * 100);
    }
    const percentEl = document.getElementById('gaugePercentDisplay');
    percentEl.textContent = percent.toFixed(0) + '%';

    // Changer la couleur selon le pourcentage
    if (percent >= 100) {
        percentEl.className = 'text-success fs-2 fw-bold';
    } else if (percent >= 50) {
        percentEl.className = 'text-warning fs-2 fw-bold';
    } else {
        percentEl.className = 'text-danger fs-2 fw-bold';
    }
}

function formatGaugeValue(value, decimals) {
    return value.toLocaleString('fr-CA', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals
    });
}

function saveGaugeTarget() {
    const target = parseFloat(document.getElementById('gaugeTargetInput').value) || 0;

    // Calculer les objectifs proportionnels pour toutes les jauges
    // Basé sur 40h/semaine, 52 semaines/an, 12 mois/an
    let targets = {};

    if (currentGaugeType === 'second') {
        targets.second = target;
        targets.hour = target * 3600;
        targets.week = target * 3600 * 40;
        targets.month = target * 3600 * 40 * 52 / 12;
    } else if (currentGaugeType === 'hour') {
        targets.second = target / 3600;
        targets.hour = target;
        targets.week = target * 40;
        targets.month = target * 40 * 52 / 12;
    } else if (currentGaugeType === 'week') {
        targets.second = target / 40 / 3600;
        targets.hour = target / 40;
        targets.week = target;
        targets.month = target * 52 / 12;
    } else if (currentGaugeType === 'month') {
        targets.second = target / (52 / 12) / 40 / 3600;
        targets.hour = target / (52 / 12) / 40;
        targets.week = target / (52 / 12);
        targets.month = target;
    }

    // Sauvegarder tous les objectifs dans localStorage (par année)
    localStorage.setItem(getStorageKey(), JSON.stringify(targets));

    // Récupérer les valeurs actuelles
    const gaugeValues = {
        second: <?= $profitParSeconde ?>,
        hour: <?= $profitParHeure ?>,
        week: <?= $profitParSemaine ?>,
        month: <?= $profitParMois ?>
    };

    // Mettre à jour toutes les jauges visuellement
    for (const [type, targetVal] of Object.entries(targets)) {
        updateGaugeVisual(type, gaugeValues[type], targetVal);
    }

    // Fermer le modal
    bootstrap.Modal.getInstance(document.getElementById('gaugeModal')).hide();
}

function updateGaugeVisual(type, value, target) {
    const progressEl = document.getElementById('gauge-progress-' + type);
    const percentEl = document.getElementById('gauge-percent-' + type);
    const dotEl = document.getElementById('gauge-dot-' + type);

    if (progressEl && percentEl && dotEl) {
        const circumference = parseFloat(progressEl.dataset.circumference);
        const centerX = parseFloat(progressEl.dataset.centerx);
        const centerY = parseFloat(progressEl.dataset.centery);
        const radius = parseFloat(progressEl.dataset.radius);

        let percent = 0;
        if (target > 0) {
            percent = Math.min(100, (value / target) * 100);
        }
        const offset = circumference - (circumference * percent / 100);

        // Mettre à jour l'arc
        progressEl.style.strokeDashoffset = offset;

        // Calculer nouvelle position du point sur l'arc
        const angle = Math.PI * (1 - percent / 100);
        const newX = centerX + radius * Math.cos(angle);
        const newY = centerY - radius * Math.sin(angle);

        // Déplacer le point et le texte
        dotEl.setAttribute('cx', newX);
        dotEl.setAttribute('cy', newY);
        percentEl.setAttribute('x', newX);
        percentEl.setAttribute('y', newY);
        percentEl.textContent = percent.toFixed(0) + '%';
    }
}

function loadSavedTargets() {
    const savedTargets = JSON.parse(localStorage.getItem(getStorageKey()) || '{}');

    // Récupérer les valeurs actuelles depuis les attributs onclick
    const gauges = {
        second: { value: <?= $profitParSeconde ?>, default: 0.05 },
        hour: { value: <?= $profitParHeure ?>, default: 150 },
        week: { value: <?= $profitParSemaine ?>, default: 5000 },
        month: { value: <?= $profitParMois ?>, default: 20000 }
    };

    // Mettre à jour chaque jauge avec l'objectif sauvegardé (sans animation initiale)
    for (const [type, data] of Object.entries(gauges)) {
        const target = savedTargets[type] || data.default;
        // Calculer les valeurs finales pour l'animation
        window['gaugeTarget_' + type] = target;
    }
}

function animateGaugesOnLoad() {
    const savedTargets = JSON.parse(localStorage.getItem(getStorageKey()) || '{}');
    const types = ['second', 'hour', 'week', 'month'];
    const defaults = { second: 0.05, hour: 150, week: 5000, month: 20000 };
    const values = {
        second: <?= $profitParSeconde ?>,
        hour: <?= $profitParHeure ?>,
        week: <?= $profitParSemaine ?>,
        month: <?= $profitParMois ?>
    };

    types.forEach((type, index) => {
        const progressEl = document.getElementById('gauge-progress-' + type);
        const percentEl = document.getElementById('gauge-percent-' + type);
        const dotEl = document.getElementById('gauge-dot-' + type);

        if (progressEl && percentEl && dotEl) {
            const target = savedTargets[type] || defaults[type];
            const value = values[type];
            const circumference = parseFloat(progressEl.dataset.circumference);
            const centerX = parseFloat(progressEl.dataset.centerx);
            const centerY = parseFloat(progressEl.dataset.centery);
            const radius = parseFloat(progressEl.dataset.radius);

            let percent = 0;
            if (target > 0) {
                percent = Math.min(100, (value / target) * 100);
            }
            const finalOffset = circumference - (circumference * percent / 100);

            // Position finale du point
            const angle = Math.PI * (1 - percent / 100);
            const finalX = centerX + radius * Math.cos(angle);
            const finalY = centerY - radius * Math.sin(angle);

            // Délai progressif pour chaque jauge
            setTimeout(() => {
                // Activer la transition
                progressEl.classList.add('animated');
                progressEl.style.strokeDashoffset = finalOffset;

                // Animer le point le long de l'arc
                animateDotAlongArc(dotEl, percentEl, centerX, centerY, radius, percent, 1200);

            }, index * 200);
        }
    });
}

function animateDotAlongArc(dotEl, textEl, centerX, centerY, radius, targetPercent, duration) {
    const startTime = performance.now();

    function animate(currentTime) {
        const elapsed = currentTime - startTime;
        const progress = Math.min(elapsed / duration, 1);

        // Easing pour un mouvement fluide
        const easeOut = 1 - Math.pow(1 - progress, 3);
        const currentPercent = easeOut * targetPercent;

        // Calculer la position sur l'arc
        const angle = Math.PI * (1 - currentPercent / 100);
        const x = centerX + radius * Math.cos(angle);
        const y = centerY - radius * Math.sin(angle);

        // Mettre à jour la position
        dotEl.setAttribute('cx', x);
        dotEl.setAttribute('cy', y);
        textEl.setAttribute('x', x);
        textEl.setAttribute('y', y);
        textEl.textContent = Math.round(currentPercent) + '%';

        // Afficher progressivement
        if (progress > 0.1) {
            dotEl.classList.add('visible');
            textEl.classList.add('visible');
        }

        if (progress < 1) {
            requestAnimationFrame(animate);
        } else {
            // Animation pop à la fin
            animateDotPop(dotEl);
        }
    }

    requestAnimationFrame(animate);
}

function animateDotPop(dotEl) {
    const originalRadius = 12;
    const maxRadius = 18;
    const duration = 300;
    const startTime = performance.now();

    function popAnimate(currentTime) {
        const elapsed = currentTime - startTime;
        const progress = Math.min(elapsed / duration, 1);

        // Effet bounce
        let radius;
        if (progress < 0.5) {
            // Grossir
            radius = originalRadius + (maxRadius - originalRadius) * (progress * 2);
        } else {
            // Rétrécir
            radius = maxRadius - (maxRadius - originalRadius) * ((progress - 0.5) * 2);
        }

        dotEl.setAttribute('r', radius);

        if (progress < 1) {
            requestAnimationFrame(popAnimate);
        }
    }

    requestAnimationFrame(popAnimate);
}
</script>

<?php include '../includes/footer.php'; ?>
