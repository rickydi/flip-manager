-- Migration: Ajouter derniere_consultation pour trier par projet récemment ouvert
-- Date: 2026-01-13

-- Ajouter colonne pour tracker la dernière consultation d'un projet
ALTER TABLE projets ADD COLUMN derniere_consultation DATETIME DEFAULT NULL;

-- Initialiser avec la date de création pour les projets existants
UPDATE projets SET derniere_consultation = date_creation WHERE derniere_consultation IS NULL;
