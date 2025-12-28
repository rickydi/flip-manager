<?php
/**
 * Budget Builder - API AJAX
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/ClaudeService.php';

header('Content-Type: application/json');

// Vérifier authentification
if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Non authentifié']);
    exit;
}

// S'assurer que les tables existent
try {
    $pdo->query("SELECT 1 FROM catalogue_items LIMIT 1");
    // Ajouter colonnes fournisseur et lien_achat si manquantes
    try {
        $pdo->query("SELECT fournisseur FROM catalogue_items LIMIT 1");
    } catch (Exception $e) {
        $pdo->exec("ALTER TABLE catalogue_items ADD COLUMN fournisseur VARCHAR(255) DEFAULT NULL");
        $pdo->exec("ALTER TABLE catalogue_items ADD COLUMN lien_achat VARCHAR(500) DEFAULT NULL");
    }
    // Ajouter colonne etape_id si manquante
    try {
        $pdo->query("SELECT etape_id FROM catalogue_items LIMIT 1");
    } catch (Exception $e) {
        $pdo->exec("ALTER TABLE catalogue_items ADD COLUMN etape_id INT DEFAULT NULL");
    }
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
            fournisseur VARCHAR(255) DEFAULT NULL,
            lien_achat VARCHAR(500) DEFAULT NULL,
            etape_id INT DEFAULT NULL,
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
    // Vérifier si les nouvelles colonnes existent
    try {
        $pdo->query("SELECT type FROM budget_items LIMIT 1");
    } catch (Exception $e) {
        $pdo->exec("ALTER TABLE budget_items ADD COLUMN type ENUM('folder','item') DEFAULT 'item'");
        $pdo->exec("ALTER TABLE budget_items ADD COLUMN parent_budget_id INT NULL");
    }
} catch (Exception $e) {
    // Créer la table budget_items avec structure complète
    $pdo->exec("
        CREATE TABLE budget_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            projet_id INT NOT NULL,
            catalogue_item_id INT NULL,
            parent_budget_id INT NULL,
            type ENUM('folder','item') DEFAULT 'item',
            nom VARCHAR(255) NOT NULL,
            prix DECIMAL(10,2) DEFAULT 0,
            quantite INT DEFAULT 1,
            ordre INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_projet (projet_id),
            INDEX idx_catalogue (catalogue_item_id),
            INDEX idx_parent (parent_budget_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

// Table des étapes
try {
    $pdo->query("SELECT 1 FROM budget_etapes LIMIT 1");
} catch (Exception $e) {
    $pdo->exec("
        CREATE TABLE budget_etapes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nom VARCHAR(255) NOT NULL,
            ordre INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

// Fonction helper pour ajouter récursivement un dossier et son contenu au panier
function addFolderContentsToPanier($pdo, $projetId, $catalogueFolderId, $parentBudgetId = null) {
    $addedCount = 0;

    // Récupérer les enfants de ce dossier
    $stmt = $pdo->prepare("SELECT * FROM catalogue_items WHERE parent_id = ? AND actif = 1 ORDER BY type DESC, ordre");
    $stmt->execute([$catalogueFolderId]);
    $children = $stmt->fetchAll();

    foreach ($children as $child) {
        // Obtenir le prochain ordre
        $stmt = $pdo->prepare("SELECT COALESCE(MAX(ordre), 0) + 1 FROM budget_items WHERE projet_id = ?");
        $stmt->execute([$projetId]);
        $ordre = $stmt->fetchColumn();

        if ($child['type'] === 'folder') {
            // Ajouter le sous-dossier
            $stmt = $pdo->prepare("
                INSERT INTO budget_items (projet_id, catalogue_item_id, parent_budget_id, type, nom, prix, quantite, ordre)
                VALUES (?, ?, ?, 'folder', ?, 0, 1, ?)
            ");
            $stmt->execute([$projetId, $child['id'], $parentBudgetId, $child['nom'], $ordre]);
            $newFolderId = $pdo->lastInsertId();
            $addedCount++;

            // Récursion pour le contenu du sous-dossier
            $addedCount += addFolderContentsToPanier($pdo, $projetId, $child['id'], $newFolderId);
        } else {
            // Ajouter l'item
            $stmt = $pdo->prepare("
                INSERT INTO budget_items (projet_id, catalogue_item_id, parent_budget_id, type, nom, prix, quantite, ordre)
                VALUES (?, ?, ?, 'item', ?, ?, ?, ?)
            ");
            $stmt->execute([
                $projetId,
                $child['id'],
                $parentBudgetId,
                $child['nom'],
                $child['prix'],
                $child['quantite_defaut'] ?? 1,
                $ordre
            ]);
            $addedCount++;
        }
    }

    return $addedCount;
}

// Fonction helper pour propager l'étape à tous les enfants récursivement
function propagateEtapeToChildren($pdo, $parentId, $etapeId) {
    $stmt = $pdo->prepare("SELECT id FROM catalogue_items WHERE parent_id = ? AND actif = 1");
    $stmt->execute([$parentId]);
    $children = $stmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($children as $childId) {
        $stmt = $pdo->prepare("UPDATE catalogue_items SET etape_id = ? WHERE id = ?");
        $stmt->execute([$etapeId, $childId]);
        // Récursion pour les sous-enfants
        propagateEtapeToChildren($pdo, $childId, $etapeId);
    }
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
            $etapeId = !empty($input['etape_id']) ? (int)$input['etape_id'] : null;
            $fournisseur = trim($input['fournisseur'] ?? '');
            $lien = trim($input['lien'] ?? '');

            if (empty($nom)) {
                throw new Exception('Le nom est requis');
            }

            // Si on a un parent, hériter de son étape si pas spécifié
            if ($parentId && !$etapeId) {
                $stmt = $pdo->prepare("SELECT etape_id FROM catalogue_items WHERE id = ?");
                $stmt->execute([$parentId]);
                $parentEtape = $stmt->fetchColumn();
                if ($parentEtape) {
                    $etapeId = (int)$parentEtape;
                }
            }

            // Récupérer le prochain ordre
            if ($parentId) {
                $stmt = $pdo->prepare("SELECT COALESCE(MAX(ordre), 0) + 1 FROM catalogue_items WHERE parent_id = ?");
                $stmt->execute([$parentId]);
            } else {
                $stmt = $pdo->query("SELECT COALESCE(MAX(ordre), 0) + 1 FROM catalogue_items WHERE parent_id IS NULL");
            }
            $ordre = $stmt->fetchColumn();

            $stmt = $pdo->prepare("INSERT INTO catalogue_items (parent_id, type, nom, prix, ordre, etape_id, fournisseur, lien_achat) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$parentId, $type, $nom, $prix, $ordre, $etapeId, $fournisseur ?: null, $lien ?: null]);

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

        case 'get_item':
            $id = (int)($input['id'] ?? 0);
            if (!$id) throw new Exception('ID requis');

            $stmt = $pdo->prepare("SELECT * FROM catalogue_items WHERE id = ?");
            $stmt->execute([$id]);
            $item = $stmt->fetch();

            if (!$item) throw new Exception('Item non trouvé');

            echo json_encode(['success' => true, 'item' => $item]);
            break;

        case 'update_item':
            $id = (int)($input['id'] ?? 0);
            if (!$id) throw new Exception('ID requis');

            $nom = trim($input['nom'] ?? '');
            $prix = (float)($input['prix'] ?? 0);
            $fournisseur = trim($input['fournisseur'] ?? '');
            $lienAchat = trim($input['lien_achat'] ?? '');
            $etapeId = !empty($input['etape_id']) ? (int)$input['etape_id'] : null;

            $stmt = $pdo->prepare("UPDATE catalogue_items SET nom = ?, prix = ?, fournisseur = ?, lien_achat = ?, etape_id = ? WHERE id = ?");
            $stmt->execute([$nom, $prix, $fournisseur ?: null, $lienAchat ?: null, $etapeId, $id]);

            echo json_encode(['success' => true, 'message' => 'Item mis à jour']);
            break;

        case 'duplicate_item':
            $id = (int)($input['id'] ?? 0);
            if (!$id) throw new Exception('ID requis');

            // Fonction récursive pour dupliquer un élément et ses enfants
            $duplicateRecursive = function($pdo, $itemId, $newParentId, $addCopySuffix = true) use (&$duplicateRecursive) {
                // Récupérer l'item original
                $stmt = $pdo->prepare("SELECT * FROM catalogue_items WHERE id = ?");
                $stmt->execute([$itemId]);
                $item = $stmt->fetch();

                if (!$item) return null;

                // Trouver le prochain ordre
                $stmt = $pdo->prepare("SELECT COALESCE(MAX(ordre), 0) + 1 FROM catalogue_items WHERE parent_id <=> ?");
                $stmt->execute([$newParentId]);
                $newOrdre = $stmt->fetchColumn();

                // Créer la copie
                $newNom = $addCopySuffix ? $item['nom'] . ' (copie)' : $item['nom'];
                $stmt = $pdo->prepare("
                    INSERT INTO catalogue_items (parent_id, type, nom, prix, fournisseur, lien_achat, etape_id, ordre, actif)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)
                ");
                $stmt->execute([
                    $newParentId,
                    $item['type'],
                    $newNom,
                    $item['prix'],
                    $item['fournisseur'],
                    $item['lien_achat'],
                    $item['etape_id'],
                    $newOrdre
                ]);

                $newId = $pdo->lastInsertId();

                // Si c'est un dossier, dupliquer les enfants récursivement
                if ($item['type'] === 'folder') {
                    $stmt = $pdo->prepare("SELECT id FROM catalogue_items WHERE parent_id = ? AND actif = 1 ORDER BY ordre");
                    $stmt->execute([$itemId]);
                    $children = $stmt->fetchAll(PDO::FETCH_COLUMN);

                    foreach ($children as $childId) {
                        $duplicateRecursive($pdo, $childId, $newId, false); // Pas de suffixe pour les enfants
                    }
                }

                return $newId;
            };

            // Récupérer l'item pour avoir son parent_id et etape_id
            $stmt = $pdo->prepare("SELECT parent_id, etape_id FROM catalogue_items WHERE id = ?");
            $stmt->execute([$id]);
            $item = $stmt->fetch();

            if (!$item) throw new Exception('Item non trouvé');

            $newId = $duplicateRecursive($pdo, $id, $item['parent_id'], true);

            // Propager l'étape du parent à tous les enfants du nouvel élément
            if ($newId && $item['etape_id']) {
                propagateEtapeToChildren($pdo, $newId, $item['etape_id']);
            }

            echo json_encode(['success' => true, 'new_id' => $newId]);
            break;

        case 'update_folder':
            $id = (int)($input['id'] ?? 0);
            if (!$id) throw new Exception('ID requis');

            $nom = trim($input['nom'] ?? '');
            $etapeId = !empty($input['etape_id']) ? (int)$input['etape_id'] : null;

            $stmt = $pdo->prepare("UPDATE catalogue_items SET nom = ?, etape_id = ? WHERE id = ?");
            $stmt->execute([$nom, $etapeId, $id]);

            echo json_encode(['success' => true, 'message' => 'Dossier mis à jour']);
            break;

        case 'move_catalogue_item':
            $id = (int)($input['id'] ?? 0);
            $targetId = (int)($input['target_id'] ?? 0);
            $position = $input['position'] ?? 'after'; // before, after, into
            $newParentId = isset($input['new_parent_id']) ? (int)$input['new_parent_id'] : null;

            if (!$id || !$targetId) throw new Exception('IDs requis');

            // Fonction pour trouver l'étape en remontant la chaîne des parents
            $findEtapeId = function($pdo, $itemId) use (&$findEtapeId) {
                $stmt = $pdo->prepare("SELECT parent_id, etape_id FROM catalogue_items WHERE id = ?");
                $stmt->execute([$itemId]);
                $item = $stmt->fetch();

                if (!$item) return null;
                if ($item['etape_id']) return $item['etape_id'];
                if ($item['parent_id']) return $findEtapeId($pdo, $item['parent_id']);
                return null;
            };

            // Récupérer les infos de la cible
            $stmt = $pdo->prepare("SELECT parent_id, ordre, etape_id FROM catalogue_items WHERE id = ?");
            $stmt->execute([$targetId]);
            $target = $stmt->fetch();

            if (!$target) throw new Exception('Cible non trouvée');

            // Trouver l'étape (de la cible ou en remontant ses parents)
            $etapeId = $target['etape_id'] ?: $findEtapeId($pdo, $target['parent_id']);

            if ($position === 'into') {
                // Déplacer dans le dossier cible - hériter de son étape
                $stmt = $pdo->prepare("SELECT COALESCE(MAX(ordre), 0) + 1 FROM catalogue_items WHERE parent_id = ?");
                $stmt->execute([$targetId]);
                $newOrdre = $stmt->fetchColumn();

                $stmt = $pdo->prepare("UPDATE catalogue_items SET parent_id = ?, ordre = ?, etape_id = ? WHERE id = ?");
                $stmt->execute([$targetId, $newOrdre, $etapeId, $id]);
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

                $stmt = $pdo->prepare("UPDATE catalogue_items SET parent_id = ?, ordre = ?, etape_id = ? WHERE id = ?");
                $stmt->execute([$parentId, $newOrdre, $etapeId, $id]);
            }

            // Propager l'étape à tous les enfants récursivement
            propagateEtapeToChildren($pdo, $id, $etapeId);

            echo json_encode(['success' => true]);
            break;

        case 'move_to_section':
            $id = (int)($input['id'] ?? 0);
            $etapeId = isset($input['etape_id']) ? ($input['etape_id'] ? (int)$input['etape_id'] : null) : null;

            if (!$id) throw new Exception('ID requis');

            // Déplacer l'élément vers la section (étape) - le mettre à la racine de la section
            $stmt = $pdo->prepare("UPDATE catalogue_items SET etape_id = ?, parent_id = NULL WHERE id = ?");
            $stmt->execute([$etapeId, $id]);

            // Propager l'étape à tous les enfants récursivement
            propagateEtapeToChildren($pdo, $id, $etapeId);

            echo json_encode(['success' => true]);
            break;

        // ================================
        // PANIER
        // ================================

        case 'check_panier_item':
            // Vérifie si un item existe déjà dans le panier et retourne sa quantité TOTALE
            $projetId = (int)($input['projet_id'] ?? 0);
            $catalogueItemId = (int)($input['catalogue_item_id'] ?? 0);

            if (!$projetId || !$catalogueItemId) {
                throw new Exception('Projet et item requis');
            }

            // Récupérer l'item du catalogue
            $stmt = $pdo->prepare("SELECT nom, prix FROM catalogue_items WHERE id = ? AND type = 'item'");
            $stmt->execute([$catalogueItemId]);
            $catalogueItem = $stmt->fetch();

            if (!$catalogueItem) {
                throw new Exception('Item non trouvé');
            }

            // Vérifier si l'item existe déjà dans le panier - SOMME de toutes les instances
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as count, COALESCE(SUM(quantite), 0) as total_quantite
                FROM budget_items
                WHERE projet_id = ? AND catalogue_item_id = ?
            ");
            $stmt->execute([$projetId, $catalogueItemId]);
            $result = $stmt->fetch();

            $exists = (int)$result['count'] > 0;
            $totalQuantite = (int)$result['total_quantite'];

            echo json_encode([
                'success' => true,
                'exists' => $exists,
                'current_quantity' => $totalQuantite,
                'item_name' => $catalogueItem['nom'],
                'item_price' => (float)$catalogueItem['prix']
            ]);
            break;

        case 'add_to_panier':
            $projetId = (int)($input['projet_id'] ?? 0);
            $catalogueItemId = (int)($input['catalogue_item_id'] ?? 0);
            $quantiteToAdd = (int)($input['quantite'] ?? 1);

            if (!$projetId || !$catalogueItemId) {
                throw new Exception('Projet et item requis');
            }

            if ($quantiteToAdd < 1) $quantiteToAdd = 1;

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
                // Ajouter la quantité spécifiée
                $stmt = $pdo->prepare("UPDATE budget_items SET quantite = quantite + ? WHERE id = ?");
                $stmt->execute([$quantiteToAdd, $existing['id']]);
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
                    $quantiteToAdd,
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

            // Récupérer le dossier du catalogue
            $stmt = $pdo->prepare("SELECT * FROM catalogue_items WHERE id = ? AND type = 'folder'");
            $stmt->execute([$folderId]);
            $folder = $stmt->fetch();

            if (!$folder) {
                throw new Exception('Dossier non trouvé');
            }

            // Obtenir le prochain ordre
            $stmt = $pdo->prepare("SELECT COALESCE(MAX(ordre), 0) + 1 FROM budget_items WHERE projet_id = ?");
            $stmt->execute([$projetId]);
            $ordre = $stmt->fetchColumn();

            // Ajouter le dossier principal au panier
            $stmt = $pdo->prepare("
                INSERT INTO budget_items (projet_id, catalogue_item_id, parent_budget_id, type, nom, prix, quantite, ordre)
                VALUES (?, ?, NULL, 'folder', ?, 0, 1, ?)
            ");
            $stmt->execute([$projetId, $folderId, $folder['nom'], $ordre]);
            $mainFolderId = $pdo->lastInsertId();

            // Ajouter récursivement le contenu du dossier
            $addedCount = 1 + addFolderContentsToPanier($pdo, $projetId, $folderId, $mainFolderId);

            echo json_encode(['success' => true, 'count' => $addedCount]);
            break;

        case 'remove_from_panier':
            $id = (int)($input['id'] ?? 0);
            if (!$id) throw new Exception('ID requis');

            // Supprimer récursivement les enfants d'abord
            $deleteChildren = function($pdo, $parentId) use (&$deleteChildren) {
                $stmt = $pdo->prepare("SELECT id FROM budget_items WHERE parent_budget_id = ?");
                $stmt->execute([$parentId]);
                $children = $stmt->fetchAll(PDO::FETCH_COLUMN);

                foreach ($children as $childId) {
                    $deleteChildren($pdo, $childId);
                    $stmt = $pdo->prepare("DELETE FROM budget_items WHERE id = ?");
                    $stmt->execute([$childId]);
                }
            };

            $deleteChildren($pdo, $id);

            // Supprimer l'élément lui-même
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

        case 'update_panier_price':
            $id = (int)($input['id'] ?? 0);
            $prix = (float)($input['prix'] ?? 0);

            if (!$id) throw new Exception('ID requis');

            $stmt = $pdo->prepare("UPDATE budget_items SET prix = ? WHERE id = ?");
            $stmt->execute([$prix, $id]);

            echo json_encode(['success' => true]);
            break;

        case 'clear_panier':
            $projetId = (int)($input['projet_id'] ?? 0);
            if (!$projetId) throw new Exception('Projet requis');

            $stmt = $pdo->prepare("DELETE FROM budget_items WHERE projet_id = ?");
            $stmt->execute([$projetId]);

            echo json_encode(['success' => true]);
            break;

        case 'get_panier':
            $projetId = (int)($input['projet_id'] ?? 0);
            if (!$projetId) throw new Exception('Projet requis');

            // Récupérer les items du panier avec leur étape
            $stmt = $pdo->prepare("
                SELECT
                    bi.id, bi.nom, bi.prix, bi.quantite, bi.type, bi.parent_budget_id,
                    bi.catalogue_item_id, bi.ordre,
                    COALESCE(ci.etape_id, 0) as etape_id,
                    COALESCE(be.nom, 'Sans étape') as etape_nom,
                    be.ordre as etape_ordre
                FROM budget_items bi
                LEFT JOIN catalogue_items ci ON bi.catalogue_item_id = ci.id
                LEFT JOIN budget_etapes be ON ci.etape_id = be.id
                WHERE bi.projet_id = ?
                ORDER BY COALESCE(be.ordre, 999), bi.parent_budget_id, bi.ordre
            ");
            $stmt->execute([$projetId]);
            $items = $stmt->fetchAll();

            // Construire l'arbre hiérarchique par étape
            $itemsById = [];
            foreach ($items as &$item) {
                $item['children'] = [];
                $itemsById[$item['id']] = &$item;
            }
            unset($item);

            $rootItems = [];
            foreach ($items as &$item) {
                if ($item['parent_budget_id'] && isset($itemsById[$item['parent_budget_id']])) {
                    $itemsById[$item['parent_budget_id']]['children'][] = &$item;
                } else {
                    $rootItems[] = &$item;
                }
            }
            unset($item);

            // Grouper par étape
            $sections = [];
            $etapeMap = [];

            // Récupérer les numéros d'étapes
            $etapesStmt = $pdo->query("SELECT id, nom, ordre FROM budget_etapes ORDER BY ordre");
            $etapes = $etapesStmt->fetchAll();
            $etapeNums = [];
            foreach ($etapes as $idx => $e) {
                $etapeNums[$e['id']] = $idx + 1;
            }

            foreach ($rootItems as $item) {
                $etapeId = $item['etape_id'] ?: 0;
                $etapeKey = 'etape_' . $etapeId;

                if (!isset($etapeMap[$etapeKey])) {
                    $etapeMap[$etapeKey] = [
                        'etape_id' => $etapeId ?: null,
                        'etape_nom' => $item['etape_nom'],
                        'etape_num' => isset($etapeNums[$etapeId]) ? $etapeNums[$etapeId] : null,
                        'etape_ordre' => $item['etape_ordre'] ?? 999,
                        'items' => []
                    ];
                }
                $etapeMap[$etapeKey]['items'][] = $item;
            }

            // Trier par ordre d'étape
            usort($etapeMap, function($a, $b) {
                return ($a['etape_ordre'] ?? 999) - ($b['etape_ordre'] ?? 999);
            });

            // Calculer le total
            $total = 0;
            foreach ($items as $item) {
                if ($item['type'] !== 'folder') {
                    $total += ($item['prix'] ?? 0) * ($item['quantite'] ?? 1);
                }
            }

            echo json_encode([
                'success' => true,
                'sections' => array_values($etapeMap),
                'total' => $total
            ]);
            break;

        // ================================
        // COMMANDE
        // ================================

        case 'get_order_items':
            $projetId = (int)($input['projet_id'] ?? 0);
            if (!$projetId) throw new Exception('Projet requis');

            // Ajouter colonne commande si manquante
            try {
                $pdo->query("SELECT commande FROM budget_items LIMIT 1");
            } catch (Exception $e) {
                $pdo->exec("ALTER TABLE budget_items ADD COLUMN commande TINYINT(1) DEFAULT 0");
            }

            // Récupérer tous les items du panier avec les infos du catalogue
            $stmt = $pdo->prepare("
                SELECT
                    bi.id, bi.nom, bi.prix, bi.quantite, bi.commande,
                    ci.fournisseur, ci.lien_achat
                FROM budget_items bi
                LEFT JOIN catalogue_items ci ON bi.catalogue_item_id = ci.id
                WHERE bi.projet_id = ? AND (bi.type = 'item' OR bi.type IS NULL)
                ORDER BY ci.fournisseur, bi.nom
            ");
            $stmt->execute([$projetId]);
            $items = $stmt->fetchAll();

            // Grouper par fournisseur
            $grouped = [];
            foreach ($items as $item) {
                $fournisseur = $item['fournisseur'] ?: 'Non spécifié';
                if (!isset($grouped[$fournisseur])) {
                    $grouped[$fournisseur] = [];
                }
                $grouped[$fournisseur][] = $item;
            }

            echo json_encode(['success' => true, 'grouped' => $grouped]);
            break;

        case 'toggle_order_item':
            $itemId = (int)($input['item_id'] ?? 0);
            $checked = (bool)($input['checked'] ?? false);

            if (!$itemId) throw new Exception('Item requis');

            // Ajouter colonne commande si manquante
            try {
                $pdo->query("SELECT commande FROM budget_items LIMIT 1");
            } catch (Exception $e) {
                $pdo->exec("ALTER TABLE budget_items ADD COLUMN commande TINYINT(1) DEFAULT 0");
            }

            $stmt = $pdo->prepare("UPDATE budget_items SET commande = ? WHERE id = ?");
            $stmt->execute([$checked ? 1 : 0, $itemId]);

            echo json_encode(['success' => true]);
            break;

        case 'get_fournisseurs':
            // Récupérer les fournisseurs de la table fournisseurs
            $fournisseurs = [];
            try {
                $stmt = $pdo->query("SELECT nom FROM fournisseurs WHERE actif = 1 ORDER BY nom");
                $fournisseurs = $stmt->fetchAll(PDO::FETCH_COLUMN);
            } catch (Exception $e) {
                // Table n'existe pas encore
            }

            // Ajouter aussi les fournisseurs déjà utilisés dans le catalogue
            $stmt = $pdo->query("SELECT DISTINCT fournisseur FROM catalogue_items WHERE fournisseur IS NOT NULL AND fournisseur != '' ORDER BY fournisseur");
            $catalogueFournisseurs = $stmt->fetchAll(PDO::FETCH_COLUMN);

            // Fusionner et enlever les doublons
            $fournisseurs = array_unique(array_merge($fournisseurs, $catalogueFournisseurs));
            sort($fournisseurs);

            echo json_encode(['success' => true, 'fournisseurs' => array_values($fournisseurs)]);
            break;

        // ================================
        // ÉTAPES
        // ================================

        case 'get_etapes':
            $stmt = $pdo->query("SELECT * FROM budget_etapes ORDER BY ordre, id");
            $etapes = $stmt->fetchAll();
            echo json_encode(['success' => true, 'etapes' => $etapes]);
            break;

        case 'get_catalogue_by_etape':
            // Fonction récursive pour récupérer les enfants d'un dossier
            $getChildren = function($pdo, $parentId) use (&$getChildren) {
                $stmt = $pdo->prepare("
                    SELECT * FROM catalogue_items
                    WHERE parent_id = ? AND actif = 1
                    ORDER BY type DESC, ordre, nom
                ");
                $stmt->execute([$parentId]);
                $children = $stmt->fetchAll();

                foreach ($children as &$child) {
                    if ($child['type'] === 'folder') {
                        $child['children'] = $getChildren($pdo, $child['id']);
                    }
                }
                return $children;
            };

            // Fonction pour récupérer le chemin du dossier parent (pour items sans dossier parent avec étape)
            $getParentPath = function($pdo, $parentId) {
                $path = [];
                while ($parentId) {
                    $stmt = $pdo->prepare("SELECT id, nom, parent_id FROM catalogue_items WHERE id = ?");
                    $stmt->execute([$parentId]);
                    $parent = $stmt->fetch();
                    if ($parent) {
                        array_unshift($path, $parent['nom']);
                        $parentId = $parent['parent_id'];
                    } else {
                        break;
                    }
                }
                return implode(' / ', $path);
            };

            // Récupérer toutes les étapes
            $stmt = $pdo->query("SELECT * FROM budget_etapes ORDER BY ordre, id");
            $etapes = $stmt->fetchAll();

            $grouped = [];

            // Pour chaque étape, récupérer les dossiers ET items avec cette étape
            $etapeNum = 0;
            foreach ($etapes as $etape) {
                $etapeNum++;

                // Récupérer tous les éléments (dossiers ET items) qui ont cette étape
                $stmt = $pdo->prepare("
                    SELECT * FROM catalogue_items
                    WHERE etape_id = ? AND actif = 1
                    ORDER BY type DESC, ordre, nom
                ");
                $stmt->execute([$etape['id']]);
                $items = $stmt->fetchAll();

                // Pour chaque élément, ajouter les enfants si c'est un dossier, ou le chemin si c'est un item
                foreach ($items as &$item) {
                    if ($item['type'] === 'folder') {
                        $item['children'] = $getChildren($pdo, $item['id']);
                    } else {
                        $item['folder_path'] = $getParentPath($pdo, $item['parent_id']);
                    }
                }

                if (!empty($items)) {
                    $grouped[] = [
                        'etape_id' => $etape['id'],
                        'etape_nom' => $etape['nom'],
                        'etape_num' => $etapeNum,
                        'items' => $items
                    ];
                }
            }

            // Éléments sans étape (qui n'ont pas de parent avec étape non plus)
            $stmt = $pdo->query("
                SELECT * FROM catalogue_items
                WHERE (etape_id IS NULL OR etape_id = 0) AND actif = 1 AND parent_id IS NULL
                ORDER BY type DESC, ordre, nom
            ");
            $noEtapeItems = $stmt->fetchAll();

            // Pour chaque élément sans étape, ajouter les enfants si c'est un dossier
            foreach ($noEtapeItems as &$item) {
                if ($item['type'] === 'folder') {
                    $item['children'] = $getChildren($pdo, $item['id']);
                }
            }

            if (!empty($noEtapeItems)) {
                $grouped[] = [
                    'etape_id' => null,
                    'etape_nom' => 'Non spécifié',
                    'items' => $noEtapeItems
                ];
            }

            echo json_encode(['success' => true, 'grouped' => $grouped]);
            break;

        case 'add_etape':
            $nom = trim($input['nom'] ?? '');
            if (empty($nom)) throw new Exception('Le nom est requis');

            $stmt = $pdo->query("SELECT COALESCE(MAX(ordre), 0) + 1 FROM budget_etapes");
            $ordre = $stmt->fetchColumn();

            $stmt = $pdo->prepare("INSERT INTO budget_etapes (nom, ordre) VALUES (?, ?)");
            $stmt->execute([$nom, $ordre]);

            echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
            break;

        case 'update_etape':
            $id = (int)($input['id'] ?? 0);
            $nom = trim($input['nom'] ?? '');
            if (!$id) throw new Exception('ID requis');
            if (empty($nom)) throw new Exception('Le nom est requis');

            $stmt = $pdo->prepare("UPDATE budget_etapes SET nom = ? WHERE id = ?");
            $stmt->execute([$nom, $id]);

            echo json_encode(['success' => true]);
            break;

        case 'delete_etape':
            $id = (int)($input['id'] ?? 0);
            if (!$id) throw new Exception('ID requis');

            // Retirer l'étape des items
            $stmt = $pdo->prepare("UPDATE catalogue_items SET etape_id = NULL WHERE etape_id = ?");
            $stmt->execute([$id]);

            $stmt = $pdo->prepare("DELETE FROM budget_etapes WHERE id = ?");
            $stmt->execute([$id]);

            echo json_encode(['success' => true]);
            break;

        case 'reorder_etapes':
            $ordre = $input['ordre'] ?? [];
            if (empty($ordre) || !is_array($ordre)) throw new Exception('Ordre requis');

            $stmt = $pdo->prepare("UPDATE budget_etapes SET ordre = ? WHERE id = ?");
            foreach ($ordre as $position => $id) {
                $stmt->execute([$position, (int)$id]);
            }

            echo json_encode(['success' => true]);
            break;

        case 'fix_section_etapes':
            // Corriger tous les items d'une section pour qu'ils aient la bonne étape
            $etapeId = isset($input['etape_id']) ? ($input['etape_id'] ? (int)$input['etape_id'] : null) : null;

            // Récupérer tous les items racine de cette section
            if ($etapeId) {
                $stmt = $pdo->prepare("SELECT id FROM catalogue_items WHERE etape_id = ? AND actif = 1");
                $stmt->execute([$etapeId]);
            } else {
                $stmt = $pdo->query("SELECT id FROM catalogue_items WHERE (etape_id IS NULL OR etape_id = 0) AND parent_id IS NULL AND actif = 1");
            }
            $rootItems = $stmt->fetchAll(PDO::FETCH_COLUMN);

            // Propager l'étape à tous les enfants de chaque item racine
            foreach ($rootItems as $itemId) {
                propagateEtapeToChildren($pdo, $itemId, $etapeId);
            }

            echo json_encode(['success' => true, 'fixed' => count($rootItems)]);
            break;

        case 'fetch_price_from_url':
            $url = trim($input['url'] ?? '');
            if (empty($url)) throw new Exception('URL requis');

            // Valider l'URL
            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                throw new Exception('URL invalide');
            }

            // Récupérer le contenu de la page
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                CURLOPT_HTTPHEADER => [
                    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language: fr-CA,fr;q=0.9,en;q=0.8'
                ]
            ]);
            $html = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200 || empty($html)) {
                throw new Exception('Impossible de charger la page (code: ' . $httpCode . ')');
            }

            $price = null;
            $method = 'regex';

            // Essayer d'abord avec l'IA Claude si configurée
            try {
                $claude = new ClaudeService($pdo);
                $aiResult = $claude->extractPriceFromHtml($html, $url);
                if ($aiResult['success'] && $aiResult['price']) {
                    $price = $aiResult['price'];
                    $method = 'ia';
                }
            } catch (Exception $e) {
                // IA non disponible, continuer avec regex
            }

            // Fallback: Patterns regex pour trouver les prix (sites canadiens courants)
            if ($price === null) {
                $patterns = [
                    // JSON-LD structured data (très fiable)
                    '/"price"\s*:\s*"?(\d+(?:[.,]\d{1,2})?)"?/i',
                    '/"lowPrice"\s*:\s*"?(\d+(?:[.,]\d{1,2})?)"?/i',
                    // Meta tags
                    '/property="product:price:amount"\s+content="(\d+(?:[.,]\d{1,2})?)"/i',
                    '/content="(\d+(?:[.,]\d{1,2})?)"\s+property="product:price:amount"/i',
                    '/name="product:price:amount"\s+content="(\d+(?:[.,]\d{1,2})?)"/i',
                    // Canac, Patrick Morin et autres sites québécois
                    '/(\d{1,3}(?:\s?\d{3})*[.,]\d{2})\s*\$\s*(?:\/|<)/i',
                    '/class="[^"]*(?:price|prix)[^"]*"[^>]*>[\s\n]*(\d{1,3}(?:[\s,]\d{3})*[.,]\d{2})\s*\$/im',
                    '/>(\d{1,3}[.,]\d{2})\s*\$\s*</i',
                    // Home Depot, Rona, etc.
                    '/data-price="(\d+(?:[.,]\d{1,2})?)"/i',
                    '/data-product-price="(\d+(?:[.,]\d{1,2})?)"/i',
                    // Prix avec $ canadien (format: 149,99 $ ou 1 234,56 $)
                    '/(\d{1,3}(?:[\s\x{00a0}]\d{3})*[.,]\d{2})\s*\$/u',
                    '/\$\s*(\d{1,3}(?:[\s\x{00a0}]\d{3})*[.,]\d{2})/u',
                    // Prix génériques avec classes
                    '/<[^>]*class="[^"]*(?:price|prix|cost|amount|regular-price|sale-price)[^"]*"[^>]*>[\s\S]*?(\d{1,3}(?:[\s,]\d{3})*[.,]\d{2})/i',
                    '/itemprop="price"[^>]*content="(\d+(?:[.,]\d{1,2})?)"/i',
                    // Dernier recours - tout nombre suivi de $
                    '/(\d{1,3}[.,]\d{2})\s*\$/i',
                ];

                foreach ($patterns as $pattern) {
                    if (preg_match($pattern, $html, $matches)) {
                        $priceStr = $matches[1];
                        // Nettoyer le prix
                        $priceStr = preg_replace('/\s/', '', $priceStr);
                        $priceStr = str_replace(',', '.', $priceStr);
                        $priceFloat = (float)$priceStr;

                        // Vérifier que c'est un prix raisonnable (entre 0.01 et 100000)
                        if ($priceFloat >= 0.01 && $priceFloat <= 100000) {
                            $price = $priceFloat;
                            break;
                        }
                    }
                }
            }

            if ($price === null) {
                throw new Exception('Prix non trouvé sur cette page');
            }

            echo json_encode(['success' => true, 'price' => $price]);
            break;

        case 'get_order_items_by_etape':
            $projetId = (int)($input['projet_id'] ?? 0);
            if (!$projetId) throw new Exception('Projet requis');

            // Ajouter colonne commande si manquante
            try {
                $pdo->query("SELECT commande FROM budget_items LIMIT 1");
            } catch (Exception $e) {
                $pdo->exec("ALTER TABLE budget_items ADD COLUMN commande TINYINT(1) DEFAULT 0");
            }

            // Récupérer tous les items du panier avec les infos du catalogue et l'étape
            $stmt = $pdo->prepare("
                SELECT
                    bi.id, bi.nom, bi.prix, bi.quantite, bi.commande,
                    ci.fournisseur, ci.lien_achat, ci.etape_id,
                    e.nom as etape_nom
                FROM budget_items bi
                LEFT JOIN catalogue_items ci ON bi.catalogue_item_id = ci.id
                LEFT JOIN budget_etapes e ON ci.etape_id = e.id
                WHERE bi.projet_id = ? AND (bi.type = 'item' OR bi.type IS NULL)
                ORDER BY e.ordre, e.nom, bi.nom
            ");
            $stmt->execute([$projetId]);
            $items = $stmt->fetchAll();

            // Grouper par étape
            $grouped = [];
            foreach ($items as $item) {
                $etape = $item['etape_nom'] ?: 'Non spécifié';
                if (!isset($grouped[$etape])) {
                    $grouped[$etape] = [];
                }
                $grouped[$etape][] = $item;
            }

            echo json_encode(['success' => true, 'grouped' => $grouped]);
            break;

        case 'get_order_items_combined':
            $projetId = (int)($input['projet_id'] ?? 0);
            $groupEtape = (bool)($input['group_etape'] ?? false);
            $groupFournisseur = (bool)($input['group_fournisseur'] ?? false);

            if (!$projetId) throw new Exception('Projet requis');

            // Ajouter colonne commande si manquante
            try {
                $pdo->query("SELECT commande FROM budget_items LIMIT 1");
            } catch (Exception $e) {
                $pdo->exec("ALTER TABLE budget_items ADD COLUMN commande TINYINT(1) DEFAULT 0");
            }

            // Récupérer tous les items du panier avec les infos du catalogue et l'étape
            $stmt = $pdo->prepare("
                SELECT
                    bi.id, bi.nom, bi.prix, bi.quantite, bi.commande,
                    ci.fournisseur, ci.lien_achat, ci.etape_id,
                    e.nom as etape_nom, e.ordre as etape_ordre
                FROM budget_items bi
                LEFT JOIN catalogue_items ci ON bi.catalogue_item_id = ci.id
                LEFT JOIN budget_etapes e ON ci.etape_id = e.id
                WHERE bi.projet_id = ? AND (bi.type = 'item' OR bi.type IS NULL)
                ORDER BY e.ordre, e.nom, ci.fournisseur, bi.nom
            ");
            $stmt->execute([$projetId]);
            $items = $stmt->fetchAll();

            $grouped = [];

            if ($groupEtape && $groupFournisseur) {
                // Grouper par étape puis par fournisseur
                foreach ($items as $item) {
                    $etape = $item['etape_nom'] ?: 'Non spécifié';
                    $fournisseur = $item['fournisseur'] ?: 'Non spécifié';

                    if (!isset($grouped[$etape])) {
                        $grouped[$etape] = ['subgroups' => []];
                    }
                    if (!isset($grouped[$etape]['subgroups'][$fournisseur])) {
                        $grouped[$etape]['subgroups'][$fournisseur] = [];
                    }
                    $grouped[$etape]['subgroups'][$fournisseur][] = $item;
                }
            } elseif ($groupEtape) {
                // Grouper par étape seulement
                foreach ($items as $item) {
                    $etape = $item['etape_nom'] ?: 'Non spécifié';
                    if (!isset($grouped[$etape])) {
                        $grouped[$etape] = ['items' => []];
                    }
                    $grouped[$etape]['items'][] = $item;
                }
            } elseif ($groupFournisseur) {
                // Grouper par fournisseur seulement
                foreach ($items as $item) {
                    $fournisseur = $item['fournisseur'] ?: 'Non spécifié';
                    if (!isset($grouped[$fournisseur])) {
                        $grouped[$fournisseur] = ['items' => []];
                    }
                    $grouped[$fournisseur]['items'][] = $item;
                }
            } else {
                // Pas de groupement - liste simple
                $grouped['Tous les items'] = ['items' => $items];
            }

            echo json_encode(['success' => true, 'grouped' => $grouped]);
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
