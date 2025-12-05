<?php
/**
 * Rapport de paie hebdomadaire
 * Flip Manager
 */

require_once '../../config.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

requireAdmin();

$pageTitle = 'Rapport de paie hebdomadaire';

// Créer la table semaines_payees si elle n'existe pas
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS semaines_payees (
            id INT AUTO_INCREMENT PRIMARY KEY,
            semaine_debut DATE NOT NULL,
            paye_par INT NULL,
            date_paiement DATETIME DEFAULT CURRENT_TIMESTAMP,
            notes TEXT NULL,
            UNIQUE KEY unique_semaine (semaine_debut)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Exception $e) {
    // Ignorer
}

// Déterminer la semaine sélectionnée (lundi à dimanche)
$semaineParam = isset($_GET['semaine']) ? $_GET['semaine'] : date('Y-m-d');
$dateSemaine = new DateTime($semaineParam);

// Trouver le lundi de cette semaine
$jourSemaine = (int)$dateSemaine->format('N'); // 1 = lundi, 7 = dimanche
$dateSemaine->modify('-' . ($jourSemaine - 1) . ' days');
$lundi = $dateSemaine->format('Y-m-d');
$dateSemaine->modify('+6 days');
$dimanche = $dateSemaine->format('Y-m-d');

// Traitement POST pour marquer comme payé/non payé
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $action = $_POST['action'] ?? '';

        if ($action === 'marquer_paye') {
            $stmt = $pdo->prepare("INSERT IGNORE INTO semaines_payees (semaine_debut, paye_par) VALUES (?, ?)");
            $stmt->execute([$lundi, getCurrentUserId()]);
            setFlashMessage('success', 'Semaine marquée comme payée!');
        } elseif ($action === 'annuler_paye') {
            $stmt = $pdo->prepare("DELETE FROM semaines_payees WHERE semaine_debut = ?");
            $stmt->execute([$lundi]);
            setFlashMessage('warning', 'Statut de paiement annulé.');
        }

        redirect('/admin/rapports/paie-hebdo.php?semaine=' . $lundi);
    }
}

// Vérifier si la semaine est payée
$semainePaye = false;
$datePaiement = null;
try {
    $stmt = $pdo->prepare("SELECT * FROM semaines_payees WHERE semaine_debut = ?");
    $stmt->execute([$lundi]);
    $paiement = $stmt->fetch();
    if ($paiement) {
        $semainePaye = true;
        $datePaiement = $paiement['date_paiement'];
    }
} catch (Exception $e) {
    // Table n'existe pas
}

// Générer les dates de la semaine
$joursSemaine = [];
$dateTemp = new DateTime($lundi);
for ($i = 0; $i < 7; $i++) {
    $joursSemaine[] = [
        'date' => $dateTemp->format('Y-m-d'),
        'jour' => $dateTemp->format('l'),
        'affichage' => strftime('%a %e %b', $dateTemp->getTimestamp())
    ];
    $dateTemp->modify('+1 day');
}

// Noms des jours en français
$joursNomsFr = [
    'Monday' => 'Lun',
    'Tuesday' => 'Mar',
    'Wednesday' => 'Mer',
    'Thursday' => 'Jeu',
    'Friday' => 'Ven',
    'Saturday' => 'Sam',
    'Sunday' => 'Dim'
];

// Récupérer les heures de la semaine par employé
$rapportEmployes = [];
$totauxParJour = array_fill_keys(array_column($joursSemaine, 'date'), ['heures' => 0, 'montant' => 0]);
$totalGeneral = ['heures' => 0, 'montant' => 0];

