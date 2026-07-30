[CmdletBinding()]
param(
    [ValidateSet('config', 'preflight', 'build', 'up', 'status', 'logs', 'seed-demo', 'rollback')]
    [string] $Action = 'up',
    [string] $EnvFile = '.env.staging',
    [string] $ImageTag,
    [switch] $AllowDemoSeed
)

$ErrorActionPreference = 'Stop'

if (-not (Test-Path -LiteralPath $EnvFile)) {
    & "$PSScriptRoot/staging-preflight.ps1" -Mode structural -EnvFile $EnvFile
    exit $LASTEXITCODE
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

function Invoke-StagingPreflight {
    param(
        [ValidateSet('structural', 'pre-migrate', 'runtime')]
        [string] $Mode
    )

    & "$PSScriptRoot/staging-preflight.ps1" -Mode $Mode -EnvFile $resolvedEnvFile
    if ($LASTEXITCODE -ne 0) {
        throw "Staging $Mode preflight failed with exit code $LASTEXITCODE."
    }
}

function Wait-StagingReadiness {
    for ($attempt = 1; $attempt -le 18; $attempt++) {
        & docker compose @composeArgs exec -T backend `
            curl --fail --silent --show-error http://127.0.0.1:8000/health/ready 2>$null | Out-Null
        if ($LASTEXITCODE -eq 0) {
            Write-Output 'staging.readiness PASS'

            return
        }

        Start-Sleep -Seconds 5
    }

    throw 'Staging readiness did not pass within 90 seconds; frontend remains unexposed.'
}

function Start-StagingRelease {
    param([switch] $SkipBuild)

    Invoke-StagingPreflight -Mode structural
    if (-not $SkipBuild) {
        Invoke-StagingCompose build --pull
    }

    Invoke-StagingCompose up -d postgres redis mailpit --wait --wait-timeout 120
    Invoke-StagingPreflight -Mode pre-migrate
    Invoke-StagingCompose up -d backend queue scheduler --wait --wait-timeout 180
    Invoke-StagingPreflight -Mode runtime
    Wait-StagingReadiness
    Invoke-StagingCompose up -d frontend --wait --wait-timeout 120 --remove-orphans
    Set-Content -LiteralPath '.staging-release' -Value $env:STAGING_IMAGE_TAG
    Invoke-StagingCompose ps
}

switch ($Action) {
    'config' {
        Invoke-StagingPreflight -Mode structural
    }
    'preflight' {
        Invoke-StagingPreflight -Mode runtime
    }
    'build' {
        Invoke-StagingCompose build --pull
    }
    'up' {
        Start-StagingRelease
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
        Start-StagingRelease -SkipBuild
    }
}
