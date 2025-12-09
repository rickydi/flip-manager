-- Migration: Ajouter la colonne ordre aux photos pour permettre la réorganisation
ALTER TABLE photos_projet ADD COLUMN IF NOT EXISTS ordre INT DEFAULT 0 AFTER description;

-- Initialiser l'ordre basé sur la date de création pour les photos existantes
UPDATE photos_projet p
JOIN (
    SELECT id, projet_id, ROW_NUMBER() OVER (PARTITION BY projet_id ORDER BY date_prise ASC, id ASC) as new_ordre
    FROM photos_projet
) ranked ON p.id = ranked.id
SET p.ordre = ranked.new_ordre;

-- Ajouter un index pour l'ordre
ALTER TABLE photos_projet ADD INDEX idx_ordre (projet_id, ordre);
