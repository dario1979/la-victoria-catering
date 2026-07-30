#!/usr/bin/env bash
set -euo pipefail

action="${1:-}"
project="${E2E_PROJECT_NAME:-la-victoria-e2e}"

if [[ ! "$project" =~ ^[a-z0-9][a-z0-9_-]*$ ]]; then
    echo "E2E_PROJECT_NAME contains unsupported characters." >&2
    exit 1
fi

export APP_ENV=testing
export APP_DEBUG=false
export APP_KEY="${E2E_APP_KEY:-base64:MDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDA=}"
export APP_URL="${E2E_BASE_URL:-http://127.0.0.1:18080}"
export DB_DATABASE="${E2E_DB_DATABASE:-la_victoria_e2e}"
export DB_USERNAME="${E2E_DB_USERNAME:-la_victoria_e2e}"
export DB_PASSWORD="${E2E_DB_PASSWORD:-e2e_database_only}"
export DEMO_USER_PASSWORD="${E2E_PASSWORD:-123456}"
export FORWARD_FRONTEND_PORT="${E2E_FRONTEND_PORT:-18080}"
export FORWARD_BACKEND_PORT="${E2E_BACKEND_PORT:-18000}"
export FORWARD_DB_PORT="${E2E_DB_PORT:-15432}"
export FORWARD_REDIS_PORT="${E2E_REDIS_PORT:-16379}"
export FORWARD_MAILPIT_SMTP_PORT="${E2E_MAILPIT_SMTP_PORT:-11025}"
export FORWARD_MAILPIT_UI_PORT="${E2E_MAILPIT_UI_PORT:-18025}"
export ARCA_ENABLED=false
export MERCADOPAGO_ENABLED=false
export MERCADOPAGO_WEBHOOKS_ENABLED=false
export PWA_PUSH_ENABLED=false

compose=(docker compose --project-name "$project")

case "$action" in
    up)
        "${compose[@]}" build
        "${compose[@]}" up -d --wait --wait-timeout 120
        "${compose[@]}" exec -T backend php artisan migrate:fresh --seed --force
        ;;
    reset)
        "${compose[@]}" exec -T backend php artisan migrate:fresh --seed --force
        ;;
    logs)
        mkdir -p .e2e-artifacts
        "${compose[@]}" ps --all
        "${compose[@]}" logs --no-color
        ;;
    down)
        "${compose[@]}" down --volumes --remove-orphans
        ;;
    *)
        echo "Usage: scripts/e2e-stack.sh {up|reset|logs|down}" >&2
        exit 2
        ;;
esac
