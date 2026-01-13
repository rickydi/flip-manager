<?php
/**
 * Liste des projets - Admin
 * Flip Manager
 */

require_once '../../config.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../includes/calculs.php';

// Vérifier que l'utilisateur est admin
requireAdmin();

$pageTitle = 'Liste des projets';

// Récupérer la préférence de vue de l'utilisateur
$vuePreference = 'liste'; // valeur par défaut
try {
    $stmtPref = $pdo->prepare("SELECT vue_projets_preference FROM users WHERE id = ?");
    $stmtPref->execute([getCurrentUserId()]);
    $prefResult = $stmtPref->fetchColumn();
    if ($prefResult) {
        $vuePreference = $prefResult;
    }
} catch (Exception $e) {
    // Colonne n'existe pas encore, utiliser valeur par défaut
}

// Filtres
$filtreStatut = isset($_GET['statut']) ? $_GET['statut'] : '';
$showArchives = isset($_GET['archives']) && $_GET['archives'] == '1';

// Construire la requête
$where = "WHERE 1=1";
$params = [];

if ($filtreStatut !== '') {
    $where .= " AND statut = ?";
    $params[] = $filtreStatut;
} elseif (!$showArchives) {
    $where .= " AND statut != 'archive'";
}

// Récupérer les projets
$sql = "SELECT * FROM projets $where ORDER BY date_creation DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$projets = $stmt->fetchAll();

