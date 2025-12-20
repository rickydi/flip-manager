<?php
/**
 * Fonctions utilitaires
 * Flip Manager
 */

/**
 * Échappe les caractères HTML pour éviter les attaques XSS
 * @param string $string
 * @return string
 */
function e($string) {
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Formate un montant en devise canadienne
 * @param float $amount
 * @param bool $showSymbol
 * @return string
 */
function formatMoney($amount, $showSymbol = true) {
    $num = (float)$amount;
    if ($showSymbol) {
        return number_format($num, 2, ',', ' ') . ' $';
    }
    // Sans symbole - format pour les inputs (sans espaces)
    return $num == floor($num) ? number_format($num, 0, '', '') : number_format($num, 2, '.', '');
}

/**
 * Parse un nombre depuis un input (gère les formats avec virgule ou point)
 * @param mixed $value
 * @return float
 */
function parseNumber($value) {
    if (is_numeric($value)) {
        return (float)$value;
    }
    // Enlever tous les types d'espaces (normal, insécable, fine) et remplacer virgule par point
    $value = preg_replace('/[\s\x{00A0}\x{202F}]+/u', '', $value);
    $value = str_replace(',', '.', $value);
    return (float)$value;
}

/**
 * Formate un pourcentage
 * @param float $value
 * @param int $decimals
 * @return string
 */
function formatPercent($value, $decimals = 2) {
    return number_format((float)$value, $decimals, ',', ' ') . ' %';
}

/**
 * Formate une date en français
 * @param string $date
 * @param string $format
 * @return string
 */
function formatDate($date, $format = 'd/m/Y') {
    if (empty($date)) return '-';
    $dt = new DateTime($date);
    return $dt->format($format);
}

/**
 * Formate une date et heure en français
 * @param string $datetime
 * @return string
 */
function formatDateTime($datetime) {
    if (empty($datetime)) return '-';
    $dt = new DateTime($datetime);
    return $dt->format('d/m/Y H:i');
}

/**
 * Formate une date en temps relatif (il y a X minutes/heures/jours)
 * @param string $datetime
 * @return string
 */
function formatTempsEcoule($datetime) {
    if (empty($datetime)) return '';

    $dt = new DateTime($datetime);
    $now = new DateTime();
    $diff = $now->diff($dt);

    if ($diff->days > 30) {
        return 'il y a ' . floor($diff->days / 30) . ' mois';
    } elseif ($diff->days > 0) {
        return 'il y a ' . $diff->days . ' jour' . ($diff->days > 1 ? 's' : '');
    } elseif ($diff->h > 0) {
        return 'il y a ' . $diff->h . 'h' . ($diff->i > 0 ? $diff->i : '');
    } elseif ($diff->i > 0) {
        return 'il y a ' . $diff->i . ' min';
    } else {
        return 'à l\'instant';
    }
}

/**
 * Formate une durée de session en secondes vers un format lisible
 * @param int|null $seconds Durée en secondes
 * @return string
 */
function formatDureeSession($seconds) {
    if (empty($seconds) || $seconds <= 0) return '-';

    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);

    if ($hours > 0) {
        return $hours . 'h' . ($minutes > 0 ? str_pad($minutes, 2, '0', STR_PAD_LEFT) : '');
    } elseif ($minutes > 0) {
        return $minutes . ' min';
    } else {
        return '< 1 min';
    }
}

/**
 * Enregistre une activité utilisateur
 * @param PDO $pdo
 * @param int $userId
 * @param string $action (login, logout, page_view, etc.)
 * @param string|null $page
 * @param string|null $details
 */
function logActivity($pdo, $userId, $action, $page = null, $details = null) {
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $stmt = $pdo->prepare("INSERT INTO user_activity (user_id, action, page, details, ip_address) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$userId, $action, $page, $details, $ip]);
    } catch (Exception $e) {
        // Ignorer les erreurs silencieusement
    }
}

/**
 * Récupère l'historique d'activité d'un utilisateur
 * @param PDO $pdo
 * @param int $userId
 * @param int $limit
 * @return array
 */
function getUserActivity($pdo, $userId, $limit = 50) {
    $stmt = $pdo->prepare("SELECT * FROM user_activity WHERE user_id = ? ORDER BY created_at DESC LIMIT ?");
    $stmt->execute([$userId, $limit]);
    return $stmt->fetchAll();
}

/**
 * Formate le nom d'une action pour l'affichage
 * @param string $action
 * @return string
 */
