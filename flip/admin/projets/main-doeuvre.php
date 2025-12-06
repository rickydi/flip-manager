<?php
/**
 * Main-d'œuvre du projet - Admin
 * Flip Manager
 */

require_once '../../config.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../includes/calculs.php';

requireAdmin();

$projetId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$projet = getProjetById($pdo, $projetId);

if (!$projet) {
    setFlashMessage('danger', 'Projet non trouvé.');
    redirect('/admin/projets/liste.php');
}

$pageTitle = $projet['nom'] . ' - Main-d\'œuvre';
$indicateurs = calculerIndicateursProjet($pdo, $projet);

// Calcul main d'œuvre extrapolée
$moExtrapole = ['heures' => 0, 'cout' => 0, 'jours' => 0];
$dateDebutTravaux = $projet['date_debut_travaux'] ?? $projet['date_acquisition'];
$dateFinPrevue = $projet['date_fin_prevue'];

if ($dateDebutTravaux && $dateFinPrevue) {
    $d1 = new DateTime($dateDebutTravaux);
    $d2 = new DateTime($dateFinPrevue);
    $d2Inclusive = clone $d2;
    $d2Inclusive->modify('+1 day');
    $period = new DatePeriod($d1, new DateInterval('P1D'), $d2Inclusive);

    $joursOuvrables = 0;
    foreach ($period as $dt) {
        if ((int)$dt->format('N') < 6) $joursOuvrables++;
    }
    $moExtrapole['jours'] = max(1, $joursOuvrables);
}

