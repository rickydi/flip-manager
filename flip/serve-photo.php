<?php
/**
 * Serveur de photos sécurisé
 * Vérifie l'authentification avant de servir les fichiers
 * Flip Manager
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';

$file = $_GET['file'] ?? '';
$token = $_GET['token'] ?? '';

// Nettoyer le nom de fichier (sécurité)
$file = basename($file);

if (empty($file)) {
    http_response_code(400);
    die('Fichier non spécifié');
}

$filePath = __DIR__ . '/uploads/photos/' . $file;

// Vérifier que le fichier existe
if (!file_exists($filePath)) {
    http_response_code(404);
    die('Fichier non trouvé');
}

// Vérifier l'accès
$hasAccess = false;

// 1. Utilisateur connecté (admin ou employé)
if (isLoggedIn()) {
    $hasAccess = true;
}

// 2. Token de partage valide
if (!$hasAccess && !empty($token)) {
    // Vérifier le token dans la base de données
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
    'mp4' => 'video/mp4',
    'mov' => 'video/quicktime',
    'avi' => 'video/x-msvideo',
    'mkv' => 'video/x-matroska',
    'webm' => 'video/webm',
    'm4v' => 'video/x-m4v',
];

$mimeType = $mimeTypes[$extension] ?? 'application/octet-stream';

header('Content-Type: ' . $mimeType);
header('Content-Length: ' . filesize($filePath));
header('Cache-Control: private, max-age=3600');

readfile($filePath);
exit;
