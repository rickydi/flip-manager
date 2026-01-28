<?php
/**
 * API: Link Preview - Récupère image et titre d'un lien produit
 * Utilise cURL pour mieux gérer les sites qui bloquent les requêtes
 */

require_once '../config.php';

header('Content-Type: application/json');
header('Cache-Control: public, max-age=3600'); // Cache 1 heure

$url = $_GET['url'] ?? '';

if (empty($url)) {
    echo json_encode(['success' => false, 'error' => 'URL manquante']);
    exit;
}

// Valider l'URL
if (!filter_var($url, FILTER_VALIDATE_URL)) {
    echo json_encode(['success' => false, 'error' => 'URL invalide']);
    exit;
}

// Domaines autorisés pour la sécurité
$allowedDomains = [
    'homedepot.ca', 'homedepot.com',
    'rona.ca',
    'renodepot.com',
    'bmr.co',
    'canac.ca',
    'canadiantire.ca',
    'ikea.com',
    'lowes.ca', 'lowes.com',
    'patrickmorin.com'
];

$urlHost = parse_url($url, PHP_URL_HOST);
$isAllowed = false;
foreach ($allowedDomains as $domain) {
    if (strpos($urlHost, $domain) !== false) {
        $isAllowed = true;
        break;
    }
}

if (!$isAllowed) {
    echo json_encode(['success' => false, 'error' => 'Domaine non autorisé']);
    exit;
}

