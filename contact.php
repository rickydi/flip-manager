<?php
/**
 * Script de contact Evoreno
 * Traite les soumissions du formulaire et envoie un email via SMTP
 */

// Charger PHPMailer directement (sans composer)
require_once __DIR__ . '/PHPMailer-6.9.1/src/PHPMailer.php';
require_once __DIR__ . '/PHPMailer-6.9.1/src/SMTP.php';
require_once __DIR__ . '/PHPMailer-6.9.1/src/Exception.php';

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
    $telephone = htmlspecialchars(trim($data['telephone'] ?? 'Non fourni'));
    $projet = htmlspecialchars(trim($data['projet'] ?? 'Non spécifié'));

    writeLog("Prénom: $prenom, Nom: $nom, Email: $email, Tél: $telephone, Projet: $projet");

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

    writeLog("Config SMTP - Host: $smtpHost, Port: $smtpPort, User: $smtpUsername");

    // Date formatée
    $dateFormatted = date('d/m/Y à H:i');

    // Corps du message HTML pour Evoreno
    $messageHTML = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; }
        .container { max-width: 600px; margin: 0 auto; }
        .header { background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%); color: white; padding: 30px; text-align: center; }
        .header h1 { margin: 0; font-size: 24px; }
        .header p { margin: 10px 0 0; opacity: 0.9; font-size: 14px; }
        .content { padding: 30px; background: #f9f9f9; }
        .card { background: white; border-radius: 10px; padding: 25px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .field { margin-bottom: 20px; padding-bottom: 15px; border-bottom: 1px solid #eee; }
        .field:last-child { margin-bottom: 0; padding-bottom: 0; border-bottom: none; }
        .label { font-size: 12px; color: #888; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 5px; }
        .value { font-size: 16px; color: #333; font-weight: 500; }
        .value a { color: #0066cc; text-decoration: none; }
        .highlight { background: #e8f4fd; padding: 15px; border-radius: 8px; border-left: 4px solid #0066cc; }
        .footer { padding: 20px 30px; text-align: center; font-size: 12px; color: #888; }
        .badge { display: inline-block; background: #28a745; color: white; padding: 5px 12px; border-radius: 20px; font-size: 12px; margin-bottom: 20px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>ÉVORENO</h1>
            <p>Nouvelle demande de devis</p>
        </div>
        <div class="content">
            <div class="card">
                <span class="badge">Nouveau lead</span>

                <div class="field">
                    <div class="label">Client</div>
                    <div class="value">' . $prenom . ' ' . $nom . '</div>
                </div>

                <div class="field">
                    <div class="label">Email</div>
                    <div class="value"><a href="mailto:' . $email . '">' . $email . '</a></div>
                </div>

                <div class="field">
                    <div class="label">Téléphone</div>
                    <div class="value"><a href="tel:' . $telephone . '">' . $telephone . '</a></div>
                </div>

                <div class="field">
                    <div class="label">Type de projet</div>
                    <div class="value">' . $projet . '</div>
                </div>

                <div class="highlight">
                    <div class="label">Date de la demande</div>
                    <div class="value">' . $dateFormatted . '</div>
                </div>
            </div>
        </div>
        <div class="footer">
            Demande reçue via le formulaire de contact evoreno.com
        </div>
    </div>
</body>
</html>';

    // Email de confirmation HTML pour le client
    $confirmHTML = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; }
        .container { max-width: 600px; margin: 0 auto; }
        .header { background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%); color: white; padding: 40px 30px; text-align: center; }
        .header h1 { margin: 0; font-size: 28px; }
        .content { padding: 40px 30px; background: #ffffff; }
        .message { font-size: 16px; margin-bottom: 30px; }
        .recap { background: #f8f9fa; border-radius: 10px; padding: 25px; margin: 25px 0; }
        .recap h3 { margin: 0 0 15px; color: #1a1a2e; font-size: 16px; }
        .recap-item { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #eee; }
        .recap-item:last-child { border-bottom: none; }
        .recap-label { color: #666; }
        .recap-value { font-weight: 500; color: #333; }
        .cta { text-align: center; margin: 30px 0; }
        .cta a { display: inline-block; background: #1a1a2e; color: white; padding: 15px 30px; text-decoration: none; border-radius: 8px; font-weight: 500; }
        .contact-info { background: #1a1a2e; color: white; padding: 30px; text-align: center; }
        .contact-info h3 { margin: 0 0 15px; font-size: 16px; }
        .contact-info p { margin: 5px 0; font-size: 14px; opacity: 0.9; }
        .contact-info a { color: #ffffff; text-decoration: none; }
        .footer { padding: 20px; text-align: center; font-size: 12px; color: #888; background: #f8f9fa; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>ÉVORENO</h1>
        </div>
        <div class="content">
            <div class="message">
                <p>Bonjour <strong>' . $prenom . '</strong>,</p>
                <p>Nous avons bien reçu votre demande de devis et nous vous remercions de votre confiance.</p>
                <p>Un membre de notre équipe analysera votre projet et vous contactera dans les <strong>24 à 48 heures</strong> pour discuter de vos besoins en détail.</p>
            </div>

            <div class="recap">
                <h3>Récapitulatif de votre demande</h3>
                <div class="recap-item">
                    <span class="recap-label">Nom</span>
                    <span class="recap-value">' . $prenom . ' ' . $nom . '</span>
                </div>
                <div class="recap-item">
                    <span class="recap-label">Email</span>
                    <span class="recap-value">' . $email . '</span>
                </div>
                <div class="recap-item">
                    <span class="recap-label">Type de projet</span>
                    <span class="recap-value">' . $projet . '</span>
                </div>
                <div class="recap-item">
                    <span class="recap-label">Date</span>
                    <span class="recap-value">' . $dateFormatted . '</span>
                </div>
            </div>
        </div>

        <div class="contact-info">
            <h3>Une question urgente ?</h3>
            <p><a href="tel:+15145695583">(514) 569-5583</a></p>
            <p>330 ch Saint-François-Xavier, Delson, QC</p>
        </div>

        <div class="footer">
            © ' . date('Y') . ' Évoreno - Rénovation Résidentielle<br>
            Cet email a été envoyé automatiquement suite à votre demande sur evoreno.com
        </div>
    </div>
</body>
</html>';

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

        // Contenu HTML
        $mail->isHTML(true);
        $mail->Subject = "Nouvelle demande de devis - $prenom $nom";
        $mail->Body = $messageHTML;
        $mail->AltBody = "Nouvelle demande de devis\n\nClient: $prenom $nom\nEmail: $email\nTéléphone: $telephone\nProjet: $projet\nDate: $dateFormatted";

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

        $mailConfirm->isHTML(true);
        $mailConfirm->Subject = "Confirmation de votre demande - Évoreno";
        $mailConfirm->Body = $confirmHTML;
        $mailConfirm->AltBody = "Bonjour $prenom,\n\nNous avons bien reçu votre demande de devis.\nNotre équipe vous contactera dans les plus brefs délais.\n\nType de projet: $projet\n\nCordialement,\nL'équipe Évoreno\n\nTél: (514) 569-5583\n330 ch Saint-François-Xavier, Delson, QC";

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
        $backupData .= "Téléphone: $telephone\n";
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
