<?php
/**
 * Feuille de temps - Employé / Contremaître
 * Flip Manager
 */

require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

requireLogin();

$pageTitle = 'Feuille de temps';
$userId = getCurrentUserId();

// Récupérer les infos de l'utilisateur connecté
$stmt = $pdo->prepare("SELECT taux_horaire, est_contremaitre FROM users WHERE id = ?");
$stmt->execute([$userId]);
$currentUser = $stmt->fetch();
$tauxHoraire = (float)$currentUser['taux_horaire'];
$estContremaitre = !empty($currentUser['est_contremaitre']);

// Si contremaître, récupérer la liste des employés
$employes = [];
if ($estContremaitre) {
    $stmt = $pdo->query("SELECT id, CONCAT(prenom, ' ', nom) as nom_complet, taux_horaire FROM users WHERE actif = 1 ORDER BY prenom, nom");
    $employes = $stmt->fetchAll();
}

$errors = [];

// Traitement de la soumission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token de sécurité invalide.';
    } else {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'ajouter') {
            $projetId = (int)($_POST['projet_id'] ?? 0);
            $dateTravail = $_POST['date_travail'] ?? '';
            $heures = parseNumber($_POST['heures'] ?? 0);
            $description = trim($_POST['description'] ?? '');
            
            // Déterminer pour quel employé on saisit
            $targetUserId = $userId;
            $targetTauxHoraire = $tauxHoraire;
            
            if ($estContremaitre && !empty($_POST['employe_id'])) {
                $targetUserId = (int)$_POST['employe_id'];
                // Récupérer le taux horaire de l'employé cible
                $stmt = $pdo->prepare("SELECT taux_horaire FROM users WHERE id = ? AND actif = 1");
                $stmt->execute([$targetUserId]);
                $targetTauxHoraire = (float)$stmt->fetchColumn();
            }
            
            // Validations
            if ($projetId <= 0) $errors[] = 'Veuillez sélectionner un projet.';
            if (empty($dateTravail)) $errors[] = 'La date est requise.';
            if ($heures <= 0) $errors[] = 'Le nombre d\'heures doit être supérieur à 0.';
            if ($heures > 24) $errors[] = 'Le nombre d\'heures ne peut pas dépasser 24.';
            
            // Vérifier que le projet existe
            if ($projetId > 0) {
                $stmt = $pdo->prepare("SELECT id FROM projets WHERE id = ? AND statut != 'archive'");
                $stmt->execute([$projetId]);
                if (!$stmt->fetch()) {
                    $errors[] = 'Projet invalide.';
                }
            }
            
            if (empty($errors)) {
                $stmt = $pdo->prepare("
                    INSERT INTO heures_travaillees (projet_id, user_id, date_travail, heures, taux_horaire, description)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$projetId, $targetUserId, $dateTravail, $heures, $targetTauxHoraire, $description]);
                
                if ($targetUserId === $userId) {
                    setFlashMessage('success', 'Heures enregistrées avec succès (' . $heures . 'h)');
                } else {
                    // Récupérer le nom de l'employé pour le message
                    $stmt = $pdo->prepare("SELECT CONCAT(prenom, ' ', nom) FROM users WHERE id = ?");
                    $stmt->execute([$targetUserId]);
                    $nomEmploye = $stmt->fetchColumn();
                    setFlashMessage('success', 'Heures enregistrées pour ' . $nomEmploye . ' (' . $heures . 'h)');
                }
                redirect('/employe/feuille-temps.php');
            }
        } elseif ($action === 'modifier') {
            $id = (int)($_POST['temps_id'] ?? 0);
            $projetId = (int)($_POST['projet_id'] ?? 0);
            $dateTravail = $_POST['date_travail'] ?? '';
            $heures = parseNumber($_POST['heures'] ?? 0);
            $description = trim($_POST['description'] ?? '');

            // Vérifier que l'entrée existe et peut être modifiée
            if ($estContremaitre) {
                $stmt = $pdo->prepare("SELECT * FROM heures_travaillees WHERE id = ? AND statut = 'en_attente'");
                $stmt->execute([$id]);
            } else {
                $stmt = $pdo->prepare("SELECT * FROM heures_travaillees WHERE id = ? AND user_id = ? AND statut = 'en_attente'");
                $stmt->execute([$id, $userId]);
            }

            $entree = $stmt->fetch();
            if ($entree) {
                // Validations
                if ($projetId <= 0) $errors[] = 'Veuillez sélectionner un projet.';
                if (empty($dateTravail)) $errors[] = 'La date est requise.';
                if ($heures <= 0) $errors[] = 'Le nombre d\'heures doit être supérieur à 0.';
                if ($heures > 24) $errors[] = 'Le nombre d\'heures ne peut pas dépasser 24.';

                if (empty($errors)) {
                    $stmt = $pdo->prepare("
                        UPDATE heures_travaillees
                        SET projet_id = ?, date_travail = ?, heures = ?, description = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([$projetId, $dateTravail, $heures, $description, $id]);
                    setFlashMessage('success', 'Entrée modifiée avec succès.');
                    redirect('/employe/feuille-temps.php');
                }
            } else {
                setFlashMessage('danger', 'Impossible de modifier cette entrée.');
                redirect('/employe/feuille-temps.php');
            }
        } elseif ($action === 'supprimer') {
            $id = (int)($_POST['temps_id'] ?? 0);

            // Le contremaître peut supprimer les entrées des autres aussi
            if ($estContremaitre) {
                $stmt = $pdo->prepare("SELECT id FROM heures_travaillees WHERE id = ? AND statut = 'en_attente'");
                $stmt->execute([$id]);
            } else {
                $stmt = $pdo->prepare("SELECT id FROM heures_travaillees WHERE id = ? AND user_id = ? AND statut = 'en_attente'");
                $stmt->execute([$id, $userId]);
            }

            if ($stmt->fetch()) {
                $stmt = $pdo->prepare("DELETE FROM heures_travaillees WHERE id = ?");
                $stmt->execute([$id]);
                setFlashMessage('success', 'Entrée supprimée.');
            } else {
                setFlashMessage('danger', 'Impossible de supprimer cette entrée.');
            }
            redirect('/employe/feuille-temps.php');
        }
    }
}

