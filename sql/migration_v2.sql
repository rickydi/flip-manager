-- ============================================
-- Migration v2 - Correction tables investisseurs
-- Exécuter dans phpMyAdmin si vous avez des erreurs
-- ============================================

-- Ajouter colonnes manquantes à investisseurs
ALTER TABLE investisseurs 
ADD COLUMN IF NOT EXISTS type ENUM('investisseur', 'preteur') DEFAULT 'investisseur' AFTER telephone,
ADD COLUMN IF NOT EXISTS taux_interet_defaut DECIMAL(5,2) DEFAULT 0 AFTER type,
ADD COLUMN IF NOT EXISTS frais_dossier_defaut DECIMAL(5,2) DEFAULT 0 AFTER taux_interet_defaut;

-- Correction pour projet_investisseurs - ajouter colonnes alias si nécessaire
-- La colonne mise_de_fonds est utilisée pour stocker le montant
-- La colonne pourcentage_profit est utilisée pour stocker le taux d'intérêt
