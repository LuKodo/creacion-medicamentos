#!/bin/sh
set -eu

data_dir=/var/www/html/data
seed_dir=/opt/app-seed/data

mkdir -p "$data_dir" /var/www/html/salidas

# An empty FSS mount gets the image's initial data once. Never merge into or
# overwrite an initialized volume: later uploads and updates are authoritative.
if [ -d "$seed_dir" ] && [ -z "$(find "$data_dir" -mindepth 1 -maxdepth 1 ! -name lost+found ! -name .snapshot -print -quit)" ]; then
    cp -a "$seed_dir"/. "$data_dir"/
fi

chown www-data:www-data "$data_dir" /var/www/html/salidas
exec "$@"
