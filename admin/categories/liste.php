<?php
/**
 * Gestion des catégories - Admin
 * Flip Manager
 */

require_once '../../config.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

requireAdmin();

$pageTitle = 'Gestion des catégories';
$errors = [];
$success = '';

$groupeLabels = [
    'exterieur' => 'Extérieur',
    'finition' => 'Finition intérieure',
    'ebenisterie' => 'Ébénisterie',
    'electricite' => 'Électricité',
    'plomberie' => 'Plomberie',
    'autre' => 'Autre'
];

// Traitement des actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token de sécurité invalide.';
    } else {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'ajouter') {
            $nom = trim($_POST['nom'] ?? '');
            $groupe = $_POST['groupe'] ?? 'autre';
            
            if (empty($nom)) {
                $errors[] = 'Le nom de la catégorie est requis.';
            } else {
                // Récupérer le dernier ordre du groupe
                $stmt = $pdo->prepare("SELECT MAX(ordre) FROM categories WHERE groupe = ?");
                $stmt->execute([$groupe]);
                $maxOrdre = $stmt->fetchColumn() ?: 0;
                
                $stmt = $pdo->prepare("INSERT INTO categories (nom, groupe, ordre) VALUES (?, ?, ?)");
                if ($stmt->execute([$nom, $groupe, $maxOrdre + 1])) {
                    setFlashMessage('success', 'Catégorie ajoutée avec succès!');
                    redirect('/admin/categories/liste.php');
                } else {
                    $errors[] = 'Erreur lors de l\'ajout de la catégorie.';
                }
            }
        } elseif ($action === 'modifier') {
            $id = (int)($_POST['id'] ?? 0);
            $nom = trim($_POST['nom'] ?? '');
            $groupe = $_POST['groupe'] ?? 'autre';
            
            if ($id && !empty($nom)) {
                $stmt = $pdo->prepare("UPDATE categories SET nom = ?, groupe = ? WHERE id = ?");
                if ($stmt->execute([$nom, $groupe, $id])) {
                    setFlashMessage('success', 'Catégorie modifiée avec succès!');
                    redirect('/admin/categories/liste.php');
                }
            }
        } elseif ($action === 'supprimer') {
            $id = (int)($_POST['id'] ?? 0);
            
            if ($id) {
                // Vérifier si la catégorie est utilisée
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM factures WHERE categorie_id = ?");
                $stmt->execute([$id]);
                $count = $stmt->fetchColumn();
                
                if ($count > 0) {
                    $errors[] = 'Cette catégorie est utilisée par ' . $count . ' facture(s). Impossible de la supprimer.';
                } else {
                    $stmt = $pdo->prepare("DELETE FROM categories WHERE id = ?");
                    if ($stmt->execute([$id])) {
                        setFlashMessage('success', 'Catégorie supprimée avec succès!');
                        redirect('/admin/categories/liste.php');
                    }
                }
            }
        }
    }
}

// Récupérer les catégories
$stmt = $pdo->query("SELECT * FROM categories ORDER BY groupe, ordre, nom");
$categories = $stmt->fetchAll();

// Grouper par catégorie
$categoriesGroupees = [];
foreach ($categories as $cat) {
    $categoriesGroupees[$cat['groupe']][] = $cat;
}

include '../../includes/header.php';
?>

<div class="container-fluid">
    <div class="page-header">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="/admin/index.php">Tableau de bord</a></li>
                <li class="breadcrumb-item active">Catégories</li>
            </ol>
        </nav>
        <div class="d-flex justify-content-between align-items-center">
            <h1><i class="bi bi-tags me-2"></i>Gestion des catégories</h1>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
                <i class="bi bi-plus-circle me-1"></i>Nouvelle catégorie
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
    
    <!-- Liste des catégories par groupe -->
    <?php foreach ($groupeLabels as $groupe => $label): ?>
        <div class="card mb-4">
            <div class="card-header">
                <i class="bi bi-folder me-2"></i><?= $label ?>
                <span class="badge bg-secondary ms-2"><?= count($categoriesGroupees[$groupe] ?? []) ?></span>
            </div>
            <div class="card-body p-0">
                <?php if (empty($categoriesGroupees[$groupe])): ?>
                    <p class="text-muted p-3 mb-0">Aucune catégorie dans ce groupe.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Nom</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($categoriesGroupees[$groupe] as $cat): ?>
                                    <tr>
                                        <td><?= e($cat['nom']) ?></td>
                                        <td class="text-end">
                                            <button type="button" class="btn btn-outline-primary btn-sm" 
                                                    data-bs-toggle="modal" data-bs-target="#editModal<?= $cat['id'] ?>">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <button type="button" class="btn btn-outline-danger btn-sm"
                                                    data-bs-toggle="modal" data-bs-target="#deleteModal<?= $cat['id'] ?>">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- Modal Ajouter -->
<div class="modal fade" id="addModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="">
                <?php csrfField(); ?>
                <input type="hidden" name="action" value="ajouter">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>Nouvelle catégorie</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="nom" class="form-label">Nom de la catégorie *</label>
                        <input type="text" class="form-control" id="nom" name="nom" required>
                    </div>
                    <div class="mb-3">
                        <label for="groupe" class="form-label">Groupe *</label>
                        <select class="form-select" id="groupe" name="groupe" required>
                            <?php foreach ($groupeLabels as $key => $label): ?>
                                <option value="<?= $key ?>"><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
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
<?php foreach ($categories as $cat): ?>
<!-- Modal Modifier -->
<div class="modal fade" id="editModal<?= $cat['id'] ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="">
                <?php csrfField(); ?>
                <input type="hidden" name="action" value="modifier">
                <input type="hidden" name="id" value="<?= $cat['id'] ?>">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Modifier la catégorie</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Nom de la catégorie *</label>
                        <input type="text" class="form-control" name="nom" value="<?= e($cat['nom']) ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Groupe *</label>
                        <select class="form-select" name="groupe" required>
                            <?php foreach ($groupeLabels as $key => $label): ?>
                                <option value="<?= $key ?>" <?= $cat['groupe'] === $key ? 'selected' : '' ?>><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
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

<!-- Modal Supprimer -->
<div class="modal fade" id="deleteModal<?= $cat['id'] ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="bi bi-exclamation-triangle me-2"></i>Supprimer</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Supprimer la catégorie <strong><?= e($cat['nom']) ?></strong> ?</p>
                <p class="text-muted mb-0">Cette action est irréversible.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                <form method="POST" action="" class="d-inline">
                    <?php csrfField(); ?>
                    <input type="hidden" name="action" value="supprimer">
                    <input type="hidden" name="id" value="<?= $cat['id'] ?>">
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
