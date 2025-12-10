-- ============================================
-- Migration: Système de templates budgets détaillés
-- Flip Manager
-- ============================================

-- ============================================
-- Table: sous_categories (Templates sous-catégories)
-- Liées aux catégories existantes
-- ============================================
CREATE TABLE IF NOT EXISTS sous_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    categorie_id INT NOT NULL,
    nom VARCHAR(100) NOT NULL,
    ordre INT DEFAULT 0,
    actif TINYINT(1) DEFAULT 1,
    date_creation DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (categorie_id) REFERENCES categories(id) ON DELETE CASCADE,
    INDEX idx_categorie (categorie_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Table: materiaux (Templates matériaux avec prix défaut)
-- Liés aux sous-catégories
-- ============================================
CREATE TABLE IF NOT EXISTS materiaux (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sous_categorie_id INT NOT NULL,
    nom VARCHAR(150) NOT NULL,
    prix_defaut DECIMAL(10,2) DEFAULT 0,
    ordre INT DEFAULT 0,
    actif TINYINT(1) DEFAULT 1,
    date_creation DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sous_categorie_id) REFERENCES sous_categories(id) ON DELETE CASCADE,
    INDEX idx_sous_categorie (sous_categorie_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Table: projet_postes (Postes importés par projet avec quantité)
-- Ex: "Salle de bain" x2 pour un projet
-- ============================================
CREATE TABLE IF NOT EXISTS projet_postes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    projet_id INT NOT NULL,
    categorie_id INT NOT NULL,
    quantite INT DEFAULT 1,
    budget_extrapole DECIMAL(10,2) DEFAULT 0,
    date_creation DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (projet_id) REFERENCES projets(id) ON DELETE CASCADE,
    FOREIGN KEY (categorie_id) REFERENCES categories(id) ON DELETE CASCADE,
    UNIQUE KEY unique_projet_categorie (projet_id, categorie_id),
    INDEX idx_projet (projet_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Table: projet_items (Items cochés par projet)
-- Détail des matériaux sélectionnés
-- ============================================
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
    FOREIGN KEY (projet_id) REFERENCES projets(id) ON DELETE CASCADE,
    FOREIGN KEY (projet_poste_id) REFERENCES projet_postes(id) ON DELETE CASCADE,
    FOREIGN KEY (materiau_id) REFERENCES materiaux(id) ON DELETE SET NULL,
    INDEX idx_projet (projet_id),
    INDEX idx_poste (projet_poste_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
