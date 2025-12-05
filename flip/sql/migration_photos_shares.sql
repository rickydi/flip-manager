-- Migration: Partage sécurisé des photos
-- Flip Manager

CREATE TABLE IF NOT EXISTS photos_shares (
    id INT AUTO_INCREMENT PRIMARY KEY,
    fichier VARCHAR(255) NOT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    created_by INT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    expire_at DATETIME NULL,
    INDEX idx_fichier_token (fichier, token),
    INDEX idx_token (token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
