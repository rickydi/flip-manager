<?php
/**
 * API: Actions en masse sur les factures
 * Actions supportées: payer, non_payer, approuver, en_attente, rejeter, supprimer
 */

// Debug: tester si le script est accessible
header('Content-Type: application/json');

// Vérifier que le script fonctionne
$rawInput = file_get_contents('php://input');
if (empty($rawInput)) {
    echo json_encode(['success' => false, 'error' => 'Aucune donnée reçue', 'method' => $_SERVER['REQUEST_METHOD']]);
    exit;
}

require_once '../../config.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

// Vérifier authentification
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Non authentifié']);
    exit;
}

// Récupérer les données
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);

if (!$input || empty($input['action']) || empty($input['ids']) || !is_array($input['ids'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Données invalides',
        'debug' => [
            'raw_input' => $rawInput,
            'parsed_input' => $input,
            'json_error' => json_last_error_msg()
        ]
    ]);
    exit;
}

$action = $input['action'];
$ids = array_map('intval', $input['ids']);

// Valider les IDs
$ids = array_filter($ids, fn($id) => $id > 0);
if (empty($ids)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Aucun ID valide']);
    exit;
}

$placeholders = implode(',', array_fill(0, count($ids), '?'));

try {
    $pdo = getPDO();
    $affected = 0;

    switch ($action) {
        case 'payer':
            $stmt = $pdo->prepare("UPDATE factures SET est_payee = 1 WHERE id IN ($placeholders)");
            $stmt->execute($ids);
            $affected = $stmt->rowCount();
            break;

        case 'non_payer':
            // Debug: vérifier avant
            $stmtBefore = $pdo->prepare("SELECT id, est_payee FROM factures WHERE id IN ($placeholders)");
            $stmtBefore->execute($ids);
            $before = $stmtBefore->fetchAll(PDO::FETCH_ASSOC);
            error_log("BULK non_payer - Avant: " . json_encode($before));

            $stmt = $pdo->prepare("UPDATE factures SET est_payee = 0 WHERE id IN ($placeholders)");
            $stmt->execute($ids);

            // Debug: vérifier après
            $stmtAfter = $pdo->prepare("SELECT id, est_payee FROM factures WHERE id IN ($placeholders)");
            $stmtAfter->execute($ids);
            $after = $stmtAfter->fetchAll(PDO::FETCH_ASSOC);
            error_log("BULK non_payer - Après: " . json_encode($after));

            $affected = count($ids);
            break;

        case 'approuver':
            $stmt = $pdo->prepare("UPDATE factures SET statut = 'approuvee' WHERE id IN ($placeholders)");
            $stmt->execute($ids);
            $affected = $stmt->rowCount();
            break;

        case 'en_attente':
            $stmt = $pdo->prepare("UPDATE factures SET statut = 'en_attente' WHERE id IN ($placeholders)");
            $stmt->execute($ids);
            $affected = $stmt->rowCount();
            break;

        case 'rejeter':
            $stmt = $pdo->prepare("UPDATE factures SET statut = 'rejetee' WHERE id IN ($placeholders)");
            $stmt->execute($ids);
            $affected = $stmt->rowCount();
            break;

        case 'supprimer':
            // Vérifier que l'utilisateur est admin pour la suppression
            if (!isAdmin()) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Permission refusée']);
                exit;
            }

            // Récupérer les fichiers à supprimer
            $stmt = $pdo->prepare("SELECT fichier FROM factures WHERE id IN ($placeholders)");
            $stmt->execute($ids);
            $fichiers = $stmt->fetchAll(PDO::FETCH_COLUMN);

            // Supprimer les factures
            $stmt = $pdo->prepare("DELETE FROM factures WHERE id IN ($placeholders)");
            $stmt->execute($ids);
            $affected = $stmt->rowCount();

            // Supprimer les fichiers physiques
            foreach ($fichiers as $fichier) {
                if ($fichier) {
                    $path = UPLOAD_DIR . '/factures/' . $fichier;
                    if (file_exists($path)) {
                        @unlink($path);
                    }
                }
            }
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Action non reconnue']);
            exit;
    }

    echo json_encode([
        'success' => true,
        'affected' => $affected,
        'ids_received' => $ids,
        'message' => "$affected facture(s) modifiée(s)"
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erreur serveur: ' . $e->getMessage()]);
}
