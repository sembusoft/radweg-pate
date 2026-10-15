// Statische Prüfungen ohne Server (lokal und in der CI): node scripts/check.mjs
// Bricht ab, wenn Versionsnummern auseinanderlaufen, die Service-Worker-Shell auf
// fehlende Dateien zeigt oder Code gegen die Content-Security-Policy verstößt.
import { readFileSync, existsSync, readdirSync } from 'node:fs';
import { execFileSync } from 'node:child_process';

const root = new URL('../public/', import.meta.url).pathname;
const read = f => readFileSync(root + f, 'utf8');
let errors = 0;
const fail = msg => { errors++; console.log('FEHL ' + msg); };
const ok = msg => console.log('ok   ' + msg);

// 1. Eine Versionsnummer
const version = (read('version.php').match(/APP_VERSION\s*=\s*'([^']+)'/) || [])[1];
const sw = read('sw.js');
const cache = (sw.match(/CACHE_NAME\s*=\s*'radwegpate-([^']+)'/) || [])[1];
if (!version) fail('APP_VERSION in version.php nicht gefunden');
else if (version !== cache) fail(`Version: version.php ${version} ≠ sw.js ${cache} – CACHE_NAME in sw.js anpassen`);
else ok('Version ' + version + ' in version.php und sw.js');

// 2. Service-Worker-Shell zeigt nur auf vorhandene Dateien
const shell = [...(sw.match(/const SHELL = \[([\s\S]*?)\];/) || [, ''])[1].matchAll(/'\.\/([^'?]*)/g)].map(m => m[1]);
const missing = shell.filter(f => f && !existsSync(root + f));
missing.length ? fail('sw.js SHELL verweist auf fehlende Dateien: ' + missing.join(', ')) : ok(`sw.js SHELL: ${shell.length} Einträge vorhanden`);

// 3. Keine externen Skripte/Stile, keine Inline-Styles in HTML-Vorlagen (CSP style-src 'self')
const index = read('index.php');
/<script[^>]+src="https?:/.test(index) || /<link[^>]+stylesheet[^>]+href="https?:/.test(index)
  ? fail('index.php lädt Skripte oder Stile von externen Servern') : ok('index.php: nur lokale Skripte/Stile');
for (const f of ['index.php', 'gpx.php', 'assets/app.js']) {
  const src = read(f);
  /\sstyle="/.test(src) ? fail(`${f}: style="…"-Attribut – wird von der CSP blockiert, CSS-Klasse verwenden`) : ok(`${f}: keine Inline-Styles`);
}
/\son(click|change|submit|load)=/.test(index) ? fail('index.php: Inline-Eventhandler – von der CSP blockiert') : ok('index.php: keine Inline-Eventhandler');

// 3b. Datenordner im Webroot muss gesperrt sein
/RedirectMatch 404 "[^"]*radwegpate_private/.test(read('.htaccess'))
  ? ok('.htaccess sperrt radwegpate_private') : fail('public/.htaccess sperrt den Datenordner radwegpate_private nicht');