try {
    // Récupérer tous les employés actifs qui ont des heures cette semaine
    $stmt = $pdo->prepare("
        SELECT DISTINCT u.id, CONCAT(u.prenom, ' ', u.nom) as nom_complet
        FROM users u
        INNER JOIN heures_travaillees h ON h.user_id = u.id
        WHERE h.date_travail BETWEEN ? AND ?
        AND h.statut = 'approuvee'
        ORDER BY u.prenom, u.nom
    ");
    $stmt->execute([$lundi, $dimanche]);
    $employes = $stmt->fetchAll();

    foreach ($employes as $employe) {
        $employeData = [
            'nom' => $employe['nom_complet'],
            'jours' => [],
            'total_heures' => 0,
            'total_montant' => 0
        ];

        // Récupérer les heures par jour pour cet employé
        $stmt = $pdo->prepare("
            SELECT h.date_travail, h.heures, h.taux_horaire,
                   (h.heures * h.taux_horaire) as montant,
                   p.nom as projet_nom
            FROM heures_travaillees h
            JOIN projets p ON h.projet_id = p.id
            WHERE h.user_id = ?
            AND h.date_travail BETWEEN ? AND ?
            AND h.statut = 'approuvee'
            ORDER BY h.date_travail
        ");
        $stmt->execute([$employe['id'], $lundi, $dimanche]);
        $heuresEmploye = $stmt->fetchAll();

        // Initialiser les jours
        foreach ($joursSemaine as $jour) {
            $employeData['jours'][$jour['date']] = [
                'heures' => 0,
                'montant' => 0,
                'projets' => []
            ];
        }

        // Remplir avec les données
        foreach ($heuresEmploye as $h) {
            $date = $h['date_travail'];
            $employeData['jours'][$date]['heures'] += $h['heures'];
            $employeData['jours'][$date]['montant'] += $h['montant'];
            $employeData['jours'][$date]['projets'][] = $h['projet_nom'];

            $employeData['total_heures'] += $h['heures'];
            $employeData['total_montant'] += $h['montant'];

            $totauxParJour[$date]['heures'] += $h['heures'];
            $totauxParJour[$date]['montant'] += $h['montant'];
        }

        $totalGeneral['heures'] += $employeData['total_heures'];
        $totalGeneral['montant'] += $employeData['total_montant'];

        $rapportEmployes[] = $employeData;
    }
} catch (Exception $e) {
    // Table n'existe pas encore
}

// Récupérer la liste des semaines payées
$semainesPayees = [];
try {
    $stmt = $pdo->query("SELECT semaine_debut FROM semaines_payees");
    while ($row = $stmt->fetch()) {
        $semainesPayees[] = $row['semaine_debut'];
    }
} catch (Exception $e) {
    // Ignorer
}

// Générer la liste des semaines disponibles (12 dernières semaines)
$semainesDisponibles = [];
$dateTemp = new DateTime();
$jourActuel = (int)$dateTemp->format('N');
$dateTemp->modify('-' . ($jourActuel - 1) . ' days'); // Aller au lundi

for ($i = 0; $i < 12; $i++) {
    $lundiTemp = $dateTemp->format('Y-m-d');
    $dimancheTemp = (clone $dateTemp)->modify('+6 days')->format('Y-m-d');
    $estPaye = in_array($lundiTemp, $semainesPayees);
    $semainesDisponibles[] = [
        'valeur' => $lundiTemp,
        'label' => ($estPaye ? '✓ ' : '') . 'Sem. ' . $dateTemp->format('d/m') . ' au ' . (new DateTime($dimancheTemp))->format('d/m/Y'),
        'paye' => $estPaye
    ];
    $dateTemp->modify('-7 days');
}

// Format date pour affichage
setlocale(LC_TIME, 'fr_CA.UTF-8', 'fr_FR.UTF-8', 'fra');

include '../../includes/header.php';
?>

<style>
@media print {
    @page {
        size: letter portrait;
        margin: 0.5in;
    }

    body {
        font-size: 11px !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }

    .no-print {
        display: none !important;
    }

    .card {
        border: 1px solid #dee2e6 !important;
        box-shadow: none !important;
        break-inside: avoid;
    }

    .table {
        font-size: 10px !important;
    }

    .table th, .table td {
        padding: 4px 6px !important;
    }

    .print-header {
        display: block !important;
        text-align: center;
        margin-bottom: 20px;
        border-bottom: 2px solid #333;
        padding-bottom: 10px;
    }

    .employe-section {
        break-inside: avoid;
        margin-bottom: 15px !important;
    }

    h1, h2, h3, h4, h5, h6 {
        margin-top: 0 !important;
        margin-bottom: 8px !important;
    }

    .container-fluid {
        padding: 0 !important;
    }
}

@media screen {
    .print-header {
        display: none;
    }
}

.employe-section {
    margin-bottom: 1.5rem;
}

.table-paie th {
    background-color: var(--bg-table-header);
    font-size: 0.85rem;
    white-space: nowrap;
}

.table-paie td {
    font-size: 0.85rem;
    vertical-align: middle;
}

.table-paie .jour-cell {
    min-width: 80px;
}

.table-paie .projet-mini {
    font-size: 0.7rem;
    color: var(--text-muted);
    max-width: 100px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.total-row {
    background-color: var(--bg-table-header) !important;
    font-weight: bold;
}

.grand-total {
    background-color: #198754 !important;
    color: white !important;
}
</style>

<div class="container-fluid">
    <!-- En-tête imprimable -->
    <div class="print-header">
        <h2 style="margin:0;"><?= APP_NAME ?></h2>
        <h3 style="margin:5px 0;">Rapport de paie hebdomadaire</h3>
        <p style="margin:5px 0;">
            <strong>Semaine du <?= (new DateTime($lundi))->format('d/m/Y') ?> au <?= (new DateTime($dimanche))->format('d/m/Y') ?></strong>
        </p>
        <?php if ($semainePaye): ?>
        <p style="margin:10px 0 0 0; color:#198754; font-weight:bold; font-size:14px;">
            ✓ PAYÉ<?php if ($datePaiement): ?> - le <?= (new DateTime($datePaiement))->format('d/m/Y') ?><?php endif; ?>
        </p>
        <?php endif; ?>
    </div>

    <!-- En-tête écran -->
    <div class="page-header no-print">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= url('/admin/index.php') ?>">Tableau de bord</a></li>
                <li class="breadcrumb-item active">Administration</li>
            </ol>
        </nav>
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h1><i class="bi bi-gear me-2"></i>Administration</h1>
            <div class="d-flex gap-2">
                <?php if ($semainePaye): ?>
                    <form method="POST" class="d-inline" onsubmit="return confirm('Annuler le statut payé pour cette semaine?');">
                        <?php csrfField(); ?>
                        <input type="hidden" name="action" value="annuler_paye">
                        <button type="submit" class="btn btn-outline-warning">
                            <i class="bi bi-x-circle me-1"></i>Annuler payé
                        </button>
                    </form>
                <?php else: ?>
                    <form method="POST" class="d-inline" onsubmit="return confirm('Marquer cette semaine comme payée?');">
                        <?php csrfField(); ?>
                        <input type="hidden" name="action" value="marquer_paye">
                        <button type="submit" class="btn btn-success">
                            <i class="bi bi-check-circle me-1"></i>Marquer payé
                        </button>
                    </form>
                <?php endif; ?>
                <button type="button" class="btn btn-primary" onclick="window.print()">
                    <i class="bi bi-printer me-1"></i>Imprimer
                </button>
            </div>
        </div>
    </div>

    <?php displayFlashMessage(); ?>

    <?php if ($semainePaye): ?>
    <div class="alert alert-success d-flex align-items-center mb-4 no-print">
        <i class="bi bi-check-circle-fill me-2" style="font-size: 1.5rem;"></i>
        <div>
            <strong>Semaine payée</strong>
            <?php if ($datePaiement): ?>
                <br><small>Payé le <?= formatDateTime($datePaiement) ?></small>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Sous-navigation Administration -->
    <ul class="nav nav-tabs mb-4 no-print">
        <li class="nav-item">
            <a class="nav-link" href="<?= url('/admin/utilisateurs/liste.php') ?>">
                <i class="bi bi-person-badge me-1"></i>Utilisateurs
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="<?= url('/admin/categories/liste.php') ?>">
                <i class="bi bi-tags me-1"></i>Catégories
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="<?= url('/admin/rapports/index.php') ?>">
                <i class="bi bi-file-earmark-bar-graph me-1"></i>Rapports
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link active" href="<?= url('/admin/rapports/paie-hebdo.php') ?>">
                <i class="bi bi-calendar-week me-1"></i>Paie hebdo
            </a>
        </li>
    </ul>

    <!-- Sélecteur de semaine -->
    <div class="card mb-4 no-print">
        <div class="card-body">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label class="form-label">Sélectionner la semaine</label>
                    <select class="form-select" name="semaine" onchange="this.form.submit()">
                        <?php foreach ($semainesDisponibles as $sem): ?>
                            <option value="<?= $sem['valeur'] ?>" <?= $sem['valeur'] === $lundi ? 'selected' : '' ?>>
                                <?= $sem['label'] ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <div class="btn-group">
                        <?php
                        $lundiPrec = (new DateTime($lundi))->modify('-7 days')->format('Y-m-d');
                        $lundiSuiv = (new DateTime($lundi))->modify('+7 days')->format('Y-m-d');
                        ?>
                        <a href="?semaine=<?= $lundiPrec ?>" class="btn btn-outline-secondary">
                            <i class="bi bi-chevron-left"></i> Semaine préc.
                        </a>
                        <a href="?semaine=<?= $lundiSuiv ?>" class="btn btn-outline-secondary">
                            Semaine suiv. <i class="bi bi-chevron-right"></i>
                        </a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Période affichée -->
    <div class="alert alert-info mb-4 no-print">
        <i class="bi bi-calendar3 me-2"></i>
        <strong>Période:</strong> Lundi <?= (new DateTime($lundi))->format('d/m/Y') ?> au Dimanche <?= (new DateTime($dimanche))->format('d/m/Y') ?>
    </div>

    <?php if (empty($rapportEmployes)): ?>
        <div class="card">
            <div class="card-body text-center py-5">
                <i class="bi bi-calendar-x text-muted" style="font-size: 4rem;"></i>
                <h4 class="mt-3">Aucune heure approuvée</h4>
                <p class="text-muted">Aucune heure de travail approuvée pour cette semaine.</p>
            </div>
        </div>
    <?php else: ?>

        <!-- Rapport par employé -->
        <?php foreach ($rapportEmployes as $employe): ?>
        <div class="card employe-section">
            <div class="card-header py-2">
                <strong><i class="bi bi-person me-2"></i><?= e($employe['nom']) ?></strong>
            </div>
            <div class="card-body p-0">
                <table class="table table-bordered table-paie mb-0">
                    <thead>
                        <tr>
                            <?php foreach ($joursSemaine as $jour): ?>
                                <th class="text-center jour-cell">
                                    <?= $joursNomsFr[$jour['jour']] ?><br>
                                    <small><?= (new DateTime($jour['date']))->format('d/m') ?></small>
                                </th>
                            <?php endforeach; ?>
                            <th class="text-center" style="background:#198754;color:white;">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- Ligne heures -->
                        <tr>
                            <?php foreach ($joursSemaine as $jour):
                                $jourData = $employe['jours'][$jour['date']];
                            ?>
                                <td class="text-center">
                                    <?php if ($jourData['heures'] > 0): ?>
                                        <strong><?= number_format($jourData['heures'], 1) ?>h</strong>
                                        <?php if (!empty($jourData['projets'])): ?>
                                            <div class="projet-mini" title="<?= e(implode(', ', array_unique($jourData['projets']))) ?>">
                                                <?= e(implode(', ', array_unique($jourData['projets']))) ?>
                                            </div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                            <td class="text-center total-row">
                                <?= number_format($employe['total_heures'], 1) ?>h
                            </td>
                        </tr>
                        <!-- Ligne montants -->
                        <tr class="table-light">
                            <?php foreach ($joursSemaine as $jour):
                                $jourData = $employe['jours'][$jour['date']];
                            ?>
                                <td class="text-center">
                                    <?php if ($jourData['montant'] > 0): ?>
                                        <?= formatMoney($jourData['montant']) ?>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                            <td class="text-center grand-total">
                                <?= formatMoney($employe['total_montant']) ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endforeach; ?>

        <!-- Totaux par jour -->
        <div class="card mb-4">
            <div class="card-header py-2">
                <strong><i class="bi bi-calculator me-2"></i>Totaux par jour (tous employés)</strong>
            </div>
            <div class="card-body p-0">
                <table class="table table-bordered table-paie mb-0">
                    <thead>
                        <tr>
                            <?php foreach ($joursSemaine as $jour): ?>
                                <th class="text-center jour-cell">
                                    <?= $joursNomsFr[$jour['jour']] ?><br>
                                    <small><?= (new DateTime($jour['date']))->format('d/m') ?></small>
                                </th>
                            <?php endforeach; ?>
                            <th class="text-center" style="background:#198754;color:white;">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <?php foreach ($joursSemaine as $jour): ?>
                                <td class="text-center">
                                    <strong><?= number_format($totauxParJour[$jour['date']]['heures'], 1) ?>h</strong>
                                </td>
                            <?php endforeach; ?>
                            <td class="text-center total-row">
                                <strong><?= number_format($totalGeneral['heures'], 1) ?>h</strong>
                            </td>
                        </tr>
                        <tr class="table-light">
                            <?php foreach ($joursSemaine as $jour): ?>
                                <td class="text-center">
                                    <?= formatMoney($totauxParJour[$jour['date']]['montant']) ?>
                                </td>
                            <?php endforeach; ?>
                            <td class="text-center grand-total">
                                <?= formatMoney($totalGeneral['montant']) ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Résumé final -->
        <div class="card">
            <div class="card-header py-2">
                <strong><i class="bi bi-list-check me-2"></i>Résumé de la semaine</strong>
            </div>
            <div class="card-body p-0">
                <table class="table table-bordered table-paie mb-0">
                    <thead>
                        <tr>
                            <th>Employé</th>
                            <th class="text-end">Heures</th>
                            <th class="text-end">Montant</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rapportEmployes as $employe): ?>
                        <tr>
                            <td><?= e($employe['nom']) ?></td>
                            <td class="text-end"><?= number_format($employe['total_heures'], 1) ?>h</td>
                            <td class="text-end"><?= formatMoney($employe['total_montant']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="grand-total">
                            <th>TOTAL</th>
                            <th class="text-end"><?= number_format($totalGeneral['heures'], 1) ?>h</th>
                            <th class="text-end"><?= formatMoney($totalGeneral['montant']) ?></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

    <?php endif; ?>

    <!-- Pied de page imprimable -->
    <div class="print-header" style="margin-top:20px;border-top:1px solid #ccc;border-bottom:none;padding-top:10px;">
        <small>Généré le <?= date('d/m/Y à H:i') ?></small>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
