<?php
/**
 * Service d'extraction de PDF Centris
 * Extrait le texte et les images, organise par propriété
 */

class PdfExtractorService {
    private $pdo;
    private $uploadDir;

    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->uploadDir = dirname(__DIR__) . '/uploads/comparables/';
    }

    /**
     * Extrait et organise le contenu d'un PDF Centris
     * @param string $pdfPath Chemin vers le PDF uploadé
     * @param int $analyseId ID de l'analyse
     * @return array ['success' => bool, 'chunks' => array, 'path' => string]
     */
    public function extractAndOrganize($pdfPath, $analyseId) {
        // Créer le dossier pour cette analyse
        $analysePath = $this->uploadDir . 'analyse_' . $analyseId . '/';
        if (!is_dir($analysePath)) {
            mkdir($analysePath, 0755, true);
        }

        // 1. Extraire le texte
        $text = $this->extractText($pdfPath);
        if (empty($text)) {
            return ['success' => false, 'error' => 'Impossible d\'extraire le texte du PDF'];
        }

        // 2. Découper par propriété (No Centris)
        $chunks = $this->splitByProperty($text);
        if (empty($chunks)) {
            return ['success' => false, 'error' => 'Aucune propriété trouvée dans le PDF'];
        }

        // 3. Extraire les images
        $images = $this->extractImages($pdfPath, $analysePath);

        // 4. Organiser les images par propriété
        $this->organizeImagesByProperty($chunks, $images, $analysePath);

        // 5. Sauvegarder le texte structuré
        file_put_contents($analysePath . 'raw_text.txt', $text);
        file_put_contents($analysePath . 'chunks.json', json_encode($chunks, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        // 6. Supprimer le PDF original (optionnel)
        // unlink($pdfPath);

        return [
            'success' => true,
            'chunks' => $chunks,
            'path' => $analysePath,
            'total_images' => count($images)
        ];
    }

    /**
     * Extrait le texte du PDF avec pdftotext
     */
    private function extractText($pdfPath) {
        $output = [];
        $returnVar = 0;

        // Essayer pdftotext (poppler-utils)
        exec("pdftotext -layout " . escapeshellarg($pdfPath) . " - 2>/dev/null", $output, $returnVar);

        if ($returnVar === 0 && !empty($output)) {
            return implode("\n", $output);
        }

        // Fallback: essayer avec php-pdf-parser si disponible
        // Pour l'instant, retourner vide si pdftotext échoue
        return '';
    }

    /**
     * Extrait les images du PDF avec pdfimages
     */
    private function extractImages($pdfPath, $outputDir) {
        $imagesDir = $outputDir . 'all_images/';
        if (!is_dir($imagesDir)) {
            mkdir($imagesDir, 0755, true);
        }

        $returnVar = 0;
        // Extraire en JPEG pour compatibilité
        exec("pdfimages -j " . escapeshellarg($pdfPath) . " " . escapeshellarg($imagesDir . "img") . " 2>/dev/null", $output, $returnVar);

        // Lister les images extraites
        $images = [];
        $files = glob($imagesDir . "img-*.{jpg,jpeg,png,ppm}", GLOB_BRACE);

        foreach ($files as $file) {
            // Extraire le numéro de page de l'image (format: img-XXX-Y.jpg où XXX = page)
            $basename = basename($file);
            if (preg_match('/img-(\d+)/', $basename, $matches)) {
                $pageNum = (int)$matches[1];
                $images[] = [
                    'path' => $file,
                    'page' => $pageNum,
                    'filename' => $basename
                ];
            }
        }

        // Trier par page
        usort($images, function($a, $b) { return $a['page'] - $b['page']; });

        return $images;
    }

    /**
     * Découpe le texte en chunks par propriété (No Centris)
     */
    private function splitByProperty($text) {
        $chunks = [];

        // Pattern pour détecter le début d'une nouvelle propriété
        // "No Centris    10979113 (Vendu en 31 jours)"
        $pattern = '/No\s+Centris\s+(\d+)\s*\(Vendu en (\d+) jours?\)/i';

        // Trouver toutes les occurrences
        preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE);

        if (empty($matches[0])) {
            return [];
        }

        $numMatches = count($matches[0]);

        for ($i = 0; $i < $numMatches; $i++) {
            $noCentris = $matches[1][$i][0];
            $joursMarche = (int)$matches[2][$i][0];
            $startPos = $matches[0][$i][1];

            // Position de fin = début de la prochaine propriété ou fin du texte
            $endPos = ($i < $numMatches - 1) ? $matches[0][$i + 1][1] : strlen($text);

            $chunkText = substr($text, $startPos, $endPos - $startPos);

            // Extraire les pages (pattern: "Page X de Y")
            $pageDebut = 1;
            $pageFin = 1;
            if (preg_match('/Page\s+(\d+)\s+de\s+(\d+)/i', $chunkText, $pageMatch)) {
                $pageDebut = (int)$pageMatch[1];
            }
            // Chercher la dernière page mentionnée
            preg_match_all('/Page\s+(\d+)\s+de\s+\d+/i', $chunkText, $allPages);
            if (!empty($allPages[1])) {
                $pageFin = max(array_map('intval', $allPages[1]));
            }

            // Extraire les données structurées du texte
            $data = $this->parseChunkData($chunkText);

            $chunks[] = [
                'no_centris' => $noCentris,
                'jours_marche' => $joursMarche,
                'page_debut' => $pageDebut,
                'page_fin' => $pageFin,
                'text' => $chunkText,
                'data' => $data
            ];
        }

        return $chunks;
    }

    /**
     * Parse les données structurées d'un chunk de texte
     */
    private function parseChunkData($text) {
        $data = [];

        // Adresse (première ligne après le No Centris qui ressemble à une adresse)
        if (preg_match('/(\d+[\s,]+(?:Rue|Avenue|Boulevard|Chemin|Place|Croissant)[^\n]+)/i', $text, $m)) {
            $data['adresse'] = trim($m[1]);
        }

        // Prix vendu
        if (preg_match('/([\d\s]+)\s*\$/m', $text, $m)) {
            $data['prix_vendu'] = (int)preg_replace('/\s/', '', $m[1]);
        }

        // Ville
        if (preg_match('/Sainte?-[A-Za-z]+|[A-Z][a-z]+-[A-Za-z]+/', $text, $m)) {
            $data['ville'] = $m[0];
        }

        // Année de construction
        if (preg_match('/Année de construction\s+(\d{4})/i', $text, $m)) {
            $data['annee_construction'] = (int)$m[1];
        }

        // Chambres
        if (preg_match('/Nbre chambres.*?(\d+\+\d+|\d+)/i', $text, $m)) {
            $data['chambres'] = $m[1];
        }

        // Salles de bain
        if (preg_match('/salles de bains.*?(\d+\+\d+|\d+)/i', $text, $m)) {
            $data['sdb'] = $m[1];
        }

        // Superficie terrain
        if (preg_match('/Superficie du terrain\s+([\d\s,\.]+)\s*pc/i', $text, $m)) {
            $data['superficie_terrain'] = trim($m[1]);
        }

        // Type de propriété
        if (preg_match('/Genre de propriété\s+([^\n]+)/i', $text, $m)) {
            $data['type_propriete'] = trim($m[1]);
        }

        // Rénovations
        if (preg_match('/Rénovations\s+([^\n]+(?:\n[^\n]+)*?)(?=Piscine|Stat\.|Fondation)/is', $text, $m)) {
            $data['renovations'] = trim($m[1]);
        }

        // Remarques
        if (preg_match('/Remarques\s+(.*?)(?=Vente avec|Déclaration|Addenda|Source|$)/is', $text, $m)) {
            $data['remarques'] = trim(substr($m[1], 0, 1000)); // Limiter à 1000 chars
        }

        // Date PA acceptée (approximation date de vente)
        if (preg_match('/Date PA acceptée\s+(\d{4}-\d{2}-\d{2})/i', $text, $m)) {
            $data['date_vente'] = $m[1];
        }

        return $data;
    }

    /**
     * Organise les images par propriété dans des dossiers séparés
     */
    private function organizeImagesByProperty(&$chunks, $images, $basePath) {
        foreach ($chunks as &$chunk) {
            $noCentris = $chunk['no_centris'];
            $pageDebut = $chunk['page_debut'];
            $pageFin = $chunk['page_fin'];

            // Créer le dossier pour cette propriété
            $propDir = $basePath . 'centris_' . $noCentris . '/';
            if (!is_dir($propDir)) {
                mkdir($propDir, 0755, true);
            }

            $chunk['photos_path'] = $propDir;
            $chunk['photos'] = [];

            // Copier les images qui correspondent aux pages de cette propriété
            foreach ($images as $img) {
                // Les pages dans pdfimages sont 0-indexed, ajuster
                $imgPage = $img['page'] + 1;

                if ($imgPage >= $pageDebut && $imgPage <= $pageFin) {
                    $newPath = $propDir . $img['filename'];
                    if (copy($img['path'], $newPath)) {
                        $chunk['photos'][] = [
                            'path' => $newPath,
                            'filename' => $img['filename'],
                            'page' => $imgPage
                        ];
                    }
                }
            }
        }
    }

    /**
     * Sauvegarde les chunks en base de données
     */
    public function saveChunksToDb($analyseId, $chunks, $basePath) {
        $stmt = $this->pdo->prepare("
            INSERT INTO comparables_chunks
            (analyse_id, no_centris, page_debut, page_fin, chunk_text, photos_path,
             adresse, ville, prix_vendu, date_vente, jours_marche, chambres, sdb,
             superficie_terrain, annee_construction, type_propriete, renovations_texte, remarques)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $savedChunks = [];

        foreach ($chunks as $chunk) {
            $data = $chunk['data'];

            $stmt->execute([
                $analyseId,
                $chunk['no_centris'],
                $chunk['page_debut'],
                $chunk['page_fin'],
                $chunk['text'],
                $chunk['photos_path'] ?? null,
                $data['adresse'] ?? null,
                $data['ville'] ?? null,
                $data['prix_vendu'] ?? 0,
                $data['date_vente'] ?? null,
                $chunk['jours_marche'],
                $data['chambres'] ?? null,
                $data['sdb'] ?? null,
                $data['superficie_terrain'] ?? null,
                $data['annee_construction'] ?? null,
                $data['type_propriete'] ?? null,
                $data['renovations'] ?? null,
                $data['remarques'] ?? null
            ]);

            $chunkId = $this->pdo->lastInsertId();

            // Sauvegarder les photos
            if (!empty($chunk['photos'])) {
                $this->savePhotosToDb($chunkId, $chunk['photos']);
            }

            $savedChunks[] = $chunkId;
        }

        // Mettre à jour le compteur dans analyses_marche
        $this->pdo->prepare("
            UPDATE analyses_marche
            SET total_chunks = ?, extraction_path = ?
            WHERE id = ?
        ")->execute([count($chunks), $basePath, $analyseId]);

        return $savedChunks;
    }

    /**
     * Sauvegarde les photos d'un chunk
     */
    private function savePhotosToDb($chunkId, $photos) {
        $stmt = $this->pdo->prepare("
            INSERT INTO comparables_photos (chunk_id, filename, file_path, ordre)
            VALUES (?, ?, ?, ?)
        ");

        foreach ($photos as $i => $photo) {
            $stmt->execute([
                $chunkId,
                $photo['filename'],
                $photo['path'],
                $i
            ]);
        }
    }

    /**
     * Vérifie si les outils nécessaires sont installés
     */
    public static function checkDependencies() {
        $results = [];

        // Vérifier pdftotext
        exec('which pdftotext 2>/dev/null', $output, $returnVar);
        $results['pdftotext'] = $returnVar === 0;

        // Vérifier pdfimages
        exec('which pdfimages 2>/dev/null', $output2, $returnVar2);
        $results['pdfimages'] = $returnVar2 === 0;

        return $results;
    }
}
