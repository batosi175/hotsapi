#!/bin/bash
php artisan down
set -e
git pull origin master
chown -R www-data:www-data .
chmod -R a+w storage
composer install -o
php artisan migrate --force
php artisan queue:restart
php artisan up
