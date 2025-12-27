    <div class="tab-pane fade <?= $tab === 'photos' ? 'show active' : '' ?>" id="photos" role="tabpanel">
        <?php
        // Extraire les employés et catégories uniques pour les filtres
        $photosEmployes = !empty($photosProjet) ? array_unique(array_column($photosProjet, 'employe_nom')) : [];
        $photosCategoriesFilter = !empty($photosProjet) ? array_unique(array_filter(array_column($photosProjet, 'description'))) : [];
        sort($photosEmployes);
        sort($photosCategoriesFilter);
        ?>

        <!-- Barre compacte : Filtres + Actions -->
        <div class="d-flex flex-wrap align-items-center gap-2 mb-3 p-2 rounded" style="background: rgba(255,255,255,0.03);">
            <!-- Icône -->
            <div class="d-flex align-items-center px-3 py-1 rounded" style="background: rgba(13,110,253,0.15);">
                <i class="bi bi-camera text-primary me-2"></i>
                <strong class="text-primary" id="photosCount"><?= count($photosProjet) ?></strong>
                <span class="text-muted ms-1">photos</span>
            </div>

            <!-- Séparateur -->
            <div class="vr mx-1 d-none d-md-block" style="height: 24px;"></div>

            <!-- Filtres -->
            <select class="form-select form-select-sm" id="filtrePhotosEmploye" onchange="filtrerPhotos()" style="width: auto; min-width: 140px;">
                <option value="">Tous employés</option>
                <?php foreach ($photosEmployes as $emp): ?>
                    <option value="<?= e($emp) ?>"><?= e($emp) ?></option>
                <?php endforeach; ?>
            </select>

            <select class="form-select form-select-sm" id="filtrePhotosCategorie" onchange="filtrerPhotos()" style="width: auto; min-width: 150px;">
                <option value="">Toutes catégories</option>
                <?php foreach ($photosCategoriesFilter as $cat): ?>
                    <option value="<?= e($cat) ?>"><?= e($cat) ?></option>
                <?php endforeach; ?>
            </select>

            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="resetFiltresPhotos()" title="Réinitialiser">
                <i class="bi bi-x-circle"></i>
            </button>

            <!-- Spacer + Actions à droite -->
            <div class="ms-auto d-flex gap-2">
                <?php if (count($photosProjet) > 1): ?>
                <button type="button" class="btn btn-outline-primary btn-sm" id="btnReorganiser" onclick="toggleReorganisation()">
                    <i class="bi bi-arrows-move me-1"></i>Réorganiser
                </button>
                <button type="button" class="btn btn-outline-secondary btn-sm" id="btnSelectionner" onclick="toggleSelectionMode()">
                    <i class="bi bi-check2-square me-1"></i>Sélectionner
                </button>
                <?php endif; ?>
                <button type="button" class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#modalAjoutPhoto">
                    <i class="bi bi-plus me-1"></i>Ajouter
                </button>
            </div>
            <!-- Barre de sélection (cachée par défaut) -->
            <div id="selectionBar" class="d-none mt-2 p-2 bg-dark rounded d-flex align-items-center gap-2">
                <button type="button" class="btn btn-outline-light btn-sm" onclick="selectAllPhotos()">
                    <i class="bi bi-check-all me-1"></i>Tout sélectionner
                </button>
                <button type="button" class="btn btn-outline-light btn-sm" onclick="deselectAllPhotos()">
                    <i class="bi bi-x-lg me-1"></i>Tout désélectionner
                </button>
                <span class="text-white ms-2"><span id="selectedCount">0</span> sélectionnée(s)</span>
                <button type="button" class="btn btn-danger btn-sm ms-auto" id="btnDeleteSelected" onclick="deleteSelectedPhotos()" disabled>
                    <i class="bi bi-trash me-1"></i>Supprimer la sélection
                </button>
                <button type="button" class="btn btn-secondary btn-sm" onclick="toggleSelectionMode()">
                    Annuler
                </button>
            </div>
        </div>

        <style>
            .photo-grid-col {
                overflow: hidden;
                min-width: 0;
            }
            .photo-grid-col .position-relative {
                overflow: hidden;
            }
            .photo-thumb {
                width: 100%;
                max-width: 100%;
                aspect-ratio: 4/3;
                object-fit: cover;
                border-radius: 0.375rem;
                display: block;
            }
            .video-thumb-container {
                width: 100%;
                max-width: 100%;
                aspect-ratio: 4/3;
                background: #1a1d21;
                display: flex;
                align-items: center;
                justify-content: center;
                position: relative;
                border-radius: 0.375rem;
                overflow: hidden;
            }
            .video-thumb-container video {
                width: 100%;
                height: 100%;
                object-fit: cover;
                position: absolute;
                top: 0;
                left: 0;
            }
            /* Checkbox pour sélection */
            .photo-checkbox {
                display: none;
                position: absolute;
                top: 5px;
                left: 5px;
                z-index: 10;
                width: 22px;
                height: 22px;
                cursor: pointer;
            }
            .selection-mode .photo-checkbox {
                display: block;
            }
            .selection-mode .photo-item {
                cursor: pointer;
            }
            .selection-mode .photo-item.selected .position-relative {
                outline: 3px solid #0d6efd;
                outline-offset: -3px;
                border-radius: 0.375rem;
            }
            .selection-mode .photo-item.selected .position-relative::after {
                content: '';
                position: absolute;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(13, 110, 253, 0.2);
                pointer-events: none;
                border-radius: 0.375rem;
                z-index: 5;
            }
            .selection-mode .btn-delete-single {
                display: none !important;
            }
            /* Plus de colonnes sur très grands écrans */
            @media (min-width: 1400px) {
                .photo-grid-col { flex: 0 0 auto; width: 12.5%; overflow: hidden; min-width: 0; } /* 8 colonnes */
            }
            @media (min-width: 1800px) {
                .photo-grid-col { flex: 0 0 auto; width: 10%; overflow: hidden; min-width: 0; } /* 10 colonnes */
            }
            @media (min-width: 2200px) {
                .photo-grid-col { flex: 0 0 auto; width: 8.333%; overflow: hidden; min-width: 0; } /* 12 colonnes */
            }
        </style>
        <?php if (empty($photosProjet)): ?>
            <div class="alert alert-info">
                <i class="bi bi-info-circle me-2"></i>Aucune photo pour ce projet. Cliquez sur "Ajouter" pour en téléverser.
            </div>
        <?php else: ?>
            <div class="row g-2" id="photosGrid">
                <?php foreach ($photosProjet as $photo):
                    $extension = strtolower(pathinfo($photo['fichier'], PATHINFO_EXTENSION));
                    $isVideo = in_array($extension, ['mp4', 'mov', 'avi', 'mkv', 'webm', 'm4v']);
                    $mediaUrl = url('/serve-photo.php?file=' . urlencode($photo['fichier']));
                ?>
                <div class="col-6 col-md-3 col-lg-2 photo-grid-col photo-item" data-id="<?= $photo['id'] ?>" data-employe="<?= e($photo['employe_nom']) ?>" data-categorie="<?= e($photo['description'] ?? '') ?>" onclick="togglePhotoSelection(this, event)">
                    <div class="position-relative">
                        <!-- Checkbox pour sélection multiple -->
                        <input type="checkbox" class="photo-checkbox" data-photo-id="<?= $photo['id'] ?>" onclick="event.stopPropagation(); togglePhotoSelection(this.closest('.photo-item'), event)">
                        <a href="<?= $mediaUrl ?>" target="_blank" class="d-block photo-link">
                            <?php if ($isVideo): ?>
                                <div class="video-thumb-container">
                                    <video src="<?= $mediaUrl ?>" muted preload="metadata"></video>
                                    <div style="position:absolute;z-index:2;background:rgba(0,0,0,0.6);border-radius:50%;width:40px;height:40px;display:flex;align-items:center;justify-content:center;">
                                        <i class="bi bi-play-fill text-white" style="font-size:1.5rem;margin-left:3px;"></i>
                                    </div>
                                </div>
                            <?php else: ?>
                                <img src="<?= $mediaUrl ?>&thumb=1" alt="Photo" class="photo-thumb" loading="lazy">
                            <?php endif; ?>
                        </a>
                        <!-- Bouton suppression -->
                        <form method="POST" class="position-absolute top-0 end-0 btn-delete-single" style="margin:3px;">
                            <?php csrfField(); ?>
                            <input type="hidden" name="action" value="delete_photo">
                            <input type="hidden" name="photo_id" value="<?= $photo['id'] ?>">
                            <button type="submit" class="btn btn-danger btn-sm" style="padding:2px 5px;font-size:10px;line-height:1;"
                                    onclick="return confirm('Supprimer cette photo ?')">
                                <i class="bi bi-trash"></i>
                            </button>
                        </form>
                        <!-- Info overlay sur l'image -->
                        <div class="position-absolute bottom-0 start-0 end-0 bg-dark bg-opacity-75 text-white p-1 rounded-bottom" style="font-size:0.7rem;">
                            <div class="d-flex justify-content-between align-items-center">
                                <small><?= formatDate($photo['date_prise']) ?></small>
                                <small><?= e($photo['employe_nom']) ?></small>
                            </div>
                            <?php if (!empty($photo['description'])): ?>
                                <small class="d-block text-truncate"><?= e($photo['description']) ?></small>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <?php if ($hasMorePhotos): ?>
            <div class="text-center mt-3">
                <a href="?id=<?= $projetId ?>&tab=photos&photos_page=<?= $photosPage + 1 ?>"
                   class="btn btn-outline-primary">
                    <i class="bi bi-arrow-down-circle me-1"></i>
                    Voir plus de photos (<?= count($photosProjet) ?> / <?= $totalPhotos ?>)
                </a>
            </div>
            <?php elseif ($totalPhotos > 0): ?>
            <div class="text-center mt-3 text-muted small">
                <i class="bi bi-check-circle me-1"></i>
                <?= $totalPhotos ?> photo<?= $totalPhotos > 1 ? 's' : '' ?> affichée<?= $totalPhotos > 1 ? 's' : '' ?>
            </div>
            <?php endif; ?>
        <?php endif; ?>

        <!-- Formulaire caché pour suppression multiple -->
        <form id="bulkDeleteForm" method="POST" style="display:none;">
            <?php csrfField(); ?>
            <input type="hidden" name="action" value="delete_photos_bulk">
            <input type="hidden" name="photo_ids" id="bulkDeletePhotoIds" value="">
        </form>
    </div><!-- Fin TAB PHOTOS -->
