-- ============================================
-- Données par défaut: Sous-catégories et Matériaux
-- Flip Manager
-- ============================================

-- ============================================
-- SALLE DE BAIN (categorie_id à ajuster selon votre DB)
-- ============================================

-- D'abord, récupérons les IDs des catégories existantes
SET @cat_sdb = (SELECT id FROM categories WHERE nom LIKE '%salle de bain%' OR nom LIKE '%Sdb%' LIMIT 1);
SET @cat_cuisine = (SELECT id FROM categories WHERE nom LIKE '%cuisine%' LIMIT 1);
SET @cat_elec = (SELECT id FROM categories WHERE nom LIKE '%lectri%' LIMIT 1);
SET @cat_plomb = (SELECT id FROM categories WHERE nom LIKE '%plomb%' LIMIT 1);
SET @cat_chauff = (SELECT id FROM categories WHERE nom LIKE '%chauff%' OR nom LIKE '%ventil%' LIMIT 1);
SET @cat_portes = (SELECT id FROM categories WHERE nom LIKE '%porte%' OR nom LIKE '%fen%' LIMIT 1);
SET @cat_finition = (SELECT id FROM categories WHERE nom LIKE '%finition%' OR nom LIKE '%int%rieur%' LIMIT 1);
SET @cat_exterieur = (SELECT id FROM categories WHERE nom LIKE '%ext%rieur%' OR nom LIKE '%toiture%' LIMIT 1);
SET @cat_structure = (SELECT id FROM categories WHERE nom LIKE '%structure%' OR nom LIKE '%fondation%' LIMIT 1);
SET @cat_divers = (SELECT id FROM categories WHERE nom LIKE '%divers%' OR nom LIKE '%autre%' LIMIT 1);

-- ============================================
-- SALLE DE BAIN - Sous-catégories
-- ============================================
INSERT INTO sous_categories (categorie_id, nom, ordre) VALUES
(@cat_sdb, 'Bain/Douche', 1),
(@cat_sdb, 'Toilette', 2),
(@cat_sdb, 'Vanité', 3),
(@cat_sdb, 'Accessoires', 4),
(@cat_sdb, 'Plancher', 5);

-- Récupérer les IDs des sous-catégories SDB
SET @sc_bain = (SELECT id FROM sous_categories WHERE nom = 'Bain/Douche' AND categorie_id = @cat_sdb);
SET @sc_toilette = (SELECT id FROM sous_categories WHERE nom = 'Toilette' AND categorie_id = @cat_sdb);
SET @sc_vanite = (SELECT id FROM sous_categories WHERE nom = 'Vanité' AND categorie_id = @cat_sdb);
SET @sc_accessoires = (SELECT id FROM sous_categories WHERE nom = 'Accessoires' AND categorie_id = @cat_sdb);
SET @sc_plancher_sdb = (SELECT id FROM sous_categories WHERE nom = 'Plancher' AND categorie_id = @cat_sdb);

-- Matériaux Bain/Douche
INSERT INTO materiaux (sous_categorie_id, nom, prix_defaut, ordre) VALUES
(@sc_bain, 'Bain acrylique 60"', 450.00, 1),
(@sc_bain, 'Bain acrylique 66"', 550.00, 2),
(@sc_bain, 'Bain autoportant', 800.00, 3),
(@sc_bain, 'Base de douche 32x32', 200.00, 4),
(@sc_bain, 'Base de douche 36x36', 250.00, 5),
(@sc_bain, 'Base de douche 48x36', 350.00, 6),
(@sc_bain, 'Ensemble douche préfab', 450.00, 7),
(@sc_bain, 'Porte de douche vitrée', 400.00, 8),
(@sc_bain, 'Rideau + tringle', 40.00, 9),
(@sc_bain, 'Crépine (drain)', 25.00, 10),
(@sc_bain, 'Robinetterie bain/douche', 180.00, 11),
(@sc_bain, 'Pomme de douche', 45.00, 12),
(@sc_bain, 'Céramique mur douche', 300.00, 13);

