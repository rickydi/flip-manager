<?php
/**
 * Configuration de la base de données
 * Flip Manager - Application de gestion de flips immobiliers
 */

// Mode debug (désactiver en production)
define('DEBUG_MODE', true);

if (DEBUG_MODE) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}

// Configuration de la base de données
define('DB_HOST', 'localhost');
define('DB_NAME', 'evorenoc_flip_manager');
define('DB_USER', 'evorenoc_flip');
define('DB_PASS', 'Zun+668@');
define('DB_CHARSET', 'utf8mb4');

// Configuration de l'application
define('APP_NAME', 'Flip Manager');
define('APP_URL', 'https://evoreno.com');
define('APP_VERSION', '1.0.0');
define('BASE_PATH', '/flip'); // Chemin de base de l'application

// Configuration des uploads
define('UPLOAD_PATH', __DIR__ . '/uploads/factures/');
define('UPLOAD_MAX_SIZE', 5 * 1024 * 1024); // 5 MB
define('ALLOWED_EXTENSIONS', ['jpg', 'jpeg', 'png', 'gif', 'pdf']);

// Fuseau horaire
date_default_timezone_set('America/Toronto');

// Connexion à la base de données avec PDO
try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
} catch (PDOException $e) {
    if (DEBUG_MODE) {
        die("Erreur de connexion à la base de données: " . $e->getMessage());
    } else {
        die("Erreur de connexion à la base de données. Veuillez contacter l'administrateur.");
    }
}

// Démarrage de la session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
