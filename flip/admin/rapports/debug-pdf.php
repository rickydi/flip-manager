<?php
/**
 * Debug PDF - Test minimal
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>Test Dompdf</h2>";

echo "<p>1. Chargement config... ";
require_once __DIR__ . '/../../config.php';
echo "OK</p>";

echo "<p>2. Chargement auth... ";
require_once __DIR__ . '/../../includes/auth.php';
echo "OK</p>";

echo "<p>3. Vérification admin... ";
requireAdmin();
echo "OK</p>";

echo "<p>4. Chargement autoload... ";
$autoloadPath = __DIR__ . '/../../../vendor/autoload.php';
echo "Chemin: " . $autoloadPath . "<br>";
echo "Existe: " . (file_exists($autoloadPath) ? 'OUI' : 'NON') . "<br>";
require_once $autoloadPath;
echo "OK</p>";

echo "<p>5. Test Dompdf class... ";
if (class_exists('Dompdf\Dompdf')) {
    echo "Classe existe!</p>";
} else {
    echo "Classe N'EXISTE PAS!</p>";
    die();
}

echo "<p>6. Création instance Dompdf... ";
try {
    $options = new Dompdf\Options();
    $options->set('isHtml5ParserEnabled', true);
    $dompdf = new Dompdf\Dompdf($options);
    echo "OK</p>";
} catch (Throwable $e) {
    echo "ERREUR: " . $e->getMessage() . "</p>";
    die();
}

echo "<p>7. Chargement HTML simple... ";
try {
    $dompdf->loadHtml('<html><body><h1>Test</h1></body></html>');
    echo "OK</p>";
} catch (Throwable $e) {
    echo "ERREUR: " . $e->getMessage() . "</p>";
    die();
}

echo "<p>8. Rendu PDF... ";
try {
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    echo "OK</p>";
} catch (Throwable $e) {
    echo "ERREUR: " . $e->getMessage() . "</p>";
    die();
}

echo "<h2 style='color:green'>TOUT FONCTIONNE!</h2>";
echo "<p><a href='fiscal-pdf.php'>Tester le vrai PDF fiscal</a></p>";
