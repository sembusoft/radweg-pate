// Rauchtest gegen einen laufenden Server (lokal: php -S, CI: test.yml).
// Aufruf: BASE_URL=http://127.0.0.1:8080 INSTALL_TOKEN=... DATA_DIR=... node scripts/smoke-test.mjs
// Spielt den kompletten Ablauf einmal durch: Einrichtung, Touren, KML-Import,
// Kontrollfahrt mit Mangel und Foto, Erledigt-Status, GPX, Passwortwechsel, Löschen.
import { existsSync, readdirSync } from 'node:fs';

// Jede Antwort sofort vollständig lesen. Ungelesene Antworten auf einer Keep-Alive-Verbindung
// zum PHP-Testserver lassen Node 22 (undici) mit "assert(!this.paused)" abstürzen, sobald eine
// Antwort größer als der Puffer ist (seit app.js > 64 KB, CI-Lauf #10).
const rawFetch = globalThis.fetch;
globalThis.fetch = async (...args) => {
  const r = await rawFetch(...args);
  const body = await r.arrayBuffer();
  return new Response(r.status === 204 || r.status === 304 ? null : body, { status: r.status, statusText: r.statusText, headers: r.headers });
};

import http from 'node:http';
const BASE = (process.env.BASE_URL || 'http://127.0.0.1:8080').replace(/\/$/, '');
const TOKEN = process.env.INSTALL_TOKEN || '';
const DATA_DIR = process.env.DATA_DIR || '';
const PASSWORD = 'Rauchtest-Passwort-1';
const PASSWORD2 = 'Rauchtest-Passwort-2';

// Kleines JPEG (GD), davor ein künstlicher EXIF-Block mit Kennwort – der Server muss ihn entfernen.
const JPEG = Buffer.from('/9j/4AAQSkZJRgABAQEAYABgAAD//gA7Q1JFQVRPUjogZ2QtanBlZyB2MS4wICh1c2luZyBJSkcgSlBFRyB2ODApLCBxdWFsaXR5ID0gODAK/9sAQwAGBAUGBQQGBgUGBwcGCAoQCgoJCQoUDg8MEBcUGBgXFBYWGh0lHxobIxwWFiAsICMmJykqKRkfLTAtKDAlKCko/9sAQwEHBwcKCAoTCgoTKBoWGigoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgo/8AAEQgAHgAoAwEiAAIRAQMRAf/EAB8AAAEFAQEBAQEBAAAAAAAAAAABAgMEBQYHCAkKC//EALUQAAIBAwMCBAMFBQQEAAABfQECAwAEEQUSITFBBhNRYQcicRQygZGhCCNCscEVUtHwJDNicoIJChYXGBkaJSYnKCkqNDU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6g4SFhoeIiYqSk5SVlpeYmZqio6Slpqeoqaqys7S1tre4ubrCw8TFxsfIycrS09TV1tfY2drh4uPk5ebn6Onq8fLz9PX29/j5+v/EAB8BAAMBAQEBAQEBAQEAAAAAAAABAgMEBQYHCAkKC//EALURAAIBAgQEAwQHBQQEAAECdwABAgMRBAUhMQYSQVEHYXETIjKBCBRCkaGxwQkjM1LwFWJy0QoWJDThJfEXGBkaJicoKSo1Njc4OTpDREVGR0hJSlNUVVZXWFlaY2RlZmdoaWpzdHV2d3h5eoKDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uLj5OXm5+jp6vLz9PX29/j5+v/aAAwDAQACEQMRAD8A83ooooPlAooooAKKKKACiiigAooooAKKKKAP/9k=', 'base64');
function withExif(jpeg) {
  const payload = Buffer.concat([Buffer.from('Exif\0\0'), Buffer.from('GPS-GEHEIM-48.323,10.042')]);
  const len = payload.length + 2;
  const app1 = Buffer.concat([Buffer.from([0xff, 0xe1, len >> 8, len & 0xff]), payload]);
  return Buffer.concat([jpeg.subarray(0, 2), app1, jpeg.subarray(2)]);
}

let failures = 0, passed = 0;
function check(cond, label, detail = '') {
  if (cond) { passed++; console.log('  ok   ' + label); }
  else { failures++; console.log('  FEHL ' + label + (detail ? ' — ' + detail : '')); }
}

