<?php
/**
 * Fonctions de calculs financiers
 * Flip Manager
 */

/**
 * Calcule les coûts d'acquisition
 * @param array $projet
 * @return array
 */
function calculerCoutsAcquisition($projet) {
    return [
        'notaire' => (float) $projet['notaire'],
        'taxe_mutation' => (float) $projet['taxe_mutation'],
        'arpenteurs' => (float) $projet['arpenteurs'],
        'assurance_titre' => (float) $projet['assurance_titre'],
        'total' => (float) $projet['notaire'] + 
                   (float) $projet['taxe_mutation'] + 
                   (float) $projet['arpenteurs'] + 
                   (float) $projet['assurance_titre']
    ];
}

/**
 * Calcule les coûts récurrents extrapolés selon le temps assumé
 * @param array $projet
 * @return array
 */
function calculerCoutsRecurrents($projet) {
    $mois = (int) $projet['temps_assume_mois'];
    $facteur = $mois / 12;
    
    $taxes_municipales = (float) $projet['taxes_municipales_annuel'] * $facteur;
    $taxes_scolaires = (float) $projet['taxes_scolaires_annuel'] * $facteur;
    $electricite = (float) $projet['electricite_annuel'] * $facteur;
    $assurances = (float) $projet['assurances_annuel'] * $facteur;
    $deneigement = (float) $projet['deneigement_annuel'] * $facteur;
    $frais_condo = (float) $projet['frais_condo_annuel'] * $facteur;
    $hypotheque = (float) $projet['hypotheque_mensuel'] * $mois;
    $loyer = (float) $projet['loyer_mensuel'] * $mois;
    
    return [
        'taxes_municipales' => [
            'annuel' => (float) $projet['taxes_municipales_annuel'],
            'extrapole' => $taxes_municipales
        ],
        'taxes_scolaires' => [
            'annuel' => (float) $projet['taxes_scolaires_annuel'],
            'extrapole' => $taxes_scolaires
        ],
        'electricite' => [
            'annuel' => (float) $projet['electricite_annuel'],
            'extrapole' => $electricite
        ],
        'assurances' => [
            'annuel' => (float) $projet['assurances_annuel'],
            'extrapole' => $assurances
        ],
        'deneigement' => [
            'annuel' => (float) $projet['deneigement_annuel'],
            'extrapole' => $deneigement
        ],
        'frais_condo' => [
            'annuel' => (float) $projet['frais_condo_annuel'],
            'extrapole' => $frais_condo
        ],
        'hypotheque' => [
            'mensuel' => (float) $projet['hypotheque_mensuel'],
            'extrapole' => $hypotheque
        ],
        'loyer' => [
            'mensuel' => (float) $projet['loyer_mensuel'],
            'extrapole' => $loyer
        ],
        'total' => $taxes_municipales + $taxes_scolaires + $electricite + 
                   $assurances + $deneigement + $frais_condo + $hypotheque + $loyer
    ];
}

/**
 * Calcule les coûts de vente
 * @param array $projet
 * @return array
 */
function calculerCoutsVente($projet) {
    $valeur = (float) $projet['valeur_potentielle'];
    $tauxCommission = (float) $projet['taux_commission'];
    $tauxInteret = (float) $projet['taux_interet'];
    $montantPret = (float) $projet['montant_pret'];
    $mois = (int) $projet['temps_assume_mois'];
    
    // Commission courtier
    $commission = $valeur * ($tauxCommission / 100);
    
    // Intérêts sur le prêt
    $interets = $montantPret * ($tauxInteret / 100) * ($mois / 12);
    
    // Quittance (généralement 0 ou fixe)
    $quittance = 0;
    
    return [
        'commission' => $commission,
        'interets' => $interets,
        'quittance' => $quittance,
        'total' => $commission + $interets + $quittance
    ];
}

/**
 * Calcule le total des budgets de rénovation extrapolés
 * @param PDO $pdo
 * @param int $projetId
 * @return float
 */
function calculerTotalBudgetRenovation($pdo, $projetId) {
    $stmt = $pdo->prepare("SELECT SUM(montant_extrapole) as total FROM budgets WHERE projet_id = ?");
    $stmt->execute([$projetId]);
    $result = $stmt->fetch();
    return (float) ($result['total'] ?? 0);
}

/**
 * Calcule le total des factures réelles (approuvées)
 * @param PDO $pdo
 * @param int $projetId
 * @return float
 */
function calculerTotalFacturesReelles($pdo, $projetId) {
    $stmt = $pdo->prepare("SELECT SUM(montant_total) as total FROM factures WHERE projet_id = ? AND statut = 'approuvee'");
    $stmt->execute([$projetId]);
    $result = $stmt->fetch();
    return (float) ($result['total'] ?? 0);
}

