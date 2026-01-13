<?php
/**
 * Service d'intégration avec Gemini (Google AI)
 * Flip Manager
 */

class GeminiService {
    private $pdo;
    private $apiKey;
    private $model;
    private $apiBaseUrl = 'https://generativelanguage.googleapis.com/v1beta/models/';

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
            // La table sera créée par ClaudeService ou la page de config
            return;
        }

        // S'assurer que les clés Gemini existent (migration)
        $this->ensureGeminiConfig();

        $this->apiKey = $this->getConfiguration('GEMINI_API_KEY');
        $this->model = $this->getConfiguration('GEMINI_MODEL') ?: 'gemini-2.5-flash-preview-05-20';
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
     * S'assure que les clés Gemini existent (migration pour bases existantes)
     */
    private function ensureGeminiConfig() {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM app_configurations WHERE cle = 'GEMINI_API_KEY'");
        $stmt->execute();
        if ($stmt->fetchColumn() == 0) {
            $stmt = $this->pdo->prepare("
                INSERT IGNORE INTO app_configurations (cle, valeur, description, est_sensible)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute(['GEMINI_API_KEY', '', 'Clé API Google Gemini', 1]);
            $stmt->execute(['GEMINI_MODEL', 'gemini-2.5-flash-preview-05-20', 'Modèle Gemini (gemini-2.5-flash-preview-05-20)', 0]);
        }
    }

    /**
     * Vérifie si le service est configuré
     */
    public function isConfigured() {
        return !empty($this->apiKey);
    }

    /**
     * Retourne le modèle configuré
     */
    public function getModel() {
        return $this->model;
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
        $fournisseursListe = !empty($fournisseurs) ? implode(', ', $fournisseurs) : 'Réno Dépot, Rona, BMR, Patrick Morin, Home Depot, Canac, IKEA';

        $categoriesInfo = '';
        if (!empty($categories)) {
            $categoriesInfo = "Catégories disponibles (utilise l'id): \n";
            foreach ($categories as $cat) {
                $categoriesInfo .= "- id: {$cat['id']}, nom: {$cat['nom']}\n";
            }
        }

        $prompt = "Tu es un assistant expert en extraction de données de factures pour des projets de rénovation immobilière (Flip) au Québec. " .
                 "Analyse cette image de facture et extrait les informations suivantes.\n\n" .
                 "Fournisseurs connus: {$fournisseursListe}\n\n" .
                 "{$categoriesInfo}\n" .
                 "IMPORTANT: \n" .
                 "- Les taxes au Québec sont TPS (5%) et TVQ (9.975%)\n" .
                 "- Le montant_avant_taxes est le sous-total AVANT taxes\n" .
                 "- Si tu vois un total TTC, calcule le montant avant taxes\n" .
                 "- La date doit être au format YYYY-MM-DD\n" .
                 "- Si le fournisseur n'est pas dans la liste connue, utilise le nom exact visible\n" .
                 "- Pour la catégorie, choisis la plus appropriée basée sur les articles achetés\n\n" .
                 "Réponds UNIQUEMENT en JSON valide avec ce format:\n" .
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

        return $this->callApiWithImage($prompt, $imageData, $mimeType);
    }

    /**
     * Analyse détaillée d'une facture avec breakdown par étape de construction
     * @param string $imageData Image en base64
     * @param string $mimeType Type MIME de l'image
     * @param array $etapes Liste des étapes de construction disponibles
     * @param string|null $customPrompt Prompt personnalisé (optionnel)
     * @return array Détails des lignes avec étapes assignées
     */
    public function analyserFactureDetails($imageData, $mimeType, $etapes = [], $customPrompt = null) {
        // Construire la liste des étapes
        $etapesListe = "";
        if (!empty($etapes)) {
            foreach ($etapes as $idx => $etape) {
                $etapesListe .= "- id: {$etape['id']}, nom: {$etape['nom']}\n";
            }
        } else {
            // Étapes par défaut si non fournies
            $etapesListe = "- id: 1, nom: Démolition\n" .
                          "- id: 2, nom: Structure/Charpente\n" .
                          "- id: 3, nom: Plomberie\n" .
                          "- id: 4, nom: Électricité\n" .
                          "- id: 5, nom: Isolation\n" .
                          "- id: 6, nom: Gypse/Plâtre\n" .
                          "- id: 7, nom: Finition intérieure\n" .
                          "- id: 8, nom: Peinture\n" .
                          "- id: 9, nom: Revêtement extérieur\n" .
                          "- id: 10, nom: Toiture\n" .
                          "- id: 11, nom: Planchers\n" .
                          "- id: 12, nom: Cuisine\n" .
                          "- id: 13, nom: Salle de bain\n" .
                          "- id: 14, nom: Portes et fenêtres\n" .
                          "- id: 15, nom: Autre\n";
        }

        // Utiliser le prompt personnalisé si fourni
        if (!empty($customPrompt)) {
            $prompt = $customPrompt;
        } else {
            $prompt = "Tu es un expert en construction et rénovation au Québec. " .
                      "Tu analyses des factures de quincaillerie (Home Depot, Réno Dépot, BMR, etc.) " .
                      "et tu catégorises chaque article par étape de construction.\n\n" .
                      "Analyse cette facture de quincaillerie et catégorise CHAQUE LIGNE par étape de construction.\n\n" .
                      "FOURNISSEURS CONNUS: Home Depot, Réno Dépot, Rona, BMR, Patrick Morin, Canac, Canadian Tire, IKEA, Lowes.\n" .
                      "IMPORTANT: Identifie le fournisseur depuis le LOGO ou le NOM DE L'ENTREPRISE visible sur la facture.\n\n" .
                      "ÉTAPES DISPONIBLES (utilise EXACTEMENT ces noms et ids):\n{$etapesListe}\n" .
                      "GUIDE DE CATÉGORISATION - associe les articles à l'étape la plus appropriée:\n" .
                      "- Bois (2x4, 2x6, 2x8, etc.), clous charpente, équerres, étriers → étape contenant 'structure' ou 'division'\n" .
                      "- Tuyaux, raccords, valves, robinets, drains → étape contenant 'plomberie'\n" .
                      "- Fils, boîtes électriques, prises, interrupteurs, disjoncteurs → étape contenant 'électricité' ou 'electrique'\n" .
                      "- Laine, styromousse, pare-vapeur, isolant → étape contenant 'isolation'\n" .
                      "- Gypse, vis gypse, composé, ruban → étape contenant 'gypse'\n" .
                      "- Moulures, trim, quincaillerie décorative → étape contenant 'finition'\n" .
                      "- Peinture, primer, rouleaux, pinceaux, latex → étape contenant 'peinture' ou 'latex'\n" .
                      "- Plancher, céramique, tuile, sous-couche → étape contenant 'plancher' ou 'ceramique'\n" .
                      "- Armoires, comptoirs, éviers cuisine, vanités → étape contenant 'cuisine' ou 'vanité' ou 'ébénisterie'\n" .
                      "- Portes, fenêtres, cadres → étape contenant 'porte' ou 'fenêtre'\n" .
                      "- Escalier, marches, rampe → étape contenant 'escalier'\n" .
                      "- Extérieur, revêtement, bardeau → étape contenant 'extérieur'\n\n" .
                      "IMPORTANT: Tu DOIS utiliser les noms d'étapes EXACTEMENT comme fournis ci-dessus. Ne jamais inventer de nouvelles étapes.\n\n" .
                      "Réponds UNIQUEMENT en JSON valide avec ce format:\n" .
                      "{\n" .
                      "  \"fournisseur\": \"Nom visible sur facture\",\n" .
                      "  \"date_facture\": \"YYYY-MM-DD\",\n" .
                      "  \"lignes\": [\n" .
                      "    {\n" .
                      "      \"description\": \"Description de l'article\",\n" .
                      "      \"quantite\": 1,\n" .
                      "      \"prix_unitaire\": 10.00,\n" .
                      "      \"total\": 10.00,\n" .
                      "      \"etape_id\": 4,\n" .
                      "      \"etape_nom\": \"Structures et division\",\n" .
                      "      \"raison\": \"Bois de construction\"\n" .
                      "    }\n" .
                      "  ],\n" .
                      "  \"totaux_par_etape\": [\n" .
                      "    {\"etape_id\": 4, \"etape_nom\": \"Structures et division\", \"montant\": 150.00}\n" .
                      "  ],\n" .
                      "  \"sous_total\": 500.00,\n" .
                      "  \"tps\": 25.00,\n" .
                      "  \"tvq\": 49.88,\n" .
                      "  \"total\": 574.88\n" .
                      "}\n\n" .
                      "CRITIQUE: Utilise UNIQUEMENT les étapes listées ci-dessus avec leurs IDs exacts. Choisis l'étape la plus proche même si pas parfaite.";
        }

        return $this->callApiWithImage($prompt, $imageData, $mimeType, 4096);
    }

    /**
     * Extrait le prix d'un produit depuis le contenu HTML d'une page web
     * @param string $html Contenu HTML de la page
     * @param string $url URL de la page (pour contexte)
     * @return array ['success' => bool, 'price' => float|null, 'message' => string]
     */
    public function extractPriceFromHtml($html, $url) {
        if (empty($this->apiKey)) {
            return ['success' => false, 'price' => null, 'message' => 'Clé API Gemini non configurée'];
        }

        // Limiter le HTML pour ne pas dépasser les limites de l'API
        $relevantHtml = $this->extractRelevantHtml($html);

        $prompt = "Tu es un assistant spécialisé dans l'extraction de prix de produits depuis des pages web de magasins. " .
                 "Tu dois analyser le HTML fourni et trouver le prix de vente actuel du produit. " .
                 "Ignore les prix barrés (anciens prix) et trouve le prix actuel en dollars canadiens.\n\n" .
                 "URL: {$url}\n\n" .
                 "HTML (extrait):\n{$relevantHtml}\n\n" .
                 "Réponds UNIQUEMENT en JSON valide avec cette structure: {\"price\": 123.45, \"found\": true} ou {\"price\": null, \"found\": false, \"reason\": \"explication\"}";

        try {
            $result = $this->callApiText($prompt, 200);
            if (isset($result['found']) && $result['found'] && isset($result['price'])) {
                return ['success' => true, 'price' => (float)$result['price'], 'message' => 'Prix trouvé par IA Gemini'];
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
            return ['success' => false, 'price' => null, 'message' => 'Clé API Gemini non configurée'];
        }

        $prompt = "Tu es un assistant spécialisé dans l'extraction de prix de produits depuis des captures d'écran de sites de magasins. " .
                 "Tu dois analyser l'image fournie et trouver le prix de vente actuel du produit. " .
                 "Ignore les prix barrés (anciens prix) et trouve le prix actuel. " .
                 "Les prix sont généralement en dollars canadiens (CAD/$). " .
                 "Réponds UNIQUEMENT en JSON valide avec cette structure: {\"price\": 123.45, \"found\": true} ou {\"price\": null, \"found\": false, \"reason\": \"explication\"}";

        try {
            $result = $this->callApiWithImage($prompt, $imageData, $mimeType, 200);
            if (isset($result['found']) && $result['found'] && isset($result['price'])) {
                return ['success' => true, 'price' => (float)$result['price'], 'message' => 'Prix trouvé par analyse d\'image Gemini'];
            }
            return ['success' => false, 'price' => null, 'message' => $result['reason'] ?? 'Prix non trouvé dans l\'image'];
        } catch (Exception $e) {
            return ['success' => false, 'price' => null, 'message' => $e->getMessage()];
        }
    }

    /**
     * Extrait les données structurées d'un chunk de texte PDF avec l'IA
     * @param string $rawText Texte brut extrait du PDF
     * @return array Données structurées extraites
     */
    public function extractChunkDataWithAI($rawText) {
        $prompt = "Tu es un assistant spécialisé dans l'extraction de données de fiches immobilières Centris (Québec). " .
                 "Tu dois extraire TOUTES les informations disponibles du texte fourni avec précision. " .
                 "Si une donnée n'est pas présente, utilise null. " .
                 "ATTENTION: Superficie TERRAIN (grand, ex: 4000+ pc) ≠ Superficie HABITABLE (petit, ex: 800-2000 pc).\n\n" .
                 "Extrait les données de cette fiche Centris:\n\n" .
                 "=== TEXTE DE LA FICHE ===\n" .
                 substr($rawText, 0, 12000) . "\n\n" .
                 "=== CHAMPS À EXTRAIRE ===\n" .
                 "Retourne un JSON avec ces champs (null si non trouvé):\n\n" .
                 "{\n" .
                 "  \"adresse\": \"Numéro + nom de rue complet\",\n" .
                 "  \"ville\": \"Nom de la ville\",\n" .
                 "  \"prix_vendu\": 0,\n" .
                 "  \"date_vente\": \"YYYY-MM-DD (cherche Date PA acceptée ou Signature acte de vente)\",\n" .
                 "  \"annee_construction\": 0,\n" .
                 "  \"type_propriete\": \"Genre de propriété (Maison de plain-pied, Cottage, etc.)\",\n" .
                 "  \"type_batiment\": \"Isolé, Jumelé, etc.\",\n" .
                 "  \"chambres\": \"Nombre de chambres (Nbre chambres, PAS Nbre pièces)\",\n" .
                 "  \"sdb\": \"Nombre de salles de bain (format: 2+1 si applicable)\",\n" .
                 "  \"nb_pieces\": \"Nombre total de pièces\",\n" .
                 "  \"superficie_terrain\": \"TERRAIN (lot). Typiquement 3000-10000+ pc.\",\n" .
                 "  \"superficie_habitable\": \"BÂTIMENT (maison). Typiquement 800-2500 pc. TOUJOURS PLUS PETIT que le terrain!\",\n" .
                 "  \"eval_terrain\": 0,\n" .
                 "  \"eval_batiment\": 0,\n" .
                 "  \"eval_total\": 0,\n" .
                 "  \"taxe_municipale\": 0,\n" .
                 "  \"taxe_scolaire\": 0,\n" .
                 "  \"garage\": \"Attaché, Détaché, Simple, Double, etc.\",\n" .
                 "  \"piscine\": \"Type de piscine ou null\",\n" .
                 "  \"sous_sol\": \"Type de sous-sol\",\n" .
                 "  \"chauffage\": \"Mode de chauffage\",\n" .
                 "  \"renovations_texte\": \"Liste des rénovations avec années et coûts\"\n" .
                 "}\n\n" .
                 "Réponds UNIQUEMENT en JSON valide, sans texte autour.";

        try {
            $result = $this->callApiText($prompt, 2048, 0);

            // Normaliser les valeurs numériques
            $numericFields = ['prix_vendu', 'annee_construction', 'eval_terrain', 'eval_batiment',
                             'eval_total', 'taxe_municipale', 'taxe_scolaire', 'stationnement', 'renovations_total'];
            foreach ($numericFields as $field) {
                if (isset($result[$field])) {
                    $result[$field] = (int) preg_replace('/[^\d]/', '', (string)$result[$field]);
                }
            }

            return $result;
        } catch (Exception $e) {
            error_log("Gemini AI extraction error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Appel API avec image (Vision)
     */
    private function callApiWithImage($prompt, $imageData, $mimeType, $maxTokens = 2048, $temperature = null) {
        $url = $this->apiBaseUrl . $this->model . ':generateContent?key=' . $this->apiKey;

        $payload = [
            'contents' => [
                [
                    'parts' => [
                        [
                            'inline_data' => [
                                'mime_type' => $mimeType,
                                'data' => $imageData
                            ]
                        ],
                        [
                            'text' => $prompt
                        ]
                    ]
                ]
            ],
            'generationConfig' => [
                'maxOutputTokens' => $maxTokens,
                'responseMimeType' => 'application/json'
            ]
        ];

        if ($temperature !== null) {
            $payload['generationConfig']['temperature'] = $temperature;
        }

        return $this->executeRequest($url, $payload);
    }

    /**
     * Appel API texte seulement
     */
    private function callApiText($prompt, $maxTokens = 2048, $temperature = null) {
        $url = $this->apiBaseUrl . $this->model . ':generateContent?key=' . $this->apiKey;

        $payload = [
            'contents' => [
                [
                    'parts' => [
                        [
                            'text' => $prompt
                        ]
                    ]
                ]
            ],
            'generationConfig' => [
                'maxOutputTokens' => $maxTokens,
                'responseMimeType' => 'application/json'
            ]
        ];

        if ($temperature !== null) {
            $payload['generationConfig']['temperature'] = $temperature;
        }

        return $this->executeRequest($url, $payload);
    }

    /**
     * Exécute la requête HTTP vers l'API Gemini
     */
    private function executeRequest($url, $payload) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json'
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
            throw new Exception('Erreur API Gemini (' . $httpCode . '): ' . $errorMessage);
        }

        $data = json_decode($response, true);

        // Extraire le contenu de la réponse Gemini
        $content = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

        if (empty($content)) {
            throw new Exception("Réponse vide de l'IA Gemini.");
        }

        // Extraire le JSON de la réponse
        if (preg_match('/\{[\s\S]*\}/', $content, $matches)) {
            $json = json_decode($matches[0], true);
            if ($json) return $json;
        }

        // Si pas de JSON trouvé, retourner le contenu brut
        throw new Exception("Réponse invalide de l'IA Gemini (pas de JSON trouvé).");
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
}
