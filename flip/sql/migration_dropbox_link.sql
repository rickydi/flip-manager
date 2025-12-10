-- Migration: Ajout du champ lien Dropbox
ALTER TABLE projets ADD COLUMN dropbox_link VARCHAR(500) DEFAULT NULL AFTER notes;
