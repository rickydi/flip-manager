<?php
/**
 * API pour récupérer l'historique d'activité d'un utilisateur
 * Flip Manager
 */

require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

// Vérifier que l'utilisateur est admin
requireAdmin();

header('Content-Type: application/json');

$userId = (int)($_GET['user_id'] ?? 0);
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = min(500, max(10, (int)($_GET['per_page'] ?? 100)));
$export = ($_GET['export'] ?? '') === 'csv';
$dateFilter = $_GET['date'] ?? ''; // Format: YYYY-MM-DD
$loadMore = isset($_GET['load_more']); // Mode "Voir plus"

if ($userId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'ID utilisateur invalide']);
    exit;
}

// Récupérer les infos de l'utilisateur
$stmt = $pdo->prepare("SELECT prenom, nom FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user) {
    http_response_code(404);
    echo json_encode(['error' => 'Utilisateur non trouvé']);
    exit;
}

// Export CSV
if ($export) {
    // Récupérer TOUT l'historique pour l'export (avec filtre date optionnel)
    $sql = "SELECT * FROM user_activity WHERE user_id = ?";
    $params = [$userId];

    if (!empty($dateFilter) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFilter)) {
        $sql .= " AND DATE(created_at) = ?";
        $params[] = $dateFilter;
    }

    $sql .= " ORDER BY created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $activities = $stmt->fetchAll();

    // Générer le CSV
    $filename = 'activite_' . preg_replace('/[^a-z0-9]/i', '_', $user['prenom'] . '_' . $user['nom']) . '_' . date('Y-m-d') . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');
    // BOM pour Excel
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

    // En-têtes
    fputcsv($output, ['Date', 'Action', 'Page', 'Détails', 'Adresse IP'], ';');

    // Données
    foreach ($activities as $activity) {
        $actionName = match($activity['action']) {
            'login' => 'Connexion',
            'logout' => 'Déconnexion',
            'page_view' => 'Page visitée',
            default => $activity['action']
        };

        fputcsv($output, [
            $activity['created_at'],
            $actionName,
            $activity['page'] ?? '',
            $activity['details'] ?? '',
            $activity['ip_address'] ?? ''
        ], ';');
    }

    fclose($output);
    exit;
}

// Construire la requête avec filtre date optionnel
$whereClause = "user_id = ?";
$params = [$userId];

if (!empty($dateFilter) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFilter)) {
    $whereClause .= " AND DATE(created_at) = ?";
    $params[] = $dateFilter;
}

// Compter le total
$stmt = $pdo->prepare("SELECT COUNT(*) FROM user_activity WHERE $whereClause");
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();

// Récupérer les données paginées
$offset = ($page - 1) * $perPage;
$stmt = $pdo->prepare("SELECT * FROM user_activity WHERE $whereClause ORDER BY created_at DESC LIMIT ? OFFSET ?");
$stmt->execute(array_merge($params, [$perPage, $offset]));
$data = $stmt->fetchAll();

$result = [
    'data' => $data,
    'total' => $total,
    'pages' => ceil($total / $perPage),
    'current_page' => $page,
    'per_page' => $perPage
];

// Formater les données pour l'affichage
$formattedData = array_map(function($activity) {
    return [
        'id' => $activity['id'],
        'date' => formatDateTime($activity['created_at']),
        'date_raw' => $activity['created_at'],
        'action' => $activity['action'],
        'action_formatted' => formatActivityAction($activity['action']),
        'page' => $activity['page'],
        'details' => $activity['details'],
        'ip_address' => $activity['ip_address']
    ];
}, $result['data']);

$hasMore = ($result['current_page'] * $result['per_page']) < $result['total'];

echo json_encode([
    'success' => true,
    'data' => $formattedData,
    'pagination' => [
        'total' => $result['total'],
        'pages' => $result['pages'],
        'current_page' => $result['current_page'],
        'per_page' => $result['per_page'],
        'has_more' => $hasMore,
        'loaded' => min($result['current_page'] * $result['per_page'], $result['total'])
    ],
    'user' => [
        'prenom' => $user['prenom'],
        'nom' => $user['nom']
    ],
    'filter' => [
        'date' => $dateFilter ?: null
    ]
]);
