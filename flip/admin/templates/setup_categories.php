<?php
require_once '../../config.php';
require_once '../../includes/auth.php';

requireAdmin();

echo "<h1>Configuration de la Structure Québec</h1>";

try {
    $pdo->beginTransaction();

    // 1. Vider la table des groupes actuels (Optionnel, mais plus propre pour repartir à neuf)
    // Attention: cela mettrait les catégories existantes dans 'autre' si on ne fait pas attention.
    // Pour l'instant, on va plutot faire un UPSERT ou insérer les manquants.
    
    // Liste des groupes désirés
    $newGroups = [
        ['code' => 'structure', 'nom' => 'Structure', 'ordre' => 1],
        ['code' => 'ventilation', 'nom' => 'Ventilation', 'ordre' => 2],
        ['code' => 'plomberie', 'nom' => 'Plomberie', 'ordre' => 3],
        ['code' => 'electricite', 'nom' => 'Électricité', 'ordre' => 4],
        ['code' => 'fenetres', 'nom' => 'Fenêtres', 'ordre' => 5],
        ['code' => 'exterieur', 'nom' => 'Finition extérieur', 'ordre' => 6],
        ['code' => 'finition', 'nom' => 'Finition intérieure', 'ordre' => 7],
        ['code' => 'ebenisterie', 'nom' => 'Ébénisterie', 'ordre' => 8],
        ['code' => 'sdb', 'nom' => 'Salle de bain', 'ordre' => 9],
        ['code' => 'autre', 'nom' => 'Autre', 'ordre' => 10]
    ];

    $stmtCheck = $pdo->prepare("SELECT id FROM category_groups WHERE code = ?");
    $stmtInsert = $pdo->prepare("INSERT INTO category_groups (code, nom, ordre) VALUES (?, ?, ?)");
    $stmtUpdate = $pdo->prepare("UPDATE category_groups SET nom = ?, ordre = ? WHERE code = ?");

    foreach ($newGroups as $g) {
        $stmtCheck->execute([$g['code']]);
        if ($stmtCheck->fetch()) {
            // Mise à jour
            $stmtUpdate->execute([$g['nom'], $g['ordre'], $g['code']]);
            echo "Mis à jour: {$g['nom']}<br>";
        } else {
            // Insertion
            $stmtInsert->execute([$g['code'], $g['nom'], $g['ordre']]);
            echo "Ajouté: {$g['nom']}<br>";
        }
    }

    $pdo->commit();
    echo "<h3 style='color:green'>Succès! Vos catégories sont configurées.</h3>";
    echo "<a href='liste.php'>Retourner à la liste</a>";

} catch (Exception $e) {
    $pdo->rollBack();
    echo "<h3 style='color:red'>Erreur: " . $e->getMessage() . "</h3>";
}
