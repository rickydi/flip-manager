<?php
/**
 * Traitement AJAX de l'analyse (Mode Texte)
 * Flip Manager
 */

require_once '../../config.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../includes/AIServiceFactory.php';

requireAdmin();
header('Content-Type: application/json');

$action = $_POST['action'] ?? '';
$aiService = AIServiceFactory::create($pdo);

try {
    if ($action === 'init') {
        // 1. Créer l'analyse
        $projetId = (int)$_POST['projet_id'];
        $nomRapport = trim($_POST['nom_rapport']);
        
        $stmt = $pdo->prepare("
            INSERT INTO analyses_marche (projet_id, nom_rapport, fichier_source, statut, date_analyse)
            VALUES (?, ?, 'Upload Texte (JS)', 'en_cours', NOW())
        ");
        $stmt->execute([$projetId, $nomRapport]);
        echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
        exit;
    }
    
    if ($action === 'process_text') {
        // 2. Traiter le texte complet
        $analyseId = (int)$_POST['analyse_id'];
        $fullText = $_POST['full_text'] ?? '';
        
        if (empty($fullText)) {
            throw new Exception("Aucun texte extrait.");
        }
        
        // Récupérer infos projet
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

        // Appel IA (Mode Texte)
        $resultats = $aiService->analyserComparablesTexte($fullText, $projetInfo);
        
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
        
        // Finaliser stats directement
        $stmt = $pdo->prepare("SELECT AVG(prix_vendu) as moyen, COUNT(*) as nb FROM comparables_items WHERE analyse_id = ?");
        $stmt->execute([$analyseId]);
        $stats = $stmt->fetch();
        
        $stmt = $pdo->prepare("SELECT prix_vendu FROM comparables_items WHERE analyse_id = ? ORDER BY prix_vendu");
        $stmt->execute([$analyseId]);
        $prix = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $median = 0;
        if (count($prix) > 0) {
            $middle = floor(count($prix) / 2);
            $median = $prix[$middle];
        }
        
        $stmt = $pdo->prepare("
            UPDATE analyses_marche 
            SET statut = 'termine', 
                prix_moyen = ?, 
                prix_median = ?, 
                prix_suggere_ia = ?,
                fourchette_basse = ?,
                fourchette_haute = ?,
                analyse_ia_texte = ?
            WHERE id = ?
        ");
        
        $stmt->execute([
            $stats['moyen'] ?? 0,
            $median,
            $resultats['analyse_globale']['prix_suggere'] ?? 0,
            $resultats['analyse_globale']['fourchette_basse'] ?? 0,
            $resultats['analyse_globale']['fourchette_haute'] ?? 0,
            $resultats['analyse_globale']['commentaire_general'] ?? '',
            $analyseId
        ]);
        
        echo json_encode(['success' => true, 'items_found' => $count]);
        exit;
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
