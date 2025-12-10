-- Migration: Ajouter le champ quittance aux projets
ALTER TABLE projets ADD COLUMN IF NOT EXISTS quittance DECIMAL(10,2) DEFAULT 0 AFTER taxe_mutation;
