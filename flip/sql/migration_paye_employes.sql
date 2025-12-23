-- ============================================
-- Migration: Module Paye Employés (Avances & Paiements)
-- Flip Manager
-- ============================================

-- Sélectionner la base de données
USE evorenoc_flip_manager;

-- ============================================
-- Table: avances_employes (Advances to Employees)
-- ============================================
CREATE TABLE IF NOT EXISTS avances_employes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL COMMENT 'Employé qui reçoit l''avance',
    montant DECIMAL(10,2) NOT NULL COMMENT 'Montant de l''avance',
    date_avance DATE NOT NULL COMMENT 'Date de l''avance',
    raison TEXT NULL COMMENT 'Raison ou note',
    statut ENUM('active', 'deduite', 'annulee') DEFAULT 'active' COMMENT 'active=non encore déduite, deduite=déduite de la paye, annulee=annulée',
    cree_par INT NULL COMMENT 'Admin qui a créé l''avance',
    deduite_semaine DATE NULL COMMENT 'Lundi de la semaine où déduite',
    date_creation DATETIME DEFAULT CURRENT_TIMESTAMP,
    date_modification DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (cree_par) REFERENCES users(id) ON DELETE SET NULL,

    INDEX idx_user (user_id),
    INDEX idx_date (date_avance),
    INDEX idx_statut (statut)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Table: paiements_employes (Employee Payments Ledger)
-- ============================================
CREATE TABLE IF NOT EXISTS paiements_employes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL COMMENT 'Employé payé',
    semaine_debut DATE NOT NULL COMMENT 'Lundi de la semaine payée',
    montant_heures DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT 'Montant basé sur les heures travaillées',
    montant_avances DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT 'Total des avances déduites',
    montant_ajustement DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT 'Ajustements (+/-)',
    note_ajustement TEXT NULL COMMENT 'Raison de l''ajustement',
    montant_net DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT 'Montant net payé (heures - avances + ajustement)',
    mode_paiement ENUM('cheque', 'virement', 'cash', 'autre') DEFAULT 'cheque',
    reference_paiement VARCHAR(100) NULL COMMENT 'Numéro de chèque ou référence',
    paye_par INT NULL COMMENT 'Admin qui a fait le paiement',
    date_paiement DATETIME DEFAULT CURRENT_TIMESTAMP,
    notes TEXT NULL,

    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (paye_par) REFERENCES users(id) ON DELETE SET NULL,

    UNIQUE KEY unique_employe_semaine (user_id, semaine_debut),
    INDEX idx_user (user_id),
    INDEX idx_semaine (semaine_debut),
    INDEX idx_date_paiement (date_paiement)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Vue: Solde des avances par employé
-- ============================================
CREATE OR REPLACE VIEW v_solde_avances AS
SELECT
    u.id as user_id,
    CONCAT(u.prenom, ' ', u.nom) AS employe_nom,
    COALESCE(SUM(CASE WHEN a.statut = 'active' THEN a.montant ELSE 0 END), 0) AS avances_actives,
    COALESCE(SUM(CASE WHEN a.statut = 'deduite' THEN a.montant ELSE 0 END), 0) AS avances_deduites,
    COALESCE(COUNT(CASE WHEN a.statut = 'active' THEN 1 END), 0) AS nb_avances_actives
FROM users u
LEFT JOIN avances_employes a ON u.id = a.user_id
WHERE u.role IN ('employe', 'admin')
GROUP BY u.id;

-- ============================================
-- Vue: Historique paiements avec totaux
-- ============================================
CREATE OR REPLACE VIEW v_historique_paiements AS
SELECT
    p.*,
    CONCAT(u.prenom, ' ', u.nom) AS employe_nom,
    CONCAT(admin.prenom, ' ', admin.nom) AS paye_par_nom
FROM paiements_employes p
JOIN users u ON p.user_id = u.id
LEFT JOIN users admin ON p.paye_par = admin.id;
