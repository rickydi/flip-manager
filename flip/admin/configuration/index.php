<?php
/**
 * Configuration Système
 * Flip Manager
 */

require_once '../../config.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../includes/AIServiceFactory.php';

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

// S'assurer que toutes les configurations IA existent
AIServiceFactory::ensureConfigurationsExist($pdo);

// S'assurer que les clés Pushover existent (migration)
$stmt = $pdo->prepare("SELECT COUNT(*) FROM app_configurations WHERE cle = 'PUSHOVER_APP_TOKEN'");
$stmt->execute();
if ($stmt->fetchColumn() == 0) {
    $stmt = $pdo->prepare("INSERT IGNORE INTO app_configurations (cle, valeur, description, est_sensible) VALUES (?, ?, ?, ?)");
    $stmt->execute(['PUSHOVER_APP_TOKEN', '', 'Token application Pushover (notifications)', 1]);
    $stmt->execute(['PUSHOVER_USER_KEY', '', 'Clé utilisateur Pushover', 1]);
}

// Récupérer les providers disponibles
$aiProviders = AIServiceFactory::getAvailableProviders();
$currentProvider = AIServiceFactory::getConfiguredProvider($pdo);

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
            <form method="POST" action="">
                <?php csrfField(); ?>

                <!-- Sélection du Provider IA -->
                <div class="card mb-4">
                    <div class="card-header bg-primary text-white">
                        <i class="bi bi-robot me-2"></i>Provider IA
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Choisir le service d'IA à utiliser</label>
                            <div class="row">
                                <?php foreach ($aiProviders as $key => $provider): ?>
                                    <?php $isConfigured = AIServiceFactory::isProviderConfigured($pdo, $key); ?>
                                    <div class="col-md-6 mb-2">
                                        <div class="form-check card h-100 <?= $currentProvider === $key ? 'border-primary' : '' ?>">
                                            <div class="card-body">
                                                <input class="form-check-input" type="radio"
                                                       name="config[AI_PROVIDER]"
                                                       id="provider_<?= $key ?>"
                                                       value="<?= $key ?>"
                                                       <?= $currentProvider === $key ? 'checked' : '' ?>>
                                                <label class="form-check-label w-100" for="provider_<?= $key ?>">
                                                    <strong><?= e($provider['name']) ?></strong>
                                                    <?php if (!$isConfigured): ?>
                                                        <span class="badge bg-warning text-dark ms-2">Non configuré</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-success ms-2">Configuré</span>
                                                    <?php endif; ?>
                                                    <br>
                                                    <small class="text-muted"><?= e($provider['description']) ?></small>
                                                </label>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Configuration Claude -->
                <div class="card mb-4" id="claude-config">
                    <div class="card-header bg-dark text-white">
                        <i class="bi bi-cpu me-2"></i>Claude (Anthropic)
                    </div>
                    <div class="card-body">
                        <?php
                        $claudeKey = '';
                        $claudeModel = 'claude-sonnet-4-20250514';
                        foreach ($configs as $conf) {
                            if ($conf['cle'] === 'ANTHROPIC_API_KEY') $claudeKey = $conf['valeur'];
                            if ($conf['cle'] === 'CLAUDE_MODEL') $claudeModel = $conf['valeur'];
                        }
                        ?>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Clé API Anthropic</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-key"></i></span>
                                <input type="password"
                                       class="form-control"
                                       name="config[ANTHROPIC_API_KEY]"
                                       value="<?= !empty($claudeKey) ? '********************' : '' ?>"
                                       placeholder="sk-ant-api..."
                                       autocomplete="off">
                            </div>
                            <div class="form-text">Obtenez votre clé sur <a href="https://console.anthropic.com/" target="_blank">console.anthropic.com</a></div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Modèle Claude</label>
                            <select class="form-select" name="config[CLAUDE_MODEL]">
                                <?php foreach ($aiProviders['claude']['models'] as $modelId => $modelName): ?>
                                    <option value="<?= $modelId ?>" <?= $claudeModel === $modelId ? 'selected' : '' ?>>
                                        <?= e($modelName) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">
                                <strong>Sonnet 4</strong> : Équilibre performance/coût |
                                <strong>Opus 4.5</strong> : Le plus puissant |
                                <strong>Haiku 4.5</strong> : Rapide et économique
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Configuration Gemini -->
                <div class="card mb-4" id="gemini-config">
                    <div class="card-header bg-dark text-white">
                        <i class="bi bi-google me-2"></i>Gemini (Google)
                    </div>
                    <div class="card-body">
                        <?php
                        $geminiKey = '';
                        $geminiModel = 'gemini-2.5-flash';
                        foreach ($configs as $conf) {
                            if ($conf['cle'] === 'GEMINI_API_KEY') $geminiKey = $conf['valeur'];
                            if ($conf['cle'] === 'GEMINI_MODEL') $geminiModel = $conf['valeur'];
                        }
                        ?>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Clé API Google AI</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-key"></i></span>
                                <input type="password"
                                       class="form-control"
                                       name="config[GEMINI_API_KEY]"
                                       value="<?= !empty($geminiKey) ? '********************' : '' ?>"
                                       placeholder="AIza..."
                                       autocomplete="off">
                            </div>
                            <div class="form-text">Obtenez votre clé sur <a href="https://aistudio.google.com/apikey" target="_blank">aistudio.google.com</a></div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Modèle Gemini</label>
                            <select class="form-select" name="config[GEMINI_MODEL]">
                                <?php foreach ($aiProviders['gemini']['models'] as $modelId => $modelName): ?>
                                    <option value="<?= $modelId ?>" <?= $geminiModel === $modelId ? 'selected' : '' ?>>
                                        <?= e($modelName) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">
                                <strong>2.5 Flash</strong> : Recommandé, contexte 1M tokens |
                                <strong>Flash Lite</strong> : Plus économique
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Autres configurations (Pushover, etc.) -->
                <div class="card mb-4">
                    <div class="card-header bg-dark text-white">
                        <i class="bi bi-bell me-2"></i>Notifications (Pushover)
                    </div>
                    <div class="card-body">
                        <?php
                        $pushoverToken = '';
                        $pushoverUser = '';
                        foreach ($configs as $conf) {
                            if ($conf['cle'] === 'PUSHOVER_APP_TOKEN') $pushoverToken = $conf['valeur'];
                            if ($conf['cle'] === 'PUSHOVER_USER_KEY') $pushoverUser = $conf['valeur'];
                        }
                        ?>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Token Application Pushover</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-key"></i></span>
                                <input type="password"
                                       class="form-control"
                                       name="config[PUSHOVER_APP_TOKEN]"
                                       value="<?= !empty($pushoverToken) ? '********************' : '' ?>"
                                       placeholder="Token application"
                                       autocomplete="off">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Clé Utilisateur Pushover</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-person"></i></span>
                                <input type="password"
                                       class="form-control"
                                       name="config[PUSHOVER_USER_KEY]"
                                       value="<?= !empty($pushoverUser) ? '********************' : '' ?>"
                                       placeholder="Clé utilisateur"
                                       autocomplete="off">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="d-flex justify-content-end mb-4">
                    <button type="submit" class="btn btn-primary btn-lg">
                        <i class="bi bi-save me-1"></i>Enregistrer les modifications
                    </button>
                </div>
            </form>
        </div>

        <div class="col-lg-4">
            <!-- Provider actif -->
            <div class="card bg-success bg-opacity-10 mb-4">
                <div class="card-body">
                    <h5 class="card-title"><i class="bi bi-check-circle me-2"></i>Provider actif</h5>
                    <p class="card-text">
                        <strong><?= e(AIServiceFactory::getActiveProviderName($pdo)) ?></strong>
                    </p>
                    <?php if (AIServiceFactory::isProviderConfigured($pdo, $currentProvider)): ?>
                        <span class="badge bg-success"><i class="bi bi-check"></i> Configuré et prêt</span>
                    <?php else: ?>
                        <span class="badge bg-warning text-dark"><i class="bi bi-exclamation-triangle"></i> Clé API manquante</span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Info Claude -->
            <div class="card bg-info bg-opacity-10 mb-4">
                <div class="card-body">
                    <h5 class="card-title"><i class="bi bi-cpu me-2"></i>Claude (Anthropic)</h5>
                    <p class="card-text small">
                        Excellent pour l'analyse de documents et le raisonnement complexe.
                    </p>
                    <ul class="small mb-0">
                        <li><strong>Contexte :</strong> 200K tokens</li>
                        <li><strong>Vision :</strong> Images, PDF</li>
                        <li><strong>Forces :</strong> Précision, analyse</li>
                    </ul>
                </div>
            </div>

            <!-- Info Gemini -->
            <div class="card bg-warning bg-opacity-10 mb-4">
                <div class="card-body">
                    <h5 class="card-title"><i class="bi bi-google me-2"></i>Gemini (Google)</h5>
                    <p class="card-text small">
                        Grande fenêtre de contexte, idéal pour les longs documents.
                    </p>
                    <ul class="small mb-0">
                        <li><strong>Contexte :</strong> 1M tokens</li>
                        <li><strong>Vision :</strong> Images, PDF, Vidéo</li>
                        <li><strong>Forces :</strong> Documents longs, multimédia</li>
                    </ul>
                </div>
            </div>

            <!-- Aide -->
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title"><i class="bi bi-question-circle me-2"></i>Besoin d'aide ?</h5>
                    <p class="card-text small">
                        <strong>Claude :</strong> <a href="https://console.anthropic.com/" target="_blank">console.anthropic.com</a><br>
                        <strong>Gemini :</strong> <a href="https://aistudio.google.com/" target="_blank">aistudio.google.com</a>
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