-- Matériaux Toilette
INSERT INTO materiaux (sous_categorie_id, nom, prix_defaut, ordre) VALUES
(@sc_toilette, 'Toilette standard', 200.00, 1),
(@sc_toilette, 'Toilette allongée', 280.00, 2),
(@sc_toilette, 'Toilette à jupe', 350.00, 3),
(@sc_toilette, 'Siège soft-close', 35.00, 4),
(@sc_toilette, 'Valve d''alimentation', 15.00, 5),
(@sc_toilette, 'Flexible d''alimentation', 10.00, 6),
(@sc_toilette, 'Bride de sol (flange)', 20.00, 7),
(@sc_toilette, 'Anneau de cire', 8.00, 8);

-- Matériaux Vanité
INSERT INTO materiaux (sous_categorie_id, nom, prix_defaut, ordre) VALUES
(@sc_vanite, 'Vanité 24"', 300.00, 1),
(@sc_vanite, 'Vanité 30"', 400.00, 2),
(@sc_vanite, 'Vanité 36"', 450.00, 3),
(@sc_vanite, 'Vanité 48"', 550.00, 4),
(@sc_vanite, 'Vanité 60" double', 750.00, 5),
(@sc_vanite, 'Comptoir vanité', 150.00, 6),
(@sc_vanite, 'Lavabo encastré', 80.00, 7),
(@sc_vanite, 'Lavabo vasque', 120.00, 8),
(@sc_vanite, 'Robinet lavabo', 120.00, 9),
(@sc_vanite, 'Drain lavabo + siphon', 25.00, 10),
(@sc_vanite, 'Miroir', 80.00, 11),
(@sc_vanite, 'Pharmacie avec miroir', 150.00, 12);

-- Matériaux Accessoires SDB
INSERT INTO materiaux (sous_categorie_id, nom, prix_defaut, ordre) VALUES
(@sc_accessoires, 'Porte-serviettes', 30.00, 1),
(@sc_accessoires, 'Anneau à serviette', 20.00, 2),
(@sc_accessoires, 'Porte-papier', 20.00, 3),
(@sc_accessoires, 'Crochet', 10.00, 4),
(@sc_accessoires, 'Ventilateur sdb', 80.00, 5),
(@sc_accessoires, 'Lumière vanité', 75.00, 6);

-- Matériaux Plancher SDB
INSERT INTO materiaux (sous_categorie_id, nom, prix_defaut, ordre) VALUES
(@sc_plancher_sdb, 'Céramique plancher', 200.00, 1),
(@sc_plancher_sdb, 'Vinyle plancher', 100.00, 2),
(@sc_plancher_sdb, 'Plancher chauffant', 250.00, 3),
(@sc_plancher_sdb, 'Membrane Ditra', 80.00, 4);

-- ============================================
-- CUISINE - Sous-catégories
-- ============================================
INSERT INTO sous_categories (categorie_id, nom, ordre) VALUES
(@cat_cuisine, 'Armoires', 1),
(@cat_cuisine, 'Comptoir', 2),
(@cat_cuisine, 'Évier', 3),
(@cat_cuisine, 'Électroménagers', 4),
(@cat_cuisine, 'Plancher', 5);

SET @sc_armoires = (SELECT id FROM sous_categories WHERE nom = 'Armoires' AND categorie_id = @cat_cuisine);
SET @sc_comptoir = (SELECT id FROM sous_categories WHERE nom = 'Comptoir' AND categorie_id = @cat_cuisine);
SET @sc_evier = (SELECT id FROM sous_categories WHERE nom = 'Évier' AND categorie_id = @cat_cuisine);
SET @sc_electros = (SELECT id FROM sous_categories WHERE nom = 'Électroménagers' AND categorie_id = @cat_cuisine);
SET @sc_plancher_cui = (SELECT id FROM sous_categories WHERE nom = 'Plancher' AND categorie_id = @cat_cuisine);

