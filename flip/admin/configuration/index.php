<?php
/**
 * Configuration Système
 * Flip Manager
 */

require_once '../../config.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

requireAdmin();

$pageTitle = 'Configuration Système';

// Auto-migration si la table n'existe pas (sécurité)
try {
    $pdo->query("SELECT 1 FROM app_configurations LIMIT 1");
} catch (Exception $e) {
    // Création de table gérée ailleurs (migration ou ClaudeService), mais on s'assure ici aussi
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS app_configurations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            cle VARCHAR(50) NOT NULL UNIQUE,
            valeur TEXT NULL,
            description VARCHAR(255) NULL,
            est_sensible TINYINT(1) DEFAULT 0,
            date_modification TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

// S'assurer que les clés Pushover existent (migration)
$stmt = $pdo->prepare("SELECT COUNT(*) FROM app_configurations WHERE cle = 'PUSHOVER_APP_TOKEN'");
$stmt->execute();
if ($stmt->fetchColumn() == 0) {
    $stmt = $pdo->prepare("INSERT IGNORE INTO app_configurations (cle, valeur, description, est_sensible) VALUES (?, ?, ?, ?)");
    $stmt->execute(['PUSHOVER_APP_TOKEN', '', 'Token application Pushover (notifications)', 1]);
    $stmt->execute(['PUSHOVER_USER_KEY', '', 'Clé utilisateur Pushover', 1]);
}

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token invalide.';
    } else {
        $configs = $_POST['config'] ?? [];
        
        try {
            $stmt = $pdo->prepare("UPDATE app_configurations SET valeur = ? WHERE cle = ?");
            
            foreach ($configs as $cle => $valeur) {
                // Si c'est une valeur sensible et qu'on reçoit les étoiles, on ne change rien
                if (strpos($valeur, '******') !== false) {
                    continue;
                }
                
                $stmt->execute([trim($valeur), $cle]);
            }
            
            setFlashMessage('success', 'Configuration mise à jour.');
            redirect('index.php');
            
        } catch (Exception $e) {
            $errors[] = 'Erreur lors de la sauvegarde : ' . $e->getMessage();
        }
    }
}

// Récupérer les configurations
$stmt = $pdo->query("SELECT * FROM app_configurations ORDER BY cle");
$configs = $stmt->fetchAll();

include '../../includes/header.php';
?>

<div class="container-fluid">
    <div class="page-header">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= url('/admin/index.php') ?>">Tableau de bord</a></li>
                <li class="breadcrumb-item active">Configuration</li>
            </ol>
        </nav>
        <h1><i class="bi bi-gear-wide-connected me-2"></i>Configuration Système & IA</h1>
    </div>

    <!-- Sous-navigation Administration -->
    <ul class="nav nav-tabs mb-4">
        <li class="nav-item">
            <a class="nav-link" href="<?= url('/admin/utilisateurs/liste.php') ?>">
                <i class="bi bi-person-badge me-1"></i>Utilisateurs
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="<?= url('/admin/fournisseurs/liste.php') ?>">
                <i class="bi bi-shop me-1"></i>Fournisseurs
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="<?= url('/admin/recurrents/liste.php') ?>">
                <i class="bi bi-arrow-repeat me-1"></i>Récurrents
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="<?= url('/admin/checklists/liste.php') ?>">
                <i class="bi bi-list-check me-1"></i>Checklists
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="<?= url('/admin/rapports/index.php') ?>">
                <i class="bi bi-file-earmark-bar-graph me-1"></i>Rapports
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link active" href="<?= url('/admin/configuration/index.php') ?>">
                <i class="bi bi-gear me-1"></i>Configuration
            </a>
        </li>
    </ul>

    <?php displayFlashMessage(); ?>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger">
            <ul class="mb-0">
                <?php foreach ($errors as $error): ?>
                    <li><?= e($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="row">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header bg-dark text-white">
                    Paramètres et Clés API
                </div>
                <div class="card-body">
                    <form method="POST" action="">
                        <?php csrfField(); ?>
                        
                        <?php if (empty($configs)): ?>
                            <div class="alert alert-warning">Aucune configuration trouvée.</div>
                        <?php else: ?>
                            <?php foreach ($configs as $conf): ?>
                                <div class="mb-4">
                                    <label class="form-label fw-bold"><?= e($conf['description'] ?: $conf['cle']) ?></label>
                                    <div class="input-group">
                                        <span class="input-group-text font-monospace bg-light"><?= e($conf['cle']) ?></span>
                                        <?php if ($conf['est_sensible']): ?>
                                            <input type="password" 
                                                   class="form-control" 
                                                   name="config[<?= $conf['cle'] ?>]" 
                                                   value="<?= !empty($conf['valeur']) ? '********************' : '' ?>"
                                                   placeholder="Saisir la clé pour modifier"
                                                   autocomplete="off">
                                        <?php else: ?>
                                            <input type="text" 
                                                   class="form-control" 
                                                   name="config[<?= $conf['cle'] ?>]" 
                                                   value="<?= e($conf['valeur']) ?>">
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($conf['cle'] === 'CLAUDE_MODEL'): ?>
                                        <div class="form-text">Modèles disponibles : claude-3-5-sonnet-20241022 (recommandé), claude-3-opus-20240229.</div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                            
                            <div class="d-flex justify-content-end">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-save me-1"></i>Enregistrer les modifications
                                </button>
                            </div>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
        </div>
        
        <div class="col-lg-4">
            <div class="card bg-info bg-opacity-10 mb-4">
                <div class="card-body">
                    <h5 class="card-title"><i class="bi bi-info-circle me-2"></i>À propos des clés API</h5>
                    <p class="card-text small">
                        Les clés API permettent de connecter votre application à des services d'Intelligence Artificielle comme Claude (Anthropic).
                    </p>
                    <p class="card-text small">
                        Ces clés sont stockées de manière sécurisée. Ne les partagez jamais.
                    </p>
                    <hr>
                    <h6 class="card-subtitle mb-2 text-muted">Ajouter une nouvelle clé ?</h6>
                    <p class="card-text small">
                        Pour l'instant, seules les clés configurées par le système (Claude) sont gérées ici. D'autres services pourront être ajoutés dans le futur.
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