(read('config.php').match(/APP_DATA_DIR',\s*__DIR__ \. '\/([^']+)'/) || [])[1] === 'radwegpate_private'
  ? ok('config.php: Datenordner radwegpate_private (fest)') : fail('config.php: APP_DATA_DIR passt nicht zur Sperre in .htaccess');
/FilesMatch "[^"]*\\\.\.\*/.test(read('.htaccess')) ? ok('.htaccess sperrt Punktdateien (.env)') : fail('.htaccess sperrt Punktdateien (.env) nicht');

// 4. Asset-Verweise in index.php existieren
for (const m of index.matchAll(/(?:src|href)="(assets\/[^"?#]+)/g)) {
  existsSync(root + m[1]) ? null : fail('index.php verweist auf fehlende Datei ' + m[1]);
}
ok('index.php: Asset-Verweise geprüft');

// 5. JavaScript-Syntax
for (const f of ['assets/app.js', 'sw.js']) {
  try { execFileSync(process.execPath, ['--check', root + f], { stdio: 'pipe' }); ok('Syntax ' + f); }
  catch (e) { fail('Syntax ' + f + ': ' + e.stderr); }
}

// 5b. Jedes benutzte Icon steckt im Sprite (sonst bleibt die Stelle leer – ohne Fehlermeldung)
const sprite = read('assets/icons.svg');
const have = new Set([...sprite.matchAll(/<symbol id="([^"]+)"/g)].map(m => m[1]));
const usedIcons = new Set();
for (const m of index.matchAll(/icons\.svg#([\w-]+)/g)) usedIcons.add(m[1]);
for (const m of read('assets/app.js').matchAll(/ic\(([^)]*)\)/g)) for (const n of m[1].matchAll(/(?<![=!]==?\s*)'([\w-]+)'/g)) usedIcons.add(n[1]);
const missingIcons = [...usedIcons].filter(n => !have.has(n));
missingIcons.length ? fail('Icons fehlen im Sprite (scripts/build-icons.mjs ergänzen): ' + missingIcons.join(', '))
  : ok(`Icons: ${usedIcons.size} benutzt, alle im Sprite (${have.size})`);

// 5c. Kontrast der Farb-Tokens in allen vier Themes
try { console.log(execFileSync(process.execPath, [new URL('./contrast.mjs', import.meta.url).pathname], { encoding: 'utf8' }).trim()); }
catch (e) { fail('Kontrast:\n' + e.stdout); }

// 5d. Datenbank: Migrationen nummeriert, nur radpate_-Tabellen, db/ von außen gesperrt
const migs = readdirSync(root + 'db/migrations').filter(f => f.endsWith('.sql')).sort();
const badName = migs.filter(f => !/^\d{3}_[a-z0-9_]+\.sql$/.test(f));
badName.length ? fail('Migrationsnamen: NNN_name.sql erwartet: ' + badName.join(', ')) : ok(`Migrationen: ${migs.length} Dateien`);
const nums = migs.map(f => f.slice(0, 3)); new Set(nums).size !== nums.length && fail('Migrationsnummer doppelt vergeben');
for (const f of migs) {
  const sql = read('db/migrations/' + f).replace(/^\s*--.*$/gm, '');
  const tables = [...sql.matchAll(/(?:CREATE TABLE(?: IF NOT EXISTS)?|ALTER TABLE|INSERT(?: IGNORE)? INTO|REFERENCES|DROP TABLE(?: IF EXISTS)?)\s+`?([\w]+)`?/gi)].map(m => m[1]);
  const foreign = tables.filter(t => !t.startsWith('radpate_'));
  foreign.length ? fail(`${f}: Tabellen ohne Präfix radpate_: ${[...new Set(foreign)].join(', ')}`) : ok(`${f}: nur radpate_-Tabellen`);
  /^\s*DELIMITER/mi.test(sql) && fail(`${f}: DELIMITER ist nicht erlaubt`);
}
/RedirectMatch 404 "\(\?i\)\^\/\(\.\*\/\)\?db\(\/\|\$\)"/.test(read('.htaccess')) ? ok('.htaccess sperrt db/') : fail('.htaccess sperrt db/ nicht');
/read_json|update_json|write_json/.test(read('api.php')) ? fail('api.php nutzt noch JSON-Dateien statt der Datenbank') : ok('api.php: Daten nur über die Datenbank');

// 6. Keine Secrets im Repository-Stand
if (existsSync(root + '.env')) console.log('hinweis public/.env liegt lokal vor (gitignored, wird nicht deployt)');
// config.php steht im Repo: Werte nur über rp_env(), keine Literale für Zugangsdaten.
const cfg = read('config.php');
/define\('APP_(INSTALL_TOKEN|DB_NAME|DB_USER|DB_PASS)',\s*'/.test(cfg) ? fail('config.php enthält Zugangsdaten als Text – gehören in die .env') : ok('config.php ohne Zugangsdaten (liest .env)');
const example = read('.env.example');
const filled = [...example.matchAll(/^(INSTALL_TOKEN|DB_NAME|DB_USER|DB_PASSWORD)=(.+)$/gm)].map(m => m[1]);
filled.length ? fail('.env.example enthält Werte für ' + filled.join(', ') + ' – Vorlage muss leer bleiben') : ok('.env.example ohne Zugangsdaten');
const deployIgnore = readFileSync(new URL('../.deployignore', import.meta.url), 'utf8');
/^\/\.env$/m.test(deployIgnore) ? ok('.deployignore schützt /.env') : fail('.deployignore: /.env fehlt (Deploy könnte die .env löschen)');

console.log(errors ? `\n${errors} Fehler` : '\nAlles in Ordnung.');
process.exit(errors ? 1 : 0);
