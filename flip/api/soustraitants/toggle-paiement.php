<?php
/**
 * API: Basculer le statut de paiement d'un sous-traitant
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

if (!$id) {
    echo json_encode(['success' => false, 'error' => 'ID manquant']);
    exit;
}

try {
    // Récupérer l'état actuel
    $stmt = $pdo->prepare("SELECT est_payee FROM sous_traitants WHERE id = ?");
    $stmt->execute([$id]);
    $soustraitant = $stmt->fetch();

    if (!$soustraitant) {
        echo json_encode(['success' => false, 'error' => 'Sous-traitant non trouvé']);
        exit;
    }

    $newStatus = $soustraitant['est_payee'] ? 0 : 1;
    $datePaiement = $newStatus ? date('Y-m-d') : null;

    $stmt = $pdo->prepare("UPDATE sous_traitants SET est_payee = ?, date_paiement = ? WHERE id = ?");
    $stmt->execute([$newStatus, $datePaiement, $id]);

    echo json_encode([
        'success' => true,
        'est_payee' => (bool)$newStatus,
        'date_paiement' => $datePaiement
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
