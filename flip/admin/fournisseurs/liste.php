<?php
/**
 * Gestion des fournisseurs - Admin
 * Flip Manager
 */

require_once '../../config.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

requireAdmin();

$pageTitle = 'Fournisseurs';

// Liste des fournisseurs par défaut
$fournisseursDefaut = [
    'Réno Dépot', 'Rona', 'BMR', 'Patrick Morin', 'Home Depot',
    'J-Jodoin', 'Ly Granite', 'COMMONWEALTH', 'CJP', 'Richelieu',
    'Canac', 'IKEA', 'Lowes', 'Canadian Tire'
];

// Créer la table fournisseurs si elle n'existe pas
try {
    // Vérifier si la table existe
    $tableExists = $pdo->query("SHOW TABLES LIKE 'fournisseurs'")->rowCount() > 0;

    if (!$tableExists) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS fournisseurs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                nom VARCHAR(255) NOT NULL UNIQUE,
                actif TINYINT(1) DEFAULT 1,
                date_creation DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Insérer les fournisseurs par défaut seulement à la création de la table
        $stmtInsert = $pdo->prepare("INSERT IGNORE INTO fournisseurs (nom) VALUES (?)");
        foreach ($fournisseursDefaut as $f) {
            $stmtInsert->execute([$f]);
        }

        // Importer les fournisseurs existants des factures
        $pdo->exec("
            INSERT IGNORE INTO fournisseurs (nom)
            SELECT DISTINCT fournisseur FROM factures WHERE fournisseur IS NOT NULL AND fournisseur != ''
        ");
    }
} catch (Exception $e) {
    // Ignorer
}

$errors = [];

// Traitement POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token de sécurité invalide.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'ajouter') {
            $nom = trim($_POST['nom'] ?? '');

            if (empty($nom)) {
                $errors[] = 'Le nom du fournisseur est requis.';
            } else {
                // Vérifier si le fournisseur existe déjà
                $stmt = $pdo->prepare("SELECT id FROM fournisseurs WHERE nom = ?");
                $stmt->execute([$nom]);
                if ($stmt->fetch()) {
                    $errors[] = 'Ce fournisseur existe déjà.';
                } else {
                    $stmt = $pdo->prepare("INSERT INTO fournisseurs (nom) VALUES (?)");
                    if ($stmt->execute([$nom])) {
                        setFlashMessage('success', 'Fournisseur ajouté!');
                        redirect('/admin/fournisseurs/liste.php');
                    } else {
                        $errors[] = 'Erreur lors de l\'ajout.';
                    }
                }
            }
        } elseif ($action === 'supprimer') {
            $id = (int)($_POST['fournisseur_id'] ?? 0);
            if ($id > 0) {
                $stmt = $pdo->prepare("DELETE FROM fournisseurs WHERE id = ?");
                if ($stmt->execute([$id])) {
                    setFlashMessage('success', 'Fournisseur supprimé!');
                } else {
                    setFlashMessage('danger', 'Erreur lors de la suppression.');
                }
                redirect('/admin/fournisseurs/liste.php');
            }
        } elseif ($action === 'modifier') {
            $id = (int)($_POST['fournisseur_id'] ?? 0);
            $nom = trim($_POST['nom'] ?? '');

            if (empty($nom)) {
                $errors[] = 'Le nom du fournisseur est requis.';
            } elseif ($id > 0) {
                $stmt = $pdo->prepare("UPDATE fournisseurs SET nom = ? WHERE id = ?");
                if ($stmt->execute([$nom, $id])) {
                    setFlashMessage('success', 'Fournisseur modifié!');
                    redirect('/admin/fournisseurs/liste.php');
                } else {
                    $errors[] = 'Erreur lors de la modification.';
                }
            }
        }
    }
}

// Récupérer tous les fournisseurs
$stmt = $pdo->query("SELECT * FROM fournisseurs ORDER BY nom ASC");
$fournisseurs = $stmt->fetchAll();

include '../../includes/header.php';
?>

