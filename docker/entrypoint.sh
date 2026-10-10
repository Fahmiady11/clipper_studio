#!/bin/sh
set -e
cd /var/www/html

# Storage is a volume: it may be empty or root-owned on first start.
mkdir -p storage/app/private storage/app/public storage/app/temp storage/app/video_cache \
    storage/app/music storage/framework/cache/data storage/framework/sessions \
    storage/framework/views storage/framework/testing storage/logs/clipper bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache

run_as_www() {
    if [ "$(id -u)" = "0" ]; then runuser -u www-data -- "$@"; else "$@"; fi
}

if [ -n "${APP_KEY:-}" ]; then
    run_as_www ln -sfn /var/www/html/storage/app/public public/storage 2>/dev/null || true
    run_as_www php artisan config:cache
    run_as_www php artisan route:cache
    run_as_www php artisan view:cache
    run_as_www php artisan event:cache
fi

# Daemon (supervisord) stays root; other commands (queue:work, schedule:work, ...)
# run as www-data so files they create in storage stay writable by php-fpm.
if [ "$(id -u)" = "0" ] && [ "${1:-}" != "supervisord" ]; then
    exec setpriv --reuid=www-data --regid=www-data --init-groups -- "$@"
fi
exec "$@"
