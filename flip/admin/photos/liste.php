<?php
/**
 * Liste des photos - Admin
 * Flip Manager
 */

require_once '../../config.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

requireAdmin();

$pageTitle = 'Photos des projets';

// Filtres
$filtreProjet = isset($_GET['projet']) ? (int)$_GET['projet'] : 0;
$filtreEmploye = isset($_GET['employe']) ? (int)$_GET['employe'] : 0;

// Récupérer les projets pour le filtre
$projets = getProjets($pdo, false);

// Récupérer les employés pour le filtre
$stmt = $pdo->query("SELECT id, CONCAT(prenom, ' ', nom) as nom_complet FROM users ORDER BY prenom, nom");
$employes = $stmt->fetchAll();

// Traitement de la suppression
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        setFlashMessage('danger', 'Token de sécurité invalide.');
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'delete') {
            $photoId = (int)($_POST['photo_id'] ?? 0);

            $stmt = $pdo->prepare("SELECT * FROM photos_projet WHERE id = ?");
            $stmt->execute([$photoId]);
            $photo = $stmt->fetch();

            if ($photo) {
                // Supprimer le fichier
                $filePath = __DIR__ . '/../../uploads/photos/' . $photo['fichier'];
                if (file_exists($filePath)) {
                    unlink($filePath);
                }

                // Supprimer de la base de données
                $stmt = $pdo->prepare("DELETE FROM photos_projet WHERE id = ?");
                $stmt->execute([$photoId]);

                setFlashMessage('success', 'Photo supprimée.');
            }
        } elseif ($action === 'delete_group') {
            $groupeId = $_POST['groupe_id'] ?? '';

            if ($groupeId) {
                // Récupérer toutes les photos du groupe
                $stmt = $pdo->prepare("SELECT * FROM photos_projet WHERE groupe_id = ?");
                $stmt->execute([$groupeId]);
                $photos = $stmt->fetchAll();

                foreach ($photos as $photo) {
                    $filePath = __DIR__ . '/../../uploads/photos/' . $photo['fichier'];
                    if (file_exists($filePath)) {
                        unlink($filePath);
                    }
                }

                // Supprimer de la base de données
                $stmt = $pdo->prepare("DELETE FROM photos_projet WHERE groupe_id = ?");
                $stmt->execute([$groupeId]);

                setFlashMessage('success', 'Groupe de photos supprimé.');
            }
        }
        redirect('/admin/photos/liste.php' . ($filtreProjet ? '?projet=' . $filtreProjet : ''));
    }
}

// Construire la requête pour les groupes de photos
$sql = "
    SELECT p.groupe_id, p.projet_id, pr.nom as projet_nom, pr.adresse as projet_adresse,
           CONCAT(u.prenom, ' ', u.nom) as employe_nom, u.id as employe_id,
           MIN(p.date_prise) as premiere_photo, MAX(p.date_prise) as derniere_photo,
           COUNT(*) as nb_photos, p.description
    FROM photos_projet p
    JOIN projets pr ON p.projet_id = pr.id
    JOIN users u ON p.user_id = u.id
    WHERE 1=1
";
$params = [];

if ($filtreProjet > 0) {
    $sql .= " AND p.projet_id = ?";
    $params[] = $filtreProjet;
}

if ($filtreEmploye > 0) {
    $sql .= " AND p.user_id = ?";
    $params[] = $filtreEmploye;
}

$sql .= " GROUP BY p.groupe_id, p.projet_id, pr.nom, pr.adresse, u.prenom, u.nom, u.id, p.description
          ORDER BY derniere_photo DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$groupesPhotos = $stmt->fetchAll();

// Calculer les totaux
$totalGroupes = count($groupesPhotos);
$totalPhotos = array_sum(array_column($groupesPhotos, 'nb_photos'));

include '../../includes/header.php';
?>

