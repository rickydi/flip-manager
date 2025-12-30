<?php
/**
 * Module Construction - Plan Électrique 2D AJAX
 * Gestion des requêtes AJAX pour le dessin électrique
 */

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/functions.php';

// Désactiver output buffering pour JSON propre
while (ob_get_level()) ob_end_clean();
header('Content-Type: application/json');

// Récupérer les données JSON
$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';

try {
    switch ($action) {
        case 'save':
            $projetId = (int)($input['projet_id'] ?? 0);
            $drawingId = $input['drawing_id'] ?? null;
            $canvasData = $input['canvas_data'] ?? '';
            $circuits = $input['circuits'] ?? [];

            if (!$projetId) {
                echo json_encode(['success' => false, 'error' => 'Projet ID manquant']);
                exit;
            }

            // Créer ou mettre à jour le dessin
            if ($drawingId) {
                $stmt = $pdo->prepare("UPDATE electrical_drawings SET canvas_data = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$canvasData, $drawingId]);
            } else {
                // Vérifier si un dessin existe déjà pour ce projet
                $stmt = $pdo->prepare("SELECT id FROM electrical_drawings WHERE projet_id = ? LIMIT 1");
                $stmt->execute([$projetId]);
                $existing = $stmt->fetch();

                if ($existing) {
                    $drawingId = $existing['id'];
                    $stmt = $pdo->prepare("UPDATE electrical_drawings SET canvas_data = ?, updated_at = NOW() WHERE id = ?");
                    $stmt->execute([$canvasData, $drawingId]);
                } else {
                    $stmt = $pdo->prepare("INSERT INTO electrical_drawings (projet_id, canvas_data) VALUES (?, ?)");
                    $stmt->execute([$projetId, $canvasData]);
                    $drawingId = $pdo->lastInsertId();
                }
            }

            // Sauvegarder les circuits
            if (!empty($circuits) && $drawingId) {
                // Supprimer les anciens circuits
                $pdo->prepare("DELETE FROM electrical_circuits WHERE drawing_id = ?")->execute([$drawingId]);

                // Ajouter les nouveaux
                $stmt = $pdo->prepare("INSERT INTO electrical_circuits (drawing_id, nom, amperage, voltage, color, ordre) VALUES (?, ?, ?, ?, ?, ?)");
                foreach ($circuits as $i => $circuit) {
                    $stmt->execute([
                        $drawingId,
                        $circuit['nom'] ?? 'Circuit ' . ($i + 1),
                        (int)($circuit['amperage'] ?? 15),
                        (int)($circuit['voltage'] ?? 120),
                        $circuit['color'] ?? '#ff0000',
                        $i
                    ]);
                }
            }

            echo json_encode(['success' => true, 'drawing_id' => $drawingId]);
            break;

        case 'load':
            $projetId = (int)($input['projet_id'] ?? 0);

            if (!$projetId) {
                echo json_encode(['success' => false, 'error' => 'Projet ID manquant']);
                exit;
            }

            $stmt = $pdo->prepare("SELECT * FROM electrical_drawings WHERE projet_id = ? LIMIT 1");
            $stmt->execute([$projetId]);
            $drawing = $stmt->fetch();

            $circuits = [];
            if ($drawing) {
                $stmt = $pdo->prepare("SELECT * FROM electrical_circuits WHERE drawing_id = ? ORDER BY ordre");
                $stmt->execute([$drawing['id']]);
                $circuits = $stmt->fetchAll();
            }

            echo json_encode([
                'success' => true,
                'drawing' => $drawing,
                'circuits' => $circuits
            ]);
            break;

        case 'resize':
            $drawingId = (int)($input['drawing_id'] ?? 0);
            $width = (int)($input['width'] ?? 1200);
            $height = (int)($input['height'] ?? 800);

            if (!$drawingId) {
                echo json_encode(['success' => false, 'error' => 'Drawing ID manquant']);
                exit;
            }

            $stmt = $pdo->prepare("UPDATE electrical_drawings SET width = ?, height = ? WHERE id = ?");
            $stmt->execute([$width, $height, $drawingId]);

            echo json_encode(['success' => true]);
            break;

        case 'delete':
            $drawingId = (int)($input['drawing_id'] ?? 0);

            if (!$drawingId) {
                echo json_encode(['success' => false, 'error' => 'Drawing ID manquant']);
                exit;
            }

            // Supprimer les circuits
            $pdo->prepare("DELETE FROM electrical_circuits WHERE drawing_id = ?")->execute([$drawingId]);

            // Supprimer le dessin
            $pdo->prepare("DELETE FROM electrical_drawings WHERE id = ?")->execute([$drawingId]);

            echo json_encode(['success' => true]);
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Action inconnue']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
