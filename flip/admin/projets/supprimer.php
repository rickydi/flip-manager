<?php
/**
 * Supprimer projet - Admin
 * Flip Manager
 */

require_once '../../config.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/admin/projets/liste.php');
}

if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    setFlashMessage('error', 'Token de sécurité invalide.');
    redirect('/admin/projets/liste.php');
}

$projetId = (int)($_POST['projet_id'] ?? 0);

if (!$projetId) {
    setFlashMessage('error', 'Projet non trouvé.');
    redirect('/admin/projets/liste.php');
}

// Vérifier que le projet existe
$stmt = $pdo->prepare("SELECT id, nom FROM projets WHERE id = ?");
$stmt->execute([$projetId]);
$projet = $stmt->fetch();

if (!$projet) {
    setFlashMessage('error', 'Projet non trouvé.');
    redirect('/admin/projets/liste.php');
}

// Supprimer le projet (les factures et budgets seront supprimés automatiquement via CASCADE)
$stmt = $pdo->prepare("DELETE FROM projets WHERE id = ?");
$result = $stmt->execute([$projetId]);

if ($result) {
    setFlashMessage('success', 'Le projet "' . $projet['nom'] . '" a été supprimé avec succès.');
} else {
    setFlashMessage('error', 'Erreur lors de la suppression du projet.');
}

redirect('/admin/projets/liste.php');
