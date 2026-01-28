-- Migration: Ajouter préférence de vue pour la liste des projets
-- Date: 2026-01-13

-- Ajouter colonne pour stocker la préférence de vue (liste ou grille)
ALTER TABLE users ADD COLUMN vue_projets_preference ENUM('liste', 'grille') DEFAULT 'liste';
