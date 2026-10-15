<?php
/**
 * Radwegpate – Konfiguration (steht im Repository, enthält KEINE Geheimnisse).
 *
 * Alle Zugangsdaten kommen aus der Datei .env neben dieser Datei (Vorlage: .env.example).
 * Die .env liegt nur auf dem Server bzw. lokal: nie im Repository (.gitignore), nie im Deploy
 * (.deployignore), von außen gesperrt (public/.htaccess, Deploy prüft das bei jedem Lauf).
 *
 * Reihenfolge je Wert: echte Umgebungsvariable (Docker, CI) vor .env vor Vorgabe.
 * RADWEGPATE_ENV: anderer Pfad zur .env (nur Testinstanzen, z. B. docker/test-entrypoint.sh).
 */
declare(strict_types=1);

if (!function_exists('rp_env')) {
    /** .env einlesen (eigener Parser wie bei fightReg, kein Composer). */
    function rp_env_load(string $file): array {
        $out = [];
        if (!is_file($file) || !is_readable($file)) return $out;
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            if (str_starts_with($line, 'export ')) $line = substr($line, 7);
            $pos = strpos($line, '=');
            if ($pos === false) continue;
            $key = trim(substr($line, 0, $pos));
            $val = trim(substr($line, $pos + 1));
            if ($val !== '' && ($val[0] === '"' || $val[0] === "'")) {
                // In Anführungszeichen: alles bis zum schließenden Zeichen, Rest (Kommentar) verwerfen.
                $end = strpos($val, $val[0], 1);
                $val = $end !== false ? substr($val, 1, $end - 1) : substr($val, 1);
            } elseif (preg_match('/\s#/', $val, $m, PREG_OFFSET_CAPTURE)) {
                // Kommentar nur ab " #": Passwörter wie "abc#def" bleiben ganz.
                $val = rtrim(substr($val, 0, $m[0][1]));
            }
            $out[$key] = $val;
        }
        return $out;
    }
    /** Wert lesen: Umgebung → .env → Vorgabe. */
    function rp_env(string $key, string $default = ''): string {
        static $file = null;
        if ($file === null) {
            $path = getenv('RADWEGPATE_ENV') ?: (string)($_SERVER['RADWEGPATE_ENV'] ?? '');
            $file = rp_env_load($path !== '' ? $path : __DIR__ . '/.env');
        }
        $v = getenv($key);
        if ($v === false || $v === '') $v = $file[$key] ?? null;
        return $v === null || $v === '' ? $default : (string)$v;
    }
}

// Ist eine .env gefunden worden? (api.php meldet sonst verständlich, was fehlt.)
define('APP_ENV_FILE', (function () {
    $path = getenv('RADWEGPATE_ENV') ?: (string)($_SERVER['RADWEGPATE_ENV'] ?? '');
    $path = $path !== '' ? $path : __DIR__ . '/.env';
    return is_file($path) ? $path : '';
})());

// Einmaliger Einrichtungscode (mindestens 20 Zeichen). Nach der Einrichtung ohne Wirkung.
define('APP_INSTALL_TOKEN', rp_env('INSTALL_TOKEN'));

// Privater Datenordner (Fotos, Sicherungen, alte JSON-Dateien). Bewusst FEST, nicht aus der .env:
// nur dieser Ordner ist von außen gesperrt (public/.htaccess), der Deploy prüft genau diesen Pfad.
define('APP_DATA_DIR', __DIR__ . '/radwegpate_private');

// Maximale Größe je Foto in MB.
define('APP_MAX_UPLOAD_MB', max(1, (int)rp_env('MAX_UPLOAD_MB', '8')));

// Datenbank (MariaDB 10.11, gemeinsame DB – Radwegpate nutzt nur Tabellen radpate_…).
define('APP_DB_HOST', rp_env('DB_HOST', 'localhost'));
define('APP_DB_PORT', (int)rp_env('DB_PORT', '3306'));
define('APP_DB_NAME', rp_env('DB_NAME'));
define('APP_DB_USER', rp_env('DB_USER'));
define('APP_DB_PASS', rp_env('DB_PASSWORD'));
