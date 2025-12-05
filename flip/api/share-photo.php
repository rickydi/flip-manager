<?php
/**
 * API: Créer un lien de partage sécurisé pour une photo
 * Flip Manager
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');

// Vérifier l'authentification
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Non autorisé']);
    exit;
}

// Vérifier la méthode
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Méthode non autorisée']);
    exit;
}

// Récupérer les données
$input = json_decode(file_get_contents('php://input'), true);
$filename = $input['filename'] ?? '';

if (empty($filename)) {
    http_response_code(400);
    echo json_encode(['error' => 'Nom de fichier requis']);
    exit;
}

// Nettoyer le nom de fichier
$filename = basename($filename);

// Vérifier que le fichier existe
$filePath = __DIR__ . '/../uploads/photos/' . $filename;
if (!file_exists($filePath)) {
    http_response_code(404);
    echo json_encode(['error' => 'Fichier non trouvé']);
    exit;
}

// Générer un token unique
$token = bin2hex(random_bytes(32));

// Durée de validité (7 jours par défaut)
$expireAt = date('Y-m-d H:i:s', strtotime('+7 days'));

try {
    // Vérifier/créer la table si elle n'existe pas
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS photos_shares (
            id INT AUTO_INCREMENT PRIMARY KEY,
            fichier VARCHAR(255) NOT NULL,
            token VARCHAR(64) NOT NULL UNIQUE,
            created_by INT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            expire_at DATETIME NULL,
            INDEX idx_fichier_token (fichier, token),
            INDEX idx_token (token)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Insérer le token
    $stmt = $pdo->prepare("
        INSERT INTO photos_shares (fichier, token, created_by, expire_at)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$filename, $token, getCurrentUserId(), $expireAt]);

    // Construire l'URL de partage
    $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
               . '://' . $_SERVER['HTTP_HOST'];
    $shareUrl = $baseUrl . '/serve-photo.php?file=' . urlencode($filename) . '&token=' . $token;

    echo json_encode([
        'success' => true,
        'url' => $shareUrl,
        'expires' => $expireAt
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erreur serveur']);
}
