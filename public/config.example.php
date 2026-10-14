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
// MUSS außerhalb des Webroots liegen. all-inkl, Webroot
//   /www/htdocs/w0XXXXXX/radweg-pate.de
// → Daten z. B. nach
//   /www/htdocs/w0XXXXXX/radweg-pate_private
// Die App legt dort zusätzlich eine .htaccess mit "Require all denied" an,
// falls der Ordner doch über eine andere Domain erreichbar sein sollte.
define('APP_DATA_DIR', dirname(__DIR__) . '/radweg-pate_private');

// Maximale Größe je hochgeladenem Foto in MB.
define('APP_MAX_UPLOAD_MB', 8);
