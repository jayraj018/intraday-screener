#!/bin/bash

set -e

echo "Running Laravel deployment..."

php artisan config:clear
php artisan cache:clear

php artisan migrate --force

php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "Laravel deployment completed."
