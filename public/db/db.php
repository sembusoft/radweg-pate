<?php
declare(strict_types=1);
/**
 * Datenbankzugriff (MariaDB 10.11) – gemeinsam für api.php und die Kommandozeilen-Skripte
 * db/migrate.php, db/import-json.php, db/backup.php.
 *
 * Zugangsdaten: .env (DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASSWORD) → config.php → Konstanten APP_DB_*.
 * Alle Tabellen tragen das Präfix radpate_ – die Datenbank teilen sich mehrere Projekte.
 * Der Ordner db/ ist von außen gesperrt (public/.htaccess).
 */

/** Verbindung (einmal je Anfrage). Wirft PDOException, wenn die DB nicht erreichbar ist. */
function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    if (!defined('APP_DB_NAME') || APP_DB_NAME === '' || APP_DB_USER === '') {
        throw new RuntimeException('Datenbank-Zugangsdaten fehlen: DB_NAME/DB_USER/DB_PASSWORD in der .env eintragen (Vorlage .env.example).');
    }
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        APP_DB_HOST, defined('APP_DB_PORT') ? (int)APP_DB_PORT : 3306, APP_DB_NAME);
    $init = defined('Pdo\Mysql::ATTR_INIT_COMMAND') ? \Pdo\Mysql::ATTR_INIT_COMMAND : PDO::MYSQL_ATTR_INIT_COMMAND;
    $pdo = new PDO($dsn, (string)APP_DB_USER, (string)APP_DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_STRINGIFY_FETCHES  => false,
        // UTC für NOW()/CURRENT_TIMESTAMP; strikte Prüfung statt stiller Kürzung.
        $init => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '+00:00', sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO'",
    ]);
    return $pdo;
}

/** Vorbereitete Anweisung ausführen. */
function dbq(string $sql, array $params = []): PDOStatement {
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}
function db_all(string $sql, array $params = []): array { return dbq($sql, $params)->fetchAll(); }
function db_row(string $sql, array $params = []): ?array { $r = dbq($sql, $params)->fetch(); return $r === false ? null : $r; }
function db_val(string $sql, array $params = []): mixed { $v = dbq($sql, $params)->fetchColumn(); return $v === false ? null : $v; }

/**
 * Transaktion. Bricht $fn mit fail() ab (exit), rollt MariaDB beim Schließen der Verbindung
 * automatisch zurück; Ausnahmen werden zurückgerollt und weitergereicht.
 */
function db_tx(callable $fn): mixed {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $out = $fn();
        $pdo->commit();
        return $out;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** Jetzt in UTC als DATETIME. */
function db_now(): string { return gmdate('Y-m-d H:i:s'); }
/** ISO-8601 (wie bisher gmdate('c')) → DATETIME UTC. Ungültig/leer → $fallback. */
function db_from_iso(mixed $iso, ?string $fallback = null): ?string {
    if (!is_string($iso) || $iso === '') return $fallback;
    try { return (new DateTimeImmutable($iso))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'); }
    catch (Throwable) { return $fallback; }
}
/** DATETIME UTC → ISO-8601 mit +00:00 (Format der bisherigen JSON-Daten). */
function db_iso(?string $dt): ?string { return $dt === null ? null : str_replace(' ', 'T', $dt) . '+00:00'; }
/** TIME → HH:MM. */
function db_hm(?string $t): ?string { return $t === null ? null : substr($t, 0, 5); }

/** Einzelwerte aus radpate_settings. */
function setting(string $name): ?string {
    $v = db_val('SELECT `value` FROM radpate_settings WHERE `name` = ?', [$name]);
    return $v === null ? null : (string)$v;
}
function setting_set(string $name, ?string $value): void {
    dbq('INSERT INTO radpate_settings (`name`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)', [$name, $value]);
}

/** Konfiguration für Kommandozeilen-Skripte laden (public/config.php → .env bzw. RADWEGPATE_ENV). */
function db_cli_bootstrap(): void {
    if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
    require_once dirname(__DIR__) . '/config.php';
    if (APP_ENV_FILE === '' && getenv('DB_NAME') === false) {
        fwrite(STDERR, ".env nicht gefunden (erwartet neben config.php oder in RADWEGPATE_ENV).\n");
        exit(2);
    }
}
