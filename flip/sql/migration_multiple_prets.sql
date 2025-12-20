-- ============================================
-- Migration: Permettre plusieurs prêts par prêteur sur un projet
-- ============================================
-- Un même prêteur peut maintenant avoir plusieurs lignes de financement
-- sur un même projet (ex: prêt initial + prêt additionnel)
-- ============================================

-- D'abord, supprimer la clé étrangère qui dépend de l'index
ALTER TABLE projet_investisseurs
DROP FOREIGN KEY projet_investisseurs_ibfk_2;

-- Supprimer la contrainte UNIQUE qui empêche les doublons
ALTER TABLE projet_investisseurs
DROP INDEX unique_projet_investisseur;

-- Recréer la clé étrangère sans l'index UNIQUE
ALTER TABLE projet_investisseurs
ADD CONSTRAINT projet_investisseurs_ibfk_2
FOREIGN KEY (investisseur_id) REFERENCES investisseurs(id);

-- Ajouter un index non-unique pour la performance des requêtes
ALTER TABLE projet_investisseurs
ADD INDEX idx_projet_investisseur (projet_id, investisseur_id);
