-- Migration: Table pour tracker les semaines payées
-- Flip Manager

CREATE TABLE IF NOT EXISTS semaines_payees (
    id INT AUTO_INCREMENT PRIMARY KEY,
    semaine_debut DATE NOT NULL COMMENT 'Date du lundi de la semaine',
    paye_par INT NULL COMMENT 'ID de l\'admin qui a marqué comme payé',
    date_paiement DATETIME DEFAULT CURRENT_TIMESTAMP,
    notes TEXT NULL,

    UNIQUE KEY unique_semaine (semaine_debut),
    INDEX idx_semaine (semaine_debut)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
