// Versionsnummer = APP_VERSION aus version.php (CI prüft das, scripts/check.mjs).
const CACHE_NAME = 'radwegpate-3.14.0';
const VERSION = CACHE_NAME.slice('radwegpate-'.length);

// App-Shell MIT ?v=<Version> – genau so, wie index.php die Dateien einbindet.
// Abgeglichen wird exakt (ohne ignoreSearch): eine neue index.php bekommt so nie ein altes
// app.js/style.css aus einem älteren Cache (das ergab in Safari eine leere, schwarze Seite).
const v = p => `${p}?v=${VERSION}`;
const SHELL = [
  './',
  v('./assets/style.css'),
  v('./assets/app.js'),
  v('./assets/theme-init.js'),
  './assets/icon.svg',
  './assets/icons.svg',
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

  // Statische Dateien: exakt aus dem Cache, sonst Netz (und für später ablegen).
  e.respondWith(
    caches.match(req).then(hit => hit || fetch(req).then(res => {
      if (res.ok && res.type === 'basic') {
        const copy = res.clone();
        caches.open(CACHE_NAME).then(c => c.put(req, copy));
      }
      return res;
    }))
  );
});
