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
        'arpenteurs' => (float) $projet['arpenteurs'],
        'assurance_titre' => (float) $projet['assurance_titre'],
        'solde_vendeur' => (float) ($projet['solde_vendeur'] ?? 0),
        'total' => (float) ($projet['cession'] ?? 0) +
                   (float) $projet['notaire'] +
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
    $aujourdhui = new DateTime();

    // Utiliser date_vente SEULEMENT si elle est dans le passé (projet vendu)
    // Sinon utiliser aujourd'hui pour le calcul réel
    if (!empty($projet['date_vente'])) {
        $dateVente = new DateTime($projet['date_vente']);
        $dateFin = ($dateVente <= $aujourdhui) ? $dateVente : $aujourdhui;
    } else {
        $dateFin = $aujourdhui;
    }

    // Si date_acquisition est dans le futur, pas encore de mois écoulés
    if ($dateAchat > $dateFin) {
        return 0;
    }

    $diff = $dateAchat->diff($dateFin);
    $mois = ($diff->y * 12) + $diff->m;

    // Mois entamé = mois complet (cohérent avec le calcul des intérêts)
    if ($diff->d > 0) {
        $mois++;
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
 * Calcule les coûts récurrents depuis le système dynamique (projet_recurrents)
 * @param PDO $pdo
 * @param int $projetId
 * @param int $mois - Durée en mois pour le calcul
 * @return array
 */
function calculerCoutsRecurrentsDynamiques($pdo, $projetId, $mois) {
    $total = 0;
    $details = [];
    $facteur = $mois / 12;

    try {
        $stmt = $pdo->prepare("
            SELECT rt.code, rt.frequence, pr.montant
            FROM projet_recurrents pr
            JOIN recurrents_types rt ON pr.recurrent_type_id = rt.id
            WHERE pr.projet_id = ? AND rt.actif = 1
        ");
        $stmt->execute([$projetId]);

        foreach ($stmt->fetchAll() as $row) {
            $montant = (float)$row['montant'];
            $code = $row['code'];
            $frequence = $row['frequence'];

            // Calculer selon la fréquence
            if ($frequence === 'mensuel') {
                $extrapole = $montant * $mois;
            } elseif ($frequence === 'saisonnier') {
                $extrapole = $montant; // Montant fixe
            } else { // annuel
                $extrapole = $montant * $facteur;
            }

            // Déterminer la clé (annuel ou mensuel)
            $sourceKey = ($frequence === 'mensuel') ? 'mensuel' : 'annuel';

            $details[$code] = [
                $sourceKey => $montant,
                'extrapole' => $extrapole
            ];

            // Loyer est un revenu (soustraire)
            if ($code === 'loyer') {
                $total -= $extrapole;
            } else {
                $total += $extrapole;
            }
        }
    } catch (Exception $e) {
        // Table n'existe pas encore, retourner structure vide
    }

    // Retourner structure compatible avec l'ancien système
    return [
        'taxes_municipales' => $details['taxes_municipales'] ?? ['annuel' => 0, 'extrapole' => 0],
        'taxes_scolaires' => $details['taxes_scolaires'] ?? ['annuel' => 0, 'extrapole' => 0],
        'electricite' => $details['electricite'] ?? ['annuel' => 0, 'extrapole' => 0],
        'assurances' => $details['assurances'] ?? ['annuel' => 0, 'extrapole' => 0],
        'deneigement' => $details['deneigement'] ?? ['annuel' => 0, 'extrapole' => 0],
        'frais_condo' => $details['frais_condo'] ?? ['annuel' => 0, 'extrapole' => 0],
        'hypotheque' => $details['hypotheque'] ?? ['mensuel' => 0, 'extrapole' => 0],
        'loyer' => $details['loyer'] ?? ['mensuel' => 0, 'extrapole' => 0],
        'details' => $details,
        'total' => $total
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

    // Taxe de mutation
    $taxeMutation = (float) ($projet['taxe_mutation'] ?? 0);

    // Solde à payer par l'acheteur (ajustement de taxes en faveur du vendeur = réduit les coûts)
    $soldeAcheteur = (float) ($projet['solde_acheteur'] ?? 0);

    return [
        'commission' => $commissionHT,
        'commission_ttc' => $commissionTTC,
        'taxes_commission' => $taxesCommission,
        'interets' => $interets,
        'quittance' => $quittance,
        'taxe_mutation' => $taxeMutation,
        'solde_acheteur' => $soldeAcheteur,
        'total' => $commissionTTC + $interets + $quittance + $taxeMutation - $soldeAcheteur
    ];
}

/**
 * Calcule le total des budgets de rénovation extrapolés (avec quantités de groupes)
 * @param PDO $pdo
 * @param int $projetId
 * @return float
 */
function calculerTotalBudgetRenovation($pdo, $projetId) {
    // NOUVEAU SYSTÈME: Utiliser budget_items (panier du budget-builder)
    try {
        $stmt = $pdo->prepare("
            SELECT SUM(prix * quantite) as total
            FROM budget_items
            WHERE projet_id = ? AND (type = 'item' OR type IS NULL)
        ");
        $stmt->execute([$projetId]);
        $result = $stmt->fetch();
        $total = (float) ($result['total'] ?? 0);

        // Si on a des données dans le nouveau système, les utiliser
        if ($total > 0) {
            return $total;
        }
    } catch (Exception $e) {
        // Table n'existe pas encore, fallback à l'ancien système
    }

    // ANCIEN SYSTÈME (fallback): Utiliser budgets table
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
 * Calcule le budget de rénovation par étape (section) - NOUVEAU SYSTÈME
 * @param PDO $pdo
 * @param int $projetId
 * @return array [etape_id => ['nom' => string, 'total' => float]]
 */
function calculerBudgetParEtape($pdo, $projetId) {
    $result = [];

    try {
        $stmt = $pdo->prepare("
            SELECT
                e.id as etape_id,
                e.nom as etape_nom,
                e.ordre as etape_ordre,
                SUM(bi.prix * bi.quantite) as total
            FROM budget_items bi
            LEFT JOIN catalogue_items ci ON bi.catalogue_item_id = ci.id
            LEFT JOIN budget_etapes e ON ci.etape_id = e.id
            WHERE bi.projet_id = ? AND (bi.type = 'item' OR bi.type IS NULL)
            GROUP BY e.id, e.nom, e.ordre
            ORDER BY e.ordre, e.nom
        ");
        $stmt->execute([$projetId]);

        foreach ($stmt->fetchAll() as $row) {
            $etapeId = $row['etape_id'] ?? 0;
            $result[$etapeId] = [
                'nom' => $row['etape_nom'] ?? 'Non spécifié',
                'ordre' => $row['etape_ordre'] ?? 999,
                'total' => (float) ($row['total'] ?? 0)
            ];
        }
    } catch (Exception $e) {
        // Table n'existe pas
    }

    return $result;
}

/**
 * Calcule les dépenses réelles par étape (factures groupées par etape_id)
 * @param PDO $pdo
 * @param int $projetId
 * @return array [etape_id => ['nom' => string, 'total' => float]]
 */
function calculerDepensesParEtape($pdo, $projetId) {
    $result = [];

    try {
        $stmt = $pdo->prepare("
            SELECT
                e.id as etape_id,
                e.nom as etape_nom,
                e.ordre as etape_ordre,
                SUM(f.montant_avant_taxes) as total
            FROM factures f
            LEFT JOIN budget_etapes e ON f.etape_id = e.id
            WHERE f.projet_id = ? AND f.statut != 'rejetee' AND f.etape_id IS NOT NULL
            GROUP BY e.id, e.nom, e.ordre
            ORDER BY e.ordre, e.nom
        ");
        $stmt->execute([$projetId]);

        foreach ($stmt->fetchAll() as $row) {
            $etapeId = $row['etape_id'] ?? 0;
            $result[$etapeId] = [
                'nom' => $row['etape_nom'] ?? 'Non spécifié',
                'ordre' => $row['etape_ordre'] ?? 999,
                'total' => (float) ($row['total'] ?? 0)
            ];
        }
    } catch (Exception $e) {
        // Table n'existe pas
    }

    return $result;
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
 * Calcule les taxes réelles des factures (TPS, TVQ et montant HT)
 * @param PDO $pdo
 * @param int $projetId
 * @return array ['montant_ht' => float, 'tps' => float, 'tvq' => float, 'total_ttc' => float]
 */
function calculerTaxesFacturesReelles($pdo, $projetId) {
    $stmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(montant_avant_taxes), 0) as montant_ht,
            COALESCE(SUM(tps), 0) as tps,
            COALESCE(SUM(tvq), 0) as tvq,
            COALESCE(SUM(montant_total), 0) as total_ttc
        FROM factures
        WHERE projet_id = ? AND statut != 'rejetee'
    ");
    $stmt->execute([$projetId]);
    $result = $stmt->fetch();

    return [
        'montant_ht' => (float) ($result['montant_ht'] ?? 0),
        'tps' => (float) ($result['tps'] ?? 0),
        'tvq' => (float) ($result['tvq'] ?? 0),
        'total_ttc' => (float) ($result['total_ttc'] ?? 0)
    ];
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
        // Utiliser le taux historique (h.taux_horaire) si sauvegardé, sinon le taux actuel (u.taux_horaire)
        $stmt = $pdo->prepare("
            SELECT SUM(h.heures) as total_heures,
                   SUM(h.heures * IF(h.taux_horaire > 0, h.taux_horaire, u.taux_horaire)) as total_cout
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
    // Utiliser montant_avant_taxes (HT) car les taxes sont affichées séparément
    $stmt = $pdo->prepare("
        SELECT categorie_id, SUM(montant_avant_taxes) as total
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
        // Requête avec type_financement pour différencier prêteurs et investisseurs
        $stmt = $pdo->prepare("
            SELECT pi.id, pi.projet_id, pi.investisseur_id,
                   pi.montant, pi.taux_interet,
                   COALESCE(pi.type_financement, 'preteur') as type_financement,
                   COALESCE(pi.pourcentage_profit, 0) as pourcentage_profit,
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
            $typeFinancement = $row['type_financement'] ?? 'preteur';
            $pourcentageProfit = (float) ($row['pourcentage_profit'] ?? 0);

            // Utiliser type_financement pour séparer (pas le taux)
            if ($typeFinancement === 'preteur') {
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
            } else {
                // Investisseur : partage des profits (pourcentage direct ou calculé)
                $investisseurs[] = [
                    'id' => $row['id'],
                    'nom' => $row['nom'],
                    'mise_de_fonds' => $montant,
                    'pourcentage' => $pourcentageProfit, // Pourcentage direct si spécifié
                    'profit_estime' => 0
                ];

                $miseTotaleInvestisseurs += $montant;
            }
        }

        // Calculer le profit pour chaque investisseur basé sur leur mise de fonds
        // Note: $equitePotentielle inclut déjà les intérêts déduits (via coutsVente dans coutTotalProjet)
        // Ne pas soustraire $totalInterets une 2ème fois
        $profitApresInterets = $equitePotentielle;

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

    // Durée PRÉVUE (extrapolée)
    $moisPrevu = (int) $projet['temps_assume_mois'];

    // Durée RÉELLE (de date_acquisition jusqu'à date_vente OU aujourd'hui)
    $moisReel = $moisPrevu; // Par défaut
    if (!empty($projet['date_acquisition'])) {
        $dateAchat = new DateTime($projet['date_acquisition']);
        $aujourdhui = new DateTime();

        // Utiliser date_vente SEULEMENT si elle est dans le passé (projet vendu)
        // Sinon utiliser aujourd'hui pour le calcul réel
        if (!empty($projet['date_vente'])) {
            $dateVente = new DateTime($projet['date_vente']);
            $dateFin = ($dateVente <= $aujourdhui) ? $dateVente : $aujourdhui;
        } else {
            $dateFin = $aujourdhui;
        }

        // Si date_acquisition est dans le futur, pas encore d'intérêts réels
        if ($dateAchat > $dateFin) {
            $moisReel = 0;
        } else {
            $diff = $dateAchat->diff($dateFin);
            $moisReel = ($diff->y * 12) + $diff->m;
            // Ajouter 1 mois si on a des jours supplémentaires (mois entamé = mois complet pour les intérêts)
            if ($diff->d > 0) {
                $moisReel++;
            }
            $moisReel = max(1, $moisReel);
        }
    }

    // Utiliser $moisPrevu pour le budget extrapolé
    $mois = $moisPrevu;
    
    // Modifier temp_assume_mois pour le calcul des récurrents
    $projetModifie = $projet;
    $projetModifie['temps_assume_mois'] = $mois;
    
    // Coûts récurrents (avec durée réelle) - utiliser le système dynamique
    $coutsRecurrents = calculerCoutsRecurrentsDynamiques($pdo, $projet['id'], $mois);
    // Fallback vers l'ancien système si aucune donnée dans le nouveau
    if ($coutsRecurrents['total'] == 0) {
        $coutsRecurrents = calculerCoutsRecurrents($projetModifie);
    }
    
    // Coûts de vente (sans intérêts pour l'instant)
    $coutsVente = calculerCoutsVente($projetModifie);
    
    // Rénovation
    $totalBudgetRenovation = calculerTotalBudgetRenovation($pdo, $projet['id']);
    $totalFacturesReelles = calculerTotalFacturesReelles($pdo, $projet['id']);
    $taxesReelles = calculerTaxesFacturesReelles($pdo, $projet['id']);
    $contingence = calculerContingence($totalBudgetRenovation, (float) $projet['taux_contingence']);

    // Budget avec taxes
    $budgetComplet = calculerBudgetRenovationComplet($pdo, $projet['id'], (float) $projet['taux_contingence']);
    
    // Récupérer les prêteurs pour avoir les intérêts PRÉVUS (avec durée prévue)
    $dataFinancement = getInvestisseursProjet($pdo, $projet['id'], 0, $moisPrevu);

    // Récupérer les intérêts RÉELS (avec durée réelle écoulée)
    $dataFinancementReel = getInvestisseursProjet($pdo, $projet['id'], 0, $moisReel);
    $interetsReels = $dataFinancementReel['total_interets'];

    // Remplacer les intérêts de vente par les intérêts des prêteurs si disponibles
    if ($dataFinancement['total_interets'] > 0) {
        $coutsVente['interets'] = $dataFinancement['total_interets'];
        $coutsVente['interets_reel'] = $interetsReels;
        $coutsVente['total'] = $coutsVente['commission_ttc'] + $coutsVente['interets'] + $coutsVente['quittance'] + ($coutsVente['taxe_mutation'] ?? 0) - ($coutsVente['solde_acheteur'] ?? 0);
    } else {
        // Calculer intérêts réels basés sur la durée réelle (sans prêteurs individuels)
        $tauxInteret = (float) $projet['taux_interet'];
        $montantPret = (float) $projet['montant_pret'];
        $tauxMensuel = $tauxInteret / 100 / 12;
        $coutsVente['interets_reel'] = $montantPret * (pow(1 + $tauxMensuel, $moisReel) - 1);
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
    // Note: totalFacturesReelles est TTC, donc utiliser budgetComplet['total_ttc'] pour comparer TTC vs TTC
    $totalReelAvecMO = $totalFacturesReelles + $mainDoeuvreReelle['cout'];
    $totalBudgetAvecMO = $budgetComplet['total_ttc'] + $mainDoeuvreExtrapole['cout'];
    $progressionBudget = $totalBudgetAvecMO > 0 ? ($totalReelAvecMO / $totalBudgetAvecMO) * 100 : 0;

    // ÉQUITÉ RÉELLE basée sur les dépenses réelles
    // Calculer les coûts récurrents RÉELS (basés sur le temps écoulé)
    $coutsRecurrentsReels = calculerCoutsRecurrentsDynamiques($pdo, $projet['id'], $moisReel);
    if ($coutsRecurrentsReels['total'] == 0) {
        $coutsRecurrentsReels = calculerCoutsRecurrentsReels($projet);
    }

    // Calculer les coûts de vente RÉELS (avec intérêts réels)
    $coutsVenteReel = $coutsVente['commission_ttc']
        + ($coutsVente['interets_reel'] ?? $coutsVente['interets'])
        + $coutsVente['quittance']
        + ($coutsVente['taxe_mutation'] ?? 0)
        - ($coutsVente['solde_acheteur'] ?? 0);

    $coutTotalReel = (float) $projet['prix_achat']
        + $coutsAcquisition['total']
        + $coutsRecurrentsReels['total']  // Récurrents RÉELS
        + $coutsVenteReel                 // Vente avec intérêts RÉELS
        + $totalFacturesReelles           // Factures réelles
        + $mainDoeuvreReelle['cout'];     // Main d'œuvre réelle
    $equiteReelle = $valeurPotentielle - $coutTotalReel;

    // ROI réels
    $roiLeverageReel = $dataFinancement['mise_totale'] > 0 ? ($equiteReelle / $dataFinancement['mise_totale']) * 100 : 0;
    $roiAllCashReel = $coutTotalReel > 0 ? ($equiteReelle / $coutTotalReel) * 100 : 0;

    // Cash flow nécessaire (tout SAUF courtier, taxes municipales, taxes scolaires, mutation)
    $cashFlowNecessaire = (float) $projet['prix_achat']
        // Acquisition sans taxe_mutation
        + ($coutsAcquisition['cession'] ?? 0)
        + ($coutsAcquisition['notaire'] ?? 0)
        + ($coutsAcquisition['arpenteurs'] ?? 0)
        + ($coutsAcquisition['assurance_titre'] ?? 0)
        + ($coutsAcquisition['solde_vendeur'] ?? 0)
        // Récurrents sans taxes municipales et scolaires
        + ($coutsRecurrents['total'] - ($coutsRecurrents['taxes_municipales']['extrapole'] ?? 0) - ($coutsRecurrents['taxes_scolaires']['extrapole'] ?? 0))
        // Budget rénovation TTC
        + $budgetComplet['total_ttc']
        // Main d'oeuvre
        + $mainDoeuvreExtrapole['cout']
        // Intérêts prêteurs
        + ($coutsVente['interets'] ?? 0)
        // Quittance
        + ($coutsVente['quittance'] ?? 0)
        // Solde acheteur (soustrait car c'est de l'argent reçu)
        - ($coutsVente['solde_acheteur'] ?? 0);

    // Cash flow moins les intérêts
    $cashFlowMoinsInterets = $cashFlowNecessaire - $dataFinancement['total_interets'];

    return [
        'couts_acquisition' => $coutsAcquisition,
        'couts_recurrents' => $coutsRecurrents,
        'couts_vente' => $coutsVente,
        'couts_fixes_totaux' => $coutsFixesTotaux,
        'renovation' => [
            'budget' => $totalBudgetRenovation,
            'reel' => $totalFacturesReelles,
            'reel_ht' => $taxesReelles['montant_ht'],
            'reel_tps' => $taxesReelles['tps'],
            'reel_tvq' => $taxesReelles['tvq'],
            'reel_ttc' => $taxesReelles['total_ttc'],
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
        'contingence' => $budgetComplet['contingence'],
        'cout_total_projet' => $coutTotalProjet,
        'valeur_potentielle' => $valeurPotentielle,
        'equite_potentielle' => $equitePotentielle,
        'equite_reelle' => $equiteReelle,
        'cout_total_reel' => $coutTotalReel,
        'cash_flow_necessaire' => $cashFlowNecessaire,
        'cash_flow_moins_interets' => $cashFlowMoinsInterets,
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
        'total_interets_reel' => $coutsVente['interets_reel'],
        'mise_fonds_totale' => $dataFinancement['mise_totale'],
        'mois_prevu' => $moisPrevu,
        'mois_reel' => $moisReel
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
    // Calculer les totaux taxables et non-taxables par item
    $totalTaxable = 0;
    $totalNonTaxable = 0;
    $hasItems = false;

    // NOUVEAU SYSTÈME: Essayer d'abord budget_items (panier du budget-builder)
    try {
        $stmt = $pdo->prepare("
            SELECT bi.prix, bi.quantite, ci.sans_taxe
            FROM budget_items bi
            LEFT JOIN catalogue_items ci ON bi.catalogue_item_id = ci.id
            WHERE bi.projet_id = ? AND (bi.type = 'item' OR bi.type IS NULL)
        ");
        $stmt->execute([$projetId]);
        $items = $stmt->fetchAll();

        foreach ($items as $row) {
            $hasItems = true;
            $prix = (float)$row['prix'];
            $qte = (int)$row['quantite'];
            $sansTaxe = (int)($row['sans_taxe'] ?? 0);

            $montant = $prix * $qte;

            if ($sansTaxe) {
                $totalNonTaxable += $montant;
            } else {
                $totalTaxable += $montant;
            }
        }
    } catch (Exception $e) {
        // Table n'existe pas
    }

    // ANCIEN SYSTÈME: Fallback sur projet_items
    if (!$hasItems) {
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
            $oldItems = $stmt->fetchAll();

            foreach ($oldItems as $row) {
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
    }

    // Si toujours pas d'items, utiliser le budget total comme taxable (fallback)
    if (!$hasItems) {
        $totalTaxable = calculerTotalBudgetRenovation($pdo, $projetId);
    }

    $budgetHT = $totalTaxable + $totalNonTaxable;
    $contingence = $budgetHT * ($tauxContingence / 100);

    // Pas de taxe sur la contingence
    $tps = $totalTaxable * 0.05; // 5%
    $tvq = $totalTaxable * 0.09975; // 9.975%
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

/**
 * Calcule les montants taxables et non-taxables par catégorie
 * @param PDO $pdo
 * @param int $projetId
 * @return array [categorie_id => ['taxable' => float, 'non_taxable' => float]]
 */
function calculerTaxabiliteParCategorie($pdo, $projetId) {
    $result = [];

    // Charger les quantités de groupes
    $groupeQtes = [];
    try {
        $stmt = $pdo->prepare("SELECT groupe_nom, quantite FROM projet_groupes WHERE projet_id = ?");
        $stmt->execute([$projetId]);
        foreach ($stmt->fetchAll() as $row) {
            $groupeQtes[$row['groupe_nom']] = (int)$row['quantite'];
        }
    } catch (Exception $e) {}

    try {
        $stmt = $pdo->prepare("
            SELECT pi.prix_unitaire, pi.quantite as qte_item, pi.sans_taxe,
                   pp.quantite as qte_cat, pp.categorie_id, c.groupe
            FROM projet_items pi
            JOIN projet_postes pp ON pi.projet_poste_id = pp.id
            JOIN categories c ON pp.categorie_id = c.id
            WHERE pi.projet_id = ?
        ");
        $stmt->execute([$projetId]);

        foreach ($stmt->fetchAll() as $row) {
            $catId = (int)$row['categorie_id'];
            $prix = (float)$row['prix_unitaire'];
            $qteItem = (int)$row['qte_item'];
            $qteCat = (int)$row['qte_cat'];
            $groupe = $row['groupe'] ?? 'autre';
            $qteGroupe = $groupeQtes[$groupe] ?? 1;
            $sansTaxe = (int)($row['sans_taxe'] ?? 0);

            $montant = $prix * $qteItem * $qteCat * $qteGroupe;

            if (!isset($result[$catId])) {
                $result[$catId] = ['taxable' => 0, 'non_taxable' => 0];
            }

            if ($sansTaxe) {
                $result[$catId]['non_taxable'] += $montant;
            } else {
                $result[$catId]['taxable'] += $montant;
            }
        }
    } catch (Exception $e) {}

    return $result;
}

/**
 * Calcule le profit cumulatif de l'année fiscale (projets vendus)
 * @param PDO $pdo
 * @param int $annee Année fiscale (ex: 2024)
 * @param int|null $exclureProjetId ID du projet à exclure du calcul (pour voir le cumulatif AVANT ce projet)
 * @return array ['total' => float, 'projets' => array]
 */
function calculerProfitCumulatifAnneeFiscale($pdo, $annee, $exclureProjetId = null) {
    $dateDebut = $annee . '-01-01';
    $dateFin = $annee . '-12-31';

    $projetsVendus = [];
    $profitCumulatif = 0;

    // Récupérer tous les projets vendus dans l'année fiscale
    $sql = "SELECT * FROM projets
            WHERE date_vente IS NOT NULL
            AND date_vente BETWEEN ? AND ?
            AND statut != 'archive'";
    $params = [$dateDebut, $dateFin];

    if ($exclureProjetId) {
        $sql .= " AND id != ?";
        $params[] = $exclureProjetId;
    }

    $sql .= " ORDER BY date_vente ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    foreach ($stmt->fetchAll() as $projet) {
        // Calculer les indicateurs pour chaque projet
        $indicateurs = calculerIndicateursProjet($pdo, $projet);
        $profit = $indicateurs['equite_reelle'] ?? 0;

        // Seulement compter les profits positifs pour le calcul fiscal
        if ($profit > 0) {
            $profitCumulatif += $profit;
        }

        $projetsVendus[] = [
            'id' => $projet['id'],
            'nom' => $projet['nom'],
            'date_vente' => $projet['date_vente'],
            'profit' => $profit,
            'profit_cumulatif' => $profitCumulatif
        ];
    }

    return [
        'annee' => $annee,
        'total' => $profitCumulatif,
        'projets' => $projetsVendus
    ];
}

/**
 * Calcule l'impôt en tenant compte du profit cumulatif de l'année
 * @param float $profitProjet Profit du projet courant
 * @param float $profitCumulatifAvant Profit cumulatif de l'année AVANT ce projet
 * @return array ['impot' => float, 'taux_effectif' => float, 'detail' => array]
 */
function calculerImpotAvecCumulatif($profitProjet, $profitCumulatifAvant = 0) {
    $seuilDPE = 500000;
    $tauxBase = 0.122; // 12,2%
    $tauxEleve = 0.265; // 26,5%

    if ($profitProjet <= 0) {
        return [
            'impot' => 0,
            'taux_effectif' => 0,
            'taux_affiche' => '0%',
            'detail' => [
                'portion_12_2' => 0,
                'portion_26_5' => 0,
                'impot_12_2' => 0,
                'impot_26_5' => 0
            ]
        ];
    }

    // Où on commence dans la tranche (basé sur le cumulatif avant)
    $positionDebut = max(0, $profitCumulatifAvant);
    $positionFin = $positionDebut + $profitProjet;

    // Calculer combien du profit est dans chaque tranche
    $portion122 = 0;
    $portion265 = 0;

    if ($positionDebut < $seuilDPE) {
        // Une partie ou tout est dans la tranche 12.2%
        $portion122 = min($profitProjet, $seuilDPE - $positionDebut);
        $portion265 = max(0, $profitProjet - $portion122);
    } else {
        // Tout est dans la tranche 26.5%
        $portion265 = $profitProjet;
    }

    $impot122 = $portion122 * $tauxBase;
    $impot265 = $portion265 * $tauxEleve;
    $impotTotal = $impot122 + $impot265;

    // Taux effectif
    $tauxEffectif = $profitProjet > 0 ? ($impotTotal / $profitProjet) : 0;

    // Taux à afficher
    if ($portion265 > 0 && $portion122 > 0) {
        $tauxAffiche = '12,2% + 26,5%';
    } elseif ($portion265 > 0) {
        $tauxAffiche = '26,5%';
    } else {
        $tauxAffiche = '12,2%';
    }

    return [
        'impot' => $impotTotal,
        'taux_effectif' => $tauxEffectif,
        'taux_affiche' => $tauxAffiche,
        'detail' => [
            'portion_12_2' => $portion122,
            'portion_26_5' => $portion265,
            'impot_12_2' => $impot122,
            'impot_26_5' => $impot265,
            'position_debut' => $positionDebut,
            'position_fin' => $positionFin,
            'seuil_restant' => max(0, $seuilDPE - $positionDebut)
        ]
    ];
}

/**
 * Obtient le résumé fiscal de l'année avec projections
 * @param PDO $pdo
 * @param int $annee
 * @return array
 */
function obtenirResumeAnneeFiscale($pdo, $annee) {
    $seuilDPE = 500000;
    $tauxBase = 0.122;
    $tauxEleve = 0.265;

    // Projets vendus
    $cumulatif = calculerProfitCumulatifAnneeFiscale($pdo, $annee);

    // Projets en cours (non vendus) pour projections
    $stmt = $pdo->prepare("
        SELECT * FROM projets
        WHERE (date_vente IS NULL OR date_vente > CURDATE())
        AND statut NOT IN ('archive', 'termine')
    ");
    $stmt->execute();
    $projetsEnCours = [];
    $profitProjete = 0;

    foreach ($stmt->fetchAll() as $projet) {
        $indicateurs = calculerIndicateursProjet($pdo, $projet);
        $profitEstime = $indicateurs['equite_potentielle'] ?? 0;

        if ($profitEstime > 0) {
            $profitProjete += $profitEstime;
        }

        $projetsEnCours[] = [
            'id' => $projet['id'],
            'nom' => $projet['nom'],
            'statut' => $projet['statut'],
            'profit_estime' => $profitEstime
        ];
    }

    // Calcul des impôts sur profits réalisés
    $profitRealise = $cumulatif['total'];
    $impotRealise = calculerImpotAvecCumulatif($profitRealise, 0);

    // Projection si tous les projets en cours sont vendus cette année
    $profitTotalProjection = $profitRealise + $profitProjete;
    $impotProjection = calculerImpotAvecCumulatif($profitTotalProjection, 0);

    // Seuil DPE restant
    $seuilRestant = max(0, $seuilDPE - $profitRealise);
    $pourcentageUtilise = min(100, ($profitRealise / $seuilDPE) * 100);

    return [
        'annee' => $annee,
        'profit_realise' => $profitRealise,
        'impot_realise' => $impotRealise['impot'],
        'taux_effectif_realise' => $impotRealise['taux_effectif'],
        'profit_net_realise' => $profitRealise - $impotRealise['impot'],
        'projets_vendus' => $cumulatif['projets'],
        'projets_en_cours' => $projetsEnCours,
        'profit_projete' => $profitProjete,
        'profit_total_projection' => $profitTotalProjection,
        'impot_projection' => $impotProjection['impot'],
        'taux_effectif_projection' => $impotProjection['taux_effectif'],
        'seuil_dpe' => $seuilDPE,
        'seuil_restant' => $seuilRestant,
        'pourcentage_utilise' => $pourcentageUtilise
    ];
}
