-- ============================================
-- Migration: Module Comparables & IA
-- ============================================

-- Table pour les configurations système (Clés API, etc.)
CREATE TABLE IF NOT EXISTS app_configurations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cle VARCHAR(50) NOT NULL UNIQUE,
    valeur TEXT NULL,
    description VARCHAR(255) NULL,
    est_sensible TINYINT(1) DEFAULT 0, -- Si 1, masquer la valeur dans l'interface
    date_modification TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insérer la configuration par défaut pour Claude (vide pour l'instant, sera mise à jour via l'admin ou script)
INSERT IGNORE INTO app_configurations (cle, valeur, description, est_sensible) 
VALUES ('ANTHROPIC_API_KEY', '', 'Clé API pour Claude (Anthropic)', 1);

INSERT IGNORE INTO app_configurations (cle, valeur, description, est_sensible) 
VALUES ('CLAUDE_MODEL', 'claude-3-5-sonnet-20241022', 'Modèle Claude à utiliser', 0);

-- Table pour les rapports d'analyse de marché
CREATE TABLE IF NOT EXISTS analyses_marche (
    id INT AUTO_INCREMENT PRIMARY KEY,
    projet_id INT NULL,
    nom_rapport VARCHAR(255) NOT NULL,
    fichier_source VARCHAR(255) NOT NULL, -- Chemin du PDF uploadé
    date_analyse DATETIME DEFAULT CURRENT_TIMESTAMP,
    statut ENUM('en_cours', 'termine', 'erreur') DEFAULT 'en_cours',
    
    -- Résultats globaux
    prix_moyen DECIMAL(12,2) DEFAULT 0,
    prix_median DECIMAL(12,2) DEFAULT 0,
    prix_suggere_ia DECIMAL(12,2) DEFAULT 0,
    fourchette_basse DECIMAL(12,2) DEFAULT 0,
    fourchette_haute DECIMAL(12,2) DEFAULT 0,
    
    analyse_ia_texte TEXT NULL, -- Résumé textuel de l'IA
    
    FOREIGN KEY (projet_id) REFERENCES projets(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Table pour les items (maisons) extraits de l'analyse
CREATE TABLE IF NOT EXISTS comparables_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    analyse_id INT NOT NULL,
    
    -- Données extraites
    adresse VARCHAR(255) NULL,
    prix_vendu DECIMAL(12,2) DEFAULT 0,
    date_vente DATE NULL,
    delai_vente INT DEFAULT 0, -- en jours
    
    -- Caractéristiques
    superficie_batiment VARCHAR(50) NULL,
    superficie_terrain VARCHAR(50) NULL,
    annee_construction INT NULL,
    chambres VARCHAR(20) NULL, -- ex: "3+1"
    salles_bains VARCHAR(20) NULL, -- ex: "2"
    garage TINYINT(1) DEFAULT 0,
    
    -- Analyse IA spécifique à cet item
    etat_general_note INT DEFAULT 0, -- Sur 10
    etat_general_texte VARCHAR(50) NULL, -- "Rénové", "Daté", etc.
    renovations_mentionnees TEXT NULL,
    
    -- Comparaison avec le sujet
    ajustement_ia DECIMAL(12,2) DEFAULT 0, -- Montant +/-
    commentaire_ia TEXT NULL, -- Pourquoi cet ajustement
    
    FOREIGN KEY (analyse_id) REFERENCES analyses_marche(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
