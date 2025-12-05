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
    // Vérifier si le fichier est trop volumineux (PHP vide $_POST quand la limite est dépassée)
    $maxPostSize = ini_get('post_max_size');
    $contentLength = isset($_SERVER['CONTENT_LENGTH']) ? (int)$_SERVER['CONTENT_LENGTH'] : 0;

    if (empty($_POST) && $contentLength > 0) {
        $errors[] = __('file_too_large') . ' (max: ' . $maxPostSize . ')';
    } elseif (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
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

            // Traiter les fichiers uploadés
            $uploadedCount = 0;
            $debugInfo = [];

            if (isset($_FILES['photos']) && !empty($_FILES['photos']['name'][0])) {
                $totalFiles = count($_FILES['photos']['name']);

                for ($i = 0; $i < $totalFiles; $i++) {
                    $errorCode = $_FILES['photos']['error'][$i];
                    $originalName = $_FILES['photos']['name'][$i];

                    if ($errorCode === UPLOAD_ERR_OK) {
                        $tmpName = $_FILES['photos']['tmp_name'][$i];
                        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

                        // Vérifier l'extension (photos et vidéos)
                        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'heic', 'webp', 'mp4', 'mov', 'avi', 'mkv', 'webm', 'm4v'];
                        if (!in_array($extension, $allowedExtensions)) {
                            $debugInfo[] = "Extension non supportée: $extension ($originalName)";
                            continue;
                        }

                        // Générer un nom unique
                        $newFilename = 'photo_' . date('Ymd_His') . '_' . uniqid() . '.' . $extension;
                        $destination = __DIR__ . '/../uploads/photos/' . $newFilename;

                        if (move_uploaded_file($tmpName, $destination)) {
                            // Insérer dans la base de données
                            $stmt = $pdo->prepare("
                                INSERT INTO photos_projet (projet_id, user_id, groupe_id, fichier, date_prise, description)
                                VALUES (?, ?, ?, ?, NOW(), ?)
                            ");
                            $stmt->execute([$projetId, $userId, $groupeId, $newFilename, $description]);
                            $uploadedCount++;
                        } else {
                            $debugInfo[] = "Échec move_uploaded_file pour: $originalName";
                        }
                    } else {
                        // Décoder l'erreur d'upload
                        $errorMessages = [
                            UPLOAD_ERR_INI_SIZE => 'Fichier trop gros (limite PHP)',
                            UPLOAD_ERR_FORM_SIZE => 'Fichier trop gros (limite formulaire)',
                            UPLOAD_ERR_PARTIAL => 'Fichier partiellement uploadé',
                            UPLOAD_ERR_NO_FILE => 'Aucun fichier',
                            UPLOAD_ERR_NO_TMP_DIR => 'Dossier temporaire manquant',
                            UPLOAD_ERR_CANT_WRITE => 'Échec écriture disque',
                            UPLOAD_ERR_EXTENSION => 'Extension PHP a bloqué l\'upload',
                        ];
                        $errorMsg = $errorMessages[$errorCode] ?? "Erreur inconnue ($errorCode)";
                        $debugInfo[] = "$originalName: $errorMsg";
                    }
                }
            } else {
                $debugInfo[] = "Aucun fichier reçu par le serveur";
            }

            if ($uploadedCount > 0) {
                setFlashMessage('success', $uploadedCount . ' ' . __('photos_uploaded'));
                redirect('/employe/photos.php?groupe=' . $groupeId . '&projet=' . $projetId);
            } else {
                $errors[] = __('no_photos_uploaded');
                if (!empty($debugInfo)) {
                    $errors[] = "Détails: " . implode(', ', $debugInfo);
                }
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
                    <form method="POST" enctype="multipart/form-data" id="photoForm">
                        <?php csrfField(); ?>
                        <input type="hidden" name="action" value="upload">
                        <input type="hidden" name="groupe_id" value="<?= e($groupeId ?: uniqid('grp_', true)) ?>">

                        <div class="mb-3">
                            <label for="projet_id" class="form-label"><?= __('project') ?> *</label>
                            <select class="form-select" id="projet_id" name="projet_id" required <?= !empty($photosGroupe) ? 'disabled' : '' ?>>
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
                            <label for="description" class="form-label"><?= __('description') ?></label>
                            <textarea class="form-control" id="description" name="description" rows="2"
                                      placeholder="<?= __('photo_description_placeholder') ?>"></textarea>
                        </div>

                        <!-- Zone de capture/upload -->
                        <div class="mb-3">
                            <label for="photoInput" class="form-label"><?= __('photos') ?> *</label>

                            <!-- Input caché pour les photos et vidéos -->
                            <input type="file" id="photoInput" name="photos[]"
                                   accept="image/*,video/*" multiple
                                   class="d-none"
                                   onchange="previewPhotos(this)">

                            <!-- Bouton Prendre photo (caméra) -->
                            <div class="d-grid gap-2 mb-3">
                                <button type="button" class="btn btn-primary btn-lg" onclick="openCamera()">
                                    <i class="bi bi-camera-fill me-2"></i><?= __('take_photo') ?>
                                </button>
                            </div>

                            <!-- Ou sélectionner depuis la galerie -->
                            <div class="text-center mb-3">
                                <span class="badge bg-secondary"><?= __('or') ?></span>
                            </div>

                            <div class="d-grid gap-2">
                                <button type="button" class="btn btn-outline-secondary" onclick="openGallery()">
                                    <i class="bi bi-images me-2"></i><?= __('choose_from_gallery') ?>
                                </button>
                            </div>
                        </div>

                        <!-- Prévisualisation des photos -->
                        <div id="photoPreview" class="row g-2 mb-3" style="display: none;">
                        </div>

                        <div id="photoCount" class="alert alert-info mb-3" style="display: none;">
                            <i class="bi bi-images me-2"></i>
                            <span id="photoCountText"></span>
                        </div>

                        <button type="submit" class="btn btn-success w-100 btn-lg" id="submitBtn" style="display: none;">
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
                                        <form method="POST" class="position-absolute top-0 end-0 m-1">
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
// Ouvrir la caméra (sur mobile)
function openCamera() {
    const input = document.getElementById('photoInput');
    input.setAttribute('capture', 'environment');
    input.click();
}

// Ouvrir la galerie
function openGallery() {
    const input = document.getElementById('photoInput');
    input.removeAttribute('capture');
    input.click();
}

// Prévisualisation des photos sélectionnées
function previewPhotos(input) {
    const preview = document.getElementById('photoPreview');
    const photoCount = document.getElementById('photoCount');
    const photoCountText = document.getElementById('photoCountText');
    const submitBtn = document.getElementById('submitBtn');

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
        submitBtn.style.display = 'block';
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
