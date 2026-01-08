<?php
/**
 * Service d'extraction de PDF Centris
 * Extrait le texte et les images, organise par propriété
 * Version PHP Pure - utilise smalot/pdfparser
 */

// Charger l'autoloader Composer
$composerAutoload = dirname(__DIR__) . '/vendor/autoload.php';
if (file_exists($composerAutoload)) {
    require_once $composerAutoload;
}

class PdfExtractorService {
    private $pdo;
    private $uploadDir;
    private $useNativeExtraction = false;

    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->uploadDir = dirname(__DIR__) . '/uploads/comparables/';

        // Vérifier si la librairie PHP est disponible
        $this->useNativeExtraction = class_exists('\\Smalot\\PdfParser\\Parser');
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
            // Retourner le texte extrait pour debug
            return [
                'success' => false,
                'error' => 'Aucune propriété trouvée dans le PDF',
                'debug_text' => substr($text, 0, 5000) // Premiers 5000 caractères
            ];
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
     * Extrait le texte du PDF - PHP Pure avec smalot/pdfparser
     */
    private function extractText($pdfPath) {
        // Méthode 1: Utiliser smalot/pdfparser (PHP pure)
        if ($this->useNativeExtraction) {
            try {
                $parser = new \Smalot\PdfParser\Parser();
                $pdf = $parser->parseFile($pdfPath);
                $text = $pdf->getText();

                if (!empty($text)) {
                    return $text;
                }
            } catch (\Exception $e) {
                // Log l'erreur mais continue avec fallback
                error_log("PdfParser error: " . $e->getMessage());
            }
        }

        // Méthode 2: Fallback vers pdftotext si disponible
        $output = [];
        $returnVar = 0;
        exec("pdftotext -layout " . escapeshellarg($pdfPath) . " - 2>/dev/null", $output, $returnVar);

        if ($returnVar === 0 && !empty($output)) {
            return implode("\n", $output);
        }

        return '';
    }

