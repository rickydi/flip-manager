-- Migration: Ajouter le champ "cession" pour l'achat de cession de contrat

ALTER TABLE projets ADD COLUMN cession DECIMAL(10,2) DEFAULT 0 AFTER prix_achat;
