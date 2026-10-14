# Projektanweisungen Radwegpate

**Stand:** 14.10.2026 · Version 3.6.0
**Gilt für:** alles in diesem Repository. Bei Widerspruch zu älteren Notizen gilt diese Datei;
für Gestaltung zusätzlich [`docs/DESIGN_SYSTEM.md`](docs/DESIGN_SYSTEM.md).

Radwegpate ist eine PWA für **eine** Radwegpatin bzw. einen Radwegpaten: Touren aus einer
My-Maps-KML anzeigen, Kontrollfahrten dokumentieren, Mängel mit Foto und GPS erfassen und bis zur
Erledigung verfolgen, GPX für Naviki exportieren. Produktiv unter <https://radweg-pate.de> (all-inkl).

---

## 1. Grundsätze

1. **Mobile first.** Die App wird unterwegs am Handy benutzt, mit einer Hand, oft in der Sonne,
   manchmal mit Handschuhen. Jede Ansicht wird zuerst für **390 px Breite** gebaut und geprüft.
2. **Nicht für den Desktop optimiert.** Breitere Bildschirme bekommen dieselbe einspaltige
   Oberfläche, mittig mit höchstens 720 px. Sie darf dort nicht kaputtgehen — mehr nicht.
   Kein Mehrspalten-Layout, keine Hover-only-Funktionen, keine Tastenkürzel als einziger Weg.
3. **Lesbar und bedienbar vor hübsch.** Schrift mindestens 13 px (Fließtext 16 px), Trefferflächen
   mindestens 44 px, Kontrast nach WCAG AA (Hochkontrast: AAA). Details in `docs/DESIGN_SYSTEM.md`.
4. **Keine erfundenen Wege.** Strecken kommen ausschließlich aus der KML; GPX-Kombinationen nur bei
   exakt anschließenden Segmenten. Diese Zusage bleibt in jeder Erweiterung erhalten.
5. **Daten gehören dem Paten.** Keine Analytics, keine externen Skripte, keine Weitergabe.
   Externe Dienste nur, wo unvermeidbar und benannt (OSM-Kacheln, eingebettetes My Maps).

## 2. Aufbau

```
public/                 genau das, was auf den Server geht (Webroot radweg-pate.de)
  index.php             Oberfläche + Security-Header (CSP)
  api.php               JSON-API (?action=…), Login, Dateien, GPX
  version.php           EINZIGE Stelle der Versionsnummer
  sw.js                 Service Worker, CACHE_NAME = radwegpate-<Version>
  .htaccess             Sperren (config, Datenordner) und Cache-Header
  config.example.php    Vorlage; config.php liegt NUR auf dem Server bzw. lokal
  assets/
    app.js              gesamte Oberflächenlogik (Vanilla JS, keine Abhängigkeiten)
    style.css           Stilvorlage nach docs/DESIGN_SYSTEM.md
    theme-init.js       setzt Farbschema/Schriftgröße vor dem ersten Zeichnen
    icons.svg           Tabler-Icon-Sprite (erzeugt, nicht von Hand bearbeiten)
    vendor/leaflet-1.9.4/
  radwegpate_private/   Datenordner (auf dem Server; nie im Repo, nie im Deploy)
scripts/                check.mjs, contrast.mjs, smoke-test.mjs, build-icons.mjs
docs/                   DEPLOY.md, DESIGN_SYSTEM.md
.github/workflows/      test.yml (jeder Push), deploy.yml (nur von Hand)
```

**Kein Build-Schritt.** Was im Repo steht, läuft so auf dem Server. Keine npm-Abhängigkeiten zur
Laufzeit; Werkzeuge (Icons) werden nur zum Erzeugen von Dateien benutzt, die dann eingecheckt sind.

## 3. Daten und Sicherheit

- **Keine Datenbank** (noch): JSON-Dateien in `radwegpate_private/` mit Dateisperre und atomarem
  Schreiben (`update_json()`). Jede Änderung über `update_json`, nie direkt `file_put_contents`.
- **Der Datenordner liegt im Webroot** und ist dreifach gesperrt (`public/.htaccess`,
  eigene `.htaccess` im Ordner — legt der Deploy an —, Deploy-Prüfung von außen).
  Sein Name `radwegpate_private` steht an vier Stellen: `config.php`, `public/.htaccess`,
  `.deployignore`, `scripts/check.mjs` (+ `deploy.yml`). Nur gemeinsam ändern.
- **CSP ohne Ausnahmen:** keine Inline-Skripte, keine `style="…"`-Attribute, keine Event-Attribute
  (`onclick=`), keine fremden Skript- oder Stilquellen. `check.mjs` prüft das.
