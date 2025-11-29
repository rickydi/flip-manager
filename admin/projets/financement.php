<?php
/**
 * Financement du projet - Prêteurs et Investisseurs
 * Flip Manager
 */

require_once '../../config.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../includes/calculs.php';

requireAdmin();

$projetId = (int)($_GET['id'] ?? 0);
if (!$projetId) {
    redirect('/admin/projets/liste.php');
}

$projet = getProjetById($pdo, $projetId);
if (!$projet) {
    setFlashMessage('danger', 'Projet non trouvé.');
    redirect('/admin/projets/liste.php');
}

$pageTitle = 'Financement - ' . $projet['nom'];
$errors = [];

// Récupérer les prêteurs et investisseurs disponibles
$stmt = $pdo->query("SELECT * FROM investisseurs ORDER BY type, nom");
$allFinanceurs = $stmt->fetchAll();
$preteurs = array_filter($allFinanceurs, fn($i) => ($i['type'] ?? '') === 'preteur');
$investisseurs = array_filter($allFinanceurs, fn($i) => ($i['type'] ?? 'investisseur') === 'investisseur');

// Récupérer les financeurs déjà associés au projet
try {
    $stmt = $pdo->prepare("
        SELECT pi.*, i.nom, i.type, i.taux_interet_defaut, i.frais_dossier_defaut
        FROM projet_investisseurs pi
        JOIN investisseurs i ON pi.investisseur_id = i.id
        WHERE pi.projet_id = ?
        ORDER BY i.type, i.nom
    ");
    $stmt->execute([$projetId]);
    $financementsActuels = $stmt->fetchAll();
} catch (Exception $e) {
    $financementsActuels = [];
}

// Traitement des actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token de sécurité invalide.';
    } else {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'ajouter') {
            $investisseurId = (int)($_POST['investisseur_id'] ?? 0);
            $montant = parseNumber($_POST['montant'] ?? 0);
            $tauxInteret = parseNumber($_POST['taux_interet'] ?? 0);
            $fraisDossier = parseNumber($_POST['frais_dossier'] ?? 0);
            $pourcentageProfit = parseNumber($_POST['pourcentage_profit'] ?? 0);
            
            if (!$investisseurId) {
                $errors[] = 'Sélectionnez un prêteur ou investisseur.';
            } elseif ($montant <= 0) {
                $errors[] = 'Le montant doit être supérieur à 0.';
            } else {
                // Déterminer le nom de la colonne (montant ou mise_de_fonds selon la structure)
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO projet_investisseurs (projet_id, investisseur_id, montant, taux_interet, frais_dossier, pourcentage_profit)
                        VALUES (?, ?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE montant = VALUES(montant), taux_interet = VALUES(taux_interet), 
                                                frais_dossier = VALUES(frais_dossier), pourcentage_profit = VALUES(pourcentage_profit)
                    ");
                    $stmt->execute([$projetId, $investisseurId, $montant, $tauxInteret, $fraisDossier, $pourcentageProfit]);
                    setFlashMessage('success', 'Financement ajouté!');
                    redirect('/admin/projets/financement.php?id=' . $projetId);
                } catch (Exception $e) {
                    // Essayer avec mise_de_fonds si montant n'existe pas
                    try {
                        $stmt = $pdo->prepare("
                            INSERT INTO projet_investisseurs (projet_id, investisseur_id, mise_de_fonds, pourcentage_profit)
                            VALUES (?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE mise_de_fonds = VALUES(mise_de_fonds), pourcentage_profit = VALUES(pourcentage_profit)
                        ");
                        $stmt->execute([$projetId, $investisseurId, $montant, $pourcentageProfit]);
                        setFlashMessage('success', 'Financement ajouté!');
                        redirect('/admin/projets/financement.php?id=' . $projetId);
                    } catch (Exception $e2) {
                        $errors[] = 'Erreur: ' . $e2->getMessage();
                    }
                }
            }
        } elseif ($action === 'supprimer') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id) {
                $stmt = $pdo->prepare("DELETE FROM projet_investisseurs WHERE id = ? AND projet_id = ?");
                $stmt->execute([$id, $projetId]);
                setFlashMessage('success', 'Financement supprimé.');
                redirect('/admin/projets/financement.php?id=' . $projetId);
            }
        }
    }
}

