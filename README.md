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

## Lokal testen

Voraussetzung: PHP ≥ 8.1 (mit gd, fileinfo, mbstring) und Node ≥ 20.

```powershell
Copy-Item public\config.example.php public\config.php   # Token und APP_DATA_DIR anpassen
node scripts\check.mjs
php -S 127.0.0.1:8080 -t public
# zweites Fenster, mit dem Token aus config.php und einem LEEREN Datenordner:
$env:INSTALL_TOKEN="..."; $env:DATA_DIR="C:\Dev\radweg-test-data"; node scripts\smoke-test.mjs
```

Der Rauchtest richtet die App ein – also nur gegen einen frischen Datenordner laufen lassen.

## Neue Version

1. `APP_VERSION` in `public/version.php` erhöhen.
2. `CACHE_NAME` in `public/sw.js` auf `radwegpate-<gleiche Version>` setzen.
3. Commit + Push → `Tests` muss grün werden.
4. Deploy von Hand, siehe [docs/DEPLOY.md](docs/DEPLOY.md).

`check.mjs` bricht ab, wenn 1. und 2. nicht zusammenpassen.
