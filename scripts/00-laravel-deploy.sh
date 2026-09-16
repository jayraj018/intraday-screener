#!/bin/bash

set -e

echo "Running Laravel deployment..."

# Migrate FIRST. With CACHE_STORE=database and SESSION_DRIVER=database, the cache and
# session tables only exist once migrations have run — clearing them on a fresh database
# fails with "relation \"cache\" does not exist", and set -e then aborts the deploy before
# migrate is ever reached. That is how production ended up with no sessions table.
php artisan migrate --force

# Clearing is best-effort: a stale cache must never break a deploy.
php artisan config:clear || true
php artisan cache:clear || true

php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "Laravel deployment completed."