    /**
     * Extrait les images du PDF
     * Note: L'extraction d'images est optionnelle, l'analyse se fait principalement sur le texte
     */
    private function extractImages($pdfPath, $outputDir) {
        $imagesDir = $outputDir . 'all_images/';
        if (!is_dir($imagesDir)) {
            mkdir($imagesDir, 0755, true);
        }

        $images = [];

        // Méthode 1: Essayer pdfimages si disponible
        $returnVar = 0;
        exec("which pdfimages 2>/dev/null", $checkOutput, $checkReturn);

        if ($checkReturn === 0) {
            exec("pdfimages -j " . escapeshellarg($pdfPath) . " " . escapeshellarg($imagesDir . "img") . " 2>/dev/null", $output, $returnVar);

            $files = glob($imagesDir . "img-*.{jpg,jpeg,png,ppm}", GLOB_BRACE);

            foreach ($files as $file) {
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

            usort($images, function($a, $b) { return $a['page'] - $b['page']; });
        }

        // Méthode 2: Essayer d'extraire avec smalot/pdfparser (limité mais fonctionne parfois)
        if (empty($images) && $this->useNativeExtraction) {
            try {
                $parser = new \Smalot\PdfParser\Parser();
                $pdf = $parser->parseFile($pdfPath);

                $imgCount = 0;
                foreach ($pdf->getPages() as $pageNum => $page) {
                    $xObjects = $page->getXObjects();
                    foreach ($xObjects as $xObject) {
                        if ($xObject instanceof \Smalot\PdfParser\XObject\Image) {
                            $imgCount++;
                            $imgPath = $imagesDir . 'img-' . str_pad($pageNum, 3, '0', STR_PAD_LEFT) . '-' . $imgCount . '.jpg';

                            // Essayer d'extraire le contenu de l'image
                            $content = $xObject->getContent();
                            if (!empty($content)) {
                                file_put_contents($imgPath, $content);
                                $images[] = [
                                    'path' => $imgPath,
                                    'page' => $pageNum,
                                    'filename' => basename($imgPath)
                                ];
                            }
                        }
                    }
                }
            } catch (\Exception $e) {
                // L'extraction d'images peut échouer, ce n'est pas critique
                error_log("Image extraction error: " . $e->getMessage());
            }
        }

        return $images;
    }

    /**
     * Découpe le texte en chunks par propriété (No Centris)
     */
    private function splitByProperty($text) {
        $chunks = [];

        // Pattern pour détecter le début d'une nouvelle propriété
        // Format Centris: "10979113 (Vendu en 31 jours)No Centris"
        $pattern = '/(\d{7,8})\s*\(Vendu en (\d+) jours?\)\s*No\s*Centris/i';

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

            // Extraire les pages (pattern: "No Centris XXXXX - Page X de Y")
            $pageDebut = 1;
            $pageFin = 1;
            if (preg_match('/No\s*Centris\s*\d+\s*-\s*Page\s+(\d+)\s+de\s+(\d+)/i', $chunkText, $pageMatch)) {
                $pageDebut = (int)$pageMatch[1];
            }
            // Chercher la dernière page mentionnée
            preg_match_all('/No\s*Centris\s*\d+\s*-\s*Page\s+(\d+)\s+de\s+\d+/i', $chunkText, $allPages);
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
     * Parse les données structurées d'un chunk de texte - Version complète
     */
    private function parseChunkData($text) {
        $data = [];

        // Adresse (ligne avec numéro + Rue/Avenue/Boulevard etc.)
        if (preg_match('/(\d+[\s,]*(?:Rue|Avenue|Boulevard|Chemin|Place|Croissant|Av\.|Boul\.)[^\n]+)/iu', $text, $m)) {
            $data['adresse'] = trim($m[1]);
        }

        // Prix vendu (format: "640 000 $" ou "1 200 000 $")
        if (preg_match('/([\d\s]{3,12})\s*\$/m', $text, $m)) {
            $data['prix_vendu'] = (int)preg_replace('/\s/', '', $m[1]);
        }

        // Ville - chercher après le code postal ou pattern connu
        if (preg_match('/[A-Z]\d[A-Z]\s*\d[A-Z]\d\s*\n\s*([A-Za-zÀ-ÿ\-]+)/u', $text, $m)) {
            $data['ville'] = trim($m[1]);
        } elseif (preg_match('/(Sainte?-[A-Za-zÀ-ÿ]+|Saint-[A-Za-zÀ-ÿ]+|[A-Z][a-zÀ-ÿ]+-[A-Za-zÀ-ÿ]+|Montréal|Laval|Québec|Longueuil|Gatineau|Sherbrooke|Trois-Rivières)/u', $text, $m)) {
            $data['ville'] = $m[1];
        }

        // Année de construction - plusieurs formats
        if (preg_match('/Année\s*(?:de\s*)?construction[:\s]*(\d{4})/iu', $text, $m)) {
            $data['annee_construction'] = (int)$m[1];
        } elseif (preg_match('/Construit(?:e)?\s*(?:en\s*)?(\d{4})/iu', $text, $m)) {
            $data['annee_construction'] = (int)$m[1];
        } elseif (preg_match('/(\d{4})\s*(?:année|construction)/iu', $text, $m)) {
            $data['annee_construction'] = (int)$m[1];
        }

        // Genre de propriété (Maison de plain-pied, Cottage, etc.)
        if (preg_match('/Genre de propriété\s*([^\t\n]+)/iu', $text, $m)) {
            $data['type_propriete'] = trim($m[1]);
        }

        // Type de bâtiment (Isolé, Jumelé, etc.)
        if (preg_match('/Type de bâtiment\s*([^\t\n]+)/iu', $text, $m)) {
            $data['type_batiment'] = trim($m[1]);
        }

        // Chambres - ATTENTION: ne pas confondre avec Nbre pièces
        // Format Centris: "Nbre chambres c-c 3" ou "Chambres: 3+1" ou "3 chambres"
        if (preg_match('/Nbre\s*chambres\s*(?:c-c|au\s*s-s)?\s*(\d+)/iu', $text, $m)) {
            $data['chambres'] = $m[1];
        } elseif (preg_match('/Chambres?\s*[:\s]*(\d+(?:\+\d+)?)/iu', $text, $m)) {
            $data['chambres'] = $m[1];
        } elseif (preg_match('/(\d+)\s*chambres?\s*(?:à\s*coucher)?/iu', $text, $m)) {
            $data['chambres'] = $m[1];
        }

        // Salles de bain (format: "2+3" ou "2")
        if (preg_match('/Nbre\s*salles?\s*(?:de\s*)?bains?\s*(\d+\+\d+|\d+)/iu', $text, $m)) {
            $data['sdb'] = $m[1];
        } elseif (preg_match('/salles?\s*(?:de\s*)?bains?\s*[:\s]*(\d+\+\d+|\d+)/iu', $text, $m)) {
            $data['sdb'] = $m[1];
        } elseif (preg_match('/(\d+\+\d+|\d+)\s*salles?\s*(?:de\s*)?bains?/iu', $text, $m)) {
            $data['sdb'] = $m[1];
        }

        // Nombre de pièces (total) - distinct des chambres
        if (preg_match('/Nbre\s*pièces\s*(\d+\+?\d*)/iu', $text, $m)) {
            $data['nb_pieces'] = $m[1];
        }

        // === SUPERFICIE TERRAIN ===
        // Format Centris: "Superficie du terrain    4 400 pc" (avec espaces/tabs)
        // Pattern très permissif pour capturer la valeur
        if (preg_match('/Superficie\s+(?:du\s+)?terrain[^\d]*([\d][\d\s]*)\s*(pc|pi|p|m)/iu', $text, $m)) {
            $val = preg_replace('/\s/', '', $m[1]);
            $data['superficie_terrain'] = $val . ' ' . $m[2];
        }

        // === DIMENSIONS TERRAIN === (pour calculer si pas de superficie)
        // Format: "Dimensions du terrain    47 X 92 p"
        if (preg_match('/Dimensions?\s+(?:du\s+)?terrain[^\d]*([\d]+(?:[,\.]\d+)?)\s*[xX×]\s*([\d]+(?:[,\.]\d+)?)\s*(p|pi|m)?/iu', $text, $m)) {
            $dim1 = (float)str_replace(',', '.', $m[1]);
            $dim2 = (float)str_replace(',', '.', $m[2]);
            $unit = $m[3] ?? 'p';
            $data['dimensions_terrain'] = round($dim1) . 'x' . round($dim2) . ' ' . $unit;

            // Calculer superficie si pas déjà trouvée
            if (empty($data['superficie_terrain']) && $dim1 > 0 && $dim2 > 0) {
                $calcul = round($dim1 * $dim2);
                $data['superficie_terrain'] = $calcul . ' pc (' . round($dim1) . 'x' . round($dim2) . ')';
            }
        }

        // === SUPERFICIE HABITABLE/BÂTIMENT ===
        // Format: "Superficie habitable    1 200 pc"
        if (preg_match('/Superficie\s+habitable[^\d]*([\d][\d\s]*)\s*(pc|pi|p|m)/iu', $text, $m)) {
            $val = preg_replace('/\s/', '', $m[1]);
            $data['superficie_habitable'] = $val . ' ' . $m[2];
        } elseif (preg_match('/Superficie\s+(?:du\s+)?b[âa]timent[^\d]*([\d][\d\s]*)\s*(pc|pi|p|m)/iu', $text, $m)) {
            $val = preg_replace('/\s/', '', $m[1]);
            $data['superficie_habitable'] = $val . ' ' . $m[2];
        }

        // === DIMENSIONS BÂTIMENT === (pour calculer si pas de superficie)
        // Format: "Dimensions du bâtiment    24 X 34 p irr"
        if (preg_match('/Dimensions?\s+(?:du\s+)?b[âa]timent[^\d]*([\d]+(?:[,\.]\d+)?)\s*[xX×]\s*([\d]+(?:[,\.]\d+)?)\s*(p|pi|m)?/iu', $text, $m)) {
            $dim1 = (float)str_replace(',', '.', $m[1]);
            $dim2 = (float)str_replace(',', '.', $m[2]);
            $unit = $m[3] ?? 'p';
            $data['dimensions_batiment'] = round($dim1) . 'x' . round($dim2) . ' ' . $unit;

            // Calculer superficie si pas déjà trouvée
            if (empty($data['superficie_habitable']) && $dim1 > 0 && $dim2 > 0) {
                $calcul = round($dim1 * $dim2);
                $data['superficie_habitable'] = $calcul . ' pc (' . round($dim1) . 'x' . round($dim2) . ')';
            }
        }

        // === ÉVALUATION MUNICIPALE ===
        // Format Centris: "Terrain 150 000 $" ou "Éval. terrain: 150000$"
        if (preg_match('/(?:Éval(?:uation)?\.?\s*)?Terrain\s*[:\s]*([\d\s]+)\s*\$/iu', $text, $m)) {
            $data['eval_terrain'] = (int)preg_replace('/\s/', '', $m[1]);
        }
        if (preg_match('/(?:Éval(?:uation)?\.?\s*)?Bâtiment\s*[:\s]*([\d\s]+)\s*\$/iu', $text, $m)) {
            $data['eval_batiment'] = (int)preg_replace('/\s/', '', $m[1]);
        }
        // Total avec ou sans pourcentage
        if (preg_match('/(?:Éval(?:uation)?\.?\s*)?Total\s*[:\s]*([\d\s]+)\s*\$(?:\s*\([\d,\.]+\s*%\))?/iu', $text, $m)) {
            $data['eval_total'] = (int)preg_replace('/\s/', '', $m[1]);
        } elseif (preg_match('/Évaluation\s*(?:municipale|mun\.?)?\s*[:\s]*([\d\s]+)\s*\$/iu', $text, $m)) {
            $data['eval_total'] = (int)preg_replace('/\s/', '', $m[1]);
        }

        // === TAXES ===
        if (preg_match('/Municipale\s*([\d\s]+)\s*\$\s*\((\d{4})\)/iu', $text, $m)) {
            $data['taxe_municipale'] = (int)preg_replace('/\s/', '', $m[1]);
            $data['taxe_annee'] = $m[2];
        }
        if (preg_match('/Scolaire\s*([\d\s]+)\s*\$\s*\((\d{4})\)/iu', $text, $m)) {
            $data['taxe_scolaire'] = (int)preg_replace('/\s/', '', $m[1]);
        }

        // === RÉNOVATIONS AVEC COÛTS ===
        // Pattern: "Cuisine - 2022 (25 000 $), Électricité - 2022 (10 000 $)"
        $renovations = [];
        if (preg_match('/Rénovations\s*([^§]+?)(?=Piscine|Municipalité|Approvisionnement|Fondation|Stat\.|$)/isu', $text, $m)) {
            $renoText = $m[1];
            // Extraire chaque rénovation avec son coût
            if (preg_match_all('/([A-Za-zÀ-ÿ\s\-]+)\s*-\s*(\d{4})\s*\(([\d\s]+)\s*\$\)/iu', $renoText, $renoMatches, PREG_SET_ORDER)) {
                foreach ($renoMatches as $reno) {
                    $renovations[] = [
                        'type' => trim($reno[1]),
                        'annee' => $reno[2],
                        'cout' => (int)preg_replace('/\s/', '', $reno[3])
                    ];
                }
            }
            $data['renovations'] = $renovations;
            $data['renovations_texte'] = trim($renoText);
            // Calculer le total des rénovations
            $data['renovations_total'] = array_sum(array_column($renovations, 'cout'));
        }

        // === CARACTÉRISTIQUES ===
        // Fondation
        if (preg_match('/Fondation\s*([^\n\t]+)/iu', $text, $m)) {
            $data['fondation'] = trim($m[1]);
        }

        // Toiture
        if (preg_match('/Revêtement de la toiture\s*([^\n\t]+)/iu', $text, $m)) {
            $data['toiture'] = trim($m[1]);
        }

        // Revêtement extérieur
        if (preg_match('/Revêtement\s*([^\n\t]+?)(?=Garage|Fenestration|$)/iu', $text, $m)) {
            $data['revetement'] = trim($m[1]);
        }

        // Garage / Stationnement - plus précis pour éviter faux positifs
        if (preg_match('/Stat(?:ionnement)?\.?\s*\(?total\)?\s*(\d+)/iu', $text, $m)) {
            $data['stationnement'] = (int)$m[1];
        }
        // Garage: Attaché, Détaché, Simple, Double, etc. - limiter aux valeurs typiques
        if (preg_match('/Garage\s*[:\s]*((?:Attaché|Détaché|Simple|Double|Triple|Intégré|Non|Oui|Aucun|\d+)[^\n\t]*)/iu', $text, $m)) {
            $garage = trim($m[1]);
            // Nettoyer si contient "Fenestration" ou autres textes parasites
            $garage = preg_replace('/Fenestration.*$/i', '', $garage);
            $garage = preg_replace('/Revêtement.*$/i', '', $garage);
            $data['garage'] = trim($garage) ?: null;
        } elseif (preg_match('/(\d+)\s*garage/iu', $text, $m)) {
            $data['garage'] = $m[1] . ' garage(s)';
        }

        // Piscine
        if (preg_match('/Piscine\s*([^\n\t]+)/iu', $text, $m)) {
            $piscine = trim($m[1]);
            if (!empty($piscine) && $piscine !== 'Non') {
                $data['piscine'] = $piscine;
            }
        }

        // Sous-sol
        if (preg_match('/Sous-sol\s*([^\n\t]+)/iu', $text, $m)) {
            $data['sous_sol'] = trim($m[1]);
        }

        // Chauffage
        if (preg_match('/Mode chauffage\s*([^\n\t]+)/iu', $text, $m)) {
            $data['chauffage'] = trim($m[1]);
        }
        if (preg_match('/Énergie\/Chauffage\s*([^\n\t]+)/iu', $text, $m)) {
            $data['energie'] = trim($m[1]);
        }

        // === PROXIMITÉS ===
        if (preg_match('/Proximité\s*([^\n]+(?:\n[^\n]+)*?)(?=Foyer|Particularités|Armoires|$)/isu', $text, $m)) {
            $data['proximites'] = trim($m[1]);
        }

        // === INCLUSIONS / EXCLUSIONS ===
        if (preg_match('/Inclusions\s*\n?\s*([^\n]+(?:\n[^\n]+)*?)(?=Exclusions|Remarques|$)/isu', $text, $m)) {
            $data['inclusions'] = trim($m[1]);
        }
        if (preg_match('/Exclusions\s*\n?\s*([^\n]+)/iu', $text, $m)) {
            $data['exclusions'] = trim($m[1]);
        }

        // === REMARQUES ===
        if (preg_match('/Remarques\s*\n?\s*(.*?)(?=Vente avec|Déclaration|Source|No Centris|$)/isu', $text, $m)) {
            $data['remarques'] = trim(substr($m[1], 0, 2000));
        }

        // Date de vente - plusieurs formats possibles
        // Format Centris: "Date PA acceptée    2025-11-07" (avec tabs/espaces)
        if (preg_match('/Date\s*PA\s*accept[ée]+[\s\t]+(\d{4}-\d{2}-\d{2})/iu', $text, $m)) {
            $data['date_vente'] = $m[1];
        } elseif (preg_match('/Date\s*PA\s*accept[ée]+[^\d]*(\d{4}-\d{2}-\d{2})/iu', $text, $m)) {
            $data['date_vente'] = $m[1];
        } elseif (preg_match('/Date\s*(?:de\s*)?vente[\s\t:]+(\d{4}-\d{2}-\d{2})/iu', $text, $m)) {
            $data['date_vente'] = $m[1];
        } elseif (preg_match('/Vendu(?:e)?\s*(?:le\s*)?(\d{1,2}[\/\-]\d{1,2}[\/\-]\d{4})/iu', $text, $m)) {
            $data['date_vente'] = $m[1];
        } elseif (preg_match('/Signature\s*(?:de\s*)?l\'?acte[\s\t]+(?:de\s*)?vente[\s\t]+(\d{4}-\d{2}-\d{2})/iu', $text, $m)) {
            $data['date_vente'] = $m[1];
        }

        // Date signature acte de vente (backup) - chercher n'importe quelle date après "Signature"
        if (empty($data['date_vente']) && preg_match('/Signature[^\d]*(\d{4}-\d{2}-\d{2})/iu', $text, $m)) {
            $data['date_vente'] = $m[1];
        }
        // Backup: chercher date après "acceptée"
        if (empty($data['date_vente']) && preg_match('/accept[ée]+[^\d]*(\d{4}-\d{2}-\d{2})/iu', $text, $m)) {
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
     * Sauvegarde les chunks en base de données - Version complète avec tous les champs
     */
    public function saveChunksToDb($analyseId, $chunks, $basePath) {
        $stmt = $this->pdo->prepare("
            INSERT INTO comparables_chunks
            (analyse_id, no_centris, page_debut, page_fin, chunk_text, photos_path,
             adresse, ville, prix_vendu, date_vente, jours_marche, chambres, sdb,
             superficie_terrain, superficie_batiment, annee_construction, type_propriete,
             eval_terrain, eval_batiment, eval_total,
             taxe_municipale, taxe_scolaire, taxe_annee,
             fondation, toiture, revetement, garage, stationnement, piscine, sous_sol,
             chauffage, energie, proximites, inclusions, exclusions,
             renovations_total, renovations_texte, remarques)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
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
                $data['superficie_habitable'] ?? null,
                $data['annee_construction'] ?? null,
                $data['type_propriete'] ?? null,
                $data['eval_terrain'] ?? null,
                $data['eval_batiment'] ?? null,
                $data['eval_total'] ?? null,
                $data['taxe_municipale'] ?? null,
                $data['taxe_scolaire'] ?? null,
                $data['taxe_annee'] ?? null,
                $data['fondation'] ?? null,
                $data['toiture'] ?? null,
                $data['revetement'] ?? null,
                $data['garage'] ?? null,
                $data['stationnement'] ?? null,
                $data['piscine'] ?? null,
                $data['sous_sol'] ?? null,
                $data['chauffage'] ?? null,
                $data['energie'] ?? null,
                $data['proximites'] ?? null,
                $data['inclusions'] ?? null,
                $data['exclusions'] ?? null,
                $data['renovations_total'] ?? 0,
                $data['renovations_texte'] ?? null,
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
     * Retourne true pour PHP pure même sans poppler-utils
     */
    public static function checkDependencies() {
        $results = [];

        // Vérifier la librairie PHP pure (smalot/pdfparser)
        $composerAutoload = dirname(__DIR__) . '/vendor/autoload.php';
        if (file_exists($composerAutoload)) {
            require_once $composerAutoload;
        }
        $results['php_parser'] = class_exists('\\Smalot\\PdfParser\\Parser');

        // Vérifier pdftotext (optionnel maintenant)
        exec('which pdftotext 2>/dev/null', $output, $returnVar);
        $results['pdftotext'] = $returnVar === 0;

        // Vérifier pdfimages (optionnel maintenant)
        exec('which pdfimages 2>/dev/null', $output2, $returnVar2);
        $results['pdfimages'] = $returnVar2 === 0;

        // Le module fonctionne si PHP parser OU pdftotext est disponible
        $results['ready'] = $results['php_parser'] || $results['pdftotext'];

        return $results;
    }
}
