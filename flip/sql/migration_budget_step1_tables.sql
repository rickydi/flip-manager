-- ============================================
-- Migration: Système de templates budgets détaillés
-- Flip Manager - VERSION SIMPLIFIÉE
-- ============================================
-- ÉTAPE 1: Créer les tables
-- ============================================

CREATE TABLE IF NOT EXISTS sous_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    categorie_id INT NOT NULL,
    nom VARCHAR(100) NOT NULL,
    ordre INT DEFAULT 0,
    actif TINYINT(1) DEFAULT 1,
    date_creation DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_categorie (categorie_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS materiaux (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sous_categorie_id INT NOT NULL,
    nom VARCHAR(150) NOT NULL,
    prix_defaut DECIMAL(10,2) DEFAULT 0,
    ordre INT DEFAULT 0,
    actif TINYINT(1) DEFAULT 1,
    date_creation DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_sous_categorie (sous_categorie_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS projet_postes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    projet_id INT NOT NULL,
    categorie_id INT NOT NULL,
    quantite INT DEFAULT 1,
    budget_extrapole DECIMAL(10,2) DEFAULT 0,
    date_creation DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_projet_categorie (projet_id, categorie_id),
    INDEX idx_projet (projet_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS projet_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    projet_id INT NOT NULL,
    projet_poste_id INT NOT NULL,
    materiau_id INT NULL,
    nom_custom VARCHAR(150) NULL,
    prix_unitaire DECIMAL(10,2) DEFAULT 0,
    quantite INT DEFAULT 1,
    prix_reel DECIMAL(10,2) NULL,
    achete TINYINT(1) DEFAULT 0,
    date_achat DATE NULL,
    date_creation DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_projet (projet_id),
    INDEX idx_poste (projet_poste_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
