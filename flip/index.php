<?php
/**
 * Page de connexion
 * Flip Manager
 */

require_once 'config.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

// Si déjà connecté, rediriger vers le dashboard approprié
if (isLoggedIn()) {
    if (isAdmin()) {
        redirect('/admin/index.php');
    } else {
        redirect('/employe/index.php');
    }
}

$error = '';

// Traitement du formulaire de connexion
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($email) || empty($password)) {
        $error = 'Veuillez remplir tous les champs.';
    } else {
        $user = verifyCredentials($pdo, $email, $password);
        
        if ($user) {
            loginUser($user);
            updateLastLogin($pdo, $user['id']);
            logActivity($pdo, $user['id'], 'login', null, 'Connexion réussie');

            if ($user['role'] === 'admin') {
                redirect('/admin/index.php');
            } else {
                redirect('/employe/index.php');
            }
        } else {
            $error = 'Identifiant ou mot de passe incorrect.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connexion - <?= APP_NAME ?></title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    
    <!-- Custom CSS -->
    <link href="<?= BASE_PATH ?>/assets/css/style.css" rel="stylesheet">

    <style>
        /* Bouton Se connecter plus gros sur mobile */
        @media (max-width: 768px) {
            .btn-primary.btn-lg {
                padding: 1rem 1.5rem;
                font-size: 1.25rem;
            }
        }

        /* Animations initiales (cachées avant Motion) */
        .login-logo { opacity: 0; }
        .login-card form { opacity: 0; }
        .login-card .alert { opacity: 0; }

        /* Animation shake pour erreur */
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            10%, 30%, 50%, 70%, 90% { transform: translateX(-5px); }
            20%, 40%, 60%, 80% { transform: translateX(5px); }
        }
        .shake { animation: shake 0.6s ease-in-out; }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-card fade-in">
            <div class="login-logo">
                <i class="bi bi-house-door-fill"></i>
                <h1><?= APP_NAME ?></h1>
            </div>
            
            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    <?= e($error) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="">
                <div class="mb-3">
                    <label for="email" class="form-label">Email</label>
                    <div class="input-group">
                        <span class="input-group-text">
                            <i class="bi bi-envelope"></i>
                        </span>
                        <input type="email" 
                               class="form-control form-control-lg" 
                               id="email" 
                               name="email" 
                               placeholder="votre@email.com"
                               value="<?= e($_POST['email'] ?? '') ?>"
                               required 
                               autofocus>
                    </div>
                </div>
                
                <div class="mb-4">
                    <label for="password" class="form-label">Mot de passe</label>
                    <div class="input-group">
                        <span class="input-group-text">
                            <i class="bi bi-lock"></i>
                        </span>
                        <input type="password" 
                               class="form-control form-control-lg" 
                               id="password" 
                               name="password" 
                               placeholder="••••••••"
                               required>
                        <button class="btn btn-outline-secondary" type="button" id="togglePassword">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                </div>
                
                <button type="submit" class="btn btn-primary btn-lg w-100">
                    <i class="bi bi-box-arrow-in-right me-2"></i>
                    Se connecter
                </button>
            </form>
            
            <div class="mt-4 text-center text-muted">
                <small>
                    <i class="bi bi-shield-lock me-1"></i>
                    Connexion sécurisée
                </small>
            </div>
        </div>
    </div>
    
    <!-- Bootstrap 5 JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

    <!-- Motion One (animations) -->
    <script src="https://cdn.jsdelivr.net/npm/motion@11.11.13/dist/motion.min.js"></script>

    <script>
        // Toggle password visibility
        document.getElementById('togglePassword').addEventListener('click', function() {
            const password = document.getElementById('password');
            const icon = this.querySelector('i');

            if (password.type === 'password') {
                password.type = 'text';
                icon.className = 'bi bi-eye-slash';
            } else {
                password.type = 'password';
                icon.className = 'bi bi-eye';
            }
        });

        // Animations avec Motion One
        document.addEventListener('DOMContentLoaded', function() {
            const { animate, stagger } = Motion;

            // Animation du logo (bounce)
            animate('.login-logo',
                { opacity: [0, 1], y: [-30, 0], scale: [0.8, 1] },
                { duration: 0.6, easing: [0.22, 1, 0.36, 1] }
            );

            // Animation du formulaire (slide up)
            animate('.login-card form',
                { opacity: [0, 1], y: [20, 0] },
                { duration: 0.5, delay: 0.3, easing: 'ease-out' }
            );

            // Animation de l'alerte erreur (shake)
            const alert = document.querySelector('.login-card .alert');
            if (alert) {
                animate(alert,
                    { opacity: [0, 1], x: [-10, 10, -10, 10, 0] },
                    { duration: 0.5, delay: 0.2 }
                );
            }

            // Animation du bouton au hover
            const submitBtn = document.querySelector('button[type="submit"]');
            submitBtn.addEventListener('mouseenter', () => {
                animate(submitBtn, { scale: 1.02 }, { duration: 0.2 });
            });
            submitBtn.addEventListener('mouseleave', () => {
                animate(submitBtn, { scale: 1 }, { duration: 0.2 });
            });

            // Animation des inputs au focus
            document.querySelectorAll('.form-control').forEach(input => {
                input.addEventListener('focus', () => {
                    animate(input.closest('.input-group'),
                        { scale: [1, 1.02, 1] },
                        { duration: 0.3 }
                    );
                });
            });
        });
    </script>
</body>
</html>
