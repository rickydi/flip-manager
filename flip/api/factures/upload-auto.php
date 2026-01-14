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

    if ($fileExt === 'pdf') {
        // Pour les PDF, on utilise Imagick pour convertir la première page
        if (!extension_loaded('imagick')) {
            // Si Imagick n'est pas dispo, on saute l'analyse AI
            $imageData = null;
        } else {
            $imagick = new Imagick();
            $imagick->setResolution(150, 150);
            $imagick->readImage($filePath . '[0]'); // Première page
            $imagick->setImageFormat('png');
            $imageData = base64_encode($imagick->getImageBlob());
            $imagick->destroy();
            $mimeType = 'image/png';
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
    if ($imageData) {
        try {
            // Récupérer les étapes du budget-builder
            $etapes = [];
            $stmt = $pdo->query("SELECT id, nom FROM budget_etapes ORDER BY ordre, nom");
            $etapes = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $aiService = AIServiceFactory::create($pdo);
            $aiResult = $aiService->analyserFactureDetails($imageData, $mimeType, $etapes, null);
        } catch (Exception $e) {
            // L'analyse AI a échoué, on continue sans
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
    $stmtEtapes = $pdo->query("SELECT id, nom FROM budget_etapes");
    while ($row = $stmtEtapes->fetch()) {
        $etapesMap[strtolower(trim($row['nom']))] = $row['id'];
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

    $description = 'Facture importée automatiquement';
    $notes = $aiResult ? 'Analysée par IA' : 'Import sans analyse IA';

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

            $stmtLigne->execute([
                $factureId,
                $ligne['description'] ?? '',
                $ligne['quantite'] ?? 1,
                $ligne['prix_unitaire'] ?? 0,
                $ligne['total'] ?? 0,
                $ligneEtapeId,
                $etapeNom,
                $ligne['raison'] ?? '',
                $ligne['sku'] ?? $ligne['code_produit'] ?? null,
                $ligne['link'] ?? null
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
            'ai_analyzed' => $aiResult !== null
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
