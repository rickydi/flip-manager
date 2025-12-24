<?php
/**
 * Debug PDF - Test complet fiscal
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>Test PDF Fiscal Complet</h2>";

try {
    echo "<p>1. Chargement fichiers... ";
    require_once __DIR__ . '/../../config.php';
    require_once __DIR__ . '/../../includes/auth.php';
    require_once __DIR__ . '/../../includes/functions.php';
    require_once __DIR__ . '/../../includes/calculs.php';
    require_once __DIR__ . '/../../../vendor/autoload.php';
    echo "OK</p>";

    echo "<p>2. Vérification admin... ";
    requireAdmin();
    echo "OK</p>";

    echo "<p>3. Récupération données fiscales... ";
    $anneeFiscale = (int) date('Y');
    $resumeFiscal = obtenirResumeAnneeFiscale($pdo, $anneeFiscale);
    echo "OK</p>";

    echo "<pre>";
    print_r($resumeFiscal);
    echo "</pre>";

    echo "<p>4. Test fonction calculerImpotAvecCumulatif... ";
    $testImpot = calculerImpotAvecCumulatif(10000, 0);
    echo "OK - Résultat: ";
    print_r($testImpot);
    echo "</p>";

    echo "<p>5. Création Dompdf... ";
    $options = new Dompdf\Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isRemoteEnabled', true);
    $options->set('defaultFont', 'DejaVu Sans');
    $dompdf = new Dompdf\Dompdf($options);
    echo "OK</p>";

    echo "<p>6. Génération HTML du rapport... ";

    // Copie simplifiée du HTML de fiscal-pdf.php
    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>';
    $html .= '<h1>RAPPORT FISCAL ' . $anneeFiscale . '</h1>';
    $html .= '<p>Profit réalisé: ' . number_format($resumeFiscal['profit_realise'], 0, ',', ' ') . ' $</p>';
    $html .= '<p>Impôt: ' . number_format($resumeFiscal['impot_realise'], 0, ',', ' ') . ' $</p>';
    $html .= '</body></html>';

    echo "OK</p>";

    echo "<p>7. Chargement HTML dans Dompdf... ";
    $dompdf->loadHtml($html);
    echo "OK</p>";

    echo "<p>8. Rendu PDF... ";
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    echo "OK</p>";

    echo "<h2 style='color:green'>TOUT FONCTIONNE!</h2>";
    echo "<p><a href='fiscal-pdf.php?annee=" . $anneeFiscale . "'>Télécharger le vrai PDF</a></p>";

} catch (Throwable $e) {
    echo "<h2 style='color:red'>ERREUR!</h2>";
    echo "<p><strong>Message:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p><strong>Fichier:</strong> " . $e->getFile() . " ligne " . $e->getLine() . "</p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}
