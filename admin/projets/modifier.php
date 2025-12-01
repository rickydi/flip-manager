<?php
/**
 * Modifier projet - Admin
 * Flip Manager
 */

require_once '../../config.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../includes/calculs.php';

requireAdmin();

$projetId = (int)($_GET['id'] ?? 0);
$tab = $_GET['tab'] ?? 'general';

if (!$projetId) {
    setFlashMessage('error', 'Projet non trouvé.');
    redirect('/admin/projets/liste.php');
}

// Récupérer le projet
$stmt = $pdo->prepare("SELECT * FROM projets WHERE id = ?");
$stmt->execute([$projetId]);
$projet = $stmt->fetch();

if (!$projet) {
    setFlashMessage('error', 'Projet non trouvé.');
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
$categories = $stmt->fetchAll();

// Récupérer les prêteurs disponibles
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

// Grouper par catégorie
$categoriesGroupees = [];
foreach ($categories as $cat) {
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

$errors = [];
$success = false;

// Traitement du formulaire
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token de sécurité invalide.';
    } else {
        $action = $_POST['action'] ?? 'general';
        
        if ($action === 'general') {
            // Mise à jour des informations générales
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
                
                $result = $stmt->execute([
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
                
                if ($result) {
                    setFlashMessage('success', 'Projet mis à jour avec succès!');
                    redirect('/admin/projets/modifier.php?id=' . $projetId);
                }
            }
        } elseif ($action === 'preteurs') {
            // Gestion des prêteurs
            $subAction = $_POST['sub_action'] ?? '';
            
            if ($subAction === 'ajouter') {
                $investisseurId = (int)($_POST['investisseur_id'] ?? 0);
                $montant = parseNumber($_POST['montant_pret'] ?? 0);
                $tauxInteret = parseNumber($_POST['taux_interet_pret'] ?? 10);
                
                if ($investisseurId && $montant > 0) {
                    try {
                        $stmt = $pdo->prepare("
                            INSERT INTO projet_investisseurs (projet_id, investisseur_id, montant, taux_interet)
                            VALUES (?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE montant = VALUES(montant), taux_interet = VALUES(taux_interet)
                        ");
                        $stmt->execute([$projetId, $investisseurId, $montant, $tauxInteret]);
                        setFlashMessage('success', 'Prêteur ajouté!');
                    } catch (Exception $e) {
                        setFlashMessage('danger', 'Erreur: ' . $e->getMessage());
                    }
                }
            } elseif ($subAction === 'supprimer') {
                $preteurId = (int)($_POST['preteur_id'] ?? 0);
                if ($preteurId) {
                    $stmt = $pdo->prepare("DELETE FROM projet_investisseurs WHERE id = ? AND projet_id = ?");
                    $stmt->execute([$preteurId, $projetId]);
                    setFlashMessage('success', 'Prêteur supprimé.');
                }
            }
            redirect('/admin/projets/modifier.php?id=' . $projetId . '&tab=preteurs');
            
        } elseif ($action === 'budgets') {
            // Mise à jour des budgets
            $budgets = $_POST['budget'] ?? [];
            
            foreach ($budgets as $categorieId => $montant) {
                $montant = parseNumber($montant);
                
                $stmt = $pdo->prepare("
                    INSERT INTO budgets (projet_id, categorie_id, montant_extrapole)
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE montant_extrapole = ?
                ");
                $stmt->execute([$projetId, $categorieId, $montant, $montant]);
            }
            
            setFlashMessage('success', 'Budgets mis à jour avec succès!');
            redirect('/admin/projets/modifier.php?id=' . $projetId . '&tab=budgets');
        } elseif ($action === 'planification') {
            // Mise à jour de la planification main d'œuvre
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
                    // Supprimer si 0
                    $stmt = $pdo->prepare("DELETE FROM projet_planification_heures WHERE projet_id = ? AND user_id = ?");
                    $stmt->execute([$projetId, $userId]);
                }
            }
            
            setFlashMessage('success', 'Planification main-d\'œuvre mise à jour!');
            redirect('/admin/projets/modifier.php?id=' . $projetId . '&tab=planification');
        }
    }
}

// Recharger le projet après les modifications
$stmt = $pdo->prepare("SELECT * FROM projets WHERE id = ?");
$stmt->execute([$projetId]);
$projet = $stmt->fetch();

// Recharger les budgets
$stmt = $pdo->prepare("
    SELECT c.*, COALESCE(b.montant_extrapole, 0) as montant_extrapole
    FROM categories c
    LEFT JOIN budgets b ON c.id = b.categorie_id AND b.projet_id = ?
    ORDER BY c.groupe, c.ordre
");
$stmt->execute([$projetId]);
$categories = $stmt->fetchAll();

$categoriesGroupees = [];
foreach ($categories as $cat) {
    $categoriesGroupees[$cat['groupe']][] = $cat;
}

// Calculer la durée réelle (comme dans calculs.php)
$dureeReelle = (int)$projet['temps_assume_mois'];
if (!empty($projet['date_vente']) && !empty($projet['date_acquisition'])) {
    $dateAchat = new DateTime($projet['date_acquisition']);
    $dateVente = new DateTime($projet['date_vente']);
    $diff = $dateAchat->diff($dateVente);
    $dureeReelle = ($diff->y * 12) + $diff->m;
    // Ajouter 1 mois seulement si jour fin > jour début
    if ((int)$dateVente->format('d') > (int)$dateAchat->format('d')) {
        $dureeReelle++;
    }
    $dureeReelle = max(1, $dureeReelle);
} elseif (!empty($projet['date_fin_prevue']) && !empty($projet['date_acquisition'])) {
    $dateAchat = new DateTime($projet['date_acquisition']);
    $dateFin = new DateTime($projet['date_fin_prevue']);
    $diff = $dateAchat->diff($dateFin);
    $dureeReelle = ($diff->y * 12) + $diff->m;
    // Ajouter 1 mois seulement si jour fin > jour début
    if ((int)$dateFin->format('d') > (int)$dateAchat->format('d')) {
        $dureeReelle++;
    }
    $dureeReelle = max(1, $dureeReelle);
}

$pageTitle = 'Modifier: ' . $projet['nom'];
include '../../includes/header.php';
?>

<div class="container-fluid">
    <div class="page-header">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="/admin/index.php">Tableau de bord</a></li>
                <li class="breadcrumb-item"><a href="/admin/projets/liste.php">Projets</a></li>
                <li class="breadcrumb-item active"><?= e($projet['nom']) ?></li>
            </ol>
        </nav>
        <div class="d-flex justify-content-between align-items-center">
            <h1><a href="/admin/projets/detail.php?id=<?= $projetId ?>" class="text-decoration-none text-dark"><i class="bi bi-pencil me-2"></i><?= e($projet['nom']) ?></a></h1>
            <div>
                <a href="/admin/projets/detail.php?id=<?= $projetId ?>" class="btn btn-primary me-2">
                    <i class="bi bi-eye me-1"></i>Voir détails
                </a>
                <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#deleteModal">
                    <i class="bi bi-trash me-1"></i>Supprimer
                </button>
            </div>
        </div>
    </div>
    
    <?php displayFlashMessages(); ?>
    
    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger">
            <ul class="mb-0">
                <?php foreach ($errors as $error): ?>
                    <li><?= e($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
    
    <!-- Onglets -->
    <ul class="nav nav-tabs mb-4">
        <li class="nav-item">
            <a class="nav-link <?= $tab === 'general' ? 'active' : '' ?>" 
               href="?id=<?= $projetId ?>&tab=general">
                <i class="bi bi-gear me-1"></i>Général
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $tab === 'preteurs' ? 'active' : '' ?>" 
               href="?id=<?= $projetId ?>&tab=preteurs">
                <i class="bi bi-bank me-1"></i>Financement
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $tab === 'budgets' ? 'active' : '' ?>" 
               href="?id=<?= $projetId ?>&tab=budgets">
                <i class="bi bi-calculator me-1"></i>Budgets
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $tab === 'planification' ? 'active' : '' ?>" 
               href="?id=<?= $projetId ?>&tab=planification">
                <i class="bi bi-people me-1"></i>Main-d'œuvre
            </a>
        </li>
    </ul>
    
    <?php if ($tab === 'general'): ?>
    <!-- Onglet Général - COMPACT -->
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
            <div class="col-lg-6">
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
                                <label class="form-label">Durée (mois) <small class="text-muted">auto</small></label>
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
            <div class="col-lg-6">
                <div class="card">
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
                // Calculer le montant du courtier avec taxes
                $commHT = (float)$projet['valeur_potentielle'] * ((float)$projet['taux_commission'] / 100);
                $commTPS = $commHT * 0.05;
                $commTVQ = $commHT * 0.09975;
                $commTTC = $commHT + $commTPS + $commTVQ;
                ?>
                <div class="card">
                    <div class="card-header"><i class="bi bi-percent me-1"></i>Taux & Notes</div>
                    <div class="card-body">
                        <div class="row g-2">
                            <div class="col-4">
                                <label class="form-label">Courtier immo.</label>
                                <div class="input-group">
                                    <input type="number" class="form-control" name="taux_commission" id="taux_commission" step="0.01" value="<?= $projet['taux_commission'] ?>">
                                    <span class="input-group-text">%</span>
                                </div>
                            </div>
                            <div class="col-4">
                                <label class="form-label">= Commission</label>
                                <div class="input-group">
                                    <span class="input-group-text">$</span>
                                    <input type="text" class="form-control bg-light" id="comm_montant" value="<?= number_format($commHT, 2, ',', ' ') ?>" readonly>
                                </div>
                                <small class="text-muted" style="font-size:0.65rem" id="comm_taxes">
                                    TPS: <?= number_format($commTPS, 2, ',', ' ') ?>$ | TVQ: <?= number_format($commTVQ, 2, ',', ' ') ?>$
                                </small>
                            </div>
                            <div class="col-4">
                                <label class="form-label">Contingence</label>
                                <div class="input-group">
                                    <input type="number" class="form-control" name="taux_contingence" step="0.01" value="<?= $projet['taux_contingence'] ?>">
                                    <span class="input-group-text">%</span>
                                </div>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Notes</label>
                                <textarea class="form-control" name="notes" rows="2"><?= e($projet['notes']) ?></textarea>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="d-flex justify-content-between mt-3">
            <a href="/admin/projets/liste.php" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i>Retour
            </a>
            <button type="submit" class="btn btn-success">
                <i class="bi bi-check-circle me-1"></i>Enregistrer
            </button>
        </div>
    </form>
    
    <?php elseif ($tab === 'preteurs'): ?>
    <!-- Onglet Financement - PRÊTEURS vs INVESTISSEURS -->
    
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
    $totalPrets = 0;
    $totalInvest = 0;
    
    foreach ($preteursProjet as $p) {
        $montant = (float)($p['montant'] ?? $p['mise_de_fonds'] ?? 0);
        $taux = (float)($p['taux_interet'] ?? $p['pourcentage_profit'] ?? 0);
        
        if ($taux > 0) {
            // Prêteur (a un taux d'intérêt)
            $listePreteurs[] = array_merge($p, ['montant_calc' => $montant, 'taux_calc' => $taux]);
            $totalPrets += $montant;
        } else {
            // Investisseur (pas de taux = partage profits)
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
                        <table class="table table-sm mb-0" id="tablePreteurs">
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
                                // Intérêts composés mensuellement
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
                        <strong><?= formatMoney($totalPrets) ?></strong>
                    </div>
                    <div class="d-flex justify-content-between text-danger">
                        <span>Intérêts (<?= $dureeReelle ?> mois) :</span>
                        <strong>
                            <?php 
                            $totalInterets = 0;
                            foreach ($listePreteurs as $p) {
                                $tauxMensuel = $p['taux_calc'] / 100 / 12;
                                $totalInterets += $p['montant_calc'] * (pow(1 + $tauxMensuel, $dureeReelle) - 1);
                            }
                            echo formatMoney($totalInterets);
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
        <a href="/admin/investisseurs/liste.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-person-plus me-1"></i>Gérer la liste des personnes
        </a>
    </div>
    
    <?php elseif ($tab === 'budgets'): ?>
    <!-- Onglet Budgets - TABLEAU UNIQUE -->
    <?php
    $totalBudget = 0;
    foreach ($categories as $cat) {
        $totalBudget += (float)$cat['montant_extrapole'];
    }
    $contingence = $totalBudget * ((float)$projet['taux_contingence'] / 100);
    ?>
    
    <!-- TOTAL EN HAUT - STICKY -->
    <div class="card bg-primary text-white mb-3 sticky-top" style="top: 60px; z-index: 100;">
        <div class="card-body py-2">
            <div class="row align-items-center">
                <div class="col-auto">
                    <i class="bi bi-calculator fs-4"></i>
                </div>
                <div class="col">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <small class="opacity-75">Total Budget Rénovation</small>
                            <h4 class="mb-0" id="totalBudget"><?= formatMoney($totalBudget) ?></h4>
                        </div>
                        <div class="text-end">
                            <small class="opacity-75">+ Contingence <?= $projet['taux_contingence'] ?>%</small>
                            <h5 class="mb-0" id="totalContingence"><?= formatMoney($contingence) ?></h5>
                        </div>
                        <div class="text-end border-start ps-3 ms-3">
                            <small class="opacity-75">Grand Total</small>
                            <h4 class="mb-0" id="grandTotal"><?= formatMoney($totalBudget + $contingence) ?></h4>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <form method="POST" action="" id="formBudgets">
        <?php csrfField(); ?>
        <input type="hidden" name="action" value="budgets">
        
        <div class="table-responsive">
            <table class="table table-sm table-hover">
                <?php 
                $currentGroupe = '';
                foreach ($categories as $cat): 
                    if ($cat['groupe'] !== $currentGroupe):
                        $currentGroupe = $cat['groupe'];
                ?>
                <thead class="table-dark">
                    <tr>
                        <th colspan="2" class="py-2">
                            <i class="bi bi-folder me-1"></i><?= $groupeLabels[$currentGroupe] ?? ucfirst($currentGroupe) ?>
                        </th>
                    </tr>
                </thead>
                <tbody>
                <?php endif; ?>
                    <tr>
                        <td class="ps-3" style="width: 70%"><?= e($cat['nom']) ?></td>
                        <td style="width: 30%">
                            <div class="input-group input-group-sm">
                                <span class="input-group-text">$</span>
                                <input type="text" 
                                       class="form-control budget-input" 
                                       name="budget[<?= $cat['id'] ?>]" 
                                       value="<?= formatMoney($cat['montant_extrapole'], false) ?>"
                                       data-id="<?= $cat['id'] ?>">
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <div class="d-flex justify-content-between mt-3">
            <a href="/admin/projets/liste.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i>Retour
            </a>
            <button type="submit" class="btn btn-success btn-lg">
                <i class="bi bi-check-circle me-1"></i>Enregistrer les budgets
            </button>
        </div>
    </form>
    
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const inputs = document.querySelectorAll('.budget-input');
        const totalEl = document.getElementById('totalBudget');
        const contingenceEl = document.getElementById('totalContingence');
        const grandTotalEl = document.getElementById('grandTotal');
        const tauxContingence = <?= (float)$projet['taux_contingence'] ?>;
        
        function parseValue(str) {
            return parseFloat(str.replace(/\s/g, '').replace(',', '.')) || 0;
        }
        
        function formatMoney(val) {
            return val.toLocaleString('fr-CA', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' $';
        }
        
        function updateTotal() {
            let total = 0;
            inputs.forEach(input => {
                total += parseValue(input.value);
            });
            
            const contingence = total * (tauxContingence / 100);
            const grandTotal = total + contingence;
            
            totalEl.textContent = formatMoney(total);
            contingenceEl.textContent = formatMoney(contingence);
            grandTotalEl.textContent = formatMoney(grandTotal);
        }
        
        inputs.forEach(input => {
            input.addEventListener('input', updateTotal);
            input.addEventListener('change', updateTotal);
        });
    });
    </script>
    
    <?php elseif ($tab === 'planification'): ?>
    <!-- Onglet Planification Main d'oeuvre -->
    <?php
    // Calculer la durée en jours ouvrables (5 jours par semaine)
    $dureeJours = 0;
    $dureeSemaines = 0;
    $dateDebut = $projet['date_debut_travaux'] ?? $projet['date_acquisition'];
    $dateFin = $projet['date_fin_prevue'];
    
    if ($dateDebut && $dateFin) {
        $d1 = new DateTime($dateDebut);
        $d2 = new DateTime($dateFin);
        
        // Calcul EXACT des jours ouvrables vs weekends (inclus date début ET fin)
        $dureeJours = 0;
        $joursFermes = 0;
        
        // Créer une période incluant la date de fin (+1 jour car DatePeriod est exclusif sur la fin)
        $d2Inclusive = clone $d2;
        $d2Inclusive->modify('+1 day');
        
        $period = new DatePeriod($d1, new DateInterval('P1D'), $d2Inclusive);
        
        foreach ($period as $dt) {
            // N = jour de la semaine ISO-8601 (1=Lundi, 7=Dimanche)
            // Samedi = 6, Dimanche = 7
            $dayOfWeek = (int)$dt->format('N');
            if ($dayOfWeek >= 6) {
                $joursFermes++;
            } else {
                $dureeJours++;
            }
        }
        
        $dureeJours = max(1, $dureeJours);
        
        // Semaines pour affichage
        $dureeSemaines = ceil($dureeJours / 5);
    }
    
    // Récupérer tous les employés actifs avec leur taux horaire
    $stmt = $pdo->query("SELECT id, CONCAT(prenom, ' ', nom) as nom_complet, taux_horaire, role FROM users WHERE actif = 1 ORDER BY prenom, nom");
    $employes = $stmt->fetchAll();
    
    // Récupérer les planifications existantes pour ce projet
    $planifications = [];
    try {
        $stmt = $pdo->prepare("SELECT user_id, heures_semaine_estimees FROM projet_planification_heures WHERE projet_id = ?");
        $stmt->execute([$projetId]);
        while ($row = $stmt->fetch()) {
            $planifications[$row['user_id']] = (float)$row['heures_semaine_estimees'];
        }
    } catch (Exception $e) {
        // Table n'existe pas encore
    }
    
    // Calculer le total estimé (heures/jour × jours ouvrables)
    $totalHeuresEstimees = 0;
    $totalCoutEstime = 0;
    foreach ($employes as $emp) {
        $heuresSemaine = $planifications[$emp['id']] ?? 0;
        // Convertir heures/semaine en heures/jour puis × jours
        $heuresJour = $heuresSemaine / 5;
        $totalHeures = $heuresJour * $dureeJours;
        $cout = $totalHeures * (float)$emp['taux_horaire'];
        $totalHeuresEstimees += $totalHeures;
        $totalCoutEstime += $cout;
    }
    ?>
    
    <!-- Résumé du projet -->
    <div class="alert alert-info mb-4">
        <div class="row align-items-center">
            <div class="col-md-4">
                <strong><i class="bi bi-calendar3 me-1"></i> Début travaux:</strong>
                <?= $dateDebut ? formatDate($dateDebut) : '<span class="text-warning">Non défini</span>' ?>
            </div>
            <div class="col-md-4">
                <strong><i class="bi bi-calendar-check me-1"></i> Fin prévue:</strong>
                <?= $dateFin ? formatDate($dateFin) : '<span class="text-warning">Non défini</span>' ?>
            </div>
            <div class="col-md-4">
                <strong><i class="bi bi-clock me-1"></i> Durée estimée:</strong>
                <?php if ($dureeJours > 0): ?>
                    <span class="badge bg-primary fs-6"><?= $dureeJours ?> jours ouvrables</span>
                    <span class="badge bg-primary fs-6 ms-1"><?= $joursFermes ?? 0 ?> jours fermés</span>
                <?php else: ?>
                    <span class="text-warning">Définir les dates dans l'onglet Général</span>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <?php if ($dureeSemaines == 0): ?>
        <div class="alert alert-warning">
            <i class="bi bi-exclamation-triangle me-2"></i>
            <strong>Attention:</strong> Vous devez d'abord définir les dates de début et fin de travaux dans l'onglet "Général" pour pouvoir calculer les coûts de main-d'œuvre.
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
                            <h4 class="mb-0" id="totalHeures"><?= number_format($totalHeuresEstimees, 1) ?> h</h4>
                        </div>
                        <div class="text-end border-start ps-3 ms-3">
                            <small class="opacity-75">Coût Main-d'œuvre Estimé</small>
                            <h4 class="mb-0" id="totalCout"><?= formatMoney($totalCoutEstime) ?></h4>
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
                            // heures/jour × jours ouvrables
                            $heuresJour = $heuresSemaine / 5;
                            $totalHeures = $heuresJour * $dureeJours;
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
                                       data-jours="<?= $dureeJours ?>"
                                       onfocus="this.select()">
                            </td>
                            <td class="text-center text-muted"><?= $dureeJours ?></td>
                            <td class="text-end total-heures"><?= number_format($totalHeures, 1) ?> h</td>
                            <td class="text-end fw-bold cout-estime"><?= formatMoney($coutEstime) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <div class="d-flex justify-content-between mt-3">
            <a href="/admin/projets/liste.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i>Retour
            </a>
            <button type="submit" class="btn btn-success btn-lg">
                <i class="bi bi-check-circle me-1"></i>Enregistrer la planification
            </button>
        </div>
    </form>
    
    <div class="mt-3 text-center">
        <a href="/admin/utilisateurs/liste.php" class="btn btn-outline-secondary btn-sm">
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
                
                // heures/jour = heures/semaine ÷ 5, puis × jours
                const heuresJour = heuresSemaine / 5;
                const totalHeures = heuresJour * jours;
                const cout = totalHeures * taux;
                
                row.querySelector('.total-heures').textContent = totalHeures.toFixed(1) + ' h';
                row.querySelector('.cout-estime').textContent = formatMoney(cout);
                
                // Highlight row if hours > 0
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
    <?php endif; ?>
</div>

<!-- Modal de suppression -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title" id="deleteModalLabel">
                    <i class="bi bi-exclamation-triangle me-2"></i>Confirmer la suppression
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>Êtes-vous sûr de vouloir supprimer le projet <strong><?= e($projet['nom']) ?></strong> ?</p>
                <div class="alert alert-warning">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    <strong>Attention :</strong> Cette action est irréversible. Toutes les factures et budgets associés seront également supprimés.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                <form action="/admin/projets/supprimer.php" method="POST" class="d-inline">
                    <?php csrfField(); ?>
                    <input type="hidden" name="projet_id" value="<?= $projetId ?>">
                    <button type="submit" class="btn btn-danger">
                        <i class="bi bi-trash me-1"></i>Supprimer définitivement
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
// Calcul instantané de la commission courtier
document.addEventListener('DOMContentLoaded', function() {
    const tauxInput = document.getElementById('taux_commission');
    const valeurInput = document.querySelector('input[name="valeur_potentielle"]');
    const commMontant = document.getElementById('comm_montant');
    const commTaxes = document.getElementById('comm_taxes');
    
    function calculerCommission() {
        if (!tauxInput || !valeurInput || !commMontant) return;
        
        // Parser la valeur potentielle (enlever espaces et virgules)
        let valeur = valeurInput.value.replace(/\s/g, '').replace(',', '.');
        valeur = parseFloat(valeur) || 0;
        
        const taux = parseFloat(tauxInput.value) || 0;
        
        // Calculs
        const commHT = valeur * (taux / 100);
        const tps = commHT * 0.05;
        const tvq = commHT * 0.09975;
        
        // Format français
        const fmt = (n) => n.toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
        
        commMontant.value = fmt(commHT);
        if (commTaxes) {
            commTaxes.textContent = 'TPS: ' + fmt(tps) + '$ | TVQ: ' + fmt(tvq) + '$';
        }
    }
    
    if (tauxInput) tauxInput.addEventListener('input', calculerCommission);
    if (valeurInput) valeurInput.addEventListener('input', calculerCommission);
    
    // Calcul automatique de la durée en mois (achat → vente)
    const dateAchat = document.querySelector('input[name="date_acquisition"]');
    const dateVente = document.querySelector('input[name="date_vente"]');
    const dateFin = document.querySelector('input[name="date_fin_prevue"]');
    const dureeMois = document.getElementById('duree_mois');
    
    function calculerDuree() {
        if (!dureeMois) return;
        
        const achat = dateAchat ? dateAchat.value : null;
        // Utiliser date vente si disponible, sinon fin travaux prévue
        const fin = (dateVente && dateVente.value) ? dateVente.value : (dateFin ? dateFin.value : null);
        
        if (achat && fin) {
            const d1 = new Date(achat);
            const d2 = new Date(fin);
            
            // Calcul des mois entre les deux dates
            let mois = (d2.getFullYear() - d1.getFullYear()) * 12 + (d2.getMonth() - d1.getMonth());
            
            // Ajouter 1 mois seulement si le jour de fin est APRES le jour de début
            // (ex: 15/01 → 20/02 = 1 mois + fraction = arrondi à 2)
            if (d2.getDate() > d1.getDate()) {
                mois++;
            }
            
            mois = Math.max(1, mois); // Minimum 1 mois
            dureeMois.value = mois;
        }
    }
    
    if (dateAchat) dateAchat.addEventListener('change', calculerDuree);
    if (dateVente) dateVente.addEventListener('change', calculerDuree);
    if (dateFin) dateFin.addEventListener('change', calculerDuree);
    
    // Calcul initial au chargement
    calculerDuree();
});
</script>

<?php include '../../includes/footer.php'; ?>
