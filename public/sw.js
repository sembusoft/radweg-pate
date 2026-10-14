// Versionsnummer = APP_VERSION aus version.php (CI prüft das, scripts/check.mjs).
const CACHE_NAME = 'radwegpate-3.7.4';

// App-Shell ohne ?v=-Parameter: index.php hängt ?v=<Version> an, der Abgleich
// läuft unten mit ignoreSearch. Eine neue Version erkennt der Browser an der
// geänderten CACHE_NAME-Zeile dieser Datei.
const SHELL = [
  './',
  './assets/style.css',
  './assets/app.js',
  './assets/icon.svg',
  './assets/icons.svg',
  './assets/theme-init.js',
  './assets/icon-192.png',
  './assets/vendor/leaflet-1.9.4/leaflet.css',
  './assets/vendor/leaflet-1.9.4/leaflet.js',
  './manifest.webmanifest'
];

self.addEventListener('install', e => e.waitUntil(
  caches.open(CACHE_NAME).then(c => c.addAll(SHELL)).then(() => self.skipWaiting())
));

self.addEventListener('activate', e => e.waitUntil(
  caches.keys()
    .then(keys => Promise.all(keys.filter(k => k.startsWith('radwegpate-') && k !== CACHE_NAME).map(k => caches.delete(k))))
    .then(() => self.clients.claim())
));

self.addEventListener('fetch', e => {
  const req = e.request;
  if (req.method !== 'GET' || !req.url.startsWith(self.location.origin)) return;
  // API (inkl. Fotos und GPX) nie aus dem Service-Worker-Cache: geschützte Daten.
  if (new URL(req.url).pathname.endsWith('/api.php')) return;

  // Seitenaufruf: zuerst Netz (neue Version sofort sichtbar), offline die Shell.
  if (req.mode === 'navigate') {
    e.respondWith(fetch(req).catch(() => caches.match('./')));
    return;
  }

  // Statische Dateien: aus dem Cache, sonst Netz (und für später ablegen).
  e.respondWith(
    caches.match(req, { ignoreSearch: true }).then(hit => hit || fetch(req).then(res => {
      if (res.ok && res.type === 'basic') {
        const copy = res.clone();
        caches.open(CACHE_NAME).then(c => c.put(req, copy));
      }
      return res;
    }))
  );
});
