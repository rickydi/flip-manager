-- ============================================
-- Migration: Table sous_traitants
-- Gestion des factures de sous-traitants par projet
-- ============================================

CREATE TABLE IF NOT EXISTS sous_traitants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    projet_id INT NOT NULL,
    etape_id INT DEFAULT NULL,
    user_id INT NOT NULL,

    -- Informations sous-traitant
    nom_entreprise VARCHAR(255) NOT NULL,
    contact VARCHAR(255) DEFAULT NULL,
    telephone VARCHAR(50) DEFAULT NULL,
    email VARCHAR(255) DEFAULT NULL,
    description TEXT,
    date_facture DATE NOT NULL,

    -- Montants
    montant_avant_taxes DECIMAL(10,2) NOT NULL,
    tps DECIMAL(10,2) DEFAULT 0,
    tvq DECIMAL(10,2) DEFAULT 0,
    montant_total DECIMAL(10,2) NOT NULL,

    -- Fichier (facture/soumission)
    fichier VARCHAR(255) NULL,

    -- Statut
    statut ENUM('en_attente', 'approuvee', 'rejetee') DEFAULT 'en_attente',
    est_payee TINYINT(1) DEFAULT 0,
    date_paiement DATE DEFAULT NULL,
    commentaire_admin TEXT NULL,
    approuve_par INT NULL,
    date_approbation DATETIME NULL,

    -- Métadonnées
    notes TEXT NULL,
    date_creation DATETIME DEFAULT CURRENT_TIMESTAMP,
    date_modification DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (projet_id) REFERENCES projets(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (approuve_par) REFERENCES users(id),
    INDEX idx_projet (projet_id),
    INDEX idx_statut (statut),
    INDEX idx_date (date_facture)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table pour stocker les entreprises de sous-traitants (autocomplétion)
CREATE TABLE IF NOT EXISTS entreprises_soustraitants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nom VARCHAR(255) NOT NULL UNIQUE,
    contact VARCHAR(255) DEFAULT NULL,
    telephone VARCHAR(50) DEFAULT NULL,
    email VARCHAR(255) DEFAULT NULL,
    specialite VARCHAR(255) DEFAULT NULL,
    actif TINYINT(1) DEFAULT 1,
    date_creation DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insérer quelques entreprises par défaut
INSERT IGNORE INTO entreprises_soustraitants (nom, specialite) VALUES
('Électricien', 'Électricité'),
('Plombier', 'Plomberie'),
('Couvreur', 'Toiture'),
('Maçon', 'Maçonnerie'),
('Peintre', 'Peinture'),
('Menuisier', 'Menuiserie'),
('Carreleur', 'Céramique'),
('Excavation', 'Excavation'),
('Béton', 'Béton');