// Planifications par employé
$planifications = [];
try {
    $stmt = $pdo->prepare("
        SELECT p.*, CONCAT(u.prenom, ' ', u.nom) as employe_nom, u.taux_horaire
        FROM projet_planification_heures p
        JOIN users u ON p.user_id = u.id
        WHERE p.projet_id = ?
    ");
    $stmt->execute([$projetId]);
    $planifications = $stmt->fetchAll();

    foreach ($planifications as $row) {
        $heuresSemaine = (float)$row['heures_semaine_estimees'];
        $tauxHoraire = (float)$row['taux_horaire'];
        $heuresJour = $heuresSemaine / 5;
        $totalHeures = $heuresJour * $moExtrapole['jours'];
        $moExtrapole['heures'] += $totalHeures;
        $moExtrapole['cout'] += $totalHeures * $tauxHoraire;
    }
} catch (Exception $e) {}

// Heures réelles par employé
$heuresReelles = [];
try {
    $stmt = $pdo->prepare("
        SELECT h.user_id, CONCAT(u.prenom, ' ', u.nom) as employe_nom,
               SUM(h.heures) as total_heures,
               SUM(h.heures * IF(h.taux_horaire > 0, h.taux_horaire, u.taux_horaire)) as total_cout,
               u.taux_horaire
        FROM heures_travaillees h
        JOIN users u ON h.user_id = u.id
        WHERE h.projet_id = ? AND h.statut != 'rejetee'
        GROUP BY h.user_id
        ORDER BY total_heures DESC
    ");
    $stmt->execute([$projetId]);
    $heuresReelles = $stmt->fetchAll();
} catch (Exception $e) {}

$totalHeuresReelles = array_sum(array_column($heuresReelles, 'total_heures'));
$totalCoutReel = array_sum(array_column($heuresReelles, 'total_cout'));

// Heures par jour (pour le graphique)
$heuresParJour = [];
try {
    $stmt = $pdo->prepare("
        SELECT date_travail as jour, SUM(heures) as total
        FROM heures_travaillees
        WHERE projet_id = ? AND statut != 'rejetee'
        GROUP BY date_travail
        ORDER BY date_travail
    ");
    $stmt->execute([$projetId]);
    foreach ($stmt->fetchAll() as $row) {
        $heuresParJour[$row['jour']] = (float)$row['total'];
    }
} catch (Exception $e) {}

include '../../includes/header.php';
?>

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
                    <a href="<?= url('/admin/projets/modifier.php?id=' . $projet['id']) ?>" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-pencil"></i>
                    </a>
                </div>
                <small class="text-muted"><i class="bi bi-geo-alt"></i> <?= e($projet['adresse']) ?>, <?= e($projet['ville']) ?></small>
            </div>
        </div>
    </div>

    <!-- Onglets de navigation -->
    <ul class="nav nav-tabs mb-3">
        <li class="nav-item">
            <a class="nav-link" href="<?= url('/admin/projets/detail.php?id=' . $projet['id']) ?>">
                <i class="bi bi-house-door me-1"></i>Base
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="<?= url('/admin/projets/financement.php?id=' . $projet['id']) ?>">
                <i class="bi bi-bank me-1"></i>Financement
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="<?= url('/admin/projets/budgets.php?id=' . $projet['id']) ?>">
                <i class="bi bi-wallet2 me-1"></i>Budgets
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link active" href="<?= url('/admin/projets/main-doeuvre.php?id=' . $projet['id']) ?>">
                <i class="bi bi-people me-1"></i>Main-d'œuvre
            </a>
        </li>
    </ul>

    <?php displayFlashMessage(); ?>

    <!-- Résumé main d'œuvre -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card text-center p-3 border-info">
                <small class="text-muted">Heures planifiées</small>
                <h4 class="mb-0 text-info"><?= number_format($moExtrapole['heures'], 0) ?>h</h4>
                <small class="text-muted"><?= $moExtrapole['jours'] ?> jours ouvrables</small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center p-3 border-primary">
                <small class="text-muted">Heures réelles</small>
                <h4 class="mb-0 text-primary"><?= number_format($totalHeuresReelles, 1) ?>h</h4>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center p-3 border-warning">
                <small class="text-muted">Coût planifié</small>
                <h4 class="mb-0 text-warning"><?= formatMoney($moExtrapole['cout']) ?></h4>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center p-3 border-success">
                <small class="text-muted">Coût réel</small>
                <h4 class="mb-0 text-success"><?= formatMoney($totalCoutReel) ?></h4>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <!-- Planification -->
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-calendar-check me-2"></i>Planification des heures</span>
                    <a href="<?= url('/admin/projets/modifier.php?id=' . $projet['id'] . '#planification') ?>" class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-pencil"></i> Modifier
                    </a>
                </div>
                <?php if (!empty($planifications)): ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Employé</th>
                                <th class="text-end">Heures/sem</th>
                                <th class="text-end">Taux</th>
                                <th class="text-end">Total estimé</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($planifications as $p):
                            $heuresSemaine = (float)$p['heures_semaine_estimees'];
                            $heuresJour = $heuresSemaine / 5;
                            $totalHeures = $heuresJour * $moExtrapole['jours'];
                            $coutEstime = $totalHeures * (float)$p['taux_horaire'];
                        ?>
                        <tr>
                            <td><?= e($p['employe_nom']) ?></td>
                            <td class="text-end"><?= number_format($heuresSemaine, 0) ?>h</td>
                            <td class="text-end"><?= formatMoney($p['taux_horaire']) ?>/h</td>
                            <td class="text-end"><?= formatMoney($coutEstime) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="card-body text-center text-muted py-4">
                    <i class="bi bi-calendar-x" style="font-size: 3rem;"></i>
                    <p class="mt-2">Aucune planification configurée</p>
                    <a href="<?= url('/admin/projets/modifier.php?id=' . $projet['id'] . '#planification') ?>" class="btn btn-primary btn-sm">
                        <i class="bi bi-plus"></i> Ajouter une planification
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Heures réelles par employé -->
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-clock-history me-2"></i>Heures réelles par employé</span>
                    <a href="<?= url('/admin/temps/liste.php?projet=' . $projet['id']) ?>" class="btn btn-sm btn-outline-primary">
                        Voir tout
                    </a>
                </div>
                <?php if (!empty($heuresReelles)): ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Employé</th>
                                <th class="text-end">Heures</th>
                                <th class="text-end">Taux moyen</th>
                                <th class="text-end">Coût</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($heuresReelles as $h):
                            $tauxMoyen = $h['total_heures'] > 0 ? $h['total_cout'] / $h['total_heures'] : 0;
                        ?>
                        <tr>
                            <td><?= e($h['employe_nom']) ?></td>
                            <td class="text-end"><?= number_format($h['total_heures'], 1) ?>h</td>
                            <td class="text-end"><?= formatMoney($tauxMoyen) ?>/h</td>
                            <td class="text-end"><?= formatMoney($h['total_cout']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-light">
                            <tr>
                                <td><strong>Total</strong></td>
                                <td class="text-end"><strong><?= number_format($totalHeuresReelles, 1) ?>h</strong></td>
                                <td></td>
                                <td class="text-end"><strong><?= formatMoney($totalCoutReel) ?></strong></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <?php else: ?>
                <div class="card-body text-center text-muted py-4">
                    <i class="bi bi-clock" style="font-size: 3rem;"></i>
                    <p class="mt-2">Aucune heure enregistrée</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Graphique heures par jour -->
    <?php if (!empty($heuresParJour)): ?>
    <div class="card mt-3">
        <div class="card-header">
            <i class="bi bi-graph-up me-2"></i>Heures travaillées par jour
        </div>
        <div class="card-body">
            <canvas id="chartHeures" height="200"></canvas>
        </div>
    </div>
    <?php endif; ?>

    <!-- Dernières entrées de temps -->
    <div class="card mt-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-list-ul me-2"></i>Dernières entrées de temps</span>
            <a href="<?= url('/admin/temps/liste.php?projet=' . $projet['id']) ?>" class="btn btn-sm btn-outline-primary">
                Voir toutes
            </a>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Employé</th>
                        <th>Description</th>
                        <th class="text-end">Heures</th>
                        <th class="text-center">Statut</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                try {
                    $stmt = $pdo->prepare("
                        SELECT h.*, CONCAT(u.prenom, ' ', u.nom) as employe_nom
                        FROM heures_travaillees h
                        JOIN users u ON h.user_id = u.id
                        WHERE h.projet_id = ?
                        ORDER BY h.date_travail DESC
                        LIMIT 10
                    ");
                    $stmt->execute([$projetId]);
                    $dernieresHeures = $stmt->fetchAll();
                } catch (Exception $e) {
                    $dernieresHeures = [];
                }

                if (empty($dernieresHeures)):
                ?>
                <tr>
                    <td colspan="5" class="text-center text-muted py-4">Aucune entrée de temps</td>
                </tr>
                <?php else: ?>
                <?php foreach ($dernieresHeures as $h): ?>
                <tr>
                    <td><?= formatDate($h['date_travail']) ?></td>
                    <td><?= e($h['employe_nom']) ?></td>
                    <td><?= e($h['description'] ?? '-') ?></td>
                    <td class="text-end"><?= number_format($h['heures'], 1) ?>h</td>
                    <td class="text-center">
                        <?php if ($h['statut'] === 'approuvee'): ?>
                            <span class="badge bg-success">Approuvée</span>
                        <?php elseif ($h['statut'] === 'en_attente'): ?>
                            <span class="badge bg-warning text-dark">En attente</span>
                        <?php else: ?>
                            <span class="badge bg-secondary"><?= ucfirst($h['statut']) ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Actions -->
    <div class="d-flex justify-content-between mt-3 mb-4">
        <a href="<?= url('/admin/projets/liste.php') ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left"></i> Retour à la liste
        </a>
        <div>
            <a href="<?= url('/admin/temps/liste.php?projet=' . $projet['id']) ?>" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-clock"></i> Feuilles de temps
            </a>
            <a href="<?= url('/admin/projets/modifier.php?id=' . $projet['id']) ?>" class="btn btn-primary btn-sm">
                <i class="bi bi-pencil"></i> Modifier le projet
            </a>
        </div>
    </div>
</div>

<?php if (!empty($heuresParJour)): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const jourLabels = <?= json_encode(array_map(function($d) { return date('d M', strtotime($d)); }, array_keys($heuresParJour))) ?>;
const jourData = <?= json_encode(array_values($heuresParJour)) ?>;

new Chart(document.getElementById('chartHeures'), {
    type: 'bar',
    data: {
        labels: jourLabels,
        datasets: [{
            label: 'Heures',
            data: jourData,
            backgroundColor: 'rgba(78, 115, 223, 0.6)',
            borderColor: 'rgba(78, 115, 223, 1)',
            borderWidth: 1
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: {
                    callback: function(value) { return value + 'h'; }
                }
            }
        }
    }
});
</script>
<?php endif; ?>

<?php include '../../includes/footer.php'; ?>
