<?php
declare(strict_types=1);
/**
 * Einmalige Übernahme der bisherigen JSON-Dateien (radwegpate_private/*.json) in die Datenbank.
 * Nur Kommandozeile.
 *
 *   php db/import-json.php          Probelauf: zeigt, was übernommen würde (ändert nichts)
 *   php db/import-json.php --run    übernehmen
 *
 * Läuft nur in eine LEERE Datenbank (kein Passwort, keine Kontrollfahrt) – ein zweiter Aufruf
 * meldet „bereits übernommen“ und ändert nichts (Exit 0). Alles in einer Transaktion.
 * Die JSON-Dateien bleiben unverändert liegen (Sicherung); Fotos bleiben, wo sie sind.
 * Alte Tagebuch-Formen (vor v3: tourId/issueKind/place ohne issues[]) werden umgewandelt.
 */
require __DIR__ . '/db.php';
db_cli_bootstrap();

$run = in_array('--run', $argv, true);
// RADWEGPATE_IMPORT_DIR: anderer Quellordner – nur für scripts/import-test.php.
$dir = rtrim((string)(getenv('RADWEGPATE_IMPORT_DIR') ?: APP_DATA_DIR), '/');
$read = function (string $name) use ($dir): ?array {
    $f = "$dir/$name.json";
    if (!is_file($f)) return null;
    $d = json_decode((string)file_get_contents($f), true);
    if (!is_array($d)) { fwrite(STDERR, "WARNUNG: $name.json ist kein gültiges JSON – übersprungen.\n"); return null; }
    return $d;
};
$cut = fn(mixed $s, int $max) => mb_substr(trim(is_string($s) ? $s : ''), 0, $max);
$hm = fn(mixed $t) => is_string($t) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t) ? $t : null;
$hex = fn(int $n) => bin2hex(random_bytes(intdiv($n, 2)));
$coord = function (mixed $c): array {
    if (!is_array($c) || !is_numeric($c['lat'] ?? null) || !is_numeric($c['lon'] ?? null)) return [null, null];
    $lat = (float)$c['lat']; $lon = (float)$c['lon'];
    return abs($lat) <= 90 && abs($lon) <= 180 ? [round($lat, 6), round($lon, 6)] : [null, null];
};
const RIDS = ['01', '02', '03', '04', '05', '06', '07'];
const KINDS = ['Belag', 'Beschilderung', 'Hindernis', 'Sicherheit', 'Verschmutzung', 'Bewuchs', 'Sonstiges'];
const STATES = ['offen', 'gemeldet', 'bearbeitung', 'erledigt'];

echo "Datenordner: $dir\nDatenbank:   " . APP_DB_NAME . "\n";
if (setting('json_imported_at') !== null) { echo 'Bereits übernommen am ' . setting('json_imported_at') . " – nichts zu tun.\n"; exit(0); }
$hasPw = setting('password_hash') !== null;
$hasEntries = (int)db_val('SELECT COUNT(*) FROM radpate_entries') > 0;
if ($hasPw || $hasEntries) { echo "Datenbank ist nicht leer (Passwort oder Kontrollfahrten vorhanden) – keine Übernahme.\n"; exit(0); }

$install = $read('install'); $routes = $read('routes'); $entries = $read('entries') ?? []; $map = $read('map_data');
if ($install === null && $routes === null && !$entries && $map === null) { echo "Keine JSON-Daten gefunden – nichts zu übernehmen.\n"; exit(0); }

