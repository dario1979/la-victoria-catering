#!/bin/sh
set -eu

mode="${1:-runtime}"
env_file="${STAGING_PREFLIGHT_ENV_FILE:-.env.staging}"
compose_file="${STAGING_PREFLIGHT_COMPOSE_FILE:-compose.staging.yaml}"
example_env_file=".env.staging.example"

case "$mode" in
    structural|pre-migrate|runtime) ;;
    *)
        echo "Mode must be structural, pre-migrate, or runtime." >&2
        exit 64
        ;;
esac

if [ ! -f "$env_file" ]; then
    if [ ! -f "$example_env_file" ]; then
        echo "Missing both $env_file and $example_env_file." >&2
        exit 1
    fi

    resolved_example="$(cd "$(dirname "$example_env_file")" && pwd)/$(basename "$example_env_file")"
    APP_KEY='base64:MDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDA=' \
    APP_URL='https://staging.example.com' \
    DB_DATABASE='structural_staging' \
    DB_USERNAME='structural_staging' \
    DB_PASSWORD='structural-database-placeholder' \
    REDIS_PASSWORD='structural-redis-placeholder' \
    SESSION_DOMAIN='staging.example.com' \
    MAIL_FROM_ADDRESS='no-reply@staging.example.com' \
    DEMO_USER_PASSWORD='structural-demo-placeholder' \
    STAGING_ENV_FILE="$resolved_example" \
        docker compose --env-file "$resolved_example" -f "$compose_file" config --quiet

    echo "staging.compose.structural PASS"
    echo "staging.runtime BLOCKED: falta $env_file; complete secretos fuera de Git antes de construir, migrar o exponer servicios."
    exit 2
fi

resolved_env="$(cd "$(dirname "$env_file")" && pwd)/$(basename "$env_file")"
export STAGING_ENV_FILE="$resolved_env"
docker compose --env-file "$resolved_env" -f "$compose_file" config --quiet
echo "staging.compose.structural PASS"

case "$mode" in
    structural)
        echo "staging.runtime NOT_RUN"
        ;;
    pre-migrate)
        docker compose --env-file "$resolved_env" -f "$compose_file" \
            run --rm --no-deps -e RUN_MIGRATIONS=false backend \
            php artisan bakery:staging-preflight \
            --phase=pre-migrate --allow-pending-migrations --format=json
        ;;
    runtime)
        docker compose --env-file "$resolved_env" -f "$compose_file" \
            exec -T backend php artisan bakery:staging-preflight \
            --phase=runtime --format=json
        ;;
esac