<div class="container-fluid">
    <div class="page-header">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= url('/admin/index.php') ?>">Tableau de bord</a></li>
                <li class="breadcrumb-item active">Administration</li>
            </ol>
        </nav>
        <h1><i class="bi bi-gear me-2"></i>Administration</h1>
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

    <!-- Sous-navigation Administration -->
    <ul class="nav nav-tabs mb-4">
        <li class="nav-item">
            <a class="nav-link" href="<?= url('/admin/utilisateurs/liste.php') ?>">
                <i class="bi bi-person-badge me-1"></i>Utilisateurs
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link active" href="<?= url('/admin/fournisseurs/liste.php') ?>">
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

    <div class="row">
        <!-- Formulaire d'ajout -->
        <div class="col-md-4">
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-plus-circle me-2"></i>Ajouter un fournisseur
                </div>
                <div class="card-body">
                    <form method="POST">
                        <?php csrfField(); ?>
                        <input type="hidden" name="action" value="ajouter">
                        <div class="mb-3">
                            <label class="form-label">Nom du fournisseur *</label>
                            <input type="text" class="form-control" name="nom" required placeholder="Ex: Home Depot">
                        </div>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-plus-circle me-1"></i>Ajouter
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Liste des fournisseurs -->
        <div class="col-md-8">
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-shop me-2"></i>Liste des fournisseurs (<?= count($fournisseurs) ?>)
                </div>
                <div class="card-body p-0">
                    <?php if (empty($fournisseurs)): ?>
                        <div class="text-center py-5">
                            <i class="bi bi-shop text-muted" style="font-size: 4rem;"></i>
                            <h4 class="mt-3">Aucun fournisseur</h4>
                            <p class="text-muted">Ajoutez votre premier fournisseur.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>Nom</th>
                                        <th style="width: 120px;" class="text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($fournisseurs as $fournisseur): ?>
                                        <tr>
                                            <td>
                                                <i class="bi bi-shop me-2 text-muted"></i>
                                                <?= e($fournisseur['nom']) ?>
                                            </td>
                                            <td class="text-end">
                                                <button type="button" class="btn btn-outline-primary btn-sm"
                                                        onclick="modifierFournisseur(<?= $fournisseur['id'] ?>, '<?= e(addslashes($fournisseur['nom'])) ?>')">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                                <button type="button" class="btn btn-outline-danger btn-sm"
                                                        onclick="supprimerFournisseur(<?= $fournisseur['id'] ?>, '<?= e(addslashes($fournisseur['nom'])) ?>')">
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
        </div>
    </div>
</div>

<!-- Modal Modifier -->
<div class="modal fade" id="modifierModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Modifier le fournisseur</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <?php csrfField(); ?>
                <input type="hidden" name="action" value="modifier">
                <input type="hidden" name="fournisseur_id" id="modifierId">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Nom du fournisseur *</label>
                        <input type="text" class="form-control" name="nom" id="modifierNom" required>
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
<div class="modal fade" id="supprimerModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="bi bi-exclamation-triangle me-2"></i>Supprimer le fournisseur</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <?php csrfField(); ?>
                <input type="hidden" name="action" value="supprimer">
                <input type="hidden" name="fournisseur_id" id="supprimerId">
                <div class="modal-body">
                    <p>Êtes-vous sûr de vouloir supprimer le fournisseur <strong id="supprimerNom"></strong> ?</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="bi bi-trash me-1"></i>Supprimer
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function modifierFournisseur(id, nom) {
    document.getElementById('modifierId').value = id;
    document.getElementById('modifierNom').value = nom;
    new bootstrap.Modal(document.getElementById('modifierModal')).show();
}

function supprimerFournisseur(id, nom) {
    document.getElementById('supprimerId').value = id;
    document.getElementById('supprimerNom').textContent = nom;
    new bootstrap.Modal(document.getElementById('supprimerModal')).show();
}
</script>

<?php include '../../includes/footer.php'; ?>
