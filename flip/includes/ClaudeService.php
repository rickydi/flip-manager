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
            
            // Insérer les clés par défaut si création (vides par sécurité)
            $this->setConfiguration('ANTHROPIC_API_KEY', '', 'Clé API Claude', 1);
            $this->setConfiguration('CLAUDE_MODEL', 'claude-3-5-sonnet-20241022', 'Modèle Claude', 0);
            $this->setConfiguration('PUSHOVER_APP_TOKEN', '', 'Token application Pushover (notifications)', 1);
            $this->setConfiguration('PUSHOVER_USER_KEY', '', 'Clé utilisateur Pushover', 1);
        }

        // S'assurer que les clés Pushover existent (migration)
        $this->ensurePushoverConfig();

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
     * S'assure que les clés Pushover existent (migration pour bases existantes)
     */
    private function ensurePushoverConfig() {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM app_configurations WHERE cle = 'PUSHOVER_APP_TOKEN'");
        $stmt->execute();
        if ($stmt->fetchColumn() == 0) {
            $stmt = $this->pdo->prepare("
                INSERT IGNORE INTO app_configurations (cle, valeur, description, est_sensible)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute(['PUSHOVER_APP_TOKEN', '', 'Token application Pushover (notifications)', 1]);
            $stmt->execute(['PUSHOVER_USER_KEY', '', 'Clé utilisateur Pushover', 1]);
        }
    }

    /**
     * Analyse un fichier PDF de comparables EN DEUX ÉTAPES
     * Étape 1: Extraire la liste des propriétés
     * Étape 2: Analyser chaque propriété en détail
     *
     * @param string $pdfPath Chemin absolu vers le fichier PDF
     * @param array $projetInfo Informations sur le projet sujet (pour comparaison)
     * @param callable|null $progressCallback Callback pour suivre la progression
     * @return array Résultats de l'analyse
     */
    public function analyserComparables($pdfPath, $projetInfo, $progressCallback = null) {
        if (!file_exists($pdfPath)) {
            throw new Exception("Fichier PDF introuvable.");
        }

        $pdfData = base64_encode(file_get_contents($pdfPath));

        // === ÉTAPE 1: Extraire la liste des propriétés ===
        if ($progressCallback) $progressCallback('etape1', 'Extraction de la liste des propriétés...');

        $listeProprietés = $this->etape1_extraireListe($pdfData);

        if (empty($listeProprietés)) {
            throw new Exception("Aucune propriété trouvée dans le PDF.");
        }

        // === ÉTAPE 2: Analyser chaque propriété en détail ===
        $comparables = [];
        $totalProps = count($listeProprietés);

        foreach ($listeProprietés as $index => $prop) {
            if ($progressCallback) {
                $progressCallback('etape2', "Analyse détaillée " . ($index + 1) . "/$totalProps: " . ($prop['adresse'] ?? 'Propriété'));
            }

            $detailAnalyse = $this->etape2_analyserPropriete($pdfData, $prop, $projetInfo);
            $comparables[] = array_merge($prop, $detailAnalyse);
        }

        // === ÉTAPE 3: Synthèse finale ===
        if ($progressCallback) $progressCallback('synthese', 'Calcul du prix suggéré...');

        $analyseSynthese = $this->etape3_synthese($comparables, $projetInfo);

        return [
            'comparables' => $comparables,
            'analyse_globale' => $analyseSynthese
        ];
    }

    /**
     * ÉTAPE 1: Extraire la liste simple des propriétés du PDF
     */
    private function etape1_extraireListe($pdfData) {
        $systemPrompt = "Tu es un assistant qui extrait des données de fiches Centris. " .
                       "Ton SEUL travail est de lister les propriétés vendues présentes dans le PDF. " .
                       "Réponds UNIQUEMENT en JSON valide, sans texte.";

        $userMessage = "Analyse ce PDF Centris et liste TOUTES les propriétés vendues.\n\n" .
                      "Pour chaque propriété, extrais UNIQUEMENT:\n" .
                      "- adresse (rue, ville)\n" .
                      "- prix_vendu (nombre entier)\n" .
                      "- date_vente (YYYY-MM-DD si disponible)\n" .
                      "- chambres (ex: '3+1' ou '4')\n" .
                      "- sdb (salles de bain)\n" .
                      "- superficie (en pi²)\n" .
                      "- annee (année de construction)\n\n" .
                      "Format JSON:\n" .
                      "[{\"adresse\": \"123 Rue Test, Ville\", \"prix_vendu\": 350000, \"date_vente\": \"2024-05-15\", \"chambres\": \"3\", \"sdb\": \"2\", \"superficie\": \"1200\", \"annee\": 1985}]";

        $payload = [
            'model' => $this->model,
            'max_tokens' => 2048,
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

        $result = $this->callApiPdf($payload);

        // S'assurer que c'est un array
        if (!is_array($result)) {
            throw new Exception("Format de réponse invalide à l'étape 1");
        }

        // Si c'est un objet avec une clé 'proprietes' ou 'comparables', l'extraire
        if (isset($result['proprietes'])) return $result['proprietes'];
        if (isset($result['comparables'])) return $result['comparables'];

        // Sinon c'est déjà un array de propriétés
        return $result;
    }

    /**
     * ÉTAPE 2: Analyser une propriété en détail (état, rénovations, ajustement)
     */
    private function etape2_analyserPropriete($pdfData, $propriete, $projetInfo) {
        $adresse = $propriete['adresse'] ?? 'Inconnue';

        $systemPrompt = "Tu es un expert en évaluation immobilière au Québec. " .
                       "Tu analyses UNE SEULE propriété et tu évalues son état basé sur les photos/descriptions. " .
                       "Réponds UNIQUEMENT en JSON valide.";

        $userMessage = "Dans ce PDF, trouve la propriété située au: {$adresse}\n\n" .
                      "PROJET SUJET (pour comparaison):\n" .
                      "- Adresse: " . ($projetInfo['adresse'] ?? 'N/A') . "\n" .
                      "- État prévu: Entièrement rénové (cuisine quartz, SDB moderne, planchers neufs)\n\n" .
                      "Analyse cette propriété ({$adresse}) et retourne:\n" .
                      "- etat_note: Note de 1 à 10 (10 = entièrement rénové luxe)\n" .
                      "- etat_texte: Description courte de l'état (ex: 'Cuisine rénovée, SDB d'origine')\n" .
                      "- renovations: Liste des rénovations visibles\n" .
                      "- ajustement: Montant +/- $ pour ramener au niveau du sujet (rénové)\n" .
                      "- commentaire: Justification de l'ajustement\n\n" .
                      "Format JSON:\n" .
                      "{\"etat_note\": 7, \"etat_texte\": \"Partiellement rénové\", \"renovations\": \"Cuisine refaite 2020, planchers flottants\", \"ajustement\": -15000, \"commentaire\": \"SDB non rénovées, sous-sol non fini\"}";

        $payload = [
            'model' => $this->model,
            'max_tokens' => 1024,
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

        try {
            return $this->callApiPdf($payload);
        } catch (Exception $e) {
            // En cas d'erreur, retourner des valeurs par défaut
            return [
                'etat_note' => 5,
                'etat_texte' => 'Non analysé',
                'renovations' => '',
                'ajustement' => 0,
                'commentaire' => 'Erreur lors de l\'analyse: ' . $e->getMessage()
            ];
        }
    }

    /**
     * ÉTAPE 3: Synthèse - Calculer le prix suggéré basé sur les comparables analysés
     */
    private function etape3_synthese($comparables, $projetInfo) {
        // Calculer la moyenne des prix ajustés
        $prixAjustes = [];
        foreach ($comparables as $comp) {
            $prixVendu = (float)($comp['prix_vendu'] ?? 0);
            $ajustement = (float)($comp['ajustement'] ?? 0);
            if ($prixVendu > 0) {
                $prixAjustes[] = $prixVendu + $ajustement;
            }
        }

        if (empty($prixAjustes)) {
            return [
                'prix_suggere' => 0,
                'fourchette_basse' => 0,
                'fourchette_haute' => 0,
                'commentaire_general' => 'Impossible de calculer un prix suggéré.'
            ];
        }

        $moyenne = array_sum($prixAjustes) / count($prixAjustes);
        $min = min($prixAjustes);
        $max = max($prixAjustes);

        // Arrondir aux 5000$ près
        $prixSuggere = round($moyenne / 5000) * 5000;
        $fourchetteBasse = round($min / 5000) * 5000;
        $fourchetteHaute = round($max / 5000) * 5000;

        $nbComparables = count($comparables);
        $commentaire = "Basé sur $nbComparables comparables analysés. ";
        $commentaire .= "Prix ajustés varient de " . number_format($min, 0, ',', ' ') . "$ à " . number_format($max, 0, ',', ' ') . "$.";

        return [
            'prix_suggere' => $prixSuggere,
            'fourchette_basse' => $fourchetteBasse,
            'fourchette_haute' => $fourchetteHaute,
            'commentaire_general' => $commentaire
        ];
    }

    /**
     * Appel API avec support PDF
     */
    private function callApiPdf($payload) {
        $ch = curl_init($this->apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_TIMEOUT, 120); // 2 minutes timeout
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'x-api-key: ' . $this->apiKey,
            'anthropic-version: 2023-06-01',
            'content-type: application/json',
            'anthropic-beta: pdfs-2024-09-25'
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

        // Extraire le JSON de la réponse
        if (preg_match('/\[[\s\S]*\]/', $content, $matchesArray)) {
            $json = json_decode($matchesArray[0], true);
            if ($json !== null) return $json;
        }
        if (preg_match('/\{[\s\S]*\}/', $content, $matchesObj)) {
            $json = json_decode($matchesObj[0], true);
            if ($json !== null) return $json;
        }

        throw new Exception("Réponse invalide de l'IA (pas de JSON trouvé): " . substr($content, 0, 200));
    }

    /**
     * Analyse une image de facture et extrait les informations
     * @param string $imageData Base64 encoded image data
     * @param string $mimeType Type MIME de l'image (image/png, image/jpeg, etc.)
     * @param array $fournisseurs Liste des fournisseurs connus
     * @param array $categories Liste des catégories disponibles
     * @return array Données extraites de la facture
     */
    public function analyserFacture($imageData, $mimeType, $fournisseurs = [], $categories = []) {
        $systemPrompt = "Tu es un assistant expert en extraction de données de factures pour des projets de rénovation immobilière (Flip) au Québec. " .
                       "Tu dois analyser l'image de facture fournie et extraire les informations clés. " .
                       "Sois précis et utilise les valeurs exactes visibles sur la facture. " .
                       "Réponds UNIQUEMENT en JSON valide, sans texte autour.";

        $fournisseursListe = !empty($fournisseurs) ? implode(', ', $fournisseurs) : 'Réno Dépot, Rona, BMR, Patrick Morin, Home Depot, Canac, IKEA';

        $categoriesInfo = '';
        if (!empty($categories)) {
            $categoriesInfo = "Catégories disponibles (utilise l'id): \n";
            foreach ($categories as $cat) {
                $categoriesInfo .= "- id: {$cat['id']}, nom: {$cat['nom']}\n";
            }
        }

        $userMessage = "Analyse cette image de facture et extrais les informations suivantes.\n\n" .
                      "Fournisseurs connus: {$fournisseursListe}\n\n" .
                      "{$categoriesInfo}\n" .
                      "IMPORTANT: \n" .
                      "- Les taxes au Québec sont TPS (5%) et TVQ (9.975%)\n" .
                      "- Le montant_avant_taxes est le sous-total AVANT taxes\n" .
                      "- Si tu vois un total TTC, calcule le montant avant taxes\n" .
                      "- La date doit être au format YYYY-MM-DD\n" .
                      "- Si le fournisseur n'est pas dans la liste connue, utilise le nom exact visible\n" .
                      "- Pour la catégorie, choisis la plus appropriée basée sur les articles achetés\n\n" .
                      "Format JSON attendu:\n" .
                      "{\n" .
                      "  \"fournisseur\": \"Nom du fournisseur\",\n" .
                      "  \"date_facture\": \"YYYY-MM-DD\",\n" .
                      "  \"description\": \"Description courte des achats\",\n" .
                      "  \"montant_avant_taxes\": 123.45,\n" .
                      "  \"tps\": 6.17,\n" .
                      "  \"tvq\": 12.32,\n" .
                      "  \"montant_total\": 141.94,\n" .
                      "  \"categorie_id\": 5,\n" .
                      "  \"categorie_suggestion\": \"Nom de la catégorie suggérée\",\n" .
                      "  \"notes\": \"Informations supplémentaires utiles\",\n" .
                      "  \"confiance\": 0.95\n" .
                      "}";

        $payload = [
            'model' => $this->model,
            'max_tokens' => 2048,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'image',
                            'source' => [
                                'type' => 'base64',
                                'media_type' => $mimeType,
                                'data' => $imageData
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

        return $this->callApiFacture($payload);
    }

    private function callApiFacture($payload) {
        $ch = curl_init($this->apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'x-api-key: ' . $this->apiKey,
            'anthropic-version: 2023-06-01',
            'content-type: application/json'
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

        // Extraire le JSON de la réponse
        if (preg_match('/\{[\s\S]*\}/', $content, $matches)) {
            $json = json_decode($matches[0], true);
            if ($json) return $json;
        }

        throw new Exception("Réponse invalide de l'IA (pas de JSON trouvé).");
    }

    /**
     * Extrait le prix d'un produit depuis le contenu HTML d'une page web
     * @param string $html Contenu HTML de la page
     * @param string $url URL de la page (pour contexte)
     * @return array ['success' => bool, 'price' => float|null, 'message' => string]
     */
    public function extractPriceFromHtml($html, $url) {
        if (empty($this->apiKey)) {
            return ['success' => false, 'price' => null, 'message' => 'Clé API Claude non configurée'];
        }

        // Limiter le HTML pour ne pas dépasser les limites de l'API
        // Extraire seulement les parties pertinentes (body, scripts JSON-LD, meta tags)
        $relevantHtml = $this->extractRelevantHtml($html);

        $systemPrompt = "Tu es un assistant spécialisé dans l'extraction de prix de produits depuis des pages web de magasins. " .
                       "Tu dois analyser le HTML fourni et trouver le prix de vente actuel du produit. " .
                       "Ignore les prix barrés (anciens prix) et trouve le prix actuel en dollars canadiens. " .
                       "Réponds UNIQUEMENT en JSON valide avec cette structure: {\"price\": 123.45, \"found\": true} ou {\"price\": null, \"found\": false, \"reason\": \"explication\"}";

        $payload = [
            'model' => $this->model,
            'max_tokens' => 200,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => "Extrait le prix du produit de cette page web.\n\nURL: {$url}\n\nHTML (extrait):\n{$relevantHtml}"
                ]
            ],
            'system' => $systemPrompt
        ];

        try {
            $result = $this->callApiSimple($payload);
            if (isset($result['found']) && $result['found'] && isset($result['price'])) {
                return ['success' => true, 'price' => (float)$result['price'], 'message' => 'Prix trouvé par IA'];
            }
            return ['success' => false, 'price' => null, 'message' => $result['reason'] ?? 'Prix non trouvé'];
        } catch (Exception $e) {
            return ['success' => false, 'price' => null, 'message' => $e->getMessage()];
        }
    }

    /**
     * Extrait le prix d'un produit depuis une capture d'écran (Vision)
     * @param string $imageData Image en base64 (sans le préfixe data:image/...)
     * @param string $mimeType Type MIME de l'image (image/png, image/jpeg, etc.)
     * @return array
     */
    public function extractPriceFromImage($imageData, $mimeType = 'image/png') {
        if (empty($this->apiKey)) {
            return ['success' => false, 'price' => null, 'message' => 'Clé API Claude non configurée'];
        }

        $systemPrompt = "Tu es un assistant spécialisé dans l'extraction de prix de produits depuis des captures d'écran de sites de magasins. " .
                       "Tu dois analyser l'image fournie et trouver le prix de vente actuel du produit. " .
                       "Ignore les prix barrés (anciens prix) et trouve le prix actuel. " .
                       "Les prix sont généralement en dollars canadiens (CAD/$). " .
                       "Réponds UNIQUEMENT en JSON valide avec cette structure: {\"price\": 123.45, \"found\": true} ou {\"price\": null, \"found\": false, \"reason\": \"explication\"}";

        $payload = [
            'model' => $this->model,
            'max_tokens' => 200,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'image',
                            'source' => [
                                'type' => 'base64',
                                'media_type' => $mimeType,
                                'data' => $imageData
                            ]
                        ],
                        [
                            'type' => 'text',
                            'text' => "Analyse cette capture d'écran d'une page produit et extrait le prix de vente actuel. Réponds en JSON."
                        ]
                    ]
                ]
            ],
            'system' => $systemPrompt
        ];

        try {
            $result = $this->callApiSimple($payload);
            if (isset($result['found']) && $result['found'] && isset($result['price'])) {
                return ['success' => true, 'price' => (float)$result['price'], 'message' => 'Prix trouvé par analyse d\'image'];
            }
            return ['success' => false, 'price' => null, 'message' => $result['reason'] ?? 'Prix non trouvé dans l\'image'];
        } catch (Exception $e) {
            return ['success' => false, 'price' => null, 'message' => $e->getMessage()];
        }
    }

    /**
     * Extrait les parties pertinentes du HTML pour l'analyse de prix
     */
    private function extractRelevantHtml($html) {
        $relevant = '';

        // Extraire les JSON-LD (structured data)
        if (preg_match_all('/<script[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $matches)) {
            $relevant .= "=== JSON-LD Data ===\n" . implode("\n", $matches[1]) . "\n\n";
        }

        // Extraire les meta tags de prix
        if (preg_match_all('/<meta[^>]*(price|amount|product)[^>]*>/i', $html, $matches)) {
            $relevant .= "=== Meta Tags ===\n" . implode("\n", $matches[0]) . "\n\n";
        }

        // Extraire les éléments avec classes de prix courantes
        $pricePatterns = [
            '/<[^>]*(class|id)=["\'][^"\']*price[^"\']*["\'][^>]*>.*?<\/[^>]+>/is',
            '/<[^>]*(class|id)=["\'][^"\']*prix[^"\']*["\'][^>]*>.*?<\/[^>]+>/is',
            '/<[^>]*data-price[^>]*>/i'
        ];

        foreach ($pricePatterns as $pattern) {
            if (preg_match_all($pattern, $html, $matches)) {
                $relevant .= "=== Price Elements ===\n" . implode("\n", array_slice($matches[0], 0, 10)) . "\n\n";
            }
        }

        // Si on n'a pas trouvé grand chose, prendre un extrait du body
        if (strlen($relevant) < 500) {
            if (preg_match('/<body[^>]*>(.*?)<\/body>/is', $html, $match)) {
                // Nettoyer le HTML
                $body = strip_tags($match[1], '<span><div><p><strong><b>');
                $body = preg_replace('/\s+/', ' ', $body);
                $relevant .= "=== Body Extract ===\n" . substr($body, 0, 3000);
            }
        }

        // Limiter la taille totale
        return substr($relevant, 0, 8000);
    }

    /**
     * Appel API simplifié pour extraction de prix
     */
    private function callApiSimple($payload) {
        $ch = curl_init($this->apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'x-api-key: ' . $this->apiKey,
            'anthropic-version: 2023-06-01',
            'content-type: application/json'
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

        // Extraire le JSON de la réponse
        if (preg_match('/\{[\s\S]*\}/', $content, $matches)) {
            $json = json_decode($matches[0], true);
            if ($json) return $json;
        }

        throw new Exception("Réponse invalide de l'IA");
    }
}
