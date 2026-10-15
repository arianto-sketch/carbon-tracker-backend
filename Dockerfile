# syntax=docker/dockerfile:1.7
# Image API (PHP-FPM) untuk app, queue worker, dan scheduler. Lihat docs/DEPLOY.md.
# Development lokal memakai Laragon, jadi hanya ada stage production.

ARG PHP_VERSION=8.3

# --- base: PHP + ekstensi yang dibutuhkan Laravel, PhpSpreadsheet (gd, zip) dan MySQL ---
FROM php:${PHP_VERSION}-fpm-alpine AS base

# fcgi: healthcheck ke php-fpm (/ping)
RUN apk add --no-cache fcgi icu-libs libzip libpng libjpeg-turbo freetype \
 && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev libzip-dev libpng-dev libjpeg-turbo-dev freetype-dev \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j"$(nproc)" bcmath gd intl opcache pcntl pdo_mysql zip \
 && apk del .build-deps

COPY docker/php/php.ini "$PHP_INI_DIR/conf.d/zz-app.ini"
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/zz-app.conf

WORKDIR /var/www/html

# --- vendor: dependency production saja (tanpa phpunit, larastan, dll.) ---
FROM base AS vendor

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress

# --- prod ---
FROM base AS prod

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY --from=vendor /var/www/html/vendor ./vendor
COPY . .

RUN composer dump-autoload --optimize --no-dev --no-interaction \
 && php artisan package:discover --ansi \
 && rm /usr/bin/composer \
 && mkdir -p storage/app/private storage/app/public storage/framework/cache/data storage/framework/sessions \
    storage/framework/views storage/logs bootstrap/cache \
 && chown -R www-data:www-data storage bootstrap/cache

COPY --chmod=755 docker/php/entrypoint.sh /usr/local/bin/app-entrypoint

USER www-data

EXPOSE 9000

ENTRYPOINT ["app-entrypoint"]
CMD ["php-fpm"]
