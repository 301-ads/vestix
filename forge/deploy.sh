#!/usr/bin/env bash
# Laravel Forge zero-downtime deploy (release-based).
# Paste into Forge → Site → Deployment.

$CREATE_RELEASE()

cd $FORGE_RELEASE_DIRECTORY

$FORGE_COMPOSER install --no-dev --no-interaction --prefer-dist --optimize-autoloader

npm ci || npm install
npm run build

$FORGE_PHP artisan filament:upgrade
$FORGE_PHP artisan optimize
$FORGE_PHP artisan storage:link --force
$FORGE_PHP artisan migrate --force

$ACTIVATE_RELEASE()

$RESTART_QUEUES()