class Client {
  constructor() { this.cookies = new Map(); this.csrf = ''; }
  async req(path, { method = 'GET', json, form } = {}) {
    const headers = { 'X-CSRF-Token': this.csrf };
    if (this.cookies.size) headers.Cookie = [...this.cookies].map(([k, v]) => k + '=' + v).join('; ');
    let body;
    if (json !== undefined) { headers['Content-Type'] = 'application/json'; body = JSON.stringify(json); }
    if (form) body = form;
    const res = await fetch(BASE + '/' + path, { method, headers, body, redirect: 'manual' });
    for (const c of res.headers.getSetCookie?.() || []) {
      const [pair] = c.split(';'); const i = pair.indexOf('=');
      this.cookies.set(pair.slice(0, i), pair.slice(i + 1));
    }
    return res;
  }
  async api(action, opts = {}) {
    const res = await this.req('api.php?action=' + action + (opts.query || ''), opts);
    const ct = res.headers.get('content-type') || '';
    const data = ct.includes('json') ? await res.json() : null;
    return { res, data };
  }
  async status() { const { data } = await this.api('status'); this.csrf = data.csrf; return data; }
}

const hex = n => [...crypto.getRandomValues(new Uint8Array(n))].map(b => b.toString(16).padStart(2, '0')).join('');

