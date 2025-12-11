<?php
/**
 * Gestion des templates de budgets - Admin
 * Sous-catégories imbriquées et Matériaux
 * Flip Manager
 */

require_once '../../config.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

requireAdmin();

$pageTitle = 'Templates Budgets';
$errors = [];
$success = '';

// ========================================
// AUTO-MIGRATION: Table des groupes
// ========================================
try {
    $pdo->query("SELECT 1 FROM category_groups LIMIT 1");
} catch (Exception $e) {
    $pdo->exec("
        CREATE TABLE category_groups (
            id INT AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(50) NOT NULL UNIQUE,
            nom VARCHAR(100) NOT NULL,
            ordre INT DEFAULT 0,
            actif TINYINT(1) DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Insérer les groupes par défaut (Structure Québec)
    $defaultGroups = [
        ['structure', 'Structure', 1],
        ['ventilation', 'Ventilation', 2],
        ['plomberie', 'Plomberie', 3],
        ['electricite', 'Électricité', 4],
        ['fenetres', 'Fenêtres', 5],
        ['exterieur', 'Finition extérieur', 6],
        ['finition', 'Finition intérieure', 7],
        ['ebenisterie', 'Ébénisterie', 8],
        ['sdb', 'Salle de bain', 9],
        ['autre', 'Autre', 10]
    ];
    $stmt = $pdo->prepare("INSERT INTO category_groups (code, nom, ordre) VALUES (?, ?, ?)");
    foreach ($defaultGroups as $g) {
        try {
            $stmt->execute($g);
        } catch (Exception $e) {
            // Ignorer si existe déjà
        }
    }
}

// Récupérer les groupes dynamiques
$stmt = $pdo->query("SELECT * FROM category_groups WHERE actif = 1 ORDER BY ordre, nom");
$groupes = $stmt->fetchAll();
$groupeLabels = [];
foreach ($groupes as $g) {
    $groupeLabels[$g['code']] = $g['nom'];
}

// Catégorie sélectionnée
$categorieId = (int)($_GET['categorie'] ?? 0);

// Vérifier si la colonne parent_id existe, sinon l'ajouter
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM sous_categories LIKE 'parent_id'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE sous_categories ADD COLUMN parent_id INT NULL AFTER categorie_id");
        $pdo->exec("ALTER TABLE sous_categories ADD INDEX idx_parent (parent_id)");
    }
} catch (Exception $e) {
    // Ignorer
}

// Vérifier si la colonne quantite_defaut existe dans materiaux
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM materiaux LIKE 'quantite_defaut'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE materiaux ADD COLUMN quantite_defaut INT DEFAULT 1 AFTER prix_defaut");
    }
} catch (Exception $e) {
    // Ignorer
}

