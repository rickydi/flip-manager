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
            <a class="nav-link" href="<?= url('/admin/templates/liste.php') ?>">
                <i class="bi bi-box-seam me-1"></i>Templates
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
            <a class="nav-link active" href="<?= url('/admin/configuration/index.php') ?>">
                <i class="bi bi-gear-wide-connected me-1"></i>Configuration
            </a>
        </li>
    </ul>

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
                                        
                                        <?php elseif ($conf['cle'] === 'CLAUDE_MODEL'): 
                                            $knownModels = [
                                                'claude-3-5-sonnet-20241022',
                                                'claude-3-opus-20240229',
                                                'claude-3-sonnet-20240229',
                                                'claude-3-haiku-20240307',
                                                'claude-3-5-haiku-20241022',
                                                'claude-3-7-sonnet-20250219' // Exemple prédictif si l'utilisateur insiste
                                            ];
                                            // Ne pas forcer 'custom' si la valeur est connue, même si je ne l'ai pas listée ci-dessus dans $knownModels pour l'affichage PHP simple
                                            // Ici on simplifie : si la valeur n'est pas dans les options ci-dessous, c'est custom.
                                            $isCustom = !in_array($conf['valeur'], [
                                                'claude-3-5-sonnet-20241022', 'claude-3-opus-20240229', 'claude-3-sonnet-20240229', 'claude-3-haiku-20240307',
                                                'claude-3-5-haiku-20241022'
                                            ]);
                                        ?>
                                            <select class="form-select" onchange="updateModelInput(this)">
                                                <option value="claude-3-5-sonnet-20241022" <?= $conf['valeur'] == 'claude-3-5-sonnet-20241022' ? 'selected' : '' ?>>Claude 3.5 Sonnet (Recommandé)</option>
                                                <option value="claude-3-5-haiku-20241022" <?= $conf['valeur'] == 'claude-3-5-haiku-20241022' ? 'selected' : '' ?>>Claude 3.5 Haiku</option>
                                                <option value="claude-3-opus-20240229" <?= $conf['valeur'] == 'claude-3-opus-20240229' ? 'selected' : '' ?>>Claude 3 Opus</option>
                                                <option value="claude-3-sonnet-20240229" <?= $conf['valeur'] == 'claude-3-sonnet-20240229' ? 'selected' : '' ?>>Claude 3 Sonnet</option>
                                                <option value="claude-3-haiku-20240307" <?= $conf['valeur'] == 'claude-3-haiku-20240307' ? 'selected' : '' ?>>Claude 3 Haiku</option>
                                                <option value="custom" <?= $isCustom ? 'selected' : '' ?>>Autre / Futur modèle (ex: Claude 4.5)</option>
                                            </select>
                                        </div>
                                        
                                        <!-- Champ caché ou visible pour la valeur réelle envoyée -->
                                        <div class="mt-2 <?= $isCustom ? '' : 'd-none' ?>" id="custom_model_container">
                                            <label class="form-label small text-muted">Identifiant du modèle :</label>
                                            <input type="text" 
                                                   class="form-control" 
                                                   id="input_claude_model"
                                                   name="config[<?= $conf['cle'] ?>]" 
                                                   value="<?= e($conf['valeur']) ?>"
                                                   placeholder="Ex: claude-4-5-opus...">
                                        </div>
                                        
                                        <div class="form-text">
                                            Sélectionnez un modèle ou choisissez "Autre" pour saisir un futur modèle (ex: <code>claude-4.5</code>).
                                        </div>

                                        <script>
                                        function updateModelInput(select) {
                                            var container = document.getElementById('custom_model_container');
                                            var input = document.getElementById('input_claude_model');
                                            
                                            if (select.value === 'custom') {
                                                container.classList.remove('d-none');
                                                input.focus();
                                                // On ne change pas la valeur de l'input, l'utilisateur doit saisir
                                            } else {
                                                container.classList.add('d-none');
                                                input.value = select.value;
                                            }
                                        }
                                        </script>
                                        
                                        <?php else: ?>
                                            <input type="text" 
                                                   class="form-control" 
                                                   name="config[<?= $conf['cle'] ?>]" 
                                                   value="<?= e($conf['valeur']) ?>">
                                        <?php endif; ?>
                                        
                                        <?php if ($conf['cle'] !== 'CLAUDE_MODEL'): ?>
                                    </div> <!-- Fin input-group normal -->
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
