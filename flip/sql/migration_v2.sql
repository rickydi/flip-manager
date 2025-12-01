-- ============================================
-- Migration v2 - Correction tables investisseurs
-- Exécuter dans phpMyAdmin si vous avez des erreurs
-- ============================================

-- Ajouter colonnes manquantes à investisseurs
ALTER TABLE investisseurs 
ADD COLUMN IF NOT EXISTS type ENUM('investisseur', 'preteur') DEFAULT 'investisseur' AFTER telephone,
ADD COLUMN IF NOT EXISTS taux_interet_defaut DECIMAL(5,2) DEFAULT 0 AFTER type,
ADD COLUMN IF NOT EXISTS frais_dossier_defaut DECIMAL(5,2) DEFAULT 0 AFTER taux_interet_defaut;

-- Ajouter colonnes manquantes à projet_investisseurs
ALTER TABLE projet_investisseurs 
ADD COLUMN IF NOT EXISTS montant DECIMAL(12,2) DEFAULT 0,
ADD COLUMN IF NOT EXISTS taux_interet DECIMAL(5,2) DEFAULT 0;
