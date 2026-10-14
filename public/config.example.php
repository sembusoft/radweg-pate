<?php
/**
 * Radwegpate – Server-Einstellungen (Vorlage)
 *
 * Auf dem Server als config.php ablegen und anpassen. Die echte config.php
 * steht NICHT im Repository (.gitignore) und wird vom Deploy NICHT
 * überschrieben (.deployignore).
 */
declare(strict_types=1);

// Einmaliger Einrichtungscode für die Ersteinrichtung (mindestens 20 Zeichen).
// Erzeugen z. B. mit: php -r "echo bin2hex(random_bytes(16));"
// Nach erfolgter Einrichtung (install.json vorhanden) hat er keine Wirkung mehr.
define('APP_INSTALL_TOKEN', 'HIER-EINEN-ZUFAELLIGEN-CODE-EINTRAGEN');

// Privater Datenordner: Tagebuch, Fotos, KML-Daten, Passwort-Hash.
// Liegt unterhalb des Webroots: /www/htdocs/w0XXXXXX/radweg-pate.de/radwegpate_private
// Von außen dreifach gesperrt:
//   1. public/.htaccess antwortet auf /radwegpate_private/… mit 404,
//   2. die App legt im Ordner selbst eine .htaccess "Require all denied" an,
//   3. der Deploy prüft nach jedem Lauf, dass install.json nicht abrufbar ist.
// Der Deploy überträgt und löscht in diesem Ordner nichts (.deployignore).
// Name nur ändern, wenn auch public/.htaccess und .deployignore angepasst werden.
define('APP_DATA_DIR', __DIR__ . '/radwegpate_private');

// Maximale Größe je hochgeladenem Foto in MB.
define('APP_MAX_UPLOAD_MB', 8);
