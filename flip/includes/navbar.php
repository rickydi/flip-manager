<?php
/**
 * Barre de navigation - Responsive optimisée
 * Flip Manager
 */

// Déterminer la page active
$currentUri = $_SERVER['REQUEST_URI'];
$isAdmin = isAdmin();
?>

<nav class="navbar navbar-expand-lg navbar-dark fixed-top">
    <div class="container-fluid">
        <!-- Logo/Titre à gauche -->
        <a class="navbar-brand" href="<?= $isAdmin ? url('/admin/index.php') : url('/employe/index.php') ?>">
            <i class="bi bi-house-door-fill"></i>
            <span class="d-none d-sm-inline"><?= APP_NAME ?></span>
        </a>


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
                        <a class="nav-link px-2 <?= strpos($currentUri, '/admin/utilisateurs/') !== false || strpos($currentUri, '/admin/categories/') !== false || strpos($currentUri, '/admin/rapports/') !== false ? 'active' : '' ?>"
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
                           href="<?= url('/employe/index.php') ?>" title="Tableau de bord">
                            <i class="bi bi-speedometer2"></i>
                            <span class="nav-text-short d-none d-lg-inline"> Accueil</span>
                            <span class="nav-text-full"> Accueil</span>
                        </a>
                    </li>

                    <li class="nav-item">
                        <a class="nav-link px-2 <?= strpos($currentUri, '/employe/nouvelle-facture.php') !== false ? 'active' : '' ?>"
                           href="<?= url('/employe/nouvelle-facture.php') ?>" title="Nouvelle facture">
                            <i class="bi bi-plus-circle"></i>
                            <span class="nav-text-short d-none d-lg-inline"> Nouvelle</span>
                            <span class="nav-text-full"> Nouvelle facture</span>
                        </a>
                    </li>

                    <li class="nav-item">
                        <a class="nav-link px-2 <?= strpos($currentUri, '/employe/mes-factures.php') !== false ? 'active' : '' ?>"
                           href="<?= url('/employe/mes-factures.php') ?>" title="Mes factures">
                            <i class="bi bi-receipt"></i>
                            <span class="nav-text-short d-none d-lg-inline"> Factures</span>
                            <span class="nav-text-full"> Mes factures</span>
                        </a>
                    </li>

                    <li class="nav-item">
                        <a class="nav-link px-2 <?= strpos($currentUri, '/employe/feuille-temps.php') !== false ? 'active' : '' ?>"
                           href="<?= url('/employe/feuille-temps.php') ?>" title="Temps">
                            <i class="bi bi-clock-history"></i>
                            <span class="nav-text-short d-none d-lg-inline"> Temps</span>
                            <span class="nav-text-full"> Feuille de temps</span>
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
                                <i class="bi bi-box-arrow-right"></i> Déconnexion
                            </a>
                        </li>
                    </ul>
                </li>
            </ul>
        </div>
    </div>
</nav>
