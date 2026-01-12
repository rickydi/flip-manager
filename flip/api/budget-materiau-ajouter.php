<?php
/**
 * API: Ajouter un matériau au catalogue budget
 * Appelé depuis nouvelle.php quand on clique "+ Budget"
 */

require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

// Vérifier authentification admin
if (!isAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Accès non autorisé']);
    exit;
}

// Vérifier méthode POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Méthode non autorisée']);
    exit;
}

// Récupérer les données
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || empty($input['nom'])) {
    echo json_encode(['success' => false, 'error' => 'Nom du matériau requis']);
    exit;
}

$nom = trim($input['nom']);
$prix = floatval($input['prix'] ?? 0);
$fournisseur = trim($input['fournisseur'] ?? '');
$etapeId = !empty($input['etape_id']) ? intval($input['etape_id']) : null;
$sku = trim($input['sku'] ?? '');
$lien = trim($input['lien'] ?? '');
$imageBase64 = $input['image_base64'] ?? '';

try {
    // Vérifier si la table budget_materiaux existe, sinon la créer
    try {
        $pdo->query("SELECT 1 FROM budget_materiaux LIMIT 1");
    } catch (Exception $e) {
        $pdo->exec("
            CREATE TABLE budget_materiaux (
                id INT AUTO_INCREMENT PRIMARY KEY,
                nom VARCHAR(255) NOT NULL,
                description TEXT,
                prix DECIMAL(10,2) DEFAULT 0,
                unite VARCHAR(50) DEFAULT 'unité',
                categorie_id INT DEFAULT NULL,
                sous_categorie_id INT DEFAULT NULL,
                etape_id INT DEFAULT NULL,
                fournisseur VARCHAR(255),
                sku VARCHAR(100),
                lien VARCHAR(500),
                image VARCHAR(255),
                actif TINYINT(1) DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_etape (etape_id),
                INDEX idx_categorie (categorie_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    // Sauvegarder l'image si fournie
    $imagePath = null;
    if (!empty($imageBase64)) {
        // Extraire le type et les données
        if (preg_match('/^data:image\/(\w+);base64,(.+)$/', $imageBase64, $matches)) {
            $extension = $matches[1] === 'jpeg' ? 'jpg' : $matches[1];
            $imageData = base64_decode($matches[2]);

            if ($imageData !== false) {
                // Générer un nom unique
                $imagePath = 'materiau_' . time() . '_' . uniqid() . '.' . $extension;
                $uploadDir = __DIR__ . '/../uploads/materiaux/';

                // Créer le dossier s'il n'existe pas
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }

                // Sauvegarder l'image
                file_put_contents($uploadDir . $imagePath, $imageData);
            }
        }
    }

    // Insérer le matériau
    $stmt = $pdo->prepare("
        INSERT INTO budget_materiaux (nom, prix, fournisseur, etape_id, sku, lien, image)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([$nom, $prix, $fournisseur, $etapeId, $sku, $lien, $imagePath]);

    $newId = $pdo->lastInsertId();

    echo json_encode([
        'success' => true,
        'id' => $newId,
        'message' => 'Matériau ajouté au catalogue'
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