function formatActivityAction($action) {
    $actions = [
        'login' => '🔐 Connexion',
        'logout' => '🚪 Déconnexion',
        'page_view' => '👁 Page visitée',
    ];
    return $actions[$action] ?? $action;
}

/**
 * Génère un nom de fichier unique
 * @param string $originalName
 * @return string
 */
function generateUniqueFilename($originalName) {
    $extension = pathinfo($originalName, PATHINFO_EXTENSION);
    return uniqid() . '_' . time() . '.' . strtolower($extension);
}

/**
 * Vérifie si un fichier uploadé est valide
 * @param array $file $_FILES array
 * @return array ['valid' => bool, 'error' => string]
 */
function validateUploadedFile($file) {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors = [
            UPLOAD_ERR_INI_SIZE => 'Le fichier dépasse la taille maximale autorisée.',
            UPLOAD_ERR_FORM_SIZE => 'Le fichier dépasse la taille maximale du formulaire.',
            UPLOAD_ERR_PARTIAL => 'Le fichier n\'a été que partiellement téléchargé.',
            UPLOAD_ERR_NO_FILE => 'Aucun fichier n\'a été téléchargé.',
            UPLOAD_ERR_NO_TMP_DIR => 'Dossier temporaire manquant.',
            UPLOAD_ERR_CANT_WRITE => 'Échec de l\'écriture du fichier.',
            UPLOAD_ERR_EXTENSION => 'Extension PHP a arrêté l\'upload.'
        ];
        return ['valid' => false, 'error' => $errors[$file['error']] ?? 'Erreur inconnue.'];
    }
    
    // Vérifier la taille
    if ($file['size'] > UPLOAD_MAX_SIZE) {
        return ['valid' => false, 'error' => 'Le fichier dépasse 5 MB.'];
    }
    
    // Vérifier l'extension
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, ALLOWED_EXTENSIONS)) {
        return ['valid' => false, 'error' => 'Type de fichier non autorisé. Utilisez: ' . implode(', ', ALLOWED_EXTENSIONS)];
    }
    
    // Vérifier le type MIME
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    $allowedMimes = [
        'image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'application/pdf'
    ];
    
    if (!in_array($mimeType, $allowedMimes)) {
        return ['valid' => false, 'error' => 'Type de fichier non autorisé.'];
    }
    
    return ['valid' => true, 'error' => ''];
}

/**
 * Upload un fichier
 * @param array $file $_FILES array
 * @param string $destination
 * @return array ['success' => bool, 'filename' => string, 'error' => string]
 */
function uploadFile($file, $destination = null) {
    $validation = validateUploadedFile($file);
    if (!$validation['valid']) {
        return ['success' => false, 'filename' => '', 'error' => $validation['error']];
    }
    
    $destination = $destination ?? UPLOAD_PATH;
    
    // Créer le dossier s'il n'existe pas
    if (!is_dir($destination)) {
        mkdir($destination, 0755, true);
    }
    
    $filename = generateUniqueFilename($file['name']);
    $filepath = $destination . $filename;
    
    if (move_uploaded_file($file['tmp_name'], $filepath)) {
        return ['success' => true, 'filename' => $filename, 'error' => ''];
    }
    
    return ['success' => false, 'filename' => '', 'error' => 'Erreur lors du déplacement du fichier.'];
}

/**
 * Supprime un fichier uploadé
 * @param string $filename
 * @return bool
 */
function deleteUploadedFile($filename) {
    if (empty($filename)) return true;
    
    $filepath = UPLOAD_PATH . $filename;
    if (file_exists($filepath)) {
        return unlink($filepath);
    }
    return true;
}

/**
 * Récupère le libellé du statut d'un projet
 * @param string $statut
 * @return string
 */
function getStatutProjetLabel($statut) {
    $labels = [
        'prospection' => 'Prospection',
        'acquisition' => 'Acquisition',
        'renovation' => 'Rénovation',
        'vente' => 'En vente',
        'vendu' => 'Vendu',
        'archive' => 'Archivé'
    ];
    return $labels[$statut] ?? $statut;
}

/**
 * Récupère la classe CSS du statut d'un projet
 * @param string $statut
 * @return string
 */
function getStatutProjetClass($statut) {
    $classes = [
        'prospection' => 'bg-secondary',
        'acquisition' => 'bg-info',
        'renovation' => 'bg-warning',
        'vente' => 'bg-primary',
        'vendu' => 'bg-success',
        'archive' => 'bg-dark'
    ];
    return $classes[$statut] ?? 'bg-secondary';
}

