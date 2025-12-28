<!-- ✅ VERSION CORRIGÉE : DOM VALIDE / RÉNOVATION 100% JS -->

<div class="tab-pane fade <?= $tab === 'base' ? 'show active' : '' ?>" id="base" role="tabpanel">

<!-- Indicateurs en haut -->
<?php
$extrapoleBgClass = $indicateurs['equite_potentielle'] >= 0 ? 'bg-success' : 'bg-danger';
$extrapoleTextClass = $indicateurs['equite_potentielle'] >= 0 ? 'text-success' : 'text-danger';
$reelBgClass = $indicateurs['equite_reelle'] >= 0 ? 'bg-success' : 'bg-danger';
$reelTextClass = $indicateurs['equite_reelle'] >= 0 ? 'text-success' : 'text-danger';
?>

<div class="row g-2 mb-3">
    <div class="col-6 col-lg">
        <div class="card text-center p-2">
            <small class="text-muted">Valeur potentielle</small>
            <strong id="indValeurPotentielle"><?= formatMoney($indicateurs['valeur_potentielle']) ?></strong>
        </div>
    </div>
    <div class="col-6 col-lg">
        <div class="card text-center p-2 <?= $extrapoleBgClass ?> bg-opacity-10">
            <small class="text-muted">Extrapolé</small>
            <strong id="indEquiteBudget"><?= formatMoney($indicateurs['equite_potentielle']) ?></strong>
        </div>
    </div>
    <div class="col-6 col-lg">
        <div class="card text-center p-2">
            <small class="text-muted">Cash Flow</small>
            <strong id="indCashFlow"><?= formatMoney($indicateurs['cash_flow_necessaire']) ?></strong>
        </div>
    </div>
    <div class="col-6 col-lg">
        <div class="card text-center p-2 <?= $reelBgClass ?> bg-opacity-10">
            <small class="text-muted">Réel</small>
            <strong id="indEquiteReelle"><?= formatMoney($indicateurs['equite_reelle']) ?></strong>
        </div>
    </div>
</div>

<!-- ✅ TABLE DÉTAIL DES COÛTS -->
<div class="card">
<div class="table-responsive">
<table class="cost-table">
<thead>
<tr>
<th>Poste</th>
<th class="text-info">Extrapolé</th>
<th>Diff</th>
<th class="text-success">Réel</th>
</tr>
</thead>
<tbody>

<tr class="section-header"><td colspan="4">Achat</td></tr>
<tr>
<td>Prix d'achat</td>
<td class="text-end"><?= formatMoney($projet['prix_achat']) ?></td>
<td>-</td>
<td class="text-end"><?= formatMoney($projet['prix_achat']) ?></td>
</tr>

<tr class="section-header"><td colspan="4">Rénovation (+ <?= $projet['taux_contingence'] ?>%)</td></tr>

<!-- ✅ RÉNOVATION_DYNAMIC_START -->
<!-- (injecté entièrement par renderRenovationFromJson JS) -->

<tr class="section-header"><td colspan="4">Main d'œuvre</td></tr>
<?php if ($indicateurs['main_doeuvre']['cout'] > 0): ?>
<tr>
<td>Main d'œuvre</td>
<td class="text-end"><?= formatMoney($indicateurs['main_doeuvre_extrapole']['cout']) ?></td>
<td>-</td>
<td class="text-end"><?= formatMoney($indicateurs['main_doeuvre']['cout']) ?></td>
</tr>
<?php endif; ?>

<tr class="total-row">
<td>Total rénovation TTC</td>
<td class="text-end" id="detailRenoTotal"><?= formatMoney($indicateurs['renovation']['total_ttc']) ?></td>
<td>-</td>
<td class="text-end"><?= formatMoney($indicateurs['renovation']['reel_ttc']) ?></td>
</tr>

</tbody>
</table>
</div>
</div>

</div>
