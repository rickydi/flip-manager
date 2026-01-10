<?php
/**
 * API: Sauvegarder les lignes détaillées d'une facture
 * Persiste le breakdown par étape dans la base de données
 */

require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

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

if (!$input || empty($input['facture_id']) || !isset($input['lignes'])) {
    echo json_encode(['success' => false, 'error' => 'Données manquantes']);
    exit;
}

$factureId = (int)$input['facture_id'];
$lignes = $input['lignes'];

try {
    $pdo->beginTransaction();

    // Supprimer les anciennes lignes
    $stmt = $pdo->prepare("DELETE FROM facture_lignes WHERE facture_id = ?");
    $stmt->execute([$factureId]);

    // Insérer les nouvelles lignes
    $stmt = $pdo->prepare("
        INSERT INTO facture_lignes
        (facture_id, description, quantite, prix_unitaire, total, etape_id, etape_nom, raison)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");

    foreach ($lignes as $ligne) {
        $stmt->execute([
            $factureId,
            $ligne['description'] ?? '',
            $ligne['quantite'] ?? 1,
            $ligne['prix_unitaire'] ?? 0,
            $ligne['total'] ?? 0,
            $ligne['etape_id'] ?? null,
            $ligne['etape_nom'] ?? null,
            $ligne['raison'] ?? null
        ]);
    }

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => count($lignes) . ' lignes enregistrées',
        'count' => count($lignes)
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
