/*
 * Ticket Scanner service worker.
 *
 * Caches the scanner's static files (Bootstrap, icons, logo, the offline engine) so the page's look
 * loads at once on weak stadium signal. The scan API is never cached: a ticket is always checked live
 * (or from the phone's own saved list when there is no signal, see offline.js).
 *
 * The scanner page itself is fetched from the network every time. A copy of the last good one is kept
 * ONLY as a fallback for when the phone is offline and the page is reloaded.
 */
const CACHE = 'ticket-scanner-v5';
const PAGES = 'ticket-scanner-pages-v1';
const STATIC = [
  '/admin/assets/vendor/bootstrap/bootstrap.min.css',
  '/admin/assets/vendor/fontawesome/css/all.min.css',
  '/admin/assets/vendor/fontawesome/webfonts/fa-solid-900.woff2',
  '/scan/logo-96.png',
  '/scan/icon-192.png',
  '/scan/offline.js',
  '/scan/jsQR.js',
];

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(CACHE).then((cache) => cache.addAll(STATIC)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((key) => key !== CACHE && key !== PAGES).map((key) => caches.delete(key))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const request = event.request;
  if (request.method !== 'GET') return;
  const url = new URL(request.url);
  if (url.origin !== self.location.origin) return;
  const path = url.pathname;

  // The scanner page: network first, a saved copy only when the network is unreachable.
  if (request.mode === 'navigate' && (path === '/scan/' || path === '/scan')) {
    event.respondWith(
      fetch(request).then((response) => {
        // A redirect means the login page: never keep that as the scanner.
        if (response && response.ok && !response.redirected) {
          const copy = response.clone();
          caches.open(PAGES).then((cache) => cache.put(request, copy));
        }
        return response;
      }).catch(() => caches.open(PAGES).then((cache) => cache.match(request).then((hit) => hit || cache.match('/scan/'))).then((hit) => hit || Response.error()))
    );
    return;
  }

  if (!STATIC.includes(path) && !path.startsWith('/admin/assets/vendor/fontawesome/webfonts/')) return;

  // Cache first for the fixed static files; refresh the copy in the background.
  // Pages ask for e.g. offline.js?v=123, so the copy is kept under the plain path and matched without the query.
  const key = new Request(url.origin + path);
  event.respondWith(
    caches.open(CACHE).then((cache) =>
      cache.match(key).then((hit) => {
        const refresh = fetch(request).then((response) => {
          if (response && response.ok) cache.put(key, response.clone());
          return response;
        }).catch(() => hit);
        return hit || refresh;
      })
    )
  );
});