// Récupérer la première photo de chaque projet pour la vue grille
$photosProjet = [];
try {
    foreach ($projets as $projet) {
        $stmtPhoto = $pdo->prepare("
            SELECT fichier FROM photos_projet
            WHERE projet_id = ?
            ORDER BY COALESCE(ordre, 999999), date_prise ASC
            LIMIT 1
        ");
        $stmtPhoto->execute([$projet['id']]);
        $photo = $stmtPhoto->fetchColumn();
        $photosProjet[$projet['id']] = $photo;
    }
} catch (Exception $e) {
    // Table photos_projet n'existe pas, ignorer
}

include '../../includes/header.php';
?>

<div class="container-fluid">
    <!-- En-tête -->
    <div class="page-header">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= url('/admin/index.php') ?>">Tableau de bord</a></li>
                <li class="breadcrumb-item active">Projets</li>
            </ol>
        </nav>
        <div class="d-flex justify-content-between align-items-center">
            <h1><i class="bi bi-building me-2"></i>Projets</h1>
            <a href="<?= url('/admin/projets/nouveau.php') ?>" class="btn btn-primary">
                <i class="bi bi-plus-circle me-1"></i>
                Nouveau projet
            </a>
        </div>
    </div>
    
    <?php displayFlashMessage(); ?>
    
    <!-- Filtres -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" action="" class="row g-3">
                <div class="col-md-4">
                    <label for="statut" class="form-label">Statut</label>
                    <select class="form-select" id="statut" name="statut">
                        <option value="">Tous les statuts</option>
                        <option value="prospection" <?= $filtreStatut === 'prospection' ? 'selected' : '' ?>>Prospection</option>
                        <option value="acquisition" <?= $filtreStatut === 'acquisition' ? 'selected' : '' ?>>Acquisition</option>
                        <option value="renovation" <?= $filtreStatut === 'renovation' ? 'selected' : '' ?>>Rénovation</option>
                        <option value="vente" <?= $filtreStatut === 'vente' ? 'selected' : '' ?>>En vente</option>
                        <option value="vendu" <?= $filtreStatut === 'vendu' ? 'selected' : '' ?>>Vendu</option>
                        <option value="archive" <?= $filtreStatut === 'archive' ? 'selected' : '' ?>>Archivé</option>
                    </select>
                </div>
                <div class="col-md-4 d-flex align-items-end">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="archives" name="archives" 
                               value="1" <?= $showArchives ? 'checked' : '' ?>>
                        <label class="form-check-label" for="archives">
                            Afficher les projets archivés
                        </label>
                    </div>
                </div>
                <div class="col-md-4 d-flex align-items-end">
                    <button type="submit" class="btn btn-outline-primary me-2">
                        <i class="bi bi-search me-1"></i>
                        Filtrer
                    </button>
                    <a href="<?= url('/admin/projets/liste.php') ?>" class="btn btn-outline-secondary">
                        <i class="bi bi-x-circle me-1"></i>
                        Réinitialiser
                    </a>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Liste des projets -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><?= count($projets) ?> projet(s)</span>
            <div class="btn-group" role="group" aria-label="Mode d'affichage">
                <button type="button" class="btn btn-sm <?= $vuePreference === 'liste' ? 'btn-primary' : 'btn-outline-secondary' ?>"
                        id="btnVueListe" onclick="changerVue('liste')" title="Vue liste">
                    <i class="bi bi-list-ul"></i>
                </button>
                <button type="button" class="btn btn-sm <?= $vuePreference === 'grille' ? 'btn-primary' : 'btn-outline-secondary' ?>"
                        id="btnVueGrille" onclick="changerVue('grille')" title="Vue grille">
                    <i class="bi bi-grid-3x3-gap"></i>
                </button>
            </div>
        </div>
        <div class="card-body p-0">
            <?php if (empty($projets)): ?>
                <div class="empty-state">
                    <i class="bi bi-building"></i>
                    <h4>Aucun projet</h4>
                    <p>Aucun projet ne correspond à vos critères.</p>
                </div>
            <?php else: ?>
                <!-- Vue Liste (tableau) -->
                <div id="vueListe" class="<?= $vuePreference === 'liste' ? '' : 'd-none' ?>">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Projet</th>
                                    <th>Adresse</th>
                                    <th>Statut</th>
                                    <th class="text-end">Prix d'achat</th>
                                    <th class="text-end">Valeur potentielle</th>
                                    <th class="text-end">Rénovation</th>
                                    <th class="text-end">Équité</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($projets as $projet):
                                    $indicateurs = calculerIndicateursProjet($pdo, $projet);
                                ?>
                                    <tr style="cursor: pointer;" onclick="window.location='<?= url('/admin/projets/detail.php?id=' . $projet['id']) ?>'">
                                        <td>
                                            <strong><?= e($projet['nom']) ?></strong>
                                            <br>
                                            <small class="text-muted">
                                                Créé le <?= formatDate($projet['date_creation']) ?>
                                            </small>
                                        </td>
                                        <td>
                                            <?= e($projet['adresse']) ?>
                                            <br>
                                            <small class="text-muted"><?= e($projet['ville']) ?></small>
                                        </td>
                                        <td>
                                            <span class="badge <?= getStatutProjetClass($projet['statut']) ?>">
                                                <?= getStatutProjetLabel($projet['statut']) ?>
                                            </span>
                                        </td>
                                        <td class="text-end"><?= formatMoney($projet['prix_achat']) ?></td>
                                        <td class="text-end"><?= formatMoney($projet['valeur_potentielle']) ?></td>
                                        <td class="text-end">
                                            <?= formatMoney($indicateurs['renovation']['reel'] + $indicateurs['main_doeuvre']['cout']) ?>
                                            <br>
                                            <small class="text-muted">/ <?= formatMoney($indicateurs['renovation']['budget']) ?></small>
                                            <?php if ($indicateurs['main_doeuvre']['cout'] > 0): ?>
                                                <br><small class="text-info"><i class="bi bi-person-fill"></i> <?= formatMoney($indicateurs['main_doeuvre']['cout']) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <strong class="<?= $indicateurs['equite_potentielle'] >= 0 ? 'text-success' : 'text-danger' ?>">
                                                <?= formatMoney($indicateurs['equite_potentielle']) ?>
                                            </strong>
                                        </td>
                                        <td class="action-buttons" onclick="event.stopPropagation()">
                                            <a href="<?= url('/admin/projets/detail.php?id=' . $projet['id']) ?>"
                                               class="btn btn-outline-primary btn-sm"
                                               title="Voir détails">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                            <a href="<?= url('/admin/projets/modifier.php?id=' . $projet['id']) ?>"
                                               class="btn btn-outline-secondary btn-sm"
                                               title="Modifier">
                                                <i class="bi bi-pencil"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Vue Grille (cartes avec photos) -->
                <div id="vueGrille" class="p-3 <?= $vuePreference === 'grille' ? '' : 'd-none' ?>">
                    <div class="row g-4">
                        <?php foreach ($projets as $projet):
                            $indicateurs = calculerIndicateursProjet($pdo, $projet);
                            $photoUrl = !empty($photosProjet[$projet['id']])
                                ? url('/serve-photo.php?file=' . urlencode($photosProjet[$projet['id']]) . '&thumb=1')
                                : null;
                        ?>
                            <div class="col-12 col-sm-6 col-md-4 col-lg-3 col-xl-2">
                                <div class="projet-card" onclick="window.location='<?= url('/admin/projets/detail.php?id=' . $projet['id']) ?>'">
                                    <!-- Photo du projet -->
                                    <div class="projet-card-image">
                                        <?php if ($photoUrl): ?>
                                            <img src="<?= $photoUrl ?>" alt="<?= e($projet['nom']) ?>" loading="lazy">
                                        <?php else: ?>
                                            <div class="projet-card-no-image">
                                                <i class="bi bi-building"></i>
                                            </div>
                                        <?php endif; ?>
                                        <!-- Badge statut sur la photo -->
                                        <span class="projet-card-badge <?= getStatutProjetClass($projet['statut']) ?>">
                                            <?= getStatutProjetLabel($projet['statut']) ?>
                                        </span>
                                    </div>

                                    <!-- Titre -->
                                    <div class="projet-card-title">
                                        <?= e($projet['nom']) ?>
                                    </div>

                                    <!-- Informations -->
                                    <div class="projet-card-info">
                                        <div class="projet-card-address">
                                            <i class="bi bi-geo-alt"></i>
                                            <?= e($projet['adresse']) ?>, <?= e($projet['ville']) ?>
                                        </div>
                                        <div class="projet-card-stats">
                                            <div class="projet-card-stat">
                                                <span class="label">Achat</span>
                                                <span class="value"><?= formatMoney($projet['prix_achat']) ?></span>
                                            </div>
                                            <div class="projet-card-stat">
                                                <span class="label">Valeur</span>
                                                <span class="value"><?= formatMoney($projet['valeur_potentielle']) ?></span>
                                            </div>
                                            <div class="projet-card-stat">
                                                <span class="label">Équité</span>
                                                <span class="value <?= $indicateurs['equite_potentielle'] >= 0 ? 'text-success' : 'text-danger' ?>">
                                                    <?= formatMoney($indicateurs['equite_potentielle']) ?>
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
/* Styles pour les cartes de projets (vue grille) */
.projet-card {
    background: #1e2126;
    border-radius: 12px;
    overflow: hidden;
    cursor: pointer;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
    height: 100%;
    display: flex;
    flex-direction: column;
}

.projet-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.3);
}

