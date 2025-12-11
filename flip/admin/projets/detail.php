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
// AJAX: Upload document
// ========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'upload_document') {
    header('Content-Type: application/json');

    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'error' => 'Token invalide']);
        exit;
    }

    if (!isset($_FILES['document']) || $_FILES['document']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'error' => 'Erreur lors de l\'upload']);
        exit;
    }

    $file = $_FILES['document'];
    $maxSize = 10 * 1024 * 1024; // 10 Mo

    if ($file['size'] > $maxSize) {
        echo json_encode(['success' => false, 'error' => 'Fichier trop volumineux (max 10 Mo)']);
        exit;
    }

    // Types autorisés
    $allowedTypes = ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                     'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                     'image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    if (!in_array($file['type'], $allowedTypes)) {
        echo json_encode(['success' => false, 'error' => 'Type de fichier non autorisé']);
        exit;
    }

    // Créer le dossier si nécessaire
    $uploadDir = __DIR__ . '/../../uploads/documents/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    // Générer un nom unique
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $nomFichier = $projetId . '_' . time() . '_' . uniqid() . '.' . $extension;
    $destination = $uploadDir . $nomFichier;

    if (move_uploaded_file($file['tmp_name'], $destination)) {
        try {
            $stmt = $pdo->prepare("INSERT INTO projet_documents (projet_id, nom, fichier, type, taille) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$projetId, $file['name'], $nomFichier, $file['type'], $file['size']]);
            echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
        } catch (Exception $e) {
            unlink($destination);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'Erreur lors du déplacement du fichier']);
    }
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
// AJAX: Sauvegarde automatique des budgets
// ========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'save_budget') {
    header('Content-Type: application/json');

    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'error' => 'Token invalide']);
        exit;
    }

    $postes = $_POST['postes'] ?? [];
    $items = $_POST['items'] ?? [];
    $groupes = $_POST['groupes'] ?? [];

    // Debug: log received data
    error_log("=== SAVE BUDGET DEBUG ===");
    error_log("Postes received: " . json_encode($postes));

    try {
        $pdo->beginTransaction();

        // Supprimer tous les items et postes existants (items d'abord car FK vers postes)
        $pdo->prepare("DELETE FROM projet_items WHERE projet_id = ?")->execute([$projetId]);
        $pdo->prepare("DELETE FROM projet_postes WHERE projet_id = ?")->execute([$projetId]);
        $pdo->prepare("DELETE FROM budgets WHERE projet_id = ?")->execute([$projetId]);
        $pdo->prepare("DELETE FROM projet_groupes WHERE projet_id = ?")->execute([$projetId]);

        // Sauvegarder les quantités de groupes
        foreach ($groupes as $groupeNom => $qte) {
            $qte = max(1, (int)$qte);
            $stmt = $pdo->prepare("INSERT INTO projet_groupes (projet_id, groupe_nom, quantite) VALUES (?, ?, ?)");
            $stmt->execute([$projetId, $groupeNom, $qte]);
        }

        // Réinsérer les postes cochés
        foreach ($postes as $categorieId => $data) {
            if (!empty($data['checked'])) {
                $quantite = max(1, (int)($data['quantite'] ?? 1));
                $budgetExtrapole = parseNumber($data['budget_extrapole'] ?? '0');
                error_log("Saving cat $categorieId with qty=$quantite, budget=$budgetExtrapole");

                $stmt = $pdo->prepare("
                    INSERT INTO projet_postes (projet_id, categorie_id, quantite, budget_extrapole)
                    VALUES (?, ?, ?, ?)
                ");
                $stmt->execute([$projetId, $categorieId, $quantite, $budgetExtrapole]);
                $posteId = $pdo->lastInsertId();

                // Insérer les items cochés
                if (isset($items[$categorieId])) {
                    foreach ($items[$categorieId] as $materiauId => $itemData) {
                        if (!empty($itemData['checked'])) {
                            $prixUnitaire = parseNumber($itemData['prix'] ?? '0');
                            $qteItem = max(1, (int)($itemData['qte'] ?? 1));
                            $sansTaxe = !empty($itemData['sans_taxe']) ? 1 : 0;

                            $stmt = $pdo->prepare("
                                INSERT INTO projet_items (projet_id, projet_poste_id, materiau_id, prix_unitaire, quantite, sans_taxe)
                                VALUES (?, ?, ?, ?, ?, ?)
                            ");
                            $stmt->execute([$projetId, $posteId, $materiauId, $prixUnitaire, $qteItem, $sansTaxe]);
                        }
                    }
                }

                // Sync avec table budgets
                $stmt = $pdo->prepare("
                    INSERT INTO budgets (projet_id, categorie_id, montant_extrapole)
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE montant_extrapole = ?
                ");
                $stmt->execute([$projetId, $categorieId, $budgetExtrapole, $budgetExtrapole]);
            }
        }

        $pdo->commit();
        error_log("Transaction committed successfully");

        // Calculer les nouveaux totaux
        $projet = getProjetById($pdo, $projetId);
        $indicateurs = calculerIndicateursProjet($pdo, $projet);

        echo json_encode([
            'success' => true,
            'totals' => [
                'budget' => $indicateurs['renovation']['budget'],
                'contingence' => $indicateurs['contingence'],
                'equite' => $indicateurs['equite_potentielle']
            ]
        ]);
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Transaction rolled back: " . $e->getMessage());
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
        SELECT pi.*, i.nom as investisseur_nom
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

                if ($investisseurId && $montant > 0) {
                    $stmt = $pdo->prepare("
                        INSERT INTO projet_investisseurs (projet_id, investisseur_id, montant, taux_interet)
                        VALUES (?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE montant = VALUES(montant), taux_interet = VALUES(taux_interet)
                    ");
                    $stmt->execute([$projetId, $investisseurId, $montant, $tauxInteret]);
                    setFlashMessage('success', 'Prêteur ajouté!');
                }
            } elseif ($subAction === 'supprimer') {
                $preteurId = (int)($_POST['preteur_id'] ?? 0);
                if ($preteurId) {
                    $stmt = $pdo->prepare("DELETE FROM projet_investisseurs WHERE id = ? AND projet_id = ?");
                    $stmt->execute([$preteurId, $projetId]);
                    setFlashMessage('success', 'Prêteur supprimé.');
                }
            } elseif ($subAction === 'modifier') {
                $preteurId = (int)($_POST['preteur_id'] ?? 0);
                $montant = parseNumber($_POST['montant_pret'] ?? 0);
                $tauxInteret = parseNumber($_POST['taux_interet_pret'] ?? 0);

                if ($preteurId && $montant > 0) {
                    $stmt = $pdo->prepare("
                        UPDATE projet_investisseurs
                        SET montant = ?, taux_interet = ?
                        WHERE id = ? AND projet_id = ?
                    ");
                    $stmt->execute([$montant, $tauxInteret, $preteurId, $projetId]);
                    setFlashMessage('success', 'Financement mis à jour!');
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

        } elseif ($action === 'postes_budgets') {
            // Gestion des postes (catégories importées avec quantité)
            $postes = $_POST['postes'] ?? [];
            $items = $_POST['items'] ?? [];
            $groupes = $_POST['groupes'] ?? [];

            try {
                $pdo->beginTransaction();

                // Supprimer dans le bon ordre (FK constraints)
                $pdo->prepare("DELETE FROM projet_items WHERE projet_id = ?")->execute([$projetId]);
                $pdo->prepare("DELETE FROM projet_postes WHERE projet_id = ?")->execute([$projetId]);
                $pdo->prepare("DELETE FROM budgets WHERE projet_id = ?")->execute([$projetId]);
                $pdo->prepare("DELETE FROM projet_groupes WHERE projet_id = ?")->execute([$projetId]);

                // Sauvegarder les quantités de groupes
                foreach ($groupes as $groupeNom => $qte) {
                    $qte = max(1, (int)$qte);
                    $stmt = $pdo->prepare("INSERT INTO projet_groupes (projet_id, groupe_nom, quantite) VALUES (?, ?, ?)");
                    $stmt->execute([$projetId, $groupeNom, $qte]);
                }

                // Réinsérer les postes cochés
                foreach ($postes as $categorieId => $data) {
                    if (!empty($data['checked'])) {
                        $quantite = max(1, (int)($data['quantite'] ?? 1));
                        $budgetExtrapole = parseNumber($data['budget_extrapole'] ?? '0');

                        $stmt = $pdo->prepare("
                            INSERT INTO projet_postes (projet_id, categorie_id, quantite, budget_extrapole)
                            VALUES (?, ?, ?, ?)
                        ");
                        $stmt->execute([$projetId, $categorieId, $quantite, $budgetExtrapole]);
                        $posteId = $pdo->lastInsertId();

                        // Insérer les items cochés pour ce poste
                        if (isset($items[$categorieId])) {
                            foreach ($items[$categorieId] as $materiauId => $itemData) {
                                if (!empty($itemData['checked'])) {
                                    $prixUnitaire = parseNumber($itemData['prix'] ?? '0');
                                    $qteItem = max(1, (int)($itemData['qte'] ?? 1));
                                    $sansTaxe = !empty($itemData['sans_taxe']) ? 1 : 0;

                                    $stmt = $pdo->prepare("
                                        INSERT INTO projet_items (projet_id, projet_poste_id, materiau_id, prix_unitaire, quantite, sans_taxe)
                                        VALUES (?, ?, ?, ?, ?, ?)
                                    ");
                                    $stmt->execute([$projetId, $posteId, $materiauId, $prixUnitaire, $qteItem, $sansTaxe]);
                                }
                            }
                        }

                        // Mettre à jour aussi la table budgets pour compatibilité
                        $stmt = $pdo->prepare("
                            INSERT INTO budgets (projet_id, categorie_id, montant_extrapole)
                            VALUES (?, ?, ?)
                            ON DUPLICATE KEY UPDATE montant_extrapole = ?
                        ");
                        $stmt->execute([$projetId, $categorieId, $budgetExtrapole, $budgetExtrapole]);
                    }
                }

                $pdo->commit();
            } catch (Exception $e) {
                $pdo->rollBack();
                setFlashMessage('danger', 'Erreur lors de la sauvegarde: ' . $e->getMessage());
                redirect('/admin/projets/detail.php?id=' . $projetId . '&tab=budgets');
            }

            setFlashMessage('success', 'Budgets détaillés mis à jour!');
            redirect('/admin/projets/detail.php?id=' . $projetId . '&tab=budgets');
        }
    }
}

// Recharger le projet après modifications
$projet = getProjetById($pdo, $projetId);
$tab = $_GET['tab'] ?? 'base';
$pageTitle = $projet['nom'];
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
// DONNÉES TEMPLATES BUDGETS DÉTAILLÉS
// ========================================
$templatesBudgets = [];
$projetPostes = [];
$projetItems = [];
$projetGroupes = [];

try {
    // Charger les templates (sous-catégories et matériaux par catégorie)
    // Inclut TOUTES les sous-catégories (même imbriquées) pour avoir tous les matériaux
    $stmt = $pdo->query("
        SELECT c.id as categorie_id, c.nom as categorie_nom, c.groupe,
               sc.id as sc_id, sc.nom as sc_nom, sc.parent_id as sc_parent_id,
               m.id as mat_id, m.nom as mat_nom, m.prix_defaut,
               COALESCE(m.quantite_defaut, 1) as quantite_defaut
        FROM categories c
        LEFT JOIN sous_categories sc ON sc.categorie_id = c.id AND sc.actif = 1
        LEFT JOIN materiaux m ON m.sous_categorie_id = sc.id AND m.actif = 1
        ORDER BY c.groupe, c.ordre, sc.ordre, m.ordre
    ");

    foreach ($stmt->fetchAll() as $row) {
        $catId = $row['categorie_id'];
        if (!isset($templatesBudgets[$catId])) {
            $templatesBudgets[$catId] = [
                'id' => $catId,
                'nom' => $row['categorie_nom'],
                'groupe' => $row['groupe'],
                'sous_categories' => []
            ];
        }
        if ($row['sc_id']) {
            $scId = $row['sc_id'];
            if (!isset($templatesBudgets[$catId]['sous_categories'][$scId])) {
                $templatesBudgets[$catId]['sous_categories'][$scId] = [
                    'id' => $scId,
                    'nom' => $row['sc_nom'],
                    'materiaux' => []
                ];
            }
            if ($row['mat_id']) {
                $templatesBudgets[$catId]['sous_categories'][$scId]['materiaux'][] = [
                    'id' => $row['mat_id'],
                    'nom' => $row['mat_nom'],
                    'prix_defaut' => (float)$row['prix_defaut'],
                    'quantite_defaut' => (int)$row['quantite_defaut']
                ];
            }
        }
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
    <div class="row g-2 mb-3">
        <div class="col-6 col-lg">
            <div class="card text-center p-2 bg-primary bg-opacity-10" role="button" data-bs-toggle="tooltip" data-bs-placement="bottom" title="Prix de vente estimé de la propriété après rénovations">
                <small class="text-muted">Valeur potentielle <i class="bi bi-info-circle small"></i></small>
                <strong class="fs-5 text-primary" id="indValeurPotentielle"><?= formatMoney($indicateurs['valeur_potentielle']) ?></strong>
            </div>
        </div>
        <div class="col-6 col-lg">
            <div class="card text-center p-2 bg-warning bg-opacity-10" role="button" data-bs-toggle="tooltip" data-bs-placement="bottom" title="Profit prévu si vous respectez le budget. Calcul: Valeur potentielle - Prix d'achat - Budget total - Frais">
                <small class="text-muted">Équité Budget <i class="bi bi-info-circle small"></i></small>
                <strong class="fs-5 text-warning" id="indEquiteBudget"><?= formatMoney($indicateurs['equite_potentielle']) ?></strong>
            </div>
        </div>
        <div class="col-6 col-lg">
            <div class="card text-center p-2 bg-info bg-opacity-10" role="button" data-bs-toggle="tooltip" data-bs-placement="bottom" title="Cash flow nécessaire. Exclut: courtier, taxes mun/scol, mutation. Sans intérêts: <?= formatMoney($indicateurs['cash_flow_moins_interets'], false) ?>$">
                <small class="text-muted">Cash Flow <i class="bi bi-info-circle small"></i></small>
                <strong class="fs-5 text-info" id="indCashFlow"><?= formatMoney($indicateurs['cash_flow_necessaire']) ?></strong>
            </div>
        </div>
        <div class="col-6 col-lg">
            <div class="card text-center p-2 bg-success bg-opacity-10" role="button" data-bs-toggle="tooltip" data-bs-placement="bottom" title="Profit réel basé sur les dépenses actuelles. Calcul: Valeur potentielle - Prix d'achat - Dépenses réelles - Frais">
                <small class="text-muted">Équité Réelle <i class="bi bi-info-circle small"></i></small>
                <strong class="fs-5 text-success" id="indEquiteReelle"><?= formatMoney($indicateurs['equite_reelle']) ?></strong>
            </div>
        </div>
        <div class="col-6 col-lg">
            <div class="card text-center p-2" role="button" data-bs-toggle="tooltip" data-bs-placement="bottom" title="Retour sur investissement basé sur votre mise de fonds (cash investi). Calcul: Équité Réelle ÷ Mise de fonds × 100">
                <small class="text-muted">ROI Leverage <i class="bi bi-info-circle small"></i></small>
                <strong class="fs-5" id="indRoiLeverage"><?= formatPercent($indicateurs['roi_leverage']) ?></strong>
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
                            <div class="col-4">
                                <label class="form-label">Cession</label>
                                <div class="input-group"><span class="input-group-text">$</span>
                                    <input type="text" class="form-control money-input" name="cession" value="<?= formatMoney($projet['cession'] ?? 0, false) ?>">
                                </div>
                            </div>
                            <div class="col-4">
                                <label class="form-label">Notaire</label>
                                <div class="input-group"><span class="input-group-text">$</span>
                                    <input type="text" class="form-control money-input" name="notaire" value="<?= formatMoney($projet['notaire'], false) ?>">
                                </div>
                            </div>
                            <div class="col-4">
                                <label class="form-label">Arpenteurs</label>
                                <div class="input-group"><span class="input-group-text">$</span>
                                    <input type="text" class="form-control money-input" name="arpenteurs" value="<?= formatMoney($projet['arpenteurs'], false) ?>">
                                </div>
                            </div>
                            <div class="col-4">
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
                    foreach ($categories as $cat):
                        $budgetUnit = $budgets[$cat['id']] ?? 0;
                        $depense = $depenses[$cat['id']] ?? 0;
                        if ($budgetUnit == 0 && $depense == 0) continue;
                        // Multiplier par la quantité du groupe
                        $qteGroupe = $projetGroupes[$cat['groupe']] ?? 1;
                        $budgetHT = $budgetUnit * $qteGroupe;

                        // Afficher en HT car TPS/TVQ sont montrés séparément
                        $budgetAffiche = $budgetHT;
                        $ecart = $budgetAffiche - $depense;
                        $totalBudgetReno += $budgetHT;
                        $totalReelReno += $depense;
                    ?>
                    <tr class="sub-item">
                        <td>
                            <?= e($cat['nom']) ?>
                            <?php if ($qteGroupe > 1): ?>
                                <small class="text-muted">(×<?= $qteGroupe ?>)</small>
                            <?php endif; ?>
                        </td>
                        <td class="text-end"><?= formatMoney($budgetAffiche) ?></td>
                        <td class="text-end <?= $ecart >= 0 ? 'positive' : 'negative' ?>"><?= $ecart != 0 ? formatMoney($ecart) : '-' ?></td>
                        <td class="text-end"><?= formatMoney($depense) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    
                    <!-- MAIN D'ŒUVRE -->
                    <?php 
                    $diffMO = $moExtrapole['cout'] - $moReel['cout'];
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
                    
                    <tr class="sub-item">
                        <td>Contingence <?= $projet['taux_contingence'] ?>%</td>
                        <td class="text-end"><?= formatMoney($indicateurs['contingence']) ?></td>
                        <td class="text-end">-</td>
                        <td class="text-end">-</td>
                    </tr>

                    <tr class="sub-item">
                        <td>TPS 5%</td>
                        <td class="text-end"><?= formatMoney($indicateurs['renovation']['tps']) ?></td>
                        <td class="text-end">-</td>
                        <td class="text-end">-</td>
                    </tr>

                    <tr class="sub-item">
                        <td>TVQ 9.975%</td>
                        <td class="text-end"><?= formatMoney($indicateurs['renovation']['tvq']) ?></td>
                        <td class="text-end">-</td>
                        <td class="text-end">-</td>
                    </tr>

                    <?php
                    $renoReel = $indicateurs['renovation']['reel'] + $indicateurs['main_doeuvre']['cout'];
                    $renoBudgetTTC = $indicateurs['renovation']['total_ttc'] + $indicateurs['main_doeuvre_extrapole']['cout'];
                    $diffReno = $renoBudgetTTC - $renoReel;
                    ?>
                    <tr class="total-row">
                        <td>Sous-total Rénovation (avec taxes)</td>
                        <td class="text-end"><?= formatMoney($renoBudgetTTC) ?></td>
                        <td class="text-end <?= $diffReno >= 0 ? 'positive' : 'negative' ?>"><?= formatMoney($diffReno) ?></td>
                        <td class="text-end"><?= formatMoney($renoReel) ?></td>
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
                    <tr class="profit-row">
                        <td>ÉQUITÉ / PROFIT</td>
                        <td class="text-end"><?= formatMoney($indicateurs['equite_potentielle']) ?></td>
                        <td class="text-end" style="color:<?= $diffEquite >= 0 ? '#90EE90' : '#ffcccc' ?>"><?= $diffEquite >= 0 ? '+' : '' ?><?= formatMoney($diffEquite) ?></td>
                        <td class="text-end"><?= formatMoney($indicateurs['equite_reelle']) ?></td>
                    </tr>
                    
                    <tr>
                        <td><strong>ROI @ Leverage</strong></td>
                        <td class="text-end"><?= formatPercent($indicateurs['roi_leverage']) ?></td>
                        <td class="text-end">-</td>
                        <td class="text-end"><?= formatPercent($indicateurs['roi_leverage_reel']) ?></td>
                    </tr>

                    <!-- PARTAGE DES PROFITS -->
                    <?php if (!empty($indicateurs['preteurs']) || !empty($indicateurs['investisseurs'])): ?>
                    <tr class="section-header" data-section="partage">
                        <td colspan="4"><i class="bi bi-pie-chart me-1"></i> Partage des profits <i class="bi bi-chevron-down toggle-icon"></i></td>
                    </tr>

                    <?php if (!empty($indicateurs['preteurs'])): ?>
                    <?php foreach ($indicateurs['preteurs'] as $preteur): ?>
                    <tr class="sub-item">
                        <td><i class="bi bi-bank text-warning me-1"></i><?= e($preteur['nom']) ?> (<?= $preteur['taux'] ?>%)</td>
                        <td class="text-end text-success">+<?= formatMoney($preteur['interets_total']) ?></td>
                        <td class="text-end">-</td>
                        <td class="text-end text-success">+<?= formatMoney($preteur['interets_total']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>

                    <?php if (!empty($indicateurs['investisseurs'])): ?>
                    <?php foreach ($indicateurs['investisseurs'] as $inv): ?>
                    <tr class="sub-item">
                        <td><i class="bi bi-person text-info me-1"></i><?= e($inv['nom']) ?> (<?= number_format($inv['pourcentage'], 1) ?>%)</td>
                        <td class="text-end text-success">+<?= formatMoney($inv['profit_estime']) ?></td>
                        <td class="text-end">-</td>
                        <td class="text-end text-success">+<?= formatMoney($inv['profit_estime']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>

                    <?php
                    // Les intérêts des prêteurs sont DÉJÀ inclus dans les coûts de vente
                    // Donc on ne soustrait que la part des investisseurs (partage de profits)
                    $totalPartageInvestisseurs = 0;
                    foreach ($indicateurs['investisseurs'] ?? [] as $inv) {
                        $totalPartageInvestisseurs += $inv['profit_estime'];
                    }
                    $profitNet = $indicateurs['equite_potentielle'] - $totalPartageInvestisseurs;

                    // Calcul impôt sur le profit (gain en capital)
                    // 12,2% sur les premiers 500 000$ (Fédéral 9% + Québec 3,2%)
                    // 26,5% au-delà de 500 000$
                    $seuilImpot = 500000;
                    $tauxBase = 0.122; // 12,2%
                    $tauxEleve = 0.265; // 26,5%

                    if ($profitNet <= 0) {
                        $impotAPayer = 0;
                    } elseif ($profitNet <= $seuilImpot) {
                        $impotAPayer = $profitNet * $tauxBase;
                    } else {
                        $impotAPayer = ($seuilImpot * $tauxBase) + (($profitNet - $seuilImpot) * $tauxEleve);
                    }
                    $profitApresImpot = $profitNet - $impotAPayer;
                    ?>
                    <tr class="total-row">
                        <td>PROFIT NET (après partage)</td>
                        <td class="text-end"><?= formatMoney($profitNet) ?></td>
                        <td class="text-end">-</td>
                        <td class="text-end"><?= formatMoney($profitNet) ?></td>
                    </tr>
                    <tr class="sub-item text-danger">
                        <td>
                            <i class="bi bi-bank2 me-1"></i>Impôt à payer
                            <small class="text-muted">(<?= $profitNet <= $seuilImpot ? '12,2%' : '12,2% + 26,5%' ?>)</small>
                        </td>
                        <td class="text-end">-<?= formatMoney($impotAPayer) ?></td>
                        <td class="text-end">-</td>
                        <td class="text-end">-<?= formatMoney($impotAPayer) ?></td>
                    </tr>
                    <tr class="total-row table-success">
                        <td><strong><i class="bi bi-cash-stack me-1"></i>PROFIT APRÈS IMPÔT</strong></td>
                        <td class="text-end"><strong><?= formatMoney($profitApresImpot) ?></strong></td>
                        <td class="text-end">-</td>
                        <td class="text-end"><strong><?= formatMoney($profitApresImpot) ?></strong></td>
                    </tr>
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

    <!-- Explications -->
    <div class="alert alert-info mb-4">
        <div class="row">
            <div class="col-md-6">
                <h6><i class="bi bi-bank me-1"></i> PRÊTEUR</h6>
                <small>Prête de l'argent → Reçoit des <strong>INTÉRÊTS</strong> (= coût pour le projet)</small>
            </div>
            <div class="col-md-6">
                <h6><i class="bi bi-people me-1"></i> INVESTISSEUR</h6>
                <small>Met de l'argent "à risque" → Reçoit un <strong>% DES PROFITS</strong> (= partage des gains)</small>
            </div>
        </div>
    </div>

    <?php
    // Séparer les prêteurs des investisseurs
    $listePreteurs = [];
    $listeInvestisseurs = [];
    $totalPretsCalc = 0;
    $totalInvest = 0;

    foreach ($preteursProjet as $p) {
        $montant = (float)($p['montant'] ?? $p['mise_de_fonds'] ?? 0);
        $taux = (float)($p['taux_interet'] ?? $p['pourcentage_profit'] ?? 0);

        if ($taux > 0) {
            $listePreteurs[] = array_merge($p, ['montant_calc' => $montant, 'taux_calc' => $taux]);
            $totalPretsCalc += $montant;
        } else {
            $listeInvestisseurs[] = array_merge($p, ['montant_calc' => $montant, 'pct_calc' => $taux]);
            $totalInvest += $montant;
        }
    }
    ?>

    <div class="row">
        <!-- COLONNE PRÊTEURS -->
        <div class="col-lg-6">
            <div class="card mb-4 border-warning">
                <div class="card-header bg-warning text-dark">
                    <i class="bi bi-bank me-2"></i><strong>PRÊTEURS</strong>
                    <small class="float-end">Coût = Intérêts</small>
                </div>

                <?php if (empty($listePreteurs)): ?>
                    <div class="card-body text-center text-muted py-4">
                        <i class="bi bi-bank" style="font-size: 2rem;"></i>
                        <p class="mb-0 small">Aucun prêteur</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0 table-dark">
                            <thead>
                                <tr class="table-warning text-dark">
                                    <th>Nom</th>
                                    <th class="text-end">Montant</th>
                                    <th class="text-center">Taux</th>
                                    <th class="text-end">Intérêts</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($listePreteurs as $p):
                                $tauxMensuel = $p['taux_calc'] / 100 / 12;
                                $interets = $p['montant_calc'] * (pow(1 + $tauxMensuel, $dureeReelle) - 1);
                            ?>
                                <tr>
                                    <form method="POST" id="form-preteur-<?= $p['id'] ?>">
                                        <?php csrfField(); ?>
                                        <input type="hidden" name="action" value="preteurs">
                                        <input type="hidden" name="sub_action" value="modifier">
                                        <input type="hidden" name="preteur_id" value="<?= $p['id'] ?>">
                                        <td class="align-middle"><i class="bi bi-person-circle text-warning me-1"></i><?= e($p['investisseur_nom']) ?></td>
                                        <td class="text-end">
                                            <input type="text" class="form-control form-control-sm money-input text-end bg-dark text-white border-secondary"
                                                   name="montant_pret" value="<?= number_format($p['montant_calc'], 0, ',', ' ') ?>"
                                                   style="width: 100px; display: inline-block;">
                                        </td>
                                        <td class="text-center">
                                            <input type="text" class="form-control form-control-sm text-center bg-dark text-white border-secondary"
                                                   name="taux_interet_pret" value="<?= $p['taux_calc'] ?>"
                                                   style="width: 60px; display: inline-block;">%
                                        </td>
                                        <td class="text-end text-danger fw-bold"><?= formatMoney($interets) ?></td>
                                        <td class="text-nowrap">
                                            <button type="submit" class="btn btn-outline-primary btn-sm py-0 px-1" title="Sauvegarder">
                                                <i class="bi bi-check"></i>
                                            </button>
                                    </form>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Supprimer?')">
                                                <?php csrfField(); ?>
                                                <input type="hidden" name="action" value="preteurs">
                                                <input type="hidden" name="sub_action" value="supprimer">
                                                <input type="hidden" name="preteur_id" value="<?= $p['id'] ?>">
                                                <button type="submit" class="btn btn-outline-danger btn-sm py-0 px-1" title="Supprimer">
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
                <div class="card-footer" style="background: rgba(30, 58, 95, 0.6);">
                    <form method="POST" class="row g-2 align-items-end">
                        <?php csrfField(); ?>
                        <input type="hidden" name="action" value="preteurs">
                        <input type="hidden" name="sub_action" value="ajouter">
                        <div class="col-4">
                            <label class="form-label small mb-0 text-light">Personne</label>
                            <select class="form-select form-select-sm bg-dark text-white border-secondary" name="investisseur_id" required>
                                <option value="">Choisir...</option>
                                <?php foreach ($tousInvestisseurs as $inv): ?>
                                    <option value="<?= $inv['id'] ?>"><?= e($inv['nom']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-3">
                            <label class="form-label small mb-0 text-light">Montant $</label>
                            <input type="text" class="form-control form-control-sm money-input bg-dark text-white border-secondary" name="montant_pret" required placeholder="0">
                        </div>
                        <div class="col-3">
                            <label class="form-label small mb-0 text-light">Taux %</label>
                            <input type="text" class="form-control form-control-sm bg-dark text-white border-secondary" name="taux_interet_pret" value="10" required>
                        </div>
                        <div class="col-2">
                            <button type="submit" class="btn btn-warning btn-sm w-100"><i class="bi bi-plus-lg"></i></button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Total prêteurs -->
            <div class="card bg-warning text-dark mb-4">
                <div class="card-body py-2">
                    <div class="d-flex justify-content-between">
                        <span>Total prêts :</span>
                        <strong><?= formatMoney($totalPretsCalc) ?></strong>
                    </div>
                    <div class="d-flex justify-content-between text-danger">
                        <span>Intérêts (<?= $dureeReelle ?> mois) :</span>
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
            <div class="card mb-4 border-success">
                <div class="card-header bg-success text-white">
                    <i class="bi bi-people me-2"></i><strong>INVESTISSEURS</strong>
                    <small class="float-end">Partage des profits</small>
                </div>

                <?php if (empty($listeInvestisseurs)): ?>
                    <div class="card-body text-center text-muted py-4">
                        <i class="bi bi-people" style="font-size: 2rem;"></i>
                        <p class="mb-0 small">Aucun investisseur</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0 table-dark">
                            <thead>
                                <tr class="table-success text-dark">
                                    <th>Nom</th>
                                    <th class="text-end">Mise</th>
                                    <th class="text-center">% Profits</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php
                            $totalPctInvest = 0;
                            foreach ($listeInvestisseurs as $inv):
                                $pct = $totalInvest > 0 ? ($inv['montant_calc'] / $totalInvest) * 100 : 0;
                                $totalPctInvest += $pct;
                            ?>
                                <tr>
                                    <form method="POST" id="form-invest-<?= $inv['id'] ?>">
                                        <?php csrfField(); ?>
                                        <input type="hidden" name="action" value="preteurs">
                                        <input type="hidden" name="sub_action" value="modifier">
                                        <input type="hidden" name="preteur_id" value="<?= $inv['id'] ?>">
                                        <input type="hidden" name="taux_interet_pret" value="0">
                                        <td class="align-middle"><i class="bi bi-person-circle text-success me-1"></i><?= e($inv['investisseur_nom']) ?></td>
                                        <td class="text-end">
                                            <input type="text" class="form-control form-control-sm money-input text-end bg-dark text-white border-secondary"
                                                   name="montant_pret" value="<?= number_format($inv['montant_calc'], 0, ',', ' ') ?>"
                                                   style="width: 100px; display: inline-block;">
                                        </td>
                                        <td class="text-center"><span class="badge bg-success"><?= number_format($pct, 1) ?>%</span></td>
                                        <td class="text-nowrap">
                                            <button type="submit" class="btn btn-outline-primary btn-sm py-0 px-1" title="Sauvegarder">
                                                <i class="bi bi-check"></i>
                                            </button>
                                    </form>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Supprimer?')">
                                                <?php csrfField(); ?>
                                                <input type="hidden" name="action" value="preteurs">
                                                <input type="hidden" name="sub_action" value="supprimer">
                                                <input type="hidden" name="preteur_id" value="<?= $inv['id'] ?>">
                                                <button type="submit" class="btn btn-outline-danger btn-sm py-0 px-1" title="Supprimer">
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
                <div class="card-footer" style="background: rgba(30, 58, 95, 0.6);">
                    <form method="POST" class="row g-2 align-items-end">
                        <?php csrfField(); ?>
                        <input type="hidden" name="action" value="preteurs">
                        <input type="hidden" name="sub_action" value="ajouter">
                        <input type="hidden" name="taux_interet_pret" value="0">
                        <div class="col-6">
                            <label class="form-label small mb-0 text-light">Personne</label>
                            <select class="form-select form-select-sm bg-dark text-white border-secondary" name="investisseur_id" required>
                                <option value="">Choisir...</option>
                                <?php foreach ($tousInvestisseurs as $inv): ?>
                                    <option value="<?= $inv['id'] ?>"><?= e($inv['nom']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-4">
                            <label class="form-label small mb-0 text-light">Mise $</label>
                            <input type="text" class="form-control form-control-sm money-input bg-dark text-white border-secondary" name="montant_pret" required placeholder="0">
                        </div>
                        <div class="col-2">
                            <button type="submit" class="btn btn-success btn-sm w-100"><i class="bi bi-plus-lg"></i></button>
                        </div>
                    </form>
                    <small class="text-muted">% calculé automatiquement selon la mise</small>
                </div>
            </div>

            <!-- Total investisseurs -->
            <div class="card bg-success text-white mb-4">
                <div class="card-body py-2">
                    <div class="d-flex justify-content-between">
                        <span>Total mises :</span>
                        <strong><?= formatMoney($totalInvest) ?></strong>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Lien pour ajouter des personnes -->
    <div class="text-center">
        <a href="<?= url('/admin/investisseurs/liste.php') ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-person-plus me-1"></i>Gérer la liste des personnes
        </a>
    </div>
    </div><!-- Fin TAB FINANCEMENT -->

    <!-- TAB BUDGETS -->
    <div class="tab-pane fade <?= $tab === 'budgets' ? 'show active' : '' ?>" id="budgets" role="tabpanel">
    <?php
    // Calculer le total depuis les postes existants (avec quantité de groupe)
    // et séparer les montants taxables des non-taxables
    $totalBudgetTab = 0;
    $totalTaxable = 0;
    $totalNonTaxable = 0;

    foreach ($projetPostes as $categorieId => $poste) {
        // Trouver le groupe de cette catégorie
        $groupePoste = $templatesBudgets[$categorieId]['groupe'] ?? 'autre';
        $qteGroupe = $projetGroupes[$groupePoste] ?? 1;
        $qteCat = (int)$poste['quantite'];

        // Calculer par item pour séparer taxable/non-taxable
        if (isset($templatesBudgets[$categorieId]['sous_categories'])) {
            foreach ($templatesBudgets[$categorieId]['sous_categories'] as $sc) {
                foreach ($sc['materiaux'] as $mat) {
                    if (isset($projetItems[$categorieId][$mat['id']])) {
                        $item = $projetItems[$categorieId][$mat['id']];
                        $prixItem = (float)$item['prix_unitaire'];
                        $qteItem = (int)($item['quantite'] ?? 1);
                        $sansTaxe = (int)($item['sans_taxe'] ?? 0);
                        $montantItem = $prixItem * $qteItem * $qteCat * $qteGroupe;

                        if ($sansTaxe) {
                            $totalNonTaxable += $montantItem;
                        } else {
                            $totalTaxable += $montantItem;
                        }
                    }
                }
            }
        }
    }

    $totalBudgetTab = $totalTaxable + $totalNonTaxable;
    $contingenceTab = $totalBudgetTab * ((float)$projet['taux_contingence'] / 100);

    // Contingence proportionnelle taxable
    $contingenceTaxable = $totalBudgetTab > 0 ? $contingenceTab * ($totalTaxable / $totalBudgetTab) : 0;

    // Base taxable = items taxables + portion taxable de la contingence
    $baseTaxable = $totalTaxable + $contingenceTaxable;
    $tpsTab = $baseTaxable * 0.05;
    $tvqTab = $baseTaxable * 0.09975;
    $grandTotalTab = $totalBudgetTab + $contingenceTab + $tpsTab + $tvqTab;
    ?>

    <!-- TOTAL EN HAUT - STICKY -->
    <div class="bg-primary text-white mb-3 sticky-top" style="top: 60px; z-index: 100; font-size: 0.85rem;">
        <!-- Rangée 1: Matériaux + Taxes + Total -->
        <div class="px-3 py-1 d-flex justify-content-end align-items-center">
            <span class="px-3 border-end">
                <span class="opacity-75">Matériaux:</span>
                <strong id="totalBudget"><?= formatMoney($totalBudgetTab) ?></strong>
            </span>
            <span class="px-3 border-end">
                <span class="opacity-75">TPS:</span>
                <strong id="totalTPS"><?= formatMoney($tpsTab) ?></strong>
            </span>
            <span class="px-3 border-end">
                <span class="opacity-75">TVQ:</span>
                <strong id="totalTVQ"><?= formatMoney($tvqTab) ?></strong>
            </span>
            <span class="px-3">
                <span class="opacity-75">Total:</span>
                <strong class="fs-5" id="grandTotal"><?= formatMoney($grandTotalTab) ?></strong>
            </span>
        </div>
        <!-- Rangée 2: Contingence -->
        <div class="px-3 py-1 d-flex justify-content-end align-items-center border-top border-light border-opacity-25">
            <span class="px-3">
                <span class="opacity-75">Contingence <?= $projet['taux_contingence'] ?>%:</span>
                <strong id="totalContingence"><?= formatMoney($contingenceTab) ?></strong>
            </span>
        </div>
    </div>

    <form method="POST" action="" id="formBudgetsDetail">
        <?php csrfField(); ?>
        <input type="hidden" name="action" value="postes_budgets">

        <?php if (empty($templatesBudgets)): ?>
        <div class="alert alert-warning">
            <i class="bi bi-exclamation-triangle me-2"></i>
            <strong>Templates non configurés.</strong>
            <a href="<?= url('/admin/templates/liste.php') ?>">Configurer les templates</a> ou exécuter les migrations SQL.
        </div>
        <?php else: ?>

        <?php
        // Calculer les totaux par groupe avec taxes
        $groupeTotaux = [];
        foreach ($templatesBudgets as $catId => $cat) {
            $groupe = $cat['groupe'];
            if (!isset($groupeTotaux[$groupe])) {
                $groupeTotaux[$groupe] = ['taxable' => 0, 'non_taxable' => 0];
            }

            if (isset($projetPostes[$catId])) {
                $posteG = $projetPostes[$catId];
                $qteCatG = (int)$posteG['quantite'];
                $qteGroupeG = $projetGroupes[$groupe] ?? 1;

                if (!empty($cat['sous_categories'])) {
                    foreach ($cat['sous_categories'] as $sc) {
                        foreach ($sc['materiaux'] as $mat) {
                            if (isset($projetItems[$catId][$mat['id']])) {
                                $item = $projetItems[$catId][$mat['id']];
                                $prixItem = (float)$item['prix_unitaire'];
                                $qteItem = (int)($item['quantite'] ?? 1);
                                $sansTaxe = (int)($item['sans_taxe'] ?? 0);
                                $montant = $prixItem * $qteItem * $qteCatG * $qteGroupeG;

                                if ($sansTaxe) {
                                    $groupeTotaux[$groupe]['non_taxable'] += $montant;
                                } else {
                                    $groupeTotaux[$groupe]['taxable'] += $montant;
                                }
                            }
                        }
                    }
                }
            }
        }

        // Calculer TPS/TVQ par groupe
        foreach ($groupeTotaux as $groupe => &$totaux) {
            $totaux['budget_ht'] = $totaux['taxable'] + $totaux['non_taxable'];
            $totaux['tps'] = $totaux['taxable'] * 0.05;
            $totaux['tvq'] = $totaux['taxable'] * 0.09975;
            $totaux['total_ttc'] = $totaux['budget_ht'] + $totaux['tps'] + $totaux['tvq'];
        }
        unset($totaux);
        ?>

        <div class="accordion" id="accordionBudgets">
        <?php
        $currentGroupe = '';
        foreach ($templatesBudgets as $catId => $cat):
            $isImported = isset($projetPostes[$catId]);
            $poste = $projetPostes[$catId] ?? null;
            $quantite = $poste ? (int)$poste['quantite'] : 1;
            $budgetExtrapole = $poste ? (float)$poste['budget_extrapole'] : 0;

            // Calculer le total des items cochés avec taxes (SANS quantité groupe)
            $totalItemsCalc = 0;
            $catTaxable = 0;
            $catNonTaxable = 0;

            if (!empty($cat['sous_categories'])) {
                foreach ($cat['sous_categories'] as $sc) {
                    foreach ($sc['materiaux'] as $mat) {
                        if (isset($projetItems[$catId][$mat['id']])) {
                            $item = $projetItems[$catId][$mat['id']];
                            $qteItem = (int)($item['quantite'] ?? 1);
                            // Montant SANS quantité groupe (sera multiplié au niveau groupe)
                            $montantItem = (float)$item['prix_unitaire'] * $qteItem * $quantite;
                            $totalItemsCalc += $montantItem;

                            if (!empty($item['sans_taxe'])) {
                                $catNonTaxable += $montantItem;
                            } else {
                                $catTaxable += $montantItem;
                            }
                        }
                    }
                }
            }
            $catBudgetHT = $catTaxable + $catNonTaxable;
            $catTPS = $catTaxable * 0.05;
            $catTVQ = $catTaxable * 0.09975;
            $catTotalTTC = $catBudgetHT + $catTPS + $catTVQ;

            // Groupe header
            if ($cat['groupe'] !== $currentGroupe):
                $currentGroupe = $cat['groupe'];
                $qteGroupe = $projetGroupes[$currentGroupe] ?? 1;
                $grpTotaux = $groupeTotaux[$currentGroupe] ?? ['budget_ht' => 0, 'tps' => 0, 'tvq' => 0, 'total_ttc' => 0];
        ?>
            <div class="bg-dark text-white px-3 py-2 mt-3 rounded-top d-flex justify-content-between align-items-center">
                <span><i class="bi bi-folder me-1"></i><?= $groupeLabels[$currentGroupe] ?? ucfirst($currentGroupe) ?></span>
                <div class="d-flex align-items-center">
                    <span class="me-2 small opacity-75">Qté:</span>
                    <div class="btn-group btn-group-sm">
                        <button type="button" class="btn btn-outline-light groupe-qte-minus" data-groupe="<?= $currentGroupe ?>">
                            <i class="bi bi-dash"></i>
                        </button>
                        <input type="number"
                               class="form-control form-control-sm text-center bg-dark text-white border-light groupe-qte-input"
                               name="groupes[<?= $currentGroupe ?>]"
                               value="<?= $qteGroupe ?>"
                               min="1" max="20"
                               data-groupe="<?= $currentGroupe ?>"
                               style="width: 50px;">
                        <button type="button" class="btn btn-outline-light groupe-qte-plus" data-groupe="<?= $currentGroupe ?>">
                            <i class="bi bi-plus"></i>
                        </button>
                    </div>
                </div>
            </div>
            <!-- Totaux du groupe avec taxes -->
            <div class="bg-secondary text-white px-3 py-1 d-flex justify-content-end align-items-center gap-3 groupe-totaux-bar" data-groupe="<?= $currentGroupe ?>" style="font-size: 0.8rem;">
                <span>
                    <span class="opacity-75">Matériaux:</span>
                    <strong class="groupe-budget-ht"><?= formatMoney($grpTotaux['budget_ht']) ?></strong>
                </span>
                <span>
                    <span class="opacity-75">TPS:</span>
                    <strong class="groupe-tps"><?= formatMoney($grpTotaux['tps']) ?></strong>
                </span>
                <span>
                    <span class="opacity-75">TVQ:</span>
                    <strong class="groupe-tvq"><?= formatMoney($grpTotaux['tvq']) ?></strong>
                </span>
                <span class="border-start ps-3">
                    <span class="opacity-75">Total:</span>
                    <strong class="groupe-total-ttc"><?= formatMoney($grpTotaux['total_ttc']) ?></strong>
                </span>
            </div>
        <?php endif; ?>

            <div class="accordion-item">
                <h2 class="accordion-header">
                    <div class="d-flex align-items-center w-100 px-3 py-2 bg-dark text-white">
                        <!-- Checkbox import -->
                        <div class="form-check me-2">
                            <input type="checkbox"
                                   class="form-check-input poste-checkbox"
                                   id="poste_<?= $catId ?>"
                                   name="postes[<?= $catId ?>][checked]"
                                   value="1"
                                   <?= $isImported ? 'checked' : '' ?>
                                   data-cat-id="<?= $catId ?>"
                                   data-groupe="<?= $cat['groupe'] ?>">
                        </div>

                        <!-- Nom catégorie (cliquable pour expand) -->
                        <button class="btn btn-link text-start flex-grow-1 text-decoration-none text-white p-0 collapsed"
                                type="button"
                                data-bs-toggle="collapse"
                                data-bs-target="#cat_<?= $catId ?>">
                            <i class="bi bi-caret-right-fill me-1 cat-chevron"></i>
                            <strong><?= e($cat['nom']) ?></strong>
                            <?php if (!empty($cat['sous_categories'])): ?>
                                <small class="text-white-50 ms-2">(<?= count($cat['sous_categories']) ?> sous-cat.)</small>
                            <?php endif; ?>
                        </button>

                        <!-- Quantité avec +/- -->
                        <div class="input-group input-group-sm me-1" style="width: 85px;">
                            <button type="button" class="btn btn-outline-secondary btn-sm qte-minus py-0 px-1" data-cat-id="<?= $catId ?>" <?= !$isImported ? 'disabled' : '' ?>>
                                <i class="bi bi-dash"></i>
                            </button>
                            <input type="number"
                                   class="form-control form-control-sm text-center qte-input px-0"
                                   name="postes[<?= $catId ?>][quantite]"
                                   value="<?= $quantite ?>"
                                   min="1"
                                   max="10"
                                   data-cat-id="<?= $catId ?>"
                                   style="max-width: 35px;"
                                   <?= !$isImported ? 'disabled' : '' ?>>
                            <button type="button" class="btn btn-outline-secondary btn-sm qte-plus py-0 px-1" data-cat-id="<?= $catId ?>" <?= !$isImported ? 'disabled' : '' ?>>
                                <i class="bi bi-plus"></i>
                            </button>
                        </div>

                        <!-- Budget extrapolé HT -->
                        <div class="input-group input-group-sm" style="width: 90px;">
                            <span class="input-group-text px-1">$</span>
                            <input type="text"
                                   class="form-control text-end budget-extrapole"
                                   name="postes[<?= $catId ?>][budget_extrapole]"
                                   value="<?= $budgetExtrapole > 0 ? formatMoney($budgetExtrapole, false) : ($totalItemsCalc > 0 ? formatMoney($totalItemsCalc, false) : '0') ?>"
                                   data-cat-id="<?= $catId ?>"
                                   data-calc="<?= $totalItemsCalc ?>"
                                   <?= !$isImported ? 'disabled' : '' ?>>
                        </div>
                        <!-- Total TTC - même taille que groupe -->
                        <span class="cat-total-ttc text-end fw-bold ms-2" style="font-size: 0.8rem; min-width: 85px;" data-cat-id="<?= $catId ?>"><?= formatMoney($catTotalTTC) ?></span>
                    </div>
                </h2>

                <div id="cat_<?= $catId ?>" class="accordion-collapse collapse" data-bs-parent="#accordionBudgets">
                    <div class="accordion-body p-2 bg-white">
                        <?php if (empty($cat['sous_categories'])): ?>
                            <p class="text-muted small mb-0">
                                Aucune sous-catégorie.
                                <a href="<?= url('/admin/templates/liste.php?categorie=' . $catId) ?>">Configurer</a>
                            </p>
                        <?php else: ?>
                            <?php foreach ($cat['sous_categories'] as $scId => $sc): ?>
                                <div class="mb-2">
                                    <div class="fw-bold small text-primary mb-1">
                                        <i class="bi bi-caret-right-fill me-1"></i><?= e($sc['nom']) ?>
                                    </div>
                                    <?php if (!empty($sc['materiaux'])): ?>
                                        <div class="ps-3">
                                            <?php foreach ($sc['materiaux'] as $mat):
                                                $isChecked = isset($projetItems[$catId][$mat['id']]);
                                                $prixItem = $isChecked ? (float)$projetItems[$catId][$mat['id']]['prix_unitaire'] : $mat['prix_defaut'];
                                                $qteItem = $isChecked ? (int)$projetItems[$catId][$mat['id']]['quantite'] : ($mat['quantite_defaut'] ?? 1);
                                                $sansTaxe = $isChecked ? (int)($projetItems[$catId][$mat['id']]['sans_taxe'] ?? 0) : 0;
                                                $totalItemHT = $prixItem * $qteItem * $quantite; // Inclure quantité catégorie
                                                // Total avec taxes si taxable
                                                $totalItem = $sansTaxe ? $totalItemHT : $totalItemHT * 1.14975;
                                            ?>
                                                <div class="d-flex align-items-center mb-1 item-row" data-cat-id="<?= $catId ?>" data-sans-taxe="<?= $sansTaxe ?>">
                                                    <div class="form-check me-2">
                                                        <input type="checkbox"
                                                               class="form-check-input item-checkbox"
                                                               name="items[<?= $catId ?>][<?= $mat['id'] ?>][checked]"
                                                               value="1"
                                                               <?= $isChecked ? 'checked' : '' ?>
                                                               data-cat-id="<?= $catId ?>"
                                                               data-prix="<?= $mat['prix_defaut'] ?>"
                                                               data-qte="<?= $mat['quantite_defaut'] ?? 1 ?>">
                                                    </div>
                                                    <input type="hidden" class="item-sans-taxe-input" name="items[<?= $catId ?>][<?= $mat['id'] ?>][sans_taxe]" value="<?= $sansTaxe ?>">
                                                    <span class="flex-grow-1 small"><?= e($mat['nom']) ?></span>
                                                    <div class="input-group input-group-sm me-1" style="width: 85px;">
                                                        <button type="button" class="btn btn-outline-secondary btn-sm item-qte-minus py-0 px-1" data-cat-id="<?= $catId ?>">
                                                            <i class="bi bi-dash"></i>
                                                        </button>
                                                        <input type="number"
                                                               class="form-control form-control-sm text-center item-qte px-0"
                                                               name="items[<?= $catId ?>][<?= $mat['id'] ?>][qte]"
                                                               value="<?= $qteItem ?>"
                                                               min="1"
                                                               style="max-width: 35px;"
                                                               data-cat-id="<?= $catId ?>">
                                                        <button type="button" class="btn btn-outline-secondary btn-sm item-qte-plus py-0 px-1" data-cat-id="<?= $catId ?>">
                                                            <i class="bi bi-plus"></i>
                                                        </button>
                                                    </div>
                                                    <div class="input-group input-group-sm" style="width: 90px;">
                                                        <span class="input-group-text px-1">$</span>
                                                        <input type="text"
                                                               class="form-control text-end item-prix"
                                                               name="items[<?= $catId ?>][<?= $mat['id'] ?>][prix]"
                                                               value="<?= formatMoney($prixItem, false) ?>"
                                                               data-cat-id="<?= $catId ?>">
                                                    </div>
                                                    <button type="button" class="btn btn-sm py-0 px-1 ms-1 item-sans-taxe btn-outline-danger <?= $sansTaxe ? 'active' : '' ?>" title="Sans taxe" data-cat-id="<?= $catId ?>" data-mat-id="<?= $mat['id'] ?>" style="font-size: 0.6rem; white-space: nowrap;">
                                                        Sans Tx
                                                    </button>
                                                    <span class="item-total text-end fw-bold small ms-1" style="width: 70px;" data-cat-id="<?= $catId ?>"><?= formatMoney($totalItem) ?></span>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <p class="text-muted small ps-3 mb-1">Aucun matériau</p>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

        <?php endforeach; ?>
        </div>

        <?php endif; ?>

        <div class="d-flex justify-content-between align-items-center mt-3">
            <a href="<?= url('/admin/templates/liste.php') ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-gear me-1"></i>Gérer les templates
            </a>
            <div id="saveStatus" class="text-muted small">
                <span id="saveIdle"><i class="bi bi-cloud-check me-1"></i>Sauvegarde auto</span>
                <span id="saveSaving" class="d-none"><i class="bi bi-arrow-repeat spin me-1"></i>Enregistrement...</span>
                <span id="saveSaved" class="d-none text-success"><i class="bi bi-check-circle me-1"></i>Enregistré!</span>
            </div>
        </div>
    </form>

    <style>
    .spin { animation: spin 1s linear infinite; }
    @keyframes spin { 100% { transform: rotate(360deg); } }
    </style>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const tauxContingence = <?= (float)$projet['taux_contingence'] ?>;
        const csrfToken = '<?= generateCSRFToken() ?>';
        let saveTimeout = null;

        function parseValue(str) {
            return parseFloat(String(str).replace(/\s/g, '').replace(',', '.')) || 0;
        }

        function formatMoney(val) {
            return val.toLocaleString('fr-CA', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' $';
        }

        // ========================================
        // AUTO-SAVE
        // ========================================
        function showSaveStatus(status) {
            document.getElementById('saveIdle').classList.add('d-none');
            document.getElementById('saveSaving').classList.add('d-none');
            document.getElementById('saveSaved').classList.add('d-none');
            document.getElementById('save' + status.charAt(0).toUpperCase() + status.slice(1)).classList.remove('d-none');
        }

        function autoSave() {
            if (saveTimeout) clearTimeout(saveTimeout);

            saveTimeout = setTimeout(function() {
                showSaveStatus('saving');
                console.log('Auto-save démarré...');

                const form = document.getElementById('formBudgetsDetail');
                const formData = new FormData(form);
                formData.set('ajax_action', 'save_budget');
                formData.set('csrf_token', csrfToken);

                // Debug: afficher les données envoyées
                for (let [key, value] of formData.entries()) {
                    if (key.includes('postes') || key.includes('items')) {
                        console.log(key + ': ' + value);
                    }
                }

                fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                })
                .then(response => {
                    console.log('Response status:', response.status);
                    return response.text();
                })
                .then(text => {
                    console.log('Response text:', text);
                    try {
                        const data = JSON.parse(text);
                        if (data.success) {
                            showSaveStatus('saved');
                            setTimeout(() => showSaveStatus('idle'), 2000);
                        } else {
                            console.error('Erreur:', data.error);
                            showSaveStatus('idle');
                        }
                    } catch(e) {
                        console.error('Parse error:', e, text);
                        showSaveStatus('idle');
                    }
                })
                .catch(error => {
                    console.error('Erreur réseau:', error);
                    showSaveStatus('idle');
                });
            }, 500); // Debounce 500ms
        }

        // ========================================
        // CALCULS
        // ========================================
        function updateTotals() {
            let totalTaxable = 0;
            let totalNonTaxable = 0;

            // Parcourir tous les items cochés pour séparer taxable/non-taxable
            document.querySelectorAll('.item-checkbox:checked').forEach(itemCheckbox => {
                const row = itemCheckbox.closest('.item-row');
                const catId = itemCheckbox.dataset.catId;
                const prixInput = row.querySelector('.item-prix');
                const qteInput = row.querySelector('.item-qte');
                const sansTaxeInput = row.querySelector('.item-sans-taxe-input');

                // Trouver la quantité de la catégorie
                const qteCatInput = document.querySelector(`.qte-input[data-cat-id="${catId}"]`);
                const qteCat = qteCatInput ? parseInt(qteCatInput.value) || 1 : 1;

                // Trouver la quantité du groupe
                const posteCheckbox = document.querySelector(`.poste-checkbox[data-cat-id="${catId}"]`);
                const groupe = posteCheckbox ? posteCheckbox.dataset.groupe : 'autre';
                const groupeQteInput = document.querySelector(`.groupe-qte-input[data-groupe="${groupe}"]`);
                const qteGroupe = groupeQteInput ? parseInt(groupeQteInput.value) || 1 : 1;

                if (prixInput && qteInput) {
                    const prix = parseValue(prixInput.value);
                    const qte = parseInt(qteInput.value) || 1;
                    const sansTaxe = sansTaxeInput ? parseInt(sansTaxeInput.value) || 0 : 0;
                    const montant = prix * qte * qteCat * qteGroupe;

                    if (sansTaxe) {
                        totalNonTaxable += montant;
                    } else {
                        totalTaxable += montant;
                    }
                }
            });

            const grandTotal = totalTaxable + totalNonTaxable;
            const contingence = grandTotal * (tauxContingence / 100);

            // Contingence proportionnelle taxable
            const contingenceTaxable = grandTotal > 0 ? contingence * (totalTaxable / grandTotal) : 0;

            // Base taxable = items taxables + portion taxable de la contingence
            const baseTaxable = totalTaxable + contingenceTaxable;
            const tps = baseTaxable * 0.05;
            const tvq = baseTaxable * 0.09975;
            const totalAvecTaxes = grandTotal + contingence + tps + tvq;

            document.getElementById('totalBudget').textContent = formatMoney(grandTotal);
            document.getElementById('totalContingence').textContent = formatMoney(contingence);
            document.getElementById('totalTPS').textContent = formatMoney(tps);
            document.getElementById('totalTVQ').textContent = formatMoney(tvq);
            document.getElementById('grandTotal').textContent = formatMoney(totalAvecTaxes);
        }

        function updateItemTotal(row) {
            const checkbox = row.querySelector('.item-checkbox');
            const prixInput = row.querySelector('.item-prix');
            const qteInput = row.querySelector('.item-qte');
            const totalSpan = row.querySelector('.item-total');
            const sansTaxeInput = row.querySelector('.item-sans-taxe-input');

            if (checkbox && prixInput && qteInput && totalSpan) {
                const prix = parseValue(prixInput.value);
                const qte = parseInt(qteInput.value) || 1;
                const catId = checkbox.dataset.catId;
                const qteCatInput = document.querySelector(`.qte-input[data-cat-id="${catId}"]`);
                const qteCat = qteCatInput ? parseInt(qteCatInput.value) || 1 : 1;
                const sansTaxe = sansTaxeInput ? parseInt(sansTaxeInput.value) || 0 : 0;
                const totalHT = prix * qte * qteCat;
                // Ajouter taxes si taxable (14.975% = TPS 5% + TVQ 9.975%)
                const total = sansTaxe ? totalHT : totalHT * 1.14975;
                totalSpan.textContent = formatMoney(total);
            }
        }

        function updateCategoryTotal(catId) {
            let total = 0;
            let catTaxable = 0;
            let catNonTaxable = 0;

            const qteInput = document.querySelector(`.qte-input[data-cat-id="${catId}"]`);
            const qteCat = qteInput ? parseInt(qteInput.value) || 1 : 1;

            // Trouver le groupe pour mise à jour ultérieure
            const posteCheckbox = document.querySelector(`.poste-checkbox[data-cat-id="${catId}"]`);
            const groupe = posteCheckbox ? posteCheckbox.dataset.groupe : 'autre';

            document.querySelectorAll(`.item-checkbox[data-cat-id="${catId}"]:checked`).forEach(checkbox => {
                const row = checkbox.closest('.item-row');
                const prixInput = row.querySelector('.item-prix');
                const qteItemInput = row.querySelector('.item-qte');
                const sansTaxeInput = row.querySelector('.item-sans-taxe-input');

                if (prixInput && qteItemInput) {
                    const prix = parseValue(prixInput.value);
                    const qteItem = parseInt(qteItemInput.value) || 1;
                    const sansTaxe = sansTaxeInput ? parseInt(sansTaxeInput.value) || 0 : 0;
                    // Montant SANS quantité groupe (sera multiplié au niveau groupe)
                    const montant = prix * qteItem * qteCat;

                    total += montant;

                    if (sansTaxe) {
                        catNonTaxable += montant;
                    } else {
                        catTaxable += montant;
                    }
                }
                updateItemTotal(row);
            });

            const budgetInput = document.querySelector(`.budget-extrapole[data-cat-id="${catId}"]`);
            if (budgetInput) {
                budgetInput.value = formatMoney(total);
            }

            // Mettre à jour les taxes de la catégorie
            const catBudgetHT = catTaxable + catNonTaxable;
            const catTPS = catTaxable * 0.05;
            const catTVQ = catTaxable * 0.09975;
            const catTotalTTC = catBudgetHT + catTPS + catTVQ;

            // Mettre à jour le total TTC de la catégorie
            const totalSpan = document.querySelector(`.cat-total-ttc[data-cat-id="${catId}"]`);
            if (totalSpan) totalSpan.textContent = formatMoney(catTotalTTC);

            updateTotals();

            // Mettre à jour aussi les totaux du groupe
            if (posteCheckbox) {
                updateGroupeTotauxBar(groupe);
            }
        }

        function updateAllItemTotals(catId) {
            document.querySelectorAll(`.item-row[data-cat-id="${catId}"]`).forEach(row => {
                updateItemTotal(row);
            });
        }

        // ========================================
        // BOUTONS +/- CATÉGORIE
        // ========================================
        document.querySelectorAll('.qte-minus').forEach(btn => {
            btn.addEventListener('click', function() {
                const catId = this.dataset.catId;
                const input = document.querySelector(`.qte-input[data-cat-id="${catId}"]`);
                if (input && !input.disabled) {
                    const val = parseInt(input.value) || 1;
                    if (val > 1) {
                        input.value = val - 1;
                        updateAllItemTotals(catId);
                        updateCategoryTotal(catId);
                        autoSave();
                    }
                }
            });
        });

        document.querySelectorAll('.qte-plus').forEach(btn => {
            btn.addEventListener('click', function() {
                const catId = this.dataset.catId;
                const input = document.querySelector(`.qte-input[data-cat-id="${catId}"]`);
                if (input && !input.disabled) {
                    const val = parseInt(input.value) || 1;
                    if (val < 10) {
                        input.value = val + 1;
                        updateAllItemTotals(catId);
                        updateCategoryTotal(catId);
                        autoSave();
                    }
                }
            });
        });

        // ========================================
        // BOUTONS +/- ITEMS
        // ========================================
        document.querySelectorAll('.item-qte-minus').forEach(btn => {
            btn.addEventListener('click', function() {
                const row = this.closest('.item-row');
                const input = row.querySelector('.item-qte');
                if (input) {
                    const val = parseInt(input.value) || 1;
                    if (val > 1) {
                        input.value = val - 1;
                        updateItemTotal(row);
                        updateCategoryTotal(this.dataset.catId);
                        autoSave();
                    }
                }
            });
        });

        document.querySelectorAll('.item-qte-plus').forEach(btn => {
            btn.addEventListener('click', function() {
                const row = this.closest('.item-row');
                const input = row.querySelector('.item-qte');
                if (input) {
                    const val = parseInt(input.value) || 1;
                    input.value = val + 1;
                    updateItemTotal(row);
                    updateCategoryTotal(this.dataset.catId);
                    autoSave();
                }
            });
        });

        // ========================================
        // BOUTONS SANS TAXE
        // ========================================
        document.querySelectorAll('.item-sans-taxe').forEach(btn => {
            btn.addEventListener('click', function() {
                const row = this.closest('.item-row');
                const input = row.querySelector('.item-sans-taxe-input');
                const catId = this.dataset.catId;

                if (input) {
                    // Toggle la valeur
                    const currentVal = parseInt(input.value) || 0;
                    const newVal = currentVal ? 0 : 1;
                    input.value = newVal;

                    // Toggle le style du bouton (active = sans taxe)
                    this.classList.toggle('active', newVal === 1);

                    // Mettre à jour le data attribute
                    row.dataset.sansTaxe = newVal;

                    // Mettre à jour le total de l'item (avec/sans taxes)
                    updateItemTotal(row);

                    // Recalculer les totaux
                    updateCategoryTotal(catId);
                    autoSave();
                }
            });
        });

        // ========================================
        // BOUTONS +/- GROUPES
        // ========================================
        document.querySelectorAll('.groupe-qte-minus').forEach(btn => {
            btn.addEventListener('click', function() {
                const groupe = this.dataset.groupe;
                const input = document.querySelector(`.groupe-qte-input[data-groupe="${groupe}"]`);
                if (input) {
                    const val = parseInt(input.value) || 1;
                    if (val > 1) {
                        input.value = val - 1;
                        updateGroupeTotals(groupe);
                        autoSave();
                    }
                }
            });
        });

        document.querySelectorAll('.groupe-qte-plus').forEach(btn => {
            btn.addEventListener('click', function() {
                const groupe = this.dataset.groupe;
                const input = document.querySelector(`.groupe-qte-input[data-groupe="${groupe}"]`);
                if (input) {
                    const val = parseInt(input.value) || 1;
                    if (val < 20) {
                        input.value = val + 1;
                        updateGroupeTotals(groupe);
                        autoSave();
                    }
                }
            });
        });

        document.querySelectorAll('.groupe-qte-input').forEach(input => {
            input.addEventListener('change', function() {
                updateGroupeTotals(this.dataset.groupe);
                autoSave();
            });
        });

        function updateGroupeTotals(groupe) {
            // Recalculer tous les totaux des catégories de ce groupe
            document.querySelectorAll(`.poste-checkbox:checked`).forEach(checkbox => {
                const catId = checkbox.dataset.catId;
                updateCategoryTotal(catId);
            });

            // Mettre à jour la barre de totaux du groupe
            updateGroupeTotauxBar(groupe);
        }

        function updateGroupeTotauxBar(groupe) {
            const bar = document.querySelector(`.groupe-totaux-bar[data-groupe="${groupe}"]`);
            if (!bar) return;

            let totalTaxable = 0;
            let totalNonTaxable = 0;

            // Parcourir les items cochés de ce groupe
            document.querySelectorAll(`.poste-checkbox[data-groupe="${groupe}"]:checked`).forEach(posteCheckbox => {
                const catId = posteCheckbox.dataset.catId;
                const qteCatInput = document.querySelector(`.qte-input[data-cat-id="${catId}"]`);
                const qteCat = qteCatInput ? parseInt(qteCatInput.value) || 1 : 1;
                const groupeQteInput = document.querySelector(`.groupe-qte-input[data-groupe="${groupe}"]`);
                const qteGroupe = groupeQteInput ? parseInt(groupeQteInput.value) || 1 : 1;

                document.querySelectorAll(`.item-checkbox[data-cat-id="${catId}"]:checked`).forEach(itemCheckbox => {
                    const row = itemCheckbox.closest('.item-row');
                    const prixInput = row.querySelector('.item-prix');
                    const qteInput = row.querySelector('.item-qte');
                    const sansTaxeInput = row.querySelector('.item-sans-taxe-input');

                    if (prixInput && qteInput) {
                        const prix = parseValue(prixInput.value);
                        const qte = parseInt(qteInput.value) || 1;
                        const sansTaxe = sansTaxeInput ? parseInt(sansTaxeInput.value) || 0 : 0;
                        const montant = prix * qte * qteCat * qteGroupe;

                        if (sansTaxe) {
                            totalNonTaxable += montant;
                        } else {
                            totalTaxable += montant;
                        }
                    }
                });
            });

            const budgetHT = totalTaxable + totalNonTaxable;
            const tps = totalTaxable * 0.05;
            const tvq = totalTaxable * 0.09975;
            const totalTTC = budgetHT + tps + tvq;

            bar.querySelector('.groupe-budget-ht').textContent = formatMoney(budgetHT);
            bar.querySelector('.groupe-tps').textContent = formatMoney(tps);
            bar.querySelector('.groupe-tvq').textContent = formatMoney(tvq);
            bar.querySelector('.groupe-total-ttc').textContent = formatMoney(totalTTC);
        }

        function updateAllGroupeTotaux() {
            document.querySelectorAll('.groupe-totaux-bar').forEach(bar => {
                updateGroupeTotauxBar(bar.dataset.groupe);
            });
        }

        // ========================================
        // EVENTS: CHECKBOX CATÉGORIE
        // ========================================
        document.querySelectorAll('.poste-checkbox').forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                const catId = this.dataset.catId;
                const qteInput = document.querySelector(`.qte-input[data-cat-id="${catId}"]`);
                const budgetInput = document.querySelector(`.budget-extrapole[data-cat-id="${catId}"]`);
                const minusBtn = document.querySelector(`.qte-minus[data-cat-id="${catId}"]`);
                const plusBtn = document.querySelector(`.qte-plus[data-cat-id="${catId}"]`);

                if (this.checked) {
                    if (qteInput) qteInput.disabled = false;
                    if (budgetInput) budgetInput.disabled = false;
                    if (minusBtn) minusBtn.disabled = false;
                    if (plusBtn) plusBtn.disabled = false;
                } else {
                    if (qteInput) qteInput.disabled = true;
                    if (budgetInput) budgetInput.disabled = true;
                    if (minusBtn) minusBtn.disabled = true;
                    if (plusBtn) plusBtn.disabled = true;
                }

                updateTotals();
                autoSave();
            });
        });

        // ========================================
        // EVENTS: CHECKBOX ITEM
        // ========================================
        document.querySelectorAll('.item-checkbox').forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                const catId = this.dataset.catId;

                // Si on coche un item, cocher automatiquement la catégorie
                if (this.checked) {
                    const posteCheckbox = document.getElementById('poste_' + catId);
                    if (posteCheckbox && !posteCheckbox.checked) {
                        posteCheckbox.checked = true;
                        // Activer les inputs de la catégorie
                        const qteInput = document.querySelector(`.qte-input[data-cat-id="${catId}"]`);
                        const budgetInput = document.querySelector(`.budget-extrapole[data-cat-id="${catId}"]`);
                        const minusBtn = document.querySelector(`.qte-minus[data-cat-id="${catId}"]`);
                        const plusBtn = document.querySelector(`.qte-plus[data-cat-id="${catId}"]`);
                        if (qteInput) qteInput.disabled = false;
                        if (budgetInput) budgetInput.disabled = false;
                        if (minusBtn) minusBtn.disabled = false;
                        if (plusBtn) plusBtn.disabled = false;
                    }
                }

                updateCategoryTotal(catId);
                autoSave();
            });
        });

        // ========================================
        // EVENTS: PRIX ITEM
        // ========================================
        document.querySelectorAll('.item-prix').forEach(input => {
            input.addEventListener('change', function() {
                const row = this.closest('.item-row');
                if (row) updateItemTotal(row);
                updateCategoryTotal(this.dataset.catId);
                autoSave();
            });
        });

        // ========================================
        // EVENTS: QUANTITÉ ITEM
        // ========================================
        document.querySelectorAll('.item-qte').forEach(input => {
            input.addEventListener('change', function() {
                const row = this.closest('.item-row');
                if (row) updateItemTotal(row);
                updateCategoryTotal(this.dataset.catId);
                autoSave();
            });
        });

        // ========================================
        // EVENTS: QUANTITÉ CATÉGORIE
        // ========================================
        document.querySelectorAll('.qte-input').forEach(input => {
            input.addEventListener('change', function() {
                const catId = this.dataset.catId;
                updateAllItemTotals(catId);
                updateCategoryTotal(catId);
                autoSave();
            });
        });

        // ========================================
        // EVENTS: BUDGET EXTRAPOLÉ MANUEL
        // ========================================
        document.querySelectorAll('.budget-extrapole').forEach(input => {
            input.addEventListener('change', function() {
                updateTotals();
                autoSave();
            });
        });
    });
    </script>
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
        ?>

        <!-- Barre compacte : Stats -->
        <div class="d-flex flex-wrap align-items-center gap-2 mb-3 p-2 rounded" style="background: rgba(255,255,255,0.03);">
            <!-- Total heures -->
            <div class="d-flex align-items-center px-3 py-1 rounded" style="background: rgba(13,110,253,0.15);">
                <i class="bi bi-clock text-primary me-2"></i>
                <span class="text-muted me-1">Heures:</span>
                <strong class="text-primary"><?= number_format($totalHeuresTab, 1) ?> h</strong>
            </div>

            <!-- Coût total -->
            <div class="d-flex align-items-center px-3 py-1 rounded" style="background: rgba(25,135,84,0.15);">
                <i class="bi bi-cash text-success me-2"></i>
                <span class="text-muted me-1">Coût:</span>
                <strong class="text-success"><?= formatMoney($totalCoutTab) ?></strong>
            </div>

            <!-- Spacer + Badge à droite -->
            <div class="ms-auto">
                <span class="badge bg-secondary"><?= count($heuresProjet) ?> entrées</span>
            </div>
        </div>

        <?php if (empty($heuresProjet)): ?>
            <div class="alert alert-info">
                <i class="bi bi-info-circle me-2"></i>Aucune heure enregistrée pour ce projet.
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover">
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
    </div><!-- Fin TAB TEMPS -->

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
                <?php endif; ?>
                <button type="button" class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#modalAjoutPhoto">
                    <i class="bi bi-plus me-1"></i>Ajouter
                </button>
            </div>
        </div>

        <?php if (empty($photosProjet)): ?>
            <div class="alert alert-info">
                <i class="bi bi-info-circle me-2"></i>Aucune photo pour ce projet. Cliquez sur "Ajouter" pour en téléverser.
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
        <?php else: ?>
            <div class="row g-2" id="photosGrid">
                <?php foreach ($photosProjet as $photo):
                    $extension = strtolower(pathinfo($photo['fichier'], PATHINFO_EXTENSION));
                    $isVideo = in_array($extension, ['mp4', 'mov', 'avi', 'mkv', 'webm', 'm4v']);
                    $mediaUrl = url('/serve-photo.php?file=' . urlencode($photo['fichier']));
                ?>
                <div class="col-6 col-md-3 col-lg-2 photo-grid-col photo-item" data-id="<?= $photo['id'] ?>" data-employe="<?= e($photo['employe_nom']) ?>" data-categorie="<?= e($photo['description'] ?? '') ?>">
                    <div class="position-relative">
                        <a href="<?= $mediaUrl ?>" target="_blank" class="d-block">
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
                        <form method="POST" class="position-absolute top-0 end-0" style="margin:3px;">
                            <?php csrfField(); ?>
                            <input type="hidden" name="action" value="delete_photo">
                            <input type="hidden" name="photo_id" value="<?= $photo['id'] ?>">
                            <button type="submit" class="btn btn-danger btn-sm" style="padding:2px 5px;font-size:10px;line-height:1;"
                                    onclick="return confirm('Supprimer cette photo ?')">
                                <i class="bi bi-trash"></i>
                            </button>
                        </form>
                        <div class="mt-1">
                            <small class="text-muted d-block"><?= formatDate($photo['date_prise']) ?></small>
                            <small class="text-muted"><?= e($photo['employe_nom']) ?></small>
                            <?php if (!empty($photo['description'])): ?>
                                <span class="badge bg-secondary ms-1" style="font-size:0.65rem;"><?= e($photo['description']) ?></span>
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
        sort($facturesCategories);
        ?>

        <!-- Barre compacte : Total + Filtres + Actions -->
        <div class="d-flex flex-wrap align-items-center gap-2 mb-3 p-2 rounded" style="background: rgba(255,255,255,0.03);">
            <!-- Total -->
            <div class="d-flex align-items-center px-3 py-1 rounded" style="background: rgba(220,53,69,0.15);">
                <i class="bi bi-receipt text-danger me-2"></i>
                <span class="text-muted me-2">Total:</span>
                <strong class="text-danger" id="facturesTotal"><?= formatMoney($totalFacturesTab) ?></strong>
            </div>

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
            <div class="table-responsive">
                <table class="table table-sm table-hover" id="facturesTable">
                    <thead class="table-dark">
                        <tr>
                            <th>Date</th>
                            <th>Fournisseur</th>
                            <th>Catégorie</th>
                            <th class="text-end">Montant</th>
                            <th>Statut</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($facturesProjet as $f): ?>
                        <tr class="facture-row" data-statut="<?= e($f['statut']) ?>" data-categorie="<?= e($f['categorie_nom'] ?? '') ?>" data-montant="<?= $f['montant_total'] ?>">
                            <td><?= formatDate($f['date_facture']) ?></td>
                            <td><?= e($f['fournisseur'] ?? 'N/A') ?></td>
                            <td><?= e($f['categorie_nom'] ?? 'N/A') ?></td>
                            <td class="text-end fw-bold"><?= formatMoney($f['montant_total']) ?></td>
                            <td>
                                <?php
                                $statusClass = match($f['statut']) {
                                    'approuvee' => 'bg-success',
                                    'rejetee' => 'bg-danger',
                                    default => 'bg-warning'
                                };
                                ?>
                                <span class="badge <?= $statusClass ?>"><?= getStatutFactureLabel($f['statut']) ?></span>
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
        ?>

        <div class="row">
            <!-- Checklists -->
            <div class="col-lg-7">
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
                                            <button class="accordion-button <?= $idx > 0 ? 'collapsed' : '' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#checklist<?= $tpl['id'] ?>">
                                                <span class="me-auto"><?= e($tpl['nom']) ?></span>
                                                <span class="badge <?= $pctComplete == 100 ? 'bg-success' : 'bg-secondary' ?> me-2"><?= $completedItems ?>/<?= $totalItems ?></span>
                                            </button>
                                        </h2>
                                        <div id="checklist<?= $tpl['id'] ?>" class="accordion-collapse collapse <?= $idx == 0 ? 'show' : '' ?>">
                                            <div class="accordion-body p-0">
                                                <?php if (empty($tpl['items'])): ?>
                                                    <p class="text-muted small p-3 mb-0">Aucun item dans cette checklist.</p>
                                                <?php else: ?>
                                                    <ul class="list-group list-group-flush">
                                                        <?php foreach ($tpl['items'] as $item): ?>
                                                            <?php
                                                            $isComplete = !empty($projetChecklists[$item['id']]['complete']);
                                                            $completeDate = $projetChecklists[$item['id']]['complete_date'] ?? null;
                                                            ?>
                                                            <li class="list-group-item d-flex align-items-center">
                                                                <div class="form-check flex-grow-1">
                                                                    <input class="form-check-input checklist-item" type="checkbox"
                                                                           id="item<?= $item['id'] ?>"
                                                                           data-item-id="<?= $item['id'] ?>"
                                                                           <?= $isComplete ? 'checked' : '' ?>>
                                                                    <label class="form-check-label <?= $isComplete ? 'text-decoration-line-through text-muted' : '' ?>" for="item<?= $item['id'] ?>">
                                                                        <?= e($item['nom']) ?>
                                                                    </label>
                                                                </div>
                                                                <?php if ($isComplete && $completeDate): ?>
                                                                    <small class="text-muted"><?= date('d/m/Y', strtotime($completeDate)) ?></small>
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

            <!-- Documents -->
            <div class="col-lg-5">
                <div class="card mb-4">
                    <div class="card-header">
                        <i class="bi bi-folder me-2"></i>Documents
                    </div>
                    <div class="card-body">
                        <!-- Upload form -->
                        <form id="documentUploadForm" enctype="multipart/form-data" class="mb-3">
                            <?php csrfField(); ?>
                            <input type="hidden" name="action" value="upload_document">
                            <input type="hidden" name="projet_id" value="<?= $projetId ?>">
                            <div class="input-group">
                                <input type="file" class="form-control form-control-sm" name="document" id="documentFile" required>
                                <button type="submit" class="btn btn-sm btn-primary">
                                    <i class="bi bi-upload me-1"></i>Uploader
                                </button>
                            </div>
                            <small class="text-muted">PDF, Word, Excel, Images (max 10 Mo)</small>
                        </form>

                        <!-- Documents list -->
                        <?php if (empty($projetDocuments)): ?>
                            <div class="text-center text-muted py-3">
                                <i class="bi bi-folder" style="font-size: 2rem;"></i>
                                <p class="mb-0 small">Aucun document</p>
                            </div>
                        <?php else: ?>
                            <ul class="list-group list-group-flush" id="documentsList">
                                <?php foreach ($projetDocuments as $doc): ?>
                                    <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                                        <div>
                                            <i class="bi bi-file-earmark me-2"></i>
                                            <a href="<?= url('/uploads/documents/' . $doc['fichier']) ?>" target="_blank"><?= e($doc['nom']) ?></a>
                                            <br><small class="text-muted"><?= date('d/m/Y', strtotime($doc['uploaded_at'])) ?> - <?= round($doc['taille'] / 1024) ?> Ko</small>
                                        </div>
                                        <button type="button" class="btn btn-sm btn-outline-danger delete-document" data-doc-id="<?= $doc['id'] ?>">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
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

                fetch('<?= url('/admin/projets/detail.php?id=' . $projetId) ?>', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: `ajax_action=toggle_checklist&item_id=${itemId}&complete=${isComplete ? 1 : 0}&csrf_token=<?= generateCSRFToken() ?>`
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        label.classList.toggle('text-decoration-line-through', isComplete);
                        label.classList.toggle('text-muted', isComplete);
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

        // Document upload
        document.getElementById('documentUploadForm')?.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            formData.append('ajax_action', 'upload_document');

            fetch('<?= url('/admin/projets/detail.php?id=' . $projetId) ?>', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    window.location.href = '<?= url('/admin/projets/detail.php?id=' . $projetId . '&tab=checklist') ?>';
                } else {
                    alert(data.error || 'Erreur lors de l\'upload');
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
                        this.closest('li').remove();
                    }
                });
            });
        });
        </script>
    </div><!-- Fin TAB CHECKLIST -->

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
        refreshTimer = setTimeout(doRefresh, refreshInterval);
    }

    // Démarrer le refresh
    scheduleRefresh();

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
    const factures = document.querySelectorAll('.facture-row');
    let count = 0;
    let total = 0;

    factures.forEach(row => {
        const rowStatut = row.dataset.statut;
        const rowCategorie = row.dataset.categorie;
        const rowMontant = parseFloat(row.dataset.montant) || 0;

        const matchStatut = !statut || rowStatut === statut;
        const matchCategorie = !categorie || rowCategorie === categorie;

        if (matchStatut && matchCategorie) {
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
    filtrerFactures();
}

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
<?php include '../../includes/footer.php'; ?>
