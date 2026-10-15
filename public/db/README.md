# db/ – Datenbank (MariaDB 10.11)

Alles hier läuft **nur auf der Kommandozeile** (Deploy per SSH, lokal über `rp.ps1`).
Von außen ist der Ordner gesperrt (`public/.htaccess` → 404), und jedes Skript bricht
außerhalb der CLI zusätzlich ab.

| Datei              | Zweck |
|--------------------|-------|
| `db.php`           | Verbindung und Helfer (`db()`, `dbq`, `db_row`, `db_all`, `db_val`, `db_tx`, `setting`) – auch von `api.php` genutzt |
| `migrations/*.sql` | Schema, nummeriert `NNN_name.sql` |
| `migrate.php`      | spielt offene Migrationen ein (`--status`: nur anzeigen) |
| `import-json.php`  | einmalige Übernahme der JSON-Dateien von vor 3.9.0 (ohne `--run`: Probelauf) |
| `backup.php`       | Sicherung aller `radpate_`-Tabellen nach `radwegpate_private/backups/` |
| `check.php`        | Zugang prüfen |

## Regeln für Migrationen (wie bei fightReg)

- **Präfix `radpate_`** für jede Tabelle – die Datenbank teilen sich mehrere Projekte.
  Auch Fremdschlüssel-Namen tragen es (`fk_radpate_…`). `scripts/check.mjs` prüft das.
- **Nur erweitern.** Neue Tabellen, Spalten, Indizes, Zeilen. Eine neue Spalte ist `NULL`-fähig
  oder hat eine Vorgabe. Umbenennen/Entfernen erst in einer späteren Migration, wenn kein
  ausgelieferter Code die alte Form mehr liest.
- **Idempotent:** `CREATE TABLE IF NOT EXISTS`, `ADD COLUMN IF NOT EXISTS`, `INSERT IGNORE`.
- **Ohne DELIMITER**, Anweisungsende = `;` am Zeilenende (der Runner trennt dort).
- **Nie eine eingespielte Datei ändern** – `migrate.php --status` meldet geänderte Prüfsummen.
  Korrekturen als neue Datei.
- Zeiten in UTC (`DATETIME`), Zeichensatz `utf8mb4_unicode_ci`, Engine InnoDB.

Stand: Tabelle `radpate_schema_migrations` (Dateiname, sha1, Zeitpunkt).
