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

    /**
     * Extrait les données structurées d'un chunk de texte PDF avec l'IA
     * Étape 1: Extraction des champs (remplace le parsing regex)
     * @param string $rawText Texte brut extrait du PDF
     * @return array Données structurées extraites
     */
    public function extractChunkDataWithAI($rawText) {
        $systemPrompt = "Tu es un assistant spécialisé dans l'extraction de données de fiches immobilières Centris (Québec). " .
                       "Tu dois extraire TOUTES les informations disponibles du texte fourni avec précision. " .
                       "Si une donnée n'est pas présente, utilise null. " .
                       "ATTENTION: Superficie TERRAIN (grand, ex: 4000+ pc) ≠ Superficie HABITABLE (petit, ex: 800-2000 pc). " .
                       "Réponds UNIQUEMENT en JSON valide, sans texte autour.";

        $userMessage = "Extrait les données de cette fiche Centris:\n\n" .
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
                      "  \"superficie_terrain\": \"TERRAIN (lot). Cherche 'Superficie du terrain' ou 'Dimensions du terrain'. Si dimensions (ex: 47 X 92 p), calcule 47*92=4324 pc. Typiquement 3000-10000+ pc.\",\n" .
                      "  \"superficie_habitable\": \"BÂTIMENT (maison). Cherche 'Superficie habitable' ou 'Dimensions du bâtiment'. Si dimensions (ex: 24 X 34 p), calcule 24*34=816 pc. Typiquement 800-2500 pc. TOUJOURS PLUS PETIT que le terrain!\",\n" .
                      "  \"dimensions_terrain\": \"Format original du terrain (ex: 47 X 92 p)\",\n" .
                      "  \"dimensions_batiment\": \"Format original du bâtiment (ex: 24 X 34 p)\",\n" .
                      "  \"eval_terrain\": 0,\n" .
                      "  \"eval_batiment\": 0,\n" .
                      "  \"eval_total\": 0,\n" .
                      "  \"taxe_municipale\": 0,\n" .
                      "  \"taxe_scolaire\": 0,\n" .
                      "  \"taxe_annee\": \"YYYY\",\n" .
                      "  \"fondation\": \"Type de fondation\",\n" .
                      "  \"toiture\": \"Revêtement de la toiture\",\n" .
                      "  \"revetement\": \"Revêtement extérieur\",\n" .
                      "  \"garage\": \"Attaché, Détaché, Simple, Double, etc.\",\n" .
                      "  \"stationnement\": 0,\n" .
                      "  \"piscine\": \"Type de piscine ou null\",\n" .
                      "  \"sous_sol\": \"Type de sous-sol\",\n" .
                      "  \"chauffage\": \"Mode de chauffage\",\n" .
                      "  \"energie\": \"Source d'énergie\",\n" .
                      "  \"renovations_total\": 0,\n" .
                      "  \"renovations_texte\": \"Liste des rénovations avec années et coûts\",\n" .
                      "  \"proximites\": \"Proximités mentionnées\",\n" .
                      "  \"inclusions\": \"Inclusions\",\n" .
                      "  \"exclusions\": \"Exclusions\",\n" .
                      "  \"remarques\": \"Remarques importantes\"\n" .
                      "}\n\n" .
                      "RÈGLES IMPORTANTES:\n" .
                      "- Les prix et évaluations sont des nombres entiers sans espaces ni $\n" .
                      "- Les superficies doivent inclure l'unité (ex: \"4324 pc\" ou \"816 pc\")\n" .
                      "- TERRAIN: 'Superficie du terrain' ou 'Dimensions du terrain' (47 X 92 = grand lot)\n" .
                      "- BÂTIMENT: 'Superficie habitable' ou 'Dimensions du bâtiment' (24 X 34 = maison)\n" .
                      "- Le bâtiment est TOUJOURS plus petit que le terrain!\n" .
                      "- Cherche 'Nbre chambres' pas 'Nbre pièces' pour les chambres";

        $payload = [
            'model' => $this->model,
            'max_tokens' => 2048,
            'temperature' => 0, // Résultats consistants
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $userMessage
                ]
            ],
            'system' => $systemPrompt
        ];

        try {
            $result = $this->callApiSimple($payload);

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
            error_log("AI extraction error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Analyse approfondie d'un chunk basée sur les données texte extraites
     * @param array $chunkData Toutes les données extraites du chunk
     * @param array $projetInfo Infos du projet sujet pour comparaison
     * @return array Analyse complète avec note et ajustement
     */
    public function analyzeChunkText($chunkData, $projetInfo) {
        $systemPrompt = "Tu es un expert en évaluation immobilière au Québec spécialisé dans les flips immobiliers. " .
                       "Tu analyses les données d'une propriété VENDUE (comparable) pour évaluer son état et calculer un ajustement par rapport au projet sujet. " .
                       "Tu dois être PRÉCIS et CRITIQUE. Chaque élément (rénovations, caractéristiques, âge) doit influencer ton ajustement. " .
                       "Réponds UNIQUEMENT en JSON valide.";

        // Construire le message détaillé avec toutes les données extraites
        $comparable = "=== PROPRIÉTÉ COMPARABLE (VENDUE) ===\n";
        $comparable .= "No Centris: " . ($chunkData['no_centris'] ?? 'N/A') . "\n";
        $comparable .= "Adresse: " . ($chunkData['adresse'] ?? 'N/A') . "\n";
        $comparable .= "Ville: " . ($chunkData['ville'] ?? 'N/A') . "\n";
        $comparable .= "Prix vendu: " . number_format((float)($chunkData['prix_vendu'] ?? 0), 0, ',', ' ') . " $\n";
        $comparable .= "Date de vente: " . ($chunkData['date_vente'] ?? 'N/A') . "\n";
        $comparable .= "Jours sur le marché: " . ($chunkData['jours_marche'] ?? 'N/A') . "\n\n";

        $comparable .= "--- Caractéristiques ---\n";
        $comparable .= "Type: " . ($chunkData['type_propriete'] ?? 'N/A') . "\n";
        $comparable .= "Année construction: " . ($chunkData['annee_construction'] ?? 'N/A') . "\n";
        $comparable .= "Chambres: " . ($chunkData['chambres'] ?? 'N/A') . "\n";
        $comparable .= "Salles de bain: " . ($chunkData['sdb'] ?? 'N/A') . "\n";
        $comparable .= "Superficie terrain: " . ($chunkData['superficie_terrain'] ?? 'N/A') . "\n";
        $comparable .= "Superficie bâtiment: " . ($chunkData['superficie_batiment'] ?? 'N/A') . "\n\n";

        $comparable .= "--- Évaluation Municipale ---\n";
        $comparable .= "Terrain: " . ($chunkData['eval_terrain'] ?? 'N/A') . "\n";
        $comparable .= "Bâtiment: " . ($chunkData['eval_batiment'] ?? 'N/A') . "\n";
        $comparable .= "Total: " . ($chunkData['eval_total'] ?? 'N/A') . "\n\n";

        $comparable .= "--- Taxes ---\n";
        $comparable .= "Municipale: " . ($chunkData['taxe_municipale'] ?? 'N/A') . "\n";
        $comparable .= "Scolaire: " . ($chunkData['taxe_scolaire'] ?? 'N/A') . "\n\n";

        $comparable .= "--- Construction & Finitions ---\n";
        $comparable .= "Fondation: " . ($chunkData['fondation'] ?? 'N/A') . "\n";
        $comparable .= "Toiture: " . ($chunkData['toiture'] ?? 'N/A') . "\n";
        $comparable .= "Revêtement: " . ($chunkData['revetement'] ?? 'N/A') . "\n";
        $comparable .= "Garage: " . ($chunkData['garage'] ?? 'N/A') . "\n";
        $comparable .= "Stationnement: " . ($chunkData['stationnement'] ?? 'N/A') . "\n";
        $comparable .= "Piscine: " . ($chunkData['piscine'] ?? 'N/A') . "\n";
        $comparable .= "Sous-sol: " . ($chunkData['sous_sol'] ?? 'N/A') . "\n";
        $comparable .= "Chauffage: " . ($chunkData['chauffage'] ?? 'N/A') . "\n";
        $comparable .= "Énergie: " . ($chunkData['energie'] ?? 'N/A') . "\n\n";

        $comparable .= "--- Rénovations ---\n";
        $comparable .= "Total rénovations: " . ($chunkData['renovations_total'] ?? 'N/A') . "\n";
        $comparable .= "Détail: " . ($chunkData['renovations_texte'] ?? 'Aucune info') . "\n\n";

        $comparable .= "--- Autres ---\n";
        $comparable .= "Proximités: " . ($chunkData['proximites'] ?? 'N/A') . "\n";
        $comparable .= "Inclusions: " . ($chunkData['inclusions'] ?? 'N/A') . "\n";
        $comparable .= "Exclusions: " . ($chunkData['exclusions'] ?? 'N/A') . "\n";
        $comparable .= "Remarques: " . ($chunkData['remarques'] ?? 'N/A') . "\n";

        // Construire les infos du projet sujet
        $sujet = "=== PROJET SUJET (MA PROPRIÉTÉ À VENDRE) ===\n";
        $sujet .= "Adresse: " . ($projetInfo['adresse'] ?? 'N/A') . "\n";
        $sujet .= "Ville: " . ($projetInfo['ville'] ?? 'N/A') . "\n";
        $sujet .= "Type: " . ($projetInfo['type'] ?? 'Maison unifamiliale') . "\n";
        $sujet .= "Chambres: " . ($projetInfo['chambres'] ?? 'N/A') . "\n";
        $sujet .= "Salles de bain: " . ($projetInfo['sdb'] ?? 'N/A') . "\n";
        $sujet .= "Superficie: " . ($projetInfo['superficie'] ?? 'N/A') . "\n";
        $sujet .= "Garage: " . ($projetInfo['garage'] ?? 'Non') . "\n";
        $sujet .= "État prévu: ENTIÈREMENT RÉNOVÉ au goût du jour\n";
        $sujet .= "- Cuisine: moderne avec comptoirs quartz, armoires neuves\n";
        $sujet .= "- Salles de bain: rénovées modernes\n";
        $sujet .= "- Planchers: neufs (bois franc ou vinyle de luxe)\n";
        $sujet .= "- Peinture: fraîche partout\n";
        $sujet .= "- Électricité: mise aux normes si nécessaire\n";
        $sujet .= "- Plomberie: fonctionnelle et mise à jour\n";

        $userMessage = $comparable . "\n" . $sujet . "\n\n";
        $userMessage .= "=== ANALYSE DEMANDÉE ===\n";
        $userMessage .= "1. Évalue l'ÉTAT GÉNÉRAL du comparable sur 10:\n";
        $userMessage .= "   - 1-3: Délabré, à rénover complètement\n";
        $userMessage .= "   - 4-5: Correct mais daté, rénovations partielles nécessaires\n";
        $userMessage .= "   - 6-7: Bon état, quelques mises à jour récentes\n";
        $userMessage .= "   - 8-9: Bien rénové, au goût du jour\n";
        $userMessage .= "   - 10: Luxueux, finitions haut de gamme\n\n";
        $userMessage .= "2. Calcule l'AJUSTEMENT en $ pour ramener ce comparable au niveau du sujet:\n";
        $userMessage .= "   - Si comparable MIEUX rénové/équipé → ajustement NÉGATIF (ex: -25000)\n";
        $userMessage .= "   - Si comparable MOINS rénové/équipé → ajustement POSITIF (ex: +35000)\n\n";
        $userMessage .= "3. Facteurs à considérer pour l'ajustement:\n";
        $userMessage .= "   - Différence d'état des rénovations (cuisine, SDB, planchers)\n";
        $userMessage .= "   - Âge du bâtiment et état structurel\n";
        $userMessage .= "   - Présence/absence de garage, piscine\n";
        $userMessage .= "   - Superficie terrain et bâtiment\n";
        $userMessage .= "   - Type de chauffage/énergie\n";
        $userMessage .= "   - Sous-sol fini ou non\n\n";
        $userMessage .= "4. Donne un POURCENTAGE DE CONFIANCE (0-100%) basé sur:\n";
        $userMessage .= "   - Qualité des données disponibles\n";
        $userMessage .= "   - Similarité avec le sujet (localisation, type, taille)\n";
        $userMessage .= "   - Pertinence du comparable pour l'estimation\n\n";
        $userMessage .= "Format JSON attendu:\n";
        $userMessage .= "{\n";
        $userMessage .= "  \"etat_note\": 7,\n";
        $userMessage .= "  \"etat_analyse\": \"Description détaillée de l'état du comparable...\",\n";
        $userMessage .= "  \"ajustement\": 15000,\n";
        $userMessage .= "  \"ajustement_details\": {\n";
        $userMessage .= "    \"renovations\": 10000,\n";
        $userMessage .= "    \"caracteristiques\": 5000,\n";
        $userMessage .= "    \"autres\": 0\n";
        $userMessage .= "  },\n";
        $userMessage .= "  \"confiance\": 85,\n";
        $userMessage .= "  \"commentaire_ia\": \"Justification détaillée de l'ajustement...\"\n";
        $userMessage .= "}";

        $payload = [
            'model' => $this->model,
            'max_tokens' => 2048,
            'temperature' => 0, // Résultats consistants
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $userMessage
                ]
            ],
            'system' => $systemPrompt
        ];

        try {
            $result = $this->callApiSimple($payload);
            return [
                'etat_note' => (float)($result['etat_note'] ?? 5),
                'etat_analyse' => $result['etat_analyse'] ?? 'Analyse non disponible',
                'ajustement' => (float)($result['ajustement'] ?? 0),
                'ajustement_details' => $result['ajustement_details'] ?? null,
                'confiance' => (int)($result['confiance'] ?? 50),
                'commentaire_ia' => $result['commentaire_ia'] ?? ''
            ];
        } catch (Exception $e) {
            return [
                'etat_note' => 5,
                'etat_analyse' => 'Erreur lors de l\'analyse',
                'ajustement' => 0,
                'confiance' => 0,
                'commentaire_ia' => 'Erreur: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Analyse les photos d'une propriété (chunk) avec Claude Vision
     * @param array $photos Liste des chemins vers les photos
     * @param array $chunkData Données texte déjà extraites (adresse, prix, etc.)
     * @param array $projetInfo Infos du projet sujet pour comparaison
     * @return array Analyse de l'état et ajustement suggéré
     */
    public function analyzeChunkPhotos($photos, $chunkData, $projetInfo) {
        if (empty($photos)) {
            return [
                'etat_note' => 5,
                'etat_analyse' => 'Aucune photo disponible',
                'ajustement' => 0,
                'commentaire_ia' => 'Analyse basée uniquement sur les données texte.'
            ];
        }

        // Préparer les images pour l'API (max 5 photos pour limiter les coûts)
        $imageContents = [];
        $photosPaths = array_slice($photos, 0, 5);

        foreach ($photosPaths as $photo) {
            $path = is_array($photo) ? $photo['path'] : $photo;
            if (file_exists($path)) {
                $imageData = base64_encode(file_get_contents($path));
                $mimeType = $this->getMimeType($path);
                if ($mimeType) {
                    $imageContents[] = [
                        'type' => 'image',
                        'source' => [
                            'type' => 'base64',
                            'media_type' => $mimeType,
                            'data' => $imageData
                        ]
                    ];
                }
            }
        }

        if (empty($imageContents)) {
            return [
                'etat_note' => 5,
                'etat_analyse' => 'Photos non lisibles',
                'ajustement' => 0,
                'commentaire_ia' => 'Impossible de lire les photos.'
            ];
        }

        $adresse = $chunkData['adresse'] ?? 'Inconnue';
        $prixVendu = $chunkData['prix_vendu'] ?? 0;
        $renovationsTexte = $chunkData['renovations_texte'] ?? '';

        $systemPrompt = "Tu es un expert en évaluation immobilière au Québec spécialisé dans les flips. " .
                       "Tu analyses les photos d'une propriété VENDUE pour évaluer son état de rénovation. " .
                       "Réponds UNIQUEMENT en JSON valide.";

        $userMessage = "PROPRIÉTÉ COMPARABLE: {$adresse}\n" .
                      "Prix vendu: " . number_format($prixVendu, 0, ',', ' ') . " $\n" .
                      "Rénovations mentionnées: {$renovationsTexte}\n\n" .
                      "PROJET SUJET (ma propriété à vendre):\n" .
                      "- Adresse: " . ($projetInfo['adresse'] ?? 'N/A') . "\n" .
                      "- État prévu: Entièrement rénové au goût du jour (cuisine quartz/moderne, SDB modernes, planchers neufs)\n\n" .
                      "Analyse ces photos et retourne:\n" .
                      "- etat_note: Note de 1 à 10 (1=délabré, 5=correct, 8=bien rénové, 10=luxe)\n" .
                      "- etat_analyse: Description de l'état (cuisine, SDB, planchers, finitions)\n" .
                      "- ajustement: Montant +/- $ pour ajuster ce comparable au niveau du sujet\n" .
                      "  - Si comparable MIEUX rénové que sujet: ajustement NÉGATIF (ex: -20000)\n" .
                      "  - Si comparable MOINS rénové que sujet: ajustement POSITIF (ex: +30000)\n" .
                      "- commentaire_ia: Justification de l'ajustement\n\n" .
                      "Format JSON:\n" .
                      "{\"etat_note\": 7, \"etat_analyse\": \"Cuisine moderne avec îlot, SDB rénovées, planchers bois\", \"ajustement\": -10000, \"commentaire_ia\": \"Comparable bien rénové, similaire au sujet\"}";

        // Ajouter le texte à la fin des images
        $imageContents[] = [
            'type' => 'text',
            'text' => $userMessage
        ];

        $payload = [
            'model' => $this->model,
            'max_tokens' => 1024,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $imageContents
                ]
            ],
            'system' => $systemPrompt
        ];

        try {
            return $this->callApiSimple($payload);
        } catch (Exception $e) {
            return [
                'etat_note' => 5,
                'etat_analyse' => 'Erreur d\'analyse',
                'ajustement' => 0,
                'commentaire_ia' => 'Erreur: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Détermine le type MIME d'une image
     */
    private function getMimeType($path) {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mimeTypes = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp'
        ];
        return $mimeTypes[$ext] ?? null;
    }

    /**
     * Consolide les analyses de tous les chunks pour calculer le prix suggéré
     * @param array $chunks Données des chunks avec analyses
     * @param array $projetInfo Infos du projet sujet
     * @return array Analyse globale consolidée
     */
    public function consolidateChunksAnalysis($chunks, $projetInfo) {
        $prixAjustes = [];
        $commentaires = [];

        foreach ($chunks as $chunk) {
            $prixVendu = (float)($chunk['prix_vendu'] ?? 0);
            $ajustement = (float)($chunk['ajustement'] ?? 0);

            if ($prixVendu > 0) {
                $prixAjuste = $prixVendu + $ajustement;
                $prixAjustes[] = $prixAjuste;

                $commentaires[] = sprintf(
                    "%s: %s$ vendu → %s$ ajusté (%s$)",
                    $chunk['adresse'] ?? $chunk['no_centris'],
                    number_format($prixVendu, 0, ',', ' '),
                    number_format($prixAjuste, 0, ',', ' '),
                    ($ajustement >= 0 ? '+' : '') . number_format($ajustement, 0, ',', ' ')
                );
            }
        }

        if (empty($prixAjustes)) {
            return [
                'prix_suggere' => 0,
                'fourchette_basse' => 0,
                'fourchette_haute' => 0,
                'prix_median' => 0,
                'commentaire_general' => 'Aucun comparable valide pour calculer un prix.'
            ];
        }

        // Calculs statistiques
        sort($prixAjustes);
        $count = count($prixAjustes);
        $moyenne = array_sum($prixAjustes) / $count;
        $min = min($prixAjustes);
        $max = max($prixAjustes);

        // Médiane
        if ($count % 2 === 0) {
            $median = ($prixAjustes[$count/2 - 1] + $prixAjustes[$count/2]) / 2;
        } else {
            $median = $prixAjustes[floor($count/2)];
        }

        // Arrondir aux 5000$ près
        $prixSuggere = round($moyenne / 5000) * 5000;
        $fourchetteBasse = round($min / 5000) * 5000;
        $fourchetteHaute = round($max / 5000) * 5000;
        $prixMedian = round($median / 5000) * 5000;

        $commentaire = "Basé sur $count comparables analysés.\n";
        $commentaire .= "Moyenne: " . number_format($moyenne, 0, ',', ' ') . " $\n";
        $commentaire .= "Médiane: " . number_format($median, 0, ',', ' ') . " $\n\n";
        $commentaire .= "Détail:\n" . implode("\n", $commentaires);

        return [
            'prix_suggere' => $prixSuggere,
            'fourchette_basse' => $fourchetteBasse,
            'fourchette_haute' => $fourchetteHaute,
            'prix_median' => $prixMedian,
            'nb_comparables' => $count,
            'commentaire_general' => $commentaire
        ];
    }

    /**
     * Appel API avec support PDF
     */
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

    /**
     * Analyse détaillée d'une facture avec breakdown par étape de construction
     * @param string $imageData Image en base64
     * @param string $mimeType Type MIME de l'image
     * @param array $etapes Liste des étapes de construction disponibles
     * @param string|null $customPrompt Prompt personnalisé (optionnel)
     * @return array Détails des lignes avec étapes assignées
     */
    public function analyserFactureDetails($imageData, $mimeType, $etapes = [], $customPrompt = null) {
        $systemPrompt = "Tu es un expert en construction et rénovation au Québec. " .
                       "Tu analyses des factures de quincaillerie (Home Depot, Réno Dépot, BMR, etc.) " .
                       "et tu catégorises chaque article par étape de construction. " .
                       "Réponds UNIQUEMENT en JSON valide.";

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
            $userMessage = $customPrompt;
        } else {
            $userMessage = "Analyse cette facture de quincaillerie et catégorise CHAQUE LIGNE par étape de construction.\n\n" .
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
                      "Retourne un JSON avec:\n" .
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

        $payload = [
            'model' => $this->model,
            'max_tokens' => 4096,
            'temperature' => 0,
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
