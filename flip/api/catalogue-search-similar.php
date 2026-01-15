<?php
/**
 * API: Rechercher des articles similaires dans le catalogue
 * Avant d'ajouter un nouvel item, on vérifie s'il existe déjà quelque chose de similaire
 *
 * NOUVEAU: Utilise l'IA pour la comparaison sémantique (ex: "vis gypse" = "gypse vis")
 */

require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/ClaudeService.php';

header('Content-Type: application/json');

// Vérifier authentification admin
if (!isAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Accès non autorisé']);
    exit;
}

// Récupérer les paramètres de recherche
$nom = trim($_GET['nom'] ?? '');
$sku = trim($_GET['sku'] ?? '');
$fournisseur = trim($_GET['fournisseur'] ?? '');

if (empty($nom) && empty($sku)) {
    echo json_encode(['success' => true, 'similar' => []]);
    exit;
}

try {
    // Vérifier si la table existe
    try {
        $pdo->query("SELECT 1 FROM catalogue_items LIMIT 1");
    } catch (Exception $e) {
        echo json_encode(['success' => true, 'similar' => []]);
        exit;
    }

    $similar = [];

    // 1. Recherche exacte par SKU (priorité haute)
    if (!empty($sku)) {
        $stmt = $pdo->prepare("
            SELECT id, nom, prix, fournisseur, sku, lien_achat, image
            FROM catalogue_items
            WHERE type = 'item'
            AND actif = 1
            AND sku IS NOT NULL
            AND sku != ''
            AND LOWER(sku) = LOWER(?)
            LIMIT 5
        ");
        $stmt->execute([$sku]);
        $skuMatches = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($skuMatches as $match) {
            $match['match_type'] = 'sku_exact';
            $match['match_score'] = 100;
            $similar[] = $match;
        }
    }

    // 2. Recherche par nom similaire (fuzzy)
    if (!empty($nom)) {
        // Nettoyer le nom pour la recherche
        $searchTerms = preg_split('/\s+/', strtolower($nom));
        $searchTerms = array_filter($searchTerms, function($t) {
            return strlen($t) > 2; // Ignorer les mots trop courts
        });

        if (count($searchTerms) > 0) {
            // Construire la requête avec LIKE pour chaque terme
            $conditions = [];
            $params = [];

            foreach ($searchTerms as $term) {
                $conditions[] = "LOWER(nom) LIKE ?";
                $params[] = '%' . $term . '%';
            }

            $sql = "
                SELECT id, nom, prix, fournisseur, sku, lien_achat, image
                FROM catalogue_items
                WHERE type = 'item'
                AND actif = 1
                AND (" . implode(' OR ', $conditions) . ")
                LIMIT 10
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $nameMatches = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Calculer un score de similarité pour chaque résultat
            foreach ($nameMatches as $match) {
                // Éviter les doublons (déjà trouvés par SKU)
                $alreadyFound = false;
                foreach ($similar as $existing) {
                    if ($existing['id'] === $match['id']) {
                        $alreadyFound = true;
                        break;
                    }
                }

                if (!$alreadyFound) {
                    $score = calculateSimilarity($nom, $match['nom']);
                    if ($score >= 40) { // Seuil minimum de similarité
                        $match['match_type'] = 'name_similar';
                        $match['match_score'] = $score;
                        $similar[] = $match;
                    }
                }
            }
        }
    }

    // 3. Recherche par fournisseur + nom partiel
    if (!empty($fournisseur) && !empty($nom)) {
        $stmt = $pdo->prepare("
            SELECT id, nom, prix, fournisseur, sku, lien_achat, image
            FROM catalogue_items
            WHERE type = 'item'
            AND actif = 1
            AND LOWER(fournisseur) = LOWER(?)
            LIMIT 20
        ");
        $stmt->execute([$fournisseur]);
        $fournisseurMatches = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($fournisseurMatches as $match) {
            // Éviter les doublons
            $alreadyFound = false;
            foreach ($similar as $existing) {
                if ($existing['id'] === $match['id']) {
                    $alreadyFound = true;
                    break;
                }
            }

            if (!$alreadyFound) {
                $score = calculateSimilarity($nom, $match['nom']);
                if ($score >= 30) { // Seuil plus bas car même fournisseur
                    $match['match_type'] = 'fournisseur_match';
                    $match['match_score'] = $score + 10; // Bonus pour même fournisseur
                    $similar[] = $match;
                }
            }
        }
    }

    // Trier par score décroissant
    usort($similar, function($a, $b) {
        return $b['match_score'] - $a['match_score'];
    });

    // NOUVEAU: Si pas de bons résultats (score < 60), utiliser l'IA sémantique
    $useAI = !isset($_GET['use_ai']) || $_GET['use_ai'] !== '0'; // Activé par défaut
    $bestScore = !empty($similar) ? $similar[0]['match_score'] : 0;

    if ($useAI && !empty($nom) && $bestScore < 60) {
        try {
            // Récupérer plus d'items du catalogue pour la comparaison IA
            // On prend les items qui ont au moins un mot en commun
            $searchWords = array_filter(preg_split('/\s+/', strtolower($nom)), function($w) {
                return strlen($w) > 2;
            });

            if (!empty($searchWords)) {
                // Construire une requête plus large pour avoir des candidats
                $orConditions = [];
                $aiParams = [];
                foreach ($searchWords as $word) {
                    $orConditions[] = "LOWER(nom) LIKE ?";
                    $aiParams[] = '%' . $word . '%';
                }

                $aiSql = "
                    SELECT id, nom, prix, fournisseur, sku, lien_achat, image
                    FROM catalogue_items
                    WHERE type = 'item'
                    AND actif = 1
                    AND (" . implode(' OR ', $orConditions) . ")
                    LIMIT 30
                ";

                $aiStmt = $pdo->prepare($aiSql);
                $aiStmt->execute($aiParams);
                $catalogueItems = $aiStmt->fetchAll(PDO::FETCH_ASSOC);

                if (!empty($catalogueItems)) {
                    $claudeService = new ClaudeService($pdo);
                    $semanticResult = $claudeService->findSemanticDuplicates($nom, $catalogueItems, $fournisseur);

                    if (!empty($semanticResult['matches'])) {
                        // Ajouter les correspondances IA (éviter doublons)
                        foreach ($semanticResult['matches'] as $aiMatch) {
                            $alreadyFound = false;
                            foreach ($similar as $existing) {
                                if ($existing['id'] === $aiMatch['id']) {
                                    $alreadyFound = true;
                                    // Mettre à jour le score si l'IA donne un meilleur score
                                    if ($aiMatch['match_score'] > $existing['match_score']) {
                                        $existing['match_score'] = $aiMatch['match_score'];
                                        $existing['match_type'] = 'semantic_ai';
                                        $existing['reason'] = $aiMatch['reason'];
                                    }
                                    break;
                                }
                            }

                            if (!$alreadyFound) {
                                $similar[] = $aiMatch;
                            }
                        }

                        // Re-trier après ajout IA
                        usort($similar, function($a, $b) {
                            return $b['match_score'] - $a['match_score'];
                        });
                    }
                }
            }
        } catch (Exception $aiError) {
            // Log l'erreur mais ne pas bloquer la réponse
            error_log("Semantic search error: " . $aiError->getMessage());
        }
    }

    // Limiter à 5 résultats
    $similar = array_slice($similar, 0, 5);

    echo json_encode([
        'success' => true,
        'similar' => $similar,
        'search_criteria' => [
            'nom' => $nom,
            'sku' => $sku,
            'fournisseur' => $fournisseur
        ],
        'ai_used' => $useAI && $bestScore < 60
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

/**
 * Calculer un score de similarité entre deux chaînes (0-100)
 */
function calculateSimilarity($search, $target) {
    $search = strtolower(trim($search));
    $target = strtolower(trim($target));

    // Correspondance exacte
    if ($search === $target) {
        return 100;
    }

    // L'un contient l'autre
    if (strpos($target, $search) !== false) {
        return 90;
    }
    if (strpos($search, $target) !== false) {
        return 85;
    }

    // Compter les mots en commun
    $searchWords = array_filter(preg_split('/\s+/', $search), function($w) { return strlen($w) > 2; });
    $targetWords = array_filter(preg_split('/\s+/', $target), function($w) { return strlen($w) > 2; });

    if (empty($searchWords) || empty($targetWords)) {
        return 0;
    }

    $commonWords = 0;
    foreach ($searchWords as $sw) {
        foreach ($targetWords as $tw) {
            if (strpos($tw, $sw) !== false || strpos($sw, $tw) !== false) {
                $commonWords++;
                break;
            }
        }
    }

    $score = ($commonWords / max(count($searchWords), count($targetWords))) * 80;

    // Bonus pour la distance Levenshtein
    $levenshtein = levenshtein($search, $target);
    $maxLen = max(strlen($search), strlen($target));
    if ($maxLen > 0) {
        $levenshteinScore = (1 - ($levenshtein / $maxLen)) * 20;
        $score += max(0, $levenshteinScore);
    }

    return round($score);
}
