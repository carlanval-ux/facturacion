# PoC aislado de FacturaScripts (FS-001). Solo desarrollo: datos ficticios, sin emisión fiscal real.
FROM composer:2.8 AS vendor
WORKDIR /app
COPY composer.json ./
RUN composer install --no-dev --no-scripts --no-interaction --ignore-platform-reqs --prefer-dist

FROM php:8.3-apache
RUN apt-get update && apt-get install -y --no-install-recommends libzip-dev libpng-dev libjpeg-dev libfreetype6-dev libpq-dev libxml2-dev unzip \
 && docker-php-ext-configure gd --with-jpeg --with-freetype \
 && docker-php-ext-install -j2 bcmath gd mysqli pgsql zip \
 && a2enmod rewrite && rm -rf /var/lib/apt/lists/*
ENV APACHE_DOCUMENT_ROOT=/var/www/html
COPY --chown=www-data:www-data . /var/www/html
COPY --from=vendor --chown=www-data:www-data /app/vendor /var/www/html/vendor
COPY --chown=www-data:www-data integration/plugins/ /var/www/html/Plugins/
RUN printf '<Directory /var/www/html>\n AllowOverride All\n</Directory>\n' > /etc/apache2/conf-enabled/fs.conf \
 && mkdir -p /var/www/html/MyFiles && chown -R www-data:www-data /var/www/html
