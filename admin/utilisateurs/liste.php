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
            $username = trim(strtolower($_POST['username'] ?? ''));
            $email = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';
            $role = $_POST['role'] ?? 'employe';
            $tauxHoraire = parseNumber($_POST['taux_horaire'] ?? 0);
            
            if (empty($nom)) $errors[] = 'Le nom est requis.';
            if (empty($prenom)) $errors[] = 'Le prénom est requis.';
            if (empty($username)) $errors[] = 'L\'identifiant est requis.';
            if (!preg_match('/^[a-z0-9_]+$/', $username)) $errors[] = 'L\'identifiant ne peut contenir que des lettres minuscules, chiffres et underscores.';
            if (strlen($password) < 4) $errors[] = 'Le mot de passe doit contenir au moins 4 caractères.';
            
            // Vérifier que l'username n'existe pas
            if (!empty($username)) {
                $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
                $stmt->execute([$username]);
                if ($stmt->fetch()) {
                    $errors[] = 'Cet identifiant est déjà utilisé.';
                }
            }
            
            // Générer un email bidon si vide
            if (empty($email)) {
                $email = $username . '@local.flip';
            }
            
            if (empty($errors)) {
                $stmt = $pdo->prepare("
                    INSERT INTO users (username, nom, prenom, email, password, role, taux_horaire)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$username, $nom, $prenom, $email, password_hash($password, PASSWORD_DEFAULT), $role, $tauxHoraire]);
                setFlashMessage('success', 'Utilisateur créé avec succès. Identifiant: ' . $username);
                redirect('/admin/utilisateurs/liste.php');
            }
        } elseif ($action === 'modifier') {
            $userId = (int)($_POST['user_id'] ?? 0);
            $nom = trim($_POST['nom'] ?? '');
            $prenom = trim($_POST['prenom'] ?? '');
            $username = trim(strtolower($_POST['username'] ?? ''));
            $email = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';
            $role = $_POST['role'] ?? 'employe';
            $actif = isset($_POST['actif']) ? 1 : 0;
            $tauxHoraire = parseNumber($_POST['taux_horaire'] ?? 0);
            
            if (empty($nom)) $errors[] = 'Le nom est requis.';
            if (empty($prenom)) $errors[] = 'Le prénom est requis.';
            if (empty($username)) $errors[] = 'L\'identifiant est requis.';
            if (!preg_match('/^[a-z0-9_]+$/', $username)) $errors[] = 'L\'identifiant ne peut contenir que des lettres minuscules, chiffres et underscores.';
            
            // Vérifier que l'username n'existe pas pour un autre utilisateur
            if (!empty($username)) {
                $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
                $stmt->execute([$username, $userId]);
                if ($stmt->fetch()) {
                    $errors[] = 'Cet identifiant est déjà utilisé.';
                }
            }
            
            // Générer un email bidon si vide
            if (empty($email)) {
                $email = $username . '@local.flip';
            }
            
            if (empty($errors)) {
                if (!empty($password)) {
                    $stmt = $pdo->prepare("
                        UPDATE users SET username = ?, nom = ?, prenom = ?, email = ?, password = ?, role = ?, actif = ?, taux_horaire = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([$username, $nom, $prenom, $email, password_hash($password, PASSWORD_DEFAULT), $role, $actif, $tauxHoraire, $userId]);
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE users SET username = ?, nom = ?, prenom = ?, email = ?, role = ?, actif = ?, taux_horaire = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([$username, $nom, $prenom, $email, $role, $actif, $tauxHoraire, $userId]);
                }
                setFlashMessage('success', 'Utilisateur modifié avec succès.');
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
    <div class="page-header">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="/admin/index.php">Tableau de bord</a></li>
                <li class="breadcrumb-item active">Utilisateurs</li>
            </ol>
        </nav>
        <div class="d-flex justify-content-between align-items-center">
            <h1><i class="bi bi-people me-2"></i>Utilisateurs</h1>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalCreer">
                <i class="bi bi-plus-circle me-1"></i>Nouvel utilisateur
            </button>
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
    
    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Identifiant</th>
                            <th>Nom</th>
                            <th>Rôle</th>
                            <th>Taux horaire</th>
                            <th>Statut</th>
                            <th>Dernière connexion</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($utilisateurs as $user): ?>
                            <tr>
                                <td>
                                    <code><?= e($user['username'] ?? '-') ?></code>
                                </td>
                                <td>
                                    <strong><?= e($user['prenom']) ?> <?= e($user['nom']) ?></strong>
                                </td>
                                <td>
                                    <span class="badge <?= $user['role'] === 'admin' ? 'bg-primary' : 'bg-secondary' ?>">
                                        <?= $user['role'] === 'admin' ? 'Administrateur' : 'Employé' ?>
                                    </span>
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
                                    <?= $user['derniere_connexion'] ? formatDateTime($user['derniere_connexion']) : 'Jamais' ?>
                                </td>
                                <td class="action-buttons">
                                    <button type="button" 
                                            class="btn btn-outline-primary btn-sm"
                                            data-bs-toggle="modal" 
                                            data-bs-target="#modalModifier<?= $user['id'] ?>">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                </td>
                            </tr>
                            
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
                                                <div class="mb-3">
                                                    <label class="form-label">Identifiant (login) *</label>
                                                    <input type="text" class="form-control" name="username" 
                                                           value="<?= e($user['username'] ?? '') ?>" required
                                                           pattern="[a-z0-9_]+" title="Lettres minuscules, chiffres et _ uniquement">
                                                    <small class="text-muted">Lettres minuscules, chiffres et _ uniquement</small>
                                                </div>
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
                                                    <label class="form-label">Email <small class="text-muted">(optionnel)</small></label>
                                                    <input type="email" class="form-control" name="email" 
                                                           value="<?= e($user['email']) ?>">
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
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" name="actif" 
                                                           id="actif<?= $user['id'] ?>" <?= $user['actif'] ? 'checked' : '' ?>>
                                                    <label class="form-check-label" for="actif<?= $user['id'] ?>">
                                                        Compte actif
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
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

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
                    <div class="mb-3">
                        <label class="form-label">Identifiant (login) *</label>
                        <input type="text" class="form-control" name="username" required
                               pattern="[a-z0-9_]+" title="Lettres minuscules, chiffres et _ uniquement">
                        <small class="text-muted">Lettres minuscules, chiffres et _ uniquement</small>
                    </div>
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
                        <label class="form-label">Email <small class="text-muted">(optionnel)</small></label>
                        <input type="email" class="form-control" name="email">
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
