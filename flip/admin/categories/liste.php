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
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
                <i class="bi bi-plus-circle me-1"></i>Nouvelle catégorie
            </button>
        </div>
    </div>

    <!-- Sous-navigation Administration -->
    <ul class="nav nav-tabs mb-4">
        <li class="nav-item">
            <a class="nav-link" href="<?= url('/admin/utilisateurs/liste.php') ?>">
                <i class="bi bi-person-badge me-1"></i>Utilisateurs
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link active" href="<?= url('/admin/categories/liste.php') ?>">
                <i class="bi bi-tags me-1"></i>Catégories
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="<?= url('/admin/templates/liste.php') ?>">
                <i class="bi bi-box-seam me-1"></i>Templates
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

    <style>
        .category-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
        }
        @media (max-width: 768px) {
            .category-grid {
                grid-template-columns: 1fr;
            }
        }
        .category-group {
            margin-bottom: 2rem;
        }
        .category-group-title {
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-secondary);
            margin-bottom: 0.75rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .category-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.2rem 0.5rem;
            margin-bottom: 0.15rem;
            background: var(--bg-card);
            border-radius: 0.25rem;
            cursor: pointer;
            transition: all 0.2s;
            border: 1px solid var(--border-color);
            font-size: 0.875rem;
        }
        .category-item:hover {
            background: rgba(0, 123, 255, 0.1);
            border-color: var(--primary-color);
        }
        .category-item-name {
            font-weight: 500;
            color: var(--text-primary);
        }
        .category-item-actions {
            display: flex;
            gap: 0.5rem;
        }
        .category-number {
            color: #ff0000;
            font-family: 'Consolas', 'Monaco', monospace;
            font-size: 12px;
            font-weight: bold;
            margin-right: 8px;
            background: yellow;
            padding: 2px 6px;
            border-radius: 3px;
            min-width: 24px;
            text-align: center;
        }
        .add-to-group-btn {
            width: 22px;
            height: 22px;
            padding: 0;
            font-size: 0.75rem;
            line-height: 1;
            margin-left: 0.25rem;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
    </style>

    <!-- Liste des catégories par groupe - 2 colonnes -->
    <div class="category-grid">
        <?php foreach ($groupeLabels as $groupe => $label): ?>
            <div class="category-group">
                <div class="category-group-title">
                    <i class="bi bi-folder"></i>
                    <?= $label ?>
                    <span class="badge bg-secondary"><?= count($categoriesGroupees[$groupe] ?? []) ?></span>
                    <button type="button" class="btn btn-sm btn-outline-primary add-to-group-btn"
                            onclick="event.stopPropagation(); openAddModal('<?= $groupe ?>')"
                            title="Ajouter une catégorie dans <?= $label ?>">
                        <i class="bi bi-plus-lg"></i>
                    </button>
                </div>

                <?php if (empty($categoriesGroupees[$groupe])): ?>
                    <p class="text-muted small">Aucune catégorie</p>
                <?php else: ?>
                    <?php $catIndex = 0; ?>
                    <?php foreach ($categoriesGroupees[$groupe] as $cat): ?>
                        <?php $catIndex++; ?>
                        <div class="category-item" onclick="document.getElementById('editBtn<?= $cat['id'] ?>').click()">
                            <span class="category-number"><?= $catIndex ?></span>
                            <span class="category-item-name"><?= e($cat['nom']) ?></span>
                            <div class="category-item-actions" onclick="event.stopPropagation()">
                                <button type="button" id="editBtn<?= $cat['id'] ?>" class="btn btn-outline-primary btn-sm"
                                        data-bs-toggle="modal" data-bs-target="#editModal<?= $cat['id'] ?>">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <button type="button" class="btn btn-outline-danger btn-sm"
                                        data-bs-toggle="modal" data-bs-target="#deleteModal<?= $cat['id'] ?>">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
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

<script>
function openAddModal(groupe) {
    // Pré-sélectionner le groupe dans le modal
    document.getElementById('groupe').value = groupe;
    // Ouvrir le modal
    var modal = new bootstrap.Modal(document.getElementById('addModal'));
    modal.show();
    // Focus sur le champ nom
    setTimeout(function() {
        document.getElementById('nom').focus();
    }, 500);
}
</script>

<?php include '../../includes/footer.php'; ?>
