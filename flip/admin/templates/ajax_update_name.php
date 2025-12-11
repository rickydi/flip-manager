<?php
/**
 * AJAX: Update name inline
 * Permet de modifier les noms de catégories, groupes, sous-catégories et matériaux
 */

require_once '../../config.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

requireAdmin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Méthode non autorisée']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

if (!$data) {
    echo json_encode(['success' => false, 'message' => 'Données invalides']);
    exit;
}

$type = $data['type'] ?? '';
$id = (int)($data['id'] ?? 0);
$nom = trim($data['nom'] ?? '');

if (empty($nom) || !$id) {
    echo json_encode(['success' => false, 'message' => 'Données manquantes']);
    exit;
}

try {
    switch ($type) {
        case 'categorie':
            $stmt = $pdo->prepare("UPDATE categories SET nom = ? WHERE id = ?");
            $stmt->execute([$nom, $id]);
            break;

        case 'groupe':
            $stmt = $pdo->prepare("UPDATE category_groups SET nom = ? WHERE id = ?");
            $stmt->execute([$nom, $id]);
            break;

        case 'sous_categorie':
            $stmt = $pdo->prepare("UPDATE sous_categories SET nom = ? WHERE id = ?");
            $stmt->execute([$nom, $id]);
            break;

        case 'materiaux':
            $stmt = $pdo->prepare("UPDATE materiaux SET nom = ? WHERE id = ?");
            $stmt->execute([$nom, $id]);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Type inconnu']);
            exit;
    }

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
