<?php
/**
 * Traitement AJAX de l'analyse par lots (Chunks)
 * Flip Manager
 */

require_once '../../config.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../includes/ClaudeService.php';

requireAdmin();
header('Content-Type: application/json');

$action = $_POST['action'] ?? '';
$claudeService = new ClaudeService($pdo);

try {
    if ($action === 'init') {
        // 1. Créer l'analyse
        $projetId = (int)$_POST['projet_id'];
        $nomRapport = trim($_POST['nom_rapport']);
        
        $stmt = $pdo->prepare("
            INSERT INTO analyses_marche (projet_id, nom_rapport, fichier_source, statut, date_analyse)
            VALUES (?, ?, 'Upload partiel (JS)', 'en_cours', NOW())
        ");
        $stmt->execute([$projetId, $nomRapport]);
        echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
        exit;
    }
    
    if ($action === 'process_chunk') {
        // 2. Traiter un morceau de PDF
        $analyseId = (int)$_POST['analyse_id'];
        
        if (!isset($_FILES['pdf_chunk']) || $_FILES['pdf_chunk']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception("Erreur upload chunk");
        }
        
        // Récupérer infos projet pour le contexte
        $stmt = $pdo->prepare("SELECT p.* FROM analyses_marche a JOIN projets p ON a.projet_id = p.id WHERE a.id = ?");
        $stmt->execute([$analyseId]);
        $projet = $stmt->fetch();
        
        $projetInfo = [
            'adresse' => $projet['adresse'] . ', ' . $projet['ville'],
            'type' => 'Maison unifamiliale',
            'chambres' => 'N/A',
            'sdb' => 'N/A',
            'superficie' => 'N/A',
            'garage' => 'N/A'
        ];

        // Appel IA
        $resultats = $claudeService->analyserComparables($_FILES['pdf_chunk']['tmp_name'], $projetInfo);
        
        // Sauvegarder les items trouvés
        $stmtItem = $pdo->prepare("
            INSERT INTO comparables_items (analyse_id, adresse, prix_vendu, date_vente, chambres, salles_bains, superficie_batiment, annee_construction, etat_general_note, etat_general_texte, renovations_mentionnees, ajustement_ia, commentaire_ia)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $count = 0;
        if (!empty($resultats['comparables'])) {
            foreach ($resultats['comparables'] as $comp) {
                $stmtItem->execute([
                    $analyseId,
                    $comp['adresse'] ?? '',
                    $comp['prix_vendu'] ?? 0,
                    $comp['date_vente'] ?? null,
                    $comp['chambres'] ?? '',
                    $comp['sdb'] ?? '',
                    $comp['superficie'] ?? '',
                    $comp['annee'] ?? null,
                    $comp['etat_note'] ?? 0,
                    $comp['etat_texte'] ?? '',
                    $comp['renovations'] ?? '',
                    $comp['ajustement'] ?? 0,
                    $comp['commentaire'] ?? ''
                ]);
                $count++;
            }
        }
        
        echo json_encode(['success' => true, 'items_found' => $count]);
        exit;
    }
    
    if ($action === 'finish') {
        // 3. Finaliser (Calculer moyennes)
        $analyseId = (int)$_POST['analyse_id'];
        
        // Calculer stats
        $stmt = $pdo->prepare("SELECT AVG(prix_vendu) as moyen, COUNT(*) as nb FROM comparables_items WHERE analyse_id = ?");
        $stmt->execute([$analyseId]);
        $stats = $stmt->fetch();
        
        // Médian (approximatif via SQL ou PHP)
        $stmt = $pdo->prepare("SELECT prix_vendu FROM comparables_items WHERE analyse_id = ? ORDER BY prix_vendu");
        $stmt->execute([$analyseId]);
        $prix = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $median = 0;
        if (count($prix) > 0) {
            $middle = floor(count($prix) / 2);
            $median = $prix[$middle];
        }
        
        // Prix suggéré (Moyenne ajustée par l'IA - on prend la moyenne des prix vendus + moyenne des ajustements)
        $stmt = $pdo->prepare("SELECT AVG(prix_vendu + ajustement_ia) as suggere FROM comparables_items WHERE analyse_id = ?");
        $stmt->execute([$analyseId]);
        $suggere = $stmt->fetchColumn() ?: 0;
        
        $stmt = $pdo->prepare("
            UPDATE analyses_marche 
            SET statut = 'termine', 
                prix_moyen = ?, 
                prix_median = ?, 
                prix_suggere_ia = ?,
                analyse_ia_texte = ?
            WHERE id = ?
        ");
        
        $resume = "Analyse complétée sur " . $stats['nb'] . " comparables (via découpage automatique).";
        $stmt->execute([$stats['moyen'], $median, $suggere, $resume, $analyseId]);
        
        echo json_encode(['success' => true]);
        exit;
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
