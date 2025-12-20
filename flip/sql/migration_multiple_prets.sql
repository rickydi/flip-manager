-- ============================================
-- Migration: Permettre plusieurs prêts par prêteur sur un projet
-- ============================================
-- Un même prêteur peut maintenant avoir plusieurs lignes de financement
-- sur un même projet (ex: prêt initial + prêt additionnel)
-- ============================================

-- Supprimer la contrainte UNIQUE qui empêche les doublons
ALTER TABLE projet_investisseurs
DROP INDEX unique_projet_investisseur;

-- Ajouter un index non-unique pour la performance des requêtes
ALTER TABLE projet_investisseurs
ADD INDEX idx_projet_investisseur (projet_id, investisseur_id);
