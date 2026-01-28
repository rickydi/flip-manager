-- ============================================
-- Migration: Notes d'amélioration de l'application
-- ============================================

CREATE TABLE IF NOT EXISTS app_notes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    contenu TEXT NOT NULL,
    terminee TINYINT(1) DEFAULT 0,
    priorite INT DEFAULT 0,
    user_id INT NULL,
    date_creation DATETIME DEFAULT CURRENT_TIMESTAMP,
    date_modification DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