async function main() {
  console.log('Rauchtest gegen ' + BASE);

  // Shell
  const page = await fetch(BASE + '/');
  const html = await page.text();
  const csp = page.headers.get('content-security-policy') || '';
  check(page.status === 200, 'Startseite 200');
  check(!/unpkg|cdn\./.test(csp + html), 'keine externen Skript-/Stilquellen (CSP + HTML)');
  check(/script-src 'self';/.test(csp) && /style-src 'self';/.test(csp), 'CSP: script-src und style-src nur self');
  const ver = (html.match(/app\.js\?v=([^"]+)"/) || [])[1];
  const sw = await (await fetch(BASE + '/sw.js')).text();
  const cache = (sw.match(/CACHE_NAME\s*=\s*'radwegpate-([^']+)'/) || [])[1];
  check(ver && ver === cache, 'Version index.php = sw.js', ver + ' / ' + cache);
  for (const f of ['assets/vendor/leaflet-1.9.4/leaflet.js', 'assets/vendor/leaflet-1.9.4/leaflet.css', 'assets/app.js', 'assets/style.css', 'manifest.webmanifest']) {
    const r = await fetch(BASE + '/' + f); check(r.status === 200, 'erreichbar: ' + f);
  }

  // Einrichtung
  const a = new Client();
  let s = await a.status();
  check(s.ok && s.installed === false && s.loggedIn === false, 'Status: nicht eingerichtet');
  check(typeof s.version === 'string', 'Status liefert Version', s.version);
  let r = await a.api('bootstrap');
  check(r.res.status === 401, 'bootstrap ohne Login → 401');
  r = await a.api('setup', { method: 'POST', json: { code: 'falsch', password: PASSWORD } });
  check(r.res.status === 403, 'Einrichtung mit falschem Code → 403', String(r.res.status));
  r = await a.api('setup', { method: 'POST', json: { code: TOKEN, password: PASSWORD } });
  check(r.data?.ok === true, 'Einrichtung mit Code', r.data?.error);
  s = await a.status();
  check(s.installed && s.loggedIn, 'nach Einrichtung angemeldet');
  r = await a.api('setup', { method: 'POST', json: { code: TOKEN, password: PASSWORD } });
  check(r.res.status === 409, 'zweite Einrichtung → 409');

  r = await a.api('bootstrap');
  check(r.data?.routes?.length === 7, '7 Touren vorhanden');
  check(r.data?.routes?.[4]?.name === 'Süd / Richtung Illerzell', 'Tourname 05 wie KML', r.data?.routes?.[4]?.name);

  // CSRF
  const saveCsrf = a.csrf; a.csrf = 'falsch';
  r = await a.api('route', { method: 'POST', json: { id: '01', name: 'x' } });
  check(r.res.status === 403, 'POST ohne gültiges CSRF-Token → 403');
  a.csrf = saveCsrf;

  // KML-Import (Linie Hin + Rück, Wendepunkt auf der Linie)
  const line = [[10.040, 48.320], [10.030, 48.322], [10.020, 48.325], [10.030, 48.322], [10.040, 48.320]];
  const srcId = hex(8);
  r = await a.api('mapImport', { method: 'POST', json: { fileName: 'test.kml', sources: [{ id: srcId, name: '01 West', lines: [line], turnpoints: [{ name: 'Wendepunkt', point: [10.020, 48.325] }] }], assignments: { [srcId]: '01' } } });
  check(r.data?.ok && r.data.points === 5, 'KML-Import', r.data?.error);
  r = await a.api('mapImport', { method: 'POST', json: { sources: [{ id: hex(8), name: 'x', lines: [line], turnpoints: [{ name: 'W', point: [11, 49] }] }] } });
  check(r.res.status === 400, 'Wendepunkt neben der Linie wird abgelehnt');
  const gpx = await a.req('api.php?action=gpx&id=01');
  const gpxText = await gpx.text();
  check(gpx.status === 200 && (gpxText.match(/<trkpt /g) || []).length === 5, 'GPX für Tour 01 mit 5 Punkten');
  const boot = await a.api('bootstrap');
  check(/^[a-f0-9]{32}$/.test(boot.data?.gpxToken || ''), 'bootstrap liefert GPX-Schlüssel');
  let anon = await fetch(BASE + '/api.php?action=gpx&id=01&dl=' + boot.data.gpxToken);
  check(anon.status === 200 && (anon.headers.get('content-type') || '').includes('gpx'), 'GPX mit Schlüssel ohne Anmeldung (eigenes Browserfenster)');
  check((anon.headers.get('content-disposition') || '').startsWith('inline'), 'GPX über Schlüssel-Link wird inline ausgeliefert');
  anon = await fetch(BASE + '/api.php?action=gpx&id=01&save=1&dl=' + boot.data.gpxToken);
  check((anon.headers.get('content-disposition') || '').startsWith('attachment'), 'GPX über Schlüssel-Link mit save=1 als Datei');
  anon = await fetch(BASE + '/gpx.php?id=01&dl=' + boot.data.gpxToken);
  const dlHtml = await anon.text();
  check(anon.status === 200 && dlHtml.includes('action=gpx&amp;id=01&amp;dl=' + boot.data.gpxToken + '&amp;save=1'), 'Download-Seite gpx.php mit Link');
  anon = await fetch(BASE + '/gpx.php?id=99&dl=<x>');
  const badHtml = await anon.text();
  check(badHtml.includes('Link ungültig') && !badHtml.includes('<x>'), 'Download-Seite lehnt ungültige Parameter ab');
  const viaDl = path => new Promise((ok, no) => { const u = new URL(BASE + path);
    http.get({ hostname: u.hostname, port: u.port, path: u.pathname + u.search, headers: { Host: 'dl.example.test' } }, res => { res.resume(); ok(res.statusCode); }).on('error', no); });
  check(await viaDl('/api.php?action=gpx&id=01&dl=' + boot.data.gpxToken) === 200, 'dl.-Host: GPX mit Schlüssel');
  check(await viaDl('/api.php?action=login') === 404, 'dl.-Host: andere API-Aktionen gesperrt');
  check(await viaDl('/api.php?action=gpx&id=01') === 401, 'dl.-Host: GPX ohne Schlüssel → 401');
  anon = await fetch(BASE + '/api.php?action=gpx&id=01&dl=' + 'f'.repeat(32));
  check(anon.status === 401, 'GPX mit falschem Schlüssel → 401');
  anon = await fetch(BASE + '/api.php?action=bootstrap&dl=' + boot.data.gpxToken);
  check(anon.status === 401, 'Schlüssel öffnet nichts außer GPX');

  // Kontrollfahrt mit Mangel
  const issueId = 'i_' + hex(12);
  r = await a.api('entry', { method: 'POST', json: { tourIds: ['01', '03'], date: '2026-10-14', startTime: '09:05', endTime: '10:15', notes: 'Rauchtest', coords: null,
    issues: [{ id: issueId, tourId: '01', kind: 'Belag', status: 'offen', place: 'Brücke', description: 'Schlagloch', coords: { lat: 48.32, lon: 10.03 } }] } });
  check(r.data?.ok && r.data.entry?.issues?.length === 1, 'Kontrollfahrt mit Mangel gespeichert', r.data?.error);
  const entryId = r.data?.entry?.id;

  // Foto mit EXIF hochladen
  const fd = new FormData();
  fd.set('entryId', entryId); fd.set('issueId', issueId);
  fd.set('file', new Blob([withExif(JPEG)], { type: 'image/jpeg' }), 'kamera.jpg');
  r = await a.api('upload', { method: 'POST', form: fd });
  check(r.data?.ok, 'Foto-Upload', r.data?.error);
  const photoId = r.data?.photo?.id;
  let ph = await a.req('api.php?action=photo&id=' + photoId);
  const bytes = Buffer.from(await ph.arrayBuffer());
  check(ph.status === 200 && bytes[0] === 0xff && bytes[1] === 0xd8, 'Foto abrufbar (JPEG)');
  check(!bytes.includes(Buffer.from('GPS-GEHEIM')), 'EXIF-Block wurde entfernt');
  check((ph.headers.get('cache-control') || '').includes('immutable'), 'Foto mit Cache-Header', ph.headers.get('cache-control'));
  ph = await a.req('api.php?action=photo&thumb=1&id=' + photoId);
  check(ph.status === 200 && ph.headers.get('content-type') === 'image/jpeg', 'Vorschaubild abrufbar');

  // Mangel erledigt / wieder offen
  r = await a.api('issueStatus', { method: 'POST', json: { entryId, issueId, status: 'erledigt' } });
  check(r.data?.ok && r.data.issue?.completedAt, 'Mangel erledigt mit Zeitstempel');
  r = await a.api('issueStatus', { method: 'POST', json: { entryId, issueId, status: 'offen' } });
  check(r.data?.ok && r.data.issue?.completedAt === null, 'Mangel wieder geöffnet');

  // Laufende Kontrollfahrt: anlegen, Mängel einzeln speichern, beenden
  r = await a.api('tripStart', { method: 'POST', json: { tourIds: ['02', '05'], date: '2026-10-14', startTime: '14:32' } });
  check(r.data?.ok && r.data.entry?.running === true && r.data.entry.startTime === '14:32', 'Fahrt gestartet (läuft, Startzeit)', r.data?.error);
  const tripId = r.data?.entry?.id;
  r = await a.api('tripStart', { method: 'POST', json: { tourIds: ['02'], date: '2026-10-14', startTime: '25:00' } });
  check(r.res.status === 400, 'ungültige Startzeit abgelehnt');
  const t1 = 'i_' + hex(12), t2 = 'i_' + hex(12);
  r = await a.api('issueSave', { method: 'POST', json: { entryId: tripId, issue: { id: t1, tourId: '05', kind: 'Bewuchs', status: 'offen', time: '14:40', place: 'Feldweg', description: 'Hecke ragt in den Weg', coords: { lat: 48.31, lon: 10.04 } } } });
  check(r.data?.ok && r.data.issue?.time === '14:40', 'Mangel 1 einzeln gespeichert (mit Uhrzeit)', r.data?.error);
  r = await a.api('issueSave', { method: 'POST', json: { entryId: tripId, issue: { id: t2, tourId: '02', kind: 'Belag', status: 'offen', time: '14:55', place: 'Brücke', description: '' } } });
  check(r.data?.ok, 'Mangel 2 einzeln gespeichert');
  r = await a.api('issueSave', { method: 'POST', json: { entryId: tripId, issue: { id: 'i_' + hex(12), tourId: '07', kind: 'Belag', status: 'offen' } } });
  check(r.res.status === 400, 'Mangel auf nicht gewählter Tour abgelehnt');
  const fd2 = new FormData(); fd2.set('entryId', tripId); fd2.set('issueId', t1);
  fd2.set('file', new Blob([withExif(JPEG)], { type: 'image/jpeg' }), 'hecke.jpg');
  r = await a.api('upload', { method: 'POST', form: fd2 });
  check(r.data?.ok, 'Foto zu Mangel 1 hochgeladen', r.data?.error);
  r = await a.api('issueSave', { method: 'POST', json: { entryId: tripId, issue: { id: t1, tourId: '05', kind: 'Bewuchs', status: 'gemeldet', time: '14:41', place: 'Feldweg Nord', description: 'Hecke ragt in den Weg' } } });
  check(r.data?.issue?.photos?.length === 1 && r.data.issue.status === 'gemeldet', 'Mangel 1 geändert, Foto bleibt erhalten');
  r = await a.api('issueDelete', { method: 'POST', json: { entryId: tripId, issueId: t2 } });
  check(r.data?.ok, 'Mangel 2 gelöscht');
  r = await a.api('tripUpdate', { method: 'POST', json: { entryId: tripId, endTime: '15:20', notes: 'Fahrt beendet' } });
  check(r.data?.ok && r.data.entry.running === false && r.data.entry.endTime === '15:20' && r.data.entry.issues.length === 1, 'Fahrt beendet (Endzeit, läuft nicht mehr)', r.data?.error);
  r = await a.api('bootstrap');
  const trip = r.data?.entries?.find(e => e.id === tripId);
  check(trip?.status === 'mangel' && trip.issues[0].place === 'Feldweg Nord', 'Fahrt im Tagebuch mit Mangel');
  r = await a.api('deleteEntry', { method: 'POST', json: { id: tripId } });
  check(r.data?.ok, 'Testfahrt wieder gelöscht');

  // Export
  const exp = await a.req('api.php?action=export');
  const expJson = await exp.json();
  check(expJson.entries?.length === 1 && expJson.mapData?.sources?.length === 1, 'JSON-Sicherung vollständig');

  // Passwortwechsel meldet andere Sitzungen ab
  const b = new Client(); await b.status();
  r = await b.api('login', { method: 'POST', json: { password: 'falsch-falsch-falsch' } });
  check(r.res.status === 401, 'Login mit falschem Passwort → 401');
  r = await b.api('login', { method: 'POST', json: { password: PASSWORD } });
  check(r.data?.ok, 'zweite Sitzung angemeldet', r.data?.error);
  r = await a.api('password', { method: 'POST', json: { old: PASSWORD, new: PASSWORD2 } });
  check(r.data?.ok, 'Passwort geändert', r.data?.error);
  r = await b.api('bootstrap');
  check(r.res.status === 401, 'andere Sitzung nach Passwortwechsel abgemeldet', String(r.res.status));
  r = await a.api('bootstrap');
  check(r.res.status === 200, 'eigene Sitzung bleibt angemeldet', String(r.res.status));

  // Löschen räumt Fotos und Vorschaubilder ab
  r = await a.api('deleteEntry', { method: 'POST', json: { id: entryId } });
  check(r.data?.ok, 'Kontrollfahrt gelöscht');
  ph = await a.req('api.php?action=photo&id=' + photoId);
  check(ph.status === 404, 'Foto danach weg');
  if (DATA_DIR) {
    check(existsSync(DATA_DIR + '/.htaccess'), 'Datenordner hat Schutz-.htaccess');
    const left = existsSync(DATA_DIR + '/photos') ? readdirSync(DATA_DIR + '/photos').filter(f => f !== 'thumbs') : [];
    const thumbs = existsSync(DATA_DIR + '/photos/thumbs') ? readdirSync(DATA_DIR + '/photos/thumbs') : [];
    check(left.length === 0 && thumbs.length === 0, 'keine Fotodateien übrig', left.concat(thumbs).join(','));
  }

  // Nur hinter Apache (lokale Docker-Umgebung, CHECK_HTACCESS=1): Sperren der .htaccess.
  // php -S in der CI wertet keine .htaccess aus – dort entfällt dieser Teil.
  if (process.env.CHECK_HTACCESS === '1') {
    for (const p of ['radwegpate_private/install.json', 'radwegpate_private/entries.json', 'radwegpate_private/', 'radwegpate_private/backups/', 'config.php', '.env', '.env.example', 'version.php', 'db/db.php', 'db/migrate.php', 'db/migrations/001_grundschema.sql', 'db/README.md']) {
      const res = await fetch(BASE + '/' + p, { redirect: 'manual' });
      check(res.status === 403 || res.status === 404, `.htaccess sperrt /${p}`, 'HTTP ' + res.status);
    }
    const swRes = await fetch(BASE + '/sw.js');
    check((swRes.headers.get('cache-control') || '').includes('no-cache'), 'sw.js mit Cache-Control no-cache', swRes.headers.get('cache-control'));
  }

  r = await a.api('logout', { method: 'POST' });
  check(r.data?.ok, 'Abmelden');

  console.log(`\n${passed} ok, ${failures} fehlgeschlagen`);
  process.exit(failures ? 1 : 0);
}
main().catch(e => { console.error(e); process.exit(1); });
