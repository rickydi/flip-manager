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
// MODE PARTIEL (AJAX) — retour d’un seul tab
// ========================================
if (isset($_GET['_partial']) && $_GET['_partial'] === 'base') {
    // Charger le projet et les données nécessaires au tab Base
    $projet = getProjetById($pdo, $projetId);
    if (!$projet) {
        http_response_code(404);
        exit;
    }

    // Synchroniser budgets AVANT calcul
    syncBudgetsFromProjetItems($pdo, $projetId);

    // Calculs nécessaires au tab Base
    $indicateurs = calculerIndicateursProjet($pdo, $projet);
    $budgetParEtape = calculerBudgetParEtape($pdo, $projetId);
    $depensesParEtape = calculerDepensesParEtape($pdo, $projetId);

    // Données utilisées par tab-base.php
    $recurrentsReels = calculerCoutsRecurrentsReels($projet);

    // Retourner UNIQUEMENT le HTML du tab Base
    include 'partials/tab-base.php';
    exit;
}

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
    // Legacy code - projet_postes n'est plus utilisé
    $postes = [];

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

        // Recalculer les indicateurs complets (comme get_indicateurs)
        $projet = getProjetById($pdo, $projetId);
        $indicateurs = calculerIndicateursProjet($pdo, $projet);
        $budgetParEtape = calculerBudgetParEtape($pdo, $projetId);
        $depensesParEtape = calculerDepensesParEtape($pdo, $projetId);

        echo json_encode([
            'success' => true,
            'indicateurs' => [
                'valeur_potentielle' => $indicateurs['valeur_potentielle'],
                'equite_potentielle' => $indicateurs['equite_potentielle'],
                'equite_reelle' => $indicateurs['equite_reelle'],
                'roi_leverage' => $indicateurs['roi_leverage'],
                'cout_total_projet' => $indicateurs['cout_total_projet']
            ],
            'renovation' => $indicateurs['renovation'],
            'budget_par_etape' => $budgetParEtape,
            'depenses_par_etape' => $depensesParEtape,
            'main_oeuvre' => $indicateurs['main_doeuvre'],
            'couts_acquisition' => $indicateurs['couts_acquisition'],
            'couts_recurrents' => $indicateurs['couts_recurrents'],
            'couts_vente' => $indicateurs['couts_vente']
        ]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

/**
 * ========================================
 * AJAX: Obtenir les indicateurs (refresh sans sauvegarder)
 * Utilisé par l’onglet Base
 * ========================================
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action']) && in_array($_POST['ajax_action'], ['get_indicateurs', 'get_project_totals'])) {
    header('Content-Type: application/json');

    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'error' => 'Token invalide']);
        exit;
    }

    try {
        $projet = getProjetById($pdo, $projetId);
        $indicateurs = calculerIndicateursProjet($pdo, $projet);
        $budgetParEtape = calculerBudgetParEtape($pdo, $projetId);
        $depensesParEtape = calculerDepensesParEtape($pdo, $projetId);

echo json_encode([
            'success' => true,
            'indicateurs' => [
                'valeur_potentielle' => $indicateurs['valeur_potentielle'],
                'equite_potentielle' => $indicateurs['equite_potentielle'],
                'equite_reelle' => $indicateurs['equite_reelle'],
                'roi_leverage' => $indicateurs['roi_leverage'],
                'cout_total_projet' => $indicateurs['cout_total_projet']
            ],
            'renovation' => $indicateurs['renovation'],
            'budget_par_etape' => $budgetParEtape,
            'depenses_par_etape' => $depensesParEtape,
            'main_oeuvre' => $indicateurs['main_doeuvre']
        ]);
    } catch (Exception $e) {
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

// Récupérer les étapes (remplace les anciennes catégories)
$categoriesAvecBudget = [];
try {
    $stmt = $pdo->query("SELECT * FROM budget_etapes ORDER BY ordre, nom");
    $categoriesAvecBudget = $stmt->fetchAll();
} catch (Exception $e) {}

// Grouper par catégorie (toutes dans 'Étapes' maintenant)
$categoriesGroupees = [];
foreach ($categoriesAvecBudget as $cat) {
    $categoriesGroupees['etapes'][] = $cat;
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

// NOUVEAU SYSTÈME: Budget par étape (sections du budget-builder)
$budgetParEtape = calculerBudgetParEtape($pdo, $projetId);
$depensesParEtape = calculerDepensesParEtape($pdo, $projetId);

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
    // Legacy: ancien système de catégories - maintenant on utilise budget_etapes
    // Ce code n'est plus utilisé avec le nouveau budget-builder

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
        SELECT f.*, e.nom as etape_nom
        FROM factures f
        LEFT JOIN budget_etapes e ON f.etape_id = e.id
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
    <!-- TAB BASE --> <?php include 'partials/tab-base.php'; ?>

    <!-- TAB FINANCEMENT --> <?php include 'partials/tab-financement.php'; ?>

    <!-- TAB BUDGETS -->
    <div class="tab-pane fade <?= $tab === 'budgets' ? 'show active' : '' ?>" id="budgets" role="tabpanel">
    <?php include 'budget-builder-content.php'; ?>
    </div><!-- Fin TAB BUDGETS -->

    <!-- TAB MAIN-D'ŒUVRE --> <?php include 'partials/tab-maindoeuvre.php'; ?>

    <!-- TAB TEMPS --> <?php include 'partials/tab-temps.php'; ?>

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

    <!-- TAB PHOTOS --> <?php include 'partials/tab-photos.php'; ?>

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
    <?php include 'partials/tab-factures.php'; ?>
    <!-- TAB CHECKLIST -->
    <?php include 'partials/tab-checklist.php'; ?>

    <!-- TAB DOCUMENTS -->
    <?php include 'partials/tab-documents.php'; ?>
    <!-- TAB GOOGLE SHEET -->
    <?php include 'partials/tab-googlesheet.php'; ?>

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

window.initDetailCharts = function () {
    if (window.chartCouts) window.chartCouts.destroy();
    if (window.chartBudget) window.chartBudget.destroy();
    if (window.chartProfits) window.chartProfits.destroy();

    // Chart 1: Coûts vs Valeur
    window.chartCouts = new Chart(document.getElementById('chartCouts'), {
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
};

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
        // Prendre la PREMIÈRE total-row trouvée (pas la dernière)
        // Ou s'arrêter si on atteint grand-total
        if (row.classList.contains('grand-total')) {
            break;
        }
        if (row.classList.contains('total-row') && !totalRow) {
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
/* CSRF global pour synchronisation Budget → Détail (même hors onglet Base) */
window.baseFormCsrfToken = '<?= generateCSRFToken() ?>';

/* Fonctions globales TOUJOURS disponibles pour le refresh des indicateurs depuis Budget Builder */
window.formatMoneyBase = function(val) {
    return val.toLocaleString('fr-CA', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' $';
};

window.formatPercentBase = function(val) {
    return val.toLocaleString('fr-CA', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' %';
};

window.updateIndicateurs = function(ind) {
    const elValeur = document.getElementById('indValeurPotentielle');
    const elEquiteBudget = document.getElementById('indEquiteBudget');
    const elEquiteReelle = document.getElementById('indEquiteReelle');
    const elRoi = document.getElementById('indRoiLeverage');
    const elCoutTotal = document.getElementById('detailCoutTotalProjet');

    if (elValeur) elValeur.textContent = window.formatMoneyBase(ind.valeur_potentielle);
    if (elEquiteBudget) elEquiteBudget.textContent = window.formatMoneyBase(ind.equite_potentielle);
    if (elEquiteReelle) elEquiteReelle.textContent = window.formatMoneyBase(ind.equite_reelle);
    if (elRoi) elRoi.textContent = window.formatPercentBase(ind.roi_leverage);
    if (elCoutTotal && ind.cout_total_projet) elCoutTotal.textContent = window.formatMoneyBase(ind.cout_total_projet);
};

// Fonction pour mettre à jour les sections de coûts (acquisition, récurrents, vente)
window.updateCoutsSection = function(section, data) {
    // Mettre à jour les sous-totaux par section
    const totalEl = document.getElementById('detail' + section.charAt(0).toUpperCase() + section.slice(1) + 'Total');
    if (totalEl && data.total !== undefined) {
        totalEl.textContent = window.formatMoneyBase(data.total);
    }

    // Pour les récurrents, mettre à jour les valeurs extrapolées
    if (section === 'recurrents' && data) {
        // Clés fixes (taxes_municipales, electricite, etc.)
        Object.keys(data).forEach(key => {
            if (key !== 'details' && key !== 'total' && typeof data[key] === 'object' && data[key].extrapole !== undefined) {
                const el = document.getElementById('detailRecurrent_' + key);
                if (el) el.textContent = window.formatMoneyBase(data[key].extrapole);
            }
        });
        // Types dynamiques dans 'details' (gazon, etc.)
        if (data.details) {
            Object.keys(data.details).forEach(key => {
                if (typeof data.details[key] === 'object' && data.details[key].extrapole !== undefined) {
                    const el = document.getElementById('detailRecurrent_' + key);
                    if (el) el.textContent = window.formatMoneyBase(data.details[key].extrapole);
                }
            });
        }
    }

    // Pour la vente, mettre à jour commission et intérêts
    if (section === 'vente' && data) {
        const elCommission = document.getElementById('detailCommissionTTC');
        const elInterets = document.getElementById('detailInterets');
        if (elCommission && data.commission_ttc !== undefined) {
            elCommission.textContent = window.formatMoneyBase(data.commission_ttc);
        }
        if (elInterets && data.interets !== undefined) {
            elInterets.textContent = window.formatMoneyBase(data.interets);
        }
    }
};

window.updateRenovation = function(reno, budgetParEtape, depensesParEtape) {
    // Mettre à jour les totaux de la section rénovation
    const elContingence = document.getElementById('detailContingence');
    const elTPS = document.getElementById('detailTPS');
    const elTVQ = document.getElementById('detailTVQ');
    const elRenoTotal = document.getElementById('detailRenoTotal');

    if (elContingence) elContingence.textContent = window.formatMoneyBase(reno.contingence);
    if (elTPS) elTPS.textContent = window.formatMoneyBase(reno.tps);
    if (elTVQ) elTVQ.textContent = window.formatMoneyBase(reno.tvq);
    // Sous-total = budget TTC + main d'oeuvre extrapolée (identique au calcul PHP)
    const renoBudgetTTC = (reno.budget_ttc || 0) + (reno.main_doeuvre_budget?.cout || 0);
    if (elRenoTotal) elRenoTotal.textContent = window.formatMoneyBase(renoBudgetTTC);

    // Mettre à jour chaque ligne d'étape (Budget, Diff, Réel)
    if (budgetParEtape) {
        for (const [etapeId, etape] of Object.entries(budgetParEtape)) {
            const row = document.querySelector(`tr.detail-etape-row[data-etape-id="${etapeId}"]`);
            if (row) {
                const budgetHT = etape.total || 0;
                const depense = depensesParEtape && depensesParEtape[etapeId] ? (depensesParEtape[etapeId].total || 0) : 0;
                const ecart = budgetHT - depense;

                const budgetCell = row.querySelector('.detail-etape-budget');
                const diffCell = row.querySelector('.detail-etape-diff');
                const reelCell = row.querySelector('.detail-etape-reel');

                if (budgetCell) budgetCell.textContent = window.formatMoneyBase(budgetHT);
                if (reelCell) reelCell.textContent = window.formatMoneyBase(depense);
                if (diffCell) {
                    diffCell.textContent = ecart !== 0 ? window.formatMoneyBase(ecart) : '-';
                    diffCell.classList.remove('positive', 'negative');
                    if (ecart > 0) diffCell.classList.add('positive');
                    else if (ecart < 0) diffCell.classList.add('negative');
                }
            }
        }
    }

    // Mettre à jour les dépenses sans budget (étapes qui ont des factures mais pas de budget)
    if (depensesParEtape) {
        for (const [etapeId, dep] of Object.entries(depensesParEtape)) {
            if (!budgetParEtape || !budgetParEtape[etapeId]) {
                const row = document.querySelector(`tr.detail-etape-row[data-etape-id="${etapeId}"]`);
                if (row) {
                    const depense = dep.total || 0;
                    const budgetCell = row.querySelector('.detail-etape-budget');
                    const reelCell = row.querySelector('.detail-etape-reel');
                    const diffCell = row.querySelector('.detail-etape-diff');
                    if (budgetCell) budgetCell.textContent = '-';
                    if (reelCell) reelCell.textContent = window.formatMoneyBase(depense);
                    if (diffCell) {
                        diffCell.textContent = window.formatMoneyBase(-depense);
                        diffCell.classList.remove('positive');
                        diffCell.classList.add('negative');
                    }
                }
            }
        }
    }
};

(function() {
    const formBase = document.getElementById('formBase');
    if (!formBase) return;

    const csrfToken = window.baseFormCsrfToken;
    let baseSaveTimeout = null;

    function showBaseSaveStatus(status) {
        document.getElementById('baseIdle').classList.add('d-none');
        document.getElementById('baseSaving').classList.add('d-none');
        document.getElementById('baseSaved').classList.add('d-none');
        document.getElementById('base' + status.charAt(0).toUpperCase() + status.slice(1)).classList.remove('d-none');
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

                    // Mettre à jour les indicateurs de base
                    if (data.indicateurs) {
                        updateIndicateurs(data.indicateurs);
                    }

                    // Mettre à jour la section rénovation complète
                    if (data.renovation) {
                        if (typeof window.renderRenovationFromJson === 'function') {
                            window.renderRenovationFromJson(
                                data.renovation,
                                data.budget_par_etape || {},
                                data.depenses_par_etape || {}
                            );
                        } else if (typeof window.updateRenovation === 'function') {
                            window.updateRenovation(
                                data.renovation,
                                data.budget_par_etape,
                                data.depenses_par_etape
                            );
                        }
                    }

                    // Mettre à jour les coûts d'acquisition, récurrents et vente
                    if (data.couts_acquisition) {
                        updateCoutsSection('acquisition', data.couts_acquisition);
                    }
                    if (data.couts_recurrents) {
                        updateCoutsSection('recurrents', data.couts_recurrents);
                    }
                    if (data.couts_vente) {
                        updateCoutsSection('vente', data.couts_vente);
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

    // Les fonctions updateIndicateurs, updateRenovation, formatMoneyBase, formatPercentBase
    // sont maintenant définies globalement en dehors de cette IIFE pour être toujours disponibles
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
// Persistance de l'onglet actif dans l'URL et refresh des données
document.querySelectorAll('#projetTabs button[data-bs-toggle="tab"]').forEach(tab => {
    tab.addEventListener('shown.bs.tab', function(e) {
        const tabId = e.target.getAttribute('data-bs-target').replace('#', '');
        const url = new URL(window.location);
        url.searchParams.set('tab', tabId);
        window.history.replaceState({}, '', url);

        // Rafraîchir les indicateurs et rénovation quand on arrive sur l'onglet Base
        if (tabId === 'base' && window.baseFormCsrfToken && window.updateIndicateurs) {
            const formData = new FormData();
            formData.set('ajax_action', 'get_indicateurs');
            formData.set('csrf_token', window.baseFormCsrfToken);

            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    if (data.indicateurs) {
                        window.updateIndicateurs(data.indicateurs);
                    }
                    if (data.renovation && window.updateRenovation) {
                        window.updateRenovation(data.renovation, data.budget_par_etape, data.depenses_par_etape);
                    }
                }
            })
            .catch(err => console.error('Erreur refresh indicateurs:', err));
        }
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
