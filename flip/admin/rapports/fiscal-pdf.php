<?php
/**
 * Export PDF du rapport fiscal
 * Flip Manager
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

try {

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/calculs.php';
require_once __DIR__ . '/../../../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

requireAdmin();

// Année fiscale
$anneeFiscale = isset($_GET['annee']) ? (int) $_GET['annee'] : (int) date('Y');
$resumeFiscal = obtenirResumeAnneeFiscale($pdo, $anneeFiscale);

// Configuration Dompdf
$options = new Options();
$options->set('isHtml5ParserEnabled', true);
$options->set('defaultFont', 'DejaVu Sans');

$dompdf = new Dompdf($options);

// Générer le HTML du rapport - CSS simplifié pour Dompdf
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
        .header .subtitle {
            font-size: 11pt;
            color: #ccc;
        }
        .header .date {
            font-size: 9pt;
            color: #aaa;
            margin-top: 8px;
        }
        .section {
            margin-bottom: 20px;
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
        table.summary {
            width: 100%;
            margin-bottom: 15px;
        }
        table.summary td {
            width: 25%;
            text-align: center;
            padding: 12px;
            background-color: #f8fafc;
            border: 1px solid #e2e8f0;
        }
        .value {
            font-size: 16pt;
            font-weight: bold;
            color: #1e3a5f;
        }
        .value-green { color: #10b981; }
        .value-red { color: #ef4444; }
        .value-blue { color: #3b82f6; }
        .label {
            font-size: 8pt;
            color: #64748b;
            text-transform: uppercase;
            margin-top: 4px;
        }
        .progress-container {
            background-color: #e2e8f0;
            height: 18px;
            margin: 8px 0;
        }
        .progress-bar {
            height: 18px;
            background-color: #10b981;
        }
        .progress-bar.warning { background-color: #f59e0b; }
        .progress-bar.danger { background-color: #ef4444; }
        table.progress-labels {
            width: 100%;
            font-size: 9pt;
            color: #64748b;
        }
        table.progress-labels td.center { text-align: center; }
        table.progress-labels td.right { text-align: right; }
        .tax-info {
            background-color: #fef3c7;
            border: 1px solid #f59e0b;
            padding: 12px;
            margin-top: 15px;
        }
        .tax-info h4 {
            color: #92400e;
            margin: 0 0 8px 0;
            font-size: 11pt;
        }
        .tax-info p {
            font-size: 10pt;
            color: #78350f;
            margin: 0;
        }
        .tax-info-blue {
            background-color: #dbeafe;
            border: 1px solid #3b82f6;
        }
        .tax-info-blue h4 { color: #1e40af; }
        .tax-info-blue p { color: #1e3a8a; }
        table.projects {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
        }
        table.projects th {
            background-color: #1e3a5f;
            color: white;
            padding: 8px;
            text-align: left;
            font-size: 9pt;
        }
        table.projects th.right { text-align: right; }
        table.projects td {
            padding: 8px;
            border-bottom: 1px solid #e2e8f0;
            font-size: 9pt;
        }
        table.projects td.right { text-align: right; }
        .positive { color: #10b981; font-weight: bold; }
        .negative { color: #ef4444; font-weight: bold; }
        .total-row {
            background-color: #1e3a5f;
        }
        .total-row td {
            color: white;
            font-weight: bold;
        }
        .footer {
            text-align: center;
            font-size: 8pt;
            color: #94a3b8;
            margin-top: 30px;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>RAPPORT FISCAL ' . $anneeFiscale . '</h1>
        <div class="subtitle">Flip Manager - Suivi des profits et impots</div>
        <div class="date">Genere le ' . date('d/m/Y') . ' a ' . date('H:i') . '</div>
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

    <div class="section">
        <div class="section-title">UTILISATION DU SEUIL DPE (500 000 $)</div>';

$gaugeClass = '';
if ($resumeFiscal['pourcentage_utilise'] >= 75) $gaugeClass = 'warning';
if ($resumeFiscal['pourcentage_utilise'] >= 100) $gaugeClass = 'danger';
$width = min(100, $resumeFiscal['pourcentage_utilise']);

$html .= '
        <div class="progress-container">
            <div class="progress-bar ' . $gaugeClass . '" style="width: ' . $width . '%;"></div>
        </div>
        <table class="progress-labels">
            <tr>
                <td>0 $</td>
                <td class="center">' . number_format($resumeFiscal['pourcentage_utilise'], 1) . '% utilise</td>
                <td class="right">500 000 $</td>
            </tr>
        </table>

        <div class="tax-info">
            <h4>Information fiscale</h4>
            <p>
                <strong>Seuil DPE utilise:</strong> ' . number_format($resumeFiscal['profit_realise'], 0, ',', ' ') . ' $ sur 500 000 $<br>
                <strong>Seuil restant a 12,2%:</strong> ' . number_format($resumeFiscal['seuil_restant'], 0, ',', ' ') . ' $<br>
                <strong>Taux effectif:</strong> ' . number_format($resumeFiscal['taux_effectif_realise'] * 100, 2) . ' %
            </p>
        </div>
    </div>';

if (!empty($resumeFiscal['projets_vendus'])) {
    $html .= '
    <div class="section">
        <div class="section-title">DETAIL DES PROJETS VENDUS</div>

        <table class="projects">
            <tr>
                <th>Projet</th>
                <th>Date de vente</th>
                <th class="right">Profit</th>
                <th class="right">Cumulatif</th>
                <th class="right">Taux</th>
                <th class="right">Impot</th>
            </tr>';

    $profitCumul = 0;
    foreach ($resumeFiscal['projets_vendus'] as $pv) {
        $impotProjet = calculerImpotAvecCumulatif($pv['profit'], $profitCumul);
        $profitCumul = $pv['profit_cumulatif'];
        $profitClass = $pv['profit'] >= 0 ? 'positive' : 'negative';

        $html .= '
            <tr>
                <td>' . htmlspecialchars($pv['nom']) . '</td>
                <td>' . date('d/m/Y', strtotime($pv['date_vente'])) . '</td>
                <td class="right ' . $profitClass . '">' . number_format($pv['profit'], 0, ',', ' ') . ' $</td>
                <td class="right">' . number_format($pv['profit_cumulatif'], 0, ',', ' ') . ' $</td>
                <td class="right">' . $impotProjet['taux_affiche'] . '</td>
                <td class="right">' . number_format($impotProjet['impot'], 0, ',', ' ') . ' $</td>
            </tr>';
    }

    $html .= '
            <tr class="total-row">
                <td colspan="2"><strong>TOTAL</strong></td>
                <td class="right">' . number_format($resumeFiscal['profit_realise'], 0, ',', ' ') . ' $</td>
                <td class="right">-</td>
                <td class="right">-</td>
                <td class="right">' . number_format($resumeFiscal['impot_realise'], 0, ',', ' ') . ' $</td>
            </tr>
        </table>
    </div>';
}

if (!empty($resumeFiscal['projets_en_cours'])) {
    $projetsRentables = [];
    foreach ($resumeFiscal['projets_en_cours'] as $p) {
        if ($p['profit_estime'] > 0) {
            $projetsRentables[] = $p;
        }
    }

    if (!empty($projetsRentables)) {
        $html .= '
    <div class="section">
        <div class="section-title">PROJETS EN COURS (PROJECTIONS)</div>

        <table class="projects">
            <tr>
                <th>Projet</th>
                <th>Statut</th>
                <th class="right">Profit estime</th>
                <th class="right">Taux si vendu</th>
            </tr>';

        $profitCumulProjection = $resumeFiscal['profit_realise'];
        foreach ($projetsRentables as $pc) {
            $impotProjection = calculerImpotAvecCumulatif($pc['profit_estime'], $profitCumulProjection);

            $html .= '
            <tr>
                <td>' . htmlspecialchars($pc['nom']) . '</td>
                <td>' . ucfirst(str_replace('_', ' ', $pc['statut'])) . '</td>
                <td class="right positive">' . number_format($pc['profit_estime'], 0, ',', ' ') . ' $</td>
                <td class="right">' . $impotProjection['taux_affiche'] . '</td>
            </tr>';

            $profitCumulProjection += $pc['profit_estime'];
        }

        $html .= '
        </table>

        <div class="tax-info tax-info-blue">
            <h4>Projection si tous vendus en ' . $anneeFiscale . '</h4>
            <p>
                <strong>Profit total:</strong> ' . number_format($resumeFiscal['profit_total_projection'], 0, ',', ' ') . ' $<br>
                <strong>Impots estimes:</strong> ' . number_format($resumeFiscal['impot_projection'], 0, ',', ' ') . ' $<br>
                <strong>Taux effectif:</strong> ' . number_format($resumeFiscal['taux_effectif_projection'] * 100, 2) . ' %
            </p>
        </div>
    </div>';
    }
}

$html .= '
    <div class="footer">
        Flip Manager - Rapport fiscal ' . $anneeFiscale . '
    </div>
</body>
</html>';

// Générer le PDF
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

// Télécharger le PDF
$filename = 'Rapport_Fiscal_' . $anneeFiscale . '_' . date('Ymd') . '.pdf';
$dompdf->stream($filename, ['Attachment' => true]);

} catch (Throwable $e) {
    header('Content-Type: text/html; charset=utf-8');
    echo "<h2 style='color:red'>ERREUR PDF</h2>";
    echo "<p><strong>Message:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p><strong>Fichier:</strong> " . $e->getFile() . " ligne " . $e->getLine() . "</p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}
