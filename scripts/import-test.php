<?php
declare(strict_types=1);
/**
 * Test der JSON-Übernahme (db/import-json.php) gegen eine LEERE Datenbank.
 *
 *   DB_HOST=127.0.0.1 DB_USER=… DB_PASS=… DB_NAME=radwegpate_import php scripts/import-test.php
 *
 * Nutzt die synthetischen Altdaten in tests/fixtures/json-import (aktuelles Format, laufende Fahrt,
 * Format vor v3). Prüft Zeilenzahlen, Umwandlung, Wiederholungsschutz. Exit 0 = grün.
 */
$root = dirname(__DIR__);
$env = fn(string $k, string $d = '') => (string)(getenv($k) ?: $d);
$tmp = sys_get_temp_dir() . '/rp-import-' . bin2hex(random_bytes(4));
mkdir($tmp . '/photos', 0700, true);
foreach (glob($root . '/tests/fixtures/json-import/*.json') as $f) copy($f, $tmp . '/' . basename($f));
foreach (glob($root . '/tests/fixtures/json-import/photos/*') as $f) copy($f, $tmp . '/photos/' . basename($f));
// Eigene .env für diesen Test (RADWEGPATE_ENV); der Datenordner ist in config.php fest
// (public/radwegpate_private) – deshalb zeigt APP_DATA_DIR hier über einen Umweg auf $tmp:
// import-json.php liest den Ordner aus RADWEGPATE_IMPORT_DIR, wenn gesetzt (nur für Tests).
$envFile = $tmp . '/.env';
file_put_contents($envFile, implode("\n", [
    'INSTALL_TOKEN=import-test-0123456789abcdef',
    'DB_HOST=' . $env('DB_HOST', '127.0.0.1'),
    'DB_PORT=' . $env('DB_PORT', '3306'),
    'DB_NAME=' . $env('DB_NAME', 'radwegpate_import'),
    'DB_USER=' . $env('DB_USER', 'root'),
    'DB_PASSWORD="' . $env('DB_PASS') . '"',
]) . "\n");
putenv('RADWEGPATE_ENV=' . $envFile);
putenv('RADWEGPATE_IMPORT_DIR=' . $tmp);

$fails = 0;
$check = function (bool $ok, string $msg) use (&$fails) { echo ($ok ? '  ok   ' : '  FEHL ') . $msg . "\n"; if (!$ok) $fails++; };
$run = function (string $script, string $args = '') use ($root): array {
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$root/public/db/$script") . " $args 2>&1", $out, $rc);
    return [$rc, implode("\n", $out)];
};

echo "Übernahme-Test gegen Datenbank " . $env('DB_NAME', 'radwegpate_import') . "\n";
[$rc, $out] = $run('migrate.php'); $check($rc === 0, 'Migrationen eingespielt');
if ($rc !== 0) { echo $out, "\n"; exit(1); }
require $root . '/public/db/db.php';
require $root . '/public/config.php';
$check((int)db_val('SELECT COUNT(*) FROM radpate_entries') === 0 && setting('password_hash') === null, 'Datenbank ist leer');

[$rc, $out] = $run('import-json.php');
$check($rc === 0 && str_contains($out, 'Kontrollfahrten 3 · Mängel 3 · Fotos 2 · KML-Ebenen 2'), 'Probelauf zählt richtig');
$check((int)db_val('SELECT COUNT(*) FROM radpate_entries') === 0, 'Probelauf ändert nichts');

