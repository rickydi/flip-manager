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
        'cession' => (float) ($projet['cession'] ?? 0),
        'notaire' => (float) $projet['notaire'],
        'taxe_mutation' => (float) $projet['taxe_mutation'],
        'arpenteurs' => (float) $projet['arpenteurs'],
        'assurance_titre' => (float) $projet['assurance_titre'],
        'solde_vendeur' => (float) ($projet['solde_vendeur'] ?? 0),
        'total' => (float) ($projet['cession'] ?? 0) +
                   (float) $projet['notaire'] +
                   (float) $projet['taxe_mutation'] +
                   (float) $projet['arpenteurs'] +
                   (float) $projet['assurance_titre'] +
                   (float) ($projet['solde_vendeur'] ?? 0)
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
                   $assurances + $deneigement + $frais_condo + $hypotheque - $loyer
    ];
}

/**
 * Calcule le nombre de mois écoulés depuis la date d'achat
 * @param array $projet
 * @return float Nombre de mois (peut être décimal)
 */
function calculerMoisEcoules($projet) {
    if (empty($projet['date_acquisition'])) {
        return 0;
    }

    $dateAchat = new DateTime($projet['date_acquisition']);
    $dateFin = !empty($projet['date_vente']) ? new DateTime($projet['date_vente']) : new DateTime();

    $diff = $dateAchat->diff($dateFin);
    $mois = ($diff->y * 12) + $diff->m;

    // Ajouter une fraction pour les jours
    $joursRestants = $diff->d;
    if ($joursRestants > 0) {
        $mois += $joursRestants / 30;
    }

    return max(0, $mois);
}

/**
 * Calcule les coûts récurrents RÉELS basés sur le temps écoulé depuis la date d'achat
 * @param array $projet
 * @return array
 */
