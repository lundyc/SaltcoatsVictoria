/*
 * Hub service worker.
 *
 * Keeps the Hub's fixed look (stylesheets, scripts, fonts, icons) on the phone so pages open quickly
 * and the app shell loads on weak signal. Hub pages hold private club data and change constantly, so
 * they are NEVER stored: they always come from the network. With no signal a navigation shows the
 * simple offline page instead. Forms, saves and anything that is not a plain GET are never touched.
 * The ticket scanner has its own service worker (/scan/sw.js) and its own offline list.
 */
const CACHE = 'hub-shell-v1';
const OFFLINE = '/admin/offline.html';
const PRECACHE = [
  OFFLINE,
  '/admin/icon-192.png',
  '/admin/assets/vendor/bootstrap/bootstrap.min.css',
  '/admin/assets/vendor/fontawesome/css/all.min.css',
  '/admin/assets/vendor/fonts/inter.css',
  '/admin/assets/vendor/jquery/jquery.min.js',
  '/admin/assets/vendor/bootstrap/bootstrap.bundle.min.js',
];

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(CACHE).then((cache) => cache.addAll(PRECACHE)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((key) => key !== CACHE).map((key) => caches.delete(key))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const request = event.request;
  if (request.method !== 'GET') return;
  const url = new URL(request.url);
  if (url.origin !== self.location.origin) return;
  const path = url.pathname;

  if (request.mode === 'navigate') {
    event.respondWith(
      fetch(request).catch(() => caches.open(CACHE).then((cache) => cache.match(OFFLINE)).then((hit) => hit || Response.error()))
    );
    return;
  }

  // Fixed files only: never PHP output (colour sheets, data feeds) or uploads.
  const isStatic = path.startsWith('/admin/assets/') && !path.endsWith('.php');
  if (!isStatic) return;

  // Serve the saved copy at once, refresh it in the background.
  event.respondWith(
    caches.open(CACHE).then((cache) =>
      cache.match(request).then((hit) => {
        const refresh = fetch(request).then((response) => {
          if (response && response.ok) cache.put(request, response.clone());
          return response;
        }).catch(() => hit);
        return hit || refresh;
      })
    )
  );
});
