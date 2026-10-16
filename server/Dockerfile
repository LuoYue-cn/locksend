FROM composer:2 AS vendor
WORKDIR /app
COPY server/composer.json server/composer.lock ./
RUN composer install --no-dev --prefer-dist --no-progress --no-interaction --optimize-autoloader \
    --ignore-platform-req=ext-sodium --ignore-platform-req=ext-pdo

FROM node:20-alpine AS web
WORKDIR /web
COPY web/package.json web/package-lock.json ./
RUN npm ci
COPY web/ ./
# build.mjs writes into ../server/public and ../server/templates
RUN mkdir -p /server/public /server/templates && node build.mjs

FROM php:8.3-fpm-alpine
RUN apk add --no-cache $PHPIZE_DEPS \
 && docker-php-ext-install pdo_sqlite \
 && apk del $PHPIZE_DEPS
RUN { echo 'expose_php=0'; echo 'display_errors=0'; echo 'log_errors=1'; echo 'opcache.validate_timestamps=0'; } > /usr/local/etc/php/conf.d/locksend.ini
WORKDIR /var/www/locksend/server
COPY server/src ./src
COPY server/bin ./bin
COPY server/migrations ./migrations
COPY server/public/index.php ./public/index.php
COPY --from=vendor /app/vendor ./vendor
COPY --from=web /server/public ./public
COPY --from=web /server/templates ./templates
RUN mkdir -p /var/lib/locksend && chown -R www-data:www-data /var/lib/locksend
VOLUME ["/var/lib/locksend", "/var/www/locksend/server/public"]
