<?php
/**
 * API de pointage employés (Start/Pause/Arrêt)
 * Flip Manager
 */

require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

// Vérifier que l'utilisateur est connecté
requireLogin();

header('Content-Type: application/json');

// Auto-création des tables si nécessaire
try {
    $pdo->exec("
        ALTER TABLE projets
        ADD COLUMN IF NOT EXISTS latitude DECIMAL(10, 8) NULL,
        ADD COLUMN IF NOT EXISTS longitude DECIMAL(11, 8) NULL,
        ADD COLUMN IF NOT EXISTS rayon_gps INT DEFAULT 100
    ");
} catch (Exception $e) {
    // Ignorer si colonnes existent déjà
}

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS pointages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            projet_id INT NULL,
            type ENUM('start', 'pause', 'resume', 'stop') NOT NULL,
            date_pointage DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            latitude DECIMAL(10, 8) NULL,
            longitude DECIMAL(11, 8) NULL,
            precision_gps DECIMAL(10, 2) NULL,
            auto_gps TINYINT(1) DEFAULT 0,
            notes TEXT NULL,
            date_creation DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_date (user_id, date_pointage),
            INDEX idx_projet_date (projet_id, date_pointage)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sessions_travail (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            projet_id INT NULL,
            date_travail DATE NOT NULL,
            heure_debut DATETIME NOT NULL,
            heure_fin DATETIME NULL,
            duree_travail INT DEFAULT 0,
            duree_pause INT DEFAULT 0,
            statut ENUM('en_cours', 'pause', 'terminee') DEFAULT 'en_cours',
            date_creation DATETIME DEFAULT CURRENT_TIMESTAMP,
            date_modification DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_user_date (user_id, date_travail),
            INDEX idx_statut (statut)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_gps_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL UNIQUE,
            gps_enabled TINYINT(1) DEFAULT 0,
            auto_punch_enabled TINYINT(1) DEFAULT 0,
            derniere_latitude DECIMAL(10, 8) NULL,
            derniere_longitude DECIMAL(11, 8) NULL,
            derniere_mise_a_jour DATETIME NULL,
            date_creation DATETIME DEFAULT CURRENT_TIMESTAMP,
            date_modification DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Exception $e) {
    // Tables existent déjà
}

$userId = getCurrentUserId();
$method = $_SERVER['REQUEST_METHOD'];

// ============================================
// GET: Récupérer le statut actuel
// ============================================
if ($method === 'GET') {
    $action = $_GET['action'] ?? 'status';

    if ($action === 'status') {
        // Récupérer la session en cours
        $stmt = $pdo->prepare("
            SELECT s.*, p.nom as projet_nom, p.adresse as projet_adresse
            FROM sessions_travail s
            LEFT JOIN projets p ON s.projet_id = p.id
            WHERE s.user_id = ? AND s.statut IN ('en_cours', 'pause')
            ORDER BY s.date_creation DESC
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $session = $stmt->fetch();

        // Calculer le temps travaillé si en cours
        $tempsTravaille = 0;
        $tempsPause = 0;

        if ($session) {
            if ($session['statut'] === 'en_cours') {
                // Calculer le temps depuis le début ou la dernière reprise
                $stmt = $pdo->prepare("
                    SELECT type, date_pointage
                    FROM pointages
                    WHERE user_id = ? AND DATE(date_pointage) = ?
                    ORDER BY date_pointage ASC
                ");
                $stmt->execute([$userId, $session['date_travail']]);
                $pointages = $stmt->fetchAll();

                $lastStart = null;
                $totalWork = 0;
                $totalPause = 0;

                foreach ($pointages as $p) {
                    $time = strtotime($p['date_pointage']);
                    if ($p['type'] === 'start' || $p['type'] === 'resume') {
                        $lastStart = $time;
                    } elseif (($p['type'] === 'pause' || $p['type'] === 'stop') && $lastStart) {
                        $totalWork += ($time - $lastStart);
                        $lastStart = null;
                    }
                }

                // Si toujours en cours, ajouter le temps depuis le dernier start/resume
                if ($lastStart) {
                    $totalWork += (time() - $lastStart);
                }

                $tempsTravaille = floor($totalWork / 60); // En minutes
                $tempsPause = $session['duree_pause'];
            } else {
                $tempsTravaille = $session['duree_travail'];
                $tempsPause = $session['duree_pause'];
            }
        }

        // Récupérer les paramètres GPS
        $stmt = $pdo->prepare("SELECT * FROM user_gps_settings WHERE user_id = ?");
        $stmt->execute([$userId]);
        $gpsSettings = $stmt->fetch() ?: [
            'gps_enabled' => 0,
            'auto_punch_enabled' => 0
        ];

        echo json_encode([
            'success' => true,
            'session' => $session ? [
                'id' => $session['id'],
                'projet_id' => $session['projet_id'],
                'projet_nom' => $session['projet_nom'],
                'projet_adresse' => $session['projet_adresse'],
                'statut' => $session['statut'],
                'heure_debut' => $session['heure_debut'],
                'duree_travail' => $tempsTravaille,
                'duree_pause' => $tempsPause
            ] : null,
            'gps_settings' => [
                'gps_enabled' => (bool)$gpsSettings['gps_enabled'],
                'auto_punch_enabled' => (bool)$gpsSettings['auto_punch_enabled']
            ]
        ]);
        exit;
    }

    if ($action === 'projets_gps') {
        // Récupérer les projets avec coordonnées GPS
        $stmt = $pdo->query("
            SELECT id, nom, adresse, ville, latitude, longitude, rayon_gps
            FROM projets
            WHERE statut NOT IN ('vendu', 'archive')
            AND latitude IS NOT NULL
            AND longitude IS NOT NULL
        ");
        $projets = $stmt->fetchAll();

        echo json_encode([
            'success' => true,
            'projets' => $projets
        ]);
        exit;
    }

    // Historique du jour
    if ($action === 'historique') {
        $date = $_GET['date'] ?? date('Y-m-d');

        $stmt = $pdo->prepare("
            SELECT p.*, pr.nom as projet_nom
            FROM pointages p
            LEFT JOIN projets pr ON p.projet_id = pr.id
            WHERE p.user_id = ? AND DATE(p.date_pointage) = ?
            ORDER BY p.date_pointage ASC
        ");
        $stmt->execute([$userId, $date]);
        $pointages = $stmt->fetchAll();

        echo json_encode([
            'success' => true,
            'pointages' => $pointages
        ]);
        exit;
    }
}

// ============================================
// POST: Créer un pointage
// ============================================
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);

    $type = $input['type'] ?? null;
    $projetId = !empty($input['projet_id']) ? (int)$input['projet_id'] : null;
    $latitude = isset($input['latitude']) ? (float)$input['latitude'] : null;
    $longitude = isset($input['longitude']) ? (float)$input['longitude'] : null;
    $precision = isset($input['precision']) ? (float)$input['precision'] : null;
    $autoGps = !empty($input['auto_gps']) ? 1 : 0;
    $notes = $input['notes'] ?? null;

    if (!in_array($type, ['start', 'pause', 'resume', 'stop'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Type de pointage invalide']);
        exit;
    }

    // Vérifier la logique
    $stmt = $pdo->prepare("
        SELECT * FROM sessions_travail
        WHERE user_id = ? AND statut IN ('en_cours', 'pause')
        ORDER BY date_creation DESC
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $sessionActive = $stmt->fetch();

    $now = date('Y-m-d H:i:s');
    $today = date('Y-m-d');

    // Logique de validation
    if ($type === 'start') {
        if ($sessionActive) {
            http_response_code(400);
            echo json_encode(['error' => 'Une session est déjà en cours. Terminez-la d\'abord.']);
            exit;
        }
        if (!$projetId) {
            http_response_code(400);
            echo json_encode(['error' => 'Veuillez sélectionner un projet']);
            exit;
        }
    } elseif ($type === 'pause') {
        if (!$sessionActive || $sessionActive['statut'] !== 'en_cours') {
            http_response_code(400);
            echo json_encode(['error' => 'Aucune session active à mettre en pause']);
            exit;
        }
        $projetId = $sessionActive['projet_id'];
    } elseif ($type === 'resume') {
        if (!$sessionActive || $sessionActive['statut'] !== 'pause') {
            http_response_code(400);
            echo json_encode(['error' => 'Aucune session en pause à reprendre']);
            exit;
        }
        $projetId = $sessionActive['projet_id'];
    } elseif ($type === 'stop') {
        if (!$sessionActive) {
            http_response_code(400);
            echo json_encode(['error' => 'Aucune session à terminer']);
            exit;
        }
        $projetId = $sessionActive['projet_id'];
    }

    try {
        $pdo->beginTransaction();

        // Créer le pointage
        $stmt = $pdo->prepare("
            INSERT INTO pointages (user_id, projet_id, type, date_pointage, latitude, longitude, precision_gps, auto_gps, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$userId, $projetId, $type, $now, $latitude, $longitude, $precision, $autoGps, $notes]);
        $pointageId = $pdo->lastInsertId();

        // Gérer la session
        if ($type === 'start') {
            // Créer une nouvelle session
            $stmt = $pdo->prepare("
                INSERT INTO sessions_travail (user_id, projet_id, date_travail, heure_debut, statut)
                VALUES (?, ?, ?, ?, 'en_cours')
            ");
            $stmt->execute([$userId, $projetId, $today, $now]);
            $sessionId = $pdo->lastInsertId();
        } elseif ($type === 'pause') {
            // Calculer le temps travaillé depuis le dernier start/resume
            $stmt = $pdo->prepare("
                SELECT date_pointage FROM pointages
                WHERE user_id = ? AND type IN ('start', 'resume')
                AND DATE(date_pointage) = ?
                ORDER BY date_pointage DESC
                LIMIT 1
            ");
            $stmt->execute([$userId, $today]);
            $lastStart = $stmt->fetchColumn();

            if ($lastStart) {
                $minutes = (strtotime($now) - strtotime($lastStart)) / 60;
                $stmt = $pdo->prepare("
                    UPDATE sessions_travail
                    SET statut = 'pause', duree_travail = duree_travail + ?
                    WHERE id = ?
                ");
                $stmt->execute([floor($minutes), $sessionActive['id']]);
            }
        } elseif ($type === 'resume') {
            // Calculer le temps de pause
            $stmt = $pdo->prepare("
                SELECT date_pointage FROM pointages
                WHERE user_id = ? AND type = 'pause'
                AND DATE(date_pointage) = ?
                ORDER BY date_pointage DESC
                LIMIT 1
            ");
            $stmt->execute([$userId, $today]);
            $lastPause = $stmt->fetchColumn();

            if ($lastPause) {
                $minutes = (strtotime($now) - strtotime($lastPause)) / 60;
                $stmt = $pdo->prepare("
                    UPDATE sessions_travail
                    SET statut = 'en_cours', duree_pause = duree_pause + ?
                    WHERE id = ?
                ");
                $stmt->execute([floor($minutes), $sessionActive['id']]);
            }
        } elseif ($type === 'stop') {
            // Calculer le temps final si était en cours
            $additionalMinutes = 0;
            if ($sessionActive['statut'] === 'en_cours') {
                $stmt = $pdo->prepare("
                    SELECT date_pointage FROM pointages
                    WHERE user_id = ? AND type IN ('start', 'resume')
                    AND DATE(date_pointage) = ?
                    ORDER BY date_pointage DESC
                    LIMIT 1
                ");
                $stmt->execute([$userId, $today]);
                $lastStart = $stmt->fetchColumn();

                if ($lastStart) {
                    $additionalMinutes = floor((strtotime($now) - strtotime($lastStart)) / 60);
                }
            }

            $stmt = $pdo->prepare("
                UPDATE sessions_travail
                SET statut = 'terminee', heure_fin = ?, duree_travail = duree_travail + ?
                WHERE id = ?
            ");
            $stmt->execute([$now, $additionalMinutes, $sessionActive['id']]);
        }

        $pdo->commit();

        // Récupérer le nouveau statut
        $stmt = $pdo->prepare("
            SELECT s.*, p.nom as projet_nom
            FROM sessions_travail s
            LEFT JOIN projets p ON s.projet_id = p.id
            WHERE s.user_id = ?
            ORDER BY s.date_creation DESC
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $session = $stmt->fetch();

        echo json_encode([
            'success' => true,
            'message' => match($type) {
                'start' => 'Session démarrée',
                'pause' => 'Session en pause',
                'resume' => 'Session reprise',
                'stop' => 'Session terminée'
            },
            'pointage_id' => $pointageId,
            'session' => $session ? [
                'id' => $session['id'],
                'projet_id' => $session['projet_id'],
                'projet_nom' => $session['projet_nom'],
                'statut' => $session['statut'],
                'heure_debut' => $session['heure_debut'],
                'heure_fin' => $session['heure_fin'],
                'duree_travail' => $session['duree_travail'],
                'duree_pause' => $session['duree_pause']
            ] : null
        ]);

    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Erreur: ' . $e->getMessage()]);
    }
    exit;
}

// ============================================
// PUT: Mettre à jour les paramètres GPS
// ============================================
if ($method === 'PUT') {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? 'update_gps';

    if ($action === 'update_gps') {
        $gpsEnabled = isset($input['gps_enabled']) ? (int)$input['gps_enabled'] : 0;
        $autoPunchEnabled = isset($input['auto_punch_enabled']) ? (int)$input['auto_punch_enabled'] : 0;

        $stmt = $pdo->prepare("
            INSERT INTO user_gps_settings (user_id, gps_enabled, auto_punch_enabled)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE
            gps_enabled = VALUES(gps_enabled),
            auto_punch_enabled = VALUES(auto_punch_enabled),
            date_modification = NOW()
        ");
        $stmt->execute([$userId, $gpsEnabled, $autoPunchEnabled]);

        echo json_encode([
            'success' => true,
            'message' => 'Paramètres GPS mis à jour'
        ]);
        exit;
    }

    if ($action === 'update_position') {
        $latitude = isset($input['latitude']) ? (float)$input['latitude'] : null;
        $longitude = isset($input['longitude']) ? (float)$input['longitude'] : null;

        if ($latitude && $longitude) {
            $stmt = $pdo->prepare("
                INSERT INTO user_gps_settings (user_id, derniere_latitude, derniere_longitude, derniere_mise_a_jour)
                VALUES (?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                derniere_latitude = VALUES(derniere_latitude),
                derniere_longitude = VALUES(derniere_longitude),
                derniere_mise_a_jour = NOW()
            ");
            $stmt->execute([$userId, $latitude, $longitude]);
        }

        echo json_encode(['success' => true]);
        exit;
    }
}

http_response_code(405);
echo json_encode(['error' => 'Méthode non autorisée']);
