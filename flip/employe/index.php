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

$pageTitle = __('dashboard');

// Récupérer les projets actifs
$projets = getProjets($pdo);

// Auto-création table budget_etapes si elle n'existe pas
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS budget_etapes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nom VARCHAR(255) NOT NULL,
        ordre INT DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

// Récupérer les dernières factures de l'employé
$userId = getCurrentUserId();
$mesFactures = [];
try {
    $stmt = $pdo->prepare("
        SELECT f.*, p.nom as projet_nom, e.nom as etape_nom
        FROM factures f
        JOIN projets p ON f.projet_id = p.id
        LEFT JOIN budget_etapes e ON f.etape_id = e.id
        WHERE f.user_id = ?
        ORDER BY f.date_creation DESC
        LIMIT 10
    ");
    $stmt->execute([$userId]);
    $mesFactures = $stmt->fetchAll();
} catch (Exception $e) {
    // Fallback sans étapes
    $stmt = $pdo->prepare("
        SELECT f.*, p.nom as projet_nom, NULL as etape_nom
        FROM factures f
        JOIN projets p ON f.projet_id = p.id
        WHERE f.user_id = ?
        ORDER BY f.date_creation DESC
        LIMIT 10
    ");
    $stmt->execute([$userId]);
    $mesFactures = $stmt->fetchAll();
}

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

// Récupérer les dernières entrées d'heures
$stmt = $pdo->prepare("
    SELECT h.*, p.nom as projet_nom
    FROM heures_travaillees h
    JOIN projets p ON h.projet_id = p.id
    WHERE h.user_id = ?
    ORDER BY h.date_travail DESC, h.date_creation DESC
    LIMIT 10
");
$stmt->execute([$userId]);
$mesHeures = $stmt->fetchAll();

// Vérifier si l'utilisateur est un contremaître (pas accès au pointage)
$stmt = $pdo->prepare("SELECT est_contremaitre FROM users WHERE id = ?");
$stmt->execute([$userId]);
$estContremaitre = (bool)$stmt->fetchColumn();

// Ne pas afficher le pointage aux contremaîtres ni aux admins
$afficherPointage = !$estContremaitre && !isAdmin();

include '../includes/header.php';
?>

<div class="container-fluid">
    <!-- ========================================== -->
    <!-- INTERFACE MOBILE - Deux gros boutons -->
    <!-- ========================================== -->
    <div class="d-md-none mobile-action-menu">
        <div class="text-center mb-4">
            <h4 class="mb-1"><i class="bi bi-person-circle me-2"></i><?= __('hello') ?>, <?= e(getCurrentUserName()) ?></h4>
            <p class="text-muted small mb-0"><?= __('what_to_do') ?></p>
        </div>

        <?php if ($afficherPointage): ?>
        <!-- ========================================== -->
        <!-- POINTAGE - Interface Mobile Simple -->
        <!-- ========================================== -->
        <div class="pointage-section mb-4" id="pointageMobile">
            <div class="card border-0 shadow-lg pointage-card">
                <div class="card-body p-4">
                    <!-- Timer et statut -->
                    <div class="text-center mb-4">
                        <div class="pointage-timer" id="pointageTimerMobile">
                            <span class="timer-display" id="timerDisplayMobile">00:00:00</span>
                        </div>
                        <div class="pointage-projet mt-2 d-none" id="pointageProjetMobile">
                            <i class="bi bi-geo-alt-fill"></i> <span id="projetNomMobile"></span>
                            <div class="small opacity-75" id="projetAdresseActiveMobile"></div>
                        </div>
                        <div class="pointage-status mt-2" id="pointageStatusMobile"></div>
                    </div>

                    <!-- Sélection projet (visible par défaut) -->
                    <div class="mb-4" id="projetSelectContainerMobile">
                        <select class="form-select form-select-lg text-center" id="projetSelectMobile">
                            <option value="">-- Choisir un projet --</option>
                            <?php foreach ($projets as $p): ?>
                                <option value="<?= $p['id'] ?>" data-adresse="<?= e($p['adresse'] ?? '') ?>, <?= e($p['ville'] ?? '') ?>"><?= e($p['nom']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="text-center mt-2 text-white-50 small" id="projetAdresseMobile"></div>
                    </div>

                    <!-- Boutons principaux -->
                    <div class="pointage-buttons" id="pointageBtnsMobile">
                        <!-- Punch In - gros bouton vert (visible par défaut) -->
                        <button type="button" class="btn-punch btn-punch-in" id="btnPunchInMobile">
                            <i class="bi bi-play-circle-fill"></i>
                            <span>PUNCH IN</span>
                        </button>

                        <!-- Break - bouton jaune -->
                        <button type="button" class="btn-punch btn-punch-break d-none" id="btnBreakMobile">
                            <i class="bi bi-cup-hot-fill"></i>
                            <span>BREAK</span>
                        </button>

                        <!-- Punch Out - bouton rouge -->
                        <button type="button" class="btn-punch btn-punch-out d-none" id="btnPunchOutMobile">
                            <i class="bi bi-stop-circle-fill"></i>
                            <span>PUNCH OUT</span>
                        </button>
                    </div>

                    <!-- Bouton Photo (petit) -->
                    <div class="text-center mt-4 d-none" id="photoButtonContainer">
                        <a href="<?= url('/employe/photos.php') ?>" class="btn btn-outline-secondary btn-sm">
                            <i class="bi bi-camera me-1"></i> Prendre photo
                        </a>
                    </div>

                    <!-- Toggle GPS (discret) -->
                    <div class="mt-4 pt-3 border-top">
                        <div class="form-check form-switch d-flex align-items-center justify-content-center gap-2">
                            <input class="form-check-input" type="checkbox" id="gpsToggleMobile" style="width: 2.5em; height: 1.25em;">
                            <label class="form-check-label small text-muted" for="gpsToggleMobile">
                                <i class="bi bi-geo-alt me-1"></i> GPS Auto
                            </label>
                            <span class="badge bg-success ms-1 d-none" id="gpsStatusMobile" style="font-size: 0.65rem;">
                                <i class="bi bi-broadcast"></i>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php else: ?>
        <!-- Interface pour contremaîtres (pas de pointage) -->
        <div class="d-grid gap-3">
            <a href="<?= url('/employe/nouvelle-facture.php') ?>" class="btn btn-primary btn-lg py-4">
                <i class="bi bi-receipt" style="font-size: 2.5rem;"></i>
                <div class="mt-2 fw-bold" style="font-size: 1.2rem;"><?= __('add_invoice') ?></div>
            </a>
            <a href="<?= url('/employe/feuille-temps.php') ?>" class="btn btn-success btn-lg py-4">
                <i class="bi bi-clock-history" style="font-size: 2.5rem;"></i>
                <div class="mt-2 fw-bold" style="font-size: 1.2rem;"><?= __('add_hours') ?></div>
            </a>
            <a href="<?= url('/employe/photos.php') ?>" class="btn btn-warning btn-lg py-4">
                <i class="bi bi-camera" style="font-size: 2.5rem;"></i>
                <div class="mt-2 fw-bold" style="font-size: 1.2rem;"><?= __('take_photos') ?></div>
            </a>
        </div>

        <hr class="my-4">

        <div class="d-flex justify-content-center gap-3 flex-wrap">
            <a href="<?= url('/employe/mes-factures.php') ?>" class="btn btn-outline-secondary">
                <i class="bi bi-receipt me-1"></i><?= __('my_invoices') ?>
            </a>
            <a href="<?= url('/employe/mes-heures.php') ?>" class="btn btn-outline-secondary">
                <i class="bi bi-clock-history me-1"></i><?= __('my_hours') ?>
            </a>
        </div>
        <?php endif; ?>

        <!-- Petit bonhomme qui dit allo -->
        <div class="waving-guy-container">
            <svg class="waving-guy-svg" id="sombreroGuy" width="120" height="150" viewBox="0 0 120 150" style="cursor: pointer;">
                <!-- Sombrero -->
                <ellipse cx="60" cy="28" rx="45" ry="8" fill="#8B4513"/>
                <ellipse cx="60" cy="26" rx="45" ry="6" fill="#A0522D"/>
                <path d="M 35 28 Q 60 -5 85 28" fill="#CD853F"/>
                <path d="M 38 26 Q 60 0 82 26" fill="#DEB887"/>
                <!-- Décoration sombrero -->
                <path d="M 40 22 Q 60 8 80 22" stroke="#e74c3c" stroke-width="3" fill="none"/>
                <circle cx="60" cy="12" r="3" fill="#e74c3c"/>

                <!-- Tête -->
                <circle cx="60" cy="45" r="16" fill="#f4c675" stroke="#d4a655" stroke-width="2"/>
                <!-- Yeux -->
                <circle cx="54" cy="43" r="2.5" fill="#333"/>
                <circle cx="66" cy="43" r="2.5" fill="#333"/>
                <!-- Moustache -->
                <path d="M 50 52 Q 55 56 60 52 Q 65 56 70 52" stroke="#333" stroke-width="2" fill="none" stroke-linecap="round"/>
                <!-- Sourire -->
                <path d="M 52 56 Q 60 63 68 56" stroke="#333" stroke-width="2" fill="none" stroke-linecap="round"/>

                <!-- Corps (poncho) -->
                <path d="M 30 65 L 60 62 L 90 65 L 85 100 L 35 100 Z" fill="#e74c3c"/>
                <path d="M 35 70 L 60 67 L 85 70" stroke="#f1c40f" stroke-width="3" fill="none"/>
                <path d="M 37 80 L 60 77 L 83 80" stroke="#2ecc71" stroke-width="3" fill="none"/>
                <path d="M 38 90 L 60 87 L 82 90" stroke="#f1c40f" stroke-width="3" fill="none"/>

                <!-- Bras gauche (fixe) -->
                <rect x="15" y="72" width="20" height="10" rx="5" fill="#f4c675"/>
                <!-- Main gauche -->
                <circle cx="15" cy="77" r="7" fill="#f4c675"/>

                <!-- Bras droit (qui fait allo) -->
                <g class="waving-arm">
                    <rect x="85" y="65" width="22" height="10" rx="5" fill="#f4c675" transform="rotate(-50, 85, 70)"/>
                    <!-- Main droite qui salue -->
                    <g class="waving-hand-svg">
                        <circle cx="100" cy="42" r="8" fill="#f4c675"/>
                        <!-- Doigts -->
                        <rect x="97" y="28" width="4" height="12" rx="2" fill="#f4c675"/>
                        <rect x="102" y="26" width="4" height="14" rx="2" fill="#f4c675"/>
                        <rect x="107" y="29" width="4" height="10" rx="2" fill="#f4c675"/>
                        <rect x="92" y="31" width="4" height="9" rx="2" fill="#f4c675"/>
                    </g>
                </g>

                <!-- Jambes -->
                <rect x="45" y="100" width="10" height="32" rx="4" fill="#fff"/>
                <rect x="65" y="100" width="10" height="32" rx="4" fill="#fff"/>

                <!-- Sandales -->
                <ellipse cx="50" cy="135" rx="9" ry="5" fill="#8B4513"/>
                <ellipse cx="70" cy="135" rx="9" ry="5" fill="#8B4513"/>
            </svg>
        </div>
    </div>
    
    <!-- ========================================== -->
    <!-- INTERFACE DESKTOP - Dashboard complet -->
    <!-- ========================================== -->
    <div class="d-none d-md-block">
    <!-- En-tête -->
    <div class="page-header d-flex justify-content-between align-items-start">
        <div>
            <h1><i class="bi bi-speedometer2 me-2"></i><?= __('dashboard') ?></h1>
            <p class="text-muted"><?= __('hello') ?>, <?= e(getCurrentUserName()) ?></p>
        </div>
        <div><?= renderLanguageToggle() ?></div>
    </div>

    <?php displayFlashMessage(); ?>

    <?php if ($afficherPointage): ?>
    <!-- ========================================== -->
    <!-- EMPLOYÉ SIMPLE - Seulement le pointage -->
    <!-- ========================================== -->
    <!-- ========================================== -->
    <!-- POINTAGE - Interface Desktop Simple -->
    <!-- ========================================== -->
    <div class="card mb-4 pointage-card-desktop" id="pointageDesktop">
        <div class="card-body py-4">
            <div class="row align-items-center">
                <!-- Timer et statut -->
                <div class="col-md-4 text-center text-md-start mb-3 mb-md-0">
                    <div class="d-flex align-items-center gap-3">
                        <div class="timer-display-desktop" id="timerDisplayDesktop">00:00:00</div>
                        <div>
                            <div class="pointage-status" id="pointageStatusDesktop"></div>
                            <div class="pointage-projet text-muted small d-none" id="pointageProjetDesktop">
                                <i class="bi bi-geo-alt-fill"></i> <span id="projetNomDesktop"></span>
                                <span class="ms-2" id="projetAdresseActiveDesktop"></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Sélection projet -->
                <div class="col-md-4 mb-3 mb-md-0">
                    <div class="d-none" id="projetSelectContainerDesktop">
                        <select class="form-select" id="projetSelectDesktop">
                            <option value="">-- Choisir un projet --</option>
                            <?php foreach ($projets as $p): ?>
                                <option value="<?= $p['id'] ?>" data-adresse="<?= e($p['adresse'] ?? '') ?>, <?= e($p['ville'] ?? '') ?>"><?= e($p['nom']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="small text-muted mt-1" id="projetAdresseDesktop"></div>
                    </div>
                </div>

                <!-- Boutons -->
                <div class="col-md-5 text-center text-md-end">
                    <div class="d-flex gap-2 justify-content-center justify-content-md-end align-items-center" id="pointageBtnsDesktop">
                        <!-- Punch In -->
                        <button type="button" class="btn btn-success btn-lg px-4 d-none" id="btnPunchInDesktop">
                            <i class="bi bi-play-circle-fill me-2"></i>PUNCH IN
                        </button>
                        <!-- Break -->
                        <button type="button" class="btn btn-warning btn-lg px-4 d-none" id="btnBreakDesktop">
                            <i class="bi bi-cup-hot-fill me-2"></i>BREAK
                        </button>
                        <!-- Punch Out -->
                        <button type="button" class="btn btn-danger btn-lg px-4 d-none" id="btnPunchOutDesktop">
                            <i class="bi bi-stop-circle-fill me-2"></i>PUNCH OUT
                        </button>
                        <!-- Photo (petit) -->
                        <a href="<?= url('/employe/photos.php') ?>" class="btn btn-outline-secondary d-none" id="btnPhotoDesktop" title="Prendre photo">
                            <i class="bi bi-camera"></i>
                        </a>
                        <!-- GPS toggle -->
                        <div class="form-check form-switch m-0 ms-2">
                            <input class="form-check-input" type="checkbox" id="gpsToggleDesktop" title="GPS Auto-Punch">
                            <span class="badge bg-success ms-1 d-none" id="gpsStatusDesktop" style="font-size: 0.6rem;">
                                <i class="bi bi-broadcast"></i>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php else: ?>
    <!-- ========================================== -->
    <!-- CONTREMAÎTRE - Interface complète -->
    <!-- ========================================== -->

    <!-- Statistiques -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value"><?= $totalFactures ?></div>
            <div class="stat-label"><?= __('submitted_invoices') ?></div>
        </div>
        <div class="stat-card warning">
            <div class="stat-value"><?= $facturesEnAttente ?></div>
            <div class="stat-label"><?= __('pending') ?></div>
        </div>
        <div class="stat-card success">
            <div class="stat-value"><?= $facturesApprouvees ?></div>
            <div class="stat-label"><?= __('approved') ?></div>
        </div>
        <div class="stat-card primary">
            <div class="stat-value"><?= formatMoney($totalMontant) ?></div>
            <div class="stat-label"><?= __('total_approved') ?></div>
        </div>
    </div>

    <!-- Projets actifs -->
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-building me-2"></i><?= __('active_projects') ?></span>
        </div>
        <div class="card-body">
            <?php if (empty($projets)): ?>
                <div class="empty-state">
                    <i class="bi bi-building"></i>
                    <h4><?= __('no_active_projects') ?></h4>
                    <p><?= __('no_projects_msg') ?></p>
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
                                    <a href="<?= url('/employe/nouvelle-facture.php?projet_id=' . $projet['id']) ?>"
                                       class="btn btn-primary btn-sm">
                                        <i class="bi bi-plus-circle me-1"></i>
                                        <?= __('new_invoice') ?>
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
            <span><i class="bi bi-receipt me-2"></i><?= __('my_last_invoices') ?></span>
            <a href="<?= url('/employe/mes-factures.php') ?>" class="btn btn-outline-primary btn-sm">
                <?= __('see_all') ?>
            </a>
        </div>
        <div class="card-body p-0">
            <?php if (empty($mesFactures)): ?>
                <div class="empty-state">
                    <i class="bi bi-receipt"></i>
                    <h4><?= __('no_invoices') ?></h4>
                    <p><?= __('no_invoice_yet') ?></p>
                    <a href="<?= url('/employe/nouvelle-facture.php') ?>" class="btn btn-primary">
                        <i class="bi bi-plus-circle me-1"></i>
                        <?= __('submit_invoice') ?>
                    </a>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th><?= __('date') ?></th>
                                <th><?= __('project') ?></th>
                                <th><?= __('supplier') ?></th>
                                <th><?= __('category') ?></th>
                                <th class="text-end"><?= __('amount') ?></th>
                                <th class="text-center"><?= __('status') ?></th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($mesFactures as $facture): ?>
                                <tr>
                                    <td><?= formatDate($facture['date_facture']) ?></td>
                                    <td><?= e($facture['projet_nom']) ?></td>
                                    <td><?= e($facture['fournisseur']) ?></td>
                                    <td><?= e($facture['etape_nom'] ?? '-') ?></td>
                                    <td class="text-end"><?= formatMoney($facture['montant_total']) ?></td>
                                    <td class="text-center">
                                        <span class="badge <?= getStatutFactureClass($facture['statut']) ?>">
                                            <?= getStatutFactureIcon($facture['statut']) ?>
                                            <?= getStatutFactureLabel($facture['statut']) ?>
                                        </span>
                                    </td>
                                    <td class="action-buttons">
                                        <?php if ($facture['statut'] === 'en_attente' && canEditFacture($facture['date_creation'])): ?>
                                            <a href="<?= url('/employe/modifier-facture.php?id=' . $facture['id']) ?>" 
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

    <!-- Mes heures -->
    <div class="card mt-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-clock-history me-2"></i><?= __('my_hours') ?></span>
            <div>
                <a href="<?= url('/employe/mes-heures.php') ?>" class="btn btn-outline-primary btn-sm me-1">
                    <?= __('see_all') ?>
                </a>
                <a href="<?= url('/employe/feuille-temps.php') ?>" class="btn btn-primary btn-sm">
                    <i class="bi bi-plus-circle me-1"></i><?= __('add_hours') ?>
                </a>
            </div>
        </div>
        <div class="card-body p-0">
            <?php if (empty($mesHeures)): ?>
                <div class="empty-state">
                    <i class="bi bi-clock"></i>
                    <h4><?= __('no_entries') ?></h4>
                    <p><?= __('no_hours_yet') ?></p>
                    <a href="<?= url('/employe/feuille-temps.php') ?>" class="btn btn-primary">
                        <i class="bi bi-plus-circle me-1"></i>
                        <?= __('add_hours') ?>
                    </a>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th><?= __('date') ?></th>
                                <th><?= __('project') ?></th>
                                <th class="text-end"><?= __('hours') ?></th>
                                <th class="text-center"><?= __('status') ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($mesHeures as $h): ?>
                                <tr>
                                    <td><?= formatDate($h['date_travail']) ?></td>
                                    <td><?= e($h['projet_nom']) ?></td>
                                    <td class="text-end"><strong><?= number_format($h['heures'], 1) ?>h</strong></td>
                                    <td class="text-center">
                                        <span class="badge <?= getStatutFactureClass($h['statut']) ?>">
                                            <?= getStatutFactureLabel($h['statut']) ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    </div><!-- Fin interface desktop -->
</div>

<style>
/* Style pour l'interface mobile */
.mobile-action-menu {
    padding: 1.5rem 0;
    min-height: calc(100vh - 120px);
    display: flex;
    flex-direction: column;
    justify-content: center;
}

.mobile-action-menu .btn-lg {
    border-radius: 1rem;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.15);
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.mobile-action-menu .btn-lg:active {
    transform: scale(0.98);
}

[data-theme="dark"] .mobile-action-menu .btn-lg {
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.4);
}

/* Petit bonhomme SVG qui fait allo */
.waving-guy-container {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 180px;
}

.waving-guy-svg {
    display: inline-block;
}

.waving-guy-svg .waving-arm {
    transform-origin: 85px 70px;
    animation: wave-arm 0.6s ease-in-out infinite;
}

@keyframes wave-arm {
    0%, 100% { transform: rotate(0deg); }
    50% { transform: rotate(-20deg); }
}

/* Réaction au toucher */
.waving-guy-svg.touched {
    animation: jump 0.5s ease-out;
}

.waving-guy-svg.touched .waving-arm {
    animation: wave-fast 0.15s ease-in-out 6;
}

@keyframes jump {
    0% { transform: translateY(0) scale(1); }
    30% { transform: translateY(-20px) scale(1.1); }
    50% { transform: translateY(-15px) scale(1.05); }
    70% { transform: translateY(-5px) scale(1.02); }
    100% { transform: translateY(0) scale(1); }
}

@keyframes wave-fast {
    0%, 100% { transform: rotate(0deg); }
    50% { transform: rotate(-30deg); }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var guy = document.getElementById('sombreroGuy');
    if (guy) {
        var isPlaying = false;

        guy.addEventListener('click', handleTouch);
        guy.addEventListener('touchstart', function(e) {
            e.preventDefault();
            handleTouch();
        });

        function handleTouch() {
            if (isPlaying) return;
            isPlaying = true;

            guy.classList.add('touched');

            if ('speechSynthesis' in window) {
                var msg = new SpeechSynthesisUtterance('Allo Jason!');
                msg.lang = 'fr-CA';
                msg.rate = 1.1;
                msg.pitch = 1.2;
                window.speechSynthesis.speak(msg);
            }

            if (navigator.vibrate) {
                navigator.vibrate(100);
            }

            setTimeout(function() {
                guy.classList.remove('touched');
                isPlaying = false;
            }, 1200);
        }
    }

    // ========================================
    // SYSTÈME DE POINTAGE - Interface Simplifiée
    // ========================================
    <?php if ($afficherPointage): ?>
    const PointageSystem = {
        session: null,
        gpsEnabled: false,
        autoPunchEnabled: false,
        timerInterval: null,
        startTime: null,
        pauseTime: 0,
        watchId: null,
        projetsGPS: [],

        init: function() {
            this.bindEvents();
            this.loadStatus();
            this.loadProjetsGPS();
        },

        bindEvents: function() {
            // Mobile - Nouveaux boutons
            document.getElementById('btnPunchInMobile')?.addEventListener('click', () => this.punch('start'));
            document.getElementById('btnBreakMobile')?.addEventListener('click', () => this.toggleBreak());
            document.getElementById('btnPunchOutMobile')?.addEventListener('click', () => this.punch('stop'));
            document.getElementById('gpsToggleMobile')?.addEventListener('change', (e) => this.toggleGPS(e.target.checked));

            // Desktop - Nouveaux boutons
            document.getElementById('btnPunchInDesktop')?.addEventListener('click', () => this.punch('start'));
            document.getElementById('btnBreakDesktop')?.addEventListener('click', () => this.toggleBreak());
            document.getElementById('btnPunchOutDesktop')?.addEventListener('click', () => this.punch('stop'));
            document.getElementById('gpsToggleDesktop')?.addEventListener('change', (e) => this.toggleGPS(e.target.checked));

            // Sync GPS toggles
            document.getElementById('gpsToggleMobile')?.addEventListener('change', (e) => {
                const desktop = document.getElementById('gpsToggleDesktop');
                if (desktop) desktop.checked = e.target.checked;
            });
            document.getElementById('gpsToggleDesktop')?.addEventListener('change', (e) => {
                const mobile = document.getElementById('gpsToggleMobile');
                if (mobile) mobile.checked = e.target.checked;
            });

            // Afficher l'adresse quand on sélectionne un projet
            document.getElementById('projetSelectMobile')?.addEventListener('change', (e) => {
                this.showProjetAdresse('Mobile', e.target);
                // Sync avec desktop
                const desktop = document.getElementById('projetSelectDesktop');
                if (desktop) {
                    desktop.value = e.target.value;
                    this.showProjetAdresse('Desktop', desktop);
                }
            });
            document.getElementById('projetSelectDesktop')?.addEventListener('change', (e) => {
                this.showProjetAdresse('Desktop', e.target);
                // Sync avec mobile
                const mobile = document.getElementById('projetSelectMobile');
                if (mobile) {
                    mobile.value = e.target.value;
                    this.showProjetAdresse('Mobile', mobile);
                }
            });
        },

        showProjetAdresse: function(view, selectEl) {
            const adresseEl = document.getElementById('projetAdresse' + view);
            if (!adresseEl) return;

            const selectedOption = selectEl.options[selectEl.selectedIndex];
            const adresse = selectedOption?.dataset?.adresse || '';

            if (adresse && adresse !== ', ') {
                adresseEl.innerHTML = '<i class="bi bi-geo-alt me-1"></i>' + adresse;
            } else {
                adresseEl.textContent = '';
            }
        },

        // Toggle Break: si en cours -> pause, si en pause -> resume
        toggleBreak: function() {
            if (this.session) {
                if (this.session.statut === 'en_cours') {
                    this.punch('pause');
                } else if (this.session.statut === 'pause') {
                    this.punch('resume');
                }
            }
        },

        loadStatus: async function() {
            try {
                const response = await fetch('<?= url('/api/pointage.php') ?>?action=status');
                const data = await response.json();

                if (data.success) {
                    this.session = data.session;
                    this.gpsEnabled = data.gps_settings.gps_enabled;
                    this.autoPunchEnabled = data.gps_settings.auto_punch_enabled;

                    // Mettre à jour les toggles GPS
                    document.getElementById('gpsToggleMobile').checked = this.autoPunchEnabled;
                    document.getElementById('gpsToggleDesktop').checked = this.autoPunchEnabled;

                    this.updateUI();

                    // Démarrer le GPS si activé
                    if (this.autoPunchEnabled) {
                        this.startGPSWatch();
                    }
                }
            } catch (error) {
                console.error('Erreur chargement statut:', error);
            }
        },

        loadProjetsGPS: async function() {
            try {
                const response = await fetch('<?= url('/api/pointage.php') ?>?action=projets_gps');
                const data = await response.json();
                if (data.success) {
                    this.projetsGPS = data.projets;
                }
            } catch (error) {
                console.error('Erreur chargement projets GPS:', error);
            }
        },

        updateUI: function() {
            const views = ['Mobile', 'Desktop'];

            views.forEach(view => {
                const statusEl = document.getElementById('pointageStatus' + view);
                const timerDisplay = document.getElementById('timerDisplay' + view);
                const projetEl = document.getElementById('pointageProjet' + view);
                const projetNomEl = document.getElementById('projetNom' + view);
                const projetAdresseActiveEl = document.getElementById('projetAdresseActive' + view);
                const selectContainer = document.getElementById('projetSelectContainer' + view);
                const btnPunchIn = document.getElementById('btnPunchIn' + view);
                const btnBreak = document.getElementById('btnBreak' + view);
                const btnPunchOut = document.getElementById('btnPunchOut' + view);
                const btnPhoto = document.getElementById('btnPhoto' + view);
                const photoContainer = document.getElementById('photoButtonContainer');

                // Cacher tous les boutons
                btnPunchIn?.classList.add('d-none');
                btnBreak?.classList.add('d-none');
                btnPunchOut?.classList.add('d-none');
                btnPhoto?.classList.add('d-none');
                selectContainer?.classList.add('d-none');
                projetEl?.classList.add('d-none');
                photoContainer?.classList.add('d-none');

                if (!this.session || this.session.statut === 'terminee') {
                    // Pas de session ou session terminée - afficher Punch In
                    this.stopTimer();
                    statusEl.innerHTML = '<span class="badge bg-secondary">Pret a travailler</span>';
                    timerDisplay.textContent = '00:00:00';
                    btnPunchIn?.classList.remove('d-none');
                    selectContainer?.classList.remove('d-none');
                    // Réinitialiser la session pour permettre un nouveau punch in
                    this.session = null;
                } else if (this.session.statut === 'en_cours') {
                    // En cours - afficher Break et Punch Out
                    statusEl.innerHTML = '<span class="badge bg-success"><i class="bi bi-circle-fill me-1" style="font-size:0.5rem"></i> En cours</span>';
                    btnBreak?.classList.remove('d-none');
                    btnBreak?.classList.remove('on-break');
                    btnPunchOut?.classList.remove('d-none');
                    btnPhoto?.classList.remove('d-none');
                    photoContainer?.classList.remove('d-none');
                    projetEl?.classList.remove('d-none');
                    if (projetNomEl) projetNomEl.textContent = this.session.projet_nom || '';
                    if (projetAdresseActiveEl) projetAdresseActiveEl.textContent = this.session.projet_adresse || '';
                    // Changer texte du bouton Break
                    const breakBtnMobile = document.getElementById('btnBreakMobile');
                    const breakBtnDesktop = document.getElementById('btnBreakDesktop');
                    if (breakBtnMobile) breakBtnMobile.querySelector('span').textContent = 'BREAK';
                    if (breakBtnDesktop) breakBtnDesktop.innerHTML = '<i class="bi bi-cup-hot-fill me-2"></i>BREAK';

                    this.startTimer();
                } else if (this.session.statut === 'pause') {
                    // En pause - afficher Break (pour reprendre) et Punch Out
                    statusEl.innerHTML = '<span class="badge bg-warning text-dark"><i class="bi bi-pause-fill me-1"></i> En pause</span>';
                    btnBreak?.classList.remove('d-none');
                    btnBreak?.classList.add('on-break');
                    btnPunchOut?.classList.remove('d-none');
                    btnPhoto?.classList.remove('d-none');
                    photoContainer?.classList.remove('d-none');
                    projetEl?.classList.remove('d-none');
                    if (projetNomEl) projetNomEl.textContent = this.session.projet_nom || '';
                    if (projetAdresseActiveEl) projetAdresseActiveEl.textContent = this.session.projet_adresse || '';
                    // Changer texte du bouton Break -> Reprendre
                    const breakBtnMobile = document.getElementById('btnBreakMobile');
                    const breakBtnDesktop = document.getElementById('btnBreakDesktop');
                    if (breakBtnMobile) breakBtnMobile.querySelector('span').textContent = 'REPRENDRE';
                    if (breakBtnDesktop) breakBtnDesktop.innerHTML = '<i class="bi bi-play-fill me-2"></i>REPRENDRE';

                    this.stopTimer();
                    this.displayTime(this.session.duree_travail * 60);
                }
            });
        },

        punch: async function(type) {
            const projetIdMobile = document.getElementById('projetSelectMobile')?.value;
            const projetIdDesktop = document.getElementById('projetSelectDesktop')?.value;
            const projetId = projetIdMobile || projetIdDesktop;

            if (type === 'start' && !projetId) {
                alert('Veuillez selectionner un projet');
                return;
            }

            // Obtenir la position GPS si disponible
            let position = null;
            if (this.gpsEnabled && navigator.geolocation) {
                try {
                    position = await new Promise((resolve, reject) => {
                        navigator.geolocation.getCurrentPosition(resolve, reject, {
                            enableHighAccuracy: true,
                            timeout: 5000,
                            maximumAge: 0
                        });
                    });
                } catch (e) {
                    console.log('GPS non disponible');
                }
            }

            const body = {
                type: type,
                projet_id: type === 'start' ? projetId : null,
                latitude: position?.coords?.latitude || null,
                longitude: position?.coords?.longitude || null,
                precision: position?.coords?.accuracy || null,
                auto_gps: false
            };

            try {
                const response = await fetch('<?= url('/api/pointage.php') ?>', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(body)
                });

                const data = await response.json();

                if (data.success) {
                    this.session = data.session;
                    this.updateUI();

                    // Feedback vibration
                    if (navigator.vibrate) {
                        navigator.vibrate(type === 'stop' ? [100, 50, 100] : 100);
                    }
                } else {
                    alert(data.error || 'Erreur');
                }
            } catch (error) {
                console.error('Erreur pointage:', error);
                alert('Erreur de connexion');
            }
        },

        startTimer: function() {
            if (this.timerInterval) clearInterval(this.timerInterval);

            // Calculer le temps depuis le début
            if (this.session) {
                const startDate = new Date(this.session.heure_debut);
                this.startTime = startDate.getTime();
                this.pauseTime = (this.session.duree_pause || 0) * 60 * 1000;
            }

            this.timerInterval = setInterval(() => {
                const now = Date.now();
                const elapsed = Math.floor((now - this.startTime - this.pauseTime) / 1000);
                this.displayTime(elapsed);
            }, 1000);
        },

        stopTimer: function() {
            if (this.timerInterval) {
                clearInterval(this.timerInterval);
                this.timerInterval = null;
            }
        },

        displayTime: function(seconds) {
            const h = Math.floor(seconds / 3600);
            const m = Math.floor((seconds % 3600) / 60);
            const s = seconds % 60;
            const display = String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');

            const timerMobile = document.getElementById('timerDisplayMobile');
            const timerDesktop = document.getElementById('timerDisplayDesktop');
            if (timerMobile) timerMobile.textContent = display;
            if (timerDesktop) timerDesktop.textContent = display;
        },

        toggleGPS: function(enabled) {
            this.autoPunchEnabled = enabled;

            // Sauvegarder le paramètre
            fetch('<?= url('/api/pointage.php') ?>', {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'update_gps',
                    gps_enabled: enabled ? 1 : 0,
                    auto_punch_enabled: enabled ? 1 : 0
                })
            });

            if (enabled) {
                this.startGPSWatch();
            } else {
                this.stopGPSWatch();
            }
        },

        startGPSWatch: function() {
            if (!navigator.geolocation) {
                alert('GPS non supporte sur cet appareil');
                return;
            }

            const gpsStatusMobile = document.getElementById('gpsStatusMobile');
            if (gpsStatusMobile) {
                gpsStatusMobile.classList.remove('d-none');
                gpsStatusMobile.innerHTML = '<i class="bi bi-broadcast"></i>';
            }

            this.watchId = navigator.geolocation.watchPosition(
                (position) => this.handleGPSPosition(position),
                (error) => console.log('Erreur GPS:', error),
                {
                    enableHighAccuracy: true,
                    maximumAge: 30000,
                    timeout: 30000
                }
            );
        },

        stopGPSWatch: function() {
            if (this.watchId) {
                navigator.geolocation.clearWatch(this.watchId);
                this.watchId = null;
            }

            const gpsStatusMobile = document.getElementById('gpsStatusMobile');
            if (gpsStatusMobile) gpsStatusMobile.classList.add('d-none');
        },

        handleGPSPosition: function(position) {
            const lat = position.coords.latitude;
            const lng = position.coords.longitude;

            // Mettre à jour la position côté serveur
            fetch('<?= url('/api/pointage.php') ?>', {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'update_position',
                    latitude: lat,
                    longitude: lng
                })
            });

            // Vérifier la proximité avec les projets
            if (!this.session) {
                for (const projet of this.projetsGPS) {
                    const distance = this.calculateDistance(lat, lng, parseFloat(projet.latitude), parseFloat(projet.longitude));
                    const rayon = parseInt(projet.rayon_gps) || 100;

                    if (distance <= rayon) {
                        // Auto-punch!
                        this.autoPunch(projet);
                        break;
                    }
                }
            }
        },

        calculateDistance: function(lat1, lng1, lat2, lng2) {
            // Formule Haversine
            const R = 6371000; // Rayon de la Terre en mètres
            const dLat = (lat2 - lat1) * Math.PI / 180;
            const dLng = (lng2 - lng1) * Math.PI / 180;
            const a = Math.sin(dLat/2) * Math.sin(dLat/2) +
                      Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
                      Math.sin(dLng/2) * Math.sin(dLng/2);
            const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
            return R * c;
        },

        autoPunch: async function(projet) {
            if (confirm('Vous etes arrive sur le projet "' + projet.nom + '". Demarrer le pointage?')) {
                // Sélectionner le projet
                const selectMobile = document.getElementById('projetSelectMobile');
                const selectDesktop = document.getElementById('projetSelectDesktop');
                if (selectMobile) selectMobile.value = projet.id;
                if (selectDesktop) selectDesktop.value = projet.id;

                // Obtenir position exacte
                let position = null;
                try {
                    position = await new Promise((resolve, reject) => {
                        navigator.geolocation.getCurrentPosition(resolve, reject, {
                            enableHighAccuracy: true,
                            timeout: 5000
                        });
                    });
                } catch (e) {}

                // Punch avec flag auto_gps
                const body = {
                    type: 'start',
                    projet_id: projet.id,
                    latitude: position?.coords?.latitude || null,
                    longitude: position?.coords?.longitude || null,
                    precision: position?.coords?.accuracy || null,
                    auto_gps: true
                };

                try {
                    const response = await fetch('<?= url('/api/pointage.php') ?>', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(body)
                    });

                    const data = await response.json();
                    if (data.success) {
                        this.session = data.session;
                        this.updateUI();
                        if (navigator.vibrate) navigator.vibrate([100, 50, 100, 50, 100]);
                    }
                } catch (error) {
                    console.error('Erreur auto-punch:', error);
                }
            }
        }
    };

    PointageSystem.init();
    <?php endif; ?>
});
</script>

