<?php
/**
 * Webhook pour déploiement automatique Git
 * Ce script est appelé par GitHub lors d'un push
 */

// Clé secrète pour sécuriser le webhook (changez-la!)
$secret = 'flip-manager-deploy-2024';

// Vérifier le token
$token = $_GET['token'] ?? '';
if ($token !== $secret) {
    http_response_code(403);
    exit('Accès refusé');
}

// Chemin du repository
$repoPath = '/home/evorenoc/public_html';

// Exécuter git pull
$output = [];
$returnCode = 0;

chdir($repoPath);
exec('git pull origin master 2>&1', $output, $returnCode);

// Logger le résultat
$log = date('Y-m-d H:i:s') . " - Deploy\n";
$log .= "Return code: $returnCode\n";
$log .= implode("\n", $output) . "\n\n";

file_put_contents($repoPath . '/deploy.log', $log, FILE_APPEND);

// Réponse
header('Content-Type: application/json');
echo json_encode([
    'success' => $returnCode === 0,
    'output' => $output
]);
