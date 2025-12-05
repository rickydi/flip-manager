-- Migration: Catégories de photos
-- Flip Manager

CREATE TABLE IF NOT EXISTS photos_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cle VARCHAR(50) NOT NULL UNIQUE,
    nom_fr VARCHAR(100) NOT NULL,
    nom_es VARCHAR(100),
    ordre INT DEFAULT 0,
    actif TINYINT(1) DEFAULT 1,
    date_creation DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insérer les catégories par défaut
INSERT INTO photos_categories (cle, nom_fr, nom_es, ordre, actif) VALUES
('cat_interior_finishing', 'Finition intérieure', 'Acabado interior', 1, 1),
('cat_exterior', 'Extérieur', 'Exterior', 2, 1),
('cat_plumbing', 'Plomberie', 'Plomería', 3, 1),
('cat_electrical', 'Électricité', 'Electricidad', 4, 1),
('cat_structure', 'Structure', 'Estructura', 5, 1),
('cat_foundation', 'Fondation', 'Cimentación', 6, 1),
('cat_roofing', 'Toiture', 'Techo', 7, 1),
('cat_windows_doors', 'Fenêtres et portes', 'Ventanas y puertas', 8, 1),
('cat_painting', 'Peinture', 'Pintura', 9, 1),
('cat_flooring', 'Plancher', 'Piso', 10, 1),
('cat_before_work', 'Avant travaux', 'Antes del trabajo', 11, 1),
('cat_after_work', 'Après travaux', 'Después del trabajo', 12, 1),
('cat_progress', 'En cours', 'En progreso', 13, 1),
('cat_other', 'Autre', 'Otro', 99, 1)
ON DUPLICATE KEY UPDATE nom_fr = VALUES(nom_fr), nom_es = VALUES(nom_es);
