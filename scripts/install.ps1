$ErrorActionPreference = 'Stop'

$repoDir = Split-Path -Parent $PSScriptRoot
Set-Location $repoDir

if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
    throw 'Docker is required.'
}

if (-not (Test-Path .env)) {
    Copy-Item .env.example .env
}

$envContent = Get-Content -Raw .env
if ($envContent -notmatch '(?m)^APP_KEY=base64:') {
    $appKey = docker run --rm php:8.4-cli-alpine php -r 'echo "base64:".base64_encode(random_bytes(32));'
    $envContent = $envContent -replace '(?m)^APP_KEY=.*$', "APP_KEY=$appKey"
    Set-Content -Path .env -Value $envContent -NoNewline
}

docker compose build
docker compose up -d postgres redis mailpit
docker compose run --rm backend php artisan migrate --force

Write-Host 'Installation complete. Start all services with: docker compose up'
