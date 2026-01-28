<?php
/**
 * API: Upload automatique de facture avec analyse AI
 * Reçoit un fichier, l'analyse avec l'IA, et crée la facture automatiquement
 * Admin seulement
 */

require_once '../../config.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../includes/AIServiceFactory.php';

header('Content-Type: application/json');

/**
 * Génère un lien vers le produit selon le fournisseur et le SKU
 * Équivalent PHP de la fonction JavaScript generateProductLink()
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
 * Équivalent PHP de la fonction JavaScript updateDescriptionHidden()
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

// Admin seulement
if (!isAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Accès réservé aux administrateurs']);
    exit;
}

// Vérifier méthode POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Méthode non autorisée']);
    exit;
}

// Vérifier le projet_id
$projetId = (int)($_POST['projet_id'] ?? 0);
if (!$projetId) {
    echo json_encode(['success' => false, 'error' => 'Projet non spécifié']);
    exit;
}

// Vérifier le fichier
if (!isset($_FILES['fichier']) || $_FILES['fichier']['error'] !== UPLOAD_ERR_OK) {
    $errorMsg = match($_FILES['fichier']['error'] ?? UPLOAD_ERR_NO_FILE) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Fichier trop volumineux',
        UPLOAD_ERR_NO_FILE => 'Aucun fichier fourni',
        default => 'Erreur lors de l\'upload'
    };
    echo json_encode(['success' => false, 'error' => $errorMsg]);
    exit;
}

$file = $_FILES['fichier'];
$fileName = $file['name'];
$fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

// Vérifier l'extension
$allowedExtensions = ['jpg', 'jpeg', 'png', 'pdf'];
if (!in_array($fileExt, $allowedExtensions)) {
    echo json_encode(['success' => false, 'error' => 'Format non supporté. Utilisez: JPG, PNG ou PDF']);
    exit;
}

// Vérifier la taille (5 MB max)
if ($file['size'] > 5 * 1024 * 1024) {
    echo json_encode(['success' => false, 'error' => 'Fichier trop volumineux (max 5 MB)']);
    exit;
}

try {
    // 1. Upload du fichier
    $upload = uploadFile($file);
    if (!$upload['success']) {
        throw new Exception($upload['error']);
    }
    $fichier = $upload['filename'];
    $filePath = UPLOAD_PATH . $fichier;

    // 2. Préparer l'image pour l'analyse AI
    $imageData = null;
    $mimeType = 'image/png';
    $pdfConversionError = null;

    if ($fileExt === 'pdf') {
        // Pour les PDF, on utilise Imagick pour convertir la première page
        // Résolution 300 DPI pour une meilleure qualité d'OCR (comme le formulaire simple)
        if (!extension_loaded('imagick')) {
            $pdfConversionError = 'Extension Imagick non disponible';
        } else {
            try {
                $imagick = new Imagick();
                $imagick->setResolution(300, 300); // 300 DPI pour qualité OCR optimale
                $imagick->readImage($filePath . '[0]'); // Première page
                $imagick->setImageFormat('png');
                $imagick->setImageCompressionQuality(95); // Haute qualité
                $imageData = base64_encode($imagick->getImageBlob());
                $imagick->destroy();
                $mimeType = 'image/png';
            } catch (Exception $e) {
                $pdfConversionError = $e->getMessage();
                error_log("Conversion PDF échouée pour {$fileName}: " . $e->getMessage());
            }
        }
    } else {
        // Image directe
        $imageData = base64_encode(file_get_contents($filePath));
        $mimeType = match($fileExt) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            default => 'image/png'
        };
    }

    // 3. Analyser avec l'IA
    $aiResult = null;
    $aiError = null;
    if ($imageData) {
        try {
            // Récupérer les étapes du budget-builder
            $etapes = [];
            try {
                $stmt = $pdo->query("SELECT id, nom FROM budget_etapes ORDER BY ordre, nom");
                $etapes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                // Table n'existe pas
            }

            $aiService = AIServiceFactory::create($pdo);
            $aiResult = $aiService->analyserFactureDetails($imageData, $mimeType, $etapes, null);
        } catch (Exception $e) {
            // L'analyse AI a échoué, on continue sans
            $aiError = $e->getMessage();
            error_log("Analyse AI échouée pour {$fileName}: " . $e->getMessage());
        }
    }

    // 4. Extraire les données de l'analyse ou utiliser des valeurs par défaut
    $fournisseur = $aiResult['fournisseur'] ?? 'Fournisseur inconnu';
    $dateFacture = $aiResult['date_facture'] ?? date('Y-m-d');
    $sousTotal = $aiResult['sous_total'] ?? $aiResult['total'] ?? 0;
    $tps = $aiResult['tps'] ?? 0;
    $tvq = $aiResult['tvq'] ?? 0;
    $total = $aiResult['total'] ?? $sousTotal;

    // Si sous_total est 0 mais total existe, estimer
    if ($sousTotal == 0 && $total > 0) {
        // Estimer le sous-total (total / 1.14975 pour TPS+TVQ)
        $sousTotal = $total / 1.14975;
        $tps = $sousTotal * 0.05;
        $tvq = $sousTotal * 0.09975;
    }

    // Calculer le montant total
    $montantTotal = $sousTotal + $tps + $tvq;

    // 5. Déterminer l'étape principale
    $etapeId = null;
    $etapesMap = [];
    try {
        $stmtEtapes = $pdo->query("SELECT id, nom FROM budget_etapes");
        while ($row = $stmtEtapes->fetch()) {
            $etapesMap[strtolower(trim($row['nom']))] = $row['id'];
        }
    } catch (Exception $e) {
        // Table budget_etapes n'existe pas, on continue sans
    }

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

    // 6. Créer la facture
    $stmt = $pdo->prepare("
        INSERT INTO factures (projet_id, etape_id, user_id, fournisseur, description, date_facture,
                             montant_avant_taxes, tps, tvq, montant_total, fichier, notes, statut)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'en_attente')
    ");

    // Générer la description à partir des lignes (comme le formulaire simple)
    $description = generateDescription($aiResult['lignes'] ?? []);
    if ($aiResult) {
        $notes = 'Analysée par IA';
    } elseif ($pdfConversionError) {
        $notes = 'PDF - conversion image impossible';
    } elseif ($aiError) {
        $notes = 'Erreur analyse IA';
    } else {
        $notes = 'Import sans analyse IA';
    }

    $success = $stmt->execute([
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
        $notes
    ]);

    if (!$success) {
        throw new Exception('Erreur lors de la création de la facture');
    }

    $factureId = $pdo->lastInsertId();

    // 7. Sauvegarder les lignes du breakdown si présentes
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

            // Générer le lien produit automatiquement (comme le formulaire simple)
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

    // 8. Retourner le succès
    echo json_encode([
        'success' => true,
        'facture_id' => $factureId,
        'data' => [
            'fournisseur' => $fournisseur,
            'date_facture' => $dateFacture,
            'montant_total' => $montantTotal,
            'etape_nom' => $aiResult['totaux_par_etape'][0]['etape_nom'] ?? null,
            'nb_lignes' => count($aiResult['lignes'] ?? []),
            'ai_analyzed' => $aiResult !== null,
            'pdf_error' => $pdfConversionError,
            'ai_error' => $aiError
        ]
    ]);

} catch (Exception $e) {
    // Supprimer le fichier uploadé en cas d'erreur
    if (isset($fichier)) {
        deleteUploadedFile($fichier);
    }

    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
