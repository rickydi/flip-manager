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
$profitParMinute = $profitNetAnnuel / (52 * 40 * 60); // en minutes

// Extrapolation annuelle basée sur les jours écoulés
$premiereDateVente = null;
$joursEcoules = 0;
$profitExtrapolAnnuel = 0;

if (!empty($resumeFiscal['projets_vendus'])) {
    // Trouver la première date de vente de l'année
    $stmtFirstDate = $pdo->prepare("
        SELECT MIN(date_vente) as first_date
        FROM projets
        WHERE YEAR(date_vente) = ? AND date_vente IS NOT NULL
    ");
    $stmtFirstDate->execute([$anneeFiscale]);
    $result = $stmtFirstDate->fetch();

    if ($result && $result['first_date']) {
        $premiereDateVente = $result['first_date'];
        $dateDebut = new DateTime($premiereDateVente);
        $dateFin = new DateTime(); // Aujourd'hui

        // Si on regarde une année passée, utiliser le 31 décembre
        if ($anneeFiscale < date('Y')) {
            $dateFin = new DateTime($anneeFiscale . '-12-31');
        }

        $joursEcoules = max(1, $dateDebut->diff($dateFin)->days + 1);
        $profitParJour = $profitNetAnnuel / $joursEcoules;
        $profitExtrapolAnnuel = $profitParJour * 365;
    }
}

// Valeurs extrapolées
$profitExtrapolParMois = $profitExtrapolAnnuel / 12;
$profitExtrapolParSemaine = $profitExtrapolAnnuel / 52;
$profitExtrapolParHeure = $profitExtrapolAnnuel / (52 * 40);
$profitExtrapolParMinute = $profitExtrapolAnnuel / (52 * 40 * 60);

// === Notes d'amélioration de l'app ===
// Créer la table si elle n'existe pas
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS app_notes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            contenu TEXT NOT NULL,
            terminee TINYINT(1) DEFAULT 0,
            priorite INT DEFAULT 0,
            user_id INT NULL,
            date_creation DATETIME DEFAULT CURRENT_TIMESTAMP,
            date_modification DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
} catch (Exception $e) {
    // Table existe déjà
}

// Gestion des actions sur les notes
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['note_action'])) {
    $action = $_POST['note_action'];

    if ($action === 'add' && !empty(trim($_POST['note_contenu'] ?? ''))) {
        $stmt = $pdo->prepare("INSERT INTO app_notes (contenu, user_id) VALUES (?, ?)");
        $stmt->execute([trim($_POST['note_contenu']), $_SESSION['user_id']]);
    }

    if ($action === 'toggle' && !empty($_POST['note_id'])) {
        $stmt = $pdo->prepare("UPDATE app_notes SET terminee = NOT terminee, date_modification = NOW() WHERE id = ?");
        $stmt->execute([(int)$_POST['note_id']]);
    }

    if ($action === 'delete' && !empty($_POST['note_id'])) {
        $stmt = $pdo->prepare("DELETE FROM app_notes WHERE id = ?");
        $stmt->execute([(int)$_POST['note_id']]);
    }

    // Redirect pour éviter resoumission
    header('Location: ' . $_SERVER['REQUEST_URI']);
    exit;
}

// Récupérer les notes
$appNotes = [];
try {
    $stmt = $pdo->query("SELECT * FROM app_notes ORDER BY terminee ASC, priorite DESC, date_creation DESC");
    $appNotes = $stmt->fetchAll();
} catch (Exception $e) {
    // Table n'existe pas
}

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

