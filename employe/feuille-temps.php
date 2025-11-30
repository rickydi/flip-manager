<?php
/**
 * Feuille de temps - Employé
 * Flip Manager
 */

require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

requireLogin();

$pageTitle = 'Feuille de temps';
$userId = getCurrentUserId();

// Récupérer le taux horaire de l'utilisateur
$stmt = $pdo->prepare("SELECT taux_horaire FROM users WHERE id = ?");
$stmt->execute([$userId]);
$tauxHoraire = (float)$stmt->fetchColumn();

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
                $stmt->execute([$projetId, $userId, $dateTravail, $heures, $tauxHoraire, $description]);
                
                setFlashMessage('success', 'Heures enregistrées avec succès (' . $heures . 'h à ' . formatMoney($tauxHoraire) . '/h = ' . formatMoney($heures * $tauxHoraire) . ')');
                redirect('/employe/feuille-temps.php');
            }
        } elseif ($action === 'supprimer') {
            $id = (int)($_POST['temps_id'] ?? 0);
            
            // Vérifier que l'entrée appartient à l'utilisateur et est en attente
            $stmt = $pdo->prepare("SELECT id FROM heures_travaillees WHERE id = ? AND user_id = ? AND statut = 'en_attente'");
            $stmt->execute([$id, $userId]);
            
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

// Récupérer les entrées de temps de l'utilisateur (30 derniers jours)
$stmt = $pdo->prepare("
    SELECT h.*, p.nom as projet_nom
    FROM heures_travaillees h
    JOIN projets p ON h.projet_id = p.id
    WHERE h.user_id = ?
    ORDER BY h.date_travail DESC, h.date_creation DESC
    LIMIT 50
");
$stmt->execute([$userId]);
$mesHeures = $stmt->fetchAll();

// Calculer les totaux
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
                <h1><i class="bi bi-clock-history me-2"></i>Feuille de temps</h1>
                <p class="text-muted mb-0">
                    Votre taux horaire : 
                    <?php if ($tauxHoraire > 0): ?>
                        <strong><?= formatMoney($tauxHoraire) ?>/h</strong>
                    <?php else: ?>
                        <span class="text-danger">Non défini - Contactez l'admin</span>
                    <?php endif; ?>
                </p>
            </div>
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
    
    <!-- Stats -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-label">Total heures</div>
            <div class="stat-value"><?= number_format($totaux['total_heures'] ?? 0, 1) ?>h</div>
        </div>
        <div class="stat-card warning">
            <div class="stat-label">En attente</div>
            <div class="stat-value"><?= number_format($totaux['heures_attente'] ?? 0, 1) ?>h</div>
        </div>
        <div class="stat-card success">
            <div class="stat-label">Approuvées</div>
            <div class="stat-value"><?= number_format($totaux['heures_approuvees'] ?? 0, 1) ?>h</div>
        </div>
        <div class="stat-card primary">
            <div class="stat-label">Valeur totale</div>
            <div class="stat-value"><?= formatMoney($totaux['total_montant'] ?? 0) ?></div>
        </div>
    </div>
    
    <div class="row">
        <!-- Formulaire de saisie -->
        <div class="col-lg-4">
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-plus-circle me-2"></i>Ajouter des heures
                </div>
                <div class="card-body">
                    <form method="POST" action="">
                        <?php csrfField(); ?>
                        <input type="hidden" name="action" value="ajouter">
                        
                        <div class="mb-3">
                            <label class="form-label">Projet *</label>
                            <select class="form-select" name="projet_id" required>
                                <option value="">-- Sélectionnez --</option>
                                <?php foreach ($projets as $projet): ?>
                                    <option value="<?= $projet['id'] ?>"><?= e($projet['nom']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Date *</label>
                            <input type="date" class="form-control" name="date_travail" 
                                   value="<?= date('Y-m-d') ?>" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Nombre d'heures *</label>
                            <div class="input-group">
                                <input type="number" step="0.5" min="0.5" max="24" 
                                       class="form-control" name="heures" value="8" required>
                                <span class="input-group-text">heures</span>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" name="description" rows="2" 
                                      placeholder="Travaux effectués..."></textarea>
                        </div>
                        
                        <?php if ($tauxHoraire > 0): ?>
                            <div class="alert alert-info small mb-3">
                                <i class="bi bi-info-circle me-1"></i>
                                8h = <?= formatMoney(8 * $tauxHoraire) ?>
                            </div>
                        <?php endif; ?>
                        
                        <button type="submit" class="btn btn-primary w-100" <?= $tauxHoraire <= 0 ? 'disabled' : '' ?>>
                            <i class="bi bi-check-lg me-1"></i>Enregistrer
                        </button>
                    </form>
                </div>
            </div>
        </div>
        
        <!-- Liste des entrées -->
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-list-ul me-2"></i>Mes dernières entrées</span>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($mesHeures)): ?>
                        <div class="empty-state py-5">
                            <i class="bi bi-clock"></i>
                            <h4>Aucune entrée de temps</h4>
                            <p>Commencez par ajouter vos heures de travail.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Projet</th>
                                        <th>Heures</th>
                                        <th>Montant</th>
                                        <th>Statut</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($mesHeures as $h): ?>
                                        <tr>
                                            <td><?= formatDate($h['date_travail']) ?></td>
                                            <td>
                                                <strong><?= e($h['projet_nom']) ?></strong>
                                                <?php if (!empty($h['description'])): ?>
                                                    <br><small class="text-muted"><?= e($h['description']) ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td><strong><?= number_format($h['heures'], 1) ?>h</strong></td>
                                            <td><?= formatMoney($h['heures'] * $h['taux_horaire']) ?></td>
                                            <td>
                                                <span class="badge <?= getStatutFactureClass($h['statut']) ?>">
                                                    <?= getStatutFactureLabel($h['statut']) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($h['statut'] === 'en_attente'): ?>
                                                    <form method="POST" action="" class="d-inline" 
                                                          onsubmit="return confirm('Supprimer cette entrée ?');">
                                                        <?php csrfField(); ?>
                                                        <input type="hidden" name="action" value="supprimer">
                                                        <input type="hidden" name="temps_id" value="<?= $h['id'] ?>">
                                                        <button type="submit" class="btn btn-outline-danger btn-sm">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </form>
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

<?php include '../includes/footer.php'; ?>
