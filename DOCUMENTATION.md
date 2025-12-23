# Flip Manager - Documentation Complète

**Version 1.0 - Décembre 2025**

Logiciel de gestion intégrale pour entreprise de flip immobilier au Québec.

---

# PARTIE 1: PRÉSENTATION GÉNÉRALE

## 1.1 Qu'est-ce que Flip Manager?

Flip Manager est une application web conçue spécifiquement pour les entrepreneurs en flip immobilier (achat-rénovation-revente). Elle permet de:

- Gérer plusieurs projets de flip simultanément
- Planifier les budgets de rénovation avec précision
- Suivre toutes les dépenses en temps réel
- Gérer les employés et sous-traitants
- Calculer la rentabilité réelle vs prévue
- Optimiser la fiscalité selon les lois québécoises
- Collaborer avec une équipe via un portail employé

## 1.2 Problèmes résolus

| Problème | Solution Flip Manager |
|----------|----------------------|
| Suivi des dépenses éparpillé (Excel, papier) | Tout centralisé dans une seule application |
| Calculs manuels sujets aux erreurs | Calculs automatiques et en temps réel |
| Pas de visibilité sur la rentabilité réelle | Tableau comparatif Prévu vs Réel |
| Gestion fiscale complexe | Calcul automatique des impôts progressifs |
| Employés sans accès aux infos sensibles | Portail employé avec permissions limitées |
| Prêteurs multiples difficiles à suivre | Gestion multi-prêteurs avec intérêts composés |

## 1.3 Utilisateurs cibles

1. **Flippers solo** - Gestion de 1-5 projets par année
2. **Petites équipes** - Propriétaire + employés/sous-traitants
3. **Investisseurs** - Suivi de plusieurs projets avec prêteurs privés

---

# PARTIE 2: ACCÈS ET SÉCURITÉ

## 2.1 Deux types de comptes

### Compte Administrateur
- Accès complet à toutes les fonctionnalités
- Voir tous les chiffres financiers
- Gérer les projets, budgets, utilisateurs
- Approuver les factures et heures
- Voir le tableau de bord fiscal

### Compte Employé
- Accès limité aux informations non-sensibles
- Soumettre des factures
- Entrer ses heures travaillées
- Uploader des photos de chantier
- Voir uniquement ses propres soumissions
- **PAS d'accès aux:** budgets, profits, coûts, informations financières

## 2.2 Tableau des permissions détaillé

| Fonctionnalité | Employé | Admin |
|----------------|:-------:|:-----:|
| **Projets** | | |
| Voir la liste des projets | Nom + Adresse seulement | Tout |
| Créer un projet | Non | Oui |
| Modifier un projet | Non | Oui |
| Supprimer un projet | Non | Oui |
| Voir les détails financiers | Non | Oui |
| **Factures** | | |
| Soumettre une facture | Oui | Oui |
| Voir ses propres factures | Oui | Oui |
| Voir toutes les factures | Non | Oui |
| Approuver/Rejeter | Non | Oui |
| Modifier une facture | Non | Oui |
| Supprimer une facture | Non | Oui |
| **Heures** | | |
| Saisir ses heures | Oui | Oui |
| Saisir pour d'autres (contremaître) | Si autorisé | Oui |
| Voir toutes les heures | Non | Oui |
| Approuver les heures | Non | Oui |
| **Photos** | | |
| Uploader des photos | Oui | Oui |
| Voir les photos du projet | Oui | Oui |
| Supprimer des photos | Non | Oui |
| **Budgets** | | |
| Voir les budgets | Non | Oui |
| Modifier les budgets | Non | Oui |
| Utiliser Budget Builder | Non | Oui |
| **Rapports** | | |
| Tableau de bord | Non | Oui |
| Analyse de rentabilité | Non | Oui |
| Suivi fiscal | Non | Oui |
| **Administration** | | |
| Gérer les utilisateurs | Non | Oui |
| Gérer les catégories | Non | Oui |
| Configuration système | Non | Oui |

## 2.3 Sécurité

- **Mots de passe**: Hashés avec bcrypt (irréversible)
- **Sessions**: Sécurisées avec tokens uniques
- **CSRF**: Protection contre les attaques cross-site
- **Validation**: Toutes les entrées sont validées et nettoyées
- **Upload**: Vérification des types de fichiers autorisés

---

# PARTIE 3: TABLEAU DE BORD ADMINISTRATEUR

