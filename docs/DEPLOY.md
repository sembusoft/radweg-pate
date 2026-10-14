# Deployment auf radweg-pate.de (all-inkl)

Ausgeliefert wird nur `public/` per rsync über SSH (Workflow `Deploy`, startet
nur von Hand). `config.php` und der Datenordner liegen ausschließlich auf dem
Server und werden nie überschrieben.

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

### 3. Datenordner und config.php auf dem Server

Der Datenordner liegt **im Webroot**: `radweg-pate.de/radwegpate_private/`
(Tagebuch, Fotos, KML-Daten, Passwort-Hash). Er ist von außen dreifach gesperrt:

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
nano config.php                                              # Inhalt wie public/config.example.php
chmod 600 config.php
```

In `config.php`: `APP_INSTALL_TOKEN` = der eben erzeugte Code,
`APP_DATA_DIR` = `__DIR__ . '/radwegpate_private'` (wie in der Vorlage).

**Nicht** den alten Code aus `projekt_dokumente/` weiterverwenden.

### 4. GitHub – Environment `production`

Repository → Settings → Environments → `production` anlegen:

| Art      | Name          | Wert (Beispiel)                          |
|----------|---------------|------------------------------------------|
| Secret   | `SSH_HOST`    | `w0XXXXXX.kasserver.com`                 |
| Secret   | `SSH_USER`    | `ssh-w0XXXXXX`                           |
| Secret   | `SSH_KEY`     | Inhalt von `radweg-pate-deploy` (privat) |
| Variable | `REMOTE_PATH` | `/www/htdocs/w0XXXXXX/radweg-pate.de`    |
| Variable | `APP_URL`     | `https://radweg-pate.de`                 |

### 5. Bestehende Daten

Liegen `install.json`, `entries.json`, `routes.json`, `map_data.json` und
`photos/` bereits in `radweg-pate.de/radwegpate_private/`, gilt alles weiter:
Passwort, Tagebuch, Fotos und KML-Zuordnung. Eine Einrichtung ist nicht nötig.
Vor dem ersten Deploy den Ordner trotzdem einmal per FTP sichern.

## Jedes Deployment

1. Push auf `main`, Workflow **Tests** grün.
2. Actions → **Deploy (radweg-pate.de)** → Run workflow
   - `confirm`: `deploy`
   - zuerst mit **dry_run** – die Zusammenfassung zeigt jede Datei, die übertragen oder gelöscht würde
   - dann ohne dry_run
3. Der Lauf prüft danach selbst: Startseite 200, `sw.js` trägt die neue Version,
   `api.php?action=status` meldet dieselbe Version, `config.php` ist von außen gesperrt.
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