// Calculer les totaux
$totalPrets = 0;
$totalInvestissements = 0;
$totalInteretsEstimes = 0;
$totalFraisDossier = 0;

foreach ($financementsActuels as $f) {
    $montant = (float)($f['montant'] ?? $f['mise_de_fonds'] ?? 0);
    $taux = (float)($f['taux_interet'] ?? 0);
    $frais = (float)($f['frais_dossier'] ?? 0);
    
    if (($f['type'] ?? '') === 'preteur') {
        $totalPrets += $montant;
        $interets = $montant * ($taux / 100) * ($projet['temps_assume_mois'] / 12);
        $totalInteretsEstimes += $interets;
        $totalFraisDossier += $montant * ($frais / 100);
    } else {
        $totalInvestissements += $montant;
    }
}

include '../../includes/header.php';
?>

<div class="container-fluid">
    <div class="page-header">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="/admin/index.php">Tableau de bord</a></li>
                <li class="breadcrumb-item"><a href="/admin/projets/liste.php">Projets</a></li>
                <li class="breadcrumb-item"><a href="/admin/projets/detail.php?id=<?= $projetId ?>"><?= e($projet['nom']) ?></a></li>
                <li class="breadcrumb-item active">Financement</li>
            </ol>
        </nav>
        <div class="d-flex justify-content-between align-items-center">
            <h1><i class="bi bi-bank me-2"></i>Financement</h1>
            <a href="/admin/projets/detail.php?id=<?= $projetId ?>" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i>Retour au projet
            </a>
        </div>
        <p class="text-muted"><?= e($projet['nom']) ?> - <?= e($projet['adresse']) ?></p>
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
    
    <!-- Résumé -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card bg-primary text-white">
                <div class="card-body text-center">
                    <h5>Total Prêts</h5>
                    <h3><?= formatMoney($totalPrets) ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-warning text-dark">
                <div class="card-body text-center">
                    <h5>Intérêts estimés</h5>
                    <h3><?= formatMoney($totalInteretsEstimes) ?></h3>
                    <small>(<?= $projet['temps_assume_mois'] ?> mois)</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-secondary text-white">
                <div class="card-body text-center">
                    <h5>Frais de dossier</h5>
                    <h3><?= formatMoney($totalFraisDossier) ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-success text-white">
                <div class="card-body text-center">
                    <h5>Total Investissements</h5>
                    <h3><?= formatMoney($totalInvestissements) ?></h3>
                </div>
            </div>
        </div>
    </div>
    
    <div class="row">
        <!-- Liste des financements -->
        <div class="col-md-8">
            <div class="card mb-4">
                <div class="card-header">
                    <i class="bi bi-list-ul me-2"></i>Financements actuels
                </div>
                <?php if (empty($financementsActuels)): ?>
                    <div class="card-body">
                        <p class="text-muted mb-0">Aucun financement configuré pour ce projet.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Type</th>
                                    <th>Nom</th>
                                    <th class="text-end">Montant</th>
                                    <th class="text-center">Taux</th>
                                    <th class="text-end">Intérêts/mois</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($financementsActuels as $f): 
                                    $montant = (float)($f['montant'] ?? $f['mise_de_fonds'] ?? 0);
                                    $taux = (float)($f['taux_interet'] ?? 0);
                                    $interetsMois = $montant * ($taux / 100) / 12;
                                ?>
                                    <tr>
                                        <td>
                                            <?php if (($f['type'] ?? '') === 'preteur'): ?>
                                                <span class="badge bg-primary">Prêteur</span>
                                            <?php else: ?>
                                                <span class="badge bg-success">Investisseur</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><strong><?= e($f['nom']) ?></strong></td>
                                        <td class="text-end"><?= formatMoney($montant) ?></td>
                                        <td class="text-center"><?= $taux ?>%</td>
                                        <td class="text-end"><?= formatMoney($interetsMois) ?></td>
                                        <td class="text-end">
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Supprimer ce financement?')">
                                                <?php csrfField(); ?>
                                                <input type="hidden" name="action" value="supprimer">
                                                <input type="hidden" name="id" value="<?= $f['id'] ?>">
                                                <button type="submit" class="btn btn-outline-danger btn-sm">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Formulaire d'ajout -->
        <div class="col-md-4">
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <i class="bi bi-plus-circle me-2"></i>Ajouter un financement
                </div>
                <div class="card-body">
                    <form method="POST">
                        <?php csrfField(); ?>
                        <input type="hidden" name="action" value="ajouter">
                        
                        <div class="mb-3">
                            <label class="form-label">Prêteur / Investisseur *</label>
                            <select class="form-select" name="investisseur_id" id="selectFinanceur" required>
                                <option value="">Sélectionner...</option>
                                <?php if (!empty($preteurs)): ?>
                                    <optgroup label="🏦 Prêteurs">
                                        <?php foreach ($preteurs as $p): ?>
                                            <option value="<?= $p['id'] ?>" 
                                                    data-type="preteur"
                                                    data-taux="<?= $p['taux_interet_defaut'] ?? 0 ?>"
                                                    data-frais="<?= $p['frais_dossier_defaut'] ?? 0 ?>">
                                                <?= e($p['nom']) ?> (<?= $p['taux_interet_defaut'] ?? 0 ?>%)
                                            </option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                <?php endif; ?>
                                <?php if (!empty($investisseurs)): ?>
                                    <optgroup label="👤 Investisseurs">
                                        <?php foreach ($investisseurs as $i): ?>
                                            <option value="<?= $i['id'] ?>" data-type="investisseur">
                                                <?= e($i['nom']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                <?php endif; ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Montant *</label>
                            <div class="input-group">
                                <input type="text" class="form-control money-input" name="montant" required placeholder="0">
                                <span class="input-group-text">$</span>
                            </div>
                        </div>
                        
                        <div id="champsPreteur">
                            <div class="mb-3">
                                <label class="form-label">Taux d'intérêt annuel</label>
                                <div class="input-group">
                                    <input type="text" class="form-control" name="taux_interet" id="tauxInteret" placeholder="10">
                                    <span class="input-group-text">%</span>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Frais de dossier</label>
                                <div class="input-group">
                                    <input type="text" class="form-control" name="frais_dossier" id="fraisDossier" placeholder="3">
                                    <span class="input-group-text">%</span>
                                </div>
                            </div>
                        </div>
                        
                        <div id="champsInvestisseur" style="display:none;">
                            <div class="mb-3">
                                <label class="form-label">% des profits</label>
                                <div class="input-group">
                                    <input type="text" class="form-control" name="pourcentage_profit" placeholder="0">
                                    <span class="input-group-text">%</span>
                                </div>
                            </div>
                        </div>
                        
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-plus-circle me-1"></i>Ajouter
                        </button>
                    </form>
                    
                    <hr>
                    <a href="/admin/investisseurs/liste.php" class="btn btn-outline-secondary btn-sm w-100">
                        <i class="bi bi-people me-1"></i>Gérer les prêteurs/investisseurs
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.getElementById('selectFinanceur').addEventListener('change', function() {
    const selected = this.options[this.selectedIndex];
    const type = selected.dataset.type;
    const champsPreteur = document.getElementById('champsPreteur');
    const champsInvestisseur = document.getElementById('champsInvestisseur');
    
    if (type === 'preteur') {
        champsPreteur.style.display = 'block';
        champsInvestisseur.style.display = 'none';
        document.getElementById('tauxInteret').value = selected.dataset.taux || '';
        document.getElementById('fraisDossier').value = selected.dataset.frais || '';
    } else if (type === 'investisseur') {
        champsPreteur.style.display = 'none';
        champsInvestisseur.style.display = 'block';
    } else {
        champsPreteur.style.display = 'none';
        champsInvestisseur.style.display = 'none';
    }
});
</script>

<?php include '../../includes/footer.php'; ?>
