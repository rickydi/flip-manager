<?php
/**
 * Supprimer facture - Admin
 * Flip Manager
 */

require_once '../../config.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/admin/factures/liste.php');
}

if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    setFlashMessage('danger', 'Token de sécurité invalide.');
    redirect('/admin/factures/liste.php');
}

$factureId = (int)($_POST['facture_id'] ?? 0);
$redirect = $_POST['redirect'] ?? '/admin/factures/liste.php';

if (!$factureId) {
    setFlashMessage('danger', 'Facture non trouvée.');
    redirect($redirect);
}

// Récupérer la facture pour supprimer le fichier associé
$stmt = $pdo->prepare("SELECT fichier FROM factures WHERE id = ?");
$stmt->execute([$factureId]);
$facture = $stmt->fetch();

if (!$facture) {
    setFlashMessage('danger', 'Facture non trouvée.');
    redirect($redirect);
}

// Supprimer le fichier si existant
if ($facture['fichier']) {
    deleteUploadedFile($facture['fichier']);
}

// Supprimer la facture
$stmt = $pdo->prepare("DELETE FROM factures WHERE id = ?");
if ($stmt->execute([$factureId])) {
    setFlashMessage('success', 'Facture supprimée.');
} else {
    setFlashMessage('danger', 'Erreur lors de la suppression.');
}

redirect($redirect);
