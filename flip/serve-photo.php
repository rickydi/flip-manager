<?php
/**
 * Serveur de photos sécurisé - Version optimisée
 * Vérifie l'authentification avant de servir les fichiers
 * Supporte les miniatures pour un chargement rapide
 * Flip Manager
 */

$file = $_GET['file'] ?? '';
$token = $_GET['token'] ?? '';
$wantThumb = isset($_GET['thumb']) && $_GET['thumb'] == '1';

// Nettoyer le nom de fichier (sécurité)
$file = basename($file);

if (empty($file)) {
    http_response_code(400);
    die('Fichier non spécifié');
}

$filePath = __DIR__ . '/uploads/photos/' . $file;
$thumbDir = __DIR__ . '/uploads/photos/thumbs';
$thumbPath = $thumbDir . '/' . $file;

// Vérifier que le fichier existe AVANT de charger config/auth
if (!file_exists($filePath)) {
    http_response_code(404);
    die('Fichier non trouvé');
}

// Si thumbnail demandée, vérifier/créer la miniature
$servePath = $filePath;
if ($wantThumb) {
    $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    // Seulement pour les images (pas vidéos)
    if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
        // Créer le dossier thumbs si nécessaire
        if (!is_dir($thumbDir)) {
            mkdir($thumbDir, 0755, true);
        }

        // Générer la miniature si elle n'existe pas ou est plus vieille que l'original
        if (!file_exists($thumbPath) || filemtime($thumbPath) < filemtime($filePath)) {
            $thumbCreated = false;

            // Utiliser GD pour créer la miniature
            $sourceImage = null;
            switch ($extension) {
                case 'jpg':
                case 'jpeg':
                    $sourceImage = @imagecreatefromjpeg($filePath);
                    break;
                case 'png':
                    $sourceImage = @imagecreatefrompng($filePath);
                    break;
                case 'gif':
                    $sourceImage = @imagecreatefromgif($filePath);
                    break;
                case 'webp':
                    $sourceImage = @imagecreatefromwebp($filePath);
                    break;
            }

            if ($sourceImage) {
                $origWidth = imagesx($sourceImage);
                $origHeight = imagesy($sourceImage);

                // Taille cible de la miniature (max 300px)
                $maxSize = 300;
                $ratio = min($maxSize / $origWidth, $maxSize / $origHeight);

                // Si l'image est déjà petite, pas besoin de miniature
                if ($ratio >= 1) {
                    imagedestroy($sourceImage);
                    // Utiliser l'original
                } else {
                    $newWidth = (int)($origWidth * $ratio);
                    $newHeight = (int)($origHeight * $ratio);

                    $thumbImage = imagecreatetruecolor($newWidth, $newHeight);

                    // Préserver la transparence pour PNG
                    if ($extension === 'png') {
                        imagealphablending($thumbImage, false);
                        imagesavealpha($thumbImage, true);
                    }

                    imagecopyresampled($thumbImage, $sourceImage, 0, 0, 0, 0, $newWidth, $newHeight, $origWidth, $origHeight);

                    // Sauvegarder en JPEG pour les miniatures (plus petit)
                    $thumbPathJpg = preg_replace('/\.[^.]+$/', '.jpg', $thumbPath);
                    if (imagejpeg($thumbImage, $thumbPathJpg, 75)) {
                        $thumbPath = $thumbPathJpg;
                        $thumbCreated = true;
                    }

                    imagedestroy($thumbImage);
                    imagedestroy($sourceImage);
                }
            }
        }

        // Utiliser la miniature si elle existe
        $thumbPathJpg = preg_replace('/\.[^.]+$/', '.jpg', $thumbPath);
        if (file_exists($thumbPathJpg)) {
            $servePath = $thumbPathJpg;
        } elseif (file_exists($thumbPath)) {
            $servePath = $thumbPath;
        }
    }
}

// Obtenir les infos du fichier pour le cache
$fileModTime = filemtime($servePath);
$fileSize = filesize($servePath);
$etag = md5($file . $fileModTime . $fileSize . ($wantThumb ? '_thumb' : ''));

// Vérifier le cache du navigateur AVANT d'aller plus loin
if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
    http_response_code(304);
    exit;
}

if (isset($_SERVER['HTTP_IF_MODIFIED_SINCE'])) {
    $ifModifiedSince = strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']);
    if ($ifModifiedSince >= $fileModTime) {
        http_response_code(304);
        exit;
    }
}

// Maintenant charger l'authentification (seulement si pas en cache)
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';

// Vérifier l'accès
$hasAccess = false;

// 1. Utilisateur connecté (admin ou employé)
if (isLoggedIn()) {
    $hasAccess = true;
}

// 2. Token de partage valide
if (!$hasAccess && !empty($token)) {
    try {
        $stmt = $pdo->prepare("
            SELECT * FROM photos_shares
            WHERE fichier = ? AND token = ?
            AND (expire_at IS NULL OR expire_at > NOW())
        ");
        $stmt->execute([$file, $token]);
        if ($stmt->fetch()) {
            $hasAccess = true;
        }
    } catch (Exception $e) {
        // Table n'existe pas encore, ignorer
    }
}

if (!$hasAccess) {
    http_response_code(403);
    die('Accès non autorisé. Veuillez vous connecter.');
}

// Servir le fichier (utiliser l'extension du fichier servi, pas l'original)
$serveExtension = strtolower(pathinfo($servePath, PATHINFO_EXTENSION));
$mimeTypes = [
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'gif' => 'image/gif',
    'webp' => 'image/webp',
    'heic' => 'image/heic',
    'heif' => 'image/heif',
    'mp4' => 'video/mp4',
    'mov' => 'video/quicktime',
    'avi' => 'video/x-msvideo',
    'mkv' => 'video/x-matroska',
    'webm' => 'video/webm',
    'm4v' => 'video/x-m4v',
];

$mimeType = $mimeTypes[$serveExtension] ?? 'application/octet-stream';

// Headers de cache optimisés
header('Content-Type: ' . $mimeType);
header('Content-Length: ' . $fileSize);
header('ETag: ' . $etag);
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $fileModTime) . ' GMT');
header('Cache-Control: private, max-age=86400'); // 24 heures
header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 86400) . ' GMT');

// Pour les gros fichiers vidéo, supporter le Range (streaming)
if (strpos($mimeType, 'video/') === 0 && isset($_SERVER['HTTP_RANGE'])) {
    header('Accept-Ranges: bytes');

    $range = $_SERVER['HTTP_RANGE'];
    list(, $range) = explode('=', $range, 2);
    list($start, $end) = explode('-', $range);

    $start = intval($start);
    $end = $end === '' ? $fileSize - 1 : intval($end);
    $length = $end - $start + 1;

    http_response_code(206);
    header("Content-Range: bytes $start-$end/$fileSize");
    header("Content-Length: $length");

    $fp = fopen($servePath, 'rb');
    fseek($fp, $start);
    echo fread($fp, $length);
    fclose($fp);
    exit;
}

readfile($servePath);
exit;
