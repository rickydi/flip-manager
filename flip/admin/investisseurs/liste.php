<?php
/**
 * Gestion des investisseurs et prêteurs - Admin
 * Flip Manager
 */

require_once '../../config.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

requireAdmin();

$pageTitle = 'Investisseurs & Prêteurs';
$errors = [];

// Traitement des actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token de sécurité invalide.';
    } else {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'ajouter') {
            $nom = trim($_POST['nom'] ?? '');
            $type = $_POST['type'] ?? 'investisseur';
            $email = trim($_POST['email'] ?? '');
            $telephone = trim($_POST['telephone'] ?? '');
            $tauxInteret = parseNumber($_POST['taux_interet_defaut'] ?? 0);
            $fraisDossier = parseNumber($_POST['frais_dossier_defaut'] ?? 0);
            $notes = trim($_POST['notes'] ?? '');
            
            if (empty($nom)) {
                $errors[] = 'Le nom est requis.';
            } else {
                $stmt = $pdo->prepare("INSERT INTO investisseurs (nom, type, email, telephone, taux_interet_defaut, frais_dossier_defaut, notes) VALUES (?, ?, ?, ?, ?, ?, ?)");
                if ($stmt->execute([$nom, $type, $email, $telephone, $tauxInteret, $fraisDossier, $notes])) {
                    setFlashMessage('success', ($type === 'preteur' ? 'Prêteur' : 'Investisseur') . ' ajouté avec succès!');
                    redirect('/admin/investisseurs/liste.php');
                } else {
                    $errors[] = 'Erreur lors de l\'ajout.';
                }
            }
        } elseif ($action === 'modifier') {
            $id = (int)($_POST['id'] ?? 0);
            $nom = trim($_POST['nom'] ?? '');
            $type = $_POST['type'] ?? 'investisseur';
            $email = trim($_POST['email'] ?? '');
            $telephone = trim($_POST['telephone'] ?? '');
            $tauxInteret = parseNumber($_POST['taux_interet_defaut'] ?? 0);
            $fraisDossier = parseNumber($_POST['frais_dossier_defaut'] ?? 0);
            $notes = trim($_POST['notes'] ?? '');
            
            if ($id && !empty($nom)) {
                $stmt = $pdo->prepare("UPDATE investisseurs SET nom = ?, type = ?, email = ?, telephone = ?, taux_interet_defaut = ?, frais_dossier_defaut = ?, notes = ? WHERE id = ?");
                if ($stmt->execute([$nom, $type, $email, $telephone, $tauxInteret, $fraisDossier, $notes, $id])) {
                    setFlashMessage('success', 'Modifié avec succès!');
                    redirect('/admin/investisseurs/liste.php');
                }
            }
        } elseif ($action === 'supprimer') {
            $id = (int)($_POST['id'] ?? 0);
            
            if ($id) {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM projet_investisseurs WHERE investisseur_id = ?");
                $stmt->execute([$id]);
                $count = $stmt->fetchColumn();
                
                if ($count > 0) {
                    $errors[] = 'Associé à ' . $count . ' projet(s). Impossible de supprimer.';
                } else {
                    $stmt = $pdo->prepare("DELETE FROM investisseurs WHERE id = ?");
                    if ($stmt->execute([$id])) {
                        setFlashMessage('success', 'Supprimé avec succès!');
                        redirect('/admin/investisseurs/liste.php');
                    }
                }
            }
        }
    }
}

