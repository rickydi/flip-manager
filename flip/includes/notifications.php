<?php
/**
 * Système de notifications Pushover
 * Flip Manager
 */

// Configuration Pushover (à définir dans config.php)
if (!defined('PUSHOVER_APP_TOKEN')) {
    define('PUSHOVER_APP_TOKEN', ''); // Token de l'application Pushover
}
if (!defined('PUSHOVER_USER_KEY')) {
    define('PUSHOVER_USER_KEY', ''); // Clé utilisateur Pushover (admin)
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
    // Vérifier que les clés sont configurées
    if (empty(PUSHOVER_APP_TOKEN) || empty(PUSHOVER_USER_KEY)) {
        return false;
    }

    $data = [
        'token' => PUSHOVER_APP_TOKEN,
        'user' => PUSHOVER_USER_KEY,
        'title' => $title,
        'message' => $message,
        'priority' => $priority,
        'sound' => 'pushover'
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
        CURLOPT_TIMEOUT => 10
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

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

    $url = APP_URL . BASE_PATH . '/admin/factures.php';

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

    $url = APP_URL . BASE_PATH . '/admin/heures.php';

    return sendPushoverNotification($title, $message, '0', $url);
}

/**
 * Notification pour nouvelles photos uploadées
 */
function notifyNewPhotos($employeNom, $projetNom, $nbPhotos) {
    $title = "Nouvelles photos";
    $message = "$employeNom a ajouté $nbPhotos photo(s)\n";
    $message .= "Projet: $projetNom";

    $url = APP_URL . BASE_PATH . '/admin/photos.php';

    return sendPushoverNotification($title, $message, '-1', $url); // Priorité basse pour photos
}