/**
 * Récupère le libellé du statut d'une facture
 * @param string $statut
 * @return string
 */
function getStatutFactureLabel($statut) {
    $labels = [
        'en_attente' => 'En attente',
        'approuvee' => 'Approuvée',
        'rejetee' => 'Rejetée'
    ];
    return $labels[$statut] ?? $statut;
}

/**
 * Récupère la classe CSS du statut d'une facture
 * @param string $statut
 * @return string
 */
function getStatutFactureClass($statut) {
    $classes = [
        'en_attente' => 'bg-warning text-dark',
        'approuvee' => 'bg-success',
        'rejetee' => 'bg-danger'
    ];
    return $classes[$statut] ?? 'bg-secondary';
}

/**
 * Récupère l'icône du statut d'une facture
 * @param string $statut
 * @return string
 */
function getStatutFactureIcon($statut) {
    $icons = [
        'en_attente' => '⏳',
        'approuvee' => '✅',
        'rejetee' => '❌'
    ];
    return $icons[$statut] ?? '❓';
}

/**
 * Récupère le libellé du groupe de catégorie
 * @param string $groupe
 * @return string
 */
function getGroupeCategorieLabel($groupe) {
    $labels = [
        'exterieur' => 'Extérieur',
        'finition' => 'Finition intérieure',
        'ebenisterie' => 'Ébénisterie',
        'electricite' => 'Électricité',
        'plomberie' => 'Plomberie',
        'autre' => 'Autre'
    ];
    return $labels[$groupe] ?? $groupe;
}

/**
 * Affiche un message flash
 * @param string $type success, danger, warning, info
 * @param string $message
 */
function setFlashMessage($type, $message) {
    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message
    ];
}

/**
 * Récupère et supprime le message flash
 * @return array|null
 */
function getFlashMessage() {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

/**
 * Affiche le message flash en HTML
 */
function displayFlashMessage() {
    $flash = getFlashMessage();
    if ($flash) {
        echo '<div class="alert alert-' . e($flash['type']) . ' alert-dismissible fade show" role="alert">';
        echo e($flash['message']);
        echo '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>';
        echo '</div>';
    }
}

/**
 * Alias pour displayFlashMessage
 */
function displayFlashMessages() {
    displayFlashMessage();
}

/**
 * Construit une URL avec le chemin de base
 * @param string $path
 * @return string
 */
function url($path = '') {
    $base = defined('BASE_PATH') ? BASE_PATH : '';
    // Si le chemin commence par /, on le combine avec BASE_PATH
    if (strpos($path, '/') === 0) {
        return $base . $path;
    }
    return $base . '/' . $path;
}

/**
 * Redirige vers une URL
 * @param string $url
 */
function redirect($url) {
    // Ajouter BASE_PATH pour les URLs qui commencent par /
    if (strpos($url, '/') === 0 && defined('BASE_PATH')) {
        $url = BASE_PATH . $url;
    }
    header('Location: ' . $url);
    exit;
}

/**
 * Récupère tous les projets actifs
 * @param PDO $pdo
 * @param bool $activeOnly
 * @return array
 */
function getProjets($pdo, $activeOnly = true) {
    $sql = "SELECT * FROM projets";
    if ($activeOnly) {
        $sql .= " WHERE statut != 'archive'";
    }
    $sql .= " ORDER BY date_creation DESC";
    
    $stmt = $pdo->query($sql);
    return $stmt->fetchAll();
}

/**
 * Récupère un projet par son ID
 * @param PDO $pdo
 * @param int $id
 * @return array|false
 */
function getProjetById($pdo, $id) {
    $stmt = $pdo->prepare("SELECT * FROM projets WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch();
}

/**
 * Récupère toutes les catégories groupées
 * @param PDO $pdo
 * @return array
 */
function getCategoriesGrouped($pdo) {
    $stmt = $pdo->query("SELECT * FROM categories ORDER BY groupe, ordre");
    $categories = $stmt->fetchAll();
    
    $grouped = [];
    foreach ($categories as $cat) {
        $grouped[$cat['groupe']][] = $cat;
    }
    return $grouped;
}

/**
 * Récupère toutes les catégories
 * @param PDO $pdo
 * @return array
 */
function getCategories($pdo) {
    $stmt = $pdo->query("SELECT * FROM categories ORDER BY groupe, ordre");
    return $stmt->fetchAll();
}

/**
 * Vérifie si une facture peut être modifiée par l'employé (moins de 24h)
 * @param string $dateCreation
 * @return bool
 */
function canEditFacture($dateCreation) {
    $created = new DateTime($dateCreation);
    $now = new DateTime();
    $diff = $now->getTimestamp() - $created->getTimestamp();
    return $diff < 86400; // 24 heures
}

/**
 * Récupère le nombre de factures en attente
 * @param PDO $pdo
 * @return int
 */
function getFacturesEnAttenteCount($pdo) {
    $stmt = $pdo->query("SELECT COUNT(*) FROM factures WHERE statut = 'en_attente'");
    return (int) $stmt->fetchColumn();
}

/**
 * Récupère le nombre d'heures en attente d'approbation
 * @param PDO $pdo
 * @return int
 */
function getHeuresEnAttenteCount($pdo) {
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM heures_travaillees WHERE statut = 'en_attente'");
        return (int) $stmt->fetchColumn();
    } catch (Exception $e) {
        return 0; // Table pas encore créée
    }
}

/**
 * Récupère le coût total de la main d'œuvre pour un projet (heures approuvées)
 * @param PDO $pdo
 * @param int $projetId
 * @return array ['heures' => float, 'cout' => float]
 */
function getCoutMainOeuvre($pdo, $projetId) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                COALESCE(SUM(heures), 0) as total_heures,
                COALESCE(SUM(heures * taux_horaire), 0) as total_cout
            FROM heures_travaillees 
            WHERE projet_id = ? AND statut = 'approuvee'
        ");
        $stmt->execute([$projetId]);
        $result = $stmt->fetch();
        return [
            'heures' => (float)$result['total_heures'],
            'cout' => (float)$result['total_cout']
        ];
    } catch (Exception $e) {
        return ['heures' => 0, 'cout' => 0];
    }
}