-- Matériaux Armoires
INSERT INTO materiaux (sous_categorie_id, nom, prix_defaut, ordre) VALUES
(@sc_armoires, 'Armoires cuisine complète (budget)', 3500.00, 1),
(@sc_armoires, 'Armoires cuisine complète (moyen)', 6000.00, 2),
(@sc_armoires, 'Armoires cuisine complète (haut)', 10000.00, 3),
(@sc_armoires, 'Refacing armoires', 2500.00, 4),
(@sc_armoires, 'Peinture armoires', 800.00, 5),
(@sc_armoires, 'Poignées/boutons (ensemble)', 150.00, 6),
(@sc_armoires, 'Pentures soft-close', 100.00, 7);

-- Matériaux Comptoir
INSERT INTO materiaux (sous_categorie_id, nom, prix_defaut, ordre) VALUES
(@sc_comptoir, 'Comptoir stratifié', 600.00, 1),
(@sc_comptoir, 'Comptoir quartz', 2500.00, 2),
(@sc_comptoir, 'Comptoir granit', 2000.00, 3),
(@sc_comptoir, 'Comptoir butcher block', 800.00, 4),
(@sc_comptoir, 'Dosseret céramique', 400.00, 5),
(@sc_comptoir, 'Dosseret mosaïque', 500.00, 6);

-- Matériaux Évier
INSERT INTO materiaux (sous_categorie_id, nom, prix_defaut, ordre) VALUES
(@sc_evier, 'Évier inox simple', 150.00, 1),
(@sc_evier, 'Évier inox double', 250.00, 2),
(@sc_evier, 'Évier granit composite', 350.00, 3),
(@sc_evier, 'Robinet cuisine standard', 150.00, 4),
(@sc_evier, 'Robinet cuisine col de cygne', 250.00, 5),
(@sc_evier, 'Robinet avec douchette', 200.00, 6),
(@sc_evier, 'Broyeur', 180.00, 7),
(@sc_evier, 'Distributeur savon', 25.00, 8);

-- Matériaux Électroménagers
INSERT INTO materiaux (sous_categorie_id, nom, prix_defaut, ordre) VALUES
(@sc_electros, 'Réfrigérateur', 1200.00, 1),
(@sc_electros, 'Cuisinière électrique', 800.00, 2),
(@sc_electros, 'Cuisinière gaz', 1000.00, 3),
(@sc_electros, 'Hotte de cuisine', 250.00, 4),
(@sc_electros, 'Hotte intégrée micro-ondes', 400.00, 5),
(@sc_electros, 'Lave-vaisselle', 600.00, 6),
(@sc_electros, 'Micro-ondes comptoir', 150.00, 7);

-- Matériaux Plancher Cuisine
INSERT INTO materiaux (sous_categorie_id, nom, prix_defaut, ordre) VALUES
(@sc_plancher_cui, 'Céramique', 500.00, 1),
(@sc_plancher_cui, 'Vinyle', 300.00, 2),
(@sc_plancher_cui, 'Plancher flottant', 400.00, 3);

-- ============================================
-- ÉLECTRICITÉ - Sous-catégories
-- ============================================
INSERT INTO sous_categories (categorie_id, nom, ordre) VALUES
(@cat_elec, 'Panneau', 1),
(@cat_elec, 'Filage', 2),
(@cat_elec, 'Prises/Interrupteurs', 3),
(@cat_elec, 'Luminaires', 4),
(@cat_elec, 'Divers électrique', 5);

SET @sc_panneau = (SELECT id FROM sous_categories WHERE nom = 'Panneau' AND categorie_id = @cat_elec);
SET @sc_filage = (SELECT id FROM sous_categories WHERE nom = 'Filage' AND categorie_id = @cat_elec);
SET @sc_prises = (SELECT id FROM sous_categories WHERE nom = 'Prises/Interrupteurs' AND categorie_id = @cat_elec);
SET @sc_luminaires = (SELECT id FROM sous_categories WHERE nom = 'Luminaires' AND categorie_id = @cat_elec);
SET @sc_divers_elec = (SELECT id FROM sous_categories WHERE nom = 'Divers électrique' AND categorie_id = @cat_elec);