<style>
/* ========================================== */
/* POINTAGE - Nouveau Design Simplifié */
/* ========================================== */

/* Card pointage mobile */
.pointage-card {
    background: #1a1a2e;
    border-radius: 1.5rem !important;
}

[data-theme="light"] .pointage-card {
    background: #4a5568;
}

/* Timer grand format */
.timer-display {
    font-family: 'SF Mono', 'Courier New', monospace;
    font-size: 3.5rem;
    font-weight: 700;
    color: #fff;
    text-shadow: 0 0 20px rgba(255,255,255,0.3);
    letter-spacing: 2px;
}

.timer-display-desktop {
    font-family: 'SF Mono', 'Courier New', monospace;
    font-size: 2rem;
    font-weight: 700;
    color: var(--text-primary);
}

/* Projet affiché */
.pointage-projet {
    color: rgba(255,255,255,0.7);
    font-size: 0.9rem;
}

.pointage-card-desktop .pointage-projet {
    color: var(--text-muted);
}

/* Status badges */
.pointage-status .badge {
    font-size: 0.75rem;
    padding: 0.4rem 0.8rem;
}

/* Boutons de pointage - Style moderne */
.pointage-buttons {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.btn-punch {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.75rem;
    padding: 1.25rem 2rem;
    border: none;
    border-radius: 1rem;
    font-size: 1.25rem;
    font-weight: 700;
    letter-spacing: 1px;
    cursor: pointer;
    transition: all 0.3s ease;
    box-shadow: 0 4px 15px rgba(0,0,0,0.2);
}

.btn-punch i {
    font-size: 1.5rem;
}

/* Punch In - Vert */
.btn-punch-in {
    background: #00b894;
    color: #fff;
}

.btn-punch-in:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(0, 184, 148, 0.4);
}

