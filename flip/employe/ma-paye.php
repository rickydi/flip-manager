<?php
/**
 * Ma paye - Historique des paiements et avances
 * Flip Manager
 */

require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

requireLogin();

$pageTitle = 'Ma paye';
$userId = getCurrentUserId();

// Récupérer l'utilisateur
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();

// Créer la table avances si elle n'existe pas
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS avances_employes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            montant DECIMAL(10,2) NOT NULL,
            date_avance DATE NOT NULL,
            raison TEXT NULL,
            statut ENUM('active', 'deduite', 'annulee') DEFAULT 'active',
            cree_par INT NULL,
            deduite_semaine DATE NULL,
            date_creation DATETIME DEFAULT CURRENT_TIMESTAMP,
            date_modification DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_user (user_id),
            INDEX idx_statut (statut)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Exception $e) {
    // Ignorer
}

// Récupérer les avances
$stmt = $pdo->prepare("
    SELECT * FROM avances_employes
    WHERE user_id = ?
    ORDER BY date_avance DESC
");
$stmt->execute([$userId]);
$avances = $stmt->fetchAll();

// Calculer totaux avances
$totalAvancesActives = 0;
$totalAvancesDeduites = 0;
foreach ($avances as $av) {
    if ($av['statut'] === 'active') {
        $totalAvancesActives += $av['montant'];
    } elseif ($av['statut'] === 'deduite') {
        $totalAvancesDeduites += $av['montant'];
    }
}

// Récupérer les semaines travaillées (approuvées) des 3 derniers mois
$stmt = $pdo->prepare("
    SELECT
        DATE(date_travail - INTERVAL (DAYOFWEEK(date_travail) - 2) DAY) as semaine_lundi,
        SUM(heures) as total_heures,
        SUM(heures * taux_horaire) as total_montant
    FROM heures_travaillees
    WHERE user_id = ?
    AND statut = 'approuvee'
    AND date_travail >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH)
    GROUP BY semaine_lundi
    ORDER BY semaine_lundi DESC
");
$stmt->execute([$userId]);
$semaines = $stmt->fetchAll();

// Vérifier quelles semaines sont payées
$semainesPayees = [];
try {
    $result = $pdo->query("SELECT semaine_debut FROM semaines_payees");
    while ($row = $result->fetch()) {
        $semainesPayees[] = $row['semaine_debut'];
    }
} catch (Exception $e) {
    // Table n'existe pas
}

include '../includes/header.php';
?>

<style>
.paye-card {
    border-left: 4px solid;
}
.paye-card.active { border-color: #ffc107; }
.paye-card.deduite { border-color: #198754; }
.paye-card.annulee { border-color: #6c757d; }
.semaine-payee { background-color: #e8f5e9; }
.semaine-non-payee { background-color: #fff3cd; }
</style>

<div class="container-fluid">
    <div class="page-header">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= url('/employe/index.php') ?>">Tableau de bord</a></li>
                <li class="breadcrumb-item active">Ma paye</li>
            </ol>
        </nav>
        <h1><i class="bi bi-wallet2 me-2"></i>Ma paye</h1>
    </div>

    <!-- Résumé -->
    <div class="row mb-4">
        <div class="col-md-4">
            <div class="card text-center h-100">
                <div class="card-body">
                    <h6 class="text-muted">Mon taux horaire</h6>
                    <h2 class="text-primary"><?= formatMoney($user['taux_horaire']) ?>/h</h2>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card text-center h-100">
                <div class="card-body">
                    <h6 class="text-muted">Avances à rembourser</h6>
                    <h2 class="<?= $totalAvancesActives > 0 ? 'text-danger' : 'text-success' ?>">
                        <?= formatMoney($totalAvancesActives) ?>
                    </h2>
                    <?php if ($totalAvancesActives > 0): ?>
                        <small class="text-muted">Sera déduit de la prochaine paye</small>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card text-center h-100">
                <div class="card-body">
                    <h6 class="text-muted">Total avances remboursées</h6>
                    <h2 class="text-secondary"><?= formatMoney($totalAvancesDeduites) ?></h2>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Mes semaines -->
        <div class="col-lg-7 mb-4">
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-calendar-week me-2"></i>Mes semaines (3 derniers mois)
                </div>
                <div class="card-body p-0">
                    <?php if (empty($semaines)): ?>
                        <div class="text-center py-4 text-muted">
                            <i class="bi bi-calendar-x" style="font-size: 3rem;"></i>
                            <p class="mt-2">Aucune heure approuvée récemment</p>
                        </div>
                    <?php else: ?>
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Semaine du</th>
                                    <th class="text-end">Heures</th>
                                    <th class="text-end">Montant</th>
                                    <th class="text-center">Statut</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($semaines as $sem):
                                    $estPayee = in_array($sem['semaine_lundi'], $semainesPayees);
                                    $dimanche = date('Y-m-d', strtotime($sem['semaine_lundi'] . ' +6 days'));
                                ?>
                                <tr class="<?= $estPayee ? 'semaine-payee' : 'semaine-non-payee' ?>">
                                    <td>
                                        <?= formatDate($sem['semaine_lundi']) ?>
                                        <small class="text-muted">au <?= formatDate($dimanche) ?></small>
                                    </td>
                                    <td class="text-end"><?= number_format($sem['total_heures'], 1) ?>h</td>
                                    <td class="text-end fw-bold"><?= formatMoney($sem['total_montant']) ?></td>
                                    <td class="text-center">
                                        <?php if ($estPayee): ?>
                                            <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Payée</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning text-dark"><i class="bi bi-clock me-1"></i>En attente</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Mes avances -->
        <div class="col-lg-5 mb-4">
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-cash-stack me-2"></i>Mes avances
                </div>
                <div class="card-body p-0">
                    <?php if (empty($avances)): ?>
                        <div class="text-center py-4 text-muted">
                            <i class="bi bi-check-circle" style="font-size: 3rem;"></i>
                            <p class="mt-2">Aucune avance</p>
                        </div>
                    <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($avances as $av): ?>
                            <div class="list-group-item paye-card <?= $av['statut'] ?>">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <strong><?= formatMoney($av['montant']) ?></strong>
                                        <small class="text-muted d-block"><?= formatDate($av['date_avance']) ?></small>
                                        <?php if ($av['raison']): ?>
                                            <small class="text-muted"><?= e($av['raison']) ?></small>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <?php if ($av['statut'] === 'active'): ?>
                                            <span class="badge bg-warning text-dark">Active</span>
                                        <?php elseif ($av['statut'] === 'deduite'): ?>
                                            <span class="badge bg-success">Remboursée</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Annulée</span>
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
    </div>
</div>

<?php include '../includes/footer.php'; ?>