-- Matériaux Panneau
INSERT INTO materiaux (sous_categorie_id, nom, prix_defaut, ordre) VALUES
(@sc_panneau, 'Changement panneau 100A', 1500.00, 1),
(@sc_panneau, 'Changement panneau 200A', 2500.00, 2),
(@sc_panneau, 'Mise à terre', 300.00, 3),
(@sc_panneau, 'Disjoncteur standard', 15.00, 4),
(@sc_panneau, 'Disjoncteur AFCI', 50.00, 5),
(@sc_panneau, 'Disjoncteur GFCI', 45.00, 6);

-- Matériaux Filage
INSERT INTO materiaux (sous_categorie_id, nom, prix_defaut, ordre) VALUES
(@sc_filage, 'Ajout circuit 15A', 250.00, 1),
(@sc_filage, 'Ajout circuit 20A', 300.00, 2),
(@sc_filage, 'Circuit 240V (sécheuse)', 400.00, 3),
(@sc_filage, 'Circuit 240V (cuisinière)', 450.00, 4),
(@sc_filage, 'Fil 14/2 (rouleau 75m)', 80.00, 5),
(@sc_filage, 'Fil 12/2 (rouleau 75m)', 120.00, 6);

-- Matériaux Prises/Interrupteurs
INSERT INTO materiaux (sous_categorie_id, nom, prix_defaut, ordre) VALUES
(@sc_prises, 'Prise standard', 3.00, 1),
(@sc_prises, 'Prise GFCI', 25.00, 2),
(@sc_prises, 'Prise USB', 25.00, 3),
(@sc_prises, 'Interrupteur simple', 3.00, 4),
(@sc_prises, 'Interrupteur 3-way', 8.00, 5),
(@sc_prises, 'Dimmer', 25.00, 6),
(@sc_prises, 'Plaque (par unité)', 2.00, 7);

-- Matériaux Luminaires
INSERT INTO materiaux (sous_categorie_id, nom, prix_defaut, ordre) VALUES
(@sc_luminaires, 'Plafonnier standard', 40.00, 1),
(@sc_luminaires, 'Plafonnier LED', 60.00, 2),
(@sc_luminaires, 'Spot encastré (pot light)', 25.00, 3),
(@sc_luminaires, 'Luminaire suspendu', 100.00, 4),
(@sc_luminaires, 'Lustre', 200.00, 5),
(@sc_luminaires, 'Applique murale', 60.00, 6),
(@sc_luminaires, 'Lumière extérieure', 50.00, 7);

-- Matériaux Divers électrique
INSERT INTO materiaux (sous_categorie_id, nom, prix_defaut, ordre) VALUES
(@sc_divers_elec, 'Détecteur de fumée', 30.00, 1),
(@sc_divers_elec, 'Détecteur CO', 35.00, 2),
(@sc_divers_elec, 'Détecteur combiné', 45.00, 3),
(@sc_divers_elec, 'Sonnette', 80.00, 4),
(@sc_divers_elec, 'Thermostat standard', 30.00, 5),
(@sc_divers_elec, 'Thermostat intelligent', 200.00, 6);

-- ============================================
-- PLOMBERIE - Sous-catégories
-- ============================================
INSERT INTO sous_categories (categorie_id, nom, ordre) VALUES
(@cat_plomb, 'Chauffe-eau', 1),
(@cat_plomb, 'Tuyauterie', 2),
(@cat_plomb, 'Buanderie', 3);

SET @sc_chauffe = (SELECT id FROM sous_categories WHERE nom = 'Chauffe-eau' AND categorie_id = @cat_plomb);
SET @sc_tuyau = (SELECT id FROM sous_categories WHERE nom = 'Tuyauterie' AND categorie_id = @cat_plomb);
SET @sc_buanderie = (SELECT id FROM sous_categories WHERE nom = 'Buanderie' AND categorie_id = @cat_plomb);

-- Matériaux Chauffe-eau
INSERT INTO materiaux (sous_categorie_id, nom, prix_defaut, ordre) VALUES
(@sc_chauffe, 'Chauffe-eau 40 gal électrique', 600.00, 1),
(@sc_chauffe, 'Chauffe-eau 60 gal électrique', 800.00, 2),
(@sc_chauffe, 'Chauffe-eau 40 gal gaz', 900.00, 3),
(@sc_chauffe, 'Chauffe-eau tankless électrique', 1200.00, 4),
(@sc_chauffe, 'Chauffe-eau tankless gaz', 2000.00, 5),
(@sc_chauffe, 'Installation chauffe-eau', 400.00, 6);

