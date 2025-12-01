<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "Test 3 - Simulation de index.php<br><br>";

require_once 'config.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

echo "Includes OK<br>";

// Si déjà connecté, rediriger vers le dashboard approprié
if (isLoggedIn()) {
    echo "Utilisateur connecté - isAdmin: " . (isAdmin() ? 'oui' : 'non') . "<br>";
} else {
    echo "Utilisateur non connecté<br>";
}

$error = '';
echo "Variable error OK<br>";

echo "Méthode: " . $_SERVER['REQUEST_METHOD'] . "<br>";

echo "<br>BASE_PATH en HTML: " . BASE_PATH . "<br>";
echo "CSS path: " . BASE_PATH . "/assets/css/style.css<br>";

echo "<br><strong>Tout le code PHP fonctionne !</strong><br>";
echo "<br>Début du HTML...<br>";
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Test Connexion</title>
    <link href="<?= BASE_PATH ?>/assets/css/style.css" rel="stylesheet">
</head>
<body>
    <h1>Page de test OK</h1>
    <p>Si vous voyez ceci, le HTML fonctionne.</p>
</body>
</html>
