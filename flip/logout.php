<?php
/**
 * Déconnexion
 * Flip Manager
 */

require_once 'config.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

// Déconnecter l'utilisateur (avec sauvegarde de la durée de session)
logoutUser($pdo);

// Rediriger vers la page de connexion
redirect('/index.php');
