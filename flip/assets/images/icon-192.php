<?php
// Génère une icône PWA 192x192
header('Content-Type: image/png');

$size = 192;
$img = imagecreatetruecolor($size, $size);

// Couleurs
$bgColor = imagecolorallocate($img, 30, 58, 95); // #1e3a5f
$white = imagecolorallocate($img, 255, 255, 255);

// Fond
imagefilledrectangle($img, 0, 0, $size, $size, $bgColor);

// Bordure arrondie (simulée avec cercles aux coins)
$radius = 30;
imagefilledellipse($img, $radius, $radius, $radius * 2, $radius * 2, $bgColor);
imagefilledellipse($img, $size - $radius, $radius, $radius * 2, $radius * 2, $bgColor);
imagefilledellipse($img, $radius, $size - $radius, $radius * 2, $radius * 2, $bgColor);
imagefilledellipse($img, $size - $radius, $size - $radius, $radius * 2, $radius * 2, $bgColor);

// Icône maison simple
$centerX = $size / 2;
$centerY = $size / 2;
$houseSize = 80;

// Toit (triangle)
$roof = [
    $centerX, $centerY - 50,           // Pointe
    $centerX - 50, $centerY - 5,       // Gauche
    $centerX + 50, $centerY - 5        // Droite
];
imagefilledpolygon($img, $roof, 3, $white);

// Corps de la maison
imagefilledrectangle($img, $centerX - 40, $centerY - 5, $centerX + 40, $centerY + 45, $white);

// Porte
imagefilledrectangle($img, $centerX - 12, $centerY + 10, $centerX + 12, $centerY + 45, $bgColor);

// Lettre F
imagestring($img, 5, $centerX - 5, $centerY + 18, 'F', $white);

imagepng($img);
imagedestroy($img);
