<?php
/**
 * Module Construction - Plan Électrique 2D
 * Éditeur de plans électriques avec Fabric.js
 */

if (!isset($pdo)) {
    require_once __DIR__ . '/../../../config.php';
}
if (!function_exists('e')) {
    require_once __DIR__ . '/../../../includes/functions.php';
}

// ============================================
// AUTO-MIGRATION: Tables pour dessins
// ============================================

try {
    $pdo->query("SELECT 1 FROM electrical_drawings LIMIT 1");
} catch (Exception $e) {
    $pdo->exec("
        CREATE TABLE electrical_drawings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            projet_id INT NOT NULL,
            nom VARCHAR(100) DEFAULT 'Plan principal',
            canvas_data LONGTEXT,
            width INT DEFAULT 1200,
            height INT DEFAULT 800,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_projet (projet_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

try {
    $pdo->query("SELECT 1 FROM electrical_circuits LIMIT 1");
} catch (Exception $e) {
    $pdo->exec("
        CREATE TABLE electrical_circuits (
            id INT AUTO_INCREMENT PRIMARY KEY,
            drawing_id INT NOT NULL,
            nom VARCHAR(100) NOT NULL,
            amperage INT DEFAULT 15,
            voltage INT DEFAULT 120,
            color VARCHAR(7) DEFAULT '#ff0000',
            ordre INT DEFAULT 0,
            INDEX idx_drawing (drawing_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

// ============================================
// CHARGER LE DESSIN DU PROJET
// ============================================

$drawing = null;
$circuits = [];

if (isset($projetId)) {
    $stmt = $pdo->prepare("SELECT * FROM electrical_drawings WHERE projet_id = ? LIMIT 1");
    $stmt->execute([$projetId]);
    $drawing = $stmt->fetch();

    if ($drawing) {
        $stmt = $pdo->prepare("SELECT * FROM electrical_circuits WHERE drawing_id = ? ORDER BY ordre");
        $stmt->execute([$drawing['id']]);
        $circuits = $stmt->fetchAll();
    }
}

// Circuits par défaut si aucun
if (empty($circuits)) {
    $circuits = [
        ['id' => 0, 'nom' => 'Circuit 1', 'amperage' => 15, 'voltage' => 120, 'color' => '#e74c3c'],
        ['id' => 0, 'nom' => 'Circuit 2', 'amperage' => 15, 'voltage' => 120, 'color' => '#3498db'],
        ['id' => 0, 'nom' => 'Circuit 3', 'amperage' => 20, 'voltage' => 240, 'color' => '#2ecc71'],
    ];
}
?>

<!-- Fabric.js CDN -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/fabric.js/5.3.1/fabric.min.js"></script>

<div class="electrical-drawing-container">
    <!-- Toolbar -->
    <div class="drawing-toolbar mb-2">
        <div class="btn-group btn-group-sm me-2">
            <button type="button" class="btn btn-outline-secondary active" data-tool="select" title="Sélection">
                <i class="bi bi-cursor"></i>
            </button>
            <button type="button" class="btn btn-outline-secondary" data-tool="wall" title="Dessiner mur">
                <i class="bi bi-border-style"></i>
            </button>
            <button type="button" class="btn btn-outline-secondary" data-tool="room" title="Dessiner pièce">
                <i class="bi bi-square"></i>
            </button>
            <button type="button" class="btn btn-outline-secondary" data-tool="wire" title="Dessiner fil">
                <i class="bi bi-bezier2"></i>
            </button>
            <button type="button" class="btn btn-outline-secondary" data-tool="text" title="Ajouter texte">
                <i class="bi bi-fonts"></i>
            </button>
        </div>
        <div class="btn-group btn-group-sm me-2">
            <button type="button" class="btn btn-outline-secondary" onclick="zoomIn()" title="Zoom +">
                <i class="bi bi-zoom-in"></i>
            </button>
            <button type="button" class="btn btn-outline-secondary" onclick="zoomOut()" title="Zoom -">
                <i class="bi bi-zoom-out"></i>
            </button>
            <button type="button" class="btn btn-outline-secondary" onclick="resetZoom()" title="Reset zoom">
                <i class="bi bi-fullscreen"></i>
            </button>
        </div>
        <div class="btn-group btn-group-sm me-2">
            <button type="button" class="btn btn-outline-secondary" onclick="undo()" title="Annuler">
                <i class="bi bi-arrow-counterclockwise"></i>
            </button>
            <button type="button" class="btn btn-outline-secondary" onclick="redo()" title="Refaire">
                <i class="bi bi-arrow-clockwise"></i>
            </button>
        </div>
        <div class="btn-group btn-group-sm me-2">
            <button type="button" class="btn btn-outline-secondary" onclick="deleteSelected()" title="Supprimer">
                <i class="bi bi-trash"></i>
            </button>
            <button type="button" class="btn btn-outline-secondary" onclick="duplicateSelected()" title="Dupliquer">
                <i class="bi bi-copy"></i>
            </button>
        </div>
        <div class="btn-group btn-group-sm me-2">
            <button type="button" class="btn btn-outline-secondary" onclick="toggleGrid()" title="Grille">
                <i class="bi bi-grid-3x3"></i>
            </button>
            <button type="button" class="btn btn-outline-secondary" onclick="toggleSnap()" title="Snap" id="snapBtn">
                <i class="bi bi-magnet"></i>
            </button>
        </div>
        <div class="btn-group btn-group-sm">
            <button type="button" class="btn btn-primary" onclick="saveDrawing()">
                <i class="bi bi-save me-1"></i>Sauvegarder
            </button>
            <button type="button" class="btn btn-success" onclick="printDrawing()">
                <i class="bi bi-printer me-1"></i>Imprimer
            </button>
        </div>
    </div>

    <div class="drawing-workspace">
        <!-- Palette de symboles -->
        <div class="symbol-palette">
            <h6 class="palette-title">Symboles</h6>

            <div class="palette-section">
                <div class="palette-section-title" onclick="togglePaletteSection(this)">
                    <i class="bi bi-plug me-1"></i>Prises
                    <i class="bi bi-chevron-down float-end"></i>
                </div>
                <div class="palette-items">
                    <div class="palette-item" data-symbol="outlet" draggable="true" title="Prise murale">
                        <svg viewBox="0 0 40 40"><circle cx="20" cy="20" r="15" fill="none" stroke="currentColor" stroke-width="2"/><circle cx="14" cy="17" r="2" fill="currentColor"/><circle cx="26" cy="17" r="2" fill="currentColor"/><line x1="20" y1="23" x2="20" y2="28" stroke="currentColor" stroke-width="2"/></svg>
                    </div>
                    <div class="palette-item" data-symbol="outlet-gfi" draggable="true" title="Prise GFI">
                        <svg viewBox="0 0 40 40"><circle cx="20" cy="20" r="15" fill="none" stroke="currentColor" stroke-width="2"/><circle cx="14" cy="17" r="2" fill="currentColor"/><circle cx="26" cy="17" r="2" fill="currentColor"/><line x1="20" y1="23" x2="20" y2="28" stroke="currentColor" stroke-width="2"/><text x="20" y="12" text-anchor="middle" font-size="6" fill="currentColor">GFI</text></svg>
                    </div>
                    <div class="palette-item" data-symbol="outlet-240" draggable="true" title="Prise 240V">
                        <svg viewBox="0 0 40 40"><circle cx="20" cy="20" r="15" fill="none" stroke="currentColor" stroke-width="2"/><circle cx="14" cy="15" r="2" fill="currentColor"/><circle cx="26" cy="15" r="2" fill="currentColor"/><circle cx="20" cy="24" r="2" fill="currentColor"/><text x="20" y="35" text-anchor="middle" font-size="6" fill="currentColor">240V</text></svg>
                    </div>
                    <div class="palette-item" data-symbol="outlet-ext" draggable="true" title="Prise extérieure">
                        <svg viewBox="0 0 40 40"><rect x="5" y="8" width="30" height="24" rx="3" fill="none" stroke="currentColor" stroke-width="2"/><circle cx="14" cy="17" r="2" fill="currentColor"/><circle cx="26" cy="17" r="2" fill="currentColor"/><line x1="20" y1="23" x2="20" y2="28" stroke="currentColor" stroke-width="2"/></svg>
                    </div>
                </div>
            </div>

            <div class="palette-section">
                <div class="palette-section-title" onclick="togglePaletteSection(this)">
                    <i class="bi bi-toggle-on me-1"></i>Interrupteurs
                    <i class="bi bi-chevron-down float-end"></i>
                </div>
                <div class="palette-items">
                    <div class="palette-item" data-symbol="switch" draggable="true" title="Interrupteur">
                        <svg viewBox="0 0 40 40"><circle cx="20" cy="20" r="4" fill="currentColor"/><line x1="20" y1="16" x2="32" y2="8" stroke="currentColor" stroke-width="2"/></svg>
                    </div>
                    <div class="palette-item" data-symbol="switch-3way" draggable="true" title="Interrupteur 3-way">
                        <svg viewBox="0 0 40 40"><circle cx="20" cy="20" r="4" fill="currentColor"/><line x1="20" y1="16" x2="32" y2="8" stroke="currentColor" stroke-width="2"/><text x="36" y="12" font-size="8" fill="currentColor">3</text></svg>
                    </div>
                    <div class="palette-item" data-symbol="switch-dimmer" draggable="true" title="Gradateur">
                        <svg viewBox="0 0 40 40"><circle cx="20" cy="20" r="4" fill="currentColor"/><line x1="20" y1="16" x2="32" y2="8" stroke="currentColor" stroke-width="2"/><path d="M 28 18 Q 34 20 28 22" fill="none" stroke="currentColor" stroke-width="1.5"/></svg>
                    </div>
                    <div class="palette-item" data-symbol="switch-motion" draggable="true" title="Détecteur mouvement">
                        <svg viewBox="0 0 40 40"><circle cx="20" cy="20" r="4" fill="currentColor"/><line x1="20" y1="16" x2="32" y2="8" stroke="currentColor" stroke-width="2"/><path d="M 28 14 A 8 8 0 0 1 28 26" fill="none" stroke="currentColor" stroke-width="1.5"/></svg>
                    </div>
                </div>
            </div>

            <div class="palette-section">
                <div class="palette-section-title" onclick="togglePaletteSection(this)">
                    <i class="bi bi-lightbulb me-1"></i>Lumières
                    <i class="bi bi-chevron-down float-end"></i>
                </div>
                <div class="palette-items">
                    <div class="palette-item" data-symbol="light-ceiling" draggable="true" title="Plafonnier">
                        <svg viewBox="0 0 40 40"><circle cx="20" cy="20" r="12" fill="none" stroke="currentColor" stroke-width="2"/><line x1="12" y1="12" x2="28" y2="28" stroke="currentColor" stroke-width="1.5"/><line x1="28" y1="12" x2="12" y2="28" stroke="currentColor" stroke-width="1.5"/></svg>
                    </div>
                    <div class="palette-item" data-symbol="light-recessed" draggable="true" title="Encastré/Puck">
                        <svg viewBox="0 0 40 40"><circle cx="20" cy="20" r="10" fill="none" stroke="currentColor" stroke-width="2"/><circle cx="20" cy="20" r="5" fill="currentColor"/></svg>
                    </div>
                    <div class="palette-item" data-symbol="light-wall" draggable="true" title="Applique murale">
                        <svg viewBox="0 0 40 40"><rect x="10" y="12" width="20" height="16" rx="2" fill="none" stroke="currentColor" stroke-width="2"/><line x1="15" y1="16" x2="25" y2="24" stroke="currentColor" stroke-width="1.5"/><line x1="25" y1="16" x2="15" y2="24" stroke="currentColor" stroke-width="1.5"/></svg>
                    </div>
                    <div class="palette-item" data-symbol="light-ext" draggable="true" title="Lumière extérieure">
                        <svg viewBox="0 0 40 40"><circle cx="20" cy="20" r="12" fill="none" stroke="currentColor" stroke-width="2"/><line x1="12" y1="12" x2="28" y2="28" stroke="currentColor" stroke-width="1.5"/><line x1="28" y1="12" x2="12" y2="28" stroke="currentColor" stroke-width="1.5"/><rect x="8" y="8" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1"/></svg>
                    </div>
                </div>
            </div>

            <div class="palette-section">
                <div class="palette-section-title" onclick="togglePaletteSection(this)">
                    <i class="bi bi-thermometer-half me-1"></i>Chauffage
                    <i class="bi bi-chevron-down float-end"></i>
                </div>
                <div class="palette-items">
                    <div class="palette-item" data-symbol="thermostat" draggable="true" title="Thermostat">
                        <svg viewBox="0 0 40 40"><rect x="10" y="10" width="20" height="20" rx="2" fill="none" stroke="currentColor" stroke-width="2"/><text x="20" y="24" text-anchor="middle" font-size="10" fill="currentColor">T</text></svg>
                    </div>
                    <div class="palette-item" data-symbol="baseboard" draggable="true" title="Plinthe chauffante">
                        <svg viewBox="0 0 40 40"><rect x="5" y="15" width="30" height="10" fill="none" stroke="currentColor" stroke-width="2"/><line x1="10" y1="18" x2="10" y2="22" stroke="currentColor" stroke-width="1.5"/><line x1="15" y1="18" x2="15" y2="22" stroke="currentColor" stroke-width="1.5"/><line x1="20" y1="18" x2="20" y2="22" stroke="currentColor" stroke-width="1.5"/><line x1="25" y1="18" x2="25" y2="22" stroke="currentColor" stroke-width="1.5"/><line x1="30" y1="18" x2="30" y2="22" stroke="currentColor" stroke-width="1.5"/></svg>
                    </div>
                    <div class="palette-item" data-symbol="floor-heat" draggable="true" title="Plancher chauffant">
                        <svg viewBox="0 0 40 40"><rect x="8" y="8" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2"/><path d="M 12 14 Q 16 18 12 22 Q 16 26 12 30" fill="none" stroke="currentColor" stroke-width="1.5"/><path d="M 20 14 Q 24 18 20 22 Q 24 26 20 30" fill="none" stroke="currentColor" stroke-width="1.5"/><path d="M 28 14 Q 32 18 28 22 Q 32 26 28 30" fill="none" stroke="currentColor" stroke-width="1.5"/></svg>
                    </div>
                </div>
            </div>

            <div class="palette-section">
                <div class="palette-section-title" onclick="togglePaletteSection(this)">
                    <i class="bi bi-box me-1"></i>Panneau
                    <i class="bi bi-chevron-down float-end"></i>
                </div>
                <div class="palette-items">
                    <div class="palette-item" data-symbol="panel" draggable="true" title="Panneau principal">
                        <svg viewBox="0 0 40 40"><rect x="8" y="5" width="24" height="30" fill="none" stroke="currentColor" stroke-width="2"/><line x1="12" y1="10" x2="28" y2="10" stroke="currentColor" stroke-width="1.5"/><line x1="12" y1="15" x2="28" y2="15" stroke="currentColor" stroke-width="1.5"/><line x1="12" y1="20" x2="28" y2="20" stroke="currentColor" stroke-width="1.5"/><line x1="12" y1="25" x2="28" y2="25" stroke="currentColor" stroke-width="1.5"/><line x1="12" y1="30" x2="28" y2="30" stroke="currentColor" stroke-width="1.5"/></svg>
                    </div>
                    <div class="palette-item" data-symbol="subpanel" draggable="true" title="Sous-panneau">
                        <svg viewBox="0 0 40 40"><rect x="10" y="8" width="20" height="24" fill="none" stroke="currentColor" stroke-width="2"/><line x1="14" y1="13" x2="26" y2="13" stroke="currentColor" stroke-width="1.5"/><line x1="14" y1="18" x2="26" y2="18" stroke="currentColor" stroke-width="1.5"/><line x1="14" y1="23" x2="26" y2="23" stroke="currentColor" stroke-width="1.5"/><line x1="14" y1="28" x2="26" y2="28" stroke="currentColor" stroke-width="1.5"/></svg>
                    </div>
                </div>
            </div>

            <div class="palette-section">
                <div class="palette-section-title" onclick="togglePaletteSection(this)">
                    <i class="bi bi-shield-check me-1"></i>Sécurité
                    <i class="bi bi-chevron-down float-end"></i>
                </div>
                <div class="palette-items">
                    <div class="palette-item" data-symbol="smoke" draggable="true" title="Détecteur fumée">
                        <svg viewBox="0 0 40 40"><circle cx="20" cy="20" r="12" fill="none" stroke="currentColor" stroke-width="2"/><text x="20" y="24" text-anchor="middle" font-size="8" fill="currentColor">S</text></svg>
                    </div>
                    <div class="palette-item" data-symbol="co" draggable="true" title="Détecteur CO">
                        <svg viewBox="0 0 40 40"><circle cx="20" cy="20" r="12" fill="none" stroke="currentColor" stroke-width="2"/><text x="20" y="24" text-anchor="middle" font-size="7" fill="currentColor">CO</text></svg>
                    </div>
                </div>
            </div>

            <!-- Circuits -->
            <h6 class="palette-title mt-3">Circuits</h6>
            <div class="circuits-list" id="circuitsList">
                <?php foreach ($circuits as $i => $circuit): ?>
                <div class="circuit-item <?= $i === 0 ? 'active' : '' ?>" data-circuit="<?= $i ?>" data-color="<?= e($circuit['color']) ?>">
                    <span class="circuit-color" style="background: <?= e($circuit['color']) ?>"></span>
                    <span class="circuit-name"><?= e($circuit['nom']) ?></span>
                    <span class="circuit-info"><?= $circuit['amperage'] ?>A</span>
                </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="btn btn-sm btn-outline-primary w-100 mt-2" onclick="addCircuit()">
                <i class="bi bi-plus-lg me-1"></i>Nouveau circuit
            </button>
        </div>

        <!-- Canvas -->
        <div class="canvas-container" id="drawingCanvasContainer">
            <canvas id="electricalCanvas"></canvas>
        </div>
    </div>

    <!-- Barre d'info -->
    <div class="drawing-status-bar mt-2">
        <span id="drawingStatusText">Prêt</span>
        <span class="float-end">
            <span id="drawingZoomLevel">100%</span> |
            <span id="drawingCanvasSize"><?= $drawing ? $drawing['width'] : 1200 ?>x<?= $drawing ? $drawing['height'] : 800 ?></span>
        </span>
    </div>
</div>

<!-- Modal nouveau circuit -->
<div class="modal fade" id="circuitModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Nouveau circuit</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Nom</label>
                    <input type="text" class="form-control" id="circuit-name" placeholder="Ex: Cuisine">
                </div>
                <div class="row">
                    <div class="col-6">
                        <div class="mb-3">
                            <label class="form-label">Ampérage</label>
                            <select class="form-select" id="circuit-amp">
                                <option value="15">15A</option>
                                <option value="20">20A</option>
                                <option value="30">30A</option>
                                <option value="40">40A</option>
                                <option value="50">50A</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="mb-3">
                            <label class="form-label">Voltage</label>
                            <select class="form-select" id="circuit-voltage">
                                <option value="120">120V</option>
                                <option value="240">240V</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Couleur</label>
                    <input type="color" class="form-control form-control-color w-100" id="circuit-color" value="#9b59b6">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                <button type="button" class="btn btn-primary" onclick="saveCircuit()">Ajouter</button>
            </div>
        </div>
    </div>
</div>

<style>
.electrical-drawing-container {
    display: flex;
    flex-direction: column;
    height: calc(100vh - 300px);
    min-height: 500px;
}

.drawing-toolbar {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    padding: 0.5rem;
    background: rgba(0,0,0,0.2);
    border-radius: 6px;
}

.drawing-toolbar .btn.active {
    background: var(--bs-primary);
    color: white;
}

.drawing-workspace {
    display: flex;
    flex: 1;
    gap: 1rem;
    overflow: hidden;
}

.symbol-palette {
    width: 180px;
    background: rgba(0,0,0,0.2);
    border-radius: 6px;
    padding: 0.5rem;
    overflow-y: auto;
}

.palette-title {
    font-size: 0.85rem;
    font-weight: 600;
    margin-bottom: 0.5rem;
    padding-bottom: 0.25rem;
    border-bottom: 1px solid var(--border-color);
}

.palette-section {
    margin-bottom: 0.5rem;
}

.palette-section-title {
    font-size: 0.75rem;
    padding: 0.25rem 0.5rem;
    background: rgba(255,255,255,0.05);
    border-radius: 4px;
    cursor: pointer;
    margin-bottom: 0.25rem;
}

.palette-section-title:hover {
    background: rgba(255,255,255,0.1);
}

.palette-items {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 4px;
    padding: 4px;
}

.palette-item {
    aspect-ratio: 1;
    background: rgba(255,255,255,0.05);
    border: 1px solid var(--border-color);
    border-radius: 4px;
    cursor: grab;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s;
}

.palette-item:hover {
    background: rgba(255,255,255,0.15);
    border-color: var(--bs-primary);
}

.palette-item.selected {
    background: var(--bs-primary);
    border-color: var(--bs-primary);
    color: white;
}

.palette-item svg {
    width: 28px;
    height: 28px;
}

.palette-items.collapsed {
    display: none;
}

.circuits-list {
    max-height: 150px;
    overflow-y: auto;
}

.circuit-item {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.35rem 0.5rem;
    border-radius: 4px;
    cursor: pointer;
    font-size: 0.8rem;
}

.circuit-item:hover, .circuit-item.active {
    background: rgba(255,255,255,0.1);
}

.circuit-color {
    width: 12px;
    height: 12px;
    border-radius: 50%;
    flex-shrink: 0;
}

.circuit-name {
    flex: 1;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.circuit-info {
    font-size: 0.7rem;
    opacity: 0.7;
}

.canvas-container {
    flex: 1;
    background: #1a1a2e;
    border-radius: 6px;
    overflow: hidden;
    position: relative;
}

#electricalCanvas {
    width: 100%;
    height: 100%;
}

.drawing-status-bar {
    font-size: 0.75rem;
    padding: 0.25rem 0.5rem;
    background: rgba(0,0,0,0.2);
    border-radius: 4px;
    color: var(--text-muted);
}
</style>

<script>
// Variables pour le module dessin (éviter conflits avec component.php)
var DRAW_PROJET_ID = <?= $projetId ?? 'null' ?>;
var DRAW_DRAWING_ID = <?= $drawing ? $drawing['id'] : 'null' ?>;
var DRAW_SAVED_CANVAS = <?= $drawing && $drawing['canvas_data'] ? $drawing['canvas_data'] : 'null' ?>;

let canvas;
let currentTool = 'select';
let currentCircuit = 0;
let isDrawing = false;
let startPoint = null;
let gridVisible = true;
let snapEnabled = true;
let undoStack = [];
let redoStack = [];
const GRID_SIZE = 20;
const SNAP_THRESHOLD = 10;

// Symboles SVG
const SYMBOLS = {
    'outlet': '<svg viewBox="0 0 40 40"><circle cx="20" cy="20" r="15" fill="white" stroke="#333" stroke-width="2"/><circle cx="14" cy="17" r="2" fill="#333"/><circle cx="26" cy="17" r="2" fill="#333"/><line x1="20" y1="23" x2="20" y2="28" stroke="#333" stroke-width="2"/></svg>',
    'outlet-gfi': '<svg viewBox="0 0 40 40"><circle cx="20" cy="20" r="15" fill="white" stroke="#333" stroke-width="2"/><circle cx="14" cy="17" r="2" fill="#333"/><circle cx="26" cy="17" r="2" fill="#333"/><line x1="20" y1="23" x2="20" y2="28" stroke="#333" stroke-width="2"/><text x="20" y="12" text-anchor="middle" font-size="6" fill="#333">GFI</text></svg>',
    'outlet-240': '<svg viewBox="0 0 40 40"><circle cx="20" cy="20" r="15" fill="white" stroke="#333" stroke-width="2"/><circle cx="14" cy="15" r="2" fill="#333"/><circle cx="26" cy="15" r="2" fill="#333"/><circle cx="20" cy="24" r="2" fill="#333"/></svg>',
    'outlet-ext': '<svg viewBox="0 0 40 40"><rect x="5" y="8" width="30" height="24" rx="3" fill="white" stroke="#333" stroke-width="2"/><circle cx="14" cy="17" r="2" fill="#333"/><circle cx="26" cy="17" r="2" fill="#333"/><line x1="20" y1="23" x2="20" y2="28" stroke="#333" stroke-width="2"/></svg>',
    'switch': '<svg viewBox="0 0 40 40"><circle cx="20" cy="25" r="4" fill="#333"/><line x1="20" y1="21" x2="32" y2="10" stroke="#333" stroke-width="2"/></svg>',
    'switch-3way': '<svg viewBox="0 0 40 40"><circle cx="20" cy="25" r="4" fill="#333"/><line x1="20" y1="21" x2="32" y2="10" stroke="#333" stroke-width="2"/><text x="36" y="14" font-size="8" fill="#333">3</text></svg>',
    'switch-dimmer': '<svg viewBox="0 0 40 40"><circle cx="20" cy="25" r="4" fill="#333"/><line x1="20" y1="21" x2="32" y2="10" stroke="#333" stroke-width="2"/><path d="M 30 16 Q 36 20 30 24" fill="none" stroke="#333" stroke-width="1.5"/></svg>',
    'switch-motion': '<svg viewBox="0 0 40 40"><circle cx="20" cy="25" r="4" fill="#333"/><line x1="20" y1="21" x2="32" y2="10" stroke="#333" stroke-width="2"/><path d="M 28 12 A 10 10 0 0 1 28 28" fill="none" stroke="#333" stroke-width="1.5"/></svg>',
    'light-ceiling': '<svg viewBox="0 0 40 40"><circle cx="20" cy="20" r="12" fill="white" stroke="#333" stroke-width="2"/><line x1="12" y1="12" x2="28" y2="28" stroke="#333" stroke-width="1.5"/><line x1="28" y1="12" x2="12" y2="28" stroke="#333" stroke-width="1.5"/></svg>',
    'light-recessed': '<svg viewBox="0 0 40 40"><circle cx="20" cy="20" r="12" fill="white" stroke="#333" stroke-width="2"/><circle cx="20" cy="20" r="5" fill="#333"/></svg>',
    'light-wall': '<svg viewBox="0 0 40 40"><rect x="10" y="12" width="20" height="16" rx="2" fill="white" stroke="#333" stroke-width="2"/><line x1="15" y1="16" x2="25" y2="24" stroke="#333" stroke-width="1.5"/><line x1="25" y1="16" x2="15" y2="24" stroke="#333" stroke-width="1.5"/></svg>',
    'light-ext': '<svg viewBox="0 0 40 40"><rect x="8" y="8" width="24" height="24" fill="none" stroke="#333" stroke-width="1"/><circle cx="20" cy="20" r="10" fill="white" stroke="#333" stroke-width="2"/><line x1="13" y1="13" x2="27" y2="27" stroke="#333" stroke-width="1.5"/><line x1="27" y1="13" x2="13" y2="27" stroke="#333" stroke-width="1.5"/></svg>',
    'thermostat': '<svg viewBox="0 0 40 40"><rect x="10" y="10" width="20" height="20" rx="2" fill="white" stroke="#333" stroke-width="2"/><text x="20" y="24" text-anchor="middle" font-size="10" fill="#333">T</text></svg>',
    'baseboard': '<svg viewBox="0 0 40 40"><rect x="5" y="15" width="30" height="10" fill="white" stroke="#333" stroke-width="2"/><line x1="10" y1="18" x2="10" y2="22" stroke="#333" stroke-width="1.5"/><line x1="15" y1="18" x2="15" y2="22" stroke="#333" stroke-width="1.5"/><line x1="20" y1="18" x2="20" y2="22" stroke="#333" stroke-width="1.5"/><line x1="25" y1="18" x2="25" y2="22" stroke="#333" stroke-width="1.5"/><line x1="30" y1="18" x2="30" y2="22" stroke="#333" stroke-width="1.5"/></svg>',
    'floor-heat': '<svg viewBox="0 0 40 40"><rect x="8" y="8" width="24" height="24" fill="white" stroke="#333" stroke-width="2"/><path d="M 12 14 Q 16 18 12 22 Q 16 26 12 30" fill="none" stroke="#333" stroke-width="1.5"/><path d="M 20 14 Q 24 18 20 22 Q 24 26 20 30" fill="none" stroke="#333" stroke-width="1.5"/><path d="M 28 14 Q 32 18 28 22 Q 32 26 28 30" fill="none" stroke="#333" stroke-width="1.5"/></svg>',
    'panel': '<svg viewBox="0 0 40 40"><rect x="8" y="5" width="24" height="30" fill="white" stroke="#333" stroke-width="2"/><line x1="12" y1="10" x2="28" y2="10" stroke="#333" stroke-width="1.5"/><line x1="12" y1="15" x2="28" y2="15" stroke="#333" stroke-width="1.5"/><line x1="12" y1="20" x2="28" y2="20" stroke="#333" stroke-width="1.5"/><line x1="12" y1="25" x2="28" y2="25" stroke="#333" stroke-width="1.5"/><line x1="12" y1="30" x2="28" y2="30" stroke="#333" stroke-width="1.5"/></svg>',
    'subpanel': '<svg viewBox="0 0 40 40"><rect x="10" y="8" width="20" height="24" fill="white" stroke="#333" stroke-width="2"/><line x1="14" y1="13" x2="26" y2="13" stroke="#333" stroke-width="1.5"/><line x1="14" y1="18" x2="26" y2="18" stroke="#333" stroke-width="1.5"/><line x1="14" y1="23" x2="26" y2="23" stroke="#333" stroke-width="1.5"/><line x1="14" y1="28" x2="26" y2="28" stroke="#333" stroke-width="1.5"/></svg>',
    'smoke': '<svg viewBox="0 0 40 40"><circle cx="20" cy="20" r="12" fill="white" stroke="#333" stroke-width="2"/><text x="20" y="24" text-anchor="middle" font-size="8" fill="#333">S</text></svg>',
    'co': '<svg viewBox="0 0 40 40"><circle cx="20" cy="20" r="12" fill="white" stroke="#333" stroke-width="2"/><text x="20" y="24" text-anchor="middle" font-size="7" fill="#333">CO</text></svg>'
};

// Labels pour les symboles
const SYMBOL_LABELS = {
    'outlet': 'Prise',
    'outlet-gfi': 'Prise GFI',
    'outlet-240': 'Prise 240V',
    'outlet-ext': 'Prise ext.',
    'switch': 'Interrupteur',
    'switch-3way': 'Inter. 3-way',
    'switch-dimmer': 'Gradateur',
    'switch-motion': 'Détecteur',
    'light-ceiling': 'Plafonnier',
    'light-recessed': 'Encastré',
    'light-wall': 'Applique',
    'light-ext': 'Lumière ext.',
    'thermostat': 'Thermostat',
    'baseboard': 'Plinthe',
    'floor-heat': 'Plancher ch.',
    'panel': 'Panneau',
    'subpanel': 'Sous-panneau',
    'smoke': 'Détecteur fumée',
    'co': 'Détecteur CO'
};

// Initialisation
let canvasInitialized = false;
let selectedSymbol = null; // Symbole sélectionné pour placement par clic
let lastEndPoint = null; // Dernier point de fin pour chaîner les lignes

document.addEventListener('DOMContentLoaded', function() {
    // Initialiser quand l'onglet devient visible
    const drawingTab = document.getElementById('drawing-tab');
    if (drawingTab) {
        drawingTab.addEventListener('shown.bs.tab', function() {
            console.log('Tab Plan 2D visible');
            if (!canvasInitialized) {
                setTimeout(() => {
                    initCanvas();
                    initToolsDrawing();
                    initPaletteDrawing();
                    initCircuitsDrawing();
                    if (DRAW_SAVED_CANVAS) {
                        loadCanvasData(DRAW_SAVED_CANVAS);
                    }
                    canvasInitialized = true;
                    console.log('Canvas initialisé');
                }, 150);
            } else {
                resizeCanvas();
            }
        });
    }

    // Si l'onglet est déjà visible, initialiser directement
    const drawingContent = document.getElementById('drawing-content');
    if (drawingContent && drawingContent.classList.contains('show')) {
        setTimeout(() => {
            initCanvas();
            initToolsDrawing();
            initPaletteDrawing();
            initCircuitsDrawing();
            if (DRAW_SAVED_CANVAS) {
                loadCanvasData(DRAW_SAVED_CANVAS);
            }
            canvasInitialized = true;
        }, 150);
    }
});

function initCanvas() {
    const container = document.getElementById('drawingCanvasContainer');
    if (!container) {
        console.error('Container canvas non trouvé');
        return;
    }

    const CANVAS_WIDTH = <?= $drawing ? $drawing['width'] : 1200 ?>;
    const CANVAS_HEIGHT = <?= $drawing ? $drawing['height'] : 800 ?>;

    // Stocker les dimensions originales pour le zoom
    window.CANVAS_ORIGINAL_WIDTH = CANVAS_WIDTH;
    window.CANVAS_ORIGINAL_HEIGHT = CANVAS_HEIGHT;

    canvas = new fabric.Canvas('electricalCanvas', {
        width: CANVAS_WIDTH,
        height: CANVAS_HEIGHT,
        backgroundColor: '#ffffff',
        selection: true
    });

    // Dessiner la grille
    drawGrid();

    // Events souris
    canvas.on('mouse:down', onMouseDown);
    canvas.on('mouse:move', onMouseMove);
    canvas.on('mouse:up', onMouseUp);

    // Resize après initialisation
    setTimeout(() => resizeCanvas(), 50);
    window.addEventListener('resize', resizeCanvas);

    console.log('Canvas Fabric.js créé:', canvas);
}

function drawGrid() {
    if (!gridVisible) return;

    const width = window.CANVAS_ORIGINAL_WIDTH || 1200;
    const height = window.CANVAS_ORIGINAL_HEIGHT || 800;

    // Lignes verticales
    for (let x = 0; x <= width; x += GRID_SIZE) {
        const line = new fabric.Line([x, 0, x, height], {
            stroke: '#e0e0e0',
            strokeWidth: x % 100 === 0 ? 0.5 : 0.2,
            selectable: false,
            evented: false,
            isGrid: true
        });
        canvas.add(line);
        canvas.sendToBack(line);
    }

    // Lignes horizontales
    for (let y = 0; y <= height; y += GRID_SIZE) {
        const line = new fabric.Line([0, y, width, y], {
            stroke: '#e0e0e0',
            strokeWidth: y % 100 === 0 ? 0.5 : 0.2,
            selectable: false,
            evented: false,
            isGrid: true
        });
        canvas.add(line);
        canvas.sendToBack(line);
    }
}

function clearGrid() {
    const objects = canvas.getObjects().filter(obj => obj.isGrid);
    objects.forEach(obj => canvas.remove(obj));
}

function toggleGrid() {
    gridVisible = !gridVisible;
    clearGrid();
    if (gridVisible) drawGrid();
    canvas.renderAll();
}

function toggleSnap() {
    snapEnabled = !snapEnabled;
    document.getElementById('snapBtn').classList.toggle('active', snapEnabled);
}

function snapToGrid(value) {
    if (!snapEnabled) return value;
    return Math.round(value / GRID_SIZE) * GRID_SIZE;
}

function resizeCanvas() {
    if (!canvas) return;

    const container = document.getElementById('drawingCanvasContainer');
    if (!container || container.clientWidth === 0) return;

    const canvasWidth = window.CANVAS_ORIGINAL_WIDTH || 1200;
    const canvasHeight = window.CANVAS_ORIGINAL_HEIGHT || 800;
    const containerWidth = container.clientWidth;
    const containerHeight = container.clientHeight;

    let zoom = Math.min(containerWidth / canvasWidth, containerHeight / canvasHeight);
    zoom = Math.min(zoom, 1); // Max 100%

    canvas.setZoom(zoom);
    canvas.setWidth(canvasWidth * zoom);
    canvas.setHeight(canvasHeight * zoom);
    canvas.renderAll();

    updateZoomDisplay();
}

function initToolsDrawing() {
    // Boutons d'outils dans la toolbar du dessin
    const container = document.querySelector('.electrical-drawing-container');
    if (!container) return;

    container.querySelectorAll('[data-tool]').forEach(btn => {
        btn.addEventListener('click', function() {
            container.querySelectorAll('[data-tool]').forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            currentTool = this.dataset.tool;
            selectedSymbol = null;

            // Désélectionner les symboles dans la palette
            container.querySelectorAll('.palette-item').forEach(p => p.classList.remove('selected'));

            if (canvas) {
                canvas.isDrawingMode = false;
                // En mode dessin, ignorer complètement les objets
                if (currentTool === 'select') {
                    canvas.selection = true;
                    canvas.skipTargetFind = false;
                    setObjectsSelectable(true);
                    updateEndpointVisibility(true); // Montrer les points d'extrémité
                } else {
                    canvas.selection = false;
                    canvas.skipTargetFind = true; // Ignorer les objets sous le curseur
                    canvas.discardActiveObject();
                    setObjectsSelectable(false);
                    updateEndpointVisibility(false); // Cacher les points d'extrémité
                }
                canvas.renderAll();
            }

            if (currentTool !== 'wire' && currentTool !== 'wall') {
                lastEndPoint = null;
            }

            updateStatus();
        });
    });

    // Activer le mode dessin par défaut au démarrage (outil sélection actif)
    if (canvas) {
        canvas.selection = true;
        canvas.skipTargetFind = false;
    }
}

// Rendre tous les objets sélectionnables ou non
function setObjectsSelectable(selectable) {
    canvas.getObjects().forEach(obj => {
        if (!obj.isGrid) {
            obj.selectable = selectable;
            obj.evented = selectable;
        }
    });
    canvas.renderAll();
}

function initPaletteDrawing() {
    const container = document.querySelector('.electrical-drawing-container');
    if (!container) return;

    // Clic sur un symbole pour le sélectionner
    container.querySelectorAll('.palette-item').forEach(item => {
        // Clic pour sélectionner le symbole
        item.addEventListener('click', function(e) {
            e.preventDefault();
            container.querySelectorAll('.palette-item').forEach(p => p.classList.remove('selected'));
            this.classList.add('selected');
            selectedSymbol = this.dataset.symbol;
            currentTool = 'symbol'; // Mode placement de symbole

            container.querySelectorAll('[data-tool]').forEach(b => b.classList.remove('active'));
            if (canvas) {
                canvas.selection = false;
                canvas.skipTargetFind = true; // Ignorer les objets
                canvas.discardActiveObject();
                canvas.renderAll();
            }

            updateStatus();
        });

        // Drag & drop aussi
        item.addEventListener('dragstart', function(e) {
            e.dataTransfer.setData('symbol', this.dataset.symbol);
        });
    });

    // Drop sur le canvas
    const canvasEl = document.getElementById('drawingCanvasContainer');
    if (!canvasEl) return;

    canvasEl.addEventListener('dragover', function(e) {
        e.preventDefault();
    });

    canvasEl.addEventListener('drop', function(e) {
        e.preventDefault();
        if (!canvas) return;

        const symbol = e.dataTransfer.getData('symbol');
        if (!symbol) return;

        const rect = canvasEl.getBoundingClientRect();
        const zoom = canvas.getZoom();
        const x = snapToGrid((e.clientX - rect.left) / zoom);
        const y = snapToGrid((e.clientY - rect.top) / zoom);

        addSymbol(symbol, x, y);
    });
}

function initCircuitsDrawing() {
    const container = document.querySelector('.electrical-drawing-container');
    if (!container) return;

    container.querySelectorAll('.circuit-item').forEach((item, index) => {
        item.addEventListener('click', function() {
            container.querySelectorAll('.circuit-item').forEach(i => i.classList.remove('active'));
            this.classList.add('active');
            currentCircuit = index;
        });
    });
}

// Ajouter des contrôles aux extrémités d'une ligne
function addEndpointControls(line) {
    const color = line.stroke || '#333';

    // Point de départ (x1, y1)
    const startCircle = new fabric.Circle({
        left: line.x1,
        top: line.y1,
        radius: 6,
        fill: '#fff',
        stroke: color,
        strokeWidth: 2,
        originX: 'center',
        originY: 'center',
        selectable: false,
        evented: false,
        visible: false, // Caché par défaut
        hasControls: false,
        hasBorders: false,
        isEndpoint: true,
        linkedLine: line,
        endpointType: 'start'
    });

    // Point de fin (x2, y2)
    const endCircle = new fabric.Circle({
        left: line.x2,
        top: line.y2,
        radius: 6,
        fill: '#fff',
        stroke: color,
        strokeWidth: 2,
        originX: 'center',
        originY: 'center',
        selectable: false,
        evented: false,
        visible: false, // Caché par défaut
        hasControls: false,
        hasBorders: false,
        isEndpoint: true,
        linkedLine: line,
        endpointType: 'end'
    });

    // Stocker les références
    line.startCircle = startCircle;
    line.endCircle = endCircle;

    // Event: quand on déplace le cercle, mettre à jour la ligne
    startCircle.on('moving', function() {
        const snap = getSmartSnapPoint(this.left, this.top, this);
        this.set({ left: snap.x, top: snap.y });
        line.set({ x1: snap.x, y1: snap.y });
        line.setCoords();
        canvas.renderAll();
    });

    endCircle.on('moving', function() {
        const snap = getSmartSnapPoint(this.left, this.top, this);
        this.set({ left: snap.x, top: snap.y });
        line.set({ x2: snap.x, y2: snap.y });
        line.setCoords();
        canvas.renderAll();
    });

    canvas.add(startCircle);
    canvas.add(endCircle);
}

// Mettre à jour les cercles d'extrémité quand on active le mode sélection
function updateEndpointVisibility(visible) {
    canvas.getObjects().forEach(obj => {
        if (obj.isEndpoint) {
            obj.set({ visible: visible, evented: visible, selectable: visible });
        }
    });
    canvas.renderAll();
}

// Trouver le point d'extrémité le plus proche pour snap
function findNearestEndpoint(x, y, excludeObj) {
    if (!snapEnabled) return null;

    let nearest = null;
    let minDist = SNAP_THRESHOLD;

    canvas.getObjects().forEach(obj => {
        if (obj.isGrid || obj.isEndpoint) return; // Ignorer grille et cercles d'extrémité
        if (excludeObj && (obj === excludeObj || obj === excludeObj.linkedLine)) return;

        // Pour les lignes (murs et fils)
        if (obj.type === 'line') {
            const points = [
                { x: obj.x1, y: obj.y1 },
                { x: obj.x2, y: obj.y2 }
            ];
            points.forEach(p => {
                const dist = Math.sqrt(Math.pow(x - p.x, 2) + Math.pow(y - p.y, 2));
                if (dist < minDist) {
                    minDist = dist;
                    nearest = p;
                }
            });
        }
    });

    return nearest;
}

function addSymbol(symbolType, x, y) {
    const svg = SYMBOLS[symbolType];
    if (!svg) return;

    fabric.loadSVGFromString(svg, function(objects, options) {
        const group = fabric.util.groupSVGElements(objects, options);

        // Taille fixe pour le symbole (40x40)
        group.scaleToWidth(40);
        group.scaleToHeight(40);

        group.set({
            left: x,
            top: y,
            originX: 'center',
            originY: 'center',
            symbolType: symbolType,
            circuitIndex: currentCircuit,
            hasControls: true,
            hasBorders: true,
            cornerSize: 8,
            transparentCorners: false,
            cornerColor: '#0d6efd',
            borderColor: '#0d6efd',
            // Non-sélectionnable pendant le placement
            selectable: false,
            evented: false
        });

        canvas.add(group);
        canvas.discardActiveObject();
        canvas.renderAll();

        // Mettre à jour les compteurs de circuits
        updateCircuitCounts();
    });
}

function getCircuitColor(index) {
    const item = document.querySelectorAll('.circuit-item')[index];
    return item ? item.dataset.color : '#333';
}

// Créer un arc (courbe) entre deux points
function createArcPath(x1, y1, x2, y2, curvature = 0.2) {
    // Calculer le point milieu
    const midX = (x1 + x2) / 2;
    const midY = (y1 + y2) / 2;

    // Calculer la direction perpendiculaire
    const dx = x2 - x1;
    const dy = y2 - y1;
    const len = Math.sqrt(dx * dx + dy * dy);

    // Point de contrôle perpendiculaire au milieu
    const perpX = -dy / len;
    const perpY = dx / len;

    // Décalage pour la courbe
    const offset = len * curvature;
    const ctrlX = midX + perpX * offset;
    const ctrlY = midY + perpY * offset;

    // Créer le path SVG avec courbe quadratique
    return `M ${x1} ${y1} Q ${ctrlX} ${ctrlY} ${x2} ${y2}`;
}

// Trouver un symbole proche d'un point
function findNearbySymbol(x, y, threshold = 25) {
    const objects = canvas.getObjects();
    for (const obj of objects) {
        if (obj.symbolType) {
            const centerX = obj.left;
            const centerY = obj.top;
            const dist = Math.sqrt(Math.pow(x - centerX, 2) + Math.pow(y - centerY, 2));
            if (dist < threshold) {
                return obj;
            }
        }
    }
    return null;
}

// Connecter un fil à un symbole
function connectWireToSymbol(wire, symbol, endpoint) {
    if (!wire.connections) wire.connections = { start: null, end: null };
    if (!symbol.connectedWires) symbol.connectedWires = [];

    wire.connections[endpoint] = symbol;
    if (!symbol.connectedWires.includes(wire)) {
        symbol.connectedWires.push(wire);
    }
}

// Compter les appareils connectés par circuit
function countDevicesPerCircuit() {
    const counts = {};
    const circuits = document.querySelectorAll('.circuit-item');

    circuits.forEach((item, index) => {
        counts[index] = { prises: 0, interrupteurs: 0, lumieres: 0, autres: 0 };
    });

    canvas.getObjects().forEach(obj => {
        if (obj.symbolType && obj.circuitIndex !== undefined) {
            const circuit = obj.circuitIndex;
            if (!counts[circuit]) counts[circuit] = { prises: 0, interrupteurs: 0, lumieres: 0, autres: 0 };

            if (obj.symbolType.includes('prise') || obj.symbolType.includes('outlet')) {
                counts[circuit].prises++;
            } else if (obj.symbolType.includes('switch') || obj.symbolType.includes('interrupteur')) {
                counts[circuit].interrupteurs++;
            } else if (obj.symbolType.includes('light') || obj.symbolType.includes('lumiere') || obj.symbolType.includes('plafonnier')) {
                counts[circuit].lumieres++;
            } else {
                counts[circuit].autres++;
            }
        }
    });

    return counts;
}

// Mettre à jour l'affichage des compteurs de circuits
function updateCircuitCounts() {
    const counts = countDevicesPerCircuit();

    document.querySelectorAll('.circuit-item').forEach((item, index) => {
        let countSpan = item.querySelector('.circuit-count');
        if (!countSpan) {
            countSpan = document.createElement('span');
            countSpan.className = 'circuit-count badge bg-secondary ms-2';
            item.appendChild(countSpan);
        }

        const c = counts[index] || { prises: 0, interrupteurs: 0, lumieres: 0 };
        const total = c.prises + c.interrupteurs + c.lumieres + c.autres;
        countSpan.textContent = total > 0 ? total : '';
        countSpan.title = `Prises: ${c.prises}, Inter: ${c.interrupteurs}, Lumières: ${c.lumieres}`;
    });
}

function getSmartSnapPoint(rawX, rawY, excludeObj) {
    // D'abord essayer snap aux extrémités existantes
    const endpoint = findNearestEndpoint(rawX, rawY, excludeObj);
    if (endpoint) {
        return { x: endpoint.x, y: endpoint.y, snappedTo: 'endpoint' };
    }
    // Sinon snap à la grille
    return { x: snapToGrid(rawX), y: snapToGrid(rawY), snappedTo: 'grid' };
}

function onMouseDown(opt) {
    const pointer = canvas.getPointer(opt.e);

    // Mode placement de symbole par clic
    if (currentTool === 'symbol' && selectedSymbol) {
        const snap = getSmartSnapPoint(pointer.x, pointer.y);
        addSymbol(selectedSymbol, snap.x, snap.y);
        return;
    }

    if (currentTool === 'select') return;

    // Pour les outils de dessin
    let snapPoint = getSmartSnapPoint(pointer.x, pointer.y);

    // Si on a un lastEndPoint, vérifier si on clique près de lui
    if (lastEndPoint && (currentTool === 'wire' || currentTool === 'wall')) {
        const distToLast = Math.sqrt(
            Math.pow(pointer.x - lastEndPoint.x, 2) +
            Math.pow(pointer.y - lastEndPoint.y, 2)
        );
        // Si on clique près du dernier point (< 30px), chaîner
        if (distToLast < 30) {
            startPoint = { x: lastEndPoint.x, y: lastEndPoint.y };
        } else {
            // Sinon, commencer une nouvelle ligne
            startPoint = { x: snapPoint.x, y: snapPoint.y };
            lastEndPoint = null;
        }
    } else {
        startPoint = { x: snapPoint.x, y: snapPoint.y };
    }

    isDrawing = true;
}

function onMouseMove(opt) {
    if (!isDrawing || currentTool === 'select' || currentTool === 'symbol') return;

    const pointer = canvas.getPointer(opt.e);
    const snap = getSmartSnapPoint(pointer.x, pointer.y);
    const x = snap.x;
    const y = snap.y;

    // Supprimer l'objet temporaire
    if (canvas.tempObject) {
        canvas.remove(canvas.tempObject);
    }

    let tempObj = null;

    if (currentTool === 'wall') {
        tempObj = new fabric.Line([startPoint.x, startPoint.y, x, y], {
            stroke: '#333',
            strokeWidth: 6,
            selectable: false,
            evented: false
        });
    } else if (currentTool === 'room') {
        tempObj = new fabric.Rect({
            left: Math.min(startPoint.x, x),
            top: Math.min(startPoint.y, y),
            width: Math.abs(x - startPoint.x),
            height: Math.abs(y - startPoint.y),
            fill: 'rgba(200, 200, 200, 0.3)',
            stroke: '#333',
            strokeWidth: 4,
            selectable: false,
            evented: false
        });
    } else if (currentTool === 'wire') {
        const color = getCircuitColor(currentCircuit);
        const pathData = createArcPath(startPoint.x, startPoint.y, x, y, 0.15);
        tempObj = new fabric.Path(pathData, {
            stroke: color,
            strokeWidth: 2,
            strokeDashArray: [5, 3],
            fill: 'transparent',
            selectable: false,
            evented: false
        });
    }

    if (tempObj) {
        canvas.tempObject = tempObj;
        canvas.add(tempObj);
        canvas.renderAll();
    }
}

function onMouseUp(opt) {
    if (!isDrawing || currentTool === 'select' || currentTool === 'symbol') return;
    isDrawing = false;

    if (canvas.tempObject) {
        canvas.remove(canvas.tempObject);
        canvas.tempObject = null;
    }

    const pointer = canvas.getPointer(opt.e);
    const snap = getSmartSnapPoint(pointer.x, pointer.y);
    const x = snap.x;
    const y = snap.y;

    // Ignorer si le point de fin est le même que le départ
    if (startPoint.x === x && startPoint.y === y) {
        startPoint = null;
        return;
    }

    if (currentTool === 'wall') {
        const wall = new fabric.Line([startPoint.x, startPoint.y, x, y], {
            stroke: '#333',
            strokeWidth: 6,
            strokeLineCap: 'round',
            objectType: 'wall',
            selectable: false,
            evented: false,
            hasControls: false,
            hasBorders: false
        });
        canvas.add(wall);
        // Ajouter les points de contrôle aux extrémités
        addEndpointControls(wall);
        lastEndPoint = { x: x, y: y };
    } else if (currentTool === 'room') {
        const room = new fabric.Rect({
            left: Math.min(startPoint.x, x),
            top: Math.min(startPoint.y, y),
            width: Math.abs(x - startPoint.x),
            height: Math.abs(y - startPoint.y),
            fill: 'rgba(240, 240, 240, 0.5)',
            stroke: '#333',
            strokeWidth: 4,
            objectType: 'room',
            selectable: false,
            evented: false
        });
        canvas.add(room);
        canvas.sendToBack(room);
        canvas.getObjects().filter(o => o.isGrid).forEach(g => canvas.sendToBack(g));
        lastEndPoint = null;
    } else if (currentTool === 'wire') {
        const color = getCircuitColor(currentCircuit);
        const pathData = createArcPath(startPoint.x, startPoint.y, x, y, 0.15);
        const wire = new fabric.Path(pathData, {
            stroke: color,
            strokeWidth: 2,
            fill: 'transparent',
            objectType: 'wire',
            circuitIndex: currentCircuit,
            wireStart: { x: startPoint.x, y: startPoint.y },
            wireEnd: { x: x, y: y },
            selectable: false,
            evented: false,
            hasControls: false,
            hasBorders: false
        });
        canvas.add(wire);

        // Vérifier connexion aux symboles
        const startSymbol = findNearbySymbol(startPoint.x, startPoint.y);
        const endSymbol = findNearbySymbol(x, y);

        if (startSymbol) {
            connectWireToSymbol(wire, startSymbol, 'start');
        }
        if (endSymbol) {
            connectWireToSymbol(wire, endSymbol, 'end');
        }

        // Mettre à jour les compteurs
        updateCircuitCounts();

        lastEndPoint = { x: x, y: y };
    } else if (currentTool === 'text') {
        const text = prompt('Entrez le texte:');
        if (text) {
            const textObj = new fabric.Text(text, {
                left: startPoint.x,
                top: startPoint.y,
                fontSize: 14,
                fill: '#333',
                objectType: 'text',
                selectable: false,
                evented: false
            });
            canvas.add(textObj);
        }
        lastEndPoint = null;
    }

    canvas.discardActiveObject();
    canvas.renderAll();
    startPoint = null;
}

function zoomIn() {
    const zoom = canvas.getZoom();
    canvas.setZoom(Math.min(zoom * 1.2, 3));
    updateZoomDisplay();
}

function zoomOut() {
    const zoom = canvas.getZoom();
    canvas.setZoom(Math.max(zoom / 1.2, 0.3));
    updateZoomDisplay();
}

function resetZoom() {
    resizeCanvas();
}

function deleteSelected() {
    const active = canvas.getActiveObjects();
    active.forEach(obj => {
        if (!obj.isGrid) canvas.remove(obj);
    });
    canvas.discardActiveObject();
    canvas.renderAll();
}

function duplicateSelected() {
    const active = canvas.getActiveObject();
    if (!active || active.isGrid) return;

    active.clone(function(cloned) {
        cloned.set({
            left: cloned.left + 20,
            top: cloned.top + 20
        });
        canvas.add(cloned);
        canvas.setActiveObject(cloned);
        canvas.renderAll();
    });
}

function saveState() {
    if (!canvas) return; // Canvas pas encore initialisé
    const json = JSON.stringify(canvas.toJSON(['symbolType', 'circuitIndex', 'objectType', 'isGrid', 'wireStart', 'wireEnd']));
    undoStack.push(json);
    if (undoStack.length > 50) undoStack.shift();
    redoStack = [];
}

function undo() {
    if (undoStack.length < 2) return;
    redoStack.push(undoStack.pop());
    const json = undoStack[undoStack.length - 1];
    loadCanvasData(JSON.parse(json));
}

function redo() {
    if (redoStack.length === 0) return;
    const json = redoStack.pop();
    undoStack.push(json);
    loadCanvasData(JSON.parse(json));
}

function loadCanvasData(data) {
    canvas.loadFromJSON(data, function() {
        canvas.renderAll();
    });
}

function updateStatus() {
    const toolNames = {
        'select': 'Sélection - cliquer pour sélectionner',
        'wall': 'Mur - cliquer-glisser pour dessiner',
        'room': 'Pièce - cliquer-glisser pour dessiner',
        'wire': 'Fil - cliquer-glisser pour dessiner',
        'text': 'Texte - cliquer pour placer',
        'symbol': 'Symbole - cliquer pour placer: ' + (SYMBOL_LABELS[selectedSymbol] || selectedSymbol || '')
    };
    const el = document.getElementById('drawingStatusText');
    if (el) el.textContent = toolNames[currentTool] || 'Prêt';
}

function updateZoomDisplay() {
    const el = document.getElementById('drawingZoomLevel');
    if (el && canvas) el.textContent = Math.round(canvas.getZoom() * 100) + '%';
}

function togglePaletteSection(el) {
    const items = el.nextElementSibling;
    items.classList.toggle('collapsed');
    el.querySelector('.bi-chevron-down').classList.toggle('bi-chevron-right');
}

function addCircuit() {
    document.getElementById('circuit-name').value = 'Circuit ' + (document.querySelectorAll('.circuit-item').length + 1);
    new bootstrap.Modal(document.getElementById('circuitModal')).show();
}

function saveCircuit() {
    const nom = document.getElementById('circuit-name').value.trim();
    const amp = document.getElementById('circuit-amp').value;
    const voltage = document.getElementById('circuit-voltage').value;
    const color = document.getElementById('circuit-color').value;

    if (!nom) {
        alert('Entrez un nom');
        return;
    }

    // Ajouter visuellement
    const list = document.getElementById('circuitsList');
    const index = list.children.length;
    const div = document.createElement('div');
    div.className = 'circuit-item';
    div.dataset.circuit = index;
    div.dataset.color = color;
    div.innerHTML = `
        <span class="circuit-color" style="background: ${color}"></span>
        <span class="circuit-name">${nom}</span>
        <span class="circuit-info">${amp}A</span>
    `;
    div.addEventListener('click', function() {
        document.querySelectorAll('.circuit-item').forEach(i => i.classList.remove('active'));
        this.classList.add('active');
        currentCircuit = index;
    });
    list.appendChild(div);

    bootstrap.Modal.getInstance(document.getElementById('circuitModal')).hide();
}

function saveDrawing() {
    const canvasData = JSON.stringify(canvas.toJSON(['symbolType', 'circuitIndex', 'objectType', 'isGrid']));

    fetch('<?= url('/modules/construction/electrical/drawing-ajax.php') ?>', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            action: 'save',
            projet_id: DRAW_PROJET_ID,
            drawing_id: DRAW_DRAWING_ID,
            canvas_data: canvasData,
            circuits: getCircuitsData()
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showToast('Plan sauvegardé!');
        } else {
            alert(data.error || 'Erreur de sauvegarde');
        }
    });
}

function getCircuitsData() {
    const circuits = [];
    document.querySelectorAll('.circuit-item').forEach(item => {
        circuits.push({
            nom: item.querySelector('.circuit-name').textContent,
            amperage: parseInt(item.querySelector('.circuit-info').textContent),
            color: item.dataset.color
        });
    });
    return circuits;
}

function showToast(msg) {
    const toast = document.createElement('div');
    toast.className = 'position-fixed bottom-0 end-0 p-3';
    toast.style.zIndex = 9999;
    toast.innerHTML = `<div class="toast show"><div class="toast-body">${msg}</div></div>`;
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 2000);
}

function printDrawing() {
    const dataUrl = canvas.toDataURL({
        format: 'png',
        quality: 1,
        multiplier: 2
    });

    const printWindow = window.open('', '_blank');
    printWindow.document.write(`
        <!DOCTYPE html>
        <html>
        <head>
            <title>Plan Électrique</title>
            <style>
                body { margin: 0; padding: 20px; }
                h1 { font-size: 18px; margin-bottom: 10px; }
                .date { font-size: 10px; color: #666; margin-bottom: 20px; }
                img { max-width: 100%; border: 1px solid #ccc; }
                .legend { margin-top: 20px; font-size: 12px; }
                .legend-item { display: inline-flex; align-items: center; margin-right: 20px; margin-bottom: 5px; }
                .legend-color { width: 20px; height: 10px; margin-right: 5px; }
            </style>
        </head>
        <body>
            <h1>Plan Électrique</h1>
            <div class="date">Généré le ${new Date().toLocaleDateString('fr-CA')}</div>
            <img src="${dataUrl}">
            <div class="legend">
                <strong>Circuits:</strong><br>
                ${getCircuitsData().map(c => `<span class="legend-item"><span class="legend-color" style="background:${c.color}"></span>${c.nom} (${c.amperage}A)</span>`).join('')}
            </div>
        </body>
        </html>
    `);
    printWindow.document.close();
    printWindow.onload = function() {
        printWindow.print();
    };
}

// Init undo state
setTimeout(() => saveState(), 100);
</script>