// Traitement des actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token de sécurité invalide.';
    } else {
        $action = $_POST['action'] ?? '';

        // === CATÉGORIES ===
        if ($action === 'ajouter_categorie') {
            $nom = trim($_POST['nom'] ?? '');
            $groupe = $_POST['groupe'] ?? 'autre';

            if (empty($nom)) {
                $errors[] = 'Le nom est requis.';
            } else {
                $stmt = $pdo->prepare("SELECT MAX(ordre) FROM categories WHERE groupe = ?");
                $stmt->execute([$groupe]);
                $maxOrdre = $stmt->fetchColumn() ?: 0;

                $stmt = $pdo->prepare("INSERT INTO categories (nom, groupe, ordre) VALUES (?, ?, ?)");
                if ($stmt->execute([$nom, $groupe, $maxOrdre + 1])) {
                    $newCatId = $pdo->lastInsertId();
                    setFlashMessage('success', 'Catégorie ajoutée!');
                    redirect('/admin/templates/liste.php?categorie=' . $newCatId);
                }
            }
        }

        elseif ($action === 'modifier_categorie') {
            $id = (int)($_POST['id'] ?? 0);
            $nom = trim($_POST['nom'] ?? '');
            $groupe = $_POST['groupe'] ?? 'autre';

            if ($id && !empty($nom)) {
                $stmt = $pdo->prepare("UPDATE categories SET nom = ?, groupe = ? WHERE id = ?");
                if ($stmt->execute([$nom, $groupe, $id])) {
                    setFlashMessage('success', 'Catégorie modifiée!');
                    redirect('/admin/templates/liste.php?categorie=' . $id);
                }
            }
        }

        elseif ($action === 'supprimer_categorie') {
            $id = (int)($_POST['id'] ?? 0);

            if ($id) {
                // Supprimer les sous-catégories et matériaux associés
                $stmt = $pdo->prepare("SELECT id FROM sous_categories WHERE categorie_id = ?");
                $stmt->execute([$id]);
                foreach ($stmt->fetchAll() as $sc) {
                    supprimerSousCategorieRecursif($pdo, $sc['id']);
                }

                // Supprimer la catégorie
                $pdo->prepare("DELETE FROM categories WHERE id = ?")->execute([$id]);
                setFlashMessage('success', 'Catégorie supprimée!');
                redirect('/admin/templates/liste.php');
            }
        }

        // Monter une catégorie
        elseif ($action === 'monter_categorie') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id) {
                $stmt = $pdo->prepare("SELECT groupe, ordre FROM categories WHERE id = ?");
                $stmt->execute([$id]);
                $cat = $stmt->fetch();
                if ($cat) {
                    // Trouver la catégorie précédente dans le même groupe
                    $stmt = $pdo->prepare("SELECT id, ordre FROM categories WHERE groupe = ? AND ordre < ? ORDER BY ordre DESC LIMIT 1");
                    $stmt->execute([$cat['groupe'], $cat['ordre']]);
                    $prev = $stmt->fetch();
                    if ($prev) {
                        // Échanger les ordres
                        $pdo->prepare("UPDATE categories SET ordre = ? WHERE id = ?")->execute([$prev['ordre'], $id]);
                        $pdo->prepare("UPDATE categories SET ordre = ? WHERE id = ?")->execute([$cat['ordre'], $prev['id']]);
                    }
                }
                redirect('/admin/templates/liste.php' . ($categorieId ? '?categorie=' . $categorieId : ''));
            }
        }

        // Descendre une catégorie
        elseif ($action === 'descendre_categorie') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id) {
                $stmt = $pdo->prepare("SELECT groupe, ordre FROM categories WHERE id = ?");
                $stmt->execute([$id]);
                $cat = $stmt->fetch();
                if ($cat) {
                    // Trouver la catégorie suivante dans le même groupe
                    $stmt = $pdo->prepare("SELECT id, ordre FROM categories WHERE groupe = ? AND ordre > ? ORDER BY ordre ASC LIMIT 1");
                    $stmt->execute([$cat['groupe'], $cat['ordre']]);
                    $next = $stmt->fetch();
                    if ($next) {
                        // Échanger les ordres
                        $pdo->prepare("UPDATE categories SET ordre = ? WHERE id = ?")->execute([$next['ordre'], $id]);
                        $pdo->prepare("UPDATE categories SET ordre = ? WHERE id = ?")->execute([$cat['ordre'], $next['id']]);
                    }
                }
                redirect('/admin/templates/liste.php' . ($categorieId ? '?categorie=' . $categorieId : ''));
            }
        }

        // === GROUPES ===
        elseif ($action === 'ajouter_groupe') {
            $nom = trim($_POST['nom'] ?? '');
            $code = trim($_POST['code'] ?? '');

            if (empty($nom)) {
                $errors[] = 'Le nom est requis.';
            } else {
                // Générer un code si non fourni
                if (empty($code)) {
                    $code = strtolower(preg_replace('/[^a-zA-Z0-9]/', '_', $nom));
                }

                $stmt = $pdo->prepare("SELECT MAX(ordre) FROM category_groups");
                $stmt->execute();
                $maxOrdre = $stmt->fetchColumn() ?: 0;

                try {
                    $stmt = $pdo->prepare("INSERT INTO category_groups (code, nom, ordre) VALUES (?, ?, ?)");
                    $stmt->execute([$code, $nom, $maxOrdre + 1]);
                    setFlashMessage('success', 'Groupe ajouté!');
                } catch (Exception $e) {
                    $errors[] = 'Ce code existe déjà.';
                }
                redirect('/admin/templates/liste.php');
            }
        }

        elseif ($action === 'modifier_groupe') {
            $id = (int)($_POST['id'] ?? 0);
            $nom = trim($_POST['nom'] ?? '');

            if ($id && !empty($nom)) {
                $stmt = $pdo->prepare("UPDATE category_groups SET nom = ? WHERE id = ?");
                $stmt->execute([$nom, $id]);
                setFlashMessage('success', 'Groupe modifié!');
                redirect('/admin/templates/liste.php');
            }
        }

        elseif ($action === 'supprimer_groupe') {
            $id = (int)($_POST['id'] ?? 0);

            if ($id) {
                // Récupérer le code du groupe
                $stmt = $pdo->prepare("SELECT code FROM category_groups WHERE id = ?");
                $stmt->execute([$id]);
                $code = $stmt->fetchColumn();

                // Déplacer les catégories vers "autre"
                if ($code) {
                    $pdo->prepare("UPDATE categories SET groupe = 'autre' WHERE groupe = ?")->execute([$code]);
                }

                $pdo->prepare("DELETE FROM category_groups WHERE id = ?")->execute([$id]);
                setFlashMessage('success', 'Groupe supprimé! Les catégories ont été déplacées vers "Autre".');
                redirect('/admin/templates/liste.php');
            }
        }

        // Monter un groupe
        elseif ($action === 'monter_groupe') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id) {
                $stmt = $pdo->prepare("SELECT ordre FROM category_groups WHERE id = ?");
                $stmt->execute([$id]);
                $ordre = $stmt->fetchColumn();

                $stmt = $pdo->prepare("SELECT id, ordre FROM category_groups WHERE ordre < ? ORDER BY ordre DESC LIMIT 1");
                $stmt->execute([$ordre]);
                $prev = $stmt->fetch();
                if ($prev) {
                    $pdo->prepare("UPDATE category_groups SET ordre = ? WHERE id = ?")->execute([$prev['ordre'], $id]);
                    $pdo->prepare("UPDATE category_groups SET ordre = ? WHERE id = ?")->execute([$ordre, $prev['id']]);
                }
                redirect('/admin/templates/liste.php');
            }
        }

        // Descendre un groupe
        elseif ($action === 'descendre_groupe') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id) {
                $stmt = $pdo->prepare("SELECT ordre FROM category_groups WHERE id = ?");
                $stmt->execute([$id]);
                $ordre = $stmt->fetchColumn();

                $stmt = $pdo->prepare("SELECT id, ordre FROM category_groups WHERE ordre > ? ORDER BY ordre ASC LIMIT 1");
                $stmt->execute([$ordre]);
                $next = $stmt->fetch();
                if ($next) {
                    $pdo->prepare("UPDATE category_groups SET ordre = ? WHERE id = ?")->execute([$next['ordre'], $id]);
                    $pdo->prepare("UPDATE category_groups SET ordre = ? WHERE id = ?")->execute([$ordre, $next['id']]);
                }
                redirect('/admin/templates/liste.php');
            }
        }

        // === SOUS-CATÉGORIES ===
        elseif ($action === 'ajouter_sous_categorie') {
            $nom = trim($_POST['nom'] ?? '');
            $catId = (int)($_POST['categorie_id'] ?? 0);
            $parentId = !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;

            if (empty($nom) || !$catId) {
                $errors[] = 'Données invalides.';
            } else {
                $stmt = $pdo->prepare("SELECT MAX(ordre) FROM sous_categories WHERE categorie_id = ? AND (parent_id = ? OR (parent_id IS NULL AND ? IS NULL))");
                $stmt->execute([$catId, $parentId, $parentId]);
                $maxOrdre = $stmt->fetchColumn() ?: 0;

                $stmt = $pdo->prepare("INSERT INTO sous_categories (categorie_id, parent_id, nom, ordre) VALUES (?, ?, ?, ?)");
                if ($stmt->execute([$catId, $parentId, $nom, $maxOrdre + 1])) {
                    setFlashMessage('success', 'Sous-catégorie ajoutée!');
                    redirect('/admin/templates/liste.php?categorie=' . $catId);
                }
            }
        }

        elseif ($action === 'modifier_sous_categorie') {
            $id = (int)($_POST['id'] ?? 0);
            $nom = trim($_POST['nom'] ?? '');

            if ($id && !empty($nom)) {
                $stmt = $pdo->prepare("UPDATE sous_categories SET nom = ? WHERE id = ?");
                if ($stmt->execute([$nom, $id])) {
                    setFlashMessage('success', 'Sous-catégorie modifiée!');
                    redirect('/admin/templates/liste.php?categorie=' . $categorieId);
                }
            }
        }

        elseif ($action === 'supprimer_sous_categorie') {
            $id = (int)($_POST['id'] ?? 0);

            if ($id) {
                // Supprimer récursivement les enfants et matériaux
                supprimerSousCategorieRecursif($pdo, $id);
                setFlashMessage('success', 'Sous-catégorie supprimée!');
                redirect('/admin/templates/liste.php?categorie=' . $categorieId);
            }
        }

        // === MATÉRIAUX ===
        elseif ($action === 'ajouter_materiau') {
            $nom = trim($_POST['nom'] ?? '');
            $scId = (int)($_POST['sous_categorie_id'] ?? 0);
            $prix = (float)str_replace([' ', ',', '$'], ['', '.', ''], $_POST['prix_defaut'] ?? '0');
            $quantite = max(1, (int)($_POST['quantite_defaut'] ?? 1));

            if (empty($nom) || !$scId) {
                $errors[] = 'Données invalides.';
            } else {
                $stmt = $pdo->prepare("SELECT MAX(ordre) FROM materiaux WHERE sous_categorie_id = ?");
                $stmt->execute([$scId]);
                $maxOrdre = $stmt->fetchColumn() ?: 0;

                $stmt = $pdo->prepare("INSERT INTO materiaux (sous_categorie_id, nom, prix_defaut, quantite_defaut, ordre) VALUES (?, ?, ?, ?, ?)");
                if ($stmt->execute([$scId, $nom, $prix, $quantite, $maxOrdre + 1])) {
                    setFlashMessage('success', 'Matériau ajouté!');
                    redirect('/admin/templates/liste.php?categorie=' . $categorieId);
                }
            }
        }

        elseif ($action === 'modifier_materiau') {
            $id = (int)($_POST['id'] ?? 0);
            $nom = trim($_POST['nom'] ?? '');
            $prix = (float)str_replace([' ', ',', '$'], ['', '.', ''], $_POST['prix_defaut'] ?? '0');
            $quantite = max(1, (int)($_POST['quantite_defaut'] ?? 1));

            if ($id && !empty($nom)) {
                $stmt = $pdo->prepare("UPDATE materiaux SET nom = ?, prix_defaut = ?, quantite_defaut = ? WHERE id = ?");
                if ($stmt->execute([$nom, $prix, $quantite, $id])) {
                    setFlashMessage('success', 'Matériau modifié!');
                    redirect('/admin/templates/liste.php?categorie=' . $categorieId);
                }
            }
        }

        elseif ($action === 'supprimer_materiau') {
            $id = (int)($_POST['id'] ?? 0);

            if ($id) {
                $stmt = $pdo->prepare("DELETE FROM materiaux WHERE id = ?");
                if ($stmt->execute([$id])) {
                    setFlashMessage('success', 'Matériau supprimé!');
                    redirect('/admin/templates/liste.php?categorie=' . $categorieId);
                }
            }
        }

        // === DUPLICATION (KITS) ===
        elseif ($action === 'dupliquer_sous_categorie') {
            $id = (int)($_POST['id'] ?? 0);

            if ($id) {
                try {
                    dupliquerSousCategorieRecursif($pdo, $id);
                    setFlashMessage('success', 'Kit dupliqué avec succès!');
                } catch (Exception $e) {
                    $errors[] = 'Erreur lors de la duplication: ' . $e->getMessage();
                }
                redirect('/admin/templates/liste.php?categorie=' . $categorieId);
            }
        }
    }
}

