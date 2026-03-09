#!/bin/sh
set -eu

if [ ! -f .env ]; then
  cp .env.example .env
fi

if ! grep -q '^APP_KEY=base64:' .env; then
  php artisan key:generate --force
fi

php artisan migrate --seed --force

exec php artisan serve --host=0.0.0.0 --port=8000
