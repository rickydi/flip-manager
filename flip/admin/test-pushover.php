<?php
/**
 * Test Pushover - Page de diagnostic
 * Accès: /admin/test-pushover.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/notifications.php';

// Admin seulement
requireAdmin();

// Vider le log si demandé (avant tout output)
$logFile = __DIR__ . '/../logs/pushover_debug.log';
if (isset($_GET['clear_log'])) {
    if (file_exists($logFile)) {
        unlink($logFile);
    }
    header('Location: test-pushover.php');
    exit;
}

$result = null;
$logContent = '';

// Lire le log existant
if (file_exists($logFile)) {
    $logContent = file_get_contents($logFile);
}

// Test manuel
if (isset($_POST['test'])) {
    $result = sendPushoverNotification(
        'Test Flip Manager',
        'Ceci est un test de notification Pushover.',
        '0',
        APP_URL . BASE_PATH . '/admin/'
    );

    // Relire le log après le test
    if (file_exists($logFile)) {
        $logContent = file_get_contents($logFile);
    }
}

// Récupérer les clés actuelles
$appToken = getNotificationConfig($pdo, 'PUSHOVER_APP_TOKEN');
$userKey = getNotificationConfig($pdo, 'PUSHOVER_USER_KEY');

$pageTitle = 'Test Pushover';
include '../includes/header.php';
?>

<div class="container-fluid">
    <h1><i class="bi bi-bell me-2"></i>Test Pushover</h1>

    <div class="row">
        <div class="col-md-6">
            <div class="card mb-4">
                <div class="card-header">Configuration actuelle</div>
                <div class="card-body">
                    <table class="table table-sm">
                        <tr>
                            <th>PUSHOVER_APP_TOKEN</th>
                            <td>
                                <?php if (!empty($appToken)): ?>
                                    <span class="badge bg-success">Configuré</span>
                                    <small class="text-muted">(<?= strlen($appToken) ?> caractères)</small>
                                    <br><code><?= htmlspecialchars(substr($appToken, 0, 8)) ?>...<?= htmlspecialchars(substr($appToken, -4)) ?></code>
                                <?php else: ?>
                                    <span class="badge bg-danger">Non configuré</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th>PUSHOVER_USER_KEY</th>
                            <td>
                                <?php if (!empty($userKey)): ?>
                                    <span class="badge bg-success">Configuré</span>
                                    <small class="text-muted">(<?= strlen($userKey) ?> caractères)</small>
                                    <br><code><?= htmlspecialchars(substr($userKey, 0, 8)) ?>...<?= htmlspecialchars(substr($userKey, -4)) ?></code>
                                <?php else: ?>
                                    <span class="badge bg-danger">Non configuré</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th>APP_URL</th>
                            <td><code><?= htmlspecialchars(APP_URL) ?></code></td>
                        </tr>
                        <tr>
                            <th>BASE_PATH</th>
                            <td><code><?= htmlspecialchars(BASE_PATH) ?></code></td>
                        </tr>
                        <tr>
                            <th>cURL disponible</th>
                            <td>
                                <?php if (function_exists('curl_init')): ?>
                                    <span class="badge bg-success">Oui</span>
                                <?php else: ?>
                                    <span class="badge bg-danger">Non</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>

                    <form method="POST">
                        <?php csrfField(); ?>
                        <button type="submit" name="test" class="btn btn-primary">
                            <i class="bi bi-send me-1"></i>Envoyer notification test
                        </button>
                    </form>

                    <?php if ($result !== null): ?>
                        <div class="alert <?= $result ? 'alert-success' : 'alert-danger' ?> mt-3">
                            <?php if ($result): ?>
                                <i class="bi bi-check-circle me-1"></i>Notification envoyée avec succès!
                            <?php else: ?>
                                <i class="bi bi-x-circle me-1"></i>Échec de l'envoi. Voir le log ci-dessous.
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>Log de débogage</span>
                    <?php if (file_exists($logFile)): ?>
                        <a href="?clear_log=1" class="btn btn-sm btn-outline-danger">Vider le log</a>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <?php if (!empty($logContent)): ?>
                        <pre style="max-height: 400px; overflow-y: auto; background: #1a1a2e; color: #0f0; padding: 10px; border-radius: 5px; font-size: 12px;"><?= htmlspecialchars($logContent) ?></pre>
                    <?php else: ?>
                        <p class="text-muted">Aucun log disponible. Envoyez une notification test.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
