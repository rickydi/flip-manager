-- ============================================
-- Flip Manager - Script de création de la base de données
-- ============================================

-- Création de la base de données
CREATE DATABASE IF NOT EXISTS flip_manager CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE flip_manager;

-- ============================================
-- Table: users (Utilisateurs)
-- ============================================
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nom VARCHAR(100) NOT NULL,
    prenom VARCHAR(100) NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('employe', 'admin') DEFAULT 'employe',
    actif TINYINT(1) DEFAULT 1,
    date_creation DATETIME DEFAULT CURRENT_TIMESTAMP,
    derniere_connexion DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Table: projets (Projets de flip)
-- ============================================
CREATE TABLE projets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nom VARCHAR(255) NOT NULL,
    adresse VARCHAR(255) NOT NULL,
    ville VARCHAR(100) NOT NULL,
    code_postal VARCHAR(10),
    
    -- Dates
    date_acquisition DATE,
    date_debut_travaux DATE,
    date_fin_prevue DATE,
    date_vente DATE NULL,
    
    -- Statut
    statut ENUM('acquisition', 'renovation', 'vente', 'vendu', 'archive') DEFAULT 'acquisition',
    
    -- Financier - Acquisition
    prix_achat DECIMAL(12,2) DEFAULT 0,
    notaire DECIMAL(10,2) DEFAULT 0,
    taxe_mutation DECIMAL(10,2) DEFAULT 0,
    arpenteurs DECIMAL(10,2) DEFAULT 0,
    assurance_titre DECIMAL(10,2) DEFAULT 0,
    
    -- Financier - Coûts récurrents (montants annuels)
    taxes_municipales_annuel DECIMAL(10,2) DEFAULT 0,
    taxes_scolaires_annuel DECIMAL(10,2) DEFAULT 0,
    electricite_annuel DECIMAL(10,2) DEFAULT 0,
    assurances_annuel DECIMAL(10,2) DEFAULT 0,
    deneigement_annuel DECIMAL(10,2) DEFAULT 0,
    frais_condo_annuel DECIMAL(10,2) DEFAULT 0,
    hypotheque_mensuel DECIMAL(10,2) DEFAULT 0,
    loyer_mensuel DECIMAL(10,2) DEFAULT 0,
    
    -- Temps et vente
    temps_assume_mois INT DEFAULT 6,
    valeur_potentielle DECIMAL(12,2) DEFAULT 0,
    prix_vente_reel DECIMAL(12,2) NULL,
    
    -- Commission et contingence
    taux_commission DECIMAL(4,2) DEFAULT 4.00,
    taux_contingence DECIMAL(4,2) DEFAULT 15.00,
    
    -- Financement
    taux_interet DECIMAL(5,2) DEFAULT 10.00,
    montant_pret DECIMAL(12,2) DEFAULT 0,
    
    -- Métadonnées
    photo_principale VARCHAR(255) NULL,
    notes TEXT NULL,
    date_creation DATETIME DEFAULT CURRENT_TIMESTAMP,
    date_modification DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Table: categories (Catégories de dépenses)
-- ============================================
CREATE TABLE categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nom VARCHAR(100) NOT NULL,
    groupe ENUM('exterieur', 'finition', 'ebenisterie', 'electricite', 'plomberie', 'autre') NOT NULL,
    ordre INT DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Table: budgets (Budget par catégorie par projet)
