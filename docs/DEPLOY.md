# Deployment auf radweg-pate.de (all-inkl)

Ausgeliefert wird nur `public/` per rsync über SSH (Workflow `Deploy`, startet
nur von Hand). Die `.env` (Zugangsdaten) und der Datenordner liegen ausschließlich auf dem
Server und werden nie überschrieben.

Seit 3.9.0 liegen die Daten in **MariaDB** (Tabellen `radpate_…`). Ablauf jedes
Deploys: `db/` übertragen → Zugang prüfen (`db/check.php`) → Sicherung
(`db/backup.php`, nach `radwegpate_private/backups/`) → Migrationen
(`db/migrate.php`) → einmalige Übernahme der alten JSON-Dateien
(`db/import-json.php --run`) → erst dann der Code-Abgleich.

## Einmalige Einrichtung

### 1. all-inkl (KAS)

- Domain `radweg-pate.de` zeigt auf das Verzeichnis `/radweg-pate.de`.
- PHP-Version für die Domain: **8.3** (mindestens 8.1).
- SSH-Zugang: derselbe wie für fightReg genügt, wenn es dasselbe KAS-Konto ist.

### 2. Eigener Deploy-Schlüssel (PowerShell)

```powershell
ssh-keygen -t ed25519 -f $HOME\.ssh\radweg-pate-deploy -C "github-actions radweg-pate" -N '""'
Get-Content $HOME\.ssh\radweg-pate-deploy.pub
```

Den öffentlichen Schlüssel auf dem Server an `~/.ssh/authorized_keys` anhängen.

### 3. Datenordner und .env auf dem Server

Der Datenordner liegt **im Webroot**: `radweg-pate.de/radwegpate_private/`
(Fotos, Datenbank-Sicherungen, die alten JSON-Dateien). Er ist von außen dreifach gesperrt:

1. `public/.htaccess`: `RedirectMatch 404` auf `/radwegpate_private/…`
2. Die App legt im Ordner selbst eine `.htaccess` mit `Require all denied` an.
3. Jeder Deploy-Lauf (auch der Probelauf) ruft `install.json`, `entries.json`
   und den Ordner von außen ab und wird **rot**, wenn eine Antwort 2xx ist.

Der Deploy überträgt und löscht in diesem Ordner nichts (`.deployignore`).
Ordnername nur zusammen mit `public/.htaccess`, `.deployignore` und
`scripts/check.mjs` ändern.

Per SSH (Pfade an das eigene Konto anpassen):

```bash
cd /www/htdocs/w0XXXXXX/radweg-pate.de
mkdir -p radwegpate_private && chmod 700 radwegpate_private   # existiert schon
php -r "echo bin2hex(random_bytes(16)), PHP_EOL;"            # neuen Einrichtungscode erzeugen
nano .env                                                    # Inhalt wie public/.env.example
chmod 600 .env
```

Die `.env` liegt im Webroot neben `config.php` und ist von außen gesperrt
(`public/.htaccess` sperrt alle Punktdateien; der Deploy ruft `/.env` bei jedem Lauf ab
und wird rot bei 2xx). Der Deploy überträgt und löscht sie nie (`.deployignore`).
`config.php` steht seit 3.9.0 **im Repository** (ohne Geheimnisse) und wird mit ausgeliefert;
sie liest die `.env`. Der Datenordner ist dort fest eingetragen.

Inhalt der `.env`:

```ini
INSTALL_TOKEN=…            # nur für eine Neueinrichtung nötig
DB_HOST=localhost
DB_PORT=3306
DB_NAME=d0XXXXXX
DB_USER=d0XXXXXX
DB_PASSWORD="…"            # mit Leerzeichen oder " #" in Anführungszeichen
MAX_UPLOAD_MB=8
```

**Nicht** den alten Code aus `projekt_dokumente/` weiterverwenden.

### 3b. Datenbank (ab 3.9.0)

Die Datenbank ist eine **gemeinsame** MariaDB für mehrere Mini-Projekte; Radwegpate legt nur
Tabellen mit dem Präfix `radpate_` an und fasst keine anderen an.

