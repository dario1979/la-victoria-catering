#!/usr/bin/env bash
set -euo pipefail

frontend_base="${1:-http://127.0.0.1:8080}"
backend_base="${2:-http://127.0.0.1:8000}"
email="${SMOKE_EMAIL:-admin@lavictoria.test}"
password="${SMOKE_PASSWORD:-}"

if [[ -z "$password" ]]; then
    echo "SMOKE_PASSWORD is required." >&2
    exit 1
fi

temporary_directory="$(mktemp -d)"
cookie_jar="$temporary_directory/cookies.txt"
trap 'rm -rf "$temporary_directory"' EXIT

assert_http() {
    local name="$1"
    local url="$2"
    local expected_type="${3:-}"
    shift 3 || true
    local headers="$temporary_directory/headers.txt"
    local body="$temporary_directory/body.bin"

    curl --fail --silent --show-error \
        --cookie "$cookie_jar" \
        --cookie-jar "$cookie_jar" \
        --dump-header "$headers" \
        --output "$body" \
        "$@" \
        "$url"

    if [[ -n "$expected_type" ]] && ! grep -Eiq "^content-type: ${expected_type}" "$headers"; then
        echo "$name returned an unexpected Content-Type." >&2
        exit 1
    fi
    printf '%s\t200\t%s bytes\n' "$name" "$(wc -c < "$body")"
}

assert_http "application shell" "$frontend_base/" "text/html"
assert_http "liveness" "$backend_base/up" "text/html|application/json"
assert_http "manifest" "$frontend_base/build/manifest.webmanifest" "application/manifest\\+json"
assert_http "service worker" "$frontend_base/build/sw.js" "application/javascript"

csrf_json="$(curl --fail --silent --show-error \
    --cookie "$cookie_jar" \
    --cookie-jar "$cookie_jar" \
    "$frontend_base/api/v1/auth/csrf")"
csrf_token="$(jq --exit-status --raw-output '.data.token' <<< "$csrf_json")"
login_body="$(jq --null-input --compact-output \
    --arg email "$email" \
    --arg password "$password" \
    '{email: $email, password: $password}')"
login_json="$(curl --fail --silent --show-error \
    --cookie "$cookie_jar" \
    --cookie-jar "$cookie_jar" \
    --header "Content-Type: application/json" \
    --header "X-CSRF-TOKEN: $csrf_token" \
    --data "$login_body" \
    "$frontend_base/api/v1/auth/login")"
read -r organization_id branch_id < <(
    jq --exit-status --raw-output \
        '[.data.organizations[0].id, .data.branches[0].id] | @tsv' \
        <<< "$login_json"
)
printf 'login\t200\n'

tenant_headers=(
    --header "X-Organization-ID: $organization_id"
    --header "X-Branch-ID: $branch_id"
)
for endpoint in \
    "/api/v1/dashboard/summary" \
    "/api/v1/customers?per_page=10" \
    "/api/v1/lots?per_page=10" \
    "/api/v1/purchase-orders?per_page=10" \
    "/api/v1/cash-sessions?per_page=10" \
    "/api/v1/alerts?per_page=10" \
    "/api/v1/notifications?per_page=10"; do
    assert_http "$endpoint" "$frontend_base$endpoint" "application/json" "${tenant_headers[@]}"
done

assert_http \
    "customers XLSX export" \
    "$frontend_base/api/v1/customers?export=xlsx" \
    "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" \
    "${tenant_headers[@]}"

echo "Authenticated Docker smoke passed."
