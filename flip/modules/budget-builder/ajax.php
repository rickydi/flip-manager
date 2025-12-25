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
            // Récupérer la liste unique des fournisseurs
            $stmt = $pdo->query("SELECT DISTINCT fournisseur FROM catalogue_items WHERE fournisseur IS NOT NULL AND fournisseur != '' ORDER BY fournisseur");
            $fournisseurs = $stmt->fetchAll(PDO::FETCH_COLUMN);

            echo json_encode(['success' => true, 'fournisseurs' => $fournisseurs]);
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
            // Fonction pour récupérer le chemin du dossier parent
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

            // Pour chaque étape, récupérer les items (garder le numéro d'ordre même si vide)
            $etapeNum = 0;
            foreach ($etapes as $etape) {
                $etapeNum++;
                $stmt = $pdo->prepare("
                    SELECT * FROM catalogue_items
                    WHERE etape_id = ? AND actif = 1
                    ORDER BY type DESC, ordre, nom
                ");
                $stmt->execute([$etape['id']]);
                $items = $stmt->fetchAll();

                // Ajouter le chemin du dossier parent pour chaque item
                foreach ($items as &$item) {
                    $item['folder_path'] = $getParentPath($pdo, $item['parent_id']);
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

            // Items sans étape
            $stmt = $pdo->query("
                SELECT * FROM catalogue_items
                WHERE (etape_id IS NULL OR etape_id = 0) AND actif = 1
                ORDER BY type DESC, ordre, nom
            ");
            $noEtapeItems = $stmt->fetchAll();

            // Ajouter le chemin du dossier parent pour chaque item sans étape
            foreach ($noEtapeItems as &$item) {
                $item['folder_path'] = $getParentPath($pdo, $item['parent_id']);
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

        default:
            throw new Exception('Action non reconnue: ' . $action);
    }

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