.projet-card-image {
    position: relative;
    aspect-ratio: 16/10;
    overflow: hidden;
    background: #2a2f36;
}

.projet-card-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform 0.3s ease;
}

.projet-card:hover .projet-card-image img {
    transform: scale(1.05);
}

.projet-card-no-image {
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    background: linear-gradient(135deg, #2a2f36 0%, #1e2126 100%);
}

.projet-card-no-image i {
    font-size: 3rem;
    color: #495057;
}

.projet-card-badge {
    position: absolute;
    top: 10px;
    right: 10px;
    padding: 4px 10px;
    font-size: 0.7rem;
    font-weight: 600;
    border-radius: 20px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.projet-card-title {
    padding: 12px 15px 8px;
    font-weight: 600;
    font-size: 1rem;
    color: #fff;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.projet-card-info {
    padding: 0 15px 15px;
    flex: 1;
    display: flex;
    flex-direction: column;
}

.projet-card-address {
    font-size: 0.8rem;
    color: #8a8f98;
    margin-bottom: 12px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.projet-card-address i {
    margin-right: 5px;
    color: #0d6efd;
}

.projet-card-stats {
    display: flex;
    flex-direction: column;
    gap: 6px;
    margin-top: auto;
}

.projet-card-stat {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 0.8rem;
}

.projet-card-stat .label {
    color: #6c757d;
}

.projet-card-stat .value {
    font-weight: 600;
    color: #fff;
}

/* Responsive adjustments */
@media (max-width: 576px) {
    .projet-card-title {
        font-size: 0.95rem;
    }

    .projet-card-stats {
        gap: 4px;
    }

    .projet-card-stat {
        font-size: 0.75rem;
    }
}
</style>

<script>
function changerVue(vue) {
    const vueListe = document.getElementById('vueListe');
    const vueGrille = document.getElementById('vueGrille');
    const btnListe = document.getElementById('btnVueListe');
    const btnGrille = document.getElementById('btnVueGrille');

    if (vue === 'liste') {
        vueListe.classList.remove('d-none');
        vueGrille.classList.add('d-none');
        btnListe.classList.remove('btn-outline-secondary');
        btnListe.classList.add('btn-primary');
        btnGrille.classList.remove('btn-primary');
        btnGrille.classList.add('btn-outline-secondary');
    } else {
        vueListe.classList.add('d-none');
        vueGrille.classList.remove('d-none');
        btnListe.classList.remove('btn-primary');
        btnListe.classList.add('btn-outline-secondary');
        btnGrille.classList.remove('btn-outline-secondary');
        btnGrille.classList.add('btn-primary');
    }

    // Sauvegarder la préférence sur le serveur
    fetch('<?= url('/api/save-vue-preference.php') ?>', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ vue: vue })
    })
    .then(response => response.json())
    .then(data => {
        if (!data.success) {
            console.error('Erreur sauvegarde préférence:', data.error);
        }
    })
    .catch(error => {
        console.error('Erreur réseau:', error);
    });
}
</script>

<?php include '../../includes/footer.php'; ?>
