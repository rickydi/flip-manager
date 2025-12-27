<?php
/**
 * Gestion des paye employés - Avances et paiements
 * Flip Manager
 */

require_once '../../config.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

requireAdmin();

$pageTitle = 'Paye Employés';

// Créer les tables si elles n'existent pas
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

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS paiements_employes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            semaine_debut DATE NOT NULL,
            montant_heures DECIMAL(10,2) NOT NULL DEFAULT 0,
            montant_avances DECIMAL(10,2) NOT NULL DEFAULT 0,
            montant_ajustement DECIMAL(10,2) NOT NULL DEFAULT 0,
            note_ajustement TEXT NULL,
            montant_net DECIMAL(10,2) NOT NULL DEFAULT 0,
            mode_paiement ENUM('cheque', 'virement', 'cash', 'autre') DEFAULT 'cheque',
            reference_paiement VARCHAR(100) NULL,
            paye_par INT NULL,
            date_paiement DATETIME DEFAULT CURRENT_TIMESTAMP,
            notes TEXT NULL,
            UNIQUE KEY unique_employe_semaine (user_id, semaine_debut),
            INDEX idx_user (user_id),
            INDEX idx_semaine (semaine_debut)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Exception $e) {
    // Ignorer si tables existent
}

// Traitement POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $action = $_POST['action'] ?? '';

        if ($action === 'ajouter_avance') {
            $userId = (int)($_POST['user_id'] ?? 0);
            $montant = parseNumber($_POST['montant'] ?? 0);
            $dateAvance = $_POST['date_avance'] ?? date('Y-m-d');
            $raison = trim($_POST['raison'] ?? '');

            if ($userId > 0 && $montant > 0) {
                $stmt = $pdo->prepare("
                    INSERT INTO avances_employes (user_id, montant, date_avance, raison, cree_par)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute([$userId, $montant, $dateAvance, $raison, getCurrentUserId()]);
                setFlashMessage('success', 'Avance de ' . formatMoney($montant) . ' ajoutée avec succès!');
            } else {
                setFlashMessage('danger', 'Employé et montant sont requis.');
            }
            redirect('/admin/paye/liste.php');
        }

        if ($action === 'annuler_avance') {
            $avanceId = (int)($_POST['avance_id'] ?? 0);
            if ($avanceId > 0) {
                $stmt = $pdo->prepare("UPDATE avances_employes SET statut = 'annulee' WHERE id = ? AND statut = 'active'");
                $stmt->execute([$avanceId]);
                setFlashMessage('warning', 'Avance annulée.');
            }
            redirect('/admin/paye/liste.php');
        }
    }
}

