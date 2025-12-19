<?php
/**
 * Système de notifications Pushover
 * Flip Manager
 *
 * Les clés sont stockées dans app_configurations (Admin > Configuration)
 */

/**
 * Récupère une configuration depuis la base de données
 */
function getNotificationConfig($pdo, $key) {
    try {
        $stmt = $pdo->prepare("SELECT valeur FROM app_configurations WHERE cle = ?");
        $stmt->execute([$key]);
        return $stmt->fetchColumn() ?: '';
    } catch (Exception $e) {
        return '';
    }
}

/**
 * Initialise les configurations Pushover si elles n'existent pas
 */
function initPushoverConfig($pdo) {
    try {
        // Vérifier si les configs existent déjà
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM app_configurations WHERE cle = 'PUSHOVER_APP_TOKEN'");
        $stmt->execute();
        if ($stmt->fetchColumn() == 0) {
            // Ajouter les configurations Pushover
            $stmt = $pdo->prepare("
                INSERT INTO app_configurations (cle, valeur, description, est_sensible)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute(['PUSHOVER_APP_TOKEN', '', 'Token application Pushover', 1]);
            $stmt->execute(['PUSHOVER_USER_KEY', '', 'Clé utilisateur Pushover', 1]);
        }
    } catch (Exception $e) {
        // Table n'existe pas encore, ignorer
    }
}

/**
 * Envoie une notification Pushover
 *
 * @param string $title Titre de la notification
 * @param string $message Message de la notification
 * @param string $priority Priorité (-2 à 2, 0 = normal)
 * @param string $url URL optionnelle à joindre
 * @return bool Succès de l'envoi
 */
function sendPushoverNotification($title, $message, $priority = '0', $url = '') {
    global $pdo;

    // Fichier de log pour débogage
    $logFile = __DIR__ . '/../logs/pushover_debug.log';
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }

    $log = function($msg) use ($logFile) {
        @file_put_contents($logFile, date('Y-m-d H:i:s') . ' - ' . $msg . "\n", FILE_APPEND);
    };

    $log("=== Nouvelle notification ===");
    $log("Titre: $title");

    // Initialiser les configs si nécessaire
    initPushoverConfig($pdo);

    // Récupérer les clés depuis la base de données
    $appToken = getNotificationConfig($pdo, 'PUSHOVER_APP_TOKEN');
    $userKey = getNotificationConfig($pdo, 'PUSHOVER_USER_KEY');

    $log("App Token présent: " . (!empty($appToken) ? 'OUI (' . strlen($appToken) . ' chars)' : 'NON'));
    $log("User Key présent: " . (!empty($userKey) ? 'OUI (' . strlen($userKey) . ' chars)' : 'NON'));

    // Vérifier que les clés sont configurées
    if (empty($appToken) || empty($userKey)) {
        $log("ERREUR: Clés manquantes - abandon");
        return false;
    }

    $data = [
        'token' => $appToken,
        'user' => $userKey,
        'title' => $title,
        'message' => $message,
        'priority' => $priority,
        'sound' => 'gamelan'
    ];

    if (!empty($url)) {
        $data['url'] = $url;
        $data['url_title'] = 'Voir les détails';
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://api.pushover.net/1/messages.json',
        CURLOPT_POSTFIELDS => $data,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true
    ]);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $log("HTTP Code: $httpCode");
    $log("Réponse: $response");
    if ($curlError) {
        $log("CURL Error: $curlError");
    }
    $log("Résultat: " . ($httpCode === 200 ? 'SUCCÈS' : 'ÉCHEC'));

    return $httpCode === 200;
}

/**
 * Notification pour nouvelle facture soumise
 */
function notifyNewFacture($employeNom, $projetNom, $fournisseur, $montant) {
    $title = "Nouvelle facture";
    $message = "$employeNom a soumis une facture\n";
    $message .= "Projet: $projetNom\n";
    $message .= "Fournisseur: $fournisseur\n";
    $message .= "Montant: " . number_format($montant, 2, ',', ' ') . " $";

    $url = APP_URL . BASE_PATH . '/admin/factures/liste.php';

    return sendPushoverNotification($title, $message, '0', $url);
}

/**
 * Notification pour nouvelles heures soumises
 */
function notifyNewHeures($employeNom, $projetNom, $heures, $date) {
    $title = "Nouvelles heures";
    $message = "$employeNom a entré ses heures\n";
    $message .= "Projet: $projetNom\n";
    $message .= "Heures: " . number_format($heures, 1) . "h\n";
    $message .= "Date: $date";

    $url = APP_URL . BASE_PATH . '/admin/temps/liste.php';

    return sendPushoverNotification($title, $message, '0', $url);
}

/**
 * Notification pour nouvelles photos uploadées
 */
function notifyNewPhotos($employeNom, $projetNom, $nbPhotos) {
    $title = "Nouvelles photos";
    $message = "$employeNom a ajouté $nbPhotos photo(s)\n";
    $message .= "Projet: $projetNom";

    $url = APP_URL . BASE_PATH . '/admin/photos/liste.php';

    return sendPushoverNotification($title, $message, '0', $url);
}

/**
 * Notification quand une facture est approuvée (pour l'employé - via admin)
 */
function notifyFactureApprouvee($employeNom, $projetNom, $fournisseur, $montant) {
    $title = "Facture approuvée";
    $message = "Votre facture a été approuvée!\n";
    $message .= "Projet: $projetNom\n";
    $message .= "Fournisseur: $fournisseur\n";
    $message .= "Montant: " . number_format($montant, 2, ',', ' ') . " $";

    return sendPushoverNotification($title, $message, '0');
}

/**
 * Notification quand une facture est rejetée (pour l'employé - via admin)
 */
function notifyFactureRejetee($employeNom, $projetNom, $fournisseur, $montant, $raison) {
    $title = "Facture rejetée";
    $message = "Votre facture a été rejetée\n";
    $message .= "Projet: $projetNom\n";
    $message .= "Fournisseur: $fournisseur\n";
    $message .= "Montant: " . number_format($montant, 2, ',', ' ') . " $\n";
    $message .= "Raison: $raison";

    return sendPushoverNotification($title, $message, '0');
}

/**
 * Notification pour facture de gros montant (> seuil)
 */
function notifyGrosMontant($employeNom, $projetNom, $fournisseur, $montant, $seuil = 3000) {
    if ($montant < $seuil) return false;

    $title = "Facture importante!";
    $message = "$employeNom a soumis une grosse facture\n";
    $message .= "Projet: $projetNom\n";
    $message .= "Fournisseur: $fournisseur\n";
    $message .= "Montant: " . number_format($montant, 2, ',', ' ') . " $";

    $url = APP_URL . BASE_PATH . '/admin/factures/approuver.php';

    return sendPushoverNotification($title, $message, '0', $url);
}

/**
 * Notification quand quelqu'un se connecte
 */
function notifyConnexion($userName, $userRole, $ip = '') {
    $title = "Connexion";
    $message = "$userName s'est connecté\n";
    $message .= "Rôle: " . ($userRole === 'admin' ? 'Administrateur' : 'Employé');
    if ($ip) {
        $message .= "\nIP: $ip";
    }

    return sendPushoverNotification($title, $message, '0');
}

/**
 * Notification rappel heures à approuver
 */
function notifyHeuresEnAttente($nombre) {
    if ($nombre <= 0) return false;

    $title = "Heures à approuver";
    $message = "$nombre entrée(s) d'heures en attente d'approbation";

    $url = APP_URL . BASE_PATH . '/admin/temps/liste.php';

    return sendPushoverNotification($title, $message, '0', $url);
}
