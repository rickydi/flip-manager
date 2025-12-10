<?php
/**
 * Script d'insertion des données templates budgets
 * Exécuter ce fichier une fois après avoir créé les tables
 * URL: /flip/sql/migration_budget_step2_data.php
 */

require_once '../config.php';

// Vérifier que les tables existent
try {
    $pdo->query("SELECT 1 FROM sous_categories LIMIT 1");
} catch (Exception $e) {
    die("ERREUR: Les tables n'existent pas. Exécutez d'abord migration_budget_step1_tables.sql");
}

// Vérifier si données déjà insérées
$count = $pdo->query("SELECT COUNT(*) FROM sous_categories")->fetchColumn();
if ($count > 0) {
    die("Les données sont déjà insérées ($count sous-catégories existantes).");
}

echo "<h2>Insertion des templates budgets...</h2>";

// Récupérer les catégories existantes
$categories = [];
$stmt = $pdo->query("SELECT id, nom FROM categories ORDER BY id");
while ($row = $stmt->fetch()) {
    $nomLower = mb_strtolower($row['nom']);
    $categories[$row['id']] = $nomLower;
}

// Mapper les noms de catégories aux IDs
function findCatId($categories, $keywords) {
    foreach ($categories as $id => $nom) {
        foreach ($keywords as $kw) {
            if (strpos($nom, $kw) !== false) {
                return $id;
            }
        }
    }
    return null;
}

$catIds = [
    'sdb' => findCatId($categories, ['salle de bain', 'sdb', 'bathroom']),
    'cuisine' => findCatId($categories, ['cuisine', 'kitchen']),
    'elec' => findCatId($categories, ['électri', 'electri', 'electric']),
    'plomb' => findCatId($categories, ['plomb', 'plumb']),
    'portes' => findCatId($categories, ['porte', 'fenêtre', 'fenetre', 'door', 'window']),
    'finition' => findCatId($categories, ['finition', 'intérieur', 'interior']),
    'exterieur' => findCatId($categories, ['extérieur', 'exterior', 'toiture', 'roof']),
    'structure' => findCatId($categories, ['structure', 'fondation', 'foundation']),
    'divers' => findCatId($categories, ['divers', 'autre', 'other', 'misc']),
];

echo "<pre>";
echo "Catégories trouvées:\n";
print_r($catIds);
echo "</pre>";

