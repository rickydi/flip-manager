<?php
/**
 * API: Link Preview - Récupère image et titre d'un lien produit
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
    // Récupérer la page avec timeout court
    $context = stream_context_create([
        'http' => [
            'timeout' => 5,
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'follow_location' => true,
            'max_redirects' => 3
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false
        ]
    ]);

    $html = @file_get_contents($url, false, $context);

    if ($html === false) {
        echo json_encode(['success' => false, 'error' => 'Impossible de charger la page']);
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
        // Pattern pour Home Depot
        if (strpos($url, 'homedepot') !== false) {
            if (preg_match('/data-src=["\']([^"\']*images\.homedepot[^"\']+)["\']|src=["\']([^"\']*images\.homedepot[^"\']+)["\']/', $html, $matches)) {
                $result['image'] = $matches[1] ?: $matches[2];
            }
        }
        // Pattern générique pour première grande image
        elseif (preg_match('/<img[^>]*src=["\']([^"\']+\.(jpg|jpeg|png|webp)[^"\']*)["\'][^>]*>/i', $html, $matches)) {
            $imgUrl = $matches[1];
            // Ignorer les petites icônes et logos
            if (!preg_match('/(icon|logo|sprite|badge|button)/i', $imgUrl)) {
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
