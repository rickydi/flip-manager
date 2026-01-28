<?php
/**
 * API admin pour voir les employés en cours de travail
 * Flip Manager
 */

require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

// Vérifier que l'utilisateur est admin
requireAdmin();

header('Content-Type: application/json');

$action = $_GET['action'] ?? 'actifs';

// ============================================
// Employés actuellement en travail
// ============================================
if ($action === 'actifs') {
    // Auto-création table si nécessaire
    try {
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
                date_modification DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Exception $e) {}

    $stmt = $pdo->query("
        SELECT
            s.id,
            s.user_id,
            s.projet_id,
            s.statut,
            s.heure_debut,
            s.duree_travail,
            s.duree_pause,
            CONCAT(u.prenom, ' ', u.nom) as employe_nom,
            u.est_contremaitre,
            p.nom as projet_nom,
            p.adresse as projet_adresse,
            p.ville as projet_ville
        FROM sessions_travail s
        JOIN users u ON s.user_id = u.id
        LEFT JOIN projets p ON s.projet_id = p.id
        WHERE s.statut IN ('en_cours', 'pause')
        AND u.est_contremaitre = 0
        AND u.role = 'employe'
        ORDER BY s.heure_debut DESC
    ");
    $sessions = $stmt->fetchAll();

    // Calculer le temps réel pour chaque session en cours
    $result = [];
    foreach ($sessions as $session) {
        $tempsTravaille = $session['duree_travail'];

        // Si en cours, calculer le temps additionnel
        if ($session['statut'] === 'en_cours') {
            $stmt = $pdo->prepare("
                SELECT date_pointage FROM pointages
                WHERE user_id = ? AND type IN ('start', 'resume')
                AND DATE(date_pointage) = CURDATE()
                ORDER BY date_pointage DESC
                LIMIT 1
            ");
            $stmt->execute([$session['user_id']]);
            $lastStart = $stmt->fetchColumn();

            if ($lastStart) {
                $additionalMinutes = floor((time() - strtotime($lastStart)) / 60);
                $tempsTravaille += $additionalMinutes;
            }
        }

        $result[] = [
            'id' => $session['id'],
            'user_id' => $session['user_id'],
            'employe_nom' => $session['employe_nom'],
            'projet_id' => $session['projet_id'],
            'projet_nom' => $session['projet_nom'],
            'projet_adresse' => $session['projet_adresse'],
            'projet_ville' => $session['projet_ville'],
            'statut' => $session['statut'],
            'heure_debut' => $session['heure_debut'],
            'duree_travail' => $tempsTravaille,
            'duree_pause' => $session['duree_pause'],
            'duree_formatee' => formatDureeMinutes($tempsTravaille)
        ];
    }

    echo json_encode([
        'success' => true,
        'employes_actifs' => $result,
        'total' => count($result)
    ]);
    exit;
}

// ============================================
// Résumé du jour
// ============================================
if ($action === 'resume_jour') {
    $date = $_GET['date'] ?? date('Y-m-d');

    $stmt = $pdo->prepare("
        SELECT
            s.*,
            CONCAT(u.prenom, ' ', u.nom) as employe_nom,
            p.nom as projet_nom
        FROM sessions_travail s
        JOIN users u ON s.user_id = u.id
        LEFT JOIN projets p ON s.projet_id = p.id
        WHERE s.date_travail = ?
        AND u.est_contremaitre = 0
        AND u.role = 'employe'
        ORDER BY u.nom, u.prenom, s.heure_debut
    ");
    $stmt->execute([$date]);
    $sessions = $stmt->fetchAll();

    // Grouper par employé
    $parEmploye = [];
    foreach ($sessions as $s) {
        $userId = $s['user_id'];
        if (!isset($parEmploye[$userId])) {
            $parEmploye[$userId] = [
                'employe_nom' => $s['employe_nom'],
                'sessions' => [],
                'total_travail' => 0,
                'total_pause' => 0
            ];
        }
        $parEmploye[$userId]['sessions'][] = $s;
        $parEmploye[$userId]['total_travail'] += $s['duree_travail'];
        $parEmploye[$userId]['total_pause'] += $s['duree_pause'];
    }

    echo json_encode([
        'success' => true,
        'date' => $date,
        'par_employe' => array_values($parEmploye),
        'total_sessions' => count($sessions)
    ]);
    exit;
}

// ============================================
// Historique des pointages
// ============================================
if ($action === 'historique') {
    $userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : null;
    $dateDebut = $_GET['date_debut'] ?? date('Y-m-d', strtotime('-7 days'));
    $dateFin = $_GET['date_fin'] ?? date('Y-m-d');

    $sql = "
        SELECT
            p.*,
            CONCAT(u.prenom, ' ', u.nom) as employe_nom,
            pr.nom as projet_nom
        FROM pointages p
        JOIN users u ON p.user_id = u.id
        LEFT JOIN projets pr ON p.projet_id = pr.id
        WHERE DATE(p.date_pointage) BETWEEN ? AND ?
        AND u.est_contremaitre = 0
        AND u.role = 'employe'
    ";
    $params = [$dateDebut, $dateFin];

    if ($userId) {
        $sql .= " AND p.user_id = ?";
        $params[] = $userId;
    }

    $sql .= " ORDER BY p.date_pointage DESC LIMIT 500";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $pointages = $stmt->fetchAll();

    echo json_encode([
        'success' => true,
        'pointages' => $pointages
    ]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Action invalide']);

// ============================================
// Fonction helper
// ============================================
function formatDureeMinutes($minutes) {
    if ($minutes < 60) {
        return $minutes . ' min';
    }
    $heures = floor($minutes / 60);
    $mins = $minutes % 60;
    return $heures . 'h' . ($mins > 0 ? sprintf('%02d', $mins) : '');
}