<div class="container-fluid">
    <div class="page-header">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= url('/admin/index.php') ?>">Tableau de bord</a></li>
                <li class="breadcrumb-item active">Photos des projets</li>
            </ol>
        </nav>
        <h1><i class="bi bi-camera me-2"></i>Photos des projets</h1>
    </div>

    <?php displayFlashMessage(); ?>

    <!-- Stats -->
    <div class="stats-grid mb-4">
        <div class="stat-card">
            <div class="stat-label">Groupes de photos</div>
            <div class="stat-value"><?= $totalGroupes ?></div>
        </div>
        <div class="stat-card primary">
            <div class="stat-label">Total photos</div>
            <div class="stat-value"><?= $totalPhotos ?></div>
        </div>
    </div>

    <!-- Filtres -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" action="" class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label class="form-label">Projet</label>
                    <select class="form-select" name="projet" onchange="this.form.submit()">
                        <option value="">Tous les projets</option>
                        <?php foreach ($projets as $p): ?>
                            <option value="<?= $p['id'] ?>" <?= $filtreProjet == $p['id'] ? 'selected' : '' ?>>
                                <?= e($p['nom']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Employé</label>
                    <select class="form-select" name="employe" onchange="this.form.submit()">
                        <option value="">Tous les employés</option>
                        <?php foreach ($employes as $emp): ?>
                            <option value="<?= $emp['id'] ?>" <?= $filtreEmploye == $emp['id'] ? 'selected' : '' ?>>
                                <?= e($emp['nom_complet']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <a href="<?= url('/admin/photos/liste.php') ?>" class="btn btn-outline-secondary">
                        <i class="bi bi-x-circle me-1"></i>Réinitialiser
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- Liste des groupes de photos -->
    <?php if (empty($groupesPhotos)): ?>
        <div class="card">
            <div class="card-body">
                <div class="empty-state py-5">
                    <i class="bi bi-camera"></i>
                    <h4>Aucune photo</h4>
                    <p>Aucune photo n'a été prise pour le moment.</p>
                </div>
            </div>
        </div>
    <?php else: ?>
        <?php foreach ($groupesPhotos as $groupe):
            // Récupérer les photos de ce groupe
            $stmt = $pdo->prepare("SELECT * FROM photos_projet WHERE groupe_id = ? ORDER BY date_prise ASC");
            $stmt->execute([$groupe['groupe_id']]);
            $photosGroupe = $stmt->fetchAll();
        ?>
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <div>
                        <strong><?= e($groupe['projet_nom']) ?></strong>
                        <span class="text-muted">- <?= e($groupe['projet_adresse']) ?></span>
                        <br>
                        <small class="text-muted">
                            Par <?= e($groupe['employe_nom']) ?> •
                            <?= formatDate($groupe['premiere_photo']) ?>
                            <?php if ($groupe['premiere_photo'] !== $groupe['derniere_photo']): ?>
                                - <?= formatDate($groupe['derniere_photo']) ?>
                            <?php endif; ?>
                        </small>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge bg-primary"><?= $groupe['nb_photos'] ?> photo(s)</span>
                        <form method="POST" action="" class="d-inline"
                              onsubmit="return confirm('Supprimer ce groupe de <?= $groupe['nb_photos'] ?> photo(s) ?');">
                            <?php csrfField(); ?>
                            <input type="hidden" name="action" value="delete_group">
                            <input type="hidden" name="groupe_id" value="<?= e($groupe['groupe_id']) ?>">
                            <button type="submit" class="btn btn-outline-danger btn-sm">
                                <i class="bi bi-trash"></i>
                            </button>
                        </form>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (!empty($groupe['description'])): ?>
                        <p class="text-muted mb-3">
                            <i class="bi bi-tag me-2"></i>
                            <?php
                            // Si c'est une clé de catégorie, la traduire
                            $desc = $groupe['description'];
                            echo e(strpos($desc, 'cat_') === 0 ? __($desc) : $desc);
                            ?>
                        </p>
                    <?php endif; ?>
                    <div class="row g-2">
                        <?php foreach ($photosGroupe as $photo):
                            $extension = strtolower(pathinfo($photo['fichier'], PATHINFO_EXTENSION));
                            $isVideo = in_array($extension, ['mp4', 'mov', 'avi', 'mkv', 'webm', 'm4v']);
                        ?>
                            <div class="col-6 col-md-3 col-lg-2">
                                <div class="position-relative">
                                    <a href="<?= url('/uploads/photos/' . e($photo['fichier'])) ?>"
                                       target="_blank"
                                       class="d-block">
                                        <?php if ($isVideo): ?>
                                            <div class="video-thumbnail rounded" style="width:100%;height:120px;background:#1a1d21;display:flex;align-items:center;justify-content:center;position:relative;">
                                                <video src="<?= url('/uploads/photos/' . e($photo['fichier'])) ?>"
                                                       style="width:100%;height:100%;object-fit:cover;position:absolute;top:0;left:0;"
                                                       muted preload="metadata"></video>
                                                <div style="position:absolute;z-index:2;background:rgba(0,0,0,0.6);border-radius:50%;width:50px;height:50px;display:flex;align-items:center;justify-content:center;">
                                                    <i class="bi bi-play-fill text-white" style="font-size:2rem;margin-left:4px;"></i>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <img src="<?= url('/uploads/photos/' . e($photo['fichier'])) ?>"
                                                 alt="Photo"
                                                 class="img-fluid rounded"
                                                 style="width:100%;height:120px;object-fit:cover;">
                                        <?php endif; ?>
                                    </a>
                                    <form method="POST" action="" class="position-absolute top-0 end-0 m-1">
                                        <?php csrfField(); ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="photo_id" value="<?= $photo['id'] ?>">
                                        <button type="submit" class="btn btn-danger btn-sm"
                                                onclick="return confirm('Supprimer cette photo ?')">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                    <div class="position-absolute bottom-0 start-0 end-0 bg-dark bg-opacity-50 text-white p-1 rounded-bottom">
                                        <small>
                                            <?php if ($isVideo): ?><i class="bi bi-camera-video me-1"></i><?php endif; ?>
                                            <?= date('H:i', strtotime($photo['date_prise'])) ?>
                                        </small>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php include '../../includes/footer.php'; ?>
