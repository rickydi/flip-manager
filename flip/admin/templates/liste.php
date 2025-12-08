<?php
/**
 * Gestion des templates de budgets - Admin
 * Sous-catégories imbriquées et Matériaux
 * Flip Manager
 */

require_once '../../config.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

requireAdmin();

$pageTitle = 'Templates Budgets';
$errors = [];
$success = '';

// Groupes de catégories
$groupeLabels = [
    'exterieur' => 'Extérieur',
    'finition' => 'Finition intérieure',
    'ebenisterie' => 'Ébénisterie',
    'electricite' => 'Électricité',
    'plomberie' => 'Plomberie',
    'autre' => 'Autre'
];

// Catégorie sélectionnée
$categorieId = (int)($_GET['categorie'] ?? 0);

// Vérifier si la colonne parent_id existe, sinon l'ajouter
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM sous_categories LIKE 'parent_id'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE sous_categories ADD COLUMN parent_id INT NULL AFTER categorie_id");
        $pdo->exec("ALTER TABLE sous_categories ADD INDEX idx_parent (parent_id)");
    }
} catch (Exception $e) {
    // Ignorer
}

// Traitement des actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token de sécurité invalide.';
    } else {
        $action = $_POST['action'] ?? '';

        // === SOUS-CATÉGORIES ===
        if ($action === 'ajouter_sous_categorie') {
            $nom = trim($_POST['nom'] ?? '');
            $catId = (int)($_POST['categorie_id'] ?? 0);
            $parentId = !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;

            if (empty($nom) || !$catId) {
                $errors[] = 'Données invalides.';
            } else {
                $stmt = $pdo->prepare("SELECT MAX(ordre) FROM sous_categories WHERE categorie_id = ? AND (parent_id = ? OR (parent_id IS NULL AND ? IS NULL))");
                $stmt->execute([$catId, $parentId, $parentId]);
                $maxOrdre = $stmt->fetchColumn() ?: 0;

                $stmt = $pdo->prepare("INSERT INTO sous_categories (categorie_id, parent_id, nom, ordre) VALUES (?, ?, ?, ?)");
                if ($stmt->execute([$catId, $parentId, $nom, $maxOrdre + 1])) {
                    setFlashMessage('success', 'Sous-catégorie ajoutée!');
                    redirect('/admin/templates/liste.php?categorie=' . $catId);
                }
            }
        }

        elseif ($action === 'modifier_sous_categorie') {
            $id = (int)($_POST['id'] ?? 0);
            $nom = trim($_POST['nom'] ?? '');

            if ($id && !empty($nom)) {
                $stmt = $pdo->prepare("UPDATE sous_categories SET nom = ? WHERE id = ?");
                if ($stmt->execute([$nom, $id])) {
                    setFlashMessage('success', 'Sous-catégorie modifiée!');
                    redirect('/admin/templates/liste.php?categorie=' . $categorieId);
                }
            }
        }

        elseif ($action === 'supprimer_sous_categorie') {
            $id = (int)($_POST['id'] ?? 0);

            if ($id) {
                // Supprimer récursivement les enfants et matériaux
                supprimerSousCategorieRecursif($pdo, $id);
                setFlashMessage('success', 'Sous-catégorie supprimée!');
                redirect('/admin/templates/liste.php?categorie=' . $categorieId);
            }
        }

        // === MATÉRIAUX ===
        elseif ($action === 'ajouter_materiau') {
            $nom = trim($_POST['nom'] ?? '');
            $scId = (int)($_POST['sous_categorie_id'] ?? 0);
            $prix = (float)str_replace([' ', ',', '$'], ['', '.', ''], $_POST['prix_defaut'] ?? '0');

            if (empty($nom) || !$scId) {
                $errors[] = 'Données invalides.';
            } else {
                $stmt = $pdo->prepare("SELECT MAX(ordre) FROM materiaux WHERE sous_categorie_id = ?");
                $stmt->execute([$scId]);
                $maxOrdre = $stmt->fetchColumn() ?: 0;

                $stmt = $pdo->prepare("INSERT INTO materiaux (sous_categorie_id, nom, prix_defaut, ordre) VALUES (?, ?, ?, ?)");
                if ($stmt->execute([$scId, $nom, $prix, $maxOrdre + 1])) {
                    setFlashMessage('success', 'Matériau ajouté!');
                    redirect('/admin/templates/liste.php?categorie=' . $categorieId);
                }
            }
        }

        elseif ($action === 'modifier_materiau') {
            $id = (int)($_POST['id'] ?? 0);
            $nom = trim($_POST['nom'] ?? '');
            $prix = (float)str_replace([' ', ',', '$'], ['', '.', ''], $_POST['prix_defaut'] ?? '0');

            if ($id && !empty($nom)) {
                $stmt = $pdo->prepare("UPDATE materiaux SET nom = ?, prix_defaut = ? WHERE id = ?");
                if ($stmt->execute([$nom, $prix, $id])) {
                    setFlashMessage('success', 'Matériau modifié!');
                    redirect('/admin/templates/liste.php?categorie=' . $categorieId);
                }
            }
        }

        elseif ($action === 'supprimer_materiau') {
            $id = (int)($_POST['id'] ?? 0);

            if ($id) {
                $stmt = $pdo->prepare("DELETE FROM materiaux WHERE id = ?");
                if ($stmt->execute([$id])) {
                    setFlashMessage('success', 'Matériau supprimé!');
                    redirect('/admin/templates/liste.php?categorie=' . $categorieId);
                }
            }
        }
    }
}

