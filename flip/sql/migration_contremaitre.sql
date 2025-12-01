-- ============================================
-- Migration: Ajout du rôle Contremaître
-- Flip Manager
-- ============================================

USE evorenoc_flip_manager;

-- Ajouter le flag contremaître aux utilisateurs
ALTER TABLE users ADD COLUMN est_contremaitre TINYINT(1) DEFAULT 0 AFTER taux_horaire;
