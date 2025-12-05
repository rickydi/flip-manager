<?php
/**
 * Déconnexion
 * Flip Manager
 */

require_once 'config.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

// Logger la déconnexion avant de détruire la session
if (isset($_SESSION['user_id'])) {
    $duree = isset($_SESSION['login_time']) ? formatDureeSession(time() - $_SESSION['login_time']) : '';
    logActivity($pdo, $_SESSION['user_id'], 'logout', null, 'Déconnexion (durée: ' . $duree . ')');
}

// Déconnecter l'utilisateur (avec sauvegarde de la durée de session)
logoutUser($pdo);

// Rediriger vers la page de connexion
redirect('/index.php');
