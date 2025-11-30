<?php
/**
 * Rapports - Admin
 * Flip Manager
 */

require_once '../../config.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../includes/calculs.php';

requireAdmin();

$pageTitle = 'Rapports';

// Filtres
$filtreProjet = isset($_GET['projet']) ? (int)$_GET['projet'] : 0;
$filtreCategorie = isset($_GET['categorie']) ? (int)$_GET['categorie'] : 0;
$filtreGroupe = isset($_GET['groupe']) ? $_GET['groupe'] : '';
$dateDebut = isset($_GET['date_debut']) ? $_GET['date_debut'] : date('Y-m-01'); // Premier jour du mois
$dateFin = isset($_GET['date_fin']) ? $_GET['date_fin'] : date('Y-m-d'); // Aujourd'hui

// Récupérer les projets et catégories pour les filtres
$projets = getProjets($pdo, false);
$categories = getCategories($pdo);

// Liste des groupes de catégories
$groupes = [
    'exterieur' => 'Extérieur',
    'finition' => 'Finition intérieure',
    'ebenisterie' => 'Ébénisterie',
    'electricite' => 'Électricité',
    'plomberie' => 'Plomberie',
    'autre' => 'Autre'
];

// Construire les conditions WHERE
$whereFactures = "WHERE f.statut = 'approuvee'";
$whereHeures = "WHERE h.statut = 'approuve'";
$params = [];

if ($filtreProjet > 0) {
    $whereFactures .= " AND f.projet_id = ?";
    $whereHeures .= " AND h.projet_id = ?";
    $params[] = $filtreProjet;
}

if ($dateDebut) {
    $whereFactures .= " AND f.date_facture >= ?";
    $whereHeures .= " AND h.date_travail >= ?";
    $params[] = $dateDebut;
}

if ($dateFin) {
    $whereFactures .= " AND f.date_facture <= ?";
    $whereHeures .= " AND h.date_travail <= ?";
    $params[] = $dateFin;
}

// Construire les filtres additionnels pour les factures
$filtreFacturesAdd = "";
if ($filtreCategorie > 0) {
    $filtreFacturesAdd .= " AND f.categorie_id = $filtreCategorie";
}
if ($filtreGroupe !== '') {
    $filtreFacturesAdd .= " AND c.groupe = '$filtreGroupe'";
}

// 1. Rapport par projet
$sqlProjet = "
    SELECT p.id, p.nom, p.adresse,
           COALESCE(SUM(f.montant_total), 0) as total_factures
    FROM projets p
    LEFT JOIN factures f ON f.projet_id = p.id AND f.statut = 'approuvee'
        " . ($dateDebut ? "AND f.date_facture >= '$dateDebut'" : "") . "
        " . ($dateFin ? "AND f.date_facture <= '$dateFin'" : "") . "
        " . ($filtreCategorie > 0 ? "AND f.categorie_id = $filtreCategorie" : "") . "
    " . ($filtreGroupe !== '' ? "LEFT JOIN categories c ON f.categorie_id = c.id" : "") . "
    " . ($filtreProjet > 0 ? "WHERE p.id = $filtreProjet" : "") . "
    " . ($filtreGroupe !== '' && $filtreProjet == 0 ? "WHERE c.groupe = '$filtreGroupe'" : "") . "
    " . ($filtreGroupe !== '' && $filtreProjet > 0 ? "AND c.groupe = '$filtreGroupe'" : "") . "
    GROUP BY p.id, p.nom, p.adresse
    ORDER BY p.nom
";
$rapportProjets = $pdo->query($sqlProjet)->fetchAll();

// Ajouter les heures par projet
foreach ($rapportProjets as &$projet) {
    try {
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(h.heures * h.taux_horaire), 0) as cout_main_oeuvre,
                   COALESCE(SUM(h.heures), 0) as total_heures
            FROM heures_travaillees h
            WHERE h.projet_id = ? AND h.statut = 'approuvee'
            " . ($dateDebut ? "AND h.date_travail >= '$dateDebut'" : "") . "
            " . ($dateFin ? "AND h.date_travail <= '$dateFin'" : "") . "
        ");
        $stmt->execute([$projet['id']]);
        $heures = $stmt->fetch();
        $projet['cout_main_oeuvre'] = $heures['cout_main_oeuvre'] ?? 0;
        $projet['total_heures'] = $heures['total_heures'] ?? 0;
    } catch (Exception $e) {
        $projet['cout_main_oeuvre'] = 0;
        $projet['total_heures'] = 0;
    }
    $projet['total_global'] = $projet['total_factures'] + $projet['cout_main_oeuvre'];
}
unset($projet);

