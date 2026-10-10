#!/bin/sh
set -e

php artisan config:clear
php artisan migrate --force
php artisan db:seed --force   # only seeds when the users table is empty

exec php artisan serve --host=0.0.0.0 --port="${PORT:-10000}"
