</main>
    
    <!-- Footer -->
    <?php if (isLoggedIn()): ?>
    <footer class="footer mt-auto py-3 bg-light">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center">
                <span class="text-muted">&copy; <?= date('Y') ?> <?= APP_NAME ?> v<?= APP_VERSION ?></span>
                <span class="text-muted">
                    <i class="bi bi-person-circle"></i> 
                    <?= e(getCurrentUserName()) ?>
                </span>
            </div>
        </div>
    </footer>
    <?php endif; ?>

    <!-- Bootstrap 5 JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Flatpickr Date Picker -->
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/fr.js"></script>
    
    <!-- Custom JS -->
    <script src="<?= BASE_PATH ?>/assets/js/app.js"></script>
    
    <?php if (isset($extraJs)): ?>
        <?= $extraJs ?>
    <?php endif; ?>

    <!-- Afficher la page une fois tout chargé -->
    <script>document.documentElement.style.visibility = 'visible';</script>
</body>
</html>
