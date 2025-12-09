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
     * Analyse un fichier PDF de comparables
     * @param string $pdfPath Chemin absolu vers le fichier PDF
     * @param array $projetInfo Informations sur le projet sujet (pour comparaison)
     * @return array Résultats de l'analyse
     */
    public function analyserComparables($pdfPath, $projetInfo) {
        if (!file_exists($pdfPath)) {
            throw new Exception("Fichier PDF introuvable.");
        }

        $pdfData = base64_encode(file_get_contents($pdfPath));

        $systemPrompt = "Tu es un expert en évaluation immobilière au Québec (Flip immobilier). " .
                       "Ton rôle est d'analyser des fiches descriptives Centris (PDF) et de les comparer avec un projet sujet. " .
                       "Tu dois extraire les données de chaque comparable vendu et estimer la valeur du sujet. " .
                       "Sois précis, critique sur les rénovations visibles (regarde les photos si disponibles dans le PDF) et justifie chaque ajustement. " .
                       "Réponds UNIQUEMENT en JSON valide.";

        $userMessage = "Voici un rapport de comparables vendus (PDF). \n" .
                      "PROJET SUJET (CE QUE JE VAIS VENDRE) : \n" .
                      "- Adresse : " . ($projetInfo['adresse'] ?? 'N/A') . "\n" .
                      "- Type : " . ($projetInfo['type'] ?? 'Maison unifamiliale') . "\n" .
                      "- Chambres : " . ($projetInfo['chambres'] ?? 'N/A') . "\n" .
                      "- Salles de bain : " . ($projetInfo['sdb'] ?? 'N/A') . "\n" .
                      "- Superficie : " . ($projetInfo['superficie'] ?? 'N/A') . "\n" .
                      "- Garage : " . ($projetInfo['garage'] ?? 'Non') . "\n" .
                      "- État prévu à la vente : Entièrement rénové au goût du jour (Cuisine quartz, SDB moderne, planchers neufs).\n\n" .
                      "TACHE : \n" .
                      "1. Extrais chaque propriété vendue du PDF. \n" .
                      "2. Pour chaque propriété, note l'état des rénovations sur 10 (basé sur les photos et descriptions). \n" .
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
            'max_tokens' => 4096,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'document',
                            'source' => [
                                'type' => 'base64',
                                'media_type' => 'application/pdf',
                                'data' => $pdfData
                            ]
                        ],
                        [
                            'type' => 'text',
                            'text' => $userMessage
                        ]
                    ]
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
            'content-type: application/json',
            'anthropic-beta: pdfs-2024-09-25' // Header nécessaire pour le support PDF
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if (curl_errno($ch)) {
            throw new Exception('Erreur Curl: ' . curl_error($ch));
        }
        curl_close($ch);

        if ($httpCode !== 200) {
            $error = json_decode($response, true);
            throw new Exception('Erreur API Claude (' . $httpCode . '): ' . ($error['error']['message'] ?? $response));
        }

        $data = json_decode($response, true);
        $content = $data['content'][0]['text'] ?? '';
        
        // Extraire le JSON de la réponse (au cas où il y a du texte autour)
        if (preg_match('/\{[\s\S]*\}/', $content, $matches)) {
            $json = json_decode($matches[0], true);
            if ($json) return $json;
        }

        throw new Exception("Réponse invalide de l'IA (pas de JSON trouvé).");
    }
}
