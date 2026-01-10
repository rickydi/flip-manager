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
    $result = $claude->analyserFactureDetails($imageData, $mimeType, $etapes);

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
