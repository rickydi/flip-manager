<?php
/**
 * Gestion des utilisateurs - Admin
 * Flip Manager
 */

require_once '../../config.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

// Vérifier que l'utilisateur est admin
requireAdmin();

$pageTitle = 'Utilisateurs';

$errors = [];
$success = '';

// Traitement des actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token de sécurité invalide.';
    } else {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'creer') {
            $nom = trim($_POST['nom'] ?? '');
            $prenom = trim($_POST['prenom'] ?? '');
            $email = trim(strtolower($_POST['email'] ?? ''));
            $password = $_POST['password'] ?? '';
            $role = $_POST['role'] ?? 'employe';
            $tauxHoraire = parseNumber($_POST['taux_horaire'] ?? 0);
            
            if (empty($nom)) $errors[] = 'Le nom est requis.';
            if (empty($prenom)) $errors[] = 'Le prénom est requis.';
            if (empty($email)) $errors[] = 'L\'email est requis.';
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'L\'email n\'est pas valide.';
            if (strlen($password) < 4) $errors[] = 'Le mot de passe doit contenir au moins 4 caractères.';
            
            // Vérifier que l'email n'existe pas
            if (!empty($email)) {
                $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
                $stmt->execute([$email]);
                if ($stmt->fetch()) {
                    $errors[] = 'Cet email est déjà utilisé.';
                }
            }
            
            // Générer un username à partir de l'email (pour compatibilité)
            $username = explode('@', $email)[0];
            $username = preg_replace('/[^a-z0-9_]/', '', strtolower($username));
            
            $estContremaitre = isset($_POST['est_contremaitre']) ? 1 : 0;
            
            if (empty($errors)) {
                $stmt = $pdo->prepare("
                    INSERT INTO users (username, nom, prenom, email, password, role, taux_horaire, est_contremaitre)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$username, $nom, $prenom, $email, password_hash($password, PASSWORD_DEFAULT), $role, $tauxHoraire, $estContremaitre]);
                setFlashMessage('success', 'Utilisateur créé avec succès. Login: ' . $email);
                redirect('/admin/utilisateurs/liste.php');
            }
        } elseif ($action === 'modifier') {
            $userId = (int)($_POST['user_id'] ?? 0);
            $nom = trim($_POST['nom'] ?? '');
            $prenom = trim($_POST['prenom'] ?? '');
            $email = trim(strtolower($_POST['email'] ?? ''));
            $password = $_POST['password'] ?? '';
            $role = $_POST['role'] ?? 'employe';
            $actif = isset($_POST['actif']) ? 1 : 0;
            $tauxHoraire = parseNumber($_POST['taux_horaire'] ?? 0);
            $estContremaitre = isset($_POST['est_contremaitre']) ? 1 : 0;
            
            if (empty($nom)) $errors[] = 'Le nom est requis.';
            if (empty($prenom)) $errors[] = 'Le prénom est requis.';
            if (empty($email)) $errors[] = 'L\'email est requis.';
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'L\'email n\'est pas valide.';
            
            // Vérifier que l'email n'existe pas pour un autre utilisateur
            if (!empty($email)) {
                $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
                $stmt->execute([$email, $userId]);
                if ($stmt->fetch()) {
                    $errors[] = 'Cet email est déjà utilisé.';
                }
            }
            
            // Générer un username à partir de l'email (pour compatibilité)
            $username = explode('@', $email)[0];
            $username = preg_replace('/[^a-z0-9_]/', '', strtolower($username));
            
            if (empty($errors)) {
                if (!empty($password)) {
                    $stmt = $pdo->prepare("
                        UPDATE users SET username = ?, nom = ?, prenom = ?, email = ?, password = ?, role = ?, actif = ?, taux_horaire = ?, est_contremaitre = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([$username, $nom, $prenom, $email, password_hash($password, PASSWORD_DEFAULT), $role, $actif, $tauxHoraire, $estContremaitre, $userId]);
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE users SET username = ?, nom = ?, prenom = ?, email = ?, role = ?, actif = ?, taux_horaire = ?, est_contremaitre = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([$username, $nom, $prenom, $email, $role, $actif, $tauxHoraire, $estContremaitre, $userId]);
                }
                setFlashMessage('success', 'Utilisateur modifié avec succès.');
                redirect('/admin/utilisateurs/liste.php');
            }
        } elseif ($action === 'supprimer') {
            $userId = (int)($_POST['user_id'] ?? 0);
            $currentUserId = getCurrentUserId();
            
            // Ne pas supprimer soi-même
            if ($userId === $currentUserId) {
                $errors[] = 'Vous ne pouvez pas supprimer votre propre compte.';
            } else {
                // Vérifier que l'utilisateur existe
                $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                if ($stmt->fetch()) {
                    // Supprimer l'utilisateur
                    $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
                    $stmt->execute([$userId]);
                    setFlashMessage('success', 'Utilisateur supprimé.');
                } else {
                    setFlashMessage('danger', 'Utilisateur introuvable.');
                }
                redirect('/admin/utilisateurs/liste.php');
            }
        }
    }
}

