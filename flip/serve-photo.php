<?php
/**
 * Serveur de photos sécurisé - Version optimisée
 * Vérifie l'authentification avant de servir les fichiers
 * Flip Manager
 */

$file = $_GET['file'] ?? '';
$token = $_GET['token'] ?? '';

// Nettoyer le nom de fichier (sécurité)
$file = basename($file);

if (empty($file)) {
    http_response_code(400);
    die('Fichier non spécifié');
}

$filePath = __DIR__ . '/uploads/photos/' . $file;

// Vérifier que le fichier existe AVANT de charger config/auth
if (!file_exists($filePath)) {
    http_response_code(404);
    die('Fichier non trouvé');
}

// Obtenir les infos du fichier pour le cache
$fileModTime = filemtime($filePath);
$fileSize = filesize($filePath);
$etag = md5($file . $fileModTime . $fileSize);

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

// Servir le fichier
$extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
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

$mimeType = $mimeTypes[$extension] ?? 'application/octet-stream';

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

    $fp = fopen($filePath, 'rb');
    fseek($fp, $start);
    echo fread($fp, $length);
    fclose($fp);
    exit;
}

readfile($filePath);
exit;
