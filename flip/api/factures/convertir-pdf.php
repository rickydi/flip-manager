<?php
/**
 * API: Convertir un fichier en base64 pour l'analyse IA
 * - Images: retourne directement en base64
 * - PDF: retourne en base64 (Claude supporte les PDF nativement)
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

// Déterminer le type MIME
$mimeType = match($fileExt) {
    'jpg', 'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'pdf' => 'application/pdf',
    default => null
};

if (!$mimeType) {
    echo json_encode(['success' => false, 'error' => 'Format non supporté (JPG, PNG, PDF uniquement)']);
    exit;
}

// Lire le fichier et encoder en base64
$fileContent = file_get_contents($file['tmp_name']);
if ($fileContent === false) {
    echo json_encode(['success' => false, 'error' => 'Erreur lecture fichier']);
    exit;
}

$base64Data = base64_encode($fileContent);

echo json_encode([
    'success' => true,
    'image' => "data:{$mimeType};base64,{$base64Data}",
    'mime_type' => $mimeType,
    'is_pdf' => ($fileExt === 'pdf')
]);
