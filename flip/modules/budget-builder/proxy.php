<?php
/**
 * Proxy pour charger des sites externes dans un iframe
 * Permet la sélection de prix directement dans l'app
 */

header('Content-Type: text/html; charset=utf-8');

$url = $_GET['url'] ?? '';

if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
    die('URL invalide');
}

$parsedUrl = parse_url($url);
$baseUrl = $parsedUrl['scheme'] . '://' . $parsedUrl['host'];

// Récupérer le contenu
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => 5,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_ENCODING => 'gzip, deflate',
    CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36',
    CURLOPT_HTTPHEADER => [
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language: fr-CA,fr;q=0.9,en;q=0.8',
    ]
]);
$html = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200 || empty($html)) {
    die('<div style="padding:40px;text-align:center;font-family:sans-serif;">
        <h2>⚠️ Impossible de charger cette page</h2>
        <p>Le site bloque les requêtes externes (code: ' . $httpCode . ')</p>
        <p>Essayez avec un autre site ou utilisez le bookmarklet.</p>
    </div>');
}

// Réécrire les URLs relatives en URLs absolues
$html = preg_replace('/(href|src)=["\']\/([^\/])/i', '$1="' . $baseUrl . '/$2', $html);
$html = preg_replace('/(href|src)=["\']\//i', '$1="' . $baseUrl . '/', $html);

// Supprimer les scripts qui pourraient causer des problèmes
$html = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $html);

// Injecter notre script de sélection avant </body>
$selectionScript = '
<style>
    * { cursor: crosshair !important; }
    .flip-price-hover {
        outline: 3px solid #198754 !important;
        background: rgba(25, 135, 84, 0.2) !important;
        cursor: pointer !important;
    }
    .flip-toolbar {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        background: linear-gradient(135deg, #1e3a5f 0%, #0d253f 100%);
        color: white;
        padding: 10px 20px;
        z-index: 999999;
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        display: flex;
        align-items: center;
        justify-content: space-between;
        box-shadow: 0 2px 10px rgba(0,0,0,0.3);
    }
    .flip-toolbar button {
        background: #dc3545;
        color: white;
        border: none;
        padding: 8px 16px;
        border-radius: 4px;
        cursor: pointer;
        font-weight: bold;
    }
    .flip-toolbar button:hover {
        background: #c82333;
    }
    body { padding-top: 50px !important; }
</style>
<div class="flip-toolbar">
    <span>👆 <strong>Cliquez sur le prix</strong> pour le sélectionner</span>
    <button onclick="window.parent.postMessage({type:\'close\'},\'*\')">✕ Fermer</button>
</div>
<script>
    var hoveredEl = null;
    document.addEventListener("mouseover", function(e) {
        if (hoveredEl) hoveredEl.classList.remove("flip-price-hover");
        hoveredEl = e.target;
        hoveredEl.classList.add("flip-price-hover");
    });
    document.addEventListener("click", function(e) {
        e.preventDefault();
        e.stopPropagation();
        var text = e.target.innerText || e.target.textContent || "";
        var match = text.match(/[\d\s,.]+/);
        if (match) {
            var price = match[0].replace(/\s/g, "").replace(",", ".");
            price = parseFloat(price);
            if (price > 0 && price < 1000000) {
                window.parent.postMessage({type: "price", value: price}, "*");
            } else {
                alert("Élément sélectionné ne contient pas un prix valide");
            }
        } else {
            alert("Aucun nombre trouvé dans cet élément");
        }
    }, true);
</script>';

$html = str_replace('</body>', $selectionScript . '</body>', $html);

echo $html;