- Jede Eingabe wird in `api.php` geprüft (Länge, Format, erlaubte Werte), jede Ausgabe in `app.js`
  mit `esc()` maskiert. IDs per Regex prüfen.
- POST braucht das CSRF-Token (`X-CSRF-Token`); neue Aktionen nach `require_login()` einordnen.
- Fotos: EXIF wird entfernt (Browser kodiert neu, Server streift JPEG-Metadaten zusätzlich ab).
- Geheimnisse (Einrichtungscode) nur in `config.php` auf dem Server — nie ins Repo, nie in
  `projekt_dokumente/` oder Chats.

## 4. Oberfläche — die harten Regeln

Ausführlich und mit Begründung in [`docs/DESIGN_SYSTEM.md`](docs/DESIGN_SYSTEM.md). Kurzfassung:

1. **Nur Tokens.** Farben ausschließlich über `var(--…)`; ein Festwert in einem Baustein ist ein Fehler.
2. **Vier Themes plus „Automatisch“:** `light`, `dark`, `hc-light`, `hc-dark`. Jede neue Farbe
   bekommt einen Wert in **allen vier** Token-Blöcken und ein Paar in `scripts/contrast.mjs`.
3. **Schrift in `rem`**, nie `px` — sonst greift die Schriftgrößen-Einstellung nicht.
   Fließtext 1rem (16 px), Mindestgröße 0.8125rem (13 px). Eingabefelder 1rem (kein iOS-Zoom).
4. **Trefferflächen ≥ 44 px**, Hauptknöpfe 48 px, die Plus-Taste 56 px.
5. **Icons nur aus dem Tabler-Sprite** (`ic('name')` in JS, `<svg class="ic"><use href="assets/icons.svg#name"/></svg>`
   im HTML). Keine Emojis oder Unicode-Pfeile als Icons. Neues Icon → `scripts/build-icons.mjs`.
6. **Einspaltig, keine seitliche Verschiebung.** Grid-Spalten immer `minmax(0, 1fr)`,
   Formularelemente `min-width: 0; max-width: 100%`.
7. **Navigation unten** (Tab-Leiste), Kennzahlen als Badges in der Kopfleiste, Dialoge als Blatt
   von unten mit festem Fuß. **Zahnrad** = Darstellung & Konto (Gerät/Zugang),
   **Tab Einstellungen** = Touren, KML, Datensicherung (Daten).
9. **Geringe Radien:** 8 px für Karten/Kästen, 6 px für Knöpfe/Felder/Badges — nur über
   `--radius` / `--radius-sm`.
8. **Text, nicht nur Farbe:** Zustände („offen“, „erledigt“) stehen immer auch als Wort da.

## 5. Arbeitsweise

- **Antworten und Doku auf Deutsch**, knapp: Ergebnis zuerst, Begründung kurz.
  Code-Kommentare derzeit Deutsch (wie der Bestand).
- **Befehle für Volkers Rechner in PowerShell**, nicht Bash.
- Arbeitsordner: `C:\Dev\Radweg-Pate`, Repository `github.com/sembusoft/radweg-pate`, Branch `main`.
- **Nach jeder Änderung an JS, CSS, Icons oder index.php:** `APP_VERSION` in `public/version.php`
  **und** `CACHE_NAME` in `public/sw.js` erhöhen (gleiche Nummer). Sonst bleibt die alte App auf
  dem Handy hängen. `check.mjs` bricht bei Abweichung ab.
- Neue Datei, die offline da sein muss → in `SHELL` von `sw.js` eintragen.
- Alte Daten bleiben lesbar: Formatänderungen an `entries.json` & Co. nur erweiternd, mit
  Rückfall für ältere Einträge (siehe `entryIssues()` / Legacy-Zweig in `issueStatus`).

## 6. Prüfen vor dem Push

```powershell
.\rp.ps1 test     # Docker: check.mjs + Rauchtest hinter Apache inkl. .htaccess-Sperren
```

Ohne Docker genügt notfalls `node scripts\check.mjs`. Lokal ausprobieren: `.\rp.ps1 start`
→ <http://localhost:8090> (Details in `README.md`).

Die CI (`test.yml`) macht zusätzlich den Rauchtest gegen einen echten PHP-Server (PHP 8.1 und 8.3).
Oberflächenänderungen außerdem am Handy (oder 390 px im Browser) in **Hell und Kontrast dunkel**
und mit **Schriftgröße „Sehr groß“** ansehen.

## 7. Ausliefern

Nur über GitHub Actions → **Deploy (radweg-pate.de)**, siehe [`docs/DEPLOY.md`](docs/DEPLOY.md).
Kein FTP-Upload von Programmdateien. Von Hand per FTP nur `config.php` (einmalig).
