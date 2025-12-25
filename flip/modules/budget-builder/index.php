<?php
/**
 * Budget Builder - Module Standalone
 * Page de test/dev autonome
 */

require_once '../../config.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

requireAdmin();

$projetId = isset($_GET['projet_id']) ? (int)$_GET['projet_id'] : 0;

// Récupérer le projet si spécifié
$projet = null;
if ($projetId) {
    $stmt = $pdo->prepare("SELECT * FROM projets WHERE id = ?");
    $stmt->execute([$projetId]);
    $projet = $stmt->fetch();
}

$pageTitle = 'Budget Builder' . ($projet ? ' - ' . $projet['nom'] : '');

include '../../includes/header.php';
?>

<div class="container-fluid py-3">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0">
            <i class="bi bi-calculator me-2"></i>Budget Builder
            <?php if ($projet): ?>
                <span class="text-muted">- <?= e($projet['nom']) ?></span>
            <?php endif; ?>
        </h4>
        <?php if (!$projet): ?>
            <div class="alert alert-warning mb-0 py-1 px-3">
                <i class="bi bi-info-circle me-1"></i>
                Mode standalone - <a href="<?= url('/admin/projets/liste.php') ?>">Sélectionner un projet</a>
            </div>
        <?php endif; ?>
    </div>

    <?php include 'component.php'; ?>
</div>

<?php include '../../includes/footer.php'; ?>
