<?php
/**
 * Gestion des catégories de photos - Admin
 * Flip Manager
 */

require_once '../../config.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

requireAdmin();

$pageTitle = 'Catégories de photos';

$errors = [];
$success = '';

// Traitement des actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token de sécurité invalide.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'add') {
            $nomFr = trim($_POST['nom_fr'] ?? '');
            $nomEs = trim($_POST['nom_es'] ?? '');
            $ordre = (int)($_POST['ordre'] ?? 0);

            if (empty($nomFr)) {
                $errors[] = 'Le nom en français est obligatoire.';
            } else {
                // Générer une clé unique
                $cle = 'cat_' . preg_replace('/[^a-z0-9]+/', '_', strtolower(removeAccents($nomFr)));
                $cle = rtrim($cle, '_');

                // Vérifier que la clé n'existe pas
                $stmt = $pdo->prepare("SELECT id FROM photos_categories WHERE cle = ?");
                $stmt->execute([$cle]);
                if ($stmt->fetch()) {
                    $cle .= '_' . uniqid();
                }

                $stmt = $pdo->prepare("INSERT INTO photos_categories (cle, nom_fr, nom_es, ordre, actif) VALUES (?, ?, ?, ?, 1)");
                $stmt->execute([$cle, $nomFr, $nomEs ?: $nomFr, $ordre]);

                setFlashMessage('success', 'Catégorie ajoutée avec succès.');
                redirect('/admin/photos/categories.php');
            }
        } elseif ($action === 'edit') {
            $id = (int)($_POST['id'] ?? 0);
            $nomFr = trim($_POST['nom_fr'] ?? '');
            $nomEs = trim($_POST['nom_es'] ?? '');
            $ordre = (int)($_POST['ordre'] ?? 0);

            if (empty($nomFr)) {
                $errors[] = 'Le nom en français est obligatoire.';
            } else {
                $stmt = $pdo->prepare("UPDATE photos_categories SET nom_fr = ?, nom_es = ?, ordre = ? WHERE id = ?");
                $stmt->execute([$nomFr, $nomEs ?: $nomFr, $ordre, $id]);

                setFlashMessage('success', 'Catégorie modifiée avec succès.');
                redirect('/admin/photos/categories.php');
            }
        } elseif ($action === 'toggle') {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $pdo->prepare("UPDATE photos_categories SET actif = NOT actif WHERE id = ?");
            $stmt->execute([$id]);

            setFlashMessage('success', 'Statut de la catégorie modifié.');
            redirect('/admin/photos/categories.php');
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);

            // Vérifier si la catégorie est utilisée
            $stmt = $pdo->prepare("SELECT cle FROM photos_categories WHERE id = ?");
            $stmt->execute([$id]);
            $cat = $stmt->fetch();

            if ($cat) {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM photos_projet WHERE description = ?");
                $stmt->execute([$cat['cle']]);
                $count = $stmt->fetchColumn();

                if ($count > 0) {
                    $errors[] = "Cette catégorie est utilisée par $count groupe(s) de photos. Impossible de la supprimer.";
                } else {
                    $stmt = $pdo->prepare("DELETE FROM photos_categories WHERE id = ?");
                    $stmt->execute([$id]);

                    setFlashMessage('success', 'Catégorie supprimée.');
                    redirect('/admin/photos/categories.php');
                }
            }
        }
    }
}

// Récupérer les catégories
$stmt = $pdo->query("SELECT * FROM photos_categories ORDER BY ordre, nom_fr");
$categories = $stmt->fetchAll();

// Fonction pour enlever les accents
function removeAccents($string) {
    $unwanted = array(
        'À'=>'A', 'Á'=>'A', 'Â'=>'A', 'Ã'=>'A', 'Ä'=>'A', 'Å'=>'A',
        'à'=>'a', 'á'=>'a', 'â'=>'a', 'ã'=>'a', 'ä'=>'a', 'å'=>'a',
        'È'=>'E', 'É'=>'E', 'Ê'=>'E', 'Ë'=>'E',
        'è'=>'e', 'é'=>'e', 'ê'=>'e', 'ë'=>'e',
        'Ì'=>'I', 'Í'=>'I', 'Î'=>'I', 'Ï'=>'I',
        'ì'=>'i', 'í'=>'i', 'î'=>'i', 'ï'=>'i',
        'Ò'=>'O', 'Ó'=>'O', 'Ô'=>'O', 'Õ'=>'O', 'Ö'=>'O',
        'ò'=>'o', 'ó'=>'o', 'ô'=>'o', 'õ'=>'o', 'ö'=>'o',
        'Ù'=>'U', 'Ú'=>'U', 'Û'=>'U', 'Ü'=>'U',
        'ù'=>'u', 'ú'=>'u', 'û'=>'u', 'ü'=>'u',
        'Ç'=>'C', 'ç'=>'c', 'Ñ'=>'N', 'ñ'=>'n'
    );
    return strtr($string, $unwanted);
}

