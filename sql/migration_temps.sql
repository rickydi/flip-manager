-- ============================================
-- Migration: Ajout du module Temps (Time Tracking)
-- Flip Manager
-- ============================================

-- Sélectionner la base de données
USE evorenoc_flip_manager;

-- Ajouter le taux horaire aux utilisateurs
ALTER TABLE users ADD COLUMN taux_horaire DECIMAL(10,2) DEFAULT 0 AFTER role;

-- ============================================
-- Table: heures_travaillees (Time Tracking)
-- ============================================
CREATE TABLE heures_travaillees (
    id INT AUTO_INCREMENT PRIMARY KEY,
    projet_id INT NOT NULL,
    user_id INT NOT NULL,
    date_travail DATE NOT NULL,
    heures DECIMAL(5,2) NOT NULL,
    taux_horaire DECIMAL(10,2) NOT NULL COMMENT 'Taux horaire au moment de la saisie',
    description TEXT NULL,
    statut ENUM('en_attente', 'approuvee', 'rejetee') DEFAULT 'en_attente',
    approuve_par INT NULL,
    date_approbation DATETIME NULL,
    date_creation DATETIME DEFAULT CURRENT_TIMESTAMP,
    date_modification DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (projet_id) REFERENCES projets(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (approuve_par) REFERENCES users(id) ON DELETE SET NULL,
    
    INDEX idx_projet (projet_id),
    INDEX idx_user (user_id),
    INDEX idx_date (date_travail),
    INDEX idx_statut (statut)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Vue pour faciliter les calculs
-- ============================================
CREATE OR REPLACE VIEW v_heures_projet AS
SELECT 
    h.projet_id,
    h.user_id,
    CONCAT(u.prenom, ' ', u.nom) AS employe_nom,
    SUM(h.heures) AS total_heures,
    SUM(h.heures * h.taux_horaire) AS cout_total,
    COUNT(*) AS nb_entrees
FROM heures_travaillees h
JOIN users u ON h.user_id = u.id
WHERE h.statut = 'approuvee'
GROUP BY h.projet_id, h.user_id;
