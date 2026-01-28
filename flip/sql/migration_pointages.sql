-- ============================================
-- Migration: Système de pointage employés
-- ============================================

-- Ajouter coordonnées GPS aux projets (pour géo-fencing)
ALTER TABLE projets
ADD COLUMN IF NOT EXISTS latitude DECIMAL(10, 8) NULL AFTER code_postal,
ADD COLUMN IF NOT EXISTS longitude DECIMAL(11, 8) NULL AFTER latitude,
ADD COLUMN IF NOT EXISTS rayon_gps INT DEFAULT 100 AFTER longitude; -- Rayon en mètres pour auto-punch

-- ============================================
-- Table: pointages (Système de punch employés)
-- ============================================
CREATE TABLE IF NOT EXISTS pointages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    projet_id INT NULL, -- Peut être NULL si punch sans projet

    -- Type de pointage
    type ENUM('start', 'pause', 'resume', 'stop') NOT NULL,

    -- Horodatage
    date_pointage DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    -- Coordonnées GPS au moment du pointage
    latitude DECIMAL(10, 8) NULL,
    longitude DECIMAL(11, 8) NULL,
    precision_gps DECIMAL(10, 2) NULL, -- Précision en mètres

    -- Auto-punch par GPS?
    auto_gps TINYINT(1) DEFAULT 0,

    -- Notes optionnelles
    notes TEXT NULL,

    -- Métadonnées
    date_creation DATETIME DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (projet_id) REFERENCES projets(id) ON DELETE SET NULL,

    INDEX idx_user_date (user_id, date_pointage),
    INDEX idx_projet_date (projet_id, date_pointage)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Table: sessions_travail (Sessions calculées)
-- ============================================
CREATE TABLE IF NOT EXISTS sessions_travail (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    projet_id INT NULL,

    -- Période
    date_travail DATE NOT NULL,
    heure_debut DATETIME NOT NULL,
    heure_fin DATETIME NULL, -- NULL si session en cours

    -- Temps calculé (en minutes)
    duree_travail INT DEFAULT 0, -- Minutes travaillées
    duree_pause INT DEFAULT 0, -- Minutes de pause

    -- Statut
    statut ENUM('en_cours', 'pause', 'terminee') DEFAULT 'en_cours',

    -- Métadonnées
    date_creation DATETIME DEFAULT CURRENT_TIMESTAMP,
    date_modification DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (projet_id) REFERENCES projets(id) ON DELETE SET NULL,

    INDEX idx_user_date (user_id, date_travail),
    INDEX idx_statut (statut)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Table: user_gps_settings (Préférences GPS par utilisateur)
-- ============================================
CREATE TABLE IF NOT EXISTS user_gps_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,

    -- Activation GPS
    gps_enabled TINYINT(1) DEFAULT 0,
    auto_punch_enabled TINYINT(1) DEFAULT 0,

    -- Dernière position connue
    derniere_latitude DECIMAL(10, 8) NULL,
    derniere_longitude DECIMAL(11, 8) NULL,
    derniere_mise_a_jour DATETIME NULL,

    -- Métadonnées
    date_creation DATETIME DEFAULT CURRENT_TIMESTAMP,
    date_modification DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