// Récupérer les utilisateurs
$stmt = $pdo->query("SELECT * FROM users ORDER BY role DESC, nom, prenom");
$utilisateurs = $stmt->fetchAll();

include '../../includes/header.php';
?>

<div class="container-fluid">
    <!-- En-tête -->
    <div class="page-header d-flex justify-content-between align-items-start flex-wrap">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="<?= url('/admin/index.php') ?>">Tableau de bord</a></li>
                    <li class="breadcrumb-item active">Administration</li>
                </ol>
            </nav>
            <h1><i class="bi bi-gear me-2"></i>Administration</h1>
        </div>
        <div class="d-flex gap-2 mt-2 mt-md-0">
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalCreer">
                <i class="bi bi-plus-circle me-1"></i>Nouvel utilisateur
            </button>
        </div>
    </div>

    <!-- Sous-navigation Administration -->
    <ul class="nav nav-tabs mb-4">
        <li class="nav-item">
            <a class="nav-link active" href="<?= url('/admin/utilisateurs/liste.php') ?>">
                <i class="bi bi-person-badge me-1"></i>Utilisateurs
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="<?= url('/admin/fournisseurs/liste.php') ?>">
                <i class="bi bi-shop me-1"></i>Fournisseurs
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="<?= url('/admin/recurrents/liste.php') ?>">
                <i class="bi bi-arrow-repeat me-1"></i>Récurrents
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="<?= url('/admin/rapports/index.php') ?>">
                <i class="bi bi-file-earmark-bar-graph me-1"></i>Rapports
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="<?= url('/admin/rapports/paie-hebdo.php') ?>">
                <i class="bi bi-calendar-week me-1"></i>Paie hebdo
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="<?= url('/admin/configuration/index.php') ?>">
                <i class="bi bi-gear-wide-connected me-1"></i>Configuration
            </a>
        </li>
    </ul>
    
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
    
    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Email (login)</th>
                            <th>Nom</th>
                            <th>Rôle</th>
                            <th>Taux horaire</th>
                            <th>Statut</th>
                            <th>Dernière connexion</th>
                            <th>Durée session</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($utilisateurs as $user): ?>
                            <tr>
                                <td>
                                    <code><?= e($user['email'] ?? '-') ?></code>
                                </td>
                                <td>
                                    <strong><?= e($user['prenom']) ?> <?= e($user['nom']) ?></strong>
                                </td>
                                <td>
                                    <span class="badge <?= $user['role'] === 'admin' ? 'bg-primary' : 'bg-secondary' ?>">
                                        <?= $user['role'] === 'admin' ? 'Administrateur' : 'Employé' ?>
                                    </span>
                                    <?php if (!empty($user['est_contremaitre'])): ?>
                                        <span class="badge bg-info">Contremaître</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (($user['taux_horaire'] ?? 0) > 0): ?>
                                        <strong><?= formatMoney($user['taux_horaire']) ?></strong>/h
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge <?= $user['actif'] ? 'bg-success' : 'bg-danger' ?>">
                                        <?= $user['actif'] ? 'Actif' : 'Inactif' ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($user['derniere_connexion']): ?>
                                        <?= formatDateTime($user['derniere_connexion']) ?>
                                        <br><small class="text-muted"><?= formatTempsEcoule($user['derniere_connexion']) ?></small>
                                    <?php else: ?>
                                        <span class="text-muted">Jamais</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($user['duree_derniere_session'])): ?>
                                        <i class="bi bi-clock me-1 text-muted"></i><?= formatDureeSession($user['duree_derniere_session']) ?>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="action-buttons">
                                    <button type="button"
                                            class="btn btn-outline-info btn-sm"
                                            data-bs-toggle="modal"
                                            data-bs-target="#modalActivite<?= $user['id'] ?>"
                                            title="Voir l'activité">
                                        <i class="bi bi-clock-history"></i>
                                    </button>
                                    <button type="button"
                                            class="btn btn-outline-primary btn-sm"
                                            data-bs-toggle="modal"
                                            data-bs-target="#modalModifier<?= $user['id'] ?>">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <?php if ($user['id'] !== getCurrentUserId()): ?>
                                        <form method="POST" action="" class="d-inline" 
                                              onsubmit="return confirm('Supprimer cet utilisateur ?\n\n<?= e($user['prenom']) ?> <?= e($user['nom']) ?>');">
                                            <?php csrfField(); ?>
                                            <input type="hidden" name="action" value="supprimer">
                                            <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                            <button type="submit" class="btn btn-outline-danger btn-sm" title="Supprimer">
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
        </div>
    </div>
</div>

