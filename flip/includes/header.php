<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle ?? 'Flip Manager') ?> - <?= APP_NAME ?></title>
    
    <!-- Mode sombre: appliquer immédiatement pour éviter flash -->
    <script>
    (function() {
        var dm = localStorage.getItem('darkMode');
        if (dm === 'true' || (!dm && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            document.documentElement.classList.add('dark-mode');
        }
    })();
    </script>
    <style>
    html.dark-mode { background-color: #1a1d21; }
    html.dark-mode body { background-color: #1a1d21; }
    </style>
    
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
