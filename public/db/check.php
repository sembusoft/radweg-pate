<?php
declare(strict_types=1);
/**
 * Prüft .env und Datenbank-Zugang (nur Kommandozeile; Deploy und Fehlersuche).
 *   php db/check.php   → "ok <db> · MariaDB <version> · radpate-Tabellen: n"   Exit 0, sonst 1
 */
require __DIR__ . '/db.php';
db_cli_bootstrap();
echo 'PHP ' . PHP_VERSION . ' · pdo_mysql ' . (extension_loaded('pdo_mysql') ? 'ja' : 'NEIN') . "\n";
echo '.env: ' . (APP_ENV_FILE !== '' ? APP_ENV_FILE : '(keine Datei – Werte aus der Umgebung)') . "\n";
foreach (['DB_NAME' => APP_DB_NAME, 'DB_USER' => APP_DB_USER, 'DB_PASSWORD' => APP_DB_PASS] as $k => $v) {
    if ($v === '') { echo "FEHLT $k in der .env\n"; exit(1); }
}
if (strlen(APP_INSTALL_TOKEN) < 20) echo "Hinweis: INSTALL_TOKEN leer/kurz – nur für eine Neueinrichtung nötig.\n";
try {
    $pdo = db();
    $n = count($pdo->query("SHOW TABLES LIKE 'radpate\\_%'")->fetchAll());
    echo 'ok ' . APP_DB_NAME . ' · MariaDB ' . $pdo->query('SELECT VERSION()')->fetchColumn() . " · radpate-Tabellen: $n\n";
    exit(0);
} catch (Throwable $e) {
    echo 'KEINE VERBINDUNG: ' . $e->getMessage() . "\n";
    exit(1);
}
