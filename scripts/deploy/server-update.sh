#!/usr/bin/env bash
set -euo pipefail

APP_DIR="${APP_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)}"
PHP_BIN="${PHP_BIN:-/opt/alt/php84/usr/bin/php}"
COMPOSER_BIN="${COMPOSER_BIN:-/usr/local/bin/composer2}"
BRANCH="${DEPLOY_BRANCH:-main}"

cd "$APP_DIR"

echo "[server-update] Pulling branch $BRANCH"
git pull origin "$BRANCH"

echo "[server-update] Installing composer deps (prod)"
"$PHP_BIN" "$COMPOSER_BIN" install --no-dev --prefer-dist --optimize-autoloader

echo "[server-update] Running migrations"
"$PHP_BIN" artisan migrate --force

echo "[server-update] Refreshing caches"
"$PHP_BIN" artisan optimize:clear
"$PHP_BIN" artisan config:cache
"$PHP_BIN" artisan route:cache
"$PHP_BIN" artisan view:cache

echo "[server-update] Done. Ensure fresh public/build assets were synced from local."
