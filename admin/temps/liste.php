<?php
/**
 * Gestion des heures - Admin
 * Flip Manager
 */

require_once '../../config.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

requireAdmin();

$pageTitle = 'Gestion du temps';
$adminId = getCurrentUserId();

// Traitement des actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        setFlashMessage('danger', 'Token de sécurité invalide.');
    } else {
        $action = $_POST['action'] ?? '';
        $heureId = (int)($_POST['heure_id'] ?? 0);
        
        if ($action === 'approuver' && $heureId > 0) {
            $stmt = $pdo->prepare("
                UPDATE heures_travaillees 
                SET statut = 'approuvee', approuve_par = ?, date_approbation = NOW()
                WHERE id = ? AND statut = 'en_attente'
            ");
            $stmt->execute([$adminId, $heureId]);
            setFlashMessage('success', 'Heures approuvées.');
        } elseif ($action === 'rejeter' && $heureId > 0) {
            $stmt = $pdo->prepare("
                UPDATE heures_travaillees 
                SET statut = 'rejetee', approuve_par = ?, date_approbation = NOW()
                WHERE id = ? AND statut = 'en_attente'
            ");
            $stmt->execute([$adminId, $heureId]);
            setFlashMessage('warning', 'Heures rejetées.');
        } elseif ($action === 'approuver_tous') {
            $stmt = $pdo->prepare("
                UPDATE heures_travaillees 
                SET statut = 'approuvee', approuve_par = ?, date_approbation = NOW()
                WHERE statut = 'en_attente'
            ");
            $stmt->execute([$adminId]);
            setFlashMessage('success', 'Toutes les heures en attente ont été approuvées.');
        } elseif ($action === 'modifier' && $heureId > 0) {
            $dateTravail = $_POST['date_travail'] ?? '';
            $heuresNb = parseNumber($_POST['heures'] ?? 0);
            $description = trim($_POST['description'] ?? '');
            $tauxHoraire = parseNumber($_POST['taux_horaire'] ?? 0);
            
            if ($heuresNb > 0 && $heuresNb <= 24 && !empty($dateTravail)) {
                $stmt = $pdo->prepare("
                    UPDATE heures_travaillees 
                    SET date_travail = ?, heures = ?, description = ?, taux_horaire = ?
                    WHERE id = ?
                ");
                $stmt->execute([$dateTravail, $heuresNb, $description, $tauxHoraire, $heureId]);
                setFlashMessage('success', 'Entrée modifiée.');
            } else {
                setFlashMessage('danger', 'Données invalides.');
            }
        } elseif ($action === 'supprimer' && $heureId > 0) {
            $stmt = $pdo->prepare("DELETE FROM heures_travaillees WHERE id = ?");
            $stmt->execute([$heureId]);
            setFlashMessage('success', 'Entrée supprimée.');
        }
        redirect('/admin/temps/liste.php');
    }
}

// Filtres
$filtreProjet = isset($_GET['projet']) ? (int)$_GET['projet'] : 0;
$filtreStatut = $_GET['statut'] ?? '';  // Par défaut: afficher toutes les entrées
$filtreEmploye = isset($_GET['employe']) ? (int)$_GET['employe'] : 0;

// Récupérer les projets pour le filtre
$projets = getProjets($pdo, false);

// Récupérer les employés pour le filtre
$stmt = $pdo->query("SELECT id, CONCAT(prenom, ' ', nom) as nom_complet FROM users ORDER BY prenom, nom");
$employes = $stmt->fetchAll();

// Construire la requête
$sql = "
    SELECT h.*, 
           p.nom as projet_nom,
           CONCAT(u.prenom, ' ', u.nom) as employe_nom,
           CONCAT(a.prenom, ' ', a.nom) as approuve_par_nom
    FROM heures_travaillees h
    JOIN projets p ON h.projet_id = p.id
    JOIN users u ON h.user_id = u.id
    LEFT JOIN users a ON h.approuve_par = a.id
    WHERE 1=1
";
$params = [];

if ($filtreProjet > 0) {
    $sql .= " AND h.projet_id = ?";
    $params[] = $filtreProjet;
}

if ($filtreStatut && in_array($filtreStatut, ['en_attente', 'approuvee', 'rejetee'])) {
    $sql .= " AND h.statut = ?";
    $params[] = $filtreStatut;
}

if ($filtreEmploye > 0) {
    $sql .= " AND h.user_id = ?";
    $params[] = $filtreEmploye;
}

$sql .= " ORDER BY h.statut = 'en_attente' DESC, h.date_travail DESC, h.date_creation DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$heures = $stmt->fetchAll();

// Calculer les totaux
$totalHeures = 0;
$totalMontant = 0;
$enAttente = 0;
foreach ($heures as $h) {
    $totalHeures += $h['heures'];
    $totalMontant += $h['heures'] * $h['taux_horaire'];
    if ($h['statut'] === 'en_attente') $enAttente++;
}

include '../../includes/header.php';
?>

<div class="container-fluid">
    <div class="page-header">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="/admin/index.php">Tableau de bord</a></li>
                <li class="breadcrumb-item active">Gestion du temps</li>
            </ol>
        </nav>
        <div class="d-flex justify-content-between align-items-center">
            <h1><i class="bi bi-clock-history me-2"></i>Gestion du temps</h1>
            <?php if ($enAttente > 0): ?>
                <form method="POST" action="" class="d-inline" 
                      onsubmit="return confirm('Approuver toutes les <?= $enAttente ?> entrées en attente ?');">
                    <?php csrfField(); ?>
                    <input type="hidden" name="action" value="approuver_tous">
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-check-all me-1"></i>Tout approuver (<?= $enAttente ?>)
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>
    
    <?php displayFlashMessage(); ?>
    
    <!-- Stats -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-label">Entrées affichées</div>
            <div class="stat-value"><?= count($heures) ?></div>
        </div>
        <div class="stat-card warning">
            <div class="stat-label">En attente</div>
            <div class="stat-value"><?= $enAttente ?></div>
        </div>
        <div class="stat-card primary">
            <div class="stat-label">Total heures</div>
            <div class="stat-value"><?= number_format($totalHeures, 1) ?>h</div>
        </div>
        <div class="stat-card success">
            <div class="stat-label">Total montant</div>
            <div class="stat-value"><?= formatMoney($totalMontant) ?></div>
        </div>
    </div>
    
    <!-- Filtres -->
    <div class="card mb-3">
        <div class="card-body">
            <form method="GET" action="/admin/temps/liste.php" class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label">Statut</label>
                    <select class="form-select" name="statut">
                        <option value="">Tous</option>
                        <option value="en_attente" <?= $filtreStatut === 'en_attente' ? 'selected' : '' ?>>En attente</option>
                        <option value="approuvee" <?= $filtreStatut === 'approuvee' ? 'selected' : '' ?>>Approuvées</option>
                        <option value="rejetee" <?= $filtreStatut === 'rejetee' ? 'selected' : '' ?>>Rejetées</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Projet</label>
                    <select class="form-select" name="projet">
                        <option value="">Tous les projets</option>
                        <?php foreach ($projets as $p): ?>
                            <option value="<?= $p['id'] ?>" <?= $filtreProjet == $p['id'] ? 'selected' : '' ?>>
                                <?= e($p['nom']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Employé</label>
                    <select class="form-select" name="employe">
                        <option value="">Tous</option>
                        <?php foreach ($employes as $emp): ?>
                            <option value="<?= $emp['id'] ?>" <?= $filtreEmploye == $emp['id'] ? 'selected' : '' ?>>
                                <?= e($emp['nom_complet']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">&nbsp;</label>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-funnel"></i> Filtrer
                        </button>
                        <a href="/admin/temps/liste.php" class="btn btn-outline-secondary">
                            <i class="bi bi-x-circle"></i> Reset
                        </a>
                    </div>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Liste des heures -->
    <div class="card">
        <div class="card-body p-0">
            <?php if (empty($heures)): ?>
                <div class="empty-state py-5">
                    <i class="bi bi-clock"></i>
                    <h4>Aucune entrée de temps</h4>
                    <p>Aucune entrée ne correspond aux filtres sélectionnés.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Employé</th>
                                <th>Projet</th>
                                <th>Heures</th>
                                <th>Taux</th>
                                <th>Montant</th>
                                <th>Statut</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($heures as $h): ?>
                                <tr class="<?= $h['statut'] === 'en_attente' ? 'table-warning' : '' ?>">
                                    <td><?= formatDate($h['date_travail']) ?></td>
                                    <td>
                                        <strong><?= e($h['employe_nom']) ?></strong>
                                        <?php if (!empty($h['description'])): ?>
                                            <br><small class="text-muted"><?= e($h['description']) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a href="/admin/projets/detail.php?id=<?= $h['projet_id'] ?>">
                                            <?= e($h['projet_nom']) ?>
                                        </a>
                                    </td>
                                    <td><strong><?= number_format($h['heures'], 1) ?>h</strong></td>
                                    <td><?= formatMoney($h['taux_horaire']) ?>/h</td>
                                    <td><strong><?= formatMoney($h['heures'] * $h['taux_horaire']) ?></strong></td>
                                    <td>
                                        <span class="badge <?= getStatutFactureClass($h['statut']) ?>">
                                            <?= getStatutFactureLabel($h['statut']) ?>
                                        </span>
                                        <?php if ($h['date_approbation']): ?>
                                            <br><small class="text-muted">
                                                <?= formatDateTime($h['date_approbation']) ?>
                                                <?php if ($h['approuve_par_nom']): ?>
                                                    par <?= e($h['approuve_par_nom']) ?>
                                                <?php endif; ?>
                                            </small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="action-buttons">
                                        <?php if ($h['statut'] === 'en_attente'): ?>
                                            <form method="POST" action="" class="d-inline">
                                                <?php csrfField(); ?>
                                                <input type="hidden" name="action" value="approuver">
                                                <input type="hidden" name="heure_id" value="<?= $h['id'] ?>">
                                                <button type="submit" class="btn btn-success btn-sm" title="Approuver">
                                                    <i class="bi bi-check-lg"></i>
                                                </button>
                                            </form>
                                            <form method="POST" action="" class="d-inline">
                                                <?php csrfField(); ?>
                                                <input type="hidden" name="action" value="rejeter">
                                                <input type="hidden" name="heure_id" value="<?= $h['id'] ?>">
                                                <button type="submit" class="btn btn-danger btn-sm" title="Rejeter">
                                                    <i class="bi bi-x-lg"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                        <button type="button" class="btn btn-outline-primary btn-sm" 
                                                data-bs-toggle="modal" data-bs-target="#modalModifier<?= $h['id'] ?>" title="Modifier">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <form method="POST" action="" class="d-inline" 
                                              onsubmit="return confirm('Supprimer cette entrée ?');">
                                            <?php csrfField(); ?>
                                            <input type="hidden" name="action" value="supprimer">
                                            <input type="hidden" name="heure_id" value="<?= $h['id'] ?>">
                                            <button type="submit" class="btn btn-outline-danger btn-sm" title="Supprimer">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
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

<!-- Modales de modification -->
<?php foreach ($heures as $h): ?>
<div class="modal fade" id="modalModifier<?= $h['id'] ?>" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="">
                <?php csrfField(); ?>
                <input type="hidden" name="action" value="modifier">
                <input type="hidden" name="heure_id" value="<?= $h['id'] ?>">
                
                <div class="modal-header">
                    <h5 class="modal-title">Modifier l'entrée de temps</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Employé</label>
                        <input type="text" class="form-control" value="<?= e($h['employe_nom']) ?>" disabled>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Projet</label>
                        <input type="text" class="form-control" value="<?= e($h['projet_nom']) ?>" disabled>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Date *</label>
                        <input type="date" class="form-control" name="date_travail" 
                               value="<?= $h['date_travail'] ?>" required>
                    </div>
                    <div class="row">
                        <div class="col-6 mb-3">
                            <label class="form-label">Heures *</label>
                            <input type="number" step="0.5" min="0.5" max="24" class="form-control" 
                                   name="heures" value="<?= $h['heures'] ?>" required>
                        </div>
                        <div class="col-6 mb-3">
                            <label class="form-label">Taux horaire</label>
                            <div class="input-group">
                                <input type="number" step="0.01" min="0" class="form-control" 
                                       name="taux_horaire" value="<?= $h['taux_horaire'] ?>">
                                <span class="input-group-text">$/h</span>
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea class="form-control" name="description" rows="2"><?= e($h['description'] ?? '') ?></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-primary">Enregistrer</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>

<?php include '../../includes/footer.php'; ?>