## 3.1 Statistiques globales (4 cartes)

### Carte 1: Projets actifs
- Nombre total de projets non archivés
- Clic: accès à la liste des projets

### Carte 2: Factures en attente
- Nombre de factures à approuver
- Badge d'alerte si > 0
- Clic: accès à la liste d'approbation

### Carte 3: Factures approuvées
- Total des factures traitées
- Indicateur de productivité

### Carte 4: Total dépensé
- Somme de toutes les factures approuvées
- Format monétaire canadien

## 3.2 Section Fiscalité (nouveau)

### En-tête
- **Titre**: "Fiscalité" avec icône graphique
- **Sélecteur d'année**: Menu déroulant des années fiscales
  - Années disponibles basées sur les dates de vente réelles
  - Année courante toujours présente
- **Pourcentage DPE**: Affichage grand format (ex: "45%")

### Jauge de progression
- Barre horizontale colorée
- **Vert** (0-74%): Seuil DPE confortable
- **Jaune** (75-99%): Attention, approche du seuil
- **Rouge** (100%+): Seuil dépassé, taux élevé applicable
- Indicateurs: 0$ | Reste X$ à 12,2% | 500 000$

### Chiffres clés (4 cartes)
1. **Flips vendus**: Nombre de projets vendus dans l'année
2. **Profit brut**: Total des profits avant impôt (bordure verte)
3. **Impôts**: Montant d'impôt à payer (bordure rouge)
4. **Profit net**: Profit après impôt (bordure bleue)

### Liste des projets vendus
- Nom du projet (cliquable vers détails)
- Date de vente (format: 15 Jan)
- Profit réalisé (vert si positif, rouge si négatif)
- Taux d'imposition appliqué (12,2% ou 26,5% ou mixte)

### Liste des projets en cours (projections)
- Nom du projet
- Profit estimé (basé sur les données actuelles)
- Taux qui s'appliquerait si vendu maintenant
- **Encadré de résumé**:
  - "Si tous vendus en 2025: X$"
  - "Impôts estimés: Y$"

## 3.3 Activités récentes

Liste chronologique des 20 dernières actions:

| Type | Icône | Informations affichées |
|------|-------|----------------------|
| Facture | Reçu | Fournisseur, projet, montant, statut |
| Heures | Horloge | Durée, description, projet, statut |
| Photo | Caméra | Description, projet, date |
| Projet | Bâtiment | Nom, statut, budget |

Chaque item affiche:
- Icône colorée selon le type
- Description principale
- Nom du projet associé
- Nom de l'employé qui a soumis
- Montant (si applicable)
- Date de création
- Badge de statut

## 3.4 Factures à approuver

Liste des factures en attente avec:
- Nom du fournisseur
- Projet associé
- Nom de l'employé soumetteur
- Montant
- Date de soumission
- **Bouton vert** pour approbation rapide (1 clic)
- Clic sur la ligne: ouvre les détails pour modification

## 3.5 Actions rapides

Boutons d'accès direct:
- Voir les projets
- Nouvelle facture
- Feuilles de temps
- Photos
- Paie hebdomadaire

---

# PARTIE 4: GESTION DES PROJETS

## 4.1 Liste des projets

### Informations affichées
- Nom du projet
- Adresse complète
- Statut (En cours / Terminé / Archivé)
- Date d'acquisition
- Indicateurs clés rapides

### Actions disponibles
- Voir les détails
- Modifier
- Archiver
- Supprimer (avec confirmation)

### Filtres
- Par statut
- Recherche par nom/adresse

## 4.2 Création d'un projet

### Informations de base
| Champ | Description | Obligatoire |
|-------|-------------|:-----------:|
| Nom | Identifiant du projet (ex: "Flip Laval 2025") | Oui |
| Adresse | Adresse complète de la propriété | Oui |
| Ville | Ville | Oui |
| Code postal | Format canadien (A1A 1A1) | Non |
| Statut | En cours / Terminé / Archivé | Oui |

### Dates importantes
| Champ | Description | Impact |
|-------|-------------|--------|
| Date d'acquisition | Date d'achat de la propriété | Début du calcul des intérêts et récurrents |
| Date de vente | Date de vente (réelle ou prévue) | Fin du calcul, année fiscale |
| Temps assumé (mois) | Durée prévue du projet | Calcul des coûts extrapolés |

