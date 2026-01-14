<?php
/**
 * API: Créer une facture à partir des données analysées par l'IA
 * Utilisé par l'upload multiple - exactement comme le formulaire simple
 */

require_once '../../config.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

/**
 * Génère un lien vers le produit selon le fournisseur et le SKU
 */
function generateProductLink($fournisseur, $sku) {
    if (empty($sku)) return null;

    $f = strtolower($fournisseur);
    $skuClean = preg_replace('/\s/', '', $sku);

    if (strpos($f, 'home depot') !== false) {
        return "https://www.homedepot.ca/product/{$skuClean}";
    } elseif (strpos($f, 'rona') !== false) {
        return "https://www.rona.ca/fr/produit/{$skuClean}";
    } elseif (strpos($f, 'réno') !== false || strpos($f, 'reno depot') !== false || strpos($f, 'renodepot') !== false) {
        return "https://www.renodepot.com/fr/produit/{$skuClean}";
    } elseif (strpos($f, 'bmr') !== false) {
        return "https://www.bmr.co/fr/produit/{$skuClean}";
    } elseif (strpos($f, 'canac') !== false) {
        return "https://www.canac.ca/fr/produit/{$skuClean}";
    } elseif (strpos($f, 'patrick morin') !== false) {
        return "https://www.yourlink.ca/search?q={$skuClean}";
    } elseif (strpos($f, 'canadian tire') !== false) {
        return "https://www.canadiantire.ca/fr/search.html?q={$skuClean}";
    } elseif (strpos($f, 'ikea') !== false) {
        return "https://www.ikea.com/ca/fr/search/?q={$skuClean}";
    } elseif (strpos($f, 'lowes') !== false || strpos($f, 'lowe\'s') !== false) {
        return "https://www.lowes.ca/search?searchTerm={$skuClean}";
    }

    return null;
}

/**
 * Génère une description formatée à partir des lignes d'articles
 */
function generateDescription($lignes) {
    if (empty($lignes)) return 'Facture importée automatiquement';

    $lines = [];
    foreach ($lignes as $ligne) {
        $desc = $ligne['description'] ?? 'N/A';
        $qte = $ligne['quantite'] ?? 1;
        $total = number_format($ligne['total'] ?? 0, 2, '.', '');
        $line = "{$desc} x{$qte} {$total}$";
        if (!empty($ligne['etape_nom'])) {
            $line .= " [{$ligne['etape_nom']}]";
        }
        $lines[] = $line;
    }

    return implode("\n", $lines);
}

// Vérifier authentification
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Non connecté']);
    exit;
}

// Vérifier méthode POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Méthode non autorisée']);
    exit;
}

// Récupérer les données JSON
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    echo json_encode(['success' => false, 'error' => 'Données JSON invalides']);
    exit;
}

$projetId = (int)($input['projet_id'] ?? 0);
$aiResult = $input['data'] ?? null;
$fichierBase64 = $input['fichier_base64'] ?? null;
$fichierNom = $input['fichier_nom'] ?? 'facture.png';
$statut = $input['statut'] ?? 'en_attente';
$estPayee = !empty($input['est_payee']) ? 1 : 0;

// Valider le statut
if (!in_array($statut, ['en_attente', 'approuvee', 'rejetee'])) {
    $statut = 'en_attente';
}

if (!$projetId) {
    echo json_encode(['success' => false, 'error' => 'Projet non spécifié']);
    exit;
}

if (!$aiResult) {
    echo json_encode(['success' => false, 'error' => 'Données d\'analyse manquantes']);
    exit;
}

