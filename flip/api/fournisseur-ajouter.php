<?php
/**
 * API pour ajouter un fournisseur
 * Flip Manager
 */

require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

// Vérifier l'authentification
if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Non authentifié']);
    exit;
}

// Vérifier le token CSRF
if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'error' => 'Token invalide']);
    exit;
}

$nom = trim($_POST['nom'] ?? '');

if (empty($nom)) {
    echo json_encode(['success' => false, 'error' => 'Le nom est requis']);
    exit;
}

try {
    // Créer la table si elle n'existe pas
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS fournisseurs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nom VARCHAR(255) NOT NULL UNIQUE,
            actif TINYINT(1) DEFAULT 1,
            date_creation DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Vérifier si le fournisseur existe déjà
    $stmt = $pdo->prepare("SELECT id FROM fournisseurs WHERE nom = ?");
    $stmt->execute([$nom]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Ce fournisseur existe déjà']);
        exit;
    }

    // Ajouter le fournisseur
    $stmt = $pdo->prepare("INSERT INTO fournisseurs (nom) VALUES (?)");
    if ($stmt->execute([$nom])) {
        echo json_encode(['success' => true, 'id' => $pdo->lastInsertId(), 'nom' => $nom]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Erreur lors de l\'ajout']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Erreur: ' . $e->getMessage()]);
}
