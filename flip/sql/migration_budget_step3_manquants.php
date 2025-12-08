<?php
/**
 * Script pour ajouter Salle de bain et Cuisine
 * URL: /flip/sql/migration_budget_step3_manquants.php
 */

require_once '../config.php';

echo "<h2>Ajout des catégories manquantes...</h2>";

// Afficher toutes les catégories pour trouver les bonnes
echo "<h3>Catégories existantes dans votre base:</h3>";
echo "<table border='1' cellpadding='5'>";
echo "<tr><th>ID</th><th>Nom</th><th>Groupe</th></tr>";
$stmt = $pdo->query("SELECT id, nom, groupe FROM categories ORDER BY groupe, ordre");
while ($row = $stmt->fetch()) {
    echo "<tr><td>{$row['id']}</td><td>{$row['nom']}</td><td>{$row['groupe']}</td></tr>";
}
echo "</table>";

// Formulaire pour sélectionner les IDs
echo "<hr>";
echo "<h3>Entrez les IDs des catégories manquantes:</h3>";
echo "<form method='POST'>";
echo "<p>ID pour <strong>Salle de bain</strong>: <input type='number' name='sdb_id' required></p>";
echo "<p>ID pour <strong>Cuisine</strong>: <input type='number' name='cuisine_id' required></p>";
echo "<button type='submit' style='padding:10px 20px;'>Ajouter les templates</button>";
echo "</form>";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sdbId = (int)$_POST['sdb_id'];
    $cuisineId = (int)$_POST['cuisine_id'];

    $data = [
        $sdbId => [
            'Bain/Douche' => [
                ['Bain acrylique 60"', 450],
                ['Bain acrylique 66"', 550],
                ['Bain autoportant', 800],
                ['Base de douche 32x32', 200],
                ['Base de douche 36x36', 250],
                ['Base de douche 48x36', 350],
                ['Ensemble douche préfab', 450],
                ['Porte de douche vitrée', 400],
                ['Rideau + tringle', 40],
                ['Crépine (drain)', 25],
                ['Robinetterie bain/douche', 180],
                ['Pomme de douche', 45],
                ['Céramique mur douche', 300],
            ],
            'Toilette' => [
                ['Toilette standard', 200],
                ['Toilette allongée', 280],
                ['Toilette à jupe', 350],
                ['Siège soft-close', 35],
                ['Valve d\'alimentation', 15],
                ['Flexible d\'alimentation', 10],
                ['Bride de sol (flange)', 20],
                ['Anneau de cire', 8],
            ],
            'Vanité' => [
                ['Vanité 24"', 300],
                ['Vanité 30"', 400],
                ['Vanité 36"', 450],
                ['Vanité 48"', 550],
                ['Vanité 60" double', 750],
                ['Comptoir vanité', 150],
                ['Lavabo encastré', 80],
                ['Lavabo vasque', 120],
                ['Robinet lavabo', 120],
                ['Drain lavabo + siphon', 25],
                ['Miroir', 80],
                ['Pharmacie avec miroir', 150],
            ],
            'Accessoires SDB' => [
                ['Porte-serviettes', 30],
                ['Anneau à serviette', 20],
                ['Porte-papier', 20],
                ['Crochet', 10],
                ['Ventilateur sdb', 80],
                ['Lumière vanité', 75],
            ],
            'Plancher SDB' => [
                ['Céramique plancher', 200],
                ['Vinyle plancher', 100],
                ['Plancher chauffant', 250],
                ['Membrane Ditra', 80],
            ],
        ],
        $cuisineId => [
            'Armoires' => [
                ['Armoires cuisine complète (budget)', 3500],
                ['Armoires cuisine complète (moyen)', 6000],
                ['Armoires cuisine complète (haut)', 10000],
                ['Refacing armoires', 2500],
                ['Peinture armoires', 800],
                ['Poignées/boutons (ensemble)', 150],
                ['Pentures soft-close', 100],
            ],
            'Comptoir' => [
                ['Comptoir stratifié', 600],
                ['Comptoir quartz', 2500],
                ['Comptoir granit', 2000],
                ['Comptoir butcher block', 800],
                ['Dosseret céramique', 400],
                ['Dosseret mosaïque', 500],
            ],
            'Évier' => [
                ['Évier inox simple', 150],
                ['Évier inox double', 250],
                ['Évier granit composite', 350],
                ['Robinet cuisine standard', 150],
                ['Robinet cuisine col de cygne', 250],
                ['Robinet avec douchette', 200],
                ['Broyeur', 180],
                ['Distributeur savon', 25],
            ],
            'Électroménagers' => [
                ['Réfrigérateur', 1200],
                ['Cuisinière électrique', 800],
                ['Cuisinière gaz', 1000],
                ['Hotte de cuisine', 250],
                ['Hotte intégrée micro-ondes', 400],
                ['Lave-vaisselle', 600],
                ['Micro-ondes comptoir', 150],
            ],
            'Plancher Cuisine' => [
                ['Céramique', 500],
                ['Vinyle', 300],
                ['Plancher flottant', 400],
            ],
        ],
    ];

    $totalSc = 0;
    $totalMat = 0;

    foreach ($data as $catId => $sousCategories) {
        if (!$catId) continue;

        echo "<h4>Catégorie ID: $catId</h4>";

        $scOrdre = 1;
        foreach ($sousCategories as $scNom => $materiaux) {
            $stmt = $pdo->prepare("INSERT INTO sous_categories (categorie_id, nom, ordre) VALUES (?, ?, ?)");
            $stmt->execute([$catId, $scNom, $scOrdre++]);
            $scId = $pdo->lastInsertId();
            $totalSc++;

            echo "<p>📂 $scNom</p>";

            $matOrdre = 1;
            foreach ($materiaux as $mat) {
                $stmt = $pdo->prepare("INSERT INTO materiaux (sous_categorie_id, nom, prix_defaut, ordre) VALUES (?, ?, ?, ?)");
                $stmt->execute([$scId, $mat[0], $mat[1], $matOrdre++]);
                $totalMat++;
            }
        }
    }

    echo "<hr>";
    echo "<h2 style='color:green'>✅ Terminé!</h2>";
    echo "<p><strong>$totalSc</strong> sous-catégories et <strong>$totalMat</strong> matériaux ajoutés.</p>";
    echo "<p><a href='/flip/admin/templates/liste.php'>Voir les templates</a></p>";
}
?>
