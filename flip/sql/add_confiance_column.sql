-- Ajouter la colonne confiance à comparables_chunks
-- À exécuter dans phpMyAdmin

ALTER TABLE comparables_chunks
ADD COLUMN confiance INT DEFAULT 0 COMMENT 'Pourcentage de confiance IA (0-100)';
