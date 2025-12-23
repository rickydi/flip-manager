<?php
/**
 * Détail du projet - Admin
 * Flip Manager - Vue compacte 3 colonnes
 */

require_once '../../config.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../includes/calculs.php';

requireAdmin();

$projetId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// ========================================
// AUTO-MIGRATION: Créer tables si manquantes
// ========================================
try {
    $pdo->query("SELECT 1 FROM projet_postes LIMIT 1");
} catch (Exception $e) {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS projet_postes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            projet_id INT NOT NULL,
            categorie_id INT NOT NULL,
            quantite INT DEFAULT 1,
            budget_extrapole DECIMAL(12,2) DEFAULT 0,
            FOREIGN KEY (projet_id) REFERENCES projets(id) ON DELETE CASCADE,
            FOREIGN KEY (categorie_id) REFERENCES categories(id) ON DELETE CASCADE,
            UNIQUE KEY unique_projet_cat (projet_id, categorie_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

try {
    $pdo->query("SELECT 1 FROM projet_items LIMIT 1");
    // Vérifier si colonne sans_taxe existe
    try {
        $pdo->query("SELECT sans_taxe FROM projet_items LIMIT 1");
    } catch (Exception $e2) {
        $pdo->exec("ALTER TABLE projet_items ADD COLUMN sans_taxe TINYINT(1) DEFAULT 0");
    }
} catch (Exception $e) {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS projet_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            projet_id INT NOT NULL,
            projet_poste_id INT NOT NULL,
            materiau_id INT NOT NULL,
            prix_unitaire DECIMAL(10,2) DEFAULT 0,
            quantite INT DEFAULT 1,
            sans_taxe TINYINT(1) DEFAULT 0,
            FOREIGN KEY (projet_id) REFERENCES projets(id) ON DELETE CASCADE,
            FOREIGN KEY (projet_poste_id) REFERENCES projet_postes(id) ON DELETE CASCADE,
            FOREIGN KEY (materiau_id) REFERENCES materiaux(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

// Table pour stocker les quantités de groupes par projet
try {
    $pdo->query("SELECT 1 FROM projet_groupes LIMIT 1");
} catch (Exception $e) {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS projet_groupes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            projet_id INT NOT NULL,
            groupe_nom VARCHAR(50) NOT NULL,
            quantite INT DEFAULT 1,
            FOREIGN KEY (projet_id) REFERENCES projets(id) ON DELETE CASCADE,
            UNIQUE KEY unique_projet_groupe (projet_id, groupe_nom)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

// Table pour stocker les quantités des sous-catégories par projet
try {
    $pdo->query("SELECT 1 FROM projet_sous_categories LIMIT 1");
} catch (Exception $e) {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS projet_sous_categories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            projet_id INT NOT NULL,
            sous_categorie_id INT NOT NULL,
            quantite INT DEFAULT 1,
            is_direct_drop TINYINT(1) DEFAULT 0,
            groupe VARCHAR(50) DEFAULT NULL,
            FOREIGN KEY (projet_id) REFERENCES projets(id) ON DELETE CASCADE,
            FOREIGN KEY (sous_categorie_id) REFERENCES sous_categories(id) ON DELETE CASCADE,
            UNIQUE KEY unique_projet_sc (projet_id, sous_categorie_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

// Migration: ajouter les colonnes is_direct_drop et groupe si elles n'existent pas
try {
    $pdo->query("SELECT is_direct_drop FROM projet_sous_categories LIMIT 1");
} catch (Exception $e) {
    $pdo->exec("ALTER TABLE projet_sous_categories ADD COLUMN is_direct_drop TINYINT(1) DEFAULT 0");
}
try {
    $pdo->query("SELECT groupe FROM projet_sous_categories LIMIT 1");
} catch (Exception $e) {
    $pdo->exec("ALTER TABLE projet_sous_categories ADD COLUMN groupe VARCHAR(50) DEFAULT NULL");
}

// Table pour stocker les valeurs de coûts récurrents par projet (types dynamiques)
try {
    $pdo->query("SELECT 1 FROM projet_recurrents LIMIT 1");
} catch (Exception $e) {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS projet_recurrents (
            id INT AUTO_INCREMENT PRIMARY KEY,
            projet_id INT NOT NULL,
            recurrent_type_id INT NOT NULL,
            montant DECIMAL(12,2) DEFAULT 0,
            FOREIGN KEY (projet_id) REFERENCES projets(id) ON DELETE CASCADE,
            UNIQUE KEY unique_projet_recurrent (projet_id, recurrent_type_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

// Auto-créer la table recurrents_types si elle n'existe pas
try {
    $pdo->query("SELECT 1 FROM recurrents_types LIMIT 1");
    // Ajouter 'saisonnier' à l'ENUM si la table existe déjà
    try {
        $pdo->exec("ALTER TABLE recurrents_types MODIFY frequence ENUM('annuel', 'mensuel', 'saisonnier') DEFAULT 'annuel'");
    } catch (Exception $e) {
        // Déjà modifié ou erreur
    }
} catch (Exception $e) {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS recurrents_types (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nom VARCHAR(100) NOT NULL,
            code VARCHAR(50) NOT NULL UNIQUE,
            frequence ENUM('annuel', 'mensuel', 'saisonnier') DEFAULT 'annuel',
            ordre INT DEFAULT 0,
            actif TINYINT(1) DEFAULT 1,
            est_systeme TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
    // Insérer les types par défaut
    $defaults = [
        ['Taxes municipales', 'taxes_municipales', 'annuel', 1, 1],
        ['Taxes scolaires', 'taxes_scolaires', 'annuel', 2, 1],
        ['Électricité', 'electricite', 'annuel', 3, 1],
        ['Assurances', 'assurances', 'annuel', 4, 1],
        ['Déneigement', 'deneigement', 'saisonnier', 5, 1],
        ['Frais condo', 'frais_condo', 'annuel', 6, 1],
        ['Hypothèque', 'hypotheque', 'mensuel', 7, 1],
        ['Loyer reçu', 'loyer', 'mensuel', 8, 1],
    ];
    $stmt = $pdo->prepare("INSERT INTO recurrents_types (nom, code, frequence, ordre, est_systeme) VALUES (?, ?, ?, ?, ?)");
    foreach ($defaults as $d) {
        $stmt->execute($d);
    }
}

// ========================================
// HELPER: Synchroniser la table budgets depuis projet_items
// ========================================
function syncBudgetsFromProjetItems($pdo, $projetId) {
    // Calculer les totaux par catégorie depuis projet_items
    $stmt = $pdo->prepare("
        SELECT pp.categorie_id,
               SUM(pi.prix_unitaire * pi.quantite) as total_ht
        FROM projet_postes pp
        LEFT JOIN projet_items pi ON pi.projet_poste_id = pp.id
        WHERE pp.projet_id = ?
        GROUP BY pp.categorie_id
    ");
    $stmt->execute([$projetId]);
    $totals = $stmt->fetchAll();

    // Récupérer les quantités des groupes
    $stmt = $pdo->prepare("SELECT groupe_nom, quantite FROM projet_groupes WHERE projet_id = ?");
    $stmt->execute([$projetId]);
    $groupeQtes = [];
    foreach ($stmt->fetchAll() as $g) {
        $groupeQtes[$g['groupe_nom']] = $g['quantite'];
    }

    // Récupérer les quantités des postes et leur groupe
    $stmt = $pdo->prepare("
        SELECT pp.categorie_id, pp.quantite as poste_qte, c.groupe
        FROM projet_postes pp
        JOIN categories c ON c.id = pp.categorie_id
        WHERE pp.projet_id = ?
    ");
    $stmt->execute([$projetId]);
    $postes = [];
    foreach ($stmt->fetchAll() as $p) {
        $postes[$p['categorie_id']] = [
            'qte' => $p['poste_qte'],
            'groupe' => $p['groupe']
        ];
    }

    // Mettre à jour la table budgets
    foreach ($totals as $t) {
        $catId = $t['categorie_id'];
        $totalHT = (float)$t['total_ht'];

        // Appliquer les multiplicateurs
        $posteQte = $postes[$catId]['qte'] ?? 1;
        $groupe = $postes[$catId]['groupe'] ?? '';
        $groupeQte = $groupeQtes[$groupe] ?? 1;

        $montantExtrapole = $totalHT * $posteQte * $groupeQte;

        $stmt = $pdo->prepare("
            INSERT INTO budgets (projet_id, categorie_id, montant_extrapole)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE montant_extrapole = VALUES(montant_extrapole)
        ");
        $stmt->execute([$projetId, $catId, $montantExtrapole]);
    }

    // Supprimer les budgets pour les catégories qui n'ont plus d'items
    $catIds = array_column($totals, 'categorie_id');
    if (empty($catIds)) {
        $pdo->prepare("DELETE FROM budgets WHERE projet_id = ?")->execute([$projetId]);
    } else {
        $placeholders = implode(',', array_fill(0, count($catIds), '?'));
        $stmt = $pdo->prepare("DELETE FROM budgets WHERE projet_id = ? AND categorie_id NOT IN ($placeholders)");
        $stmt->execute(array_merge([$projetId], $catIds));
    }
}

// ========================================
// AJAX: Sauvegarde automatique de l'onglet Base
// ========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'save_base') {
    header('Content-Type: application/json');

    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'error' => 'Token invalide']);
        exit;
    }

    try {
        // Récupérer les valeurs
        $nom = trim($_POST['nom'] ?? '');
        $adresse = trim($_POST['adresse'] ?? '');
        $ville = trim($_POST['ville'] ?? '');
        $codePostal = trim($_POST['code_postal'] ?? '');
        $dateAcquisition = $_POST['date_acquisition'] ?: null;
        $dateDebutTravaux = $_POST['date_debut_travaux'] ?: null;
        $dateFinPrevue = $_POST['date_fin_prevue'] ?: null;
        $dateVente = $_POST['date_vente'] ?: null;
        $statut = $_POST['statut'] ?? 'acquisition';

        $prixAchat = parseNumber($_POST['prix_achat'] ?? 0);
        $roleEvaluation = parseNumber($_POST['role_evaluation'] ?? 0);
        $cession = parseNumber($_POST['cession'] ?? 0);
        $notaire = parseNumber($_POST['notaire'] ?? 0);
        $taxeMutation = parseNumber($_POST['taxe_mutation'] ?? 0);
        $quittance = parseNumber($_POST['quittance'] ?? 0);
        $arpenteurs = parseNumber($_POST['arpenteurs'] ?? 0);
        $assuranceTitre = parseNumber($_POST['assurance_titre'] ?? 0);
        $soldeVendeur = parseNumber($_POST['solde_vendeur'] ?? 0);
        $soldeAcheteur = parseNumber($_POST['solde_acheteur'] ?? 0);

        $tempsAssumeMois = (int)($_POST['temps_assume_mois'] ?? 6);
        $valeurPotentielle = parseNumber($_POST['valeur_potentielle'] ?? 0);

        $tauxCommission = parseNumber($_POST['taux_commission'] ?? 4);
        $tauxContingence = parseNumber($_POST['taux_contingence'] ?? 15);
        $tauxInteret = parseNumber($_POST['taux_interet'] ?? 10);
        $montantPret = parseNumber($_POST['montant_pret'] ?? 0);

        $notes = trim($_POST['notes'] ?? '');
        $dropboxLink = trim($_POST['dropbox_link'] ?? '');

        // Validation minimale
        if (empty($nom) || empty($adresse) || empty($ville)) {
            echo json_encode(['success' => false, 'error' => 'Champs requis manquants']);
            exit;
        }

        $pdo->beginTransaction();

        // Mise à jour du projet
        $stmt = $pdo->prepare("
            UPDATE projets SET
                nom = ?, adresse = ?, ville = ?, code_postal = ?,
                date_acquisition = ?, date_debut_travaux = ?, date_fin_prevue = ?, date_vente = ?,
                statut = ?, prix_achat = ?, role_evaluation = ?, cession = ?, notaire = ?, taxe_mutation = ?, quittance = ?,
                arpenteurs = ?, assurance_titre = ?, solde_vendeur = ?, solde_acheteur = ?,
                temps_assume_mois = ?, valeur_potentielle = ?,
                taux_commission = ?, taux_contingence = ?,
                taux_interet = ?, montant_pret = ?, notes = ?, dropbox_link = ?
            WHERE id = ?
        ");

        $stmt->execute([
            $nom, $adresse, $ville, $codePostal,
            $dateAcquisition, $dateDebutTravaux, $dateFinPrevue, $dateVente,
            $statut, $prixAchat, $roleEvaluation, $cession, $notaire, $taxeMutation, $quittance,
            $arpenteurs, $assuranceTitre, $soldeVendeur, $soldeAcheteur,
            $tempsAssumeMois, $valeurPotentielle,
            $tauxCommission, $tauxContingence,
            $tauxInteret, $montantPret, $notes, $dropboxLink,
            $projetId
        ]);

        // Sauvegarder les coûts récurrents dynamiques
        if (isset($_POST['recurrents']) && is_array($_POST['recurrents'])) {
            $stmtRec = $pdo->prepare("
                INSERT INTO projet_recurrents (projet_id, recurrent_type_id, montant)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE montant = VALUES(montant)
            ");
            foreach ($_POST['recurrents'] as $typeId => $montant) {
                $montantVal = parseNumber($montant);
                $stmtRec->execute([$projetId, (int)$typeId, $montantVal]);
            }
        }

        $pdo->commit();

        // Recalculer les indicateurs
        $projet = getProjetById($pdo, $projetId);
        $indicateurs = calculerIndicateursProjet($pdo, $projet);

        echo json_encode([
            'success' => true,
            'indicateurs' => [
                'valeur_potentielle' => $indicateurs['valeur_potentielle'],
                'equite_potentielle' => $indicateurs['equite_potentielle'],
                'equite_reelle' => $indicateurs['equite_reelle'],
                'roi_leverage' => $indicateurs['roi_leverage']
            ]
        ]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ========================================
// AJAX: Toggle checklist item
// ========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'toggle_checklist') {
    header('Content-Type: application/json');

    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'error' => 'Token invalide']);
        exit;
    }

    $itemId = (int)($_POST['item_id'] ?? 0);
    $complete = (int)($_POST['complete'] ?? 0);

    try {
        // Utiliser INSERT ... ON DUPLICATE KEY UPDATE pour créer ou mettre à jour
        $stmt = $pdo->prepare("
            INSERT INTO projet_checklists (projet_id, template_item_id, complete, complete_date, complete_by)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE complete = VALUES(complete), complete_date = VALUES(complete_date), complete_by = VALUES(complete_by)
        ");
        $stmt->execute([
            $projetId,
            $itemId,
            $complete,
            $complete ? date('Y-m-d H:i:s') : null,
            $complete ? ($_SESSION['user']['nom'] ?? 'Utilisateur') : null
        ]);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ========================================
// AJAX: Sauvegarder note checklist
// ========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'save_checklist_note') {
    header('Content-Type: application/json');

    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'error' => 'Token invalide']);
        exit;
    }

    $itemId = (int)($_POST['item_id'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');

    try {
        // Utiliser INSERT ... ON DUPLICATE KEY UPDATE pour créer ou mettre à jour
        $stmt = $pdo->prepare("
            INSERT INTO projet_checklists (projet_id, template_item_id, notes)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE notes = VALUES(notes)
        ");
        $stmt->execute([$projetId, $itemId, $notes ?: null]);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ========================================
// AJAX: Supprimer checklist item complètement
// ========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'delete_checklist_item') {
    header('Content-Type: application/json');

    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'error' => 'Token invalide']);
        exit;
    }

    $itemId = (int)($_POST['item_id'] ?? 0);

    try {
        // Supprimer les données de tous les projets pour cet item
        $stmt = $pdo->prepare("DELETE FROM projet_checklists WHERE template_item_id = ?");
        $stmt->execute([$itemId]);

        // Supprimer l'item du template
        $stmt = $pdo->prepare("DELETE FROM checklist_template_items WHERE id = ?");
        $stmt->execute([$itemId]);

        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ========================================
// AJAX: Changer statut facture
// ========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'change_facture_status') {
    header('Content-Type: application/json');

    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'error' => 'Token invalide']);
        exit;
    }

    $factureId = (int)($_POST['facture_id'] ?? 0);
    $newStatus = $_POST['new_status'] ?? '';
    $validStatuses = ['en_attente', 'approuvee', 'rejetee'];

    if (!in_array($newStatus, $validStatuses)) {
        echo json_encode(['success' => false, 'error' => 'Statut invalide']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("UPDATE factures SET statut = ? WHERE id = ?");
        $stmt->execute([$newStatus, $factureId]);
        echo json_encode(['success' => true, 'status' => $newStatus]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ========================================
// AJAX: Upload document (single or multiple)
// ========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'upload_document') {
    header('Content-Type: application/json');

    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'error' => 'Token invalide']);
        exit;
    }

    if (!isset($_FILES['documents'])) {
        echo json_encode(['success' => false, 'error' => 'Aucun fichier reçu']);
        exit;
    }

    $maxSize = 10 * 1024 * 1024; // 10 Mo
    $allowedTypes = ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                     'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                     'image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    // Créer le dossier si nécessaire
    $uploadDir = __DIR__ . '/../../uploads/documents/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $uploaded = [];
    $errors = [];

    // Normaliser le tableau de fichiers (pour gérer single et multiple)
    $files = $_FILES['documents'];
    $fileCount = is_array($files['name']) ? count($files['name']) : 1;

    for ($i = 0; $i < $fileCount; $i++) {
        $fileName = is_array($files['name']) ? $files['name'][$i] : $files['name'];
        $fileTmpName = is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'];
        $fileSize = is_array($files['size']) ? $files['size'][$i] : $files['size'];
        $fileType = is_array($files['type']) ? $files['type'][$i] : $files['type'];
        $fileError = is_array($files['error']) ? $files['error'][$i] : $files['error'];

        if ($fileError !== UPLOAD_ERR_OK) {
            $errors[] = "$fileName: Erreur d'upload";
            continue;
        }

        if ($fileSize > $maxSize) {
            $errors[] = "$fileName: Trop volumineux (max 10 Mo)";
            continue;
        }

        if (!in_array($fileType, $allowedTypes)) {
            $errors[] = "$fileName: Type non autorisé";
            continue;
        }

        // Générer un nom unique
        $extension = pathinfo($fileName, PATHINFO_EXTENSION);
        $nomFichier = $projetId . '_' . time() . '_' . uniqid() . '.' . $extension;
        $destination = $uploadDir . $nomFichier;

        if (move_uploaded_file($fileTmpName, $destination)) {
            try {
                $stmt = $pdo->prepare("INSERT INTO projet_documents (projet_id, nom, fichier, type, taille) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$projetId, $fileName, $nomFichier, $fileType, $fileSize]);
                $uploaded[] = ['id' => $pdo->lastInsertId(), 'name' => $fileName];
            } catch (Exception $e) {
                unlink($destination);
                $errors[] = "$fileName: " . $e->getMessage();
            }
        } else {
            $errors[] = "$fileName: Erreur lors du déplacement";
        }
    }

    echo json_encode([
        'success' => count($uploaded) > 0,
        'uploaded' => $uploaded,
        'errors' => $errors,
        'count' => count($uploaded)
    ]);
    exit;
}

// ========================================
// AJAX: Delete document
// ========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'delete_document') {
    header('Content-Type: application/json');

    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'error' => 'Token invalide']);
        exit;
    }

    $docId = (int)($_POST['doc_id'] ?? 0);

    try {
        // Récupérer le fichier
        $stmt = $pdo->prepare("SELECT fichier FROM projet_documents WHERE id = ? AND projet_id = ?");
        $stmt->execute([$docId, $projetId]);
        $doc = $stmt->fetch();

        if ($doc) {
            // Supprimer le fichier
            $filePath = __DIR__ . '/../../uploads/documents/' . $doc['fichier'];
            if (file_exists($filePath)) {
                unlink($filePath);
            }

            // Supprimer de la base
            $stmt = $pdo->prepare("DELETE FROM projet_documents WHERE id = ? AND projet_id = ?");
            $stmt->execute([$docId, $projetId]);
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Document non trouvé']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ========================================
// AJAX: Rename document
// ========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'rename_document') {
    header('Content-Type: application/json');

    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'error' => 'Token invalide']);
        exit;
    }

    $docId = (int)($_POST['doc_id'] ?? 0);
    $newName = trim($_POST['new_name'] ?? '');

    if (empty($newName)) {
        echo json_encode(['success' => false, 'error' => 'Le nom ne peut pas être vide']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("UPDATE projet_documents SET nom = ? WHERE id = ? AND projet_id = ?");
        $stmt->execute([$newName, $docId, $projetId]);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ========================================
// AJAX: Ajouter Google Sheet
// ========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'add_google_sheet') {
    header('Content-Type: application/json');

    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'error' => 'Token invalide']);
        exit;
    }

    $nom = trim($_POST['nom'] ?? '');
    $url = trim($_POST['url'] ?? '');

    if (empty($nom) || empty($url)) {
        echo json_encode(['success' => false, 'error' => 'Nom et URL requis']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO projet_google_sheets (projet_id, nom, url) VALUES (?, ?, ?)");
        $stmt->execute([$projetId, $nom, $url]);
        $newId = $pdo->lastInsertId();
        echo json_encode(['success' => true, 'id' => $newId, 'nom' => $nom, 'url' => $url]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ========================================
// AJAX: Modifier Google Sheet
// ========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'edit_google_sheet') {
    header('Content-Type: application/json');

    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'error' => 'Token invalide']);
        exit;
    }

    $sheetId = (int)($_POST['sheet_id'] ?? 0);
    $nom = trim($_POST['nom'] ?? '');
    $url = trim($_POST['url'] ?? '');

    try {
        $stmt = $pdo->prepare("UPDATE projet_google_sheets SET nom = ?, url = ? WHERE id = ? AND projet_id = ?");
        $stmt->execute([$nom, $url, $sheetId, $projetId]);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ========================================
// AJAX: Supprimer Google Sheet
// ========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'delete_google_sheet') {
    header('Content-Type: application/json');

    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'error' => 'Token invalide']);
        exit;
    }

    $sheetId = (int)($_POST['sheet_id'] ?? 0);

    try {
        $stmt = $pdo->prepare("DELETE FROM projet_google_sheets WHERE id = ? AND projet_id = ?");
        $stmt->execute([$sheetId, $projetId]);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// [CODE OBSOLÈTE SUPPRIMÉ: save_budget - Remplacé par save_budget_builder (JSON)]

// ========================================
// AJAX: Mise à jour d'un item (prix et/ou quantité)
// ========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'update_item_data') {
    header('Content-Type: application/json');

    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'error' => 'Token invalide']);
        exit;
    }

    $catId = (int)($_POST['cat_id'] ?? 0);
    $matId = (int)($_POST['mat_id'] ?? 0);

    try {
        $updates = [];
        $params = [];

        if (isset($_POST['prix'])) {
            $updates[] = "prix_unitaire = ?";
            $params[] = parseNumber($_POST['prix']);
        }
        if (isset($_POST['qte'])) {
            $updates[] = "quantite = ?";
            $params[] = max(1, (int)$_POST['qte']);
        }

        if (!empty($updates)) {
            $params[] = $projetId;
            $params[] = $matId;

            $stmt = $pdo->prepare("
                UPDATE projet_items
                SET " . implode(', ', $updates) . "
                WHERE projet_id = ? AND materiau_id = ?
            ");
            $stmt->execute($params);
        }

        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ========================================
// AJAX: Supprimer un matériau du projet
// ========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'remove_material') {
    header('Content-Type: application/json');

    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'error' => 'Token invalide']);
        exit;
    }

    $matId = (int)($_POST['mat_id'] ?? 0);

    try {
        $stmt = $pdo->prepare("DELETE FROM projet_items WHERE projet_id = ? AND materiau_id = ?");
        $stmt->execute([$projetId, $matId]);

        // Sync la table budgets
        syncBudgetsFromProjetItems($pdo, $projetId);

        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ========================================
// AJAX: Ajouter un item par drag-drop (supporte form-data ET JSON)
// ========================================
$dropData = null;
$jsonInputDrop = file_get_contents('php://input');
$jsonDataDrop = json_decode($jsonInputDrop, true);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($jsonDataDrop['ajax_action']) && $jsonDataDrop['ajax_action'] === 'add_dropped_item') {
    $dropData = $jsonDataDrop;
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'add_dropped_item') {
    $dropData = $_POST;
}

