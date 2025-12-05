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

// Charger les noms des catégories depuis la base de données
$categoryNames = [];
try {
    $stmt = $pdo->query("SELECT cle, nom_fr FROM photos_categories");
    while ($row = $stmt->fetch()) {
        $categoryNames[$row['cle']] = $row['nom_fr'];
    }
} catch (Exception $e) {
    // Table n'existe pas encore
}

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
        <div class="d-flex justify-content-between align-items-center">
            <h1><i class="bi bi-camera me-2"></i>Photos des projets</h1>
            <a href="<?= url('/admin/photos/categories.php') ?>" class="btn btn-outline-primary">
                <i class="bi bi-tags me-2"></i>Gérer les catégories
            </a>
        </div>
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
        <?php
        // Collecter toutes les photos pour la galerie
        $allPhotosForGallery = [];
        $photoIndexCounter = 0;

        foreach ($groupesPhotos as $groupe):
            // Récupérer les photos de ce groupe
            $stmt = $pdo->prepare("SELECT * FROM photos_projet WHERE groupe_id = ? ORDER BY date_prise ASC");
            $stmt->execute([$groupe['groupe_id']]);
            $photosGroupe = $stmt->fetchAll();

            // Ajouter à la galerie globale
            foreach ($photosGroupe as $p) {
                $ext = strtolower(pathinfo($p['fichier'], PATHINFO_EXTENSION));
                $allPhotosForGallery[] = [
                    'url' => url('/serve-photo.php?file=' . urlencode($p['fichier'])),
                    'isVideo' => in_array($ext, ['mp4', 'mov', 'avi', 'mkv', 'webm', 'm4v']),
                    'filename' => $p['fichier']
                ];
            }
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
                            $desc = $groupe['description'];
                            // Chercher d'abord dans la base de données, sinon traduction, sinon texte brut
                            if (isset($categoryNames[$desc])) {
                                echo e($categoryNames[$desc]);
                            } elseif (strpos($desc, 'cat_') === 0) {
                                echo e(__($desc));
                            } else {
                                echo e($desc);
                            }
                            ?>
                        </p>
                    <?php endif; ?>
                    <div class="row g-2">
                        <?php foreach ($photosGroupe as $photo):
                            $extension = strtolower(pathinfo($photo['fichier'], PATHINFO_EXTENSION));
                            $isVideo = in_array($extension, ['mp4', 'mov', 'avi', 'mkv', 'webm', 'm4v']);
                            $mediaUrl = url('/serve-photo.php?file=' . urlencode($photo['fichier']));
                            $currentPhotoIndex = $photoIndexCounter++;
                        ?>
                            <div class="col-6 col-md-3 col-lg-2">
                                <div class="position-relative">
                                    <a href="javascript:void(0)" onclick="openGallery(<?= $currentPhotoIndex ?>)" class="d-block">
                                        <?php if ($isVideo): ?>
                                            <div class="video-thumbnail rounded" style="width:100%;height:120px;background:#1a1d21;display:flex;align-items:center;justify-content:center;position:relative;">
                                                <video src="<?= $mediaUrl ?>"
                                                       style="width:100%;height:100%;object-fit:cover;position:absolute;top:0;left:0;"
                                                       muted preload="metadata"></video>
                                                <div style="position:absolute;z-index:2;background:rgba(0,0,0,0.6);border-radius:50%;width:50px;height:50px;display:flex;align-items:center;justify-content:center;">
                                                    <i class="bi bi-play-fill text-white" style="font-size:2rem;margin-left:4px;"></i>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <img src="<?= $mediaUrl ?>"
                                                 alt="Photo"
                                                 class="img-fluid rounded"
                                                 style="width:100%;height:120px;object-fit:cover;">
                                        <?php endif; ?>
                                    </a>
                                    <form method="POST" action="" class="position-absolute top-0 end-0" style="margin:2px;">
                                        <?php csrfField(); ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="photo_id" value="<?= $photo['id'] ?>">
                                        <button type="submit" class="btn btn-danger"
                                                style="padding:2px 5px;font-size:10px;line-height:1;"
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

<script>
// Données de la galerie
const galleryItems = <?= json_encode($allPhotosForGallery) ?>;
let currentIndex = 0;
let touchStartX = 0;
let touchEndX = 0;

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
    document.getElementById('galleryVideo').pause();
}

function showCurrentMedia() {
    const item = galleryItems[currentIndex];
    const img = document.getElementById('galleryImage');
    const video = document.getElementById('galleryVideo');
    const counter = document.getElementById('galleryCounter');

    counter.textContent = (currentIndex + 1) + ' / ' + galleryItems.length;

    document.getElementById('galleryPrev').style.display = currentIndex > 0 ? 'block' : 'none';
    document.getElementById('galleryNext').style.display = currentIndex < galleryItems.length - 1 ? 'block' : 'none';

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

async function sharePhoto() {
    const item = galleryItems[currentIndex];
    const btn = document.querySelector('[onclick="sharePhoto()"]');
    const originalHtml = btn.innerHTML;

    try {
        btn.innerHTML = '<i class="bi bi-hourglass-split"></i>';
        btn.disabled = true;

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
            await navigator.share({ title: 'Photo du projet', url: shareUrl });
        } else {
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

// Swipe tactile
const galleryOverlay = document.getElementById('galleryOverlay');
if (galleryOverlay) {
    galleryOverlay.addEventListener('touchstart', e => touchStartX = e.changedTouches[0].screenX, false);
    galleryOverlay.addEventListener('touchend', e => { touchEndX = e.changedTouches[0].screenX; handleSwipe(); }, false);
}

function handleSwipe() {
    const diff = touchStartX - touchEndX;
    if (Math.abs(diff) > 50) {
        diff > 0 ? nextPhoto() : prevPhoto();
    }
}

// Clavier
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
    <?php endif; ?>
</div>

<?php include '../../includes/footer.php'; ?>