// Récupérer les projets actifs
$projets = getProjets($pdo, true);

// Calculer les totaux (seulement pour ses propres heures)
$stmt = $pdo->prepare("
    SELECT 
        SUM(heures) as total_heures,
        SUM(heures * taux_horaire) as total_montant,
        SUM(CASE WHEN statut = 'en_attente' THEN heures ELSE 0 END) as heures_attente,
        SUM(CASE WHEN statut = 'approuvee' THEN heures ELSE 0 END) as heures_approuvees
    FROM heures_travaillees 
    WHERE user_id = ?
");
$stmt->execute([$userId]);
$totaux = $stmt->fetch();

include '../includes/header.php';
?>

<div class="container-fluid">
    <?php displayFlashMessage(); ?>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger">
            <ul class="mb-0">
                <?php foreach ($errors as $error): ?>
                    <li><?= e($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <!-- ========================================== -->
    <!-- INTERFACE MOBILE - Formulaire plein écran -->
    <!-- ========================================== -->
    <div class="d-md-none mobile-timesheet">
        <div class="text-center mb-4">
            <h4 class="mb-1">
                <i class="bi bi-clock-history me-2"></i><?= __('timesheet') ?>
                <?php if ($estContremaitre): ?>
                    <span class="badge bg-info"><?= __('foreman') ?></span>
                <?php endif; ?>
            </h4>
        </div>

        <form method="POST" action="">
            <?php csrfField(); ?>
            <input type="hidden" name="action" value="ajouter">

            <?php if ($estContremaitre): ?>
                <div class="mb-3">
                    <label class="form-label"><?= __('employee') ?></label>
                    <select class="form-select form-select-lg" name="employe_id" required>
                        <?php foreach ($employes as $emp): ?>
                            <option value="<?= $emp['id'] ?>" <?= $emp['id'] == $userId ? 'selected' : '' ?>>
                                <?= e($emp['nom_complet']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <div class="mb-3">
                <label class="form-label"><?= __('project') ?></label>
                <select class="form-select form-select-lg" name="projet_id" required>
                    <option value=""><?= __('select') ?></option>
                    <?php foreach ($projets as $projet): ?>
                        <option value="<?= $projet['id'] ?>"><?= e($projet['nom']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="mb-3">
                <label class="form-label"><?= __('date') ?></label>
                <input type="date" class="form-control form-control-lg" name="date_travail"
                       value="<?= date('Y-m-d') ?>" required>
            </div>

            <div class="row g-3 mb-4">
                <div class="col-6">
                    <label class="form-label"><?= __('arrival') ?></label>
                    <input type="time" class="form-control form-control-lg text-center" id="heureDebutAddMobile" value="08:00">
                </div>
                <div class="col-6">
                    <label class="form-label"><?= __('departure') ?></label>
                    <input type="time" class="form-control form-control-lg text-center" id="heureFinAddMobile" value="16:00">
                </div>
            </div>

            <input type="hidden" name="heures" id="heuresDirectAddMobile" value="8">

            <button type="submit" class="btn btn-success btn-lg w-100 py-4 mobile-entry-btn">
                <i class="bi bi-box-arrow-in-right" style="font-size: 2rem;"></i>
                <div class="mt-2 fw-bold" style="font-size: 1.3rem;"><?= __('entry') ?></div>
            </button>
        </form>

        <div class="text-center mt-4">
            <a href="<?= url('/employe/') ?>" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i><?= __('back') ?>
            </a>
        </div>
    </div>

    <!-- ========================================== -->
    <!-- INTERFACE DESKTOP -->
    <!-- ========================================== -->
    <div class="d-none d-md-block">
        <!-- En-tête -->
        <div class="page-header">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1>
                        <i class="bi bi-clock-history me-2"></i><?= __('timesheet') ?>
                        <?php if ($estContremaitre): ?>
                            <span class="badge bg-info"><?= __('foreman') ?></span>
                        <?php endif; ?>
                    </h1>
                    <?php if (!$estContremaitre): ?>
                        <p class="text-muted mb-0">
                            <?= __('your_rate') ?> :
                            <?php if ($tauxHoraire > 0): ?>
                                <strong><?= formatMoney($tauxHoraire) ?>/h</strong>
                            <?php else: ?>
                                <span class="text-danger"><?= __('not_defined') ?></span>
                            <?php endif; ?>
                        </p>
                    <?php endif; ?>
                </div>
                <div><?= renderLanguageToggle() ?></div>
            </div>
        </div>

        <!-- Stats (seulement pour ses propres heures, sans $$ si contremaître pour éviter confusion) -->
        <?php if (!$estContremaitre): ?>
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-label"><?= __('total_hours') ?></div>
                    <div class="stat-value"><?= number_format($totaux['total_heures'] ?? 0, 1) ?>h</div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-label"><?= __('pending') ?></div>
                    <div class="stat-value"><?= number_format($totaux['heures_attente'] ?? 0, 1) ?>h</div>
                </div>
                <div class="stat-card success">
                    <div class="stat-label"><?= __('approved') ?></div>
                    <div class="stat-value"><?= number_format($totaux['heures_approuvees'] ?? 0, 1) ?>h</div>
                </div>
                <div class="stat-card primary">
                    <div class="stat-label"><?= __('total_value') ?></div>
                    <div class="stat-value"><?= formatMoney($totaux['total_montant'] ?? 0) ?></div>
                </div>
            </div>
        <?php endif; ?>

        <div class="row">
            <!-- Formulaire de saisie simplifié -->
            <div class="col-lg-4">
                <div class="card">
                    <div class="card-body">
                        <form method="POST" action="">
                            <?php csrfField(); ?>
                            <input type="hidden" name="action" value="ajouter">

                            <?php if ($estContremaitre): ?>
                                <div class="mb-3">
                                    <label class="form-label"><?= __('employee') ?> *</label>
                                    <select class="form-select" name="employe_id" required>
                                        <?php foreach ($employes as $emp): ?>
                                            <option value="<?= $emp['id'] ?>" <?= $emp['id'] == $userId ? 'selected' : '' ?>>
                                                <?= e($emp['nom_complet']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            <?php endif; ?>

                            <div class="mb-3">
                                <label class="form-label"><?= __('project') ?> *</label>
                                <select class="form-select" name="projet_id" required>
                                    <option value=""><?= __('select') ?></option>
                                    <?php foreach ($projets as $projet): ?>
                                        <option value="<?= $projet['id'] ?>"><?= e($projet['nom']) ?> - <?= e($projet['adresse']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label class="form-label"><?= __('date') ?> *</label>
                                <input type="date" class="form-control" name="date_travail"
                                       value="<?= date('Y-m-d') ?>" required>
                            </div>

                            <!-- Saisie arrivée/départ -->
                            <div class="row g-2 mb-3">
                                <div class="col-6">
                                    <label class="form-label"><?= __('arrival') ?></label>
                                    <input type="time" class="form-control" id="heureDebutAdd" value="08:00">
                                </div>
                                <div class="col-6">
                                    <label class="form-label"><?= __('departure') ?></label>
                                    <input type="time" class="form-control" id="heureFinAdd" value="16:00">
                                </div>
                            </div>

                            <!-- Heures calculées (caché) -->
                            <input type="hidden" name="heures" id="heuresDirectAdd" value="8">

                            <button type="submit" class="btn btn-success btn-lg w-100">
                                <i class="bi bi-box-arrow-in-right me-2"></i><?= __('entry') ?>
                            </button>
                        </form>
                    </div>
                </div>
            </div>

        </div>
    </div><!-- Fin interface desktop -->
</div>

<style>
/* Style pour l'interface mobile */
.mobile-timesheet {
    padding: 1rem 0;
    min-height: calc(100vh - 120px);
}

.mobile-timesheet .form-label {
    font-weight: 600;
    margin-bottom: 0.5rem;
}

.mobile-entry-btn {
    border-radius: 1rem;
    box-shadow: 0 4px 15px rgba(40, 167, 69, 0.4);
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.mobile-entry-btn:active {
    transform: scale(0.98);
}

[data-theme="dark"] .mobile-entry-btn {
    box-shadow: 0 4px 15px rgba(40, 167, 69, 0.3);
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Fonction pour calculer les heures entre deux horaires
    function calculerHeures(debut, fin) {
        if (!debut || !fin) return 0;
        var d = debut.split(':');
        var f = fin.split(':');
        var debutMin = parseInt(d[0]) * 60 + parseInt(d[1]);
        var finMin = parseInt(f[0]) * 60 + parseInt(f[1]);
        var diff = (finMin - debutMin) / 60;
        return diff > 0 ? Math.round(diff * 2) / 2 : 0; // Arrondir à 0.5h
    }

    // Formulaire desktop - mise à jour automatique
    var heureDebutAdd = document.getElementById('heureDebutAdd');
    var heureFinAdd = document.getElementById('heureFinAdd');
    var heuresDirectAdd = document.getElementById('heuresDirectAdd');

    if (heureDebutAdd && heureFinAdd && heuresDirectAdd) {
        function updateHeuresAdd() {
            var heures = calculerHeures(heureDebutAdd.value, heureFinAdd.value);
            if (heures > 0) {
                heuresDirectAdd.value = heures;
            }
        }
        heureDebutAdd.addEventListener('change', updateHeuresAdd);
        heureFinAdd.addEventListener('change', updateHeuresAdd);
    }

    // Formulaire mobile - mise à jour automatique
    var heureDebutMobile = document.getElementById('heureDebutAddMobile');
    var heureFinMobile = document.getElementById('heureFinAddMobile');
    var heuresDirectMobile = document.getElementById('heuresDirectAddMobile');

    if (heureDebutMobile && heureFinMobile && heuresDirectMobile) {
        function updateHeuresMobile() {
            var heures = calculerHeures(heureDebutMobile.value, heureFinMobile.value);
            if (heures > 0) {
                heuresDirectMobile.value = heures;
            }
        }
        heureDebutMobile.addEventListener('change', updateHeuresMobile);
        heureFinMobile.addEventListener('change', updateHeuresMobile);
    }
});
</script>

<?php include '../includes/footer.php'; ?>
