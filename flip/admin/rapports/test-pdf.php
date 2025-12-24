<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "Step 1: Loading config...\n";
require_once __DIR__ . '/../../config.php';

echo "Step 2: Loading auth...\n";
require_once __DIR__ . '/../../includes/auth.php';

echo "Step 3: Loading functions...\n";
require_once __DIR__ . '/../../includes/functions.php';

echo "Step 4: Loading calculs...\n";
require_once __DIR__ . '/../../includes/calculs.php';

echo "Step 5: Loading autoload...\n";
require_once __DIR__ . '/../../../vendor/autoload.php';

echo "Step 6: Using Dompdf...\n";
use Dompdf\Dompdf;
use Dompdf\Options;

echo "Step 7: Creating options...\n";
$options = new Options();
$options->set('isHtml5ParserEnabled', true);

echo "Step 8: Creating Dompdf instance...\n";
$dompdf = new Dompdf($options);

echo "Step 9: Loading HTML...\n";
$dompdf->loadHtml('<h1>Test PDF</h1>');

echo "Step 10: Rendering...\n";
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

echo "SUCCESS - All steps completed!\n";
