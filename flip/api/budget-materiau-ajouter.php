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
$imageUrl = $input['image_url'] ?? '';

try {
    // Vérifier si la table catalogue_items existe
    try {
        $pdo->query("SELECT 1 FROM catalogue_items LIMIT 1");
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Table catalogue_items non trouvée. Utilisez d\'abord le Budget Builder.']);
        exit;
    }

    // Télécharger l'image et convertir en base64 si URL fournie
    $imageBase64 = null;
    if (!empty($imageUrl)) {
        // Télécharger l'image
        $context = stream_context_create([
            'http' => [
                'timeout' => 10,
                'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false
            ]
        ]);

        $imageData = @file_get_contents($imageUrl, false, $context);

        if ($imageData !== false) {
            // Déterminer le type MIME depuis l'URL
            $mimeType = 'image/jpeg';
            if (preg_match('/\.(png)/i', $imageUrl)) {
                $mimeType = 'image/png';
            } elseif (preg_match('/\.(gif)/i', $imageUrl)) {
                $mimeType = 'image/gif';
            } elseif (preg_match('/\.(webp)/i', $imageUrl)) {
                $mimeType = 'image/webp';
            }

            // Convertir en base64 avec data URI
            $imageBase64 = 'data:' . $mimeType . ';base64,' . base64_encode($imageData);
        }
    }

    // Trouver le parent_id basé sur l'étape (ou null pour racine)
    $parentId = null;
    if ($etapeId) {
        // Chercher si un dossier existe déjà pour cette étape dans le catalogue
        $stmt = $pdo->prepare("SELECT id FROM catalogue_items WHERE etape_id = ? AND type = 'folder' LIMIT 1");
        $stmt->execute([$etapeId]);
        $folder = $stmt->fetch();
        if ($folder) {
            $parentId = $folder['id'];
        }
    }

    // Trouver le prochain ordre
    $stmt = $pdo->prepare("SELECT COALESCE(MAX(ordre), 0) + 1 FROM catalogue_items WHERE parent_id " . ($parentId ? "= ?" : "IS NULL"));
    if ($parentId) {
        $stmt->execute([$parentId]);
    } else {
        $stmt->execute();
    }
    $ordre = $stmt->fetchColumn();

    // Insérer le matériau dans catalogue_items
    $stmt = $pdo->prepare("
        INSERT INTO catalogue_items (parent_id, type, nom, prix, ordre, etape_id, fournisseur, lien_achat, image, actif)
        VALUES (?, 'item', ?, ?, ?, ?, ?, ?, ?, 1)
    ");

    $stmt->execute([$parentId, $nom, $prix, $ordre, $etapeId, $fournisseur, $lien, $imageBase64]);

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
