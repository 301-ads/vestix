#!/usr/bin/env bash
set -e

cd "$FORGE_SITE_PATH"

git pull origin "$FORGE_SITE_BRANCH"

$FORGE_COMPOSER install --no-dev --no-interaction --prefer-dist --optimize-autoloader

npm ci
npm run build

if [ -f artisan ]; then
    $FORGE_PHP artisan migrate --force
    $FORGE_PHP artisan storage:link --force
    $FORGE_PHP artisan config:cache
    $FORGE_PHP artisan route:cache
    $FORGE_PHP artisan view:cache
    $FORGE_PHP artisan filament:upgrade
    $FORGE_PHP artisan optimize
fi

( flock -w 10 9 || exit 1
    echo 'Restarting FPM...'; sudo -S service "$FORGE_PHP_FPM" reload ) 9>/tmp/fpmlock
