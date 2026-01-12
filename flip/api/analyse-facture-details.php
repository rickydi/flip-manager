<?php
/**
 * API: Analyse détaillée de facture par IA Claude
 * Décortique chaque ligne de la facture par étape de construction
 */

require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/ClaudeService.php';

header('Content-Type: application/json');

// Vérifier authentification admin
if (!isAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Accès non autorisé']);
    exit;
}

// Vérifier méthode POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Méthode non autorisée']);
    exit;
}

// Récupérer les données
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || empty($input['image'])) {
    echo json_encode(['success' => false, 'error' => 'Aucune image fournie']);
    exit;
}

$imageData = $input['image'];
$mimeType = $input['mime_type'] ?? 'image/png';
$customPrompt = $input['custom_prompt'] ?? null;

// Nettoyer le base64 si préfixé
if (strpos($imageData, 'data:') === 0) {
    $parts = explode(',', $imageData);
    if (count($parts) === 2) {
        // Extraire le mime type du préfixe
        if (preg_match('/data:([^;]+);/', $parts[0], $matches)) {
            $mimeType = $matches[1];
        }
        $imageData = $parts[1];
    }
}

// Compresser l'image si elle dépasse 4.5 MB (limite Claude = 5 MB)
$maxSize = 4.5 * 1024 * 1024; // 4.5 MB
$currentSize = strlen(base64_decode($imageData));

if ($currentSize > $maxSize) {
    $imgBinary = base64_decode($imageData);
    $image = @imagecreatefromstring($imgBinary);

    if ($image) {
        $width = imagesx($image);
        $height = imagesy($image);

        // Réduire progressivement jusqu'à être sous la limite
        $quality = 85;
        $scale = 1.0;

        do {
            // Redimensionner si nécessaire
            if ($scale < 1.0) {
                $newWidth = (int)($width * $scale);
                $newHeight = (int)($height * $scale);
                $resized = imagecreatetruecolor($newWidth, $newHeight);
                imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
                $workImage = $resized;
            } else {
                $workImage = $image;
            }

            // Compresser en JPEG
            ob_start();
            imagejpeg($workImage, null, $quality);
            $compressed = ob_get_clean();

            if ($workImage !== $image) {
                imagedestroy($workImage);
            }

            // Réduire la qualité ou l'échelle
            if (strlen($compressed) > $maxSize) {
                if ($quality > 50) {
                    $quality -= 10;
                } else {
                    $scale -= 0.1;
                    $quality = 75;
                }
            }
        } while (strlen($compressed) > $maxSize && $scale > 0.3);

        imagedestroy($image);
        $imageData = base64_encode($compressed);
        $mimeType = 'image/jpeg';
    }
}

try {
    // Récupérer les étapes du budget-builder
    $etapes = [];
    try {
        $stmt = $pdo->query("SELECT id, nom FROM budget_etapes ORDER BY ordre, nom");
        $etapes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // Table n'existe pas encore, utiliser les étapes par défaut
    }

    // Analyser avec Claude
    $claude = new ClaudeService($pdo);
    $result = $claude->analyserFactureDetails($imageData, $mimeType, $etapes, $customPrompt);

    echo json_encode([
        'success' => true,
        'data' => $result
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