### Coûts d'acquisition
| Champ | Description | Calcul |
|-------|-------------|--------|
| Prix d'achat | Prix payé pour la propriété | Base du profit |
| Rôle d'évaluation | Valeur municipale | Référence |
| Cession | Frais de cession si applicable | + coûts |
| Notaire | Honoraires notariaux | + coûts |
| Arpenteurs | Certificat de localisation | + coûts |
| Assurance titre | Protection juridique | + coûts |
| Solde vendeur | Ajustements (taxes, etc.) | + coûts |

### Coûts récurrents
| Champ | Fréquence | Calcul |
|-------|-----------|--------|
| Taxes municipales | Annuel | ÷ 12 × mois |
| Taxes scolaires | Annuel | ÷ 12 × mois |
| Électricité | Annuel | ÷ 12 × mois |
| Assurances | Annuel | ÷ 12 × mois |
| Déneigement | Saisonnier | Montant fixe |
| Frais de condo | Mensuel | × mois |
| Hypothèque | Mensuel | × mois |
| Loyer | Mensuel | × mois (revenu, soustrait) |

### Financement - Prêteurs

Possibilité d'ajouter plusieurs prêteurs:

| Champ | Description |
|-------|-------------|
| Nom du prêteur | Identification |
| Montant prêté | Capital emprunté |
| Taux d'intérêt (%) | Taux annuel |

**Calcul des intérêts**: Composés mensuellement
```
Intérêts = Montant × ((1 + Taux/12)^Mois - 1)
```

### Paramètres de vente
| Champ | Description |
|-------|-------------|
| Valeur potentielle | Prix de vente visé |
| Taux de commission (%) | Commission du courtier |
| Taxe de mutation | Calculée automatiquement ou manuelle |
| Quittance | Frais de radiation hypothécaire |
| Solde acheteur | Ajustements en faveur du vendeur |

### Budget de rénovation
| Champ | Description |
|-------|-------------|
| Taux contingence (%) | Marge de sécurité (défaut: 10%) |
| Budget main d'oeuvre | Coût estimé de la main d'oeuvre |

## 4.3 Page détail du projet

### Onglet: Résumé (cartes visuelles)

6 cartes d'indicateurs:

1. **Coût total**
   - Extrapolé (prévu)
   - Réel (actuel)
   - Écart en %

2. **Équité potentielle**
   - Profit prévu
   - Basé sur valeur de vente - coûts

3. **Équité réelle**
   - Profit actuel
   - Mis à jour en temps réel

4. **Cash flow**
   - Argent nécessaire pour le projet
   - Exclut certains frais (courtier, taxes)
   - Tooltip avec détail sans intérêts

5. **ROI**
   - Retour sur investissement
   - Basé sur la mise de fonds

6. **Marge**
   - Pourcentage de profit
   - Sur le prix de vente

### Onglet: Base (tableau financier complet)

Structure en 4 colonnes:
| Poste | Extrapolé | Écart | Réel |

#### Section Acquisition
- Prix d'achat
- Cession
- Notaire
- Arpenteurs
- Assurance titre
- Solde vendeur
- **Sous-total Acquisition**

#### Section Rénovation
Pour chaque catégorie de budget:
- Nom de la catégorie
- Budget prévu (extrapolé)
- Écart (+ si économie, - si dépassement)
- Dépenses réelles (factures approuvées)

Puis:
- **Sous-total Rénovation (HT)**
- Contingence (% du budget)
- TPS (5%)
- TVQ (9,975%)
- **Sous-total Rénovation (TTC)**

#### Section Main d'oeuvre
- Budget main d'oeuvre
- Heures réelles × taux
- Écart

#### Section Récurrents
Pour chaque poste récurrent:
- Montant prévu (basé sur temps assumé)
- Montant réel (basé sur temps écoulé)
- Écart

**Calcul du temps réel**:
- De la date d'acquisition jusqu'à:
  - Date de vente (si dans le passé)
  - Aujourd'hui (si date de vente future ou non définie)

#### Section Vente
- Commission courtier (% + TPS/TVQ)
- Intérêts sur prêts
  - Prévu: basé sur temps assumé
  - Réel: basé sur temps écoulé
- Quittance
- Taxe de mutation (si applicable)
- Solde acheteur (crédit)
- **Sous-total Vente**