<!-- Modals Modifier et Activité (en dehors du tableau) -->
<?php foreach ($utilisateurs as $user): ?>
    <!-- Modal Modifier -->
    <div class="modal fade" id="modalModifier<?= $user['id'] ?>" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" action="">
                    <?php csrfField(); ?>
                    <input type="hidden" name="action" value="modifier">
                    <input type="hidden" name="user_id" value="<?= $user['id'] ?>">

                    <div class="modal-header">
                        <h5 class="modal-title">Modifier l'utilisateur</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-6 mb-3">
                                <label class="form-label">Prénom *</label>
                                <input type="text" class="form-control" name="prenom"
                                       value="<?= e($user['prenom']) ?>" required>
                            </div>
                            <div class="col-6 mb-3">
                                <label class="form-label">Nom *</label>
                                <input type="text" class="form-control" name="nom"
                                       value="<?= e($user['nom']) ?>" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Email (login) *</label>
                            <input type="email" class="form-control" name="email"
                                   value="<?= e($user['email']) ?>" required>
                            <small class="text-muted">L'email est utilisé pour se connecter</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Nouveau mot de passe</label>
                            <input type="password" class="form-control" name="password"
                                   placeholder="Laisser vide pour ne pas changer">
                        </div>
                        <div class="row">
                            <div class="col-6 mb-3">
                                <label class="form-label">Rôle</label>
                                <select class="form-select" name="role">
                                    <option value="employe" <?= $user['role'] === 'employe' ? 'selected' : '' ?>>Employé</option>
                                    <option value="admin" <?= $user['role'] === 'admin' ? 'selected' : '' ?>>Administrateur</option>
                                </select>
                            </div>
                            <div class="col-6 mb-3">
                                <label class="form-label">Taux horaire</label>
                                <div class="input-group">
                                    <input type="number" step="0.01" min="0" class="form-control"
                                           name="taux_horaire" value="<?= formatMoney($user['taux_horaire'] ?? 0, false) ?>">
                                    <span class="input-group-text">$/h</span>
                                </div>
                            </div>
                        </div>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" name="actif"
                                   id="actif<?= $user['id'] ?>" <?= $user['actif'] ? 'checked' : '' ?>>
                            <label class="form-check-label" for="actif<?= $user['id'] ?>">
                                Compte actif
                            </label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="est_contremaitre"
                                   id="contremaitre<?= $user['id'] ?>" <?= !empty($user['est_contremaitre']) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="contremaitre<?= $user['id'] ?>">
                                <i class="bi bi-person-badge me-1"></i>Contremaître (peut saisir les heures des autres)
                            </label>
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

    <!-- Modal Activité -->
    <div class="modal fade" id="modalActivite<?= $user['id'] ?>" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-clock-history me-2"></i>Activité de <?= e($user['prenom']) ?> <?= e($user['nom']) ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" style="max-height: 400px; overflow-y: auto;">
                    <?php
                    $activities = getUserActivity($pdo, $user['id'], 50);
                    if (empty($activities)): ?>
                        <p class="text-muted text-center py-4">Aucune activité enregistrée</p>
                    <?php else: ?>
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Action</th>
                                    <th>Page</th>
                                    <th>Détails</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($activities as $activity): ?>
                                    <tr>
                                        <td>
                                            <small><?= formatDateTime($activity['created_at']) ?></small>
                                        </td>
                                        <td><?= formatActivityAction($activity['action']) ?></td>
                                        <td>
                                            <?php if ($activity['page']): ?>
                                                <code><?= e($activity['page']) ?></code>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <small class="text-muted"><?= e($activity['details'] ?? '') ?></small>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fermer</button>
                </div>
            </div>
        </div>
    </div>
<?php endforeach; ?>

<!-- Modal Créer -->
<div class="modal fade" id="modalCreer" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="">
                <?php csrfField(); ?>
                <input type="hidden" name="action" value="creer">
                
                <div class="modal-header">
                    <h5 class="modal-title">Nouvel utilisateur</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-6 mb-3">
                            <label class="form-label">Prénom *</label>
                            <input type="text" class="form-control" name="prenom" required>
                        </div>
                        <div class="col-6 mb-3">
                            <label class="form-label">Nom *</label>
                            <input type="text" class="form-control" name="nom" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email (login) *</label>
                        <input type="email" class="form-control" name="email" required
                               placeholder="exemple@email.com">
                        <small class="text-muted">L'email est utilisé pour se connecter</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Mot de passe *</label>
                        <input type="password" class="form-control" name="password" required minlength="4">
                    </div>
                    <div class="row">
                        <div class="col-6 mb-3">
                            <label class="form-label">Rôle</label>
                            <select class="form-select" name="role">
                                <option value="employe">Employé</option>
                                <option value="admin">Administrateur</option>
                            </select>
                        </div>
                        <div class="col-6 mb-3">
                            <label class="form-label">Taux horaire</label>
                            <div class="input-group">
                                <input type="number" step="0.01" min="0" class="form-control" 
                                       name="taux_horaire" value="0">
                                <span class="input-group-text">$/h</span>
                            </div>
                        </div>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="est_contremaitre" id="contremaitreNouveau">
                        <label class="form-check-label" for="contremaitreNouveau">
                            <i class="bi bi-person-badge me-1"></i>Contremaître (peut saisir les heures des autres)
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-primary">Créer</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
