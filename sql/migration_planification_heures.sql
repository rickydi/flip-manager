-- ============================================
-- Migration: Planification des heures de travail (extrapolation)
-- Flip Manager
-- ============================================

-- ============================================
-- Table: projet_planification_heures
-- Permet d'estimer les heures de travail par employé pour chaque projet
-- ============================================
CREATE TABLE IF NOT EXISTS projet_planification_heures (
    id INT AUTO_INCREMENT PRIMARY KEY,
    projet_id INT NOT NULL,
    user_id INT NOT NULL,
    heures_semaine_estimees DECIMAL(5,2) DEFAULT 0 COMMENT 'Heures estimées par semaine pour cet employé',
    date_creation DATETIME DEFAULT CURRENT_TIMESTAMP,
    date_modification DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (projet_id) REFERENCES projets(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    
    UNIQUE KEY unique_projet_user (projet_id, user_id),
    INDEX idx_projet (projet_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
