<?php
/**
 * API: Convertir un PDF en image base64
 * Utilisé par l'upload multiple pour avoir la même qualité que le formulaire simple
 */

require_once '../../config.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

// Vérifier authentification
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Non connecté']);
    exit;
}

// Vérifier méthode POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Méthode non autorisée']);
    exit;
}

// Vérifier le fichier
if (!isset($_FILES['fichier']) || $_FILES['fichier']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'error' => 'Aucun fichier fourni']);
    exit;
}

$file = $_FILES['fichier'];
$fileExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

// Si c'est déjà une image, la retourner en base64
if (in_array($fileExt, ['jpg', 'jpeg', 'png'])) {
    $imageData = base64_encode(file_get_contents($file['tmp_name']));
    $mimeType = match($fileExt) {
        'jpg', 'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        default => 'image/png'
    };

    echo json_encode([
        'success' => true,
        'image' => "data:{$mimeType};base64,{$imageData}",
        'mime_type' => $mimeType
    ]);
    exit;
}

// Si c'est un PDF, le convertir
if ($fileExt !== 'pdf') {
    echo json_encode(['success' => false, 'error' => 'Format non supporté']);
    exit;
}

// Vérifier Imagick
if (!extension_loaded('imagick')) {
    echo json_encode(['success' => false, 'error' => 'Extension Imagick non disponible']);
    exit;
}

try {
    $imagick = new Imagick();
    $imagick->setResolution(300, 300); // 300 DPI comme le formulaire simple
    $imagick->readImage($file['tmp_name'] . '[0]'); // Première page
    $imagick->setImageFormat('png');
    $imagick->setImageCompressionQuality(95);

    $imageData = base64_encode($imagick->getImageBlob());
    $imagick->destroy();

    echo json_encode([
        'success' => true,
        'image' => "data:image/png;base64,{$imageData}",
        'mime_type' => 'image/png'
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Erreur conversion PDF: ' . $e->getMessage()
    ]);
}
