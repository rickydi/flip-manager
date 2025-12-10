-- Migration: Ajouter support pour sous-catégories imbriquées
-- Exécuter dans phpMyAdmin

-- Ajouter colonne parent_id pour permettre l'imbrication
ALTER TABLE sous_categories
ADD COLUMN parent_id INT NULL AFTER categorie_id,
ADD INDEX idx_parent (parent_id),
ADD CONSTRAINT fk_sous_cat_parent
    FOREIGN KEY (parent_id) REFERENCES sous_categories(id) ON DELETE CASCADE;
