<?php
/**
 * API: Créer un sous-traitant à partir des données analysées par l'IA
 * Utilisé par l'upload multiple - exactement comme pour les factures
 */

require_once '../../config.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

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
$fichierNom = $input['fichier_nom'] ?? 'soumission.png';
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
        $fichier = uniqid('soustraitant_') . '.' . $ext;
        $filePath = UPLOAD_PATH . $fichier;

        if (!file_put_contents($filePath, base64_decode($fichierBase64))) {
            throw new Exception('Erreur lors de la sauvegarde du fichier');
        }
    }

    // Extraire les données de l'analyse
    $nomEntreprise = $aiResult['nom_entreprise'] ?? $aiResult['fournisseur'] ?? 'Sous-traitant inconnu';
    $contact = $aiResult['contact'] ?? null;
    $telephone = $aiResult['telephone'] ?? null;
    $email = $aiResult['email'] ?? null;
    $description = $aiResult['description'] ?? '';
    $dateFacture = $aiResult['date_facture'] ?? $aiResult['date_soumission'] ?? date('Y-m-d');

    $sousTotal = (float)($aiResult['sous_total'] ?? $aiResult['montant_avant_taxes'] ?? $aiResult['total'] ?? 0);
    $tps = (float)($aiResult['tps'] ?? 0);
    $tvq = (float)($aiResult['tvq'] ?? 0);
    $total = (float)($aiResult['total'] ?? $aiResult['montant_total'] ?? $sousTotal);

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

    // Déterminer l'étape
    $etapeId = null;
    $etapeNom = $aiResult['etape_nom'] ?? $aiResult['categorie'] ?? '';

    if ($etapeNom) {
        $nomLower = strtolower(trim($etapeNom));
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

    // Créer le sous-traitant
    $stmt = $pdo->prepare("
        INSERT INTO sous_traitants (projet_id, etape_id, user_id, nom_entreprise, contact, telephone, email,
                                    description, date_facture, montant_avant_taxes, tps, tvq, montant_total,
                                    fichier, notes, statut, est_payee)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $projetId,
        $etapeId,
        $_SESSION['user_id'],
        $nomEntreprise,
        $contact,
        $telephone,
        $email,
        $description,
        $dateFacture,
        $sousTotal,
        $tps,
        $tvq,
        $montantTotal,
        $fichier,
        'Analysé par IA (upload multiple)',
        $statut,
        $estPayee
    ]);

    $soustraitantId = $pdo->lastInsertId();

    // Ajouter l'entreprise à la liste d'autocomplétion si elle n'existe pas
    try {
        $stmtCheck = $pdo->prepare("SELECT id FROM entreprises_soustraitants WHERE nom = ?");
        $stmtCheck->execute([$nomEntreprise]);
        if (!$stmtCheck->fetch()) {
            $stmtInsert = $pdo->prepare("INSERT INTO entreprises_soustraitants (nom, contact, telephone, email) VALUES (?, ?, ?, ?)");
            $stmtInsert->execute([$nomEntreprise, $contact, $telephone, $email]);
        }
    } catch (Exception $e) {
        // Ignore errors on autocomplete table
    }

    echo json_encode([
        'success' => true,
        'soustraitant_id' => $soustraitantId,
        'data' => [
            'nom_entreprise' => $nomEntreprise,
            'date_facture' => $dateFacture,
            'montant_total' => $montantTotal
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