// Récupérer les employés avec leurs soldes d'avances
$employes = $pdo->query("
    SELECT
        u.id,
        CONCAT(u.prenom, ' ', u.nom) AS nom_complet,
        u.taux_horaire,
        u.actif,
        COALESCE(SUM(CASE WHEN a.statut = 'active' THEN a.montant ELSE 0 END), 0) AS avances_actives,
        COALESCE(COUNT(CASE WHEN a.statut = 'active' THEN 1 END), 0) AS nb_avances
    FROM users u
    LEFT JOIN avances_employes a ON u.id = a.user_id
    WHERE u.role IN ('employe', 'admin')
    GROUP BY u.id
    ORDER BY u.actif DESC, u.prenom, u.nom
")->fetchAll();

// Récupérer les avances actives (pour liste détaillée)
$avancesActives = $pdo->query("
    SELECT
        a.*,
        CONCAT(u.prenom, ' ', u.nom) AS employe_nom,
        CONCAT(admin.prenom, ' ', admin.nom) AS cree_par_nom
    FROM avances_employes a
    JOIN users u ON a.user_id = u.id
    LEFT JOIN users admin ON a.cree_par = admin.id
    WHERE a.statut = 'active'
    ORDER BY a.date_avance DESC
")->fetchAll();

// Total des avances actives
$totalAvances = array_sum(array_column($avancesActives, 'montant'));

include '../../includes/header.php';
?>

<style>
.solde-avance {
    font-weight: bold;
}
.solde-avance.has-avance {
    color: #dc3545;
}
.employe-inactif {
    opacity: 0.6;
}
</style>

<div class="container-fluid">
    <div class="page-header">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= url('/admin/index.php') ?>">Tableau de bord</a></li>
                <li class="breadcrumb-item active">Paye Employés</li>
            </ol>
        </nav>
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h1><i class="bi bi-wallet2 me-2"></i>Paye Employés</h1>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#modalAvance">
                    <i class="bi bi-plus-circle me-1"></i>Nouvelle avance
                </button>
                <a href="<?= url('/admin/rapports/paie-hebdo.php') ?>" class="btn btn-outline-primary">
                    <i class="bi bi-calendar-week me-1"></i>Paie hebdo
                </a>
            </div>
        </div>
    </div>

    <?php displayFlashMessage(); ?>

    <!-- Sous-navigation Administration -->
    <ul class="nav nav-tabs mb-4">
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
            <a class="nav-link" href="<?= url('/admin/fournisseurs/liste.php') ?>">
                <i class="bi bi-shop me-1"></i>Fournisseurs
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="<?= url('/admin/rapports/index.php') ?>">
                <i class="bi bi-file-earmark-bar-graph me-1"></i>Rapports
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="<?= url('/admin/rapports/paie-hebdo.php') ?>">
                <i class="bi bi-calendar-week me-1"></i>Paie hebdo
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link active" href="<?= url('/admin/paye/liste.php') ?>">
                <i class="bi bi-wallet2 me-1"></i>Paye
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="<?= url('/admin/configuration/index.php') ?>">
                <i class="bi bi-gear-wide-connected me-1"></i>Configuration
            </a>
        </li>
    </ul>

    <div class="row">
        <!-- Colonne gauche: Soldes par employé -->
        <div class="col-lg-6 mb-4">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-people me-2"></i>Solde avances par employé</span>
                    <?php if ($totalAvances > 0): ?>
                        <span class="badge bg-danger"><?= formatMoney($totalAvances) ?> total</span>
                    <?php endif; ?>
                </div>
                <div class="card-body p-0">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Employé</th>
                                <th class="text-center">Taux/h</th>
                                <th class="text-end">Avances dues</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($employes as $emp): ?>
                            <tr class="<?= !$emp['actif'] ? 'employe-inactif' : '' ?>">
                                <td>
                                    <?= e($emp['nom_complet']) ?>
                                    <?php if (!$emp['actif']): ?>
                                        <span class="badge bg-secondary">Inactif</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center"><?= formatMoney($emp['taux_horaire']) ?></td>
                                <td class="text-end">
                                    <span class="solde-avance <?= $emp['avances_actives'] > 0 ? 'has-avance' : '' ?>">
                                        <?php if ($emp['avances_actives'] > 0): ?>
                                            <?= formatMoney($emp['avances_actives']) ?>
                                            <small class="text-muted">(<?= $emp['nb_avances'] ?>)</small>
                                        <?php else: ?>
                                            <span class="text-success">0 $</span>
                                        <?php endif; ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <button type="button" class="btn btn-sm btn-outline-success"
                                            onclick="ouvrirAvance(<?= $emp['id'] ?>, '<?= e($emp['nom_complet']) ?>')">
                                        <i class="bi bi-plus"></i>
                                    </button>
                                    <a href="<?= url('/admin/paye/historique.php?user_id=' . $emp['id']) ?>"
                                       class="btn btn-sm btn-outline-info" title="Historique">
                                        <i class="bi bi-clock-history"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Colonne droite: Avances actives -->
        <div class="col-lg-6 mb-4">
            <div class="card h-100">
                <div class="card-header">
                    <i class="bi bi-exclamation-triangle me-2"></i>Avances actives (non déduites)
                </div>
                <div class="card-body p-0">
                    <?php if (empty($avancesActives)): ?>
                        <div class="text-center py-5 text-muted">
                            <i class="bi bi-check-circle" style="font-size: 3rem;"></i>
                            <p class="mt-2">Aucune avance active</p>
                        </div>
                    <?php else: ?>
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Employé</th>
                                    <th class="text-end">Montant</th>
                                    <th>Raison</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($avancesActives as $av): ?>
                                <tr>
                                    <td><?= formatDate($av['date_avance']) ?></td>
                                    <td><?= e($av['employe_nom']) ?></td>
                                    <td class="text-end text-danger fw-bold"><?= formatMoney($av['montant']) ?></td>
                                    <td>
                                        <?php if ($av['raison']): ?>
                                            <small class="text-muted" title="<?= e($av['raison']) ?>">
                                                <?= e(substr($av['raison'], 0, 30)) ?><?= strlen($av['raison']) > 30 ? '...' : '' ?>
                                            </small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <form method="POST" class="d-inline"
                                              onsubmit="return confirm('Annuler cette avance?');">
                                            <?php csrfField(); ?>
                                            <input type="hidden" name="action" value="annuler_avance">
                                            <input type="hidden" name="avance_id" value="<?= $av['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Annuler">
                                                <i class="bi bi-x"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal Nouvelle Avance -->
<div class="modal fade" id="modalAvance" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <?php csrfField(); ?>
                <input type="hidden" name="action" value="ajouter_avance">

                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>Nouvelle avance</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Employé *</label>
                        <select class="form-select" name="user_id" id="avanceUserId" required>
                            <option value="">Sélectionner...</option>
                            <?php foreach ($employes as $emp): ?>
                                <?php if ($emp['actif']): ?>
                                <option value="<?= $emp['id'] ?>"><?= e($emp['nom_complet']) ?></option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Montant *</label>
                        <div class="input-group">
                            <input type="text" class="form-control" name="montant" id="avanceMontant"
                                   placeholder="0.00" required>
                            <span class="input-group-text">$</span>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Date</label>
                        <input type="date" class="form-control" name="date_avance"
                               value="<?= date('Y-m-d') ?>">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Raison / Note</label>
                        <textarea class="form-control" name="raison" rows="2"
                                  placeholder="Optionnel..."></textarea>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-check me-1"></i>Ajouter l'avance
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function ouvrirAvance(userId, nomEmploye) {
    document.getElementById('avanceUserId').value = userId;
    document.getElementById('avanceMontant').value = '';
    var modal = new bootstrap.Modal(document.getElementById('modalAvance'));
    modal.show();
    setTimeout(function() {
        document.getElementById('avanceMontant').focus();
    }, 500);
}
</script>

<?php include '../../includes/footer.php'; ?>
