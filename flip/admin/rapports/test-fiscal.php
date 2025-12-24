<?php
/**
 * Test - Génère le HTML du rapport fiscal sans PDF
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

try {

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/calculs.php';

requireAdmin();

$anneeFiscale = isset($_GET['annee']) ? (int) $_GET['annee'] : (int) date('Y');
$resumeFiscal = obtenirResumeAnneeFiscale($pdo, $anneeFiscale);

$gaugeClass = '';
if ($resumeFiscal['pourcentage_utilise'] >= 75) $gaugeClass = 'warning';
if ($resumeFiscal['pourcentage_utilise'] >= 100) $gaugeClass = 'danger';
$width = min(100, $resumeFiscal['pourcentage_utilise']);

// Génération HTML identique à fiscal-pdf.php
$html = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body {
            font-family: DejaVu Sans, Arial, sans-serif;
            font-size: 11pt;
            color: #333;
            margin: 20px;
        }
        .header {
            background-color: #1e3a5f;
            color: white;
            padding: 20px;
            margin-bottom: 20px;
        }
        .header h1 {
            font-size: 22pt;
            margin: 0 0 5px 0;
        }
        .section-title {
            background-color: #f1f5f9;
            padding: 8px 12px;
            font-size: 12pt;
            font-weight: bold;
            color: #1e3a5f;
            border-left: 4px solid #1e3a5f;
            margin-bottom: 12px;
        }
        table.summary td {
            text-align: center;
            padding: 12px;
            background-color: #f8fafc;
            border: 1px solid #e2e8f0;
        }
        .value { font-size: 16pt; font-weight: bold; }
        .value-green { color: #10b981; }
        .value-red { color: #ef4444; }
        .value-blue { color: #3b82f6; }
        .label { font-size: 8pt; color: #64748b; }
    </style>
</head>
<body>
    <div class="header">
        <h1>RAPPORT FISCAL ' . $anneeFiscale . '</h1>
        <div>Flip Manager - Suivi des profits et impots</div>
    </div>

    <div class="section">
        <div class="section-title">RESUME DE L\'ANNEE</div>
        <table class="summary">
            <tr>
                <td>
                    <div class="value">' . count($resumeFiscal['projets_vendus']) . '</div>
                    <div class="label">Flips vendus</div>
                </td>
                <td>
                    <div class="value value-green">' . number_format($resumeFiscal['profit_realise'], 0, ',', ' ') . ' $</div>
                    <div class="label">Profit brut</div>
                </td>
                <td>
                    <div class="value value-red">' . number_format($resumeFiscal['impot_realise'], 0, ',', ' ') . ' $</div>
                    <div class="label">Impots</div>
                </td>
                <td>
                    <div class="value value-blue">' . number_format($resumeFiscal['profit_net_realise'], 0, ',', ' ') . ' $</div>
                    <div class="label">Profit net</div>
                </td>
            </tr>
        </table>
    </div>
</body>
</html>';

echo "<h2>Test HTML fiscal (sans PDF)</h2>";
echo "<p style='color:green'>HTML genere avec succes!</p>";
echo "<hr>";
echo $html;
echo "<hr>";
echo "<h3>Maintenant test du PDF...</h3>";

require_once __DIR__ . '/../../../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

$options = new Options();
$options->set('isHtml5ParserEnabled', true);
$options->set('defaultFont', 'DejaVu Sans');

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

echo "<p style='color:green'>PDF rendu avec succes! Taille: " . strlen($dompdf->output()) . " bytes</p>";
echo "<p><a href='fiscal-pdf.php?annee=" . $anneeFiscale . "'>Telecharger le PDF</a></p>";

} catch (Throwable $e) {
    echo "<h2 style='color:red'>ERREUR!</h2>";
    echo "<p><strong>Message:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p><strong>Fichier:</strong> " . $e->getFile() . " ligne " . $e->getLine() . "</p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}