/**
 * Calcule les dépenses réelles par catégorie
 * @param PDO $pdo
 * @param int $projetId
 * @return array
 */
function calculerDepensesParCategorie($pdo, $projetId) {
    $stmt = $pdo->prepare("
        SELECT categorie_id, SUM(montant_total) as total 
        FROM factures 
        WHERE projet_id = ? AND statut = 'approuvee'
        GROUP BY categorie_id
    ");
    $stmt->execute([$projetId]);
    $results = $stmt->fetchAll();
    
    $depenses = [];
    foreach ($results as $row) {
        $depenses[$row['categorie_id']] = (float) $row['total'];
    }
    return $depenses;
}

/**
 * Récupère les budgets par catégorie pour un projet
 * @param PDO $pdo
 * @param int $projetId
 * @return array
 */
function getBudgetsParCategorie($pdo, $projetId) {
    $stmt = $pdo->prepare("SELECT categorie_id, montant_extrapole FROM budgets WHERE projet_id = ?");
    $stmt->execute([$projetId]);
    $results = $stmt->fetchAll();
    
    $budgets = [];
    foreach ($results as $row) {
        $budgets[$row['categorie_id']] = (float) $row['montant_extrapole'];
    }
    return $budgets;
}

/**
 * Calcule la contingence
 * @param float $totalRenovation
 * @param float $tauxContingence
 * @return float
 */
function calculerContingence($totalRenovation, $tauxContingence) {
    return $totalRenovation * ($tauxContingence / 100);
}

/**
 * Calcule les coûts fixes totaux
 * @param array $coutsAcquisition
 * @param array $coutsRecurrents
 * @param array $coutsVente
 * @return float
 */
function calculerCoutsFixesTotaux($coutsAcquisition, $coutsRecurrents, $coutsVente) {
    return $coutsAcquisition['total'] + $coutsRecurrents['total'] + $coutsVente['total'];
}

/**
 * Calcule le coût total du projet
 * @param array $projet
 * @param float $coutsFixesTotaux
 * @param float $totalRenovation
 * @param float $contingence
 * @return float
 */
function calculerCoutTotalProjet($projet, $coutsFixesTotaux, $totalRenovation, $contingence) {
    return (float) $projet['prix_achat'] + $coutsFixesTotaux + $totalRenovation + $contingence;
}

/**
 * Calcule l'équité potentielle
 * @param float $valeurPotentielle
 * @param float $coutTotalProjet
 * @return float
 */
function calculerEquitePotentielle($valeurPotentielle, $coutTotalProjet) {
    return $valeurPotentielle - $coutTotalProjet;
}

/**
 * Calcule le ROI avec leverage (mise de fonds)
 * @param float $equitePotentielle
 * @param float $miseDeFondsTotale
 * @return float
 */
function calculerROILeverage($equitePotentielle, $miseDeFondsTotale) {
    if ($miseDeFondsTotale <= 0) return 0;
    return ($equitePotentielle / $miseDeFondsTotale) * 100;
}

/**
 * Calcule le ROI all cash (sans leverage)
 * @param float $equitePotentielle
 * @param float $coutTotalProjet
 * @return float
 */
function calculerROIAllCash($equitePotentielle, $coutTotalProjet) {
    if ($coutTotalProjet <= 0) return 0;
    return ($equitePotentielle / $coutTotalProjet) * 100;
}

/**
 * Calcule le pourcentage d'un montant par rapport à la valeur potentielle
 * @param float $montant
 * @param float $valeurPotentielle
 * @return float
 */
function calculerPourcentageValeur($montant, $valeurPotentielle) {
    if ($valeurPotentielle <= 0) return 0;
    return ($montant / $valeurPotentielle) * 100;
}

/**
 * Récupère les investisseurs d'un projet avec calculs
 * @param PDO $pdo
 * @param int $projetId
 * @param float $equitePotentielle
 * @return array
 */
function getInvestisseursProjet($pdo, $projetId, $equitePotentielle = 0) {
    $stmt = $pdo->prepare("
        SELECT pi.*, i.nom, i.email, i.telephone
        FROM projet_investisseurs pi
        JOIN investisseurs i ON pi.investisseur_id = i.id
        WHERE pi.projet_id = ?
        ORDER BY pi.mise_de_fonds DESC
    ");
    $stmt->execute([$projetId]);
    $investisseurs = $stmt->fetchAll();
    
    // Calculer la mise de fonds totale
    $miseTotale = 0;
    foreach ($investisseurs as $inv) {
        $miseTotale += (float) $inv['mise_de_fonds'];
    }
    
    // Calculer le pourcentage et profit estimé pour chaque investisseur
    foreach ($investisseurs as &$inv) {
        $mise = (float) $inv['mise_de_fonds'];
        $inv['pourcentage_calcule'] = $miseTotale > 0 ? ($mise / $miseTotale) * 100 : 0;
        $inv['profit_estime'] = $equitePotentielle * ($inv['pourcentage_calcule'] / 100);
    }
    
    return [
        'investisseurs' => $investisseurs,
        'mise_totale' => $miseTotale
    ];
}

/**
 * Calcule tous les indicateurs financiers d'un projet
 * @param PDO $pdo
 * @param array $projet
 * @return array
 */
function calculerIndicateursProjet($pdo, $projet) {
    // Coûts d'acquisition
    $coutsAcquisition = calculerCoutsAcquisition($projet);
    
    // Coûts récurrents
    $coutsRecurrents = calculerCoutsRecurrents($projet);
    
    // Coûts de vente
    $coutsVente = calculerCoutsVente($projet);
    
    // Rénovation
    $totalBudgetRenovation = calculerTotalBudgetRenovation($pdo, $projet['id']);
    $totalFacturesReelles = calculerTotalFacturesReelles($pdo, $projet['id']);
    $contingence = calculerContingence($totalBudgetRenovation, (float) $projet['taux_contingence']);
    
    // Coûts fixes totaux
    $coutsFixesTotaux = calculerCoutsFixesTotaux($coutsAcquisition, $coutsRecurrents, $coutsVente);
    
    // Coût total projet
    $coutTotalProjet = calculerCoutTotalProjet($projet, $coutsFixesTotaux, $totalBudgetRenovation, $contingence);
    
    // Équité potentielle
    $valeurPotentielle = (float) $projet['valeur_potentielle'];
    $equitePotentielle = calculerEquitePotentielle($valeurPotentielle, $coutTotalProjet);
    
    // Investisseurs
    $dataInvestisseurs = getInvestisseursProjet($pdo, $projet['id'], $equitePotentielle);
    
    // ROI
    $roiLeverage = calculerROILeverage($equitePotentielle, $dataInvestisseurs['mise_totale']);
    $roiAllCash = calculerROIAllCash($equitePotentielle, $coutTotalProjet);
    
    // Pourcentages
    $pctCoutsFixes = calculerPourcentageValeur($coutsFixesTotaux, $valeurPotentielle);
    $pctRenovation = calculerPourcentageValeur($totalBudgetRenovation, $valeurPotentielle);
    $pctPrixAchat = calculerPourcentageValeur((float) $projet['prix_achat'], $valeurPotentielle);
    
    // Progression du budget de rénovation
    $progressionBudget = $totalBudgetRenovation > 0 ? ($totalFacturesReelles / $totalBudgetRenovation) * 100 : 0;
    
    return [
        'couts_acquisition' => $coutsAcquisition,
        'couts_recurrents' => $coutsRecurrents,
        'couts_vente' => $coutsVente,
        'couts_fixes_totaux' => $coutsFixesTotaux,
        'renovation' => [
            'budget' => $totalBudgetRenovation,
            'reel' => $totalFacturesReelles,
            'ecart' => $totalBudgetRenovation - $totalFacturesReelles,
            'progression' => $progressionBudget
        ],
        'contingence' => $contingence,
        'cout_total_projet' => $coutTotalProjet,
        'valeur_potentielle' => $valeurPotentielle,
        'equite_potentielle' => $equitePotentielle,
        'roi_leverage' => $roiLeverage,
        'roi_all_cash' => $roiAllCash,
        'pourcentages' => [
            'couts_fixes' => $pctCoutsFixes,
            'renovation' => $pctRenovation,
            'prix_achat' => $pctPrixAchat
        ],
        'investisseurs' => $dataInvestisseurs['investisseurs'],
        'mise_fonds_totale' => $dataInvestisseurs['mise_totale']
    ];
}

/**
 * Calcule les taxes (TPS et TVQ)
 * @param float $montantAvantTaxes
 * @return array
 */
function calculerTaxes($montantAvantTaxes) {
    $tps = $montantAvantTaxes * 0.05; // 5%
    $tvq = $montantAvantTaxes * 0.09975; // 9.975%
    $total = $montantAvantTaxes + $tps + $tvq;
    
    return [
        'avant_taxes' => $montantAvantTaxes,
        'tps' => round($tps, 2),
        'tvq' => round($tvq, 2),
        'total' => round($total, 2)
    ];
}