-- ============================================
CREATE TABLE budgets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    projet_id INT NOT NULL,
    categorie_id INT NOT NULL,
    montant_extrapole DECIMAL(10,2) DEFAULT 0,
    FOREIGN KEY (projet_id) REFERENCES projets(id) ON DELETE CASCADE,
    FOREIGN KEY (categorie_id) REFERENCES categories(id) ON DELETE CASCADE,
    UNIQUE KEY unique_projet_categorie (projet_id, categorie_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Table: factures (Factures entrées par employés)
-- ============================================
CREATE TABLE factures (
    id INT AUTO_INCREMENT PRIMARY KEY,
    projet_id INT NOT NULL,
    categorie_id INT NOT NULL,
    user_id INT NOT NULL,
    
    -- Informations facture
    fournisseur VARCHAR(255) NOT NULL,
    description TEXT,
    date_facture DATE NOT NULL,
    
    -- Montants
    montant_avant_taxes DECIMAL(10,2) NOT NULL,
    tps DECIMAL(10,2) DEFAULT 0,
    tvq DECIMAL(10,2) DEFAULT 0,
    montant_total DECIMAL(10,2) NOT NULL,
    
    -- Fichier
    fichier VARCHAR(255) NULL,
    
    -- Statut
    statut ENUM('en_attente', 'approuvee', 'rejetee') DEFAULT 'en_attente',
    commentaire_admin TEXT NULL,
    approuve_par INT NULL,
    date_approbation DATETIME NULL,
    
    -- Métadonnées
    notes TEXT NULL,
    date_creation DATETIME DEFAULT CURRENT_TIMESTAMP,
    date_modification DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (projet_id) REFERENCES projets(id) ON DELETE CASCADE,
    FOREIGN KEY (categorie_id) REFERENCES categories(id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (approuve_par) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Table: investisseurs (Investisseurs)
-- ============================================
CREATE TABLE investisseurs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nom VARCHAR(255) NOT NULL,
    email VARCHAR(255),
    telephone VARCHAR(20),
    notes TEXT NULL,
    actif TINYINT(1) DEFAULT 1,
    date_creation DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Table: projet_investisseurs (Lien projet-investisseur)
-- ============================================
CREATE TABLE projet_investisseurs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    projet_id INT NOT NULL,
    investisseur_id INT NOT NULL,
    mise_de_fonds DECIMAL(12,2) DEFAULT 0,
    pourcentage_profit DECIMAL(5,2) DEFAULT 0,
    type_investissement ENUM('comptant', 'prive', 'banque', 'materiel') DEFAULT 'comptant',
    notes TEXT NULL,
    
    FOREIGN KEY (projet_id) REFERENCES projets(id) ON DELETE CASCADE,
    FOREIGN KEY (investisseur_id) REFERENCES investisseurs(id),
    UNIQUE KEY unique_projet_investisseur (projet_id, investisseur_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Insertion des catégories par défaut
-- ============================================

-- Extérieur
INSERT INTO categories (nom, groupe, ordre) VALUES
('Permis', 'exterieur', 1),
('Achats générales', 'exterieur', 2),
('Conteneurs', 'exterieur', 3),
('Excavation', 'exterieur', 4),
('Béton', 'exterieur', 5),
('Béton finition et crépi', 'exterieur', 6),
('Maçonnerie', 'exterieur', 7),
('Revêtement extérieur', 'exterieur', 8),
('Toiture', 'exterieur', 9),
('Portes et fenêtres', 'exterieur', 10),
('Aluminium, gouttières', 'exterieur', 11),
('Patio, deck, balcon', 'exterieur', 12),
('Paysagement', 'exterieur', 13),
('Bois structure', 'exterieur', 14),
('Isolation', 'exterieur', 15),
('Porte de garage', 'exterieur', 16),
('Gaz', 'exterieur', 17),
('Démolition', 'exterieur', 18),
('A/C', 'exterieur', 19),
('Ménage', 'exterieur', 20),
('Autres extérieur', 'exterieur', 21);

-- Finition intérieure
INSERT INTO categories (nom, groupe, ordre) VALUES
('Portes intérieures', 'finition', 1),
('Gypse', 'finition', 2),
('Peinture', 'finition', 3),
('Plâtre', 'finition', 4),
('Moulures', 'finition', 5),
('Plancher flottant', 'finition', 6),
('Plancher', 'finition', 7),
('Céramique', 'finition', 8);

-- Ébénisterie
INSERT INTO categories (nom, groupe, ordre) VALUES
('Comptoir', 'ebenisterie', 1),
('Escalier bois', 'ebenisterie', 2),
('Main courante', 'ebenisterie', 3),
('Armoires', 'ebenisterie', 4);

-- Électricité
INSERT INTO categories (nom, groupe, ordre) VALUES
('Électricité général', 'electricite', 1),
('Luminaire', 'electricite', 2),
('Chauffage', 'electricite', 3);

-- Plomberie
INSERT INTO categories (nom, groupe, ordre) VALUES
('Plomberie général', 'plomberie', 1),
('Eau chaude', 'plomberie', 2),
('Robinetterie', 'plomberie', 3);

-- Autre
INSERT INTO categories (nom, groupe, ordre) VALUES
('Ingénieur', 'autre', 1),
('Autres', 'autre', 2);

-- ============================================
-- Création d'un utilisateur admin par défaut
-- Mot de passe: admin123 (à changer en production!)
-- ============================================
INSERT INTO users (nom, prenom, email, password, role, actif) VALUES
('Admin', 'System', 'admin@flipmanager.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 1);

-- Mot de passe: employe123
INSERT INTO users (nom, prenom, email, password, role, actif) VALUES
('Employé', 'Test', 'employe@flipmanager.com', '$2y$10$HfzIhGCCaxqyaIdGgjARSuOKAcm1Uy82YfLuNaajn6JrjLWy9Sj/W', 'employe', 1);
