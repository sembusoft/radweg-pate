<?php
declare(strict_types=1);
// RADWEGPATE_CONFIG: nur für die lokale Wegwerf-Testinstanz (docker-compose, Dienst "test"),
// gesetzt per Apache-SetEnv. Produktiv nicht gesetzt → config.php neben dieser Datei.
$configFile = (string)(getenv('RADWEGPATE_CONFIG') ?: __DIR__ . '/config.php');
if (!is_file($configFile)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo '{"ok":false,"error":"config.php fehlt. Vorlage config.example.php kopieren und anpassen."}';
    exit;
}
require $configFile;
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
function read_json(string $name, array $default=[]): array {
    $file=storage().'/'.$name.'.json';
    if (!is_file($file)) return $default;
    $data=json_decode((string)file_get_contents($file), true);
    return is_array($data) ? $data : $default;
}
function write_json_atomic(string $name,array $data): void {
    $path=storage().'/'.$name.'.json';
    $tmp=storage().'/.'.basename($name).'.'.bin2hex(random_bytes(8)).'.tmp';
    $encoded=json_encode($data,JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($encoded===false || file_put_contents($tmp,$encoded,LOCK_EX)===false) fail('Speichern fehlgeschlagen.',500);
    @chmod($tmp,0600);
    if (!rename($tmp,$path)) { @unlink($tmp); fail('Speichern fehlgeschlagen.',500); }
}
function update_json(string $name, callable $fn, array $default=[]): mixed {
    $fp=fopen(storage().'/'.$name.'.lock','c');
    if (!$fp || !flock($fp,LOCK_EX)) fail('Sperre konnte nicht gesetzt werden.',500);
    try {
        $data=read_json($name,$default);
        $res=$fn($data);
        write_json_atomic($name,$data);
        return $res;
    } finally { flock($fp,LOCK_UN); fclose($fp); }
}
function post_json(): array {
    $data=json_decode(file_get_contents('php://input') ?: '{}',true);
    if (!is_array($data)) fail('Ungültige Eingabe.');
    return $data;
}
function check_csrf(): void {
    if (!hash_equals($_SESSION['csrf']??'', $_SERVER['HTTP_X_CSRF_TOKEN']??'')) fail('Sicherheitsprüfung fehlgeschlagen. Bitte neu laden.',403);
}
function password_version(): int { return (int)(read_json('install')['pwv'] ?? 0); }
function logged_in(): bool {
    // Nach einem Passwortwechsel (pwv erhöht) sind alle anderen Sitzungen ungültig.
    return !empty($_SESSION['logged'])
        && (time() - (int)($_SESSION['last_active']??0)) < 60*60*24*14
        && (int)($_SESSION['pwv'] ?? 0) === password_version();
}
function start_session_for_user(): void {
    session_regenerate_id(true);
    $_SESSION['logged']=true; $_SESSION['last_active']=time(); $_SESSION['pwv']=password_version();
}
function require_login(): void {
    if (!logged_in()) {unset($_SESSION['logged']); fail('Bitte erneut anmelden.',401);}
    $_SESSION['last_active']=time();
}
function defaults(): array {
    // Namen wie die Ebenen der My-Maps-KML (Stand 10/2026). Gilt nur für Neuinstallationen;
    // bestehende routes.json bleibt unverändert.
    return [
        ['id'=>'01','name'=>'West / Illerbrücke Oberkirchberg','type'=>'abfahrt','area'=>'Richtung Iller / westliche Gemeindegrenze','links'=>['komoot'=>'','google'=>'','apple'=>'','mymaps'=>''],'note'=>'Verlauf und Endpunkt noch anhand der Originalkarte prüfen.'],
        ['id'=>'02','name'=>'Nord / Freudenegg – Gerlenhofen','type'=>'abfahrt','area'=>'Richtung Aufheim / nördliche Gemeindegrenze','links'=>['komoot'=>'','google'=>'','apple'=>'','mymaps'=>''],'note'=>'Verlauf und Endpunkt noch anhand der Originalkarte prüfen.'],
        ['id'=>'03','name'=>'Nordost / Hittistetten','type'=>'abfahrt','area'=>'Nördlicher Bereich Richtung Hittistetten','links'=>['komoot'=>'','google'=>'','apple'=>'','mymaps'=>''],'note'=>'Verlauf und Endpunkt noch anhand der Originalkarte prüfen.'],
        ['id'=>'04','name'=>'Ost / Wullenstetten – Witzighausen','type'=>'abfahrt','area'=>'Wullenstetten / Witzighausen / Richtung Weißenhorn','links'=>['komoot'=>'','google'=>'','apple'=>'','mymaps'=>''],'note'=>'Verlauf und Endpunkt noch anhand der Originalkarte prüfen.'],
        ['id'=>'05','name'=>'Süd / Richtung Illerzell','type'=>'abfahrt','area'=>'Südlicher Außenbereich von Senden','links'=>['komoot'=>'','google'=>'','apple'=>'','mymaps'=>''],'note'=>'Verlauf und Endpunkt noch anhand der Originalkarte prüfen.'],
        ['id'=>'06','name'=>'Südost / Richtung Illerberg','type'=>'abfahrt','area'=>'Südöstlicher Bereich Richtung Illerberg','links'=>['komoot'=>'','google'=>'','apple'=>'','mymaps'=>''],'note'=>'Verlauf und Endpunkt noch anhand der Originalkarte prüfen.'],
        ['id'=>'07','name'=>'Gartenparadies-Runde','type'=>'runde','area'=>'Rosa Rundtour (Originalroute ca. 39,5 km, ohne individuelle Zu-/Rückfahrt)','links'=>['komoot'=>'','google'=>'','apple'=>'','mymaps'=>''],'note'=>'Start / Ende Flurweg 7: individuelle Zu- und Rückfahrt noch prüfen.']
    ];
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
function check_login_rate(): void {
    $ip=(string)($_SERVER['REMOTE_ADDR']??'unknown');
    $fp=storage().'/attempt_'.hash('sha256',$ip).'.json';
    $events=json_decode((string)@file_get_contents($fp),true);
    $events=is_array($events)?$events:[];
    $events=array_values(array_filter($events, fn($v)=>is_numeric($v)&&$v>time()-900));
    if (count($events)>=10) fail('Zu viele Anmeldeversuche. In 15 Minuten erneut versuchen.',429);
    $events[]=time();
    file_put_contents($fp,json_encode($events),LOCK_EX);
    // Gelegentlich verwaiste Zähler anderer IPs entfernen (älter als einen Tag).
    if (random_int(1,20)===1) foreach (glob(storage().'/attempt_*.json') ?: [] as $old) {
        if (filemtime($old) < time()-86400) @unlink($old);
    }
}
function photo_metadata(string $pid): ?array {
    foreach (read_json('entries') as $entry) {
        foreach (($entry['photos']??[]) as $p) if (($p['id']??'')===$pid) return $p;
        foreach (($entry['issues']??[]) as $issue) foreach (($issue['photos']??[]) as $p) if (($p['id']??'')===$pid) return $p;
    }
    return null;
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
$installed=is_file(storage().'/install.json');

if ($action==='status' && $method==='GET') {
    result(['ok'=>true,'installed'=>$installed,'loggedIn'=>$installed && logged_in(), 'csrf'=>$_SESSION['csrf'], 'version'=>APP_VERSION]);
}
if ($action==='setup' && $method==='POST') {
    check_csrf();
    if ($installed) fail('Einrichtung bereits abgeschlossen.',409);
    $token=defined('APP_INSTALL_TOKEN') ? (string)APP_INSTALL_TOKEN : '';
    if (strlen($token)<20 || str_starts_with($token,'HIER-')) fail('Einrichtung gesperrt: APP_INSTALL_TOKEN in config.php setzen (mindestens 20 Zeichen).',503);
    check_login_rate();
    $data=post_json();
    if (!hash_equals($token, (string)($data['code']??''))) fail('Einrichtungscode stimmt nicht.',403);
    $pw=(string)($data['password']??'');
    if (strlen($pw)<12 || strlen($pw)>256) fail('Passwort muss 12 bis 256 Zeichen haben.');
    $path=storage().'/install.json';
    $file=@fopen($path,'x');
    if (!$file) fail('Bereits eingerichtet.',409);
    fwrite($file, json_encode(['password_hash'=>password_hash($pw,PASSWORD_DEFAULT),'created_at'=>gmdate('c'),'pwv'=>1]));
    fclose($file);
    @chmod($path,0600);
    start_session_for_user();
    result(['ok'=>true]);
}
if ($action==='login' && $method==='POST') {
    check_csrf();
    check_login_rate();
    $pw=(string)(post_json()['password']??'');
    if (!$installed || !password_verify($pw,(string)(read_json('install')['password_hash']??''))) fail('Passwort ist nicht korrekt.',401);
    start_session_for_user();
    result(['ok'=>true]);
}
if ($action==='logout' && $method==='POST') {
    check_csrf();
    $_SESSION=[];
    session_regenerate_id(true);
    result(['ok'=>true]);
}
// GPX-Schlüssel: Die Homescreen-App auf dem iPhone öffnet GPX in einem eigenen Browserfenster
// (mit „Fertig“-Knopf). Dieses Fenster teilt die Cookies der App nicht – deshalb trägt der Link einen
// zeitlich begrenzten Schlüssel, der NUR den GPX-Download erlaubt (12 h, je Sitzung, serverseitig gespeichert).
const GPX_TOKEN_TTL = 12 * 3600;
function gpx_token_valid(string $token): bool {
    if (!preg_match('/^[a-f0-9]{32}$/',$token)) return false;
    $tokens=read_json('gpx_tokens');
    return isset($tokens[$token]) && (int)$tokens[$token] > time();
}
function issue_gpx_token(): string {
    if (!empty($_SESSION['gpxToken']) && (int)($_SESSION['gpxTokenExp']??0) > time()+3600) return (string)$_SESSION['gpxToken'];
    $token=bin2hex(random_bytes(16)); $exp=time()+GPX_TOKEN_TTL;
    update_json('gpx_tokens',function (&$tokens) use ($token,$exp) {
        $tokens=array_filter($tokens,fn($e)=>(int)$e>time()); // abgelaufene entfernen
        $tokens[$token]=$exp; return true;
    });
    $_SESSION['gpxToken']=$token; $_SESSION['gpxTokenExp']=$exp;
    return $token;
}
$gpxByToken = $action==='gpx' && $method==='GET' && gpx_token_valid((string)($_GET['dl']??''));
if (!$gpxByToken) {
    require_login();
    if ($method==='POST') check_csrf();
}


function map_info(): array {
    return read_json('map_data', ['sources'=>[], 'assignments'=>[], 'importedAt'=>null, 'fileName'=>'']);
}
function map_valid_id(string $value): bool { return preg_match('/^[a-f0-9]{16}$/', $value)===1; }
function gpx_escape(string $s): string { return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8'); }
if ($action==='bootstrap' && $method==='GET') result(['ok'=>true,'routes'=>read_json('routes',defaults()),'entries'=>read_json('entries'),'gpxToken'=>issue_gpx_token()]);
if ($action==='route' && $method==='POST') {
    $in=post_json();
    $id=(string)($in['id']??'');
    if (!in_array($id,['01','02','03','04','05','06','07'],true)) fail('Unbekannte Tour.');
    $out=update_json('routes',function (&$routes) use ($in,$id) {
        foreach ($routes as &$route) if ($route['id']===$id) {
            $route['name']=norm((string)($in['name']??''),90);
            if (!$route['name']) fail('Name darf nicht leer sein.');
            $route['area']=norm((string)($in['area']??''),180);
            $route['note']=norm((string)($in['note']??''),1000);
            $km=$in['km']??null;
            if ($km!==null && $km!=='') {
                if (!is_numeric($km) || (float)$km<0.1 || (float)$km>999) fail('Ungültige Kilometerangabe.');
                $route['km']=round((float)$km,1);
            } else { $route['km']=null; }
            if (isset($in['links']) && is_array($in['links'])) foreach (['komoot','google','apple','mymaps'] as $app) {
                $value=trim((string)($in['links'][$app]??''));
                if (!valid_url($value)) fail('Bitte gültige HTTPS-Adresse für '. $app .' angeben.');
                $route['links'][$app]=$value;
            }
            return $route;
        }
        fail('Tour nicht gefunden.');
    },defaults());
    result(['ok'=>true,'route'=>$out]);
}
// Kompatibilität: Ältere Einträge mit tourId / issueKind / place werden weiter angezeigt.
// Neue Einträge: tourIds[] und issues[] mit eigenen Fotos und Koordinaten.
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
    return ['lat'=>(float)$raw['lat'],'lon'=>(float)$raw['lon']];
}
function valid_time(mixed $raw, bool $allowEmpty=true): ?string {
    if ($raw===null || $raw==='') { if ($allowEmpty) return null; fail('Uhrzeit fehlt.'); }
    if (!is_string($raw) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/',$raw)) fail('Uhrzeit ist ungültig (HH:MM).');
    return $raw;
}
const ISSUE_KINDS=['Belag','Beschilderung','Hindernis','Sicherheit','Verschmutzung','Bewuchs','Sonstiges'];
const ISSUE_STATES=['offen','gemeldet','bearbeitung','erledigt'];
/** Prüft eine Mangelzeile. $previous: gespeicherte Fassung (Fotos, Erledigt-Zeit bleiben erhalten). */
function clean_issue(mixed $issue, array $tourIds, ?array $previous=null): array {
    if (!is_array($issue)) fail('Ungültige Mangelzeile.');
    $issueId=(string)($issue['id']??'');
    if (!preg_match('/^i_[a-f0-9]{24}$/',$issueId)) fail('Ungültige Mangel-ID.');
    $tour=(string)($issue['tourId']??'');
    if (!in_array($tour,$tourIds,true)) fail('Mangel-Tour muss zu den geprüften Strecken gehören.');
    $kind=(string)($issue['kind']??'');
    $status=(string)($issue['status']??'offen');
    if (!in_array($kind,ISSUE_KINDS,true) || !in_array($status,ISSUE_STATES,true)) fail('Ungültige Mangelart oder Bearbeitungsstatus.');
    $wasDone=($previous['status']??'offen')==='erledigt';
    return [
        'id'=>$issueId,'tourId'=>$tour,'kind'=>$kind,
        'time'=>valid_time($issue['time']??null),
        'place'=>norm((string)($issue['place']??''),180),
        'description'=>norm((string)($issue['description']??''),3000),
        'status'=>$status,'coords'=>valid_coords($issue['coords']??null),
        'photos'=>$previous['photos']??[],
        'createdAt'=>$previous['createdAt']??gmdate('c'),
        'completedAt'=>$status==='erledigt'?($wasDone?($previous['completedAt']??gmdate('c')):gmdate('c')):null
    ];
}
function entry_status(array $issues): string { return count($issues)>0?'mangel':'gut'; }
function find_entry_index(array $entries, string $id): int {
    foreach ($entries as $i=>$e) if (($e['id']??'')===$id) return $i;
    fail('Kontrollfahrt nicht gefunden.',404);
}
function photo_list(array $entry): array {
    $all=$entry['photos']??[];
    foreach (($entry['issues']??[]) as $issue) foreach (($issue['photos']??[]) as $p) $all[]=$p;
    return $all;
}
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
    $data=['tourIds'=>$tourIds,'tourId'=>$tourIds[0],'date'=>$date,
      'startTime'=>$startTime,'endTime'=>$endTime,
      'status'=>entry_status($issues),
      'notes'=>norm((string)($in['notes']??''),5000), 'coords'=>valid_coords($in['coords']??null),
      'issues'=>$issues];
    $saved=update_json('entries',function (&$entries) use ($id,$data){
        $now=gmdate('c');
        foreach ($entries as &$entry) if ($id!=='' && ($entry['id']??'')===$id) {
            $previous=[];
            foreach (($entry['issues']??[]) as $old) $previous[$old['id']]=$old;
            // Alte Daten beim ersten Bearbeiten bewahren: legacy-Fotos bleiben als allgemeine Fotos erhalten.
            foreach ($data['issues'] as $i=>$_) {
                $key=$data['issues'][$i]['id'];
                $data['issues'][$i]['photos']=$previous[$key]['photos']??[];
                $data['issues'][$i]['createdAt']=$previous[$key]['createdAt']??$data['issues'][$i]['createdAt'];
                $wasDone=($previous[$key]['status']??'offen')==='erledigt';
                $nowDone=$data['issues'][$i]['status']==='erledigt';
                $data['issues'][$i]['completedAt']=$nowDone?($wasDone?($previous[$key]['completedAt']??gmdate('c')):gmdate('c')):null;
            }
            $remaining=[];
            foreach ($data['issues'] as $i) $remaining[$i['id']]=true;
            $discarded=[];
            foreach (($entry['issues']??[]) as $old) if (!isset($remaining[$old['id']])) foreach (($old['photos']??[]) as $photo) $discarded[]=$photo;
            if ($data['endTime']!==null) $data['running']=false;
            $entry=array_merge($entry,$data,['updatedAt'=>$now]);
            return ['entry'=>$entry,'discarded'=>$discarded];
        }
        if ($id!=='') fail('Eintrag nicht gefunden.',404);
        $entry=array_merge(['id'=>bin2hex(random_bytes(12)),'createdAt'=>$now,'photos'=>[]],$data,['updatedAt'=>$now]);
        $entries[]=$entry;
        return ['entry'=>$entry,'discarded'=>[]];
    });
    foreach ($saved['discarded'] as $photo) if (!empty($photo['file'])) delete_photo_files($photo['file'],(string)($photo['id']??''));
    result(['ok'=>true,'entry'=>$saved['entry']]);
}
// Eigenstaendige Mangel-Aktualisierung: erledigt/offen ohne die alte Kontrollfahrt zu veraendern.
// JSON-Aktualisierung ist gesperrt/atomar, daher gehen parallel erfasste Mängel nicht verloren.
if ($action==='issueStatus' && $method==='POST') {
    $in=post_json();
    $entryId=(string)($in['entryId']??'');
    $issueId=(string)($in['issueId']??'');
    $next=(string)($in['status']??'');
    if (!preg_match('/^[a-f0-9]{24}$/',$entryId) || !preg_match('/^i_[a-f0-9]{24}$/',$issueId)) fail('Ungültige Mangelkennung.');
    if (!in_array($next,['erledigt','offen'],true)) fail('Ungültiger Mangelstatus.');
    $out=update_json('entries',function (&$entries) use ($entryId,$issueId,$next) {
        foreach ($entries as &$entry) if (($entry['id']??'')===$entryId) {
            // Kompatibilitaet mit Tagebuechern vor v3: den alten Mangel bei Bedarf uebernehmen.
            if (!isset($entry['issues']) || !is_array($entry['issues'])) {
                $entry['issues']=[];
                if (($entry['status']??'')==='mangel' || ($entry['status']??'')==='hinweis' || !empty($entry['issueKind']) || !empty($entry['place'])) {
                    $kinds=['Belag','Beschilderung','Hindernis','Sicherheit','Verschmutzung','Bewuchs','Sonstiges'];
                    $entry['issues'][]=[
                        'id'=>'i_'.$entryId,'tourId'=>$entry['tourId']??'01',
                        'kind'=>in_array($entry['issueKind']??'', $kinds,true)?$entry['issueKind']:'Sonstiges',
                        'place'=>$entry['place']??'','description'=>'', 'status'=>'offen',
                        'coords'=>$entry['coords']??null,'photos'=>[]
                    ];
                }
            }
            foreach ($entry['issues'] as &$issue) if (($issue['id']??'')===$issueId) {
                $old=(string)($issue['status']??'offen');
                $issue['status']=$next;
                if ($next==='erledigt') {
                    if ($old!=='erledigt' || empty($issue['completedAt'])) $issue['completedAt']=gmdate('c');
                } else {
                    $issue['completedAt']=null;
                }
                $entry['updatedAt']=gmdate('c');
                return ['issue'=>$issue,'entryId'=>$entryId];
            }
            fail('Mangel nicht gefunden.',404);
        }
        fail('Streckenpruefung nicht gefunden.',404);
    });
    result(['ok'=>true]+$out);
}
// ── Laufende Kontrollfahrt: anlegen, Metadaten ändern/beenden, Mängel einzeln speichern ──
if ($action==='tripStart' && $method==='POST') {
    $in=post_json();
    $tourIds=valid_tour_ids($in['tourIds']??[]);
    $date=(string)($in['date']??'');
    if (!valid_date($date)) fail('Datum ist ungültig.');
    $startTime=valid_time($in['startTime']??null,false);
    $now=gmdate('c');
    $entry=['id'=>bin2hex(random_bytes(12)),'createdAt'=>$now,'updatedAt'=>$now,
        'tourIds'=>$tourIds,'tourId'=>$tourIds[0],'date'=>$date,'startTime'=>$startTime,'endTime'=>null,
        'running'=>true,'status'=>'gut','notes'=>'','coords'=>null,'issues'=>[],'photos'=>[]];
    update_json('entries',function (&$entries) use ($entry) { $entries[]=$entry; return true; });
    result(['ok'=>true,'entry'=>$entry]);
}
if ($action==='tripUpdate' && $method==='POST') {
    $in=post_json();
    $id=(string)($in['entryId']??'');
    if (!preg_match('/^[a-f0-9]{24}$/',$id)) fail('Ungültige Eintrags-ID.');
    $entry=update_json('entries',function (&$entries) use ($id,$in) {
        $i=find_entry_index($entries,$id); $e=&$entries[$i];
        if (array_key_exists('date',$in)) { if (!valid_date((string)$in['date'])) fail('Datum ist ungültig.'); $e['date']=(string)$in['date']; }
        if (array_key_exists('startTime',$in)) $e['startTime']=valid_time($in['startTime'],false);
        if (array_key_exists('endTime',$in)) $e['endTime']=valid_time($in['endTime']);
        if (array_key_exists('notes',$in)) $e['notes']=norm((string)$in['notes'],5000);
        if (array_key_exists('running',$in)) $e['running']=(bool)$in['running'];
        if (($e['running']??false) && !empty($e['endTime']) && !($in['running']??false)) $e['running']=false;
        if (array_key_exists('tourIds',$in)) {
            $ids=valid_tour_ids($in['tourIds']);
            foreach (($e['issues']??[]) as $iss) if (!in_array($iss['tourId']??'',$ids,true)) fail('Eine Tour mit erfassten Mängeln kann nicht entfernt werden.');
            $e['tourIds']=$ids; $e['tourId']=$ids[0];
        }
        $e['updatedAt']=gmdate('c');
        return $e;
    });
    result(['ok'=>true,'entry'=>$entry]);
}
if ($action==='issueSave' && $method==='POST') {
    $in=post_json();
    $id=(string)($in['entryId']??'');
    if (!preg_match('/^[a-f0-9]{24}$/',$id)) fail('Ungültige Eintrags-ID.');
    $issue=update_json('entries',function (&$entries) use ($id,$in) {
        $i=find_entry_index($entries,$id); $e=&$entries[$i];
        $issues=is_array($e['issues']??null)?$e['issues']:[];
        $issueId=(string)($in['issue']['id']??'');
        $pos=null; foreach ($issues as $k=>$old) if (($old['id']??'')===$issueId) { $pos=$k; break; }
        if ($pos===null && count($issues)>=25) fail('Maximal 25 Mängel pro Kontrollfahrt.');
        $clean=clean_issue($in['issue']??null,$e['tourIds']??[$e['tourId']??'01'],$pos===null?null:$issues[$pos]);
        if ($pos===null) $issues[]=$clean; else $issues[$pos]=$clean;
        $e['issues']=array_values($issues); $e['status']=entry_status($e['issues']); $e['updatedAt']=gmdate('c');
        return $clean;
    });
    result(['ok'=>true,'issue'=>$issue]);
}
if ($action==='issueDelete' && $method==='POST') {
    $in=post_json();
    $id=(string)($in['entryId']??''); $issueId=(string)($in['issueId']??'');
    if (!preg_match('/^[a-f0-9]{24}$/',$id) || !preg_match('/^i_[a-f0-9]{24}$/',$issueId)) fail('Ungültige Mangelkennung.');
    $photos=update_json('entries',function (&$entries) use ($id,$issueId) {
        $i=find_entry_index($entries,$id); $e=&$entries[$i];
        foreach (($e['issues']??[]) as $k=>$iss) if (($iss['id']??'')===$issueId) {
            array_splice($e['issues'],$k,1); $e['status']=entry_status($e['issues']); $e['updatedAt']=gmdate('c');
            return $iss['photos']??[];
        }
        fail('Mangel nicht gefunden.',404);
    });
    foreach ($photos as $p) if (!empty($p['file'])) delete_photo_files($p['file'],(string)($p['id']??''));
    result(['ok'=>true]);
}
if ($action==='deleteEntry' && $method==='POST') {
    $id=(string)(post_json()['id']??'');
    $photos=update_json('entries',function (&$entries) use ($id) {
        foreach ($entries as $i=>$entry) if (($entry['id']??'')===$id) {
            array_splice($entries,$i,1); return photo_list($entry);
        }
        fail('Eintrag nicht gefunden.',404);
    });
    foreach ($photos as $p) if (!empty($p['file'])) delete_photo_files($p['file'],(string)($p['id']??''));
    result(['ok'=>true]);
}
if ($action==='upload' && $method==='POST') {
    $entryId=(string)($_POST['entryId']??'');
    $issueId=(string)($_POST['issueId']??'');
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
    // Reservierung zuerst in gesperrter JSON-Liste. Foto-Upload ist pro Einzelmangel begrenzt.
    if ($mime==='image/jpeg') { strip_jpeg_metadata($f['tmp_name']); clearstatcache(true,$f['tmp_name']); }
    $photo=['id'=>$photoId,'name'=>norm((string)$f['name'],120),'file'=>$filename,'mime'=>$mime,'size'=>(int)filesize($f['tmp_name'])];
    update_json('entries',function (&$entries) use ($entryId,$issueId,$photo) {
        foreach ($entries as &$entry) if (($entry['id']??'')===$entryId) {
            if (count(photo_list($entry))>=40) fail('Maximal 40 Fotos je Kontrollfahrt.');
            if ($issueId==='') {
                if (count($entry['photos']??[])>=8) fail('Maximal 8 allgemeine Fotos.');
                $entry['photos'][]=$photo; return true;
            }
            foreach ($entry['issues'] as &$issue) if ($issue['id']===$issueId) {
                if (count($issue['photos']??[])>=5) fail('Maximal 5 Fotos je Mangel.');
                $issue['photos'][]=$photo;return true;
            }
            fail('Mangel nicht gefunden.',404);
        }
        fail('Eintrag nicht gefunden.',404);
    });
    if (!move_uploaded_file($f['tmp_name'],$path.'/'.$filename)) {
        // bei einem I/O-Fehler die reservierte Metadatenzeile wieder entfernen
        update_json('entries',function (&$entries) use ($photoId) {
            foreach ($entries as &$entry) {
                $entry['photos']=array_values(array_filter($entry['photos']??[],fn($p)=>($p['id']??'')!==$photoId));
                foreach (($entry['issues']??[]) as &$issue) $issue['photos']=array_values(array_filter($issue['photos']??[],fn($p)=>($p['id']??'')!==$photoId));
            }
        });
        fail('Speichern des Fotos fehlgeschlagen.',500);
    }
    @chmod($path.'/'.$filename,0600);
    result(['ok'=>true,'photo'=>$photo]);
}
if ($action==='deletePhoto' && $method==='POST') {
    $id=(string)(post_json()['id']??'');
    $file=update_json('entries',function (&$entries) use ($id) {
        foreach ($entries as &$entry) {
            foreach (($entry['photos']??[]) as $idx=>$photo) if (($photo['id']??'')===$id) {
                array_splice($entry['photos'],$idx,1); return $photo;
            }
            if (isset($entry['issues']) && is_array($entry['issues'])) foreach ($entry['issues'] as &$issue) foreach (($issue['photos']??[]) as $idx=>$photo) if (($photo['id']??'')===$id) {
                array_splice($issue['photos'],$idx,1);return $photo;
            }
        }
        fail('Foto nicht gefunden.',404);
    });
    delete_photo_files((string)($file['file']??''),(string)($file['id']??''));
    result(['ok'=>true]);
}
if ($action==='photo' && $method==='GET') {
    $pid=(string)($_GET['id']??'');
    if (!preg_match('/^[0-9a-f]{24}$/',$pid)) fail('Foto nicht gefunden.',404);
    $metadata=photo_metadata($pid);
    if (!$metadata) fail('Foto nicht gefunden.',404);
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
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="radwegpate-tagebuch-'.date('Y-m-d').'.json"');
    echo json_encode(['exportedAt'=>gmdate('c'),'routes'=>read_json('routes',defaults()),'entries'=>read_json('entries'),'mapData'=>map_info()],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;
}
if ($action==='password' && $method==='POST') {
    $in=post_json();
    $old=(string)($in['old']??'');$new=(string)($in['new']??'');
    if (!password_verify($old,(string)(read_json('install')['password_hash']??''))) fail('Das bisherige Passwort ist nicht korrekt.');
    if (strlen($new)<12 || strlen($new)>256) fail('Neues Passwort: 12 bis 256 Zeichen.');
    update_json('install',function (&$install) use ($new) {
        $install['password_hash']=password_hash($new,PASSWORD_DEFAULT);
        $install['passwordChangedAt']=gmdate('c');
        $install['pwv']=(int)($install['pwv']??0)+1; return true;
    });
    start_session_for_user(); // diese Sitzung bleibt, alle anderen sind abgemeldet
    result(['ok'=>true]);
}

// KML wird im Browser geparst. Linien, explizite Wendepunkte und Ebenenzuordnung werden als JSON gespeichert.
// Keine KML/XML-Verarbeitung auf dem Server: keine PHP-DOM-Erweiterung erforderlich.
if ($action==='mapData' && $method==='GET') {
    result(['ok'=>true,'map'=>map_info()]);
}
if ($action==='mapImport' && $method==='POST') {
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
    $old=map_info();$assign=[];
    foreach ($clean as $src){
        $id=$src['id'];
        $auto=(string)($in['assignments'][$id]??'');
        $prior=(string)($old['assignments'][$id]??'');
        $assign[$id]=in_array($auto,['01','02','03','04','05','06','07'],true)?$auto:(in_array($prior,['01','02','03','04','05','06','07'],true)?$prior:'');
    }
    $imported=['sources'=>$clean,'assignments'=>$assign,'importedAt'=>gmdate('c'),'fileName'=>norm((string)($in['fileName']??'KML-Import'),180)];
    update_json('map_data',function (&$data) use ($imported) {$data=$imported;return true;},[]);
    result(['ok'=>true,'sources'=>count($clean),'lines'=>$totalLines,'points'=>$totalPoints]);
}
if ($action==='mapAssign' && $method==='POST') {
    $in=post_json();$map=update_json('map_data',function (&$data) use ($in) {
        if(!is_array($data['sources']??null)) fail('Zuerst KML importieren.');
        $available=array_column($data['sources'],'id');$next=[];
        foreach($available as $id){
            $value=(string)($in['assignments'][$id]??'');
            if(!in_array($value,['','01','02','03','04','05','06','07'],true)) fail('Tourenzuordnung ungültig.');
            $next[$id]=$value;
        }
        $data['assignments']=$next;return true;
    },[]);
    result(['ok'=>true]);
}
if ($action==='gpx' && $method==='GET') {
    // Einzeltrack wie bisher; mehrere IDs = kombinierter GPS-Track, ohne Wegerfindung.
    $rawCombo=(string)($_GET['ids']??'');
    $combo=($rawCombo!=='');
    $ids=$combo?explode(',', $rawCombo):[(string)($_GET['id']??'')];
    $valid=['01','02','03','04','05','06','07'];
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
    $routes=read_json('routes',defaults());$names=[];
    foreach($ids as $id){
        $name='Tour '.$id;
        foreach($routes as $route) if($route['id']===$id){$name=$route['name'];break;}
        $names[]=$name;
    }
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
    header('Content-Disposition: attachment; filename="'.$filename.'"');
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