-- Matériaux Tuyauterie
INSERT INTO materiaux (sous_categorie_id, nom, prix_defaut, ordre) VALUES
(@sc_tuyau, 'Remplacement entrée d''eau', 800.00, 1),
(@sc_tuyau, 'Remplacement drain principal', 1500.00, 2),
(@sc_tuyau, 'Nouvelle ligne eau chaude/froide', 400.00, 3),
(@sc_tuyau, 'Nouveau drain', 300.00, 4),
(@sc_tuyau, 'Valve d''arrêt principale', 150.00, 5);

-- Matériaux Buanderie
INSERT INTO materiaux (sous_categorie_id, nom, prix_defaut, ordre) VALUES
(@sc_buanderie, 'Robinet buanderie', 60.00, 1),
(@sc_buanderie, 'Bac à laver', 150.00, 2),
(@sc_buanderie, 'Boîte d''alimentation laveuse', 50.00, 3),
(@sc_buanderie, 'Drain laveuse', 80.00, 4);

-- ============================================
-- PORTES ET FENÊTRES - Sous-catégories
-- ============================================
INSERT INTO sous_categories (categorie_id, nom, ordre) VALUES
(@cat_portes, 'Portes intérieures', 1),
(@cat_portes, 'Portes extérieures', 2),
(@cat_portes, 'Fenêtres', 3);

SET @sc_portes_int = (SELECT id FROM sous_categories WHERE nom = 'Portes intérieures' AND categorie_id = @cat_portes);
SET @sc_portes_ext = (SELECT id FROM sous_categories WHERE nom = 'Portes extérieures' AND categorie_id = @cat_portes);
SET @sc_fenetres = (SELECT id FROM sous_categories WHERE nom = 'Fenêtres' AND categorie_id = @cat_portes);

-- Matériaux Portes intérieures
INSERT INTO materiaux (sous_categorie_id, nom, prix_defaut, ordre) VALUES
(@sc_portes_int, 'Porte intérieure creuse', 60.00, 1),
(@sc_portes_int, 'Porte intérieure solide', 150.00, 2),
(@sc_portes_int, 'Porte pliante (garde-robe)', 80.00, 3),
(@sc_portes_int, 'Porte coulissante (grange)', 300.00, 4),
(@sc_portes_int, 'Poignée intérieure', 25.00, 5),
(@sc_portes_int, 'Charnières (3)', 15.00, 6),
(@sc_portes_int, 'Cadrage porte', 40.00, 7);

-- Matériaux Portes extérieures
INSERT INTO materiaux (sous_categorie_id, nom, prix_defaut, ordre) VALUES
(@sc_portes_ext, 'Porte entrée acier', 400.00, 1),
(@sc_portes_ext, 'Porte entrée fibre de verre', 600.00, 2),
(@sc_portes_ext, 'Porte patio coulissante', 800.00, 3),
(@sc_portes_ext, 'Porte patio française', 1200.00, 4),
(@sc_portes_ext, 'Porte garage simple', 800.00, 5),
(@sc_portes_ext, 'Porte garage double', 1400.00, 6),
(@sc_portes_ext, 'Ouvre-porte garage', 350.00, 7),
(@sc_portes_ext, 'Serrure entrée', 80.00, 8),
(@sc_portes_ext, 'Serrure intelligente', 250.00, 9);

-- Matériaux Fenêtres
INSERT INTO materiaux (sous_categorie_id, nom, prix_defaut, ordre) VALUES
(@sc_fenetres, 'Fenêtre simple (petite)', 250.00, 1),
(@sc_fenetres, 'Fenêtre simple (moyenne)', 350.00, 2),
(@sc_fenetres, 'Fenêtre simple (grande)', 500.00, 3),
(@sc_fenetres, 'Fenêtre coulissante sous-sol', 200.00, 4),
(@sc_fenetres, 'Puits de lumière', 600.00, 5);

