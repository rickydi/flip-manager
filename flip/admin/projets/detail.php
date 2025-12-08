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
            $cession = parseNumber($_POST['cession'] ?? 0);
            $notaire = parseNumber($_POST['notaire'] ?? 0);
            $taxeMutation = parseNumber($_POST['taxe_mutation'] ?? 0);
            $arpenteurs = parseNumber($_POST['arpenteurs'] ?? 0);
            $assuranceTitre = parseNumber($_POST['assurance_titre'] ?? 0);

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

            if (empty($nom)) $errors[] = 'Le nom du projet est requis.';
            if (empty($adresse)) $errors[] = 'L\'adresse est requise.';
            if (empty($ville)) $errors[] = 'La ville est requise.';

            if (empty($errors)) {
                $stmt = $pdo->prepare("
                    UPDATE projets SET
                        nom = ?, adresse = ?, ville = ?, code_postal = ?,
                        date_acquisition = ?, date_debut_travaux = ?, date_fin_prevue = ?, date_vente = ?,
                        statut = ?, prix_achat = ?, cession = ?, notaire = ?, taxe_mutation = ?,
                        arpenteurs = ?, assurance_titre = ?,
                        taxes_municipales_annuel = ?, taxes_scolaires_annuel = ?,
                        electricite_annuel = ?, assurances_annuel = ?,
                        deneigement_annuel = ?, frais_condo_annuel = ?,
                        hypotheque_mensuel = ?, loyer_mensuel = ?,
                        temps_assume_mois = ?, valeur_potentielle = ?,
                        taux_commission = ?, taux_contingence = ?,
                        taux_interet = ?, montant_pret = ?, notes = ?
                    WHERE id = ?
                ");

                $stmt->execute([
                    $nom, $adresse, $ville, $codePostal,
                    $dateAcquisition, $dateDebutTravaux, $dateFinPrevue, $dateVente,
                    $statut, $prixAchat, $cession, $notaire, $taxeMutation,
                    $arpenteurs, $assuranceTitre,
                    $taxesMunicipalesAnnuel, $taxesScolairesAnnuel,
                    $electriciteAnnuel, $assurancesAnnuel,
                    $deneigementAnnuel, $fraisCondoAnnuel,
                    $hypothequeMensuel, $loyerMensuel,
                    $tempsAssumeMois, $valeurPotentielle,
                    $tauxCommission, $tauxContingence,
                    $tauxInteret, $montantPret, $notes,
                    $projetId
                ]);

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

// Durée réelle (cohérent avec calculs.php)
$dureeReelle = (int)$projet['temps_assume_mois'];
if (!empty($projet['date_vente']) && !empty($projet['date_acquisition'])) {
    $dateAchat = new DateTime($projet['date_acquisition']);
    $dateVente = new DateTime($projet['date_vente']);
    $diff = $dateAchat->diff($dateVente);
    $dureeReelle = ($diff->y * 12) + $diff->m;
    if ((int)$dateVente->format('d') > (int)$dateAchat->format('d')) {
        $dureeReelle++;
    }
    $dureeReelle = max(1, $dureeReelle);
}

$categories = getCategories($pdo);
$budgets = getBudgetsParCategorie($pdo, $projetId);
$depenses = calculerDepensesParCategorie($pdo, $projetId);

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
</style>

<div class="container-fluid">
    <!-- En-tête -->
    <div class="page-header">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
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
    <ul class="nav nav-tabs mb-3" id="projetTabs" role="tablist">
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

    <div class="tab-content" id="projetTabsContent">
    <!-- TAB BASE -->
    <div class="tab-pane fade <?= $tab === 'base' ? 'show active' : '' ?>" id="base" role="tabpanel">

    <!-- Indicateurs en haut -->
    <div class="row g-2 mb-3">
        <div class="col-6 col-md-3">
            <div class="card text-center p-2 bg-primary bg-opacity-10">
                <small class="text-muted">Valeur potentielle</small>
                <strong class="fs-5 text-primary"><?= formatMoney($indicateurs['valeur_potentielle']) ?></strong>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card text-center p-2 bg-warning bg-opacity-10">
                <small class="text-muted">Équité Budget</small>
                <strong class="fs-5 text-warning"><?= formatMoney($indicateurs['equite_potentielle']) ?></strong>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card text-center p-2 bg-success bg-opacity-10">
                <small class="text-muted">Équité Réelle</small>
                <strong class="fs-5 text-success"><?= formatMoney($indicateurs['equite_reelle']) ?></strong>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card text-center p-2">
                <small class="text-muted">ROI Leverage</small>
                <strong class="fs-5"><?= formatPercent($indicateurs['roi_leverage']) ?></strong>
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
    </style>
    <form method="POST" action="" class="compact-form">
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
                                <input type="date" class="form-control" name="date_acquisition" value="<?= e($projet['date_acquisition']) ?>">
                            </div>
                            <div class="col-3">
                                <label class="form-label">Début trav.</label>
                                <input type="date" class="form-control" name="date_debut_travaux" value="<?= e($projet['date_debut_travaux']) ?>">
                            </div>
                            <div class="col-3">
                                <label class="form-label">Fin travaux</label>
                                <input type="date" class="form-control" name="date_fin_prevue" value="<?= e($projet['date_fin_prevue']) ?>">
                            </div>
                            <div class="col-3">
                                <label class="form-label">Vendu</label>
                                <input type="date" class="form-control" name="date_vente" value="<?= e($projet['date_vente'] ?? '') ?>">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header"><i class="bi bi-currency-dollar me-1"></i>Achat</div>
                    <div class="card-body">
                        <div class="row g-2">
                            <div class="col-4">
                                <label class="form-label">Prix achat</label>
                                <div class="input-group"><span class="input-group-text">$</span>
                                    <input type="text" class="form-control money-input" name="prix_achat" value="<?= formatMoney($projet['prix_achat'], false) ?>">
                                </div>
                            </div>
                            <div class="col-4">
                                <label class="form-label">Valeur pot.</label>
                                <div class="input-group"><span class="input-group-text">$</span>
                                    <input type="text" class="form-control money-input" name="valeur_potentielle" value="<?= formatMoney($projet['valeur_potentielle'], false) ?>">
                                </div>
                            </div>
                            <div class="col-4">
                                <label class="form-label">Durée (mois)</label>
                                <input type="number" class="form-control bg-light" name="temps_assume_mois" id="duree_mois" value="<?= (int)$projet['temps_assume_mois'] ?>" readonly>
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
                                <label class="form-label">Mutation</label>
                                <div class="input-group"><span class="input-group-text">$</span>
                                    <input type="text" class="form-control money-input" name="taxe_mutation" value="<?= formatMoney($projet['taxe_mutation'], false) ?>">
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
                        </div>
                    </div>
                </div>
            </div>

            <!-- Colonne droite -->
            <div class="col-lg-6 d-flex flex-column gap-3">
                <div class="card flex-grow-1">
                    <div class="card-header"><i class="bi bi-arrow-repeat me-1"></i>Récurrents</div>
                    <div class="card-body">
                        <div class="row g-2">
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
                        </div>
                    </div>
                </div>

                <?php
                // Calcul commission courtier avec taxes
                $commHT = (float)$projet['valeur_potentielle'] * ((float)$projet['taux_commission'] / 100);
                $commTPS = $commHT * 0.05;
                $commTVQ = $commHT * 0.09975;
                $commTTC = $commHT + $commTPS + $commTVQ;
                // Calcul contingence
                $totalBudgetBase = 0;
                foreach ($categoriesAvecBudget as $cat) {
                    $totalBudgetBase += (float)$cat['montant_extrapole'];
                }
                $contingenceBase = $totalBudgetBase * ((float)$projet['taux_contingence'] / 100);
                ?>
                <div class="card h-100">
                    <div class="card-header"><i class="bi bi-percent me-1"></i>Taux</div>
                    <div class="card-body p-2">
                        <table class="table table-sm table-borderless mb-0">
                            <tr>
                                <td style="width:40%"><label class="form-label mb-0">Courtier</label></td>
                                <td style="width:30%">
                                    <div class="input-group input-group-sm">
                                        <input type="number" class="form-control" name="taux_commission" id="taux_commission" step="0.01" value="<?= $projet['taux_commission'] ?>">
                                        <span class="input-group-text">%</span>
                                    </div>
                                </td>
                                <td class="text-end fw-bold"><?= formatMoney($commTTC) ?></td>
                            </tr>
                            <tr>
                                <td><label class="form-label mb-0">Contingence</label></td>
                                <td>
                                    <div class="input-group input-group-sm">
                                        <input type="number" class="form-control" name="taux_contingence" id="taux_contingence" step="0.01" value="<?= $projet['taux_contingence'] ?>">
                                        <span class="input-group-text">%</span>
                                    </div>
                                </td>
                                <td class="text-end fw-bold"><?= formatMoney($contingenceBase) ?></td>
                            </tr>
                        </table>
                        <input type="hidden" name="notes" value="<?= e($projet['notes']) ?>">
                    </div>
                </div>
            </div>
        </div>

        <div class="text-end mt-2">
            <button type="submit" class="btn btn-success">
                <i class="bi bi-check-circle me-1"></i>Enregistrer
            </button>
        </div>
    </form>

    <!-- GRAPHIQUES -->
    <div class="row g-2 mb-3 mt-3">
        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-header py-1 text-center small">Coûts vs Valeur</div>
                <div class="card-body p-2"><canvas id="chartCouts" height="150"></canvas></div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-header py-1 text-center small">Heures travaillées</div>
                <div class="card-body p-2"><canvas id="chartBudget" height="150"></canvas></div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-header py-1 text-center small">Budget vs Dépensé</div>
                <div class="card-body p-2"><canvas id="chartProfits" height="150"></canvas></div>
            </div>
        </div>
    </div>
    
    <!-- TABLEAU UNIFIÉ : EXTRAPOLÉ | DIFF | RÉEL -->
    <div class="card">
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
                        <td>Taxe mutation</td>
                        <td class="text-end"><?= formatMoney($indicateurs['couts_acquisition']['taxe_mutation']) ?></td>
                        <td class="text-end">-</td>
                        <td class="text-end"><?= formatMoney($indicateurs['couts_acquisition']['taxe_mutation']) ?></td>
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
                    <tr class="total-row">
                        <td>Sous-total Acquisition</td>
                        <td class="text-end"><?= formatMoney($indicateurs['couts_acquisition']['total']) ?></td>
                        <td class="text-end">-</td>
                        <td class="text-end"><?= formatMoney($indicateurs['couts_acquisition']['total']) ?></td>
                    </tr>
                    
                    <!-- COÛTS RÉCURRENTS -->
                    <tr class="section-header" data-section="recurrents">
                        <td colspan="4"><i class="bi bi-arrow-repeat me-1"></i> Récurrents (<?= $dureeReelle ?> mois) <i class="bi bi-chevron-down toggle-icon"></i></td>
                    </tr>
                    <tr class="sub-item">
                        <td>Taxes municipales</td>
                        <td class="text-end"><?= formatMoney($indicateurs['couts_recurrents']['taxes_municipales']['extrapole']) ?></td>
                        <td class="text-end">-</td>
                        <td class="text-end"><?= formatMoney($indicateurs['couts_recurrents']['taxes_municipales']['extrapole']) ?></td>
                    </tr>
                    <tr class="sub-item">
                        <td>Taxes scolaires</td>
                        <td class="text-end"><?= formatMoney($indicateurs['couts_recurrents']['taxes_scolaires']['extrapole']) ?></td>
                        <td class="text-end">-</td>
                        <td class="text-end"><?= formatMoney($indicateurs['couts_recurrents']['taxes_scolaires']['extrapole']) ?></td>
                    </tr>
                    <tr class="sub-item">
                        <td>Électricité</td>
                        <td class="text-end"><?= formatMoney($indicateurs['couts_recurrents']['electricite']['extrapole']) ?></td>
                        <td class="text-end">-</td>
                        <td class="text-end"><?= formatMoney($indicateurs['couts_recurrents']['electricite']['extrapole']) ?></td>
                    </tr>
                    <tr class="sub-item">
                        <td>Assurances</td>
                        <td class="text-end"><?= formatMoney($indicateurs['couts_recurrents']['assurances']['extrapole']) ?></td>
                        <td class="text-end">-</td>
                        <td class="text-end"><?= formatMoney($indicateurs['couts_recurrents']['assurances']['extrapole']) ?></td>
                    </tr>
                    <tr class="sub-item">
                        <td>Déneigement</td>
                        <td class="text-end"><?= formatMoney($indicateurs['couts_recurrents']['deneigement']['extrapole']) ?></td>
                        <td class="text-end">-</td>
                        <td class="text-end"><?= formatMoney($indicateurs['couts_recurrents']['deneigement']['extrapole']) ?></td>
                    </tr>
                    <tr class="sub-item">
                        <td>Frais condo</td>
                        <td class="text-end"><?= formatMoney($indicateurs['couts_recurrents']['frais_condo']['extrapole']) ?></td>
                        <td class="text-end">-</td>
                        <td class="text-end"><?= formatMoney($indicateurs['couts_recurrents']['frais_condo']['extrapole']) ?></td>
                    </tr>
                    <tr class="sub-item">
                        <td>Hypothèque</td>
                        <td class="text-end"><?= formatMoney($indicateurs['couts_recurrents']['hypotheque']['extrapole']) ?></td>
                        <td class="text-end">-</td>
                        <td class="text-end"><?= formatMoney($indicateurs['couts_recurrents']['hypotheque']['extrapole']) ?></td>
                    </tr>
                    <tr class="total-row">
                        <td>Sous-total Récurrents</td>
                        <td class="text-end"><?= formatMoney($indicateurs['couts_recurrents']['total']) ?></td>
                        <td class="text-end">-</td>
                        <td class="text-end"><?= formatMoney($indicateurs['couts_recurrents']['total']) ?></td>
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
                        // Ajouter taxes (14.975% = TPS 5% + TVQ 9.975%)
                        $budgetTTC = $budgetHT * 1.14975;
                        $ecart = $budgetTTC - $depense;
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
                        <td class="text-end"><?= formatMoney($budgetTTC) ?> <small class="text-muted opacity-75">Tx in</small></td>
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
                    
                    <!-- COÛTS DE VENTE -->
                    <tr class="section-header" data-section="vente">
                        <td colspan="4"><i class="bi bi-shop me-1"></i> Vente <i class="bi bi-chevron-down toggle-icon"></i></td>
                    </tr>
                    <tr class="sub-item">
                        <td>Intérêts (<?= $projet['taux_interet'] ?>% sur <?= $dureeReelle ?> mois)</td>
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
                </tbody>
            </table>
        </div>
    </div>
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
                        <table class="table table-sm mb-0">
                            <thead class="table-light">
                                <tr>
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
                                    <td><?= e($p['investisseur_nom']) ?></td>
                                    <td class="text-end"><?= formatMoney($p['montant_calc']) ?></td>
                                    <td class="text-center"><span class="badge bg-warning text-dark"><?= $p['taux_calc'] ?>%</span></td>
                                    <td class="text-end text-danger"><?= formatMoney($interets) ?></td>
                                    <td>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Supprimer?')">
                                            <?php csrfField(); ?>
                                            <input type="hidden" name="action" value="preteurs">
                                            <input type="hidden" name="sub_action" value="supprimer">
                                            <input type="hidden" name="preteur_id" value="<?= $p['id'] ?>">
                                            <button type="submit" class="btn btn-outline-danger btn-sm py-0 px-1">
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
                <div class="card-footer bg-light">
                    <form method="POST" class="row g-2 align-items-end">
                        <?php csrfField(); ?>
                        <input type="hidden" name="action" value="preteurs">
                        <input type="hidden" name="sub_action" value="ajouter">
                        <div class="col-4">
                            <label class="form-label small mb-0">Personne</label>
                            <select class="form-select form-select-sm" name="investisseur_id" required>
                                <option value="">Choisir...</option>
                                <?php foreach ($tousInvestisseurs as $inv): ?>
                                    <option value="<?= $inv['id'] ?>"><?= e($inv['nom']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-3">
                            <label class="form-label small mb-0">Montant $</label>
                            <input type="text" class="form-control form-control-sm money-input" name="montant_pret" required placeholder="0">
                        </div>
                        <div class="col-3">
                            <label class="form-label small mb-0">Taux %</label>
                            <input type="text" class="form-control form-control-sm" name="taux_interet_pret" value="10" required>
                        </div>
                        <div class="col-2">
                            <button type="submit" class="btn btn-warning btn-sm w-100">+</button>
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
                        <table class="table table-sm mb-0">
                            <thead class="table-light">
                                <tr>
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
                                    <td><?= e($inv['investisseur_nom']) ?></td>
                                    <td class="text-end"><?= formatMoney($inv['montant_calc']) ?></td>
                                    <td class="text-center"><span class="badge bg-success"><?= number_format($pct, 1) ?>%</span></td>
                                    <td>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Supprimer?')">
                                            <?php csrfField(); ?>
                                            <input type="hidden" name="action" value="preteurs">
                                            <input type="hidden" name="sub_action" value="supprimer">
                                            <input type="hidden" name="preteur_id" value="<?= $inv['id'] ?>">
                                            <button type="submit" class="btn btn-outline-danger btn-sm py-0 px-1">
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
                <div class="card-footer bg-light">
                    <form method="POST" class="row g-2 align-items-end">
                        <?php csrfField(); ?>
                        <input type="hidden" name="action" value="preteurs">
                        <input type="hidden" name="sub_action" value="ajouter">
                        <input type="hidden" name="taux_interet_pret" value="0">
                        <div class="col-6">
                            <label class="form-label small mb-0">Personne</label>
                            <select class="form-select form-select-sm" name="investisseur_id" required>
                                <option value="">Choisir...</option>
                                <?php foreach ($tousInvestisseurs as $inv): ?>
                                    <option value="<?= $inv['id'] ?>"><?= e($inv['nom']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-4">
                            <label class="form-label small mb-0">Mise $</label>
                            <input type="text" class="form-control form-control-sm money-input" name="montant_pret" required placeholder="0">
                        </div>
                        <div class="col-2">
                            <button type="submit" class="btn btn-success btn-sm w-100">+</button>
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
    <div class="card bg-primary text-white mb-3 sticky-top" style="top: 60px; z-index: 100;">
        <div class="card-body py-2">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                <div class="d-flex align-items-center gap-3">
                    <i class="bi bi-calculator fs-4"></i>
                    <div>
                        <small class="opacity-75">Matériaux</small>
                        <h5 class="mb-0" id="totalBudget"><?= formatMoney($totalBudgetTab) ?></h5>
                    </div>
                    <div>
                        <small class="opacity-75">Conting. <?= $projet['taux_contingence'] ?>%</small>
                        <h6 class="mb-0" id="totalContingence"><?= formatMoney($contingenceTab) ?></h6>
                    </div>
                </div>
                <div class="d-flex align-items-center gap-3">
                    <div class="text-end">
                        <small class="opacity-75">TPS 5%</small>
                        <h6 class="mb-0" id="totalTPS"><?= formatMoney($tpsTab) ?></h6>
                    </div>
                    <div class="text-end">
                        <small class="opacity-75">TVQ 9.975%</small>
                        <h6 class="mb-0" id="totalTVQ"><?= formatMoney($tvqTab) ?></h6>
                    </div>
                    <div class="text-end border-start ps-3">
                        <small class="opacity-75">Total avec taxes</small>
                        <h4 class="mb-0" id="grandTotal"><?= formatMoney($grandTotalTab) ?></h4>
                    </div>
                </div>
            </div>
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
                    <div class="d-flex align-items-center w-100 px-3 py-2 bg-light border-bottom">
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
                        <button class="btn btn-link text-start flex-grow-1 text-decoration-none p-0 collapsed"
                                type="button"
                                data-bs-toggle="collapse"
                                data-bs-target="#cat_<?= $catId ?>">
                            <i class="bi bi-caret-right-fill me-1 cat-chevron"></i>
                            <strong><?= e($cat['nom']) ?></strong>
                            <?php if (!empty($cat['sous_categories'])): ?>
                                <small class="text-muted ms-2">(<?= count($cat['sous_categories']) ?> sous-cat.)</small>
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
    if ((int)$dateVente->format('d') > (int)$dateAchat->format('d')) {
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
<script>
Chart.defaults.color = '#666';
const optionsLine = {
    responsive: true, maintainAspectRatio: false,
    plugins: { legend: { position: 'top', labels: { boxWidth: 10, font: { size: 10 } } } },
    scales: { x: { ticks: { font: { size: 9 } } }, y: { ticks: { callback: v => (v/1000).toFixed(0)+'k', font: { size: 9 } } } }
};
const optionsBar = {
    responsive: true, maintainAspectRatio: false,
    plugins: { legend: { display: false } },
    scales: { x: { ticks: { font: { size: 9 } } }, y: { ticks: { callback: v => v+'h', font: { size: 9 } } } }
};

new Chart(document.getElementById('chartCouts'), {
    type: 'line',
    data: {
        labels: <?= json_encode($labelsTimeline) ?>,
        datasets: [
            { label: 'Coûts', data: <?= json_encode($coutsTimeline) ?>, borderColor: '#e74a3b', backgroundColor: 'rgba(231,74,59,0.1)', fill: true, tension: 0.3, pointRadius: 2 },
            { label: 'Valeur', data: <?= json_encode(array_fill(0, count($labelsTimeline), $valeurPotentielle)) ?>, borderColor: '#1cc88a', borderDash: [5,5], pointRadius: 0 }
        ]
    },
    options: optionsLine
});

new Chart(document.getElementById('chartBudget'), {
    type: 'bar',
    data: {
        labels: <?= json_encode($jourLabelsHeures ?: ['Aucune']) ?>,
        datasets: [{ data: <?= json_encode($jourDataHeures ?: [0]) ?>, backgroundColor: 'rgba(78,115,223,0.6)' }]
    },
    options: optionsBar
});

new Chart(document.getElementById('chartProfits'), {
    type: 'line',
    data: {
        labels: <?= json_encode($jourLabels) ?>,
        datasets: [
            { label: 'Budget', data: <?= json_encode($dataExtrapole) ?>, borderColor: '#36b9cc', fill: true, backgroundColor: 'rgba(54,185,204,0.1)', tension: 0.3, pointRadius: 1 },
            { label: 'Réel', data: <?= json_encode($dataReel) ?>, borderColor: '#e74a3b', fill: true, backgroundColor: 'rgba(231,74,59,0.2)', stepped: true, pointRadius: 2 }
        ]
    },
    options: optionsLine
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
<?php include '../../includes/footer.php'; ?>
