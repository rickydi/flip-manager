<?php
/**
 * Export PDF du rapport fiscal
 * Flip Manager
 */

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
$options->set('isRemoteEnabled', true);
$options->set('defaultFont', 'DejaVu Sans');

$dompdf = new Dompdf($options);

// Générer le HTML du rapport
$html = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: DejaVu Sans, Arial, sans-serif;
            font-size: 11pt;
            color: #333;
            line-height: 1.4;
        }
        .header {
            background: linear-gradient(135deg, #1e3a5f 0%, #2d5a87 100%);
            color: white;
            padding: 25px;
            margin-bottom: 20px;
        }
        .header h1 {
            font-size: 24pt;
            margin-bottom: 5px;
        }
        .header .subtitle {
            font-size: 12pt;
            opacity: 0.9;
        }
        .header .date {
            font-size: 10pt;
            opacity: 0.7;
            margin-top: 10px;
        }
        .section {
            margin-bottom: 25px;
            page-break-inside: avoid;
        }
        .section-title {
            background: #f1f5f9;
            padding: 10px 15px;
            font-size: 13pt;
            font-weight: bold;
            color: #1e3a5f;
            border-left: 4px solid #1e3a5f;
            margin-bottom: 15px;
        }
        .summary-grid {
            display: table;
            width: 100%;
            margin-bottom: 20px;
        }
        .summary-item {
            display: table-cell;
            width: 25%;
            text-align: center;
            padding: 15px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
        }
        .summary-item .value {
            font-size: 18pt;
            font-weight: bold;
            color: #1e3a5f;
        }
        .summary-item .label {
            font-size: 9pt;
            color: #64748b;
            text-transform: uppercase;
            margin-top: 5px;
        }
        .summary-item.green .value { color: #10b981; }
        .summary-item.red .value { color: #ef4444; }
        .summary-item.blue .value { color: #3b82f6; }

        .progress-bar {
            background: #e2e8f0;
            height: 20px;
            border-radius: 10px;
            overflow: hidden;
            margin: 10px 0;
        }
        .progress-fill {
            height: 100%;
            border-radius: 10px;
        }
        .progress-fill.safe { background: #10b981; }
        .progress-fill.warning { background: #f59e0b; }
        .progress-fill.danger { background: #ef4444; }

        .progress-labels {
            display: table;
            width: 100%;
            font-size: 9pt;
            color: #64748b;
        }
        .progress-labels span {
            display: table-cell;
        }
        .progress-labels .center { text-align: center; }
        .progress-labels .right { text-align: right; }

        table.projects {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        table.projects th {
            background: #1e3a5f;
            color: white;
            padding: 10px;
            text-align: left;
            font-size: 10pt;
        }
        table.projects th.right { text-align: right; }
        table.projects td {
            padding: 10px;
            border-bottom: 1px solid #e2e8f0;
            font-size: 10pt;
        }
        table.projects td.right { text-align: right; }
        table.projects tr:nth-child(even) {
            background: #f8fafc;
        }
        table.projects .positive { color: #10b981; font-weight: bold; }
        table.projects .negative { color: #ef4444; font-weight: bold; }

        .total-row {
            background: #1e3a5f !important;
            color: white;
            font-weight: bold;
        }
        .total-row td { color: white !important; }

        .tax-info {
            background: #fef3c7;
            border: 1px solid #f59e0b;
            padding: 15px;
            border-radius: 5px;
            margin-top: 20px;
        }
        .tax-info h4 {
            color: #92400e;
            margin-bottom: 10px;
        }
        .tax-info p {
            font-size: 10pt;
            color: #78350f;
        }

        .footer {
            position: fixed;
            bottom: 20px;
            left: 0;
            right: 0;
            text-align: center;
            font-size: 9pt;
            color: #94a3b8;
        }
        .page-break {
            page-break-before: always;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>RAPPORT FISCAL ' . $anneeFiscale . '</h1>
        <div class="subtitle">Flip Manager - Suivi des profits et impôts</div>
        <div class="date">Généré le ' . date('d/m/Y à H:i') . '</div>
    </div>

    <div class="section">
        <div class="section-title">RÉSUMÉ DE L\'ANNÉE</div>

        <div class="summary-grid">
            <div class="summary-item">
                <div class="value">' . count($resumeFiscal['projets_vendus']) . '</div>
                <div class="label">Flips vendus</div>
            </div>
            <div class="summary-item green">
                <div class="value">' . number_format($resumeFiscal['profit_realise'], 0, ',', ' ') . ' $</div>
                <div class="label">Profit brut</div>
            </div>
            <div class="summary-item red">
                <div class="value">' . number_format($resumeFiscal['impot_realise'], 0, ',', ' ') . ' $</div>
                <div class="label">Impôts</div>
            </div>
            <div class="summary-item blue">
                <div class="value">' . number_format($resumeFiscal['profit_net_realise'], 0, ',', ' ') . ' $</div>
                <div class="label">Profit net</div>
            </div>
        </div>
    </div>

    <div class="section">
        <div class="section-title">UTILISATION DU SEUIL DPE (500 000 $)</div>

        <div class="progress-bar">';

$gaugeClass = 'safe';
if ($resumeFiscal['pourcentage_utilise'] >= 75) $gaugeClass = 'warning';
if ($resumeFiscal['pourcentage_utilise'] >= 100) $gaugeClass = 'danger';
$width = min(100, $resumeFiscal['pourcentage_utilise']);

$html .= '
            <div class="progress-fill ' . $gaugeClass . '" style="width: ' . $width . '%;"></div>
        </div>
        <div class="progress-labels">
            <span>0 $</span>
            <span class="center">' . number_format($resumeFiscal['pourcentage_utilise'], 1) . '% utilisé</span>
            <span class="right">500 000 $</span>
        </div>

        <div class="tax-info">
            <h4>Information fiscale</h4>
            <p>
                <strong>Seuil DPE utilisé:</strong> ' . number_format($resumeFiscal['profit_realise'], 0, ',', ' ') . ' $ sur 500 000 $<br>
                <strong>Seuil restant à 12,2%:</strong> ' . number_format($resumeFiscal['seuil_restant'], 0, ',', ' ') . ' $<br>
                <strong>Taux effectif:</strong> ' . number_format($resumeFiscal['taux_effectif_realise'] * 100, 2) . ' %
            </p>
        </div>
    </div>';

if (!empty($resumeFiscal['projets_vendus'])) {
    $html .= '
    <div class="section">
        <div class="section-title">DÉTAIL DES PROJETS VENDUS</div>

        <table class="projects">
            <tr>
                <th>Projet</th>
                <th>Date de vente</th>
                <th class="right">Profit</th>
                <th class="right">Cumulatif</th>
                <th class="right">Taux</th>
                <th class="right">Impôt</th>
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
    $projetsRentables = array_filter($resumeFiscal['projets_en_cours'], fn($p) => $p['profit_estime'] > 0);

    if (!empty($projetsRentables)) {
        $html .= '
    <div class="section">
        <div class="section-title">PROJETS EN COURS (PROJECTIONS)</div>

        <table class="projects">
            <tr>
                <th>Projet</th>
                <th>Statut</th>
                <th class="right">Profit estimé</th>
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

        <div class="tax-info" style="margin-top: 15px; background: #dbeafe; border-color: #3b82f6;">
            <h4 style="color: #1e40af;">Projection si tous vendus en ' . $anneeFiscale . '</h4>
            <p style="color: #1e3a8a;">
                <strong>Profit total:</strong> ' . number_format($resumeFiscal['profit_total_projection'], 0, ',', ' ') . ' $<br>
                <strong>Impôts estimés:</strong> ' . number_format($resumeFiscal['impot_projection'], 0, ',', ' ') . ' $<br>
                <strong>Taux effectif:</strong> ' . number_format($resumeFiscal['taux_effectif_projection'] * 100, 2) . ' %
            </p>
        </div>
    </div>';
    }
}

$html .= '
    <div class="footer">
        Flip Manager - Rapport fiscal ' . $anneeFiscale . ' - Page 1
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
