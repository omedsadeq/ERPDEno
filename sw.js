/**
 * Service Worker — Lezan Dental Clinics PWA
 * سیستەمی زیرەکی کلینیکی ددان
 *
 * Caching policy (SECURITY-CRITICAL — do not change lightly):
 *   - PHP pages / HTML / API / AJAX responses are NEVER cached.
 *     All patient, visit, payment and user data always comes from the network.
 *   - Only safe same-origin static assets are cached (CSS, JS, icons, fonts, sounds).
 *   - /uploads/ (patient photos, x-ray images) is explicitly EXCLUDED from caching.
 *   - Navigation requests are network-first; if the network is down, the
 *     self-contained offline page is shown instead of a broken cached page.
 *
 * Update flow: bump CACHE_VERSION on every deploy that changes static assets.
 * Old caches are deleted on activate; skipWaiting + clients.claim ensure the
 * new worker takes over immediately, so users never keep broken cached files.
 */

const CACHE_VERSION = 'denthisab-v7'; // v7: accounting approval center rollout (Task 16)
const STATIC_CACHE = CACHE_VERSION + '-static';
const OFFLINE_URL = 'offline.html'; // relative to SW scope

/* Precache the minimal shell needed to show the offline page + install UI */
const PRECACHE_ASSETS = [
    OFFLINE_URL,
    'manifest.json',
    'assets/css/fonts.css',
    'fonts/NRT%20Font/NRT-Reg.woff',
    'assets/css/style.css',
    'assets/css/responsive.css',
    'assets/js/notifications.js',
    'assets/js/lightbox.js',
    'assets/images/icons/icon-192.png',
    'assets/images/icons/icon-512.png',
    'assets/images/icons/apple-touch-icon.png',
    'assets/images/icons/favicon-32.png',
    'assets/images/logo.png',
    // Vendor libraries, previously loaded from cdnjs/jsdelivr. Self-hosted so
    // the app renders without a round trip to three external hosts — and so
    // it still works offline, which a CDN link never did.
    'assets/vendor/fontawesome/css/all.min.css',
    'assets/vendor/fontawesome/webfonts/fa-solid-900.woff2',
    'assets/vendor/fontawesome/webfonts/fa-regular-400.woff2',
    'assets/vendor/jquery/jquery.min.js',
    'assets/vendor/sweetalert2/sweetalert2.all.min.js'
];

/* File extensions considered "safe static assets" for runtime caching */
const STATIC_EXTENSIONS = /\.(css|js|png|jpg|jpeg|gif|svg|webp|ico|woff2?|ttf|eot|wav|mp3)$/i;

/* Paths that must never be cached, whatever their extension */
const NEVER_CACHE = /(\/uploads\/|\/api\/|\/ajax\/|\.php)/i;

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(STATIC_CACHE)
            .then((cache) => cache.addAll(PRECACHE_ASSETS))
            .catch(() => { /* partial precache failure must not block install */ })
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(
                keys.filter((key) => key !== STATIC_CACHE).map((key) => caches.delete(key))
            ))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;

    // Only GET requests are ever considered; POST/PUT etc. always hit network.
    if (request.method !== 'GET') return;

    const url = new URL(request.url);

    // 1. Page navigations (HTML documents): network ONLY, never cached.
    //    Offline → show the self-contained offline fallback page.
    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request).catch(() =>
                caches.match(OFFLINE_URL).then((cached) =>
                    cached || new Response('Offline', { status: 503, headers: { 'Content-Type': 'text/plain' } })
                )
            )
        );
        return;
    }

    // 2. Cross-origin (CDN fonts/libs): pass through to network untouched.
    if (url.origin !== self.location.origin) return;

    // 3. Sensitive/dynamic paths: pass through to network untouched.
    if (NEVER_CACHE.test(url.pathname)) return;

    // 4. Same-origin static assets: stale-while-revalidate.
    //    Serve from cache instantly, refresh the cache in the background so
    //    the next load picks up deployed changes.
    if (STATIC_EXTENSIONS.test(url.pathname)) {
        event.respondWith(
            caches.open(STATIC_CACHE).then((cache) =>
                cache.match(request).then((cached) => {
                    const networkFetch = fetch(request)
                        .then((response) => {
                            if (response && response.status === 200 && response.type === 'basic') {
                                cache.put(request, response.clone());
                            }
                            return response;
                        })
                        .catch(() => cached); // offline: fall back to cache
                    return cached || networkFetch;
                })
            )
        );
    }
    // Anything else: default network behavior (not cached).
});

/* Allow the app to clear caches (e.g. right after logout) */
self.addEventListener('message', (event) => {
    if (!event.data) return;
    if (event.data.type === 'SKIP_WAITING') {
        self.skipWaiting();
    }
    if (event.data.type === 'CLEAR_CACHES') {
        event.waitUntil(
            caches.keys().then((keys) => Promise.all(keys.map((key) => caches.delete(key))))
        );
    }
});