// ── Kontrollfahrten in die neue Form bringen ──
$warn = []; $seenE = []; $seenI = []; $seenP = []; $plan = [];
$photoRows = function (mixed $list, string $where) use (&$seenP, &$warn, $cut, $dir): array {
    $out = [];
    foreach (is_array($list) ? $list : [] as $p) {
        if (!is_array($p) || empty($p['file'])) continue;
        $id = (string)($p['id'] ?? '');
        if (!preg_match('/^[a-f0-9]{24}$/', $id) || isset($seenP[$id])) { $warn[] = "Foto ohne gültige/eindeutige ID ($where) – übersprungen"; continue; }
        $seenP[$id] = true;
        $file = basename((string)$p['file']);
        if (!is_file("$dir/photos/$file")) $warn[] = "Fotodatei fehlt: photos/$file ($where) – Verweis wird trotzdem übernommen";
        $out[] = ['id' => $id, 'file' => $file, 'name' => $cut($p['name'] ?? '', 120), 'mime' => $cut($p['mime'] ?? 'image/jpeg', 30) ?: 'image/jpeg', 'size' => max(0, (int)($p['size'] ?? 0))];
    }
    return $out;
};
foreach ($entries as $n => $e) {
    if (!is_array($e)) continue;
    $id = (string)($e['id'] ?? '');
    if (!preg_match('/^[a-f0-9]{24}$/', $id) || isset($seenE[$id])) { $old = $id; $id = $hex(24); $warn[] = "Kontrollfahrt #$n: ID '$old' ungültig/doppelt → neu $id"; }
    $seenE[$id] = true;
    $created = db_from_iso($e['createdAt'] ?? null, db_now());
    $date = (string)($e['date'] ?? '');
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$dt || $dt->format('Y-m-d') !== $date) { $date = substr($created, 0, 10); $warn[] = "Kontrollfahrt $id: Datum ungültig → $date"; }
    $tours = [];
    foreach ((array)($e['tourIds'] ?? [$e['tourId'] ?? null]) as $t) if (in_array($t, RIDS, true) && !in_array($t, $tours, true)) $tours[] = $t;
    // Alte Form: ein Mangel direkt am Eintrag.
    $issues = $e['issues'] ?? null;
    if (!is_array($issues)) {
        $issues = [];
        if (in_array($e['status'] ?? '', ['mangel', 'hinweis'], true) || !empty($e['issueKind']) || !empty($e['place'])) {
            $issues[] = ['id' => 'i_' . $id, 'tourId' => $e['tourId'] ?? ($tours[0] ?? '01'), 'kind' => $e['issueKind'] ?? 'Sonstiges',
                'place' => $e['place'] ?? '', 'description' => '', 'status' => 'offen', 'coords' => $e['coords'] ?? null, 'photos' => [], 'createdAt' => $e['createdAt'] ?? null];
        }
    }
    $iss = [];
    foreach ($issues as $k => $i) {
        if (!is_array($i)) continue;
        $iid = (string)($i['id'] ?? '');
        if (!preg_match('/^i_[a-f0-9]{24}$/', $iid) || isset($seenI[$iid])) $iid = 'i_' . $hex(24);
        $seenI[$iid] = true;
        $route = in_array($i['tourId'] ?? null, RIDS, true) ? $i['tourId'] : ($tours[0] ?? '01');
        if (!in_array($route, $tours, true)) $tours[] = $route;
        $status = in_array($i['status'] ?? '', STATES, true) ? $i['status'] : 'offen';
        [$lat, $lon] = $coord($i['coords'] ?? null);
        $iss[] = ['id' => $iid, 'route' => $route, 'pos' => $k, 'kind' => in_array($i['kind'] ?? '', KINDS, true) ? $i['kind'] : 'Sonstiges',
            'time' => $hm($i['time'] ?? null), 'place' => $cut($i['place'] ?? '', 180), 'description' => $cut($i['description'] ?? '', 3000),
            'status' => $status, 'lat' => $lat, 'lon' => $lon, 'created' => db_from_iso($i['createdAt'] ?? null, $created),
            'completed' => $status === 'erledigt' ? db_from_iso($i['completedAt'] ?? null, $created) : null,
            'photos' => $photoRows($i['photos'] ?? [], "Mangel $iid")];
    }
    if (!$tours) $tours = ['01'];
    [$lat, $lon] = $coord($e['coords'] ?? null);
    $plan[] = ['id' => $id, 'date' => $date, 'start' => $hm($e['startTime'] ?? null), 'end' => $hm($e['endTime'] ?? null),
        'running' => !empty($e['running']) ? 1 : 0, 'notes' => $cut($e['notes'] ?? '', 60000), 'lat' => $lat, 'lon' => $lon,
        'created' => $created, 'updated' => db_from_iso($e['updatedAt'] ?? null, $created), 'tours' => $tours, 'issues' => $iss,
        'photos' => $photoRows($e['photos'] ?? [], "Fahrt $id")];
}
$nI = array_sum(array_map(fn($p) => count($p['issues']), $plan));
$nP = count($seenP);
$sources = is_array($map['sources'] ?? null) ? $map['sources'] : [];
echo "Gefunden: Passwort " . (!empty($install['password_hash']) ? 'ja' : 'nein') . ' · Touren ' . (is_array($routes) ? count($routes) : 0)
    . ' · Kontrollfahrten ' . count($plan) . " · Mängel $nI · Fotos $nP · KML-Ebenen " . count($sources) . "\n";
foreach ($warn as $w) echo "  Hinweis: $w\n";
if (!$run) { echo "Probelauf – nichts geändert. Übernehmen mit: php db/import-json.php --run\n"; exit(0); }