/**
 * Récupère les heures par employé pour un projet
 * @param PDO $pdo
 * @param int $projetId
 * @return array
 */
function getHeuresParEmploye($pdo, $projetId) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                u.id as user_id,
                CONCAT(u.prenom, ' ', u.nom) as employe_nom,
                SUM(h.heures) as total_heures,
                SUM(h.heures * h.taux_horaire) as total_cout,
                COUNT(*) as nb_entrees
            FROM heures_travaillees h
            JOIN users u ON h.user_id = u.id
            WHERE h.projet_id = ? AND h.statut = 'approuvee'
            GROUP BY u.id, u.prenom, u.nom
            ORDER BY total_heures DESC
        ");
        $stmt->execute([$projetId]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Pagination - calcule l'offset
 * @param int $page
 * @param int $perPage
 * @return int
 */
function getOffset($page, $perPage = 20) {
    return max(0, ($page - 1) * $perPage);
}

/**
 * Génère les liens de pagination
 * @param int $currentPage
 * @param int $totalPages
 * @param string $baseUrl
 * @return string HTML
 */
function generatePagination($currentPage, $totalPages, $baseUrl) {
    if ($totalPages <= 1) return '';

    // Préserver les paramètres de requête existants (filtres, etc.)
    $queryParams = $_GET;
    unset($queryParams['page']); // Retirer page, on va l'ajouter nous-mêmes
    $queryString = http_build_query($queryParams);
    $separator = $queryString ? '&' : '';
    $baseWithParams = $baseUrl . '?' . $queryString . $separator;

    $html = '<nav><ul class="pagination justify-content-center">';

    // Previous
    if ($currentPage > 1) {
        $html .= '<li class="page-item"><a class="page-link" href="' . $baseWithParams . 'page=' . ($currentPage - 1) . '">Précédent</a></li>';
    }

    // Pages
    for ($i = max(1, $currentPage - 2); $i <= min($totalPages, $currentPage + 2); $i++) {
        $active = $i === $currentPage ? 'active' : '';
        $html .= '<li class="page-item ' . $active . '"><a class="page-link" href="' . $baseWithParams . 'page=' . $i . '">' . $i . '</a></li>';
    }

    // Next
    if ($currentPage < $totalPages) {
        $html .= '<li class="page-item"><a class="page-link" href="' . $baseWithParams . 'page=' . ($currentPage + 1) . '">Suivant</a></li>';
    }

    $html .= '</ul></nav>';
    return $html;
}
