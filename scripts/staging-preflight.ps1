[CmdletBinding()]
param(
    [ValidateSet('structural', 'pre-migrate', 'runtime')]
    [string] $Mode = 'runtime',
    [string] $EnvFile = '.env.staging',
    [string] $ComposeFile = 'compose.staging.yaml'
)

$ErrorActionPreference = 'Stop'
$exampleEnvFile = '.env.staging.example'

function Invoke-Compose {
    param([string[]] $ComposeArguments)

    & docker compose @script:composeArgs @ComposeArguments
    $code = $LASTEXITCODE
    if ($code -ne 0) {
        throw "Staging preflight failed with exit code $code."
    }
}

if (-not (Test-Path -LiteralPath $EnvFile)) {
    if (-not (Test-Path -LiteralPath $exampleEnvFile)) {
        throw "Missing both $EnvFile and $exampleEnvFile."
    }

    $placeholders = @{
        APP_KEY = 'base64:MDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDA='
        APP_URL = 'https://staging.example.com'
        DB_DATABASE = 'structural_staging'
        DB_USERNAME = 'structural_staging'
        DB_PASSWORD = 'structural-database-placeholder'
        REDIS_PASSWORD = 'structural-redis-placeholder'
        SESSION_DOMAIN = 'staging.example.com'
        MAIL_FROM_ADDRESS = 'no-reply@staging.example.com'
        DEMO_USER_PASSWORD = 'structural-demo-placeholder'
    }
    $previous = @{}

    try {
        foreach ($entry in $placeholders.GetEnumerator()) {
            $previous[$entry.Key] = [Environment]::GetEnvironmentVariable($entry.Key, 'Process')
            [Environment]::SetEnvironmentVariable($entry.Key, $entry.Value, 'Process')
        }
        $previous['STAGING_ENV_FILE'] = [Environment]::GetEnvironmentVariable('STAGING_ENV_FILE', 'Process')
        $resolvedExample = (Resolve-Path -LiteralPath $exampleEnvFile).Path
        [Environment]::SetEnvironmentVariable('STAGING_ENV_FILE', $resolvedExample, 'Process')
        $script:composeArgs = @('--env-file', $resolvedExample, '-f', $ComposeFile)

        Invoke-Compose -ComposeArguments @('config', '--quiet') | Out-Null
    } finally {
        foreach ($entry in $previous.GetEnumerator()) {
            [Environment]::SetEnvironmentVariable($entry.Key, $entry.Value, 'Process')
        }
    }

    Write-Output 'staging.compose.structural PASS'
    Write-Output "staging.runtime BLOCKED: falta $EnvFile; complete secretos fuera de Git antes de construir, migrar o exponer servicios."
    exit 2
}

$resolvedEnvFile = (Resolve-Path -LiteralPath $EnvFile).Path
$env:STAGING_ENV_FILE = $resolvedEnvFile
$script:composeArgs = @('--env-file', $resolvedEnvFile, '-f', $ComposeFile)
Invoke-Compose -ComposeArguments @('config', '--quiet') | Out-Null
Write-Output 'staging.compose.structural PASS'

switch ($Mode) {
    'structural' {
        Write-Output 'staging.runtime NOT_RUN'
    }
    'pre-migrate' {
        Invoke-Compose -ComposeArguments @(
            'run', '--rm', '--no-deps',
            '-e', 'RUN_MIGRATIONS=false',
            'backend',
            'php', 'artisan', 'bakery:staging-preflight',
            '--phase=pre-migrate',
            '--allow-pending-migrations',
            '--format=json'
        )
    }
    'runtime' {
        Invoke-Compose -ComposeArguments @(
            'exec', '-T', 'backend',
            'php', 'artisan', 'bakery:staging-preflight',
            '--phase=runtime',
            '--format=json'
        )
    }
}