.btn-punch-in:active {
    transform: scale(0.98);
}

/* Break - Jaune/Orange */
.btn-punch-break {
    background: #f39c12;
    color: #2d3436;
}

.btn-punch-break:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(243, 156, 18, 0.4);
}

.btn-punch-break.on-break {
    background: #0984e3;
    color: #fff;
    animation: pulse-break 1.5s infinite;
}

@keyframes pulse-break {
    0%, 100% { box-shadow: 0 4px 15px rgba(116, 185, 255, 0.4); }
    50% { box-shadow: 0 4px 25px rgba(116, 185, 255, 0.6); }
}

/* Punch Out - Rouge */
.btn-punch-out {
    background: #d63031;
    color: #fff;
}

.btn-punch-out:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(214, 48, 49, 0.4);
}

/* Desktop card */
.pointage-card-desktop {
    border: none;
    box-shadow: 0 4px 20px var(--shadow-color);
}

.pointage-card-desktop .btn-lg {
    padding: 0.75rem 1.5rem;
}

/* Animation entrée */
.pointage-card {
    animation: slideUp 0.5s ease-out;
}

@keyframes slideUp {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* GPS status actif */
#gpsStatusMobile.active,
#gpsStatusDesktop.active {
    animation: pulse-gps 2s infinite;
}

@keyframes pulse-gps {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
}
</style>

<?php include '../includes/footer.php'; ?>
