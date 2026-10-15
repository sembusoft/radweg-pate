#!/bin/sh
# Wegwerf-Instanz für den Rauchtest: eigene .env außerhalb des Webroots (RADWEGPATE_ENV),
# Datenordner als tmpfs an derselben Stelle wie auf dem Server
# (/var/www/html/radwegpate_private) – so prüft der Test auch die .htaccess-Sperren.
# Datenbank radwegpate_test wird bei jedem Lauf neu angelegt und migriert.
# Deine lokalen Testdaten (public/.env, Datenbank radwegpate) bleiben unberührt.
set -e
mkdir -p /opt/rp-test
cat > /opt/rp-test/.env <<ENV
INSTALL_TOKEN=${INSTALL_TOKEN}
DB_HOST=db
DB_PORT=3306
DB_NAME=radwegpate_test
DB_USER=radwegpate
DB_PASSWORD=radwegpate-lokal
MAX_UPLOAD_MB=8
ENV
php -r '$p=new PDO("mysql:host=db", "root", getenv("DB_ROOT_PASSWORD"));
  $p->exec("DROP DATABASE IF EXISTS radwegpate_test");
  $p->exec("CREATE DATABASE radwegpate_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
  $p->exec("GRANT ALL ON radwegpate_test.* TO \"radwegpate\"@\"%\"");'
RADWEGPATE_ENV=/opt/rp-test/.env php /var/www/html/db/migrate.php
echo 'SetEnv RADWEGPATE_ENV /opt/rp-test/.env' > /etc/apache2/conf-enabled/radwegpate-test.conf
exec apache2-foreground
