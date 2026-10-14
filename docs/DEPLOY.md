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

Per SSH (Pfade an das eigene Konto anpassen):

```bash
cd /www/htdocs/w0XXXXXX
mkdir -p radweg-pate_private && chmod 700 radweg-pate_private
php -r "echo bin2hex(random_bytes(16)), PHP_EOL;"   # neuen Einrichtungscode erzeugen
nano radweg-pate.de/config.php                        # Inhalt wie public/config.example.php
chmod 600 radweg-pate.de/config.php
```

In `config.php`: `APP_INSTALL_TOKEN` = der eben erzeugte Code,
`APP_DATA_DIR` = `/www/htdocs/w0XXXXXX/radweg-pate_private`.

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

### 5. Bestehende Daten übernehmen (falls die App schon unter frag-volker.de/pate lief)

Aus dem alten privaten Ordner (`radwegpate_private`) nach
`radweg-pate_private` kopieren: `install.json`, `entries.json`, `routes.json`,
`map_data.json` und den Ordner `photos/`. Passwort, Tagebuch, Fotos und
KML-Zuordnung bleiben damit erhalten; eine Einrichtung ist nicht nötig.

```bash
cp -a /PFAD/ZUM/ALTEN/radwegpate_private/. /www/htdocs/w0XXXXXX/radweg-pate_private/
```

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
