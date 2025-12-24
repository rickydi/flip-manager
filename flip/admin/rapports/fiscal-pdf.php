<?php
/**
 * Export PDF du rapport fiscal
 * Flip Manager - Format professionnel 8.5x11
 */

require_once __DIR__ . '/../../../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/calculs.php';

requireAdmin();

// Année fiscale
$anneeFiscale = isset($_GET['annee']) ? (int) $_GET['annee'] : (int) date('Y');
$resumeFiscal = obtenirResumeAnneeFiscale($pdo, $anneeFiscale);

// Configuration Dompdf
$options = new Options();
$options->set('isHtml5ParserEnabled', true);
$options->set('defaultFont', 'DejaVu Sans');
$options->set('dpi', 96);

$dompdf = new Dompdf($options);

// Calculs pour le rapport
$nbProjetsVendus = count($resumeFiscal['projets_vendus']);
$profitBrut = $resumeFiscal['profit_realise'];
$impotTotal = $resumeFiscal['impot_realise'];
$profitNet = $resumeFiscal['profit_net_realise'];
$tauxEffectif = $resumeFiscal['taux_effectif_realise'] * 100;
$seuilRestant = $resumeFiscal['seuil_restant'];
$pourcentageUtilise = $resumeFiscal['pourcentage_utilise'];

// Couleur de la jauge
$gaugeColor = '#22c55e'; // vert
if ($pourcentageUtilise >= 75) $gaugeColor = '#f59e0b'; // orange
if ($pourcentageUtilise >= 100) $gaugeColor = '#ef4444'; // rouge
$gaugeWidth = min(100, $pourcentageUtilise);

