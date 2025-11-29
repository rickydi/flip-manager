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
            $statut = $_POST['statut'] ?? 'acquisition';
            
            $prixAchat = parseNumber($_POST['prix_achat'] ?? 0);
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
                        date_acquisition = ?, date_debut_travaux = ?, date_fin_prevue = ?,
                        statut = ?, prix_achat = ?, notaire = ?, taxe_mutation = ?,
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
                    $dateAcquisition, $dateDebutTravaux, $dateFinPrevue,
                    $statut, $prixAchat, $notaire, $taxeMutation,
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
            <h1><i class="bi bi-pencil me-2"></i><?= e($projet['nom']) ?></h1>
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
                            <div class="col-4">
                                <label class="form-label">Acquisition</label>
                                <input type="date" class="form-control" name="date_acquisition" value="<?= e($projet['date_acquisition']) ?>">
                            </div>
                            <div class="col-4">
                                <label class="form-label">Début travaux</label>
                                <input type="date" class="form-control" name="date_debut_travaux" value="<?= e($projet['date_debut_travaux']) ?>">
                            </div>
                            <div class="col-4">
                                <label class="form-label">Fin prévue</label>
                                <input type="date" class="form-control" name="date_fin_prevue" value="<?= e($projet['date_fin_prevue']) ?>">
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-header"><i class="bi bi-currency-dollar me-1"></i>Acquisition</div>
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
                                <input type="number" class="form-control" name="temps_assume_mois" value="<?= (int)$projet['temps_assume_mois'] ?>">
                            </div>
                            <div class="col-3">
                                <label class="form-label">Notaire</label>
                                <div class="input-group"><span class="input-group-text">$</span>
                                    <input type="text" class="form-control money-input" name="notaire" value="<?= formatMoney($projet['notaire'], false) ?>">
                                </div>
                            </div>
                            <div class="col-3">
                                <label class="form-label">Mutation</label>
                                <div class="input-group"><span class="input-group-text">$</span>
                                    <input type="text" class="form-control money-input" name="taxe_mutation" value="<?= formatMoney($projet['taxe_mutation'], false) ?>">
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
                
                <div class="card">
                    <div class="card-header"><i class="bi bi-percent me-1"></i>Taux & Notes</div>
                    <div class="card-body">
                        <div class="row g-2">
                            <div class="col-6">
                                <label class="form-label">Commission</label>
                                <div class="input-group">
                                    <input type="number" class="form-control" name="taux_commission" step="0.01" value="<?= $projet['taux_commission'] ?>">
                                    <span class="input-group-text">%</span>
                                </div>
                            </div>
                            <div class="col-6">
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
    <!-- Onglet Financement -->
    
    <!-- Simulateur de durée -->
    <div class="card mb-4 bg-light">
        <div class="card-body">
            <div class="row align-items-center">
                <div class="col-md-3">
                    <h5 class="mb-0"><i class="bi bi-sliders me-2"></i>Simulateur</h5>
                </div>
                <div class="col-md-6">
                    <label class="form-label mb-1">Durée du projet : <strong id="dureeLabel"><?= $projet['temps_assume_mois'] ?> mois</strong></label>
                    <input type="range" class="form-range" id="dureeSlider" min="1" max="12" value="<?= $projet['temps_assume_mois'] ?>" oninput="updateCalculs()">
                    <div class="d-flex justify-content-between small text-muted">
                        <span>1 mois</span>
                        <span>6 mois</span>
                        <span>12 mois</span>
                    </div>
                </div>
                <div class="col-md-3 text-end">
                    <div class="h4 text-danger mb-0" id="totalInteretsDuree">0 $</div>
                    <small class="text-muted">Intérêts totaux</small>
                </div>
            </div>
        </div>
    </div>
    
    <div class="row">
        <div class="col-lg-8">
            <!-- Liste des prêteurs/investisseurs -->
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-list-ul me-2"></i>Prêteurs & Investisseurs</span>
                </div>
                <?php 
                $totalPrets = 0;
                if (empty($preteursProjet)): ?>
                    <div class="card-body">
                        <div class="text-center text-muted py-4">
                            <i class="bi bi-bank" style="font-size: 3rem;"></i>
                            <p class="mt-2 mb-0">Aucun prêteur ou investisseur configuré.</p>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0" id="tableFinancement">
                            <thead>
                                <tr>
                                    <th>Nom</th>
                                    <th class="text-end">Montant</th>
                                    <th class="text-center">Taux</th>
                                    <th class="text-end">Intérêts/mois</th>
                                    <th class="text-end">Intérêts (durée)</th>
                                    <th class="text-end">Total dû</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php 
                                $preteursData = [];
                                foreach ($preteursProjet as $p): 
                                    $montant = (float)($p['montant'] ?? $p['mise_de_fonds'] ?? 0);
                                    $taux = (float)($p['taux_interet'] ?? $p['pourcentage_profit'] ?? 10);
                                    $totalPrets += $montant;
                                    $preteursData[] = ['montant' => $montant, 'taux' => $taux];
                                ?>
                                    <tr data-montant="<?= $montant ?>" data-taux="<?= $taux ?>">
                                        <td><strong><?= e($p['investisseur_nom']) ?></strong></td>
                                        <td class="text-end"><?= formatMoney($montant) ?></td>
                                        <td class="text-center"><span class="badge bg-info"><?= $taux ?>%</span></td>
                                        <td class="text-end interets-mois">-</td>
                                        <td class="text-end interets-duree">-</td>
                                        <td class="text-end total-du fw-bold">-</td>
                                        <td>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Supprimer?')">
                                                <?php csrfField(); ?>
                                                <input type="hidden" name="action" value="preteurs">
                                                <input type="hidden" name="sub_action" value="supprimer">
                                                <input type="hidden" name="preteur_id" value="<?= $p['id'] ?>">
                                                <button type="submit" class="btn btn-outline-danger btn-sm">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-dark">
                                <tr>
                                    <th>TOTAL</th>
                                    <th class="text-end"><?= formatMoney($totalPrets) ?></th>
                                    <th></th>
                                    <th class="text-end" id="footInteretsMois">-</th>
                                    <th class="text-end" id="footInteretsDuree">-</th>
                                    <th class="text-end" id="footTotalDu">-</th>
                                    <th></th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Résumé visuel -->
            <div class="row">
                <div class="col-md-4">
                    <div class="card text-center bg-primary text-white">
                        <div class="card-body">
                            <h3 class="mb-0"><?= formatMoney($totalPrets) ?></h3>
                            <small>Capital emprunté</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card text-center bg-warning text-dark">
                        <div class="card-body">
                            <h3 class="mb-0" id="resumeInterets">0 $</h3>
                            <small>Intérêts à payer</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card text-center bg-danger text-white">
                        <div class="card-body">
                            <h3 class="mb-0" id="resumeTotal">0 $</h3>
                            <small>Total à rembourser</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-lg-4">
            <!-- Formulaire ajout -->
            <div class="card">
                <div class="card-header bg-success text-white">
                    <i class="bi bi-plus-circle me-2"></i>Ajouter
                </div>
                <div class="card-body">
                    <form method="POST">
                        <?php csrfField(); ?>
                        <input type="hidden" name="action" value="preteurs">
                        <input type="hidden" name="sub_action" value="ajouter">
                        
                        <div class="mb-3">
                            <label class="form-label">Prêteur / Investisseur *</label>
                            <select class="form-select" name="investisseur_id" required>
                                <option value="">Sélectionner...</option>
                                <?php foreach ($tousInvestisseurs as $inv): ?>
                                    <option value="<?= $inv['id'] ?>"><?= e($inv['nom']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">
                                <a href="/admin/investisseurs/liste.php" target="_blank">+ Ajouter nouveau</a>
                            </small>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Montant *</label>
                            <div class="input-group">
                                <span class="input-group-text">$</span>
                                <input type="text" class="form-control money-input" name="montant_pret" required placeholder="0">
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Taux d'intérêt annuel</label>
                            <div class="input-group">
                                <input type="text" class="form-control" name="taux_interet_pret" value="10" placeholder="10">
                                <span class="input-group-text">%</span>
                            </div>
                        </div>
                        
                        <button type="submit" class="btn btn-success w-100">
                            <i class="bi bi-plus-circle me-1"></i>Ajouter
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <script>
    function updateCalculs() {
        const duree = parseInt(document.getElementById('dureeSlider').value);
        document.getElementById('dureeLabel').textContent = duree + ' mois';
        
        const rows = document.querySelectorAll('#tableFinancement tbody tr');
        let totalInteretsMois = 0;
        let totalInteretsDuree = 0;
        let totalDu = 0;
        let totalCapital = 0;
        
        rows.forEach(row => {
            const montant = parseFloat(row.dataset.montant) || 0;
            const taux = parseFloat(row.dataset.taux) || 0;
            
            const interetsMois = montant * (taux / 100) / 12;
            const interetsDuree = interetsMois * duree;
            const du = montant + interetsDuree;
            
            row.querySelector('.interets-mois').textContent = formatMoney(interetsMois);
            row.querySelector('.interets-duree').textContent = formatMoney(interetsDuree);
            row.querySelector('.total-du').textContent = formatMoney(du);
            
            totalInteretsMois += interetsMois;
            totalInteretsDuree += interetsDuree;
            totalDu += du;
            totalCapital += montant;
        });
        
        document.getElementById('footInteretsMois').textContent = formatMoney(totalInteretsMois);
        document.getElementById('footInteretsDuree').textContent = formatMoney(totalInteretsDuree);
        document.getElementById('footTotalDu').textContent = formatMoney(totalDu);
        
        document.getElementById('totalInteretsDuree').textContent = formatMoney(totalInteretsDuree);
        document.getElementById('resumeInterets').textContent = formatMoney(totalInteretsDuree);
        document.getElementById('resumeTotal').textContent = formatMoney(totalDu);
    }
    
    function formatMoney(value) {
        return value.toLocaleString('fr-CA', {minimumFractionDigits: 0, maximumFractionDigits: 0}) + ' $';
    }
    
    // Initialiser les calculs au chargement
    document.addEventListener('DOMContentLoaded', updateCalculs);
    </script>
    
    <?php elseif ($tab === 'budgets'): ?>
    <!-- Onglet Budgets - COMPACT -->
    <style>
        .budget-item { margin-bottom: 0.4rem; }
        .budget-item label { font-size: 0.75rem; color: #666; margin-bottom: 0.1rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .budget-item .form-control { font-size: 0.85rem; padding: 0.25rem 0.4rem; }
        .budget-item .input-group-text { font-size: 0.75rem; padding: 0.25rem 0.4rem; }
        .budget-card .card-header { padding: 0.4rem 0.75rem; font-size: 0.85rem; }
        .budget-card .card-body { padding: 0.5rem; }
        .budget-card { margin-bottom: 0.75rem !important; }
    </style>
    <form method="POST" action="">
        <?php csrfField(); ?>
        <input type="hidden" name="action" value="budgets">
        
        <div class="row">
        <?php 
        $colIndex = 0;
        foreach ($categoriesGroupees as $groupe => $cats): 
        ?>
            <div class="col-lg-4 col-md-6">
                <div class="card budget-card">
                    <div class="card-header"><i class="bi bi-folder me-1"></i><?= $groupeLabels[$groupe] ?? ucfirst($groupe) ?></div>
                    <div class="card-body">
                        <div class="row g-1">
                            <?php foreach ($cats as $cat): ?>
                            <div class="col-6 budget-item">
                                <label title="<?= e($cat['nom']) ?>"><?= e($cat['nom']) ?></label>
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text">$</span>
                                    <input type="text" class="form-control money-input" 
                                           name="budget[<?= $cat['id'] ?>]" 
                                           value="<?= formatMoney($cat['montant_extrapole'], false) ?>">
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php 
        $colIndex++;
        endforeach; 
        ?>
        </div>
        
        <div class="d-flex justify-content-between mt-2">
            <a href="/admin/projets/liste.php" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i>Retour
            </a>
            <button type="submit" class="btn btn-success">
                <i class="bi bi-check-circle me-1"></i>Enregistrer
            </button>
        </div>
    </form>
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

<?php include '../../includes/footer.php'; ?>
