<?php
/**
 * Budget Builder - API AJAX
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/auth.php';

header('Content-Type: application/json');

// Vérifier authentification
if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Non authentifié']);
    exit;
}

// S'assurer que les tables existent
try {
    $pdo->query("SELECT 1 FROM catalogue_items LIMIT 1");
} catch (Exception $e) {
    // Créer la table catalogue_items
    $pdo->exec("
        CREATE TABLE catalogue_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            parent_id INT NULL,
            type ENUM('folder', 'item') NOT NULL DEFAULT 'folder',
            nom VARCHAR(255) NOT NULL,
            prix DECIMAL(10,2) DEFAULT 0,
            quantite_defaut INT DEFAULT 1,
            ordre INT DEFAULT 0,
            actif TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_parent (parent_id),
            INDEX idx_type (type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

try {
    $pdo->query("SELECT 1 FROM budget_items LIMIT 1");
} catch (Exception $e) {
    // Créer la table budget_items
    $pdo->exec("
        CREATE TABLE budget_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            projet_id INT NOT NULL,
            catalogue_item_id INT NULL,
            nom VARCHAR(255) NOT NULL,
            prix DECIMAL(10,2) DEFAULT 0,
            quantite INT DEFAULT 1,
            ordre INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_projet (projet_id),
            INDEX idx_catalogue (catalogue_item_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

// Lire les données JSON
$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';

try {
    switch ($action) {

        // ================================
        // CATALOGUE
        // ================================

        case 'add_catalogue_item':
            $parentId = !empty($input['parent_id']) ? (int)$input['parent_id'] : null;
            $type = $input['type'] === 'item' ? 'item' : 'folder';
            $nom = trim($input['nom'] ?? '');
            $prix = (float)($input['prix'] ?? 0);

            if (empty($nom)) {
                throw new Exception('Le nom est requis');
            }

            // Récupérer le prochain ordre
            if ($parentId) {
                $stmt = $pdo->prepare("SELECT COALESCE(MAX(ordre), 0) + 1 FROM catalogue_items WHERE parent_id = ?");
                $stmt->execute([$parentId]);
            } else {
                $stmt = $pdo->query("SELECT COALESCE(MAX(ordre), 0) + 1 FROM catalogue_items WHERE parent_id IS NULL");
            }
            $ordre = $stmt->fetchColumn();

            $stmt = $pdo->prepare("INSERT INTO catalogue_items (parent_id, type, nom, prix, ordre) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$parentId, $type, $nom, $prix, $ordre]);

            echo json_encode([
                'success' => true,
                'id' => $pdo->lastInsertId(),
                'message' => 'Élément ajouté'
            ]);
            break;

        case 'update_catalogue_item':
            $id = (int)($input['id'] ?? 0);
            $nom = trim($input['nom'] ?? '');
            $prix = isset($input['prix']) ? (float)$input['prix'] : null;

            if (!$id) throw new Exception('ID requis');

            if (!empty($nom)) {
                $stmt = $pdo->prepare("UPDATE catalogue_items SET nom = ? WHERE id = ?");
                $stmt->execute([$nom, $id]);
            }

            if ($prix !== null) {
                $stmt = $pdo->prepare("UPDATE catalogue_items SET prix = ? WHERE id = ?");
                $stmt->execute([$prix, $id]);
            }

            echo json_encode(['success' => true, 'message' => 'Mis à jour']);
            break;

        case 'delete_catalogue_item':
            $id = (int)($input['id'] ?? 0);
            if (!$id) throw new Exception('ID requis');

            // La suppression en cascade est gérée par la FK
            $stmt = $pdo->prepare("DELETE FROM catalogue_items WHERE id = ?");
            $stmt->execute([$id]);

            echo json_encode(['success' => true, 'message' => 'Supprimé']);
            break;

        case 'reorder_catalogue':
            $items = $input['items'] ?? [];
            $parentId = $input['parent_id'] ?? null;

            foreach ($items as $ordre => $itemId) {
                $stmt = $pdo->prepare("UPDATE catalogue_items SET ordre = ?, parent_id = ? WHERE id = ?");
                $stmt->execute([$ordre, $parentId ?: null, $itemId]);
            }

            echo json_encode(['success' => true]);
            break;

        case 'move_catalogue_item':
            $id = (int)($input['id'] ?? 0);
            $targetId = (int)($input['target_id'] ?? 0);
            $position = $input['position'] ?? 'after'; // before, after, into
            $newParentId = isset($input['new_parent_id']) ? (int)$input['new_parent_id'] : null;

            if (!$id || !$targetId) throw new Exception('IDs requis');

            // Récupérer les infos de la cible
            $stmt = $pdo->prepare("SELECT parent_id, ordre FROM catalogue_items WHERE id = ?");
            $stmt->execute([$targetId]);
            $target = $stmt->fetch();

            if (!$target) throw new Exception('Cible non trouvée');

            if ($position === 'into') {
                // Déplacer dans le dossier cible
                $stmt = $pdo->prepare("SELECT COALESCE(MAX(ordre), 0) + 1 FROM catalogue_items WHERE parent_id = ?");
                $stmt->execute([$targetId]);
                $newOrdre = $stmt->fetchColumn();

                $stmt = $pdo->prepare("UPDATE catalogue_items SET parent_id = ?, ordre = ? WHERE id = ?");
                $stmt->execute([$targetId, $newOrdre, $id]);
            } else {
                // Déplacer avant ou après la cible (même parent)
                $parentId = $target['parent_id'];
                $targetOrdre = $target['ordre'];

                // Décaler les éléments pour faire de la place
                if ($position === 'before') {
                    $stmt = $pdo->prepare("UPDATE catalogue_items SET ordre = ordre + 1 WHERE parent_id <=> ? AND ordre >= ?");
                    $stmt->execute([$parentId, $targetOrdre]);
                    $newOrdre = $targetOrdre;
                } else {
                    $stmt = $pdo->prepare("UPDATE catalogue_items SET ordre = ordre + 1 WHERE parent_id <=> ? AND ordre > ?");
                    $stmt->execute([$parentId, $targetOrdre]);
                    $newOrdre = $targetOrdre + 1;
                }

                $stmt = $pdo->prepare("UPDATE catalogue_items SET parent_id = ?, ordre = ? WHERE id = ?");
                $stmt->execute([$parentId, $newOrdre, $id]);
            }

            echo json_encode(['success' => true]);
            break;

        // ================================
        // PANIER
        // ================================

        case 'add_to_panier':
            $projetId = (int)($input['projet_id'] ?? 0);
            $catalogueItemId = (int)($input['catalogue_item_id'] ?? 0);

            if (!$projetId || !$catalogueItemId) {
                throw new Exception('Projet et item requis');
            }

            // Récupérer l'item du catalogue
            $stmt = $pdo->prepare("SELECT * FROM catalogue_items WHERE id = ? AND type = 'item'");
            $stmt->execute([$catalogueItemId]);
            $catalogueItem = $stmt->fetch();

            if (!$catalogueItem) {
                throw new Exception('Item non trouvé');
            }

            // Vérifier si l'item existe déjà dans le panier
            $stmt = $pdo->prepare("SELECT id, quantite FROM budget_items WHERE projet_id = ? AND catalogue_item_id = ?");
            $stmt->execute([$projetId, $catalogueItemId]);
            $existing = $stmt->fetch();

            if ($existing) {
                // Incrémenter la quantité
                $stmt = $pdo->prepare("UPDATE budget_items SET quantite = quantite + 1 WHERE id = ?");
                $stmt->execute([$existing['id']]);
                $newId = $existing['id'];
            } else {
                // Ajouter nouveau
                $stmt = $pdo->prepare("SELECT COALESCE(MAX(ordre), 0) + 1 FROM budget_items WHERE projet_id = ?");
                $stmt->execute([$projetId]);
                $ordre = $stmt->fetchColumn();

                $stmt = $pdo->prepare("
                    INSERT INTO budget_items (projet_id, catalogue_item_id, nom, prix, quantite, ordre)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $projetId,
                    $catalogueItemId,
                    $catalogueItem['nom'],
                    $catalogueItem['prix'],
                    $catalogueItem['quantite_defaut'] ?? 1,
                    $ordre
                ]);
                $newId = $pdo->lastInsertId();
            }

            echo json_encode(['success' => true, 'id' => $newId]);
            break;

        case 'add_folder_to_panier':
            $projetId = (int)($input['projet_id'] ?? 0);
            $folderId = (int)($input['folder_id'] ?? 0);

            if (!$projetId || !$folderId) {
                throw new Exception('Projet et dossier requis');
            }

            // Fonction récursive pour récupérer tous les items d'un dossier
            function getAllItemsInFolder($pdo, $folderId) {
                $items = [];

                $stmt = $pdo->prepare("SELECT * FROM catalogue_items WHERE parent_id = ? AND actif = 1");
                $stmt->execute([$folderId]);
                $children = $stmt->fetchAll();

                foreach ($children as $child) {
                    if ($child['type'] === 'item') {
                        $items[] = $child;
                    } else {
                        // Récursion pour les sous-dossiers
                        $items = array_merge($items, getAllItemsInFolder($pdo, $child['id']));
                    }
                }

                return $items;
            }

            $items = getAllItemsInFolder($pdo, $folderId);

            if (empty($items)) {
                throw new Exception('Aucun item dans ce dossier');
            }

            $addedCount = 0;
            foreach ($items as $catalogueItem) {
                // Vérifier si l'item existe déjà dans le panier
                $stmt = $pdo->prepare("SELECT id, quantite FROM budget_items WHERE projet_id = ? AND catalogue_item_id = ?");
                $stmt->execute([$projetId, $catalogueItem['id']]);
                $existing = $stmt->fetch();

                if ($existing) {
                    // Incrémenter la quantité
                    $stmt = $pdo->prepare("UPDATE budget_items SET quantite = quantite + 1 WHERE id = ?");
                    $stmt->execute([$existing['id']]);
                } else {
                    // Ajouter nouveau
                    $stmt = $pdo->prepare("SELECT COALESCE(MAX(ordre), 0) + 1 FROM budget_items WHERE projet_id = ?");
                    $stmt->execute([$projetId]);
                    $ordre = $stmt->fetchColumn();

                    $stmt = $pdo->prepare("
                        INSERT INTO budget_items (projet_id, catalogue_item_id, nom, prix, quantite, ordre)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $projetId,
                        $catalogueItem['id'],
                        $catalogueItem['nom'],
                        $catalogueItem['prix'],
                        $catalogueItem['quantite_defaut'] ?? 1,
                        $ordre
                    ]);
                }
                $addedCount++;
            }

            echo json_encode(['success' => true, 'count' => $addedCount]);
            break;

        case 'remove_from_panier':
            $id = (int)($input['id'] ?? 0);
            if (!$id) throw new Exception('ID requis');

            $stmt = $pdo->prepare("DELETE FROM budget_items WHERE id = ?");
            $stmt->execute([$id]);

            echo json_encode(['success' => true]);
            break;

        case 'update_panier_quantity':
            $id = (int)($input['id'] ?? 0);
            $quantite = max(1, (int)($input['quantite'] ?? 1));

            if (!$id) throw new Exception('ID requis');

            $stmt = $pdo->prepare("UPDATE budget_items SET quantite = ? WHERE id = ?");
            $stmt->execute([$quantite, $id]);

            echo json_encode(['success' => true]);
            break;

        case 'clear_panier':
            $projetId = (int)($input['projet_id'] ?? 0);
            if (!$projetId) throw new Exception('Projet requis');

            $stmt = $pdo->prepare("DELETE FROM budget_items WHERE projet_id = ?");
            $stmt->execute([$projetId]);

            echo json_encode(['success' => true]);
            break;

        default:
            throw new Exception('Action non reconnue: ' . $action);
    }

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
