[CmdletBinding()]
param(
    [ValidateSet('config', 'build', 'up', 'status', 'logs', 'seed-demo', 'rollback')]
    [string] $Action = 'up',
    [string] $EnvFile = '.env.staging',
    [string] $ImageTag,
    [switch] $AllowDemoSeed
)

$ErrorActionPreference = 'Stop'

if (-not (Test-Path -LiteralPath $EnvFile)) {
    throw "Missing staging environment file: $EnvFile. Copy .env.staging.example and provide secrets outside Git."
}

$resolvedEnvFile = (Resolve-Path -LiteralPath $EnvFile).Path
$env:STAGING_ENV_FILE = $resolvedEnvFile
$composeArgs = @('--env-file', $resolvedEnvFile, '-f', 'compose.staging.yaml')

if ($ImageTag) {
    $env:STAGING_IMAGE_TAG = $ImageTag
} elseif (-not $env:STAGING_IMAGE_TAG) {
    $env:STAGING_IMAGE_TAG = (git rev-parse --short=12 HEAD).Trim()
}

function Invoke-StagingCompose {
    param([Parameter(ValueFromRemainingArguments = $true)][string[]] $Arguments)

    & docker compose @composeArgs @Arguments
    if ($LASTEXITCODE -ne 0) {
        throw "docker compose failed with exit code $LASTEXITCODE."
    }
}

switch ($Action) {
    'config' {
        Invoke-StagingCompose config --quiet
    }
    'build' {
        Invoke-StagingCompose build --pull
    }
    'up' {
        Invoke-StagingCompose config --quiet
        Invoke-StagingCompose build --pull
        Invoke-StagingCompose up -d --remove-orphans
        Set-Content -LiteralPath '.staging-release' -Value $env:STAGING_IMAGE_TAG
        Invoke-StagingCompose ps
    }
    'status' {
        Invoke-StagingCompose ps
    }
    'logs' {
        Invoke-StagingCompose logs --tail=200
    }
    'seed-demo' {
        if (-not $AllowDemoSeed) {
            throw 'Demo data is opt-in. Re-run with -AllowDemoSeed after confirming this is an isolated non-production environment.'
        }
        Invoke-StagingCompose exec -T backend php artisan db:seed --force
    }
    'rollback' {
        if (-not $ImageTag) {
            throw 'Rollback requires -ImageTag with a previously built immutable image tag.'
        }
        Invoke-StagingCompose up -d --no-build --remove-orphans
        Set-Content -LiteralPath '.staging-release' -Value $env:STAGING_IMAGE_TAG
        Invoke-StagingCompose ps
    }
}
