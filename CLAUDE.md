# CLAUDE.md — Radwegpate

PWA für Radwegpaten (Touren aus KML, Kontrollfahrten, Mängel mit Foto/GPS, GPX für Komoot).
PHP 8.1+ mit MariaDB 10.11 (Tabellen-Präfix `radpate_`, gemeinsame DB), Vanilla JS, Leaflet. Produktiv: radweg-pate.de (all-inkl).
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
- Keine Geheimnisse ins Repo: Zugangsdaten nur in `public/.env` (Vorlage `.env.example`); `.env` und
  `radwegpate_private/` bleiben auf dem Server. `config.php` steht im Repo und liest die `.env`.
- Schema nur über neue Dateien `public/db/migrations/NNN_name.sql` – nur erweiternd, idempotent,
  jede Tabelle mit Präfix `radpate_` (Regeln: `public/db/README.md`).