// Données à insérer
$data = [
    // SALLE DE BAIN
    'sdb' => [
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
        'Accessoires' => [
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
    // CUISINE
    'cuisine' => [
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
    // ÉLECTRICITÉ
    'elec' => [
        'Panneau' => [
            ['Changement panneau 100A', 1500],
            ['Changement panneau 200A', 2500],
            ['Mise à terre', 300],
            ['Disjoncteur standard', 15],
            ['Disjoncteur AFCI', 50],
            ['Disjoncteur GFCI', 45],
        ],
        'Filage' => [
            ['Ajout circuit 15A', 250],
            ['Ajout circuit 20A', 300],
            ['Circuit 240V (sécheuse)', 400],
            ['Circuit 240V (cuisinière)', 450],
            ['Fil 14/2 (rouleau 75m)', 80],
            ['Fil 12/2 (rouleau 75m)', 120],
        ],
        'Prises/Interrupteurs' => [
            ['Prise standard', 3],
            ['Prise GFCI', 25],
            ['Prise USB', 25],
            ['Interrupteur simple', 3],
            ['Interrupteur 3-way', 8],
            ['Dimmer', 25],
            ['Plaque (par unité)', 2],
        ],
        'Luminaires' => [
            ['Plafonnier standard', 40],
            ['Plafonnier LED', 60],
            ['Spot encastré (pot light)', 25],
            ['Luminaire suspendu', 100],
            ['Lustre', 200],
            ['Applique murale', 60],
            ['Lumière extérieure', 50],
        ],
        'Divers électrique' => [
            ['Détecteur de fumée', 30],
            ['Détecteur CO', 35],
            ['Détecteur combiné', 45],
            ['Sonnette', 80],
            ['Thermostat standard', 30],
            ['Thermostat intelligent', 200],
        ],
    ],
    // PLOMBERIE
    'plomb' => [
        'Chauffe-eau' => [
            ['Chauffe-eau 40 gal électrique', 600],
            ['Chauffe-eau 60 gal électrique', 800],
            ['Chauffe-eau 40 gal gaz', 900],
            ['Chauffe-eau tankless électrique', 1200],
            ['Chauffe-eau tankless gaz', 2000],
            ['Installation chauffe-eau', 400],
        ],
        'Tuyauterie' => [
            ['Remplacement entrée d\'eau', 800],
            ['Remplacement drain principal', 1500],
            ['Nouvelle ligne eau chaude/froide', 400],
            ['Nouveau drain', 300],
            ['Valve d\'arrêt principale', 150],
        ],
        'Buanderie' => [
            ['Robinet buanderie', 60],
            ['Bac à laver', 150],
            ['Boîte d\'alimentation laveuse', 50],
            ['Drain laveuse', 80],
        ],
    ],
    // PORTES ET FENÊTRES
    'portes' => [
        'Portes intérieures' => [
            ['Porte intérieure creuse', 60],
            ['Porte intérieure solide', 150],
            ['Porte pliante (garde-robe)', 80],
            ['Porte coulissante (grange)', 300],
            ['Poignée intérieure', 25],
            ['Charnières (3)', 15],
            ['Cadrage porte', 40],
        ],
        'Portes extérieures' => [
            ['Porte entrée acier', 400],
            ['Porte entrée fibre de verre', 600],
            ['Porte patio coulissante', 800],
            ['Porte patio française', 1200],
            ['Porte garage simple', 800],
            ['Porte garage double', 1400],
            ['Ouvre-porte garage', 350],
            ['Serrure entrée', 80],
            ['Serrure intelligente', 250],
        ],
        'Fenêtres' => [
            ['Fenêtre simple (petite)', 250],
            ['Fenêtre simple (moyenne)', 350],
            ['Fenêtre simple (grande)', 500],
            ['Fenêtre coulissante sous-sol', 200],
            ['Puits de lumière', 600],
        ],
    ],
    // FINITION INTÉRIEURE
    'finition' => [
        'Murs' => [
            ['Gypse 4x8 (feuille)', 15],
            ['Tirage de joints (par pièce)', 200],
            ['Peinture (par pièce)', 150],
            ['Peinture maison complète', 2500],
            ['Papier peint (par mur)', 200],
        ],
        'Planchers' => [
            ['Plancher flottant (par pi²)', 3],
            ['Plancher bois franc (par pi²)', 8],
            ['Sablage/vernis plancher', 1500],
            ['Céramique (par pi²)', 5],
            ['Vinyle (par pi²)', 2],
            ['Tapis (par pi²)', 3],
            ['Sous-plancher OSB (feuille)', 35],
        ],
        'Moulures' => [
            ['Plinthe (par pi lin)', 2],
            ['Quart-de-rond (par pi lin)', 1],
            ['Cadrage (par porte/fenêtre)', 40],
            ['Cimaise', 100],
            ['Couronne (par pièce)', 150],
        ],
        'Escalier' => [
            ['Marche escalier', 40],
            ['Contremarche', 20],
            ['Nez de marche', 15],
            ['Main courante (par pi lin)', 10],
            ['Balustre', 8],
            ['Poteau départ', 80],
            ['Recouvrement escalier complet', 800],
        ],
    ],
    // EXTÉRIEUR
    'exterieur' => [
        'Toiture' => [
            ['Toiture bardeau (par pi²)', 5],
            ['Toiture complète (bungalow)', 8000],
            ['Toiture complète (2 étages)', 12000],
            ['Réparation toiture', 500],
            ['Solin', 150],
            ['Évent de plomberie', 50],
            ['Évent maximum', 80],
        ],
        'Revêtement' => [
            ['Vinyle (par pi²)', 4],
            ['Canexel (par pi²)', 6],
            ['Aluminium (par pi²)', 5],
            ['Brique (par pi²)', 15],
            ['Revêtement complet', 15000],
        ],
        'Gouttières' => [
            ['Gouttière aluminium (par pi lin)', 8],
            ['Descente pluviale', 50],
            ['Gouttières complètes', 1200],
        ],
        'Balcon/Terrasse' => [
            ['Balcon bois traité', 2500],
            ['Balcon composite', 5000],
            ['Rampe aluminium (par pi lin)', 80],
            ['Rampe verre', 200],
            ['Escalier extérieur', 800],
            ['Patio pavé (par pi²)', 15],
        ],
        'Entrée/Stationnement' => [
            ['Asphalte stationnement', 3500],
            ['Pavé uni entrée', 6000],
            ['Réparation asphalte', 500],
            ['Scellant asphalte', 200],
        ],
        'Aménagement paysager' => [
            ['Gazon (pose)', 1500],
            ['Semence gazon', 200],
            ['Haie de cèdres', 800],
            ['Arbre', 300],
            ['Plate-bande', 400],
            ['Muret', 1000],
            ['Clôture bois (par pi lin)', 40],
            ['Clôture maille (par pi lin)', 20],
        ],
    ],
    // STRUCTURE
    'structure' => [
        'Fondation' => [
            ['Réparation fissure (injection)', 500],
            ['Réparation fissure (extérieur)', 2500],
            ['Drain français', 8000],
            ['Imperméabilisation', 5000],
            ['Pompe puisard', 400],
        ],
        'Charpente' => [
            ['Poutre LVL', 300],
            ['Colonne ajustable', 80],
            ['Renforcement solive', 500],
            ['Ouverture mur porteur', 1500],
        ],
        'Isolation' => [
            ['Isolation laine R20 (par pi²)', 1],
            ['Isolation laine R40 (par pi²)', 2],
            ['Isolation uréthane (par pi²)', 4],
            ['Isolation sous-sol complet', 3000],
            ['Isolation entretoit', 2000],
            ['Pare-vapeur', 200],
        ],
    ],
    // DIVERS
    'divers' => [
        'Permis' => [
            ['Permis rénovation', 300],
            ['Permis construction', 500],
            ['Permis plomberie', 150],
            ['Permis électricité', 150],
        ],
        'Location équipement' => [
            ['Conteneur déchets (petit)', 400],
            ['Conteneur déchets (gros)', 600],
            ['Location lift', 300],
            ['Location échafaud', 200],
        ],
        'Nettoyage' => [
            ['Nettoyage fin chantier', 500],
            ['Nettoyage conduits ventilation', 400],
        ],
    ],
];

// Insertion des données
$totalSc = 0;
$totalMat = 0;

foreach ($data as $catKey => $sousCategories) {
    $catId = $catIds[$catKey];

    if (!$catId) {
        echo "<p style='color:orange'>⚠️ Catégorie '$catKey' non trouvée, ignorée.</p>";
        continue;
    }

    echo "<p>📁 <strong>" . ucfirst($catKey) . "</strong> (ID: $catId)</p>";

    $scOrdre = 1;
    foreach ($sousCategories as $scNom => $materiaux) {
        // Insérer sous-catégorie
        $stmt = $pdo->prepare("INSERT INTO sous_categories (categorie_id, nom, ordre) VALUES (?, ?, ?)");
        $stmt->execute([$catId, $scNom, $scOrdre++]);
        $scId = $pdo->lastInsertId();
        $totalSc++;

        echo "<p style='margin-left:20px'>📂 $scNom (ID: $scId)</p>";

        // Insérer matériaux
        $matOrdre = 1;
        foreach ($materiaux as $mat) {
            $stmt = $pdo->prepare("INSERT INTO materiaux (sous_categorie_id, nom, prix_defaut, ordre) VALUES (?, ?, ?, ?)");
            $stmt->execute([$scId, $mat[0], $mat[1], $matOrdre++]);
            $totalMat++;
        }
        echo "<p style='margin-left:40px;color:gray'>" . count($materiaux) . " matériaux</p>";
    }
}

echo "<hr>";
echo "<h2 style='color:green'>✅ Terminé!</h2>";
echo "<p><strong>$totalSc</strong> sous-catégories et <strong>$totalMat</strong> matériaux insérés.</p>";
echo "<p><a href='/flip/admin/templates/liste.php'>Voir les templates</a></p>";
