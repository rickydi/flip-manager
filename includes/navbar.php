<?php
/**
 * Barre de navigation
 * Flip Manager
 */

// Déterminer la page active
$currentUri = $_SERVER['REQUEST_URI'];
$isAdmin = isAdmin();
?>

<nav class="navbar navbar-expand-lg navbar-dark bg-dark fixed-top">
    <div class="container-fluid">
        <!-- Logo/Titre à gauche -->
        <a class="navbar-brand d-flex align-items-center" href="<?= $isAdmin ? '/admin/index.php' : '/employe/index.php' ?>">
            <i class="bi bi-house-door-fill me-1"></i>
            <span class="d-none d-md-inline"><?= APP_NAME ?></span>
            <span class="d-md-none">Flip</span>
        </a>
        
        <!-- Contrôles rapides sur mobile (avant toggler) -->
        <div class="d-flex d-lg-none align-items-center me-2">
            <button type="button" class="dark-mode-toggle" onclick="toggleDarkMode()" title="Mode sombre/clair" style="font-size:1rem;">
                <i class="bi bi-moon-fill"></i>
            </button>
        </div>
        
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarMain">
            <span class="navbar-toggler-icon"></span>
        </button>
        
        <div class="collapse navbar-collapse" id="navbarMain">
            <!-- Menu centré -->
            <ul class="navbar-nav mx-auto mb-2 mb-lg-0">
                <?php if ($isAdmin): ?>
                    <!-- Menu Admin -->
                    <li class="nav-item">
                        <a class="nav-link <?= strpos($currentUri, '/admin/index.php') !== false ? 'active' : '' ?>" 
                           href="/admin/index.php" title="Tableau de bord">
                            <i class="bi bi-speedometer2"></i><span class="d-none d-xl-inline"> Tableau de bord</span><span class="d-xl-none d-lg-inline"> Accueil</span>
                        </a>
                    </li>
                    
                    <li class="nav-item">
                        <a class="nav-link <?= strpos($currentUri, '/admin/projets/') !== false ? 'active' : '' ?>" 
                           href="/admin/projets/liste.php">
                            <i class="bi bi-building"></i><span class="d-none d-lg-inline"> Projets</span>
                        </a>
                    </li>
                    
                    <li class="nav-item">
                        <?php $countEnAttente = getFacturesEnAttenteCount($pdo); ?>
                        <a class="nav-link <?= strpos($currentUri, '/admin/factures/') !== false ? 'active' : '' ?>" 
                           href="/admin/factures/liste.php">
                            <i class="bi bi-receipt"></i><span class="d-none d-lg-inline"> Factures</span>
                            <?php if ($countEnAttente > 0): ?>
                                <span class="badge bg-danger"><?= $countEnAttente ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                    
                    <li class="nav-item">
                        <a class="nav-link <?= strpos($currentUri, '/admin/investisseurs/') !== false ? 'active' : '' ?>" 
                           href="/admin/investisseurs/liste.php" title="Investisseurs">
                            <i class="bi bi-people"></i><span class="d-none d-xl-inline"> Investisseurs</span><span class="d-xl-none d-lg-inline"> Invest.</span>
                        </a>
                    </li>
                    
                    <li class="nav-item">
                        <a class="nav-link <?= strpos($currentUri, '/admin/temps/') !== false ? 'active' : '' ?>" 
                           href="/admin/temps/liste.php">
                            <i class="bi bi-clock-history"></i><span class="d-none d-lg-inline"> Temps</span>
                            <?php 
                            $countHeuresAttente = getHeuresEnAttenteCount($pdo);
                            if ($countHeuresAttente > 0): 
                            ?>
                                <span class="badge bg-warning text-dark"><?= $countHeuresAttente ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                    
                    <li class="nav-item">
                        <a class="nav-link <?= strpos($currentUri, '/admin/utilisateurs/') !== false || strpos($currentUri, '/admin/categories/') !== false || strpos($currentUri, '/admin/rapports/') !== false ? 'active' : '' ?>" 
                           href="/admin/utilisateurs/liste.php" title="Administration">
                            <i class="bi bi-gear"></i><span class="d-none d-xl-inline"> Administration</span><span class="d-xl-none d-lg-inline"> Admin</span>
                        </a>
                    </li>
                    
                <?php else: ?>
                    <!-- Menu Employé -->
                    <li class="nav-item">
                        <a class="nav-link <?= strpos($currentUri, '/employe/index.php') !== false ? 'active' : '' ?>" 
                           href="/employe/index.php">
                            <i class="bi bi-speedometer2"></i> Tableau de bord
                        </a>
                    </li>
                    
                    <li class="nav-item">
                        <a class="nav-link <?= strpos($currentUri, '/employe/nouvelle-facture.php') !== false ? 'active' : '' ?>" 
                           href="/employe/nouvelle-facture.php">
                            <i class="bi bi-plus-circle"></i> Nouvelle facture
                        </a>
                    </li>
                    
                    <li class="nav-item">
                        <a class="nav-link <?= strpos($currentUri, '/employe/mes-factures.php') !== false ? 'active' : '' ?>" 
                           href="/employe/mes-factures.php">
                            <i class="bi bi-receipt"></i> Mes factures
                        </a>
                    </li>
                    
                    <li class="nav-item">
                        <a class="nav-link <?= strpos($currentUri, '/employe/feuille-temps.php') !== false ? 'active' : '' ?>" 
                           href="/employe/feuille-temps.php">
                            <i class="bi bi-clock-history"></i> Temps
                        </a>
                    </li>
                <?php endif; ?>
            </ul>
            
            <!-- Contrôles à droite -->
            <ul class="navbar-nav ms-auto">
                <!-- Zoom + Dark mode - caché sur les petits écrans -->
                <li class="nav-item d-none d-xl-flex align-items-center me-2">
                    <button type="button" class="btn btn-outline-light btn-sm me-1" onclick="changeTextSize(-1)" title="Réduire le texte">
                        <i class="bi bi-dash-lg"></i>
                    </button>
                    <span class="text-light small mx-1" id="textSizeIndicator">100%</span>
                    <button type="button" class="btn btn-outline-light btn-sm ms-1" onclick="changeTextSize(1)" title="Agrandir le texte">
                        <i class="bi bi-plus-lg"></i>
                    </button>
                    <span class="text-secondary mx-2">|</span>
                    <button type="button" class="dark-mode-toggle" onclick="toggleDarkMode()" title="Mode sombre/clair" id="darkModeBtn">
                        <i class="bi bi-moon-fill" id="darkModeIcon"></i>
                    </button>
                </li>
                
                <!-- User menu -->
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle py-1" href="#" data-bs-toggle="dropdown">
                        <i class="bi bi-person-circle"></i>
                        <span class="d-none d-xl-inline"><?= e(getCurrentUserName()) ?></span>
                        <span class="badge <?= $isAdmin ? 'bg-danger' : 'bg-secondary' ?> d-none d-lg-inline">
                            <?= $isAdmin ? 'Admin' : 'Employé' ?>
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
                        <!-- Contrôles de zoom dans le menu sur petits écrans -->
                        <li class="d-xl-none px-3 py-2">
                            <div class="d-flex align-items-center justify-content-between">
                                <span class="small text-muted">Taille texte</span>
                                <div>
                                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="changeTextSize(-1)">
                                        <i class="bi bi-dash"></i>
                                    </button>
                                    <span class="mx-1 small" id="textSizeIndicator2">100%</span>
                                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="changeTextSize(1)">
                                        <i class="bi bi-plus"></i>
                                    </button>
                                </div>
                            </div>
                        </li>
                        <li class="d-xl-none"><hr class="dropdown-divider"></li>
                        <li>
                            <a class="dropdown-item text-danger" href="/logout.php">
                                <i class="bi bi-box-arrow-right"></i> Déconnexion
                            </a>
                        </li>
                    </ul>
                </li>
            </ul>
        </div>
    </div>
</nav>