// 2. Rapport par catégorie
$sqlCategorie = "
    SELECT c.nom as categorie, c.groupe,
           COALESCE(SUM(f.montant_total), 0) as total
    FROM categories c
    LEFT JOIN factures f ON f.categorie_id = c.id AND f.statut = 'approuvee'
        " . ($dateDebut ? "AND f.date_facture >= '$dateDebut'" : "") . "
        " . ($dateFin ? "AND f.date_facture <= '$dateFin'" : "") . "
        " . ($filtreProjet > 0 ? "AND f.projet_id = $filtreProjet" : "") . "
    WHERE 1=1
    " . ($filtreCategorie > 0 ? "AND c.id = $filtreCategorie" : "") . "
    " . ($filtreGroupe !== '' ? "AND c.groupe = '$filtreGroupe'" : "") . "
    GROUP BY c.id, c.nom, c.groupe
    HAVING total > 0
    ORDER BY total DESC
";
$rapportCategories = $pdo->query($sqlCategorie)->fetchAll();

// 3. Rapport par employé (heures)
try {
    $sqlEmployes = "
        SELECT CONCAT(u.prenom, ' ', u.nom) as employe,
               u.taux_horaire,
               COALESCE(SUM(h.heures), 0) as total_heures,
               COALESCE(SUM(h.heures * h.taux_horaire), 0) as cout_total
        FROM users u
        LEFT JOIN heures_travaillees h ON h.user_id = u.id AND h.statut = 'approuvee'
            " . ($dateDebut ? "AND h.date_travail >= '$dateDebut'" : "") . "
            " . ($dateFin ? "AND h.date_travail <= '$dateFin'" : "") . "
            " . ($filtreProjet > 0 ? "AND h.projet_id = $filtreProjet" : "") . "
        WHERE u.actif = 1
        GROUP BY u.id, u.prenom, u.nom, u.taux_horaire
        HAVING total_heures > 0
        ORDER BY total_heures DESC
    ";
    $rapportEmployes = $pdo->query($sqlEmployes)->fetchAll();
} catch (Exception $e) {
    $rapportEmployes = [];
}

// Totaux globaux
$totalFactures = array_sum(array_column($rapportProjets, 'total_factures'));
$totalMainOeuvre = array_sum(array_column($rapportProjets, 'cout_main_oeuvre'));
$totalGlobal = $totalFactures + $totalMainOeuvre;

// Nom du projet filtré
$nomProjetFiltre = 'Tous les projets';
if ($filtreProjet > 0) {
    foreach ($projets as $p) {
        if ($p['id'] == $filtreProjet) {
            $nomProjetFiltre = $p['nom'];
            break;
        }
    }
}

// Nom de la catégorie/groupe filtré
$nomFiltreCategorie = '';
if ($filtreCategorie > 0) {
    foreach ($categories as $c) {
        if ($c['id'] == $filtreCategorie) {
            $nomFiltreCategorie = $c['nom'];
            break;
        }
    }
}
if ($filtreGroupe !== '') {
    $nomFiltreCategorie = $groupes[$filtreGroupe] ?? $filtreGroupe;
}

// Si un projet est sélectionné, charger les indicateurs financiers détaillés
$indicateurs = null;
$projet = null;
if ($filtreProjet > 0) {
    $projet = getProjetById($pdo, $filtreProjet);
    if ($projet) {
        $indicateurs = calculerIndicateursProjet($pdo, $projet);
        
        // Calculer la durée réelle
        $dureeReelle = (int)$projet['temps_assume_mois'];
        if (!empty($projet['date_vente']) && !empty($projet['date_acquisition'])) {
            $dateAchat = new DateTime($projet['date_acquisition']);
            $dateVente = new DateTime($projet['date_vente']);
            $diff = $dateAchat->diff($dateVente);
            $dureeReelle = ($diff->y * 12) + $diff->m + ($diff->d > 15 ? 1 : 0);
            $dureeReelle = max(1, $dureeReelle);
        }
    }
}

