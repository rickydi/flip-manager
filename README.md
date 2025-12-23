# Flip Manager

**Logiciel de gestion de projets immobiliers (flips)**

Application web complète pour gérer tous les aspects d'une entreprise de flip immobilier: budgets, factures, main d'oeuvre, suivi fiscal et analyse de rentabilité.

---

## Table des matières

1. [Vue d'ensemble](#vue-densemble)
2. [Tableau de bord](#tableau-de-bord)
3. [Gestion des projets](#gestion-des-projets)
4. [Budget Builder](#budget-builder)
5. [Factures et dépenses](#factures-et-dépenses)
6. [Main d'oeuvre](#main-doeuvre)
7. [Suivi fiscal](#suivi-fiscal)
8. [Analyse de rentabilité](#analyse-de-rentabilité)
9. [Portail employé](#portail-employé)
10. [Installation](#installation)

---

## Vue d'ensemble

Flip Manager est un outil complet pour:

- **Planifier** les rénovations avec un budget détaillé
- **Suivre** les dépenses en temps réel (factures, main d'oeuvre)
- **Analyser** la rentabilité de chaque projet
- **Optimiser** la fiscalité avec le suivi du seuil DPE
- **Collaborer** avec les employés via un portail dédié

---

## Tableau de bord

### Statistiques globales
- Nombre de projets actifs
- Factures en attente d'approbation
- Factures approuvées
- Total des dépenses

### Section Fiscalité
- **Sélecteur d'année fiscale** - Basé sur les dates de vente réelles
- **Jauge du seuil DPE** - Visualisation du 500 000$ à 12,2%
- **Chiffres clés:**
  - Flips vendus dans l'année
  - Profit brut cumulatif
  - Impôts à payer
  - Profit net après impôt
- **Liste des projets vendus** avec profit et taux appliqué
- **Projections** pour les projets en cours

### Activités récentes
- Dernières factures soumises
- Heures travaillées
- Photos uploadées
- Nouveaux projets

### Approbations rapides
- Liste des factures en attente
- Bouton d'approbation rapide

---

## Gestion des projets

### Informations de base
- Nom et adresse du projet
- Statut (En cours, Terminé, Archivé)
- Dates importantes:
  - Date d'acquisition
  - Date de vente (réelle ou prévue)

### Coûts d'acquisition
| Poste | Description |
|-------|-------------|
| Prix d'achat | Prix payé pour la propriété |
| Cession | Frais de cession |
| Notaire | Frais notariaux |
| Arpenteurs | Certificat de localisation |
| Assurance titre | Protection juridique |
| Solde vendeur | Ajustements |

### Coûts récurrents (mensuels/annuels)
- Taxes municipales
- Taxes scolaires
- Électricité
- Assurances
- Déneigement
- Frais de condo
- Hypothèque
- Loyer (revenu si locataire)

### Financement
- **Prêteurs privés** avec taux d'intérêt
- Calcul automatique des intérêts composés
- Support multi-prêteurs

### Coûts de vente
- Commission courtier (% + taxes)
- Taxe de mutation (calculée automatiquement)
- Quittance
- Solde acheteur

---

## Budget Builder

### Catalogue de matériaux
- Base de données de matériaux avec prix
- Organisé par catégories et sous-catégories
- Drag & drop vers le budget du projet

### Fonctionnalités
- **Quantités ajustables** par catégorie et matériau
- **Groupes multipliables** (ex: 3 salles de bain identiques)
- **Items taxables/non-taxables** (distinction TPS/TVQ)
- **Contingence** configurable (% du budget)

### Calculs automatiques
- Sous-total par catégorie
- TPS (5%) et TVQ (9,975%)
- Total avec taxes
- Contingence

---

## Factures et dépenses

### Soumission de factures
- Upload de photo/PDF de la facture
- Association à un projet et une catégorie
- Montant et description

### Workflow d'approbation
1. Employé soumet la facture
2. Admin reçoit notification
3. Admin approuve ou rejette
4. Facture comptabilisée dans le projet

### Suivi des écarts
- Budget prévu vs réel par catégorie
- Visualisation des dépassements
- Impact sur la contingence

---

## Main d'oeuvre

### Feuilles de temps
- Saisie des heures par employé
- Association à un projet
- Taux horaire configurable
- Description du travail

### Fonctionnalités avancées
- **Entrée multi-employés** (pour contremaîtres)
- **Mémorisation du dernier projet** utilisé
- Historique des heures par employé

### Calculs
- Coût main d'oeuvre par projet
- Comparaison budget vs réel
- Impact sur la rentabilité

---

## Suivi fiscal

### Calcul d'impôt progressif (Québec)

| Tranche de profit | Taux |
|-------------------|------|
| 0$ - 500 000$ | 12,2% (DPE) |
| Au-delà de 500 000$ | 26,5% |

### Fonctionnement
- Calcul basé sur le **profit cumulatif de l'année**
- Chaque projet "consomme" une partie du seuil DPE
- Taux dynamique affiché par projet

### Exemple
Si vous avez déjà fait 400 000$ de profit et vendez un flip à 200 000$:
- 100 000$ taxés à 12,2% = 12 200$
- 100 000$ taxés à 26,5% = 26 500$
- **Total: 38 700$** (taux effectif: 19,35%)

### Projections
- Estimation si tous les projets en cours sont vendus
- Aide à planifier le timing des ventes
- Optimisation fiscale entre deux années

---

## Analyse de rentabilité

### Tableau Base (par projet)

Structure en 4 colonnes:
| Poste | Extrapolé | Écart | Réel |

### Sections analysées
1. **Acquisition** - Prix d'achat + frais
2. **Rénovation** - Budget vs factures réelles
3. **Récurrents** - Basé sur temps écoulé réel
4. **Vente** - Commission, intérêts, taxes
5. **Partage des profits** - Prêteurs + impôts

### Indicateurs clés
- **Coût total** (prévu vs réel)
- **Équité potentielle** (profit prévu)
- **Équité réelle** (profit actuel)
- **Cash flow** requis
- **ROI** sur mise de fonds

### Calculs en temps réel
- Intérêts basés sur le **temps réel écoulé**
- Coûts récurrents proratisés
- Contingence consommée par les dépassements

---

## Portail employé

### Fonctionnalités disponibles
- Soumettre des factures
- Saisir ses heures
- Uploader des photos du chantier
- Voir l'historique de ses soumissions

### Restrictions
- Pas d'accès aux informations financières
- Pas de visibilité sur les profits
- Limité à ses propres données

---

## Installation

### Prérequis
- PHP 8.0+
- MySQL 5.7+ / MariaDB
- Serveur web (Apache/Nginx)

### Étapes

1. **Base de données**
   ```sql
   -- Créer une base de données MySQL
   -- Importer le fichier sql/database.sql
   ```

2. **Configuration**
   ```php
   // Modifier config.php
   define('DB_HOST', 'localhost');
   define('DB_NAME', 'votre_base');
   define('DB_USER', 'votre_user');
   define('DB_PASS', 'votre_mot_de_passe');
   ```

3. **Premier accès**
   - Email: `admin@flipmanager.com`
   - Mot de passe: `admin123`
   - **Changer le mot de passe immédiatement!**

---

## Architecture technique

### Structure des fichiers
```
flip/
├── admin/           # Pages administration
│   ├── projets/     # Gestion des projets
│   ├── factures/    # Gestion des factures
│   ├── temps/       # Feuilles de temps
│   └── index.php    # Tableau de bord
├── employe/         # Portail employé
├── includes/        # Fonctions partagées
│   ├── calculs.php  # Calculs financiers
│   ├── auth.php     # Authentification
│   └── functions.php
└── assets/          # CSS, JS, images
```

### Technologies
- **Backend:** PHP 8+ avec PDO
- **Base de données:** MySQL/MariaDB
- **Frontend:** Bootstrap 5, JavaScript vanilla
- **Authentification:** Sessions PHP sécurisées

---

## Formules de calcul

### Intérêts composés (prêteurs)
```
intérêts = montant × ((1 + taux_mensuel)^mois - 1)
```

### Taxe de mutation (droits de mutation)
| Tranche | Taux |
|---------|------|
| 0$ - 58 900$ | 0,5% |
| 58 900$ - 294 600$ | 1,0% |
| 294 600$ - 500 000$ | 1,5% |
| 500 000$+ | 3,0% |

### Commission courtier
```
commission_ttc = valeur_vente × taux% × 1,14975 (TPS+TVQ)
```

### Coûts récurrents réels
```
coût_réel = coût_annuel × (mois_écoulés / 12)
```

---

## Rôles et permissions

| Fonctionnalité | Employé | Admin |
|----------------|---------|-------|
| Voir projets (nom/adresse) | Oui | Oui |
| Soumettre factures | Oui | Oui |
| Voir ses propres factures | Oui | Oui |
| Saisir ses heures | Oui | Oui |
| Uploader des photos | Oui | Oui |
| Approuver factures | Non | Oui |
| Voir budgets/profits | Non | Oui |
| Gérer projets | Non | Oui |
| Voir tableau de bord fiscal | Non | Oui |
| Gérer utilisateurs | Non | Oui |

---

## Sécurité

- Mots de passe hashés (bcrypt)
- Protection CSRF sur les formulaires
- Validation des entrées utilisateur
- Séparation des rôles (Admin/Employé)
- Sessions sécurisées

---

## Licence

Propriétaire - Usage interne.

---

*Flip Manager - Gérez vos flips comme un pro*