// Récupérer les investisseurs et prêteurs
try {
    $stmt = $pdo->query("
        SELECT i.*, 
               (SELECT COUNT(*) FROM projet_investisseurs WHERE investisseur_id = i.id) as nb_projets,
               (SELECT COALESCE(SUM(COALESCE(montant, mise_de_fonds)), 0) FROM projet_investisseurs WHERE investisseur_id = i.id) as total_investi
        FROM investisseurs i 
        ORDER BY i.nom
    ");
    $all = $stmt->fetchAll();
} catch (Exception $e) {
    // Si la colonne type n'existe pas encore
    $stmt = $pdo->query("SELECT * FROM investisseurs ORDER BY nom");
    $all = $stmt->fetchAll();
}

// Séparer par type (si la colonne existe)
$investisseurs = array_filter($all, fn($i) => ($i['type'] ?? 'investisseur') === 'investisseur');
$preteurs = array_filter($all, fn($i) => ($i['type'] ?? '') === 'preteur');

include '../../includes/header.php';
?>

<div class="container-fluid">
    <div class="page-header">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= url('/admin/index.php') ?>">Tableau de bord</a></li>
                <li class="breadcrumb-item active">Investisseurs & Prêteurs</li>
            </ol>
        </nav>
        <div class="d-flex justify-content-between align-items-center">
            <h1><i class="bi bi-people me-2"></i>Investisseurs & Prêteurs</h1>
            <div>
                <button type="button" class="btn btn-success me-2" data-bs-toggle="modal" data-bs-target="#addInvestisseurModal">
                    <i class="bi bi-plus-circle me-1"></i>Investisseur
                </button>
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addPreteurModal">
                    <i class="bi bi-plus-circle me-1"></i>Prêteur
                </button>
            </div>
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
    
    <!-- Prêteurs -->
    <div class="card mb-4">
        <div class="card-header bg-primary text-white">
            <i class="bi bi-bank me-2"></i>Prêteurs (avec intérêts)
            <span class="badge bg-light text-primary ms-2"><?= count($preteurs) ?></span>
        </div>
        <?php if (empty($preteurs)): ?>
            <div class="card-body">
                <p class="text-muted mb-0">Aucun prêteur. Ajoutez vos prêteurs privés ou institutions.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Nom</th>
                            <th>Contact</th>
                            <th class="text-center">Taux intérêt</th>
                            <th class="text-center">Frais dossier</th>
                            <th class="text-center">Projets</th>
                            <th class="text-end">Total prêté</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($preteurs as $p): ?>
                            <tr>
                                <td><strong><?= e($p['nom']) ?></strong></td>
                                <td>
                                    <?php if ($p['email']): ?><small><?= e($p['email']) ?></small><br><?php endif; ?>
                                    <?php if ($p['telephone']): ?><small class="text-muted"><?= e($p['telephone']) ?></small><?php endif; ?>
                                </td>
                                <td class="text-center"><?= $p['taux_interet_defaut'] ?>%</td>
                                <td class="text-center"><?= $p['frais_dossier_defaut'] ?>%</td>
                                <td class="text-center"><span class="badge bg-secondary"><?= (int)$p['nb_projets'] ?></span></td>
                                <td class="text-end"><?= formatMoney($p['total_investi'] ?? 0) ?></td>
                                <td class="text-end">
                                    <button class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#editModal<?= $p['id'] ?>">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <button class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#deleteModal<?= $p['id'] ?>">
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
    
    <!-- Investisseurs -->
    <div class="card">
        <div class="card-header bg-success text-white">
            <i class="bi bi-person-badge me-2"></i>Investisseurs (partage des profits)
            <span class="badge bg-light text-success ms-2"><?= count($investisseurs) ?></span>
        </div>
        <?php if (empty($investisseurs)): ?>
            <div class="card-body">
                <p class="text-muted mb-0">Aucun investisseur. Ajoutez vos partenaires d'investissement.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Nom</th>
                            <th>Contact</th>
                            <th class="text-center">Projets</th>
                            <th class="text-end">Total investi</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($investisseurs as $inv): ?>
                            <tr>
                                <td><strong><?= e($inv['nom']) ?></strong></td>
                                <td>
                                    <?php if ($inv['email']): ?><small><?= e($inv['email']) ?></small><br><?php endif; ?>
                                    <?php if ($inv['telephone']): ?><small class="text-muted"><?= e($inv['telephone']) ?></small><?php endif; ?>
                                </td>
                                <td class="text-center"><span class="badge bg-secondary"><?= (int)$inv['nb_projets'] ?></span></td>
                                <td class="text-end"><?= formatMoney($inv['total_investi'] ?? 0) ?></td>
                                <td class="text-end">
                                    <button class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#editModal<?= $inv['id'] ?>">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <button class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#deleteModal<?= $inv['id'] ?>">
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

<!-- Modal Ajouter Investisseur -->
<div class="modal fade" id="addInvestisseurModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <?php csrfField(); ?>
                <input type="hidden" name="action" value="ajouter">
                <input type="hidden" name="type" value="investisseur">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>Nouvel investisseur</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Nom *</label>
                        <input type="text" class="form-control" name="nom" required>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" name="email">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Téléphone</label>
                            <input type="tel" class="form-control" name="telephone">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea class="form-control" name="notes" rows="2"></textarea>
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

<!-- Modal Ajouter Prêteur -->
<div class="modal fade" id="addPreteurModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <?php csrfField(); ?>
                <input type="hidden" name="action" value="ajouter">
                <input type="hidden" name="type" value="preteur">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>Nouveau prêteur</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Nom *</label>
                        <input type="text" class="form-control" name="nom" required placeholder="Ex: Prêteur privé Jean, Banque XYZ">
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" name="email">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Téléphone</label>
                            <input type="tel" class="form-control" name="telephone">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Taux d'intérêt par défaut</label>
                            <div class="input-group">
                                <input type="text" class="form-control money-input" name="taux_interet_defaut" placeholder="10">
                                <span class="input-group-text">%</span>
                            </div>
                            <small class="text-muted">Taux annuel</small>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Frais de dossier</label>
                            <div class="input-group">
                                <input type="text" class="form-control money-input" name="frais_dossier_defaut" placeholder="3">
                                <span class="input-group-text">%</span>
                            </div>
                            <small class="text-muted">À la mise en place</small>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea class="form-control" name="notes" rows="2"></textarea>
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

<!-- Modals Modifier et Supprimer -->
<?php foreach ($all as $item): ?>
<div class="modal fade" id="editModal<?= $item['id'] ?>" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <?php csrfField(); ?>
                <input type="hidden" name="action" value="modifier">
                <input type="hidden" name="id" value="<?= $item['id'] ?>">
                <input type="hidden" name="type" value="<?= $item['type'] ?>">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Modifier</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Nom *</label>
                        <input type="text" class="form-control" name="nom" value="<?= e($item['nom']) ?>" required>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" name="email" value="<?= e($item['email']) ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Téléphone</label>
                            <input type="tel" class="form-control" name="telephone" value="<?= e($item['telephone']) ?>">
                        </div>
                    </div>
                    <?php if ($item['type'] === 'preteur'): ?>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Taux d'intérêt</label>
                            <div class="input-group">
                                <input type="text" class="form-control money-input" name="taux_interet_defaut" value="<?= $item['taux_interet_defaut'] ?>">
                                <span class="input-group-text">%</span>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Frais de dossier</label>
                            <div class="input-group">
                                <input type="text" class="form-control money-input" name="frais_dossier_defaut" value="<?= $item['frais_dossier_defaut'] ?>">
                                <span class="input-group-text">%</span>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea class="form-control" name="notes" rows="2"><?= e($item['notes']) ?></textarea>
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

<div class="modal fade" id="deleteModal<?= $item['id'] ?>" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">Supprimer</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Supprimer <strong><?= e($item['nom']) ?></strong> ?</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                <form method="POST" class="d-inline">
                    <?php csrfField(); ?>
                    <input type="hidden" name="action" value="supprimer">
                    <input type="hidden" name="id" value="<?= $item['id'] ?>">
                    <button type="submit" class="btn btn-danger">Supprimer</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>

<?php include '../../includes/footer.php'; ?>
