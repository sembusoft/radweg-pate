<?php
declare(strict_types=1);
/**
 * Spielt offene Migrationen aus db/migrations/*.sql ein (nur Kommandozeile).
 *
 *   php db/migrate.php            offene einspielen
 *   php db/migrate.php --status   nur anzeigen, was eingespielt ist und was offen wäre
 *
 * Stand in radpate_schema_migrations (Dateiname + sha1 beim Einspielen). Eine Datei, die dort
 * steht, läuft nicht noch einmal; eine nachträglich geänderte wird gemeldet (Prüfsumme).
 * Regeln wie bei fightReg: nur erweiternd, idempotent, ohne DELIMITER (db/README.md).
 * Exit-Code 0 = alles eingespielt, 1 = Fehler.
 */
require __DIR__ . '/db.php';
db_cli_bootstrap();

$statusOnly = in_array('--status', $argv, true);
$dir = __DIR__ . '/migrations';
$files = glob($dir . '/[0-9][0-9][0-9]_*.sql') ?: [];
sort($files, SORT_STRING);

try {
    $pdo = db();
} catch (Throwable $e) {
    fwrite(STDERR, 'Datenbank nicht erreichbar: ' . $e->getMessage() . "\n");
    exit(1);
}
echo 'Datenbank: ' . APP_DB_NAME . ' · MariaDB ' . $pdo->query('SELECT VERSION()')->fetchColumn() . "\n";

$pdo->exec("CREATE TABLE IF NOT EXISTS `radpate_schema_migrations` (
    `filename`    VARCHAR(190) NOT NULL,
    `checksum`    CHAR(40)     NOT NULL COMMENT 'sha1 des Inhalts beim Einspielen, Zeilenenden normalisiert',
    `applied_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`filename`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$applied = [];
foreach ($pdo->query('SELECT filename, checksum, applied_at FROM radpate_schema_migrations') as $r) $applied[$r['filename']] = $r;

$sum = fn(string $sql) => sha1(str_replace("\r\n", "\n", $sql));
/** Anweisungen trennen: Kommentarzeilen weg, Ende = „;“ am Zeilenende. */
$split = function (string $sql): array {
    $lines = array_filter(explode("\n", str_replace("\r\n", "\n", $sql)), fn($l) => !preg_match('/^\s*--/', $l));
    $parts = preg_split('/;\s*$/m', implode("\n", $lines)) ?: [];
    return array_values(array_filter(array_map('trim', $parts), fn($s) => $s !== ''));
};

$open = [];
foreach ($files as $path) {
    $name = basename($path);
    $sql = (string)file_get_contents($path);
    if (isset($applied[$name])) {
        $drift = $applied[$name]['checksum'] !== $sum($sql) ? '  ACHTUNG: Datei seit dem Einspielen geändert' : '';
        echo "  eingespielt  $name  ({$applied[$name]['applied_at']} UTC)$drift\n";
    } else {
        $open[] = $path;
        echo "  offen        $name\n";
    }
}
if (!$open) { echo "Nichts offen.\n"; exit(0); }
if ($statusOnly) { echo count($open) . " offen (nur Anzeige, --status).\n"; exit(0); }

foreach ($open as $path) {
    $name = basename($path);
    $sql = (string)file_get_contents($path);
    echo "→ $name … ";
    try {
        // DDL beendet Transaktionen in MariaDB implizit – deshalb keine Klammer-Transaktion.
        // Die Migrationen sind idempotent: bricht eine ab, läuft sie beim nächsten Mal sauber durch.
        foreach ($split($sql) as $stmt) $pdo->exec($stmt);
        $pdo->prepare('INSERT INTO radpate_schema_migrations (filename, checksum) VALUES (?, ?)')->execute([$name, $sum($sql)]);
        echo "ok\n";
    } catch (Throwable $e) {
        echo "FEHLER\n";
        fwrite(STDERR, "$name: " . $e->getMessage() . "\n");
        exit(1);
    }
}
echo count($open) . " Migration(en) eingespielt.\n";
exit(0);
