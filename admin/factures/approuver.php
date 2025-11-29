<?php
/**
 * Approuver/Rejeter factures - Admin
 * Flip Manager
 */

require_once '../../config.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

// Vérifier que l'utilisateur est admin
requireAdmin();

$pageTitle = 'Approbation des factures';

// Traitement des actions rapides (GET)
if (isset($_GET['action']) && isset($_GET['id'])) {
    $action = $_GET['action'];
    $factureId = (int)$_GET['id'];
    
    if ($action === 'approuver') {
        $stmt = $pdo->prepare("
            UPDATE factures SET 
                statut = 'approuvee', 
                approuve_par = ?, 
                date_approbation = NOW() 
            WHERE id = ? AND statut = 'en_attente'
        ");
        $stmt->execute([getCurrentUserId(), $factureId]);
        setFlashMessage('success', 'Facture approuvée avec succès.');
    } elseif ($action === 'rejeter') {
        // Afficher le formulaire de rejet
        $stmt = $pdo->prepare("
            SELECT f.*, p.nom as projet_nom, c.nom as categorie_nom
            FROM factures f
            JOIN projets p ON f.projet_id = p.id
            JOIN categories c ON f.categorie_id = c.id
            WHERE f.id = ?
        ");
        $stmt->execute([$factureId]);
        $facture = $stmt->fetch();
        
        if ($facture && $facture['statut'] === 'en_attente') {
            // Afficher formulaire de rejet
            include '../../includes/header.php';
            ?>
            <div class="container-fluid">
                <div class="page-header">
                    <h1><i class="bi bi-x-circle me-2"></i>Rejeter la facture</h1>
                </div>
                
                <div class="card">
                    <div class="card-header">
                        Facture de <?= e($facture['fournisseur']) ?> - <?= formatMoney($facture['montant_total']) ?>
                    </div>
                    <div class="card-body">
                        <p><strong>Projet:</strong> <?= e($facture['projet_nom']) ?></p>
                        <p><strong>Catégorie:</strong> <?= e($facture['categorie_nom']) ?></p>
                        <p><strong>Date:</strong> <?= formatDate($facture['date_facture']) ?></p>
                        
                        <form method="POST" action="">
                            <?php csrfField(); ?>
                            <input type="hidden" name="facture_id" value="<?= $factureId ?>">
                            <input type="hidden" name="action" value="rejeter">
                            
                            <div class="mb-3">
                                <label for="commentaire" class="form-label">Raison du rejet *</label>
                                <textarea class="form-control" id="commentaire" name="commentaire" rows="3" required
                                          placeholder="Expliquez pourquoi cette facture est rejetée..."></textarea>
                            </div>
                            
                            <div class="d-flex justify-content-between">
                                <a href="/admin/factures/approuver.php" class="btn btn-outline-secondary">Annuler</a>
                                <button type="submit" class="btn btn-danger">
                                    <i class="bi bi-x-circle me-1"></i>Confirmer le rejet
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <?php
            include '../../includes/footer.php';
            exit;
        }
    }
    
    redirect('/admin/factures/approuver.php');
}

// Traitement POST (rejet avec commentaire)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $factureId = (int)($_POST['facture_id'] ?? 0);
        $action = $_POST['action'] ?? '';
        $commentaire = trim($_POST['commentaire'] ?? '');
        
        if ($action === 'rejeter' && $factureId > 0 && !empty($commentaire)) {
            $stmt = $pdo->prepare("
                UPDATE factures SET 
                    statut = 'rejetee', 
                    commentaire_admin = ?,
                    approuve_par = ?, 
                    date_approbation = NOW() 
                WHERE id = ? AND statut = 'en_attente'
            ");
            $stmt->execute([$commentaire, getCurrentUserId(), $factureId]);
            setFlashMessage('warning', 'Facture rejetée.');
        }
    }
    redirect('/admin/factures/approuver.php');
}

// Récupérer les factures en attente
$stmt = $pdo->query("
    SELECT f.*, p.nom as projet_nom, c.nom as categorie_nom,
           CONCAT(u.prenom, ' ', u.nom) as employe_nom
    FROM factures f
    JOIN projets p ON f.projet_id = p.id
    JOIN categories c ON f.categorie_id = c.id
    JOIN users u ON f.user_id = u.id
    WHERE f.statut = 'en_attente'
    ORDER BY f.date_creation ASC
");
$factures = $stmt->fetchAll();

include '../../includes/header.php';
?>

<div class="container-fluid">
    <!-- En-tête -->
    <div class="page-header">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="/admin/index.php">Tableau de bord</a></li>
                <li class="breadcrumb-item"><a href="/admin/factures/liste.php">Factures</a></li>
                <li class="breadcrumb-item active">À approuver</li>
            </ol>
        </nav>
        <h1>
            <i class="bi bi-check2-square me-2"></i>Factures à approuver
            <?php if (count($factures) > 0): ?>
                <span class="badge bg-danger"><?= count($factures) ?></span>
            <?php endif; ?>
        </h1>
    </div>
    
    <?php displayFlashMessage(); ?>
    
    <div class="card">
        <div class="card-body p-0">
            <?php if (empty($factures)): ?>
                <div class="empty-state">
                    <i class="bi bi-check-circle text-success"></i>
                    <h4>Aucune facture en attente</h4>
                    <p>Toutes les factures ont été traitées.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Projet</th>
                                <th>Fournisseur</th>
                                <th>Catégorie</th>
                                <th>Employé</th>
                                <th class="text-end">Montant</th>
                                <th>Fichier</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($factures as $facture): ?>
                                <tr>
                                    <td>
                                        <?= formatDate($facture['date_facture']) ?>
                                        <br>
                                        <small class="text-muted">Soumis <?= formatDateTime($facture['date_creation']) ?></small>
                                    </td>
                                    <td><?= e($facture['projet_nom']) ?></td>
                                    <td>
                                        <strong><?= e($facture['fournisseur']) ?></strong>
                                        <?php if ($facture['description']): ?>
                                            <br><small class="text-muted"><?= e(substr($facture['description'], 0, 50)) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= e($facture['categorie_nom']) ?></td>
                                    <td><?= e($facture['employe_nom']) ?></td>
                                    <td class="text-end">
                                        <strong><?= formatMoney($facture['montant_total']) ?></strong>
                                        <br>
                                        <small class="text-muted">HT: <?= formatMoney($facture['montant_avant_taxes']) ?></small>
                                    </td>
                                    <td>
                                        <?php if ($facture['fichier']): ?>
                                            <a href="/uploads/factures/<?= e($facture['fichier']) ?>" 
                                               target="_blank" 
                                               class="btn btn-outline-secondary btn-sm">
                                                <i class="bi bi-file-earmark"></i> Voir
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <a href="?action=approuver&id=<?= $facture['id'] ?>" 
                                           class="btn btn-success btn-sm"
                                           onclick="return confirm('Approuver cette facture?')">
                                            <i class="bi bi-check-lg me-1"></i>Approuver
                                        </a>
                                        <a href="?action=rejeter&id=<?= $facture['id'] ?>" 
                                           class="btn btn-danger btn-sm">
                                            <i class="bi bi-x-lg me-1"></i>Rejeter
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
