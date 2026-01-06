-- Migration Comparables V2 - Extraction par chunks avec photos
-- Flip Manager

-- Table pour stocker les chunks de texte extraits du PDF
CREATE TABLE IF NOT EXISTS comparables_chunks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    analyse_id INT NOT NULL,
    no_centris VARCHAR(20) NOT NULL,
    page_debut INT DEFAULT 1,
    page_fin INT DEFAULT 1,
    chunk_text MEDIUMTEXT,
    photos_path VARCHAR(255),  -- Chemin vers le dossier photos
    statut ENUM('pending', 'text_done', 'photos_done', 'done', 'error') DEFAULT 'pending',

    -- Données extraites du texte
    adresse VARCHAR(255),
    ville VARCHAR(100),
    prix_vendu DECIMAL(12,2) DEFAULT 0,
    date_vente DATE NULL,
    jours_marche INT DEFAULT 0,
    chambres VARCHAR(20),
    sdb VARCHAR(20),
    superficie_terrain VARCHAR(50),
    superficie_batiment VARCHAR(50),
    annee_construction INT,
    type_propriete VARCHAR(100),
    renovations_texte TEXT,
    remarques TEXT,

    -- Analyse IA des photos
    etat_note DECIMAL(3,1) DEFAULT 0,
    etat_analyse TEXT,
    ajustement DECIMAL(12,2) DEFAULT 0,
    commentaire_ia TEXT,

    error_message TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_analyse (analyse_id),
    INDEX idx_centris (no_centris),
    INDEX idx_statut (statut)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Modifier la table analyses_marche pour le nouveau workflow
ALTER TABLE analyses_marche
    ADD COLUMN IF NOT EXISTS total_chunks INT DEFAULT 0,
    ADD COLUMN IF NOT EXISTS processed_chunks INT DEFAULT 0,
    ADD COLUMN IF NOT EXISTS photos_analyzed INT DEFAULT 0,
    ADD COLUMN IF NOT EXISTS extraction_path VARCHAR(255),
    ADD COLUMN IF NOT EXISTS error_log TEXT;

-- Table pour stocker les photos individuelles (optionnel, pour galerie)
CREATE TABLE IF NOT EXISTS comparables_photos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    chunk_id INT NOT NULL,
    filename VARCHAR(255) NOT NULL,
    label VARCHAR(100),  -- ex: "Cuisine", "Facade", "Salle de bains"
    file_path VARCHAR(255) NOT NULL,
    ordre INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_chunk (chunk_id),
    FOREIGN KEY (chunk_id) REFERENCES comparables_chunks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
