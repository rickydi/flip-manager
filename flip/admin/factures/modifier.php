<?php
/**
 * Modifier facture - Redirige vers nouvelle.php en mode édition
 * Flip Manager
 */

$factureId = (int)($_GET['id'] ?? 0);

if (!$factureId) {
    header('Location: liste.php');
    exit;
}

header('Location: nouvelle.php?id=' . $factureId);
exit;