try {
    // Utiliser cURL pour mieux gérer les headers
    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_ENCODING => 'gzip, deflate',
        CURLOPT_HTTPHEADER => [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
            'Accept-Language: fr-CA,fr;q=0.9,en-CA;q=0.8,en;q=0.7',
            'Cache-Control: no-cache',
            'Pragma: no-cache',
            'Sec-Fetch-Dest: document',
            'Sec-Fetch-Mode: navigate',
            'Sec-Fetch-Site: none',
            'Sec-Fetch-User: ?1',
            'Upgrade-Insecure-Requests: 1'
        ],
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        CURLOPT_REFERER => 'https://www.google.com/',
        CURLOPT_COOKIEJAR => '/tmp/cookies_' . md5($urlHost) . '.txt',
        CURLOPT_COOKIEFILE => '/tmp/cookies_' . md5($urlHost) . '.txt',
    ]);

    $html = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($html === false || $httpCode >= 400) {
        // Fallback: essayer de construire l'URL de l'image directement pour Home Depot
        if (strpos($url, 'homedepot') !== false) {
            preg_match('/\/(\d{10})$/', $url, $matches);
            if (!empty($matches[1])) {
                $sku = $matches[1];
                // Home Depot utilise un pattern prévisible pour les images
                $imageUrl = "https://images.homedepot.ca/productimages/p_" . $sku . "_1000.jpg";

                // Vérifier si l'image existe
                $imgCh = curl_init($imageUrl);
                curl_setopt_array($imgCh, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_NOBODY => true,
                    CURLOPT_TIMEOUT => 5,
                    CURLOPT_SSL_VERIFYPEER => false,
                ]);
                curl_exec($imgCh);
                $imgHttpCode = curl_getinfo($imgCh, CURLINFO_HTTP_CODE);
                curl_close($imgCh);

                if ($imgHttpCode == 200) {
                    echo json_encode([
                        'success' => true,
                        'url' => $url,
                        'title' => 'Produit Home Depot #' . $sku,
                        'image' => $imageUrl
                    ]);
                    exit;
                }

                // Essayer autre format d'URL
                $imageUrl2 = "https://images.homedepot.ca/productimages/p_" . $sku . ".jpg";
                $imgCh2 = curl_init($imageUrl2);
                curl_setopt_array($imgCh2, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_NOBODY => true,
                    CURLOPT_TIMEOUT => 5,
                    CURLOPT_SSL_VERIFYPEER => false,
                ]);
                curl_exec($imgCh2);
                $imgHttpCode2 = curl_getinfo($imgCh2, CURLINFO_HTTP_CODE);
                curl_close($imgCh2);

                if ($imgHttpCode2 == 200) {
                    echo json_encode([
                        'success' => true,
                        'url' => $url,
                        'title' => 'Produit Home Depot #' . $sku,
                        'image' => $imageUrl2
                    ]);
                    exit;
                }
            }
        }

        echo json_encode(['success' => false, 'error' => 'Impossible de charger la page: ' . ($error ?: "HTTP $httpCode")]);
        exit;
    }

    $result = [
        'success' => true,
        'url' => $url,
        'title' => null,
        'image' => null
    ];

    // Chercher l'image Open Graph
    if (preg_match('/<meta[^>]*property=["\']og:image["\'][^>]*content=["\']([^"\']+)["\'][^>]*>/i', $html, $matches)) {
        $result['image'] = $matches[1];
    } elseif (preg_match('/<meta[^>]*content=["\']([^"\']+)["\'][^>]*property=["\']og:image["\'][^>]*>/i', $html, $matches)) {
        $result['image'] = $matches[1];
    }

    // Chercher le titre Open Graph ou titre normal
    if (preg_match('/<meta[^>]*property=["\']og:title["\'][^>]*content=["\']([^"\']+)["\'][^>]*>/i', $html, $matches)) {
        $result['title'] = html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
    } elseif (preg_match('/<meta[^>]*content=["\']([^"\']+)["\'][^>]*property=["\']og:title["\'][^>]*>/i', $html, $matches)) {
        $result['title'] = html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
    } elseif (preg_match('/<title[^>]*>([^<]+)<\/title>/i', $html, $matches)) {
        $result['title'] = html_entity_decode(trim($matches[1]), ENT_QUOTES, 'UTF-8');
    }

    // Si pas d'image OG, chercher l'image principale du produit
    if (empty($result['image'])) {
        // Pattern pour Home Depot - chercher dans le JSON des données produit
        if (strpos($url, 'homedepot') !== false) {
            // Essayer de trouver l'image dans les données JSON embedded
            if (preg_match('/"image"\s*:\s*"([^"]+)"/i', $html, $matches)) {
                $result['image'] = $matches[1];
            } elseif (preg_match('/data-src=["\']([^"\']*images\.homedepot[^"\']+)["\']|src=["\']([^"\']*images\.homedepot[^"\']+)["\']/', $html, $matches)) {
                $result['image'] = $matches[1] ?: $matches[2];
            }

            // Si toujours pas d'image, construire l'URL directement
            if (empty($result['image'])) {
                preg_match('/\/(\d{10})$/', $url, $matches);
                if (!empty($matches[1])) {
                    $result['image'] = "https://images.homedepot.ca/productimages/p_" . $matches[1] . "_1000.jpg";
                }
            }
        }
        // Pattern pour Rona
        elseif (strpos($url, 'rona.ca') !== false) {
            if (preg_match('/"image"\s*:\s*"([^"]+)"/i', $html, $matches)) {
                $result['image'] = $matches[1];
            }
        }
        // Pattern générique pour première grande image
        elseif (preg_match('/<img[^>]*src=["\']([^"\']+\.(jpg|jpeg|png|webp)[^"\']*)["\'][^>]*>/i', $html, $matches)) {
            $imgUrl = $matches[1];
            // Ignorer les petites icônes et logos
            if (!preg_match('/(icon|logo|sprite|badge|button|tracking|pixel)/i', $imgUrl)) {
                // S'assurer que c'est une URL absolue
                if (strpos($imgUrl, 'http') !== 0) {
                    $parsed = parse_url($url);
                    $imgUrl = $parsed['scheme'] . '://' . $parsed['host'] . '/' . ltrim($imgUrl, '/');
                }
                $result['image'] = $imgUrl;
            }
        }
    }

    echo json_encode($result);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