-- ============================================
-- FINITION INTÉRIEURE - Sous-catégories
-- ============================================
INSERT INTO sous_categories (categorie_id, nom, ordre) VALUES
(@cat_finition, 'Murs', 1),
(@cat_finition, 'Planchers', 2),
(@cat_finition, 'Moulures', 3),
(@cat_finition, 'Escalier', 4);

SET @sc_murs = (SELECT id FROM sous_categories WHERE nom = 'Murs' AND categorie_id = @cat_finition);
SET @sc_planchers = (SELECT id FROM sous_categories WHERE nom = 'Planchers' AND categorie_id = @cat_finition);
SET @sc_moulures = (SELECT id FROM sous_categories WHERE nom = 'Moulures' AND categorie_id = @cat_finition);
SET @sc_escalier = (SELECT id FROM sous_categories WHERE nom = 'Escalier' AND categorie_id = @cat_finition);

-- Matériaux Murs
INSERT INTO materiaux (sous_categorie_id, nom, prix_defaut, ordre) VALUES
(@sc_murs, 'Gypse 4x8 (feuille)', 15.00, 1),
(@sc_murs, 'Tirage de joints (par pièce)', 200.00, 2),
(@sc_murs, 'Peinture (par pièce)', 150.00, 3),
(@sc_murs, 'Peinture maison complète', 2500.00, 4),
(@sc_murs, 'Papier peint (par mur)', 200.00, 5);

-- Matériaux Planchers
INSERT INTO materiaux (sous_categorie_id, nom, prix_defaut, ordre) VALUES
(@sc_planchers, 'Plancher flottant (par pi²)', 3.00, 1),
(@sc_planchers, 'Plancher bois franc (par pi²)', 8.00, 2),
(@sc_planchers, 'Sablage/vernis plancher', 1500.00, 3),
(@sc_planchers, 'Céramique (par pi²)', 5.00, 4),
(@sc_planchers, 'Vinyle (par pi²)', 2.00, 5),
(@sc_planchers, 'Tapis (par pi²)', 3.00, 6),
(@sc_planchers, 'Sous-plancher OSB (feuille)', 35.00, 7);

-- Matériaux Moulures
INSERT INTO materiaux (sous_categorie_id, nom, prix_defaut, ordre) VALUES
(@sc_moulures, 'Plinthe (par pi lin)', 2.00, 1),
(@sc_moulures, 'Quart-de-rond (par pi lin)', 1.00, 2),
(@sc_moulures, 'Cadrage (par porte/fenêtre)', 40.00, 3),
(@sc_moulures, 'Cimaise', 100.00, 4),
(@sc_moulures, 'Couronne (par pièce)', 150.00, 5);

-- Matériaux Escalier
INSERT INTO materiaux (sous_categorie_id, nom, prix_defaut, ordre) VALUES
(@sc_escalier, 'Marche escalier', 40.00, 1),
(@sc_escalier, 'Contremarche', 20.00, 2),
(@sc_escalier, 'Nez de marche', 15.00, 3),
(@sc_escalier, 'Main courante (par pi lin)', 10.00, 4),
(@sc_escalier, 'Balustre', 8.00, 5),
(@sc_escalier, 'Poteau départ', 80.00, 6),
(@sc_escalier, 'Recouvrement escalier complet', 800.00, 7);

-- ============================================
-- EXTÉRIEUR - Sous-catégories
-- ============================================
INSERT INTO sous_categories (categorie_id, nom, ordre) VALUES
(@cat_exterieur, 'Toiture', 1),
(@cat_exterieur, 'Revêtement', 2),
(@cat_exterieur, 'Gouttières', 3),
(@cat_exterieur, 'Balcon/Terrasse', 4),
(@cat_exterieur, 'Entrée/Stationnement', 5),
(@cat_exterieur, 'Aménagement paysager', 6);

