-- Table pour stocker plusieurs Google Sheets par projet
CREATE TABLE IF NOT EXISTS projet_google_sheets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    projet_id INT NOT NULL,
    nom VARCHAR(255) NOT NULL,
    url VARCHAR(500) NOT NULL,
    ordre INT DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (projet_id) REFERENCES projets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Optionnel: colonne simple si déjà créée (peut être ignorée)
-- ALTER TABLE projets ADD COLUMN google_sheet_url VARCHAR(500) NULL AFTER notes;
