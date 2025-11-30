<?php
/**
 * Rapports - Admin
 * Flip Manager
 */

require_once '../../config.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

requireAdmin();

$pageTitle = 'Rapports';

// Filtres
$filtreProjet = isset($_GET['projet']) ? (int)$_GET['projet'] : 0;
$dateDebut = isset($_GET['date_debut']) ? $_GET['date_debut'] : date('Y-m-01'); // Premier jour du mois
$dateFin = isset($_GET['date_fin']) ? $_GET['date_fin'] : date('Y-m-d'); // Aujourd'hui

// Récupérer les projets pour le filtre
$projets = getProjets($pdo, false);

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

// 1. Rapport par projet
$sqlProjet = "
    SELECT p.id, p.nom, p.adresse,
           COALESCE(SUM(f.montant_total), 0) as total_factures
    FROM projets p
    LEFT JOIN factures f ON f.projet_id = p.id AND f.statut = 'approuvee'
        " . ($dateDebut ? "AND f.date_facture >= '$dateDebut'" : "") . "
        " . ($dateFin ? "AND f.date_facture <= '$dateFin'" : "") . "
    " . ($filtreProjet > 0 ? "WHERE p.id = $filtreProjet" : "") . "
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
            <form method="GET" action="" class="row g-3">
                <div class="col-md-4">
                    <label for="projet" class="form-label">Projet</label>
                    <select class="form-select" id="projet" name="projet">
                        <option value="">Tous les projets</option>
                        <?php foreach ($projets as $projet): ?>
                            <option value="<?= $projet['id'] ?>" <?= $filtreProjet == $projet['id'] ? 'selected' : '' ?>>
                                <?= e($projet['nom']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="date_debut" class="form-label">Date début</label>
                    <input type="date" class="form-control" id="date_debut" name="date_debut" 
                           value="<?= e($dateDebut) ?>">
                </div>
                <div class="col-md-3">
                    <label for="date_fin" class="form-label">Date fin</label>
                    <input type="date" class="form-control" id="date_fin" name="date_fin" 
                           value="<?= e($dateFin) ?>">
                </div>
                <div class="col-md-2 d-flex align-items-end gap-2">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-funnel me-1"></i>Filtrer
                    </button>
                    <a href="/admin/rapports/index.php" class="btn btn-outline-secondary">
                        <i class="bi bi-x-circle"></i>
                    </a>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Zone imprimable -->
    <div id="rapport-imprimable">
        <!-- En-tête du rapport (visible seulement à l'impression) -->
        <div class="print-header">
            <h2><?= APP_NAME ?> - Rapport</h2>
            <p>
                <strong>Projet:</strong> <?= e($nomProjetFiltre) ?><br>
                <strong>Période:</strong> <?= formatDate($dateDebut) ?> au <?= formatDate($dateFin) ?><br>
                <strong>Généré le:</strong> <?= formatDateTime(date('Y-m-d H:i:s')) ?>
            </p>
        </div>
        
        <!-- Résumé global -->
        <div class="row mb-4">
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
        
        <!-- Rapport par projet -->
        <div class="card mb-4">
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
        <div class="card mb-4">
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
        <div class="card mb-4">
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
