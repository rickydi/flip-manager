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

// Récupérer les entrées de temps
if ($estContremaitre) {
    // Le contremaître voit toutes les entrées récentes
    $stmt = $pdo->prepare("
        SELECT h.*, p.nom as projet_nom, CONCAT(u.prenom, ' ', u.nom) as employe_nom, u.id as employe_id
        FROM heures_travaillees h
        JOIN projets p ON h.projet_id = p.id
        JOIN users u ON h.user_id = u.id
        ORDER BY h.date_travail DESC, h.date_creation DESC
        LIMIT 100
    ");
    $stmt->execute();
} else {
    // L'employé normal voit seulement ses entrées
    $stmt = $pdo->prepare("
        SELECT h.*, p.nom as projet_nom
        FROM heures_travaillees h
        JOIN projets p ON h.projet_id = p.id
        WHERE h.user_id = ?
        ORDER BY h.date_travail DESC, h.date_creation DESC
        LIMIT 50
    ");
    $stmt->execute([$userId]);
}
$mesHeures = $stmt->fetchAll();

// Calculer les totaux (seulement pour ses propres heures, pas les autres)
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
        <!-- Formulaire de saisie -->
        <div class="col-lg-4">
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-plus-circle me-2"></i><?= __('add_hours') ?>
                </div>
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
                                    <option value="<?= $projet['id'] ?>"><?= e($projet['nom']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label"><?= __('date') ?> *</label>
                            <input type="date" class="form-control" name="date_travail"
                                   value="<?= date('Y-m-d') ?>" required>
                        </div>

                        <!-- Saisie des heures -->
                        <div class="mb-3 p-3 border rounded">
                            <label class="form-label"><?= __('number_of_hours') ?> *</label>
                            <div class="input-group mb-3">
                                <input type="number" step="0.5" min="0.5" max="24"
                                       class="form-control" name="heures" id="heuresDirectAdd" value="8" required>
                                <span class="input-group-text"><?= __('hours') ?></span>
                            </div>

                            <div class="text-center my-2">
                                <span class="badge bg-secondary px-3"><?= __('or') ?></span>
                            </div>

                            <label class="form-label small text-muted"><?= __('calculate_from_hours') ?></label>
                            <div class="row g-2">
                                <div class="col-6">
                                    <label class="form-label small text-muted mb-1"><?= __('arrival') ?></label>
                                    <input type="time" class="form-control" id="heureDebutAdd" value="08:00">
                                </div>
                                <div class="col-6">
                                    <label class="form-label small text-muted mb-1"><?= __('end') ?></label>
                                    <input type="time" class="form-control" id="heureFinAdd" value="16:00">
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label"><?= __('description') ?></label>
                            <textarea class="form-control" name="description" rows="2"
                                      placeholder="<?= __('work_done') ?>"></textarea>
                        </div>

                        <?php if (!$estContremaitre && $tauxHoraire > 0): ?>
                            <div class="alert alert-info small mb-3">
                                <i class="bi bi-info-circle me-1"></i>
                                8h = <?= formatMoney(8 * $tauxHoraire) ?>
                            </div>
                        <?php endif; ?>

                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-check-lg me-1"></i><?= __('save') ?>
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Liste des entrées -->
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>
                        <i class="bi bi-list-ul me-2"></i>
                        <?= $estContremaitre ? __('all_entries') : __('my_entries') ?>
                    </span>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($mesHeures)): ?>
                        <div class="empty-state py-5">
                            <i class="bi bi-clock"></i>
                            <h4><?= __('no_entries') ?></h4>
                            <p><?= __('start_adding') ?></p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th><?= __('date') ?></th>
                                        <?php if ($estContremaitre): ?>
                                            <th><?= __('employee') ?></th>
                                        <?php endif; ?>
                                        <th><?= __('project') ?></th>
                                        <th><?= __('hours') ?></th>
                                        <?php if (!$estContremaitre): ?>
                                            <th><?= __('amount') ?></th>
                                        <?php endif; ?>
                                        <th><?= __('status') ?></th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($mesHeures as $h): ?>
                                        <tr>
                                            <td><?= formatDate($h['date_travail']) ?></td>
                                            <?php if ($estContremaitre): ?>
                                                <td><strong><?= e($h['employe_nom']) ?></strong></td>
                                            <?php endif; ?>
                                            <td>
                                                <strong><?= e($h['projet_nom']) ?></strong>
                                                <?php if (!empty($h['description'])): ?>
                                                    <br><small class="text-muted"><?= e($h['description']) ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td><strong><?= number_format($h['heures'], 1) ?>h</strong></td>
                                            <?php if (!$estContremaitre): ?>
                                                <td><?= formatMoney($h['heures'] * $h['taux_horaire']) ?></td>
                                            <?php endif; ?>
                                            <td>
                                                <span class="badge <?= getStatutFactureClass($h['statut']) ?>">
                                                    <?= getStatutFactureLabel($h['statut']) ?>
                                                </span>
                                            </td>
                                            <td class="text-nowrap">
                                                <?php if ($h['statut'] === 'en_attente'): ?>
                                                    <?php if (!$estContremaitre || $h['user_id'] == $userId || $estContremaitre): ?>
                                                        <button type="button" class="btn btn-outline-primary btn-sm"
                                                                data-bs-toggle="modal" data-bs-target="#editModal<?= $h['id'] ?>">
                                                            <i class="bi bi-pencil"></i>
                                                        </button>
                                                        <form method="POST" action="" class="d-inline"
                                                              onsubmit="return confirm('<?= __('delete_confirm') ?>');">
                                                            <?php csrfField(); ?>
                                                            <input type="hidden" name="action" value="supprimer">
                                                            <input type="hidden" name="temps_id" value="<?= $h['id'] ?>">
                                                            <button type="submit" class="btn btn-outline-danger btn-sm">
                                                                <i class="bi bi-trash"></i>
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
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
    </div>
</div>

<!-- Modals d'édition -->
<?php foreach ($mesHeures as $h): ?>
    <?php if ($h['statut'] === 'en_attente'): ?>
        <div class="modal fade" id="editModal<?= $h['id'] ?>" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form method="POST" action="">
                        <?php csrfField(); ?>
                        <input type="hidden" name="action" value="modifier">
                        <input type="hidden" name="temps_id" value="<?= $h['id'] ?>">
                        <div class="modal-header">
                            <h5 class="modal-title"><i class="bi bi-pencil me-2"></i><?= __('edit_entry') ?></h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <?php if ($estContremaitre && isset($h['employe_nom'])): ?>
                                <div class="mb-3">
                                    <label class="form-label"><?= __('employee') ?></label>
                                    <input type="text" class="form-control" value="<?= e($h['employe_nom']) ?>" disabled>
                                </div>
                            <?php endif; ?>
                            <div class="mb-3">
                                <label class="form-label"><?= __('project') ?> *</label>
                                <select class="form-select" name="projet_id" required>
                                    <?php foreach ($projets as $projet): ?>
                                        <option value="<?= $projet['id'] ?>" <?= $projet['id'] == $h['projet_id'] ? 'selected' : '' ?>>
                                            <?= e($projet['nom']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label"><?= __('date') ?> *</label>
                                <input type="date" class="form-control" name="date_travail"
                                       value="<?= e($h['date_travail']) ?>" required>
                            </div>
                            <!-- Saisie des heures -->
                            <div class="mb-3 p-3 border rounded">
                                <label class="form-label"><?= __('number_of_hours') ?> *</label>
                                <div class="input-group mb-3">
                                    <input type="number" step="0.5" min="0.5" max="24"
                                           class="form-control" name="heures" id="heuresEdit<?= $h['id'] ?>"
                                           value="<?= e($h['heures']) ?>" required>
                                    <span class="input-group-text"><?= __('hours') ?></span>
                                </div>

                                <div class="text-center my-2">
                                    <span class="badge bg-secondary px-3"><?= __('or') ?></span>
                                </div>

                                <label class="form-label small text-muted"><?= __('calculate_from_hours') ?></label>
                                <div class="row g-2">
                                    <div class="col-6">
                                        <label class="form-label small text-muted mb-1"><?= __('arrival') ?></label>
                                        <input type="time" class="form-control heure-debut-edit" data-id="<?= $h['id'] ?>" value="08:00">
                                    </div>
                                    <div class="col-6">
                                        <label class="form-label small text-muted mb-1"><?= __('end') ?></label>
                                        <input type="time" class="form-control heure-fin-edit" data-id="<?= $h['id'] ?>" value="16:00">
                                    </div>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label"><?= __('description') ?></label>
                                <textarea class="form-control" name="description" rows="2"><?= e($h['description'] ?? '') ?></textarea>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= __('cancel') ?></button>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-circle me-1"></i><?= __('save') ?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>
<?php endforeach; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Auto-fermer le calendrier/horloge quand on sélectionne une valeur
    document.querySelectorAll('input[type="date"], input[type="time"]').forEach(function(input) {
        input.addEventListener('change', function() {
            this.blur();
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

    // Formulaire d'ajout - mise à jour automatique quand on change les heures début/fin
    var heureDebutAdd = document.getElementById('heureDebutAdd');
    var heureFinAdd = document.getElementById('heureFinAdd');
    var heuresDirectAdd = document.getElementById('heuresDirectAdd');

    function updateHeuresAdd() {
        var heures = calculerHeures(heureDebutAdd.value, heureFinAdd.value);
        if (heures > 0) {
            heuresDirectAdd.value = heures;
        }
    }

    heureDebutAdd.addEventListener('change', updateHeuresAdd);
    heureFinAdd.addEventListener('change', updateHeuresAdd);

    // Modals d'édition - mise à jour automatique quand on change les heures début/fin
    document.querySelectorAll('.heure-debut-edit, .heure-fin-edit').forEach(function(input) {
        input.addEventListener('change', function() {
            var id = this.getAttribute('data-id');
            var debut = document.querySelector('.heure-debut-edit[data-id="' + id + '"]').value;
            var fin = document.querySelector('.heure-fin-edit[data-id="' + id + '"]').value;
            var heures = calculerHeures(debut, fin);
            if (heures > 0) {
                document.getElementById('heuresEdit' + id).value = heures;
            }
        });
    });
});
</script>

<?php include '../includes/footer.php'; ?>
