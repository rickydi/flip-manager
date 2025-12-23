// Flip Manager Service Worker
const CACHE_NAME = 'flip-manager-v1';

// Fichiers à mettre en cache
const STATIC_ASSETS = [
    '/flip/',
    '/flip/assets/css/style.css',
    '/flip/assets/js/app.js',
    '/flip/assets/images/icon-192.png',
    '/flip/assets/images/icon-512.png'
];

// Installation - mise en cache des assets statiques
self.addEventListener('install', (event) => {
    console.log('[SW] Installation...');
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then((cache) => {
                console.log('[SW] Mise en cache des assets statiques');
                return cache.addAll(STATIC_ASSETS);
            })
            .catch((err) => {
                console.log('[SW] Erreur cache:', err);
            })
    );
    self.skipWaiting();
});

// Activation - nettoyage des anciens caches
self.addEventListener('activate', (event) => {
    console.log('[SW] Activation...');
    event.waitUntil(
        caches.keys().then((cacheNames) => {
            return Promise.all(
                cacheNames
                    .filter((name) => name !== CACHE_NAME)
                    .map((name) => {
                        console.log('[SW] Suppression ancien cache:', name);
                        return caches.delete(name);
                    })
            );
        })
    );
    self.clients.claim();
});

// Fetch - stratégie Network First (online prioritaire)
self.addEventListener('fetch', (event) => {
    // Ignorer les requêtes non-GET
    if (event.request.method !== 'GET') return;

    // Ignorer les requêtes API/PHP (toujours réseau)
    if (event.request.url.includes('.php')) {
        return;
    }

    event.respondWith(
        fetch(event.request)
            .then((response) => {
                // Mettre en cache la réponse fraîche
                if (response.status === 200) {
                    const responseClone = response.clone();
                    caches.open(CACHE_NAME).then((cache) => {
                        cache.put(event.request, responseClone);
                    });
                }
                return response;
            })
            .catch(() => {
                // Si offline, utiliser le cache
                return caches.match(event.request).then((cachedResponse) => {
                    if (cachedResponse) {
                        return cachedResponse;
                    }
                    // Page offline par défaut si rien en cache
                    if (event.request.mode === 'navigate') {
                        return caches.match('/flip/');
                    }
                });
            })
    );
});
