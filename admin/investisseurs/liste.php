<?php
/**
 * Gestion des investisseurs - Admin
 * Flip Manager
 */

require_once '../../config.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

requireAdmin();

$pageTitle = 'Gestion des investisseurs';
$errors = [];

// Traitement des actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token de sécurité invalide.';
    } else {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'ajouter') {
            $nom = trim($_POST['nom'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $telephone = trim($_POST['telephone'] ?? '');
            $notes = trim($_POST['notes'] ?? '');
            
            if (empty($nom)) {
                $errors[] = 'Le nom est requis.';
            } else {
                $stmt = $pdo->prepare("INSERT INTO investisseurs (nom, email, telephone, notes) VALUES (?, ?, ?, ?)");
                if ($stmt->execute([$nom, $email, $telephone, $notes])) {
                    setFlashMessage('success', 'Investisseur ajouté avec succès!');
                    redirect('/admin/investisseurs/liste.php');
                } else {
                    $errors[] = 'Erreur lors de l\'ajout.';
                }
            }
        } elseif ($action === 'modifier') {
            $id = (int)($_POST['id'] ?? 0);
            $nom = trim($_POST['nom'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $telephone = trim($_POST['telephone'] ?? '');
            $notes = trim($_POST['notes'] ?? '');
            
            if ($id && !empty($nom)) {
                $stmt = $pdo->prepare("UPDATE investisseurs SET nom = ?, email = ?, telephone = ?, notes = ? WHERE id = ?");
                if ($stmt->execute([$nom, $email, $telephone, $notes, $id])) {
                    setFlashMessage('success', 'Investisseur modifié avec succès!');
                    redirect('/admin/investisseurs/liste.php');
                }
            }
        } elseif ($action === 'supprimer') {
            $id = (int)($_POST['id'] ?? 0);
            
            if ($id) {
                // Vérifier si l'investisseur est utilisé
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM projet_investisseurs WHERE investisseur_id = ?");
                $stmt->execute([$id]);
                $count = $stmt->fetchColumn();
                
                if ($count > 0) {
                    $errors[] = 'Cet investisseur est associé à ' . $count . ' projet(s). Impossible de le supprimer.';
                } else {
                    $stmt = $pdo->prepare("DELETE FROM investisseurs WHERE id = ?");
                    if ($stmt->execute([$id])) {
                        setFlashMessage('success', 'Investisseur supprimé avec succès!');
                        redirect('/admin/investisseurs/liste.php');
                    }
                }
            }
        }
    }
}

// Récupérer les investisseurs
$stmt = $pdo->query("
    SELECT i.*, 
           (SELECT COUNT(*) FROM projet_investisseurs WHERE investisseur_id = i.id) as nb_projets,
           (SELECT SUM(mise_de_fonds) FROM projet_investisseurs WHERE investisseur_id = i.id) as total_investi
    FROM investisseurs i 
    ORDER BY i.nom
");
$investisseurs = $stmt->fetchAll();

include '../../includes/header.php';
?>

<div class="container-fluid">
    <div class="page-header">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="/admin/index.php">Tableau de bord</a></li>
                <li class="breadcrumb-item active">Investisseurs</li>
            </ol>
        </nav>
        <div class="d-flex justify-content-between align-items-center">
            <h1><i class="bi bi-people me-2"></i>Investisseurs</h1>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
                <i class="bi bi-plus-circle me-1"></i>Nouvel investisseur
            </button>
        </div>
    </div>
    
    <?php displayFlashMessages(); ?>
    
    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger">
            <ul class="mb-0">
                <?php foreach ($errors as $error): ?>
                    <li><?= e($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
    
    <?php if (empty($investisseurs)): ?>
        <div class="card">
            <div class="card-body">
                <div class="empty-state">
                    <i class="bi bi-people"></i>
                    <h4>Aucun investisseur</h4>
                    <p>Ajoutez votre premier investisseur.</p>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
                        <i class="bi bi-plus-circle me-1"></i>Ajouter un investisseur
                    </button>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Nom</th>
                                <th>Email</th>
                                <th>Téléphone</th>
                                <th class="text-center">Projets</th>
                                <th class="text-end">Total investi</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($investisseurs as $inv): ?>
                                <tr>
                                    <td><strong><?= e($inv['nom']) ?></strong></td>
                                    <td><?= e($inv['email']) ?: '-' ?></td>
                                    <td><?= e($inv['telephone']) ?: '-' ?></td>
                                    <td class="text-center">
                                        <span class="badge bg-secondary"><?= (int)$inv['nb_projets'] ?></span>
                                    </td>
                                    <td class="text-end"><?= formatMoney($inv['total_investi'] ?? 0) ?></td>
                                    <td class="text-end">
                                        <button type="button" class="btn btn-outline-primary btn-sm" 
                                                data-bs-toggle="modal" data-bs-target="#editModal<?= $inv['id'] ?>">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <button type="button" class="btn btn-outline-danger btn-sm"
                                                data-bs-toggle="modal" data-bs-target="#deleteModal<?= $inv['id'] ?>">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Modal Ajouter -->
<div class="modal fade" id="addModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="">
                <?php csrfField(); ?>
                <input type="hidden" name="action" value="ajouter">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>Nouvel investisseur</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="nom" class="form-label">Nom *</label>
                        <input type="text" class="form-control" id="nom" name="nom" required>
                    </div>
                    <div class="mb-3">
                        <label for="email" class="form-label">Email</label>
                        <input type="email" class="form-control" id="email" name="email">
                    </div>
                    <div class="mb-3">
                        <label for="telephone" class="form-label">Téléphone</label>
                        <input type="tel" class="form-control" id="telephone" name="telephone">
                    </div>
                    <div class="mb-3">
                        <label for="notes" class="form-label">Notes</label>
                        <textarea class="form-control" id="notes" name="notes" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-plus-circle me-1"></i>Ajouter
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modals Modifier et Supprimer -->
<?php foreach ($investisseurs as $inv): ?>
<div class="modal fade" id="editModal<?= $inv['id'] ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="">
                <?php csrfField(); ?>
                <input type="hidden" name="action" value="modifier">
                <input type="hidden" name="id" value="<?= $inv['id'] ?>">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Modifier</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Nom *</label>
                        <input type="text" class="form-control" name="nom" value="<?= e($inv['nom']) ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" class="form-control" name="email" value="<?= e($inv['email']) ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Téléphone</label>
                        <input type="tel" class="form-control" name="telephone" value="<?= e($inv['telephone']) ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea class="form-control" name="notes" rows="2"><?= e($inv['notes']) ?></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-circle me-1"></i>Enregistrer
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="deleteModal<?= $inv['id'] ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="bi bi-exclamation-triangle me-2"></i>Supprimer</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Supprimer l'investisseur <strong><?= e($inv['nom']) ?></strong> ?</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                <form method="POST" action="" class="d-inline">
                    <?php csrfField(); ?>
                    <input type="hidden" name="action" value="supprimer">
                    <input type="hidden" name="id" value="<?= $inv['id'] ?>">
                    <button type="submit" class="btn btn-danger">
                        <i class="bi bi-trash me-1"></i>Supprimer
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>

<?php include '../../includes/footer.php'; ?>
