// Service Worker for Taxes Québec Calculator
// Version 2 - Network first strategy
const CACHE_NAME = 'taxes-qc-v2';

// Install event - skip waiting to activate immediately
self.addEventListener('install', (event) => {
    self.skipWaiting();
});

// Activate event - clean ALL old caches and take control
self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((cacheNames) => {
            return Promise.all(
                cacheNames
                    .filter((name) => name.startsWith('taxes-qc-'))
                    .map((name) => caches.delete(name))
            );
        }).then(() => self.clients.claim())
    );
});

// Fetch event - NETWORK FIRST, cache as fallback only
self.addEventListener('fetch', (event) => {
    // Only handle GET requests for our pages
    if (event.request.method !== 'GET') return;

    // Only handle requests to our domain
    const url = new URL(event.request.url);
    if (!url.pathname.includes('calculateur-taxes') &&
        !url.pathname.includes('tax-icon') &&
        !url.pathname.includes('manifest-taxes')) {
        return;
    }

    event.respondWith(
        // Try network first
        fetch(event.request)
            .then((response) => {
                // Got a good response, cache it for offline
                if (response && response.status === 200) {
                    const responseToCache = response.clone();
                    caches.open(CACHE_NAME).then((cache) => {
                        cache.put(event.request, responseToCache);
                    });
                }
                return response;
            })
            .catch(() => {
                // Network failed, try cache
                return caches.match(event.request).then((cachedResponse) => {
                    if (cachedResponse) {
                        return cachedResponse;
                    }
                    // Nothing in cache either
                    return new Response('Hors ligne - Rechargez quand connecté', {
                        status: 503,
                        headers: { 'Content-Type': 'text/plain; charset=utf-8' }
                    });
                });
            })
    );
});