[$rc, $out] = $run('import-json.php', '--run');
$check($rc === 0 && str_contains($out, 'Übernommen'), 'Übernahme läuft durch');
if ($rc !== 0) echo $out, "\n";
$check((int)db_val('SELECT COUNT(*) FROM radpate_entries') === 3, '3 Kontrollfahrten');
$check((int)db_val('SELECT COUNT(*) FROM radpate_issues') === 3, '3 Mängel (einer aus dem Format vor v3)');
$check((int)db_val('SELECT COUNT(*) FROM radpate_photos') === 2, '2 Fotoverweise');
$check(setting('password_version') === '3' && str_starts_with((string)setting('password_hash'), '$2y$'), 'Passwort-Hash und -Version übernommen');
$check(db_val("SELECT GROUP_CONCAT(route_id ORDER BY position) FROM radpate_entry_routes WHERE entry_id='1111111111111111111111aa'") === '02,01', 'Tourenreihenfolge bleibt');
$i = db_row("SELECT * FROM radpate_issues WHERE id='i_2222222222222222222222bb'");
$check($i && $i['status'] === 'erledigt' && $i['completed_at'] === '2026-10-05 12:00:00', 'Erledigt-Zeitpunkt in UTC');
$i = db_row("SELECT * FROM radpate_issues WHERE id='i_2222222222222222222222aa'");
$check($i && $i['issue_time'] === '08:30:00' && (float)$i['lat'] === 48.3271 && $i['place'] === 'Brücke', 'Uhrzeit, GPS, Umlaute');
$legacy = db_row("SELECT * FROM radpate_issues WHERE entry_id='1111111111111111111111cc'");
$check($legacy && $legacy['id'] === 'i_1111111111111111111111cc' && $legacy['kind'] === 'Beschilderung' && $legacy['route_id'] === '03' && $legacy['place'] === 'Kreuzung Nord', 'Altformat → Mangel');
$check(db_val("SELECT issue_id FROM radpate_photos WHERE id='3333333333333333333333bb'") === null, 'Altformat-Foto bleibt allgemeines Foto');
$check((int)db_val("SELECT running FROM radpate_entries WHERE id='1111111111111111111111bb'") === 1, 'laufende Fahrt bleibt laufend');
$check(db_val("SELECT name FROM radpate_routes WHERE id='01'") === 'Tour 1 geändert' && db_val("SELECT km FROM radpate_routes WHERE id='01'") == 12.3, 'Tourdaten übernommen');
$check(db_val("SELECT link_komoot FROM radpate_routes WHERE id='02'") === 'https://www.naviki.org/x2', 'Links übernommen');
$check(db_val("SELECT route_id FROM radpate_map_sources WHERE id='aaaaaaaaaaaaaaa1'") === '01' && db_val("SELECT route_id FROM radpate_map_sources WHERE id='aaaaaaaaaaaaaaa2'") === null, 'KML-Zuordnung');
$check(json_decode((string)db_val("SELECT line_data FROM radpate_map_sources WHERE id='aaaaaaaaaaaaaaa1'"), true) == [[[10.0, 48.3], [10.01, 48.31]]], 'KML-Linie unverändert');
$check(setting('map_file_name') === 'Radwege Senden.kml', 'KML-Dateiname');
$u = db_row("SELECT * FROM radpate_users WHERE name='Siglinde'");
$check($u && $u['role'] === 'admin' && (int)$u['password_version'] === 3 && str_starts_with($u['password_hash'], '$2y$'), 'bisheriger Zugang → Admin Siglinde');
$check((int)db_val('SELECT COUNT(*) FROM radpate_entries WHERE user_id IS NULL') === 0, 'alle Fahrten gehören Siglinde');

[$rc, $out] = $run('import-json.php', '--run');
$check($rc === 0 && str_contains($out, 'Bereits übernommen') && (int)db_val('SELECT COUNT(*) FROM radpate_entries') === 3, 'zweiter Lauf ändert nichts');
$check(is_file("$tmp/entries.json"), 'JSON-Dateien bleiben liegen');

[$rc, $out] = $run('backup.php', '--keep=1');
$check($rc === 0 && count(glob("$tmp/backups/db-*.json.gz")) === 1, 'Sicherung db/backup.php');

echo $fails ? "\n$fails fehlgeschlagen\n" : "\nAlles grün.\n";
exit($fails ? 1 : 0);
