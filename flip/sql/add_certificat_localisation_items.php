<?php
/**
 * Migration: Ajouter les items de vérification de certificat de localisation
 * Exécuter une seule fois
 */

require_once __DIR__ . '/../config.php';

try {
    $pdo = getConnection();

    // Trouver le template "Verification de certificat localisation"
    $stmt = $pdo->prepare("SELECT id FROM checklist_templates WHERE nom LIKE '%certificat%localisation%' OR nom LIKE '%Certificat%Localisation%' LIMIT 1");
    $stmt->execute();
    $template = $stmt->fetch();

    if (!$template) {
        // Créer le template s'il n'existe pas
        $stmt = $pdo->prepare("INSERT INTO checklist_templates (nom, description, ordre, actif) VALUES (?, ?, ?, 1)");
        $stmt->execute(['Vérification Certificat de Localisation', 'Vérifications à faire avant et pendant l\'analyse du certificat de localisation', 1]);
        $templateId = $pdo->lastInsertId();
        echo "Template créé avec ID: $templateId\n";
    } else {
        $templateId = $template['id'];
        echo "Template trouvé avec ID: $templateId\n";

        // Supprimer les anciens items pour les remplacer
        $stmt = $pdo->prepare("DELETE FROM checklist_template_items WHERE template_id = ?");
        $stmt->execute([$templateId]);
        echo "Anciens items supprimés\n";
    }

    // Liste des items à ajouter
    $items = [
        // AVANT DE FAIRE LE CERTIFICAT
        ['nom' => '── AVANT DE FAIRE LE CERTIFICAT ──', 'ordre' => 1],
        ['nom' => 'Y a-t-il un certificat de localisation récent (< 10 ans)?', 'ordre' => 2],
        ['nom' => 'Si non, qui paie pour en faire un nouveau?', 'ordre' => 3],
        ['nom' => 'Y a-t-il eu des travaux depuis le dernier certificat? (agrandissement, piscine, cabanon, clôture)', 'ordre' => 4],

        // CONFORMITÉ AU ZONAGE
        ['nom' => '── CONFORMITÉ AU ZONAGE ──', 'ordre' => 10],
        ['nom' => 'La maison respecte-t-elle les marges avant?', 'ordre' => 11],
        ['nom' => 'La maison respecte-t-elle les marges arrière?', 'ordre' => 12],
        ['nom' => 'La maison respecte-t-elle les marges latérales?', 'ordre' => 13],
        ['nom' => 'Les bâtiments accessoires (remise, cabanon) sont-ils conformes?', 'ordre' => 14],

        // EMPIÈTEMENTS
        ['nom' => '── EMPIÈTEMENTS ──', 'ordre' => 20],
        ['nom' => 'Y a-t-il des empiètements sur les terrains voisins?', 'ordre' => 21],
        ['nom' => 'Y a-t-il des voisins qui empiètent sur le terrain?', 'ordre' => 22],
        ['nom' => 'Depuis combien de temps la situation existe?', 'ordre' => 23],

        // SERVITUDES
        ['nom' => '── SERVITUDES ──', 'ordre' => 30],
        ['nom' => 'Y a-t-il des servitudes sur le terrain?', 'ordre' => 31],
        ['nom' => 'Si oui, en faveur de qui? (Hydro, Bell, voisin, etc.)', 'ordre' => 32],
        ['nom' => 'Quelle portion du terrain est affectée?', 'ordre' => 33],
        ['nom' => 'Y a-t-il des constructions dans la servitude?', 'ordre' => 34],

        // ZONES PARTICULIÈRES
        ['nom' => '── ZONES PARTICULIÈRES ──', 'ordre' => 40],
        ['nom' => 'Le terrain est-il en zone inondable?', 'ordre' => 41],
        ['nom' => 'Le terrain est-il en zone agricole?', 'ordre' => 42],
        ['nom' => 'Le terrain est-il dans une zone patrimoniale?', 'ordre' => 43],
        ['nom' => 'Le terrain est-il près d\'un aéroport?', 'ordre' => 44],

        // QUESTIONS DE SUIVI SI PROBLÈMES
        ['nom' => '── QUESTIONS DE SUIVI SI PROBLÈMES ──', 'ordre' => 50],
        ['nom' => 'Y a-t-il eu des plaintes de voisins?', 'ordre' => 51],
        ['nom' => 'Y a-t-il eu des avis de la ville?', 'ordre' => 52],
        ['nom' => 'Existe-t-il des documents prouvant la conformité à l\'époque?', 'ordre' => 53],

        // POUR UN FLIP - QUESTIONS CLÉS
        ['nom' => '── POUR UN FLIP - QUESTIONS CLÉS ──', 'ordre' => 60],
        ['nom' => 'Mes rénovations touchent-elles quelque chose de non conforme?', 'ordre' => 61],
        ['nom' => 'Ai-je besoin d\'agrandir du côté problématique?', 'ordre' => 62],
        ['nom' => 'Les non-conformités vont-elles affecter la revente?', 'ordre' => 63],
    ];

    // Insérer les items
    $stmt = $pdo->prepare("INSERT INTO checklist_template_items (template_id, nom, ordre) VALUES (?, ?, ?)");

    foreach ($items as $item) {
        $stmt->execute([$templateId, $item['nom'], $item['ordre']]);
    }

    echo "✓ " . count($items) . " items ajoutés avec succès!\n";

} catch (Exception $e) {
    echo "Erreur: " . $e->getMessage() . "\n";
}
