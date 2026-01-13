<?php
/**
 * Nouvelle facture - Employé
 * Redirige vers la page admin (partagée)
 * Flip Manager
 */

require_once '../config.php';
require_once '../includes/auth.php';

requireLogin();

// Rediriger vers la page admin (qui accepte maintenant les employés)
$projetId = isset($_GET['projet_id']) ? (int)$_GET['projet_id'] : 0;
$redirectUrl = url('/admin/factures/nouvelle.php');

if ($projetId) {
    $redirectUrl .= '?projet_id=' . $projetId;
}

header('Location: ' . $redirectUrl);
exit;
