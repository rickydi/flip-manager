-- ============================================
-- Migration: Ajout du username pour login simplifié
-- Flip Manager
-- ============================================

USE evorenoc_flip_manager;

-- Ajouter le champ username
ALTER TABLE users ADD COLUMN username VARCHAR(50) NULL AFTER id;

-- Index unique sur username
ALTER TABLE users ADD UNIQUE INDEX idx_username (username);

-- Mettre à jour les usernames existants basés sur prenom (en minuscule, sans accents ni espaces)
-- Admin devient "admin", Employé Test devient "employe"
UPDATE users SET username = LOWER(REPLACE(REPLACE(REPLACE(REPLACE(prenom, ' ', ''), 'é', 'e'), 'è', 'e'), 'ê', 'e')) WHERE username IS NULL;
