#!/bin/sh
set -eu

repo_dir=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
cd "$repo_dir"

if ! command -v docker >/dev/null 2>&1; then
    echo "Docker is required." >&2
    exit 1
fi

if [ ! -f .env ]; then
    cp .env.example .env
fi

if ! grep -q '^APP_KEY=base64:' .env; then
    app_key=$(docker run --rm php:8.4-cli-alpine php -r 'echo "base64:".base64_encode(random_bytes(32));')
    sed "s|^APP_KEY=.*|APP_KEY=${app_key}|" .env > .env.tmp
    mv .env.tmp .env
fi

docker compose build
docker compose up -d postgres redis mailpit
docker compose run --rm backend php artisan migrate --force

echo "Installation complete. Start all services with: docker compose up"