#### Section Totaux
- **COÛT TOTAL** (acquisition + réno + MO + récurrents + vente)
- **VALEUR DE VENTE**
- **ÉQUITÉ** (valeur - coût total)

#### Section Partage des profits
- **Profit net avant partage**
- Pour chaque prêteur:
  - Capital à rembourser
  - Intérêts dus (prévu vs réel)
  - Total dû
- **Impôt à payer**
  - Taux affiché dynamiquement selon le cumulatif de l'année
  - Icône info avec tooltip si profit cumulatif > 0
  - Détail: "Profit cumulatif 2025: X$ | Seuil DPE restant: Y$"
- **PROFIT APRÈS IMPÔT**

#### Lignes de couleur
- **Vert**: Ligne profitable
- **Rouge**: Ligne déficitaire
- **Gris clair**: Sous-totaux
- **Fond coloré**: Grands totaux

#### Sections collapsibles
- Clic sur l'en-tête de section pour réduire/étendre
- Affiche le total de la section quand réduite

### Onglet: Budget Builder

(Voir section dédiée)

### Onglet: Factures

Liste des factures du projet:
- Fournisseur
- Catégorie
- Montant
- Date
- Statut
- Employé soumetteur
- Actions (voir, modifier, supprimer)

### Onglet: Photos

Galerie de photos du chantier:
- Vignettes cliquables
- Catégorie de photo
- Date de prise
- Employé qui a uploadé
- Téléchargement possible

### Onglet: Heures

Liste des entrées de temps:
- Employé
- Date
- Heures travaillées
- Taux horaire
- Coût total
- Description
- Statut

---

# PARTIE 5: BUDGET BUILDER

## 5.1 Concept

Le Budget Builder permet de construire un budget de rénovation détaillé en:
1. Sélectionnant des matériaux depuis un catalogue
2. Ajustant les quantités
3. Calculant automatiquement les totaux avec taxes

## 5.2 Interface à deux colonnes

### Colonne gauche: Catalogue
- Arborescence de catégories/sous-catégories
- Liste de matériaux avec prix unitaire
- Recherche de matériaux
- Drag & drop vers le budget

### Colonne droite: Budget du projet
- Catégories sélectionnées
- Matériaux avec quantités
- Boutons +/- pour ajuster
- Totaux par catégorie
- Grand total avec taxes

## 5.3 Fonctionnalités

### Drag & Drop
- Glisser un matériau du catalogue vers le budget
- Si le matériau existe déjà: modal pour ajouter à la quantité
- Sinon: ajout avec quantité 1

### Gestion des quantités
- **Quantité matériau**: Nombre d'unités de ce matériau
- **Quantité catégorie**: Multiplicateur (ex: 3 salles de bain)
- **Quantité groupe**: Multiplicateur global (ex: duplex = ×2)

### Calcul
```
Total item = Prix unitaire × Qté matériau × Qté catégorie × Qté groupe
```

### Taxabilité
- Case à cocher "Sans taxe" par matériau
- Items sans taxe exclus du calcul TPS/TVQ
- Utile pour: main d'oeuvre, certains services

### Contingence
- Pourcentage configurable (défaut: 10%)
- Appliquée sur le total HT avant taxes
- Non taxable

### Résumé des totaux
| Ligne | Calcul |
|-------|--------|
| Sous-total HT | Somme des items |
| Contingence | Sous-total × % |
| Sous-total avant taxes | HT + Contingence |
| TPS (5%) | Items taxables × 0.05 |
| TVQ (9,975%) | Items taxables × 0.09975 |
| **TOTAL TTC** | Tout additionné |

## 5.4 Sauvegarde

- Sauvegarde automatique à chaque modification
- Animation flash pour confirmer
- Pas de bouton "Sauvegarder" nécessaire

## 5.5 Barre de totaux sticky

- Reste visible en bas de l'écran
- Affiche: Sous-total | Taxes | Total
- Toujours accessible même en scrollant

---

# PARTIE 6: FACTURES ET DÉPENSES

## 6.1 Soumission de facture

### Champs du formulaire
| Champ | Description | Obligatoire |
|-------|-------------|:-----------:|
| Projet | Sélection du projet | Oui |
| Catégorie | Catégorie de dépense | Oui |
| Fournisseur | Nom du commerce/fournisseur | Oui |
| Montant total | Montant TTC | Oui |
| Date de facture | Date sur la facture | Oui |
| Description | Notes additionnelles | Non |
| Photo/PDF | Image ou PDF de la facture | Recommandé |

