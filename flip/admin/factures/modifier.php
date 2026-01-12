<?php
/**
 * Modifier facture - Redirige vers nouvelle.php en mode édition
 * Flip Manager
 */

require_once '../../config.php';
require_once '../../includes/auth.php';

requireAdmin();

$factureId = (int)($_GET['id'] ?? 0);

if (!$factureId) {
    setFlashMessage('danger', 'Facture non trouvée.');
    redirect('/admin/factures/liste.php');
}

// Rediriger vers nouvelle.php avec l'ID pour édition
redirect('/admin/factures/nouvelle.php?id=' . $factureId);
