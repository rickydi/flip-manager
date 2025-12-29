<?php
/**
 * Module Construction - Plan Électrique
 * Générateur de plans électriques détaillés par pièce
 */

// S'assurer que les dépendances sont chargées
if (!isset($pdo)) {
    require_once __DIR__ . '/../../../config.php';
}
if (!function_exists('e')) {
    require_once __DIR__ . '/../../../includes/functions.php';
}

// ============================================
// AUTO-MIGRATION: Tables pour plans électriques
// ============================================

// Table des plans électriques (un par projet)
try {
    $pdo->query("SELECT 1 FROM electrical_plans LIMIT 1");
} catch (Exception $e) {
    $pdo->exec("
        CREATE TABLE electrical_plans (
            id INT AUTO_INCREMENT PRIMARY KEY,
            projet_id INT NOT NULL UNIQUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_projet (projet_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

// Table des étages
try {
    $pdo->query("SELECT 1 FROM electrical_floors LIMIT 1");
} catch (Exception $e) {
    $pdo->exec("
        CREATE TABLE electrical_floors (
            id INT AUTO_INCREMENT PRIMARY KEY,
            plan_id INT NOT NULL,
            nom VARCHAR(100) NOT NULL,
            ordre INT DEFAULT 0,
            INDEX idx_plan (plan_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

// Table des pièces
try {
    $pdo->query("SELECT 1 FROM electrical_rooms LIMIT 1");
} catch (Exception $e) {
    $pdo->exec("
        CREATE TABLE electrical_rooms (
            id INT AUTO_INCREMENT PRIMARY KEY,
            floor_id INT NOT NULL,
            nom VARCHAR(100) NOT NULL,
            type VARCHAR(50) DEFAULT 'custom',
            ordre INT DEFAULT 0,
            INDEX idx_floor (floor_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

// Table des composants par pièce
try {
    $pdo->query("SELECT 1 FROM electrical_components LIMIT 1");
} catch (Exception $e) {
    $pdo->exec("
        CREATE TABLE electrical_components (
            id INT AUTO_INCREMENT PRIMARY KEY,
            room_id INT NOT NULL,
            nom VARCHAR(255) NOT NULL,
            quantite INT DEFAULT 1,
            wattage VARCHAR(50) DEFAULT NULL,
            notes TEXT DEFAULT NULL,
            ordre INT DEFAULT 0,
            INDEX idx_room (room_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

// ============================================
// TEMPLATES DE PIÈCES
// ============================================

$roomTemplates = [
    'chambre' => [
        'label' => 'Chambre',
        'icon' => 'bi-door-closed',
        'components' => [
            ['nom' => 'Prise murale', 'quantite' => 2],
            ['nom' => 'Lumière au plafond plafonnier', 'quantite' => 1],
            ['nom' => 'Interrupteur plafonnier', 'quantite' => 1],
            ['nom' => 'Thermostat', 'wattage' => '2000w'],
            ['nom' => 'Plinthe chauffante', 'wattage' => '1500w'],
        ]
    ],
    'chambre_garde_robe' => [
        'label' => 'Chambre avec garde-robe',
        'icon' => 'bi-door-closed',
        'components' => [
            ['nom' => 'Prise murale', 'quantite' => 2],
            ['nom' => 'Lumière garde-robe', 'quantite' => 1],
            ['nom' => 'Interrupteur garde-robe', 'quantite' => 1],
            ['nom' => 'Lumière au plafond plafonnier', 'quantite' => 1],
            ['nom' => 'Interrupteur plafonnier', 'quantite' => 1],
            ['nom' => 'Thermostat', 'wattage' => '2000w'],
            ['nom' => 'Plinthe chauffante', 'wattage' => '1500w'],
        ]
    ],
    'sdb' => [
        'label' => 'Salle de bain',
        'icon' => 'bi-droplet',
        'components' => [
            ['nom' => 'Plancher chauffant 240v', 'quantite' => 1],
            ['nom' => 'Thermostat plancher', 'quantite' => 1],
            ['nom' => 'Interrupteur puck light', 'quantite' => 1],
            ['nom' => 'Interrupteur lumière intime et miroir', 'quantite' => 1],
            ['nom' => 'Prise GFI toilette', 'quantite' => 1],
            ['nom' => 'Prise vanité', 'quantite' => 1],
            ['nom' => 'Prise ventilateur', 'quantite' => 1],
        ]
    ],
    'sdb_plinthe' => [
        'label' => 'Salle de bain avec plinthe',
        'icon' => 'bi-droplet',
        'components' => [
            ['nom' => 'Plinthe chauffante', 'wattage' => '750w'],
            ['nom' => 'Thermostat', 'wattage' => '2000w'],
            ['nom' => 'Interrupteur puck light', 'quantite' => 1],
            ['nom' => 'Interrupteur lumière intime et miroir', 'quantite' => 1],
            ['nom' => 'Prise GFI toilette', 'quantite' => 1],
            ['nom' => 'Prise vanité', 'quantite' => 1],
            ['nom' => 'Prise ventilateur', 'quantite' => 1],
        ]
    ],
    'cuisine' => [
        'label' => 'Cuisine',
        'icon' => 'bi-cup-hot',
        'components' => [
            ['nom' => 'Interrupteur LED carré', 'quantite' => 1],
            ['nom' => 'Interrupteur lumière îlot', 'quantite' => 1],
            ['nom' => 'Interrupteur lumière table', 'quantite' => 1],
            ['nom' => 'Interrupteur LED sous cabinet', 'quantite' => 1],
            ['nom' => 'Transfo LED sous armoire', 'quantite' => 1],
            ['nom' => 'Prise lave-vaisselle', 'quantite' => 1],
            ['nom' => 'Prise frigo', 'quantite' => 1],
            ['nom' => 'Prise four encastré', 'quantite' => 1],
            ['nom' => 'Prise plaque de cuisson', 'quantite' => 1],
            ['nom' => 'Thermostat', 'wattage' => '2000w'],
            ['nom' => 'Plinthe chauffante', 'wattage' => '2000w'],
            ['nom' => 'Lumière ext et interrupteur', 'quantite' => 1],
            ['nom' => 'Prise ext GFI', 'quantite' => 1],
        ]
    ],
    'salon' => [
        'label' => 'Salon',
        'icon' => 'bi-lamp',
        'components' => [
            ['nom' => 'Prise murale', 'quantite' => 3],
            ['nom' => 'Lumière entrée', 'quantite' => 1],
            ['nom' => 'Interrupteur puck light entrée', 'quantite' => 1],
            ['nom' => 'Interrupteur puck light salon', 'quantite' => 4],
            ['nom' => 'Thermostat', 'wattage' => '2000w'],
            ['nom' => 'Plinthe chauffante', 'wattage' => '2000w'],
            ['nom' => 'Interrupteur lumière ext', 'quantite' => 1],
        ]
    ],
    'couloir' => [
        'label' => 'Couloir',
        'icon' => 'bi-arrows-expand',
        'components' => [
            ['nom' => 'Interrupteur puck light', 'quantite' => 1],
            ['nom' => 'Puck light', 'quantite' => 2],
        ]
    ],
    'escalier' => [
        'label' => 'Escalier',
        'icon' => 'bi-stairs',
        'components' => [
            ['nom' => 'Lumière puck light', 'quantite' => 2],
            ['nom' => 'Interrupteur puck light', 'quantite' => 1],
            ['nom' => 'Prise transfo LED détecteur mouvement', 'quantite' => 1],
        ]
    ],
    'buanderie' => [
        'label' => 'Buanderie',
        'icon' => 'bi-water',
        'components' => [
            ['nom' => 'Prise sécheuse', 'quantite' => 1],
            ['nom' => 'Prise laveuse', 'quantite' => 1],
            ['nom' => 'Interrupteur puck light', 'quantite' => 1],
            ['nom' => 'Puck light', 'quantite' => 2],
            ['nom' => 'Réservoir eau chaude', 'quantite' => 1],
            ['nom' => 'Prise aspirateur central', 'quantite' => 1],
            ['nom' => 'Thermostat', 'wattage' => '500w'],
            ['nom' => 'Plinthe chauffante', 'wattage' => '500w'],
        ]
    ],
    'bureau' => [
        'label' => 'Bureau',
        'icon' => 'bi-briefcase',
        'components' => [
            ['nom' => 'Prise murale', 'quantite' => 3],
            ['nom' => 'Lumière au plafond plafonnier', 'quantite' => 2],
            ['nom' => 'Interrupteur plafonnier', 'quantite' => 1],
            ['nom' => 'Thermostat', 'wattage' => '2000w'],
            ['nom' => 'Plinthe chauffante', 'wattage' => '1500w'],
        ]
    ],
    'sejour' => [
        'label' => 'Salle de séjour',
        'icon' => 'bi-tv',
        'components' => [
            ['nom' => 'Prise murale', 'quantite' => 3],
            ['nom' => 'Puck light', 'quantite' => 6],
            ['nom' => 'Interrupteur puck light', 'quantite' => 1],
            ['nom' => 'Thermostat', 'wattage' => '2000w'],
            ['nom' => 'Plinthe chauffante', 'wattage' => '2000w'],
        ]
    ],
    'custom' => [
        'label' => 'Pièce personnalisée',
        'icon' => 'bi-plus-square',
        'components' => []
    ]
];

// ============================================
// CHARGER LE PLAN DU PROJET
// ============================================

$planId = null;
$floors = [];

if (isset($projetId)) {
    // Récupérer ou créer le plan
    $stmt = $pdo->prepare("SELECT id FROM electrical_plans WHERE projet_id = ?");
    $stmt->execute([$projetId]);
    $plan = $stmt->fetch();

    if ($plan) {
        $planId = $plan['id'];
    }

    // Charger les étages et pièces
    if ($planId) {
        $stmt = $pdo->prepare("
            SELECT f.*,
                   r.id as room_id, r.nom as room_nom, r.type as room_type, r.ordre as room_ordre
            FROM electrical_floors f
            LEFT JOIN electrical_rooms r ON r.floor_id = f.id
            WHERE f.plan_id = ?
            ORDER BY f.ordre, r.ordre
        ");
        $stmt->execute([$planId]);
        $rows = $stmt->fetchAll();

        foreach ($rows as $row) {
            if (!isset($floors[$row['id']])) {
                $floors[$row['id']] = [
                    'id' => $row['id'],
                    'nom' => $row['nom'],
                    'ordre' => $row['ordre'],
                    'rooms' => []
                ];
            }
            if ($row['room_id']) {
                // Charger les composants de la pièce
                $stmtComp = $pdo->prepare("SELECT * FROM electrical_components WHERE room_id = ? ORDER BY ordre");
                $stmtComp->execute([$row['room_id']]);
                $components = $stmtComp->fetchAll();

                $floors[$row['id']]['rooms'][] = [
                    'id' => $row['room_id'],
                    'nom' => $row['room_nom'],
                    'type' => $row['room_type'],
                    'ordre' => $row['room_ordre'],
                    'components' => $components
                ];
            }
        }
        $floors = array_values($floors);
    }
}
?>

<div class="electrical-plan-container">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="mb-0"><i class="bi bi-lightning-charge me-2"></i>Plan Électrique Détaillé</h5>
        <div class="btn-group">
            <button type="button" class="btn btn-outline-primary btn-sm" onclick="addFloor()">
                <i class="bi bi-plus-lg me-1"></i>Ajouter étage
            </button>
            <button type="button" class="btn btn-outline-success btn-sm" onclick="printElectricalPlan()">
                <i class="bi bi-printer me-1"></i>Imprimer
            </button>
        </div>
    </div>

    <div id="electrical-floors">
        <?php if (empty($floors)): ?>
            <div class="text-center text-muted py-5" id="electrical-empty">
                <i class="bi bi-lightning" style="font-size: 3rem;"></i>
                <p class="mt-3 mb-2">Aucun plan électrique</p>
                <p class="small">Commencez par ajouter un étage (RDC, Sous-sol, etc.)</p>
                <button type="button" class="btn btn-primary mt-2" onclick="addFloor()">
                    <i class="bi bi-plus-lg me-1"></i>Ajouter un étage
                </button>
            </div>
        <?php else: ?>
            <?php foreach ($floors as $floor): ?>
                <?php renderFloor($floor, $roomTemplates); ?>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Modal Ajouter Étage -->
<div class="modal fade" id="addFloorModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Ajouter un étage</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Nom de l'étage</label>
                    <input type="text" class="form-control" id="floor-name" placeholder="Ex: RDC, Sous-sol, Étage">
                </div>
                <div class="d-grid gap-2">
                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="setFloorName('RDC')">RDC</button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="setFloorName('Sous-sol')">Sous-sol</button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="setFloorName('Étage')">Étage</button>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                <button type="button" class="btn btn-primary" onclick="saveFloor()">Ajouter</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal Ajouter Pièce -->
<div class="modal fade" id="addRoomModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Ajouter une pièce</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="room-floor-id">
                <div class="mb-3">
                    <label class="form-label">Type (template)</label>
                    <div class="row g-2">
                        <?php foreach ($roomTemplates as $key => $template): ?>
                        <div class="col-6 col-md-4">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="room-type" id="room-type-<?= $key ?>" value="<?= $key ?>" onchange="updateRoomNamePlaceholder()">
                                <label class="form-check-label" for="room-type-<?= $key ?>">
                                    <i class="bi <?= $template['icon'] ?> me-1"></i><?= $template['label'] ?>
                                </label>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Nom personnalisé <small class="text-muted">(optionnel)</small></label>
                    <input type="text" class="form-control" id="room-name" placeholder="Auto: Chambre 1, Chambre 2...">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                <button type="button" class="btn btn-primary" onclick="saveRoom()">Ajouter</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal Ajouter Composant -->
<div class="modal fade" id="addComponentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Ajouter composant</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="component-room-id">
                <div class="mb-3">
                    <label class="form-label">Sélection rapide</label>
                    <select class="form-select" id="component-preset" onchange="applyComponentPreset()">
                        <option value="">-- Choisir un composant --</option>
                        <optgroup label="Prises">
                            <option value="Prise murale|1|">Prise murale</option>
                            <option value="Prise GFI|1|">Prise GFI</option>
                            <option value="Prise ext GFI|1|">Prise ext GFI</option>
                            <option value="Prise vanité|1|">Prise vanité</option>
                            <option value="Prise ventilateur|1|">Prise ventilateur</option>
                            <option value="Prise lave-vaisselle|1|">Prise lave-vaisselle</option>
                            <option value="Prise frigo|1|">Prise frigo</option>
                            <option value="Prise four encastré|1|">Prise four encastré</option>
                            <option value="Prise plaque de cuisson|1|">Prise plaque de cuisson</option>
                            <option value="Prise sécheuse|1|">Prise sécheuse</option>
                            <option value="Prise laveuse|1|">Prise laveuse</option>
                            <option value="Prise aspirateur central|1|">Prise aspirateur central</option>
                        </optgroup>
                        <optgroup label="Lumières">
                            <option value="Lumière au plafond plafonnier|1|">Plafonnier</option>
                            <option value="Puck light|1|">Puck light</option>
                            <option value="Lumière garde-robe|1|">Lumière garde-robe</option>
                            <option value="Lumière entrée|1|">Lumière entrée</option>
                            <option value="LED sous cabinet|1|">LED sous cabinet</option>
                            <option value="Lumière ext|1|">Lumière ext</option>
                        </optgroup>
                        <optgroup label="Interrupteurs">
                            <option value="Interrupteur plafonnier|1|">Interrupteur plafonnier</option>
                            <option value="Interrupteur puck light|1|">Interrupteur puck light</option>
                            <option value="Interrupteur garde-robe|1|">Interrupteur garde-robe</option>
                            <option value="Interrupteur LED carré|1|">Interrupteur LED carré</option>
                            <option value="Interrupteur lumière îlot|1|">Interrupteur lumière îlot</option>
                            <option value="Interrupteur lumière table|1|">Interrupteur lumière table</option>
                            <option value="Interrupteur lumière ext|1|">Interrupteur lumière ext</option>
                            <option value="Interrupteur lumière intime et miroir|1|">Interrupteur lumière/miroir</option>
                        </optgroup>
                        <optgroup label="Chauffage">
                            <option value="Thermostat|1|2000w">Thermostat 2000w</option>
                            <option value="Thermostat|1|500w">Thermostat 500w</option>
                            <option value="Thermostat plancher|1|">Thermostat plancher</option>
                            <option value="Plinthe chauffante|1|2000w">Plinthe 2000w</option>
                            <option value="Plinthe chauffante|1|1500w">Plinthe 1500w</option>
                            <option value="Plinthe chauffante|1|750w">Plinthe 750w</option>
                            <option value="Plinthe chauffante|1|500w">Plinthe 500w</option>
                            <option value="Plancher chauffant 240v|1|">Plancher chauffant 240v</option>
                        </optgroup>
                        <optgroup label="Autres">
                            <option value="Transfo LED sous armoire|1|">Transfo LED sous armoire</option>
                            <option value="Prise transfo LED détecteur mouvement|1|">Transfo LED détecteur mouvement</option>
                            <option value="Réservoir eau chaude|1|">Réservoir eau chaude</option>
                        </optgroup>
                    </select>
                </div>
                <hr class="my-2">
                <div class="mb-3">
                    <label class="form-label">Nom <small class="text-muted">(ou personnalisé)</small></label>
                    <input type="text" class="form-control" id="component-name" placeholder="Ex: Prise murale">
                </div>
                <div class="row">
                    <div class="col-6">
                        <div class="mb-3">
                            <label class="form-label">Quantité</label>
                            <input type="number" class="form-control" id="component-qty" value="1" min="1">
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="mb-3">
                            <label class="form-label">Wattage</label>
                            <input type="text" class="form-control" id="component-wattage" placeholder="Ex: 2000w">
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                <button type="button" class="btn btn-primary" onclick="saveComponent()">Ajouter</button>
            </div>
        </div>
    </div>
</div>

<style>
.electrical-plan-container {
    max-width: 100%;
}

.floor-card {
    border: 1px solid var(--border-color);
    border-radius: 8px;
    margin-bottom: 1rem;
    background: transparent;
}

.floor-header {
    background: rgba(13, 110, 253, 0.15);
    border-bottom: 1px solid var(--border-color);
    padding: 0.75rem 1rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-radius: 8px 8px 0 0;
}

.floor-header h6 {
    margin: 0;
    font-weight: 600;
}

.floor-body {
    padding: 1rem;
}

.room-card {
    border: 1px solid var(--border-color);
    border-radius: 6px;
    margin-bottom: 0.75rem;
    background: rgba(100, 116, 139, 0.08);
}

.room-header {
    padding: 0.5rem 0.75rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-bottom: 1px solid var(--border-color);
    background: rgba(100, 116, 139, 0.15);
    border-radius: 6px 6px 0 0;
}

.room-header h6 {
    margin: 0;
    font-size: 0.9rem;
}

.room-body {
    padding: 0.5rem 0.75rem;
    background: transparent;
}

.component-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.25rem 0;
    font-size: 0.85rem;
    border-bottom: 1px dashed var(--border-color);
}

.component-item:last-child {
    border-bottom: none;
}

.component-name {
    flex: 1;
}

.component-details {
    display: flex;
    gap: 0.5rem;
    align-items: center;
}

.component-qty, .component-wattage {
    font-size: 0.8rem;
    color: var(--text-muted);
}

.add-room-btn {
    border: 2px dashed var(--border-color);
    border-radius: 6px;
    padding: 1rem;
    text-align: center;
    cursor: pointer;
    color: var(--text-muted);
    transition: all 0.2s;
    background: transparent;
}

.add-room-btn:hover {
    border-color: var(--primary-color);
    color: var(--primary-color);
    background: rgba(13, 110, 253, 0.05);
}
</style>

<script>
const PROJET_ID = <?= $projetId ?? 'null' ?>;
const ROOM_TEMPLATES = <?= json_encode($roomTemplates) ?>;

function addFloor() {
    document.getElementById('floor-name').value = '';
    new bootstrap.Modal(document.getElementById('addFloorModal')).show();
}

function setFloorName(name) {
    document.getElementById('floor-name').value = name;
}

function saveFloor() {
    const nom = document.getElementById('floor-name').value.trim();
    if (!nom) {
        alert('Veuillez entrer un nom');
        return;
    }

    fetch('<?= url('/modules/construction/electrical/ajax.php') ?>', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            action: 'add_floor',
            projet_id: PROJET_ID,
            nom: nom
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert(data.error || 'Erreur');
        }
    });
}

function addRoom(floorId) {
    document.getElementById('room-floor-id').value = floorId;
    document.getElementById('room-name').value = '';
    document.querySelectorAll('input[name="room-type"]').forEach(r => r.checked = false);
    new bootstrap.Modal(document.getElementById('addRoomModal')).show();
}

function updateRoomNamePlaceholder() {
    const type = document.querySelector('input[name="room-type"]:checked')?.value;
    const label = ROOM_TEMPLATES[type]?.label || 'Pièce';
    document.getElementById('room-name').placeholder = `Auto: ${label} 1, ${label} 2...`;
}

function saveRoom() {
    const floorId = document.getElementById('room-floor-id').value;
    const nom = document.getElementById('room-name').value.trim();
    const type = document.querySelector('input[name="room-type"]:checked')?.value || 'custom';

    // Nom optionnel - sera généré automatiquement côté serveur
    fetch('<?= url('/modules/construction/electrical/ajax.php') ?>', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            action: 'add_room',
            floor_id: floorId,
            nom: nom,
            type: type
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert(data.error || 'Erreur');
        }
    });
}

function addComponent(roomId) {
    document.getElementById('component-room-id').value = roomId;
    document.getElementById('component-preset').value = '';
    document.getElementById('component-name').value = '';
    document.getElementById('component-qty').value = 1;
    document.getElementById('component-wattage').value = '';
    new bootstrap.Modal(document.getElementById('addComponentModal')).show();
}

function applyComponentPreset() {
    const preset = document.getElementById('component-preset').value;
    if (!preset) return;

    const [nom, qty, wattage] = preset.split('|');
    document.getElementById('component-name').value = nom || '';
    document.getElementById('component-qty').value = qty || 1;
    document.getElementById('component-wattage').value = wattage || '';
}

function saveComponent() {
    const roomId = document.getElementById('component-room-id').value;
    const nom = document.getElementById('component-name').value.trim();
    const quantite = parseInt(document.getElementById('component-qty').value) || 1;
    const wattage = document.getElementById('component-wattage').value.trim();

    if (!nom) {
        alert('Veuillez entrer un nom');
        return;
    }

    fetch('<?= url('/modules/construction/electrical/ajax.php') ?>', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            action: 'add_component',
            room_id: roomId,
            nom: nom,
            quantite: quantite,
            wattage: wattage
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert(data.error || 'Erreur');
        }
    });
}

function deleteFloor(floorId) {
    if (!confirm('Supprimer cet étage et toutes ses pièces?')) return;

    fetch('<?= url('/modules/construction/electrical/ajax.php') ?>', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            action: 'delete_floor',
            floor_id: floorId
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            location.reload();
        }
    });
}

function deleteRoom(roomId) {
    if (!confirm('Supprimer cette pièce?')) return;

    fetch('<?= url('/modules/construction/electrical/ajax.php') ?>', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            action: 'delete_room',
            room_id: roomId
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            location.reload();
        }
    });
}

function deleteComponent(componentId) {
    fetch('<?= url('/modules/construction/electrical/ajax.php') ?>', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            action: 'delete_component',
            component_id: componentId
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            document.querySelector(`[data-component-id="${componentId}"]`)?.remove();
        }
    });
}

function printElectricalPlan() {
    // Générer le HTML pour impression
    let html = `
    <!DOCTYPE html>
    <html>
    <head>
        <title>Plan Électrique</title>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body { font-family: Arial, sans-serif; font-size: 12px; padding: 20px; }
            h1 { font-size: 18px; margin-bottom: 5px; border-bottom: 2px solid #000; padding-bottom: 5px; }
            .date { font-size: 10px; color: #666; margin-bottom: 15px; }
            .floor { margin-bottom: 20px; page-break-inside: avoid; }
            .floor-title { font-size: 14px; font-weight: bold; background: #e0e0e0; padding: 5px 10px; margin-bottom: 10px; }
            .room { margin-bottom: 15px; margin-left: 10px; page-break-inside: avoid; }
            .room-title { font-size: 12px; font-weight: bold; border-bottom: 1px solid #ccc; padding-bottom: 3px; margin-bottom: 8px; }
            .component { display: flex; align-items: center; padding: 4px 0; border-bottom: 1px dotted #ddd; }
            .component:last-child { border-bottom: none; }
            .checkbox { width: 14px; height: 14px; border: 1px solid #333; margin-right: 10px; flex-shrink: 0; }
            .comp-name { flex: 1; }
            .comp-details { font-size: 11px; color: #666; margin-left: 10px; }
            @media print {
                body { padding: 10px; }
                .floor { page-break-inside: avoid; }
                .room { page-break-inside: avoid; }
            }
        </style>
    </head>
    <body>
        <h1>Plan Électrique Détaillé</h1>
        <div class="date">Généré le ${new Date().toLocaleDateString('fr-CA')} à ${new Date().toLocaleTimeString('fr-CA')}</div>
    `;

    document.querySelectorAll('.floor-card').forEach(floor => {
        const floorName = floor.querySelector('.floor-header h6').textContent.trim();
        html += `<div class="floor"><div class="floor-title">${floorName}</div>`;

        floor.querySelectorAll('.room-card').forEach(room => {
            const roomName = room.querySelector('.room-header h6').textContent.trim();
            html += `<div class="room"><div class="room-title">${roomName}</div>`;

            room.querySelectorAll('.component-item').forEach(comp => {
                const name = comp.querySelector('.component-name').textContent.trim();
                const qtyEl = comp.querySelector('.component-qty');
                const wattageEl = comp.querySelector('.component-wattage');
                const qty = qtyEl ? qtyEl.textContent.trim() : '';
                const wattage = wattageEl ? wattageEl.textContent.trim() : '';

                let details = '';
                if (qty) details += qty;
                if (wattage) details += (details ? ' • ' : '') + wattage;

                html += `<div class="component">
                    <div class="checkbox"></div>
                    <span class="comp-name">${name}</span>
                    ${details ? `<span class="comp-details">${details}</span>` : ''}
                </div>`;
            });

            html += `</div>`;
        });

        html += `</div>`;
    });

    html += `</body></html>`;

    // Ouvrir dans une nouvelle fenêtre et imprimer
    const printWindow = window.open('', '_blank');
    printWindow.document.write(html);
    printWindow.document.close();
    printWindow.onload = function() {
        printWindow.print();
    };
}
</script>

<?php
function renderFloor($floor, $roomTemplates) {
?>
<div class="floor-card" data-floor-id="<?= $floor['id'] ?>">
    <div class="floor-header">
        <h6><i class="bi bi-building me-2"></i><?= e($floor['nom']) ?></h6>
        <div class="btn-group btn-group-sm">
            <button type="button" class="btn btn-outline-primary btn-sm" onclick="addRoom(<?= $floor['id'] ?>)">
                <i class="bi bi-plus-lg"></i> Pièce
            </button>
            <button type="button" class="btn btn-outline-danger btn-sm" onclick="deleteFloor(<?= $floor['id'] ?>)">
                <i class="bi bi-trash"></i>
            </button>
        </div>
    </div>
    <div class="floor-body">
        <?php if (empty($floor['rooms'])): ?>
            <div class="add-room-btn" onclick="addRoom(<?= $floor['id'] ?>)">
                <i class="bi bi-plus-lg"></i> Ajouter une pièce
            </div>
        <?php else: ?>
            <?php foreach ($floor['rooms'] as $room): ?>
                <?php renderRoom($room); ?>
            <?php endforeach; ?>
            <div class="add-room-btn mt-2" onclick="addRoom(<?= $floor['id'] ?>)">
                <i class="bi bi-plus-lg"></i> Ajouter une pièce
            </div>
        <?php endif; ?>
    </div>
</div>
<?php
}

function renderRoom($room) {
?>
<div class="room-card" data-room-id="<?= $room['id'] ?>">
    <div class="room-header">
        <h6><?= e($room['nom']) ?></h6>
        <div class="btn-group btn-group-sm">
            <button type="button" class="btn btn-link btn-sm p-0 text-primary" onclick="addComponent(<?= $room['id'] ?>)" title="Ajouter composant">
                <i class="bi bi-plus-circle"></i>
            </button>
            <button type="button" class="btn btn-link btn-sm p-0 text-danger ms-2" onclick="deleteRoom(<?= $room['id'] ?>)" title="Supprimer">
                <i class="bi bi-trash"></i>
            </button>
        </div>
    </div>
    <div class="room-body">
        <?php if (empty($room['components'])): ?>
            <div class="text-muted small text-center py-2">Aucun composant</div>
        <?php else: ?>
            <?php foreach ($room['components'] as $comp): ?>
            <div class="component-item" data-component-id="<?= $comp['id'] ?>">
                <span class="component-name"><?= e($comp['nom']) ?></span>
                <span class="component-details">
                    <?php if ($comp['quantite'] > 1): ?>
                        <span class="component-qty badge bg-secondary"><?= $comp['quantite'] ?>x</span>
                    <?php endif; ?>
                    <?php if ($comp['wattage']): ?>
                        <span class="component-wattage badge bg-warning text-dark"><?= e($comp['wattage']) ?></span>
                    <?php endif; ?>
                    <button type="button" class="btn btn-link btn-sm p-0 text-danger" onclick="deleteComponent(<?= $comp['id'] ?>)">
                        <i class="bi bi-x"></i>
                    </button>
                </span>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
<?php
}
?>
