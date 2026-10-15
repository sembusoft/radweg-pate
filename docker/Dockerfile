# Lokale Testumgebung – nah an all-inkl: Apache + PHP 8.3 (gd, pdo_mysql), .htaccess aktiv.
FROM php:8.3-apache

RUN apt-get update \
 && apt-get install -y --no-install-recommends libpng-dev libjpeg62-turbo-dev libwebp-dev \
 && docker-php-ext-configure gd --with-jpeg --with-webp \
 && docker-php-ext-install gd pdo_mysql \
 && rm -rf /var/lib/apt/lists/* \
 && a2enmod headers rewrite \
 && sed -ri 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf \
 && echo 'ServerName localhost' > /etc/apache2/conf-enabled/servername.conf

COPY docker/php.ini /usr/local/etc/php/conf.d/radwegpate.ini
COPY docker/test-entrypoint.sh /usr/local/bin/test-entrypoint.sh
RUN chmod +x /usr/local/bin/test-entrypoint.sh
