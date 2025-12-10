<?php
/**
 * Gestion des types de coûts récurrents - Admin
 * Flip Manager
 */

require_once '../../config.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

requireAdmin();

$pageTitle = 'Coûts récurrents';

// Auto-migration: créer la table si elle n'existe pas
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS recurrents_types (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nom VARCHAR(100) NOT NULL,
            code VARCHAR(50) NOT NULL UNIQUE,
            frequence ENUM('annuel', 'mensuel', 'saisonnier') DEFAULT 'annuel',
            ordre INT DEFAULT 0,
            actif TINYINT(1) DEFAULT 1,
            est_systeme TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");

    // Ajouter 'saisonnier' à l'ENUM si la table existe déjà
    try {
        $pdo->exec("ALTER TABLE recurrents_types MODIFY frequence ENUM('annuel', 'mensuel', 'saisonnier') DEFAULT 'annuel'");
    } catch (Exception $e) {
        // Déjà modifié ou erreur
    }

    // Insérer les types par défaut s'ils n'existent pas
    $stmt = $pdo->query("SELECT COUNT(*) FROM recurrents_types");
    if ($stmt->fetchColumn() == 0) {
        $defaults = [
            ['Taxes municipales', 'taxes_municipales', 'annuel', 1, 1],
            ['Taxes scolaires', 'taxes_scolaires', 'annuel', 2, 1],
            ['Électricité', 'electricite', 'annuel', 3, 1],
            ['Assurances', 'assurances', 'annuel', 4, 1],
            ['Déneigement', 'deneigement', 'saisonnier', 5, 1],
            ['Frais condo', 'frais_condo', 'annuel', 6, 1],
            ['Hypothèque', 'hypotheque', 'mensuel', 7, 1],
            ['Loyer reçu', 'loyer', 'mensuel', 8, 1],
        ];
        $stmt = $pdo->prepare("INSERT INTO recurrents_types (nom, code, frequence, ordre, est_systeme) VALUES (?, ?, ?, ?, ?)");
        foreach ($defaults as $d) {
            $stmt->execute($d);
        }
    }
} catch (Exception $e) {
    // Table existe déjà ou autre erreur
}

$errors = [];

// Traitement des actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token de sécurité invalide.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'creer') {
            $nom = trim($_POST['nom'] ?? '');
            $code = trim($_POST['code'] ?? '');
            $frequence = $_POST['frequence'] ?? 'annuel';

            if (empty($nom)) $errors[] = 'Le nom est requis.';
            if (empty($code)) {
                // Générer le code à partir du nom
                $code = strtolower(preg_replace('/[^a-zA-Z0-9]/', '_', $nom));
            }

            if (empty($errors)) {
                try {
                    $stmt = $pdo->prepare("INSERT INTO recurrents_types (nom, code, frequence, ordre, est_systeme) VALUES (?, ?, ?, (SELECT COALESCE(MAX(ordre), 0) + 1 FROM recurrents_types rt), 1)");
                    $stmt->execute([$nom, $code, $frequence]);
                    setFlashMessage('success', 'Type de coût récurrent créé.');
                    redirect('/admin/recurrents/liste.php');
                } catch (PDOException $e) {
                    if (strpos($e->getMessage(), 'Duplicate') !== false) {
                        $errors[] = 'Ce code existe déjà.';
                    } else {
                        $errors[] = 'Erreur: ' . $e->getMessage();
                    }
                }
            }
        } elseif ($action === 'modifier') {
            $id = (int)($_POST['id'] ?? 0);
            $nom = trim($_POST['nom'] ?? '');
            $frequence = $_POST['frequence'] ?? 'annuel';
            $actif = isset($_POST['actif']) ? 1 : 0;

            if (empty($nom)) $errors[] = 'Le nom est requis.';

            if (empty($errors)) {
                $stmt = $pdo->prepare("UPDATE recurrents_types SET nom = ?, frequence = ?, actif = ? WHERE id = ?");
                $stmt->execute([$nom, $frequence, $actif, $id]);
                setFlashMessage('success', 'Type modifié.');
                redirect('/admin/recurrents/liste.php');
            }
        } elseif ($action === 'supprimer') {
            $id = (int)($_POST['id'] ?? 0);

            // Supprimer aussi les valeurs liées dans projet_recurrents
            try {
                $pdo->prepare("DELETE FROM projet_recurrents WHERE recurrent_type_id = ?")->execute([$id]);
            } catch (Exception $e) {
                // Table n'existe pas encore
            }

            $stmt = $pdo->prepare("DELETE FROM recurrents_types WHERE id = ?");
            $stmt->execute([$id]);
            setFlashMessage('success', 'Type supprimé.');
            redirect('/admin/recurrents/liste.php');
        }
    }
}

