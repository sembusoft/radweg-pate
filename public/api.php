<?php
declare(strict_types=1);
// Konfiguration: config.php (im Repository) liest die Zugangsdaten aus der .env (nur auf dem Server).
require __DIR__ . '/config.php';
if (APP_DB_NAME === '' || APP_DB_USER === '') {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>false,'error'=>APP_ENV_FILE==='' ? '.env fehlt. Vorlage .env.example als .env neben config.php ablegen und ausfüllen.' : '.env unvollständig: DB_NAME und DB_USER eintragen.'], JSON_UNESCAPED_UNICODE);
    exit;
}
require __DIR__ . '/version.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Cache-Control: no-store, private');
header('X-Frame-Options: DENY');

$secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
session_name('RWP_SESSION');
session_set_cookie_params([
    'lifetime' => 60 * 60 * 24 * 14,
    'path' => rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/pate/api.php'), '/') . '/',
    'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax'
]);
ini_set('session.gc_maxlifetime', (string)(60 * 60 * 24 * 14));
session_start();
$_SESSION['csrf'] ??= bin2hex(random_bytes(24));

function result(array $result, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function fail(string $message, int $code=400): never { result(['ok'=>false,'error'=>$message],$code); }

// Datenbankfehler: kurze Meldung an die App, Einzelheiten ins Server-Log.
require __DIR__ . '/db/db.php';
set_exception_handler(function (Throwable $e): void {
    error_log('Radwegpate: '.get_class($e).': '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
    $code = $e instanceof PDOException ? (string)$e->getCode() : '';
    if ($code === '42S02') fail('Datenbank ist noch nicht eingerichtet (db/migrate.php ausführen).', 503);
    if ($e instanceof PDOException || $e instanceof RuntimeException) fail('Datenbank nicht erreichbar. Bitte später erneut versuchen.', 503);
    fail('Interner Fehler.', 500);
});

/** Privater Ordner – nur noch für Fotos (Daten liegen in der Datenbank). */
function storage(): string {
    $dir = APP_DATA_DIR;
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        fail('Privater Speicherort nicht beschreibbar. Bitte APP_DATA_DIR in config.php anpassen.', 500);
    }
    if (!is_writable($dir)) fail('APP_DATA_DIR ist nicht beschreibbar.', 500);
    // Zweite Sicherung, falls der Ordner doch unter einer (anderen) Domain im Web liegt.
    // Manche Hoster (all-inkl) verbieten PHP das Schreiben von .htaccess-Dateien.
    // Dann legt der Deploy sie per SSH an, und public/.htaccess sperrt den Ordner ohnehin.
    if (!is_file($dir.'/.htaccess') && @file_put_contents($dir.'/.htaccess', "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n")===false) {
        static $logged=false;
        if (!$logged) { error_log('Radwegpate: .htaccess im Datenordner nicht anlegbar ('.$dir.') – Schutz über public/.htaccess.'); $logged=true; }
    }
    return $dir;
}
function post_json(): array {
    $data=json_decode(file_get_contents('php://input') ?: '{}',true);
    if (!is_array($data)) fail('Ungültige Eingabe.');
    return $data;
}
function check_csrf(): void {
    if (!hash_equals($_SESSION['csrf']??'', $_SERVER['HTTP_X_CSRF_TOKEN']??'')) fail('Sicherheitsprüfung fehlgeschlagen. Bitte neu laden.',403);
}
/** Angemeldeter Benutzer (Zeile aus radpate_users) oder null. Je Anfrage einmal gelesen. */
function me(): ?array {
    static $u=false;
    if ($u!==false) return $u;
    $u=null;
    $uid=(int)($_SESSION['uid']??0);
    if ($uid<=0 || (time()-(int)($_SESSION['last_active']??0)) >= 60*60*24*14) return null;
    $row=db_row('SELECT id, name, role, active, must_change_pw, password_version FROM radpate_users WHERE id=?',[$uid]);
    // Deaktiviert oder Passwort inzwischen geändert (Version erhöht) → Sitzung ungültig.
    if (!$row || !(int)$row['active'] || (int)$row['password_version']!==(int)($_SESSION['pwv']??-1)) return null;
    return $u=$row;
}
function logged_in(): bool { return me()!==null; }
function is_admin(): bool { return (me()['role']??'')==='admin'; }
function my_id(): int { return (int)(me()['id']??0); }
function start_session_for_user(array $user): void {
    session_regenerate_id(true);
    unset($_SESSION['logged'],$_SESSION['gpxToken'],$_SESSION['gpxTokenExp']);
    $_SESSION['uid']=(int)$user['id']; $_SESSION['pwv']=(int)$user['password_version']; $_SESSION['last_active']=time();
}
function require_login(): void {
    if (!logged_in()) { unset($_SESSION['uid']); fail('Bitte erneut anmelden.',401); }
    $_SESSION['last_active']=time();
}
function require_admin(): void { if (!is_admin()) fail('Nur für Admins.',403); }
/** Darf der angemeldete Benutzer diese Kontrollfahrt ändern? (Ersteller oder Admin) */
function require_owner(array $entryRow): void {
    if (!is_admin() && (int)($entryRow['user_id']??0)!==my_id()) fail('Nur die eigene Kontrollfahrt kann geändert werden.',403);
}
const USER_ROLES=['admin','pate'];
function valid_user_name(mixed $raw): string {
    $name=trim(preg_replace('/\s+/u',' ',(string)$raw) ?? '');
    $len=function_exists('mb_strlen')?mb_strlen($name):strlen($name);
    if ($len<2 || $len>60 || !preg_match('/^[\p{L}\p{N} ._\-]+$/u',$name)) fail('Name: 2 bis 60 Zeichen, Buchstaben, Ziffern, Leerzeichen, Punkt, Bindestrich.');
    return $name;
}
function valid_new_password(mixed $raw): string {
    $pw=(string)$raw;
    if (strlen($pw)<12 || strlen($pw)>256) fail('Passwort: 12 bis 256 Zeichen.');
    return $pw;
}
/** Touren eines Benutzers (Paten-Zuordnung). */
function user_route_ids(int $uid): array {
    return array_column(db_all('SELECT route_id FROM radpate_user_routes WHERE user_id=? ORDER BY route_id',[$uid]),'route_id');
}
function user_out(array $u): array {
    return ['id'=>(int)$u['id'],'name'=>$u['name'],'role'=>$u['role'],'active'=>(bool)(int)$u['active'],
        'mustChangePassword'=>(bool)(int)$u['must_change_pw'],'lastLoginAt'=>db_iso($u['last_login_at']??null),
        'entries'=>isset($u['entries'])?(int)$u['entries']:null,
        'routeIds'=>user_route_ids((int)$u['id'])];
}
function valid_url(string $url): bool {
    if ($url==='') return true;
    if (strlen($url)>1500 || !filter_var($url,FILTER_VALIDATE_URL)) return false;
    return strtolower((string)parse_url($url,PHP_URL_SCHEME))==='https';
}
function norm(string $value,int $max): string {
    $value=trim($value);
    if ((function_exists('mb_strlen') ? mb_strlen($value) : strlen($value))>$max) fail('Ein Textfeld ist zu lang (maximal '.$max.' Zeichen).');
    return $value;
}
function valid_date(string $date): bool {
    $dt=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
    return $dt && $dt->format('Y-m-d')===$date;
}
/** Sperre nach 10 FEHLversuchen je IP in 15 Minuten (erfolgreiche Anmeldungen zählen nicht). */
function login_ip_hash(): string { return hash('sha256',(string)($_SERVER['REMOTE_ADDR']??'unknown')); }
function check_login_rate(): void {
    if (random_int(1,20)===1) dbq('DELETE FROM radpate_login_attempts WHERE attempted_at < UTC_TIMESTAMP() - INTERVAL 1 DAY');
    $n=(int)db_val('SELECT COUNT(*) FROM radpate_login_attempts WHERE ip_hash=? AND attempted_at > UTC_TIMESTAMP() - INTERVAL 15 MINUTE',[login_ip_hash()]);
    if ($n>=10) fail('Zu viele Fehlversuche. In 15 Minuten erneut versuchen.',429);
}
function login_failed(): void { dbq('INSERT INTO radpate_login_attempts (ip_hash, attempted_at) VALUES (?, UTC_TIMESTAMP())',[login_ip_hash()]); }

// ── Lesemodell: Datenbankzeilen → JSON-Form der App (unverändert gegenüber den JSON-Dateien) ──
const ROUTE_IDS=['01','02','03','04','05','06','07'];
function route_out(array $r): array {
    return ['id'=>$r['id'],'name'=>$r['name'],'type'=>$r['type'],'area'=>$r['area'],'note'=>$r['note'],
        'km'=>$r['km']===null?null:(float)$r['km'],
        'links'=>['komoot'=>$r['link_komoot'],'google'=>$r['link_google'],'apple'=>$r['link_apple'],'mymaps'=>$r['link_mymaps']]];
}
function routes_all(): array {
    $paten=[];
    foreach (db_all('SELECT ur.route_id, u.name FROM radpate_user_routes ur JOIN radpate_users u ON u.id=ur.user_id WHERE u.active=1 ORDER BY u.name') as $r) $paten[$r['route_id']][]=$r['name'];
    return array_map(fn($r)=>route_out($r)+['paten'=>$paten[$r['id']]??[]], db_all('SELECT * FROM radpate_routes ORDER BY id'));
}
function coords_out(array $r): ?array { return $r['lat']===null||$r['lon']===null ? null : ['lat'=>(float)$r['lat'],'lon'=>(float)$r['lon']]; }
function photo_out(array $p): array { return ['id'=>$p['id'],'name'=>$p['name'],'file'=>$p['file'],'mime'=>$p['mime'],'size'=>(int)$p['size']]; }
function issue_out(array $i, array $photos): array {
    return ['id'=>$i['id'],'tourId'=>$i['route_id'],'kind'=>$i['kind'],'time'=>db_hm($i['issue_time']),
        'place'=>$i['place'],'description'=>$i['description'],'status'=>$i['status'],'coords'=>coords_out($i),
        'photos'=>$photos,'createdAt'=>db_iso($i['created_at']),'completedAt'=>db_iso($i['completed_at']),
        'statusBy'=>$i['status_by_name']??null];
}
/**
 * Kontrollfahrten mit Touren, Mängeln und Fotos. $id: nur diese eine (oder [] wenn nicht vorhanden).
 * Sichtbarkeit: eigene Fahrten (Admin: alle) vollständig. Fremde Fahrten nur als Hülle für ihre
 * Mängel – Mängel sehen alle; Notizen, Zeiten, Fahrt-GPS und allgemeine Fotos bleiben beim Ersteller.
 */
function entries_load(?string $id=null): array {
    $where=$id===null?'':' WHERE entry_id = ?'; $p=$id===null?[]:[$id];
    $rows=db_all('SELECT e.*, u.name AS user_name FROM radpate_entries e LEFT JOIN radpate_users u ON u.id=e.user_id'.($id===null?'':' WHERE e.id = ?').' ORDER BY e.trip_date, e.created_at',$p);
    if (!$rows) return [];
    $tours=[]; foreach (db_all('SELECT entry_id, route_id FROM radpate_entry_routes'.$where.' ORDER BY position, route_id',$p) as $r) $tours[$r['entry_id']][]=$r['route_id'];
    $photos=[]; foreach (db_all('SELECT * FROM radpate_photos'.$where.' ORDER BY position, created_at',$p) as $r) $photos[$r['entry_id']][$r['issue_id']??''][]=photo_out($r);
    $issues=[]; foreach (db_all('SELECT i.*, u.name AS status_by_name FROM radpate_issues i LEFT JOIN radpate_users u ON u.id=i.status_by'.($id===null?'':' WHERE i.entry_id = ?').' ORDER BY i.position, i.created_at',$p) as $r) $issues[$r['entry_id']][]=issue_out($r,$photos[$r['entry_id']][$r['id']]??[]);
    $out=[]; $admin=is_admin(); $me=my_id();
    foreach ($rows as $e) {
        $ids=$tours[$e['id']]??[]; $iss=$issues[$e['id']]??[];
        $own=(int)($e['user_id']??0)===$me;
        $full=$own||$admin;
        $out[]=['id'=>$e['id'],'createdAt'=>db_iso($e['created_at']),'updatedAt'=>db_iso($e['updated_at']),
            'tourIds'=>$ids,'tourId'=>$ids[0]??null,'date'=>$e['trip_date'],
            'startTime'=>$full?db_hm($e['start_time']):null,'endTime'=>$full?db_hm($e['end_time']):null,
            'running'=>$full && (bool)$e['running'],
            'status'=>entry_status($iss),'notes'=>$full?$e['notes']:'','coords'=>$full?coords_out($e):null,
            'issues'=>$iss,'photos'=>$full?($photos[$e['id']]['']??[]):[],
            'userId'=>$e['user_id']===null?null:(int)$e['user_id'],'userName'=>$e['user_name']??'',
            'own'=>$own,'canEdit'=>$full];
    }
    return $out;
}
function entry_one(string $id): array { return entries_load($id)[0] ?? fail('Kontrollfahrt nicht gefunden.',404); }
/**
 * Kontrollfahrt sperren (innerhalb einer Transaktion) – parallele Änderungen warten.
 * $mustOwn: nur Ersteller oder Admin (Ausnahme: Mangel-Status, den darf jeder setzen).
 */
function entry_lock(string $id, bool $mustOwn=true): array {
    $row=db_row('SELECT * FROM radpate_entries WHERE id = ? FOR UPDATE',[$id]) ?? fail('Kontrollfahrt nicht gefunden.',404);
    if ($mustOwn) require_owner($row);
    return $row;
}
function entry_tour_ids(string $id): array {
    return array_column(db_all('SELECT route_id FROM radpate_entry_routes WHERE entry_id=? ORDER BY position, route_id',[$id]),'route_id');
}
function entry_set_tours(string $id, array $tourIds): void {
    dbq('DELETE FROM radpate_entry_routes WHERE entry_id=?',[$id]);
    foreach ($tourIds as $pos=>$tid) dbq('INSERT INTO radpate_entry_routes (entry_id, route_id, position) VALUES (?,?,?)',[$id,$tid,$pos]);
}
function entry_touch(string $id): void { dbq('UPDATE radpate_entries SET updated_at=? WHERE id=?',[db_now(),$id]); }
/** Fotodateien (Datei + Vorschau) zu Fotozeilen löschen – erst NACH dem Commit aufrufen. */
function delete_photo_rows_files(array $rows): void {
    foreach ($rows as $p) if (!empty($p['file'])) delete_photo_files((string)$p['file'],(string)$p['id']);
}

/**
 * Entfernt EXIF/XMP (APP1) und IPTC (APP13) verlustfrei aus einer JPEG-Datei –
 * dort stehen u. a. GPS-Position, Gerät und Aufnahmezeit. Bild- und Farbdaten bleiben.
 */
function strip_jpeg_metadata(string $path): void {
    $data=@file_get_contents($path);
    if ($data===false || strlen($data)<4 || substr($data,0,2)!=="\xFF\xD8") return;
    $out="\xFF\xD8"; $pos=2; $len=strlen($data);
    while ($pos+4<=$len && $data[$pos]==="\xFF") {
        $marker=ord($data[$pos+1]);
        if ($marker===0xDA) break; // Start of Scan: Rest unverändert übernehmen
        $segLen=(ord($data[$pos+2])<<8)+ord($data[$pos+3]);
        if ($segLen<2 || $pos+2+$segLen>$len) return; // beschädigt: Datei unangetastet lassen
        if ($marker!==0xE1 && $marker!==0xED) $out.=substr($data,$pos,2+$segLen);
        $pos+=2+$segLen;
    }
    if ($pos>=$len || $data[$pos]!=="\xFF") return;
    $out.=substr($data,$pos);
    @file_put_contents($path,$out,LOCK_EX);
}
/** Vorschaubild (max. 360 px, JPEG) erzeugen und ablegen. Ohne GD: null → Original. */
function photo_thumbnail(string $file, string $pid): ?string {
    if (!function_exists('imagecreatefromstring')) return null;
    $dir=storage().'/photos/thumbs';
    $thumb=$dir.'/'.$pid.'.jpg';
    if (is_file($thumb)) return $thumb;
    if (!is_dir($dir) && !@mkdir($dir,0700,true)) return null;
    $img=@imagecreatefromstring((string)file_get_contents($file));
    if (!$img) return null;
    $w=imagesx($img); $h=imagesy($img); $scale=min(1, 360/max($w,$h));
    $tw=max(1,(int)round($w*$scale)); $th=max(1,(int)round($h*$scale));
    $dst=imagecreatetruecolor($tw,$th);
    imagefill($dst,0,0,imagecolorallocate($dst,255,255,255));
    imagecopyresampled($dst,$img,0,0,0,0,$tw,$th,$w,$h);
    $ok=imagejpeg($dst,$thumb,78);
    imagedestroy($img); imagedestroy($dst);
    if (!$ok) return null;
    @chmod($thumb,0600);
    return $thumb;
}
function delete_photo_files(string $file, string $id): void {
    @unlink(storage().'/photos/'.basename($file));
    @unlink(storage().'/photos/thumbs/'.basename($id).'.jpg');
}

$action=(string)($_GET['action']??'status');
$method=(string)($_SERVER['REQUEST_METHOD']??'GET');
// dl.<host> dient nur der GPX-Download-Seite (gpx.php): dort gibt es nur status und gpx (mit Schlüssel).
if (str_starts_with(strtolower((string)($_SERVER['HTTP_HOST']??'')),'dl.') && !in_array($action,['status','gpx'],true)) fail('Nicht verfügbar.',404);
$installed=(int)db_val('SELECT COUNT(*) FROM radpate_users')>0;

if ($action==='status' && $method==='GET') {
    result(['ok'=>true,'installed'=>$installed,'loggedIn'=>$installed && logged_in(), 'csrf'=>$_SESSION['csrf'], 'version'=>APP_VERSION]);
}
if ($action==='setup' && $method==='POST') {
    check_csrf();
    if ($installed) fail('Einrichtung bereits abgeschlossen.',409);
    $token=defined('APP_INSTALL_TOKEN') ? (string)APP_INSTALL_TOKEN : '';
    if (strlen($token)<20 || str_starts_with($token,'HIER-')) fail('Einrichtung gesperrt: INSTALL_TOKEN in der .env setzen (mindestens 20 Zeichen).',503);
    check_login_rate();
    $data=post_json();
    if (!hash_equals($token, (string)($data['code']??''))) { login_failed(); fail('Einrichtungscode stimmt nicht.',403); }
    $name=valid_user_name($data['name']??'');
    $pw=valid_new_password($data['password']??'');
    // Erster Benutzer = Admin. Sperre über die Tabelle: kommen zwei Einrichtungen gleichzeitig, gewinnt eine.
    $user=db_tx(function () use ($name,$pw) {
        if ((int)db_val('SELECT COUNT(*) FROM radpate_users FOR UPDATE')>0) fail('Bereits eingerichtet.',409);
        dbq("INSERT INTO radpate_users (name, password_hash, role, active, must_change_pw, password_version, created_at, last_login_at) VALUES (?,?, 'admin', 1, 0, 1, ?, ?)",
            [$name,password_hash($pw,PASSWORD_DEFAULT),db_now(),db_now()]);
        return db_row('SELECT * FROM radpate_users WHERE id=?',[(int)db()->lastInsertId()]);
    });
    start_session_for_user($user);
    result(['ok'=>true,'csrf'=>$_SESSION['csrf']]);
}
if ($action==='login' && $method==='POST') {
    check_csrf();
    check_login_rate();
    $in=post_json();
    $name=trim((string)($in['name']??'')); $pw=(string)($in['password']??'');
    $user=$name===''?null:db_row('SELECT * FROM radpate_users WHERE name=? AND active=1',[$name]);
    // Gleiche Meldung für unbekannten Namen und falsches Passwort.
    if (!$user || !password_verify($pw,(string)$user['password_hash'])) {
        if (!$user) password_verify($pw,'$2y$10$abcdefghijklmnopqrstuuJ8kq3Vt6x0VZ8l9QeXyYB6r8m0Ff0nO'); // gleiche Laufzeit
        login_failed();
        fail('Name oder Passwort ist nicht korrekt.',401);
    }
    if (password_needs_rehash((string)$user['password_hash'],PASSWORD_DEFAULT)) dbq('UPDATE radpate_users SET password_hash=? WHERE id=?',[password_hash($pw,PASSWORD_DEFAULT),$user['id']]);
    dbq('UPDATE radpate_users SET last_login_at=? WHERE id=?',[db_now(),$user['id']]);
    // Fahrten, die noch niemandem gehören (z. B. von 3.9.0 im Deploy-Fenster angelegt), dem ersten Admin geben.
    dbq("UPDATE radpate_entries SET user_id=(SELECT MIN(id) FROM radpate_users WHERE role='admin') WHERE user_id IS NULL");
    start_session_for_user($user);
    result(['ok'=>true,'csrf'=>$_SESSION['csrf']]);
}
if ($action==='logout' && $method==='POST') {
    check_csrf();
    // Neue Sitzung mit neuem CSRF-Schlüssel – die App übernimmt ihn aus der Antwort und kann sich
    // ohne Neuladen gleich wieder (auch als jemand anderes) anmelden.
    $_SESSION=['csrf'=>bin2hex(random_bytes(24))];
    session_regenerate_id(true);
    result(['ok'=>true,'csrf'=>$_SESSION['csrf']]);
}
// GPX-Schlüssel: Die Homescreen-App auf dem iPhone öffnet GPX über dl.<host>/gpx.php in einem eigenen
// Fenster. Dieses teilt die Cookies der App nicht – deshalb trägt der Link einen zeitlich begrenzten
// Schlüssel, der NUR den GPX-Download erlaubt (12 h, je Sitzung, in radpate_gpx_tokens).
const GPX_TOKEN_TTL = 12 * 3600;
function gpx_token_valid(string $token): bool {
    if (!preg_match('/^[a-f0-9]{32}$/',$token)) return false;
    return db_val('SELECT 1 FROM radpate_gpx_tokens WHERE token=? AND expires_at > UTC_TIMESTAMP()',[$token])!==null;
}
function issue_gpx_token(): string {
    if (!empty($_SESSION['gpxToken']) && (int)($_SESSION['gpxTokenExp']??0) > time()+3600) return (string)$_SESSION['gpxToken'];
    $token=bin2hex(random_bytes(16)); $exp=time()+GPX_TOKEN_TTL;
    dbq('DELETE FROM radpate_gpx_tokens WHERE expires_at <= UTC_TIMESTAMP()');
    dbq('INSERT INTO radpate_gpx_tokens (token, expires_at) VALUES (?,?)',[$token,gmdate('Y-m-d H:i:s',$exp)]);
    $_SESSION['gpxToken']=$token; $_SESSION['gpxTokenExp']=$exp;
    return $token;
}
$gpxByToken = $action==='gpx' && $method==='GET' && gpx_token_valid((string)($_GET['dl']??''));
if (!$gpxByToken) {
    require_login();
    if ($method==='POST') check_csrf();
    // Startpasswort: bis zum eigenen Passwort nur lesen, Passwort ändern, abmelden.
    if ((int)(me()['must_change_pw']??0) && $method==='POST' && !in_array($action,['password','logout'],true))
        fail('Bitte zuerst ein eigenes Passwort festlegen (Zahnrad → Passwort ändern).',403);
}

/** KML-Daten in der bisherigen Form: sources[], assignments{ebene: tour}, importedAt, fileName. */
function map_info(): array {
    $sources=[];$assign=[];
    foreach (db_all('SELECT id, name, route_id, line_data, turnpoints FROM radpate_map_sources ORDER BY position') as $r) {
        $sources[]=['id'=>$r['id'],'name'=>$r['name'],'lines'=>json_decode($r['line_data'],true)?:[],'turnpoints'=>json_decode($r['turnpoints'],true)?:[]];
        $assign[$r['id']]=$r['route_id']??'';
    }
    return ['sources'=>$sources,'assignments'=>$assign,'importedAt'=>setting('map_imported_at'),'fileName'=>setting('map_file_name')??''];
}
function map_valid_id(string $value): bool { return preg_match('/^[a-f0-9]{16}$/', $value)===1; }
function gpx_escape(string $s): string { return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8'); }
if ($action==='bootstrap' && $method==='GET') {
    $u=me();
    result(['ok'=>true,'me'=>['id'=>(int)$u['id'],'name'=>$u['name'],'role'=>$u['role'],'mustChangePassword'=>(bool)(int)$u['must_change_pw'],'routeIds'=>user_route_ids((int)$u['id'])],
        'routes'=>routes_all(),'entries'=>entries_load(),'gpxToken'=>issue_gpx_token()]);
}
if ($action==='route' && $method==='POST') {
    require_admin();
    $in=post_json();
    $id=(string)($in['id']??'');
    if (!in_array($id,ROUTE_IDS,true)) fail('Unbekannte Tour.');
    $old=db_row('SELECT * FROM radpate_routes WHERE id=?',[$id]) ?? fail('Tour nicht gefunden.');
    $name=norm((string)($in['name']??''),90);
    if (!$name) fail('Name darf nicht leer sein.');
    $km=$in['km']??null;
    if ($km!==null && $km!=='') {
        if (!is_numeric($km) || (float)$km<0.1 || (float)$km>999) fail('Ungültige Kilometerangabe.');
        $km=round((float)$km,1);
    } else { $km=null; }
    $links=['komoot'=>$old['link_komoot'],'google'=>$old['link_google'],'apple'=>$old['link_apple'],'mymaps'=>$old['link_mymaps']];
    if (isset($in['links']) && is_array($in['links'])) foreach (array_keys($links) as $app) {
        $value=trim((string)($in['links'][$app]??''));
        if (!valid_url($value)) fail('Bitte gültige HTTPS-Adresse für '. $app .' angeben.');
        $links[$app]=$value;
    }
    dbq('UPDATE radpate_routes SET name=?, area=?, note=?, km=?, link_komoot=?, link_google=?, link_apple=?, link_mymaps=? WHERE id=?',
        [$name,norm((string)($in['area']??''),180),norm((string)($in['note']??''),1000),$km,$links['komoot'],$links['google'],$links['apple'],$links['mymaps'],$id]);
    result(['ok'=>true,'route'=>route_out(db_row('SELECT * FROM radpate_routes WHERE id=?',[$id]))]);
}
function valid_tour_ids(mixed $raw): array {
    if (!is_array($raw)) fail('Bitte mindestens eine Tour wählen.');
    $ids=[];
    foreach ($raw as $id) {
        if (!is_string($id) || !preg_match('/^0[1-7]$/',$id)) fail('Ungültige Tourenauswahl.');
        if (!in_array($id,$ids,true)) $ids[]=$id;
    }
    if (!$ids || count($ids)>7) fail('Bitte mindestens eine gültige Tour wählen.');
    return $ids;
}
function valid_coords(mixed $raw): ?array {
    if ($raw===null) return null;
    if (!is_array($raw) || !is_numeric($raw['lat']??null) || !is_numeric($raw['lon']??null) || abs((float)$raw['lat'])>90 || abs((float)$raw['lon'])>180) fail('GPS-Position ist ungültig.');
    return ['lat'=>round((float)$raw['lat'],6),'lon'=>round((float)$raw['lon'],6)];
}
function valid_time(mixed $raw, bool $allowEmpty=true): ?string {
    if ($raw===null || $raw==='') { if ($allowEmpty) return null; fail('Uhrzeit fehlt.'); }
    if (!is_string($raw) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/',$raw)) fail('Uhrzeit ist ungültig (HH:MM).');
    return $raw;
}
const ISSUE_KINDS=['Belag','Beschilderung','Hindernis','Sicherheit','Verschmutzung','Bewuchs','Sonstiges'];
const ISSUE_STATES=['offen','gemeldet','bearbeitung','erledigt'];
/** Prüft eine Mangelzeile aus der App. */
function clean_issue(mixed $issue, array $tourIds): array {
    if (!is_array($issue)) fail('Ungültige Mangelzeile.');
    $issueId=(string)($issue['id']??'');
    if (!preg_match('/^i_[a-f0-9]{24}$/',$issueId)) fail('Ungültige Mangel-ID.');
    $tour=(string)($issue['tourId']??'');
    if (!in_array($tour,$tourIds,true)) fail('Mangel-Tour muss zu den geprüften Strecken gehören.');
    $kind=(string)($issue['kind']??'');
    $status=(string)($issue['status']??'offen');
    if (!in_array($kind,ISSUE_KINDS,true) || !in_array($status,ISSUE_STATES,true)) fail('Ungültige Mangelart oder Bearbeitungsstatus.');
    return ['id'=>$issueId,'tourId'=>$tour,'kind'=>$kind,'time'=>valid_time($issue['time']??null),
        'place'=>norm((string)($issue['place']??''),180),'description'=>norm((string)($issue['description']??''),3000),
        'status'=>$status,'coords'=>valid_coords($issue['coords']??null)];
}
/**
 * Mangel anlegen oder ändern (innerhalb einer Transaktion, Fahrt gesperrt).
 * Erfassungszeit bleibt; „erledigt seit“ wird beim Wechsel auf erledigt gesetzt und bleibt danach stehen.
 */
function issue_upsert(string $entryId, array $c, int $position): void {
    $prev=db_row('SELECT entry_id, status, completed_at FROM radpate_issues WHERE id=?',[$c['id']]);
    if ($prev && $prev['entry_id']!==$entryId) fail('Mangel gehört zu einer anderen Kontrollfahrt.',409);
    $done=$c['status']==='erledigt';
    $completed=$done ? (($prev && $prev['status']==='erledigt' && $prev['completed_at']) ? $prev['completed_at'] : db_now()) : null;
    $vals=[$c['tourId'],$position,$c['kind'],$c['time'],$c['place'],$c['description'],$c['status'],$c['coords']['lat']??null,$c['coords']['lon']??null,$completed];
    if ($prev) dbq('UPDATE radpate_issues SET route_id=?, position=?, kind=?, issue_time=?, place=?, description=?, status=?, lat=?, lon=?, completed_at=? WHERE id=?',[...$vals,$c['id']]);
    else dbq('INSERT INTO radpate_issues (route_id, position, kind, issue_time, place, description, status, lat, lon, completed_at, id, entry_id, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',[...$vals,$c['id'],$entryId,db_now()]);
}
function issue_one(string $issueId): array {
    $r=db_row('SELECT i.*, u.name AS status_by_name FROM radpate_issues i LEFT JOIN radpate_users u ON u.id=i.status_by WHERE i.id=?',[$issueId]) ?? fail('Mangel nicht gefunden.',404);
    return issue_out($r,array_map('photo_out',db_all('SELECT * FROM radpate_photos WHERE issue_id=? ORDER BY position, created_at',[$issueId])));
}
function entry_status(array $issues): string { return count($issues)>0?'mangel':'gut'; }

if ($action==='entry' && $method==='POST') {
    $in=post_json();
    $id=(string)($in['id']??'');
    if ($id!=='' && !preg_match('/^[a-f0-9]{24}$/',$id)) fail('Ungültige Eintrags-ID.');
    $tourIds=valid_tour_ids($in['tourIds']??[]);
    $date=(string)($in['date']??'');
    if (!valid_date($date)) fail('Datum ist ungültig.');
    $rawIssues=$in['issues']??[];
    if (!is_array($rawIssues) || count($rawIssues)>25) fail('Maximal 25 Mängel pro Kontrollfahrt.');
    $issues=[];$seen=[];
    foreach ($rawIssues as $issue) {
        $clean=clean_issue($issue,$tourIds);
        if (isset($seen[$clean['id']])) fail('Doppelte Mangel-ID.');
        $seen[$clean['id']]=true; $issues[]=$clean;
    }
    $startTime=valid_time($in['startTime']??null);
    $endTime=valid_time($in['endTime']??null);
    $notes=norm((string)($in['notes']??''),5000);
    $coords=valid_coords($in['coords']??null);
    [$entryId,$discarded]=db_tx(function () use ($id,$tourIds,$date,$startTime,$endTime,$notes,$coords,$issues) {
        $now=db_now();
        if ($id!=='') {
            entry_lock($id);
            dbq('UPDATE radpate_entries SET trip_date=?, start_time=?, end_time=?, notes=?, lat=?, lon=?, updated_at=?, running=IF(? IS NULL, running, 0) WHERE id=?',
                [$date,$startTime,$endTime,$notes,$coords['lat']??null,$coords['lon']??null,$now,$endTime,$id]);
            $entryId=$id;
        } else {
            $entryId=bin2hex(random_bytes(12));
            dbq('INSERT INTO radpate_entries (id, user_id, trip_date, start_time, end_time, running, notes, lat, lon, created_at, updated_at) VALUES (?,?,?,?,?,0,?,?,?,?,?)',
                [$entryId,my_id(),$date,$startTime,$endTime,$notes,$coords['lat']??null,$coords['lon']??null,$now,$now]);
        }
        entry_set_tours($entryId,$tourIds);
        foreach ($issues as $pos=>$c) issue_upsert($entryId,$c,$pos);
        // Mängel, die in der Liste fehlen, samt Fotos entfernen.
        $keep=array_column($issues,'id');
        $ph=$keep?implode(',',array_fill(0,count($keep),'?')):"''";
        $discarded=db_all("SELECT p.id, p.file FROM radpate_photos p JOIN radpate_issues i ON i.id=p.issue_id WHERE i.entry_id=? AND i.id NOT IN ($ph)",[$entryId,...$keep]);
        dbq("DELETE FROM radpate_issues WHERE entry_id=? AND id NOT IN ($ph)",[$entryId,...$keep]);
        return [$entryId,$discarded];
    });
    delete_photo_rows_files($discarded);
    result(['ok'=>true,'entry'=>entry_one($entryId)]);
}
// Mangel erledigt/offen setzen, ohne die übrige Kontrollfahrt anzufassen.
if ($action==='issueStatus' && $method==='POST') {
    $in=post_json();
    $entryId=(string)($in['entryId']??'');
    $issueId=(string)($in['issueId']??'');
    $next=(string)($in['status']??'');
    if (!preg_match('/^[a-f0-9]{24}$/',$entryId) || !preg_match('/^i_[a-f0-9]{24}$/',$issueId)) fail('Ungültige Mangelkennung.');
    if (!in_array($next,['erledigt','offen'],true)) fail('Ungültiger Mangelstatus.');
    db_tx(function () use ($entryId,$issueId,$next) {
        entry_lock($entryId,false); // Status darf jeder setzen
        $old=db_row('SELECT status, completed_at FROM radpate_issues WHERE id=? AND entry_id=?',[$issueId,$entryId]) ?? fail('Mangel nicht gefunden.',404);
        $completed=$next==='erledigt' ? (($old['status']==='erledigt' && $old['completed_at']) ? $old['completed_at'] : db_now()) : null;
        dbq('UPDATE radpate_issues SET status=?, completed_at=?, status_by=? WHERE id=?',[$next,$completed,my_id(),$issueId]);
        entry_touch($entryId);
    });
    result(['ok'=>true,'issue'=>issue_one($issueId),'entryId'=>$entryId]);
}
// ── Laufende Kontrollfahrt: anlegen, Metadaten ändern/beenden, Mängel einzeln speichern ──
if ($action==='tripStart' && $method==='POST') {
    $in=post_json();
    $tourIds=valid_tour_ids($in['tourIds']??[]);
    $date=(string)($in['date']??'');
    if (!valid_date($date)) fail('Datum ist ungültig.');
    $startTime=valid_time($in['startTime']??null,false);
    $id=bin2hex(random_bytes(12));
    db_tx(function () use ($id,$tourIds,$date,$startTime) {
        $now=db_now();
        dbq("INSERT INTO radpate_entries (id, user_id, trip_date, start_time, end_time, running, notes, created_at, updated_at) VALUES (?,?,?,?,NULL,1,'',?,?)",[$id,my_id(),$date,$startTime,$now,$now]);
        entry_set_tours($id,$tourIds);
    });
    result(['ok'=>true,'entry'=>entry_one($id)]);
}
if ($action==='tripUpdate' && $method==='POST') {
    $in=post_json();
    $id=(string)($in['entryId']??'');
    if (!preg_match('/^[a-f0-9]{24}$/',$id)) fail('Ungültige Eintrags-ID.');
    db_tx(function () use ($id,$in) {
        $e=entry_lock($id);
        $date=$e['trip_date']; $start=db_hm($e['start_time']); $end=db_hm($e['end_time']); $notes=$e['notes']; $running=(bool)$e['running'];
        if (array_key_exists('date',$in)) { if (!valid_date((string)$in['date'])) fail('Datum ist ungültig.'); $date=(string)$in['date']; }
        if (array_key_exists('startTime',$in)) $start=valid_time($in['startTime'],false);
        if (array_key_exists('endTime',$in)) $end=valid_time($in['endTime']);
        if (array_key_exists('notes',$in)) $notes=norm((string)$in['notes'],5000);
        if (array_key_exists('running',$in)) $running=(bool)$in['running'];
        if ($running && $end!==null && !($in['running']??false)) $running=false;
        if (array_key_exists('tourIds',$in)) {
            $ids=valid_tour_ids($in['tourIds']);
            foreach (db_all('SELECT DISTINCT route_id FROM radpate_issues WHERE entry_id=?',[$id]) as $r)
                if (!in_array($r['route_id'],$ids,true)) fail('Eine Tour mit erfassten Mängeln kann nicht entfernt werden.');
            entry_set_tours($id,$ids);
        }
        dbq('UPDATE radpate_entries SET trip_date=?, start_time=?, end_time=?, notes=?, running=?, updated_at=? WHERE id=?',[$date,$start,$end,$notes,$running?1:0,db_now(),$id]);
    });
    result(['ok'=>true,'entry'=>entry_one($id)]);
}
if ($action==='issueSave' && $method==='POST') {
    $in=post_json();
    $id=(string)($in['entryId']??'');
    if (!preg_match('/^[a-f0-9]{24}$/',$id)) fail('Ungültige Eintrags-ID.');
    $issueId=db_tx(function () use ($id,$in) {
        entry_lock($id);
        $clean=clean_issue($in['issue']??null,entry_tour_ids($id));
        $pos=db_val('SELECT position FROM radpate_issues WHERE id=? AND entry_id=?',[$clean['id'],$id]);
        if ($pos===null) {
            $n=(int)db_val('SELECT COUNT(*) FROM radpate_issues WHERE entry_id=?',[$id]);
            if ($n>=25) fail('Maximal 25 Mängel pro Kontrollfahrt.');
            $pos=(int)db_val('SELECT COALESCE(MAX(position)+1,0) FROM radpate_issues WHERE entry_id=?',[$id]);
        }
        issue_upsert($id,$clean,(int)$pos);
        entry_touch($id);
        return $clean['id'];
    });
    result(['ok'=>true,'issue'=>issue_one($issueId)]);
}
if ($action==='issueDelete' && $method==='POST') {
    $in=post_json();
    $id=(string)($in['entryId']??''); $issueId=(string)($in['issueId']??'');
    if (!preg_match('/^[a-f0-9]{24}$/',$id) || !preg_match('/^i_[a-f0-9]{24}$/',$issueId)) fail('Ungültige Mangelkennung.');
    $photos=db_tx(function () use ($id,$issueId) {
        entry_lock($id);
        if (db_val('SELECT 1 FROM radpate_issues WHERE id=? AND entry_id=?',[$issueId,$id])===null) fail('Mangel nicht gefunden.',404);
        $photos=db_all('SELECT id, file FROM radpate_photos WHERE issue_id=?',[$issueId]);
        dbq('DELETE FROM radpate_issues WHERE id=?',[$issueId]); // Fotozeilen per ON DELETE CASCADE
        entry_touch($id);
        return $photos;
    });
    delete_photo_rows_files($photos);
    result(['ok'=>true]);
}
if ($action==='deleteEntry' && $method==='POST') {
    $id=(string)(post_json()['id']??'');
    $photos=db_tx(function () use ($id) {
        entry_lock($id);
        $photos=db_all('SELECT id, file FROM radpate_photos WHERE entry_id=?',[$id]);
        dbq('DELETE FROM radpate_entries WHERE id=?',[$id]); // Touren, Mängel, Fotozeilen per CASCADE
        return $photos;
    });
    delete_photo_rows_files($photos);
    result(['ok'=>true]);
}
if ($action==='upload' && $method==='POST') {
    $entryId=(string)($_POST['entryId']??'');
    $issueId=(string)($_POST['issueId']??'');
    if (!preg_match('/^[a-f0-9]{24}$/',$entryId)) fail('Eintrag nicht gefunden.',404);
    if ($issueId!=='' && !preg_match('/^i_[a-f0-9]{24}$/',$issueId)) fail('Ungültiger Mangel.');
    if (!isset($_FILES['file']) || $_FILES['file']['error']!==UPLOAD_ERR_OK) fail('Foto konnte nicht hochgeladen werden (ggf. PHP-Uploadlimit prüfen).');
    $f=$_FILES['file'];
    if ($f['size']>APP_MAX_UPLOAD_MB*1024*1024) fail('Foto ist zu groß (maximal '.APP_MAX_UPLOAD_MB.' MB).');
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $types=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
    if (!isset($types[$mime]) || @getimagesize($f['tmp_name'])===false) fail('Erlaubt sind JPG, PNG und WebP.');
    $path=storage().'/photos';
    if (!is_dir($path) && !mkdir($path,0700,true)) fail('Foto-Ordner nicht beschreibbar.',500);
    $photoId=bin2hex(random_bytes(12));$filename=$photoId.'.'.$types[$mime];
    if ($mime==='image/jpeg') { strip_jpeg_metadata($f['tmp_name']); clearstatcache(true,$f['tmp_name']); }
    $photo=['id'=>$photoId,'name'=>norm((string)$f['name'],120),'file'=>$filename,'mime'=>$mime,'size'=>(int)filesize($f['tmp_name'])];
    // Zeile zuerst (Grenzen geprüft unter Sperre), dann die Datei; scheitert die Datei, Zeile wieder weg.
    db_tx(function () use ($entryId,$issueId,$photo) {
        entry_lock($entryId);
        if ((int)db_val('SELECT COUNT(*) FROM radpate_photos WHERE entry_id=?',[$entryId])>=40) fail('Maximal 40 Fotos je Kontrollfahrt.');
        if ($issueId==='') {
            if ((int)db_val('SELECT COUNT(*) FROM radpate_photos WHERE entry_id=? AND issue_id IS NULL',[$entryId])>=8) fail('Maximal 8 allgemeine Fotos.');
        } else {
            if (db_val('SELECT 1 FROM radpate_issues WHERE id=? AND entry_id=?',[$issueId,$entryId])===null) fail('Mangel nicht gefunden.',404);
            if ((int)db_val('SELECT COUNT(*) FROM radpate_photos WHERE issue_id=?',[$issueId])>=5) fail('Maximal 5 Fotos je Mangel.');
        }
        $pos=(int)db_val('SELECT COUNT(*) FROM radpate_photos WHERE entry_id=? AND issue_id <=> ?',[$entryId,$issueId===''?null:$issueId]);
        dbq('INSERT INTO radpate_photos (id, entry_id, issue_id, position, file, name, mime, size, created_at) VALUES (?,?,?,?,?,?,?,?,?)',
            [$photo['id'],$entryId,$issueId===''?null:$issueId,$pos,$photo['file'],$photo['name'],$photo['mime'],$photo['size'],db_now()]);
    });
    if (!move_uploaded_file($f['tmp_name'],$path.'/'.$filename)) {
        dbq('DELETE FROM radpate_photos WHERE id=?',[$photoId]);
        fail('Speichern des Fotos fehlgeschlagen.',500);
    }
    @chmod($path.'/'.$filename,0600);
    result(['ok'=>true,'photo'=>$photo]);
}
if ($action==='deletePhoto' && $method==='POST') {
    $id=(string)(post_json()['id']??'');
    $row=db_row('SELECT p.id, p.file, e.user_id FROM radpate_photos p JOIN radpate_entries e ON e.id=p.entry_id WHERE p.id=?',[$id]) ?? fail('Foto nicht gefunden.',404);
    require_owner($row);
    dbq('DELETE FROM radpate_photos WHERE id=?',[$id]);
    delete_photo_rows_files([$row]);
    result(['ok'=>true]);
}
if ($action==='photo' && $method==='GET') {
    $pid=(string)($_GET['id']??'');
    if (!preg_match('/^[0-9a-f]{24}$/',$pid)) fail('Foto nicht gefunden.',404);
    $metadata=db_row('SELECT p.file, p.mime, p.issue_id, e.user_id FROM radpate_photos p JOIN radpate_entries e ON e.id=p.entry_id WHERE p.id=?',[$pid]);
    if (!$metadata) fail('Foto nicht gefunden.',404);
    // Mängelfotos sehen alle; allgemeine Fotos einer Fahrt nur Ersteller und Admin.
    if ($metadata['issue_id']===null && !is_admin() && (int)$metadata['user_id']!==my_id()) fail('Foto nicht gefunden.',404);
    $file=storage().'/photos/'.basename($metadata['file']);
    if (!is_file($file)) fail('Foto nicht gefunden.',404);
    $mime=(string)$metadata['mime'];
    if (($_GET['thumb']??'')==='1') {
        $thumb=photo_thumbnail($file,$pid);
        if ($thumb) { $file=$thumb; $mime='image/jpeg'; }
    }
    // Foto-IDs ändern sich nie: der Browser darf privat zwischenspeichern.
    header('Cache-Control: private, max-age=31536000, immutable');
    header('Content-Type: '.$mime);
    header('Content-Length: '.filesize($file));
    header('Content-Disposition: inline; filename="foto.'.pathinfo($file,PATHINFO_EXTENSION).'"');
    readfile($file); exit;
}
if ($action==='export' && $method==='GET') {
    require_admin();
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="radwegpate-tagebuch-'.date('Y-m-d').'.json"');
    echo json_encode(['exportedAt'=>gmdate('c'),'routes'=>routes_all(),'entries'=>entries_load(),'mapData'=>map_info()],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;
}
if ($action==='password' && $method==='POST') {
    $in=post_json();
    $old=(string)($in['old']??'');
    $hash=(string)db_val('SELECT password_hash FROM radpate_users WHERE id=?',[my_id()]);
    if (!password_verify($old,$hash)) fail('Das bisherige Passwort ist nicht korrekt.');
    $new=valid_new_password($in['new']??'');
    if (hash_equals($old,$new)) fail('Das neue Passwort muss sich vom bisherigen unterscheiden.');
    dbq('UPDATE radpate_users SET password_hash=?, must_change_pw=0, password_version=password_version+1 WHERE id=?',[password_hash($new,PASSWORD_DEFAULT),my_id()]);
    start_session_for_user(db_row('SELECT * FROM radpate_users WHERE id=?',[my_id()])); // diese Sitzung bleibt, alle anderen sind abgemeldet
    result(['ok'=>true]);
}

// ── Benutzerverwaltung (nur Admin) ──
if ($action==='users' && $method==='GET') {
    require_admin();
    $rows=db_all('SELECT u.*, (SELECT COUNT(*) FROM radpate_entries e WHERE e.user_id=u.id) AS entries FROM radpate_users u ORDER BY u.active DESC, u.name');
    result(['ok'=>true,'users'=>array_map('user_out',$rows)]);
}
if ($action==='userSave' && $method==='POST') {
    require_admin();
    $in=post_json();
    $id=(int)($in['id']??0);
    $name=valid_user_name($in['name']??'');
    $role=(string)($in['role']??'pate');
    if (!in_array($role,USER_ROLES,true)) fail('Unbekannte Rolle.');
    $active=array_key_exists('active',$in)?(bool)$in['active']:true;
    $pw=(string)($in['password']??'');
    // Touren des Paten (optional; fehlt der Schlüssel, bleibt die Zuordnung unverändert).
    $routeIds=null;
    if (array_key_exists('routeIds',$in)) {
        if (!is_array($in['routeIds'])) fail('Ungültige Tourenauswahl.');
        $routeIds=array_values(array_unique(array_map('strval',$in['routeIds'])));
        foreach ($routeIds as $rid) if (!in_array($rid,ROUTE_IDS,true)) fail('Ungültige Tourenauswahl.');
    }
    $saved=db_tx(function () use ($id,$name,$role,$active,$pw,$routeIds) {
        $dupe=db_val('SELECT id FROM radpate_users WHERE name=? AND id<>?',[$name,$id]);
        if ($dupe!==null) fail('Diesen Namen gibt es schon.');
        if ($id===0) {
            $hash=password_hash(valid_new_password($pw),PASSWORD_DEFAULT);
            dbq('INSERT INTO radpate_users (name, password_hash, role, active, must_change_pw, password_version, created_at) VALUES (?,?,?,?,1,1,?)',[$name,$hash,$role,$active?1:0,db_now()]);
            $newId=(int)db()->lastInsertId();
            if ($routeIds!==null) foreach ($routeIds as $rid) dbq('INSERT INTO radpate_user_routes (user_id, route_id) VALUES (?,?)',[$newId,$rid]);
            return $newId;
        }
        $old=db_row('SELECT * FROM radpate_users WHERE id=? FOR UPDATE',[$id]) ?? fail('Benutzer nicht gefunden.',404);
        if ($id===my_id() && ($role!=='admin' || !$active)) fail('Die eigene Admin-Rolle bzw. den eigenen Zugang kann man nicht entziehen.');
        if ($id===my_id() && $pw!=='') fail('Das eigene Passwort bitte über das Zahnrad ändern.');
        if ($old['role']==='admin' && ($role!=='admin' || !$active)) {
            $admins=(int)db_val("SELECT COUNT(*) FROM radpate_users WHERE role='admin' AND active=1 AND id<>? FOR UPDATE",[$id]);
            if ($admins<1) fail('Es muss mindestens ein aktiver Admin bleiben.');
        }
        dbq('UPDATE radpate_users SET name=?, role=?, active=? WHERE id=?',[$name,$role,$active?1:0,$id]);
        // Neues Startpasswort: muss beim nächsten Login geändert werden, offene Sitzungen enden.
        if ($pw!=='') dbq('UPDATE radpate_users SET password_hash=?, must_change_pw=1, password_version=password_version+1 WHERE id=?',[password_hash(valid_new_password($pw),PASSWORD_DEFAULT),$id]);
        // Rolle oder Zugang geändert: offene Sitzungen beenden, damit die neue Rolle sofort gilt.
        elseif ($old['role']!==$role || (int)$old['active']!==($active?1:0)) dbq('UPDATE radpate_users SET password_version=password_version+1 WHERE id=?',[$id]);
        if ($routeIds!==null) {
            dbq('DELETE FROM radpate_user_routes WHERE user_id=?',[$id]);
            foreach ($routeIds as $rid) dbq('INSERT INTO radpate_user_routes (user_id, route_id) VALUES (?,?)',[$id,$rid]);
        }
        return $id;
    });
    $row=db_row('SELECT u.*, (SELECT COUNT(*) FROM radpate_entries e WHERE e.user_id=u.id) AS entries FROM radpate_users u WHERE u.id=?',[$saved]);
    result(['ok'=>true,'user'=>user_out($row)]);
}

// KML wird im Browser geparst. Linien, explizite Wendepunkte und Ebenenzuordnung werden gespeichert.
// Keine KML/XML-Verarbeitung auf dem Server: keine PHP-DOM-Erweiterung erforderlich.
if ($action==='mapData' && $method==='GET') {
    result(['ok'=>true,'map'=>map_info()]);
}
if ($action==='mapImport' && $method==='POST') {
    require_admin();
    if ((int)($_SERVER['CONTENT_LENGTH']??0)>10000000) fail('Import zu groß (maximal 10 MB).',413);
    $in=post_json();
    $sources=$in['sources']??null;
    if (!is_array($sources) || count($sources)<1 || count($sources)>250) fail('Keine gültigen Streckenlinien in der KML gefunden.');
    $seen=[];$totalPoints=0;$totalLines=0;$clean=[];
    foreach($sources as $src) {
        if (!is_array($src)) fail('Ungültige KML-Ebene.');
        $id=(string)($src['id']??'');
        if (!map_valid_id($id) || isset($seen[$id])) fail('Ungültige/mehrfache Ebenen-ID.');
        $seen[$id]=true;
        $name=norm((string)($src['name']??''),160);
        $lines=[];
        if (!is_array($src['lines']??null) || count($src['lines'])>250) fail('Zu viele Linien in einer Ebene.');
        foreach($src['lines'] as $line) {
            if (!is_array($line)||count($line)<2||count($line)>80000) fail('Ungültige KML-Linie.');
            $points=[];
            foreach($line as $pt){
                if(!is_array($pt)||count($pt)<2||!is_numeric($pt[0]??null)||!is_numeric($pt[1]??null)) fail('Ungültige Koordinate.');
                $lon=(float)$pt[0];$lat=(float)$pt[1];
                if(!is_finite($lon)||!is_finite($lat)||abs($lon)>180||abs($lat)>90) fail('Koordinaten außerhalb des gültigen Bereichs.');
                $points[]=[$lon,$lat];$totalPoints++;
                if($totalPoints>110000) fail('Zu viele GPS-Punkte (maximal 110.000).');
            }
            $totalLines++;if($totalLines>1500) fail('Zu viele KML-Linien.');
            $lines[]=$points;
        }
        $turnpoints=[];
        $incoming=$src['turnpoints']??[];
        if (!is_array($incoming) || count($incoming)>12) fail('Zu viele Wendepunkt-Marker.');
        foreach($incoming as $marker){
            if(!is_array($marker)||!is_array($marker['point']??null)||count($marker['point'])!==2) fail('Ungültiger Wendepunkt.');
            $point=$marker['point'];
            if(!is_numeric($point[0])||!is_numeric($point[1])) fail('Ungültige Wendepunkt-Koordinaten.');
            $lon=(float)$point[0];$lat=(float)$point[1];
            if(!is_finite($lon)||!is_finite($lat)||abs($lon)>180||abs($lat)>90) fail('Wendepunkt außerhalb gültiger Koordinaten.');
            $onLine=false;
            foreach($lines as $line){
                foreach($line as $vertex){
                    if(abs($vertex[0]-$lon)<0.0000001 && abs($vertex[1]-$lat)<0.0000001){$onLine=true;break 2;}
                }
            }
            if(!$onLine) fail('Wendepunkt liegt nicht auf der importierten KML-Linie. Bitte Original-KML prüfen.');
            $turnpoints[]=['name'=>norm((string)($marker['name']??'Wendepunkt'),100),'point'=>[$lon,$lat]];
        }
        if ($lines) $clean[]=['id'=>$id,'name'=>$name?:'Unbenannte Ebene','lines'=>$lines,'turnpoints'=>$turnpoints];
    }
    if(!$clean) fail('Keine Linien importiert.');
    $fileName=norm((string)($in['fileName']??'KML-Import'),180);
    // Ersetzt alle Ebenen. Zuordnung: aus dem Import, sonst die bisherige derselben Ebene.
    db_tx(function () use ($clean,$in,$fileName) {
        $prior=[];
        foreach (db_all('SELECT id, route_id FROM radpate_map_sources FOR UPDATE') as $r) $prior[$r['id']]=$r['route_id']??'';
        dbq('DELETE FROM radpate_map_sources');
        foreach ($clean as $pos=>$src){
            $auto=(string)($in['assignments'][$src['id']]??'');
            $before=(string)($prior[$src['id']]??'');
            $route=in_array($auto,ROUTE_IDS,true)?$auto:(in_array($before,ROUTE_IDS,true)?$before:null);
            dbq('INSERT INTO radpate_map_sources (id, position, name, route_id, line_data, turnpoints) VALUES (?,?,?,?,?,?)',
                [$src['id'],$pos,$src['name'],$route,json_encode($src['lines'],JSON_UNESCAPED_SLASHES),json_encode($src['turnpoints'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]);
        }
        setting_set('map_imported_at',gmdate('c'));
        setting_set('map_file_name',$fileName);
    });
    result(['ok'=>true,'sources'=>count($clean),'lines'=>$totalLines,'points'=>$totalPoints]);
}
if ($action==='mapAssign' && $method==='POST') {
    require_admin();
    $in=post_json();
    db_tx(function () use ($in) {
        $available=array_column(db_all('SELECT id FROM radpate_map_sources ORDER BY position FOR UPDATE'),'id');
        if(!$available) fail('Zuerst KML importieren.');
        foreach($available as $id){
            $value=(string)($in['assignments'][$id]??'');
            if(!in_array($value,['',...ROUTE_IDS],true)) fail('Tourenzuordnung ungültig.');
            dbq('UPDATE radpate_map_sources SET route_id=? WHERE id=?',[$value===''?null:$value,$id]);
        }
    });
    result(['ok'=>true]);
}
if ($action==='gpx' && $method==='GET') {
    // Einzeltrack wie bisher; mehrere IDs = kombinierter GPS-Track, ohne Wegerfindung.
    $rawCombo=(string)($_GET['ids']??'');
    $combo=($rawCombo!=='');
    $ids=$combo?explode(',', $rawCombo):[(string)($_GET['id']??'')];
    $valid=ROUTE_IDS;
    if (($combo && (count($ids)<2 || count($ids)>7 || count($ids)!==count(array_unique($ids))))
        || (!$combo&&count($ids)!==1)) fail('Für eine Kombination bitte zwei bis sieben unterschiedliche Touren wählen.');
    foreach($ids as $id) if(!in_array($id,$valid,true)) fail('Unbekannte Tour.');
    $m=map_info();$byId=[];
    foreach($ids as $id) $byId[$id]=[];
    foreach(($m['sources']??[]) as $src){
        $id=$m['assignments'][$src['id']]??'';
        if(array_key_exists($id,$byId))foreach(($src['lines']??[]) as $line)$byId[$id][]=$line;
    }
    foreach($ids as $id)if(!$byId[$id])fail('Für Tour '.$id.' ist keine KML-Linie vorhanden.',404);
    $routeNames=array_column(db_all('SELECT id, name FROM radpate_routes'),'name','id');$names=[];
    foreach($ids as $id) $names[]=$routeNames[$id]??('Tour '.$id);
    if($combo){
        $last=null;$joined=[];
        foreach($ids as $id){
            foreach($byId[$id] as $line){
                if(count($line)<2)fail('Unvollständige KML-Linie in Tour '.$id.'.',422);
                if($last!==null){
                    $first=$line[0];
                    if(abs((float)$last[0]-(float)$first[0])>=0.0000001 || abs((float)$last[1]-(float)$first[1])>=0.0000001)
                        fail('Die ausgewählten Touren schließen nicht lückenlos aneinander an. Es wurde keine Strecke hinzugefügt.',422);
                }
                foreach($line as $point)$joined[]=$point;
                $last=$line[count($line)-1];
            }
        }
        $lines=[$joined];
        $name='Kombination '.implode(' + ',$ids);
        $filename='radwegpate-kombi-'.implode('-',$ids).'.gpx';
    }else{
        $lines=$byId[$ids[0]];
        $name=$names[0];
        $filename='radwegpate-tour-'.$ids[0].'.gpx';
    }
    header('Content-Type: application/gpx+xml; charset=utf-8');
    // Schlüssel-Link: inline, außer die Download-Seite gpx.php fordert ausdrücklich eine Datei an (save=1).
    $inline = $gpxByToken && ($_GET['save']??'')!=='1';
    header('Content-Disposition: '.($inline ? 'inline' : 'attachment').'; filename="'.$filename.'"');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<gpx version="1.1" creator="Radwegpate" xmlns="http://www.topografix.com/GPX/1/1"><metadata><name>'.gpx_escape($name).'</name></metadata><trk><name>'.gpx_escape($name).'</name>';
    foreach($lines as $line){
        echo '<trkseg>';
        foreach($line as $pos){ echo '<trkpt lat="'.htmlspecialchars((string)$pos[1], ENT_QUOTES).'" lon="'.htmlspecialchars((string)$pos[0],ENT_QUOTES).'"></trkpt>'; }
        echo '</trkseg>';
    }
    echo '</trk></gpx>';exit;
}
fail('Unbekannte Funktion.',404);
