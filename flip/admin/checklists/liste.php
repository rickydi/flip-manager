<?php
/**
 * Gestion des templates de checklists - Admin
 * Flip Manager
 */

require_once '../../config.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

requireAdmin();

$pageTitle = 'Checklists';

// Auto-migration: créer les tables si elles n'existent pas
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS checklist_templates (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nom VARCHAR(255) NOT NULL,
            description TEXT,
            ordre INT DEFAULT 0,
            actif TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS checklist_template_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            template_id INT NOT NULL,
            nom VARCHAR(255) NOT NULL,
            description TEXT,
            ordre INT DEFAULT 0,
            FOREIGN KEY (template_id) REFERENCES checklist_templates(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS projet_checklists (
            id INT AUTO_INCREMENT PRIMARY KEY,
            projet_id INT NOT NULL,
            template_item_id INT NOT NULL,
            complete TINYINT(1) DEFAULT 0,
            complete_date DATETIME NULL,
            complete_by VARCHAR(100) NULL,
            notes TEXT,
            FOREIGN KEY (projet_id) REFERENCES projets(id) ON DELETE CASCADE,
            FOREIGN KEY (template_item_id) REFERENCES checklist_template_items(id) ON DELETE CASCADE,
            UNIQUE KEY unique_projet_item (projet_id, template_item_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Exception $e) {
    // Tables existent déjà
}

$errors = [];

// Traitement des actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token de sécurité invalide.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'creer_template') {
            $nom = trim($_POST['nom'] ?? '');
            $description = trim($_POST['description'] ?? '');

            if (empty($nom)) $errors[] = 'Le nom est requis.';

            if (empty($errors)) {
                $stmt = $pdo->prepare("INSERT INTO checklist_templates (nom, description, ordre) VALUES (?, ?, (SELECT COALESCE(MAX(o.ordre), 0) + 1 FROM checklist_templates o))");
                $stmt->execute([$nom, $description]);
                setFlashMessage('success', 'Checklist créée.');
                redirect('/admin/checklists/liste.php');
            }

        } elseif ($action === 'modifier_template') {
            $id = (int)($_POST['id'] ?? 0);
            $nom = trim($_POST['nom'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $actif = isset($_POST['actif']) ? 1 : 0;

            if (empty($nom)) $errors[] = 'Le nom est requis.';

            if (empty($errors)) {
                $stmt = $pdo->prepare("UPDATE checklist_templates SET nom = ?, description = ?, actif = ? WHERE id = ?");
                $stmt->execute([$nom, $description, $actif, $id]);
                setFlashMessage('success', 'Checklist modifiée.');
                redirect('/admin/checklists/liste.php');
            }

        } elseif ($action === 'supprimer_template') {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $pdo->prepare("DELETE FROM checklist_templates WHERE id = ?");
            $stmt->execute([$id]);
            setFlashMessage('success', 'Checklist supprimée.');
            redirect('/admin/checklists/liste.php');

        } elseif ($action === 'ajouter_item') {
            $templateId = (int)($_POST['template_id'] ?? 0);
            $nom = trim($_POST['nom'] ?? '');

            if (empty($nom)) $errors[] = 'Le nom de l\'item est requis.';

            if (empty($errors)) {
                $stmt = $pdo->prepare("INSERT INTO checklist_template_items (template_id, nom, ordre) VALUES (?, ?, (SELECT COALESCE(MAX(o.ordre), 0) + 1 FROM checklist_template_items o WHERE o.template_id = ?))");
                $stmt->execute([$templateId, $nom, $templateId]);
                setFlashMessage('success', 'Item ajouté.');
                redirect('/admin/checklists/liste.php');
            }

        } elseif ($action === 'supprimer_item') {
            $id = (int)($_POST['item_id'] ?? 0);
            $stmt = $pdo->prepare("DELETE FROM checklist_template_items WHERE id = ?");
            $stmt->execute([$id]);
            setFlashMessage('success', 'Item supprimé.');
            redirect('/admin/checklists/liste.php');

        } elseif ($action === 'modifier_item') {
            $id = (int)($_POST['item_id'] ?? 0);
            $nom = trim($_POST['nom'] ?? '');

            if (empty($nom)) $errors[] = 'Le nom de l\'item est requis.';

            if (empty($errors)) {
                $stmt = $pdo->prepare("UPDATE checklist_template_items SET nom = ? WHERE id = ?");
                $stmt->execute([$nom, $id]);
                setFlashMessage('success', 'Item modifié.');
                redirect('/admin/checklists/liste.php');
            }
        }
    }
}

// Récupérer les templates avec leurs items
$stmt = $pdo->query("SELECT * FROM checklist_templates ORDER BY ordre, nom");
$templates = $stmt->fetchAll();

// Récupérer les items pour chaque template
foreach ($templates as &$template) {
    $stmt = $pdo->prepare("SELECT * FROM checklist_template_items WHERE template_id = ? ORDER BY ordre, nom");
    $stmt->execute([$template['id']]);
    $template['items'] = $stmt->fetchAll();
}
unset($template);

include '../../includes/header.php';
?>

<div class="container-fluid">
    <div class="page-header d-flex justify-content-between align-items-start flex-wrap">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="<?= url('/admin/index.php') ?>">Tableau de bord</a></li>
                    <li class="breadcrumb-item active">Administration</li>
                </ol>
            </nav>
            <h1><i class="bi bi-list-check me-2"></i>Checklists</h1>
        </div>
        <div class="d-flex gap-2 mt-2 mt-md-0">
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalCreer">
                <i class="bi bi-plus-circle me-1"></i>Nouvelle checklist
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
            <a class="nav-link" href="<?= url('/admin/categories/liste.php') ?>">
                <i class="bi bi-tags me-1"></i>Catégories
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
            <a class="nav-link active" href="<?= url('/admin/checklists/liste.php') ?>">
                <i class="bi bi-list-check me-1"></i>Checklists
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="<?= url('/admin/rapports/paie-hebdo.php') ?>">
                <i class="bi bi-file-earmark-spreadsheet me-1"></i>Paie hebdo
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

    <div class="card mb-4">
        <div class="card-header">
            <i class="bi bi-info-circle me-1"></i>
            Créez des templates de checklists qui seront disponibles dans chaque projet.
        </div>
    </div>

    <?php if (empty($templates)): ?>
        <div class="alert alert-info">
            <i class="bi bi-info-circle me-2"></i>Aucune checklist créée. Cliquez sur "Nouvelle checklist" pour commencer.
        </div>
    <?php else: ?>
        <div class="row">
            <?php foreach ($templates as $template): ?>
                <div class="col-md-6 col-lg-4 mb-4">
                    <div class="card h-100 <?= !$template['actif'] ? 'border-secondary opacity-50' : '' ?>">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <span>
                                <i class="bi bi-list-check me-1"></i>
                                <strong><?= e($template['nom']) ?></strong>
                                <?php if (!$template['actif']): ?>
                                    <span class="badge bg-secondary ms-1">Inactif</span>
                                <?php endif; ?>
                            </span>
                            <div class="dropdown">
                                <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                    <i class="bi bi-three-dots-vertical"></i>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li>
                                        <button class="dropdown-item" data-bs-toggle="modal" data-bs-target="#modalModifier<?= $template['id'] ?>">
                                            <i class="bi bi-pencil me-2"></i>Modifier
                                        </button>
                                    </li>
                                    <li><hr class="dropdown-divider"></li>
                                    <li>
                                        <form method="POST" action="" onsubmit="return confirm('Supprimer cette checklist et tous ses items ?');">
                                            <?php csrfField(); ?>
                                            <input type="hidden" name="action" value="supprimer_template">
                                            <input type="hidden" name="id" value="<?= $template['id'] ?>">
                                            <button type="submit" class="dropdown-item text-danger">
                                                <i class="bi bi-trash me-2"></i>Supprimer
                                            </button>
                                        </form>
                                    </li>
                                </ul>
                            </div>
                        </div>
                        <?php if ($template['description']): ?>
                            <div class="card-body py-2 border-bottom bg-light">
                                <small class="text-muted"><?= e($template['description']) ?></small>
                            </div>
                        <?php endif; ?>
                        <ul class="list-group list-group-flush">
                            <?php if (empty($template['items'])): ?>
                                <li class="list-group-item text-muted small">Aucun item</li>
                            <?php else: ?>
                                <?php foreach ($template['items'] as $item): ?>
                                    <li class="list-group-item d-flex justify-content-between align-items-center py-2">
                                        <span><i class="bi bi-square me-2 text-muted"></i><?= e($item['nom']) ?></span>
                                        <div>
                                            <button class="btn btn-sm btn-link text-primary p-0 me-2"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#modalModifierItem<?= $item['id'] ?>"
                                                    title="Modifier">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <form method="POST" action="" class="d-inline" onsubmit="return confirm('Supprimer cet item ?');">
                                                <?php csrfField(); ?>
                                                <input type="hidden" name="action" value="supprimer_item">
                                                <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-link text-danger p-0" title="Supprimer">
                                                    <i class="bi bi-x-lg"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </ul>
                        <div class="card-footer">
                            <form method="POST" action="" class="d-flex gap-2">
                                <?php csrfField(); ?>
                                <input type="hidden" name="action" value="ajouter_item">
                                <input type="hidden" name="template_id" value="<?= $template['id'] ?>">
                                <input type="text" class="form-control form-control-sm" name="nom" placeholder="Nouvel item..." required>
                                <button type="submit" class="btn btn-sm btn-primary">
                                    <i class="bi bi-plus"></i>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Modal Modifier Template -->
                <div class="modal fade" id="modalModifier<?= $template['id'] ?>" tabindex="-1">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <form method="POST" action="">
                                <?php csrfField(); ?>
                                <input type="hidden" name="action" value="modifier_template">
                                <input type="hidden" name="id" value="<?= $template['id'] ?>">

                                <div class="modal-header">
                                    <h5 class="modal-title">Modifier la checklist</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <div class="mb-3">
                                        <label class="form-label">Nom *</label>
                                        <input type="text" class="form-control" name="nom" value="<?= e($template['nom']) ?>" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Description</label>
                                        <textarea class="form-control" name="description" rows="2"><?= e($template['description']) ?></textarea>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="actif" id="actif<?= $template['id'] ?>" <?= $template['actif'] ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="actif<?= $template['id'] ?>">Actif</label>
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

                <!-- Modals Modifier Items -->
                <?php foreach ($template['items'] as $item): ?>
                <div class="modal fade" id="modalModifierItem<?= $item['id'] ?>" tabindex="-1">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <form method="POST" action="">
                                <?php csrfField(); ?>
                                <input type="hidden" name="action" value="modifier_item">
                                <input type="hidden" name="item_id" value="<?= $item['id'] ?>">

                                <div class="modal-header">
                                    <h5 class="modal-title">Modifier l'item</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <div class="mb-3">
                                        <label class="form-label">Nom *</label>
                                        <input type="text" class="form-control" name="nom" value="<?= e($item['nom']) ?>" required>
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
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Modal Créer -->
<div class="modal fade" id="modalCreer" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="">
                <?php csrfField(); ?>
                <input type="hidden" name="action" value="creer_template">

                <div class="modal-header">
                    <h5 class="modal-title">Nouvelle checklist</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Nom *</label>
                        <input type="text" class="form-control" name="nom" required placeholder="Ex: Acquisition, Rénovation, Vente...">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea class="form-control" name="description" rows="2" placeholder="Description optionnelle..."></textarea>
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
