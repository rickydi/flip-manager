<?php
/**
 * Script de contact Evoreno
 * Traite les soumissions du formulaire et envoie un email
 */

// Autoriser les requêtes depuis le même domaine
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Fichier de log pour déboguer
$logFile = __DIR__ . '/contact_log.txt';

function writeLog($message) {
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
}

// Répondre aux requêtes OPTIONS (preflight)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    writeLog("=== Nouvelle soumission reçue ===");
    
    // Récupérer les données JSON
    $json = file_get_contents('php://input');
    writeLog("Données reçues: " . $json);
    
    $data = json_decode($json, true);
    
    // Valider les données
    if (!$data) {
        writeLog("ERREUR: Données JSON invalides");
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Données invalides']);
        exit;
    }
    
    // Nettoyer les données
    $prenom = htmlspecialchars(trim($data['prenom'] ?? ''));
    $nom = htmlspecialchars(trim($data['nom'] ?? ''));
    $email = filter_var(trim($data['email'] ?? ''), FILTER_SANITIZE_EMAIL);
    $projet = htmlspecialchars(trim($data['projet'] ?? 'Non spécifié'));
    
    writeLog("Prénom: $prenom, Nom: $nom, Email: $email, Projet: $projet");
    
    // Vérifier que les champs requis sont remplis
    if (empty($prenom) || empty($nom) || empty($email)) {
        writeLog("ERREUR: Champs obligatoires manquants");
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Veuillez remplir tous les champs obligatoires']);
        exit;
    }
    
    // Valider l'email
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        writeLog("ERREUR: Email invalide - $email");
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Adresse email invalide']);
        exit;
    }
    
    // Destinataire
    $to = 'info@evoreno.com';
    
    // Sujet
    $subject = '=?UTF-8?B?' . base64_encode('Nouvelle demande de devis - ' . $prenom . ' ' . $nom) . '?=';
    
    // Corps du message
    $message = "NOUVELLE DEMANDE DE DEVIS - EVORENO\n";
    $message .= "=====================================\n\n";
    $message .= "Date : " . date('d/m/Y a H:i') . "\n\n";
    $message .= "INFORMATIONS DU CLIENT\n";
    $message .= "-------------------------------------\n";
    $message .= "Prenom       : " . $prenom . "\n";
    $message .= "Nom          : " . $nom . "\n";
    $message .= "Email        : " . $email . "\n";
    $message .= "Type projet  : " . $projet . "\n\n";
    $message .= "-------------------------------------\n";
    $message .= "Ce message a ete envoye depuis le formulaire de contact du site evoreno.com\n";
    
    // En-têtes de l'email - Format compatible avec la plupart des serveurs
    $headers = array(
        'From' => 'info@evoreno.com',
        'Reply-To' => $email,
        'X-Mailer' => 'PHP/' . phpversion(),
        'MIME-Version' => '1.0',
        'Content-Type' => 'text/plain; charset=UTF-8'
    );
    
    // Convertir les headers en string
    $headerString = '';
    foreach ($headers as $key => $value) {
        $headerString .= "$key: $value\r\n";
    }
    
    writeLog("Tentative d'envoi d'email a: $to");
    
    // Envoyer l'email
    $mailSent = @mail($to, $subject, $message, $headerString);
    
    writeLog("Resultat mail(): " . ($mailSent ? "SUCCES" : "ECHEC"));
    
    if ($mailSent) {
        // Envoyer aussi une copie de confirmation au client
        $confirmSubject = '=?UTF-8?B?' . base64_encode('Confirmation de votre demande - Evoreno') . '?=';
        $confirmMessage = "Bonjour " . $prenom . ",\n\n";
        $confirmMessage .= "Nous avons bien recu votre demande de devis.\n";
        $confirmMessage .= "Notre equipe vous contactera dans les plus brefs delais.\n\n";
        $confirmMessage .= "Recapitulatif de votre demande :\n";
        $confirmMessage .= "- Type de projet : " . $projet . "\n\n";
        $confirmMessage .= "Cordialement,\n";
        $confirmMessage .= "L'equipe Evoreno\n";
        $confirmMessage .= "-------------------------------------\n";
        $confirmMessage .= "Tel: (514) 569-5583\n";
        $confirmMessage .= "330 ch Saint Francois Xavier, Delson, QC\n";
        
        $confirmHeaders = "From: info@evoreno.com\r\n";
        $confirmHeaders .= "X-Mailer: PHP/" . phpversion() . "\r\n";
        $confirmHeaders .= "MIME-Version: 1.0\r\n";
        $confirmHeaders .= "Content-Type: text/plain; charset=UTF-8\r\n";
        
        @mail($email, $confirmSubject, $confirmMessage, $confirmHeaders);
        writeLog("Email de confirmation envoye a: $email");
        
        http_response_code(200);
        echo json_encode(['status' => 'success', 'message' => 'Message envoye avec succes']);
    } else {
        // Récupérer l'erreur PHP si disponible
        $error = error_get_last();
        writeLog("ERREUR mail(): " . ($error ? $error['message'] : 'Inconnue'));
        
        // Même si mail() échoue, sauvegarder les données dans un fichier
        $backupFile = __DIR__ . '/demandes_devis.txt';
        $backupData = "\n=== " . date('Y-m-d H:i:s') . " ===\n";
        $backupData .= "Prénom: $prenom\n";
        $backupData .= "Nom: $nom\n";
        $backupData .= "Email: $email\n";
        $backupData .= "Projet: $projet\n";
        file_put_contents($backupFile, $backupData, FILE_APPEND);
        writeLog("Donnees sauvegardees dans demandes_devis.txt");
        
        // Retourner succès quand même (les données sont sauvegardées)
        http_response_code(200);
        echo json_encode(['status' => 'success', 'message' => 'Votre demande a ete enregistree']);
    }
} else {
    writeLog("Methode non autorisee: " . $_SERVER['REQUEST_METHOD']);
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Methode non autorisee']);
}
?>
