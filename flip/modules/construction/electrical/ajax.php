<?php
/**
 * Module Construction - Plan Électrique AJAX
 * Gestion des requêtes AJAX pour le module électrique
 */

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/functions.php';

// Désactiver output buffering pour JSON propre
while (ob_get_level()) ob_end_clean();
header('Content-Type: application/json');

// Récupérer les données JSON
$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';

// Templates de pièces (partagé avec component.php)
$roomTemplates = [
    'chambre' => [
        'components' => [
            ['nom' => 'Prise murale', 'quantite' => 2],
            ['nom' => 'Lumière au plafond plafonnier', 'quantite' => 1],
            ['nom' => 'Interrupteur plafonnier', 'quantite' => 1],
            ['nom' => 'Thermostat', 'wattage' => '2000w'],
            ['nom' => 'Plinthe chauffante', 'wattage' => '1500w'],
        ]
    ],
    'chambre_garde_robe' => [
        'components' => [
            ['nom' => 'Prise murale', 'quantite' => 2],
            ['nom' => 'Lumière garde-robe', 'quantite' => 1],
            ['nom' => 'Interrupteur garde-robe', 'quantite' => 1],
            ['nom' => 'Lumière au plafond plafonnier', 'quantite' => 1],
            ['nom' => 'Interrupteur plafonnier', 'quantite' => 1],
            ['nom' => 'Thermostat', 'wattage' => '2000w'],
            ['nom' => 'Plinthe chauffante', 'wattage' => '1500w'],
        ]
    ],
    'sdb' => [
        'components' => [
            ['nom' => 'Plancher chauffant 240v', 'quantite' => 1],
            ['nom' => 'Thermostat plancher', 'quantite' => 1],
            ['nom' => 'Interrupteur puck light', 'quantite' => 1],
            ['nom' => 'Interrupteur lumière intime et miroir', 'quantite' => 1],
            ['nom' => 'Prise GFI toilette', 'quantite' => 1],
            ['nom' => 'Prise vanité', 'quantite' => 1],
            ['nom' => 'Prise ventilateur', 'quantite' => 1],
        ]
    ],
    'sdb_plinthe' => [
        'components' => [
            ['nom' => 'Plinthe chauffante', 'wattage' => '750w'],
            ['nom' => 'Thermostat', 'wattage' => '2000w'],
            ['nom' => 'Interrupteur puck light', 'quantite' => 1],
            ['nom' => 'Interrupteur lumière intime et miroir', 'quantite' => 1],
            ['nom' => 'Prise GFI toilette', 'quantite' => 1],
            ['nom' => 'Prise vanité', 'quantite' => 1],
            ['nom' => 'Prise ventilateur', 'quantite' => 1],
        ]
    ],
    'cuisine' => [
        'components' => [
            ['nom' => 'Interrupteur LED carré', 'quantite' => 1],
            ['nom' => 'Interrupteur lumière îlot', 'quantite' => 1],
            ['nom' => 'Interrupteur lumière table', 'quantite' => 1],
            ['nom' => 'Interrupteur LED sous cabinet', 'quantite' => 1],
            ['nom' => 'Transfo LED sous armoire', 'quantite' => 1],
            ['nom' => 'Prise lave-vaisselle', 'quantite' => 1],
            ['nom' => 'Prise frigo', 'quantite' => 1],
            ['nom' => 'Prise four encastré', 'quantite' => 1],
            ['nom' => 'Prise plaque de cuisson', 'quantite' => 1],
            ['nom' => 'Thermostat', 'wattage' => '2000w'],
            ['nom' => 'Plinthe chauffante', 'wattage' => '2000w'],
            ['nom' => 'Lumière ext et interrupteur', 'quantite' => 1],
            ['nom' => 'Prise ext GFI', 'quantite' => 1],
        ]
    ],
    'salon' => [
        'components' => [
            ['nom' => 'Prise murale', 'quantite' => 3],
            ['nom' => 'Lumière entrée', 'quantite' => 1],
            ['nom' => 'Interrupteur puck light entrée', 'quantite' => 1],
            ['nom' => 'Interrupteur puck light salon', 'quantite' => 4],
            ['nom' => 'Thermostat', 'wattage' => '2000w'],
            ['nom' => 'Plinthe chauffante', 'wattage' => '2000w'],
            ['nom' => 'Interrupteur lumière ext', 'quantite' => 1],
        ]
    ],
    'couloir' => [
        'components' => [
            ['nom' => 'Interrupteur puck light', 'quantite' => 1],
            ['nom' => 'Puck light', 'quantite' => 2],
        ]
    ],
    'escalier' => [
        'components' => [
            ['nom' => 'Lumière puck light', 'quantite' => 2],
            ['nom' => 'Interrupteur puck light', 'quantite' => 1],
            ['nom' => 'Prise transfo LED détecteur mouvement', 'quantite' => 1],
        ]
    ],
    'buanderie' => [
        'components' => [
            ['nom' => 'Prise sécheuse', 'quantite' => 1],
            ['nom' => 'Prise laveuse', 'quantite' => 1],
            ['nom' => 'Interrupteur puck light', 'quantite' => 1],
            ['nom' => 'Puck light', 'quantite' => 2],
            ['nom' => 'Réservoir eau chaude', 'quantite' => 1],
            ['nom' => 'Prise aspirateur central', 'quantite' => 1],
            ['nom' => 'Thermostat', 'wattage' => '500w'],
            ['nom' => 'Plinthe chauffante', 'wattage' => '500w'],
        ]
    ],
    'bureau' => [
        'components' => [
            ['nom' => 'Prise murale', 'quantite' => 3],
            ['nom' => 'Lumière au plafond plafonnier', 'quantite' => 2],
            ['nom' => 'Interrupteur plafonnier', 'quantite' => 1],
            ['nom' => 'Thermostat', 'wattage' => '2000w'],
            ['nom' => 'Plinthe chauffante', 'wattage' => '1500w'],
        ]
    ],
    'sejour' => [
        'components' => [
            ['nom' => 'Prise murale', 'quantite' => 3],
            ['nom' => 'Puck light', 'quantite' => 6],
            ['nom' => 'Interrupteur puck light', 'quantite' => 1],
            ['nom' => 'Thermostat', 'wattage' => '2000w'],
            ['nom' => 'Plinthe chauffante', 'wattage' => '2000w'],
        ]
    ],
    'custom' => [
        'components' => []
    ]
];

