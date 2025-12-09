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

// Récupérer les catégories de photos depuis la base de données
$photoCategories = [];
try {
    $lang = getCurrentLanguage();
    $nomCol = $lang === 'es' ? 'nom_es' : 'nom_fr';
    $stmt = $pdo->query("SELECT cle, $nomCol as nom FROM photos_categories WHERE actif = 1 ORDER BY ordre, nom_fr");
    $photoCategories = $stmt->fetchAll();
} catch (Exception $e) {
    // Table n'existe pas encore, utiliser les catégories par défaut
    $defaultCategories = [
        'cat_interior_finishing', 'cat_exterior', 'cat_plumbing', 'cat_electrical',
        'cat_structure', 'cat_foundation', 'cat_roofing', 'cat_windows_doors',
        'cat_painting', 'cat_flooring', 'cat_before_work', 'cat_after_work',
        'cat_progress', 'cat_other'
    ];
    foreach ($defaultCategories as $cat) {
        $photoCategories[] = ['cle' => $cat, 'nom' => __($cat)];
    }
}

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

            // Collecter tous les fichiers (caméra + galerie)
            $filesToProcess = [];

            // Photo de la caméra (fichier unique)
            if (isset($_FILES['camera_photo']) && $_FILES['camera_photo']['error'] === UPLOAD_ERR_OK && !empty($_FILES['camera_photo']['name'])) {
                $filesToProcess[] = [
                    'name' => $_FILES['camera_photo']['name'],
                    'tmp_name' => $_FILES['camera_photo']['tmp_name'],
                    'error' => $_FILES['camera_photo']['error'],
                    'size' => $_FILES['camera_photo']['size']
                ];
            }

            // Photos de la galerie (fichiers multiples)
            if (isset($_FILES['gallery_photos']) && !empty($_FILES['gallery_photos']['name'][0])) {
                $totalGallery = count($_FILES['gallery_photos']['name']);
                for ($i = 0; $i < $totalGallery; $i++) {
                    if (!empty($_FILES['gallery_photos']['name'][$i])) {
                        $filesToProcess[] = [
                            'name' => $_FILES['gallery_photos']['name'][$i],
                            'tmp_name' => $_FILES['gallery_photos']['tmp_name'][$i],
                            'error' => $_FILES['gallery_photos']['error'][$i],
                            'size' => $_FILES['gallery_photos']['size'][$i]
                        ];
                    }
                }
            }

            if (!empty($filesToProcess)) {
                foreach ($filesToProcess as $file) {
                    $errorCode = $file['error'];
                    $originalName = $file['name'];

                    if ($errorCode === UPLOAD_ERR_OK) {
                        $tmpName = $file['tmp_name'];
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

                        // Vérifier que le fichier temp existe
                        if (!file_exists($tmpName)) {
                            $debugInfo[] = "Fichier temp n'existe pas: $tmpName";
                            continue;
                        }

                        // Vérifier/créer le dossier destination
                        $destDir = dirname($destination);
                        if (!is_dir($destDir)) {
                            if (!mkdir($destDir, 0777, true)) {
                                $debugInfo[] = "Impossible de créer le dossier: $destDir";
                                continue;
                            }
                        }
                        if (!is_writable($destDir)) {
                            chmod($destDir, 0777);
                            if (!is_writable($destDir)) {
                                $debugInfo[] = "Dossier non writable: $destDir";
                                continue;
                            }
                        }

                        // Essayer avec copy() si move_uploaded_file échoue
                        if (move_uploaded_file($tmpName, $destination)) {
                            // Insérer dans la base de données
                            $stmt = $pdo->prepare("
                                INSERT INTO photos_projet (projet_id, user_id, groupe_id, fichier, date_prise, description)
                                VALUES (?, ?, ?, ?, NOW(), ?)
                            ");
                            $stmt->execute([$projetId, $userId, $groupeId, $newFilename, $description]);
                            $uploadedCount++;
                        } elseif (copy($tmpName, $destination)) {
                            // Fallback avec copy()
                            unlink($tmpName);
                            $stmt = $pdo->prepare("
                                INSERT INTO photos_projet (projet_id, user_id, groupe_id, fichier, date_prise, description)
                                VALUES (?, ?, ?, ?, NOW(), ?)
                            ");
                            $stmt->execute([$projetId, $userId, $groupeId, $newFilename, $description]);
                            $uploadedCount++;
                        } else {
                            $lastError = error_get_last();
                            $debugInfo[] = "Échec upload $originalName: " . ($lastError['message'] ?? 'erreur inconnue');
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
                            <select class="form-select" id="projet_id" name="projet_id" required>
                                <option value=""><?= __('select') ?></option>
                                <?php foreach ($projets as $projet): ?>
                                    <option value="<?= $projet['id'] ?>" <?= $projetIdSelected == $projet['id'] ? 'selected' : '' ?>>
                                        <?= e($projet['nom']) ?> - <?= e($projet['adresse']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label for="categorie" class="form-label"><?= __('photo_category') ?></label>
                            <select class="form-select" id="categorie" name="description">
                                <option value=""><?= __('select_category_photo') ?></option>
                                <?php foreach ($photoCategories as $cat): ?>
                                    <option value="<?= e($cat['cle']) ?>"><?= e($cat['nom']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Zone de capture/upload -->
                        <div class="mb-3">
                            <label class="form-label"><?= __('photos') ?> *</label>

                            <!-- Input caméra (avec capture) -->
                            <input type="file" id="cameraInput" name="camera_photo"
                                   accept="image/*,image/heic,image/heif,.heic,.heif" capture="environment"
                                   class="d-none"
                                   onchange="previewPhotos(this)">

                            <!-- Input galerie (sans capture) -->
                            <input type="file" id="galleryInput" name="gallery_photos[]"
                                   accept="image/*,image/heic,image/heif,.heic,.heif,video/*" multiple
                                   class="d-none"
                                   onchange="previewPhotos(this)">

                            <!-- Bouton Prendre photo (caméra) -->
                            <div class="d-grid gap-2 mb-3">
                                <button type="button" class="btn btn-primary py-3" style="font-size: 1.2rem;" onclick="document.getElementById('cameraInput').click()">
                                    <i class="bi bi-camera-fill me-2" style="font-size: 1.5rem;"></i><?= __('take_photo') ?>
                                </button>
                            </div>

                            <!-- Ou sélectionner depuis la galerie -->
                            <div class="text-center mb-3">
                                <span class="badge bg-secondary"><?= __('or') ?></span>
                            </div>

                            <div class="d-grid gap-2">
                                <button type="button" class="btn btn-outline-primary py-3" style="font-size: 1.2rem;" onclick="document.getElementById('galleryInput').click()">
                                    <i class="bi bi-images me-2" style="font-size: 1.5rem;"></i><?= __('choose_from_gallery') ?>
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

                        <button type="submit" class="btn btn-success w-100 py-4" id="submitBtn" style="display: none; font-size: 1.3rem;">
                            <i class="bi bi-cloud-upload me-2" style="font-size: 1.8rem;"></i><?= __('upload_photos') ?>
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
                            <?php foreach ($photosGroupe as $index => $photo):
                                $extension = strtolower(pathinfo($photo['fichier'], PATHINFO_EXTENSION));
                                $isVideo = in_array($extension, ['mp4', 'mov', 'avi', 'mkv', 'webm', 'm4v']);
                                $mediaUrl = url('/serve-photo.php?file=' . urlencode($photo['fichier']));
                            ?>
                                <div class="col-4 col-md-3">
                                    <div class="position-relative">
                                        <a href="javascript:void(0)" onclick="openGallery(<?= $index ?>)" class="d-block">
                                            <?php if ($isVideo): ?>
                                                <div class="video-thumbnail rounded" style="width:100%;height:100px;background:#1a1d21;display:flex;align-items:center;justify-content:center;position:relative;">
                                                    <video src="<?= $mediaUrl ?>"
                                                           style="width:100%;height:100%;object-fit:cover;position:absolute;top:0;left:0;"
                                                           muted preload="metadata"></video>
                                                    <div style="position:absolute;z-index:2;background:rgba(0,0,0,0.6);border-radius:50%;width:40px;height:40px;display:flex;align-items:center;justify-content:center;">
                                                        <i class="bi bi-play-fill text-white" style="font-size:1.5rem;margin-left:3px;"></i>
                                                    </div>
                                                </div>
                                            <?php else: ?>
                                                <img src="<?= $mediaUrl ?>"
                                                     alt="Photo"
                                                     class="img-fluid rounded"
                                                     style="width:100%;height:100px;object-fit:cover;">
                                            <?php endif; ?>
                                        </a>
                                        <form method="POST" class="position-absolute top-0 end-0" style="margin:2px;">
                                            <?php csrfField(); ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="photo_id" value="<?= $photo['id'] ?>">
                                            <button type="submit" class="btn btn-danger"
                                                    style="padding:2px 5px;font-size:10px;line-height:1;"
                                                    onclick="return confirm('<?= __('delete_photo_confirm') ?>')">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    </div>
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

<!-- Overlay de chargement -->
<div id="uploadOverlay" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.8);z-index:9999;justify-content:center;align-items:center;flex-direction:column;">
    <div class="spinner-border text-light" role="status" style="width:4rem;height:4rem;">
        <span class="visually-hidden">Chargement...</span>
    </div>
    <div class="text-white mt-4 fs-4" id="uploadStatus">
        <i class="bi bi-cloud-upload me-2"></i>Téléversement en cours...
    </div>
    <div class="text-white-50 mt-2">Veuillez patienter, ne fermez pas cette page</div>
    <div class="progress mt-3" style="width:200px;height:8px;">
        <div class="progress-bar progress-bar-striped progress-bar-animated bg-primary" style="width:100%"></div>
    </div>
</div>

<!-- Galerie Lightbox -->
<div id="galleryOverlay" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.95);z-index:9998;touch-action:pan-y;">
    <!-- Barre du haut -->
    <div style="position:absolute;top:0;left:0;right:0;padding:15px;display:flex;justify-content:space-between;align-items:center;z-index:10;">
        <span id="galleryCounter" class="text-white">1 / 1</span>
        <div>
            <button type="button" class="btn btn-outline-light btn-sm me-2" onclick="sharePhoto()" title="Partager">
                <i class="bi bi-share"></i>
            </button>
            <button type="button" class="btn btn-outline-light btn-sm me-2" onclick="downloadPhoto()" title="Télécharger">
                <i class="bi bi-download"></i>
            </button>
            <button type="button" class="btn btn-outline-light btn-sm" onclick="closeGallery()">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
    </div>

    <!-- Flèches navigation -->
    <button type="button" id="galleryPrev" onclick="prevPhoto()"
            style="position:absolute;left:10px;top:50%;transform:translateY(-50%);background:rgba(255,255,255,0.2);border:none;color:white;padding:15px;border-radius:50%;z-index:10;">
        <i class="bi bi-chevron-left" style="font-size:1.5rem;"></i>
    </button>
    <button type="button" id="galleryNext" onclick="nextPhoto()"
            style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:rgba(255,255,255,0.2);border:none;color:white;padding:15px;border-radius:50%;z-index:10;">
        <i class="bi bi-chevron-right" style="font-size:1.5rem;"></i>
    </button>

    <!-- Contenu media -->
    <div id="galleryContent" style="position:absolute;top:60px;bottom:20px;left:0;right:0;display:flex;align-items:center;justify-content:center;overflow:hidden;">
        <img id="galleryImage" src="" alt="Photo" style="max-width:100%;max-height:100%;object-fit:contain;display:none;">
        <video id="galleryVideo" src="" controls style="max-width:100%;max-height:100%;display:none;"></video>
    </div>
</div>

<?php
// Préparer les données de la galerie pour JavaScript
$galleryData = [];
if (!empty($photosGroupe)) {
    foreach ($photosGroupe as $photo) {
        $ext = strtolower(pathinfo($photo['fichier'], PATHINFO_EXTENSION));
        $galleryData[] = [
            'url' => url('/serve-photo.php?file=' . urlencode($photo['fichier'])),
            'isVideo' => in_array($ext, ['mp4', 'mov', 'avi', 'mkv', 'webm', 'm4v']),
            'filename' => $photo['fichier']
        ];
    }
}
?>

<script>
// Données de la galerie
const galleryItems = <?= json_encode($galleryData) ?>;
let currentIndex = 0;
let touchStartX = 0;
let touchEndX = 0;
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

// Afficher l'overlay pendant le téléversement
document.getElementById('photoForm').addEventListener('submit', function() {
    const btn = document.getElementById('submitBtn');
    const overlay = document.getElementById('uploadOverlay');

    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Téléversement...';

    // Afficher l'overlay
    overlay.style.display = 'flex';
});

// ===== GALERIE LIGHTBOX =====
function openGallery(index) {
    if (galleryItems.length === 0) return;
    currentIndex = index;
    showCurrentMedia();
    document.getElementById('galleryOverlay').style.display = 'block';
    document.body.style.overflow = 'hidden';
}

function closeGallery() {
    document.getElementById('galleryOverlay').style.display = 'none';
    document.body.style.overflow = '';
    // Arrêter la vidéo si elle joue
    document.getElementById('galleryVideo').pause();
}

function showCurrentMedia() {
    const item = galleryItems[currentIndex];
    const img = document.getElementById('galleryImage');
    const video = document.getElementById('galleryVideo');
    const counter = document.getElementById('galleryCounter');

    // Mise à jour du compteur
    counter.textContent = (currentIndex + 1) + ' / ' + galleryItems.length;

    // Afficher/cacher les flèches
    document.getElementById('galleryPrev').style.display = currentIndex > 0 ? 'block' : 'none';
    document.getElementById('galleryNext').style.display = currentIndex < galleryItems.length - 1 ? 'block' : 'none';

    // Arrêter la vidéo précédente
    video.pause();

    if (item.isVideo) {
        img.style.display = 'none';
        video.src = item.url;
        video.style.display = 'block';
    } else {
        video.style.display = 'none';
        img.src = item.url;
        img.style.display = 'block';
    }
}

function nextPhoto() {
    if (currentIndex < galleryItems.length - 1) {
        currentIndex++;
        showCurrentMedia();
    }
}

function prevPhoto() {
    if (currentIndex > 0) {
        currentIndex--;
        showCurrentMedia();
    }
}

// Partager la photo (avec token sécurisé)
async function sharePhoto() {
    const item = galleryItems[currentIndex];
    const btn = document.querySelector('[onclick="sharePhoto()"]');
    const originalHtml = btn.innerHTML;

    try {
        // Afficher le chargement
        btn.innerHTML = '<i class="bi bi-hourglass-split"></i>';
        btn.disabled = true;

        // Appeler l'API pour créer un lien de partage sécurisé
        const response = await fetch('<?= url('/api/share-photo.php') ?>', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ filename: item.filename })
        });

        const data = await response.json();

        if (!response.ok || !data.success) {
            throw new Error(data.error || 'Erreur lors de la création du lien');
        }

        const shareUrl = data.url;

        if (navigator.share) {
            await navigator.share({
                title: 'Photo du projet',
                url: shareUrl
            });
        } else {
            // Fallback: copier le lien
            await navigator.clipboard.writeText(shareUrl);
            alert('Lien de partage copié! (valide 7 jours)');
        }
    } catch (err) {
        if (err.name !== 'AbortError') {
            alert('Erreur: ' + (err.message || 'Impossible de partager'));
        }
    } finally {
        btn.innerHTML = originalHtml;
        btn.disabled = false;
    }
}

// Télécharger la photo
function downloadPhoto() {
    const item = galleryItems[currentIndex];
    const a = document.createElement('a');
    a.href = item.url;
    a.download = item.filename;
    a.target = '_blank';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
}

// Gestion du swipe tactile
const galleryOverlay = document.getElementById('galleryOverlay');
if (galleryOverlay) {
    galleryOverlay.addEventListener('touchstart', function(e) {
        touchStartX = e.changedTouches[0].screenX;
    }, false);

    galleryOverlay.addEventListener('touchend', function(e) {
        touchEndX = e.changedTouches[0].screenX;
        handleSwipe();
    }, false);
}

function handleSwipe() {
    const swipeThreshold = 50;
    const diff = touchStartX - touchEndX;

    if (Math.abs(diff) > swipeThreshold) {
        if (diff > 0) {
            // Swipe gauche -> photo suivante
            nextPhoto();
        } else {
            // Swipe droite -> photo précédente
            prevPhoto();
        }
    }
}

// Fermer avec Escape ou clic sur le fond
document.addEventListener('keydown', function(e) {
    if (document.getElementById('galleryOverlay').style.display === 'block') {
        if (e.key === 'Escape') closeGallery();
        if (e.key === 'ArrowRight') nextPhoto();
        if (e.key === 'ArrowLeft') prevPhoto();
    }
});

document.getElementById('galleryContent')?.addEventListener('click', function(e) {
    if (e.target === this) closeGallery();
});
</script>

<?php include '../includes/footer.php'; ?>