include '../../includes/header.php';
?>

<div class="container-fluid">
    <!-- En-tête -->
    <div class="page-header d-flex justify-content-between align-items-start flex-wrap no-print">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="/admin/index.php">Tableau de bord</a></li>
                    <li class="breadcrumb-item active">Administration</li>
                </ol>
            </nav>
            <h1><i class="bi bi-gear me-2"></i>Administration</h1>
        </div>
        <div class="d-flex gap-2 mt-2 mt-md-0">
            <a href="/admin/utilisateurs/liste.php" class="btn btn-outline-secondary">
                <i class="bi bi-person-badge me-1"></i>Utilisateurs
            </a>
            <a href="/admin/categories/liste.php" class="btn btn-outline-secondary">
                <i class="bi bi-tags me-1"></i>Catégories
            </a>
            <button type="button" class="btn btn-primary" onclick="window.print()">
                <i class="bi bi-printer me-1"></i>Imprimer
            </button>
        </div>
    </div>
    
    <!-- Sous-navigation Administration -->
    <ul class="nav nav-tabs mb-4 no-print">
        <li class="nav-item">
            <a class="nav-link" href="/admin/utilisateurs/liste.php">
                <i class="bi bi-person-badge me-1"></i>Utilisateurs
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="/admin/categories/liste.php">
                <i class="bi bi-tags me-1"></i>Catégories
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link active" href="/admin/rapports/index.php">
                <i class="bi bi-file-earmark-bar-graph me-1"></i>Rapports
            </a>
        </li>
    </ul>
    
    <!-- Filtres -->
    <div class="card mb-4 no-print">
        <div class="card-body">
            <form method="GET" action="" class="row g-3" id="filterForm">
                <div class="col-md-3">
                    <label for="projet" class="form-label">Projet</label>
                    <select class="form-select auto-submit" id="projet" name="projet">
                        <option value="">Tous les projets</option>
                        <?php foreach ($projets as $projet): ?>
                            <option value="<?= $projet['id'] ?>" <?= $filtreProjet == $projet['id'] ? 'selected' : '' ?>>
                                <?= e($projet['nom']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="groupe" class="form-label">Type de dépense</label>
                    <select class="form-select auto-submit" id="groupe" name="groupe">
                        <option value="">Tous les types</option>
                        <?php foreach ($groupes as $key => $label): ?>
                            <option value="<?= $key ?>" <?= $filtreGroupe === $key ? 'selected' : '' ?>>
                                <?= e($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="categorie" class="form-label">Catégorie</label>
                    <select class="form-select auto-submit" id="categorie" name="categorie">
                        <option value="">Toutes</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat['id'] ?>" <?= $filtreCategorie == $cat['id'] ? 'selected' : '' ?>>
                                <?= e($cat['nom']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="date_debut" class="form-label">Date début</label>
                    <input type="date" class="form-control auto-submit" id="date_debut" name="date_debut" 
                           value="<?= e($dateDebut) ?>">
                </div>
                <div class="col-md-2">
                    <label for="date_fin" class="form-label">Date fin</label>
                    <input type="date" class="form-control auto-submit" id="date_fin" name="date_fin" 
                           value="<?= e($dateFin) ?>">
                </div>
                <div class="col-md-1 d-flex align-items-end">
                    <a href="/admin/rapports/index.php" class="btn btn-outline-secondary" title="Réinitialiser">
                        <i class="bi bi-x-circle"></i>
                    </a>
                </div>
            </form>
            
            <!-- Sections à afficher -->
            <hr class="my-3">
            <div class="row">
                <div class="col-12">
                    <label class="form-label fw-bold"><i class="bi bi-eye me-1"></i>Sections à afficher :</label>
                </div>
                <div class="col-md-12">
                    <div class="d-flex flex-wrap gap-3">
                        <div class="form-check">
                            <input class="form-check-input section-toggle" type="checkbox" id="showResume" data-section="section-resume">
                            <label class="form-check-label" for="showResume">Résumé global</label>
                        </div>
                        <?php if ($filtreProjet > 0): ?>
                        <div class="form-check">
                            <input class="form-check-input section-toggle" type="checkbox" id="showFinancier" data-section="section-financier">
                            <label class="form-check-label" for="showFinancier">Résumé financier</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input section-toggle" type="checkbox" id="showAcquisition" data-section="section-acquisition">
                            <label class="form-check-label" for="showAcquisition">Coûts acquisition</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input section-toggle" type="checkbox" id="showRecurrents" data-section="section-recurrents">
                            <label class="form-check-label" for="showRecurrents">Coûts récurrents</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input section-toggle" type="checkbox" id="showVente" data-section="section-vente">
                            <label class="form-check-label" for="showVente">Coûts de vente / Intérêts</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input section-toggle" type="checkbox" id="showPreteurs" data-section="section-preteurs">
                            <label class="form-check-label" for="showPreteurs">Prêteurs</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input section-toggle" type="checkbox" id="showInvestisseurs" data-section="section-investisseurs">
                            <label class="form-check-label" for="showInvestisseurs">Investisseurs</label>
                        </div>
                        <?php endif; ?>
                        <div class="form-check">
                            <input class="form-check-input section-toggle" type="checkbox" id="showProjets" data-section="section-projets">
                            <label class="form-check-label" for="showProjets">Coûts par projet</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input section-toggle" type="checkbox" id="showCategories" data-section="section-categories">
                            <label class="form-check-label" for="showCategories">Dépenses par catégorie</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input section-toggle" type="checkbox" id="showEmployes" data-section="section-employes">
                            <label class="form-check-label" for="showEmployes">Heures par employé</label>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
    document.querySelectorAll('.auto-submit').forEach(function(element) {
        element.addEventListener('change', function() {
            document.getElementById('filterForm').submit();
        });
    });
    
    // Gestion des sections à afficher
    document.querySelectorAll('.section-toggle').forEach(function(checkbox) {
        // Cacher toutes les sections au chargement
        var sectionId = checkbox.getAttribute('data-section');
        var section = document.getElementById(sectionId);
        if (section) {
            section.style.display = 'none';
        }
        
        // Événement de changement
        checkbox.addEventListener('change', function() {
            var sectionId = this.getAttribute('data-section');
            var section = document.getElementById(sectionId);
            if (section) {
                section.style.display = this.checked ? '' : 'none';
            }
        });
    });
    </script>
    
    <!-- Zone imprimable -->
    <div id="rapport-imprimable">
        <!-- En-tête du rapport (visible seulement à l'impression) -->
        <div class="print-header">
            <h2><?= APP_NAME ?> - Rapport</h2>
            <p>
                <strong>Projet:</strong> <?= e($nomProjetFiltre) ?><br>
                <?php if ($nomFiltreCategorie): ?>
                    <strong>Filtre:</strong> <?= e($nomFiltreCategorie) ?><br>
                <?php endif; ?>
                <strong>Période:</strong> <?= formatDate($dateDebut) ?> au <?= formatDate($dateFin) ?><br>
                <strong>Généré le:</strong> <?= formatDateTime(date('Y-m-d H:i:s')) ?>
            </p>
        </div>
        
        <!-- Résumé global -->
        <div class="row mb-4" id="section-resume">
            <div class="col-md-4">
                <div class="card bg-primary text-white">
                    <div class="card-body text-center">
                        <h6 class="card-title mb-1">Total Factures</h6>
                        <h3 class="mb-0"><?= formatMoney($totalFactures) ?></h3>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card bg-info text-white">
                    <div class="card-body text-center">
                        <h6 class="card-title mb-1">Main d'œuvre</h6>
                        <h3 class="mb-0"><?= formatMoney($totalMainOeuvre) ?></h3>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card bg-success text-white">
                    <div class="card-body text-center">
                        <h6 class="card-title mb-1">Total Global</h6>
                        <h3 class="mb-0"><?= formatMoney($totalGlobal) ?></h3>
                    </div>
                </div>
            </div>
        </div>
        
        <?php if ($projet && $indicateurs): ?>
        <!-- Résumé financier du projet sélectionné -->
        <div class="card mb-4" id="section-financier">
            <div class="card-header">
                <i class="bi bi-calculator me-2"></i>Résumé financier - <?= e($projet['nom']) ?>
                <small class="text-muted float-end"><?= e($projet['adresse']) ?>, <?= e($projet['ville']) ?></small>
            </div>
            <div class="card-body">
                <div class="row">
                    <!-- Colonne gauche: Coûts -->
                    <div class="col-md-6">
                        <div id="section-acquisition">
                        <h6 class="border-bottom pb-2 mb-3"><i class="bi bi-cart me-1"></i>Coûts d'acquisition</h6>
                        <table class="table table-sm">
                            <tr>
                                <td>Prix d'achat</td>
                                <td class="text-end"><strong><?= formatMoney($projet['prix_achat']) ?></strong></td>
                            </tr>
                            <tr>
                                <td>Notaire</td>
                                <td class="text-end"><?= formatMoney($indicateurs['couts_acquisition']['notaire']) ?></td>
                            </tr>
                            <tr>
                                <td>Taxe de mutation</td>
                                <td class="text-end"><?= formatMoney($indicateurs['couts_acquisition']['taxe_mutation']) ?></td>
                            </tr>
                            <tr>
                                <td>Arpenteurs</td>
                                <td class="text-end"><?= formatMoney($indicateurs['couts_acquisition']['arpenteurs']) ?></td>
                            </tr>
                            <tr>
                                <td>Assurance titre</td>
                                <td class="text-end"><?= formatMoney($indicateurs['couts_acquisition']['assurance_titre']) ?></td>
                            </tr>
                            <tr class="table-secondary">
                                <td><strong>Total acquisition</strong></td>
                                <td class="text-end"><strong><?= formatMoney($indicateurs['couts_acquisition']['total']) ?></strong></td>
                            </tr>
                        </table>
                        </div>
                        
                        <div id="section-recurrents">
                        <h6 class="border-bottom pb-2 mb-3 mt-4"><i class="bi bi-arrow-repeat me-1"></i>Coûts récurrents (<?= $dureeReelle ?> mois)</h6>
                        <table class="table table-sm">
                            <tr>
                                <td>Taxes municipales</td>
                                <td class="text-end"><?= formatMoney($indicateurs['couts_recurrents']['taxes_municipales']['extrapole']) ?></td>
                            </tr>
                            <tr>
                                <td>Taxes scolaires</td>
                                <td class="text-end"><?= formatMoney($indicateurs['couts_recurrents']['taxes_scolaires']['extrapole']) ?></td>
                            </tr>
                            <tr>
                                <td>Électricité</td>
                                <td class="text-end"><?= formatMoney($indicateurs['couts_recurrents']['electricite']['extrapole']) ?></td>
                            </tr>
                            <tr>
                                <td>Assurances</td>
                                <td class="text-end"><?= formatMoney($indicateurs['couts_recurrents']['assurances']['extrapole']) ?></td>
                            </tr>
                            <tr>
                                <td>Déneigement</td>
                                <td class="text-end"><?= formatMoney($indicateurs['couts_recurrents']['deneigement']['extrapole']) ?></td>
                            </tr>
                            <tr>
                                <td>Hypothèque</td>
                                <td class="text-end"><?= formatMoney($indicateurs['couts_recurrents']['hypotheque']['extrapole']) ?></td>
                            </tr>
                            <tr class="table-secondary">
                                <td><strong>Total récurrents</strong></td>
                                <td class="text-end"><strong><?= formatMoney($indicateurs['couts_recurrents']['total']) ?></strong></td>
                            </tr>
                        </table>
                        </div>
                    </div>
                    
                    <!-- Colonne droite: Vente et résumé -->
                    <div class="col-md-6">
                        <div id="section-vente">
                        <h6 class="border-bottom pb-2 mb-3"><i class="bi bi-shop me-1"></i>Coûts de vente</h6>
                        <table class="table table-sm">
                            <tr>
                                <td>Intérêts (<?= $dureeReelle ?> mois @ <?= $projet['taux_interet'] ?>%)</td>
                                <td class="text-end"><strong class="text-danger"><?= formatMoney($indicateurs['couts_vente']['interets']) ?></strong></td>
                            </tr>
                            <tr>
                                <td>Commission courtier (<?= $projet['taux_commission'] ?>% + taxes)</td>
                                <td class="text-end"><?= formatMoney($indicateurs['couts_vente']['commission'] * 1.14975) ?></td>
                            </tr>
                            <tr>
                                <td>Quittance</td>
                                <td class="text-end"><?= formatMoney($indicateurs['couts_vente']['quittance']) ?></td>
                            </tr>
                            <tr class="table-secondary">
                                <td><strong>Total vente</strong></td>
                                <td class="text-end"><strong><?= formatMoney($indicateurs['couts_vente']['total']) ?></strong></td>
                            </tr>
                        </table>
                        </div>
                        
                        <h6 class="border-bottom pb-2 mb-3 mt-4"><i class="bi bi-graph-up me-1"></i>Résumé du projet</h6>
                        <table class="table table-sm">
                            <tr>
                                <td>Prix d'achat</td>
                                <td class="text-end"><?= formatMoney($projet['prix_achat']) ?></td>
                            </tr>
                            <tr>
                                <td>Rénovation (budget)</td>
                                <td class="text-end"><?= formatMoney($indicateurs['renovation']['budget']) ?></td>
                            </tr>
                            <tr>
                                <td>Contingence (<?= $projet['taux_contingence'] ?>%)</td>
                                <td class="text-end"><?= formatMoney($indicateurs['contingence']) ?></td>
                            </tr>
                            <tr>
                                <td>Coûts fixes totaux</td>
                                <td class="text-end"><?= formatMoney($indicateurs['couts_fixes_totaux']) ?></td>
                            </tr>
                            <tr class="table-primary">
                                <td><strong>Coût total projet</strong></td>
                                <td class="text-end"><strong><?= formatMoney($indicateurs['cout_total_projet']) ?></strong></td>
                            </tr>
                            <tr>
                                <td>Valeur potentielle</td>
                                <td class="text-end"><?= formatMoney($indicateurs['valeur_potentielle']) ?></td>
                            </tr>
                            <tr class="<?= $indicateurs['equite_potentielle'] >= 0 ? 'table-success' : 'table-danger' ?>">
                                <td><strong>Équité potentielle (profit)</strong></td>
                                <td class="text-end"><strong><?= formatMoney($indicateurs['equite_potentielle']) ?></strong></td>
                            </tr>
                            <tr>
                                <td>ROI @ Leverage</td>
                                <td class="text-end"><strong><?= formatPercent($indicateurs['roi_leverage']) ?></strong></td>
                            </tr>
                        </table>
                    </div>
                </div>
                
                <?php if (!empty($indicateurs['preteurs']) || !empty($indicateurs['investisseurs'])): ?>
                <hr>
                <div class="row">
                    <?php if (!empty($indicateurs['preteurs'])): ?>
                    <div class="col-md-6" id="section-preteurs">
                        <h6><i class="bi bi-bank me-1"></i>Prêteurs</h6>
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Nom</th>
                                    <th class="text-end">Montant</th>
                                    <th class="text-center">Taux</th>
                                    <th class="text-end">Intérêts</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($indicateurs['preteurs'] as $p): ?>
                                    <tr>
                                        <td><?= e($p['nom']) ?></td>
                                        <td class="text-end"><?= formatMoney($p['montant']) ?></td>
                                        <td class="text-center"><?= $p['taux'] ?>%</td>
                                        <td class="text-end text-danger"><?= formatMoney($p['interets_total']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr class="table-warning">
                                    <td><strong>Total</strong></td>
                                    <td class="text-end"><strong><?= formatMoney($indicateurs['total_prets']) ?></strong></td>
                                    <td></td>
                                    <td class="text-end text-danger"><strong><?= formatMoney($indicateurs['total_interets']) ?></strong></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($indicateurs['investisseurs'])): ?>
                    <div class="col-md-6" id="section-investisseurs">
                        <h6><i class="bi bi-people me-1"></i>Investisseurs</h6>
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Nom</th>
                                    <th class="text-end">Mise</th>
                                    <th class="text-center">%</th>
                                    <th class="text-end">Profit</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($indicateurs['investisseurs'] as $inv): 
                                    $pct = !empty($inv['pourcentage']) ? $inv['pourcentage'] : ($inv['pourcentage_calcule'] ?? 0);
                                ?>
                                    <tr>
                                        <td><?= e($inv['nom']) ?></td>
                                        <td class="text-end"><?= formatMoney($inv['mise_de_fonds']) ?></td>
                                        <td class="text-center"><?= number_format($pct, 1) ?>%</td>
                                        <td class="text-end text-success"><?= formatMoney($inv['profit_estime']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Rapport par projet -->
        <div class="card mb-4" id="section-projets">
            <div class="card-header">
                <i class="bi bi-building me-2"></i>Coûts par projet
            </div>
            <div class="card-body p-0">
                <?php if (empty($rapportProjets)): ?>
                    <p class="text-muted p-3 mb-0">Aucune donnée pour cette période.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Projet</th>
                                    <th class="text-end">Factures</th>
                                    <th class="text-end">Heures</th>
                                    <th class="text-end">Main d'œuvre</th>
                                    <th class="text-end">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rapportProjets as $projet): ?>
                                    <?php if ($projet['total_global'] > 0): ?>
                                        <tr>
                                            <td>
                                                <strong><?= e($projet['nom']) ?></strong>
                                                <?php if ($projet['adresse']): ?>
                                                    <br><small class="text-muted"><?= e($projet['adresse']) ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end"><?= formatMoney($projet['total_factures']) ?></td>
                                            <td class="text-end"><?= number_format($projet['total_heures'], 1) ?> h</td>
                                            <td class="text-end"><?= formatMoney($projet['cout_main_oeuvre']) ?></td>
                                            <td class="text-end"><strong><?= formatMoney($projet['total_global']) ?></strong></td>
                                        </tr>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr class="table-dark">
                                    <th>Total</th>
                                    <th class="text-end"><?= formatMoney($totalFactures) ?></th>
                                    <th class="text-end"><?= number_format(array_sum(array_column($rapportProjets, 'total_heures')), 1) ?> h</th>
                                    <th class="text-end"><?= formatMoney($totalMainOeuvre) ?></th>
                                    <th class="text-end"><?= formatMoney($totalGlobal) ?></th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Rapport par catégorie -->
        <div class="card mb-4" id="section-categories">
            <div class="card-header">
                <i class="bi bi-tags me-2"></i>Dépenses par catégorie
            </div>
            <div class="card-body p-0">
                <?php if (empty($rapportCategories)): ?>
                    <p class="text-muted p-3 mb-0">Aucune donnée pour cette période.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Catégorie</th>
                                    <th>Groupe</th>
                                    <th class="text-end">Montant</th>
                                    <th class="text-end">%</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rapportCategories as $cat): ?>
                                    <tr>
                                        <td><?= e($cat['categorie']) ?></td>
                                        <td><span class="badge bg-secondary"><?= e(ucfirst($cat['groupe'])) ?></span></td>
                                        <td class="text-end"><?= formatMoney($cat['total']) ?></td>
                                        <td class="text-end"><?= $totalFactures > 0 ? number_format(($cat['total'] / $totalFactures) * 100, 1) : 0 ?>%</td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr class="table-dark">
                                    <th colspan="2">Total</th>
                                    <th class="text-end"><?= formatMoney($totalFactures) ?></th>
                                    <th class="text-end">100%</th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Rapport par employé -->
        <div class="card mb-4" id="section-employes">
            <div class="card-header">
                <i class="bi bi-people me-2"></i>Heures par employé
            </div>
            <div class="card-body p-0">
                <?php if (empty($rapportEmployes)): ?>
                    <p class="text-muted p-3 mb-0">Aucune donnée pour cette période.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Employé</th>
                                    <th class="text-end">Taux horaire</th>
                                    <th class="text-end">Heures</th>
                                    <th class="text-end">Coût total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rapportEmployes as $emp): ?>
                                    <tr>
                                        <td><?= e($emp['employe']) ?></td>
                                        <td class="text-end"><?= formatMoney($emp['taux_horaire']) ?>/h</td>
                                        <td class="text-end"><?= number_format($emp['total_heures'], 1) ?> h</td>
                                        <td class="text-end"><strong><?= formatMoney($emp['cout_total']) ?></strong></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr class="table-dark">
                                    <th colspan="2">Total</th>
                                    <th class="text-end"><?= number_format(array_sum(array_column($rapportEmployes, 'total_heures')), 1) ?> h</th>
                                    <th class="text-end"><?= formatMoney($totalMainOeuvre) ?></th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
