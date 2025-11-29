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
        <a class="navbar-brand d-flex align-items-center" href="<?= $isAdmin ? '/admin/index.php' : '/employe/index.php' ?>">
            <i class="bi bi-house-door-fill me-2"></i>
            <?= APP_NAME ?>
        </a>
        
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarMain">
            <span class="navbar-toggler-icon"></span>
        </button>
        
        <div class="collapse navbar-collapse" id="navbarMain">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <?php if ($isAdmin): ?>
                    <!-- Menu Admin -->
                    <li class="nav-item">
                        <a class="nav-link <?= strpos($currentUri, '/admin/index.php') !== false ? 'active' : '' ?>" 
                           href="/admin/index.php">
                            <i class="bi bi-speedometer2"></i> Tableau de bord
                        </a>
                    </li>
                    
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle <?= strpos($currentUri, '/admin/projets/') !== false ? 'active' : '' ?>" 
                           href="#" data-bs-toggle="dropdown">
                            <i class="bi bi-building"></i> Projets
                        </a>
                        <ul class="dropdown-menu">
                            <li>
                                <a class="dropdown-item" href="/admin/projets/liste.php">
                                    <i class="bi bi-list-ul"></i> Liste des projets
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item" href="/admin/projets/nouveau.php">
                                    <i class="bi bi-plus-circle"></i> Nouveau projet
                                </a>
                            </li>
                        </ul>
                    </li>
                    
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle <?= strpos($currentUri, '/admin/factures/') !== false ? 'active' : '' ?>" 
                           href="#" data-bs-toggle="dropdown">
                            <i class="bi bi-receipt"></i> Factures
                            <?php 
                            $countEnAttente = getFacturesEnAttenteCount($pdo);
                            if ($countEnAttente > 0): 
                            ?>
                                <span class="badge bg-danger"><?= $countEnAttente ?></span>
                            <?php endif; ?>
                        </a>
                        <ul class="dropdown-menu">
                            <li>
                                <a class="dropdown-item" href="/admin/factures/liste.php">
                                    <i class="bi bi-list-ul"></i> Toutes les factures
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item" href="/admin/factures/approuver.php">
                                    <i class="bi bi-check2-square"></i> À approuver
                                    <?php if ($countEnAttente > 0): ?>
                                        <span class="badge bg-danger"><?= $countEnAttente ?></span>
                                    <?php endif; ?>
                                </a>
                            </li>
                        </ul>
                    </li>
                    
                    <li class="nav-item">
                        <a class="nav-link <?= strpos($currentUri, '/admin/investisseurs/') !== false ? 'active' : '' ?>" 
                           href="/admin/investisseurs/liste.php">
                            <i class="bi bi-people"></i> Investisseurs
                        </a>
                    </li>
                    
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">
                            <i class="bi bi-gear"></i> Administration
                        </a>
                        <ul class="dropdown-menu">
                            <li>
                                <a class="dropdown-item <?= strpos($currentUri, '/admin/utilisateurs/') !== false ? 'active' : '' ?>" 
                                   href="/admin/utilisateurs/liste.php">
                                    <i class="bi bi-person-badge"></i> Utilisateurs
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item <?= strpos($currentUri, '/admin/categories/') !== false ? 'active' : '' ?>" 
                                   href="/admin/categories/liste.php">
                                    <i class="bi bi-tags"></i> Catégories
                                </a>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item <?= strpos($currentUri, '/admin/rapports/') !== false ? 'active' : '' ?>" 
                                   href="/admin/rapports/index.php">
                                    <i class="bi bi-file-earmark-bar-graph"></i> Rapports
                                </a>
                            </li>
                        </ul>
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
                <?php endif; ?>
            </ul>
            
            <!-- User menu -->
            <ul class="navbar-nav">
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">
                        <i class="bi bi-person-circle"></i>
                        <?= e(getCurrentUserName()) ?>
                        <span class="badge <?= $isAdmin ? 'bg-danger' : 'bg-secondary' ?>">
                            <?= $isAdmin ? 'Admin' : 'Employé' ?>
                        </span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li>
                            <span class="dropdown-item-text text-muted">
                                <small><?= e($_SESSION['user_email'] ?? '') ?></small>
                            </span>
                        </li>
                        <li><hr class="dropdown-divider"></li>
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
