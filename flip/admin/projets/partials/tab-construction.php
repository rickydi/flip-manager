    <div class="tab-pane fade <?= $tab === 'construction' ? 'show active' : '' ?>" id="construction" role="tabpanel">
        <div class="card">
            <div class="card-header">
                <ul class="nav nav-tabs card-header-tabs" id="constructionSubTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="electrical-tab" data-bs-toggle="tab" data-bs-target="#electrical-content" type="button" role="tab">
                            <i class="bi bi-lightning-charge me-1"></i>Électrique
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="drawing-tab" data-bs-toggle="tab" data-bs-target="#drawing-content" type="button" role="tab">
                            <i class="bi bi-pencil-square me-1"></i>Plan 2D
                        </button>
                    </li>
                    <!-- Futurs onglets: Plomberie, etc. -->
                </ul>
            </div>
            <div class="card-body">
                <div class="tab-content" id="constructionSubTabsContent">
                    <!-- Onglet Électrique -->
                    <div class="tab-pane fade show active" id="electrical-content" role="tabpanel">
                        <?php include __DIR__ . '/../../../modules/construction/electrical/component.php'; ?>
                    </div>
                    <!-- Onglet Plan 2D -->
                    <div class="tab-pane fade" id="drawing-content" role="tabpanel">
                        <?php include __DIR__ . '/../../../modules/construction/electrical/drawing.php'; ?>
                    </div>
                    <!-- Futurs contenus: Plomberie, etc. -->
                </div>
            </div>
        </div>
    </div>
