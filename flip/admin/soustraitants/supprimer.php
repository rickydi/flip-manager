<?php
/**
 * Supprimer un sous-traitant - Admin
 * Flip Manager
 */

require_once '../../config.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/admin/index.php');
}

if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    setFlashMessage('error', 'Token de sécurité invalide.');
    redirect('/admin/index.php');
}

$soustraitantId = (int)($_POST['soustraitant_id'] ?? 0);
$redirect = $_POST['redirect'] ?? '/admin/index.php';

if (!$soustraitantId) {
    setFlashMessage('error', 'ID du sous-traitant manquant.');
    redirect($redirect);
}

try {
    // Récupérer le sous-traitant pour supprimer le fichier associé
    $stmt = $pdo->prepare("SELECT fichier FROM sous_traitants WHERE id = ?");
    $stmt->execute([$soustraitantId]);
    $soustraitant = $stmt->fetch();

    if ($soustraitant) {
        // Supprimer le fichier associé s'il existe
        if ($soustraitant['fichier']) {
            $filePath = __DIR__ . '/../../uploads/soustraitants/' . $soustraitant['fichier'];
            if (file_exists($filePath)) {
                unlink($filePath);
            }
        }

        // Supprimer l'enregistrement
        $stmt = $pdo->prepare("DELETE FROM sous_traitants WHERE id = ?");
        $stmt->execute([$soustraitantId]);

        setFlashMessage('success', 'Sous-traitant supprimé avec succès.');
    } else {
        setFlashMessage('error', 'Sous-traitant non trouvé.');
    }
} catch (Exception $e) {
    setFlashMessage('error', 'Erreur lors de la suppression : ' . $e->getMessage());
}

redirect($redirect);