/**
 * Dupliquer une sous-catégorie et tout son contenu (Récursif)
 * @param PDO $pdo
 * @param int $sourceId ID de la sous-catégorie à copier
 * @param int|null $newParentId ID du nouveau parent (si recursif), sinon NULL (copie au même niveau)
 */
function dupliquerSousCategorieRecursif($pdo, $sourceId, $newParentId = null) {
    // 1. Récupérer la sous-catégorie source
    $stmt = $pdo->prepare("SELECT * FROM sous_categories WHERE id = ?");
    $stmt->execute([$sourceId]);
    $source = $stmt->fetch();

    if (!$source) return;

    // 2. Créer la nouvelle sous-catégorie
    // Si c'est le root call ($newParentId null), on prend le même parent que la source
    $parentIdToUse = ($newParentId !== null) ? $newParentId : $source['parent_id'];
    
    // Si c'est le root call, on ajoute " - Copie" au nom
    $newNom = ($newParentId === null) ? $source['nom'] . ' - Copie' : $source['nom'];

    $stmt = $pdo->prepare("INSERT INTO sous_categories (categorie_id, parent_id, nom, ordre, actif) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([
        $source['categorie_id'],
        $parentIdToUse,
        $newNom,
        $source['ordre'] + 1, // On le met juste après
        $source['actif']
    ]);
    $newId = $pdo->lastInsertId();

    // 3. Copier les matériaux
    $stmt = $pdo->prepare("SELECT * FROM materiaux WHERE sous_categorie_id = ?");
    $stmt->execute([$sourceId]);
    $materiaux = $stmt->fetchAll();

    $stmtInsertMat = $pdo->prepare("INSERT INTO materiaux (sous_categorie_id, nom, prix_defaut, quantite_defaut, ordre, actif) VALUES (?, ?, ?, ?, ?, ?)");
    foreach ($materiaux as $mat) {
        $stmtInsertMat->execute([
            $newId,
            $mat['nom'],
            $mat['prix_defaut'],
            $mat['quantite_defaut'],
            $mat['ordre'],
            $mat['actif'] ?? 1
        ]);
    }

    // 4. Copier les enfants (Récursion)
    $stmt = $pdo->prepare("SELECT id FROM sous_categories WHERE parent_id = ?");
    $stmt->execute([$sourceId]);
    $enfants = $stmt->fetchAll();

    foreach ($enfants as $enfant) {
        dupliquerSousCategorieRecursif($pdo, $enfant['id'], $newId);
    }
}

/**
 * Supprimer une sous-catégorie et tous ses enfants récursivement
 */
function supprimerSousCategorieRecursif($pdo, $id) {
    // D'abord supprimer les enfants
    $stmt = $pdo->prepare("SELECT id FROM sous_categories WHERE parent_id = ?");
    $stmt->execute([$id]);
    $enfants = $stmt->fetchAll();
    foreach ($enfants as $enfant) {
        supprimerSousCategorieRecursif($pdo, $enfant['id']);
    }

    // Supprimer les matériaux
    $pdo->prepare("DELETE FROM materiaux WHERE sous_categorie_id = ?")->execute([$id]);

    // Supprimer la sous-catégorie
    $pdo->prepare("DELETE FROM sous_categories WHERE id = ?")->execute([$id]);
}

/**
 * Récupérer les sous-catégories de façon récursive
 */
