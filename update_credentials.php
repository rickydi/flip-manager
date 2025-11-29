<?php
/**
 * Script temporaire pour mise à jour des identifiants admin
 * À supprimer après exécution
 */

require_once 'config.php';

$newEmail = 'erik.deschenes@gmail.com';
$newPassword = 'zun+668';
$hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);

try {
    $stmt = $pdo->prepare("
        UPDATE users 
        SET email = ?, password = ?
        WHERE role = 'admin'
        LIMIT 1
    ");
    
    $result = $stmt->execute([$newEmail, $hashedPassword]);
    
    if ($result && $stmt->rowCount() > 0) {
        echo "✅ Identifiants mis à jour avec succès!\n";
        echo "Email: $newEmail\n";
        echo "Mot de passe: $newPassword\n";
        
        // Supprimer ce fichier après exécution
        unlink(__FILE__);
        echo "\n🗑️ Script supprimé.\n";
    } else {
        echo "❌ Aucun utilisateur admin trouvé.\n";
    }
} catch (Exception $e) {
    echo "❌ Erreur: " . $e->getMessage() . "\n";
}
