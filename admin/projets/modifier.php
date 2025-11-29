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
            <a href="/admin/projets/detail.php?id=<?= $projetId ?>" class="btn btn-primary">
                <i class="bi bi-eye me-1"></i>Voir détails
            </a>
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
            <a class="nav-link <?= $tab === 'budgets' ? 'active' : '' ?>" 
               href="?id=<?= $projetId ?>&tab=budgets">
                <i class="bi bi-calculator me-1"></i>Budgets
            </a>
        </li>
    </ul>
    
    <?php if ($tab === 'general'): ?>
    <!-- Onglet Général -->
    <form method="POST" action="">
        <?php csrfField(); ?>
        <input type="hidden" name="action" value="general">
        
        <div class="card mb-4">
            <div class="card-header"><i class="bi bi-info-circle me-2"></i>Informations générales</div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label for="nom" class="form-label">Nom du projet *</label>
                            <input type="text" class="form-control" id="nom" name="nom" 
                                   value="<?= e($projet['nom']) ?>" required>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label for="statut" class="form-label">Statut</label>
                            <select class="form-select" id="statut" name="statut">
                                <option value="acquisition" <?= $projet['statut'] === 'acquisition' ? 'selected' : '' ?>>Acquisition</option>
                                <option value="renovation" <?= $projet['statut'] === 'renovation' ? 'selected' : '' ?>>Rénovation</option>
                                <option value="vente" <?= $projet['statut'] === 'vente' ? 'selected' : '' ?>>En vente</option>
                                <option value="vendu" <?= $projet['statut'] === 'vendu' ? 'selected' : '' ?>>Vendu</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label for="adresse" class="form-label">Adresse *</label>
                            <input type="text" class="form-control" id="adresse" name="adresse" 
                                   value="<?= e($projet['adresse']) ?>" required>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="mb-3">
                            <label for="ville" class="form-label">Ville *</label>
                            <input type="text" class="form-control" id="ville" name="ville" 
                                   value="<?= e($projet['ville']) ?>" required>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="mb-3">
                            <label for="code_postal" class="form-label">Code postal</label>
                            <input type="text" class="form-control" id="code_postal" name="code_postal" 
                                   value="<?= e($projet['code_postal']) ?>">
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-4">
                        <div class="mb-3">
                            <label for="date_acquisition" class="form-label">Date d'acquisition</label>
                            <input type="date" class="form-control" id="date_acquisition" name="date_acquisition" 
                                   value="<?= e($projet['date_acquisition']) ?>">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="mb-3">
                            <label for="date_debut_travaux" class="form-label">Début des travaux</label>
                            <input type="date" class="form-control" id="date_debut_travaux" name="date_debut_travaux" 
                                   value="<?= e($projet['date_debut_travaux']) ?>">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="mb-3">
                            <label for="date_fin_prevue" class="form-label">Fin prévue</label>
                            <input type="date" class="form-control" id="date_fin_prevue" name="date_fin_prevue" 
                                   value="<?= e($projet['date_fin_prevue']) ?>">
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="card mb-4">
            <div class="card-header"><i class="bi bi-currency-dollar me-2"></i>Coûts d'acquisition</div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4">
                        <div class="mb-3">
                            <label for="prix_achat" class="form-label">Prix d'achat</label>
                            <div class="input-group">
                                <span class="input-group-text">$</span>
                                <input type="text" class="form-control money-input" id="prix_achat" name="prix_achat" 
                                       value="<?= formatMoney($projet['prix_achat'], false) ?>">
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="mb-3">
                            <label for="valeur_potentielle" class="form-label">Valeur potentielle</label>
                            <div class="input-group">
                                <span class="input-group-text">$</span>
                                <input type="text" class="form-control money-input" id="valeur_potentielle" name="valeur_potentielle" 
                                       value="<?= formatMoney($projet['valeur_potentielle'], false) ?>">
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="mb-3">
                            <label for="temps_assume_mois" class="form-label">Temps assumé (mois)</label>
                            <input type="number" class="form-control" id="temps_assume_mois" name="temps_assume_mois" 
                                   value="<?= (int)$projet['temps_assume_mois'] ?>">
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-3">
                        <div class="mb-3">
                            <label for="notaire" class="form-label">Notaire</label>
                            <div class="input-group">
                                <span class="input-group-text">$</span>
                                <input type="text" class="form-control money-input" id="notaire" name="notaire" 
                                       value="<?= formatMoney($projet['notaire'], false) ?>">
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="mb-3">
                            <label for="taxe_mutation" class="form-label">Taxe de mutation</label>
                            <div class="input-group">
                                <span class="input-group-text">$</span>
                                <input type="text" class="form-control money-input" id="taxe_mutation" name="taxe_mutation" 
                                       value="<?= formatMoney($projet['taxe_mutation'], false) ?>">
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="mb-3">
                            <label for="arpenteurs" class="form-label">Arpenteurs</label>
                            <div class="input-group">
                                <span class="input-group-text">$</span>
                                <input type="text" class="form-control money-input" id="arpenteurs" name="arpenteurs" 
                                       value="<?= formatMoney($projet['arpenteurs'], false) ?>">
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="mb-3">
                            <label for="assurance_titre" class="form-label">Assurance titre</label>
                            <div class="input-group">
                                <span class="input-group-text">$</span>
                                <input type="text" class="form-control money-input" id="assurance_titre" name="assurance_titre" 
                                       value="<?= formatMoney($projet['assurance_titre'], false) ?>">
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="card mb-4">
            <div class="card-header"><i class="bi bi-arrow-repeat me-2"></i>Coûts récurrents</div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-3">
                        <div class="mb-3">
                            <label for="taxes_municipales_annuel" class="form-label">Taxes municipales (an)</label>
                            <div class="input-group">
                                <span class="input-group-text">$</span>
                                <input type="text" class="form-control money-input" id="taxes_municipales_annuel" name="taxes_municipales_annuel" 
                                       value="<?= formatMoney($projet['taxes_municipales_annuel'], false) ?>">
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="mb-3">
                            <label for="taxes_scolaires_annuel" class="form-label">Taxes scolaires (an)</label>
                            <div class="input-group">
                                <span class="input-group-text">$</span>
                                <input type="text" class="form-control money-input" id="taxes_scolaires_annuel" name="taxes_scolaires_annuel" 
                                       value="<?= formatMoney($projet['taxes_scolaires_annuel'], false) ?>">
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="mb-3">
                            <label for="electricite_annuel" class="form-label">Électricité (an)</label>
                            <div class="input-group">
                                <span class="input-group-text">$</span>
                                <input type="text" class="form-control money-input" id="electricite_annuel" name="electricite_annuel" 
                                       value="<?= formatMoney($projet['electricite_annuel'], false) ?>">
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="mb-3">
                            <label for="assurances_annuel" class="form-label">Assurances (an)</label>
                            <div class="input-group">
                                <span class="input-group-text">$</span>
                                <input type="text" class="form-control money-input" id="assurances_annuel" name="assurances_annuel" 
                                       value="<?= formatMoney($projet['assurances_annuel'], false) ?>">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-3">
                        <div class="mb-3">
                            <label for="deneigement_annuel" class="form-label">Déneigement (an)</label>
                            <div class="input-group">
                                <span class="input-group-text">$</span>
                                <input type="text" class="form-control money-input" id="deneigement_annuel" name="deneigement_annuel" 
                                       value="<?= formatMoney($projet['deneigement_annuel'], false) ?>">
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="mb-3">
                            <label for="frais_condo_annuel" class="form-label">Frais condo (an)</label>
                            <div class="input-group">
                                <span class="input-group-text">$</span>
                                <input type="text" class="form-control money-input" id="frais_condo_annuel" name="frais_condo_annuel" 
                                       value="<?= formatMoney($projet['frais_condo_annuel'], false) ?>">
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="mb-3">
                            <label for="hypotheque_mensuel" class="form-label">Hypothèque (mois)</label>
                            <div class="input-group">
                                <span class="input-group-text">$</span>
                                <input type="text" class="form-control money-input" id="hypotheque_mensuel" name="hypotheque_mensuel" 
                                       value="<?= formatMoney($projet['hypotheque_mensuel'], false) ?>">
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="mb-3">
                            <label for="loyer_mensuel" class="form-label">Loyer reçu (mois)</label>
                            <div class="input-group">
                                <span class="input-group-text">$</span>
                                <input type="text" class="form-control money-input" id="loyer_mensuel" name="loyer_mensuel" 
                                       value="<?= formatMoney($projet['loyer_mensuel'], false) ?>">
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="card mb-4">
            <div class="card-header"><i class="bi bi-bank me-2"></i>Financement</div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-3">
                        <div class="mb-3">
                            <label for="montant_pret" class="form-label">Montant du prêt</label>
                            <div class="input-group">
                                <span class="input-group-text">$</span>
                                <input type="text" class="form-control money-input" id="montant_pret" name="montant_pret" 
                                       value="<?= formatMoney($projet['montant_pret'], false) ?>">
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="mb-3">
                            <label for="taux_interet" class="form-label">Taux d'intérêt</label>
                            <div class="input-group">
                                <input type="number" class="form-control" id="taux_interet" name="taux_interet" 
                                       step="0.01" value="<?= $projet['taux_interet'] ?>">
                                <span class="input-group-text">%</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="mb-3">
                            <label for="taux_commission" class="form-label">Commission</label>
                            <div class="input-group">
                                <input type="number" class="form-control" id="taux_commission" name="taux_commission" 
                                       step="0.01" value="<?= $projet['taux_commission'] ?>">
                                <span class="input-group-text">%</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="mb-3">
                            <label for="taux_contingence" class="form-label">Contingence</label>
                            <div class="input-group">
                                <input type="number" class="form-control" id="taux_contingence" name="taux_contingence" 
                                       step="0.01" value="<?= $projet['taux_contingence'] ?>">
                                <span class="input-group-text">%</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="card mb-4">
            <div class="card-header"><i class="bi bi-sticky me-2"></i>Notes</div>
            <div class="card-body">
                <textarea class="form-control" id="notes" name="notes" rows="3"><?= e($projet['notes']) ?></textarea>
            </div>
        </div>
        
        <div class="d-flex justify-content-between">
            <a href="/admin/projets/liste.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i>Retour
            </a>
            <button type="submit" class="btn btn-success btn-lg">
                <i class="bi bi-check-circle me-1"></i>Enregistrer
            </button>
        </div>
    </form>
    
    <?php elseif ($tab === 'budgets'): ?>
    <!-- Onglet Budgets -->
    <form method="POST" action="">
        <?php csrfField(); ?>
        <input type="hidden" name="action" value="budgets">
        
        <?php foreach ($categoriesGroupees as $groupe => $cats): ?>
        <div class="card mb-4">
            <div class="card-header">
                <i class="bi bi-folder me-2"></i><?= $groupeLabels[$groupe] ?? ucfirst($groupe) ?>
            </div>
            <div class="card-body">
                <div class="row">
                    <?php foreach ($cats as $cat): ?>
                    <div class="col-md-4 col-lg-3">
                        <div class="mb-3">
                            <label for="budget_<?= $cat['id'] ?>" class="form-label"><?= e($cat['nom']) ?></label>
                            <div class="input-group">
                                <span class="input-group-text">$</span>
                                <input type="text" class="form-control money-input" 
                                       id="budget_<?= $cat['id'] ?>" 
                                       name="budget[<?= $cat['id'] ?>]" 
                                       value="<?= formatMoney($cat['montant_extrapole'], false) ?>">
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        
        <div class="d-flex justify-content-between">
            <a href="/admin/projets/liste.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i>Retour
            </a>
            <button type="submit" class="btn btn-success btn-lg">
                <i class="bi bi-check-circle me-1"></i>Enregistrer les budgets
            </button>
        </div>
    </form>
    <?php endif; ?>
</div>

<?php include '../../includes/footer.php'; ?>
