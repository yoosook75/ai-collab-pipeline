#!/bin/sh
set -e

if [ -z "$APP_KEY" ]; then
  echo "APP_KEY is not set" >&2
  exit 1
fi

php artisan config:cache
php artisan route:cache
php artisan view:cache

if [ "${RUN_MIGRATIONS:-0}" = "1" ]; then
  php artisan migrate --force
fi

exec "$@"