SET @sc_toiture = (SELECT id FROM sous_categories WHERE nom = 'Toiture' AND categorie_id = @cat_exterieur);
SET @sc_revetement = (SELECT id FROM sous_categories WHERE nom = 'Revêtement' AND categorie_id = @cat_exterieur);
SET @sc_gouttieres = (SELECT id FROM sous_categories WHERE nom = 'Gouttières' AND categorie_id = @cat_exterieur);
SET @sc_balcon = (SELECT id FROM sous_categories WHERE nom = 'Balcon/Terrasse' AND categorie_id = @cat_exterieur);
SET @sc_entree = (SELECT id FROM sous_categories WHERE nom = 'Entrée/Stationnement' AND categorie_id = @cat_exterieur);
SET @sc_amenagement = (SELECT id FROM sous_categories WHERE nom = 'Aménagement paysager' AND categorie_id = @cat_exterieur);

-- Matériaux Toiture
INSERT INTO materiaux (sous_categorie_id, nom, prix_defaut, ordre) VALUES
(@sc_toiture, 'Toiture bardeau (par pi²)', 5.00, 1),
(@sc_toiture, 'Toiture complète (bungalow)', 8000.00, 2),
(@sc_toiture, 'Toiture complète (2 étages)', 12000.00, 3),
(@sc_toiture, 'Réparation toiture', 500.00, 4),
(@sc_toiture, 'Solin', 150.00, 5),
(@sc_toiture, 'Évent de plomberie', 50.00, 6),
(@sc_toiture, 'Évent maximum', 80.00, 7);

-- Matériaux Revêtement
INSERT INTO materiaux (sous_categorie_id, nom, prix_defaut, ordre) VALUES
(@sc_revetement, 'Vinyle (par pi²)', 4.00, 1),
(@sc_revetement, 'Canexel (par pi²)', 6.00, 2),
(@sc_revetement, 'Aluminium (par pi²)', 5.00, 3),
(@sc_revetement, 'Brique (par pi²)', 15.00, 4),
(@sc_revetement, 'Revêtement complet', 15000.00, 5);

-- Matériaux Gouttières
INSERT INTO materiaux (sous_categorie_id, nom, prix_defaut, ordre) VALUES
(@sc_gouttieres, 'Gouttière aluminium (par pi lin)', 8.00, 1),
(@sc_gouttieres, 'Descente pluviale', 50.00, 2),
(@sc_gouttieres, 'Gouttières complètes', 1200.00, 3);

-- Matériaux Balcon/Terrasse
INSERT INTO materiaux (sous_categorie_id, nom, prix_defaut, ordre) VALUES
(@sc_balcon, 'Balcon bois traité', 2500.00, 1),
(@sc_balcon, 'Balcon composite', 5000.00, 2),
(@sc_balcon, 'Rampe aluminium (par pi lin)', 80.00, 3),
(@sc_balcon, 'Rampe verre', 200.00, 4),
(@sc_balcon, 'Escalier extérieur', 800.00, 5),
(@sc_balcon, 'Patio pavé (par pi²)', 15.00, 6);

-- Matériaux Entrée/Stationnement
INSERT INTO materiaux (sous_categorie_id, nom, prix_defaut, ordre) VALUES
(@sc_entree, 'Asphalte stationnement', 3500.00, 1),
(@sc_entree, 'Pavé uni entrée', 6000.00, 2),
(@sc_entree, 'Réparation asphalte', 500.00, 3),
(@sc_entree, 'Scellant asphalte', 200.00, 4);

-- Matériaux Aménagement paysager
INSERT INTO materiaux (sous_categorie_id, nom, prix_defaut, ordre) VALUES
(@sc_amenagement, 'Gazon (pose)', 1500.00, 1),
(@sc_amenagement, 'Semence gazon', 200.00, 2),
(@sc_amenagement, 'Haie de cèdres', 800.00, 3),
(@sc_amenagement, 'Arbre', 300.00, 4),
(@sc_amenagement, 'Plate-bande', 400.00, 5),
(@sc_amenagement, 'Muret', 1000.00, 6),
(@sc_amenagement, 'Clôture bois (par pi lin)', 40.00, 7),
(@sc_amenagement, 'Clôture maille (par pi lin)', 20.00, 8);