### Types de fichiers acceptés
- Images: JPG, JPEG, PNG, GIF
- Documents: PDF
- Taille max: 10 MB

## 6.2 Workflow d'approbation

```
┌─────────────────┐
│ Employé soumet  │
│    facture      │
└────────┬────────┘
         ▼
┌─────────────────┐
│   En attente    │ ← Statut initial
└────────┬────────┘
         ▼
    ┌────┴────┐
    ▼         ▼
┌───────┐ ┌────────┐
│Approuv│ │Rejetée │
└───┬───┘ └────────┘
    ▼
┌───────────────────┐
│ Comptabilisée     │
│ dans le projet    │
└───────────────────┘
```

## 6.3 États des factures

| Statut | Description | Couleur |
|--------|-------------|---------|
| En attente | Soumise, pas encore traitée | Jaune |
| Approuvée | Validée par admin | Vert |
| Rejetée | Refusée par admin | Rouge |

## 6.4 Impact sur le projet

- Facture approuvée → Montant ajouté au "Réel" de la catégorie
- Calcul automatique de l'écart (Budget - Réel)
- Mise à jour des totaux du projet

## 6.5 Gestion admin des factures

### Liste des factures
- Filtres: par projet, catégorie, statut, période
- Recherche par fournisseur
- Tri par date, montant

### Modification
- Changer le montant
- Changer la catégorie
- Modifier la description
- Remplacer la photo

### Approbation en lot
- Sélection multiple
- Approbation groupée

---

# PARTIE 7: MAIN D'OEUVRE

## 7.1 Feuille de temps

### Formulaire de saisie
| Champ | Description |
|-------|-------------|
| Projet | Sélection du projet |
| Date | Date du travail |
| Heures | Nombre d'heures (décimales acceptées) |
| Description | Nature du travail effectué |

### Mémorisation du projet
- Le dernier projet utilisé est sauvegardé
- Restauré automatiquement à la prochaine visite
- Stockage local (localStorage)

## 7.2 Entrée multi-employés (contremaître)

### Fonctionnalité
- Bouton "Plusieurs" pour les contremaîtres
- Modal de sélection d'employés
- Cocher les employés concernés
- Soumettre les mêmes heures pour tous

### Accès
- Employés avec flag "contremaître"
- Administrateurs

### Formulaire mobile adapté
- Bouton placé sous le sélecteur d'employé
- Interface adaptée aux petits écrans

## 7.3 Taux horaire

- Défini par employé dans son profil
- Utilisé pour calculer le coût
- Modifiable par admin uniquement

## 7.4 Calcul du coût

```
Coût = Heures × Taux horaire
Coût total projet = Somme des heures approuvées
```

## 7.5 Approbation des heures

- Même workflow que les factures
- Admin peut approuver/rejeter
- Impact sur le coût main d'oeuvre du projet

---

# PARTIE 8: SUIVI FISCAL

## 8.1 Contexte fiscal québécois

### Taux d'imposition des sociétés (2025)

| Type | Revenu | Taux |
|------|--------|------|
| DPE (Déduction Petites Entreprises) | 0$ - 500 000$ | 12,2% |
| Taux général | Au-delà de 500 000$ | 26,5% |

### Conditions DPE
- Société privée sous contrôle canadien (SPCC)
- Revenu d'entreprise exploitée activement
- Capital imposable < 10 M$

## 8.2 Fonctionnement dans Flip Manager

### Année fiscale
- Basée sur l'année civile (1er janv - 31 déc)
- Déterminée par la **date de vente** du projet
- Sélectionnable dans le tableau de bord

### Calcul progressif

Le système calcule l'impôt en tenant compte du **cumulatif de l'année**:

**Exemple avec 5 flips à 150 000$ chacun:**

| Flip | Date vente | Profit | Cumulatif | Dans 12,2% | Dans 26,5% | Impôt | Taux effectif |
|------|------------|--------|-----------|------------|------------|-------|---------------|
| #1 | 15 mars | 150k$ | 150k$ | 150k$ | 0$ | 18 300$ | 12,2% |
| #2 | 1er juin | 150k$ | 300k$ | 150k$ | 0$ | 18 300$ | 12,2% |
| #3 | 15 août | 150k$ | 450k$ | 150k$ | 0$ | 18 300$ | 12,2% |
| #4 | 1er oct | 150k$ | 600k$ | 50k$ | 100k$ | 32 600$ | 21,7% |
| #5 | 15 déc | 150k$ | 750k$ | 0$ | 150k$ | 39 750$ | 26,5% |
| **Total** | | **750k$** | | 500k$ | 250k$ | **127 250$** | **17,0%** |

