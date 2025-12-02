<?php
/**
 * Script de contact Evoreno
 * Traite les soumissions du formulaire et envoie un email via SMTP
 */

// Charger PHPMailer
require_once __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// Charger les variables d'environnement depuis .env
function loadEnv($path) {
    if (!file_exists($path)) {
        return false;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        putenv("$name=$value");
        $_ENV[$name] = $value;
    }
    return true;
}

loadEnv(__DIR__ . '/.env');

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

    // Configuration SMTP depuis .env
    $smtpHost = getenv('SMTP_HOST') ?: 'smtp.gmail.com';
    $smtpPort = getenv('SMTP_PORT') ?: 587;
    $smtpUsername = getenv('SMTP_USERNAME') ?: '';
    $smtpPassword = getenv('SMTP_PASSWORD') ?: '';
    $fromEmail = getenv('SMTP_FROM_EMAIL') ?: 'info@evoreno.com';
    $fromName = getenv('SMTP_FROM_NAME') ?: 'Evoreno';

    // Corps du message
    $messageBody = "NOUVELLE DEMANDE DE DEVIS - EVORENO\n";
    $messageBody .= "=====================================\n\n";
    $messageBody .= "Date : " . date('d/m/Y à H:i') . "\n\n";
    $messageBody .= "INFORMATIONS DU CLIENT\n";
    $messageBody .= "-------------------------------------\n";
    $messageBody .= "Prénom       : " . $prenom . "\n";
    $messageBody .= "Nom          : " . $nom . "\n";
    $messageBody .= "Email        : " . $email . "\n";
    $messageBody .= "Type projet  : " . $projet . "\n\n";
    $messageBody .= "-------------------------------------\n";
    $messageBody .= "Ce message a été envoyé depuis le formulaire de contact du site evoreno.com\n";

    writeLog("Tentative d'envoi d'email via SMTP...");

    // Créer une instance PHPMailer
    $mail = new PHPMailer(true);

    try {
        // Configuration du serveur SMTP
        $mail->isSMTP();
        $mail->Host = $smtpHost;
        $mail->SMTPAuth = true;
        $mail->Username = $smtpUsername;
        $mail->Password = $smtpPassword;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = $smtpPort;
        $mail->CharSet = 'UTF-8';

        // Destinataires
        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress('info@evoreno.com', 'Evoreno');
        $mail->addReplyTo($email, "$prenom $nom");

        // Contenu
        $mail->isHTML(false);
        $mail->Subject = "Nouvelle demande de devis - $prenom $nom";
        $mail->Body = $messageBody;

        $mail->send();
        writeLog("SUCCES: Email envoyé à info@evoreno.com");

        // Envoyer aussi une copie de confirmation au client
        $mailConfirm = new PHPMailer(true);
        $mailConfirm->isSMTP();
        $mailConfirm->Host = $smtpHost;
        $mailConfirm->SMTPAuth = true;
        $mailConfirm->Username = $smtpUsername;
        $mailConfirm->Password = $smtpPassword;
        $mailConfirm->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mailConfirm->Port = $smtpPort;
        $mailConfirm->CharSet = 'UTF-8';

        $mailConfirm->setFrom($fromEmail, $fromName);
        $mailConfirm->addAddress($email, "$prenom $nom");

        $confirmMessage = "Bonjour $prenom,\n\n";
        $confirmMessage .= "Nous avons bien reçu votre demande de devis.\n";
        $confirmMessage .= "Notre équipe vous contactera dans les plus brefs délais.\n\n";
        $confirmMessage .= "Récapitulatif de votre demande :\n";
        $confirmMessage .= "- Type de projet : $projet\n\n";
        $confirmMessage .= "Cordialement,\n";
        $confirmMessage .= "L'équipe Evoreno\n";
        $confirmMessage .= "-------------------------------------\n";
        $confirmMessage .= "Tél: (514) 569-5583\n";
        $confirmMessage .= "330 ch Saint-François-Xavier, Delson, QC\n";

        $mailConfirm->isHTML(false);
        $mailConfirm->Subject = "Confirmation de votre demande - Evoreno";
        $mailConfirm->Body = $confirmMessage;

        $mailConfirm->send();
        writeLog("SUCCES: Email de confirmation envoyé à $email");

        http_response_code(200);
        echo json_encode(['status' => 'success', 'message' => 'Message envoyé avec succès']);

    } catch (Exception $e) {
        writeLog("ERREUR SMTP: " . $mail->ErrorInfo);

        // Sauvegarder les données dans un fichier en cas d'échec
        $backupFile = __DIR__ . '/demandes_devis.txt';
        $backupData = "\n=== " . date('Y-m-d H:i:s') . " ===\n";
        $backupData .= "Prénom: $prenom\n";
        $backupData .= "Nom: $nom\n";
        $backupData .= "Email: $email\n";
        $backupData .= "Projet: $projet\n";
        $backupData .= "Erreur SMTP: " . $mail->ErrorInfo . "\n";
        file_put_contents($backupFile, $backupData, FILE_APPEND);
        writeLog("Données sauvegardées dans demandes_devis.txt");

        // Retourner une erreur
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Erreur lors de l\'envoi. Veuillez réessayer plus tard.']);
    }
} else {
    writeLog("Méthode non autorisée: " . $_SERVER['REQUEST_METHOD']);
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Méthode non autorisée']);
}
?>