-- ============================================
-- STRUCTURE - Sous-catégories
-- ============================================
INSERT INTO sous_categories (categorie_id, nom, ordre) VALUES
(@cat_structure, 'Fondation', 1),
(@cat_structure, 'Charpente', 2),
(@cat_structure, 'Isolation', 3);

SET @sc_fondation = (SELECT id FROM sous_categories WHERE nom = 'Fondation' AND categorie_id = @cat_structure);
SET @sc_charpente = (SELECT id FROM sous_categories WHERE nom = 'Charpente' AND categorie_id = @cat_structure);
SET @sc_isolation = (SELECT id FROM sous_categories WHERE nom = 'Isolation' AND categorie_id = @cat_structure);

-- Matériaux Fondation
INSERT INTO materiaux (sous_categorie_id, nom, prix_defaut, ordre) VALUES
(@sc_fondation, 'Réparation fissure (injection)', 500.00, 1),
(@sc_fondation, 'Réparation fissure (extérieur)', 2500.00, 2),
(@sc_fondation, 'Drain français', 8000.00, 3),
(@sc_fondation, 'Imperméabilisation', 5000.00, 4),
(@sc_fondation, 'Pompe puisard', 400.00, 5);

-- Matériaux Charpente
INSERT INTO materiaux (sous_categorie_id, nom, prix_defaut, ordre) VALUES
(@sc_charpente, 'Poutre LVL', 300.00, 1),
(@sc_charpente, 'Colonne ajustable', 80.00, 2),
(@sc_charpente, 'Renforcement solive', 500.00, 3),
(@sc_charpente, 'Ouverture mur porteur', 1500.00, 4);

-- Matériaux Isolation
INSERT INTO materiaux (sous_categorie_id, nom, prix_defaut, ordre) VALUES
(@sc_isolation, 'Isolation laine R20 (par pi²)', 1.00, 1),
(@sc_isolation, 'Isolation laine R40 (par pi²)', 2.00, 2),
(@sc_isolation, 'Isolation uréthane (par pi²)', 4.00, 3),
(@sc_isolation, 'Isolation sous-sol complet', 3000.00, 4),
(@sc_isolation, 'Isolation entretoit', 2000.00, 5),
(@sc_isolation, 'Pare-vapeur', 200.00, 6);

-- ============================================
-- DIVERS - Sous-catégories
-- ============================================
INSERT INTO sous_categories (categorie_id, nom, ordre) VALUES
(@cat_divers, 'Permis', 1),
(@cat_divers, 'Location équipement', 2),
(@cat_divers, 'Nettoyage', 3);

SET @sc_permis = (SELECT id FROM sous_categories WHERE nom = 'Permis' AND categorie_id = @cat_divers);
SET @sc_location = (SELECT id FROM sous_categories WHERE nom = 'Location équipement' AND categorie_id = @cat_divers);
SET @sc_nettoyage = (SELECT id FROM sous_categories WHERE nom = 'Nettoyage' AND categorie_id = @cat_divers);

-- Matériaux Permis
INSERT INTO materiaux (sous_categorie_id, nom, prix_defaut, ordre) VALUES
(@sc_permis, 'Permis rénovation', 300.00, 1),
(@sc_permis, 'Permis construction', 500.00, 2),
(@sc_permis, 'Permis plomberie', 150.00, 3),
(@sc_permis, 'Permis électricité', 150.00, 4);

-- Matériaux Location équipement
INSERT INTO materiaux (sous_categorie_id, nom, prix_defaut, ordre) VALUES
(@sc_location, 'Conteneur déchets (petit)', 400.00, 1),
(@sc_location, 'Conteneur déchets (gros)', 600.00, 2),
(@sc_location, 'Location lift', 300.00, 3),
(@sc_location, 'Location échafaud', 200.00, 4);

-- Matériaux Nettoyage
INSERT INTO materiaux (sous_categorie_id, nom, prix_defaut, ordre) VALUES
(@sc_nettoyage, 'Nettoyage fin chantier', 500.00, 1),
(@sc_nettoyage, 'Nettoyage conduits ventilation', 400.00, 2);