### Affichage par projet

Dans la page détail de chaque projet:
- Taux affiché dynamiquement (12,2% ou 26,5% ou mixte)
- Icône info si profit cumulatif > 0
- Tooltip: "Profit cumulatif 2025: X$ | Seuil DPE restant: Y$"

### Projections

Pour les projets non vendus:
- Estimation du taux qui s'appliquerait
- Basé sur le profit estimé + cumulatif actuel
- Aide à planifier le timing des ventes

## 8.3 Stratégie d'optimisation

### Reporter une vente
Si proche du 31 décembre et proche du seuil 500k$:
- Vendre en janvier = nouvelle année fiscale
- Profiter à nouveau du taux 12,2% sur 500k$

### Exemple
- Profit cumulé en novembre: 480k$
- Flip prêt à vendre: profit estimé 100k$

**Option A - Vendre en décembre:**
- 20k$ à 12,2% = 2 440$
- 80k$ à 26,5% = 21 200$
- Total: 23 640$

**Option B - Attendre janvier:**
- 100k$ à 12,2% = 12 200$
- Économie: 11 440$

---

# PARTIE 9: CALCULS FINANCIERS DÉTAILLÉS

## 9.1 Intérêts composés

### Formule
```
Intérêts = Principal × ((1 + Taux_mensuel)^Mois - 1)

Où:
- Taux_mensuel = Taux_annuel ÷ 12
- Mois = Nombre de mois
```

### Exemple
- Prêt: 100 000$
- Taux: 15% annuel
- Durée: 6 mois

```
Taux mensuel = 0.15 ÷ 12 = 0.0125
Intérêts = 100 000 × ((1 + 0.0125)^6 - 1)
Intérêts = 100 000 × (1.0773 - 1)
Intérêts = 7 738$
```

### Temps réel vs prévu
- **Extrapolé**: Basé sur `temps_assume_mois`
- **Réel**: Basé sur le temps écoulé depuis `date_acquisition`
  - Si `date_vente` dans le passé → jusqu'à date_vente
  - Si `date_vente` dans le futur ou vide → jusqu'à aujourd'hui

## 9.2 Taxe de mutation (droits de mutation)

### Barème 2025
| Tranche | Taux |
|---------|------|
| 0$ - 58 900$ | 0,5% |
| 58 900$ - 294 600$ | 1,0% |
| 294 600$ - 500 000$ | 1,5% |
| 500 000$ et plus | 3,0% |

### Calcul automatique
Entrer le prix d'achat → taxe calculée automatiquement

### Exemple pour 350 000$
```
0 - 58 900 × 0.5% = 294.50$
58 900 - 294 600 × 1.0% = 2 357.00$
294 600 - 350 000 × 1.5% = 831.00$
Total = 3 482.50$
```

## 9.3 Commission courtier

### Formule
```
Commission HT = Prix de vente × Taux de commission
TPS = Commission HT × 5%
TVQ = Commission HT × 9.975%
Commission TTC = Commission HT × 1.14975
```

### Exemple
- Prix de vente: 400 000$
- Taux: 5%

```
Commission HT = 400 000 × 5% = 20 000$
TPS = 20 000 × 5% = 1 000$
TVQ = 20 000 × 9.975% = 1 995$
Commission TTC = 22 995$
```

## 9.4 Coûts récurrents

### Calcul extrapolé (prévu)
```
Coût = Montant_annuel × (Mois_assumés ÷ 12)
```

### Calcul réel
```
Coût = Montant_annuel × (Mois_écoulés ÷ 12)
```

### Mois écoulé entamé
- Un mois entamé compte comme un mois complet
- Cohérent avec la facturation réelle

## 9.5 Contingence

### Définition
Réserve budgétaire pour imprévus (généralement 10%)

### Calcul de la contingence "utilisée"
```
Écarts négatifs = Somme des dépassements par catégorie
Écarts positifs = Somme des économies par catégorie
Écart net = Écarts positifs + Écarts négatifs

Si Écart net < 0:
  Contingence utilisée = |Écart net|
Sinon:
  Contingence utilisée = 0
```

