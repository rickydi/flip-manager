<?php
/**
 * Photos de projet - Employé
 * Flip Manager
 */

require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

requireLogin();

$pageTitle = __('project_photos');
$userId = getCurrentUserId();

// Récupérer les projets actifs
$projets = getProjets($pdo, true);

// Générer un ID de groupe unique pour cette session
$groupeId = isset($_GET['groupe']) ? $_GET['groupe'] : null;

$errors = [];
$success = false;

// Traitement de l'upload
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token de sécurité invalide.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'upload') {
            $projetId = (int)($_POST['projet_id'] ?? 0);
            $groupeId = $_POST['groupe_id'] ?? '';
            $description = trim($_POST['description'] ?? '');

            if ($projetId <= 0) {
                $errors[] = __('select_project_error');
            }

            if (empty($groupeId)) {
                $groupeId = uniqid('grp_', true);
            }

            // Fonction pour traiter un array de fichiers
            $processFiles = function($files) use ($projetId, $userId, $groupeId, $description, $pdo) {
                $count = 0;
                if (!isset($files['name']) || empty($files['name'][0])) {
                    return 0;
                }

                $totalFiles = count($files['name']);
                for ($i = 0; $i < $totalFiles; $i++) {
                    if ($files['error'][$i] === UPLOAD_ERR_OK) {
                        $tmpName = $files['tmp_name'][$i];
                        $originalName = $files['name'][$i];
                        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

                        if (!in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'heic', 'webp'])) {
                            continue;
                        }

                        if ($files['size'][$i] > 10 * 1024 * 1024) {
                            continue;
                        }

                        $newFilename = 'photo_' . date('Ymd_His') . '_' . uniqid() . '.' . $extension;
                        $destination = __DIR__ . '/../uploads/photos/' . $newFilename;

                        if (move_uploaded_file($tmpName, $destination)) {
                            $stmt = $pdo->prepare("
                                INSERT INTO photos_projet (projet_id, user_id, groupe_id, fichier, date_prise, description)
                                VALUES (?, ?, ?, ?, NOW(), ?)
                            ");
                            $stmt->execute([$projetId, $userId, $groupeId, $newFilename, $description]);
                            $count++;
                        }
                    }
                }
                return $count;
            };

            // Traiter les fichiers des deux inputs
            $uploadedCount = 0;
            if (isset($_FILES['photos'])) {
                $uploadedCount += $processFiles($_FILES['photos']);
            }
            if (isset($_FILES['camera_photos'])) {
                $uploadedCount += $processFiles($_FILES['camera_photos']);
            }

            if ($uploadedCount > 0) {
                setFlashMessage('success', $uploadedCount . ' ' . __('photos_uploaded'));
                redirect('/employe/photos.php?groupe=' . $groupeId . '&projet=' . $projetId);
            } else {
                $errors[] = __('no_photos_uploaded');
            }
        } elseif ($action === 'delete') {
            $photoId = (int)($_POST['photo_id'] ?? 0);

            // Vérifier que la photo appartient à l'utilisateur
            $stmt = $pdo->prepare("SELECT * FROM photos_projet WHERE id = ? AND user_id = ?");
            $stmt->execute([$photoId, $userId]);
            $photo = $stmt->fetch();

            if ($photo) {
                // Supprimer le fichier
                $filePath = __DIR__ . '/../uploads/photos/' . $photo['fichier'];
                if (file_exists($filePath)) {
                    unlink($filePath);
                }

                // Supprimer de la base de données
                $stmt = $pdo->prepare("DELETE FROM photos_projet WHERE id = ?");
                $stmt->execute([$photoId]);

                setFlashMessage('success', __('photo_deleted'));
            }
            redirect('/employe/photos.php');
        }
    }
}

// Récupérer les photos du groupe actuel si on est en mode "continuer"
$photosGroupe = [];
$projetIdSelected = isset($_GET['projet']) ? (int)$_GET['projet'] : 0;

