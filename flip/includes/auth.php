<?php
/**
 * Gestion de l'authentification
 * Flip Manager
 */

// Démarrer la session si pas déjà fait
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Vérifie si l'utilisateur est connecté
 * Redirige vers la page de connexion si non connecté
 */
function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: /index.php');
        exit;
    }
}

/**
 * Vérifie si l'utilisateur est connecté
 * @return bool
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * Vérifie si l'utilisateur est un admin
 * Redirige vers le dashboard employé si non admin
 */
function requireAdmin() {
    requireLogin();
    if (!isAdmin()) {
        header('Location: /employe/index.php');
        exit;
    }
}

/**
 * Vérifie si l'utilisateur connecté est un admin
 * @return bool
 */
function isAdmin() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

/**
 * Vérifie si l'utilisateur connecté est un employé
 * @return bool
 */
function isEmploye() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'employe';
}

/**
 * Récupère l'ID de l'utilisateur connecté
 * @return int|null
 */
function getCurrentUserId() {
    return $_SESSION['user_id'] ?? null;
}

/**
 * Récupère le nom complet de l'utilisateur connecté
 * @return string
 */
function getCurrentUserName() {
    $prenom = $_SESSION['user_prenom'] ?? '';
    $nom = $_SESSION['user_nom'] ?? '';
    return trim($prenom . ' ' . $nom);
}

/**
 * Récupère le rôle de l'utilisateur connecté
 * @return string|null
 */
function getCurrentUserRole() {
    return $_SESSION['role'] ?? null;
}

/**
 * Connecte un utilisateur
 * @param array $user Données utilisateur de la BD
 */
function loginUser($user) {
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_nom'] = $user['nom'];
    $_SESSION['user_prenom'] = $user['prenom'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['login_time'] = time();
}

/**
 * Déconnecte l'utilisateur
 */
function logoutUser() {
    $_SESSION = array();
    
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    
    session_destroy();
}

/**
 * Met à jour la dernière connexion de l'utilisateur
 * @param PDO $pdo
 * @param int $userId
 */
function updateLastLogin($pdo, $userId) {
    $stmt = $pdo->prepare("UPDATE users SET derniere_connexion = NOW() WHERE id = ?");
    $stmt->execute([$userId]);
}

/**
 * Vérifie les identifiants de connexion (username OU email)
 * @param PDO $pdo
 * @param string $identifier Username ou email
 * @param string $password
 * @return array|false Données utilisateur ou false
 */
function verifyCredentials($pdo, $identifier, $password) {
    // Chercher par username OU par email
    $stmt = $pdo->prepare("SELECT * FROM users WHERE (username = ? OR email = ?) AND actif = 1");
    $stmt->execute([$identifier, $identifier]);
    $user = $stmt->fetch();
    
    if ($user && password_verify($password, $user['password'])) {
        return $user;
    }
    
    return false;
}

/**
 * Génère un token CSRF
 * @return string
 */
function generateCSRFToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Vérifie le token CSRF
 * @param string $token
 * @return bool
 */
function verifyCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Affiche un champ caché avec le token CSRF
 */
function csrfField() {
    echo '<input type="hidden" name="csrf_token" value="' . generateCSRFToken() . '">';
}