1. KAS → Datenbanken: vorhandene Datenbank nehmen (oder neu anlegen). Name, Benutzer und Passwort notieren.
2. `DB_NAME`, `DB_USER`, `DB_PASSWORD` in die `.env` auf dem Server eintragen (siehe oben).

3. Deploy starten. Der erste Lauf legt die Tabellen an und übernimmt `install.json`,
   `routes.json`, `entries.json` und `map_data.json` in die Datenbank (Passwort bleibt gleich).
   Die JSON-Dateien bleiben als Sicherung liegen und werden danach nicht mehr benutzt.
4. Von Hand prüfen/wiederholen (SSH, im Webroot):

```bash
php db/check.php              # Zugang
php db/migrate.php --status   # eingespielte/offene Migrationen
php db/import-json.php        # Probelauf der Übernahme (zeigt Zahlen)
php db/backup.php             # Sicherung nach radwegpate_private/backups/
```

Ist `php` auf der SSH-Kommandozeile eine andere Version als die der Domain, im Environment die
Variable `PHP_BIN` setzen (z. B. `php83`).

### 4. GitHub – Environment `production`

Repository → Settings → Environments → `production` anlegen:

| Art      | Name          | Wert (Beispiel)                          |
|----------|---------------|------------------------------------------|
| Secret   | `SSH_HOST`    | `w0XXXXXX.kasserver.com`                 |
| Secret   | `SSH_USER`    | `ssh-w0XXXXXX`                           |
| Secret   | `SSH_KEY`     | Inhalt von `radweg-pate-deploy` (privat) |
| Variable | `REMOTE_PATH` | `/www/htdocs/w0XXXXXX/radweg-pate.de`    |
| Variable | `APP_URL`     | `https://radweg-pate.de`                 |
| Variable | `PHP_BIN`     | optional, z. B. `php83`                  |

### 5. Bestehende Daten

Liegen `install.json`, `entries.json`, `routes.json`, `map_data.json` und
`photos/` bereits in `radweg-pate.de/radwegpate_private/`, übernimmt der erste
Deploy ab 3.9.0 alles in die Datenbank: Passwort, Tagebuch, Fotos (Verweise)
und KML-Zuordnung. Eine Einrichtung ist nicht nötig. Vor diesem Deploy den
Ordner trotzdem einmal per FTP sichern.

## Jedes Deployment

1. Push auf `main`, Workflow **Tests** grün.
2. Actions → **Deploy (radweg-pate.de)** → Run workflow
   - `confirm`: `deploy`
   - zuerst mit **dry_run** – die Zusammenfassung zeigt jede Datei, die übertragen oder gelöscht würde
   - dann ohne dry_run
3. Der Lauf prüft danach selbst: Startseite 200, `sw.js` trägt die neue Version,
   `api.php?action=status` meldet dieselbe Version, `config.php` und `.env` sind von außen gesperrt.
4. Auf dem Handy: App einmal schließen und neu öffnen (der Service Worker lädt die neue Version).

## Kurztest nach dem Deploy

- [ ] Anmelden, Tagebuch und Fotos sichtbar (Vorschaubilder laden schnell)
- [ ] Karte mit OSM-Kacheln, farbige Punkte in der Tourenliste
- [ ] Kontrollfahrt mit Mangel + Foto anlegen, Mangel als erledigt markieren
- [ ] GPX-Export einer Tour

## Subdomain dl.radweg-pate.de (GPX für die iPhone-App)

- In all-inkl als Subdomain mit **demselben Zielverzeichnis** wie radweg-pate.de anlegen, SSL (Let's Encrypt) an.
- Zweck: Die iPhone-Homescreen-App öffnet GPX über `https://dl.radweg-pate.de/gpx.php?id=…&dl=<Schlüssel>`.
  Anderer Host = außerhalb der App → iOS zeigt ein eigenes Fenster mit Schließen-Knopf; „In … öffnen“ → Naviki.
  (Eine Datei-Vorschau innerhalb der App hätte keinen Weg zurück, WebKit-Fehler 236943.)
- `.htaccess` leitet auf dl. alles außer `gpx.php`, `api.php` und `assets/` auf die Hauptdomain um;
  `api.php` erlaubt dort nur `status` und `gpx` (mit Schlüssel).
