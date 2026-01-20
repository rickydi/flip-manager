<?php
/**
 * API: Changer le statut d'un sous-traitant
 * Flip Manager
 */

require_once '../../config.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

// Vérifier l'authentification
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Non authentifié']);
    exit;
}

// Lire les données JSON
$input = json_decode(file_get_contents('php://input'), true);
$id = (int)($input['id'] ?? 0);
$statut = $input['statut'] ?? '';

if (!$id || !in_array($statut, ['en_attente', 'approuvee', 'rejetee'])) {
    echo json_encode(['success' => false, 'error' => 'Paramètres invalides']);
    exit;
}

try {
    if ($statut === 'approuvee') {
        $stmt = $pdo->prepare("UPDATE sous_traitants SET statut = ?, approuve_par = ?, date_approbation = NOW() WHERE id = ?");
        $stmt->execute([$statut, $_SESSION['user_id'], $id]);
    } else {
        $stmt = $pdo->prepare("UPDATE sous_traitants SET statut = ? WHERE id = ?");
        $stmt->execute([$statut, $id]);
    }

    echo json_encode(['success' => true, 'statut' => $statut]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