/**
 * Supprimer une sous-catégorie et tous ses enfants récursivement
 */
function supprimerSousCategorieRecursif($pdo, $id) {
    // D'abord supprimer les enfants
    $stmt = $pdo->prepare("SELECT id FROM sous_categories WHERE parent_id = ?");
    $stmt->execute([$id]);
    $enfants = $stmt->fetchAll();
    foreach ($enfants as $enfant) {
        supprimerSousCategorieRecursif($pdo, $enfant['id']);
    }

    // Supprimer les matériaux
    $pdo->prepare("DELETE FROM materiaux WHERE sous_categorie_id = ?")->execute([$id]);

    // Supprimer la sous-catégorie
    $pdo->prepare("DELETE FROM sous_categories WHERE id = ?")->execute([$id]);
}

/**
 * Récupérer les sous-catégories de façon récursive
 */
function getSousCategoriesRecursif($pdo, $categorieId, $parentId = null) {
    if ($parentId === null) {
        $stmt = $pdo->prepare("
            SELECT sc.*
            FROM sous_categories sc
            WHERE sc.categorie_id = ? AND sc.parent_id IS NULL AND sc.actif = 1
            ORDER BY sc.ordre, sc.nom
        ");
        $stmt->execute([$categorieId]);
    } else {
        $stmt = $pdo->prepare("
            SELECT sc.*
            FROM sous_categories sc
            WHERE sc.categorie_id = ? AND sc.parent_id = ? AND sc.actif = 1
            ORDER BY sc.ordre, sc.nom
        ");
        $stmt->execute([$categorieId, $parentId]);
    }

    $sousCategories = $stmt->fetchAll();

    foreach ($sousCategories as &$sc) {
        // Récupérer les matériaux
        $stmt = $pdo->prepare("SELECT * FROM materiaux WHERE sous_categorie_id = ? AND actif = 1 ORDER BY ordre, nom");
        $stmt->execute([$sc['id']]);
        $sc['materiaux'] = $stmt->fetchAll();

        // Récupérer les enfants récursivement
        $sc['enfants'] = getSousCategoriesRecursif($pdo, $categorieId, $sc['id']);
    }

    return $sousCategories;
}

// Récupérer les catégories
$stmt = $pdo->query("SELECT * FROM categories ORDER BY groupe, ordre, nom");
$categories = $stmt->fetchAll();

// Récupérer la catégorie sélectionnée
$categorieSelectionnee = null;
if ($categorieId) {
    $stmt = $pdo->prepare("SELECT * FROM categories WHERE id = ?");
    $stmt->execute([$categorieId]);
    $categorieSelectionnee = $stmt->fetch();
}

// Récupérer les sous-catégories de façon récursive
$sousCategories = [];
if ($categorieId) {
    $sousCategories = getSousCategoriesRecursif($pdo, $categorieId);
}

// Grouper les catégories
$categoriesGroupees = [];
foreach ($categories as $cat) {
    $categoriesGroupees[$cat['groupe']][] = $cat;
}

// Compter toutes les sous-catégories (incluant les imbriquées)
function compterSousCategories($sousCategories) {
    $count = count($sousCategories);
    foreach ($sousCategories as $sc) {
        if (!empty($sc['enfants'])) {
            $count += compterSousCategories($sc['enfants']);
        }
    }
    return $count;
}

include '../../includes/header.php';

/**
 * Afficher les sous-catégories de façon récursive
 */
function afficherSousCategoriesRecursif($sousCategories, $categorieId, $niveau = 0) {
    if (empty($sousCategories)) return;

    $marginLeft = $niveau * 20;

    foreach ($sousCategories as $index => $sc):
        $uniqueId = $sc['id'];
        $hasChildren = !empty($sc['enfants']);
        $hasMateriaux = !empty($sc['materiaux']);
?>
    <div class="card mb-2" style="margin-left: <?= $marginLeft ?>px;">
        <div class="card-header py-2 d-flex justify-content-between align-items-center"
             style="background: <?= $niveau === 0 ? 'var(--bg-card-header)' : ($niveau === 1 ? '#e3f2fd' : '#f3e5f5') ?>;">
            <div class="d-flex align-items-center">
                <?php if ($hasChildren || $hasMateriaux): ?>
                    <a class="text-decoration-none me-2" data-bs-toggle="collapse" href="#content<?= $uniqueId ?>" role="button">
                        <i class="bi bi-chevron-down"></i>
                    </a>
                <?php else: ?>
                    <span class="me-2" style="width: 16px;"></span>
                <?php endif; ?>

                <strong><?= e($sc['nom']) ?></strong>

                <?php if ($hasChildren): ?>
                    <span class="badge bg-info ms-2" title="Sous-catégories"><?= count($sc['enfants']) ?> sous-cat.</span>
                <?php endif; ?>
                <?php if ($hasMateriaux): ?>
                    <span class="badge bg-primary ms-1" title="Matériaux"><?= count($sc['materiaux']) ?> items</span>
                <?php endif; ?>
            </div>
            <div class="btn-group btn-group-sm">
                <button type="button" class="btn btn-outline-success btn-sm" data-bs-toggle="modal" data-bs-target="#addChildModal<?= $uniqueId ?>" title="Ajouter sous-catégorie">
                    <i class="bi bi-folder-plus"></i>
                </button>
                <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addMatModal<?= $uniqueId ?>" title="Ajouter matériau">
                    <i class="bi bi-plus-circle"></i>
                </button>
                <button type="button" class="btn btn-outline-warning btn-sm" data-bs-toggle="modal" data-bs-target="#editSousCatModal<?= $uniqueId ?>" title="Modifier">
                    <i class="bi bi-pencil"></i>
                </button>
                <button type="button" class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#deleteSousCatModal<?= $uniqueId ?>" title="Supprimer">
                    <i class="bi bi-trash"></i>
                </button>
            </div>
        </div>

        <?php if ($hasChildren || $hasMateriaux): ?>
        <div class="collapse show" id="content<?= $uniqueId ?>">
            <div class="card-body py-2">
                <?php if ($hasMateriaux): ?>
                    <table class="table table-sm table-hover mb-2">
                        <thead class="table-light">
                            <tr>
                                <th>Matériau</th>
                                <th class="text-end" style="width: 120px;">Prix défaut</th>
                                <th style="width: 80px;"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sc['materiaux'] as $mat): ?>
                                <tr>
                                    <td><i class="bi bi-box-seam me-1 text-muted"></i><?= e($mat['nom']) ?></td>
                                    <td class="text-end"><?= formatMoney($mat['prix_defaut']) ?></td>
                                    <td class="text-end">
                                        <button type="button" class="btn btn-outline-primary btn-sm py-0 px-1"
                                                data-bs-toggle="modal" data-bs-target="#editMatModal<?= $mat['id'] ?>">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <button type="button" class="btn btn-outline-danger btn-sm py-0 px-1"
                                                data-bs-toggle="modal" data-bs-target="#deleteMatModal<?= $mat['id'] ?>">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </td>
                                </tr>

                                <!-- Modal Modifier Matériau -->
                                <div class="modal fade" id="editMatModal<?= $mat['id'] ?>" tabindex="-1">
                                    <div class="modal-dialog">
                                        <div class="modal-content">
                                            <form method="POST">
                                                <?php csrfField(); ?>
                                                <input type="hidden" name="action" value="modifier_materiau">
                                                <input type="hidden" name="id" value="<?= $mat['id'] ?>">
                                                <div class="modal-header">
                                                    <h5 class="modal-title">Modifier matériau</h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                </div>
                                                <div class="modal-body">
                                                    <div class="mb-3">
                                                        <label class="form-label">Nom</label>
                                                        <input type="text" class="form-control" name="nom" value="<?= e($mat['nom']) ?>" required>
                                                    </div>
                                                    <div class="mb-3">
                                                        <label class="form-label">Prix par défaut</label>
                                                        <div class="input-group">
                                                            <span class="input-group-text">$</span>
                                                            <input type="text" class="form-control" name="prix_defaut" value="<?= $mat['prix_defaut'] ?>">
                                                        </div>
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

                                <!-- Modal Supprimer Matériau -->
                                <div class="modal fade" id="deleteMatModal<?= $mat['id'] ?>" tabindex="-1">
                                    <div class="modal-dialog">
                                        <div class="modal-content">
                                            <div class="modal-header bg-danger text-white">
                                                <h5 class="modal-title">Supprimer</h5>
                                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                            </div>
                                            <div class="modal-body">
                                                <p>Supprimer <strong><?= e($mat['nom']) ?></strong>?</p>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                                                <form method="POST" class="d-inline">
                                                    <?php csrfField(); ?>
                                                    <input type="hidden" name="action" value="supprimer_materiau">
                                                    <input type="hidden" name="id" value="<?= $mat['id'] ?>">
                                                    <button type="submit" class="btn btn-danger">Supprimer</button>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>

                <?php if ($hasChildren): ?>
                    <?php afficherSousCategoriesRecursif($sc['enfants'], $categorieId, $niveau + 1); ?>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Modal Ajouter Sous-catégorie enfant -->
    <div class="modal fade" id="addChildModal<?= $uniqueId ?>" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <?php csrfField(); ?>
                    <input type="hidden" name="action" value="ajouter_sous_categorie">
                    <input type="hidden" name="categorie_id" value="<?= $categorieId ?>">
                    <input type="hidden" name="parent_id" value="<?= $sc['id'] ?>">
                    <div class="modal-header">
                        <h5 class="modal-title">Nouvelle sous-catégorie dans <?= e($sc['nom']) ?></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Nom de la sous-catégorie *</label>
                            <input type="text" class="form-control" name="nom" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-success">Ajouter</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Modifier Sous-catégorie -->
    <div class="modal fade" id="editSousCatModal<?= $uniqueId ?>" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <?php csrfField(); ?>
                    <input type="hidden" name="action" value="modifier_sous_categorie">
                    <input type="hidden" name="id" value="<?= $sc['id'] ?>">
                    <div class="modal-header">
                        <h5 class="modal-title">Modifier sous-catégorie</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Nom</label>
                            <input type="text" class="form-control" name="nom" value="<?= e($sc['nom']) ?>" required>
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

    <!-- Modal Supprimer Sous-catégorie -->
    <div class="modal fade" id="deleteSousCatModal<?= $uniqueId ?>" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">Supprimer</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Supprimer <strong><?= e($sc['nom']) ?></strong>?</p>
                    <?php if ($hasChildren || $hasMateriaux): ?>
                        <div class="alert alert-warning mb-0">
                            <i class="bi bi-exclamation-triangle me-1"></i>
                            Ceci supprimera aussi:
                            <?php if ($hasChildren): ?><br>- <?= count($sc['enfants']) ?> sous-catégorie(s)<?php endif; ?>
                            <?php if ($hasMateriaux): ?><br>- <?= count($sc['materiaux']) ?> matériau(x)<?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                    <form method="POST" class="d-inline">
                        <?php csrfField(); ?>
                        <input type="hidden" name="action" value="supprimer_sous_categorie">
                        <input type="hidden" name="id" value="<?= $sc['id'] ?>">
                        <button type="submit" class="btn btn-danger">Supprimer</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Ajouter Matériau -->
    <div class="modal fade" id="addMatModal<?= $uniqueId ?>" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <?php csrfField(); ?>
                    <input type="hidden" name="action" value="ajouter_materiau">
                    <input type="hidden" name="sous_categorie_id" value="<?= $sc['id'] ?>">
                    <div class="modal-header">
                        <h5 class="modal-title">Ajouter un matériau à <?= e($sc['nom']) ?></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Nom du matériau *</label>
                            <input type="text" class="form-control" name="nom" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Prix par défaut</label>
                            <div class="input-group">
                                <span class="input-group-text">$</span>
                                <input type="text" class="form-control" name="prix_defaut" value="0">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-success">Ajouter</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
<?php
    endforeach;
}
?>

<div class="container-fluid">
    <!-- En-tête -->
    <div class="page-header d-flex justify-content-between align-items-start flex-wrap">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="<?= url('/admin/index.php') ?>">Tableau de bord</a></li>
                    <li class="breadcrumb-item active">Templates Budgets</li>
                </ol>
            </nav>
            <h1><i class="bi bi-box-seam me-2"></i>Templates Budgets</h1>
            <p class="text-muted mb-0">Gérer les sous-catégories imbriquées et matériaux</p>
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
            <a class="nav-link" href="<?= url('/admin/categories/liste.php') ?>">
                <i class="bi bi-tags me-1"></i>Catégories
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link active" href="<?= url('/admin/templates/liste.php') ?>">
                <i class="bi bi-box-seam me-1"></i>Templates
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="<?= url('/admin/fournisseurs/liste.php') ?>">
                <i class="bi bi-shop me-1"></i>Fournisseurs
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

    <div class="row">
        <!-- Colonne gauche: Liste des catégories -->
        <div class="col-md-3">
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-folder me-1"></i>Catégories
                </div>
                <div class="list-group list-group-flush" style="max-height: 70vh; overflow-y: auto;">
                    <?php foreach ($groupeLabels as $groupe => $label): ?>
                        <?php if (!empty($categoriesGroupees[$groupe])): ?>
                            <div class="list-group-item bg-light py-1 small fw-bold text-muted">
                                <?= $label ?>
                            </div>
                            <?php foreach ($categoriesGroupees[$groupe] as $cat): ?>
                                <a href="?categorie=<?= $cat['id'] ?>"
                                   class="list-group-item list-group-item-action py-2 <?= $categorieId == $cat['id'] ? 'active' : '' ?>">
                                    <?= e($cat['nom']) ?>
                                    <?php
                                    // Compter les sous-catégories de premier niveau
                                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM sous_categories WHERE categorie_id = ? AND parent_id IS NULL AND actif = 1");
                                    $stmt->execute([$cat['id']]);
                                    $nbSc = $stmt->fetchColumn();
                                    ?>
                                    <?php if ($nbSc > 0): ?>
                                        <span class="badge bg-secondary float-end"><?= $nbSc ?></span>
                                    <?php endif; ?>
                                </a>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Colonne droite: Détails de la catégorie -->
        <div class="col-md-9">
            <?php if (!$categorieSelectionnee): ?>
                <div class="card">
                    <div class="card-body text-center py-5">
                        <i class="bi bi-arrow-left-circle" style="font-size: 3rem; color: var(--text-muted);"></i>
                        <p class="text-muted mt-3 mb-0">Sélectionnez une catégorie pour voir et gérer ses sous-catégories</p>
                    </div>
                </div>
            <?php else: ?>
                <div class="card mb-3">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <div>
                            <i class="bi bi-folder-fill me-1 text-warning"></i>
                            <strong><?= e($categorieSelectionnee['nom']) ?></strong>
                            <span class="badge bg-secondary ms-2"><?= compterSousCategories($sousCategories) ?> sous-catégories</span>
                        </div>
                        <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addSousCatModal">
                            <i class="bi bi-plus-circle me-1"></i>Sous-catégorie
                        </button>
                    </div>
                </div>

                <?php if (empty($sousCategories)): ?>
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        Aucune sous-catégorie. Cliquez sur "Sous-catégorie" pour en ajouter une.
                    </div>
                <?php else: ?>
                    <?php afficherSousCategoriesRecursif($sousCategories, $categorieId); ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($categorieSelectionnee): ?>
<!-- Modal Ajouter Sous-catégorie de premier niveau -->
<div class="modal fade" id="addSousCatModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <?php csrfField(); ?>
                <input type="hidden" name="action" value="ajouter_sous_categorie">
                <input type="hidden" name="categorie_id" value="<?= $categorieId ?>">
                <input type="hidden" name="parent_id" value="">
                <div class="modal-header">
                    <h5 class="modal-title">Nouvelle sous-catégorie dans <?= e($categorieSelectionnee['nom']) ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Nom de la sous-catégorie *</label>
                        <input type="text" class="form-control" name="nom" required placeholder="Ex: Bain/Douche, Toilette, Vanité...">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-primary">Ajouter</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php include '../../includes/footer.php'; ?>