$html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        @page {
            margin: 50px 60px;
        }
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: DejaVu Sans, Arial, sans-serif;
            font-size: 10pt;
            color: #1f2937;
            line-height: 1.4;
            padding: 0 10px;
        }

        /* En-tête */
        .header {
            background-color: #0f172a;
            color: white;
            padding: 20px;
            margin-bottom: 20px;
        }
        .header-content {
            display: table;
            width: 100%;
        }
        .header-left {
            display: table-cell;
            vertical-align: middle;
        }
        .header-right {
            display: table-cell;
            text-align: right;
            vertical-align: middle;
        }
        .company-name {
            font-size: 24pt;
            font-weight: bold;
            letter-spacing: 1px;
        }
        .report-title {
            font-size: 11pt;
            color: #94a3b8;
            margin-top: 5px;
        }
        .report-date {
            font-size: 9pt;
            color: #64748b;
        }
        .fiscal-year {
            font-size: 28pt;
            font-weight: bold;
        }

        /* Section */
        .section {
            margin-bottom: 20px;
        }
        .section-header {
            background-color: #1e3a5f;
            color: white;
            padding: 8px 15px;
            font-size: 11pt;
            font-weight: bold;
            letter-spacing: 0.5px;
            margin-bottom: 15px;
        }

        /* Cartes de résumé */
        .summary-cards {
            width: 100%;
            margin-bottom: 20px;
        }
        .summary-cards td {
            width: 25%;
            padding: 15px;
            text-align: center;
            vertical-align: top;
            border: 1px solid #e5e7eb;
        }
        .card-value {
            font-size: 20pt;
            font-weight: bold;
            margin-bottom: 5px;
        }
        .card-label {
            font-size: 8pt;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .card-sublabel {
            font-size: 7pt;
            color: #9ca3af;
            margin-top: 3px;
        }
        .color-green { color: #059669; }
        .color-red { color: #dc2626; }
        .color-blue { color: #2563eb; }
        .color-gray { color: #374151; }

        /* Jauge DPE */
        .dpe-section {
            background-color: #f8fafc;
            border: 1px solid #e2e8f0;
            padding: 15px;
            margin-bottom: 20px;
        }
        .dpe-title {
            font-size: 10pt;
            font-weight: bold;
            color: #334155;
            margin-bottom: 10px;
        }
        .gauge-container {
            background-color: #e2e8f0;
            height: 25px;
            position: relative;
            margin-bottom: 8px;
        }
        .gauge-fill {
            height: 25px;
            position: absolute;
            left: 0;
            top: 0;
        }
        .gauge-text {
            position: absolute;
            width: 100%;
            text-align: center;
            line-height: 25px;
            font-size: 9pt;
            font-weight: bold;
            color: #1f2937;
        }
        .gauge-labels {
            width: 100%;
            font-size: 8pt;
            color: #64748b;
        }
        .gauge-labels td.right { text-align: right; }

        .dpe-info {
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px solid #e2e8f0;
        }
        .dpe-info table {
            width: 100%;
            font-size: 9pt;
        }
        .dpe-info td {
            padding: 3px 0;
        }
        .dpe-info td.label {
            color: #64748b;
            width: 60%;
        }
        .dpe-info td.value {
            text-align: right;
            font-weight: bold;
            color: #1f2937;
        }

        /* Tableau des projets */
        table.projects {
            width: 100%;
            border-collapse: collapse;
            font-size: 9pt;
        }
        table.projects th {
            background-color: #374151;
            color: white;
            padding: 10px 8px;
            text-align: left;
            font-weight: bold;
            font-size: 8pt;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        table.projects th.right { text-align: right; }
        table.projects td {
            padding: 10px 8px;
            border-bottom: 1px solid #e5e7eb;
        }
        table.projects td.right { text-align: right; }
        table.projects tr.alt { background-color: #f9fafb; }
        .text-green { color: #059669; font-weight: bold; }
        .text-red { color: #dc2626; font-weight: bold; }

        table.projects tr.total-row {
            background-color: #1e3a5f;
        }
        table.projects tr.total-row td {
            color: white;
            font-weight: bold;
            border-bottom: none;
        }

        /* Projections */
        .projection-box {
            background-color: #eff6ff;
            border: 1px solid #bfdbfe;
            padding: 15px;
            margin-top: 15px;
        }
        .projection-title {
            font-size: 10pt;
            font-weight: bold;
            color: #1e40af;
            margin-bottom: 10px;
        }
        .projection-grid {
            width: 100%;
        }
        .projection-grid td {
            width: 33.33%;
            text-align: center;
            padding: 8px;
        }
        .projection-value {
            font-size: 14pt;
            font-weight: bold;
            color: #1e40af;
        }
        .projection-label {
            font-size: 7pt;
            color: #3b82f6;
            text-transform: uppercase;
        }

        /* Pied de page */
        .footer {
            margin-top: 30px;
            padding-top: 10px;
            font-size: 7pt;
            color: #9ca3af;
            border-top: 1px solid #e5e7eb;
        }
        .footer-content {
            width: 100%;
        }
        .footer-left {
            display: table-cell;
        }
        .footer-right {
            display: table-cell;
            text-align: right;
        }

        /* Notes légales */
        .legal-note {
            font-size: 7pt;
            color: #9ca3af;
            font-style: italic;
            margin-top: 20px;
            padding-top: 10px;
            border-top: 1px solid #e5e7eb;
        }
    </style>
</head>
<body>
    <div class="header">
        <table class="header-content">
            <tr>
                <td class="header-left">
                    <div class="company-name">FLIP MANAGER</div>
                    <div class="report-title">Rapport Fiscal Annuel</div>
                </td>
                <td class="header-right">
                    <div class="fiscal-year">' . $anneeFiscale . '</div>
                    <div class="report-date">Genere le ' . date('d/m/Y') . '</div>
                </td>
            </tr>
        </table>
    </div>

    <div class="section">
        <div class="section-header">SOMMAIRE EXECUTIF</div>

        <table class="summary-cards">
            <tr>
                <td>
                    <div class="card-value color-gray">' . $nbProjetsVendus . '</div>
                    <div class="card-label">Projets vendus</div>
                    <div class="card-sublabel">Transactions completees</div>
                </td>
                <td>
                    <div class="card-value color-green">' . number_format($profitBrut, 0, ',', ' ') . ' $</div>
                    <div class="card-label">Profit brut</div>
                    <div class="card-sublabel">Avant impots</div>
                </td>
                <td>
                    <div class="card-value color-red">' . number_format($impotTotal, 0, ',', ' ') . ' $</div>
                    <div class="card-label">Impots corporatifs</div>
                    <div class="card-sublabel">Taux effectif: ' . number_format($tauxEffectif, 1) . '%</div>
                </td>
                <td>
                    <div class="card-value color-blue">' . number_format($profitNet, 0, ',', ' ') . ' $</div>
                    <div class="card-label">Profit net</div>
                    <div class="card-sublabel">Apres impots</div>
                </td>
            </tr>
        </table>
    </div>

    <div class="section">
        <div class="dpe-section">
            <div class="dpe-title">Utilisation du plafond DPE (Deduction pour petite entreprise)</div>

            <div class="gauge-container">
                <div class="gauge-fill" style="width: ' . $gaugeWidth . '%; background-color: ' . $gaugeColor . ';"></div>
                <div class="gauge-text">' . number_format($pourcentageUtilise, 1) . '% utilise</div>
            </div>
            <table class="gauge-labels">
                <tr>
                    <td>0 $</td>
                    <td style="text-align:center;">250 000 $</td>
                    <td class="right">500 000 $</td>
                </tr>
            </table>

            <div class="dpe-info">
                <table>
                    <tr>
                        <td class="label">Profits cumules ' . $anneeFiscale . '</td>
                        <td class="value">' . number_format($profitBrut, 0, ',', ' ') . ' $</td>
                    </tr>
                    <tr>
                        <td class="label">Plafond DPE restant (taux 12,2%)</td>
                        <td class="value">' . number_format($seuilRestant, 0, ',', ' ') . ' $</td>
                    </tr>
                    <tr>
                        <td class="label">Taux applicable au-dela du plafond</td>
                        <td class="value">26,5%</td>
                    </tr>
                </table>
            </div>
        </div>
    </div>';

if (!empty($resumeFiscal['projets_vendus'])) {
    $html .= '
    <div class="section">
        <div class="section-header">DETAIL DES TRANSACTIONS</div>

        <table class="projects">
            <tr>
                <th style="width:25%;">Projet</th>
                <th style="width:15%;">Date de vente</th>
                <th class="right" style="width:15%;">Profit</th>
                <th class="right" style="width:15%;">Cumul annuel</th>
                <th class="right" style="width:12%;">Taux</th>
                <th class="right" style="width:18%;">Impot</th>
            </tr>';

    $profitCumul = 0;
    $i = 0;
    foreach ($resumeFiscal['projets_vendus'] as $pv) {
        $impotProjet = calculerImpotAvecCumulatif($pv['profit'], $profitCumul);
        $profitCumul = $pv['profit_cumulatif'];
        $profitClass = $pv['profit'] >= 0 ? 'text-green' : 'text-red';
        $altClass = ($i % 2 == 1) ? ' class="alt"' : '';

        $html .= '
            <tr' . $altClass . '>
                <td>' . htmlspecialchars($pv['nom']) . '</td>
                <td>' . date('d/m/Y', strtotime($pv['date_vente'])) . '</td>
                <td class="right ' . $profitClass . '">' . number_format($pv['profit'], 0, ',', ' ') . ' $</td>
                <td class="right">' . number_format($pv['profit_cumulatif'], 0, ',', ' ') . ' $</td>
                <td class="right">' . $impotProjet['taux_affiche'] . '</td>
                <td class="right">' . number_format($impotProjet['impot'], 0, ',', ' ') . ' $</td>
            </tr>';
        $i++;
    }

    $html .= '
            <tr class="total-row">
                <td colspan="2">TOTAL ' . $anneeFiscale . '</td>
                <td class="right">' . number_format($profitBrut, 0, ',', ' ') . ' $</td>
                <td class="right">-</td>
                <td class="right">' . number_format($tauxEffectif, 1) . '%</td>
                <td class="right">' . number_format($impotTotal, 0, ',', ' ') . ' $</td>
            </tr>
        </table>
    </div>';
}

// Projections si projets en cours
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
        <div class="section-header">PROJETS EN COURS - PROJECTIONS</div>

        <table class="projects">
            <tr>
                <th style="width:30%;">Projet</th>
                <th style="width:25%;">Statut actuel</th>
                <th class="right" style="width:22%;">Profit estime</th>
                <th class="right" style="width:23%;">Taux si vendu en ' . $anneeFiscale . '</th>
            </tr>';

        $profitCumulProjection = $profitBrut;
        $i = 0;
        foreach ($projetsRentables as $pc) {
            $impotProjection = calculerImpotAvecCumulatif($pc['profit_estime'], $profitCumulProjection);
            $altClass = ($i % 2 == 1) ? ' class="alt"' : '';

            $html .= '
            <tr' . $altClass . '>
                <td>' . htmlspecialchars($pc['nom']) . '</td>
                <td>' . ucfirst(str_replace('_', ' ', $pc['statut'])) . '</td>
                <td class="right text-green">' . number_format($pc['profit_estime'], 0, ',', ' ') . ' $</td>
                <td class="right">' . $impotProjection['taux_affiche'] . '</td>
            </tr>';

            $profitCumulProjection += $pc['profit_estime'];
            $i++;
        }

        $html .= '
        </table>

        <div class="projection-box">
            <div class="projection-title">Scenario: Tous les projets vendus en ' . $anneeFiscale . '</div>
            <table class="projection-grid">
                <tr>
                    <td>
                        <div class="projection-value">' . number_format($resumeFiscal['profit_total_projection'], 0, ',', ' ') . ' $</div>
                        <div class="projection-label">Profit total projete</div>
                    </td>
                    <td>
                        <div class="projection-value">' . number_format($resumeFiscal['impot_projection'], 0, ',', ' ') . ' $</div>
                        <div class="projection-label">Impots estimes</div>
                    </td>
                    <td>
                        <div class="projection-value">' . number_format($resumeFiscal['taux_effectif_projection'] * 100, 1) . '%</div>
                        <div class="projection-label">Taux effectif projete</div>
                    </td>
                </tr>
            </table>
        </div>
    </div>';
    }
}

$html .= '
    <div class="legal-note">
        * Les taux d\'imposition utilises sont les taux corporatifs du Quebec: 12,2% (DPE) pour les premiers 500 000$ de revenus admissibles
        et 26,5% pour les revenus excedant ce seuil. Ces calculs sont fournis a titre indicatif uniquement et ne constituent pas un avis fiscal.
        Consultez un professionnel comptable pour vos declarations officielles.
    </div>

    <div class="footer">
        <table class="footer-content">
            <tr>
                <td class="footer-left">Flip Manager - Rapport fiscal ' . $anneeFiscale . '</td>
                <td class="footer-right">Document genere automatiquement le ' . date('d/m/Y a H:i') . '</td>
            </tr>
        </table>
    </div>
</body>
</html>';

// Générer le PDF - Format Letter (8.5x11)
$dompdf->loadHtml($html);
$dompdf->setPaper('letter', 'portrait');
$dompdf->render();

// Télécharger le PDF
$filename = 'Rapport_Fiscal_' . $anneeFiscale . '_' . date('Ymd') . '.pdf';
$dompdf->stream($filename, ['Attachment' => true]);
