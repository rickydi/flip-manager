-- Migration: Ajout des champs solde à payer (ajustements de taxes)
-- solde_vendeur: ajustement de taxe à payer au vendeur lors de l'achat
-- solde_acheteur: ajustement de taxe à payer à l'acheteur lors de la vente

ALTER TABLE projets ADD COLUMN solde_vendeur DECIMAL(10,2) DEFAULT 0 AFTER assurance_titre;
ALTER TABLE projets ADD COLUMN solde_acheteur DECIMAL(10,2) DEFAULT 0 AFTER quittance;
