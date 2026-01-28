<?php
/**
 * API: Sauvegarder la préférence de vue des projets (liste/grille)
 */

require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

// Vérifier authentification
if (!isLoggedIn()) {
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

if (!$input || empty($input['vue'])) {
    echo json_encode(['success' => false, 'error' => 'Vue requise']);
    exit;
}

$vue = $input['vue'];

// Valider la valeur
if (!in_array($vue, ['liste', 'grille'])) {
    echo json_encode(['success' => false, 'error' => 'Valeur de vue invalide']);
    exit;
}

try {
    $userId = getCurrentUserId();

    $stmt = $pdo->prepare("UPDATE users SET vue_projets_preference = ? WHERE id = ?");
    $stmt->execute([$vue, $userId]);

    echo json_encode(['success' => true, 'vue' => $vue]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erreur serveur']);
}
