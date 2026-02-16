#!/usr/bin/env bash
set -euo pipefail

APP_DIR="${APP_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)}"
PHP_BIN="${PHP_BIN:-/opt/alt/php84/usr/bin/php}"
COMPOSER_BIN="${COMPOSER_BIN:-/usr/local/bin/composer2}"

cd "$APP_DIR"

echo "[server-first] Running composer install (prod)..."
"$PHP_BIN" "$COMPOSER_BIN" install --no-dev --prefer-dist --optimize-autoloader

if [ ! -f .env ]; then
  echo "[server-first] .env not found. Creating from .env.example"
  cp .env.example .env
fi

if ! grep -Eq '^APP_KEY=base64:' .env; then
  echo "[server-first] Generating APP_KEY"
  "$PHP_BIN" artisan key:generate --force
else
  echo "[server-first] APP_KEY already present"
fi

echo "[server-first] Running migrations and seeders"
"$PHP_BIN" artisan migrate --force
"$PHP_BIN" artisan db:seed --force

echo "[server-first] Creating storage symlink"
"$PHP_BIN" artisan storage:link || echo "[server-first] WARNING: storage:link failed. Check symlink restrictions in hosting."

echo "[server-first] Caching framework artifacts"
"$PHP_BIN" artisan optimize:clear
"$PHP_BIN" artisan config:cache
"$PHP_BIN" artisan route:cache
"$PHP_BIN" artisan view:cache

echo "[server-first] Done. Ensure public/build was synced from local before opening traffic."