// Récupérer les types
$stmt = $pdo->query("SELECT * FROM recurrents_types ORDER BY ordre, nom");
$types = $stmt->fetchAll();

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
            <h1><i class="bi bi-arrow-repeat me-2"></i>Coûts récurrents</h1>
        </div>
        <div class="d-flex gap-2 mt-2 mt-md-0">
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalCreer">
                <i class="bi bi-plus-circle me-1"></i>Nouveau type
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
            <a class="nav-link active" href="<?= url('/admin/recurrents/liste.php') ?>">
                <i class="bi bi-arrow-repeat me-1"></i>Récurrents
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="<?= url('/admin/checklists/liste.php') ?>">
                <i class="bi bi-list-check me-1"></i>Checklists
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="<?= url('/admin/rapports/index.php') ?>">
                <i class="bi bi-file-earmark-bar-graph me-1"></i>Rapports
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="<?= url('/admin/configuration/index.php') ?>">
                <i class="bi bi-gear me-1"></i>Configuration
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
        <div class="card-header">
            <i class="bi bi-info-circle me-1"></i>
            Ces types de coûts récurrents apparaissent dans l'onglet Base de chaque projet.
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Nom</th>
                            <th>Code</th>
                            <th>Fréquence</th>
                            <th>Statut</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($types as $type): ?>
                            <tr>
                                <td>
                                    <strong><?= e($type['nom']) ?></strong>
                                </td>
                                <td><code><?= e($type['code']) ?></code></td>
                                <td>
                                    <?php
                                    $freqBadge = match($type['frequence']) {
                                        'mensuel' => ['bg-info', 'Mensuel'],
                                        'saisonnier' => ['bg-success', 'Saisonnier'],
                                        default => ['bg-warning', 'Annuel']
                                    };
                                    ?>
                                    <span class="badge <?= $freqBadge[0] ?>"><?= $freqBadge[1] ?></span>
                                </td>
                                <td>
                                    <span class="badge <?= $type['actif'] ? 'bg-success' : 'bg-danger' ?>">
                                        <?= $type['actif'] ? 'Actif' : 'Inactif' ?>
                                    </span>
                                </td>
                                <td class="action-buttons">
                                    <button type="button"
                                            class="btn btn-outline-primary btn-sm"
                                            data-bs-toggle="modal"
                                            data-bs-target="#modalModifier<?= $type['id'] ?>">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <form method="POST" action="" class="d-inline"
                                          onsubmit="return confirm('Supprimer ce type ?');">
                                        <?php csrfField(); ?>
                                        <input type="hidden" name="action" value="supprimer">
                                        <input type="hidden" name="id" value="<?= $type['id'] ?>">
                                        <button type="submit" class="btn btn-outline-danger btn-sm">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Modals Modifier -->
<?php foreach ($types as $type): ?>
<div class="modal fade" id="modalModifier<?= $type['id'] ?>" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="">
                <?php csrfField(); ?>
                <input type="hidden" name="action" value="modifier">
                <input type="hidden" name="id" value="<?= $type['id'] ?>">

                <div class="modal-header">
                    <h5 class="modal-title">Modifier le type</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Nom *</label>
                        <input type="text" class="form-control" name="nom" value="<?= e($type['nom']) ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Code</label>
                        <input type="text" class="form-control" value="<?= e($type['code']) ?>" disabled>
                        <small class="text-muted">Le code ne peut pas être modifié</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Fréquence</label>
                        <select class="form-select" name="frequence">
                            <option value="annuel" <?= $type['frequence'] === 'annuel' ? 'selected' : '' ?>>Annuel (/an)</option>
                            <option value="mensuel" <?= $type['frequence'] === 'mensuel' ? 'selected' : '' ?>>Mensuel (/mois)</option>
                            <option value="saisonnier" <?= $type['frequence'] === 'saisonnier' ? 'selected' : '' ?>>Saisonnier (fixe)</option>
                        </select>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="actif" id="actif<?= $type['id'] ?>" <?= $type['actif'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="actif<?= $type['id'] ?>">Actif</label>
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

<!-- Modal Créer -->
<div class="modal fade" id="modalCreer" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="">
                <?php csrfField(); ?>
                <input type="hidden" name="action" value="creer">

                <div class="modal-header">
                    <h5 class="modal-title">Nouveau type de coût récurrent</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Nom *</label>
                        <input type="text" class="form-control" name="nom" required placeholder="Ex: Gazon, Piscine...">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Code (optionnel)</label>
                        <input type="text" class="form-control" name="code" placeholder="Généré automatiquement si vide">
                        <small class="text-muted">Utilisé dans le code, sans espaces ni accents</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Fréquence</label>
                        <select class="form-select" name="frequence">
                            <option value="annuel">Annuel (/an)</option>
                            <option value="mensuel">Mensuel (/mois)</option>
                            <option value="saisonnier">Saisonnier (fixe)</option>
                        </select>
                        <small class="text-muted">Saisonnier = montant fixe peu importe la durée du projet</small>
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
