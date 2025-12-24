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

    <!-- Initialisation des tooltips Bootstrap -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            tooltipTriggerList.map(function(el) { return new bootstrap.Tooltip(el); });
        });
    </script>

    <!-- Flatpickr Date Picker -->
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/fr.js"></script>
    
    <!-- Custom JS -->
    <script src="<?= BASE_PATH ?>/assets/js/app.js"></script>
    
    <?php if (isset($extraJs)): ?>
        <?= $extraJs ?>
    <?php endif; ?>

    <!-- Service Worker PWA + Install Prompt -->
    <script>
        // Enregistrer le Service Worker
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                navigator.serviceWorker.register('<?= BASE_PATH ?>/sw.js')
                    .then(reg => console.log('SW enregistré:', reg.scope))
                    .catch(err => console.log('SW erreur:', err));
            });
        }

        // Gestion du prompt d'installation PWA
        let deferredPrompt;
        const installContainer = document.getElementById('pwa-install-container');
        const installBtn = document.getElementById('pwa-install-btn');

        window.addEventListener('beforeinstallprompt', (e) => {
            // Empêcher Chrome d'afficher son prompt automatique
            e.preventDefault();
            // Sauvegarder l'événement pour plus tard
            deferredPrompt = e;
            // Afficher notre bouton d'installation
            if (installContainer) {
                installContainer.style.display = 'block';
            }
        });

        if (installBtn) {
            installBtn.addEventListener('click', async () => {
                if (!deferredPrompt) return;

                // Afficher le prompt d'installation
                deferredPrompt.prompt();

                // Attendre la réponse
                const { outcome } = await deferredPrompt.userChoice;
                console.log('Installation:', outcome);

                // Réinitialiser
                deferredPrompt = null;
                installContainer.style.display = 'none';
            });
        }

        // Cacher le bouton si déjà installé
        window.addEventListener('appinstalled', () => {
            console.log('PWA installée!');
            if (installContainer) {
                installContainer.style.display = 'none';
            }
            deferredPrompt = null;
        });

        // Détecter si on est en mode standalone (déjà installé)
        if (window.matchMedia('(display-mode: standalone)').matches) {
            console.log('App lancée en mode standalone');
            if (installContainer) {
                installContainer.style.display = 'none';
            }
        }
    </script>

</body>
</html>