function calculerCoutsRecurrentsReels($projet) {
    $moisEcoules = calculerMoisEcoules($projet);
    $facteur = $moisEcoules / 12;

    $taxes_municipales = (float) $projet['taxes_municipales_annuel'] * $facteur;
    $taxes_scolaires = (float) $projet['taxes_scolaires_annuel'] * $facteur;
    $electricite = (float) $projet['electricite_annuel'] * $facteur;
    $assurances = (float) $projet['assurances_annuel'] * $facteur;
    $deneigement = (float) $projet['deneigement_annuel'] * $facteur;
    $frais_condo = (float) $projet['frais_condo_annuel'] * $facteur;
    $hypotheque = (float) $projet['hypotheque_mensuel'] * $moisEcoules;
    $loyer = (float) $projet['loyer_mensuel'] * $moisEcoules;

    return [
        'mois_ecoules' => $moisEcoules,
        'taxes_municipales' => $taxes_municipales,
        'taxes_scolaires' => $taxes_scolaires,
        'electricite' => $electricite,
        'assurances' => $assurances,
        'deneigement' => $deneigement,
        'frais_condo' => $frais_condo,
        'hypotheque' => $hypotheque,
        'loyer' => $loyer,
        'total' => $taxes_municipales + $taxes_scolaires + $electricite +
                   $assurances + $deneigement + $frais_condo + $hypotheque - $loyer
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

    // Commission courtier (HT)
    $commissionHT = $valeur * ($tauxCommission / 100);

    // Taxes sur la commission (TPS 5% + TVQ 9.975% = 14.975%)
    $taxesCommission = $commissionHT * 0.14975;
    $commissionTTC = $commissionHT + $taxesCommission;

    // Intérêts sur le prêt (composés mensuellement)
    $tauxMensuel = $tauxInteret / 100 / 12;
    $interets = $montantPret * (pow(1 + $tauxMensuel, $mois) - 1);

    // Quittance (depuis le projet)
    $quittance = (float) ($projet['quittance'] ?? 0);

    // Solde à payer à l'acheteur (ajustement de taxes)
    $soldeAcheteur = (float) ($projet['solde_acheteur'] ?? 0);

    return [
        'commission' => $commissionHT,
        'commission_ttc' => $commissionTTC,
        'taxes_commission' => $taxesCommission,
        'interets' => $interets,
        'quittance' => $quittance,
        'solde_acheteur' => $soldeAcheteur,
        'total' => $commissionTTC + $interets + $quittance + $soldeAcheteur
    ];
}

/**
 * Calcule le total des budgets de rénovation extrapolés (avec quantités de groupes)
 * @param PDO $pdo
 * @param int $projetId
 * @return float
 */
function calculerTotalBudgetRenovation($pdo, $projetId) {
    // Charger les quantités de groupes pour ce projet
    $groupeQtes = [];
    try {
        $stmt = $pdo->prepare("SELECT groupe_nom, quantite FROM projet_groupes WHERE projet_id = ?");
        $stmt->execute([$projetId]);
        foreach ($stmt->fetchAll() as $row) {
            $groupeQtes[$row['groupe_nom']] = (int)$row['quantite'];
        }
    } catch (Exception $e) {
        // Table n'existe pas encore
    }

    // Calculer le total avec multiplication par quantité de groupe
    $total = 0;
    try {
        $stmt = $pdo->prepare("
            SELECT b.montant_extrapole, c.groupe
            FROM budgets b
            JOIN categories c ON b.categorie_id = c.id
            WHERE b.projet_id = ?
        ");
        $stmt->execute([$projetId]);
        foreach ($stmt->fetchAll() as $row) {
            $groupe = $row['groupe'] ?? 'autre';
            $qteGroupe = $groupeQtes[$groupe] ?? 1;
            $total += (float)$row['montant_extrapole'] * $qteGroupe;
        }
    } catch (Exception $e) {
        // Fallback à l'ancienne méthode si erreur
        $stmt = $pdo->prepare("SELECT SUM(montant_extrapole) as total FROM budgets WHERE projet_id = ?");
        $stmt->execute([$projetId]);
        $result = $stmt->fetch();
        $total = (float) ($result['total'] ?? 0);
    }

    return $total;
}

/**
 * Calcule le total des factures réelles (approuvées ET en attente - seules les rejetées sont exclues)
 * @param PDO $pdo
 * @param int $projetId
 * @return float
 */
function calculerTotalFacturesReelles($pdo, $projetId) {
    $stmt = $pdo->prepare("SELECT SUM(montant_total) as total FROM factures WHERE projet_id = ? AND statut != 'rejetee'");
    $stmt->execute([$projetId]);
    $result = $stmt->fetch();
    return (float) ($result['total'] ?? 0);
}

/**
 * Calcule le coût total de la main d'œuvre (heures travaillées approuvées ET en attente - seules les rejetées sont exclues)
 * Utilise le taux horaire actuel de l'utilisateur (JOIN users) pour toujours avoir le bon montant
 * @param PDO $pdo
 * @param int $projetId
 * @return array
 */
function calculerCoutMainDoeuvre($pdo, $projetId) {
    try {
        $stmt = $pdo->prepare("
            SELECT SUM(h.heures) as total_heures, SUM(h.heures * u.taux_horaire) as total_cout
            FROM heures_travaillees h
            JOIN users u ON h.user_id = u.id
            WHERE h.projet_id = ? AND h.statut != 'rejetee'
        ");
        $stmt->execute([$projetId]);
        $result = $stmt->fetch();
        return [
            'heures' => (float) ($result['total_heures'] ?? 0),
            'cout' => (float) ($result['total_cout'] ?? 0)
        ];
    } catch (Exception $e) {
        return ['heures' => 0, 'cout' => 0];
    }
}

/**
 * Calcule le coût EXTRAPOLÉ/PLANIFIÉ de la main d'œuvre (depuis projet_planification_heures)
 * C'est le budget prévu, pas les heures réellement travaillées
 * @param PDO $pdo
 * @param array $projet
 * @return array
 */
function calculerCoutMainDoeuvreExtrapole($pdo, $projet) {
    $result = ['heures' => 0, 'cout' => 0, 'jours' => 0];

    $dateDebutTravaux = $projet['date_debut_travaux'] ?? $projet['date_acquisition'] ?? null;
    $dateFinPrevue = $projet['date_fin_prevue'] ?? null;

    if (!$dateDebutTravaux || !$dateFinPrevue) {
        return $result;
    }

    try {
        $d1 = new DateTime($dateDebutTravaux);
        $d2 = new DateTime($dateFinPrevue);

        // Calcul des jours ouvrables (Lundi-Vendredi)
        $d2Inclusive = clone $d2;
        $d2Inclusive->modify('+1 day');
        $period = new DatePeriod($d1, new DateInterval('P1D'), $d2Inclusive);

        $joursOuvrables = 0;
        foreach ($period as $dt) {
            if ((int)$dt->format('N') < 6) $joursOuvrables++;
        }
        $result['jours'] = max(1, $joursOuvrables);

        // Récupérer les planifications avec taux horaire
        $stmt = $pdo->prepare("
            SELECT p.heures_semaine_estimees, u.taux_horaire
            FROM projet_planification_heures p
            JOIN users u ON p.user_id = u.id
            WHERE p.projet_id = ?
        ");
        $stmt->execute([$projet['id']]);

        foreach ($stmt->fetchAll() as $row) {
            $heuresSemaine = (float)$row['heures_semaine_estimees'];
            $tauxHoraire = (float)$row['taux_horaire'];
            // heures/jour = heures/semaine ÷ 5
            $heuresJour = $heuresSemaine / 5;
            $totalHeures = $heuresJour * $result['jours'];
            $result['heures'] += $totalHeures;
            $result['cout'] += $totalHeures * $tauxHoraire;
        }
    } catch (Exception $e) {
        // Table n'existe pas ou erreur
    }

    return $result;
}

/**
 * Calcule les dépenses réelles par catégorie (approuvées ET en attente - seules les rejetées sont exclues)
 * @param PDO $pdo
 * @param int $projetId
 * @return array
 */
function calculerDepensesParCategorie($pdo, $projetId) {
    $stmt = $pdo->prepare("
        SELECT categorie_id, SUM(montant_total) as total 
        FROM factures 
        WHERE projet_id = ? AND statut != 'rejetee'
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
 * Récupère les prêteurs et investisseurs d'un projet avec calculs
 * @param PDO $pdo
 * @param int $projetId
 * @param float $equitePotentielle
 * @param int $mois - Durée du projet en mois
 * @return array
 */
function getInvestisseursProjet($pdo, $projetId, $equitePotentielle = 0, $mois = 6) {
    $preteurs = [];
    $investisseurs = [];
    $totalPrets = 0;
    $totalInterets = 0;
    $miseTotaleInvestisseurs = 0;
    
    try {
        // Requête simplifiée - utilise seulement les colonnes qui existent
        $stmt = $pdo->prepare("
            SELECT pi.id, pi.projet_id, pi.investisseur_id,
                   pi.montant, pi.taux_interet,
                   i.nom, i.email, i.telephone
            FROM projet_investisseurs pi
            JOIN investisseurs i ON pi.investisseur_id = i.id
            WHERE pi.projet_id = ?
            ORDER BY i.nom
        ");
        $stmt->execute([$projetId]);
        $all = $stmt->fetchAll();
        
        foreach ($all as $row) {
            $montant = (float) ($row['montant'] ?? 0);
            $taux = (float) ($row['taux_interet'] ?? 0);
            
            // Si taux > 0, c'est un prêteur (paie des intérêts)
            if ($taux > 0) {
                // Prêteur : calcul des intérêts composés mensuellement
                $tauxMensuel = $taux / 100 / 12;
                $interetsTotal = $montant * (pow(1 + $tauxMensuel, $mois) - 1);
                $totalDu = $montant + $interetsTotal;
                $interetsMois = $mois > 0 ? $interetsTotal / $mois : 0;
                
                $preteurs[] = [
                    'id' => $row['id'],
                    'nom' => $row['nom'],
                    'montant' => $montant,
                    'taux' => $taux,
                    'interets_mois' => $interetsMois,
                    'interets_total' => $interetsTotal,
                    'total_du' => $totalDu
                ];

                $totalPrets += $montant;
                $totalInterets += $interetsTotal;
            } elseif ($montant > 0) {
                // Investisseur sans intérêt : partage des profits
                $investisseurs[] = [
                    'id' => $row['id'],
                    'nom' => $row['nom'],
                    'mise_de_fonds' => $montant,
                    'pourcentage' => 0, // Sera calculé après selon mise de fonds
                    'profit_estime' => 0
                ];

                $miseTotaleInvestisseurs += $montant;
            }
        }

        // Calculer le profit pour chaque investisseur basé sur leur mise de fonds
        $profitApresInterets = $equitePotentielle - $totalInterets;

        foreach ($investisseurs as &$inv) {
            // Calculer le pourcentage selon la mise de fonds
            if ($miseTotaleInvestisseurs > 0) {
                $inv['pourcentage'] = ($inv['mise_de_fonds'] / $miseTotaleInvestisseurs) * 100;
                $inv['profit_estime'] = $profitApresInterets * ($inv['pourcentage'] / 100);
            }
        }

    } catch (Exception $e) {
        // Table n'existe pas ou erreur
    }
    
    return [
        'preteurs' => $preteurs,
        'investisseurs' => $investisseurs,
        'total_prets' => $totalPrets,
        'total_interets' => $totalInterets,
        'mise_totale_investisseurs' => $miseTotaleInvestisseurs,
        'mise_totale' => $totalPrets + $miseTotaleInvestisseurs
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
    
    // Calculer la durée réelle si dates disponibles
    $mois = (int) $projet['temps_assume_mois'];
    if (!empty($projet['date_vente']) && !empty($projet['date_acquisition'])) {
        $dateAchat = new DateTime($projet['date_acquisition']);
        $dateVente = new DateTime($projet['date_vente']);
        $diff = $dateAchat->diff($dateVente);
        $mois = ($diff->y * 12) + $diff->m;
        // Ajouter 1 mois seulement si jour fin > jour début
        if ((int)$dateVente->format('d') > (int)$dateAchat->format('d')) {
            $mois++;
        }
        $mois = max(1, $mois);
    }
    
    // Modifier temp_assume_mois pour le calcul des récurrents
    $projetModifie = $projet;
    $projetModifie['temps_assume_mois'] = $mois;
    
    // Coûts récurrents (avec durée réelle)
    $coutsRecurrents = calculerCoutsRecurrents($projetModifie);
    
    // Coûts de vente (sans intérêts pour l'instant)
    $coutsVente = calculerCoutsVente($projetModifie);
    
    // Rénovation
    $totalBudgetRenovation = calculerTotalBudgetRenovation($pdo, $projet['id']);
    $totalFacturesReelles = calculerTotalFacturesReelles($pdo, $projet['id']);
    $contingence = calculerContingence($totalBudgetRenovation, (float) $projet['taux_contingence']);

    // Budget avec taxes
    $budgetComplet = calculerBudgetRenovationComplet($pdo, $projet['id'], (float) $projet['taux_contingence']);
    
    // D'abord récupérer les prêteurs pour avoir les intérêts réels (avec durée réelle)
    $dataFinancement = getInvestisseursProjet($pdo, $projet['id'], 0, $mois);
    
    // Remplacer les intérêts de vente par les intérêts des prêteurs si disponibles
    if ($dataFinancement['total_interets'] > 0) {
        $coutsVente['interets'] = $dataFinancement['total_interets'];
        $coutsVente['total'] = $coutsVente['commission_ttc'] + $coutsVente['interets'] + $coutsVente['quittance'] + ($coutsVente['solde_acheteur'] ?? 0);
    }
    
    // Coûts fixes totaux (maintenant avec les bons intérêts)
    $coutsFixesTotaux = calculerCoutsFixesTotaux($coutsAcquisition, $coutsRecurrents, $coutsVente);

    // Coût total projet - UTILISER LE TTC (avec taxes) pour la rénovation!
    // Avant: utilisait $totalBudgetRenovation (HT) + $contingence
    // Maintenant: utilise $budgetComplet['total_ttc'] qui inclut HT + contingence + TPS + TVQ
    $coutTotalProjet = (float) $projet['prix_achat'] + $coutsFixesTotaux + $budgetComplet['total_ttc'];

    // Équité potentielle
    $valeurPotentielle = (float) $projet['valeur_potentielle'];
    $equitePotentielle = calculerEquitePotentielle($valeurPotentielle, $coutTotalProjet);
    
    // Recalculer les profits des investisseurs avec l'équité correcte
    $dataFinancement = getInvestisseursProjet($pdo, $projet['id'], $equitePotentielle, $mois);
    
    // ROI
    $roiLeverage = calculerROILeverage($equitePotentielle, $dataFinancement['mise_totale']);
    $roiAllCash = calculerROIAllCash($equitePotentielle, $coutTotalProjet);
    
    // Pourcentages
    $pctCoutsFixes = calculerPourcentageValeur($coutsFixesTotaux, $valeurPotentielle);
    $pctRenovation = calculerPourcentageValeur($totalBudgetRenovation, $valeurPotentielle);
    $pctPrixAchat = calculerPourcentageValeur((float) $projet['prix_achat'], $valeurPotentielle);
    
    // Main d'œuvre RÉELLE (heures travaillées)
    $mainDoeuvreReelle = calculerCoutMainDoeuvre($pdo, $projet['id']);

    // Main d'œuvre EXTRAPOLÉE/PLANIFIÉE (budget prévu)
    $mainDoeuvreExtrapole = calculerCoutMainDoeuvreExtrapole($pdo, $projet);

    // Coût total BUDGET incluant main d'œuvre PLANIFIÉE
    $coutTotalProjet = $coutTotalProjet + $mainDoeuvreExtrapole['cout'];

    // Recalculer l'équité avec la main d'œuvre PLANIFIÉE
    $equitePotentielle = calculerEquitePotentielle($valeurPotentielle, $coutTotalProjet);

    // Recalculer les profits des investisseurs avec l'équité correcte
    $dataFinancement = getInvestisseursProjet($pdo, $projet['id'], $equitePotentielle, $mois);

    // Recalculer les ROI (budget)
    $roiLeverage = calculerROILeverage($equitePotentielle, $dataFinancement['mise_totale']);
    $roiAllCash = calculerROIAllCash($equitePotentielle, $coutTotalProjet);

    // Progression du budget de rénovation (factures réelles + main d'œuvre réelle vs budget + MO planifiée)
    $totalReelAvecMO = $totalFacturesReelles + $mainDoeuvreReelle['cout'];
    $totalBudgetAvecMO = $totalBudgetRenovation + $mainDoeuvreExtrapole['cout'];
    $progressionBudget = $totalBudgetAvecMO > 0 ? ($totalReelAvecMO / $totalBudgetAvecMO) * 100 : 0;

    // ÉQUITÉ RÉELLE basée sur les dépenses réelles
    $coutTotalReel = (float) $projet['prix_achat']
        + $coutsAcquisition['total']
        + $coutsRecurrents['total']
        + $coutsVente['total']
        + $totalFacturesReelles       // Factures réelles
        + $mainDoeuvreReelle['cout']; // Main d'œuvre réelle
    $equiteReelle = $valeurPotentielle - $coutTotalReel;

    // ROI réels
    $roiLeverageReel = $dataFinancement['mise_totale'] > 0 ? ($equiteReelle / $dataFinancement['mise_totale']) * 100 : 0;
    $roiAllCashReel = $coutTotalReel > 0 ? ($equiteReelle / $coutTotalReel) * 100 : 0;

    return [
        'couts_acquisition' => $coutsAcquisition,
        'couts_recurrents' => $coutsRecurrents,
        'couts_vente' => $coutsVente,
        'couts_fixes_totaux' => $coutsFixesTotaux,
        'renovation' => [
            'budget' => $totalBudgetRenovation,
            'reel' => $totalFacturesReelles,
            'ecart' => $totalBudgetAvecMO - $totalReelAvecMO,
            'progression' => $progressionBudget,
            'contingence' => $budgetComplet['contingence'],
            'sous_total_avant_taxes' => $budgetComplet['sous_total_avant_taxes'],
            'tps' => $budgetComplet['tps'],
            'tvq' => $budgetComplet['tvq'],
            'total_ttc' => $budgetComplet['total_ttc']
        ],
        'main_doeuvre' => [
            'heures' => $mainDoeuvreReelle['heures'],
            'cout' => $mainDoeuvreReelle['cout']
        ],
        'main_doeuvre_extrapole' => [
            'heures' => $mainDoeuvreExtrapole['heures'],
            'cout' => $mainDoeuvreExtrapole['cout'],
            'jours' => $mainDoeuvreExtrapole['jours']
        ],
        'contingence' => $contingence,
        'cout_total_projet' => $coutTotalProjet,
        'valeur_potentielle' => $valeurPotentielle,
        'equite_potentielle' => $equitePotentielle,
        'equite_reelle' => $equiteReelle,
        'cout_total_reel' => $coutTotalReel,
        'roi_leverage' => $roiLeverage,
        'roi_all_cash' => $roiAllCash,
        'roi_leverage_reel' => $roiLeverageReel,
        'roi_all_cash_reel' => $roiAllCashReel,
        'pourcentages' => [
            'couts_fixes' => $pctCoutsFixes,
            'renovation' => $pctRenovation,
            'prix_achat' => $pctPrixAchat
        ],
        'preteurs' => $dataFinancement['preteurs'],
        'investisseurs' => $dataFinancement['investisseurs'],
        'total_prets' => $dataFinancement['total_prets'],
        'total_interets' => $dataFinancement['total_interets'],
        'mise_fonds_totale' => $dataFinancement['mise_totale']
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

/**
 * Calcule le budget de rénovation complet avec contingence et taxes (par item)
 * Les items marqués sans_taxe ne sont pas taxés
 * @param PDO $pdo
 * @param int $projetId
 * @param float $tauxContingence
 * @return array
 */
function calculerBudgetRenovationComplet($pdo, $projetId, $tauxContingence) {
    // Charger les quantités de groupes pour ce projet
    $groupeQtes = [];
    try {
        $stmt = $pdo->prepare("SELECT groupe_nom, quantite FROM projet_groupes WHERE projet_id = ?");
        $stmt->execute([$projetId]);
        foreach ($stmt->fetchAll() as $row) {
            $groupeQtes[$row['groupe_nom']] = (int)$row['quantite'];
        }
    } catch (Exception $e) {
        // Table n'existe pas encore
    }

    // Calculer les totaux taxables et non-taxables par item
    $totalTaxable = 0;
    $totalNonTaxable = 0;
    $hasItems = false;

    try {
        $stmt = $pdo->prepare("
            SELECT pi.prix_unitaire, pi.quantite as qte_item, pi.sans_taxe,
                   pp.quantite as qte_cat, c.groupe
            FROM projet_items pi
            JOIN projet_postes pp ON pi.projet_poste_id = pp.id
            JOIN categories c ON pp.categorie_id = c.id
            WHERE pi.projet_id = ?
        ");
        $stmt->execute([$projetId]);
        $items = $stmt->fetchAll();

        foreach ($items as $row) {
            $hasItems = true;
            $prix = (float)$row['prix_unitaire'];
            $qteItem = (int)$row['qte_item'];
            $qteCat = (int)$row['qte_cat'];
            $groupe = $row['groupe'] ?? 'autre';
            $qteGroupe = $groupeQtes[$groupe] ?? 1;
            $sansTaxe = (int)($row['sans_taxe'] ?? 0);

            $montant = $prix * $qteItem * $qteCat * $qteGroupe;

            if ($sansTaxe) {
                $totalNonTaxable += $montant;
            } else {
                $totalTaxable += $montant;
            }
        }
    } catch (Exception $e) {
        // Table n'existe pas
    }

    // Si pas d'items, utiliser le budget total comme taxable (fallback pour anciens projets)
    if (!$hasItems) {
        $totalTaxable = calculerTotalBudgetRenovation($pdo, $projetId);
    }

    $budgetHT = $totalTaxable + $totalNonTaxable;
    $contingence = $budgetHT * ($tauxContingence / 100);

    // Contingence proportionnelle taxable
    $contingenceTaxable = $budgetHT > 0 ? $contingence * ($totalTaxable / $budgetHT) : 0;

    // Base taxable = items taxables + portion taxable de la contingence
    $baseTaxable = $totalTaxable + $contingenceTaxable;
    $tps = $baseTaxable * 0.05; // 5%
    $tvq = $baseTaxable * 0.09975; // 9.975%
    $totalAvecTaxes = $budgetHT + $contingence + $tps + $tvq;

    return [
        'budget_ht' => round($budgetHT, 2),
        'total_taxable' => round($totalTaxable, 2),
        'total_non_taxable' => round($totalNonTaxable, 2),
        'contingence' => round($contingence, 2),
        'sous_total_avant_taxes' => round($budgetHT + $contingence, 2),
        'tps' => round($tps, 2),
        'tvq' => round($tvq, 2),
        'total_ttc' => round($totalAvecTaxes, 2)
    ];
}
