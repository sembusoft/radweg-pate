<?php
declare(strict_types=1);
/**
 * Sicherung aller radpate_-Tabellen als JSON (nur Kommandozeile). Läuft im Deploy vor den Migrationen.
 *
 *   php db/backup.php [--keep=10]
 *
 * Datei: radwegpate_private/backups/db-YYYYMMDD-HHMMSS.json.gz (Ordner von außen gesperrt).
 * Fotos sind Dateien und liegen ohnehin im Datenordner – sie sind nicht Teil dieser Sicherung.
 * Ohne Tabellen (allererster Deploy) gibt es nichts zu sichern: Exit 0.
 */
require __DIR__ . '/db.php';
db_cli_bootstrap();

$keep = 10;
foreach ($argv as $a) if (preg_match('/^--keep=(\d+)$/', $a, $m)) $keep = max(1, (int)$m[1]);

try { $pdo = db(); } catch (Throwable $e) { fwrite(STDERR, 'Datenbank nicht erreichbar: ' . $e->getMessage() . "\n"); exit(1); }
$tables = $pdo->query("SHOW TABLES LIKE 'radpate\\_%'")->fetchAll(PDO::FETCH_COLUMN);
if (!$tables) { echo "Noch keine radpate_-Tabellen – nichts zu sichern.\n"; exit(0); }

$dump = ['createdAt' => gmdate('c'), 'database' => APP_DB_NAME, 'tables' => []];
foreach ($tables as $t) $dump['tables'][$t] = $pdo->query("SELECT * FROM `$t`")->fetchAll();

$dir = rtrim((string)(getenv('RADWEGPATE_IMPORT_DIR') ?: APP_DATA_DIR), '/') . '/backups'; // Umweg nur für scripts/import-test.php
if (!is_dir($dir) && !mkdir($dir, 0700, true)) { fwrite(STDERR, "Sicherungsordner nicht anlegbar: $dir\n"); exit(1); }
$file = $dir . '/db-' . gmdate('Ymd-His') . '.json.gz';
$json = json_encode($dump, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($json === false || file_put_contents($file, gzencode($json, 6)) === false) { fwrite(STDERR, "Sicherung fehlgeschlagen.\n"); exit(1); }
@chmod($file, 0600);

$rows = array_sum(array_map('count', $dump['tables']));
echo 'Gesichert: ' . count($tables) . " Tabellen, $rows Zeilen → backups/" . basename($file) . ' (' . round(filesize($file) / 1024) . " KB)\n";

$all = glob($dir . '/db-*.json.gz') ?: [];
rsort($all, SORT_STRING);
foreach (array_slice($all, $keep) as $old) { @unlink($old); echo 'Alte Sicherung entfernt: ' . basename($old) . "\n"; }
exit(0);
