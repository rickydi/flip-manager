<?php
/**
 * Endpoint pour changer la langue
 * Flip Manager
 */

require_once __DIR__ . '/config.php';

$lang = $_GET['lang'] ?? $_POST['lang'] ?? '';

if (setLanguage($lang)) {
    // Rediriger vers la page précédente
    $redirect = $_SERVER['HTTP_REFERER'] ?? '/flip/employe/index.php';
    header('Location: ' . $redirect);
    exit;
}

// Si langue invalide, rediriger quand même
header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '/flip/employe/index.php'));
exit;
