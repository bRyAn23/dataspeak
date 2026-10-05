#!/bin/sh
# Generates an app key on first boot when none is supplied. Never prints secrets.
set -e
cd /var/www/html
if [ -z "$APP_KEY" ]; then
  if [ ! -f .env ]; then cp .env.example .env; fi
  if ! grep -q '^APP_KEY=base64:' .env; then php artisan key:generate --force --no-interaction >/dev/null; fi
fi
php artisan config:clear >/dev/null 2>&1 || true
exec "$@"
