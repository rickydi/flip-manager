<?php
/**
 * Script pour générer les icônes PWA
 * Exécuter une seule fois: php generate-icons.php
 */

function generateIcon($size, $filename) {
    $img = imagecreatetruecolor($size, $size);

    // Anti-aliasing
    imagealphablending($img, true);
    imagesavealpha($img, true);

    // Couleurs
    $bgColor = imagecolorallocate($img, 30, 58, 95); // #1e3a5f
    $white = imagecolorallocate($img, 255, 255, 255);
    $accent = imagecolorallocate($img, 16, 185, 129); // #10b981 vert

    // Fond avec coins arrondis
    $radius = $size * 0.15;
    imagefilledrectangle($img, 0, 0, $size, $size, $bgColor);

    // Centre
    $centerX = $size / 2;
    $centerY = $size / 2;

    // Échelle
    $scale = $size / 192;

    // Icône maison
    $roofHeight = 50 * $scale;
    $houseWidth = 80 * $scale;
    $houseHeight = 50 * $scale;

    // Toit (triangle)
    $roof = [
        $centerX, $centerY - $roofHeight - 10 * $scale,  // Pointe
        $centerX - $houseWidth/2 - 10*$scale, $centerY - 10 * $scale,  // Gauche
        $centerX + $houseWidth/2 + 10*$scale, $centerY - 10 * $scale   // Droite
    ];
    imagefilledpolygon($img, $roof, 3, $white);

    // Corps de la maison
    imagefilledrectangle($img,
        $centerX - $houseWidth/2,
        $centerY - 10 * $scale,
        $centerX + $houseWidth/2,
        $centerY + $houseHeight,
        $white
    );

    // Porte
    $doorWidth = 25 * $scale;
    $doorHeight = 40 * $scale;
    imagefilledrectangle($img,
        $centerX - $doorWidth/2,
        $centerY + $houseHeight - $doorHeight,
        $centerX + $doorWidth/2,
        $centerY + $houseHeight,
        $bgColor
    );

    // Flèche vers le haut (flip = profit up)
    $arrowSize = 20 * $scale;
    $arrowX = $centerX;
    $arrowY = $centerY + 5 * $scale;

    // Symbole $ dans la porte
    $fontSize = $size > 200 ? 5 : 4;
    $textX = $centerX - 4 * $scale;
    $textY = $centerY + $houseHeight - $doorHeight + 10 * $scale;
    imagestring($img, $fontSize, $textX, $textY, '$', $accent);

    // Sauvegarder
    imagepng($img, __DIR__ . '/' . $filename);
    imagedestroy($img);

    echo "Généré: $filename ($size x $size)\n";
}

// Générer les deux tailles
generateIcon(192, 'icon-192.png');
generateIcon(512, 'icon-512.png');

echo "\nIcônes générées avec succès!\n";
