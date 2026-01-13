-- Migration: Configuration des onglets de projet
-- Permet de réorganiser les onglets et d'ajouter des séparateurs

CREATE TABLE IF NOT EXISTS projet_tabs_config (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    tabs_order JSON NOT NULL COMMENT 'Ordre des onglets et séparateurs en JSON',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_user (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Structure du JSON tabs_order:
-- [
--   {"type": "tab", "id": "base"},
--   {"type": "tab", "id": "financement"},
--   {"type": "divider"},
--   {"type": "tab", "id": "budgets"},
--   ...
-- ]
