#!/usr/bin/env bash
set -euo pipefail

echo "Preparing Tracksuite release"

if [[ ! -f .env ]]; then
    echo "ERROR: .env is missing. Configure production secrets before deploying."
    exit 1
fi

composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
npm ci
npm run build

php artisan migrate --force
php artisan storage:link --relative || php artisan storage:link
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

echo "Tracksuite release is ready"
