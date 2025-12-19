<?php
/**
 * Barre de navigation - Responsive optimisée
 * Flip Manager
 */

// Déterminer la page active
$currentUri = $_SERVER['REQUEST_URI'];
$isAdmin = isAdmin();
?>

<style>
/* Logo Flip Effect */
.logo-flip {
    display: inline-block;
    position: relative;
    text-decoration: none;
    perspective: 1000px;
}

.logo-flip .logo-inner {
    display: inline-block;
    position: relative;
    transition: transform 0.5s;
    transform-style: preserve-3d;
}

.logo-flip:hover .logo-inner {
    transform: rotateX(180deg);
}

.logo-flip .logo-front,
.logo-flip .logo-back {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    backface-visibility: hidden;
    -webkit-backface-visibility: hidden;
}

.logo-flip .logo-front {
    position: relative;
}

.logo-flip .logo-back {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    transform: rotateX(180deg);
    justify-content: center;
    background: transparent;
    font-weight: bold;
    letter-spacing: 2px;
}
</style>

<nav class="navbar navbar-expand-lg navbar-dark fixed-top">
    <div class="container-fluid">
        <!-- Logo/Titre à gauche avec effet flip -->
        <a class="navbar-brand logo-flip" href="<?= $isAdmin ? url('/admin/index.php') : url('/employe/index.php') ?>">
            <span class="logo-inner">
                <span class="logo-front">
                    <i class="bi bi-house-door-fill"></i>
                    <span class="d-none d-sm-inline">FLIP THE</span>
                </span>
                <span class="logo-back">
                    MONEY
                </span>
            </span>
        </a>

        <!-- Bouton langue mobile (à côté du menu hamburger) - seulement pour employés -->
        <?php if (!$isAdmin): ?>
            <a href="<?= url('/set-language.php?lang=' . (getCurrentLanguage() === 'fr' ? 'es' : 'fr')) ?>"
               class="btn btn-outline-light btn-sm d-lg-none me-2">
                <?= getCurrentLanguage() === 'fr' ? '🇪🇸 ES' : '🇫🇷 FR' ?>
            </a>
        <?php endif; ?>

        <button class="navbar-toggler py-1 px-2" type="button" data-bs-toggle="collapse" data-bs-target="#navbarMain">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="navbarMain">
            <!-- Menu centré -->
            <ul class="navbar-nav mx-auto mb-2 mb-lg-0">
                <?php if ($isAdmin): ?>
                    <!-- Menu Admin -->
                    <li class="nav-item">
                        <a class="nav-link px-2 <?= strpos($currentUri, '/admin/index.php') !== false ? 'active' : '' ?>"
                           href="<?= url('/admin/index.php') ?>" title="Tableau de bord">
                            <i class="bi bi-speedometer2"></i>
                            <span class="nav-text-short d-none d-xxl-inline"> Accueil</span>
                            <span class="nav-text-full"> Accueil</span>
                        </a>
                    </li>

                    <li class="nav-item">
                        <a class="nav-link px-2 <?= strpos($currentUri, '/admin/projets/') !== false ? 'active' : '' ?>"
                           href="<?= url('/admin/projets/liste.php') ?>" title="Projets">
                            <i class="bi bi-building"></i>
                            <span class="nav-text-short d-none d-xl-inline"> Projets</span>
                            <span class="nav-text-full"> Projets</span>
                        </a>
                    </li>

                    <?php if (!empty($_SESSION['last_project_id'])): ?>
                    <li class="nav-item">
                        <a class="nav-link px-2"
                           href="<?= url('/admin/projets/detail.php?id=' . $_SESSION['last_project_id']) ?>"
                           title="<?= e($_SESSION['last_project_name'] ?? 'Projet récent') ?>">
                            <i class="bi bi-arrow-return-right text-warning"></i>
                            <span class="nav-text-short d-none d-xl-inline text-warning"> <?= e(mb_substr($_SESSION['last_project_name'] ?? 'Récent', 0, 10)) ?></span>
                            <span class="nav-text-full text-warning"> <?= e($_SESSION['last_project_name'] ?? 'Récent') ?></span>
                        </a>
                    </li>
                    <?php endif; ?>

                    <li class="nav-item">
                        <?php $countEnAttente = getFacturesEnAttenteCount($pdo); ?>
                        <a class="nav-link px-2 <?= strpos($currentUri, '/admin/factures/') !== false ? 'active' : '' ?>"
                           href="<?= url('/admin/factures/liste.php') ?>" title="Factures">
                            <i class="bi bi-receipt"></i>
                            <span class="nav-text-short d-none d-xl-inline"> Fact.</span>
                            <span class="nav-text-full"> Factures</span>
                            <?php if ($countEnAttente > 0): ?>
                                <span class="badge bg-danger"><?= $countEnAttente ?></span>
                            <?php endif; ?>
                        </a>
                    </li>

                    <li class="nav-item">
                        <a class="nav-link px-2 <?= strpos($currentUri, '/admin/investisseurs/') !== false ? 'active' : '' ?>"
                           href="<?= url('/admin/investisseurs/liste.php') ?>" title="Investisseurs">
                            <i class="bi bi-people"></i>
                            <span class="nav-text-short d-none d-xxl-inline"> Invest.</span>
                            <span class="nav-text-full"> Investisseurs</span>
                        </a>
                    </li>

                    <li class="nav-item">
                        <a class="nav-link px-2 <?= strpos($currentUri, '/admin/temps/') !== false ? 'active' : '' ?>"
                           href="<?= url('/admin/temps/liste.php') ?>" title="Temps">
                            <i class="bi bi-clock-history"></i>
                            <span class="nav-text-short d-none d-xl-inline"> Temps</span>
                            <span class="nav-text-full"> Feuilles de temps</span>
                            <?php
                            $countHeuresAttente = getHeuresEnAttenteCount($pdo);
                            if ($countHeuresAttente > 0):
                            ?>
                                <span class="badge bg-warning text-dark"><?= $countHeuresAttente ?></span>
                            <?php endif; ?>
                        </a>
                    </li>

                    <li class="nav-item">
                        <a class="nav-link px-2 <?= strpos($currentUri, '/admin/photos/') !== false ? 'active' : '' ?>"
                           href="<?= url('/admin/photos/liste.php') ?>" title="Photos">
                            <i class="bi bi-camera"></i>
                            <span class="nav-text-short d-none d-xl-inline"> Photos</span>
                            <span class="nav-text-full"> Photos</span>
                        </a>
                    </li>

                    <li class="nav-item">
                        <a class="nav-link px-2 <?= strpos($currentUri, '/admin/comparables/') !== false ? 'active' : '' ?>"
                           href="<?= url('/admin/comparables/index.php') ?>" title="Analyse IA">
                            <i class="bi bi-robot"></i>
                            <span class="nav-text-short d-none d-xl-inline"> IA</span>
                            <span class="nav-text-full"> Comparable X</span>
                        </a>
                    </li>

                    <li class="nav-item">
                        <a class="nav-link px-2 <?= strpos($currentUri, '/admin/utilisateurs/') !== false || strpos($currentUri, '/admin/categories/') !== false || strpos($currentUri, '/admin/rapports/') !== false || strpos($currentUri, '/admin/configuration/') !== false ? 'active' : '' ?>"
                           href="<?= url('/admin/utilisateurs/liste.php') ?>" title="Administration">
                            <i class="bi bi-gear"></i>
                            <span class="nav-text-short d-none d-xxl-inline"> Admin</span>
                            <span class="nav-text-full"> Administration</span>
                        </a>
                    </li>

                <?php else: ?>
                    <!-- Menu Employé -->
                    <li class="nav-item">
                        <a class="nav-link px-2 <?= strpos($currentUri, '/employe/index.php') !== false ? 'active' : '' ?>"
                           href="<?= url('/employe/index.php') ?>" title="<?= __('dashboard') ?>">
                            <i class="bi bi-speedometer2"></i>
                            <span class="nav-text-short d-none d-lg-inline"> <?= __('home') ?></span>
                            <span class="nav-text-full"> <?= __('home') ?></span>
                        </a>
                    </li>

                    <li class="nav-item">
                        <a class="nav-link px-2 <?= strpos($currentUri, '/employe/nouvelle-facture.php') !== false ? 'active' : '' ?>"
                           href="<?= url('/employe/nouvelle-facture.php') ?>" title="<?= __('new_invoice') ?>">
                            <i class="bi bi-plus-circle"></i>
                            <span class="nav-text-short d-none d-lg-inline"> <?= __('new') ?></span>
                            <span class="nav-text-full"> <?= __('new_invoice') ?></span>
                        </a>
                    </li>

                    <li class="nav-item">
                        <a class="nav-link px-2 <?= strpos($currentUri, '/employe/mes-factures.php') !== false ? 'active' : '' ?>"
                           href="<?= url('/employe/mes-factures.php') ?>" title="<?= __('my_invoices') ?>">
                            <i class="bi bi-receipt"></i>
                            <span class="nav-text-short d-none d-lg-inline"> <?= __('invoices') ?></span>
                            <span class="nav-text-full"> <?= __('my_invoices') ?></span>
                        </a>
                    </li>

                    <li class="nav-item">
                        <a class="nav-link px-2 <?= strpos($currentUri, '/employe/feuille-temps.php') !== false ? 'active' : '' ?>"
                           href="<?= url('/employe/feuille-temps.php') ?>" title="<?= __('timesheet') ?>">
                            <i class="bi bi-clock-history"></i>
                            <span class="nav-text-short d-none d-lg-inline"> <?= __('timesheet') ?></span>
                            <span class="nav-text-full"> <?= __('timesheet') ?></span>
                        </a>
                    </li>

                    <li class="nav-item">
                        <a class="nav-link px-2 <?= strpos($currentUri, '/employe/photos.php') !== false ? 'active' : '' ?>"
                           href="<?= url('/employe/photos.php') ?>" title="<?= __('photos') ?>">
                            <i class="bi bi-camera"></i>
                            <span class="nav-text-short d-none d-lg-inline"> <?= __('photos') ?></span>
                            <span class="nav-text-full"> <?= __('photos') ?></span>
                        </a>
                    </li>
                <?php endif; ?>
            </ul>

            <!-- Contrôles à droite -->
            <ul class="navbar-nav ms-auto">
                <!-- User menu -->
                <li class="nav-item dropdown">
                    <?php
                    $userName = getCurrentUserName();
                    $initials = '';
                    $nameParts = explode(' ', trim($userName));
                    if (count($nameParts) >= 2) {
                        $initials = strtoupper(substr($nameParts[0], 0, 1) . substr($nameParts[count($nameParts)-1], 0, 1));
                    } else {
                        $initials = strtoupper(substr($userName, 0, 2));
                    }
                    $avatarColors = ['#4285f4', '#ea4335', '#fbbc05', '#34a853', '#673ab7', '#e91e63', '#00bcd4', '#ff5722'];
                    $colorIndex = abs(crc32($userName)) % count($avatarColors);
                    $avatarColor = $avatarColors[$colorIndex];
                    ?>
                    <a class="nav-link dropdown-toggle py-1 px-2" href="#" data-bs-toggle="dropdown" title="<?= e($userName) ?>">
                        <span class="user-avatar" style="background-color: <?= $avatarColor ?>;">
                            <?= $initials ?>
                        </span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li>
                            <span class="dropdown-item-text text-muted">
                                <strong><?= e(getCurrentUserName()) ?></strong><br>
                                <small><?= e($_SESSION['user_email'] ?? '') ?></small>
                            </span>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <a class="dropdown-item text-danger" href="<?= url('/logout.php') ?>">
                                <i class="bi bi-box-arrow-right"></i> <?= __('logout') ?>
                            </a>
                        </li>
                    </ul>
                </li>
            </ul>
        </div>
    </div>
</nav>
