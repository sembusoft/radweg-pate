#!/bin/sh
# Wegwerf-Instanz für den Rauchtest: eigene config.php außerhalb des Webroots,
# Datenordner als tmpfs an derselben Stelle wie auf dem Server
# (/var/www/html/radwegpate_private) – so prüft der Test auch die .htaccess-Sperren.
# Deine lokalen Testdaten bleiben unberührt.
set -e
mkdir -p /opt/rp-test
cat > /opt/rp-test/config.php <<CFG
<?php
declare(strict_types=1);
define('APP_INSTALL_TOKEN', '${INSTALL_TOKEN}');
define('APP_DATA_DIR', '/var/www/html/radwegpate_private');
define('APP_MAX_UPLOAD_MB', 8);
CFG
echo 'SetEnv RADWEGPATE_CONFIG /opt/rp-test/config.php' > /etc/apache2/conf-enabled/radwegpate-test.conf
exec apache2-foreground