if ($groupeId) {
    $stmt = $pdo->prepare("
        SELECT p.*, pr.nom as projet_nom, pr.adresse as projet_adresse
        FROM photos_projet p
        JOIN projets pr ON p.projet_id = pr.id
        WHERE p.groupe_id = ? AND p.user_id = ?
        ORDER BY p.date_prise ASC
    ");
    $stmt->execute([$groupeId, $userId]);
    $photosGroupe = $stmt->fetchAll();

    if (!empty($photosGroupe)) {
        $projetIdSelected = $photosGroupe[0]['projet_id'];
    }
}

// Récupérer les derniers groupes de photos de l'utilisateur
$stmt = $pdo->prepare("
    SELECT p.groupe_id, p.projet_id, pr.nom as projet_nom, pr.adresse as projet_adresse,
           MIN(p.date_prise) as premiere_photo, MAX(p.date_prise) as derniere_photo,
           COUNT(*) as nb_photos
    FROM photos_projet p
    JOIN projets pr ON p.projet_id = pr.id
    WHERE p.user_id = ?
    GROUP BY p.groupe_id, p.projet_id, pr.nom, pr.adresse
    ORDER BY derniere_photo DESC
    LIMIT 20
");
$stmt->execute([$userId]);
$groupesPhotos = $stmt->fetchAll();

include '../includes/header.php';
?>

<div class="container-fluid">
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h1><i class="bi bi-camera me-2"></i><?= __('project_photos') ?></h1>
                <p class="text-muted mb-0"><?= __('take_project_photos') ?></p>
            </div>
            <div><?= renderLanguageToggle() ?></div>
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

    <div class="row">
        <!-- Formulaire d'upload -->
        <div class="col-lg-5">
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-camera-fill me-2"></i><?= __('add_photos') ?>
                </div>
                <div class="card-body">
                    <form method="POST" action="" enctype="multipart/form-data" id="photoForm">
                        <?php csrfField(); ?>
                        <input type="hidden" name="action" value="upload">
                        <input type="hidden" name="groupe_id" value="<?= e($groupeId ?: uniqid('grp_', true)) ?>">

                        <div class="mb-3">
                            <label class="form-label"><?= __('project') ?> *</label>
                            <select class="form-select" name="projet_id" required <?= !empty($photosGroupe) ? 'disabled' : '' ?>>
                                <option value=""><?= __('select') ?></option>
                                <?php foreach ($projets as $projet): ?>
                                    <option value="<?= $projet['id'] ?>" <?= $projetIdSelected == $projet['id'] ? 'selected' : '' ?>>
                                        <?= e($projet['nom']) ?> - <?= e($projet['adresse']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (!empty($photosGroupe)): ?>
                                <input type="hidden" name="projet_id" value="<?= $projetIdSelected ?>">
                            <?php endif; ?>
                        </div>

                        <div class="mb-3">
                            <label class="form-label"><?= __('description') ?></label>
                            <textarea class="form-control" name="description" rows="2"
                                      placeholder="<?= __('photo_description_placeholder') ?>"></textarea>
                        </div>

                        <!-- Zone de capture/upload -->
                        <div class="mb-3">
                            <label class="form-label"><?= __('photos') ?> *</label>

                            <!-- Input fichier unique -->
                            <input type="file" id="photosInput" name="photos[]"
                                   accept="image/*" multiple required
                                   class="form-control form-control-lg mb-3"
                                   onchange="previewPhotos(this)">

                            <!-- Bouton caméra pour mobile -->
                            <input type="file" id="cameraInput" name="camera_photos[]"
                                   accept="image/*" capture="environment"
                                   class="form-control mb-2"
                                   onchange="previewPhotos(this)">
                            <small class="text-muted d-block mb-3"><?= __('take_photo') ?></small>
                        </div>

                        <!-- Prévisualisation des photos -->
                        <div id="photoPreview" class="row g-2 mb-3" style="display: none;">
                        </div>

                        <div id="photoCount" class="alert alert-info mb-3" style="display: none;">
                            <i class="bi bi-images me-2"></i>
                            <span id="photoCountText"></span>
                        </div>

                        <button type="submit" class="btn btn-success w-100" id="submitBtn">
                            <i class="bi bi-cloud-upload me-2"></i><?= __('upload_photos') ?>
                        </button>
                    </form>

                    <?php if ($groupeId && !empty($photosGroupe)): ?>
                        <hr>
                        <a href="<?= url('/employe/photos.php') ?>" class="btn btn-outline-secondary w-100">
                            <i class="bi bi-plus-circle me-2"></i><?= __('new_photo_group') ?>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Photos du groupe actuel -->
        <div class="col-lg-7">
            <?php if (!empty($photosGroupe)): ?>
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span>
                            <i class="bi bi-collection me-2"></i>
                            <?= __('current_group') ?> - <?= e($photosGroupe[0]['projet_nom']) ?>
                        </span>
                        <span class="badge bg-primary"><?= count($photosGroupe) ?> photo(s)</span>
                    </div>
                    <div class="card-body">
                        <div class="row g-2">
                            <?php foreach ($photosGroupe as $photo): ?>
                                <div class="col-4 col-md-3">
                                    <div class="position-relative">
                                        <a href="/uploads/photos/<?= e($photo['fichier']) ?>" target="_blank">
                                            <img src="/uploads/photos/<?= e($photo['fichier']) ?>"
                                                 alt="Photo"
                                                 class="img-fluid rounded"
                                                 style="width:100%;height:100px;object-fit:cover;">
                                        </a>
                                        <form method="POST" action="" class="position-absolute top-0 end-0 m-1">
                                            <?php csrfField(); ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="photo_id" value="<?= $photo['id'] ?>">
                                            <button type="submit" class="btn btn-danger btn-sm"
                                                    onclick="return confirm('<?= __('delete_photo_confirm') ?>')">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                    <small class="text-muted"><?= formatDateTime($photo['date_prise']) ?></small>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Historique des groupes de photos -->
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-clock-history me-2"></i><?= __('my_photo_groups') ?>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($groupesPhotos)): ?>
                        <div class="empty-state py-4">
                            <i class="bi bi-camera"></i>
                            <h5><?= __('no_photos_yet') ?></h5>
                            <p class="text-muted"><?= __('start_taking_photos') ?></p>
                        </div>
                    <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($groupesPhotos as $groupe): ?>
                                <a href="<?= url('/employe/photos.php?groupe=' . e($groupe['groupe_id']) . '&projet=' . $groupe['projet_id']) ?>"
                                   class="list-group-item list-group-item-action <?= $groupeId === $groupe['groupe_id'] ? 'active' : '' ?>">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <strong><?= e($groupe['projet_nom']) ?></strong>
                                            <br>
                                            <small class="<?= $groupeId === $groupe['groupe_id'] ? '' : 'text-muted' ?>">
                                                <?= e($groupe['projet_adresse']) ?>
                                            </small>
                                        </div>
                                        <div class="text-end">
                                            <span class="badge <?= $groupeId === $groupe['groupe_id'] ? 'bg-light text-dark' : 'bg-primary' ?>">
                                                <?= $groupe['nb_photos'] ?> photo(s)
                                            </span>
                                            <br>
                                            <small class="<?= $groupeId === $groupe['groupe_id'] ? '' : 'text-muted' ?>">
                                                <?= formatDate($groupe['premiere_photo']) ?>
                                            </small>
                                        </div>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Simple prévisualisation des photos sélectionnées
function previewPhotos(input) {
    const preview = document.getElementById('photoPreview');
    const photoCount = document.getElementById('photoCount');
    const photoCountText = document.getElementById('photoCountText');

    if (input.files && input.files.length > 0) {
        preview.innerHTML = '';
        preview.style.display = 'flex';

        for (let file of input.files) {
            const reader = new FileReader();
            reader.onload = function(e) {
                const col = document.createElement('div');
                col.className = 'col-4';
                col.innerHTML = `
                    <img src="${e.target.result}" class="img-fluid rounded"
                         style="width:100%;height:80px;object-fit:cover;">
                `;
                preview.appendChild(col);
            };
            reader.readAsDataURL(file);
        }

        photoCount.style.display = 'block';
        photoCountText.textContent = input.files.length + ' photo(s) sélectionnée(s)';
    }
}

// Désactiver le bouton pendant la soumission
document.getElementById('photoForm').addEventListener('submit', function() {
    const btn = document.getElementById('submitBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Téléversement...';
});
</script>

<?php include '../includes/footer.php'; ?>