try {
    // Sauvegarder le fichier si fourni
    $fichier = null;
    if ($fichierBase64) {
        // Extraire les données base64
        if (strpos($fichierBase64, 'data:') === 0) {
            $parts = explode(',', $fichierBase64);
            $fichierBase64 = $parts[1] ?? $fichierBase64;
        }

        // Créer le fichier
        $ext = pathinfo($fichierNom, PATHINFO_EXTENSION) ?: 'png';
        $fichier = uniqid('facture_') . '.' . $ext;
        $filePath = UPLOAD_PATH . $fichier;

        if (!file_put_contents($filePath, base64_decode($fichierBase64))) {
            throw new Exception('Erreur lors de la sauvegarde du fichier');
        }
    }

    // Extraire les données de l'analyse
    $fournisseur = $aiResult['fournisseur'] ?? 'Fournisseur inconnu';
    $dateFacture = $aiResult['date_facture'] ?? date('Y-m-d');
    $sousTotal = (float)($aiResult['sous_total'] ?? $aiResult['total'] ?? 0);
    $tps = (float)($aiResult['tps'] ?? 0);
    $tvq = (float)($aiResult['tvq'] ?? 0);
    $total = (float)($aiResult['total'] ?? $sousTotal);

    // Si sous_total est 0 mais total existe, estimer
    if ($sousTotal == 0 && $total > 0) {
        $sousTotal = $total / 1.14975;
        $tps = $sousTotal * 0.05;
        $tvq = $sousTotal * 0.09975;
    }

    $montantTotal = $sousTotal + $tps + $tvq;

    // Mapper les étapes
    $etapesMap = [];
    try {
        $stmtEtapes = $pdo->query("SELECT id, nom FROM budget_etapes");
        while ($row = $stmtEtapes->fetch()) {
            $etapesMap[strtolower(trim($row['nom']))] = $row['id'];
        }
    } catch (Exception $e) {
        // Table n'existe pas
    }

    // Déterminer l'étape principale
    $etapeId = null;
    if (!empty($aiResult['totaux_par_etape'])) {
        $maxMontant = 0;
        $etapePrincipale = null;
        foreach ($aiResult['totaux_par_etape'] as $t) {
            if (($t['montant'] ?? 0) > $maxMontant) {
                $maxMontant = $t['montant'];
                $etapePrincipale = $t['etape_nom'] ?? '';
            }
        }

        if ($etapePrincipale) {
            $nomLower = strtolower(trim($etapePrincipale));
            if (isset($etapesMap[$nomLower])) {
                $etapeId = $etapesMap[$nomLower];
            } else {
                foreach ($etapesMap as $nom => $id) {
                    if (strpos($nom, $nomLower) !== false || strpos($nomLower, $nom) !== false) {
                        $etapeId = $id;
                        break;
                    }
                }
            }
        }
    }

    // Créer la facture
    $stmt = $pdo->prepare("
        INSERT INTO factures (projet_id, etape_id, user_id, fournisseur, description, date_facture,
                             montant_avant_taxes, tps, tvq, montant_total, fichier, notes, statut, est_payee)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $description = generateDescription($aiResult['lignes'] ?? []);

    $stmt->execute([
        $projetId,
        $etapeId,
        $_SESSION['user_id'],
        $fournisseur,
        $description,
        $dateFacture,
        $sousTotal,
        $tps,
        $tvq,
        $montantTotal,
        $fichier,
        'Analysée par IA (upload multiple)',
        $statut,
        $estPayee
    ]);

    $factureId = $pdo->lastInsertId();

    // Sauvegarder les lignes
    if (!empty($aiResult['lignes'])) {
        $stmtLigne = $pdo->prepare("
            INSERT INTO facture_lignes (facture_id, description, quantite, prix_unitaire, total, etape_id, etape_nom, raison, sku, link)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($aiResult['lignes'] as $ligne) {
            $etapeNom = $ligne['etape_nom'] ?? '';
            $ligneEtapeId = null;

            $nomLower = strtolower(trim($etapeNom));
            if (isset($etapesMap[$nomLower])) {
                $ligneEtapeId = $etapesMap[$nomLower];
            } else {
                foreach ($etapesMap as $nom => $id) {
                    if (strpos($nom, $nomLower) !== false || strpos($nomLower, $nom) !== false) {
                        $ligneEtapeId = $id;
                        break;
                    }
                }
            }

            $sku = $ligne['sku'] ?? $ligne['code_produit'] ?? null;
            $link = $ligne['link'] ?? null;
            if (empty($link) && !empty($sku) && !empty($fournisseur)) {
                $link = generateProductLink($fournisseur, $sku);
            }

            $stmtLigne->execute([
                $factureId,
                $ligne['description'] ?? '',
                $ligne['quantite'] ?? 1,
                $ligne['prix_unitaire'] ?? 0,
                $ligne['total'] ?? 0,
                $ligneEtapeId,
                $etapeNom,
                $ligne['raison'] ?? '',
                $sku,
                $link
            ]);
        }
    }

    echo json_encode([
        'success' => true,
        'facture_id' => $factureId,
        'data' => [
            'fournisseur' => $fournisseur,
            'date_facture' => $dateFacture,
            'montant_total' => $montantTotal,
            'nb_lignes' => count($aiResult['lignes'] ?? [])
        ]
    ]);

} catch (Exception $e) {
    // Supprimer le fichier uploadé en cas d'erreur
    if (isset($fichier) && file_exists(UPLOAD_PATH . $fichier)) {
        unlink(UPLOAD_PATH . $fichier);
    }

    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
