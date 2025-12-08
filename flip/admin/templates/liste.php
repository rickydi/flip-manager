<?php
/**
 * Gestion des templates de budgets - Admin
 * Sous-catégories et Matériaux
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

            if (empty($nom) || !$catId) {
                $errors[] = 'Données invalides.';
            } else {
                $stmt = $pdo->prepare("SELECT MAX(ordre) FROM sous_categories WHERE categorie_id = ?");
                $stmt->execute([$catId]);
                $maxOrdre = $stmt->fetchColumn() ?: 0;

                $stmt = $pdo->prepare("INSERT INTO sous_categories (categorie_id, nom, ordre) VALUES (?, ?, ?)");
                if ($stmt->execute([$catId, $nom, $maxOrdre + 1])) {
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
                // Vérifier si utilisée
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM materiaux WHERE sous_categorie_id = ?");
                $stmt->execute([$id]);
                $count = $stmt->fetchColumn();

                if ($count > 0) {
                    // Supprimer aussi les matériaux
                    $pdo->prepare("DELETE FROM materiaux WHERE sous_categorie_id = ?")->execute([$id]);
                }

                $stmt = $pdo->prepare("DELETE FROM sous_categories WHERE id = ?");
                if ($stmt->execute([$id])) {
                    setFlashMessage('success', 'Sous-catégorie supprimée!');
                    redirect('/admin/templates/liste.php?categorie=' . $categorieId);
                }
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

// Récupérer les sous-catégories et matériaux de la catégorie sélectionnée
$sousCategories = [];
if ($categorieId) {
    $stmt = $pdo->prepare("
        SELECT sc.*,
               (SELECT COUNT(*) FROM materiaux m WHERE m.sous_categorie_id = sc.id) as nb_materiaux
        FROM sous_categories sc
        WHERE sc.categorie_id = ? AND sc.actif = 1
        ORDER BY sc.ordre, sc.nom
    ");
    $stmt->execute([$categorieId]);
    $sousCategories = $stmt->fetchAll();

    // Récupérer les matériaux pour chaque sous-catégorie
    foreach ($sousCategories as &$sc) {
        $stmt = $pdo->prepare("SELECT * FROM materiaux WHERE sous_categorie_id = ? AND actif = 1 ORDER BY ordre, nom");
        $stmt->execute([$sc['id']]);
        $sc['materiaux'] = $stmt->fetchAll();
    }
}

// Grouper les catégories
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
                    <li class="breadcrumb-item active">Templates Budgets</li>
                </ol>
            </nav>
            <h1><i class="bi bi-box-seam me-2"></i>Templates Budgets</h1>
            <p class="text-muted mb-0">Gérer les sous-catégories et matériaux par défaut</p>
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
                                    // Compter les sous-catégories
                                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM sous_categories WHERE categorie_id = ? AND actif = 1");
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
                        <p class="text-muted mt-3 mb-0">Sélectionnez une catégorie pour voir et gérer ses sous-catégories et matériaux</p>
                    </div>
                </div>
            <?php else: ?>
                <div class="card mb-3">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <div>
                            <i class="bi bi-folder-fill me-1 text-warning"></i>
                            <strong><?= e($categorieSelectionnee['nom']) ?></strong>
                            <span class="badge bg-secondary ms-2"><?= count($sousCategories) ?> sous-catégories</span>
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
                    <div class="accordion" id="accordionSousCategories">
                        <?php foreach ($sousCategories as $index => $sc): ?>
                            <div class="accordion-item">
                                <h2 class="accordion-header">
                                    <button class="accordion-button <?= $index > 0 ? 'collapsed' : '' ?>" type="button"
                                            data-bs-toggle="collapse" data-bs-target="#collapse<?= $sc['id'] ?>">
                                        <span class="me-2"><?= e($sc['nom']) ?></span>
                                        <span class="badge bg-primary"><?= count($sc['materiaux']) ?> items</span>
                                    </button>
                                </h2>
                                <div id="collapse<?= $sc['id'] ?>" class="accordion-collapse collapse <?= $index === 0 ? 'show' : '' ?>"
                                     data-bs-parent="#accordionSousCategories">
                                    <div class="accordion-body p-2">
                                        <!-- Actions sous-catégorie -->
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <div>
                                                <button type="button" class="btn btn-outline-primary btn-sm"
                                                        data-bs-toggle="modal" data-bs-target="#editSousCatModal<?= $sc['id'] ?>">
                                                    <i class="bi bi-pencil me-1"></i>Modifier
                                                </button>
                                                <button type="button" class="btn btn-outline-danger btn-sm"
                                                        data-bs-toggle="modal" data-bs-target="#deleteSousCatModal<?= $sc['id'] ?>">
                                                    <i class="bi bi-trash me-1"></i>Supprimer
                                                </button>
                                            </div>
                                            <button type="button" class="btn btn-success btn-sm"
                                                    data-bs-toggle="modal" data-bs-target="#addMatModal<?= $sc['id'] ?>">
                                                <i class="bi bi-plus me-1"></i>Matériau
                                            </button>
                                        </div>

                                        <!-- Liste des matériaux -->
                                        <?php if (empty($sc['materiaux'])): ?>
                                            <p class="text-muted small mb-0">Aucun matériau</p>
                                        <?php else: ?>
                                            <table class="table table-sm table-hover mb-0">
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
                                                            <td><?= e($mat['nom']) ?></td>
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
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <!-- Modal Modifier Sous-catégorie -->
                            <div class="modal fade" id="editSousCatModal<?= $sc['id'] ?>" tabindex="-1">
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
                            <div class="modal fade" id="deleteSousCatModal<?= $sc['id'] ?>" tabindex="-1">
                                <div class="modal-dialog">
                                    <div class="modal-content">
                                        <div class="modal-header bg-danger text-white">
                                            <h5 class="modal-title">Supprimer</h5>
                                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body">
                                            <p>Supprimer <strong><?= e($sc['nom']) ?></strong> et ses <?= count($sc['materiaux']) ?> matériaux?</p>
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
                            <div class="modal fade" id="addMatModal<?= $sc['id'] ?>" tabindex="-1">
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

                            <!-- Modals pour chaque matériau -->
                            <?php foreach ($sc['materiaux'] as $mat): ?>
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

                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($categorieSelectionnee): ?>
<!-- Modal Ajouter Sous-catégorie -->
<div class="modal fade" id="addSousCatModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <?php csrfField(); ?>
                <input type="hidden" name="action" value="ajouter_sous_categorie">
                <input type="hidden" name="categorie_id" value="<?= $categorieId ?>">
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
