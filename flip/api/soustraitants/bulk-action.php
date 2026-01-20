<?php
/**
 * API: Actions en masse sur les sous-traitants
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
$action = $input['action'] ?? '';
$ids = $input['ids'] ?? [];

if (empty($action) || empty($ids) || !is_array($ids)) {
    echo json_encode(['success' => false, 'error' => 'Paramètres invalides']);
    exit;
}

// Nettoyer les IDs
$ids = array_map('intval', $ids);
$ids = array_filter($ids, fn($id) => $id > 0);

if (empty($ids)) {
    echo json_encode(['success' => false, 'error' => 'Aucun ID valide']);
    exit;
}

$placeholders = implode(',', array_fill(0, count($ids), '?'));

try {
    switch ($action) {
        case 'payer':
            $stmt = $pdo->prepare("UPDATE sous_traitants SET est_payee = 1, date_paiement = CURDATE() WHERE id IN ($placeholders)");
            $stmt->execute($ids);
            break;

        case 'non_payer':
            $stmt = $pdo->prepare("UPDATE sous_traitants SET est_payee = 0, date_paiement = NULL WHERE id IN ($placeholders)");
            $stmt->execute($ids);
            break;

        case 'approuver':
            $stmt = $pdo->prepare("UPDATE sous_traitants SET statut = 'approuvee', approuve_par = ?, date_approbation = NOW() WHERE id IN ($placeholders)");
            $stmt->execute(array_merge([$_SESSION['user_id']], $ids));
            break;

        case 'en_attente':
            $stmt = $pdo->prepare("UPDATE sous_traitants SET statut = 'en_attente' WHERE id IN ($placeholders)");
            $stmt->execute($ids);
            break;

        case 'rejeter':
            $stmt = $pdo->prepare("UPDATE sous_traitants SET statut = 'rejetee' WHERE id IN ($placeholders)");
            $stmt->execute($ids);
            break;

        case 'supprimer':
            // Récupérer les fichiers à supprimer
            $stmt = $pdo->prepare("SELECT fichier FROM sous_traitants WHERE id IN ($placeholders) AND fichier IS NOT NULL");
            $stmt->execute($ids);
            $fichiers = $stmt->fetchAll(PDO::FETCH_COLUMN);

            // Supprimer les fichiers
            foreach ($fichiers as $fichier) {
                $filePath = __DIR__ . '/../../uploads/soustraitants/' . $fichier;
                if (file_exists($filePath)) {
                    unlink($filePath);
                }
            }

            // Supprimer les enregistrements
            $stmt = $pdo->prepare("DELETE FROM sous_traitants WHERE id IN ($placeholders)");
            $stmt->execute($ids);
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Action non reconnue']);
            exit;
    }

    echo json_encode(['success' => true, 'affected' => count($ids)]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
