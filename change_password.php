<?php
/**
 * Script temporaire pour changer le mot de passe admin
 * SUPPRIMER APRÈS UTILISATION
 */

require_once 'config.php';

// Nouveau mot de passe sécurisé
$newPassword = 'EvoReno2024$flip';
$hash = password_hash($newPassword, PASSWORD_DEFAULT);

try {
    $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE email = 'erik.deschenes@gmail.com'");
    $stmt->execute([$hash]);
    
    if ($stmt->rowCount() > 0) {
        echo "<h2>Mot de passe mis à jour avec succès!</h2>";
        echo "<p><strong>Email:</strong> erik.deschenes@gmail.com</p>";
        echo "<p><strong>Nouveau mot de passe:</strong> $newPassword</p>";
        echo "<p style='color:red'><strong>IMPORTANT: Supprimez ce fichier immédiatement!</strong></p>";
        echo "<p><a href='/'>Aller à la connexion</a></p>";
    } else {
        echo "Aucun utilisateur trouvé avec cet email.";
    }
} catch (Exception $e) {
    echo "Erreur: " . $e->getMessage();
}
?>
