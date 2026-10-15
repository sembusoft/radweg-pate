<#
  Radwegpate – lokale Testumgebung (Docker Desktop)

  .\rp.ps1 start     App + MariaDB starten → http://localhost:8090 (legt public\.env an, spielt Migrationen ein)
  .\rp.ps1 stop      App anhalten (Daten bleiben)
  .\rp.ps1 test      check.mjs + Rauchtest gegen eine Wegwerf-Instanz (Apache, inkl. .htaccess-Sperren)
  .\rp.ps1 logs      Apache-/PHP-Protokoll live
  .\rp.ps1 reset     lokale Testdaten löschen (Datenbank + public\radwegpate_private) – Einrichtung beginnt neu
  .\rp.ps1 db        MariaDB-Konsole der lokalen Datenbank
  .\rp.ps1 status    laufende Container und Version
#>
param(
  [Parameter(Position = 0)]
  [ValidateSet('start', 'stop', 'test', 'logs', 'reset', 'status', 'db', 'help')]
  [string]$Befehl = 'help'
)
$ErrorActionPreference = 'Stop'
Set-Location -LiteralPath $PSScriptRoot
$Url = 'http://localhost:8090'
$Utf8OhneBom = New-Object System.Text.UTF8Encoding $false

function Pruefe-Docker {
  docker info *> $null
  if ($LASTEXITCODE -ne 0) { throw 'Docker läuft nicht. Bitte Docker Desktop starten.' }
}

function Version {
  $zeile = Select-String -Path 'public\version.php' -Pattern "APP_VERSION\s*=\s*'([^']+)'"
  return $zeile.Matches[0].Groups[1].Value
}

switch ($Befehl) {
  'start' {
    Pruefe-Docker
    # Zugangsdaten in public\.env (nie im Repo). Fehlt sie, aus der Vorlage anlegen.
    $envDatei = Join-Path $PSScriptRoot 'public\.env'
    if (-not (Test-Path $envDatei)) {
      $bytes = New-Object byte[] 16
      [System.Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($bytes)
      $token = -join ($bytes | ForEach-Object { $_.ToString('x2') })
      $neu = (Get-Content 'public\.env.example' -Raw -Encoding UTF8)
      $neu = $neu -replace '(?m)^INSTALL_TOKEN=.*$', "INSTALL_TOKEN=$token"
      $neu = $neu -replace '(?m)^DB_HOST=.*$', 'DB_HOST=db'
      $neu = $neu -replace '(?m)^DB_NAME=.*$', 'DB_NAME=radwegpate'
      $neu = $neu -replace '(?m)^DB_USER=.*$', 'DB_USER=radwegpate'
      $neu = $neu -replace '(?m)^DB_PASSWORD=.*$', 'DB_PASSWORD=radwegpate-lokal'
      # Ohne BOM, mit LF – wie auf dem Server.
      [IO.File]::WriteAllText($envDatei, ($neu -replace "`r`n", "`n"), $Utf8OhneBom)
      Write-Host "public\.env angelegt. Einrichtungscode: $token" -ForegroundColor Yellow
    }
    docker compose up -d --build --wait app
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
    docker compose exec -T app php db/migrate.php
    if ($LASTEXITCODE -ne 0) { Write-Host 'Migration fehlgeschlagen.' -ForegroundColor Red; exit $LASTEXITCODE }
    # Einmalig: vorhandene JSON-Testdaten übernehmen (macht nichts, wenn die DB schon Daten hat).
    docker compose exec -T app php db/import-json.php --run
    Write-Host ""
    Write-Host "Radwegpate $(Version) läuft: $Url" -ForegroundColor Green
    Write-Host "Handy-Ansicht: im Browser F12 → Gerätesymbol → 390 px Breite."
    Start-Process $Url
  }
  'stop' {
    docker compose down
  }
  'test' {
    Pruefe-Docker
    docker compose --profile test run --rm --build runner
    $rc = $LASTEXITCODE
    docker compose --profile test rm -sf test *> $null
    if ($rc -eq 0) { Write-Host "`nTest grün." -ForegroundColor Green } else { Write-Host "`nTest ROT (Exit $rc)." -ForegroundColor Red }
    exit $rc
  }
  'logs' {
    docker compose logs -f app
  }
  'reset' {
    $daten = 'public\radwegpate_private'
    $ok = Read-Host "Lokale Datenbank und $daten wirklich löschen? (ja/nein)"
    if ($ok -ne 'ja') { Write-Host 'Abgebrochen.'; break }
    docker compose down -v
    if (Test-Path $daten) { Remove-Item -LiteralPath $daten -Recurse -Force }
    Write-Host 'Gelöscht. Nach .\rp.ps1 start zeigt die App „Einmalig einrichten“.'
  }
  'db' {
    docker compose exec db mariadb -u radwegpate -pradwegpate-lokal radwegpate
  }
  'status' {
    Write-Host "Version im Code: $(Version)"
    docker compose ps
  }
  default {
    Get-Help $PSCommandPath -Detailed | Out-Null
    (Get-Content $PSCommandPath -TotalCount 11 -Encoding UTF8) | Select-Object -Skip 1 | ForEach-Object { $_ }
  }
}
