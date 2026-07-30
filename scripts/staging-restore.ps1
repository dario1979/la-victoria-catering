[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string] $Manifest,
    [string] $Backup,
    [string] $EnvFile = '.env.staging',
    [string] $ComposeFile = 'compose.staging.yaml',
    [string] $ProjectName,
    [string] $TargetDatabase,
    [string] $TargetEnvironment = 'staging',
    [string] $ReportDirectory,
    [switch] $AllowProduction,
    [switch] $KeepFailedDatabase
)

$ErrorActionPreference = 'Stop'
$engine = Join-Path $PSScriptRoot 'lib/staging-database.php'
$arguments = @(
    $engine, 'restore',
    '--manifest', $Manifest,
    '--env-file', $EnvFile,
    '--compose-file', $ComposeFile,
    '--target-environment', $TargetEnvironment
)
if ($Backup) { $arguments += @('--backup', $Backup) }
if ($ProjectName) { $arguments += @('--project-name', $ProjectName) }
if ($TargetDatabase) { $arguments += @('--target-database', $TargetDatabase) }
if ($ReportDirectory) { $arguments += @('--report-dir', $ReportDirectory) }
if ($AllowProduction) { $arguments += '--allow-production' }
if ($KeepFailedDatabase) { $arguments += '--keep-failed-database' }

& php @arguments
if ($LASTEXITCODE -ne 0) { throw "Staging restore failed with exit code $LASTEXITCODE." }