### Logique
- Les économies compensent les dépassements
- La contingence n'est "consommée" que si le net est négatif
- Maximum = contingence budgétée

## 9.6 Équité et profit

### Équité potentielle (extrapolée)
```
Équité = Valeur de vente - Coût total extrapolé
```

### Équité réelle
```
Équité = Valeur de vente - Coût total réel
```

### Profit après impôt
```
Profit = Équité - Remboursement prêteurs - Impôts
```

## 9.7 ROI (Retour sur investissement)

### Formule
```
ROI = (Profit ÷ Mise de fonds) × 100
```

### Mise de fonds
```
Mise de fonds = Coût total - Montants empruntés
```

---

# PARTIE 10: PORTAIL EMPLOYÉ

## 10.1 Accueil employé

Interface simplifiée avec:
- Projets assignés (nom et adresse seulement)
- Actions rapides: Facture, Heures, Photos
- Historique récent de ses soumissions

## 10.2 Soumission de facture

Formulaire identique à l'admin mais:
- Pas de modification après soumission
- Pas de visibilité sur le budget
- Notification à l'admin

## 10.3 Feuille de temps

- Sélection du projet
- Saisie des heures
- Description du travail
- Mémorisation du dernier projet

## 10.4 Upload de photos

- Sélection du projet
- Catégorie de photo (Avant, Pendant, Après, Problème)
- Prise de photo ou upload
- Description optionnelle

## 10.5 Historique

- Ses factures soumises avec statuts
- Ses heures saisies
- Ses photos uploadées

---

# PARTIE 11: ADMINISTRATION

## 11.1 Gestion des utilisateurs

### Création d'utilisateur
| Champ | Description |
|-------|-------------|
| Prénom | Prénom de l'employé |
| Nom | Nom de famille |
| Email | Identifiant de connexion |
| Mot de passe | Minimum 6 caractères |
| Rôle | Admin ou Employé |
| Taux horaire | Pour calcul des coûts |
| Est contremaître | Accès multi-employés |
| Actif | Peut se connecter |

### Modification
- Changer le rôle
- Réinitialiser le mot de passe
- Désactiver le compte

## 11.2 Gestion des catégories

### Catégories de dépenses
- Créer des catégories personnalisées
- Associer à des groupes (Salle de bain, Cuisine, etc.)
- Définir l'ordre d'affichage

### Sous-catégories
- Hiérarchie à deux niveaux
- Organisation du Budget Builder

## 11.3 Catalogue de matériaux

- Ajouter des matériaux au catalogue
- Définir les prix unitaires
- Associer aux catégories
- Marquer comme taxable ou non

## 11.4 Configuration

- Taux de contingence par défaut
- Taux de commission par défaut
- Paramètres d'affichage

---

# PARTIE 12: RAPPORTS

## 12.1 Paie hebdomadaire

- Sélection de la période
- Liste des employés avec heures
- Calcul automatique des montants
- Export possible

## 12.2 Rapport par projet

- Synthèse financière complète
- Comparatif budget vs réel
- Liste des factures
- Liste des heures

## 12.3 Rapport fiscal annuel

- Projets vendus dans l'année
- Profit par projet
- Calcul d'impôt détaillé
- Stratégies de report suggérées

---

# PARTIE 13: ASPECTS TECHNIQUES

## 13.1 Prérequis serveur

- PHP 8.0 ou supérieur
- MySQL 5.7+ ou MariaDB 10.3+
- Serveur web Apache ou Nginx
- Module PHP: PDO, GD (images), mbstring

## 13.2 Installation

1. Créer la base de données
2. Importer `sql/database.sql`
3. Configurer `config.php`
4. Définir les permissions sur `uploads/`

## 13.3 Structure des fichiers

