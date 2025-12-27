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
    if (!guy) return;

    var isPlaying = false;

    guy.addEventListener('click', handleTouch);
    guy.addEventListener('touchstart', function(e) {
        e.preventDefault();
        handleTouch();
    });

    function handleTouch() {
        if (isPlaying) return;
        isPlaying = true;

        // Animation de saut
        guy.classList.add('touched');

        // Dire "Allo Jason!" avec synthèse vocale
        if ('speechSynthesis' in window) {
            var msg = new SpeechSynthesisUtterance('Allo Jason!');
            msg.lang = 'fr-CA';
            msg.rate = 1.1;
            msg.pitch = 1.2;
            window.speechSynthesis.speak(msg);
        }

        // Faire vibrer sur mobile (si supporté)
        if (navigator.vibrate) {
            navigator.vibrate(100);
        }

        // Retirer la classe après l'animation
        setTimeout(function() {
            guy.classList.remove('touched');
            isPlaying = false;
        }, 1200);
    }
});
</script>

<?php include '../includes/footer.php'; ?>
