[CmdletBinding()]
param(
    [string] $EnvFile = '.env.staging',
    [string] $ComposeFile = 'compose.staging.yaml',
    [string] $OutputDirectory = 'storage/app/backups',
    [string] $ProjectName,
    [string] $DeployedSha
)

$ErrorActionPreference = 'Stop'
$engine = Join-Path $PSScriptRoot 'lib/staging-database.php'
$arguments = @($engine, 'backup', '--env-file', $EnvFile, '--compose-file', $ComposeFile, '--output-dir', $OutputDirectory)
if ($ProjectName) { $arguments += @('--project-name', $ProjectName) }
if ($DeployedSha) { $arguments += @('--deployed-sha', $DeployedSha) }

& php @arguments
if ($LASTEXITCODE -ne 0) { throw "Staging backup failed with exit code $LASTEXITCODE." }
