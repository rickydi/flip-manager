<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "Étape 1: Début<br>";

echo "Étape 2: Inclusion config.php<br>";
require_once 'config.php';
echo "Config OK - APP_NAME: " . APP_NAME . "<br>";
echo "BASE_PATH: " . BASE_PATH . "<br>";

echo "Étape 3: Inclusion auth.php<br>";
require_once 'includes/auth.php';
echo "Auth OK<br>";

echo "Étape 4: Inclusion functions.php<br>";
require_once 'includes/functions.php';
echo "Functions OK<br>";

echo "Étape 5: Test fonction url()<br>";
echo "url('/admin') = " . url('/admin') . "<br>";

echo "<br><strong>Tout fonctionne !</strong>";
