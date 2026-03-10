#!/bin/sh
set -eu

APP_PORT="${APP_PORT:-8000}"
DB_WAIT_ATTEMPTS="${DB_WAIT_ATTEMPTS:-30}"
APP_SEED_MODE="${APP_SEED_MODE:-if-empty}"

if [ ! -f .env ]; then
  cp .env.example .env
fi

if [ -z "${APP_KEY:-}" ] && ! grep -q '^APP_KEY=base64:' .env; then
  php artisan key:generate --force --ansi
fi

php artisan config:clear --ansi >/dev/null 2>&1 || true

attempt=1
until php artisan migrate --force --ansi; do
  if [ "$attempt" -ge "$DB_WAIT_ATTEMPTS" ]; then
    echo "Database did not become ready after ${DB_WAIT_ATTEMPTS} attempts." >&2
    exit 1
  fi

  echo "Waiting for database... (${attempt}/${DB_WAIT_ATTEMPTS})"
  attempt=$((attempt + 1))
  sleep 2
done

should_seed=false

database_is_seeded() {
  php -r 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); exit((int) (! (\App\Models\User::query()->exists() && \App\Models\Product::query()->exists() && \App\Models\Gateway::query()->exists())));'
}

case "$APP_SEED_MODE" in
  always)
    should_seed=true
    ;;
  if-empty)
    if database_is_seeded >/dev/null 2>&1; then
      should_seed=false
    else
      should_seed=true
    fi
    ;;
  never)
    should_seed=false
    ;;
  *)
    echo "Invalid APP_SEED_MODE: $APP_SEED_MODE" >&2
    exit 1
    ;;
esac

if [ "$should_seed" = "true" ]; then
  php artisan db:seed --force --ansi
fi

exec php artisan serve --host=0.0.0.0 --port="$APP_PORT"
