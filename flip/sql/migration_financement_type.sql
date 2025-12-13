-- Migration: Ajouter colonne type pour distinguer prêteur vs investisseur
-- Date: 2025-12-13

-- Ajouter la colonne type_financement
ALTER TABLE projet_investisseurs
ADD COLUMN type_financement ENUM('preteur', 'investisseur') DEFAULT 'preteur' AFTER investisseur_id;

-- Migrer les données existantes: ceux avec taux_interet > 0 sont des prêteurs, sinon investisseurs
UPDATE projet_investisseurs
SET type_financement = CASE
    WHEN taux_interet > 0 THEN 'preteur'
    ELSE 'investisseur'
END;