.velocity-controls {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.velocity-toggle {
    display: flex;
    background: rgba(0,0,0,0.3);
    border-radius: 0.5rem;
    padding: 2px;
    border: 1px solid rgba(255,255,255,0.1);
}

.velocity-toggle .toggle-btn {
    padding: 0.25rem 0.6rem;
    font-size: 0.7rem;
    font-weight: 600;
    border: none;
    background: transparent;
    color: #64748b;
    border-radius: 0.4rem;
    cursor: pointer;
    transition: all 0.2s;
}

.velocity-toggle .toggle-btn:hover {
    color: #94a3b8;
}

.velocity-toggle .toggle-btn.active {
    background: linear-gradient(135deg, #10b981, #06b6d4);
    color: #fff;
    box-shadow: 0 2px 8px rgba(16, 185, 129, 0.3);
}

.velocity-extrapol-info {
    margin-top: 0.25rem;
}

.velocity-extrapol-info small {
    display: flex;
    align-items: center;
    gap: 0.3rem;
    font-size: 0.7rem;
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
    overflow: visible;
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

/* Over 100% effect */
.gauge-percent-arc.over100 {
    fill: #000;
    font-weight: 700;
    font-size: 13px;
    animation: pulse-text 1.5s ease-in-out infinite;
}

.gauge-percent-dot.over100 {
    fill: #ffd700 !important;
    filter: drop-shadow(0 0 8px #ffd700) drop-shadow(0 0 15px #ffa500);
    animation: pulse-dot 1.5s ease-in-out infinite;
}

@keyframes pulse-text {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.7; }
}

@keyframes pulse-dot {
    0%, 100% {
        filter: drop-shadow(0 0 8px #ffd700) drop-shadow(0 0 15px #ffa500);
    }
    50% {
        filter: drop-shadow(0 0 15px #ffd700) drop-shadow(0 0 25px #ffa500);
    }
}

/* Fireworks container */
.fireworks-container {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    pointer-events: none;
    z-index: 9999;
    overflow: hidden;
}

.firework {
    position: absolute;
    width: 6px;
    height: 6px;
    border-radius: 50%;
    animation: firework-explode 1s ease-out forwards;
}

@keyframes firework-explode {
    0% {
        transform: scale(1);
        opacity: 1;
    }
    100% {
        transform: scale(0);
        opacity: 0;
    }
}

.sparkle {
    position: absolute;
    width: 4px;
    height: 4px;
    border-radius: 50%;
    animation: sparkle-fly 1.2s ease-out forwards;
}

@keyframes sparkle-fly {
    0% {
        transform: translate(0, 0) scale(1);
        opacity: 1;
    }
    100% {
        opacity: 0;
    }
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
    padding: 0.6rem 1rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 0.5rem;
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
    font-size: 1rem;
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
    font-size: 0.7rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--text-secondary);
    margin-bottom: 0.4rem;
    padding-bottom: 0.3rem;
    border-bottom: 1px solid var(--border-color);
}

.fiscal-project-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.35rem 0;
    border-bottom: 1px dashed var(--border-color);
}

.fiscal-project-item:last-child { border-bottom: none; }

.fiscal-project-item .name {
    font-weight: 500;
    font-size: 0.8rem;
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
    font-size: 0.65rem;
    color: var(--text-muted);
}

.fiscal-project-item .amount {
    font-weight: 600;
    font-size: 0.8rem;
}

.fiscal-project-item .amount.positive { color: #10b981; }
.fiscal-project-item .amount.negative { color: #ef4444; }

.fiscal-project-item .tax-rate {
    font-size: 0.6rem;
    padding: 0.1rem 0.3rem;
    border-radius: 0.2rem;
    background: rgba(100, 116, 139, 0.1);
    color: var(--text-secondary);
}

.fiscal-empty {
    text-align: center;
    padding: 1rem;
    color: var(--text-muted);
}

.fiscal-summary {
    margin-top: 0.5rem;
    padding: 0.5rem;
    background: rgba(59, 130, 246, 0.08);
    border-radius: 0.5rem;
    border: 1px dashed rgba(59, 130, 246, 0.3);
}

.fiscal-summary-row {
    display: flex;
    justify-content: space-between;
    font-size: 0.7rem;
}

.fiscal-summary-row .label { color: var(--text-secondary); }
.fiscal-summary-row .value { font-weight: 600; color: var(--text-primary); }

/* === Section Fiscalité compacte === */
.fiscal-section.compact .fiscal-body {
    padding: 0.75rem;
}

.fiscal-section.compact .fiscal-numbers {
    grid-template-columns: repeat(4, 1fr);
    gap: 0.4rem;
    margin-top: 0.6rem;
}

.fiscal-section.compact .fiscal-number {
    padding: 0.35rem;
}

.fiscal-section.compact .fiscal-number .num {
    font-size: 0.85rem;
}

.fiscal-section.compact .fiscal-number .lbl {
    font-size: 0.55rem;
}

.fiscal-section.compact .fiscal-projects {
    grid-template-columns: 1fr 1fr;
    gap: 0.6rem;
    margin-top: 0.6rem;
}

.fiscal-section.compact .fiscal-project-list {
    /* Pas de scroll - affichage complet */
}

.fiscal-section.compact .fiscal-gauge {
    height: 6px;
}

/* === Section Notes d'amélioration === */
.notes-section {
    background: var(--bg-card);
    border-radius: 1rem;
    overflow: hidden;
    box-shadow: 0 2px 8px var(--shadow-color);
    height: 100%;
    display: flex;
    flex-direction: column;
}

.notes-header {
    background: linear-gradient(135deg, #d97706 0%, #f59e0b 100%);
    color: white;
    padding: 0.6rem 1rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.notes-header h5 {
    margin: 0;
    font-weight: 600;
    font-size: 0.85rem;
    display: flex;
    align-items: center;
    gap: 0.4rem;
}

.notes-body {
    padding: 0.75rem;
    flex: 1;
    /* Pas de scroll - affichage complet */
}

.notes-form {
    display: flex;
    gap: 0.5rem;
    margin-bottom: 0.75rem;
}

.notes-form input {
    flex: 1;
    padding: 0.4rem 0.6rem;
    border: 1px solid var(--border-color);
    border-radius: 0.4rem;
    background: var(--bg-input);
    color: var(--text-primary);
    font-size: 0.8rem;
}

.notes-form input:focus {
    outline: none;
    border-color: #f59e0b;
    box-shadow: 0 0 0 2px rgba(245, 158, 11, 0.15);
}

.notes-form button {
    padding: 0.4rem 0.6rem;
    border: none;
    border-radius: 0.4rem;
    background: #f59e0b;
    color: white;
    cursor: pointer;
    transition: background 0.2s;
}

.notes-form button:hover {
    background: #d97706;
}

.notes-list {
    list-style: none;
    padding: 0;
    margin: 0;
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 0.4rem;
}

.note-item {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.4rem 0.6rem;
    background: rgba(245, 158, 11, 0.05);
    border-radius: 0.4rem;
    border-left: 3px solid #f59e0b;
}

.note-item.terminee {
    background: rgba(100, 116, 139, 0.05);
    border-left-color: var(--text-muted);
}

.note-item.terminee .note-text {
    text-decoration: line-through;
    opacity: 0.5;
}

.note-checkbox {
    flex-shrink: 0;
}

.note-checkbox input {
    width: 14px;
    height: 14px;
    cursor: pointer;
    accent-color: #f59e0b;
}

.note-text {
    flex: 1;
    font-size: 0.75rem;
    color: var(--text-primary);
    word-break: break-word;
    line-height: 1.3;
}

.note-delete {
    flex-shrink: 0;
    background: none;
    border: none;
    color: var(--text-muted);
    cursor: pointer;
    padding: 0.15rem;
    opacity: 0.4;
    transition: opacity 0.2s, color 0.2s;
    font-size: 0.7rem;
}

.note-delete:hover {
    opacity: 1;
    color: #ef4444;
}

.notes-empty {
    text-align: center;
    padding: 2rem;
    color: var(--text-muted);
}

.notes-empty i {
    font-size: 2rem;
    margin-bottom: 0.5rem;
    display: block;
}
</style>

<div class="container-fluid">
    <?php displayFlashMessage(); ?>

    <!-- Tachymètre Vélocité Profit -->
    <?php
    // Calcul pour les demi-cercles (arc de 180 degrés)
    $arcRadius = 60;
    $arcCircum = M_PI * $arcRadius; // Demi-circonférence

    // Pourcentages pour chaque jauge (réel)
    $pctMinute = min(100, max(0, ($profitParMinute / 3) * 100)); // Objectif 3$/minute
    $pctHour = min(100, max(0, ($profitParHeure / 150) * 100)); // Objectif 150$/h
    $pctWeek = min(100, max(0, ($profitParSemaine / 5000) * 100)); // Objectif 5000$/semaine
    $pctMonth = min(100, max(0, ($profitParMois / 20000) * 100)); // Objectif 20000$/mois

    // Offsets pour les arcs SVG
    $offsetMinute = $arcCircum - ($arcCircum * $pctMinute / 100);
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
    $posMinute = getArcEndPosition($pctMinute);
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
            <div class="velocity-controls">
                <div class="velocity-toggle" id="velocityToggle">
                    <button type="button" class="toggle-btn active" data-mode="reel">Réel</button>
                    <button type="button" class="toggle-btn" data-mode="extrapole">Extrapolé</button>
                </div>
                <select class="velocity-year-select" onchange="window.location.href='?annee='+this.value">
                    <?php foreach ($anneesDisponibles as $annee): ?>
                    <option value="<?= $annee ?>" <?= $annee == $anneeFiscale ? 'selected' : '' ?>><?= $annee ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($joursEcoules > 0): ?>
            <div class="velocity-extrapol-info" id="extrapolInfo" style="display:none;">
                <small class="text-info">
                    <i class="bi bi-graph-up-arrow"></i>
                    Basé sur <?= $joursEcoules ?> jours → <?= formatMoney($profitExtrapolAnnuel) ?>/an
                </small>
                <small class="text-muted d-block" style="font-size: 0.65rem;">
                    <?= formatMoney($profitNetAnnuel) ?> de profit net réalisé
                </small>
            </div>
            <?php endif; ?>
        </div>

        <div class="speedometer-container">
            <!-- Defs pour les gradients -->
            <svg style="position:absolute;width:0;height:0;">
                <defs>
                    <linearGradient id="minuteGradient" x1="0%" y1="0%" x2="100%" y2="0%">
                        <stop offset="0%" style="stop-color:#f59e0b"/>
                        <stop offset="100%" style="stop-color:#fbbf24"/>
                    </linearGradient>
                    <linearGradient id="hourGradient" x1="0%" y1="0%" x2="100%" y2="0%">
                        <stop offset="0%" style="stop-color:#f59e0b"/>
                        <stop offset="100%" style="stop-color:#fbbf24"/>
                    </linearGradient>
                    <linearGradient id="weekGradient" x1="0%" y1="0%" x2="100%" y2="0%">
                        <stop offset="0%" style="stop-color:#f59e0b"/>
                        <stop offset="100%" style="stop-color:#fbbf24"/>
                    </linearGradient>
                    <linearGradient id="monthGradient" x1="0%" y1="0%" x2="100%" y2="0%">
                        <stop offset="0%" style="stop-color:#f59e0b"/>
                        <stop offset="100%" style="stop-color:#fbbf24"/>
                    </linearGradient>
                </defs>
            </svg>

            <!-- Jauge Minute -->
            <div class="gauge-item minute" onclick="openGaugeModal('minute', 'Par minute', <?= $profitParMinute ?>, 3, 2)" style="cursor:pointer">
                <div class="gauge-speedometer">
                    <svg viewBox="0 0 140 85" preserveAspectRatio="xMidYMid meet">
                        <path class="gauge-bg"
                              d="M 10 75 A <?= $arcRadius ?> <?= $arcRadius ?> 0 0 1 130 75"
                              stroke-dasharray="<?= $arcCircum ?>"
                              stroke-dashoffset="0"/>
                        <path class="gauge-progress" id="gauge-progress-minute"
                              d="M 10 75 A <?= $arcRadius ?> <?= $arcRadius ?> 0 0 1 130 75"
                              stroke="url(#minuteGradient)"
                              stroke-dasharray="<?= $arcCircum ?>"
                              stroke-dashoffset="<?= $offsetMinute ?>"
                              data-circumference="<?= $arcCircum ?>"
                              data-centerx="70" data-centery="75" data-radius="60"/>
                        <!-- Point % sur l'arc -->
                        <circle class="gauge-percent-dot" cx="<?= $posMinute['x'] ?>" cy="<?= $posMinute['y'] ?>" r="12" fill="#f59e0b" id="gauge-dot-minute"/>
                        <text class="gauge-percent-arc" x="<?= $posMinute['x'] ?>" y="<?= $posMinute['y'] ?>" id="gauge-percent-minute"><?= number_format($pctMinute, 0) ?>%</text>
                    </svg>
                    <div class="gauge-center">
                        <div class="gauge-value" id="gauge-value-minute"><?= number_format($profitParMinute, 2, ',', ' ') ?><sup>$</sup></div>
                    </div>
                </div>
                <div class="gauge-label">Par minute</div>
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
                        <div class="gauge-value" id="gauge-value-hour"><?= number_format($profitParHeure, 0, ',', ' ') ?><sup>$</sup></div>
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
                        <circle class="gauge-percent-dot" cx="<?= $posWeek['x'] ?>" cy="<?= $posWeek['y'] ?>" r="12" fill="#f59e0b" id="gauge-dot-week"/>
                        <text class="gauge-percent-arc" x="<?= $posWeek['x'] ?>" y="<?= $posWeek['y'] ?>" id="gauge-percent-week"><?= number_format($pctWeek, 0) ?>%</text>
                    </svg>
                    <div class="gauge-center">
                        <div class="gauge-value" id="gauge-value-week"><?= number_format($profitParSemaine, 0, ',', ' ') ?><sup>$</sup></div>
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
                        <circle class="gauge-percent-dot" cx="<?= $posMonth['x'] ?>" cy="<?= $posMonth['y'] ?>" r="12" fill="#f59e0b" id="gauge-dot-month"/>
                        <text class="gauge-percent-arc" x="<?= $posMonth['x'] ?>" y="<?= $posMonth['y'] ?>" id="gauge-percent-month"><?= number_format($pctMonth, 0) ?>%</text>
                    </svg>
                    <div class="gauge-center">
                        <div class="gauge-value" id="gauge-value-month"><?= number_format($profitParMois, 0, ',', ' ') ?><sup>$</sup></div>
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

    <!-- Section Fiscalité + Notes -->
    <div class="row mb-4">
        <!-- Notes d'amélioration (50%) -->
        <div class="col-md-6 mb-3 mb-md-0">
            <div class="notes-section">
                <div class="notes-header">
                    <h5>
                        <i class="bi bi-lightbulb"></i>
                        App à améliorer
                    </h5>
                    <span class="badge bg-light text-dark"><?= count(array_filter($appNotes, fn($n) => !$n['terminee'])) ?> en cours</span>
                </div>
                <div class="notes-body">
                    <form method="POST" class="notes-form">
                        <input type="hidden" name="note_action" value="add">
                        <input type="text" name="note_contenu" placeholder="Nouvelle idée d'amélioration..." required>
                        <button type="submit"><i class="bi bi-plus-lg"></i></button>
                    </form>

                    <?php if (empty($appNotes)): ?>
                    <div class="notes-empty">
                        <i class="bi bi-lightbulb"></i>
                        <div>Aucune note pour le moment</div>
                        <small>Ajoutez vos idées d'amélioration</small>
                    </div>
                    <?php else: ?>
                    <ul class="notes-list">
                        <?php foreach ($appNotes as $note): ?>
                        <li class="note-item <?= $note['terminee'] ? 'terminee' : '' ?>">
                            <form method="POST" class="note-checkbox">
                                <input type="hidden" name="note_action" value="toggle">
                                <input type="hidden" name="note_id" value="<?= $note['id'] ?>">
                                <input type="checkbox" <?= $note['terminee'] ? 'checked' : '' ?> onchange="this.form.submit()" title="Marquer comme terminé">
                            </form>
                            <span class="note-text"><?= e($note['contenu']) ?></span>
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="note_action" value="delete">
                                <input type="hidden" name="note_id" value="<?= $note['id'] ?>">
                                <button type="submit" class="note-delete" title="Supprimer">
                                    <i class="bi bi-x-lg"></i>
                                </button>
                            </form>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Fiscalité (50%) -->
        <div class="col-md-6">
            <div class="fiscal-section compact">
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

    if (currentGaugeType === 'minute') {
        targets.minute = target;
        targets.hour = target * 60;
        targets.week = target * 60 * 40;
        targets.month = target * 60 * 40 * 52 / 12;
    } else if (currentGaugeType === 'hour') {
        targets.minute = target / 60;
        targets.hour = target;
        targets.week = target * 40;
        targets.month = target * 40 * 52 / 12;
    } else if (currentGaugeType === 'week') {
        targets.minute = target / 40 / 60;
        targets.hour = target / 40;
        targets.week = target;
        targets.month = target * 52 / 12;
    } else if (currentGaugeType === 'month') {
        targets.minute = target / (52 / 12) / 40 / 60;
        targets.hour = target / (52 / 12) / 40;
        targets.week = target / (52 / 12);
        targets.month = target;
    }

    // Sauvegarder tous les objectifs dans localStorage (par année)
    localStorage.setItem(getStorageKey(), JSON.stringify(targets));

    // Récupérer les valeurs actuelles selon le mode
    const isExtrapol = document.querySelector('.velocity-toggle .toggle-btn.active')?.dataset.mode === 'extrapole';
    const gaugeValues = isExtrapol ? gaugeValuesExtrapol : gaugeValuesReel;

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

        let realPercent = 0;
        if (target > 0) {
            realPercent = (value / target) * 100;
        }
        const visualPercent = Math.min(100, realPercent); // Position capped at 100%
        const offset = circumference - (circumference * visualPercent / 100);

        // Mettre à jour l'arc
        progressEl.style.strokeDashoffset = offset;

        // Calculer nouvelle position du point sur l'arc
        const angle = Math.PI * (1 - visualPercent / 100);
        const newX = centerX + radius * Math.cos(angle);
        const newY = centerY - radius * Math.sin(angle);

        // Déplacer le point et le texte
        dotEl.setAttribute('cx', newX);
        dotEl.setAttribute('cy', newY);
        percentEl.setAttribute('x', newX);
        percentEl.setAttribute('y', newY);
        percentEl.textContent = Math.round(realPercent) + '%';

        // Effet over 100%
        if (realPercent >= 100) {
            dotEl.classList.add('over100');
            percentEl.classList.add('over100');
            dotEl.setAttribute('r', 16); // Plus gros
        } else {
            dotEl.classList.remove('over100');
            percentEl.classList.remove('over100');
            dotEl.setAttribute('r', 12); // Taille normale
        }
    }
}

// Valeurs réelles et extrapolées
const gaugeValuesReel = {
    minute: <?= $profitParMinute ?>,
    hour: <?= $profitParHeure ?>,
    week: <?= $profitParSemaine ?>,
    month: <?= $profitParMois ?>
};

const gaugeValuesExtrapol = {
    minute: <?= $profitExtrapolParMinute ?>,
    hour: <?= $profitExtrapolParHeure ?>,
    week: <?= $profitExtrapolParSemaine ?>,
    month: <?= $profitExtrapolParMois ?>
};

const gaugeDefaults = { minute: 3, hour: 150, week: 5000, month: 20000 };
const gaugeTypes = ['minute', 'hour', 'week', 'month'];
const gaugeColors = { minute: '#ef4444', hour: '#f59e0b', week: '#10b981', month: '#8b5cf6' };

function loadSavedTargets() {
    const savedTargets = JSON.parse(localStorage.getItem(getStorageKey()) || '{}');

    // Mettre à jour chaque jauge avec l'objectif sauvegardé (sans animation initiale)
    for (const type of gaugeTypes) {
        const target = savedTargets[type] || gaugeDefaults[type];
        window['gaugeTarget_' + type] = target;
    }
}

function animateGaugesOnLoad() {
    const savedTargets = JSON.parse(localStorage.getItem(getStorageKey()) || '{}');
    const values = gaugeValuesReel;

    gaugeTypes.forEach((type, index) => {
        const progressEl = document.getElementById('gauge-progress-' + type);
        const percentEl = document.getElementById('gauge-percent-' + type);
        const dotEl = document.getElementById('gauge-dot-' + type);

        if (progressEl && percentEl && dotEl) {
            const target = savedTargets[type] || gaugeDefaults[type];
            const value = values[type];
            const circumference = parseFloat(progressEl.dataset.circumference);
            const centerX = parseFloat(progressEl.dataset.centerx);
            const centerY = parseFloat(progressEl.dataset.centery);
            const radius = parseFloat(progressEl.dataset.radius);

            let realPercent = 0;
            if (target > 0) {
                realPercent = (value / target) * 100;
            }
            const visualPercent = Math.min(100, realPercent); // Position capped at 100%
            const finalOffset = circumference - (circumference * visualPercent / 100);

            // Position finale du point (capped à 100%)
            const angle = Math.PI * (1 - visualPercent / 100);
            const finalX = centerX + radius * Math.cos(angle);
            const finalY = centerY - radius * Math.sin(angle);

            // Délai progressif pour chaque jauge
            setTimeout(() => {
                // Activer la transition
                progressEl.classList.add('animated');
                progressEl.style.strokeDashoffset = finalOffset;

                // Animer le point le long de l'arc (avec le vrai %)
                animateDotAlongArc(dotEl, percentEl, centerX, centerY, radius, realPercent, 1200);

            }, index * 200);
        }
    });
}

function animateDotAlongArc(dotEl, textEl, centerX, centerY, radius, targetPercent, duration) {
    const startTime = performance.now();
    const isOver100 = targetPercent >= 100;
    const visualPercent = Math.min(targetPercent, 100); // Position capped at 100%

    function animate(currentTime) {
        const elapsed = currentTime - startTime;
        const progress = Math.min(elapsed / duration, 1);

        // Easing pour un mouvement fluide
        const easeOut = 1 - Math.pow(1 - progress, 3);
        const currentVisualPercent = easeOut * visualPercent;
        const currentDisplayPercent = easeOut * targetPercent; // Affichage réel

        // Calculer la position sur l'arc (capped à 100%)
        const angle = Math.PI * (1 - currentVisualPercent / 100);
        const x = centerX + radius * Math.cos(angle);
        const y = centerY - radius * Math.sin(angle);

        // Mettre à jour la position
        dotEl.setAttribute('cx', x);
        dotEl.setAttribute('cy', y);
        textEl.setAttribute('x', x);
        textEl.setAttribute('y', y);
        textEl.textContent = Math.round(currentDisplayPercent) + '%';

        // Afficher progressivement
        if (progress > 0.1) {
            dotEl.classList.add('visible');
            textEl.classList.add('visible');
        }

        // Ajouter effet over100
        if (isOver100 && progress > 0.8) {
            dotEl.classList.add('over100');
            textEl.classList.add('over100');
            dotEl.setAttribute('r', 16); // Plus gros quand over 100%
        }

        if (progress < 1) {
            requestAnimationFrame(animate);
        } else {
            // Animation pop à la fin
            animateDotPop(dotEl, isOver100);

            // Feux d'artifice si >= 100%
            if (isOver100) {
                const rect = dotEl.getBoundingClientRect();
                launchFireworks(rect.left + rect.width/2, rect.top + rect.height/2);
            }
        }
    }

    requestAnimationFrame(animate);
}

function animateDotPop(dotEl, isOver100 = false) {
    const originalRadius = isOver100 ? 16 : 12;
    const maxRadius = isOver100 ? 22 : 18;
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

// Toggle Réel / Extrapolé
function initVelocityToggle() {
    const toggle = document.getElementById('velocityToggle');
    const extrapolInfo = document.getElementById('extrapolInfo');
    if (!toggle) return;

    toggle.querySelectorAll('.toggle-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            toggle.querySelectorAll('.toggle-btn').forEach(b => b.classList.remove('active'));
            this.classList.add('active');

            const mode = this.dataset.mode;
            const isExtrapol = mode === 'extrapole';
            const values = isExtrapol ? gaugeValuesExtrapol : gaugeValuesReel;

            // Afficher/cacher info extrapolation
            if (extrapolInfo) {
                extrapolInfo.style.display = isExtrapol ? 'block' : 'none';
            }

            // Mettre à jour les valeurs affichées et les jauges
            const savedTargets = JSON.parse(localStorage.getItem(getStorageKey()) || '{}');

            gaugeTypes.forEach(type => {
                const target = savedTargets[type] || gaugeDefaults[type];
                const value = values[type];

                // Mettre à jour la valeur affichée
                const valueEl = document.getElementById('gauge-value-' + type);
                if (valueEl) {
                    if (type === 'minute') {
                        valueEl.innerHTML = formatNumber(value, 2) + '<sup>$</sup>';
                    } else {
                        valueEl.innerHTML = formatNumber(value, 0) + '<sup>$</sup>';
                    }
                }

                // Mettre à jour la jauge visuellement
                updateGaugeVisual(type, value, target);
            });
        });
    });
}

function formatNumber(num, decimals) {
    return num.toLocaleString('fr-CA', { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
}

// Feux d'artifice
function launchFireworks(x, y) {
    // Créer le container s'il n'existe pas
    let container = document.querySelector('.fireworks-container');
    if (!container) {
        container = document.createElement('div');
        container.className = 'fireworks-container';
        document.body.appendChild(container);
    }

    const colors = ['#ffd700', '#ff6b6b', '#4ecdc4', '#45b7d1', '#96ceb4', '#ffeaa7', '#dfe6e9', '#ff7675', '#74b9ff', '#a29bfe'];
    const particleCount = 30;

    // Créer les particules
    for (let i = 0; i < particleCount; i++) {
        const particle = document.createElement('div');
        particle.className = 'sparkle';
        particle.style.left = x + 'px';
        particle.style.top = y + 'px';
        particle.style.backgroundColor = colors[Math.floor(Math.random() * colors.length)];

        // Direction aléatoire
        const angle = (Math.PI * 2 * i) / particleCount + (Math.random() - 0.5) * 0.5;
        const velocity = 80 + Math.random() * 80;
        const endX = Math.cos(angle) * velocity;
        const endY = Math.sin(angle) * velocity - 30; // Légère gravité inverse

        particle.style.setProperty('--end-x', endX + 'px');
        particle.style.setProperty('--end-y', endY + 'px');
        particle.style.animation = `sparkle-fly 1.2s ease-out forwards`;
        particle.style.transform = `translate(${endX}px, ${endY}px)`;

        container.appendChild(particle);

        // Nettoyer après l'animation
        setTimeout(() => particle.remove(), 1200);
    }

    // Ajouter quelques grosses particules
    for (let i = 0; i < 8; i++) {
        const bigParticle = document.createElement('div');
        bigParticle.className = 'firework';
        bigParticle.style.left = (x + (Math.random() - 0.5) * 60) + 'px';
        bigParticle.style.top = (y + (Math.random() - 0.5) * 60) + 'px';
        bigParticle.style.backgroundColor = colors[Math.floor(Math.random() * colors.length)];
        bigParticle.style.width = (8 + Math.random() * 8) + 'px';
        bigParticle.style.height = bigParticle.style.width;
        bigParticle.style.boxShadow = `0 0 ${10 + Math.random() * 10}px ${bigParticle.style.backgroundColor}`;

        container.appendChild(bigParticle);
        setTimeout(() => bigParticle.remove(), 1000);
    }
}

// Initialiser le toggle au chargement
document.addEventListener('DOMContentLoaded', initVelocityToggle);
</script>

<?php include '../includes/footer.php'; ?>
