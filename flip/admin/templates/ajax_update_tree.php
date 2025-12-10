<?php
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
$items = $data['items'] ?? [];
$parentId = $data['parentId'] ?? null; // ID du nouveau parent (peut être null pour la racine, ou un ID de sous-cat)
$categorieId = $data['categorieId'] ?? null; // ID de la catégorie racine (obligatoire pour les sous-cat)

$pdo->beginTransaction();

try {
    if ($type === 'sous_categorie') {
        // Mise à jour de l'ordre et du parent pour les sous-catégories
        foreach ($items as $index => $itemId) {
            $stmt = $pdo->prepare("
                UPDATE sous_categories 
                SET ordre = ?, 
                    parent_id = ?, 
                    categorie_id = ? 
                WHERE id = ?
            ");
            // Si parentId est 'root', on met NULL
            $realParentId = ($parentId === 'root' || $parentId === '') ? null : $parentId;
            
            $stmt->execute([
                $index + 1,        // Nouvel ordre (1-based)
                $realParentId,     // Nouveau parent
                $categorieId,      // Catégorie racine (doit suivre si on change de catégorie)
                $itemId
            ]);
        }
    } 
    elseif ($type === 'materiaux') {
        // Mise à jour de l'ordre et du parent (sous_categorie_id) pour les matériaux
        foreach ($items as $index => $itemId) {
            $stmt = $pdo->prepare("
                UPDATE materiaux 
                SET ordre = ?, 
                    sous_categorie_id = ? 
                WHERE id = ?
            ");
            $stmt->execute([
                $index + 1,
                $parentId, // Ici parentId EST le sous_categorie_id
                $itemId
            ]);
        }
    }

    $pdo->commit();
    echo json_encode(['success' => true]);

} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