function getSousCategoriesRecursif($pdo, $categorieId, $parentId = null) {
    if ($parentId === null) {
        $stmt = $pdo->prepare("
            SELECT sc.*
            FROM sous_categories sc
            WHERE sc.categorie_id = ? AND sc.parent_id IS NULL AND sc.actif = 1
            ORDER BY sc.ordre, sc.nom
        ");
        $stmt->execute([$categorieId]);
    } else {
        $stmt = $pdo->prepare("
            SELECT sc.*
            FROM sous_categories sc
            WHERE sc.categorie_id = ? AND sc.parent_id = ? AND sc.actif = 1
            ORDER BY sc.ordre, sc.nom
        ");
        $stmt->execute([$categorieId, $parentId]);
    }

    $sousCategories = $stmt->fetchAll();

    foreach ($sousCategories as &$sc) {
        // Récupérer les matériaux
        $stmt = $pdo->prepare("SELECT * FROM materiaux WHERE sous_categorie_id = ? AND actif = 1 ORDER BY ordre, nom");
        $stmt->execute([$sc['id']]);
        $sc['materiaux'] = $stmt->fetchAll();

        // Récupérer les enfants récursivement
        $sc['enfants'] = getSousCategoriesRecursif($pdo, $categorieId, $sc['id']);
    }

    return $sousCategories;
}

// Récupérer les catégories
$stmt = $pdo->query("SELECT * FROM categories ORDER BY groupe, ordre, nom");
$categories = $stmt->fetchAll();

// Récupérer la catégorie sélectionnée
$categorieSelectionnee = null;
if ($categorieId) {
    $stmt = $pdo->prepare("SELECT * FROM categories WHERE id = ?");
    $stmt->execute([$categorieId]);
    $categorieSelectionnee = $stmt->fetch();
}

// Récupérer les sous-catégories de façon récursive
$sousCategories = [];
if ($categorieId) {
    $sousCategories = getSousCategoriesRecursif($pdo, $categorieId);
}

// Grouper les catégories
$categoriesGroupees = [];
foreach ($categories as $cat) {
    $categoriesGroupees[$cat['groupe']][] = $cat;
}

// Compter toutes les sous-catégories (incluant les imbriquées)
function compterSousCategories($sousCategories) {
    $count = count($sousCategories);
    foreach ($sousCategories as $sc) {
        if (!empty($sc['enfants'])) {
            $count += compterSousCategories($sc['enfants']);
        }
    }
    return $count;
}

include '../../includes/header.php';

// Ajouter SortableJS
echo '<script src="https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.15.0/Sortable.min.js"></script>';

?>
<style>
    /* Styles pour l'arbre style Explorateur / Fusion 360 */
    .tree-item {
        border-left: 2px solid var(--border-color, #dee2e6);
        transition: all 0.2s;
    }

    .tree-content {
        display: flex;
        align-items: center;
        padding: 8px 12px;
        background: var(--bg-card, #f8f9fa);
        border: 1px solid var(--border-color, #e9ecef);
        margin-bottom: 3px;
        border-radius: 6px;
        position: relative;
    }

    .tree-content:hover {
        background: var(--bg-hover, #e9ecef);
        border-color: var(--primary-color, #0d6efd);
    }

    /* Indicateur de dossier ouvert/fermé */
    .tree-toggle {
        cursor: pointer;
        width: 24px;
        height: 24px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        margin-right: 6px;
        color: var(--text-muted, #6c757d);
        transition: transform 0.2s;
        border-radius: 4px;
    }

    .tree-toggle:hover {
        color: var(--primary-color, #0d6efd);
        background: rgba(13, 110, 253, 0.1);
    }

    .tree-toggle i {
        transition: transform 0.2s ease;
    }

    .tree-toggle.collapsed i,
    [aria-expanded="false"] .tree-toggle i {
        transform: rotate(-90deg);
    }

    /* Zone de drop pour l'imbrication */
    .tree-children {
        padding-left: 25px;
        min-height: 5px;
    }

    /* Style lors du drag */
    .sortable-ghost {
        opacity: 0.4;
        background: rgba(13, 110, 253, 0.15) !important;
        border: 2px dashed var(--primary-color, #0d6efd) !important;
        border-radius: 6px;
    }

    .sortable-drag {
        background: var(--bg-card, #f8f9fa) !important;
        box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        cursor: grabbing;
        border-radius: 6px;
    }

    /* Handle pour attraper */
    .drag-handle {
        cursor: grab;
        color: var(--text-muted, #adb5bd);
        margin-right: 8px;
        padding: 4px;
        border-radius: 4px;
        transition: all 0.2s;
    }
    .drag-handle:hover {
        color: var(--primary-color, #0d6efd);
        background: rgba(13, 110, 253, 0.1);
    }
    .drag-handle:active {
        cursor: grabbing;
    }

    /* Types d'items */
    .type-icon {
        width: 24px;
        text-align: center;
        margin-right: 8px;
    }

    .is-kit .tree-content {
        background: linear-gradient(135deg, rgba(13, 110, 253, 0.05) 0%, rgba(13, 110, 253, 0.02) 100%);
        border-left: 3px solid var(--primary-color, #0d6efd);
    }

    /* Matériaux - design amélioré */
    .tree-content.mat-item {
        background: var(--bg-card, #f8f9fa);
        border: 1px dashed var(--border-color, #dee2e6);
        padding: 6px 10px;
    }
    .tree-content.mat-item:hover {
        background: var(--bg-hover, #e9ecef);
        border-style: solid;
    }

    /* Card collapsible pour Groupes */
    .card-collapsible .card-header {
        cursor: pointer;
        user-select: none;
        transition: background 0.2s;
    }
    .card-collapsible .card-header:hover {
        background: var(--bg-hover, #e9ecef);
    }
    .card-collapsible .collapse-icon {
        transition: transform 0.3s ease;
    }
    .card-collapsible .card-header.collapsed .collapse-icon {
        transform: rotate(-90deg);
    }

    /* Liste des catégories/groupes */
    .list-group-item {
        background: var(--bg-card, #f8f9fa);
        border-color: var(--border-color, #e9ecef);
    }
    .list-group-item:hover {
        background: var(--bg-hover, #e9ecef);
    }
    .list-group-item.active {
        background: var(--primary-color, #0d6efd);
        border-color: var(--primary-color, #0d6efd);
    }
    .list-group-item.bg-light {
        background: var(--bg-hover, #e9ecef) !important;
    }
</style>
<?php

/**
 * Afficher les sous-catégories de façon récursive (Nouvelle version Drag & Drop)
 */
function afficherSousCategoriesRecursif($sousCategories, $categorieId) {
    if (empty($sousCategories)) return;

    // L'ID du container dépend du parent (pour SortableJS)
    // On utilise un attribut data-parent-id pour le script JS
    ?>
    <div class="list-group tree-children sortable-list" data-id-list="subcats">
    <?php

    foreach ($sousCategories as $sc):
        $uniqueId = $sc['id'];
        $hasChildren = !empty($sc['enfants']);
        $hasMateriaux = !empty($sc['materiaux']);
        // Est-ce un kit ? (Si a des enfants ou matériaux)
        $isKit = $hasChildren || $hasMateriaux;
    ?>
        <div class="tree-item mb-1 <?= $isKit ? 'is-kit' : '' ?>" data-id="<?= $uniqueId ?>" data-type="sous_categorie">
            <div class="tree-content">
                <!-- Poignée de drag -->
                <i class="bi bi-grip-vertical drag-handle"></i>

                <!-- Toggle (Flèche) seulement si contenu -->
                <?php if ($isKit): ?>
                    <span class="tree-toggle" data-bs-toggle="collapse" data-bs-target="#content<?= $uniqueId ?>">
                        <i class="bi bi-caret-down-fill"></i>
                    </span>
                <?php else: ?>
                    <span class="tree-toggle" style="visibility: hidden;"><i class="bi bi-caret-down-fill"></i></span>
                <?php endif; ?>

                <!-- Icone -->
                <div class="type-icon">
                    <i class="bi <?= $hasChildren ? 'bi-folder-fill text-warning' : 'bi-folder text-warning' ?>"></i>
                </div>

                <!-- Nom -->
                <strong class="flex-grow-1"><?= e($sc['nom']) ?></strong>

                <!-- Badges -->
                <?php if ($hasChildren): ?>
                    <span class="badge bg-warning bg-opacity-25 text-warning ms-2"><i class="bi bi-folder-fill me-1"></i><?= count($sc['enfants']) ?></span>
                <?php endif; ?>
                <?php if ($hasMateriaux): ?>
                    <span class="badge bg-primary bg-opacity-10 text-primary ms-1"><i class="bi bi-box-seam me-1"></i><?= count($sc['materiaux']) ?></span>
                <?php endif; ?>

                <!-- Actions -->
                <div class="btn-group btn-group-sm ms-2">
                     <button type="button" class="btn btn-outline-primary btn-sm border-0" title="Dupliquer le Kit" 
                            onclick="confirmDuplicate(<?= $uniqueId ?>, '<?= addslashes($sc['nom']) ?>')">
                        <i class="bi bi-files"></i>
                    </button>
                    <button type="button" class="btn btn-outline-success btn-sm border-0" data-bs-toggle="modal" data-bs-target="#addChildModal<?= $uniqueId ?>" title="Ajouter sous-dossier">
                        <i class="bi bi-folder-plus"></i>
                    </button>
                    <button type="button" class="btn btn-outline-primary btn-sm border-0" data-bs-toggle="modal" data-bs-target="#addMatModal<?= $uniqueId ?>" title="Ajouter item">
                        <i class="bi bi-plus-circle"></i>
                    </button>
                    <button type="button" class="btn btn-outline-secondary btn-sm border-0" data-bs-toggle="modal" data-bs-target="#editSousCatModal<?= $uniqueId ?>" title="Renommer">
                        <i class="bi bi-pencil"></i>
                    </button>
                    <button type="button" class="btn btn-outline-danger btn-sm border-0" data-bs-toggle="modal" data-bs-target="#deleteSousCatModal<?= $uniqueId ?>" title="Supprimer">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
            </div>

            <!-- Contenu (Enfants + Matériaux) -->
            <div class="collapse show ms-3" id="content<?= $uniqueId ?>">
                <!-- Zone Matériaux (Sortable) -->
                <div class="sortable-materials" data-parent-id="<?= $uniqueId ?>">
                    <?php if ($hasMateriaux): ?>
                        <?php foreach ($sc['materiaux'] as $mat): 
                            $qte = $mat['quantite_defaut'] ?? 1;
                            $total = $mat['prix_defaut'] * $qte;
                        ?>
                            <div class="tree-content mat-item" style="margin-left: 20px;"
                                 data-id="<?= $mat['id'] ?>" data-type="materiaux">
                                <i class="bi bi-grip-vertical drag-handle" style="font-size: 0.85em;"></i>
                                <div class="type-icon"><i class="bi bi-box-seam text-primary small"></i></div>
                                <span class="flex-grow-1 small"><?= e($mat['nom']) ?></span>

                                <span class="badge bg-secondary bg-opacity-10 text-secondary me-2">x<?= $qte ?></span>
                                <span class="badge bg-primary bg-opacity-10 text-primary me-2"><?= formatMoney($mat['prix_defaut']) ?></span>
                                <span class="badge bg-success bg-opacity-25 text-success fw-bold me-2"><?= formatMoney($total) ?></span>

                                <div class="btn-group btn-group-sm">
                                    <button type="button" class="btn btn-link text-primary p-0 me-2" data-bs-toggle="modal" data-bs-target="#editMatModal<?= $mat['id'] ?>">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <button type="button" class="btn btn-link text-danger p-0" data-bs-toggle="modal" data-bs-target="#deleteMatModal<?= $mat['id'] ?>">
                                        <i class="bi bi-x-lg"></i>
                                    </button>
                                </div>
                            </div>
                            
                            <!-- Modal Modifier Matériau -->
                            <div class="modal fade" id="editMatModal<?= $mat['id'] ?>" tabindex="-1">
                                <div class="modal-dialog">
                                    <div class="modal-content">
                                        <form method="POST">
                                            <?php csrfField(); ?>
                                            <input type="hidden" name="action" value="modifier_materiau">
                                            <input type="hidden" name="id" value="<?= $mat['id'] ?>">
                                            <div class="modal-header">
                                                <h5 class="modal-title">Modifier le matériau</h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                            </div>
                                            <div class="modal-body">
                                                <div class="mb-3">
                                                    <label class="form-label">Nom *</label>
                                                    <input type="text" class="form-control" name="nom" value="<?= e($mat['nom']) ?>" required>
                                                </div>
                                                <div class="row">
                                                    <div class="col-6 mb-3">
                                                        <label class="form-label">Prix unitaire</label>
                                                        <div class="input-group">
                                                            <span class="input-group-text">$</span>
                                                            <input type="text" class="form-control" name="prix_defaut" value="<?= $mat['prix_defaut'] ?>">
                                                        </div>
                                                    </div>
                                                    <div class="col-6 mb-3">
                                                        <label class="form-label">Quantité</label>
                                                        <input type="number" class="form-control" name="quantite_defaut" value="<?= $mat['quantite_defaut'] ?? 1 ?>" min="1">
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                                                <button type="submit" class="btn btn-primary">Enregistrer</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>

                            <!-- Modal Supprimer Matériau -->
                            <div class="modal fade" id="deleteMatModal<?= $mat['id'] ?>" tabindex="-1">
                                <div class="modal-dialog">
                                    <div class="modal-content">
                                        <div class="modal-header bg-danger text-white">
                                            <h5 class="modal-title">Supprimer le matériau</h5>
                                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body">
                                            <p>Êtes-vous sûr de vouloir supprimer <strong><?= e($mat['nom']) ?></strong>?</p>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                                            <form method="POST" class="d-inline">
                                                <?php csrfField(); ?>
                                                <input type="hidden" name="action" value="supprimer_materiau">
                                                <input type="hidden" name="id" value="<?= $mat['id'] ?>">
                                                <button type="submit" class="btn btn-danger">Supprimer</button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Récursion pour les enfants -->
                <div class="sortable-subcats" data-parent-id="<?= $uniqueId ?>">
                    <?php if ($hasChildren): ?>
                        <?php afficherSousCategoriesRecursif($sc['enfants'], $categorieId); ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>



    <!-- Modal Ajouter Sous-catégorie enfant -->
    <div class="modal fade" id="addChildModal<?= $uniqueId ?>" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <?php csrfField(); ?>
                    <input type="hidden" name="action" value="ajouter_sous_categorie">
                    <input type="hidden" name="categorie_id" value="<?= $categorieId ?>">
                    <input type="hidden" name="parent_id" value="<?= $sc['id'] ?>">
                    <div class="modal-header">
                        <h5 class="modal-title">Nouvelle sous-catégorie dans <?= e($sc['nom']) ?></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Nom de la sous-catégorie *</label>
                            <input type="text" class="form-control" name="nom" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-success">Ajouter</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Modifier Sous-catégorie -->
    <div class="modal fade" id="editSousCatModal<?= $uniqueId ?>" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <?php csrfField(); ?>
                    <input type="hidden" name="action" value="modifier_sous_categorie">
                    <input type="hidden" name="id" value="<?= $sc['id'] ?>">
                    <div class="modal-header">
                        <h5 class="modal-title">Modifier sous-catégorie</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Nom</label>
                            <input type="text" class="form-control" name="nom" value="<?= e($sc['nom']) ?>" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-primary">Enregistrer</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Supprimer Sous-catégorie -->
    <div class="modal fade" id="deleteSousCatModal<?= $uniqueId ?>" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">Supprimer</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Supprimer <strong><?= e($sc['nom']) ?></strong>?</p>
                    <?php if ($hasChildren || $hasMateriaux): ?>
                        <div class="alert alert-warning mb-0">
                            <i class="bi bi-exclamation-triangle me-1"></i>
                            Ceci supprimera aussi:
                            <?php if ($hasChildren): ?><br>- <?= count($sc['enfants']) ?> sous-catégorie(s)<?php endif; ?>
                            <?php if ($hasMateriaux): ?><br>- <?= count($sc['materiaux']) ?> matériau(x)<?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                    <form method="POST" class="d-inline">
                        <?php csrfField(); ?>
                        <input type="hidden" name="action" value="supprimer_sous_categorie">
                        <input type="hidden" name="id" value="<?= $sc['id'] ?>">
                        <button type="submit" class="btn btn-danger">Supprimer</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Ajouter Matériau -->
    <div class="modal fade" id="addMatModal<?= $uniqueId ?>" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <?php csrfField(); ?>
                    <input type="hidden" name="action" value="ajouter_materiau">
                    <input type="hidden" name="sous_categorie_id" value="<?= $sc['id'] ?>">
                    <div class="modal-header">
                        <h5 class="modal-title">Ajouter un matériau à <?= e($sc['nom']) ?></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Nom du matériau *</label>
                            <input type="text" class="form-control" name="nom" required>
                        </div>
                        <div class="row">
                            <div class="col-6 mb-3">
                                <label class="form-label">Prix unitaire</label>
                                <div class="input-group">
                                    <span class="input-group-text">$</span>
                                    <input type="text" class="form-control" name="prix_defaut" value="0">
                                </div>
                            </div>
                            <div class="col-6 mb-3">
                                <label class="form-label">Quantité</label>
                                <input type="number" class="form-control" name="quantite_defaut" value="1" min="1">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-success">Ajouter</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
<?php
    endforeach;
?>
    </div>
<?php
}
?>

<div class="container-fluid">
    <!-- En-tête -->
    <div class="page-header d-flex justify-content-between align-items-start flex-wrap">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="<?= url('/admin/index.php') ?>">Tableau de bord</a></li>
                    <li class="breadcrumb-item active">Templates Budgets</li>
                </ol>
            </nav>
            <h1><i class="bi bi-box-seam me-2"></i>Templates Budgets</h1>
        </div>
    </div>

    <!-- Sous-navigation Administration -->
    <ul class="nav nav-tabs mb-4">
        <li class="nav-item">
            <a class="nav-link" href="<?= url('/admin/utilisateurs/liste.php') ?>">
                <i class="bi bi-person-badge me-1"></i>Utilisateurs
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="<?= url('/admin/categories/liste.php') ?>">
                <i class="bi bi-tags me-1"></i>Catégories
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link active" href="<?= url('/admin/templates/liste.php') ?>">
                <i class="bi bi-box-seam me-1"></i>Templates
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="<?= url('/admin/fournisseurs/liste.php') ?>">
                <i class="bi bi-shop me-1"></i>Fournisseurs
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="<?= url('/admin/recurrents/liste.php') ?>">
                <i class="bi bi-arrow-repeat me-1"></i>Récurrents
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="<?= url('/admin/checklists/liste.php') ?>">
                <i class="bi bi-list-check me-1"></i>Checklists
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="<?= url('/admin/rapports/index.php') ?>">
                <i class="bi bi-file-earmark-bar-graph me-1"></i>Rapports
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="<?= url('/admin/configuration/index.php') ?>">
                <i class="bi bi-gear me-1"></i>Configuration
            </a>
        </li>
    </ul>

    <?php displayFlashMessage(); ?>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger">
            <ul class="mb-0">
                <?php foreach ($errors as $error): ?>
                    <li><?= e($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="row">
        <!-- Colonne gauche: Liste des catégories -->
        <div class="col-md-3">
            <!-- Card Groupes (Collapsible, fermé par défaut) -->
            <div class="card mb-3 card-collapsible">
                <div class="card-header d-flex justify-content-between align-items-center py-2 collapsed"
                     data-bs-toggle="collapse" data-bs-target="#groupesContent" aria-expanded="false">
                    <span>
                        <i class="bi bi-chevron-down collapse-icon me-1"></i>
                        <i class="bi bi-collection me-1"></i>Groupes (volets)
                        <span class="badge bg-secondary ms-1"><?= count($groupes) ?></span>
                    </span>
                    <button type="button" class="btn btn-success btn-sm py-0 px-1" data-bs-toggle="modal" data-bs-target="#addGroupeModal" title="Nouveau groupe" onclick="event.stopPropagation();">
                        <i class="bi bi-plus-lg"></i>
                    </button>
                </div>
                <div class="collapse" id="groupesContent">
                    <div class="list-group list-group-flush" style="max-height: 30vh; overflow-y: auto;">
                        <?php foreach ($groupes as $idx => $grp): ?>
                            <div class="list-group-item py-1 d-flex justify-content-between align-items-center small">
                                <span><?= e($grp['nom']) ?></span>
                                <div class="btn-group btn-group-sm">
                                    <?php if ($idx > 0): ?>
                                    <form method="POST" class="d-inline">
                                        <?php csrfField(); ?>
                                        <input type="hidden" name="action" value="monter_groupe">
                                        <input type="hidden" name="id" value="<?= $grp['id'] ?>">
                                        <button type="submit" class="btn btn-outline-secondary btn-sm py-0 px-1" title="Monter">
                                            <i class="bi bi-arrow-up" style="font-size: 0.65rem;"></i>
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                    <?php if ($idx < count($groupes) - 1): ?>
                                    <form method="POST" class="d-inline">
                                        <?php csrfField(); ?>
                                        <input type="hidden" name="action" value="descendre_groupe">
                                        <input type="hidden" name="id" value="<?= $grp['id'] ?>">
                                        <button type="submit" class="btn btn-outline-secondary btn-sm py-0 px-1" title="Descendre">
                                            <i class="bi bi-arrow-down" style="font-size: 0.65rem;"></i>
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                    <button type="button" class="btn btn-outline-warning btn-sm py-0 px-1" data-bs-toggle="modal" data-bs-target="#editGroupeModal<?= $grp['id'] ?>" title="Modifier">
                                        <i class="bi bi-pencil" style="font-size: 0.65rem;"></i>
                                    </button>
                                    <?php if ($grp['code'] !== 'autre'): ?>
                                    <button type="button" class="btn btn-outline-danger btn-sm py-0 px-1" data-bs-toggle="modal" data-bs-target="#deleteGroupeModal<?= $grp['id'] ?>" title="Supprimer">
                                        <i class="bi bi-trash" style="font-size: 0.65rem;"></i>
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Card Catégories -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-folder me-1"></i>Catégories</span>
                    <button type="button" class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#addCategorieModal" title="Nouvelle catégorie">
                        <i class="bi bi-plus-lg"></i>
                    </button>
                </div>
                <div class="list-group list-group-flush" style="max-height: 50vh; overflow-y: auto;">
                    <?php foreach ($groupeLabels as $groupe => $label): ?>
                        <?php
                        $catsInGroupe = $categoriesGroupees[$groupe] ?? [];
                        $nbCats = count($catsInGroupe);
                        if ($nbCats > 0):
                        ?>
                        <div class="list-group-item bg-light py-1 small fw-bold text-muted">
                            <?= $label ?> <span class="badge bg-secondary"><?= $nbCats ?></span>
                        </div>
                        <?php foreach ($catsInGroupe as $catIdx => $cat): ?>
                            <div class="list-group-item py-1 d-flex justify-content-between align-items-center <?= $categorieId == $cat['id'] ? 'active' : '' ?>">
                                <a href="?categorie=<?= $cat['id'] ?>" class="text-decoration-none flex-grow-1 small <?= $categorieId == $cat['id'] ? 'text-white' : '' ?>">
                                    <?= e($cat['nom']) ?>
                                </a>
                                <div class="btn-group btn-group-sm ms-1">
                                    <?php if ($catIdx > 0): ?>
                                    <form method="POST" class="d-inline">
                                        <?php csrfField(); ?>
                                        <input type="hidden" name="action" value="monter_categorie">
                                        <input type="hidden" name="id" value="<?= $cat['id'] ?>">
                                        <button type="submit" class="btn btn-outline-secondary btn-sm py-0 px-1" title="Monter">
                                            <i class="bi bi-arrow-up" style="font-size: 0.6rem;"></i>
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                    <?php if ($catIdx < $nbCats - 1): ?>
                                    <form method="POST" class="d-inline">
                                        <?php csrfField(); ?>
                                        <input type="hidden" name="action" value="descendre_categorie">
                                        <input type="hidden" name="id" value="<?= $cat['id'] ?>">
                                        <button type="submit" class="btn btn-outline-secondary btn-sm py-0 px-1" title="Descendre">
                                            <i class="bi bi-arrow-down" style="font-size: 0.6rem;"></i>
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                    <button type="button" class="btn btn-outline-warning btn-sm py-0 px-1" data-bs-toggle="modal" data-bs-target="#editCatModal<?= $cat['id'] ?>" title="Modifier">
                                        <i class="bi bi-pencil" style="font-size: 0.6rem;"></i>
                                    </button>
                                    <button type="button" class="btn btn-outline-danger btn-sm py-0 px-1" data-bs-toggle="modal" data-bs-target="#deleteCatModal<?= $cat['id'] ?>" title="Supprimer">
                                            <i class="bi bi-trash" style="font-size: 0.7rem;"></i>
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Modals pour les catégories -->
        <?php foreach ($categories as $cat): ?>
        <!-- Modal Modifier Catégorie -->
        <div class="modal fade" id="editCatModal<?= $cat['id'] ?>" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form method="POST">
                        <?php csrfField(); ?>
                        <input type="hidden" name="action" value="modifier_categorie">
                        <input type="hidden" name="id" value="<?= $cat['id'] ?>">
                        <div class="modal-header">
                            <h5 class="modal-title">Modifier la catégorie</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label">Nom *</label>
                                <input type="text" class="form-control" name="nom" value="<?= e($cat['nom']) ?>" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Groupe (volet)</label>
                                <select class="form-select" name="groupe">
                                    <?php foreach ($groupeLabels as $g => $l): ?>
                                        <option value="<?= $g ?>" <?= $cat['groupe'] === $g ? 'selected' : '' ?>><?= $l ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                            <button type="submit" class="btn btn-primary">Enregistrer</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Modal Supprimer Catégorie -->
        <div class="modal fade" id="deleteCatModal<?= $cat['id'] ?>" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header bg-danger text-white">
                        <h5 class="modal-title">Supprimer la catégorie</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p>Êtes-vous sûr de vouloir supprimer <strong><?= e($cat['nom']) ?></strong>?</p>
                        <div class="alert alert-warning mb-0">
                            <i class="bi bi-exclamation-triangle me-1"></i>
                            Toutes les sous-catégories et matériaux associés seront aussi supprimés!
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                        <form method="POST" class="d-inline">
                            <?php csrfField(); ?>
                            <input type="hidden" name="action" value="supprimer_categorie">
                            <input type="hidden" name="id" value="<?= $cat['id'] ?>">
                            <button type="submit" class="btn btn-danger">Supprimer</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>

        <!-- Colonne droite: Détails de la catégorie -->
        <div class="col-md-9">
            <?php if (!$categorieSelectionnee): ?>
                <div class="card">
                    <div class="card-body text-center py-5">
                        <i class="bi bi-arrow-left-circle" style="font-size: 3rem; color: var(--text-muted);"></i>
                        <p class="text-muted mt-3 mb-0">Sélectionnez une catégorie pour voir et gérer ses sous-catégories</p>
                    </div>
                </div>
            <?php else: ?>
                <div class="card mb-3">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <div>
                            <i class="bi bi-folder-fill me-1 text-warning"></i>
                            <strong><?= e($categorieSelectionnee['nom']) ?></strong>
                            <span class="badge bg-secondary ms-2"><?= compterSousCategories($sousCategories) ?> sous-catégories</span>
                        </div>
                        <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addSousCatModal">
                            <i class="bi bi-plus-circle me-1"></i>Sous-catégorie
                        </button>
                    </div>
                </div>

                <?php if (empty($sousCategories)): ?>
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        Aucune sous-catégorie. Cliquez sur "Sous-catégorie" pour en ajouter une.
                    </div>
                <?php else: ?>
                    <?php afficherSousCategoriesRecursif($sousCategories, $categorieId); ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($categorieSelectionnee): ?>
<!-- Modal Ajouter Sous-catégorie de premier niveau -->
<div class="modal fade" id="addSousCatModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <?php csrfField(); ?>
                <input type="hidden" name="action" value="ajouter_sous_categorie">
                <input type="hidden" name="categorie_id" value="<?= $categorieId ?>">
                <input type="hidden" name="parent_id" value="">
                <div class="modal-header">
                    <h5 class="modal-title">Nouvelle sous-catégorie dans <?= e($categorieSelectionnee['nom']) ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Nom de la sous-catégorie *</label>
                        <input type="text" class="form-control" name="nom" required placeholder="Ex: Bain/Douche, Toilette, Vanité...">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-primary">Ajouter</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Modal Ajouter Catégorie -->
<div class="modal fade" id="addCategorieModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <?php csrfField(); ?>
                <input type="hidden" name="action" value="ajouter_categorie">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-folder-plus me-2"></i>Nouvelle catégorie</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Nom de la catégorie *</label>
                        <input type="text" class="form-control" name="nom" required placeholder="Ex: Salle de bain, Cuisine, Toiture...">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Groupe (volet)</label>
                        <select class="form-select" name="groupe">
                            <?php foreach ($groupeLabels as $g => $l): ?>
                                <option value="<?= $g ?>"><?= $l ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Choisir dans quel volet la catégorie apparaîtra</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-plus-lg me-1"></i>Créer la catégorie
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Ajouter Groupe -->
<div class="modal fade" id="addGroupeModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <?php csrfField(); ?>
                <input type="hidden" name="action" value="ajouter_groupe">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-collection me-2"></i>Nouveau groupe (volet)</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Nom du groupe *</label>
                        <input type="text" class="form-control" name="nom" required placeholder="Ex: Chauffage, Climatisation...">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Code (optionnel)</label>
                        <input type="text" class="form-control" name="code" placeholder="Ex: chauffage">
                        <small class="text-muted">Laissez vide pour générer automatiquement</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-plus-lg me-1"></i>Créer le groupe
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modals Modifier/Supprimer Groupes -->
<?php foreach ($groupes as $grp): ?>
<div class="modal fade" id="editGroupeModal<?= $grp['id'] ?>" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <?php csrfField(); ?>
                <input type="hidden" name="action" value="modifier_groupe">
                <input type="hidden" name="id" value="<?= $grp['id'] ?>">
                <div class="modal-header">
                    <h5 class="modal-title">Modifier le groupe</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Nom *</label>
                        <input type="text" class="form-control" name="nom" value="<?= e($grp['nom']) ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Code</label>
                        <input type="text" class="form-control" value="<?= e($grp['code']) ?>" disabled>
                        <small class="text-muted">Le code ne peut pas être modifié</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-primary">Enregistrer</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if ($grp['code'] !== 'autre'): ?>
<div class="modal fade" id="deleteGroupeModal<?= $grp['id'] ?>" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">Supprimer le groupe</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Êtes-vous sûr de vouloir supprimer le groupe <strong><?= e($grp['nom']) ?></strong>?</p>
                <div class="alert alert-warning mb-0">
                    <i class="bi bi-exclamation-triangle me-1"></i>
                    Les catégories de ce groupe seront déplacées vers "Autre".
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                <form method="POST" class="d-inline">
                    <?php csrfField(); ?>
                    <input type="hidden" name="action" value="supprimer_groupe">
                    <input type="hidden" name="id" value="<?= $grp['id'] ?>">
                    <button type="submit" class="btn btn-danger">Supprimer</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
<?php endforeach; ?>

<?php include '../../includes/footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // 1. Initialiser le tri des Items (Matériaux)
    // Ils peuvent être déplacés entre différentes sous-catégories
    const materialLists = document.querySelectorAll('.sortable-materials');
    materialLists.forEach(function(list) {
        new Sortable(list, {
            group: 'materials', // Permet de déplacer entre les listes
            animation: 150,
            handle: '.drag-handle',
            ghostClass: 'sortable-ghost',
            dragClass: 'sortable-drag',
            onEnd: function (evt) {
                // Sauvegarder le nouvel ordre et le nouveau parent
                const itemEl = evt.item;
                const newParentList = evt.to;
                const newParentId = newParentList.getAttribute('data-parent-id');
                
                // Récupérer tous les items de la nouvelle liste pour avoir l'ordre
                const items = Array.from(newParentList.querySelectorAll('[data-type="materials"]')).map(el => el.getAttribute('data-id'));
                
                saveOrder('materiaux', items, newParentId);
            }
        });
    });

    // 2. Initialiser le tri des Sous-catégories (Dossiers)
    // Peuvent être imbriquées
    const subcatLists = document.querySelectorAll('.sortable-subcats');
    
    // Aussi la racine (s'il y a un conteneur racine pour les sous-cats de niveau 1)
    // Note: Dans le code actuel, la racine est afficherSousCategoriesRecursif appelé directement dans le HTML.
    // Il faut s'assurer que le premier niveau a aussi la classe sortable-subcats ou équivalent.
    // Pour l'instant, on cible les .sortable-subcats qui sont DANS les items recursive.
    
    // Pour gérer le root level, il faut peut-être envelopper l'appel initial php dans un div avec ID
    
    // Fonction d'init récursive ou sélecteur global ?
    // On va utiliser un sélecteur qui attrape tout, y compris les racines si on leur a mis la classe.
    
    const nestedSortables = document.querySelectorAll('.sortable-subcats, .list-group.tree-children'); // .list-group.tree-children est mis par la fonction PHP
    nestedSortables.forEach(function(list) {
        new Sortable(list, {
            group: 'subcategories', // Permet l'imbrication
            animation: 150,
            handle: '.drag-handle',
            ghostClass: 'sortable-ghost',
            dragClass: 'sortable-drag',
            fallbackOnBody: true,
            swapThreshold: 0.65,
            onEnd: function (evt) {
                const itemEl = evt.item;
                const newParentList = evt.to;
                
                // Le parent ID est sur le conteneur .sortable-subcats
                let newParentId = newParentList.getAttribute('data-parent-id');
                
                // Si c'est le root (pas de data-parent-id sur le div container principal ?), on doit le gérer
                // Le container racine généré par PHP a-t-il un data-parent-id ?
                // Dans la fonction : <div class="list-group tree-children" data-id-list="subcats">
                // Il n'a pas de data-parent-id explicite, donc c'est NULL (root).
                
                // Correction : on va assumer que si pas de data-parent-id, c'est 'root'
                if (!newParentId) newParentId = 'root';

                // Récupérer IDs
                const items = Array.from(newParentList.children).map(el => el.getAttribute('data-id')).filter(id => id != null);
                
                // Il faut aussi l'ID de la catégorie principale (page courante)
                // On peut le chopper dans l'URL ou un input hidden
                const categorieId = new URLSearchParams(window.location.search).get('categorie');
                
                saveOrder('sous_categorie', items, newParentId, categorieId);
            }
        });
    });
});

function saveOrder(type, items, parentId, categorieId = null) {
    fetch('ajax_update_tree.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            type: type,
            items: items,
            parentId: parentId,
            categorieId: categorieId
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            console.log('Ordre sauvegardé');
            // Optionnel: petit feedback visuel (toast)
        } else {
            alert('Erreur lors de la sauvegarde: ' + (data.message || 'Inconnue'));
        }
    })
    .catch(error => {
        console.error('Erreur:', error);
    });
}

function confirmDuplicate(id, nom) {
    if(confirm('Voulez-vous vraiment dupliquer le kit "' + nom + '" et tout son contenu ?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="dupliquer_sous_categorie">
            <input type="hidden" name="id" value="${id}">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

// Gestion de l'animation de la flèche du collapse Groupes
document.querySelectorAll('.card-collapsible .card-header').forEach(header => {
    const collapseTarget = document.querySelector(header.getAttribute('data-bs-target'));
    if (collapseTarget) {
        collapseTarget.addEventListener('show.bs.collapse', () => {
            header.classList.remove('collapsed');
        });
        collapseTarget.addEventListener('hide.bs.collapse', () => {
            header.classList.add('collapsed');
        });
    }
});

// Animation toggle des sous-catégories (tree)
document.querySelectorAll('.tree-toggle[data-bs-toggle="collapse"]').forEach(toggle => {
    const target = document.querySelector(toggle.getAttribute('data-bs-target'));
    if (target) {
        target.addEventListener('show.bs.collapse', () => {
            toggle.classList.remove('collapsed');
        });
        target.addEventListener('hide.bs.collapse', () => {
            toggle.classList.add('collapsed');
        });
    }
});
</script>
