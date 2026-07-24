#!/bin/sh
set -eu

mkdir -p \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/testing \
    storage/framework/views \
    bootstrap/cache

chown -R app:app storage bootstrap/cache

if [ "${APP_KEY:-}" = "" ]; then
    key_file=storage/app.key
    if [ ! -s "$key_file" ]; then
        generated_key=$(php -r 'echo "base64:".base64_encode(random_bytes(32));')
        (set -C; umask 077; printf '%s\n' "$generated_key" > "$key_file") 2>/dev/null || true
    fi
    while [ ! -s "$key_file" ]; do
        sleep 1
    done
    APP_KEY=$(cat "$key_file")
    export APP_KEY
fi

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    su-exec app php artisan migrate --force
fi

exec su-exec app "$@"