db_tx(function () use ($install, $routes, $plan, $map, $sources, $cut) {
    if (!empty($install['password_hash'])) {
        setting_set('password_hash', (string)$install['password_hash']);
        setting_set('password_version', (string)max(1, (int)($install['pwv'] ?? 1)));
        setting_set('installed_at', (string)($install['created_at'] ?? gmdate('c')));
        if (!empty($install['passwordChangedAt'])) setting_set('password_changed_at', (string)$install['passwordChangedAt']);
    }
    foreach (is_array($routes) ? $routes : [] as $r) {
        if (!is_array($r) || !in_array($r['id'] ?? null, RIDS, true)) continue;
        $l = is_array($r['links'] ?? null) ? $r['links'] : [];
        $km = is_numeric($r['km'] ?? null) ? round((float)$r['km'], 1) : null;
        dbq('UPDATE radpate_routes SET name=?, type=?, area=?, note=?, km=?, link_komoot=?, link_google=?, link_apple=?, link_mymaps=? WHERE id=?', [
            $cut($r['name'] ?? '', 90) ?: 'Tour ' . $r['id'], $cut($r['type'] ?? 'abfahrt', 20) ?: 'abfahrt', $cut($r['area'] ?? '', 180), $cut($r['note'] ?? '', 1000), $km,
            $cut($l['komoot'] ?? '', 1500), $cut($l['google'] ?? '', 1500), $cut($l['apple'] ?? '', 1500), $cut($l['mymaps'] ?? '', 1500), $r['id']]);
    }
    foreach ($plan as $e) {
        dbq('INSERT INTO radpate_entries (id, trip_date, start_time, end_time, running, notes, lat, lon, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)',
            [$e['id'], $e['date'], $e['start'], $e['end'], $e['running'], $e['notes'], $e['lat'], $e['lon'], $e['created'], $e['updated']]);
        foreach ($e['tours'] as $pos => $t) dbq('INSERT INTO radpate_entry_routes (entry_id, route_id, position) VALUES (?,?,?)', [$e['id'], $t, $pos]);
        foreach ($e['issues'] as $i) {
            dbq('INSERT INTO radpate_issues (id, entry_id, route_id, position, kind, issue_time, place, description, status, lat, lon, created_at, completed_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [$i['id'], $e['id'], $i['route'], $i['pos'], $i['kind'], $i['time'], $i['place'], $i['description'], $i['status'], $i['lat'], $i['lon'], $i['created'], $i['completed']]);
            foreach ($i['photos'] as $pos => $p) dbq('INSERT INTO radpate_photos (id, entry_id, issue_id, position, file, name, mime, size, created_at) VALUES (?,?,?,?,?,?,?,?,?)',
                [$p['id'], $e['id'], $i['id'], $pos, $p['file'], $p['name'], $p['mime'], $p['size'], $i['created']]);
        }
        foreach ($e['photos'] as $pos => $p) dbq('INSERT INTO radpate_photos (id, entry_id, issue_id, position, file, name, mime, size, created_at) VALUES (?,?,NULL,?,?,?,?,?,?)',
            [$p['id'], $e['id'], $pos, $p['file'], $p['name'], $p['mime'], $p['size'], $e['created']]);
    }
    $assign = is_array($map['assignments'] ?? null) ? $map['assignments'] : [];
    $seen = [];
    foreach ($sources as $pos => $s) {
        $sid = (string)($s['id'] ?? '');
        if (!preg_match('/^[a-f0-9]{16}$/', $sid) || isset($seen[$sid]) || !is_array($s['lines'] ?? null)) continue;
        $seen[$sid] = true;
        $route = in_array($assign[$sid] ?? null, RIDS, true) ? $assign[$sid] : null;
        dbq('INSERT INTO radpate_map_sources (id, position, name, route_id, line_data, turnpoints) VALUES (?,?,?,?,?,?)',
            [$sid, $pos, $cut($s['name'] ?? '', 160) ?: 'Unbenannte Ebene', $route, json_encode($s['lines'], JSON_UNESCAPED_SLASHES),
             json_encode(is_array($s['turnpoints'] ?? null) ? $s['turnpoints'] : [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
    }
    if ($sources) {
        setting_set('map_imported_at', (string)($map['importedAt'] ?? gmdate('c')));
        setting_set('map_file_name', $cut($map['fileName'] ?? '', 180));
    }
    setting_set('json_imported_at', gmdate('c'));
});
echo "Übernommen. Die JSON-Dateien bleiben als Sicherung in $dir liegen.\n";
exit(0);