try {
    switch ($action) {
        case 'add_floor':
            $projetId = (int)($input['projet_id'] ?? 0);
            $nom = trim($input['nom'] ?? '');

            if (!$projetId || !$nom) {
                echo json_encode(['success' => false, 'error' => 'Données manquantes']);
                exit;
            }

            // Récupérer ou créer le plan
            $stmt = $pdo->prepare("SELECT id FROM electrical_plans WHERE projet_id = ?");
            $stmt->execute([$projetId]);
            $plan = $stmt->fetch();

            if (!$plan) {
                $stmt = $pdo->prepare("INSERT INTO electrical_plans (projet_id) VALUES (?)");
                $stmt->execute([$projetId]);
                $planId = $pdo->lastInsertId();
            } else {
                $planId = $plan['id'];
            }

            // Ajouter l'étage
            $stmt = $pdo->prepare("SELECT COALESCE(MAX(ordre), 0) + 1 as next_ordre FROM electrical_floors WHERE plan_id = ?");
            $stmt->execute([$planId]);
            $ordre = $stmt->fetch()['next_ordre'];

            $stmt = $pdo->prepare("INSERT INTO electrical_floors (plan_id, nom, ordre) VALUES (?, ?, ?)");
            $stmt->execute([$planId, $nom, $ordre]);

            echo json_encode(['success' => true, 'floor_id' => $pdo->lastInsertId()]);
            break;

        case 'add_room':
            $floorId = (int)($input['floor_id'] ?? 0);
            $nom = trim($input['nom'] ?? '');
            $type = $input['type'] ?? 'custom';

            if (!$floorId) {
                echo json_encode(['success' => false, 'error' => 'Données manquantes']);
                exit;
            }

            // Labels pour les types de pièces
            $typeLabels = [
                'chambre' => 'Chambre',
                'chambre_garde_robe' => 'Chambre',
                'sdb' => 'Salle de bain',
                'sdb_plinthe' => 'Salle de bain',
                'cuisine' => 'Cuisine',
                'salon' => 'Salon',
                'couloir' => 'Couloir',
                'escalier' => 'Escalier',
                'buanderie' => 'Buanderie',
                'bureau' => 'Bureau',
                'sejour' => 'Salle de séjour',
                'custom' => 'Pièce'
            ];

            // Auto-générer le nom si vide
            if (empty($nom)) {
                $label = $typeLabels[$type] ?? 'Pièce';
                // Compter les pièces existantes avec ce type dans tout le plan
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) as cnt FROM electrical_rooms r
                    INNER JOIN electrical_floors f ON r.floor_id = f.id
                    WHERE f.plan_id = (SELECT plan_id FROM electrical_floors WHERE id = ?)
                    AND r.type LIKE ?
                ");
                $typePattern = ($type === 'chambre' || $type === 'chambre_garde_robe') ? 'chambre%' :
                              (($type === 'sdb' || $type === 'sdb_plinthe') ? 'sdb%' : $type);
                $stmt->execute([$floorId, $typePattern]);
                $count = (int)$stmt->fetch()['cnt'] + 1;
                $nom = $label . ' ' . $count;
            }

            // Ajouter la pièce
            $stmt = $pdo->prepare("SELECT COALESCE(MAX(ordre), 0) + 1 as next_ordre FROM electrical_rooms WHERE floor_id = ?");
            $stmt->execute([$floorId]);
            $ordre = $stmt->fetch()['next_ordre'];

            $stmt = $pdo->prepare("INSERT INTO electrical_rooms (floor_id, nom, type, ordre) VALUES (?, ?, ?, ?)");
            $stmt->execute([$floorId, $nom, $type, $ordre]);
            $roomId = $pdo->lastInsertId();

            // Ajouter les composants du template si défini
            if (isset($roomTemplates[$type]['components'])) {
                $compOrdre = 0;
                foreach ($roomTemplates[$type]['components'] as $comp) {
                    $stmt = $pdo->prepare("INSERT INTO electrical_components (room_id, nom, quantite, wattage, ordre) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([
                        $roomId,
                        $comp['nom'],
                        $comp['quantite'] ?? 1,
                        $comp['wattage'] ?? null,
                        $compOrdre++
                    ]);
                }
            }

            echo json_encode(['success' => true, 'room_id' => $roomId]);
            break;

        case 'add_component':
            $roomId = (int)($input['room_id'] ?? 0);
            $nom = trim($input['nom'] ?? '');
            $quantite = (int)($input['quantite'] ?? 1);
            $wattage = trim($input['wattage'] ?? '');

            if (!$roomId || !$nom) {
                echo json_encode(['success' => false, 'error' => 'Données manquantes']);
                exit;
            }

            $stmt = $pdo->prepare("SELECT COALESCE(MAX(ordre), 0) + 1 as next_ordre FROM electrical_components WHERE room_id = ?");
            $stmt->execute([$roomId]);
            $ordre = $stmt->fetch()['next_ordre'];

            $stmt = $pdo->prepare("INSERT INTO electrical_components (room_id, nom, quantite, wattage, ordre) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$roomId, $nom, $quantite, $wattage ?: null, $ordre]);

            echo json_encode(['success' => true, 'component_id' => $pdo->lastInsertId()]);
            break;

        case 'delete_floor':
            $floorId = (int)($input['floor_id'] ?? 0);

            if (!$floorId) {
                echo json_encode(['success' => false, 'error' => 'ID manquant']);
                exit;
            }

            // Supprimer les composants des pièces de cet étage
            $pdo->prepare("
                DELETE c FROM electrical_components c
                INNER JOIN electrical_rooms r ON c.room_id = r.id
                WHERE r.floor_id = ?
            ")->execute([$floorId]);

            // Supprimer les pièces
            $pdo->prepare("DELETE FROM electrical_rooms WHERE floor_id = ?")->execute([$floorId]);

            // Supprimer l'étage
            $pdo->prepare("DELETE FROM electrical_floors WHERE id = ?")->execute([$floorId]);

            echo json_encode(['success' => true]);
            break;

        case 'delete_room':
            $roomId = (int)($input['room_id'] ?? 0);

            if (!$roomId) {
                echo json_encode(['success' => false, 'error' => 'ID manquant']);
                exit;
            }

            // Supprimer les composants
            $pdo->prepare("DELETE FROM electrical_components WHERE room_id = ?")->execute([$roomId]);

            // Supprimer la pièce
            $pdo->prepare("DELETE FROM electrical_rooms WHERE id = ?")->execute([$roomId]);

            echo json_encode(['success' => true]);
            break;

        case 'delete_component':
            $componentId = (int)($input['component_id'] ?? 0);

            if (!$componentId) {
                echo json_encode(['success' => false, 'error' => 'ID manquant']);
                exit;
            }

            $pdo->prepare("DELETE FROM electrical_components WHERE id = ?")->execute([$componentId]);

            echo json_encode(['success' => true]);
            break;

        case 'update_component':
            $componentId = (int)($input['component_id'] ?? 0);
            $field = $input['field'] ?? '';
            $value = $input['value'] ?? '';

            if (!$componentId || !$field) {
                echo json_encode(['success' => false, 'error' => 'Données manquantes']);
                exit;
            }

            $allowedFields = ['nom', 'quantite', 'wattage', 'notes'];
            if (!in_array($field, $allowedFields)) {
                echo json_encode(['success' => false, 'error' => 'Champ non autorisé']);
                exit;
            }

            $stmt = $pdo->prepare("UPDATE electrical_components SET $field = ? WHERE id = ?");
            $stmt->execute([$value ?: null, $componentId]);

            echo json_encode(['success' => true]);
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Action inconnue']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
