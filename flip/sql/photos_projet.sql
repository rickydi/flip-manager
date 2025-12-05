-- Table pour les photos de projet
CREATE TABLE IF NOT EXISTS photos_projet (
    id INT AUTO_INCREMENT PRIMARY KEY,
    projet_id INT NOT NULL,
    user_id INT NOT NULL,
    groupe_id VARCHAR(36) NOT NULL,  -- UUID pour grouper les photos prises ensemble
    fichier VARCHAR(255) NOT NULL,
    date_prise DATETIME NOT NULL,
    description TEXT,
    date_creation TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (projet_id) REFERENCES projets(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_projet (projet_id),
    INDEX idx_groupe (groupe_id),
    INDEX idx_date (date_prise)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