include '../../includes/header.php';
?>

<div class="container-fluid">
    <div class="page-header">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= url('/admin/index.php') ?>">Tableau de bord</a></li>
                <li class="breadcrumb-item"><a href="<?= url('/admin/photos/liste.php') ?>">Photos</a></li>
                <li class="breadcrumb-item active">Catégories</li>
            </ol>
        </nav>
        <div class="d-flex justify-content-between align-items-center">
            <h1><i class="bi bi-tags me-2"></i>Catégories de photos</h1>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
                <i class="bi bi-plus-circle me-2"></i>Nouvelle catégorie
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
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th style="width: 60px;">Ordre</th>
                        <th>Nom (FR)</th>
                        <th>Nom (ES)</th>
                        <th style="width: 100px;">Statut</th>
                        <th style="width: 150px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($categories)): ?>
                        <tr>
                            <td colspan="5" class="text-center py-4 text-muted">
                                Aucune catégorie. Cliquez sur "Nouvelle catégorie" pour en créer une.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($categories as $cat): ?>
                            <tr class="<?= !$cat['actif'] ? 'table-secondary' : '' ?>">
                                <td><?= $cat['ordre'] ?></td>
                                <td>
                                    <strong><?= e($cat['nom_fr']) ?></strong>
                                    <br><small class="text-muted"><?= e($cat['cle']) ?></small>
                                </td>
                                <td><?= e($cat['nom_es']) ?></td>
                                <td>
                                    <?php if ($cat['actif']): ?>
                                        <span class="badge bg-success">Actif</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Inactif</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-outline-primary"
                                            onclick="editCategory(<?= htmlspecialchars(json_encode($cat)) ?>)">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <form method="POST" class="d-inline">
                                        <?php csrfField(); ?>
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="id" value="<?= $cat['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-<?= $cat['actif'] ? 'warning' : 'success' ?>"
                                                title="<?= $cat['actif'] ? 'Désactiver' : 'Activer' ?>">
                                            <i class="bi bi-<?= $cat['actif'] ? 'eye-slash' : 'eye' ?>"></i>
                                        </button>
                                    </form>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Supprimer cette catégorie ?');">
                                        <?php csrfField(); ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= $cat['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal Ajouter -->
<div class="modal fade" id="addModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <?php csrfField(); ?>
                <input type="hidden" name="action" value="add">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>Nouvelle catégorie</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Nom (Français) *</label>
                        <input type="text" class="form-control" name="nom_fr" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Nom (Espagnol)</label>
                        <input type="text" class="form-control" name="nom_es">
                        <small class="text-muted">Laissez vide pour utiliser le nom français</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Ordre d'affichage</label>
                        <input type="number" class="form-control" name="ordre" value="0" min="0">
                        <small class="text-muted">Les catégories sont triées par ordre croissant</small>
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

<!-- Modal Modifier -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <?php csrfField(); ?>
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" id="edit_id">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Modifier la catégorie</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Nom (Français) *</label>
                        <input type="text" class="form-control" name="nom_fr" id="edit_nom_fr" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Nom (Espagnol)</label>
                        <input type="text" class="form-control" name="nom_es" id="edit_nom_es">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Ordre d'affichage</label>
                        <input type="number" class="form-control" name="ordre" id="edit_ordre" min="0">
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

<script>
function editCategory(cat) {
    document.getElementById('edit_id').value = cat.id;
    document.getElementById('edit_nom_fr').value = cat.nom_fr;
    document.getElementById('edit_nom_es').value = cat.nom_es || '';
    document.getElementById('edit_ordre').value = cat.ordre;

    new bootstrap.Modal(document.getElementById('editModal')).show();
}
</script>

<?php include '../../includes/footer.php'; ?>
