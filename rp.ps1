<#
  Radwegpate – lokale Testumgebung (Docker Desktop)

  .\rp.ps1 start     App starten → http://localhost:8090   (legt beim ersten Mal public\config.php an)
  .\rp.ps1 stop      App anhalten (Daten bleiben)
  .\rp.ps1 test      check.mjs + Rauchtest gegen eine Wegwerf-Instanz (Apache, inkl. .htaccess-Sperren)
  .\rp.ps1 logs      Apache-/PHP-Protokoll live
  .\rp.ps1 reset     lokale Testdaten löschen (public\radwegpate_private) – Einrichtung beginnt neu
  .\rp.ps1 status    laufende Container und Version
#>
param(
  [Parameter(Position = 0)]
  [ValidateSet('start', 'stop', 'test', 'logs', 'reset', 'status', 'help')]
  [string]$Befehl = 'help'
)
$ErrorActionPreference = 'Stop'
Set-Location -LiteralPath $PSScriptRoot
$Url = 'http://localhost:8090'

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
    $cfg = 'public\config.php'
    if (-not (Test-Path $cfg)) {
      $bytes = New-Object byte[] 16
      [System.Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($bytes)
      $token = -join ($bytes | ForEach-Object { $_.ToString('x2') })
      (Get-Content 'public\config.example.php' -Raw -Encoding UTF8).Replace('HIER-EINEN-ZUFAELLIGEN-CODE-EINTRAGEN', $token) |
        Set-Content -Path $cfg -Encoding UTF8 -NoNewline
      Write-Host "public\config.php angelegt. Einrichtungscode: $token" -ForegroundColor Yellow
    }
    docker compose up -d --build app
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
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
    if (-not (Test-Path $daten)) { Write-Host 'Keine lokalen Testdaten vorhanden.'; break }
    $ok = Read-Host "Lokale Testdaten in $daten wirklich löschen? (ja/nein)"
    if ($ok -eq 'ja') { Remove-Item -LiteralPath $daten -Recurse -Force; Write-Host 'Gelöscht. Nächster Aufruf zeigt „Einmalig einrichten“.' }
    else { Write-Host 'Abgebrochen.' }
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
