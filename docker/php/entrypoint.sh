#!/bin/sh
# Entrypoint app, queue, dan scheduler: cache konfigurasi memakai env container saat start
# (bukan saat build, supaya satu image bisa dipakai dengan .env yang berbeda).
set -e

if [ "${APP_ENV:-production}" = "production" ]; then
    php artisan config:cache --no-interaction
    php artisan route:cache --no-interaction
    php artisan event:cache --no-interaction
    php artisan view:cache --no-interaction
fi

exec "$@"
