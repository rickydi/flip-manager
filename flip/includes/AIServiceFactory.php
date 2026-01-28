<?php
/**
 * Factory pour les services d'IA
 * Permet de choisir entre Claude (Anthropic) et Gemini (Google)
 * Flip Manager
 */

require_once __DIR__ . '/ClaudeService.php';
require_once __DIR__ . '/GeminiService.php';

class AIServiceFactory {

    const PROVIDER_CLAUDE = 'claude';
    const PROVIDER_GEMINI = 'gemini';

    /**
     * Crée une instance du service IA configuré
     * @param PDO $pdo Connexion à la base de données
     * @param string|null $forceProvider Forcer un provider spécifique (optionnel)
     * @return ClaudeService|GeminiService
     */
    public static function create($pdo, $forceProvider = null) {
        $provider = $forceProvider ?? self::getConfiguredProvider($pdo);

        switch ($provider) {
            case self::PROVIDER_GEMINI:
                return new GeminiService($pdo);
            case self::PROVIDER_CLAUDE:
            default:
                return new ClaudeService($pdo);
        }
    }

    /**
     * Récupère le provider IA configuré dans la base de données
     * @param PDO $pdo
     * @return string
     */
    public static function getConfiguredProvider($pdo) {
        try {
            $stmt = $pdo->prepare("SELECT valeur FROM app_configurations WHERE cle = 'AI_PROVIDER'");
            $stmt->execute();
            $provider = $stmt->fetchColumn();

            if ($provider && in_array($provider, [self::PROVIDER_CLAUDE, self::PROVIDER_GEMINI])) {
                return $provider;
            }
        } catch (Exception $e) {
            // Si erreur, utiliser Claude par défaut
        }

        return self::PROVIDER_CLAUDE;
    }

    /**
     * Retourne la liste des providers disponibles
     * @return array
     */
    public static function getAvailableProviders() {
        return [
            self::PROVIDER_CLAUDE => [
                'name' => 'Claude (Anthropic)',
                'description' => 'IA Anthropic - Excellente pour l\'analyse et le raisonnement',
                'models' => [
                    'claude-sonnet-4-20250514' => 'Claude Sonnet 4 (Recommandé)',
                    'claude-opus-4-5-20251101' => 'Claude Opus 4.5 (Premium)',
                    'claude-haiku-4-5-20251001' => 'Claude Haiku 4.5 (Rapide)',
                ]
            ],
            self::PROVIDER_GEMINI => [
                'name' => 'Gemini (Google)',
                'description' => 'IA Google - Grande fenêtre de contexte (1M tokens)',
                'models' => [
                    'gemini-2.5-flash' => 'Gemini 2.5 Flash (Recommandé)',
                    'gemini-2.5-flash-lite' => 'Gemini 2.5 Flash Lite (Économique)',
                    'gemini-2.0-flash' => 'Gemini 2.0 Flash',
                ]
            ]
        ];
    }

    /**
     * Vérifie si un provider est correctement configuré (clé API présente)
     * @param PDO $pdo
     * @param string $provider
     * @return bool
     */
    public static function isProviderConfigured($pdo, $provider) {
        try {
            $keyName = ($provider === self::PROVIDER_GEMINI) ? 'GEMINI_API_KEY' : 'ANTHROPIC_API_KEY';
            $stmt = $pdo->prepare("SELECT valeur FROM app_configurations WHERE cle = ?");
            $stmt->execute([$keyName]);
            $value = $stmt->fetchColumn();
            return !empty($value);
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Retourne le nom du provider actif
     * @param PDO $pdo
     * @return string
     */
    public static function getActiveProviderName($pdo) {
        $provider = self::getConfiguredProvider($pdo);
        $providers = self::getAvailableProviders();
        return $providers[$provider]['name'] ?? 'Claude (Anthropic)';
    }

    /**
     * S'assure que les configurations IA existent dans la base de données
     * @param PDO $pdo
     */
    public static function ensureConfigurationsExist($pdo) {
        $configs = [
            ['AI_PROVIDER', 'claude', 'Provider IA actif (claude ou gemini)', 0],
            ['ANTHROPIC_API_KEY', '', 'Clé API Claude (Anthropic)', 1],
            ['CLAUDE_MODEL', 'claude-sonnet-4-20250514', 'Modèle Claude', 0],
            ['GEMINI_API_KEY', '', 'Clé API Gemini (Google)', 1],
            ['GEMINI_MODEL', 'gemini-2.5-flash', 'Modèle Gemini', 0],
        ];

        $stmt = $pdo->prepare("
            INSERT IGNORE INTO app_configurations (cle, valeur, description, est_sensible)
            VALUES (?, ?, ?, ?)
        ");

        foreach ($configs as $config) {
            $stmt->execute($config);
        }
    }
}
