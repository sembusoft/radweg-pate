# CLAUDE.md — Radwegpate

PWA für Radwegpaten (Touren aus KML, Kontrollfahrten, Mängel mit Foto/GPS, GPX für Komoot).
PHP 8.1+ ohne Datenbank (JSON-Dateien), Vanilla JS, Leaflet. Produktiv: radweg-pate.de (all-inkl).
Ansprechpartner: Volker. **Antworten auf Deutsch, knapp: Ergebnis zuerst, Begründung kurz.**

## Zuerst lesen

1. `PROJEKTANWEISUNGEN.md` — Regeln des Hauses. Bei Widerspruch gilt sie.
2. `docs/DESIGN_SYSTEM.md` — **mobile first**, Tokens, vier Themes, Schriftgrößen, Icons.
3. `docs/DEPLOY.md` — Auslieferung über GitHub Actions.

## Immer

- Mobile first, 390 px; Desktop nicht optimieren.
- Farben nur über Tokens, Schrift nur in `rem`, Trefferflächen ≥ 44 px, Icons nur aus dem Tabler-Sprite.
- Nach Änderung an JS/CSS/Icons/index.php: `APP_VERSION` (`public/version.php`) und `CACHE_NAME`
  (`public/sw.js`) gemeinsam erhöhen.
- Vor dem Push: `.\rp.ps1 test` (Docker; sonst `node scripts/check.mjs`). Lokal starten: `.\rp.ps1 start` → http://localhost:8090.
- Befehle für Volkers Rechner in PowerShell. Arbeitsordner `C:\Dev\Radweg-Pate`, Branch `main`.
- Keine Geheimnisse ins Repo; `config.php` und `radwegpate_private/` bleiben auf dem Server.
