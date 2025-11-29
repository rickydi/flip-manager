# Flip Manager 🏠

Application de gestion de projets de flip immobilier (achat-rénovation-revente).

## Fonctionnalités

- **Gestion de projets** : Créer et suivre plusieurs flips simultanément
- **Deux niveaux d'accès** :
  - Employés : Entrée de factures uniquement
  - Administrateurs : Vue financière complète et gestion
- **Suivi financier** : Budgets, dépenses réelles, ROI, répartition investisseurs
- **Approbation des factures** : Workflow de validation

## Prérequis

- PHP 7.4+
- MySQL 5.7+
- Hébergement mutualisé (WHC.ca, Name.com, etc.)

## Installation

### 1. Base de données

1. Créer une base de données MySQL via phpMyAdmin
2. Importer le fichier `sql/database.sql`

### 2. Configuration

1. Copier `config.php` et modifier les paramètres :
```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'votre_base');
define('DB_USER', 'votre_user');
define('DB_PASS', 'votre_mot_de_passe');
```

### 3. Déploiement

Méthode FTP ou Git (cPanel Git Version Control).

### 4. Premier accès

Compte admin par défaut :
- Email : `admin@flipmanager.com`
- Mot de passe : `admin123`

**⚠️ Changer le mot de passe après la première connexion !**

## Structure

```
flip-manager/
├── admin/           # Interface administrateur
├── employe/         # Interface employé
├── includes/        # Fichiers PHP communs
├── assets/          # CSS, JS
├── uploads/         # Fichiers uploadés
└── sql/             # Scripts SQL
```

## Rôles

| Employé | Administrateur |
|---------|----------------|
| Voir projets (nom/adresse) | Accès complet |
| Soumettre factures | Gérer projets et budgets |
| Voir ses factures | Approuver factures |
| | Voir indicateurs financiers |
| | Gérer utilisateurs |

## Technologies

- PHP 7.4+ (vanilla)
- MySQL
- Bootstrap 5
- JavaScript vanilla

## Licence

Propriétaire - Usage interne.
