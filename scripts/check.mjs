// Statische Prüfungen ohne Server (lokal und in der CI): node scripts/check.mjs
// Bricht ab, wenn Versionsnummern auseinanderlaufen, die Service-Worker-Shell auf
// fehlende Dateien zeigt oder Code gegen die Content-Security-Policy verstößt.
import { readFileSync, existsSync } from 'node:fs';
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
const shell = [...(sw.match(/const SHELL = \[([\s\S]*?)\]/) || [, ''])[1].matchAll(/'\.\/([^']*)'/g)].map(m => m[1]);
const missing = shell.filter(f => f && !existsSync(root + f));
missing.length ? fail('sw.js SHELL verweist auf fehlende Dateien: ' + missing.join(', ')) : ok(`sw.js SHELL: ${shell.length} Einträge vorhanden`);

// 3. Keine externen Skripte/Stile, keine Inline-Styles in HTML-Vorlagen (CSP style-src 'self')
const index = read('index.php');
/<script[^>]+src="https?:/.test(index) || /<link[^>]+stylesheet[^>]+href="https?:/.test(index)
  ? fail('index.php lädt Skripte oder Stile von externen Servern') : ok('index.php: nur lokale Skripte/Stile');
for (const f of ['index.php', 'assets/app.js']) {
  const src = read(f);
  /\sstyle="/.test(src) ? fail(`${f}: style="…"-Attribut – wird von der CSP blockiert, CSS-Klasse verwenden`) : ok(`${f}: keine Inline-Styles`);
}
/\son(click|change|submit|load)=/.test(index) ? fail('index.php: Inline-Eventhandler – von der CSP blockiert') : ok('index.php: keine Inline-Eventhandler');

// 3b. Datenordner im Webroot muss gesperrt sein
/RedirectMatch 404 "[^"]*radwegpate_private/.test(read('.htaccess'))
  ? ok('.htaccess sperrt radwegpate_private') : fail('public/.htaccess sperrt den Datenordner radwegpate_private nicht');
(read('config.example.php').match(/APP_DATA_DIR',\s*__DIR__ \. '\/([^']+)'/) || [])[1] === 'radwegpate_private'
  ? ok('config.example.php: Datenordner radwegpate_private') : fail('config.example.php: APP_DATA_DIR passt nicht zur Sperre in .htaccess');

// 4. Asset-Verweise in index.php existieren
for (const m of index.matchAll(/(?:src|href)="(assets\/[^"?]+)/g)) {
  existsSync(root + m[1]) ? null : fail('index.php verweist auf fehlende Datei ' + m[1]);
}
ok('index.php: Asset-Verweise geprüft');

// 5. JavaScript-Syntax
for (const f of ['assets/app.js', 'sw.js']) {
  try { execFileSync(process.execPath, ['--check', root + f], { stdio: 'pipe' }); ok('Syntax ' + f); }
  catch (e) { fail('Syntax ' + f + ': ' + e.stderr); }
}

// 6. Keine Secrets im Repository-Stand
if (existsSync(root + 'config.php')) console.log('hinweis config.php liegt lokal vor (gitignored, wird nicht deployt)');
const example = read('config.example.php');
/APP_INSTALL_TOKEN', '[A-Za-z0-9_-]{20,}'/.test(example) && !example.includes('HIER-')
  ? fail('config.example.php enthält offenbar einen echten Einrichtungscode') : ok('config.example.php ohne echten Code');

console.log(errors ? `\n${errors} Fehler` : '\nAlles in Ordnung.');
process.exit(errors ? 1 : 0);
