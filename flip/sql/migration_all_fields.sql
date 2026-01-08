-- Migration pour ajouter TOUS les champs manquants à comparables_chunks
-- Exécuter dans phpMyAdmin

-- Superficie bâtiment
ALTER TABLE comparables_chunks ADD COLUMN IF NOT EXISTS superficie_batiment VARCHAR(50) DEFAULT NULL;

-- Évaluation municipale
ALTER TABLE comparables_chunks ADD COLUMN IF NOT EXISTS eval_terrain INT DEFAULT NULL;
ALTER TABLE comparables_chunks ADD COLUMN IF NOT EXISTS eval_batiment INT DEFAULT NULL;
ALTER TABLE comparables_chunks ADD COLUMN IF NOT EXISTS eval_total INT DEFAULT NULL;

-- Taxes
ALTER TABLE comparables_chunks ADD COLUMN IF NOT EXISTS taxe_municipale INT DEFAULT NULL;
ALTER TABLE comparables_chunks ADD COLUMN IF NOT EXISTS taxe_scolaire INT DEFAULT NULL;
ALTER TABLE comparables_chunks ADD COLUMN IF NOT EXISTS taxe_annee VARCHAR(4) DEFAULT NULL;

-- Caractéristiques construction
ALTER TABLE comparables_chunks ADD COLUMN IF NOT EXISTS fondation VARCHAR(100) DEFAULT NULL;
ALTER TABLE comparables_chunks ADD COLUMN IF NOT EXISTS toiture VARCHAR(100) DEFAULT NULL;
ALTER TABLE comparables_chunks ADD COLUMN IF NOT EXISTS revetement VARCHAR(100) DEFAULT NULL;
ALTER TABLE comparables_chunks ADD COLUMN IF NOT EXISTS garage VARCHAR(100) DEFAULT NULL;
ALTER TABLE comparables_chunks ADD COLUMN IF NOT EXISTS stationnement VARCHAR(50) DEFAULT NULL;
ALTER TABLE comparables_chunks ADD COLUMN IF NOT EXISTS piscine VARCHAR(100) DEFAULT NULL;
ALTER TABLE comparables_chunks ADD COLUMN IF NOT EXISTS sous_sol TEXT DEFAULT NULL;
ALTER TABLE comparables_chunks ADD COLUMN IF NOT EXISTS chauffage VARCHAR(100) DEFAULT NULL;
ALTER TABLE comparables_chunks ADD COLUMN IF NOT EXISTS energie VARCHAR(100) DEFAULT NULL;

-- Autres infos
ALTER TABLE comparables_chunks ADD COLUMN IF NOT EXISTS proximites TEXT DEFAULT NULL;
ALTER TABLE comparables_chunks ADD COLUMN IF NOT EXISTS inclusions TEXT DEFAULT NULL;
ALTER TABLE comparables_chunks ADD COLUMN IF NOT EXISTS exclusions TEXT DEFAULT NULL;
ALTER TABLE comparables_chunks ADD COLUMN IF NOT EXISTS renovations_total INT DEFAULT 0;

-- Confiance IA
ALTER TABLE comparables_chunks ADD COLUMN IF NOT EXISTS confiance INT DEFAULT 0;

-- Si "IF NOT EXISTS" ne fonctionne pas sur votre version MySQL, utilisez ces commandes:
-- (ignorez les erreurs "Duplicate column name" si la colonne existe déjà)

/*
ALTER TABLE comparables_chunks ADD COLUMN superficie_batiment VARCHAR(50) DEFAULT NULL;
ALTER TABLE comparables_chunks ADD COLUMN eval_terrain INT DEFAULT NULL;
ALTER TABLE comparables_chunks ADD COLUMN eval_batiment INT DEFAULT NULL;
ALTER TABLE comparables_chunks ADD COLUMN eval_total INT DEFAULT NULL;
ALTER TABLE comparables_chunks ADD COLUMN taxe_municipale INT DEFAULT NULL;
ALTER TABLE comparables_chunks ADD COLUMN taxe_scolaire INT DEFAULT NULL;
ALTER TABLE comparables_chunks ADD COLUMN taxe_annee VARCHAR(4) DEFAULT NULL;
ALTER TABLE comparables_chunks ADD COLUMN fondation VARCHAR(100) DEFAULT NULL;
ALTER TABLE comparables_chunks ADD COLUMN toiture VARCHAR(100) DEFAULT NULL;
ALTER TABLE comparables_chunks ADD COLUMN revetement VARCHAR(100) DEFAULT NULL;
ALTER TABLE comparables_chunks ADD COLUMN garage VARCHAR(100) DEFAULT NULL;
ALTER TABLE comparables_chunks ADD COLUMN stationnement VARCHAR(50) DEFAULT NULL;
ALTER TABLE comparables_chunks ADD COLUMN piscine VARCHAR(100) DEFAULT NULL;
ALTER TABLE comparables_chunks ADD COLUMN sous_sol TEXT DEFAULT NULL;
ALTER TABLE comparables_chunks ADD COLUMN chauffage VARCHAR(100) DEFAULT NULL;
ALTER TABLE comparables_chunks ADD COLUMN energie VARCHAR(100) DEFAULT NULL;
ALTER TABLE comparables_chunks ADD COLUMN proximites TEXT DEFAULT NULL;
ALTER TABLE comparables_chunks ADD COLUMN inclusions TEXT DEFAULT NULL;
ALTER TABLE comparables_chunks ADD COLUMN exclusions TEXT DEFAULT NULL;
ALTER TABLE comparables_chunks ADD COLUMN renovations_total INT DEFAULT 0;
ALTER TABLE comparables_chunks ADD COLUMN confiance INT DEFAULT 0;
*/