```
flip-manager/
├── flip/
│   ├── admin/                 # Interface admin
│   │   ├── index.php          # Tableau de bord
│   │   ├── projets/           # Gestion projets
│   │   │   ├── liste.php
│   │   │   ├── nouveau.php
│   │   │   ├── modifier.php
│   │   │   ├── detail.php
│   │   │   └── budget-builder-content.php
│   │   ├── factures/          # Gestion factures
│   │   ├── temps/             # Feuilles de temps
│   │   ├── photos/            # Galerie photos
│   │   ├── paye/              # Rapports paie
│   │   ├── configuration/     # Paramètres
│   │   └── rapports/          # Rapports
│   │
│   ├── employe/               # Interface employé
│   │   ├── index.php
│   │   ├── feuille-temps.php
│   │   ├── nouvelle-facture.php
│   │   ├── mes-factures.php
│   │   ├── mes-heures.php
│   │   └── photos.php
│   │
│   ├── includes/              # Code partagé
│   │   ├── config.php         # Configuration DB
│   │   ├── auth.php           # Authentification
│   │   ├── functions.php      # Fonctions utilitaires
│   │   ├── calculs.php        # Calculs financiers
│   │   ├── header.php         # En-tête HTML
│   │   └── footer.php         # Pied de page
│   │
│   ├── assets/                # Ressources statiques
│   │   ├── css/
│   │   ├── js/
│   │   └── images/
│   │
│   └── uploads/               # Fichiers uploadés
│       ├── factures/
│       └── photos/
│
├── sql/                       # Scripts SQL
│   └── database.sql
│
├── README.md
├── DOCUMENTATION.md
└── MILESTONES.md
```

## 13.4 Base de données - Tables principales

| Table | Description |
|-------|-------------|
| users | Utilisateurs (admin et employés) |
| projets | Projets de flip |
| categories | Catégories de dépenses |
| sous_categories | Sous-catégories |
| materiaux | Catalogue de matériaux |
| budgets | Budgets par projet/catégorie |
| projet_items | Items du Budget Builder |
| projet_postes | Postes de budget |
| projet_groupes | Multiplicateurs de groupes |
| projet_recurrents | Coûts récurrents |
| preteurs | Prêteurs par projet |
| factures | Factures soumises |
| heures_travaillees | Entrées de temps |
| photos_projet | Photos uploadées |

## 13.5 Sécurité implémentée

| Mesure | Description |
|--------|-------------|
| Bcrypt | Hash des mots de passe irréversible |
| PDO Prepared | Protection injection SQL |
| htmlspecialchars | Protection XSS |
| CSRF Token | Protection contre requêtes forgées |
| Session secure | Cookies sécurisés |
| File validation | Vérification type MIME |

---

# PARTIE 14: FAQ

## Q: Puis-je gérer plusieurs entreprises?
R: Non, l'application est conçue pour une seule entreprise. Pour plusieurs entreprises, installer des instances séparées.

## Q: Les données sont-elles sauvegardées automatiquement?
R: Le Budget Builder sauvegarde automatiquement. Pour les autres sections, cliquer sur "Enregistrer". Prévoir des backups réguliers de la base de données.

## Q: Peut-on changer l'année fiscale?
R: L'application assume une fin d'exercice au 31 décembre. Pour une date différente, modification du code requise.

## Q: Comment gérer un projet avec perte?
R: Les pertes sont affichées en rouge. Elles ne génèrent pas d'impôt à payer. Les pertes peuvent être reportées fiscalement (consulter un comptable).

## Q: Puis-je exporter les données?
R: Actuellement pas d'export intégré. Accès direct à la base de données MySQL pour exports personnalisés.

## Q: L'application fonctionne-t-elle sur mobile?
R: Oui, interface responsive adaptée aux tablettes et téléphones.

---

# PARTIE 15: GLOSSAIRE

| Terme | Définition |
|-------|------------|
| **DPE** | Déduction pour Petites Entreprises - taux d'imposition réduit |
| **Flip** | Achat-rénovation-revente rapide d'une propriété |
| **Équité** | Valeur de vente moins tous les coûts |
| **Cash flow** | Argent liquide nécessaire pour le projet |
| **ROI** | Return On Investment - retour sur mise de fonds |
| **TPS** | Taxe sur les Produits et Services (5%) |
| **TVQ** | Taxe de Vente du Québec (9.975%) |
| **Contingence** | Réserve budgétaire pour imprévus |
| **Extrapolé** | Valeur prévue/estimée |
| **Réel** | Valeur actuelle/constatée |
| **Écart** | Différence entre prévu et réel |
| **Prêteur privé** | Investisseur prêtant des fonds avec intérêts |
| **Taxe de mutation** | "Taxe de bienvenue" payée à l'achat |
| **Quittance** | Radiation d'hypothèque |
| **Cession** | Transfert de droits (ex: promesse d'achat) |

---

*Documentation Flip Manager v1.0 - Décembre 2025*
*Tous droits réservés*
