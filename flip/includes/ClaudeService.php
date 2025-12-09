<?php
/**
 * Service d'intégration avec Claude (Anthropic)
 * Flip Manager
 */

class ClaudeService {
    private $pdo;
    private $apiKey;
    private $model;
    private $apiUrl = 'https://api.anthropic.com/v1/messages';

    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->loadConfiguration();
    }

    /**
     * Charge la configuration depuis la base de données
     */
    private function loadConfiguration() {
        // Auto-migration de la table configuration si elle n'existe pas
        try {
            $this->pdo->query("SELECT 1 FROM app_configurations LIMIT 1");
        } catch (Exception $e) {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS app_configurations (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    cle VARCHAR(50) NOT NULL UNIQUE,
                    valeur TEXT NULL,
                    description VARCHAR(255) NULL,
                    est_sensible TINYINT(1) DEFAULT 0,
                    date_modification TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            
            // Insérer la clé par défaut si création (vide par sécurité)
            $this->setConfiguration('ANTHROPIC_API_KEY', '', 'Clé API Claude', 1);
            $this->setConfiguration('CLAUDE_MODEL', 'claude-3-5-sonnet-20241022', 'Modèle Claude', 0);
        }

        $this->apiKey = $this->getConfiguration('ANTHROPIC_API_KEY');
        $this->model = $this->getConfiguration('CLAUDE_MODEL') ?: 'claude-3-5-sonnet-20241022';
    }

    private function getConfiguration($key) {
        $stmt = $this->pdo->prepare("SELECT valeur FROM app_configurations WHERE cle = ?");
        $stmt->execute([$key]);
        return $stmt->fetchColumn();
    }

    private function setConfiguration($key, $value, $description, $sensitive) {
        $stmt = $this->pdo->prepare("
            INSERT INTO app_configurations (cle, valeur, description, est_sensible) 
            VALUES (?, ?, ?, ?) 
            ON DUPLICATE KEY UPDATE valeur = VALUES(valeur)
        ");
        $stmt->execute([$key, $value, $description, $sensitive]);
    }

    /**
     * Analyse un texte extrait de comparables (Centris)
     * @param string $fullText Texte complet extrait du PDF
     * @param array $projetInfo Informations sur le projet sujet (pour comparaison)
     * @return array Résultats de l'analyse
     */
    public function analyserComparablesTexte($fullText, $projetInfo) {
        $systemPrompt = "Tu es un expert en évaluation immobilière au Québec (Flip immobilier). " .
                       "Ton rôle est d'analyser le TEXTE BRUT extrait de fiches descriptives Centris et de les comparer avec un projet sujet. " .
                       "Tu dois extraire les données de chaque comparable vendu. " .
                       "IMPORTANT : Base-toi uniquement sur les descriptions textuelles (remarques, addenda, inclusions) pour juger de l'état des rénovations. " .
                       "Ignore les en-têtes de pages répétés. " .
                       "Réponds UNIQUEMENT en JSON valide, sans texte avant ni après.";

        $userMessage = "Voici le contenu textuel d'un rapport de comparables vendus. \n\n" .
                      "PROJET SUJET (CE QUE JE VAIS VENDRE) : \n" .
                      "- Adresse : " . ($projetInfo['adresse'] ?? 'N/A') . "\n" .
                      "- Type : " . ($projetInfo['type'] ?? 'Maison unifamiliale') . "\n" .
                      "- Chambres : " . ($projetInfo['chambres'] ?? 'N/A') . "\n" .
                      "- Salles de bain : " . ($projetInfo['sdb'] ?? 'N/A') . "\n" .
                      "- Superficie : " . ($projetInfo['superficie'] ?? 'N/A') . "\n" .
                      "- Garage : " . ($projetInfo['garage'] ?? 'Non') . "\n" .
                      "- État prévu à la vente : Entièrement rénové au goût du jour (Cuisine quartz, SDB moderne, planchers neufs).\n\n" .
                      "CONTENU DU RAPPORT (TEXTE) : \n" . 
                      substr($fullText, 0, 150000) . " \n\n" . // Limite de sécurité pour éviter erreur 400 si texte > context window (rare avec 200k)
                      "TACHE : \n" .
                      "1. Repère chaque propriété vendue dans le texte (cherche 'No Centris', 'Vendu', 'Adresse'). \n" .
                      "2. Pour chaque propriété, note l'état des rénovations sur 10 en lisant les remarques (ex: 'cuisine rénovée', 'à rénover'). \n" .
                      "3. Compare avec le sujet et propose un ajustement (+/- $) pour ramener le comparable au niveau du sujet. \n" .
                      "4. Estime le prix de vente final du sujet. \n\n" .
                      "Format JSON attendu : \n" .
                      "{ \n" .
                      "  'comparables': [ \n" .
                      "    { 'adresse': '...', 'prix_vendu': 000, 'date_vente': 'YYYY-MM-DD', 'chambres': '...', 'sdb': '...', 'superficie': '...', 'annee': 0000, 'etat_note': 8, 'etat_texte': 'Rénové', 'renovations': '...', 'ajustement': -10000, 'commentaire': '...' } \n" .
                      "  ], \n" .
                      "  'analyse_globale': { \n" .
                      "    'prix_suggere': 000, \n" .
                      "    'fourchette_basse': 000, \n" .
                      "    'fourchette_haute': 000, \n" .
                      "    'commentaire_general': '...' \n" .
                      "  } \n" .
                      "}";

        $payload = [
            'model' => $this->model,
            'max_tokens' => 8192,  // Augmenté pour les longues analyses
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $userMessage
                ]
            ],
            'system' => $systemPrompt
        ];

        return $this->callApi($payload);
    }

    private function callApi($payload) {
        $ch = curl_init($this->apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'x-api-key: ' . $this->apiKey,
            'anthropic-version: 2023-06-01',
            'content-type: application/json'
            // Header beta PDF retiré car on envoie du texte
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if (curl_errno($ch)) {
            throw new Exception('Erreur Curl: ' . curl_error($ch));
        }
        curl_close($ch);

        if ($httpCode !== 200) {
            $error = json_decode($response, true);
            $errorMessage = $error['error']['message'] ?? $response;
            throw new Exception('Erreur API Claude (' . $httpCode . '): ' . $errorMessage);
        }

        $data = json_decode($response, true);
        $content = $data['content'][0]['text'] ?? '';
        $stopReason = $data['stop_reason'] ?? '';

        // Log pour debug (à commenter en production)
        // file_put_contents(__DIR__ . '/../uploads/debug_claude_response.txt', $content);

        // Vérifier si la réponse a été tronquée
        if ($stopReason === 'max_tokens') {
            throw new Exception("La réponse de l'IA a été tronquée (trop de données). Essayez avec moins de comparables dans le PDF.");
        }

        // Nettoyer la réponse
        $cleanContent = $content;

        // 1. Supprimer les blocs markdown ```json ... ```
        $cleanContent = preg_replace('/```json\s*/i', '', $cleanContent);
        $cleanContent = preg_replace('/```\s*$/', '', $cleanContent);
        $cleanContent = preg_replace('/```/', '', $cleanContent);

        // 2. Extraire le JSON (chercher le premier { et le dernier })
        $firstBrace = strpos($cleanContent, '{');
        $lastBrace = strrpos($cleanContent, '}');

        if ($firstBrace !== false && $lastBrace !== false && $lastBrace > $firstBrace) {
            $jsonString = substr($cleanContent, $firstBrace, $lastBrace - $firstBrace + 1);

            // 3. Corriger les single quotes en double quotes (si Claude utilise du JS-style)
            // Attention: ne pas remplacer les apostrophes dans le texte
            $jsonString = preg_replace("/(?<![\\\\])'([^']*)'(?=\s*:)/", '"$1"', $jsonString);
            $jsonString = preg_replace("/:\s*'([^']*)'/", ': "$1"', $jsonString);

            // 4. Supprimer les virgules trailing avant } ou ]
            $jsonString = preg_replace('/,(\s*[}\]])/', '$1', $jsonString);

            $json = json_decode($jsonString, true);
            if ($json) return $json;

            // Debug: afficher l'erreur JSON
            $jsonError = json_last_error_msg();
            throw new Exception("JSON invalide: " . $jsonError . " - Début de la réponse: " . substr($jsonString, 0, 200));
        }

        throw new Exception("Réponse invalide de l'IA (pas de JSON trouvé). Début: " . substr($content, 0, 300));
    }
}
