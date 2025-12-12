<?php
/**
 * API: Analyse de facture par IA Claude
 * Reçoit une image en base64, retourne les données extraites
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

try {
    // Récupérer les fournisseurs
    $stmt = $pdo->query("SELECT nom FROM fournisseurs WHERE actif = 1 ORDER BY nom");
    $fournisseurs = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Récupérer les catégories
    $stmt = $pdo->query("SELECT id, nom FROM categories ORDER BY nom");
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Analyser avec Claude
    $claude = new ClaudeService($pdo);
    $result = $claude->analyserFacture($imageData, $mimeType, $fournisseurs, $categories);

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
