<?php
/**
 * Nouveau projet - Admin
 * Flip Manager
 */

require_once '../../config.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

// Vérifier que l'utilisateur est admin
requireAdmin();

$pageTitle = 'Nouveau projet';

$errors = [];

// Traitement du formulaire
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token de sécurité invalide.';
    } else {
        // Récupérer les données
        $nom = trim($_POST['nom'] ?? '');
        $adresse = trim($_POST['adresse'] ?? '');
        $ville = trim($_POST['ville'] ?? '');
        $codePostal = trim($_POST['code_postal'] ?? '');
        
        $dateAcquisition = $_POST['date_acquisition'] ?? null;
        $dateDebutTravaux = $_POST['date_debut_travaux'] ?? null;
        $dateFinPrevue = $_POST['date_fin_prevue'] ?? null;
        
        $statut = $_POST['statut'] ?? 'acquisition';
        
        $prixAchat = (float)($_POST['prix_achat'] ?? 0);
        $notaire = (float)($_POST['notaire'] ?? 0);
        $taxeMutation = (float)($_POST['taxe_mutation'] ?? 0);
        $arpenteurs = (float)($_POST['arpenteurs'] ?? 0);
        $assuranceTitre = (float)($_POST['assurance_titre'] ?? 0);
        
        $taxesMunicipalesAnnuel = (float)($_POST['taxes_municipales_annuel'] ?? 0);
        $taxesScolairesAnnuel = (float)($_POST['taxes_scolaires_annuel'] ?? 0);
        $electriciteAnnuel = (float)($_POST['electricite_annuel'] ?? 0);
        $assurancesAnnuel = (float)($_POST['assurances_annuel'] ?? 0);
        $deneigementAnnuel = (float)($_POST['deneigement_annuel'] ?? 0);
        $fraisCondoAnnuel = (float)($_POST['frais_condo_annuel'] ?? 0);
        $hypothequeMensuel = (float)($_POST['hypotheque_mensuel'] ?? 0);
        $loyerMensuel = (float)($_POST['loyer_mensuel'] ?? 0);
        
        $tempsAssumeMois = (int)($_POST['temps_assume_mois'] ?? 6);
        $valeurPotentielle = (float)($_POST['valeur_potentielle'] ?? 0);
        
        $tauxCommission = (float)($_POST['taux_commission'] ?? 4);
        $tauxContingence = (float)($_POST['taux_contingence'] ?? 15);
        $tauxInteret = (float)($_POST['taux_interet'] ?? 10);
        $montantPret = (float)($_POST['montant_pret'] ?? 0);
        
        $notes = trim($_POST['notes'] ?? '');
        
        // Validation
        if (empty($nom)) $errors[] = 'Le nom du projet est requis.';
        if (empty($adresse)) $errors[] = 'L\'adresse est requise.';
        if (empty($ville)) $errors[] = 'La ville est requise.';
        
        if (empty($errors)) {
            $stmt = $pdo->prepare("
                INSERT INTO projets (
                    nom, adresse, ville, code_postal,
                    date_acquisition, date_debut_travaux, date_fin_prevue,
                    statut, prix_achat, notaire, taxe_mutation, arpenteurs, assurance_titre,
                    taxes_municipales_annuel, taxes_scolaires_annuel, electricite_annuel,
                    assurances_annuel, deneigement_annuel, frais_condo_annuel,
                    hypotheque_mensuel, loyer_mensuel,
                    temps_assume_mois, valeur_potentielle,
                    taux_commission, taux_contingence, taux_interet, montant_pret,
                    notes
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $result = $stmt->execute([
                $nom, $adresse, $ville, $codePostal,
                $dateAcquisition ?: null, $dateDebutTravaux ?: null, $dateFinPrevue ?: null,
                $statut, $prixAchat, $notaire, $taxeMutation, $arpenteurs, $assuranceTitre,
                $taxesMunicipalesAnnuel, $taxesScolairesAnnuel, $electriciteAnnuel,
                $assurancesAnnuel, $deneigementAnnuel, $fraisCondoAnnuel,
                $hypothequeMensuel, $loyerMensuel,
                $tempsAssumeMois, $valeurPotentielle,
                $tauxCommission, $tauxContingence, $tauxInteret, $montantPret,
                $notes
            ]);
            
            if ($result) {
                $projetId = $pdo->lastInsertId();
                setFlashMessage('success', 'Projet créé avec succès!');
                redirect('/admin/projets/modifier.php?id=' . $projetId . '&tab=budgets');
            } else {
                $errors[] = 'Erreur lors de la création du projet.';
            }
        }
    }
}

include '../../includes/header.php';
?>

<div class="container-fluid">
    <!-- En-tête -->
    <div class="page-header">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="/admin/index.php">Tableau de bord</a></li>
                <li class="breadcrumb-item"><a href="/admin/projets/liste.php">Projets</a></li>
                <li class="breadcrumb-item active">Nouveau</li>
            </ol>
        </nav>
        <h1><i class="bi bi-plus-circle me-2"></i>Nouveau projet</h1>
    </div>
    
    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger">
            <i class="bi bi-exclamation-triangle me-2"></i>
            <strong>Erreur(s):</strong>
            <ul class="mb-0 mt-2">
                <?php foreach ($errors as $error): ?>
                    <li><?= e($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
    
    <form method="POST" action="">
        <?php csrfField(); ?>
        
        <!-- Informations générales -->
        <div class="card mb-4">
            <div class="card-header">
                <i class="bi bi-info-circle me-2"></i>Informations générales
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label for="nom" class="form-label">Nom du projet *</label>
                            <input type="text" class="form-control" id="nom" name="nom" 
                                   value="<?= e($_POST['nom'] ?? '') ?>" required>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label for="statut" class="form-label">Statut</label>
                            <select class="form-select" id="statut" name="statut">
                                <option value="acquisition" <?= ($_POST['statut'] ?? '') === 'acquisition' ? 'selected' : '' ?>>Acquisition</option>
                                <option value="renovation" <?= ($_POST['statut'] ?? '') === 'renovation' ? 'selected' : '' ?>>Rénovation</option>
                                <option value="vente" <?= ($_POST['statut'] ?? '') === 'vente' ? 'selected' : '' ?>>En vente</option>
                                <option value="vendu" <?= ($_POST['statut'] ?? '') === 'vendu' ? 'selected' : '' ?>>Vendu</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label for="adresse" class="form-label">Adresse *</label>
                            <input type="text" class="form-control" id="adresse" name="adresse" 
                                   value="<?= e($_POST['adresse'] ?? '') ?>" required>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="mb-3">
                            <label for="ville" class="form-label">Ville *</label>
                            <input type="text" class="form-control" id="ville" name="ville" 
                                   value="<?= e($_POST['ville'] ?? '') ?>" required>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="mb-3">
                            <label for="code_postal" class="form-label">Code postal</label>
                            <input type="text" class="form-control" id="code_postal" name="code_postal" 
                                   value="<?= e($_POST['code_postal'] ?? '') ?>">
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-4">
                        <div class="mb-3">
                            <label for="date_acquisition" class="form-label">Date d'acquisition</label>
                            <input type="date" class="form-control" id="date_acquisition" name="date_acquisition" 
                                   value="<?= e($_POST['date_acquisition'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="mb-3">
                            <label for="date_debut_travaux" class="form-label">Début des travaux</label>
                            <input type="date" class="form-control" id="date_debut_travaux" name="date_debut_travaux" 
                                   value="<?= e($_POST['date_debut_travaux'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="mb-3">
                            <label for="date_fin_prevue" class="form-label">Fin prévue</label>
                            <input type="date" class="form-control" id="date_fin_prevue" name="date_fin_prevue" 
                                   value="<?= e($_POST['date_fin_prevue'] ?? '') ?>">
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Coûts d'acquisition -->
        <div class="card mb-4">
            <div class="card-header">
                <i class="bi bi-currency-dollar me-2"></i>Coûts d'acquisition
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4">
                        <div class="mb-3">
                            <label for="prix_achat" class="form-label">Prix d'achat</label>
                            <div class="input-group">
                                <span class="input-group-text">$</span>
                                <input type="number" class="form-control" id="prix_achat" name="prix_achat" 
                                       step="0.01" value="<?= e($_POST['prix_achat'] ?? '0') ?>">
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="mb-3">
                            <label for="valeur_potentielle" class="form-label">Valeur potentielle (vente)</label>
                            <div class="input-group">
                                <span class="input-group-text">$</span>
                                <input type="number" class="form-control" id="valeur_potentielle" name="valeur_potentielle" 
                                       step="0.01" value="<?= e($_POST['valeur_potentielle'] ?? '0') ?>">
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="mb-3">
                            <label for="temps_assume_mois" class="form-label">Temps assumé (mois)</label>
                            <input type="number" class="form-control" id="temps_assume_mois" name="temps_assume_mois" 
                                   value="<?= e($_POST['temps_assume_mois'] ?? '6') ?>">
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-3">
                        <div class="mb-3">
                            <label for="notaire" class="form-label">Notaire</label>
                            <div class="input-group">
                                <span class="input-group-text">$</span>
                                <input type="number" class="form-control" id="notaire" name="notaire" 
                                       step="0.01" value="<?= e($_POST['notaire'] ?? '0') ?>">
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="mb-3">
                            <label for="taxe_mutation" class="form-label">Taxe de mutation</label>
                            <div class="input-group">
                                <span class="input-group-text">$</span>
                                <input type="number" class="form-control" id="taxe_mutation" name="taxe_mutation" 
                                       step="0.01" value="<?= e($_POST['taxe_mutation'] ?? '0') ?>">
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="mb-3">
                            <label for="arpenteurs" class="form-label">Arpenteurs</label>
                            <div class="input-group">
                                <span class="input-group-text">$</span>
                                <input type="number" class="form-control" id="arpenteurs" name="arpenteurs" 
                                       step="0.01" value="<?= e($_POST['arpenteurs'] ?? '0') ?>">
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="mb-3">
                            <label for="assurance_titre" class="form-label">Assurance titre</label>
                            <div class="input-group">
                                <span class="input-group-text">$</span>
                                <input type="number" class="form-control" id="assurance_titre" name="assurance_titre" 
                                       step="0.01" value="<?= e($_POST['assurance_titre'] ?? '0') ?>">
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Coûts récurrents -->
        <div class="card mb-4">
            <div class="card-header">
                <i class="bi bi-arrow-repeat me-2"></i>Coûts récurrents (annuels)
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-3">
                        <div class="mb-3">
                            <label for="taxes_municipales_annuel" class="form-label">Taxes municipales</label>
                            <div class="input-group">
                                <span class="input-group-text">$</span>
                                <input type="number" class="form-control" id="taxes_municipales_annuel" name="taxes_municipales_annuel" 
                                       step="0.01" value="<?= e($_POST['taxes_municipales_annuel'] ?? '0') ?>">
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="mb-3">
                            <label for="taxes_scolaires_annuel" class="form-label">Taxes scolaires</label>
                            <div class="input-group">
                                <span class="input-group-text">$</span>
                                <input type="number" class="form-control" id="taxes_scolaires_annuel" name="taxes_scolaires_annuel" 
                                       step="0.01" value="<?= e($_POST['taxes_scolaires_annuel'] ?? '0') ?>">
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="mb-3">
                            <label for="electricite_annuel" class="form-label">Électricité</label>
                            <div class="input-group">
                                <span class="input-group-text">$</span>
                                <input type="number" class="form-control" id="electricite_annuel" name="electricite_annuel" 
                                       step="0.01" value="<?= e($_POST['electricite_annuel'] ?? '0') ?>">
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="mb-3">
                            <label for="assurances_annuel" class="form-label">Assurances</label>
                            <div class="input-group">
                                <span class="input-group-text">$</span>
                                <input type="number" class="form-control" id="assurances_annuel" name="assurances_annuel" 
                                       step="0.01" value="<?= e($_POST['assurances_annuel'] ?? '0') ?>">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-3">
                        <div class="mb-3">
                            <label for="deneigement_annuel" class="form-label">Déneigement</label>
                            <div class="input-group">
                                <span class="input-group-text">$</span>
                                <input type="number" class="form-control" id="deneigement_annuel" name="deneigement_annuel" 
                                       step="0.01" value="<?= e($_POST['deneigement_annuel'] ?? '0') ?>">
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="mb-3">
                            <label for="frais_condo_annuel" class="form-label">Frais condo</label>
                            <div class="input-group">
                                <span class="input-group-text">$</span>
                                <input type="number" class="form-control" id="frais_condo_annuel" name="frais_condo_annuel" 
                                       step="0.01" value="<?= e($_POST['frais_condo_annuel'] ?? '0') ?>">
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="mb-3">
                            <label for="hypotheque_mensuel" class="form-label">Hypothèque (mensuel)</label>
                            <div class="input-group">
                                <span class="input-group-text">$</span>
                                <input type="number" class="form-control" id="hypotheque_mensuel" name="hypotheque_mensuel" 
                                       step="0.01" value="<?= e($_POST['hypotheque_mensuel'] ?? '0') ?>">
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="mb-3">
                            <label for="loyer_mensuel" class="form-label">Loyer reçu (mensuel)</label>
                            <div class="input-group">
                                <span class="input-group-text">$</span>
                                <input type="number" class="form-control" id="loyer_mensuel" name="loyer_mensuel" 
                                       step="0.01" value="<?= e($_POST['loyer_mensuel'] ?? '0') ?>">
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Financement -->
        <div class="card mb-4">
            <div class="card-header">
                <i class="bi bi-bank me-2"></i>Financement et commission
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-3">
                        <div class="mb-3">
                            <label for="montant_pret" class="form-label">Montant du prêt</label>
                            <div class="input-group">
                                <span class="input-group-text">$</span>
                                <input type="number" class="form-control" id="montant_pret" name="montant_pret" 
                                       step="0.01" value="<?= e($_POST['montant_pret'] ?? '0') ?>">
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="mb-3">
                            <label for="taux_interet" class="form-label">Taux d'intérêt</label>
                            <div class="input-group">
                                <input type="number" class="form-control" id="taux_interet" name="taux_interet" 
                                       step="0.01" value="<?= e($_POST['taux_interet'] ?? '10') ?>">
                                <span class="input-group-text">%</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="mb-3">
                            <label for="taux_commission" class="form-label">Commission courtier</label>
                            <div class="input-group">
                                <input type="number" class="form-control" id="taux_commission" name="taux_commission" 
                                       step="0.01" value="<?= e($_POST['taux_commission'] ?? '4') ?>">
                                <span class="input-group-text">%</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="mb-3">
                            <label for="taux_contingence" class="form-label">Contingence</label>
                            <div class="input-group">
                                <input type="number" class="form-control" id="taux_contingence" name="taux_contingence" 
                                       step="0.01" value="<?= e($_POST['taux_contingence'] ?? '15') ?>">
                                <span class="input-group-text">%</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Notes -->
        <div class="card mb-4">
            <div class="card-header">
                <i class="bi bi-sticky me-2"></i>Notes
            </div>
            <div class="card-body">
                <textarea class="form-control" id="notes" name="notes" rows="3"><?= e($_POST['notes'] ?? '') ?></textarea>
            </div>
        </div>
        
        <!-- Actions -->
        <div class="d-flex justify-content-between">
            <a href="/admin/projets/liste.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i>
                Retour
            </a>
            <button type="submit" class="btn btn-primary btn-lg">
                <i class="bi bi-check-circle me-1"></i>
                Créer le projet
            </button>
        </div>
    </form>
</div>

<?php include '../../includes/footer.php'; ?>
