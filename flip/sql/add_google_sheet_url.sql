-- Ajout de la colonne google_sheet_url pour les projets
ALTER TABLE projets ADD COLUMN google_sheet_url VARCHAR(500) NULL AFTER notes;
