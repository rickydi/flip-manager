-- Migration: Ajouter le champ role_evaluation aux projets
ALTER TABLE projets ADD COLUMN IF NOT EXISTS role_evaluation DECIMAL(12,2) DEFAULT 0 AFTER prix_achat;
