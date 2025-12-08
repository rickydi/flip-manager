<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle ?? 'Flip Manager') ?> - <?= APP_NAME ?></title>

    <!-- Anti-flash: couleur de fond immédiate -->
    <style>
    html {
        background-color: #f1f5f9;
    }
    html[data-theme="dark"] {
        background-color: #0f172a;
    }
    </style>
    <script>
    (function() {
        var dm = localStorage.getItem('darkMode');
        if (dm === 'true' || (!dm && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            document.documentElement.setAttribute('data-theme', 'dark');
        }
    })();
    </script>

    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    
    <!-- Flatpickr Date Picker -->
    <link href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/flatpickr/dist/themes/light.css" rel="stylesheet">
    
    <!-- Custom CSS -->
    <link href="<?= BASE_PATH ?>/assets/css/style.css?v=<?= date('YmdHi') ?>" rel="stylesheet">
    
    <?php if (isset($extraCss)): ?>
        <?= $extraCss ?>
    <?php endif; ?>
</head>
<body>
    <?php if (isLoggedIn()): ?>
        <?php include __DIR__ . '/navbar.php'; ?>
    <?php endif; ?>
    
    <main class="<?= isLoggedIn() ? 'main-content' : '' ?>">
