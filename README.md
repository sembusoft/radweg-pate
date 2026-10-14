# Radwegpate

PWA für Radwegpaten: Touren aus einer My-Maps-KML anzeigen, Kontrollfahrten
dokumentieren, Mängel mit Fotos und GPS erfassen und bis zur Erledigung
verfolgen, GPX für Komoot exportieren.

Produktiv: <https://radweg-pate.de> (all-inkl). PHP 8.1+, keine Datenbank –
die Daten liegen als JSON-Dateien in einem privaten Ordner außerhalb des Webroots.

## Aufbau

```
public/               → genau das, was auf den Server geht (Webroot radweg-pate.de)
  index.php           App-Oberfläche + Security-Header (CSP)
  api.php             JSON-API (?action=…), Login, Dateien, GPX
  version.php         EINZIGE Stelle für die Versionsnummer
  sw.js               Service Worker – CACHE_NAME muss zur Version passen
  config.example.php  Vorlage; die echte config.php steht nicht im Repo
  assets/             app.js, style.css, Icons, vendor/leaflet-1.9.4
scripts/
  check.mjs           statische Prüfungen (Version, CSP, Icons, SW-Shell, JS-Syntax)
  contrast.mjs        Kontrast aller Farb-Tokens in 4 Themes (WCAG)
  build-icons.mjs     erzeugt assets/icons.svg aus Tabler Icons
  smoke-test.mjs      Rauchtest gegen einen laufenden Server
docs/                 DEPLOY.md, DESIGN_SYSTEM.md, alte Installationsanleitung
PROJEKTANWEISUNGEN.md Regeln des Projekts (mobile first, Sicherheit, Arbeitsweise)
.github/workflows/    test.yml (bei jedem Push), deploy.yml (nur von Hand)
```

## Lokal testen (Docker Desktop)

Apache + PHP 8.3 wie bei all-inkl, `.htaccess` aktiv. Bedienung in PowerShell:

```powershell
.\rp.ps1 start    # http://localhost:8090 – beim ersten Mal wird public\config.php angelegt (Code wird angezeigt)
.\rp.ps1 test     # check.mjs + Rauchtest gegen eine Wegwerf-Instanz, inkl. .htaccess-Sperren
.\rp.ps1 logs     # Apache-/PHP-Protokoll
.\rp.ps1 reset    # lokale Testdaten löschen → Einrichtung beginnt neu
.\rp.ps1 stop
```

- Lokale Daten liegen in `public\radwegpate_private` (gitignored, wird nie deployt).
- Der Test (`rp.ps1 test`) läuft in einer eigenen Instanz mit Datenordner im Arbeitsspeicher –
  deine lokalen Testdaten bleiben unberührt.
- Handy-Ansicht: Browser-Entwicklertools (F12) → Gerätesymbol → 390 px.
- Am echten Handy im WLAN (`http://<PC-IP>:8090`) läuft die App, aber **ohne Service Worker**
  (der braucht HTTPS oder localhost). Offline-Verhalten deshalb nur am PC oder produktiv prüfen.

## Neue Version

1. `APP_VERSION` in `public/version.php` erhöhen.
2. `CACHE_NAME` in `public/sw.js` auf `radwegpate-<gleiche Version>` setzen.
3. Commit + Push → `Tests` muss grün werden.
4. Deploy von Hand, siehe [docs/DEPLOY.md](docs/DEPLOY.md).

`check.mjs` bricht ab, wenn 1. und 2. nicht zusammenpassen.
