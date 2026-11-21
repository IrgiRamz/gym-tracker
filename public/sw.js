/*
 * Service Worker - Gym Tracker by Neil
 * - Aset statis (CSS, JS, font, ikon, CDN): stale-while-revalidate (cepat, tetap segar).
 * - Halaman dinamis, JSON (/jadwal, /wa-status, dll.), dan semua POST/PATCH/DELETE:
 *   TIDAK di-cache. Selalu ke jaringan supaya data jadwal/sesi akurat.
 *   Hanya jika benar-benar offline saat membuka halaman, tampilkan /offline.html.
 */
const VERSION = 'gt-v1';
const STATIC_CACHE = `gt-static-${VERSION}`;
const OFFLINE_URL = '/offline.html';

const STATIC_PATHS = ['/css/', '/js/', '/images/'];
const STATIC_HOSTS = [
    'cdn.jsdelivr.net',
    'cdnjs.cloudflare.com',
    'fonts.googleapis.com',
    'fonts.gstatic.com',
];

self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(STATIC_CACHE)
            .then(cache => cache.addAll([OFFLINE_URL, '/images/icon-192.png']))
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys()
            .then(keys => Promise.all(keys.filter(k => k.startsWith('gt-static-') && k !== STATIC_CACHE).map(k => caches.delete(k))))
            .then(() => self.clients.claim())
    );
});

function isStatic(url) {
    if (url.origin === self.location.origin) {
        return STATIC_PATHS.some(p => url.pathname.startsWith(p)) || url.pathname === '/manifest.json';
    }
    return STATIC_HOSTS.includes(url.hostname);
}

self.addEventListener('fetch', event => {
    const req = event.request;
    if (req.method !== 'GET') return; // form POST/PATCH/DELETE selalu langsung ke server

    const url = new URL(req.url);

    // Aset statis: stale-while-revalidate
    if (isStatic(url)) {
        event.respondWith(
            caches.open(STATIC_CACHE).then(async cache => {
                const cached = await cache.match(req);
                const network = fetch(req).then(res => {
                    if (res && (res.ok || res.type === 'opaque')) cache.put(req, res.clone());
                    return res;
                }).catch(() => cached);
                return cached || network;
            })
        );
        return;
    }

    // Pembukaan halaman (navigasi penuh): network-first, fallback ke halaman offline
    if (req.mode === 'navigate') {
        event.respondWith(
            fetch(req).catch(() => caches.match(OFFLINE_URL))
        );
    }
    // Selain itu (JSON/AJAX, kunjungan Turbo): tidak disentuh service worker
});
