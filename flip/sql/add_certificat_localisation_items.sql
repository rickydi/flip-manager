-- Migration: Ajouter les items de vérification de certificat de localisation
-- Exécuter via phpMyAdmin ou autre outil SQL

-- D'abord, trouver l'ID du template (à adapter si nécessaire)
-- SELECT id FROM checklist_templates WHERE nom LIKE '%certificat%' OR nom LIKE '%Certificat%';

-- Si le template n'existe pas, le créer:
INSERT IGNORE INTO checklist_templates (nom, description, ordre, actif)
VALUES ('Vérification Certificat de Localisation', 'Vérifications à faire avant et pendant l\'analyse du certificat de localisation', 1, 1);

-- Récupérer l'ID du template (utiliser SET pour stocker l'ID)
SET @template_id = (SELECT id FROM checklist_templates WHERE nom LIKE '%Certificat%Localisation%' LIMIT 1);

-- Supprimer les anciens items du template (optionnel, décommenter si besoin)
-- DELETE FROM checklist_template_items WHERE template_id = @template_id;

-- AVANT DE FAIRE LE CERTIFICAT
INSERT INTO checklist_template_items (template_id, nom, ordre) VALUES
(@template_id, '── AVANT DE FAIRE LE CERTIFICAT ──', 1),
(@template_id, 'Y a-t-il un certificat de localisation récent (< 10 ans)?', 2),
(@template_id, 'Si non, qui paie pour en faire un nouveau?', 3),
(@template_id, 'Y a-t-il eu des travaux depuis le dernier certificat? (agrandissement, piscine, cabanon, clôture)', 4);

-- CONFORMITÉ AU ZONAGE
INSERT INTO checklist_template_items (template_id, nom, ordre) VALUES
(@template_id, '── CONFORMITÉ AU ZONAGE ──', 10),
(@template_id, 'La maison respecte-t-elle les marges avant?', 11),
(@template_id, 'La maison respecte-t-elle les marges arrière?', 12),
(@template_id, 'La maison respecte-t-elle les marges latérales?', 13),
(@template_id, 'Les bâtiments accessoires (remise, cabanon) sont-ils conformes?', 14);

-- EMPIÈTEMENTS
INSERT INTO checklist_template_items (template_id, nom, ordre) VALUES
(@template_id, '── EMPIÈTEMENTS ──', 20),
(@template_id, 'Y a-t-il des empiètements sur les terrains voisins?', 21),
(@template_id, 'Y a-t-il des voisins qui empiètent sur le terrain?', 22),
(@template_id, 'Depuis combien de temps la situation existe?', 23);

-- SERVITUDES
INSERT INTO checklist_template_items (template_id, nom, ordre) VALUES
(@template_id, '── SERVITUDES ──', 30),
(@template_id, 'Y a-t-il des servitudes sur le terrain?', 31),
(@template_id, 'Si oui, en faveur de qui? (Hydro, Bell, voisin, etc.)', 32),
(@template_id, 'Quelle portion du terrain est affectée?', 33),
(@template_id, 'Y a-t-il des constructions dans la servitude?', 34);

-- ZONES PARTICULIÈRES
INSERT INTO checklist_template_items (template_id, nom, ordre) VALUES
(@template_id, '── ZONES PARTICULIÈRES ──', 40),
(@template_id, 'Le terrain est-il en zone inondable?', 41),
(@template_id, 'Le terrain est-il en zone agricole?', 42),
(@template_id, 'Le terrain est-il dans une zone patrimoniale?', 43),
(@template_id, 'Le terrain est-il près d\'un aéroport?', 44);

-- QUESTIONS DE SUIVI SI PROBLÈMES
INSERT INTO checklist_template_items (template_id, nom, ordre) VALUES
(@template_id, '── QUESTIONS DE SUIVI SI PROBLÈMES ──', 50),
(@template_id, 'Y a-t-il eu des plaintes de voisins?', 51),
(@template_id, 'Y a-t-il eu des avis de la ville?', 52),
(@template_id, 'Existe-t-il des documents prouvant la conformité à l\'époque?', 53);

-- POUR UN FLIP - QUESTIONS CLÉS
INSERT INTO checklist_template_items (template_id, nom, ordre) VALUES
(@template_id, '── POUR UN FLIP - QUESTIONS CLÉS ──', 60),
(@template_id, 'Mes rénovations touchent-elles quelque chose de non conforme?', 61),
(@template_id, 'Ai-je besoin d\'agrandir du côté problématique?', 62),
(@template_id, 'Les non-conformités vont-elles affecter la revente?', 63);

SELECT CONCAT('✓ Items ajoutés au template ID: ', @template_id) AS resultat;