if ($dropData) {
    header('Content-Type: application/json');

    if (!verifyCSRFToken($dropData['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'error' => 'Token invalide']);
        exit;
    }

    $type = $dropData['type'] ?? '';
    $itemId = (int)($dropData['item_id'] ?? 0);
    $catId = (int)($dropData['cat_id'] ?? 0);
    $groupe = $dropData['groupe'] ?? '';
    $prix = parseNumber($dropData['prix'] ?? 0);
    $qte = max(1, (int)($dropData['qte'] ?? 1));
    $descendants = isset($dropData['descendants']) ? json_decode($dropData['descendants'], true) : null;

    try {
        $addedItems = []; // Liste des items ajoutés pour retourner au frontend
        $posteId = null;

        // Si c'est une sous-catégorie droppée directement, marquer comme direct drop
        if ($type === 'sous_categorie') {
            // Enregistrer cette sous-catégorie comme un "direct drop" (entrée autonome)
            $stmt = $pdo->prepare("
                INSERT INTO projet_sous_categories (projet_id, sous_categorie_id, quantite, is_direct_drop, groupe)
                VALUES (?, ?, 1, 1, ?)
                ON DUPLICATE KEY UPDATE is_direct_drop = 1, groupe = VALUES(groupe)
            ");
            $stmt->execute([$projetId, $itemId, $groupe]);

            // On a quand même besoin d'un projet_poste pour stocker les matériaux
            // Utiliser la catégorie parente de cette sous-catégorie
            $stmt = $pdo->prepare("SELECT categorie_id FROM sous_categories WHERE id = ?");
            $stmt->execute([$itemId]);
            $scRow = $stmt->fetch();
            if ($scRow) {
                $catId = (int)$scRow['categorie_id'];
            }
        }

        // Créer un projet_poste si nécessaire (pour catégories, matériaux ET sous-catégories direct drop)
        $stmt = $pdo->prepare("SELECT id FROM projet_postes WHERE projet_id = ? AND categorie_id = ?");
        $stmt->execute([$projetId, $catId]);
        $poste = $stmt->fetch();

        if (!$poste) {
            // Créer le projet_poste
            $stmt = $pdo->prepare("INSERT INTO projet_postes (projet_id, categorie_id, quantite) VALUES (?, ?, 1)");
            $stmt->execute([$projetId, $catId]);
            $posteId = $pdo->lastInsertId();
        } else {
            $posteId = $poste['id'];
        }

        if ($type === 'materiau') {
            // Ajouter un seul matériau
            $stmt = $pdo->prepare("SELECT id FROM projet_items WHERE projet_id = ? AND materiau_id = ?");
            $stmt->execute([$projetId, $itemId]);
            $existing = $stmt->fetch();

            if (!$existing) {
                $stmt = $pdo->prepare("
                    INSERT INTO projet_items (projet_id, projet_poste_id, materiau_id, prix_unitaire, quantite, sans_taxe)
                    VALUES (?, ?, ?, ?, ?, 0)
                ");
                $stmt->execute([$projetId, $posteId, $itemId, $prix, $qte]);

                // Récupérer les infos du matériau pour le retour
                // NOTE: la colonne est prix_defaut (pas prix_unitaire) dans cette DB
                $stmt = $pdo->prepare("SELECT id, nom, prix_defaut FROM materiaux WHERE id = ?");
                $stmt->execute([$itemId]);
                $mat = $stmt->fetch();
                if ($mat) {
                    $addedItems[] = [
                        'mat_id' => $mat['id'],
                        'nom' => $mat['nom'],
                        'prix' => $prix,
                        'qte' => $qte,
                        'sans_taxe' => 0
                    ];
                }
            }
        } elseif ($type === 'categorie' || $type === 'sous_categorie') {
            // Ajouter tous les matériaux de cette catégorie/sous-catégorie

            // Fonction récursive pour récupérer tous les IDs de sous-catégories
            $allSousCatIds = [];

            if ($type === 'categorie') {
                // Récupérer toutes les sous-catégories de cette catégorie
                $stmt = $pdo->prepare("SELECT id FROM sous_categories WHERE categorie_id = ? AND actif = 1");
                $stmt->execute([$itemId]);
            } else {
                // Récupérer cette sous-catégorie et ses enfants
                $stmt = $pdo->prepare("SELECT id FROM sous_categories WHERE (id = ? OR parent_id = ?) AND actif = 1");
                $stmt->execute([$itemId, $itemId]);
            }

            while ($row = $stmt->fetch()) {
                $allSousCatIds[] = $row['id'];
            }

            // Récupérer aussi les sous-sous-catégories (récursif niveau 2)
            if (!empty($allSousCatIds)) {
                $placeholders = implode(',', array_fill(0, count($allSousCatIds), '?'));
                $stmt = $pdo->prepare("SELECT id FROM sous_categories WHERE parent_id IN ($placeholders) AND actif = 1");
                $stmt->execute($allSousCatIds);
                while ($row = $stmt->fetch()) {
                    $allSousCatIds[] = $row['id'];
                }
            }

            // Récupérer tous les matériaux de ces sous-catégories
            if (!empty($allSousCatIds)) {
                $placeholders = implode(',', array_fill(0, count($allSousCatIds), '?'));
                $stmt = $pdo->prepare("SELECT id, nom, prix_defaut, quantite_defaut FROM materiaux WHERE sous_categorie_id IN ($placeholders) AND actif = 1");
                $stmt->execute($allSousCatIds);
                $materiaux = $stmt->fetchAll();

                foreach ($materiaux as $mat) {
                    // Vérifier si le matériau existe déjà
                    $checkStmt = $pdo->prepare("SELECT id FROM projet_items WHERE projet_id = ? AND materiau_id = ?");
                    $checkStmt->execute([$projetId, $mat['id']]);

                    if (!$checkStmt->fetch()) {
                        $matPrix = (float)($mat['prix_defaut'] ?? 0);
                        $matQte = max(1, (int)($mat['quantite_defaut'] ?? 1));

                        $insertStmt = $pdo->prepare("
                            INSERT INTO projet_items (projet_id, projet_poste_id, materiau_id, prix_unitaire, quantite, sans_taxe)
                            VALUES (?, ?, ?, ?, ?, 0)
                        ");
                        $insertStmt->execute([$projetId, $posteId, $mat['id'], $matPrix, $matQte]);

                        $addedItems[] = [
                            'mat_id' => $mat['id'],
                            'nom' => $mat['nom'],
                            'prix' => $matPrix,
                            'qte' => $matQte,
                            'sans_taxe' => 0
                        ];
                    }
                }
            }
        }

        // Si on a des descendants (structure imbriquée), les sauvegarder récursivement
        if ($descendants && is_array($descendants)) {
            saveDescendantsRecursively($pdo, $projetId, $posteId, $descendants, $addedItems);
        }

        // Sync la table budgets
        syncBudgetsFromProjetItems($pdo, $projetId);

        echo json_encode([
            'success' => true,
            'poste_id' => $posteId,
            'added_items' => $addedItems,
            'count' => count($addedItems)
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Fonction récursive pour sauvegarder les descendants (sous-catégories et matériaux)
function saveDescendantsRecursively($pdo, $projetId, $posteId, $descendants, &$addedItems) {
    foreach ($descendants as $item) {
        if ($item['type'] === 'sous_categorie') {
            $scId = (int)$item['id'];

            // Sauvegarder/initialiser la quantité de la sous-catégorie (défaut 1)
            $stmt = $pdo->prepare("
                INSERT INTO projet_sous_categories (projet_id, sous_categorie_id, quantite)
                VALUES (?, ?, 1)
                ON DUPLICATE KEY UPDATE sous_categorie_id = sous_categorie_id
            ");
            $stmt->execute([$projetId, $scId]);

            // Sauvegarder les matériaux de cette sous-catégorie
            if (isset($item['materiaux']) && is_array($item['materiaux'])) {
                foreach ($item['materiaux'] as $mat) {
                    $matId = (int)$mat['id'];
                    $matPrix = (float)($mat['prix'] ?? 0);
                    $matQte = max(1, (int)($mat['qte'] ?? 1));

                    // Vérifier si le matériau existe déjà
                    $checkStmt = $pdo->prepare("SELECT id FROM projet_items WHERE projet_id = ? AND materiau_id = ?");
                    $checkStmt->execute([$projetId, $matId]);

                    if (!$checkStmt->fetch()) {
                        $insertStmt = $pdo->prepare("
                            INSERT INTO projet_items (projet_id, projet_poste_id, materiau_id, prix_unitaire, quantite, sans_taxe)
                            VALUES (?, ?, ?, ?, ?, ?)
                        ");
                        $sansTaxe = !empty($mat['sansTaxe']) ? 1 : 0;
                        $insertStmt->execute([$projetId, $posteId, $matId, $matPrix, $matQte, $sansTaxe]);

                        $addedItems[] = [
                            'mat_id' => $matId,
                            'nom' => $mat['nom'] ?? '',
                            'prix' => $matPrix,
                            'qte' => $matQte,
                            'sans_taxe' => $sansTaxe,
                            'sc_id' => $scId
                        ];
                    }
                }
            }

            // Récursion pour les sous-sous-catégories
            if (isset($item['enfants']) && is_array($item['enfants'])) {
                saveDescendantsRecursively($pdo, $projetId, $posteId, $item['enfants'], $addedItems);
            }
        } elseif ($item['type'] === 'materiau') {
            // Matériau direct (pas dans une sous-catégorie)
            $matId = (int)$item['id'];
            $matPrix = (float)($item['prix'] ?? 0);
            $matQte = max(1, (int)($item['qte'] ?? 1));

            $checkStmt = $pdo->prepare("SELECT id FROM projet_items WHERE projet_id = ? AND materiau_id = ?");
            $checkStmt->execute([$projetId, $matId]);

            if (!$checkStmt->fetch()) {
                $insertStmt = $pdo->prepare("
                    INSERT INTO projet_items (projet_id, projet_poste_id, materiau_id, prix_unitaire, quantite, sans_taxe)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $sansTaxe = !empty($item['sansTaxe']) ? 1 : 0;
                $insertStmt->execute([$projetId, $posteId, $matId, $matPrix, $matQte, $sansTaxe]);

                $addedItems[] = [
                    'mat_id' => $matId,
                    'nom' => $item['nom'] ?? '',
                    'prix' => $matPrix,
                    'qte' => $matQte,
                    'sans_taxe' => $sansTaxe
                ];
            }
        }
    }
}

// ========================================
// AJAX: Sauvegarde complète du budget builder (JSON)
// ========================================
$jsonInput = file_get_contents('php://input');
$jsonData = json_decode($jsonInput, true);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($jsonData['ajax_action']) && $jsonData['ajax_action'] === 'save_budget_builder') {
    header('Content-Type: application/json');

    if (!verifyCSRFToken($jsonData['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'error' => 'Token invalide']);
        exit;
    }

    try {
        // Sauvegarder les quantités des groupes dans projet_groupes
        if (isset($jsonData['groupes'])) {
            foreach ($jsonData['groupes'] as $groupe => $qte) {
                $stmt = $pdo->prepare("
                    INSERT INTO projet_groupes (projet_id, groupe_nom, quantite)
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE quantite = VALUES(quantite)
                ");
                $stmt->execute([$projetId, $groupe, $qte]);
            }
        }

        // Collecter les IDs des catégories et sous-catégories présentes dans le DOM
        $presentCatIds = [];
        $presentScIds = [];

        if (isset($jsonData['items'])) {
            foreach ($jsonData['items'] as $item) {
                $type = $item['type'] ?? 'categorie';
                $qte = $item['quantite'] ?? 1;
                $id = (int)$item['id'];

                if ($type === 'categorie') {
                    $presentCatIds[] = $id;
                    // Catégorie: mettre à jour projet_postes
                    $stmt = $pdo->prepare("
                        UPDATE projet_postes
                        SET quantite = ?
                        WHERE projet_id = ? AND categorie_id = ?
                    ");
                    $stmt->execute([$qte, $projetId, $id]);
                } else if ($type === 'sous_categorie') {
                    $presentScIds[] = $id;
                    // Sous-catégorie: insérer/mettre à jour projet_sous_categories
                    $stmt = $pdo->prepare("
                        INSERT INTO projet_sous_categories (projet_id, sous_categorie_id, quantite)
                        VALUES (?, ?, ?)
                        ON DUPLICATE KEY UPDATE quantite = VALUES(quantite)
                    ");
                    $stmt->execute([$projetId, $id, $qte]);
                }
            }
        }

        // Supprimer les catégories qui ne sont plus présentes dans le DOM
        if (!empty($presentCatIds)) {
            $placeholders = implode(',', array_fill(0, count($presentCatIds), '?'));
            $params = array_merge([$projetId], $presentCatIds);

            // D'abord supprimer les items liés aux postes qui vont être supprimés
            $stmt = $pdo->prepare("
                DELETE pi FROM projet_items pi
                JOIN projet_postes pp ON pi.projet_poste_id = pp.id
                WHERE pp.projet_id = ? AND pp.categorie_id NOT IN ($placeholders)
            ");
            $stmt->execute($params);

            // Ensuite supprimer les postes
            $stmt = $pdo->prepare("
                DELETE FROM projet_postes
                WHERE projet_id = ? AND categorie_id NOT IN ($placeholders)
            ");
            $stmt->execute($params);
        } else {
            // Aucune catégorie présente = tout supprimer
            $stmt = $pdo->prepare("
                DELETE pi FROM projet_items pi
                JOIN projet_postes pp ON pi.projet_poste_id = pp.id
                WHERE pp.projet_id = ?
            ");
            $stmt->execute([$projetId]);

            $stmt = $pdo->prepare("DELETE FROM projet_postes WHERE projet_id = ?");
            $stmt->execute([$projetId]);
        }

        // Supprimer les sous-catégories qui ne sont plus présentes dans le DOM
        if (!empty($presentScIds)) {
            $placeholders = implode(',', array_fill(0, count($presentScIds), '?'));
            $params = array_merge([$projetId], $presentScIds);
            $stmt = $pdo->prepare("
                DELETE FROM projet_sous_categories
                WHERE projet_id = ? AND sous_categorie_id NOT IN ($placeholders)
            ");
            $stmt->execute($params);
        } else {
            // Aucune sous-catégorie présente = tout supprimer
            $stmt = $pdo->prepare("
                DELETE FROM projet_sous_categories
                WHERE projet_id = ?
            ");
            $stmt->execute([$projetId]);
        }

        // Sync la table budgets
        syncBudgetsFromProjetItems($pdo, $projetId);

        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ========================================
// AJAX: Vider tout le budget
// ========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'clear_all_budget') {
    header('Content-Type: application/json');

    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'error' => 'Token invalide']);
        exit;
    }

    try {
        // Supprimer tous les items du projet
        $stmt = $pdo->prepare("DELETE FROM projet_items WHERE projet_id = ?");
        $stmt->execute([$projetId]);

        // Supprimer tous les postes du projet
        $stmt = $pdo->prepare("DELETE FROM projet_postes WHERE projet_id = ?");
        $stmt->execute([$projetId]);

        // Supprimer les quantités de groupes
        $stmt = $pdo->prepare("DELETE FROM projet_groupes WHERE projet_id = ?");
        $stmt->execute([$projetId]);

        // Supprimer les quantités de sous-catégories
        $stmt = $pdo->prepare("DELETE FROM projet_sous_categories WHERE projet_id = ?");
        $stmt->execute([$projetId]);

        // IMPORTANT: Supprimer aussi de la table budgets pour sync avec Détail des coûts
        $stmt = $pdo->prepare("DELETE FROM budgets WHERE projet_id = ?");
        $stmt->execute([$projetId]);

        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

$projet = getProjetById($pdo, $projetId);

if (!$projet) {
    setFlashMessage('danger', 'Projet non trouvé.');
    redirect('/admin/projets/liste.php');
}

// Récupérer les catégories avec budgets
$stmt = $pdo->prepare("
    SELECT c.*, COALESCE(b.montant_extrapole, 0) as montant_extrapole
    FROM categories c
    LEFT JOIN budgets b ON c.id = b.categorie_id AND b.projet_id = ?
    ORDER BY c.groupe, c.ordre
");
$stmt->execute([$projetId]);
$categoriesAvecBudget = $stmt->fetchAll();

// Grouper par catégorie
$categoriesGroupees = [];
foreach ($categoriesAvecBudget as $cat) {
    $categoriesGroupees[$cat['groupe']][] = $cat;
}

$groupeLabels = [
    'exterieur' => 'Extérieur',
    'finition' => 'Finition intérieure',
    'ebenisterie' => 'Ébénisterie',
    'electricite' => 'Électricité',
    'plomberie' => 'Plomberie',
    'autre' => 'Autre'
];

// Récupérer les prêteurs/investisseurs disponibles
$stmt = $pdo->query("SELECT * FROM investisseurs ORDER BY nom");
$tousInvestisseurs = $stmt->fetchAll();

// Récupérer les prêteurs liés à ce projet
try {
    $stmt = $pdo->prepare("
        SELECT pi.*, i.nom as investisseur_nom,
               COALESCE(pi.type_financement, 'preteur') as type_calc,
               COALESCE(pi.pourcentage_profit, 0) as pourcentage_profit
        FROM projet_investisseurs pi
        JOIN investisseurs i ON pi.investisseur_id = i.id
        WHERE pi.projet_id = ?
        ORDER BY i.nom
    ");
    $stmt->execute([$projetId]);
    $preteursProjet = $stmt->fetchAll();
} catch (Exception $e) {
    $preteursProjet = [];
}

// Récupérer tous les employés actifs
$stmt = $pdo->query("SELECT id, CONCAT(prenom, ' ', nom) as nom_complet, taux_horaire, role FROM users WHERE actif = 1 ORDER BY prenom, nom");
$employes = $stmt->fetchAll();

// Récupérer les planifications existantes
$planifications = [];
try {
    $stmt = $pdo->prepare("SELECT user_id, heures_semaine_estimees FROM projet_planification_heures WHERE projet_id = ?");
    $stmt->execute([$projetId]);
    while ($row = $stmt->fetch()) {
        $planifications[$row['user_id']] = (float)$row['heures_semaine_estimees'];
    }
} catch (Exception $e) {}

$errors = [];

// ========================================
// TRAITEMENT DES FORMULAIRES POST
// ========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token de sécurité invalide.';
    } else {
        $action = $_POST['action'] ?? 'general';

        if ($action === 'general') {
            $nom = trim($_POST['nom'] ?? '');
            $adresse = trim($_POST['adresse'] ?? '');
            $ville = trim($_POST['ville'] ?? '');
            $codePostal = trim($_POST['code_postal'] ?? '');
            $dateAcquisition = $_POST['date_acquisition'] ?: null;
            $dateDebutTravaux = $_POST['date_debut_travaux'] ?: null;
            $dateFinPrevue = $_POST['date_fin_prevue'] ?: null;
            $dateVente = $_POST['date_vente'] ?: null;
            $statut = $_POST['statut'] ?? 'acquisition';

            $prixAchat = parseNumber($_POST['prix_achat'] ?? 0);
            $roleEvaluation = parseNumber($_POST['role_evaluation'] ?? 0);
            $cession = parseNumber($_POST['cession'] ?? 0);
            $notaire = parseNumber($_POST['notaire'] ?? 0);
            $taxeMutation = parseNumber($_POST['taxe_mutation'] ?? 0);
            $quittance = parseNumber($_POST['quittance'] ?? 0);
            $arpenteurs = parseNumber($_POST['arpenteurs'] ?? 0);
            $assuranceTitre = parseNumber($_POST['assurance_titre'] ?? 0);
            $soldeVendeur = parseNumber($_POST['solde_vendeur'] ?? 0);
            $soldeAcheteur = parseNumber($_POST['solde_acheteur'] ?? 0);

            $taxesMunicipalesAnnuel = parseNumber($_POST['taxes_municipales_annuel'] ?? 0);
            $taxesScolairesAnnuel = parseNumber($_POST['taxes_scolaires_annuel'] ?? 0);
            $electriciteAnnuel = parseNumber($_POST['electricite_annuel'] ?? 0);
            $assurancesAnnuel = parseNumber($_POST['assurances_annuel'] ?? 0);
            $deneigementAnnuel = parseNumber($_POST['deneigement_annuel'] ?? 0);
            $fraisCondoAnnuel = parseNumber($_POST['frais_condo_annuel'] ?? 0);
            $hypothequeMensuel = parseNumber($_POST['hypotheque_mensuel'] ?? 0);
            $loyerMensuel = parseNumber($_POST['loyer_mensuel'] ?? 0);

            $tempsAssumeMois = (int)($_POST['temps_assume_mois'] ?? 6);
            $valeurPotentielle = parseNumber($_POST['valeur_potentielle'] ?? 0);

            $tauxCommission = parseNumber($_POST['taux_commission'] ?? 4);
            $tauxContingence = parseNumber($_POST['taux_contingence'] ?? 15);
            $tauxInteret = parseNumber($_POST['taux_interet'] ?? 10);
            $montantPret = parseNumber($_POST['montant_pret'] ?? 0);

            $notes = trim($_POST['notes'] ?? '');
            $dropboxLink = trim($_POST['dropbox_link'] ?? '');

            if (empty($nom)) $errors[] = 'Le nom du projet est requis.';
            if (empty($adresse)) $errors[] = 'L\'adresse est requise.';
            if (empty($ville)) $errors[] = 'La ville est requise.';

            if (empty($errors)) {
                $stmt = $pdo->prepare("
                    UPDATE projets SET
                        nom = ?, adresse = ?, ville = ?, code_postal = ?,
                        date_acquisition = ?, date_debut_travaux = ?, date_fin_prevue = ?, date_vente = ?,
                        statut = ?, prix_achat = ?, role_evaluation = ?, cession = ?, notaire = ?, taxe_mutation = ?, quittance = ?,
                        arpenteurs = ?, assurance_titre = ?, solde_vendeur = ?, solde_acheteur = ?,
                        taxes_municipales_annuel = ?, taxes_scolaires_annuel = ?,
                        electricite_annuel = ?, assurances_annuel = ?,
                        deneigement_annuel = ?, frais_condo_annuel = ?,
                        hypotheque_mensuel = ?, loyer_mensuel = ?,
                        temps_assume_mois = ?, valeur_potentielle = ?,
                        taux_commission = ?, taux_contingence = ?,
                        taux_interet = ?, montant_pret = ?, notes = ?, dropbox_link = ?
                    WHERE id = ?
                ");

                $stmt->execute([
                    $nom, $adresse, $ville, $codePostal,
                    $dateAcquisition, $dateDebutTravaux, $dateFinPrevue, $dateVente,
                    $statut, $prixAchat, $roleEvaluation, $cession, $notaire, $taxeMutation, $quittance,
                    $arpenteurs, $assuranceTitre, $soldeVendeur, $soldeAcheteur,
                    $taxesMunicipalesAnnuel, $taxesScolairesAnnuel,
                    $electriciteAnnuel, $assurancesAnnuel,
                    $deneigementAnnuel, $fraisCondoAnnuel,
                    $hypothequeMensuel, $loyerMensuel,
                    $tempsAssumeMois, $valeurPotentielle,
                    $tauxCommission, $tauxContingence,
                    $tauxInteret, $montantPret, $notes, $dropboxLink,
                    $projetId
                ]);

                // Sauvegarder les coûts récurrents dynamiques
                if (isset($_POST['recurrents']) && is_array($_POST['recurrents'])) {
                    $stmtRec = $pdo->prepare("
                        INSERT INTO projet_recurrents (projet_id, recurrent_type_id, montant)
                        VALUES (?, ?, ?)
                        ON DUPLICATE KEY UPDATE montant = VALUES(montant)
                    ");
                    foreach ($_POST['recurrents'] as $typeId => $montant) {
                        $montantVal = parseNumber($montant);
                        $stmtRec->execute([$projetId, (int)$typeId, $montantVal]);
                    }
                }

                setFlashMessage('success', 'Projet mis à jour!');
                redirect('/admin/projets/detail.php?id=' . $projetId . '&tab=base');
            }
        } elseif ($action === 'preteurs') {
            $subAction = $_POST['sub_action'] ?? '';

            if ($subAction === 'ajouter') {
                $investisseurId = (int)($_POST['investisseur_id'] ?? 0);
                $montant = parseNumber($_POST['montant_pret'] ?? 0);
                $tauxInteret = parseNumber($_POST['taux_interet_pret'] ?? 10);
                $typeFinancement = $_POST['type_financement'] ?? 'preteur';
                $pourcentageProfit = parseNumber($_POST['pourcentage_profit'] ?? 0);

                if ($investisseurId && ($montant > 0 || $pourcentageProfit > 0)) {
                    // Permet plusieurs prêts/investissements du même prêteur sur un projet
                    $stmt = $pdo->prepare("
                        INSERT INTO projet_investisseurs (projet_id, investisseur_id, type_financement, montant, taux_interet, pourcentage_profit)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$projetId, $investisseurId, $typeFinancement, $montant, $tauxInteret, $pourcentageProfit]);
                    setFlashMessage('success', $typeFinancement === 'investisseur' ? 'Investisseur ajouté!' : 'Prêteur ajouté!');
                }
            } elseif ($subAction === 'supprimer') {
                $preteurId = (int)($_POST['preteur_id'] ?? 0);
                if ($preteurId) {
                    $stmt = $pdo->prepare("DELETE FROM projet_investisseurs WHERE id = ? AND projet_id = ?");
                    $stmt->execute([$preteurId, $projetId]);
                    setFlashMessage('success', 'Supprimé.');
                }
            } elseif ($subAction === 'modifier') {
                $preteurId = (int)($_POST['preteur_id'] ?? 0);
                $montant = parseNumber($_POST['montant_pret'] ?? 0);
                $tauxInteret = parseNumber($_POST['taux_interet_pret'] ?? 0);
                $pourcentageProfit = parseNumber($_POST['pourcentage_profit'] ?? 0);

                if ($preteurId && ($montant > 0 || $pourcentageProfit > 0)) {
                    $stmt = $pdo->prepare("
                        UPDATE projet_investisseurs
                        SET montant = ?, taux_interet = ?, pourcentage_profit = ?
                        WHERE id = ? AND projet_id = ?
                    ");
                    $stmt->execute([$montant, $tauxInteret, $pourcentageProfit, $preteurId, $projetId]);
                    setFlashMessage('success', 'Financement mis à jour!');
                }
            } elseif ($subAction === 'convertir') {
                // Convertir prêteur <-> investisseur
                $preteurId = (int)($_POST['preteur_id'] ?? 0);
                $nouveauType = $_POST['nouveau_type'] ?? '';

                if ($preteurId && in_array($nouveauType, ['preteur', 'investisseur'])) {
                    $stmt = $pdo->prepare("
                        UPDATE projet_investisseurs
                        SET type_financement = ?, taux_interet = ?
                        WHERE id = ? AND projet_id = ?
                    ");
                    // Si on convertit en investisseur, on met le taux à 0
                    $nouveauTaux = ($nouveauType === 'investisseur') ? 0 : 10;
                    $stmt->execute([$nouveauType, $nouveauTaux, $preteurId, $projetId]);
                    setFlashMessage('success', 'Converti en ' . ($nouveauType === 'investisseur' ? 'investisseur' : 'prêteur') . '!');
                }
            }
            redirect('/admin/projets/detail.php?id=' . $projetId . '&tab=financement');

        } elseif ($action === 'planification') {
            $heures = $_POST['heures'] ?? [];

            foreach ($heures as $userId => $heuresSemaine) {
                $heuresSemaine = parseNumber($heuresSemaine);

                if ($heuresSemaine > 0) {
                    $stmt = $pdo->prepare("
                        INSERT INTO projet_planification_heures (projet_id, user_id, heures_semaine_estimees)
                        VALUES (?, ?, ?)
                        ON DUPLICATE KEY UPDATE heures_semaine_estimees = ?
                    ");
                    $stmt->execute([$projetId, $userId, $heuresSemaine, $heuresSemaine]);
                } else {
                    $stmt = $pdo->prepare("DELETE FROM projet_planification_heures WHERE projet_id = ? AND user_id = ?");
                    $stmt->execute([$projetId, $userId]);
                }
            }

            setFlashMessage('success', 'Planification mise à jour!');
            redirect('/admin/projets/detail.php?id=' . $projetId . '&tab=maindoeuvre');

        }
        // [CODE OBSOLÈTE SUPPRIMÉ: postes_budgets]
    }
}

// Recharger le projet après modifications
$projet = getProjetById($pdo, $projetId);
$tab = $_GET['tab'] ?? 'base';
$pageTitle = $projet['nom'];

// IMPORTANT: Synchroniser budgets AVANT de calculer les indicateurs
// pour que calculerIndicateursProjet ait les bonnes valeurs de rénovation
syncBudgetsFromProjetItems($pdo, $projetId);

$indicateurs = calculerIndicateursProjet($pdo, $projet);

// Sauvegarder le dernier projet visité (pour raccourci "Projet récent")
$_SESSION['last_project_id'] = $projetId;
$_SESSION['last_project_name'] = $projet['nom'];

// Durée réelle (cohérent avec calculs.php)
$dureeReelle = (int)$projet['temps_assume_mois'];
if (!empty($projet['date_vente']) && !empty($projet['date_acquisition'])) {
    $dateAchat = new DateTime($projet['date_acquisition']);
    $dateVente = new DateTime($projet['date_vente']);
    $diff = $dateAchat->diff($dateVente);
    $dureeReelle = ($diff->y * 12) + $diff->m;
    // Ajouter 1 mois si on a des jours supplémentaires (mois entamé = mois complet pour les intérêts)
    if ($diff->d > 0) {
        $dureeReelle++;
    }
    $dureeReelle = max(1, $dureeReelle);
}

$categories = getCategories($pdo);
// syncBudgetsFromProjetItems déjà appelé plus haut avant calculerIndicateursProjet
$budgets = getBudgetsParCategorie($pdo, $projetId);
$depenses = calculerDepensesParCategorie($pdo, $projetId);

// Calcul des coûts récurrents réels basés sur le temps écoulé depuis l'achat
$recurrentsReels = calculerCoutsRecurrentsReels($projet);
$moisEcoules = $recurrentsReels['mois_ecoules'];

// ========================================
// TYPES DE COÛTS RÉCURRENTS DYNAMIQUES
// ========================================
$recurrentsTypes = [];
$projetRecurrents = [];

// Charger les types de récurrents actifs
try {
    $stmt = $pdo->query("SELECT * FROM recurrents_types WHERE actif = 1 ORDER BY ordre, nom");
    $recurrentsTypes = $stmt->fetchAll();
} catch (Exception $e) {
    // Table n'existe pas encore
}

// Charger les valeurs récurrentes pour ce projet
try {
    $stmt = $pdo->prepare("SELECT recurrent_type_id, montant FROM projet_recurrents WHERE projet_id = ?");
    $stmt->execute([$projetId]);
    foreach ($stmt->fetchAll() as $row) {
        $projetRecurrents[$row['recurrent_type_id']] = (float)$row['montant'];
    }
} catch (Exception $e) {
    // Table n'existe pas encore
}

// Migrer les valeurs existantes des colonnes projets vers projet_recurrents (une seule fois)
if (empty($projetRecurrents) && !empty($recurrentsTypes)) {
    $codeToValue = [
        'taxes_municipales' => (float)$projet['taxes_municipales_annuel'],
        'taxes_scolaires' => (float)$projet['taxes_scolaires_annuel'],
        'electricite' => (float)$projet['electricite_annuel'],
        'assurances' => (float)$projet['assurances_annuel'],
        'deneigement' => (float)$projet['deneigement_annuel'],
        'frais_condo' => (float)$projet['frais_condo_annuel'],
        'hypotheque' => (float)$projet['hypotheque_mensuel'],
        'loyer' => (float)$projet['loyer_mensuel'],
    ];

    $hasValues = array_filter($codeToValue, fn($v) => $v > 0);
    if (!empty($hasValues)) {
        try {
            $stmtInsert = $pdo->prepare("INSERT IGNORE INTO projet_recurrents (projet_id, recurrent_type_id, montant) VALUES (?, ?, ?)");
            foreach ($recurrentsTypes as $type) {
                $value = $codeToValue[$type['code']] ?? 0;
                if ($value > 0) {
                    $stmtInsert->execute([$projetId, $type['id'], $value]);
                    $projetRecurrents[$type['id']] = $value;
                }
            }
        } catch (Exception $e) {
            // Ignorer les erreurs de migration
        }
    }
}

// ========================================
// DONNÉES TEMPLATES BUDGETS DÉTAILLÉS (structure récursive comme templates)
// ========================================
$templatesBudgets = [];
$projetPostes = [];
$projetItems = [];
$projetGroupes = [];

/**
 * Récupérer les sous-catégories de façon récursive (même logique que templates/liste.php)
 */
function getSousCategoriesRecursifBudget($pdo, $categorieId, $parentId = null) {
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
        $sc['enfants'] = getSousCategoriesRecursifBudget($pdo, $categorieId, $sc['id']);
    }

    return $sousCategories;
}

try {
    // Charger les catégories avec leur structure récursive
    $stmt = $pdo->query("SELECT * FROM categories ORDER BY groupe, ordre, nom");
    $categories = $stmt->fetchAll();

    foreach ($categories as $catIndex => $cat) {
        $catId = $cat['id'];
        $templatesBudgets[$catId] = [
            'id' => $catId,
            'nom' => $cat['nom'],
            'groupe' => $cat['groupe'],
            'ordre' => $cat['ordre'] ?? $catIndex,
            'sous_categories' => getSousCategoriesRecursifBudget($pdo, $catId)
        ];
    }

    // Charger les postes existants du projet
    $stmt = $pdo->prepare("SELECT * FROM projet_postes WHERE projet_id = ?");
    $stmt->execute([$projetId]);
    foreach ($stmt->fetchAll() as $row) {
        $projetPostes[$row['categorie_id']] = $row;
    }

    // Charger les items existants du projet
    $stmt = $pdo->prepare("
        SELECT pi.*, pp.categorie_id
        FROM projet_items pi
        JOIN projet_postes pp ON pi.projet_poste_id = pp.id
        WHERE pi.projet_id = ?
    ");
    $stmt->execute([$projetId]);
    foreach ($stmt->fetchAll() as $row) {
        $projetItems[$row['categorie_id']][$row['materiau_id']] = $row;
    }

    // Charger les quantités de groupes existantes
    $stmt = $pdo->prepare("SELECT groupe_nom, quantite FROM projet_groupes WHERE projet_id = ?");
    $stmt->execute([$projetId]);
    foreach ($stmt->fetchAll() as $row) {
        $projetGroupes[$row['groupe_nom']] = (int)$row['quantite'];
    }

    // Charger les quantités des sous-catégories existantes + les direct drops
    $projetSousCategories = [];
    $projetDirectDrops = []; // sous-catégories droppées directement comme entrées autonomes
    $stmt = $pdo->prepare("SELECT sous_categorie_id, quantite, is_direct_drop, groupe FROM projet_sous_categories WHERE projet_id = ?");
    $stmt->execute([$projetId]);
    foreach ($stmt->fetchAll() as $row) {
        $projetSousCategories[$row['sous_categorie_id']] = (int)$row['quantite'];
        if (!empty($row['is_direct_drop'])) {
            $projetDirectDrops[$row['sous_categorie_id']] = [
                'quantite' => (int)$row['quantite'],
                'groupe' => $row['groupe']
            ];
        }
    }
} catch (Exception $e) {
    // Tables pas encore créées, ignorer
}

// ========================================
// CALCUL MAIN D'ŒUVRE EXTRAPOLÉE (depuis planification)
// ========================================
$moExtrapole = ['heures' => 0, 'cout' => 0, 'jours' => 0];
$dateDebutTravaux = $projet['date_debut_travaux'] ?? $projet['date_acquisition'];
$dateFinPrevue = $projet['date_fin_prevue'];

if ($dateDebutTravaux && $dateFinPrevue) {
    $d1 = new DateTime($dateDebutTravaux);
    $d2 = new DateTime($dateFinPrevue);
    
    // Calcul des jours ouvrables (Lundi-Vendredi)
    $d2Inclusive = clone $d2;
    $d2Inclusive->modify('+1 day');
    $period = new DatePeriod($d1, new DateInterval('P1D'), $d2Inclusive);
    
    $joursOuvrables = 0;
    foreach ($period as $dt) {
        if ((int)$dt->format('N') < 6) $joursOuvrables++;
    }
    $moExtrapole['jours'] = max(1, $joursOuvrables);
    
    // Récupérer les planifications avec taux horaire
    try {
        $stmt = $pdo->prepare("
            SELECT p.heures_semaine_estimees, u.taux_horaire
            FROM projet_planification_heures p
            JOIN users u ON p.user_id = u.id
            WHERE p.projet_id = ?
        ");
        $stmt->execute([$projetId]);
        
        foreach ($stmt->fetchAll() as $row) {
            $heuresSemaine = (float)$row['heures_semaine_estimees'];
            $tauxHoraire = (float)$row['taux_horaire'];
            // heures/jour = heures/semaine ÷ 5
            $heuresJour = $heuresSemaine / 5;
            $totalHeures = $heuresJour * $moExtrapole['jours'];
            $moExtrapole['heures'] += $totalHeures;
            $moExtrapole['cout'] += $totalHeures * $tauxHoraire;
        }
    } catch (Exception $e) {}
}

// ========================================
// CALCUL MAIN D'ŒUVRE RÉELLE (heures travaillées)
// Utilise le taux stocké dans la ligne (comme temps/liste.php)
// Si taux_horaire = 0, fallback sur le taux actuel de l'utilisateur
// ========================================
$moReel = ['heures' => 0, 'cout' => 0];
try {
    $stmt = $pdo->prepare("
        SELECT SUM(h.heures) as total_heures, 
               SUM(h.heures * IF(h.taux_horaire > 0, h.taux_horaire, u.taux_horaire)) as total_cout 
        FROM heures_travaillees h 
        JOIN users u ON h.user_id = u.id 
        WHERE h.projet_id = ? AND h.statut != 'rejetee'
    ");
    $stmt->execute([$projetId]);
    $res = $stmt->fetch();
    $moReel['heures'] = (float)($res['total_heures'] ?? 0);
    $moReel['cout'] = (float)($res['total_cout'] ?? 0);
} catch (Exception $e) {}

// ========================================
// UPLOAD PHOTO (Admin)
// ========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_photo') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        setFlashMessage('error', 'Token de sécurité invalide.');
        redirect('/admin/projets/detail.php?id=' . $projetId . '&tab=photos');
    }

    $groupeId = $_POST['groupe_id'] ?? uniqid('grp_', true);
    $description = trim($_POST['description'] ?? '');
    $userId = getCurrentUserId();

    $uploadedCount = 0;
    $errors = [];

    // Collecter tous les fichiers
    $filesToProcess = [];

    // Photo de la caméra
    if (isset($_FILES['camera_photo']) && $_FILES['camera_photo']['error'] === UPLOAD_ERR_OK && !empty($_FILES['camera_photo']['name'])) {
        $filesToProcess[] = [
            'name' => $_FILES['camera_photo']['name'],
            'tmp_name' => $_FILES['camera_photo']['tmp_name'],
            'error' => $_FILES['camera_photo']['error'],
            'size' => $_FILES['camera_photo']['size']
        ];
    }

    // Photos de la galerie
    if (isset($_FILES['gallery_photos']) && !empty($_FILES['gallery_photos']['name'][0])) {
        $totalGallery = count($_FILES['gallery_photos']['name']);
        for ($i = 0; $i < $totalGallery; $i++) {
            if (!empty($_FILES['gallery_photos']['name'][$i])) {
                $filesToProcess[] = [
                    'name' => $_FILES['gallery_photos']['name'][$i],
                    'tmp_name' => $_FILES['gallery_photos']['tmp_name'][$i],
                    'error' => $_FILES['gallery_photos']['error'][$i],
                    'size' => $_FILES['gallery_photos']['size'][$i]
                ];
            }
        }
    }

    if (!empty($filesToProcess)) {
        foreach ($filesToProcess as $file) {
            if ($file['error'] === UPLOAD_ERR_OK) {
                $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'heic', 'heif', 'webp', 'mp4', 'mov', 'avi', 'mkv', 'webm', 'm4v'];

                if (!in_array($extension, $allowedExtensions)) {
                    $errors[] = "Extension non supportée: $extension";
                    continue;
                }

                // Pour HEIC/HEIF, on va convertir en JPEG
                $finalExtension = in_array($extension, ['heic', 'heif']) ? 'jpg' : $extension;
                $newFilename = 'photo_' . date('Ymd_His') . '_' . uniqid() . '.' . $finalExtension;
                $destination = __DIR__ . '/../../uploads/photos/' . $newFilename;

                // Vérifier/créer le dossier
                $destDir = dirname($destination);
                if (!is_dir($destDir)) {
                    mkdir($destDir, 0777, true);
                }

                // Upload du fichier (HEIC est converti côté client en JPEG)
                $uploadSuccess = false;
                if (move_uploaded_file($file['tmp_name'], $destination) || copy($file['tmp_name'], $destination)) {
                    $uploadSuccess = true;
                } else {
                    $errors[] = "Échec upload: " . ($file['name'] ?? 'fichier');
                }

                if ($uploadSuccess) {
                    $stmt = $pdo->prepare("
                        INSERT INTO photos_projet (projet_id, user_id, groupe_id, fichier, date_prise, description)
                        VALUES (?, ?, ?, ?, NOW(), ?)
                    ");
                    $stmt->execute([$projetId, $userId, $groupeId, $newFilename, $description]);
                    $uploadedCount++;
                }
            }
        }
    }

    if ($uploadedCount > 0) {
        setFlashMessage('success', $uploadedCount . ' photo(s) ajoutée(s) avec succès.');
    } else {
        setFlashMessage('error', 'Aucune photo uploadée. ' . implode(', ', $errors));
    }
    redirect('/admin/projets/detail.php?id=' . $projetId . '&tab=photos');
}

// ========================================
// SUPPRESSION PHOTO (Admin)
// ========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_photo') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        setFlashMessage('error', 'Token de sécurité invalide.');
        redirect('/admin/projets/detail.php?id=' . $projetId . '&tab=photos');
    }

    $photoId = (int)($_POST['photo_id'] ?? 0);

    // Récupérer la photo
    $stmt = $pdo->prepare("SELECT * FROM photos_projet WHERE id = ? AND projet_id = ?");
    $stmt->execute([$photoId, $projetId]);
    $photo = $stmt->fetch();

    if ($photo) {
        // Supprimer le fichier
        $filePath = __DIR__ . '/../../uploads/photos/' . $photo['fichier'];
        if (file_exists($filePath)) {
            unlink($filePath);
        }

        // Supprimer de la base
        $stmt = $pdo->prepare("DELETE FROM photos_projet WHERE id = ?");
        $stmt->execute([$photoId]);

        setFlashMessage('success', 'Photo supprimée.');
    }
    redirect('/admin/projets/detail.php?id=' . $projetId . '&tab=photos');
}

// ========================================
// Suppression multiple de photos
// ========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_photos_bulk') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        setFlashMessage('error', 'Token de sécurité invalide.');
        redirect('/admin/projets/detail.php?id=' . $projetId . '&tab=photos');
    }

    $photoIds = $_POST['photo_ids'] ?? [];
    if (!is_array($photoIds)) {
        $photoIds = json_decode($photoIds, true) ?? [];
    }

    $deletedCount = 0;
    foreach ($photoIds as $photoId) {
        $photoId = (int)$photoId;

        // Récupérer la photo
        $stmt = $pdo->prepare("SELECT * FROM photos_projet WHERE id = ? AND projet_id = ?");
        $stmt->execute([$photoId, $projetId]);
        $photo = $stmt->fetch();

        if ($photo) {
            // Supprimer le fichier
            $filePath = __DIR__ . '/../../uploads/photos/' . $photo['fichier'];
            if (file_exists($filePath)) {
                unlink($filePath);
            }

            // Supprimer de la base
            $stmt = $pdo->prepare("DELETE FROM photos_projet WHERE id = ?");
            $stmt->execute([$photoId]);
            $deletedCount++;
        }
    }

    if ($deletedCount > 0) {
        setFlashMessage('success', $deletedCount . ' photo(s) supprimée(s).');
    }
    redirect('/admin/projets/detail.php?id=' . $projetId . '&tab=photos');
}

// ========================================
// AJAX: Mise à jour de l'ordre des photos
// ========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'update_photos_order') {
    header('Content-Type: application/json');

    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'error' => 'Token invalide']);
        exit;
    }

    $photoIds = $_POST['photo_ids'] ?? [];

    if (!empty($photoIds) && is_array($photoIds)) {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("UPDATE photos_projet SET ordre = ? WHERE id = ? AND projet_id = ?");
            foreach ($photoIds as $ordre => $photoId) {
                $stmt->execute([$ordre + 1, (int)$photoId, $projetId]);
            }

            $pdo->commit();
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'Aucune photo à réorganiser']);
    }
    exit;
}

// ========================================
// TABLE AVANCES EMPLOYES (auto-création)
// ========================================
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS avances_employes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            montant DECIMAL(10,2) NOT NULL,
            date_avance DATE NOT NULL,
            raison TEXT NULL,
            statut ENUM('active', 'deduite', 'annulee') DEFAULT 'active',
            cree_par INT NULL,
            deduite_semaine DATE NULL,
            date_creation DATETIME DEFAULT CURRENT_TIMESTAMP,
            date_modification DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_user (user_id),
            INDEX idx_statut (statut)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Exception $e) {}

// ========================================
// ACTIONS AVANCES (POST)
// ========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'ajouter_avance') {
    if (verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $avUserId = (int)($_POST['avance_user_id'] ?? 0);
        $avMontant = parseNumber($_POST['avance_montant'] ?? 0);
        $avDate = $_POST['avance_date'] ?? date('Y-m-d');
        $avRaison = trim($_POST['avance_raison'] ?? '');

        if ($avUserId > 0 && $avMontant > 0) {
            $stmt = $pdo->prepare("INSERT INTO avances_employes (user_id, montant, date_avance, raison, cree_par) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$avUserId, $avMontant, $avDate, $avRaison, getCurrentUserId()]);
            setFlashMessage('success', 'Avance de ' . formatMoney($avMontant) . ' ajoutée!');
        }
    }
    redirect('/admin/projets/detail.php?id=' . $projetId . '&tab=temps');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'annuler_avance') {
    if (verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $avId = (int)($_POST['avance_id'] ?? 0);
        if ($avId > 0) {
            $stmt = $pdo->prepare("UPDATE avances_employes SET statut = 'annulee' WHERE id = ? AND statut = 'active'");
            $stmt->execute([$avId]);
            setFlashMessage('warning', 'Avance annulée.');
        }
    }
    redirect('/admin/projets/detail.php?id=' . $projetId . '&tab=temps');
}

// ========================================
// DONNÉES POUR ONGLET TEMPS
// ========================================
$heuresProjet = [];
try {
    $stmt = $pdo->prepare("
        SELECT h.*, CONCAT(u.prenom, ' ', u.nom) as employe_nom, u.taux_horaire as taux_actuel
        FROM heures_travaillees h
        JOIN users u ON h.user_id = u.id
        WHERE h.projet_id = ?
        ORDER BY h.date_travail DESC, h.id DESC
    ");
    $stmt->execute([$projetId]);
    $heuresProjet = $stmt->fetchAll();
} catch (Exception $e) {}

// Récupérer le résumé par employé pour ce projet
$resumeEmployes = [];
try {
    $stmt = $pdo->prepare("
        SELECT
            h.user_id,
            CONCAT(u.prenom, ' ', u.nom) as nom_complet,
            u.taux_horaire,
            SUM(CASE WHEN h.statut = 'approuvee' THEN h.heures ELSE 0 END) as heures_approuvees,
            SUM(CASE WHEN h.statut = 'en_attente' THEN h.heures ELSE 0 END) as heures_attente,
            SUM(CASE WHEN h.statut = 'approuvee' THEN h.heures * COALESCE(h.taux_horaire, u.taux_horaire) ELSE 0 END) as montant_approuve
        FROM heures_travaillees h
        JOIN users u ON h.user_id = u.id
        WHERE h.projet_id = ?
        GROUP BY h.user_id, u.prenom, u.nom, u.taux_horaire
        ORDER BY u.prenom, u.nom
    ");
    $stmt->execute([$projetId]);
    $resumeEmployes = $stmt->fetchAll();
} catch (Exception $e) {}

// Récupérer les avances actives par employé
$avancesParEmploye = [];
$avancesListe = [];
try {
    // Avances groupées par employé
    $stmt = $pdo->query("
        SELECT user_id, SUM(montant) as total_avances, COUNT(*) as nb_avances
        FROM avances_employes WHERE statut = 'active' GROUP BY user_id
    ");
    while ($row = $stmt->fetch()) {
        $avancesParEmploye[$row['user_id']] = ['total' => $row['total_avances'], 'nb' => $row['nb_avances']];
    }

    // Liste des avances actives (toutes)
    $avancesListe = $pdo->query("
        SELECT a.*, CONCAT(u.prenom, ' ', u.nom) as employe_nom
        FROM avances_employes a
        JOIN users u ON a.user_id = u.id
        WHERE a.statut = 'active'
        ORDER BY a.date_avance DESC
    ")->fetchAll();
} catch (Exception $e) {}

// Liste des employés actifs pour le formulaire
$employesActifs = $pdo->query("
    SELECT id, CONCAT(prenom, ' ', nom) as nom_complet
    FROM users WHERE actif = 1 AND role IN ('employe', 'admin')
    ORDER BY prenom, nom
")->fetchAll();

// ========================================
// DONNÉES POUR ONGLET PHOTOS
// ========================================
$photosProjet = [];
$groupesPhotosProjet = [];
try {
    // Groupes de photos
    $stmt = $pdo->prepare("
        SELECT p.groupe_id, CONCAT(u.prenom, ' ', u.nom) as employe_nom,
               MIN(p.date_prise) as premiere_photo, MAX(p.date_prise) as derniere_photo,
               COUNT(*) as nb_photos, p.description
        FROM photos_projet p
        JOIN users u ON p.user_id = u.id
        WHERE p.projet_id = ?
        GROUP BY p.groupe_id, u.prenom, u.nom, p.description
        ORDER BY derniere_photo DESC
    ");
    $stmt->execute([$projetId]);
    $groupesPhotosProjet = $stmt->fetchAll();

    // Toutes les photos (triées par ordre personnalisé, puis par date)
    // Limite initiale pour performance
    $photosLimit = 24; // Photos affichées initialement
    $photosPage = isset($_GET['photos_page']) ? max(1, (int)$_GET['photos_page']) : 1;
    $photosOffset = ($photosPage - 1) * $photosLimit;

    // Compter le total
    $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM photos_projet WHERE projet_id = ?");
    $stmtCount->execute([$projetId]);
    $totalPhotos = (int)$stmtCount->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT p.*, CONCAT(u.prenom, ' ', u.nom) as employe_nom
        FROM photos_projet p
        JOIN users u ON p.user_id = u.id
        WHERE p.projet_id = ?
        ORDER BY COALESCE(p.ordre, 999999) ASC, p.date_prise DESC, p.id DESC
        LIMIT " . ($photosLimit * $photosPage) . "
    ");
    $stmt->execute([$projetId]);
    $photosProjet = $stmt->fetchAll();
    $hasMorePhotos = $totalPhotos > count($photosProjet);
} catch (Exception $e) {}

// Catégories de photos pour le formulaire d'ajout
$photoCategories = [];
try {
    $stmt = $pdo->query("SELECT cle, nom_fr as nom FROM photos_categories WHERE actif = 1 ORDER BY ordre, nom_fr");
    $photoCategories = $stmt->fetchAll();
} catch (Exception $e) {
    // Table n'existe pas, utiliser les valeurs par défaut
    $defaultCategories = [
        'cat_interior_finishing', 'cat_exterior', 'cat_plumbing', 'cat_electrical',
        'cat_structure', 'cat_foundation', 'cat_roofing', 'cat_windows_doors',
        'cat_painting', 'cat_flooring', 'cat_before_work', 'cat_after_work',
        'cat_progress', 'cat_other'
    ];
    $categoryLabels = [
        'cat_interior_finishing' => 'Finition intérieure',
        'cat_exterior' => 'Extérieur',
        'cat_plumbing' => 'Plomberie',
        'cat_electrical' => 'Électricité',
        'cat_structure' => 'Structure',
        'cat_foundation' => 'Fondation',
        'cat_roofing' => 'Toiture',
        'cat_windows_doors' => 'Portes et fenêtres',
        'cat_painting' => 'Peinture',
        'cat_flooring' => 'Plancher',
        'cat_before_work' => 'Avant travaux',
        'cat_after_work' => 'Après travaux',
        'cat_progress' => 'Progression',
        'cat_other' => 'Autre'
    ];
    foreach ($defaultCategories as $cat) {
        $photoCategories[] = ['cle' => $cat, 'nom' => $categoryLabels[$cat] ?? $cat];
    }
}

// ========================================
// DONNÉES POUR ONGLET FACTURES
// ========================================
$facturesProjet = [];
try {
    $stmt = $pdo->prepare("
        SELECT f.*, c.nom as categorie_nom
        FROM factures f
        LEFT JOIN categories c ON f.categorie_id = c.id
        WHERE f.projet_id = ?
        ORDER BY f.date_facture DESC, f.id DESC
    ");
    $stmt->execute([$projetId]);
    $facturesProjet = $stmt->fetchAll();
} catch (Exception $e) {}

include '../../includes/header.php';
?>

<style>
/* Tableau compact 3 colonnes - compatible dark mode */
.cost-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
.cost-table th, .cost-table td { padding: 6px 10px; border-bottom: 1px solid var(--bs-border-color, #dee2e6); }
.cost-table thead th { background: #2d3748; color: white; font-weight: 600; position: sticky; top: 0; }
.cost-table .section-header { background: #1e3a5f; color: white; font-weight: 600; cursor: pointer; user-select: none; }
.cost-table .section-header:hover { background: #254a73; }
.cost-table .section-header .toggle-icon { float: right; opacity: 0.5; font-size: 0.75rem; transition: transform 0.2s; }
.cost-table .section-header.collapsed .toggle-icon { transform: rotate(-90deg); }
.cost-table .labor-row { background: #1e40af !important; color: white; }
.cost-table .section-header td { padding: 8px 10px; }
.cost-table .sub-item td:first-child { padding-left: 25px; }
.cost-table .total-row { background: #374151; color: white; font-weight: 600; }
.cost-table .grand-total { background: #1e3a5f; color: white; font-weight: 700; }
.cost-table .profit-row { background: #198754; color: white; font-weight: 700; }
.cost-table .loss-row { background: #dc3545; color: white; font-weight: 700; }
.cost-table .text-end { text-align: right; }
.cost-table .positive { color: #198754; }
.cost-table .negative { color: #dc3545; }
.cost-table .col-label { width: 40%; }
.cost-table .col-num { width: 20%; text-align: right; }
@media (max-width: 768px) {
    .cost-table { font-size: 0.75rem; }
    .cost-table th, .cost-table td { padding: 4px 6px; }
}
/* Chevron rotation pour accordion catégories */
.cat-chevron { transition: transform 0.2s ease; }
button:not(.collapsed) .cat-chevron { transform: rotate(90deg); }

/* Header sticky seulement sur desktop */
@media (min-width: 768px) {
    .sticky-header {
        position: sticky;
        top: 0;
        z-index: 1020;
        background: var(--bg-body, #f1f5f9);
        padding-bottom: 0.5rem;
        margin-bottom: 0.5rem;
    }
}
</style>

<div class="container-fluid">
    <!-- En-tête sticky sur desktop -->
    <div class="sticky-header">
        <div class="page-header">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1">
                    <li class="breadcrumb-item"><a href="<?= url('/admin/index.php') ?>">Tableau de bord</a></li>
                    <li class="breadcrumb-item"><a href="<?= url('/admin/projets/liste.php') ?>">Projets</a></li>
                    <li class="breadcrumb-item active"><?= e($projet['nom']) ?></li>
                </ol>
            </nav>
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                <div>
                    <div class="d-flex align-items-center flex-wrap gap-2 mb-1">
                        <h1 class="mb-0 fs-4"><?= e($projet['nom']) ?></h1>
                        <span class="badge <?= getStatutProjetClass($projet['statut']) ?>"><?= getStatutProjetLabel($projet['statut']) ?></span>
                    </div>
                    <small class="text-muted"><i class="bi bi-geo-alt"></i> <?= e($projet['adresse']) ?>, <?= e($projet['ville']) ?></small>
                </div>
                <div class="d-flex align-items-center gap-1">
                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="changeTextSize(-1)" title="Réduire"><i class="bi bi-dash-lg"></i></button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="changeTextSize(1)" title="Agrandir"><i class="bi bi-plus-lg"></i></button>
                    <button onclick="window.print()" class="btn btn-outline-secondary btn-sm" title="Imprimer"><i class="bi bi-printer"></i></button>
                </div>
            </div>
        </div>

        <!-- Onglets de navigation -->
        <ul class="nav nav-tabs mb-0" id="projetTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link <?= $tab === 'base' ? 'active' : '' ?>" id="base-tab" data-bs-toggle="tab" data-bs-target="#base" type="button" role="tab">
                    <i class="bi bi-house-door me-1"></i>Base
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link <?= $tab === 'financement' ? 'active' : '' ?>" id="financement-tab" data-bs-toggle="tab" data-bs-target="#financement" type="button" role="tab">
                    <i class="bi bi-bank me-1"></i>Financement
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link <?= $tab === 'budgets' ? 'active' : '' ?>" id="budgets-tab" data-bs-toggle="tab" data-bs-target="#budgets" type="button" role="tab">
                    <i class="bi bi-wallet2 me-1"></i>Budgets
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link <?= $tab === 'maindoeuvre' ? 'active' : '' ?>" id="maindoeuvre-tab" data-bs-toggle="tab" data-bs-target="#maindoeuvre" type="button" role="tab">
                    <i class="bi bi-people me-1"></i>Main-d'œuvre
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link <?= $tab === 'temps' ? 'active' : '' ?>" id="temps-tab" data-bs-toggle="tab" data-bs-target="#temps" type="button" role="tab">
                    <i class="bi bi-clock me-1"></i>Temps
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link <?= $tab === 'photos' ? 'active' : '' ?>" id="photos-tab" data-bs-toggle="tab" data-bs-target="#photos" type="button" role="tab">
                    <i class="bi bi-camera me-1"></i>Photos
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link <?= $tab === 'factures' ? 'active' : '' ?>" id="factures-tab" data-bs-toggle="tab" data-bs-target="#factures" type="button" role="tab">
                    <i class="bi bi-receipt me-1"></i>Factures
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link <?= $tab === 'checklist' ? 'active' : '' ?>" id="checklist-tab" data-bs-toggle="tab" data-bs-target="#checklist" type="button" role="tab">
                    <i class="bi bi-list-check me-1"></i>Checklist
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link <?= $tab === 'documents' ? 'active' : '' ?>" id="documents-tab" data-bs-toggle="tab" data-bs-target="#documents" type="button" role="tab">
                    <i class="bi bi-folder me-1"></i>Documents
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link <?= $tab === 'googlesheet' ? 'active' : '' ?>" id="googlesheet-tab" data-bs-toggle="tab" data-bs-target="#googlesheet" type="button" role="tab">
                    <i class="bi bi-table me-1"></i>Google Sheet
                </button>
            </li>
        </ul>
    </div>

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

    <div class="tab-content" id="projetTabsContent">
    <!-- TAB BASE -->
    <div class="tab-pane fade <?= $tab === 'base' ? 'show active' : '' ?>" id="base" role="tabpanel">

    <!-- Indicateurs en haut -->
    <?php
    // Déterminer les couleurs pour Extrapolé et Réel
    $extrapoleBgClass = $indicateurs['equite_potentielle'] >= 0 ? 'bg-success' : 'bg-danger';
    $extrapoleTextClass = $indicateurs['equite_potentielle'] >= 0 ? 'text-success' : 'text-danger';
    $reelBgClass = $indicateurs['equite_reelle'] >= 0 ? 'bg-success' : 'bg-danger';
    $reelTextClass = $indicateurs['equite_reelle'] >= 0 ? 'text-success' : 'text-danger';
    ?>
    <div class="row g-2 mb-3">
        <div class="col-6 col-lg">
            <div class="card text-center p-2" style="background: rgba(100, 116, 139, 0.1);" role="button" data-bs-toggle="tooltip" data-bs-placement="bottom" title="Prix de vente estimé de la propriété après rénovations">
                <small class="text-muted">Valeur potentielle <i class="bi bi-info-circle small"></i></small>
                <strong class="fs-5" style="color: #64748b;" id="indValeurPotentielle"><?= formatMoney($indicateurs['valeur_potentielle']) ?></strong>
            </div>
        </div>
        <div class="col-6 col-lg">
            <div class="card text-center p-2 <?= $extrapoleBgClass ?> bg-opacity-10" role="button" data-bs-toggle="tooltip" data-bs-placement="bottom" title="Profit prévu si vous respectez le budget. Calcul: Valeur potentielle - Prix d'achat - Budget total - Frais">
                <small class="text-muted">Extrapolé <i class="bi bi-info-circle small"></i></small>
                <strong class="fs-5 <?= $extrapoleTextClass ?>" id="indEquiteBudget"><?= formatMoney($indicateurs['equite_potentielle']) ?></strong>
            </div>
        </div>
        <div class="col-6 col-lg">
            <div class="card text-center p-2" style="background: rgba(100, 116, 139, 0.1);" role="button" data-bs-toggle="tooltip" data-bs-placement="bottom" title="Cash flow nécessaire. Exclut: courtier, taxes mun/scol, mutation. Sans intérêts: <?= formatMoney($indicateurs['cash_flow_moins_interets'], false) ?>$">
                <small class="text-muted">Cash Flow <i class="bi bi-info-circle small"></i></small>
                <strong class="fs-5" style="color: #64748b;" id="indCashFlow"><?= formatMoney($indicateurs['cash_flow_necessaire']) ?></strong>
            </div>
        </div>
        <div class="col-6 col-lg">
            <div class="card text-center p-2 <?= $reelBgClass ?> bg-opacity-10" role="button" data-bs-toggle="tooltip" data-bs-placement="bottom" title="Profit réel basé sur les dépenses actuelles. Calcul: Valeur potentielle - Prix d'achat - Dépenses réelles - Frais">
                <small class="text-muted">Réel <i class="bi bi-info-circle small"></i></small>
                <strong class="fs-5 <?= $reelTextClass ?>" id="indEquiteReelle"><?= formatMoney($indicateurs['equite_reelle']) ?></strong>
            </div>
        </div>
        <div class="col-6 col-lg">
            <div class="card text-center p-2" style="background: rgba(100, 116, 139, 0.1);" role="button" data-bs-toggle="tooltip" data-bs-placement="bottom" title="Retour sur investissement basé sur votre mise de fonds (cash investi). Calcul: Équité Réelle ÷ Mise de fonds × 100">
                <small class="text-muted">ROI Leverage <i class="bi bi-info-circle small"></i></small>
                <strong class="fs-5" style="color: #64748b;" id="indRoiLeverage"><?= formatPercent($indicateurs['roi_leverage']) ?></strong>
            </div>
        </div>
    </div>

    <!-- FORMULAIRE ÉDITION -->
    <style>
        .compact-form .mb-3 { margin-bottom: 0.5rem !important; }
        .compact-form .form-label { font-size: 0.8rem; margin-bottom: 0.2rem; color: #666; }
        .compact-form .form-control, .compact-form .form-select { font-size: 0.9rem; padding: 0.35rem 0.5rem; }
        .compact-form .input-group-text { font-size: 0.8rem; padding: 0.35rem 0.5rem; }
        .compact-form .card { margin-bottom: 1rem !important; }
        .compact-form .card-header { padding: 0.5rem 1rem; font-size: 0.9rem; }
        .compact-form .card-body { padding: 0.75rem; }

        /* Graphiques modernes */
        .chart-card {
            background: var(--bg-card);
            border: none;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            overflow: hidden;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        .chart-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 30px rgba(0,0,0,0.12);
        }
        .chart-header {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 16px;
            background: rgba(99,102,241,0.08);
            border-bottom: 1px solid rgba(99,102,241,0.1);
        }
        .chart-header.red { background: rgba(239,68,68,0.08); }
        .chart-header.blue { background: rgba(59,130,246,0.08); }
        .chart-header.green { background: rgba(34,197,94,0.08); }
        .chart-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
        }
        .chart-icon.red { background: #ef4444; color: white; }
        .chart-icon.blue { background: #3b82f6; color: white; }
        .chart-icon.green { background: #22c55e; color: white; }
        .chart-title {
            font-weight: 600;
            font-size: 0.9rem;
            color: var(--text-primary);
        }
        .chart-subtitle {
            font-size: 0.7rem;
            color: var(--text-secondary);
        }
        .chart-body {
            padding: 12px;
            position: relative;
        }
        .chart-body canvas {
            border-radius: 8px;
        }
    </style>

    <!-- Lottie Player -->
    <script src="https://unpkg.com/@lottiefiles/lottie-player@latest/dist/lottie-player.js"></script>

    <!-- GRAPHIQUES MODERNES -->
    <div class="row g-3 mb-3">
        <div class="col-md-4">
            <div class="card chart-card h-100">
                <div class="chart-header red">
                    <div class="chart-icon red">
                        <i class="bi bi-graph-up-arrow"></i>
                    </div>
                    <div>
                        <div class="chart-title">Coûts vs Valeur</div>
                        <div class="chart-subtitle">Évolution dans le temps</div>
                    </div>
                </div>
                <div class="chart-body"><canvas id="chartCouts" height="150"></canvas></div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card chart-card h-100">
                <div class="chart-header blue">
                    <div class="chart-icon blue">
                        <i class="bi bi-clock-history"></i>
                    </div>
                    <div>
                        <div class="chart-title">Heures travaillées</div>
                        <div class="chart-subtitle">Par jour de la semaine</div>
                    </div>
                </div>
                <div class="chart-body"><canvas id="chartBudget" height="150"></canvas></div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card chart-card h-100">
                <div class="chart-header green">
                    <div class="chart-icon green">
                        <i class="bi bi-wallet2"></i>
                    </div>
                    <div>
                        <div class="chart-title">Budget vs Dépensé</div>
                        <div class="chart-subtitle">Suivi des dépenses</div>
                    </div>
                </div>
                <div class="chart-body"><canvas id="chartProfits" height="150"></canvas></div>
            </div>
        </div>
    </div>

    <div class="row">
    <div class="col-xxl-6">
    <form method="POST" action="" class="compact-form" id="formBase">
        <?php csrfField(); ?>
        <input type="hidden" name="action" value="general">

        <div class="row">
            <!-- Colonne gauche -->
            <div class="col-lg-6 d-flex flex-column gap-3">
                <div class="card">
                    <div class="card-header"><i class="bi bi-info-circle me-1"></i>Infos</div>
                    <div class="card-body">
                        <div class="row g-2">
                            <div class="col-8">
                                <label class="form-label">Nom *</label>
                                <input type="text" class="form-control" name="nom" value="<?= e($projet['nom']) ?>" required>
                            </div>
                            <div class="col-4">
                                <label class="form-label">Statut</label>
                                <select class="form-select" name="statut">
                                    <option value="prospection" <?= $projet['statut'] === 'prospection' ? 'selected' : '' ?>>Prospection</option>
                                    <option value="acquisition" <?= $projet['statut'] === 'acquisition' ? 'selected' : '' ?>>Acquisition</option>
                                    <option value="renovation" <?= $projet['statut'] === 'renovation' ? 'selected' : '' ?>>Réno</option>
                                    <option value="vente" <?= $projet['statut'] === 'vente' ? 'selected' : '' ?>>Vente</option>
                                    <option value="vendu" <?= $projet['statut'] === 'vendu' ? 'selected' : '' ?>>Vendu</option>
                                </select>
                            </div>
                            <div class="col-6">
                                <label class="form-label">Adresse *</label>
                                <input type="text" class="form-control" name="adresse" value="<?= e($projet['adresse']) ?>" required>
                            </div>
                            <div class="col-4">
                                <label class="form-label">Ville *</label>
                                <input type="text" class="form-control" name="ville" value="<?= e($projet['ville']) ?>" required>
                            </div>
                            <div class="col-2">
                                <label class="form-label">Code</label>
                                <input type="text" class="form-control" name="code_postal" value="<?= e($projet['code_postal']) ?>">
                            </div>
                            <div class="col-3">
                                <label class="form-label">Achat</label>
                                <input type="date" class="form-control" name="date_acquisition" id="date_acquisition" value="<?= e($projet['date_acquisition']) ?>" onchange="calculerDuree()">
                            </div>
                            <div class="col-3">
                                <label class="form-label">Début trav.</label>
                                <input type="date" class="form-control" name="date_debut_travaux" value="<?= e($projet['date_debut_travaux']) ?>">
                            </div>
                            <div class="col-3">
                                <label class="form-label">Fin travaux</label>
                                <input type="date" class="form-control" name="date_fin_prevue" id="date_fin_prevue" value="<?= e($projet['date_fin_prevue']) ?>" onchange="calculerDuree()">
                            </div>
                            <div class="col-3">
                                <label class="form-label">Vendu</label>
                                <input type="date" class="form-control" name="date_vente" id="date_vente" value="<?= e($projet['date_vente'] ?? '') ?>" onchange="calculerDuree()">
                            </div>
                            <div class="col-12">
                                <label class="form-label"><i class="bi bi-dropbox me-1"></i>Dropbox</label>
                                <div class="input-group">
                                    <input type="url" class="form-control" name="dropbox_link" id="dropbox_link" value="<?= e($projet['dropbox_link'] ?? '') ?>" placeholder="https://www.dropbox.com/...">
                                    <?php if (!empty($projet['dropbox_link'])): ?>
                                    <a href="<?= e($projet['dropbox_link']) ?>" target="_blank" class="btn btn-outline-primary" title="Ouvrir Dropbox">
                                        <i class="bi bi-box-arrow-up-right"></i>
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header"><i class="bi bi-currency-dollar me-1"></i>Achat</div>
                    <div class="card-body">
                        <div class="row g-2">
                            <div class="col-3">
                                <label class="form-label">Prix achat</label>
                                <div class="input-group"><span class="input-group-text">$</span>
                                    <input type="text" class="form-control money-input" name="prix_achat" id="prix_achat" value="<?= formatMoney($projet['prix_achat'], false) ?>" onchange="calculerTaxeMutation()">
                                </div>
                            </div>
                            <div class="col-3">
                                <label class="form-label">Rôle éval.</label>
                                <div class="input-group"><span class="input-group-text">$</span>
                                    <input type="text" class="form-control money-input" name="role_evaluation" id="role_evaluation" value="<?= formatMoney($projet['role_evaluation'] ?? 0, false) ?>" onchange="calculerTaxeMutation()">
                                </div>
                            </div>
                            <div class="col-3">
                                <label class="form-label">Valeur pot.</label>
                                <div class="input-group"><span class="input-group-text">$</span>
                                    <input type="text" class="form-control money-input" name="valeur_potentielle" value="<?= formatMoney($projet['valeur_potentielle'], false) ?>">
                                </div>
                            </div>
                            <div class="col-3">
                                <label class="form-label">Durée (mois)</label>
                                <input type="number" class="form-control bg-light" name="temps_assume_mois" id="duree_mois" value="<?= (int)$projet['temps_assume_mois'] ?>" readonly title="Calculé automatiquement: Date vente (ou fin travaux) - Date achat">
                            </div>
                            <div class="col-3">
                                <label class="form-label">Cession</label>
                                <div class="input-group"><span class="input-group-text">$</span>
                                    <input type="text" class="form-control money-input" name="cession" value="<?= formatMoney($projet['cession'] ?? 0, false) ?>">
                                </div>
                            </div>
                            <div class="col-3">
                                <label class="form-label">Notaire</label>
                                <div class="input-group"><span class="input-group-text">$</span>
                                    <input type="text" class="form-control money-input" name="notaire" value="<?= formatMoney($projet['notaire'], false) ?>">
                                </div>
                            </div>
                            <div class="col-3">
                                <label class="form-label">Arpenteurs</label>
                                <div class="input-group"><span class="input-group-text">$</span>
                                    <input type="text" class="form-control money-input" name="arpenteurs" value="<?= formatMoney($projet['arpenteurs'], false) ?>">
                                </div>
                            </div>
                            <div class="col-3">
                                <label class="form-label">Ass. titre</label>
                                <div class="input-group"><span class="input-group-text">$</span>
                                    <input type="text" class="form-control money-input" name="assurance_titre" value="<?= formatMoney($projet['assurance_titre'], false) ?>">
                                </div>
                            </div>
                            <div class="col-4">
                                <label class="form-label">Contingence</label>
                                <div class="input-group">
                                    <input type="number" class="form-control" name="taux_contingence" id="taux_contingence" step="0.01" value="<?= $projet['taux_contingence'] ?>">
                                    <span class="input-group-text">%</span>
                                </div>
                            </div>
                            <div class="col-4">
                                <label class="form-label">Solde vendeur</label>
                                <div class="input-group"><span class="input-group-text">$</span>
                                    <input type="text" class="form-control money-input" name="solde_vendeur" value="<?= formatMoney($projet['solde_vendeur'] ?? 0, false) ?>">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>

            <!-- Colonne droite -->
            <div class="col-lg-6 d-flex flex-column gap-3">
                <div class="card flex-grow-1">
                    <div class="card-header">
                        <i class="bi bi-arrow-repeat me-1"></i>Récurrents
                        <a href="<?= url('/admin/recurrents/liste.php') ?>" class="float-end small text-decoration-none" title="Gérer les types">
                            <i class="bi bi-gear"></i>
                        </a>
                    </div>
                    <div class="card-body">
                        <div class="row g-2">
                            <?php foreach ($recurrentsTypes as $type):
                                $valeur = $projetRecurrents[$type['id']] ?? 0;
                                $freq = match($type['frequence']) {
                                    'mensuel' => '/mois',
                                    'saisonnier' => '',
                                    default => '/an'
                                };
                                // Tronquer le nom si trop long
                                $nomCourt = mb_strlen($type['nom']) > 15 ? mb_substr($type['nom'], 0, 12) . '...' : $type['nom'];
                            ?>
                            <div class="col-6">
                                <label class="form-label" title="<?= e($type['nom']) ?>"><?= e($nomCourt) ?><?= $freq ?></label>
                                <div class="input-group"><span class="input-group-text">$</span>
                                    <input type="text" class="form-control money-input" name="recurrents[<?= $type['id'] ?>]" value="<?= formatMoney($valeur, false) ?>">
                                </div>
                            </div>
                            <?php endforeach; ?>
                            <?php if (empty($recurrentsTypes)): ?>
                            <!-- Fallback si pas de types (anciens champs pour compatibilité) -->
                            <div class="col-6">
                                <label class="form-label">Taxes mun. /an</label>
                                <div class="input-group"><span class="input-group-text">$</span>
                                    <input type="text" class="form-control money-input" name="taxes_municipales_annuel" value="<?= formatMoney($projet['taxes_municipales_annuel'], false) ?>">
                                </div>
                            </div>
                            <div class="col-6">
                                <label class="form-label">Taxes scol. /an</label>
                                <div class="input-group"><span class="input-group-text">$</span>
                                    <input type="text" class="form-control money-input" name="taxes_scolaires_annuel" value="<?= formatMoney($projet['taxes_scolaires_annuel'], false) ?>">
                                </div>
                            </div>
                            <div class="col-6">
                                <label class="form-label">Électricité /an</label>
                                <div class="input-group"><span class="input-group-text">$</span>
                                    <input type="text" class="form-control money-input" name="electricite_annuel" value="<?= formatMoney($projet['electricite_annuel'], false) ?>">
                                </div>
                            </div>
                            <div class="col-6">
                                <label class="form-label">Assurances /an</label>
                                <div class="input-group"><span class="input-group-text">$</span>
                                    <input type="text" class="form-control money-input" name="assurances_annuel" value="<?= formatMoney($projet['assurances_annuel'], false) ?>">
                                </div>
                            </div>
                            <div class="col-6">
                                <label class="form-label">Déneigement /an</label>
                                <div class="input-group"><span class="input-group-text">$</span>
                                    <input type="text" class="form-control money-input" name="deneigement_annuel" value="<?= formatMoney($projet['deneigement_annuel'], false) ?>">
                                </div>
                            </div>
                            <div class="col-6">
                                <label class="form-label">Frais condo /an</label>
                                <div class="input-group"><span class="input-group-text">$</span>
                                    <input type="text" class="form-control money-input" name="frais_condo_annuel" value="<?= formatMoney($projet['frais_condo_annuel'], false) ?>">
                                </div>
                            </div>
                            <div class="col-6">
                                <label class="form-label">Hypothèque /mois</label>
                                <div class="input-group"><span class="input-group-text">$</span>
                                    <input type="text" class="form-control money-input" name="hypotheque_mensuel" value="<?= formatMoney($projet['hypotheque_mensuel'], false) ?>">
                                </div>
                            </div>
                            <div class="col-6">
                                <label class="form-label">Loyer reçu /mois</label>
                                <div class="input-group"><span class="input-group-text">$</span>
                                    <input type="text" class="form-control money-input" name="loyer_mensuel" value="<?= formatMoney($projet['loyer_mensuel'], false) ?>">
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Section Vente -->
                <div class="card">
                    <div class="card-header"><i class="bi bi-cash-stack me-1"></i>Vente</div>
                    <div class="card-body">
                        <div class="row g-2">
                            <div class="col-4">
                                <label class="form-label">Courtier</label>
                                <div class="input-group">
                                    <input type="number" class="form-control" name="taux_commission" id="taux_commission" step="0.01" value="<?= $projet['taux_commission'] ?>">
                                    <span class="input-group-text">%</span>
                                </div>
                                <small class="text-muted"><?= formatMoney($indicateurs['couts_vente']['commission']) ?> + TPS/TVQ = <?= formatMoney($indicateurs['couts_vente']['commission_ttc']) ?></small>
                            </div>
                            <div class="col-4">
                                <label class="form-label">Quittance</label>
                                <div class="input-group"><span class="input-group-text">$</span>
                                    <input type="text" class="form-control money-input" name="quittance" value="<?= formatMoney($projet['quittance'] ?? 0, false) ?>">
                                </div>
                            </div>
                            <div class="col-4">
                                <label class="form-label">Mutation</label>
                                <div class="input-group"><span class="input-group-text">$</span>
                                    <input type="text" class="form-control money-input" name="taxe_mutation" id="taxe_mutation" value="<?= formatMoney($projet['taxe_mutation'], false) ?>">
                                    <button type="button" class="btn btn-sm btn-outline-secondary px-1" onclick="calculerTaxeMutation(true)" title="Calculer selon prix achat"><i class="bi bi-calculator"></i></button>
                                </div>
                            </div>
                            <div class="col-4">
                                <label class="form-label">Solde acheteur</label>
                                <div class="input-group"><span class="input-group-text">$</span>
                                    <input type="text" class="form-control money-input" name="solde_acheteur" value="<?= formatMoney($projet['solde_acheteur'] ?? 0, false) ?>">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <input type="hidden" name="notes" value="<?= e($projet['notes']) ?>">
            </div>
        </div>

        <div class="text-end mt-2">
            <div id="baseStatusSave" class="text-muted small">
                <span id="baseIdle"><i class="bi bi-cloud-check me-1"></i>Sauvegarde auto</span>
                <span id="baseSaving" class="d-none"><i class="bi bi-arrow-repeat spin me-1"></i>Enregistrement...</span>
                <span id="baseSaved" class="d-none text-success"><i class="bi bi-check-circle me-1"></i>Enregistré!</span>
            </div>
        </div>
    </form>
    </div><!-- Fin col-xxl-6 -->

    <!-- CARD 1: Détail des coûts (Achat -> Rénovation) -->
    <div class="col-lg-6 col-xxl-3">
    <div class="card h-100">
        <div class="card-header py-2">
            <i class="bi bi-calculator me-1"></i> Détail des coûts (<?= $dureeReelle ?> mois)
        </div>
        <div class="table-responsive">
            <table class="cost-table">
                <thead>
                    <tr>
                        <th class="col-label">Poste</th>
                        <th class="col-num text-info">Extrapolé</th>
                        <th class="col-num">Diff</th>
                        <th class="col-num text-success">Réel</th>
                    </tr>
                </thead>
                <tbody>
                    <!-- PRIX D'ACHAT -->
                    <tr class="section-header" data-section="achat">
                        <td colspan="4"><i class="bi bi-house me-1"></i> Achat <i class="bi bi-chevron-down toggle-icon"></i></td>
                    </tr>
                    <tr class="section-achat">
                        <td>Prix d'achat</td>
                        <td class="text-end"><?= formatMoney($projet['prix_achat']) ?></td>
                        <td class="text-end">-</td>
                        <td class="text-end"><?= formatMoney($projet['prix_achat']) ?></td>
                    </tr>
                    
                    <!-- COÛTS D'ACQUISITION -->
                    <tr class="section-header" data-section="acquisition">
                        <td colspan="4"><i class="bi bi-cart me-1"></i> Acquisition <i class="bi bi-chevron-down toggle-icon"></i></td>
                    </tr>
                    <?php if ($indicateurs['couts_acquisition']['cession'] > 0): ?>
                    <tr class="sub-item">
                        <td>Cession</td>
                        <td class="text-end"><?= formatMoney($indicateurs['couts_acquisition']['cession']) ?></td>
                        <td class="text-end">-</td>
                        <td class="text-end"><?= formatMoney($indicateurs['couts_acquisition']['cession']) ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr class="sub-item">
                        <td>Notaire</td>
                        <td class="text-end"><?= formatMoney($indicateurs['couts_acquisition']['notaire']) ?></td>
                        <td class="text-end">-</td>
                        <td class="text-end"><?= formatMoney($indicateurs['couts_acquisition']['notaire']) ?></td>
                    </tr>
                    <tr class="sub-item">
                        <td>Arpenteurs</td>
                        <td class="text-end"><?= formatMoney($indicateurs['couts_acquisition']['arpenteurs']) ?></td>
                        <td class="text-end">-</td>
                        <td class="text-end"><?= formatMoney($indicateurs['couts_acquisition']['arpenteurs']) ?></td>
                    </tr>
                    <tr class="sub-item">
                        <td>Assurance titre</td>
                        <td class="text-end"><?= formatMoney($indicateurs['couts_acquisition']['assurance_titre']) ?></td>
                        <td class="text-end">-</td>
                        <td class="text-end"><?= formatMoney($indicateurs['couts_acquisition']['assurance_titre']) ?></td>
                    </tr>
                    <?php if (($indicateurs['couts_acquisition']['solde_vendeur'] ?? 0) > 0): ?>
                    <tr class="sub-item">
                        <td>Solde vendeur</td>
                        <td class="text-end"><?= formatMoney($indicateurs['couts_acquisition']['solde_vendeur']) ?></td>
                        <td class="text-end">-</td>
                        <td class="text-end"><?= formatMoney($indicateurs['couts_acquisition']['solde_vendeur']) ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr class="total-row">
                        <td>Sous-total Acquisition</td>
                        <td class="text-end"><?= formatMoney($indicateurs['couts_acquisition']['total']) ?></td>
                        <td class="text-end">-</td>
                        <td class="text-end"><?= formatMoney($indicateurs['couts_acquisition']['total']) ?></td>
                    </tr>
                    
                    <!-- COÛTS RÉCURRENTS -->
                    <tr class="section-header" data-section="recurrents">
                        <td colspan="4">
                            <i class="bi bi-arrow-repeat me-1"></i> Récurrents (<?= $dureeReelle ?> mois prévu / <?= number_format($moisEcoules, 1) ?> mois écoulés)
                            <i class="bi bi-chevron-down toggle-icon"></i>
                        </td>
                    </tr>
                    <?php
                    // Calcul dynamique des coûts récurrents
                    $totalRecExtrapole = 0;
                    $totalRecReel = 0;
                    $facteurExtrapole = $dureeReelle / 12;
                    $facteurReel = $moisEcoules / 12;

                    foreach ($recurrentsTypes as $type):
                        $montant = $projetRecurrents[$type['id']] ?? 0;
                        if ($montant == 0) continue; // Ne pas afficher les types sans valeur

                        // Calculer extrapolé et réel selon la fréquence
                        if ($type['frequence'] === 'mensuel') {
                            $extrapole = $montant * $dureeReelle;
                            $reel = $montant * $moisEcoules;
                        } elseif ($type['frequence'] === 'saisonnier') {
                            // Saisonnier = montant fixe (ex: déneigement, gazon)
                            $extrapole = $montant;
                            $reel = $montant; // Coût fixe peu importe la durée
                        } else {
                            // annuel
                            $extrapole = $montant * $facteurExtrapole;
                            $reel = $montant * $facteurReel;
                        }

                        // Loyer est un revenu (soustraire)
                        $isLoyer = ($type['code'] === 'loyer');
                        if ($isLoyer) {
                            $totalRecExtrapole -= $extrapole;
                            $totalRecReel -= $reel;
                        } else {
                            $totalRecExtrapole += $extrapole;
                            $totalRecReel += $reel;
                        }

                        $ecart = $extrapole - $reel;
                    ?>
                    <tr class="sub-item">
                        <td><?= e($type['nom']) ?><?= $isLoyer ? ' <small class="text-success">(revenu)</small>' : '' ?></td>
                        <td class="text-end"><?= $isLoyer ? '-' : '' ?><?= formatMoney($extrapole) ?></td>
                        <td class="text-end <?= $ecart >= 0 ? 'positive' : 'negative' ?>"><?= formatMoney($ecart) ?></td>
                        <td class="text-end"><?= $isLoyer ? '-' : '' ?><?= formatMoney($reel) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php
                    $ecartTotalRec = $totalRecExtrapole - $totalRecReel;
                    ?>
                    <tr class="total-row">
                        <td>Sous-total Récurrents</td>
                        <td class="text-end"><?= formatMoney($totalRecExtrapole) ?></td>
                        <td class="text-end <?= $ecartTotalRec >= 0 ? 'positive' : 'negative' ?>"><?= formatMoney($ecartTotalRec) ?></td>
                        <td class="text-end"><?= formatMoney($totalRecReel) ?></td>
                    </tr>
                    
                    <!-- RÉNOVATION -->
                    <tr class="section-header" data-section="renovation">
                        <td colspan="4"><i class="bi bi-tools me-1"></i> Rénovation (+ <?= $projet['taux_contingence'] ?>% contingence) <i class="bi bi-chevron-down toggle-icon"></i></td>
                    </tr>
                    <?php
                    $totalBudgetReno = 0;
                    $totalReelReno = 0;
                    $contingenceUtilisee = 0; // Somme des dépassements
                    foreach ($categories as $cat):
                        $budgetUnit = $budgets[$cat['id']] ?? 0;
                        $depense = $depenses[$cat['id']] ?? 0;
                        if ($budgetUnit == 0 && $depense == 0) continue;
                        $qteGroupe = $projetGroupes[$cat['groupe']] ?? 1;

                        // NOTE: $budgetUnit vient de budgets.montant_extrapole qui
                        // contient DÉJÀ le multiplicateur de groupe (via syncBudgetsFromProjetItems)
                        // Donc on NE multiplie PAS à nouveau par $qteGroupe
                        $budgetHT = $budgetUnit;

                        // Afficher en HT car TPS/TVQ sont montrés séparément
                        $budgetAffiche = $budgetHT;
                        $ecart = $budgetAffiche - $depense;
                        $totalBudgetReno += $budgetHT;
                        $totalReelReno += $depense;
                        // Accumuler les dépassements pour la contingence
                        if ($ecart < 0) {
                            $contingenceUtilisee += abs($ecart);
                        }
                    ?>
                    <tr class="sub-item detail-cat-row" data-cat-id="<?= $cat['id'] ?>">
                        <td>
                            <?= e($cat['nom']) ?>
                            <?php if ($qteGroupe > 1): ?>
                                <small class="text-muted detail-qte-groupe" data-groupe="<?= htmlspecialchars($cat['groupe']) ?>">(×<?= $qteGroupe ?>)</small>
                            <?php endif; ?>
                        </td>
                        <td class="text-end detail-cat-budget" id="detailCatBudget_<?= $cat['id'] ?>"><?= formatMoney($budgetAffiche) ?></td>
                        <td class="text-end detail-cat-diff <?= $ecart >= 0 ? 'positive' : 'negative' ?>"><?= $ecart != 0 ? formatMoney($ecart) : '-' ?></td>
                        <td class="text-end detail-cat-reel"><?= formatMoney($depense) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    
                    <!-- MAIN D'ŒUVRE -->
                    <?php
                    $diffMO = $moExtrapole['cout'] - $moReel['cout'];
                    // Ajouter dépassement MO à la contingence utilisée
                    if ($diffMO < 0) {
                        $contingenceUtilisee += abs($diffMO);
                    }
                    if ($moExtrapole['heures'] > 0 || $moReel['heures'] > 0):
                    ?>
                    <tr class="sub-item labor-row">
                        <td>
                            <i class="bi bi-person-fill me-1"></i>Main d'œuvre
                            <small class="d-block opacity-75">
                                Planifié: <?= number_format($moExtrapole['heures'], 0) ?>h (<?= $moExtrapole['jours'] ?>j) | 
                                Réel: <?= number_format($moReel['heures'], 1) ?>h
                            </small>
                        </td>
                        <td class="text-end"><?= formatMoney($moExtrapole['cout']) ?></td>
                        <td class="text-end <?= $diffMO >= 0 ? 'positive' : 'negative' ?>"><?= formatMoney($diffMO) ?></td>
                        <td class="text-end"><?= formatMoney($moReel['cout']) ?></td>
                    </tr>
                    <?php endif; ?>
                    
                    <?php
                    $ecartContingence = $indicateurs['contingence'] - $contingenceUtilisee;
                    ?>
                    <tr class="sub-item">
                        <td>Contingence <?= $projet['taux_contingence'] ?>%</td>
                        <td class="text-end" id="detailContingence"><?= formatMoney($indicateurs['contingence']) ?></td>
                        <td class="text-end <?= $ecartContingence >= 0 ? 'positive' : 'negative' ?>"><?= formatMoney($ecartContingence) ?></td>
                        <td class="text-end"><?= formatMoney($contingenceUtilisee) ?></td>
                    </tr>

                    <?php
                    $diffTPS = $indicateurs['renovation']['tps'] - $indicateurs['renovation']['reel_tps'];
                    $diffTVQ = $indicateurs['renovation']['tvq'] - $indicateurs['renovation']['reel_tvq'];
                    ?>
                    <tr class="sub-item">
                        <td>TPS 5%</td>
                        <td class="text-end" id="detailTPS"><?= formatMoney($indicateurs['renovation']['tps']) ?></td>
                        <td class="text-end <?= $diffTPS >= 0 ? 'positive' : 'negative' ?>"><?= formatMoney($diffTPS) ?></td>
                        <td class="text-end"><?= formatMoney($indicateurs['renovation']['reel_tps']) ?></td>
                    </tr>

                    <tr class="sub-item">
                        <td>TVQ 9.975%</td>
                        <td class="text-end" id="detailTVQ"><?= formatMoney($indicateurs['renovation']['tvq']) ?></td>
                        <td class="text-end <?= $diffTVQ >= 0 ? 'positive' : 'negative' ?>"><?= formatMoney($diffTVQ) ?></td>
                        <td class="text-end"><?= formatMoney($indicateurs['renovation']['reel_tvq']) ?></td>
                    </tr>

                    <?php
                    // Réel TTC = factures TTC + main d'œuvre réelle
                    $renoReelTTC = $indicateurs['renovation']['reel_ttc'] + $indicateurs['main_doeuvre']['cout'];
                    // Budget TTC = budget extrapolé TTC + main d'œuvre planifiée
                    $renoBudgetTTC = $indicateurs['renovation']['total_ttc'] + $indicateurs['main_doeuvre_extrapole']['cout'];
                    $diffReno = $renoBudgetTTC - $renoReelTTC;
                    ?>
                    <tr class="total-row">
                        <td>Sous-total Rénovation (avec taxes)</td>
                        <td class="text-end" id="detailRenoTotal"><?= formatMoney($renoBudgetTTC) ?></td>
                        <td class="text-end <?= $diffReno >= 0 ? 'positive' : 'negative' ?>"><?= formatMoney($diffReno) ?></td>
                        <td class="text-end"><?= formatMoney($renoReelTTC) ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div><!-- Fin card Détail des coûts -->
    </div><!-- Fin col-xxl-3 -->

    <!-- CARD 2: Vente séparée (Vente -> Profit) -->
    <div class="col-lg-6 col-xxl-3">
    <div class="card h-100">
        <div class="card-header py-2">
            <i class="bi bi-shop me-1"></i> Vente
        </div>
        <div class="table-responsive">
            <table class="cost-table">
                <thead>
                    <tr>
                        <th class="col-label">Poste</th>
                        <th class="col-num text-info">Extrapolé</th>
                        <th class="col-num">Diff</th>
                        <th class="col-num text-success">Réel</th>
                    </tr>
                </thead>
                <tbody>
                    <!-- COÛTS DE VENTE -->
                    <tr class="section-header" data-section="vente">
                        <td colspan="4"><i class="bi bi-shop me-1"></i> Vente <i class="bi bi-chevron-down toggle-icon"></i></td>
                    </tr>
                    <tr class="sub-item">
                        <td>Intérêts (<?php
                            if (!empty($indicateurs['preteurs'])) {
                                $tauxList = [];
                                foreach ($indicateurs['preteurs'] as $p) {
                                    $tauxList[] = $p['taux'] . '%';
                                }
                                echo implode(', ', $tauxList);
                            } else {
                                echo $projet['taux_interet'] . '%';
                            }
                        ?> sur <?= $dureeReelle ?> mois)</td>
                        <td class="text-end"><?= formatMoney($indicateurs['couts_vente']['interets']) ?></td>
                        <td class="text-end">-</td>
                        <td class="text-end"><?= formatMoney($indicateurs['couts_vente']['interets']) ?></td>
                    </tr>
                    <tr class="sub-item">
                        <td>Commission courtier <?= $projet['taux_commission'] ?>% + taxes</td>
                        <td class="text-end"><?= formatMoney($indicateurs['couts_vente']['commission_ttc']) ?></td>
                        <td class="text-end">-</td>
                        <td class="text-end"><?= formatMoney($indicateurs['couts_vente']['commission_ttc']) ?></td>
                    </tr>
                    <tr class="sub-item">
                        <td>Quittance</td>
                        <td class="text-end"><?= formatMoney($indicateurs['couts_vente']['quittance']) ?></td>
                        <td class="text-end">-</td>
                        <td class="text-end"><?= formatMoney($indicateurs['couts_vente']['quittance']) ?></td>
                    </tr>
                    <?php if (($indicateurs['couts_vente']['taxe_mutation'] ?? 0) > 0): ?>
                    <tr class="sub-item">
                        <td>Taxe mutation</td>
                        <td class="text-end"><?= formatMoney($indicateurs['couts_vente']['taxe_mutation']) ?></td>
                        <td class="text-end">-</td>
                        <td class="text-end"><?= formatMoney($indicateurs['couts_vente']['taxe_mutation']) ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if (($indicateurs['couts_vente']['solde_acheteur'] ?? 0) > 0): ?>
                    <tr class="sub-item">
                        <td>Solde acheteur</td>
                        <td class="text-end"><?= formatMoney($indicateurs['couts_vente']['solde_acheteur']) ?></td>
                        <td class="text-end">-</td>
                        <td class="text-end"><?= formatMoney($indicateurs['couts_vente']['solde_acheteur']) ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr class="total-row">
                        <td>Sous-total Vente</td>
                        <td class="text-end"><?= formatMoney($indicateurs['couts_vente']['total']) ?></td>
                        <td class="text-end">-</td>
                        <td class="text-end"><?= formatMoney($indicateurs['couts_vente']['total']) ?></td>
                    </tr>
                    
                    <!-- GRAND TOTAL -->
                    <?php $diffTotal = $indicateurs['cout_total_projet'] - $indicateurs['cout_total_reel']; ?>
                    <tr class="grand-total">
                        <td>COÛT TOTAL PROJET</td>
                        <td class="text-end"><?= formatMoney($indicateurs['cout_total_projet']) ?></td>
                        <td class="text-end" style="color:<?= $diffTotal >= 0 ? '#90EE90' : '#ffcccc' ?>"><?= formatMoney($diffTotal) ?></td>
                        <td class="text-end"><?= formatMoney($indicateurs['cout_total_reel']) ?></td>
                    </tr>
                    
                    <tr>
                        <td>Valeur potentielle de vente</td>
                        <td class="text-end"><?= formatMoney($indicateurs['valeur_potentielle']) ?></td>
                        <td class="text-end">-</td>
                        <td class="text-end"><?= formatMoney($indicateurs['valeur_potentielle']) ?></td>
                    </tr>
                    
                    <?php $diffEquite = $indicateurs['equite_reelle'] - $indicateurs['equite_potentielle']; ?>
                    <tr class="total-row">
                        <td>ÉQUITÉ / PROFIT</td>
                        <td class="text-end"><?= formatMoney($indicateurs['equite_potentielle']) ?></td>
                        <td class="text-end" style="color:<?= $diffEquite >= 0 ? '#90EE90' : '#ffcccc' ?>"><?= $diffEquite >= 0 ? '+' : '' ?><?= formatMoney($diffEquite) ?></td>
                        <td class="text-end"><?= formatMoney($indicateurs['equite_reelle']) ?></td>
                    </tr>
                    
                    <!-- PARTAGE DES PROFITS -->
                    <?php if (!empty($indicateurs['preteurs']) || !empty($indicateurs['investisseurs'])): ?>
                    <tr class="section-header" data-section="partage">
                        <td colspan="4"><i class="bi bi-pie-chart me-1"></i> Partage des profits <i class="bi bi-chevron-down toggle-icon"></i></td>
                    </tr>

                    <?php
                    // Calcul du profit net EXTRAPOLÉ (avant partage) = équité potentielle
                    $profitNetAvantPartage = $indicateurs['equite_potentielle'];
                    // Calcul du profit net RÉEL (avant partage) = équité réelle
                    $profitNetAvantPartageReel = $indicateurs['equite_reelle'];

                    // Calcul impôt sur le profit EXTRAPOLÉ
                    $seuilImpot = 500000;
                    $tauxBase = 0.122; // 12,2%
                    $tauxEleve = 0.265; // 26,5%

                    if ($profitNetAvantPartage <= 0) {
                        $impotAPayer = 0;
                    } elseif ($profitNetAvantPartage <= $seuilImpot) {
                        $impotAPayer = $profitNetAvantPartage * $tauxBase;
                    } else {
                        $impotAPayer = ($seuilImpot * $tauxBase) + (($profitNetAvantPartage - $seuilImpot) * $tauxEleve);
                    }
                    $profitApresImpot = $profitNetAvantPartage - $impotAPayer;

                    // Calcul impôt sur le profit RÉEL
                    if ($profitNetAvantPartageReel <= 0) {
                        $impotAPayerReel = 0;
                    } elseif ($profitNetAvantPartageReel <= $seuilImpot) {
                        $impotAPayerReel = $profitNetAvantPartageReel * $tauxBase;
                    } else {
                        $impotAPayerReel = ($seuilImpot * $tauxBase) + (($profitNetAvantPartageReel - $seuilImpot) * $tauxEleve);
                    }
                    $profitApresImpotReel = $profitNetAvantPartageReel - $impotAPayerReel;
                    ?>

                    <!-- Prêteurs (capital + intérêts à rembourser) -->
                    <?php if (!empty($indicateurs['preteurs'])): ?>
                    <?php foreach ($indicateurs['preteurs'] as $preteur):
                        $totalDu = $preteur['montant'] + $preteur['interets_total'];
                    ?>
                    <tr class="sub-item">
                        <td>
                            <i class="bi bi-bank text-warning me-1"></i><?= e($preteur['nom']) ?>
                            <?php if ($preteur['taux'] > 0): ?>
                                <small class="text-muted">(<?= $preteur['taux'] ?>% = <?= formatMoney($preteur['interets_total']) ?> int.)</small>
                            <?php else: ?>
                                <small class="text-muted">(prêt 0%)</small>
                            <?php endif; ?>
                        </td>
                        <td class="text-end" style="color: #e74c3c;">-<?= formatMoney($totalDu) ?></td>
                        <td class="text-end">-</td>
                        <td class="text-end" style="color: #e74c3c;">-<?= formatMoney($totalDu) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>

                    <!-- PROFIT NET (avant partage) -->
                    <?php $diffProfitNet = $profitNetAvantPartageReel - $profitNetAvantPartage; ?>
                    <tr class="total-row">
                        <td>PROFIT NET (avant partage)</td>
                        <td class="text-end"><?= formatMoney($profitNetAvantPartage) ?></td>
                        <td class="text-end" style="color:<?= $diffProfitNet >= 0 ? '#90EE90' : '#ffcccc' ?>"><?= $diffProfitNet >= 0 ? '+' : '' ?><?= formatMoney($diffProfitNet) ?></td>
                        <td class="text-end"><?= formatMoney($profitNetAvantPartageReel) ?></td>
                    </tr>

                    <!-- Impôt à payer -->
                    <?php $diffImpot = $impotAPayerReel - $impotAPayer; ?>
                    <tr class="sub-item text-danger">
                        <td>
                            <i class="bi bi-bank2 me-1"></i>Impôt à payer
                            <small class="text-muted">(<?= $profitNetAvantPartage <= $seuilImpot ? '12,2%' : '12,2% + 26,5%' ?>)</small>
                        </td>
                        <td class="text-end">-<?= formatMoney($impotAPayer) ?></td>
                        <td class="text-end" style="color:<?= $diffImpot <= 0 ? '#90EE90' : '#ffcccc' ?>"><?= $diffImpot >= 0 ? '+' : '' ?><?= formatMoney($diffImpot) ?></td>
                        <td class="text-end">-<?= formatMoney($impotAPayerReel) ?></td>
                    </tr>

                    <!-- PROFIT APRÈS IMPÔT -->
                    <?php
                    $profitNegatif = $profitApresImpot < 0;
                    $profitReelNegatif = $profitApresImpotReel < 0;
                    $profitRowClass = $profitNegatif ? 'loss-row' : 'profit-row';
                    $diffProfitApres = $profitApresImpotReel - $profitApresImpot;
                    ?>
                    <tr class="<?= $profitRowClass ?>">
                        <td><strong><i class="bi bi-cash-stack me-1"></i>PROFIT APRÈS IMPÔT</strong></td>
                        <td class="text-end"><strong><?= formatMoney($profitApresImpot) ?></strong></td>
                        <td class="text-end" style="color:<?= $diffProfitApres >= 0 ? '#90EE90' : '#ffcccc' ?>"><?= $diffProfitApres >= 0 ? '+' : '' ?><?= formatMoney($diffProfitApres) ?></td>
                        <td class="text-end"><strong><?= formatMoney($profitApresImpotReel) ?></strong></td>
                    </tr>

                    <!-- Ligne miroir de séparation -->
                    <tr>
                        <td colspan="4" style="padding: 0; height: 3px; background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);"></td>
                    </tr>

                    <!-- Division entre investisseurs -->
                    <?php if (!empty($indicateurs['investisseurs'])): ?>
                    <tr class="section-header" data-section="division">
                        <td colspan="4"><i class="bi bi-people me-1"></i> Division entre investisseurs <i class="bi bi-chevron-down toggle-icon"></i></td>
                    </tr>
                    <?php foreach ($indicateurs['investisseurs'] as $inv): ?>
                    <?php
                    // Calculer la part de chaque investisseur sur le profit après impôt (extrapolé et réel)
                    $partInvestisseur = $profitApresImpot * ($inv['pourcentage'] / 100);
                    $partInvestisseurReel = $profitApresImpotReel * ($inv['pourcentage'] / 100);
                    $isNegatif = $partInvestisseur < 0;
                    $invRowClass = $isNegatif ? 'loss-row' : 'profit-row';
                    $prefix = $isNegatif ? '' : '+';
                    $prefixReel = $partInvestisseurReel < 0 ? '' : '+';
                    $diffInv = $partInvestisseurReel - $partInvestisseur;
                    ?>
                    <tr class="<?= $invRowClass ?>">
                        <td><i class="bi bi-person text-info me-1"></i><?= e($inv['nom']) ?> (<?= number_format($inv['pourcentage'], 1) ?>%)</td>
                        <td class="text-end"><?= $prefix ?><?= formatMoney($partInvestisseur) ?></td>
                        <td class="text-end" style="color:<?= $diffInv >= 0 ? '#90EE90' : '#ffcccc' ?>"><?= $diffInv >= 0 ? '+' : '' ?><?= formatMoney($diffInv) ?></td>
                        <td class="text-end"><?= $prefixReel ?><?= formatMoney($partInvestisseurReel) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div><!-- Fin card Vente -->
    </div><!-- Fin col-xxl-3 -->
    </div><!-- Fin row xxl -->
    </div><!-- Fin TAB BASE -->

    <!-- TAB FINANCEMENT -->
    <div class="tab-pane fade <?= $tab === 'financement' ? 'show active' : '' ?>" id="financement" role="tabpanel">

    <?php
    // Calcul du montant requis pour le notaire
    $prixAchatNotaire = (float)($projet['prix_achat'] ?? 0);
    $cessionNotaire = (float)($projet['cession'] ?? 0);
    $soldeVendeurNotaire = (float)($projet['solde_vendeur'] ?? 0);
    $montantRequisNotaire = $prixAchatNotaire + $cessionNotaire + $soldeVendeurNotaire;

    // Cashflow nécessaire (même calcul que page principale)
    $cashFlowNecessaire = $indicateurs['cash_flow_necessaire'] ?? 0;

    // Séparer les prêteurs des investisseurs (basé strictement sur type_financement)
    // Prêteur = reçoit des intérêts (même si 0%)
    // Investisseur = reçoit un % des profits
    $listePreteurs = [];
    $listeInvestisseurs = [];
    $totalPretsCalc = 0;
    $totalInvest = 0;
    $totalPctDirect = 0; // Total des pourcentages entrés directement

    foreach ($preteursProjet as $p) {
        $montant = (float)($p['montant'] ?? $p['mise_de_fonds'] ?? 0);
        $taux = (float)($p['taux_interet'] ?? 0);
        $pctProfit = (float)($p['pourcentage_profit'] ?? 0);
        // Utiliser strictement le type_financement enregistré, défaut à 'preteur'
        $type = $p['type_calc'] ?? 'preteur';

        if ($type === 'preteur') {
            $listePreteurs[] = array_merge($p, ['montant_calc' => $montant, 'taux_calc' => $taux]);
            $totalPretsCalc += $montant;
        } else {
            $listeInvestisseurs[] = array_merge($p, ['montant_calc' => $montant, 'pct_direct' => $pctProfit]);
            $totalInvest += $montant;
            $totalPctDirect += $pctProfit;
        }
    }

    // Calcul des différences
    $diffNotaire = $totalPretsCalc - $montantRequisNotaire;
    $isNotaireBalanced = abs($diffNotaire) < 0.01;
    $diffCashflow = $totalPretsCalc - $cashFlowNecessaire;
    $isCashflowBalanced = abs($diffCashflow) < 0.01;
    ?>

    <!-- RÉSUMÉ FINANCEMENT - DEUX TABLEAUX CÔTE À CÔTE -->
    <div class="row mb-4">
        <!-- Tableau 1: Financement Notaire -->
        <div class="col-md-6">
            <div class="card h-100" style="border-color: #3d4f5f;">
                <div class="card-header py-2 d-flex justify-content-between align-items-center" style="background: #2c3e50; border-bottom: 1px solid #3d4f5f;">
                    <span><i class="bi bi-bank2 me-2 text-info"></i><strong>Financement Notaire</strong></span>
                    <?php if (!$isNotaireBalanced): ?>
                        <?php if ($diffNotaire > 0): ?>
                            <span class="badge" style="background: #3d5a4a; color: #27ae60;">+<?= formatMoney($diffNotaire) ?></span>
                        <?php else: ?>
                            <span class="badge" style="background: #5a3d3d; color: #e74c3c;"><?= formatMoney($diffNotaire) ?></span>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="badge" style="background: #3d5a4a; color: #27ae60;"><i class="bi bi-check-circle me-1"></i>OK</span>
                    <?php endif; ?>
                </div>
                <div class="card-body py-3">
                    <table class="table table-sm table-borderless mb-0" style="color: #ecf0f1;">
                        <tbody>
                            <tr>
                                <td style="color: #95a5a6;">Prix d'achat</td>
                                <td class="text-end"><?= formatMoney($prixAchatNotaire) ?></td>
                            </tr>
                            <?php if ($cessionNotaire > 0): ?>
                            <tr>
                                <td style="color: #95a5a6;">Cession</td>
                                <td class="text-end"><?= formatMoney($cessionNotaire) ?></td>
                            </tr>
                            <?php endif; ?>
                            <?php if ($soldeVendeurNotaire > 0): ?>
                            <tr>
                                <td style="color: #95a5a6;">Solde vendeur</td>
                                <td class="text-end"><?= formatMoney($soldeVendeurNotaire) ?></td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                        <tfoot>
                            <tr style="border-top: 1px solid #3d4f5f;">
                                <td class="fw-bold pt-2">Requis au notaire</td>
                                <td class="text-end fw-bold pt-2 fs-5"><?= formatMoney($montantRequisNotaire) ?></td>
                            </tr>
                            <tr>
                                <td style="color: #95a5a6;">Total des prêts</td>
                                <td class="text-end" style="color: #95a5a6;"><?= formatMoney($totalPretsCalc) ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>

        <!-- Tableau 2: Cashflow (même calcul que page principale) -->
        <div class="col-md-6">
            <div class="card h-100" style="border-color: #3d4f5f;">
                <div class="card-header py-2 d-flex justify-content-between align-items-center" style="background: #2c3e50; border-bottom: 1px solid #3d4f5f;">
                    <span><i class="bi bi-cash-stack me-2 text-info"></i><strong>Cashflow Nécessaire</strong></span>
                    <?php if (!$isCashflowBalanced): ?>
                        <?php if ($diffCashflow > 0): ?>
                            <span class="badge" style="background: #3d5a4a; color: #27ae60;">+<?= formatMoney($diffCashflow) ?></span>
                        <?php else: ?>
                            <span class="badge" style="background: #5a3d3d; color: #e74c3c;"><?= formatMoney($diffCashflow) ?></span>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="badge" style="background: #3d5a4a; color: #27ae60;"><i class="bi bi-check-circle me-1"></i>OK</span>
                    <?php endif; ?>
                </div>
                <div class="card-body py-3">
                    <table class="table table-sm table-borderless mb-0" style="color: #ecf0f1;">
                        <tbody>
                            <tr>
                                <td style="color: #95a5a6;">Cashflow nécessaire</td>
                                <td class="text-end"><?= formatMoney($cashFlowNecessaire) ?></td>
                            </tr>
                            <tr>
                                <td style="color: #95a5a6;">Total des prêts</td>
                                <td class="text-end" style="color: #95a5a6;"><?= formatMoney($totalPretsCalc) ?></td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr style="border-top: 1px solid #3d4f5f;">
                                <td class="fw-bold pt-2">
                                    <?= $diffCashflow >= 0 ? 'Surplus' : 'Cash à sortir' ?>
                                </td>
                                <td class="text-end fw-bold pt-2 fs-5" style="<?= $diffCashflow < 0 ? 'color: #e74c3c;' : '' ?>">
                                    <?= formatMoney(abs($diffCashflow)) ?>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Légende compacte -->
    <div class="d-flex gap-4 mb-4 small text-muted">
        <div><i class="bi bi-bank me-1"></i><strong>Prêteur:</strong> Reçoit des intérêts (coût)</div>
        <div><i class="bi bi-people me-1"></i><strong>Investisseur:</strong> Reçoit un % des profits (partage)</div>
    </div>

    <div class="row">
        <!-- COLONNE PRÊTEURS -->
        <div class="col-lg-6">
            <div class="card mb-4" style="border-color: #3d4f5f;">
                <div class="card-header d-flex justify-content-between align-items-center py-2" style="background: #2c3e50; border-bottom: 1px solid #3d4f5f;">
                    <span><i class="bi bi-bank me-2 text-info"></i><strong>Prêteurs</strong></span>
                    <small class="text-secondary">Coût = Intérêts</small>
                </div>

                <?php if (empty($listePreteurs)): ?>
                    <div class="card-body text-center text-muted py-4">
                        <i class="bi bi-bank" style="font-size: 2rem;"></i>
                        <p class="mb-0 small">Aucun prêteur</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead>
                                <tr style="background: #34495e;">
                                    <th class="text-light">Nom</th>
                                    <th class="text-end text-light">Montant</th>
                                    <th class="text-center text-light">Taux</th>
                                    <th class="text-end text-light">Intérêts</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($listePreteurs as $p):
                                $tauxMensuel = $p['taux_calc'] / 100 / 12;
                                $interets = $p['montant_calc'] * (pow(1 + $tauxMensuel, $dureeReelle) - 1);
                            ?>
                                <tr style="background: #1e2a38;">
                                    <form method="POST" id="form-preteur-<?= $p['id'] ?>">
                                        <?php csrfField(); ?>
                                        <input type="hidden" name="action" value="preteurs">
                                        <input type="hidden" name="sub_action" value="modifier">
                                        <input type="hidden" name="preteur_id" value="<?= $p['id'] ?>">
                                        <td class="align-middle"><i class="bi bi-person-circle text-secondary me-1"></i><?= e($p['investisseur_nom']) ?></td>
                                        <td class="text-end">
                                            <input type="text" class="form-control form-control-sm money-input text-end"
                                                   name="montant_pret" value="<?= number_format($p['montant_calc'], 0, ',', ' ') ?>"
                                                   style="width: 100px; display: inline-block; background: #2c3e50; border-color: #3d4f5f; color: #ecf0f1;">
                                        </td>
                                        <td class="text-center">
                                            <input type="text" class="form-control form-control-sm text-center"
                                                   name="taux_interet_pret" value="<?= $p['taux_calc'] ?>"
                                                   style="width: 60px; display: inline-block; background: #2c3e50; border-color: #3d4f5f; color: #ecf0f1;">%
                                        </td>
                                        <td class="text-end" style="color: #e74c3c;"><?= formatMoney($interets) ?></td>
                                        <td class="text-nowrap">
                                            <button type="submit" class="btn btn-sm py-0 px-1" style="border-color: #3d4f5f; color: #3498db;" title="Sauvegarder">
                                                <i class="bi bi-check"></i>
                                            </button>
                                    </form>
                                            <form method="POST" class="d-inline" title="Convertir en investisseur">
                                                <?php csrfField(); ?>
                                                <input type="hidden" name="action" value="preteurs">
                                                <input type="hidden" name="sub_action" value="convertir">
                                                <input type="hidden" name="preteur_id" value="<?= $p['id'] ?>">
                                                <input type="hidden" name="nouveau_type" value="investisseur">
                                                <button type="submit" class="btn btn-sm py-0 px-1" style="border-color: #3d4f5f; color: #2ecc71;" title="Convertir en investisseur">
                                                    <i class="bi bi-arrow-right-circle"></i>
                                                </button>
                                            </form>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Supprimer?')">
                                                <?php csrfField(); ?>
                                                <input type="hidden" name="action" value="preteurs">
                                                <input type="hidden" name="sub_action" value="supprimer">
                                                <input type="hidden" name="preteur_id" value="<?= $p['id'] ?>">
                                                <button type="submit" class="btn btn-sm py-0 px-1" style="border-color: #3d4f5f; color: #7f8c8d;" title="Supprimer">
                                                    <i class="bi bi-x"></i>
                                                </button>
                                            </form>
                                        </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <!-- Formulaire ajout prêteur -->
                <div class="card-footer" style="background: #243342; border-top: 1px solid #3d4f5f;">
                    <form method="POST" class="row g-2 align-items-end">
                        <?php csrfField(); ?>
                        <input type="hidden" name="action" value="preteurs">
                        <input type="hidden" name="sub_action" value="ajouter">
                        <input type="hidden" name="type_financement" value="preteur">
                        <div class="col-4">
                            <label class="form-label small mb-0 text-secondary">Personne</label>
                            <select class="form-select form-select-sm" name="investisseur_id" required style="background: #2c3e50; border-color: #3d4f5f; color: #ecf0f1;">
                                <option value="">Choisir...</option>
                                <?php foreach ($tousInvestisseurs as $inv): ?>
                                    <option value="<?= $inv['id'] ?>"><?= e($inv['nom']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-3">
                            <label class="form-label small mb-0 text-secondary">Montant $</label>
                            <input type="text" class="form-control form-control-sm money-input" name="montant_pret" required placeholder="0" style="background: #2c3e50; border-color: #3d4f5f; color: #ecf0f1;">
                        </div>
                        <div class="col-3">
                            <label class="form-label small mb-0 text-secondary">Taux %</label>
                            <input type="text" class="form-control form-control-sm" name="taux_interet_pret" value="0" style="background: #2c3e50; border-color: #3d4f5f; color: #ecf0f1;">
                        </div>
                        <div class="col-2">
                            <button type="submit" class="btn btn-sm w-100" style="background: #3498db; border-color: #3498db; color: white;"><i class="bi bi-plus-lg"></i></button>
                        </div>
                    </form>
                    <small class="text-muted">Taux 0% = prêt sans intérêt</small>
                </div>
            </div>

            <!-- Total prêteurs -->
            <div class="card mb-4" style="border-color: #3d4f5f; background: #2c3e50;">
                <div class="card-body py-2">
                    <div class="d-flex justify-content-between">
                        <span class="text-secondary">Total prêts</span>
                        <strong><?= formatMoney($totalPretsCalc) ?></strong>
                    </div>
                    <div class="d-flex justify-content-between" style="color: #e74c3c;">
                        <span>Intérêts (<?= $dureeReelle ?> mois)</span>
                        <strong>
                            <?php
                            $totalInteretsCalc = 0;
                            foreach ($listePreteurs as $p) {
                                $tauxMensuel = $p['taux_calc'] / 100 / 12;
                                $totalInteretsCalc += $p['montant_calc'] * (pow(1 + $tauxMensuel, $dureeReelle) - 1);
                            }
                            echo formatMoney($totalInteretsCalc);
                            ?>
                        </strong>
                    </div>
                </div>
            </div>
        </div>

        <!-- COLONNE INVESTISSEURS -->
        <div class="col-lg-6">
            <div class="card mb-4" style="border-color: #3d4f5f;">
                <div class="card-header d-flex justify-content-between align-items-center py-2" style="background: #2c3e50; border-bottom: 1px solid #3d4f5f;">
                    <span><i class="bi bi-people me-2 text-info"></i><strong>Investisseurs</strong></span>
                    <small class="text-secondary">Partage des profits</small>
                </div>

                <?php if (empty($listeInvestisseurs)): ?>
                    <div class="card-body text-center text-muted py-4">
                        <i class="bi bi-people" style="font-size: 2rem;"></i>
                        <p class="mb-0 small">Aucun investisseur</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead>
                                <tr style="background: #34495e;">
                                    <th class="text-light">Nom</th>
                                    <th class="text-end text-light">Mise $</th>
                                    <th class="text-center text-light">% Direct</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php
                            $totalPctFinal = 0;
                            foreach ($listeInvestisseurs as $inv):
                                // Si pourcentage direct est défini, l'utiliser, sinon calculer selon la mise
                                $pctDirect = (float)($inv['pct_direct'] ?? 0);
                                if ($pctDirect > 0) {
                                    $pctFinal = $pctDirect;
                                } else {
                                    $pctFinal = $totalInvest > 0 ? ($inv['montant_calc'] / $totalInvest) * 100 : 0;
                                }
                                $totalPctFinal += $pctFinal;
                            ?>
                                <tr style="background: #1e2a38;">
                                    <form method="POST" id="form-invest-<?= $inv['id'] ?>">
                                        <?php csrfField(); ?>
                                        <input type="hidden" name="action" value="preteurs">
                                        <input type="hidden" name="sub_action" value="modifier">
                                        <input type="hidden" name="preteur_id" value="<?= $inv['id'] ?>">
                                        <input type="hidden" name="taux_interet_pret" value="0">
                                        <td class="align-middle"><i class="bi bi-person-circle text-secondary me-1"></i><?= e($inv['investisseur_nom']) ?></td>
                                        <td class="text-end">
                                            <input type="text" class="form-control form-control-sm money-input text-end"
                                                   name="montant_pret" value="<?= $inv['montant_calc'] > 0 ? number_format($inv['montant_calc'], 0, ',', ' ') : '' ?>"
                                                   placeholder="0"
                                                   style="width: 90px; display: inline-block; background: #2c3e50; border-color: #3d4f5f; color: #ecf0f1;">
                                        </td>
                                        <td class="text-center">
                                            <div class="input-group input-group-sm" style="width: 80px; display: inline-flex;">
                                                <input type="text" class="form-control form-control-sm text-end"
                                                       name="pourcentage_profit" value="<?= $pctDirect > 0 ? number_format($pctDirect, 1) : '' ?>"
                                                       placeholder="<?= number_format($pctFinal, 1) ?>"
                                                       style="background: #2c3e50; border-color: #3d4f5f; color: <?= $pctDirect > 0 ? '#3498db' : '#95a5a6' ?>;">
                                                <span class="input-group-text" style="background: #34495e; border-color: #3d4f5f; color: #95a5a6; padding: 0 4px;">%</span>
                                            </div>
                                        </td>
                                        <td class="text-nowrap">
                                            <button type="submit" class="btn btn-sm py-0 px-1" style="border-color: #3d4f5f; color: #3498db;" title="Sauvegarder">
                                                <i class="bi bi-check"></i>
                                            </button>
                                    </form>
                                            <form method="POST" class="d-inline" title="Convertir en prêteur">
                                                <?php csrfField(); ?>
                                                <input type="hidden" name="action" value="preteurs">
                                                <input type="hidden" name="sub_action" value="convertir">
                                                <input type="hidden" name="preteur_id" value="<?= $inv['id'] ?>">
                                                <input type="hidden" name="nouveau_type" value="preteur">
                                                <button type="submit" class="btn btn-sm py-0 px-1" style="border-color: #3d4f5f; color: #f39c12;" title="Convertir en prêteur">
                                                    <i class="bi bi-arrow-left-circle"></i>
                                                </button>
                                            </form>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Supprimer?')">
                                                <?php csrfField(); ?>
                                                <input type="hidden" name="action" value="preteurs">
                                                <input type="hidden" name="sub_action" value="supprimer">
                                                <input type="hidden" name="preteur_id" value="<?= $inv['id'] ?>">
                                                <button type="submit" class="btn btn-sm py-0 px-1" style="border-color: #3d4f5f; color: #7f8c8d;" title="Supprimer">
                                                    <i class="bi bi-x"></i>
                                                </button>
                                            </form>
                                        </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <!-- Formulaire ajout investisseur -->
                <div class="card-footer" style="background: #243342; border-top: 1px solid #3d4f5f;">
                    <form method="POST" class="row g-2 align-items-end" id="form-add-investisseur">
                        <?php csrfField(); ?>
                        <input type="hidden" name="action" value="preteurs">
                        <input type="hidden" name="sub_action" value="ajouter">
                        <input type="hidden" name="type_financement" value="investisseur">
                        <input type="hidden" name="taux_interet_pret" value="0">
                        <div class="col-5">
                            <label class="form-label small mb-0 text-secondary">Personne</label>
                            <select class="form-select form-select-sm" name="investisseur_id" required style="background: #2c3e50; border-color: #3d4f5f; color: #ecf0f1;">
                                <option value="">Choisir...</option>
                                <?php foreach ($tousInvestisseurs as $inv): ?>
                                    <option value="<?= $inv['id'] ?>"><?= e($inv['nom']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-3">
                            <label class="form-label small mb-0 text-secondary">Mise $</label>
                            <input type="text" class="form-control form-control-sm money-input" name="montant_pret" placeholder="0" style="background: #2c3e50; border-color: #3d4f5f; color: #ecf0f1;">
                        </div>
                        <div class="col-2">
                            <label class="form-label small mb-0 text-secondary">OU %</label>
                            <input type="text" class="form-control form-control-sm" name="pourcentage_profit" placeholder="%" style="background: #2c3e50; border-color: #3d4f5f; color: #ecf0f1;">
                        </div>
                        <div class="col-2">
                            <button type="submit" class="btn btn-sm w-100" style="background: #3498db; border-color: #3498db; color: white;"><i class="bi bi-plus-lg"></i></button>
                        </div>
                    </form>
                    <small class="text-muted">Entrez une mise $ (% calculé auto) OU un % direct</small>
                </div>
            </div>

            <!-- Total investisseurs et avertissement -->
            <?php
            // Calculer le total final des pourcentages
            $totalPctAffiche = 0;
            foreach ($listeInvestisseurs as $inv) {
                $pctDirect = (float)($inv['pct_direct'] ?? 0);
                if ($pctDirect > 0) {
                    $totalPctAffiche += $pctDirect;
                } else {
                    $totalPctAffiche += $totalInvest > 0 ? ($inv['montant_calc'] / $totalInvest) * 100 : 0;
                }
            }
            $pctManquant = 100 - $totalPctAffiche;
            ?>
            <div class="card mb-4" style="border-color: #3d4f5f; background: #2c3e50;">
                <div class="card-body py-2">
                    <div class="d-flex justify-content-between mb-1">
                        <span class="text-secondary">Total mises</span>
                        <strong><?= formatMoney($totalInvest) ?></strong>
                    </div>
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="text-secondary">Total %</span>
                        <?php if (abs($pctManquant) < 0.1): ?>
                            <span class="badge" style="background: #27ae60;"><i class="bi bi-check-circle me-1"></i>100%</span>
                        <?php elseif ($pctManquant > 0): ?>
                            <span class="badge" style="background: #e74c3c;">
                                <?= number_format($totalPctAffiche, 1) ?>%
                                <i class="bi bi-arrow-right mx-1"></i>
                                Manque <?= number_format($pctManquant, 1) ?>%
                            </span>
                        <?php else: ?>
                            <span class="badge" style="background: #e67e22;">
                                <?= number_format($totalPctAffiche, 1) ?>%
                                <i class="bi bi-exclamation-triangle ms-1"></i>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Lien pour ajouter des personnes -->
    <div class="text-center">
        <a href="<?= url('/admin/investisseurs/liste.php') ?>" class="btn btn-sm" style="border-color: #3d4f5f; color: #7f8c8d;">
            <i class="bi bi-person-plus me-1"></i>Gérer la liste des personnes
        </a>
    </div>
    </div><!-- Fin TAB FINANCEMENT -->

    <!-- TAB BUDGETS -->
    <div class="tab-pane fade <?= $tab === 'budgets' ? 'show active' : '' ?>" id="budgets" role="tabpanel">
    <?php include 'budget-builder-content.php'; ?>
    </div><!-- Fin TAB BUDGETS -->

    <!-- TAB MAIN-D'ŒUVRE -->
    <div class="tab-pane fade <?= $tab === 'maindoeuvre' ? 'show active' : '' ?>" id="maindoeuvre" role="tabpanel">
    <?php
    // Calculer la durée en jours ouvrables
    $dureeJoursTab = 0;
    $dureeSemainesTab = 0;
    $joursFermesTab = 0;
    $dateDebutTab = $projet['date_debut_travaux'] ?? $projet['date_acquisition'];
    $dateFinTab = $projet['date_fin_prevue'];

    if ($dateDebutTab && $dateFinTab) {
        $d1 = new DateTime($dateDebutTab);
        $d2 = new DateTime($dateFinTab);

        $d2Inclusive = clone $d2;
        $d2Inclusive->modify('+1 day');

        $period = new DatePeriod($d1, new DateInterval('P1D'), $d2Inclusive);

        foreach ($period as $dt) {
            $dayOfWeek = (int)$dt->format('N');
            if ($dayOfWeek >= 6) {
                $joursFermesTab++;
            } else {
                $dureeJoursTab++;
            }
        }

        $dureeJoursTab = max(1, $dureeJoursTab);
        $dureeSemainesTab = ceil($dureeJoursTab / 5);
    }

    // Calculer le total estimé
    $totalHeuresEstimeesTab = 0;
    $totalCoutEstimeTab = 0;
    foreach ($employes as $emp) {
        $heuresSemaine = $planifications[$emp['id']] ?? 0;
        $heuresJour = $heuresSemaine / 5;
        $totalHeures = $heuresJour * $dureeJoursTab;
        $cout = $totalHeures * (float)$emp['taux_horaire'];
        $totalHeuresEstimeesTab += $totalHeures;
        $totalCoutEstimeTab += $cout;
    }
    ?>

    <!-- Résumé du projet -->
    <div class="alert alert-info mb-4">
        <div class="row align-items-center">
            <div class="col-md-4">
                <strong><i class="bi bi-calendar3 me-1"></i> Début travaux:</strong>
                <?= $dateDebutTab ? formatDate($dateDebutTab) : '<span class="text-warning">Non défini</span>' ?>
            </div>
            <div class="col-md-4">
                <strong><i class="bi bi-calendar-check me-1"></i> Fin prévue:</strong>
                <?= $dateFinTab ? formatDate($dateFinTab) : '<span class="text-warning">Non défini</span>' ?>
            </div>
            <div class="col-md-4">
                <strong><i class="bi bi-clock me-1"></i> Durée estimée:</strong>
                <?php if ($dureeJoursTab > 0): ?>
                    <span class="badge bg-primary fs-6"><?= $dureeJoursTab ?> jours ouvrables</span>
                    <span class="badge bg-primary fs-6 ms-1"><?= $joursFermesTab ?> jours fermés</span>
                <?php else: ?>
                    <span class="text-warning">Définir les dates dans l'onglet Base</span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($dureeSemainesTab == 0): ?>
        <div class="alert alert-warning">
            <i class="bi bi-exclamation-triangle me-2"></i>
            <strong>Attention:</strong> Vous devez d'abord définir les dates de début et fin de travaux dans l'onglet "Base" pour pouvoir calculer les coûts de main-d'œuvre.
        </div>
    <?php endif; ?>

    <!-- TOTAL EN HAUT - STICKY -->
    <div class="card bg-success text-white mb-3 sticky-top" style="top: 60px; z-index: 100;">
        <div class="card-body py-2">
            <div class="row align-items-center">
                <div class="col-auto">
                    <i class="bi bi-people fs-4"></i>
                </div>
                <div class="col">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <small class="opacity-75">Total Heures Estimées</small>
                            <h4 class="mb-0" id="totalHeures"><?= number_format($totalHeuresEstimeesTab, 1) ?> h</h4>
                        </div>
                        <div class="text-end border-start ps-3 ms-3">
                            <small class="opacity-75">Coût Main-d'œuvre Estimé</small>
                            <h4 class="mb-0" id="totalCout"><?= formatMoney($totalCoutEstimeTab) ?></h4>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <form method="POST" action="" id="formPlanification">
        <?php csrfField(); ?>
        <input type="hidden" name="action" value="planification">

        <div class="card">
            <div class="card-header">
                <i class="bi bi-person-lines-fill me-1"></i> Planification par employé
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Employé</th>
                            <th class="text-center" style="width: 100px;">Taux/h</th>
                            <th class="text-center" style="width: 140px;">Heures/semaine</th>
                            <th class="text-center" style="width: 100px;">Jours</th>
                            <th class="text-end" style="width: 100px;">Total heures</th>
                            <th class="text-end" style="width: 120px;">Coût estimé</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($employes as $emp):
                            $heuresSemaine = $planifications[$emp['id']] ?? 0;
                            $tauxHoraire = (float)$emp['taux_horaire'];
                            $heuresJour = $heuresSemaine / 5;
                            $totalHeures = $heuresJour * $dureeJoursTab;
                            $coutEstime = $totalHeures * $tauxHoraire;
                        ?>
                        <tr class="<?= $heuresSemaine > 0 ? 'table-success' : '' ?>">
                            <td>
                                <i class="bi bi-person me-1"></i>
                                <?= e($emp['nom_complet']) ?>
                                <?php if ($emp['role'] === 'admin'): ?>
                                    <span class="badge bg-secondary ms-1">Admin</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php if ($tauxHoraire > 0): ?>
                                    <?= formatMoney($tauxHoraire) ?>
                                <?php else: ?>
                                    <span class="text-warning" title="Définir dans Gestion des utilisateurs">
                                        <i class="bi bi-exclamation-triangle"></i> 0$
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <input type="number"
                                       class="form-control form-control-sm text-center heures-input"
                                       name="heures[<?= $emp['id'] ?>]"
                                       value="<?= $heuresSemaine ?>"
                                       min="0"
                                       max="80"
                                       step="0.5"
                                       data-taux="<?= $tauxHoraire ?>"
                                       data-jours="<?= $dureeJoursTab ?>"
                                       onfocus="this.select()">
                            </td>
                            <td class="text-center text-muted"><?= $dureeJoursTab ?></td>
                            <td class="text-end total-heures"><?= number_format($totalHeures, 1) ?> h</td>
                            <td class="text-end fw-bold cout-estime"><?= formatMoney($coutEstime) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="text-end mt-2">
            <button type="submit" class="btn btn-success">
                <i class="bi bi-check-circle me-1"></i>Enregistrer la planification
            </button>
        </div>
    </form>

    <div class="mt-3 text-center">
        <a href="<?= url('/admin/utilisateurs/liste.php') ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-gear me-1"></i>Modifier les taux horaires des employés
        </a>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const inputs = document.querySelectorAll('.heures-input');
        const totalHeuresEl = document.getElementById('totalHeures');
        const totalCoutEl = document.getElementById('totalCout');

        function formatMoney(val) {
            return val.toLocaleString('fr-CA', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' $';
        }

        function updateTotals() {
            let grandTotalHeures = 0;
            let grandTotalCout = 0;

            inputs.forEach(input => {
                const row = input.closest('tr');
                const heuresSemaine = parseFloat(input.value) || 0;
                const taux = parseFloat(input.dataset.taux) || 0;
                const jours = parseInt(input.dataset.jours) || 0;

                const heuresJour = heuresSemaine / 5;
                const totalHeures = heuresJour * jours;
                const cout = totalHeures * taux;

                row.querySelector('.total-heures').textContent = totalHeures.toFixed(1) + ' h';
                row.querySelector('.cout-estime').textContent = formatMoney(cout);

                if (heuresSemaine > 0) {
                    row.classList.add('table-success');
                } else {
                    row.classList.remove('table-success');
                }

                grandTotalHeures += totalHeures;
                grandTotalCout += cout;
            });

            totalHeuresEl.textContent = grandTotalHeures.toFixed(1) + ' h';
            totalCoutEl.textContent = formatMoney(grandTotalCout);
        }

        inputs.forEach(input => {
            input.addEventListener('input', updateTotals);
            input.addEventListener('change', updateTotals);
        });
    });
    </script>
    </div><!-- Fin TAB MAIN-D'ŒUVRE -->

    <!-- TAB TEMPS -->
    <div class="tab-pane fade <?= $tab === 'temps' ? 'show active' : '' ?>" id="temps" role="tabpanel">
        <?php
        $totalHeuresTab = array_sum(array_column($heuresProjet, 'heures'));
        $totalCoutTab = 0;
        foreach ($heuresProjet as $h) {
            $taux = $h['taux_horaire'] > 0 ? $h['taux_horaire'] : $h['taux_actuel'];
            $totalCoutTab += $h['heures'] * $taux;
        }
        $totalAvancesActives = array_sum(array_column($avancesListe, 'montant'));
        ?>

        <!-- Barre compacte : Stats -->
        <div class="d-flex flex-wrap align-items-center gap-2 mb-3 p-2 rounded" style="background: rgba(255,255,255,0.03);">
            <div class="d-flex align-items-center px-3 py-1 rounded" style="background: rgba(13,110,253,0.15);">
                <i class="bi bi-clock text-primary me-2"></i>
                <span class="text-muted me-1">Heures:</span>
                <strong class="text-primary"><?= number_format($totalHeuresTab, 1) ?> h</strong>
            </div>
            <div class="d-flex align-items-center px-3 py-1 rounded" style="background: rgba(25,135,84,0.15);">
                <i class="bi bi-cash text-success me-2"></i>
                <span class="text-muted me-1">Coût:</span>
                <strong class="text-success"><?= formatMoney($totalCoutTab) ?></strong>
            </div>
            <?php if ($totalAvancesActives > 0): ?>
            <div class="d-flex align-items-center px-3 py-1 rounded" style="background: rgba(220,53,69,0.15);">
                <i class="bi bi-wallet2 text-danger me-2"></i>
                <span class="text-muted me-1">Avances:</span>
                <strong class="text-danger"><?= formatMoney($totalAvancesActives) ?></strong>
            </div>
            <?php endif; ?>
            <div class="ms-auto d-flex gap-2">
                <button type="button" class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#modalAvance">
                    <i class="bi bi-plus-circle me-1"></i>Nouvelle avance
                </button>
                <span class="badge bg-secondary align-self-center"><?= count($heuresProjet) ?> entrées</span>
            </div>
        </div>

        <div class="row">
            <!-- Résumé par employé -->
            <div class="col-lg-7 mb-3">
                <div class="card">
                    <div class="card-header py-2">
                        <i class="bi bi-people me-2"></i>Résumé par employé
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($resumeEmployes)): ?>
                            <div class="text-center py-3 text-muted">Aucune heure enregistrée</div>
                        <?php else: ?>
                        <table class="table table-sm table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Employé</th>
                                    <th class="text-end">Heures</th>
                                    <th class="text-end">Brut</th>
                                    <th class="text-end text-danger">Avances</th>
                                    <th class="text-end" style="background: rgba(25,135,84,0.1);">Net</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($resumeEmployes as $emp):
                                    $avEmp = $avancesParEmploye[$emp['user_id']] ?? ['total' => 0, 'nb' => 0];
                                    $netEmp = $emp['montant_approuve'] - $avEmp['total'];
                                ?>
                                <tr>
                                    <td>
                                        <?= e($emp['nom_complet']) ?>
                                        <?php if ($emp['heures_attente'] > 0): ?>
                                            <small class="text-warning">(+<?= number_format($emp['heures_attente'], 1) ?>h en attente)</small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end"><?= number_format($emp['heures_approuvees'], 1) ?>h</td>
                                    <td class="text-end"><?= formatMoney($emp['montant_approuve']) ?></td>
                                    <td class="text-end">
                                        <?php if ($avEmp['total'] > 0): ?>
                                            <span class="text-danger">-<?= formatMoney($avEmp['total']) ?></span>
                                            <small class="text-muted">(<?= $avEmp['nb'] ?>)</small>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end fw-bold" style="background: rgba(25,135,84,0.1);">
                                        <?= formatMoney($netEmp) ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Avances actives -->
            <div class="col-lg-5 mb-3">
                <div class="card">
                    <div class="card-header py-2 d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-wallet2 me-2"></i>Avances actives</span>
                        <?php if ($totalAvancesActives > 0): ?>
                            <span class="badge bg-danger"><?= formatMoney($totalAvancesActives) ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="card-body p-0" style="max-height: 250px; overflow-y: auto;">
                        <?php if (empty($avancesListe)): ?>
                            <div class="text-center py-3 text-muted">
                                <i class="bi bi-check-circle"></i> Aucune avance
                            </div>
                        <?php else: ?>
                        <table class="table table-sm table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Employé</th>
                                    <th class="text-end">Montant</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($avancesListe as $av): ?>
                                <tr>
                                    <td><small><?= formatDate($av['date_avance']) ?></small></td>
                                    <td><?= e($av['employe_nom']) ?></td>
                                    <td class="text-end text-danger fw-bold"><?= formatMoney($av['montant']) ?></td>
                                    <td>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Annuler cette avance?');">
                                            <?php csrfField(); ?>
                                            <input type="hidden" name="action" value="annuler_avance">
                                            <input type="hidden" name="avance_id" value="<?= $av['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-1" title="Annuler">
                                                <i class="bi bi-x"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                                <?php if ($av['raison']): ?>
                                <tr>
                                    <td colspan="4" class="py-0 ps-4 border-0">
                                        <small class="text-muted"><?= e($av['raison']) ?></small>
                                    </td>
                                </tr>
                                <?php endif; ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tableau détaillé des heures -->
        <div class="card">
            <div class="card-header py-2">
                <i class="bi bi-clock-history me-2"></i>Détail des heures
            </div>
            <div class="card-body p-0">
                <?php if (empty($heuresProjet)): ?>
                    <div class="text-center py-3 text-muted">
                        <i class="bi bi-info-circle me-2"></i>Aucune heure enregistrée pour ce projet.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0">
                            <thead class="table-dark">
                                <tr>
                                    <th>Date</th>
                                    <th>Employé</th>
                                    <th class="text-end">Heures</th>
                                    <th class="text-end">Taux</th>
                                    <th class="text-end">Montant</th>
                                    <th>Statut</th>
                                    <th>Description</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($heuresProjet as $h):
                                    $taux = $h['taux_horaire'] > 0 ? $h['taux_horaire'] : $h['taux_actuel'];
                                    $montant = $h['heures'] * $taux;
                                ?>
                                <tr>
                                    <td><?= formatDate($h['date_travail']) ?></td>
                                    <td><?= e($h['employe_nom']) ?></td>
                                    <td class="text-end"><?= number_format($h['heures'], 1) ?></td>
                                    <td class="text-end"><?= formatMoney($taux) ?>/h</td>
                                    <td class="text-end fw-bold"><?= formatMoney($montant) ?></td>
                                    <td>
                                        <?php
                                        $statusClass = match($h['statut']) {
                                            'approuvee' => 'bg-success',
                                            'rejetee' => 'bg-danger',
                                            default => 'bg-warning'
                                        };
                                        $statusLabel = match($h['statut']) {
                                            'approuvee' => 'Approuvée',
                                            'rejetee' => 'Rejetée',
                                            default => 'En attente'
                                        };
                                        ?>
                                        <span class="badge <?= $statusClass ?>"><?= $statusLabel ?></span>
                                    </td>
                                    <td><small class="text-muted"><?= e($h['description'] ?? '') ?></small></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div><!-- Fin TAB TEMPS -->

    <!-- Modal Nouvelle Avance -->
    <div class="modal fade" id="modalAvance" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <?php csrfField(); ?>
                    <input type="hidden" name="action" value="ajouter_avance">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-wallet2 me-2"></i>Nouvelle avance</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Employé *</label>
                            <select class="form-select" name="avance_user_id" required>
                                <option value="">Sélectionner...</option>
                                <?php foreach ($employesActifs as $emp): ?>
                                <option value="<?= $emp['id'] ?>"><?= e($emp['nom_complet']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Montant *</label>
                            <div class="input-group">
                                <input type="text" class="form-control" name="avance_montant" placeholder="0.00" required>
                                <span class="input-group-text">$</span>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Date</label>
                            <input type="date" class="form-control" name="avance_date" value="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Raison / Note</label>
                            <textarea class="form-control" name="avance_raison" rows="2" placeholder="Optionnel..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-success">
                            <i class="bi bi-check me-1"></i>Ajouter l'avance
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- TAB PHOTOS -->
    <div class="tab-pane fade <?= $tab === 'photos' ? 'show active' : '' ?>" id="photos" role="tabpanel">
        <?php
        // Extraire les employés et catégories uniques pour les filtres
        $photosEmployes = !empty($photosProjet) ? array_unique(array_column($photosProjet, 'employe_nom')) : [];
        $photosCategoriesFilter = !empty($photosProjet) ? array_unique(array_filter(array_column($photosProjet, 'description'))) : [];
        sort($photosEmployes);
        sort($photosCategoriesFilter);
        ?>

        <!-- Barre compacte : Filtres + Actions -->
        <div class="d-flex flex-wrap align-items-center gap-2 mb-3 p-2 rounded" style="background: rgba(255,255,255,0.03);">
            <!-- Icône -->
            <div class="d-flex align-items-center px-3 py-1 rounded" style="background: rgba(13,110,253,0.15);">
                <i class="bi bi-camera text-primary me-2"></i>
                <strong class="text-primary" id="photosCount"><?= count($photosProjet) ?></strong>
                <span class="text-muted ms-1">photos</span>
            </div>

            <!-- Séparateur -->
            <div class="vr mx-1 d-none d-md-block" style="height: 24px;"></div>

            <!-- Filtres -->
            <select class="form-select form-select-sm" id="filtrePhotosEmploye" onchange="filtrerPhotos()" style="width: auto; min-width: 140px;">
                <option value="">Tous employés</option>
                <?php foreach ($photosEmployes as $emp): ?>
                    <option value="<?= e($emp) ?>"><?= e($emp) ?></option>
                <?php endforeach; ?>
            </select>

            <select class="form-select form-select-sm" id="filtrePhotosCategorie" onchange="filtrerPhotos()" style="width: auto; min-width: 150px;">
                <option value="">Toutes catégories</option>
                <?php foreach ($photosCategoriesFilter as $cat): ?>
                    <option value="<?= e($cat) ?>"><?= e($cat) ?></option>
                <?php endforeach; ?>
            </select>

            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="resetFiltresPhotos()" title="Réinitialiser">
                <i class="bi bi-x-circle"></i>
            </button>

            <!-- Spacer + Actions à droite -->
            <div class="ms-auto d-flex gap-2">
                <?php if (count($photosProjet) > 1): ?>
                <button type="button" class="btn btn-outline-primary btn-sm" id="btnReorganiser" onclick="toggleReorganisation()">
                    <i class="bi bi-arrows-move me-1"></i>Réorganiser
                </button>
                <button type="button" class="btn btn-outline-secondary btn-sm" id="btnSelectionner" onclick="toggleSelectionMode()">
                    <i class="bi bi-check2-square me-1"></i>Sélectionner
                </button>
                <?php endif; ?>
                <button type="button" class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#modalAjoutPhoto">
                    <i class="bi bi-plus me-1"></i>Ajouter
                </button>
            </div>
            <!-- Barre de sélection (cachée par défaut) -->
            <div id="selectionBar" class="d-none mt-2 p-2 bg-dark rounded d-flex align-items-center gap-2">
                <button type="button" class="btn btn-outline-light btn-sm" onclick="selectAllPhotos()">
                    <i class="bi bi-check-all me-1"></i>Tout sélectionner
                </button>
                <button type="button" class="btn btn-outline-light btn-sm" onclick="deselectAllPhotos()">
                    <i class="bi bi-x-lg me-1"></i>Tout désélectionner
                </button>
                <span class="text-white ms-2"><span id="selectedCount">0</span> sélectionnée(s)</span>
                <button type="button" class="btn btn-danger btn-sm ms-auto" id="btnDeleteSelected" onclick="deleteSelectedPhotos()" disabled>
                    <i class="bi bi-trash me-1"></i>Supprimer la sélection
                </button>
                <button type="button" class="btn btn-secondary btn-sm" onclick="toggleSelectionMode()">
                    Annuler
                </button>
            </div>
        </div>

        <style>
            .photo-grid-col {
                overflow: hidden;
                min-width: 0;
            }
            .photo-grid-col .position-relative {
                overflow: hidden;
            }
            .photo-thumb {
                width: 100%;
                max-width: 100%;
                aspect-ratio: 4/3;
                object-fit: cover;
                border-radius: 0.375rem;
                display: block;
            }
            .video-thumb-container {
                width: 100%;
                max-width: 100%;
                aspect-ratio: 4/3;
                background: #1a1d21;
                display: flex;
                align-items: center;
                justify-content: center;
                position: relative;
                border-radius: 0.375rem;
                overflow: hidden;
            }
            .video-thumb-container video {
                width: 100%;
                height: 100%;
                object-fit: cover;
                position: absolute;
                top: 0;
                left: 0;
            }
            /* Checkbox pour sélection */
            .photo-checkbox {
                display: none;
                position: absolute;
                top: 5px;
                left: 5px;
                z-index: 10;
                width: 22px;
                height: 22px;
                cursor: pointer;
            }
            .selection-mode .photo-checkbox {
                display: block;
            }
            .selection-mode .photo-item {
                cursor: pointer;
            }
            .selection-mode .photo-item.selected .position-relative {
                outline: 3px solid #0d6efd;
                outline-offset: -3px;
                border-radius: 0.375rem;
            }
            .selection-mode .photo-item.selected .position-relative::after {
                content: '';
                position: absolute;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(13, 110, 253, 0.2);
                pointer-events: none;
                border-radius: 0.375rem;
                z-index: 5;
            }
            .selection-mode .btn-delete-single {
                display: none !important;
            }
            /* Plus de colonnes sur très grands écrans */
            @media (min-width: 1400px) {
                .photo-grid-col { flex: 0 0 auto; width: 12.5%; overflow: hidden; min-width: 0; } /* 8 colonnes */
            }
            @media (min-width: 1800px) {
                .photo-grid-col { flex: 0 0 auto; width: 10%; overflow: hidden; min-width: 0; } /* 10 colonnes */
            }
            @media (min-width: 2200px) {
                .photo-grid-col { flex: 0 0 auto; width: 8.333%; overflow: hidden; min-width: 0; } /* 12 colonnes */
            }
        </style>
        <?php if (empty($photosProjet)): ?>
            <div class="alert alert-info">
                <i class="bi bi-info-circle me-2"></i>Aucune photo pour ce projet. Cliquez sur "Ajouter" pour en téléverser.
            </div>
        <?php else: ?>
            <div class="row g-2" id="photosGrid">
                <?php foreach ($photosProjet as $photo):
                    $extension = strtolower(pathinfo($photo['fichier'], PATHINFO_EXTENSION));
                    $isVideo = in_array($extension, ['mp4', 'mov', 'avi', 'mkv', 'webm', 'm4v']);
                    $mediaUrl = url('/serve-photo.php?file=' . urlencode($photo['fichier']));
                ?>
                <div class="col-6 col-md-3 col-lg-2 photo-grid-col photo-item" data-id="<?= $photo['id'] ?>" data-employe="<?= e($photo['employe_nom']) ?>" data-categorie="<?= e($photo['description'] ?? '') ?>" onclick="togglePhotoSelection(this, event)">
                    <div class="position-relative">
                        <!-- Checkbox pour sélection multiple -->
                        <input type="checkbox" class="photo-checkbox" data-photo-id="<?= $photo['id'] ?>" onclick="event.stopPropagation(); togglePhotoSelection(this.closest('.photo-item'), event)">
                        <a href="<?= $mediaUrl ?>" target="_blank" class="d-block photo-link">
                            <?php if ($isVideo): ?>
                                <div class="video-thumb-container">
                                    <video src="<?= $mediaUrl ?>" muted preload="metadata"></video>
                                    <div style="position:absolute;z-index:2;background:rgba(0,0,0,0.6);border-radius:50%;width:40px;height:40px;display:flex;align-items:center;justify-content:center;">
                                        <i class="bi bi-play-fill text-white" style="font-size:1.5rem;margin-left:3px;"></i>
                                    </div>
                                </div>
                            <?php else: ?>
                                <img src="<?= $mediaUrl ?>&thumb=1" alt="Photo" class="photo-thumb" loading="lazy">
                            <?php endif; ?>
                        </a>
                        <!-- Bouton suppression -->
                        <form method="POST" class="position-absolute top-0 end-0 btn-delete-single" style="margin:3px;">
                            <?php csrfField(); ?>
                            <input type="hidden" name="action" value="delete_photo">
                            <input type="hidden" name="photo_id" value="<?= $photo['id'] ?>">
                            <button type="submit" class="btn btn-danger btn-sm" style="padding:2px 5px;font-size:10px;line-height:1;"
                                    onclick="return confirm('Supprimer cette photo ?')">
                                <i class="bi bi-trash"></i>
                            </button>
                        </form>
                        <!-- Info overlay sur l'image -->
                        <div class="position-absolute bottom-0 start-0 end-0 bg-dark bg-opacity-75 text-white p-1 rounded-bottom" style="font-size:0.7rem;">
                            <div class="d-flex justify-content-between align-items-center">
                                <small><?= formatDate($photo['date_prise']) ?></small>
                                <small><?= e($photo['employe_nom']) ?></small>
                            </div>
                            <?php if (!empty($photo['description'])): ?>
                                <small class="d-block text-truncate"><?= e($photo['description']) ?></small>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <?php if ($hasMorePhotos): ?>
            <div class="text-center mt-3">
                <a href="?id=<?= $projetId ?>&tab=photos&photos_page=<?= $photosPage + 1 ?>"
                   class="btn btn-outline-primary">
                    <i class="bi bi-arrow-down-circle me-1"></i>
                    Voir plus de photos (<?= count($photosProjet) ?> / <?= $totalPhotos ?>)
                </a>
            </div>
            <?php elseif ($totalPhotos > 0): ?>
            <div class="text-center mt-3 text-muted small">
                <i class="bi bi-check-circle me-1"></i>
                <?= $totalPhotos ?> photo<?= $totalPhotos > 1 ? 's' : '' ?> affichée<?= $totalPhotos > 1 ? 's' : '' ?>
            </div>
            <?php endif; ?>
        <?php endif; ?>

        <!-- Formulaire caché pour suppression multiple -->
        <form id="bulkDeleteForm" method="POST" style="display:none;">
            <?php csrfField(); ?>
            <input type="hidden" name="action" value="delete_photos_bulk">
            <input type="hidden" name="photo_ids" id="bulkDeletePhotoIds" value="">
        </form>
    </div><!-- Fin TAB PHOTOS -->

    <!-- MODAL AJOUT PHOTO -->
    <div class="modal fade" id="modalAjoutPhoto" tabindex="-1" aria-labelledby="modalAjoutPhotoLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalAjoutPhotoLabel"><i class="bi bi-camera-fill me-2"></i>Ajouter des photos</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
                </div>
                <form method="POST" enctype="multipart/form-data" id="formAjoutPhoto">
                    <?php csrfField(); ?>
                    <input type="hidden" name="action" value="upload_photo">
                    <input type="hidden" name="groupe_id" value="<?= uniqid('grp_', true) ?>">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="photo_description" class="form-label">Catégorie</label>
                            <select class="form-select" id="photo_description" name="description">
                                <option value="">-- Sélectionner une catégorie --</option>
                                <?php foreach ($photoCategories as $cat): ?>
                                    <option value="<?= e($cat['cle']) ?>"><?= e($cat['nom']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Input caméra -->
                        <input type="file" id="adminCameraInput" name="camera_photo"
                               accept="image/*,image/heic,image/heif,.heic,.heif" capture="environment"
                               class="d-none"
                               onchange="previewAdminPhotos(this)">

                        <!-- Input galerie -->
                        <input type="file" id="adminGalleryInput" name="gallery_photos[]"
                               accept="image/*,image/heic,image/heif,.heic,.heif,video/*" multiple
                               class="d-none"
                               onchange="previewAdminPhotos(this)">

                        <!-- Boutons -->
                        <div class="d-grid gap-2 mb-3">
                            <button type="button" class="btn btn-primary py-3" onclick="document.getElementById('adminCameraInput').click()">
                                <i class="bi bi-camera-fill me-2"></i>Prendre une photo
                            </button>
                        </div>
                        <div class="text-center mb-3">
                            <span class="badge bg-secondary">ou</span>
                        </div>
                        <div class="d-grid gap-2">
                            <button type="button" class="btn btn-outline-primary py-3" onclick="document.getElementById('adminGalleryInput').click()">
                                <i class="bi bi-images me-2"></i>Choisir depuis la galerie
                            </button>
                        </div>

                        <!-- Prévisualisation -->
                        <div id="adminPhotoPreview" class="row g-2 mt-3" style="display:none;"></div>
                        <div id="adminPhotoCount" class="alert alert-info mt-2" style="display:none;">
                            <i class="bi bi-images me-2"></i><span id="adminPhotoCountText"></span>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-success" id="adminSubmitBtn" style="display:none;">
                            <i class="bi bi-cloud-upload me-2"></i>Téléverser
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- TAB FACTURES -->
    <div class="tab-pane fade <?= $tab === 'factures' ? 'show active' : '' ?>" id="factures" role="tabpanel">
        <?php
        $totalFacturesTab = array_sum(array_column($facturesProjet, 'montant_total'));
        $facturesCategories = array_unique(array_filter(array_column($facturesProjet, 'categorie_nom')));
        $totalImpayeProjet = array_sum(array_map(function($f) {
            return empty($f['est_payee']) ? $f['montant_total'] : 0;
        }, $facturesProjet));
        sort($facturesCategories);
        $facturesFournisseurs = array_unique(array_filter(array_column($facturesProjet, 'fournisseur')));
        sort($facturesFournisseurs);
        ?>

        <!-- Barre compacte : Total + Filtres + Actions -->
        <div class="d-flex flex-wrap align-items-center gap-2 mb-3 p-2 rounded" style="background: rgba(255,255,255,0.03);">
            <!-- Total -->
            <div class="d-flex align-items-center px-3 py-1 rounded" style="background: rgba(220,53,69,0.15);">
                <i class="bi bi-receipt text-danger me-2"></i>
                <span class="text-muted me-2">Total:</span>
                <strong class="text-danger" id="facturesTotal"><?= formatMoney($totalFacturesTab) ?></strong>
            </div>
<?php if ($totalImpayeProjet > 0): ?>            <!-- Impayé -->            <div class="d-flex align-items-center px-3 py-1 rounded" style="background: rgba(255,193,7,0.15);">                <i class="bi bi-exclamation-circle text-warning me-2"></i>                <span class="text-muted me-2">Impayé:</span>                <strong class="text-warning"><?= formatMoney($totalImpayeProjet) ?></strong>            </div>            <?php endif; ?>

            <!-- Séparateur -->
            <div class="vr mx-1 d-none d-md-block" style="height: 24px;"></div>

            <!-- Filtres -->
            <select class="form-select form-select-sm" id="filtreFacturesStatut" onchange="filtrerFactures()" style="width: auto; min-width: 130px;">
                <option value="">Tous statuts</option>
                <option value="en_attente">En attente</option>
                <option value="approuvee">Approuvée</option>
                <option value="rejetee">Rejetée</option>
            </select>

            <select class="form-select form-select-sm" id="filtreFacturesCategorie" onchange="filtrerFactures()" style="width: auto; min-width: 150px;">
                <option value="">Toutes catégories</option>
                <?php foreach ($facturesCategories as $cat): ?>
                    <option value="<?= e($cat) ?>"><?= e($cat) ?></option>
                <?php endforeach; ?>
            </select>

            <select class="form-select form-select-sm" id="filtreFacturesFournisseur" onchange="filtrerFactures()" style="width: auto; min-width: 150px;">
                <option value="">Tous fournisseurs</option>
                <?php foreach ($facturesFournisseurs as $four): ?>
                    <option value="<?= e($four) ?>"><?= e($four) ?></option>
                <?php endforeach; ?>
            </select>

            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="resetFiltresFactures()" title="Réinitialiser">
                <i class="bi bi-x-circle"></i>
            </button>

            <!-- Spacer + Actions à droite -->
            <div class="ms-auto d-flex align-items-center gap-2">
                <span class="badge bg-secondary" id="facturesCount"><?= count($facturesProjet) ?> factures</span>
                <a href="<?= url('/admin/factures/nouvelle.php?projet=' . $projetId) ?>" class="btn btn-success btn-sm">
                    <i class="bi bi-plus me-1"></i>Nouvelle
                </a>
            </div>
        </div>

        <?php if (empty($facturesProjet)): ?>
            <div class="alert alert-info">
                <i class="bi bi-info-circle me-2"></i>Aucune facture pour ce projet. Cliquez sur "Nouvelle" pour en ajouter.
            </div>
        <?php else: ?>
            <div class="table-responsive" style="overflow: visible;">
                <table class="table table-sm table-hover" id="facturesTable">
                    <thead class="table-dark">
                        <tr>
                            <th>Date</th>
                            <th>Fournisseur</th>
                            <th>Catégorie</th>
                            <th class="text-end">Montant</th>
                            <th>Statut</th>
                            <th>Paiement</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($facturesProjet as $f): ?>
                        <tr class="facture-row" data-statut="<?= e($f['statut']) ?>" data-categorie="<?= e($f['categorie_nom'] ?? '') ?>" data-fournisseur="<?= e($f['fournisseur'] ?? '') ?>" data-montant="<?= $f['montant_total'] ?>">
                            <td><?= formatDate($f['date_facture']) ?></td>
                            <td><?= e($f['fournisseur'] ?? 'N/A') ?></td>
                            <td><?= e($f['categorie_nom'] ?? 'N/A') ?></td>
                            <td class="text-end fw-bold"><?= formatMoney($f['montant_total']) ?></td>
                            <td>
                                <?php
                                $statusClass = match($f['statut']) {
                                    'approuvee' => 'bg-success',
                                    'rejetee' => 'bg-danger',
                                    default => 'bg-warning text-dark'
                                };
                                ?>
                                <div class="dropdown">
                                    <button class="btn btn-sm <?= $statusClass ?> dropdown-toggle" type="button" data-bs-toggle="dropdown" data-bs-strategy="fixed" aria-expanded="false">
                                        <?= getStatutFactureLabel($f['statut']) ?>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end">
                                        <li><a class="dropdown-item change-facture-status <?= $f['statut'] === 'en_attente' ? 'active' : '' ?>" href="#" data-facture-id="<?= $f['id'] ?>" data-status="en_attente"><i class="bi bi-clock text-warning me-2"></i>En attente</a></li>
                                        <li><a class="dropdown-item change-facture-status <?= $f['statut'] === 'approuvee' ? 'active' : '' ?>" href="#" data-facture-id="<?= $f['id'] ?>" data-status="approuvee"><i class="bi bi-check-circle text-success me-2"></i>Approuver</a></li>
                                        <li><a class="dropdown-item change-facture-status <?= $f['statut'] === 'rejetee' ? 'active' : '' ?>" href="#" data-facture-id="<?= $f['id'] ?>" data-status="rejetee"><i class="bi bi-x-circle text-danger me-2"></i>Rejeter</a></li>
                                    </ul>
                                </div>
                            </td>
                            <td>
                                <a href="<?= url('/admin/factures/liste.php?toggle_paiement=1&id=' . $f['id']) ?>"
                                   class="badge <?= !empty($f['est_payee']) ? 'bg-success' : 'bg-primary' ?> text-white"
                                   style="cursor:pointer; text-decoration:none;"
                                   title="Cliquer pour changer le statut"
                                   onclick="event.preventDefault(); togglePaiementFacture(<?= $f['id'] ?>, this);">
                                    <?php if (!empty($f['est_payee'])): ?>
                                        <i class="bi bi-check-circle me-1"></i>Payé
                                    <?php else: ?>
                                        <i class="bi bi-clock me-1"></i>Non payé
                                    <?php endif; ?>
                                </a>
                            </td>
                            <td>
                                <a href="<?= url('/admin/factures/modifier.php?id=' . $f['id']) ?>" class="btn btn-sm btn-outline-primary" title="Modifier">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <form action="<?= url('/admin/factures/supprimer.php') ?>" method="POST" class="d-inline" onsubmit="return confirm('Supprimer cette facture?')">
                                    <?php csrfField(); ?>
                                    <input type="hidden" name="facture_id" value="<?= $f['id'] ?>">
                                    <input type="hidden" name="redirect" value="/admin/projets/detail.php?id=<?= $projetId ?>&tab=factures">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Supprimer">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div><!-- Fin TAB FACTURES -->

    <!-- TAB CHECKLIST -->
    <div class="tab-pane fade <?= $tab === 'checklist' ? 'show active' : '' ?>" id="checklist" role="tabpanel">
        <?php
        // Auto-créer les tables si nécessaire
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS checklist_templates (id INT AUTO_INCREMENT PRIMARY KEY, nom VARCHAR(255) NOT NULL, description TEXT, ordre INT DEFAULT 0, actif TINYINT(1) DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $pdo->exec("CREATE TABLE IF NOT EXISTS checklist_template_items (id INT AUTO_INCREMENT PRIMARY KEY, template_id INT NOT NULL, nom VARCHAR(255) NOT NULL, description TEXT, ordre INT DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $pdo->exec("CREATE TABLE IF NOT EXISTS projet_checklists (id INT AUTO_INCREMENT PRIMARY KEY, projet_id INT NOT NULL, template_item_id INT NOT NULL, complete TINYINT(1) DEFAULT 0, complete_date DATETIME NULL, complete_by VARCHAR(100) NULL, notes TEXT, UNIQUE KEY unique_projet_item (projet_id, template_item_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $pdo->exec("CREATE TABLE IF NOT EXISTS projet_documents (id INT AUTO_INCREMENT PRIMARY KEY, projet_id INT NOT NULL, nom VARCHAR(255) NOT NULL, fichier VARCHAR(500) NOT NULL, type VARCHAR(100), taille INT, uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Exception $e) {}

        // Récupérer les templates actifs avec leurs items
        $checklistTemplates = [];
        try {
            $stmt = $pdo->query("SELECT * FROM checklist_templates WHERE actif = 1 ORDER BY ordre, nom");
            $checklistTemplates = $stmt->fetchAll();
            foreach ($checklistTemplates as &$tpl) {
                $stmt = $pdo->prepare("SELECT * FROM checklist_template_items WHERE template_id = ? ORDER BY ordre, nom");
                $stmt->execute([$tpl['id']]);
                $tpl['items'] = $stmt->fetchAll();
            }
            unset($tpl);
        } catch (Exception $e) {}

        // Récupérer l'état des checklists pour ce projet
        $projetChecklists = [];
        try {
            $stmt = $pdo->prepare("SELECT * FROM projet_checklists WHERE projet_id = ?");
            $stmt->execute([$projetId]);
            foreach ($stmt->fetchAll() as $pc) {
                $projetChecklists[$pc['template_item_id']] = $pc;
            }
        } catch (Exception $e) {}

        // Récupérer les documents du projet
        $projetDocuments = [];
        try {
            $stmt = $pdo->prepare("SELECT * FROM projet_documents WHERE projet_id = ? ORDER BY uploaded_at DESC");
            $stmt->execute([$projetId]);
            $projetDocuments = $stmt->fetchAll();
        } catch (Exception $e) {}

        // Récupérer les Google Sheets du projet
        $googleSheets = [];
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS projet_google_sheets (id INT AUTO_INCREMENT PRIMARY KEY, projet_id INT NOT NULL, nom VARCHAR(255) NOT NULL, url VARCHAR(500) NOT NULL, ordre INT DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $stmt = $pdo->prepare("SELECT * FROM projet_google_sheets WHERE projet_id = ? ORDER BY ordre, created_at");
            $stmt->execute([$projetId]);
            $googleSheets = $stmt->fetchAll();
        } catch (Exception $e) {}
        ?>

        <style>
            /* Tooltips plus grands */
            .tooltip {
                font-size: 1rem !important;
            }
            .tooltip-inner {
                max-width: 350px !important;
                padding: 10px 15px !important;
                font-size: 1rem !important;
                line-height: 1.5 !important;
            }

            /* Animation pulse pour checkbox complétée */
            @keyframes checkPulse {
                0%, 100% {
                    box-shadow: 0 0 0 0 rgba(25, 135, 84, 0.7);
                }
                50% {
                    box-shadow: 0 0 0 6px rgba(25, 135, 84, 0);
                }
            }
            .checklist-item:checked {
                background-color: #198754 !important;
                border-color: #198754 !important;
                animation: checkPulse 2s ease-in-out infinite;
            }
            .checklist-item:checked::after {
                content: '';
                position: absolute;
            }
        </style>

        <div class="row">
            <!-- Checklists -->
            <div class="col-12">
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-list-check me-2"></i>Checklists</span>
                        <a href="<?= url('/admin/checklists/liste.php') ?>" class="btn btn-sm btn-outline-secondary" title="Gérer les templates">
                            <i class="bi bi-gear"></i>
                        </a>
                    </div>
                    <div class="card-body">
                        <?php if (empty($checklistTemplates)): ?>
                            <div class="text-center text-muted py-4">
                                <i class="bi bi-list-check" style="font-size: 2rem;"></i>
                                <p class="mb-0">Aucune checklist configurée.</p>
                                <a href="<?= url('/admin/checklists/liste.php') ?>" class="btn btn-primary btn-sm mt-2">Créer des checklists</a>
                            </div>
                        <?php else: ?>
                            <div class="accordion" id="checklistAccordion">
                                <?php foreach ($checklistTemplates as $idx => $tpl): ?>
                                    <?php
                                    $totalItems = count($tpl['items']);
                                    $completedItems = 0;
                                    foreach ($tpl['items'] as $item) {
                                        if (!empty($projetChecklists[$item['id']]['complete'])) {
                                            $completedItems++;
                                        }
                                    }
                                    $pctComplete = $totalItems > 0 ? round(($completedItems / $totalItems) * 100) : 0;
                                    ?>
                                    <div class="accordion-item">
                                        <h2 class="accordion-header">
                                            <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#checklist<?= $tpl['id'] ?>">
                                                <span class="me-auto"><?= e($tpl['nom']) ?></span>
                                                <span class="badge <?= $pctComplete == 100 ? 'bg-success' : 'bg-secondary' ?> me-2"><?= $completedItems ?>/<?= $totalItems ?></span>
                                            </button>
                                        </h2>
                                        <div id="checklist<?= $tpl['id'] ?>" class="accordion-collapse collapse show">
                                            <div class="accordion-body p-0">
                                                <?php if (empty($tpl['items'])): ?>
                                                    <p class="text-muted small p-3 mb-0">Aucun item dans cette checklist.</p>
                                                <?php else: ?>
                                                    <ul class="list-group list-group-flush">
                                                        <?php foreach ($tpl['items'] as $item): ?>
                                                            <?php
                                                            $isComplete = !empty($projetChecklists[$item['id']]['complete']);
                                                            $completeDate = $projetChecklists[$item['id']]['complete_date'] ?? null;
                                                            $itemNotes = $projetChecklists[$item['id']]['notes'] ?? '';
                                                            ?>
                                                            <li class="list-group-item d-flex align-items-center <?= $isComplete ? 'bg-success bg-opacity-10' : '' ?>">
                                                                <div class="form-check flex-grow-1">
                                                                    <input class="form-check-input checklist-item" type="checkbox"
                                                                           id="item<?= $item['id'] ?>"
                                                                           data-item-id="<?= $item['id'] ?>"
                                                                           <?= $isComplete ? 'checked' : '' ?>>
                                                                    <label class="form-check-label <?= $isComplete ? 'text-success fw-semibold' : '' ?>" for="item<?= $item['id'] ?>">
                                                                        <?= $isComplete ? '<i class="bi bi-check-lg me-1"></i>' : '' ?><?= e($item['nom']) ?>
                                                                    </label>
                                                                    <?php if ($itemNotes): ?>
                                                                        <i class="bi bi-info-circle text-info ms-2"
                                                                           data-bs-toggle="tooltip"
                                                                           data-bs-placement="top"
                                                                           title="<?= e($itemNotes) ?>"></i>
                                                                    <?php endif; ?>
                                                                </div>
                                                                <button type="button" class="btn btn-sm btn-link text-secondary p-0 me-2 edit-note-btn"
                                                                        data-bs-toggle="modal"
                                                                        data-bs-target="#editNoteModal"
                                                                        data-item-id="<?= $item['id'] ?>"
                                                                        data-item-nom="<?= e($item['nom']) ?>"
                                                                        data-notes="<?= e($itemNotes) ?>"
                                                                        title="Ajouter/modifier une note">
                                                                    <i class="bi bi-pencil-square"></i>
                                                                </button>
                                                                <button type="button" class="btn btn-sm btn-link text-danger p-0 me-2 delete-checklist-btn"
                                                                        data-item-id="<?= $item['id'] ?>"
                                                                        data-item-nom="<?= e($item['nom']) ?>"
                                                                        title="Réinitialiser cet item">
                                                                    <i class="bi bi-x-circle"></i>
                                                                </button>
                                                                <?php if ($isComplete && $completeDate): ?>
                                                                    <small class="text-success"><?= date('d/m/Y', strtotime($completeDate)) ?></small>
                                                                <?php endif; ?>
                                                            </li>
                                                        <?php endforeach; ?>
                                                    </ul>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <script>
        // Toggle checklist items
        document.querySelectorAll('.checklist-item').forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                const itemId = this.dataset.itemId;
                const isComplete = this.checked;
                const label = this.nextElementSibling;
                const listItem = this.closest('.list-group-item');

                fetch('<?= url('/admin/projets/detail.php?id=' . $projetId) ?>', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: `ajax_action=toggle_checklist&item_id=${itemId}&complete=${isComplete ? 1 : 0}&csrf_token=<?= generateCSRFToken() ?>`
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        // Toggle green styling
                        label.classList.toggle('text-success', isComplete);
                        label.classList.toggle('fw-semibold', isComplete);
                        listItem.classList.toggle('bg-success', isComplete);
                        listItem.classList.toggle('bg-opacity-10', isComplete);

                        // Add/remove checkmark icon
                        if (isComplete) {
                            if (!label.querySelector('.bi-check-lg')) {
                                label.insertAdjacentHTML('afterbegin', '<i class="bi bi-check-lg me-1"></i>');
                            }
                        } else {
                            const icon = label.querySelector('.bi-check-lg');
                            if (icon) icon.remove();
                        }

                        // Update badge count
                        const accordion = checkbox.closest('.accordion-item');
                        if (accordion) {
                            const badge = accordion.querySelector('.badge');
                            const checkboxes = accordion.querySelectorAll('.checklist-item');
                            const checked = accordion.querySelectorAll('.checklist-item:checked').length;
                            badge.textContent = `${checked}/${checkboxes.length}`;
                            badge.className = `badge ${checked === checkboxes.length ? 'bg-success' : 'bg-secondary'} me-2`;
                        }
                    }
                });
            });
        });

        </script>
    </div><!-- Fin TAB CHECKLIST -->

    <!-- TAB DOCUMENTS -->
    <div class="tab-pane fade <?= $tab === 'documents' ? 'show active' : '' ?>" id="documents" role="tabpanel">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-folder me-2"></i>Documents du projet</span>
                <span class="badge bg-secondary"><?= count($projetDocuments) ?> document(s)</span>
            </div>
            <div class="card-body">
                <!-- Upload form -->
                <form id="documentUploadForm" enctype="multipart/form-data" class="mb-4">
                    <?php csrfField(); ?>
                    <input type="hidden" name="projet_id" value="<?= $projetId ?>">
                    <label class="form-label">Ajouter des documents</label>
                    <div class="input-group">
                        <input type="file" class="form-control" name="documents[]" id="documentFiles" multiple required>
                        <button type="submit" class="btn btn-primary" id="uploadBtn">
                            <i class="bi bi-upload me-1"></i>Uploader
                        </button>
                    </div>
                    <small class="text-muted">PDF, Word, Excel, Images (max 10 Mo par fichier) - Sélection multiple possible</small>
                    <div id="selectedFiles" class="mt-2 small text-muted"></div>
                </form>

                <!-- Documents list -->
                <?php if (empty($projetDocuments)): ?>
                    <div class="text-center text-muted py-5" id="emptyState">
                        <i class="bi bi-folder" style="font-size: 3rem;"></i>
                        <p class="mb-0 mt-2">Aucun document pour ce projet</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-dark table-hover">
                            <thead>
                                <tr>
                                    <th>Nom</th>
                                    <th>Date</th>
                                    <th>Taille</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="documentsList">
                                <?php foreach ($projetDocuments as $doc): ?>
                                    <tr data-doc-id="<?= $doc['id'] ?>">
                                        <td class="doc-name-cell">
                                            <i class="bi bi-file-earmark me-2"></i>
                                            <span class="doc-name-display">
                                                <a href="<?= url('/uploads/documents/' . $doc['fichier']) ?>" target="_blank" class="text-info doc-link"><?= e($doc['nom']) ?></a>
                                                <button type="button" class="btn btn-sm btn-link text-warning p-0 ms-2 rename-doc-btn" title="Renommer">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                            </span>
                                            <span class="doc-name-edit d-none">
                                                <input type="text" class="form-control form-control-sm d-inline-block bg-dark text-white" style="width: 250px;" value="<?= e($doc['nom']) ?>">
                                                <button type="button" class="btn btn-sm btn-success ms-1 save-rename-btn"><i class="bi bi-check"></i></button>
                                                <button type="button" class="btn btn-sm btn-secondary cancel-rename-btn"><i class="bi bi-x"></i></button>
                                            </span>
                                        </td>
                                        <td><?= date('d/m/Y H:i', strtotime($doc['uploaded_at'])) ?></td>
                                        <td><?= round($doc['taille'] / 1024) ?> Ko</td>
                                        <td class="text-end">
                                            <a href="<?= url('/uploads/documents/' . $doc['fichier']) ?>" download class="btn btn-sm btn-outline-primary me-1" title="Télécharger">
                                                <i class="bi bi-download"></i>
                                            </a>
                                            <button type="button" class="btn btn-sm btn-outline-danger delete-document" data-doc-id="<?= $doc['id'] ?>" title="Supprimer">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <script>
        // Show selected files count
        document.getElementById('documentFiles')?.addEventListener('change', function() {
            const count = this.files.length;
            const filesDiv = document.getElementById('selectedFiles');
            if (count > 0) {
                const names = Array.from(this.files).map(f => f.name).join(', ');
                filesDiv.innerHTML = `<i class="bi bi-check-circle text-success me-1"></i>${count} fichier(s) sélectionné(s): ${names}`;
            } else {
                filesDiv.innerHTML = '';
            }
        });

        // Document upload (multiple)
        document.getElementById('documentUploadForm')?.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            formData.append('ajax_action', 'upload_document');

            const btn = document.getElementById('uploadBtn');
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Upload...';

            fetch('<?= url('/admin/projets/detail.php?id=' . $projetId) ?>', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    window.location.href = '<?= url('/admin/projets/detail.php?id=' . $projetId . '&tab=documents') ?>';
                } else {
                    alert(data.errors?.join('\n') || data.error || 'Erreur lors de l\'upload');
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-upload me-1"></i>Uploader';
                }
            });
        });

        // Rename document
        document.querySelectorAll('.rename-doc-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const cell = this.closest('.doc-name-cell');
                cell.querySelector('.doc-name-display').classList.add('d-none');
                cell.querySelector('.doc-name-edit').classList.remove('d-none');
                cell.querySelector('.doc-name-edit input').focus();
            });
        });

        document.querySelectorAll('.cancel-rename-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const cell = this.closest('.doc-name-cell');
                cell.querySelector('.doc-name-display').classList.remove('d-none');
                cell.querySelector('.doc-name-edit').classList.add('d-none');
            });
        });

        document.querySelectorAll('.save-rename-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const row = this.closest('tr');
                const docId = row.dataset.docId;
                const cell = this.closest('.doc-name-cell');
                const input = cell.querySelector('.doc-name-edit input');
                const newName = input.value.trim();

                if (!newName) {
                    alert('Le nom ne peut pas être vide');
                    return;
                }

                fetch('<?= url('/admin/projets/detail.php?id=' . $projetId) ?>', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: `ajax_action=rename_document&doc_id=${docId}&new_name=${encodeURIComponent(newName)}&csrf_token=<?= generateCSRFToken() ?>`
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        cell.querySelector('.doc-link').textContent = newName;
                        cell.querySelector('.doc-name-display').classList.remove('d-none');
                        cell.querySelector('.doc-name-edit').classList.add('d-none');
                    } else {
                        alert(data.error || 'Erreur');
                    }
                });
            });
        });

        // Enter key to save rename
        document.querySelectorAll('.doc-name-edit input').forEach(input => {
            input.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    this.closest('.doc-name-edit').querySelector('.save-rename-btn').click();
                } else if (e.key === 'Escape') {
                    this.closest('.doc-name-edit').querySelector('.cancel-rename-btn').click();
                }
            });
        });

        // Delete document
        document.querySelectorAll('.delete-document').forEach(btn => {
            btn.addEventListener('click', function() {
                if (!confirm('Supprimer ce document ?')) return;
                const docId = this.dataset.docId;

                fetch('<?= url('/admin/projets/detail.php?id=' . $projetId) ?>', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: `ajax_action=delete_document&doc_id=${docId}&csrf_token=<?= generateCSRFToken() ?>`
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        this.closest('tr').remove();
                    }
                });
            });
        });
        </script>
    </div><!-- Fin TAB DOCUMENTS -->

    <!-- TAB GOOGLE SHEET -->
    <div class="tab-pane fade <?= $tab === 'googlesheet' ? 'show active' : '' ?>" id="googlesheet" role="tabpanel">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-table me-2"></i>Google Sheets</span>
                <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addSheetModal">
                    <i class="bi bi-plus-lg me-1"></i>Ajouter
                </button>
            </div>
            <div class="card-body">
                <?php if (empty($googleSheets)): ?>
                    <div class="text-center text-muted py-5" id="noSheetState">
                        <i class="bi bi-table" style="font-size: 3rem;"></i>
                        <p class="mb-0 mt-2">Aucun Google Sheet configuré</p>
                        <p class="small">Cliquez sur "Ajouter" pour lier un Google Sheet</p>
                    </div>
                <?php else: ?>
                    <div class="row g-3" id="sheetsList">
                        <?php foreach ($googleSheets as $sheet): ?>
                            <?php
                            // Créer l'URL d'édition
                            $editUrl = $sheet['url'];
                            if (preg_match('/\/d\/([a-zA-Z0-9-_]+)/', $sheet['url'], $matches)) {
                                $sheetId = $matches[1];
                                $editUrl = "https://docs.google.com/spreadsheets/d/{$sheetId}/edit";
                            }
                            ?>
                            <div class="col-3 col-md-2 col-lg-1" data-sheet-id="<?= $sheet['id'] ?>">
                                <div class="sheet-card position-relative" style="border: 1px solid #3a3a3a; border-radius: 6px; overflow: hidden; transition: all 0.2s;"
                                     onmouseover="this.style.borderColor='#0d6efd'; this.style.transform='translateY(-2px)';"
                                     onmouseout="this.style.borderColor='#3a3a3a'; this.style.transform='translateY(0)';">
                                    <!-- Action buttons -->
                                    <div class="position-absolute top-0 end-0" style="z-index: 2;">
                                        <button type="button" class="btn btn-sm btn-dark p-0 px-1 edit-sheet-btn" style="font-size: 0.65rem;"
                                                data-id="<?= $sheet['id'] ?>"
                                                data-nom="<?= e($sheet['nom']) ?>"
                                                data-url="<?= e($sheet['url']) ?>"
                                                title="Modifier">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-dark p-0 px-1 delete-sheet-btn" style="font-size: 0.65rem;"
                                                data-id="<?= $sheet['id'] ?>" title="Supprimer">
                                            <i class="bi bi-trash text-danger"></i>
                                        </button>
                                    </div>
                                    <!-- Square clickable area -->
                                    <a href="<?= e($editUrl) ?>" target="_blank" class="d-block text-decoration-none">
                                        <div class="bg-success bg-opacity-10 d-flex align-items-center justify-content-center" style="aspect-ratio: 1; min-height: 60px;">
                                            <i class="bi bi-file-earmark-spreadsheet text-success" style="font-size: 1.5rem;"></i>
                                        </div>
                                        <!-- Name -->
                                        <div class="p-1 bg-dark text-center">
                                            <small class="text-white text-truncate d-block" style="font-size: 0.7rem;" title="<?= e($sheet['nom']) ?>"><?= e($sheet['nom']) ?></small>
                                        </div>
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Modal Ajouter -->
        <div class="modal fade" id="addSheetModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content bg-dark text-white">
                    <div class="modal-header border-secondary">
                        <h5 class="modal-title"><i class="bi bi-plus-lg me-2"></i>Ajouter un Google Sheet</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Nom</label>
                            <input type="text" class="form-control bg-dark text-white border-secondary" id="newSheetNom" placeholder="Ex: Budget cuisine">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Lien Google Sheet</label>
                            <input type="url" class="form-control bg-dark text-white border-secondary" id="newSheetUrl" placeholder="https://docs.google.com/spreadsheets/d/...">
                            <small class="text-muted">Assurez-vous que le sheet est partagé (en lecture ou édition)</small>
                        </div>
                    </div>
                    <div class="modal-footer border-secondary">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="button" class="btn btn-primary" id="confirmAddSheet">
                            <i class="bi bi-plus-lg me-1"></i>Ajouter
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal Modifier -->
        <div class="modal fade" id="editSheetModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content bg-dark text-white">
                    <div class="modal-header border-secondary">
                        <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Modifier le Google Sheet</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" id="editSheetId">
                        <div class="mb-3">
                            <label class="form-label">Nom</label>
                            <input type="text" class="form-control bg-dark text-white border-secondary" id="editSheetNom">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Lien Google Sheet</label>
                            <input type="url" class="form-control bg-dark text-white border-secondary" id="editSheetUrl">
                        </div>
                    </div>
                    <div class="modal-footer border-secondary">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="button" class="btn btn-primary" id="confirmEditSheet">
                            <i class="bi bi-check-lg me-1"></i>Sauvegarder
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <script>
        // Ajouter un sheet
        document.getElementById('confirmAddSheet')?.addEventListener('click', function() {
            const nom = document.getElementById('newSheetNom').value.trim();
            const url = document.getElementById('newSheetUrl').value.trim();

            if (!nom || !url) {
                alert('Veuillez remplir tous les champs');
                return;
            }

            fetch('<?= url('/admin/projets/detail.php?id=' . $projetId) ?>', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `ajax_action=add_google_sheet&nom=${encodeURIComponent(nom)}&url=${encodeURIComponent(url)}&csrf_token=<?= generateCSRFToken() ?>`
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert('Erreur: ' + (data.error || 'Erreur inconnue'));
                }
            });
        });

        // Ouvrir modal modifier
        document.querySelectorAll('.edit-sheet-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                document.getElementById('editSheetId').value = this.dataset.id;
                document.getElementById('editSheetNom').value = this.dataset.nom;
                document.getElementById('editSheetUrl').value = this.dataset.url;
                new bootstrap.Modal(document.getElementById('editSheetModal')).show();
            });
        });

        // Sauvegarder modification
        document.getElementById('confirmEditSheet')?.addEventListener('click', function() {
            const id = document.getElementById('editSheetId').value;
            const nom = document.getElementById('editSheetNom').value.trim();
            const url = document.getElementById('editSheetUrl').value.trim();

            fetch('<?= url('/admin/projets/detail.php?id=' . $projetId) ?>', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `ajax_action=edit_google_sheet&sheet_id=${id}&nom=${encodeURIComponent(nom)}&url=${encodeURIComponent(url)}&csrf_token=<?= generateCSRFToken() ?>`
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert('Erreur: ' + (data.error || 'Erreur inconnue'));
                }
            });
        });

        // Supprimer
        document.querySelectorAll('.delete-sheet-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                if (!confirm('Supprimer ce Google Sheet?')) return;
                const id = this.dataset.id;

                fetch('<?= url('/admin/projets/detail.php?id=' . $projetId) ?>', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: `ajax_action=delete_google_sheet&sheet_id=${id}&csrf_token=<?= generateCSRFToken() ?>`
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        this.closest('[data-sheet-id]').remove();
                    }
                });
            });
        });
        </script>
    </div><!-- Fin TAB GOOGLE SHEET -->

    </div><!-- Fin tab-content -->

    <!-- Actions -->
    <div class="d-flex justify-content-between mt-3 mb-4">
        <a href="<?= url('/admin/projets/liste.php') ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Retour</a>
        <a href="<?= url('/admin/factures/liste.php?projet=' . $projet['id']) ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-receipt"></i> Factures</a>
    </div>
</div>

<?php
// Données pour les graphiques (cohérent avec calculs.php)
$moisProjet = (int)$projet['temps_assume_mois'];
if (!empty($projet['date_vente']) && !empty($projet['date_acquisition'])) {
    $dateAchat = new DateTime($projet['date_acquisition']);
    $dateVente = new DateTime($projet['date_vente']);
    $diff = $dateAchat->diff($dateVente);
    $moisProjet = ($diff->y * 12) + $diff->m;
    // Ajouter 1 mois si on a des jours supplémentaires (mois entamé = mois complet pour les intérêts)
    if ($diff->d > 0) {
        $moisProjet++;
    }
    $moisProjet = max(1, $moisProjet);
}

$labelsTimeline = [];
$coutsTimeline = [];
$baseAchat = (float)$projet['prix_achat'] + $indicateurs['couts_acquisition']['total'];
$budgetReno = $indicateurs['renovation']['budget'];
$contingence = $indicateurs['contingence'];
$totalPrets = $indicateurs['total_prets'] ?? 0;
$tauxInteret = (float)($projet['taux_interet'] ?? 10);
$tauxMensuel = $tauxInteret / 100 / 12;  // Pour intérêts composés
$recurrentsAnnuel = (float)$projet['taxes_municipales_annuel'] + (float)$projet['taxes_scolaires_annuel']
    + (float)$projet['electricite_annuel'] + (float)$projet['assurances_annuel']
    + (float)$projet['deneigement_annuel'] + (float)$projet['frais_condo_annuel'];
$recurrentsMensuel = $recurrentsAnnuel / 12 + (float)$projet['hypotheque_mensuel'] - (float)$projet['loyer_mensuel'];
$commissionTTC = $indicateurs['couts_vente']['commission_ttc'];

for ($m = 0; $m <= $moisProjet; $m++) {
    $labelsTimeline[] = $m == 0 ? 'Achat' : 'M' . $m;
    $pctReno = min(1, $m / max(1, $moisProjet - 1));
    $interetsCumules = $totalPrets * (pow(1 + $tauxMensuel, $m) - 1);  // Intérêts composés
    $cout = $baseAchat + ($budgetReno * $pctReno) + ($recurrentsMensuel * $m) + $interetsCumules;
    if ($m == $moisProjet) $cout += $contingence + $commissionTTC;
    $coutsTimeline[] = round($cout, 2);
}

$valeurPotentielle = $indicateurs['valeur_potentielle'];

// Heures travaillées
$heuresParJour = [];
try {
    $stmt = $pdo->prepare("SELECT date_travail as jour, SUM(heures) as total FROM heures_travaillees WHERE projet_id = ? AND statut != 'rejetee' GROUP BY date_travail ORDER BY date_travail");
    $stmt->execute([$projetId]);
    foreach ($stmt->fetchAll() as $row) $heuresParJour[$row['jour']] = (float)$row['total'];
} catch (Exception $e) {}

$jourLabelsHeures = [];
$jourDataHeures = [];
foreach ($heuresParJour as $jour => $heures) {
    $jourLabelsHeures[] = date('d M', strtotime($jour));
    $jourDataHeures[] = $heures;
}

// Budget vs Dépensé
$dateDebut = !empty($projet['date_acquisition']) ? $projet['date_acquisition'] : date('Y-m-d');
$dateFin = !empty($projet['date_vente']) ? $projet['date_vente'] : date('Y-m-d', strtotime('+' . $moisProjet . ' months', strtotime($dateDebut)));
$budgetTotal = $indicateurs['renovation']['budget'] ?: 1;

$depensesCumulees = [];
try {
    $stmt = $pdo->prepare("SELECT date_facture as jour, SUM(montant_total) as total FROM factures WHERE projet_id = ? AND statut != 'rejetee' GROUP BY date_facture ORDER BY date_facture");
    $stmt->execute([$projetId]);
    $cumul = 0;
    foreach ($stmt->fetchAll() as $row) {
        $cumul += (float)$row['total'];
        $depensesCumulees[$row['jour']] = $cumul;
    }
} catch (Exception $e) {}

$jourLabels = [];
$dataExtrapole = [];
$dataReel = [];
$dateStart = new DateTime($dateDebut);
$dateEnd = new DateTime($dateFin);
$joursTotal = max(1, $dateStart->diff($dateEnd)->days);
$dernierCumul = 0;

$interval = new DateInterval('P7D');
$period = new DatePeriod($dateStart, $interval, $dateEnd);
$points = iterator_to_array($period);
$points[] = $dateEnd;

foreach ($points as $date) {
    $dateStr = $date->format('Y-m-d');
    $joursEcoules = $dateStart->diff($date)->days;
    $pctProgression = $joursEcoules / $joursTotal;
    $jourLabels[] = $date->format('d M');
    $dataExtrapole[] = round($budgetTotal * $pctProgression, 2);
    foreach ($depensesCumulees as $jour => $cumul) {
        if ($jour <= $dateStr) $dernierCumul = $cumul;
    }
    $dataReel[] = $dernierCumul;
}
$dataReel[count($dataReel) - 1] += $indicateurs['main_doeuvre']['cout'];
?>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<!-- Motion One pour animations graphiques -->
<script src="https://cdn.jsdelivr.net/npm/motion@11.11.13/dist/motion.min.js"></script>
<script>
// Configuration moderne Chart.js
Chart.defaults.color = '#64748b';
Chart.defaults.font.family = "'Inter', 'Segoe UI', sans-serif";

// Créer des dégradés
function createGradient(ctx, color1, color2, opacity = 0.3) {
    const gradient = ctx.createLinearGradient(0, 0, 0, 150);
    gradient.addColorStop(0, color1.replace(')', `, ${opacity})`).replace('rgb', 'rgba'));
    gradient.addColorStop(1, color1.replace(')', ', 0.01)').replace('rgb', 'rgba'));
    return gradient;
}

// Options communes améliorées
const optionsLine = {
    responsive: true,
    maintainAspectRatio: false,
    animation: {
        duration: 1500,
        easing: 'easeOutQuart',
        delay: (context) => context.dataIndex * 100
    },
    interaction: {
        intersect: false,
        mode: 'index'
    },
    plugins: {
        legend: {
            position: 'top',
            labels: {
                boxWidth: 12,
                boxHeight: 12,
                borderRadius: 3,
                useBorderRadius: true,
                font: { size: 11, weight: '500' },
                padding: 15
            }
        },
        tooltip: {
            backgroundColor: 'rgba(15, 23, 42, 0.9)',
            titleFont: { size: 12, weight: '600' },
            bodyFont: { size: 11 },
            padding: 12,
            cornerRadius: 8,
            displayColors: true,
            boxPadding: 5
        }
    },
    scales: {
        x: {
            grid: { display: false },
            ticks: { font: { size: 10 }, color: '#94a3b8' }
        },
        y: {
            grid: { color: 'rgba(148, 163, 184, 0.1)' },
            ticks: { callback: v => (v/1000).toFixed(0)+'k', font: { size: 10 }, color: '#94a3b8' }
        }
    }
};

const optionsBar = {
    responsive: true,
    maintainAspectRatio: false,
    animation: {
        duration: 1200,
        easing: 'easeOutQuart',
        delay: (context) => context.dataIndex * 150
    },
    plugins: {
        legend: { display: false },
        tooltip: {
            backgroundColor: 'rgba(15, 23, 42, 0.9)',
            titleFont: { size: 12, weight: '600' },
            bodyFont: { size: 11 },
            padding: 12,
            cornerRadius: 8
        }
    },
    scales: {
        x: {
            grid: { display: false },
            ticks: { font: { size: 10 }, color: '#94a3b8' }
        },
        y: {
            grid: { color: 'rgba(148, 163, 184, 0.1)' },
            ticks: { callback: v => v+'h', font: { size: 10 }, color: '#94a3b8' }
        }
    }
};

// Chart 1: Coûts vs Valeur
new Chart(document.getElementById('chartCouts'), {
    type: 'line',
    data: {
        labels: <?= json_encode($labelsTimeline) ?>,
        datasets: [
            {
                label: 'Coûts',
                data: <?= json_encode($coutsTimeline) ?>,
                borderColor: '#ef4444',
                backgroundColor: 'rgba(239, 68, 68, 0.15)',
                fill: true,
                tension: 0.4,
                pointRadius: 4,
                pointBackgroundColor: '#ef4444',
                pointBorderColor: '#fff',
                pointBorderWidth: 2,
                pointHoverRadius: 6
            },
            {
                label: 'Valeur cible',
                data: <?= json_encode(array_fill(0, count($labelsTimeline), $valeurPotentielle)) ?>,
                borderColor: '#22c55e',
                borderDash: [8, 4],
                borderWidth: 2,
                pointRadius: 0,
                fill: false
            }
        ]
    },
    options: optionsLine
});

// Chart 2: Heures travaillées
new Chart(document.getElementById('chartBudget'), {
    type: 'bar',
    data: {
        labels: <?= json_encode($jourLabelsHeures ?: ['Aucune']) ?>,
        datasets: [{
            data: <?= json_encode($jourDataHeures ?: [0]) ?>,
            backgroundColor: 'rgba(59, 130, 246, 0.7)',
            borderRadius: 6,
            borderSkipped: false,
            hoverBackgroundColor: 'rgba(59, 130, 246, 0.9)'
        }]
    },
    options: optionsBar
});

// Chart 3: Budget vs Dépensé
new Chart(document.getElementById('chartProfits'), {
    type: 'line',
    data: {
        labels: <?= json_encode($jourLabels) ?>,
        datasets: [
            {
                label: 'Budget prévu',
                data: <?= json_encode($dataExtrapole) ?>,
                borderColor: '#22c55e',
                backgroundColor: 'rgba(34, 197, 94, 0.15)',
                fill: true,
                tension: 0.4,
                pointRadius: 3,
                pointBackgroundColor: '#22c55e',
                pointBorderColor: '#fff',
                pointBorderWidth: 2
            },
            {
                label: 'Dépensé réel',
                data: <?= json_encode($dataReel) ?>,
                borderColor: '#f97316',
                backgroundColor: 'rgba(249, 115, 22, 0.15)',
                fill: true,
                stepped: 'middle',
                pointRadius: 4,
                pointBackgroundColor: '#f97316',
                pointBorderColor: '#fff',
                pointBorderWidth: 2,
                pointHoverRadius: 6
            }
        ]
    },
    options: optionsLine
});

// Animation des cartes graphiques avec Motion
document.addEventListener('DOMContentLoaded', function() {
    if (typeof Motion !== 'undefined') {
        const { animate, stagger } = Motion;
        animate('.chart-card',
            { opacity: [0, 1], y: [30, 0], scale: [0.95, 1] },
            { duration: 0.6, delay: stagger(0.15), easing: [0.22, 1, 0.36, 1] }
        );
    }
});
</script>
<script>
// Toggle sections avec affichage Extrapolé | Diff | Réel alignés sur les colonnes
document.querySelectorAll('.section-header[data-section]').forEach(header => {
    // Sauvegarder le HTML original
    const originalTd = header.querySelector('td');
    const originalHTML = originalTd.innerHTML;
    const originalColspan = originalTd.getAttribute('colspan');
    
    // Trouver la ligne total-row associée et stocker les montants
    let row = header.nextElementSibling;
    let totalRow = null;
    while (row && !row.classList.contains('section-header')) {
        if (row.classList.contains('total-row')) {
            totalRow = row;
        }
        row = row.nextElementSibling;
    }
    
    // Extraire les 3 montants du total-row
    if (totalRow) {
        const cells = totalRow.querySelectorAll('td');
        if (cells.length >= 4) {
            header.dataset.extrapole = cells[1].textContent.trim();
            header.dataset.diff = cells[2].textContent.trim();
            header.dataset.reel = cells[3].textContent.trim();
            header.dataset.diffClass = cells[2].classList.contains('positive') ? 'positive' : 
                                       cells[2].classList.contains('negative') ? 'negative' : '';
        }
    }
    
    header.addEventListener('click', function() {
        this.classList.toggle('collapsed');
        const isCollapsed = this.classList.contains('collapsed');
        const existingTd = this.querySelector('td');
        
        if (isCollapsed && this.dataset.reel) {
            // Transformer en 4 colonnes
            existingTd.setAttribute('colspan', '1');
            existingTd.classList.add('col-label');
            
            // Ajouter les 3 cellules de montant
            const extTd = document.createElement('td');
            extTd.className = 'text-end col-num';
            extTd.style.color = '#87CEEB';
            extTd.textContent = this.dataset.extrapole;
            
            const diffTd = document.createElement('td');
            diffTd.className = 'text-end col-num';
            if (this.dataset.diffClass === 'positive') diffTd.style.color = '#90EE90';
            else if (this.dataset.diffClass === 'negative') diffTd.style.color = '#ff6b6b';
            else diffTd.style.opacity = '0.7';
            diffTd.textContent = this.dataset.diff;
            
            const reelTd = document.createElement('td');
            reelTd.className = 'text-end col-num';
            reelTd.style.color = '#90EE90';
            reelTd.textContent = this.dataset.reel;
            
            this.appendChild(extTd);
            this.appendChild(diffTd);
            this.appendChild(reelTd);
        } else {
            // Restaurer le colspan original
            existingTd.setAttribute('colspan', originalColspan);
            existingTd.classList.remove('col-label');
            // Supprimer les cellules ajoutées
            while (this.children.length > 1) {
                this.removeChild(this.lastChild);
            }
        }
        
        // Toggle les lignes
        let nextRow = this.nextElementSibling;
        while (nextRow && !nextRow.classList.contains('section-header')) {
            nextRow.style.display = isCollapsed ? 'none' : '';
            nextRow = nextRow.nextElementSibling;
        }
    });
});

// ========================================
// AJAX REFRESH - Met à jour les données sans flash
// ========================================
(function() {
    var refreshInterval = 30000; // 30 secondes
    var refreshTimer;

    function getCollapsedSections() {
        var collapsed = [];
        document.querySelectorAll('.section-header.collapsed').forEach(function(el) {
            collapsed.push(el.dataset.section);
        });
        return collapsed;
    }

    function doRefresh() {
        // Ne pas rafraîchir si l'onglet n'est pas visible
        if (document.hidden) {
            scheduleRefresh();
            return;
        }

        // Ne pas rafraîchir si on est sur l'onglet Budgets (Budget Builder actif)
        var budgetsTab = document.getElementById('budgets');
        if (budgetsTab && budgetsTab.classList.contains('show')) {
            scheduleRefresh();
            return;
        }

        var collapsedBefore = getCollapsedSections();

        fetch(window.location.href, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(response) { return response.text(); })
        .then(function(html) {
            var parser = new DOMParser();
            var doc = parser.parseFromString(html, 'text/html');

            // Mettre à jour les indicateurs (cartes en haut)
            var newIndicators = doc.querySelectorAll('.row.g-2.mb-3 .card');
            var currentIndicators = document.querySelectorAll('.row.g-2.mb-3 .card');
            newIndicators.forEach(function(newCard, i) {
                if (currentIndicators[i]) {
                    var newValue = newCard.querySelector('strong');
                    var currentValue = currentIndicators[i].querySelector('strong');
                    if (newValue && currentValue && newValue.textContent !== currentValue.textContent) {
                        currentValue.textContent = newValue.textContent;
                        currentValue.style.transition = 'color 0.3s';
                        currentValue.style.color = '#ffc107';
                        setTimeout(function() { currentValue.style.color = ''; }, 500);
                    }
                }
            });

            // Mettre à jour le tableau des coûts
            var newTable = doc.querySelector('.cost-table tbody');
            var currentTable = document.querySelector('.cost-table tbody');
            if (newTable && currentTable) {
                var newRows = newTable.querySelectorAll('tr');
                var currentRows = currentTable.querySelectorAll('tr');
                newRows.forEach(function(newRow, i) {
                    if (currentRows[i] && !currentRows[i].classList.contains('section-header')) {
                        var newCells = newRow.querySelectorAll('td');
                        var currentCells = currentRows[i].querySelectorAll('td');
                        newCells.forEach(function(newCell, j) {
                            if (currentCells[j] && newCell.textContent !== currentCells[j].textContent) {
                                currentCells[j].innerHTML = newCell.innerHTML;
                            }
                        });
                    }
                });
            }

            // Restaurer l'état des sections collapsées
            collapsedBefore.forEach(function(section) {
                var header = document.querySelector('.section-header[data-section="' + section + '"]');
                if (header && !header.classList.contains('collapsed')) {
                    header.click();
                }
            });

            scheduleRefresh();
        })
        .catch(function(err) {
            console.log('Refresh error:', err);
            scheduleRefresh();
        });
    }

    function scheduleRefresh() {
        // DÉSACTIVÉ TEMPORAIREMENT - cause des conflits avec Budget Builder
        // refreshTimer = setTimeout(doRefresh, refreshInterval);
    }

    // Démarrer le refresh - DÉSACTIVÉ
    // scheduleRefresh();

    // Pause quand l'onglet est caché
    document.addEventListener('visibilitychange', function() {
        if (document.hidden) {
            clearTimeout(refreshTimer);
        } else {
            scheduleRefresh();
        }
    });
})();
</script>

<!-- Auto-save pour l'onglet Base -->
<script>
(function() {
    const formBase = document.getElementById('formBase');
    if (!formBase) return;

    const csrfToken = '<?= generateCSRFToken() ?>';
    let baseSaveTimeout = null;

    function formatMoneyBase(val) {
        return val.toLocaleString('fr-CA', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' $';
    }

    function formatPercentBase(val) {
        return val.toLocaleString('fr-CA', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' %';
    }

    function showBaseSaveStatus(status) {
        document.getElementById('baseIdle').classList.add('d-none');
        document.getElementById('baseSaving').classList.add('d-none');
        document.getElementById('baseSaved').classList.add('d-none');
        document.getElementById('base' + status.charAt(0).toUpperCase() + status.slice(1)).classList.remove('d-none');
    }

    function updateIndicateurs(ind) {
        const elValeur = document.getElementById('indValeurPotentielle');
        const elEquiteBudget = document.getElementById('indEquiteBudget');
        const elEquiteReelle = document.getElementById('indEquiteReelle');
        const elRoi = document.getElementById('indRoiLeverage');

        if (elValeur) elValeur.textContent = formatMoneyBase(ind.valeur_potentielle);
        if (elEquiteBudget) elEquiteBudget.textContent = formatMoneyBase(ind.equite_potentielle);
        if (elEquiteReelle) elEquiteReelle.textContent = formatMoneyBase(ind.equite_reelle);
        if (elRoi) elRoi.textContent = formatPercentBase(ind.roi_leverage);
    }

    function autoSaveBase() {
        if (baseSaveTimeout) clearTimeout(baseSaveTimeout);

        baseSaveTimeout = setTimeout(function() {
            showBaseSaveStatus('saving');

            const formData = new FormData(formBase);
            formData.set('ajax_action', 'save_base');
            formData.set('csrf_token', csrfToken);

            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showBaseSaveStatus('saved');
                    setTimeout(() => showBaseSaveStatus('idle'), 2000);

                    // Mettre à jour les indicateurs
                    if (data.indicateurs) {
                        updateIndicateurs(data.indicateurs);
                    }
                } else {
                    console.error('Erreur:', data.error);
                    showBaseSaveStatus('idle');
                }
            })
            .catch(error => {
                console.error('Erreur réseau:', error);
                showBaseSaveStatus('idle');
            });
        }, 500); // Debounce 500ms
    }

    // Écouter les changements sur tous les inputs du formulaire Base
    formBase.querySelectorAll('input, select, textarea').forEach(input => {
        // Pour les inputs text/money, on écoute l'événement blur et change
        if (input.type === 'text' || input.type === 'number' || input.tagName === 'TEXTAREA') {
            input.addEventListener('blur', autoSaveBase);
            input.addEventListener('change', autoSaveBase);
        } else {
            // Pour les selects et autres, on écoute change
            input.addEventListener('change', autoSaveBase);
        }
    });

    // Empêcher la soumission normale du formulaire (sauf si explicitement nécessaire)
    formBase.addEventListener('submit', function(e) {
        e.preventDefault();
        autoSaveBase();
    });
})();
</script>

<!-- Calcul automatique de la durée -->
<script>
function calculerDuree() {
    const dateAchat = document.getElementById('date_acquisition').value;
    const dateVente = document.getElementById('date_vente').value;
    const dateFinTravaux = document.getElementById('date_fin_prevue').value;
    const dureeMois = document.getElementById('duree_mois');

    if (!dateAchat) {
        dureeMois.value = 0;
        return;
    }

    // Utiliser date de vente si disponible, sinon date fin travaux, sinon aujourd'hui
    let dateFin = dateVente || dateFinTravaux || new Date().toISOString().split('T')[0];

    const d1 = new Date(dateAchat);
    const d2 = new Date(dateFin);

    if (d2 < d1) {
        dureeMois.value = 0;
        return;
    }

    // Calcul des mois
    let mois = (d2.getFullYear() - d1.getFullYear()) * 12 + (d2.getMonth() - d1.getMonth());

    // Ajouter 1 mois si le jour de fin est >= jour d'achat
    if (d2.getDate() >= d1.getDate()) {
        mois++;
    }

    dureeMois.value = Math.max(1, mois);
}

// Calcul automatique de la taxe de mutation (droits de mutation - "taxe de bienvenue")
// Basé sur le MAX entre prix d'achat et rôle d'évaluation municipale
// Taux standards du Québec (peuvent varier selon la municipalité pour la dernière tranche)
function calculerTaxeMutation(triggerSave = false) {
    const prixAchatInput = document.getElementById('prix_achat');
    const roleEvalInput = document.getElementById('role_evaluation');
    const taxeMutationInput = document.getElementById('taxe_mutation');

    if (!prixAchatInput || !taxeMutationInput) return;

    // Parser les valeurs (enlever espaces et symboles)
    const prixStr = prixAchatInput.value.replace(/[^0-9.,]/g, '').replace(',', '.');
    const prixAchat = parseFloat(prixStr) || 0;

    const roleStr = roleEvalInput ? roleEvalInput.value.replace(/[^0-9.,]/g, '').replace(',', '.') : '0';
    const roleEval = parseFloat(roleStr) || 0;

    // Prendre le maximum entre prix d'achat et rôle d'évaluation
    const baseCalcul = Math.max(prixAchat, roleEval);

    if (baseCalcul <= 0) {
        taxeMutationInput.value = '0';
        return;
    }

    // Tranches progressives Québec (2024)
    // 0 à 58 900$: 0.5%
    // 58 900$ à 294 600$: 1.0%
    // 294 600$ à 500 000$: 1.5%
    // 500 000$ et +: 3.0% (peut varier selon municipalité)
    let taxe = 0;

    // Tranche 1: 0 à 58 900$
    const tranche1 = Math.min(baseCalcul, 58900);
    taxe += tranche1 * 0.005;

    // Tranche 2: 58 900$ à 294 600$
    if (baseCalcul > 58900) {
        const tranche2 = Math.min(baseCalcul, 294600) - 58900;
        taxe += tranche2 * 0.01;
    }

    // Tranche 3: 294 600$ à 500 000$
    if (baseCalcul > 294600) {
        const tranche3 = Math.min(baseCalcul, 500000) - 294600;
        taxe += tranche3 * 0.015;
    }

    // Tranche 4: 500 000$ et plus
    if (baseCalcul > 500000) {
        const tranche4 = baseCalcul - 500000;
        taxe += tranche4 * 0.03;
    }

    // Arrondir à 2 décimales et formater
    taxe = Math.round(taxe * 100) / 100;
    taxeMutationInput.value = taxe.toLocaleString('fr-CA', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    // Déclencher la sauvegarde automatique si demandé
    if (triggerSave && typeof autoSaveBase === 'function') {
        autoSaveBase();
    }
}
</script>

<!-- Filtres Photos et Factures -->
<script>
// Filtrage des photos
function filtrerPhotos() {
    const employe = document.getElementById('filtrePhotosEmploye').value;
    const categorie = document.getElementById('filtrePhotosCategorie').value;
    const photos = document.querySelectorAll('.photo-item');
    let count = 0;

    photos.forEach(photo => {
        const photoEmploye = photo.dataset.employe;
        const photoCategorie = photo.dataset.categorie;

        const matchEmploye = !employe || photoEmploye === employe;
        const matchCategorie = !categorie || photoCategorie === categorie;

        if (matchEmploye && matchCategorie) {
            photo.style.display = '';
            count++;
        } else {
            photo.style.display = 'none';
        }
    });

    document.getElementById('photosCount').textContent = count;
}

function resetFiltresPhotos() {
    document.getElementById('filtrePhotosEmploye').value = '';
    document.getElementById('filtrePhotosCategorie').value = '';
    filtrerPhotos();
}

// Filtrage des factures
function filtrerFactures() {
    const statut = document.getElementById('filtreFacturesStatut').value;
    const categorie = document.getElementById('filtreFacturesCategorie').value;
    const fournisseur = document.getElementById('filtreFacturesFournisseur').value;
    const factures = document.querySelectorAll('.facture-row');
    let count = 0;
    let total = 0;

    factures.forEach(row => {
        const rowStatut = row.dataset.statut;
        const rowCategorie = row.dataset.categorie;
        const rowFournisseur = row.dataset.fournisseur;
        const rowMontant = parseFloat(row.dataset.montant) || 0;

        const matchStatut = !statut || rowStatut === statut;
        const matchCategorie = !categorie || rowCategorie === categorie;
        const matchFournisseur = !fournisseur || rowFournisseur === fournisseur;

        if (matchStatut && matchCategorie && matchFournisseur) {
            row.style.display = '';
            count++;
            total += rowMontant;
        } else {
            row.style.display = 'none';
        }
    });

    document.getElementById('facturesCount').textContent = count + ' factures';
    document.getElementById('facturesTotal').textContent = total.toLocaleString('fr-CA', {style: 'currency', currency: 'CAD'});
}

function resetFiltresFactures() {
    document.getElementById('filtreFacturesStatut').value = '';
    document.getElementById('filtreFacturesCategorie').value = '';
    document.getElementById('filtreFacturesFournisseur').value = '';
    filtrerFactures();
}

// Toggle paiement facture via AJAXfunction togglePaiementFacture(factureId, element) {    fetch('<?= url('/admin/factures/liste.php') ?>?toggle_paiement=1&id=' + factureId, {        headers: { 'X-Requested-With': 'XMLHttpRequest' }    })    .then(response => response.json())    .then(data => {        if (data.est_payee) {            element.className = 'badge bg-success text-white';            element.innerHTML = '<i class="bi bi-check-circle me-1"></i>Payé';        } else {            element.className = 'badge bg-primary text-white';            element.innerHTML = '<i class="bi bi-clock me-1"></i>Non payé';        }    })    .catch(err => {        window.location.reload();    });}
// Variable pour stocker les fichiers convertis
let adminConvertedFiles = [];

// Prévisualisation des photos dans le modal admin (avec conversion HEIC)
async function previewAdminPhotos(input) {
    const preview = document.getElementById('adminPhotoPreview');
    const photoCount = document.getElementById('adminPhotoCount');
    const photoCountText = document.getElementById('adminPhotoCountText');
    const submitBtn = document.getElementById('adminSubmitBtn');

    if (!input.files || input.files.length === 0) return;

    // Afficher la barre de progression
    const totalFiles = input.files.length;
    preview.innerHTML = `
        <div class="col-12 text-center py-3">
            <div class="mb-2"><i class="bi bi-gear-fill me-2 spin-icon"></i><span id="adminProgressText">Préparation...</span></div>
            <div class="progress" style="height: 20px;">
                <div id="adminProgressBar" class="progress-bar progress-bar-striped progress-bar-animated bg-primary" role="progressbar" style="width: 0%">0%</div>
            </div>
            <small class="text-muted mt-1 d-block" id="adminProgressDetail"></small>
        </div>
    `;
    preview.style.display = 'flex';
    submitBtn.style.display = 'none';
    adminConvertedFiles = [];

    const processedFiles = [];
    let processed = 0;
    let heicConverted = 0;
    const progressBar = document.getElementById('adminProgressBar');
    const progressText = document.getElementById('adminProgressText');
    const progressDetail = document.getElementById('adminProgressDetail');

    for (let file of input.files) {
        const fileName = file.name.toLowerCase();
        const isHeic = fileName.endsWith('.heic') || fileName.endsWith('.heif') || file.type === 'image/heic' || file.type === 'image/heif';

        if (isHeic && typeof heic2any !== 'undefined') {
            progressText.textContent = 'Conversion HEIC...';
            progressDetail.textContent = file.name;
            try {
                // Convertir HEIC en JPEG
                const convertedBlob = await heic2any({
                    blob: file,
                    toType: 'image/jpeg',
                    quality: 0.9
                });
                const convertedFile = new File(
                    [convertedBlob],
                    file.name.replace(/\.heic$/i, '.jpg').replace(/\.heif$/i, '.jpg'),
                    { type: 'image/jpeg' }
                );
                processedFiles.push(convertedFile);
                heicConverted++;
            } catch (err) {
                console.error('Erreur conversion HEIC:', err);
                processedFiles.push(file); // Garder l'original si échec
            }
        } else {
            progressText.textContent = 'Traitement...';
            progressDetail.textContent = file.name;
            processedFiles.push(file);
        }

        // Mettre à jour la progression
        processed++;
        const percent = Math.round((processed / totalFiles) * 100);
        progressBar.style.width = percent + '%';
        progressBar.textContent = percent + '%';
    }

    // Créer un nouveau FileList avec les fichiers convertis
    const dt = new DataTransfer();
    processedFiles.forEach(f => dt.items.add(f));
    input.files = dt.files;
    adminConvertedFiles = processedFiles;

    // Afficher les previews
    preview.innerHTML = '';
    for (let file of processedFiles) {
        const col = document.createElement('div');
        col.className = 'col-4';

        const isVideo = file.type.startsWith('video/');
        if (isVideo) {
            col.innerHTML = `
                <div class="rounded" style="width:100%;height:80px;background:#1a1d21;display:flex;align-items:center;justify-content:center;">
                    <i class="bi bi-play-circle text-white" style="font-size:2rem;"></i>
                </div>
            `;
        } else {
            const reader = new FileReader();
            reader.onload = function(e) {
                col.innerHTML = `<img src="${e.target.result}" class="img-fluid rounded" style="width:100%;height:80px;object-fit:cover;">`;
            };
            reader.readAsDataURL(file);
        }
        preview.appendChild(col);
    }

    photoCount.style.display = 'block';
    let countText = processedFiles.length + ' photo(s) prête(s)';
    if (heicConverted > 0) {
        countText += ' (' + heicConverted + ' HEIC converti' + (heicConverted > 1 ? 's' : '') + ')';
    }
    photoCountText.textContent = countText;
    submitBtn.style.display = 'inline-block';
}

// Upload AJAX avec barre de progression
document.getElementById('formAjoutPhoto')?.addEventListener('submit', function(e) {
    e.preventDefault();

    const form = this;
    const btn = document.getElementById('adminSubmitBtn');
    const preview = document.getElementById('adminPhotoPreview');
    const photoCount = document.getElementById('adminPhotoCount');

    // Afficher la barre de progression d'upload
    preview.innerHTML = `
        <div class="col-12 text-center py-3">
            <div class="mb-2"><i class="bi bi-cloud-upload me-2 spin-icon"></i><span id="uploadProgressText">Téléversement en cours...</span></div>
            <div class="progress" style="height: 25px;">
                <div id="uploadProgressBar" class="progress-bar progress-bar-striped progress-bar-animated bg-success" role="progressbar" style="width: 0%">0%</div>
            </div>
            <small class="text-muted mt-2 d-block" id="uploadProgressDetail">Préparation...</small>
        </div>
    `;
    preview.style.display = 'flex';
    photoCount.style.display = 'none';
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Téléversement...';

    const formData = new FormData(form);
    const xhr = new XMLHttpRequest();

    xhr.upload.addEventListener('progress', function(e) {
        if (e.lengthComputable) {
            const percent = Math.round((e.loaded / e.total) * 100);
            const progressBar = document.getElementById('uploadProgressBar');
            const progressDetail = document.getElementById('uploadProgressDetail');

            progressBar.style.width = percent + '%';
            progressBar.textContent = percent + '%';

            const loadedMB = (e.loaded / 1024 / 1024).toFixed(1);
            const totalMB = (e.total / 1024 / 1024).toFixed(1);
            progressDetail.textContent = loadedMB + ' Mo / ' + totalMB + ' Mo';

            if (percent === 100) {
                document.getElementById('uploadProgressText').textContent = 'Traitement par le serveur...';
                progressDetail.textContent = 'Veuillez patienter...';
            }
        }
    });

    xhr.addEventListener('load', function() {
        if (xhr.status === 200) {
            // Recharger la page pour afficher les nouvelles photos
            window.location.href = window.location.pathname + '?id=<?= $projetId ?>&tab=photos&success=photos_added';
        } else {
            alert('Erreur lors du téléversement. Veuillez réessayer.');
            window.location.reload();
        }
    });

    xhr.addEventListener('error', function() {
        alert('Erreur de connexion. Veuillez réessayer.');
        window.location.reload();
    });

    xhr.open('POST', window.location.href);
    xhr.send(formData);
});

// ===== RÉORGANISATION DES PHOTOS (Drag & Drop) =====
let sortableInstance = null;
let isReorganizing = false;

function toggleReorganisation() {
    const grid = document.getElementById('photosGrid');
    const btn = document.getElementById('btnReorganiser');

    if (!isReorganizing) {
        // Activer le mode réorganisation
        isReorganizing = true;
        btn.innerHTML = '<i class="bi bi-check-lg me-1"></i>Terminer';
        btn.classList.remove('btn-outline-primary');
        btn.classList.add('btn-primary');

        // Ajouter une classe pour le style
        grid.classList.add('sortable-mode');

        // Initialiser Sortable
        sortableInstance = new Sortable(grid, {
            animation: 150,
            ghostClass: 'sortable-ghost',
            chosenClass: 'sortable-chosen',
            dragClass: 'sortable-drag',
            onEnd: function(evt) {
                // Envoyer le nouvel ordre au serveur
                savePhotosOrder();
            }
        });

        // Afficher un message
        showToast('Mode réorganisation activé. Glissez-déposez les photos pour les réorganiser.', 'info');
    } else {
        // Désactiver le mode réorganisation
        isReorganizing = false;
        btn.innerHTML = '<i class="bi bi-arrows-move me-1"></i>Réorganiser';
        btn.classList.remove('btn-primary');
        btn.classList.add('btn-outline-primary');

        grid.classList.remove('sortable-mode');

        if (sortableInstance) {
            sortableInstance.destroy();
            sortableInstance = null;
        }

        showToast('Réorganisation terminée!', 'success');
    }
}

function savePhotosOrder() {
    const grid = document.getElementById('photosGrid');
    const items = grid.querySelectorAll('.photo-item');
    const photoIds = Array.from(items).map(item => item.dataset.id);

    const formData = new FormData();
    formData.append('ajax_action', 'update_photos_order');
    formData.append('csrf_token', '<?= generateCSRFToken() ?>');
    photoIds.forEach((id, index) => {
        formData.append('photo_ids[]', id);
    });

    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (!data.success) {
            showToast('Erreur lors de la sauvegarde: ' + (data.error || 'Erreur inconnue'), 'danger');
        }
    })
    .catch(error => {
        showToast('Erreur de connexion', 'danger');
    });
}

// ===== SÉLECTION MULTIPLE DE PHOTOS (Long-press comme Google Photos) =====
let isSelectionMode = false;
let selectedPhotos = new Set();
let longPressTimer = null;
const LONG_PRESS_DURATION = 500; // 500ms pour activer la sélection

// Initialiser les événements long-press sur les photos
document.addEventListener('DOMContentLoaded', function() {
    initPhotoLongPress();
});

function initPhotoLongPress() {
    document.querySelectorAll('.photo-item').forEach(item => {
        // Mouse events
        item.addEventListener('mousedown', function(e) {
            if (e.button !== 0) return; // Only left click
            startLongPress(this, e);
        });
        item.addEventListener('mouseup', cancelLongPress);
        item.addEventListener('mouseleave', cancelLongPress);

        // Touch events (mobile)
        item.addEventListener('touchstart', function(e) {
            startLongPress(this, e);
        }, { passive: true });
        item.addEventListener('touchend', cancelLongPress);
        item.addEventListener('touchmove', cancelLongPress);
    });
}

function startLongPress(photoItem, event) {
    cancelLongPress();
    longPressTimer = setTimeout(() => {
        // Activer le mode sélection si pas déjà actif
        if (!isSelectionMode) {
            enterSelectionMode();
        }
        // Sélectionner cette photo
        selectPhoto(photoItem);
        // Vibration feedback sur mobile
        if (navigator.vibrate) {
            navigator.vibrate(50);
        }
    }, LONG_PRESS_DURATION);
}

function cancelLongPress() {
    if (longPressTimer) {
        clearTimeout(longPressTimer);
        longPressTimer = null;
    }
}

function enterSelectionMode() {
    const grid = document.getElementById('photosGrid');
    const btn = document.getElementById('btnSelectionner');
    const selectionBar = document.getElementById('selectionBar');

    isSelectionMode = true;
    if (btn) {
        btn.innerHTML = '<i class="bi bi-x-lg me-1"></i>Annuler';
        btn.classList.remove('btn-outline-secondary');
        btn.classList.add('btn-secondary');
    }
    grid.classList.add('selection-mode');
    selectionBar.classList.remove('d-none');

    // Désactiver les liens des photos
    document.querySelectorAll('.photo-link').forEach(link => {
        link.dataset.href = link.href;
        link.removeAttribute('href');
    });
}

function exitSelectionMode() {
    const grid = document.getElementById('photosGrid');
    const btn = document.getElementById('btnSelectionner');
    const selectionBar = document.getElementById('selectionBar');

    isSelectionMode = false;
    if (btn) {
        btn.innerHTML = '<i class="bi bi-check2-square me-1"></i>Sélectionner';
        btn.classList.remove('btn-secondary');
        btn.classList.add('btn-outline-secondary');
    }
    grid.classList.remove('selection-mode');
    selectionBar.classList.add('d-none');

    // Désélectionner tout
    deselectAllPhotos();

    // Réactiver les liens des photos
    document.querySelectorAll('.photo-link').forEach(link => {
        if (link.dataset.href) {
            link.href = link.dataset.href;
        }
    });
}

function toggleSelectionMode() {
    if (isSelectionMode) {
        exitSelectionMode();
    } else {
        enterSelectionMode();
    }
}

function togglePhotoSelection(photoItem, event) {
    if (!isSelectionMode) return;

    event.preventDefault();
    event.stopPropagation();

    const photoId = photoItem.dataset.id;
    const checkbox = photoItem.querySelector('.photo-checkbox');

    if (selectedPhotos.has(photoId)) {
        selectedPhotos.delete(photoId);
        photoItem.classList.remove('selected');
        checkbox.checked = false;
    } else {
        selectedPhotos.add(photoId);
        photoItem.classList.add('selected');
        checkbox.checked = true;
    }

    updateSelectionCount();
}

function selectPhoto(photoItem) {
    const photoId = photoItem.dataset.id;
    const checkbox = photoItem.querySelector('.photo-checkbox');

    if (!selectedPhotos.has(photoId)) {
        selectedPhotos.add(photoId);
        photoItem.classList.add('selected');
        checkbox.checked = true;
        updateSelectionCount();
    }
}

function selectAllPhotos() {
    document.querySelectorAll('.photo-item').forEach(item => {
        const photoId = item.dataset.id;
        selectedPhotos.add(photoId);
        item.classList.add('selected');
        item.querySelector('.photo-checkbox').checked = true;
    });
    updateSelectionCount();
}

function deselectAllPhotos() {
    document.querySelectorAll('.photo-item').forEach(item => {
        item.classList.remove('selected');
        item.querySelector('.photo-checkbox').checked = false;
    });
    selectedPhotos.clear();
    updateSelectionCount();
}

function updateSelectionCount() {
    const count = selectedPhotos.size;
    document.getElementById('selectedCount').textContent = count;
    document.getElementById('btnDeleteSelected').disabled = count === 0;
}

function deleteSelectedPhotos() {
    const count = selectedPhotos.size;
    if (count === 0) return;

    if (!confirm(`Êtes-vous sûr de vouloir supprimer ${count} photo(s) ?`)) {
        return;
    }

    // Remplir le formulaire caché et soumettre
    document.getElementById('bulkDeletePhotoIds').value = JSON.stringify(Array.from(selectedPhotos));
    document.getElementById('bulkDeleteForm').submit();
}

function showToast(message, type = 'info') {
    // Créer un toast Bootstrap simple
    const toastContainer = document.getElementById('toastContainer') || createToastContainer();
    const toast = document.createElement('div');
    toast.className = `toast align-items-center text-bg-${type} border-0`;
    toast.setAttribute('role', 'alert');
    toast.innerHTML = `
        <div class="d-flex">
            <div class="toast-body">${message}</div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    `;
    toastContainer.appendChild(toast);
    const bsToast = new bootstrap.Toast(toast, { delay: 3000 });
    bsToast.show();
    toast.addEventListener('hidden.bs.toast', () => toast.remove());
}

function createToastContainer() {
    const container = document.createElement('div');
    container.id = 'toastContainer';
    container.className = 'toast-container position-fixed bottom-0 end-0 p-3';
    container.style.zIndex = '9999';
    document.body.appendChild(container);
    return container;
}
</script>

<style>
/* Styles pour le mode réorganisation */
.sortable-mode .photo-item {
    cursor: grab;
}
.sortable-mode .photo-item:active {
    cursor: grabbing;
}
.sortable-ghost {
    opacity: 0.4;
}
.sortable-chosen {
    transform: scale(1.05);
    box-shadow: 0 10px 30px rgba(0,0,0,0.3);
}
.sortable-drag {
    opacity: 0.9;
}
.sortable-mode .photo-item .position-relative::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    border: 2px dashed #0d6efd;
    border-radius: 0.375rem;
    z-index: 5;
    pointer-events: none;
}
</style>

<script>
// Persistance de l'onglet actif dans l'URL
document.querySelectorAll('#projetTabs button[data-bs-toggle="tab"]').forEach(tab => {
    tab.addEventListener('shown.bs.tab', function(e) {
        const tabId = e.target.getAttribute('data-bs-target').replace('#', '');
        const url = new URL(window.location);
        url.searchParams.set('tab', tabId);
        window.history.replaceState({}, '', url);
    });
});

</script>

<!-- Modal Edit Note Checklist (doit être hors des tab-pane) -->
<div class="modal fade" id="editNoteModal" tabindex="-1" aria-labelledby="editNoteModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content bg-dark text-white">
            <div class="modal-header border-secondary">
                <h5 class="modal-title" id="editNoteModalLabel"><i class="bi bi-pencil-square me-2"></i>Note pour l'item</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="editNoteItemId">
                <p class="text-muted small mb-2">Item: <span id="editNoteItemNom" class="text-white"></span></p>
                <div class="mb-3">
                    <label class="form-label">Note / Info-bulle</label>
                    <textarea class="form-control bg-dark text-white border-secondary" id="editNoteText" rows="3" placeholder="Entrez une note qui s'affichera en info-bulle..."></textarea>
                </div>
            </div>
            <div class="modal-footer border-secondary">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                <button type="button" class="btn btn-primary" id="saveNoteBtn">
                    <i class="bi bi-check-lg me-1"></i>Sauvegarder
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// Edit note modal - populate data when modal opens
const editNoteModal = document.getElementById('editNoteModal');
if (editNoteModal) {
    editNoteModal.addEventListener('show.bs.modal', function(e) {
        const btn = e.relatedTarget;
        if (btn) {
            document.getElementById('editNoteItemId').value = btn.dataset.itemId || '';
            document.getElementById('editNoteItemNom').textContent = btn.dataset.itemNom || '';
            document.getElementById('editNoteText').value = btn.dataset.notes || '';
        }
    });
}

// Save note button
document.getElementById('saveNoteBtn').addEventListener('click', function() {
    const itemId = document.getElementById('editNoteItemId').value;
    const notes = document.getElementById('editNoteText').value.trim();

    fetch('<?= url('/admin/projets/detail.php?id=' . $projetId) ?>', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `ajax_action=save_checklist_note&item_id=${itemId}&notes=${encodeURIComponent(notes)}&csrf_token=<?= generateCSRFToken() ?>`
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            bootstrap.Modal.getInstance(editNoteModal).hide();
            location.reload();
        } else {
            alert('Erreur: ' + (data.error || 'Erreur inconnue'));
        }
    })
    .catch(err => {
        alert('Erreur réseau: ' + err.message);
    });
});

// Delete checklist item
document.querySelectorAll('.delete-checklist-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        const itemId = this.dataset.itemId;
        const itemNom = this.dataset.itemNom;

        if (!confirm(`Supprimer "${itemNom}" ?\n\nCet item sera supprimé définitivement de la checklist.`)) {
            return;
        }

        fetch('<?= url('/admin/projets/detail.php?id=' . $projetId) ?>', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `ajax_action=delete_checklist_item&item_id=${itemId}&csrf_token=<?= generateCSRFToken() ?>`
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert('Erreur: ' + (data.error || 'Erreur inconnue'));
            }
        })
        .catch(err => {
            alert('Erreur: ' + err.message);
        });
    });
});

// Change facture status
document.querySelectorAll('.change-facture-status').forEach(link => {
    link.addEventListener('click', function(e) {
        e.preventDefault();
        const factureId = this.dataset.factureId;
        const newStatus = this.dataset.status;

        fetch('<?= url('/admin/projets/detail.php?id=' . $projetId) ?>', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `ajax_action=change_facture_status&facture_id=${factureId}&new_status=${newStatus}&csrf_token=<?= generateCSRFToken() ?>`
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert('Erreur: ' + (data.error || 'Erreur inconnue'));
            }
        })
        .catch(err => {
            alert('Erreur: ' + err.message);
        });
    });
});
</script>

<?php include '../../includes/footer.php'; ?>
