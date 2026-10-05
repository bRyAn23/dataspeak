#!/bin/sh
# Production entrypoint: makes sure the storage tree exists on the shared volume, then warms the Laravel caches
# from the container environment (no .env file is ever written). Never prints secrets.
set -e
cd /app
mkdir -p storage/app/tmp storage/fonts storage/framework/cache/data storage/framework/sessions \
         storage/framework/views storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true
if [ -z "$APP_KEY" ]; then
  echo "APP_KEY is not set. Generate one with: php artisan key:generate --show" >&2
  exit 1
fi
php artisan config:cache >/dev/null
php artisan route:cache >/dev/null
php artisan view:cache >/dev/null
php artisan event:cache >/dev/null 2>&1 || true
exec "$@"
