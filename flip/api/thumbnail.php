<?php
/**
 * Génère un thumbnail d'une image à la volée
 * Usage: /api/thumbnail.php?file=factures/xxx.jpg&w=200&h=200
 */

require_once '../config.php';

$file = $_GET['file'] ?? '';
$width = min((int)($_GET['w'] ?? 200), 800);
$height = min((int)($_GET['h'] ?? 200), 800);

if (empty($file)) {
    http_response_code(400);
    exit('Missing file parameter');
}

// Sécurité: nettoyer le chemin
$file = basename($file);
$folder = basename(dirname($_GET['file'] ?? ''));
$allowedFolders = ['factures', 'projets', 'comparables'];

if (!in_array($folder, $allowedFolders)) {
    $folder = 'factures';
}

$sourcePath = dirname(__DIR__) . '/uploads/' . $folder . '/' . $file;

if (!file_exists($sourcePath)) {
    http_response_code(404);
    exit('File not found');
}

// Vérifier l'extension
$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
    http_response_code(400);
    exit('Invalid file type');
}

// Cache headers
$etag = md5($sourcePath . $width . $height . filemtime($sourcePath));
header('ETag: "' . $etag . '"');
header('Cache-Control: public, max-age=31536000');

if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH'], '"') === $etag) {
    http_response_code(304);
    exit;
}

// Créer le dossier cache si nécessaire
$cacheDir = dirname(__DIR__) . '/uploads/cache/';
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0755, true);
}

$cachePath = $cacheDir . $width . 'x' . $height . '_' . $file;

// Utiliser le cache s'il existe et est plus récent
if (file_exists($cachePath) && filemtime($cachePath) >= filemtime($sourcePath)) {
    header('Content-Type: image/' . ($ext === 'jpg' ? 'jpeg' : $ext));
    readfile($cachePath);
    exit;
}

// Vérifier si GD est disponible
if (!extension_loaded('gd')) {
    // Pas de GD, retourner l'image originale
    header('Content-Type: image/' . ($ext === 'jpg' ? 'jpeg' : $ext));
    readfile($sourcePath);
    exit;
}

// Charger l'image source
$sourceImage = null;
switch ($ext) {
    case 'jpg':
    case 'jpeg':
        $sourceImage = @imagecreatefromjpeg($sourcePath);
        break;
    case 'png':
        $sourceImage = @imagecreatefrompng($sourcePath);
        break;
    case 'gif':
        $sourceImage = @imagecreatefromgif($sourcePath);
        break;
    case 'webp':
        $sourceImage = @imagecreatefromwebp($sourcePath);
        break;
}

if (!$sourceImage) {
    header('Content-Type: image/' . ($ext === 'jpg' ? 'jpeg' : $ext));
    readfile($sourcePath);
    exit;
}

// Dimensions originales
$origWidth = imagesx($sourceImage);
$origHeight = imagesy($sourceImage);

// Calculer les nouvelles dimensions en gardant le ratio
$ratio = min($width / $origWidth, $height / $origHeight);
$newWidth = (int)($origWidth * $ratio);
$newHeight = (int)($origHeight * $ratio);

// Si l'image est déjà plus petite, ne pas agrandir
if ($newWidth >= $origWidth && $newHeight >= $origHeight) {
    imagedestroy($sourceImage);
    header('Content-Type: image/' . ($ext === 'jpg' ? 'jpeg' : $ext));
    readfile($sourcePath);
    exit;
}

// Créer le thumbnail
$thumb = imagecreatetruecolor($newWidth, $newHeight);

// Préserver la transparence pour PNG
if ($ext === 'png') {
    imagealphablending($thumb, false);
    imagesavealpha($thumb, true);
    $transparent = imagecolorallocatealpha($thumb, 0, 0, 0, 127);
    imagefill($thumb, 0, 0, $transparent);
}

// Redimensionner
imagecopyresampled($thumb, $sourceImage, 0, 0, 0, 0, $newWidth, $newHeight, $origWidth, $origHeight);

// Sauvegarder en cache
switch ($ext) {
    case 'jpg':
    case 'jpeg':
        imagejpeg($thumb, $cachePath, 85);
        break;
    case 'png':
        imagepng($thumb, $cachePath, 8);
        break;
    case 'gif':
        imagegif($thumb, $cachePath);
        break;
    case 'webp':
        imagewebp($thumb, $cachePath, 85);
        break;
}

// Envoyer l'image
header('Content-Type: image/' . ($ext === 'jpg' ? 'jpeg' : $ext));
readfile($cachePath);

imagedestroy($sourceImage);
imagedestroy($thumb);
