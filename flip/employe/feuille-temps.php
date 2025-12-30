<?php
/**
 * Feuille de temps - Employé / Contremaître
 * Flip Manager
 */

require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/notifications.php';

requireLogin();

$pageTitle = 'Feuille de temps';
$userId = getCurrentUserId();

// Récupérer les infos de l'utilisateur connecté
$stmt = $pdo->prepare("SELECT taux_horaire, est_contremaitre FROM users WHERE id = ?");
$stmt->execute([$userId]);
$currentUser = $stmt->fetch();
$tauxHoraire = (float)$currentUser['taux_horaire'];
$estContremaitre = !empty($currentUser['est_contremaitre']) || isAdmin();

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

            // Validations communes
            if ($projetId <= 0) $errors[] = 'Veuillez sélectionner un projet.';
            if (empty($dateTravail)) $errors[] = 'La date est requise.';
            if ($heures <= 0) $errors[] = 'Le nombre d\'heures doit être supérieur à 0.';
            if ($heures > 24) $errors[] = 'Le nombre d\'heures ne peut pas dépasser 24.';

            // Vérifier que le projet existe
            if ($projetId > 0) {
                $stmt = $pdo->prepare("SELECT id, nom FROM projets WHERE id = ? AND statut != 'archive'");
                $stmt->execute([$projetId]);
                $projetData = $stmt->fetch();
                if (!$projetData) {
                    $errors[] = 'Projet invalide.';
                }
            }

            if (empty($errors)) {
                // Gérer la sélection multiple d'employés (contremaître seulement)
                $targetUserIds = [];

                if ($estContremaitre && !empty($_POST['employes_ids']) && is_array($_POST['employes_ids'])) {
                    // Mode multi-employés
                    $targetUserIds = array_map('intval', $_POST['employes_ids']);
                } elseif ($estContremaitre && !empty($_POST['employe_id'])) {
                    // Mode employé unique
                    $targetUserIds = [(int)$_POST['employe_id']];
                } else {
                    // Employé normal - seulement lui-même
                    $targetUserIds = [$userId];
                }

                $nbAjoutes = 0;
                $nomsAjoutes = [];

                foreach ($targetUserIds as $targetUserId) {
                    // Récupérer le taux horaire de l'employé cible
                    $stmt = $pdo->prepare("SELECT taux_horaire, CONCAT(prenom, ' ', nom) as nom_complet FROM users WHERE id = ? AND actif = 1");
                    $stmt->execute([$targetUserId]);
                    $targetUser = $stmt->fetch();

                    if ($targetUser) {
                        $targetTauxHoraire = (float)$targetUser['taux_horaire'];

                        $stmt = $pdo->prepare("
                            INSERT INTO heures_travaillees (projet_id, user_id, date_travail, heures, taux_horaire, description)
                            VALUES (?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([$projetId, $targetUserId, $dateTravail, $heures, $targetTauxHoraire, $description]);

                        // Notification
                        notifyNewHeures($targetUser['nom_complet'], $projetData['nom'], $heures, $dateTravail);

                        $nbAjoutes++;
                        $nomsAjoutes[] = $targetUser['nom_complet'];
                    }
                }

                if ($nbAjoutes === 1) {
                    if ($targetUserIds[0] === $userId) {
                        setFlashMessage('success', 'Heures enregistrées avec succès (' . $heures . 'h)');
                    } else {
                        setFlashMessage('success', 'Heures enregistrées pour ' . $nomsAjoutes[0] . ' (' . $heures . 'h)');
                    }
                } else {
                    setFlashMessage('success', 'Heures enregistrées pour ' . $nbAjoutes . ' employés (' . $heures . 'h chacun)');
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
                    <select class="form-select form-select-lg" name="employe_id" id="selectEmployeUniqueMobile">
                        <?php foreach ($employes as $emp): ?>
                            <option value="<?= $emp['id'] ?>" <?= $emp['id'] == $userId ? 'selected' : '' ?>>
                                <?= e($emp['nom_complet']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="mt-2">
                        <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalMultiEmployes">
                            <i class="bi bi-people me-1"></i>Plusieurs
                        </button>
                    </div>
                    <!-- Hidden inputs pour les employés multiples (mobile) -->
                    <div id="multiEmployesHiddenMobile"></div>
                    <div id="multiEmployesPreviewMobile" class="mt-2 d-none">
                        <small class="text-primary"><i class="bi bi-people-fill me-1"></i><span id="multiEmployesCountMobile"></span> employés sélectionnés</small>
                    </div>
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

            <div class="row g-3 mb-3">
                <div class="col-6">
                    <label class="form-label"><?= __('arrival') ?></label>
                    <select class="form-select form-select-lg text-center" id="heureDebutAddMobile">
                        <?php for ($h = 5; $h <= 12; $h++): ?>
                            <option value="<?= sprintf('%02d:00', $h) ?>" <?= $h == 7 && 0 == 0 ? 'selected' : '' ?>><?= sprintf('%02d:00', $h) ?></option>
                            <option value="<?= sprintf('%02d:15', $h) ?>"><?= sprintf('%02d:15', $h) ?></option>
                            <option value="<?= sprintf('%02d:30', $h) ?>"><?= sprintf('%02d:30', $h) ?></option>
                            <option value="<?= sprintf('%02d:45', $h) ?>"><?= sprintf('%02d:45', $h) ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-6">
                    <label class="form-label"><?= __('departure') ?></label>
                    <select class="form-select form-select-lg text-center" id="heureFinAddMobile">
                        <?php for ($h = 12; $h <= 22; $h++): ?>
                            <option value="<?= sprintf('%02d:00', $h) ?>" <?= $h == 16 && 0 == 0 ? 'selected' : '' ?>><?= sprintf('%02d:00', $h) ?></option>
                            <option value="<?= sprintf('%02d:15', $h) ?>"><?= sprintf('%02d:15', $h) ?></option>
                            <option value="<?= sprintf('%02d:30', $h) ?>"><?= sprintf('%02d:30', $h) ?></option>
                            <option value="<?= sprintf('%02d:45', $h) ?>"><?= sprintf('%02d:45', $h) ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
            </div>

            <!-- Recap heures mobile -->
            <div class="alert alert-info mb-3 text-center" id="heuresRecapMobile">
                <strong><i class="bi bi-clock me-1"></i>Total: <span id="heuresDisplayMobile">9</span>h</strong>
            </div>

            <input type="hidden" name="heures" id="heuresDirectAddMobile" value="9">

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
                                    <label class="form-label d-flex justify-content-between align-items-center">
                                        <span><?= __('employee') ?> *</span>
                                        <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalMultiEmployes">
                                            <i class="bi bi-people me-1"></i>Plusieurs
                                        </button>
                                    </label>
                                    <select class="form-select" name="employe_id" id="selectEmployeUnique">
                                        <?php foreach ($employes as $emp): ?>
                                            <option value="<?= $emp['id'] ?>" <?= $emp['id'] == $userId ? 'selected' : '' ?>>
                                                <?= e($emp['nom_complet']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <!-- Hidden inputs pour les employés multiples -->
                                    <div id="multiEmployesHidden"></div>
                                    <div id="multiEmployesPreview" class="mt-2 d-none">
                                        <small class="text-primary"><i class="bi bi-people-fill me-1"></i><span id="multiEmployesCount"></span> employés sélectionnés</small>
                                    </div>
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
                                    <select class="form-select" id="heureDebutAdd">
                                        <?php for ($h = 5; $h <= 12; $h++): ?>
                                            <option value="<?= sprintf('%02d:00', $h) ?>" <?= $h == 7 ? 'selected' : '' ?>><?= sprintf('%02d:00', $h) ?></option>
                                            <option value="<?= sprintf('%02d:15', $h) ?>"><?= sprintf('%02d:15', $h) ?></option>
                                            <option value="<?= sprintf('%02d:30', $h) ?>"><?= sprintf('%02d:30', $h) ?></option>
                                            <option value="<?= sprintf('%02d:45', $h) ?>"><?= sprintf('%02d:45', $h) ?></option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                                <div class="col-6">
                                    <label class="form-label"><?= __('departure') ?></label>
                                    <select class="form-select" id="heureFinAdd">
                                        <?php for ($h = 12; $h <= 22; $h++): ?>
                                            <option value="<?= sprintf('%02d:00', $h) ?>" <?= $h == 16 ? 'selected' : '' ?>><?= sprintf('%02d:00', $h) ?></option>
                                            <option value="<?= sprintf('%02d:15', $h) ?>"><?= sprintf('%02d:15', $h) ?></option>
                                            <option value="<?= sprintf('%02d:30', $h) ?>"><?= sprintf('%02d:30', $h) ?></option>
                                            <option value="<?= sprintf('%02d:45', $h) ?>"><?= sprintf('%02d:45', $h) ?></option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                            </div>

                            <!-- Recap heures desktop -->
                            <div class="alert alert-info mb-3 text-center" id="heuresRecapDesktop">
                                <strong><i class="bi bi-clock me-1"></i>Total: <span id="heuresDisplayDesktop">9</span>h</strong>
                            </div>

                            <!-- Heures calculées (caché) -->
                            <input type="hidden" name="heures" id="heuresDirectAdd" value="9">

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

/* Time input clickable anywhere */
.time-input-clickable {
    cursor: pointer;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Time inputs: ouvrir le picker au clic n'importe où sur le champ
    document.querySelectorAll('.time-input-clickable').forEach(function(input) {
        input.addEventListener('click', function() {
            this.showPicker();
        });
    });

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
    var heuresDisplayDesktop = document.getElementById('heuresDisplayDesktop');

    if (heureDebutAdd && heureFinAdd && heuresDirectAdd) {
        function updateHeuresAdd() {
            var heures = calculerHeures(heureDebutAdd.value, heureFinAdd.value);
            if (heures > 0) {
                heuresDirectAdd.value = heures;
                if (heuresDisplayDesktop) {
                    heuresDisplayDesktop.textContent = heures;
                }
            }
        }
        heureDebutAdd.addEventListener('change', updateHeuresAdd);
        heureFinAdd.addEventListener('change', updateHeuresAdd);
    }

    // Formulaire mobile - mise à jour automatique
    var heureDebutMobile = document.getElementById('heureDebutAddMobile');
    var heureFinMobile = document.getElementById('heureFinAddMobile');
    var heuresDirectMobile = document.getElementById('heuresDirectAddMobile');
    var heuresDisplayMobile = document.getElementById('heuresDisplayMobile');

    if (heureDebutMobile && heureFinMobile && heuresDirectMobile) {
        function updateHeuresMobile() {
            var heures = calculerHeures(heureDebutMobile.value, heureFinMobile.value);
            if (heures > 0) {
                heuresDirectMobile.value = heures;
                if (heuresDisplayMobile) {
                    heuresDisplayMobile.textContent = heures;
                }
            }
        }
        heureDebutMobile.addEventListener('change', updateHeuresMobile);
        heureFinMobile.addEventListener('change', updateHeuresMobile);
    }

    // Restaurer le dernier projet utilisé depuis localStorage
    var lastProjectId = localStorage.getItem('lastProjectId');
    if (lastProjectId) {
        // Desktop
        var projetSelectDesktop = document.querySelector('.d-none.d-md-block select[name="projet_id"]');
        if (projetSelectDesktop) {
            var optionDesktop = projetSelectDesktop.querySelector('option[value="' + lastProjectId + '"]');
            if (optionDesktop) projetSelectDesktop.value = lastProjectId;
        }
        // Mobile
        var projetSelectMobile = document.querySelector('.mobile-timesheet select[name="projet_id"]');
        if (projetSelectMobile) {
            var optionMobile = projetSelectMobile.querySelector('option[value="' + lastProjectId + '"]');
            if (optionMobile) projetSelectMobile.value = lastProjectId;
        }
    }

    // Sauvegarder le projet dès qu'on le sélectionne
    document.querySelectorAll('select[name="projet_id"]').forEach(function(select) {
        select.addEventListener('change', function() {
            if (this.value) {
                localStorage.setItem('lastProjectId', this.value);
            }
        });
    });
});
</script>

<?php if ($estContremaitre): ?>
<!-- Modal Sélection Multiple Employés -->
<div class="modal fade" id="modalMultiEmployes" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-people me-2"></i>Sélectionner les employés</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="d-flex gap-2 mb-3">
                    <button type="button" class="btn btn-outline-primary btn-sm" onclick="selectAllEmployes()">
                        <i class="bi bi-check-all me-1"></i>Tout sélectionner
                    </button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="deselectAllEmployes()">
                        <i class="bi bi-x-lg me-1"></i>Tout désélectionner
                    </button>
                </div>
                <div class="list-group">
                    <?php foreach ($employes as $emp): ?>
                    <label class="list-group-item d-flex align-items-center">
                        <input type="checkbox" class="form-check-input me-3 employe-checkbox"
                               value="<?= $emp['id'] ?>"
                               data-nom="<?= e($emp['nom_complet']) ?>">
                        <span><?= e($emp['nom_complet']) ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="modal-footer">
                <span class="me-auto text-muted"><span id="modalEmployeCount">0</span> sélectionné(s)</span>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                <button type="button" class="btn btn-primary" onclick="confirmerMultiEmployes()">
                    <i class="bi bi-check me-1"></i>Confirmer
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// Gestion de la sélection multiple d'employés
var selectedEmployes = [];

document.querySelectorAll('.employe-checkbox').forEach(function(cb) {
    cb.addEventListener('change', updateModalCount);
});

function updateModalCount() {
    var count = document.querySelectorAll('.employe-checkbox:checked').length;
    document.getElementById('modalEmployeCount').textContent = count;
}

function selectAllEmployes() {
    document.querySelectorAll('.employe-checkbox').forEach(function(cb) {
        cb.checked = true;
    });
    updateModalCount();
}

function deselectAllEmployes() {
    document.querySelectorAll('.employe-checkbox').forEach(function(cb) {
        cb.checked = false;
    });
    updateModalCount();
}

function confirmerMultiEmployes() {
    var checkboxes = document.querySelectorAll('.employe-checkbox:checked');

    // Éléments desktop
    var hiddenDiv = document.getElementById('multiEmployesHidden');
    var previewDiv = document.getElementById('multiEmployesPreview');
    var countSpan = document.getElementById('multiEmployesCount');
    var selectUnique = document.getElementById('selectEmployeUnique');

    // Éléments mobile
    var hiddenDivMobile = document.getElementById('multiEmployesHiddenMobile');
    var previewDivMobile = document.getElementById('multiEmployesPreviewMobile');
    var countSpanMobile = document.getElementById('multiEmployesCountMobile');
    var selectUniqueMobile = document.getElementById('selectEmployeUniqueMobile');

    // Vider les hidden inputs précédents (desktop et mobile)
    if (hiddenDiv) hiddenDiv.innerHTML = '';
    if (hiddenDivMobile) hiddenDivMobile.innerHTML = '';
    selectedEmployes = [];

    if (checkboxes.length > 1) {
        // Mode multi-employés
        checkboxes.forEach(function(cb) {
            // Desktop
            if (hiddenDiv) {
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'employes_ids[]';
                input.value = cb.value;
                hiddenDiv.appendChild(input);
            }
            // Mobile
            if (hiddenDivMobile) {
                var inputMobile = document.createElement('input');
                inputMobile.type = 'hidden';
                inputMobile.name = 'employes_ids[]';
                inputMobile.value = cb.value;
                hiddenDivMobile.appendChild(inputMobile);
            }
            selectedEmployes.push(cb.dataset.nom);
        });

        // Désactiver les selects uniques et afficher les previews
        if (selectUnique) {
            selectUnique.disabled = true;
            selectUnique.name = '';
        }
        if (selectUniqueMobile) {
            selectUniqueMobile.disabled = true;
            selectUniqueMobile.name = '';
        }
        if (previewDiv) {
            previewDiv.classList.remove('d-none');
            countSpan.textContent = checkboxes.length;
        }
        if (previewDivMobile) {
            previewDivMobile.classList.remove('d-none');
            countSpanMobile.textContent = checkboxes.length;
        }
    } else if (checkboxes.length === 1) {
        // Un seul sélectionné - utiliser le mode normal
        if (selectUnique) {
            selectUnique.disabled = false;
            selectUnique.name = 'employe_id';
            selectUnique.value = checkboxes[0].value;
        }
        if (selectUniqueMobile) {
            selectUniqueMobile.disabled = false;
            selectUniqueMobile.name = 'employe_id';
            selectUniqueMobile.value = checkboxes[0].value;
        }
        if (previewDiv) previewDiv.classList.add('d-none');
        if (previewDivMobile) previewDivMobile.classList.add('d-none');
    } else {
        // Aucun sélectionné - réactiver les selects
        if (selectUnique) {
            selectUnique.disabled = false;
            selectUnique.name = 'employe_id';
        }
        if (selectUniqueMobile) {
            selectUniqueMobile.disabled = false;
            selectUniqueMobile.name = 'employe_id';
        }
        if (previewDiv) previewDiv.classList.add('d-none');
        if (previewDivMobile) previewDivMobile.classList.add('d-none');
    }

    // Fermer le modal
    bootstrap.Modal.getInstance(document.getElementById('modalMultiEmployes')).hide();
}

// Réinitialiser quand on ouvre le modal
document.getElementById('modalMultiEmployes').addEventListener('show.bs.modal', function() {
    // Si on a des employés déjà sélectionnés, les cocher
    document.querySelectorAll('.employe-checkbox').forEach(function(cb) {
        cb.checked = selectedEmployes.includes(cb.dataset.nom);
    });
    updateModalCount();
});
</script>
<?php endif; ?>

<?php include '../includes/footer.php'; ?>
