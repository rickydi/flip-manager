<?php
/**
 * Configuration de la base de données
 * Flip Manager - Application de gestion de flips immobiliers
 */

// Mode debug (désactiver en production)
define('DEBUG_MODE', false);

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
define('APP_NAME', 'Manager');
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

// Migration: ajouter colonne duree_derniere_session si elle n'existe pas
try {
    $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS duree_derniere_session INT DEFAULT NULL");
} catch (Exception $e) {
    // Ignorer si la colonne existe déjà
}

// Migration: créer la table user_activity pour l'historique des activités
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_activity (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        action VARCHAR(50) NOT NULL,
        page VARCHAR(255) DEFAULT NULL,
        details VARCHAR(255) DEFAULT NULL,
        ip_address VARCHAR(45) DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user_id (user_id),
        INDEX idx_created_at (created_at)
    )");
} catch (Exception $e) {
    // Ignorer si la table existe déjà
}

// Mettre à jour la durée de session et logger l'activité (si connecté)
if (isset($_SESSION['user_id']) && isset($_SESSION['login_time'])) {
    $dureeSession = time() - $_SESSION['login_time'];
    try {
        $stmt = $pdo->prepare("UPDATE users SET duree_derniere_session = ? WHERE id = ?");
        $stmt->execute([$dureeSession, $_SESSION['user_id']]);
    } catch (Exception $e) {
        // Ignorer les erreurs
    }

    // Logger les pages visitées (sauf API et assets)
    $currentPage = $_SERVER['REQUEST_URI'] ?? '';
    $isApi = strpos($currentPage, '/api/') !== false;
    $isLogout = strpos($currentPage, 'logout') !== false;
    $isLogin = $currentPage === '/flip/' || $currentPage === '/flip/index.php';

    // Ne logger que les pages principales, pas trop souvent (1 fois par page par session)
    if (!$isApi && !$isLogout && !$isLogin) {
        $pageKey = 'visited_' . md5($currentPage);
        if (!isset($_SESSION[$pageKey])) {
            $_SESSION[$pageKey] = true;
            try {
                // Nettoyer le chemin pour l'affichage
                $pageName = str_replace(['/flip/', '.php'], ['', ''], $currentPage);
                $pageName = trim($pageName, '/') ?: 'accueil';
                $stmt = $pdo->prepare("INSERT INTO user_activity (user_id, action, page, ip_address) VALUES (?, 'page_view', ?, ?)");
                $stmt->execute([$_SESSION['user_id'], $pageName, $_SERVER['REMOTE_ADDR'] ?? null]);
            } catch (Exception $e) {
                // Ignorer
            }
        }
    }
}

// Système de traduction
require_once __DIR__ . '/includes/lang.php';
