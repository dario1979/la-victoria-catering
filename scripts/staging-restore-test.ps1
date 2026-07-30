[CmdletBinding()]
param(
    [string] $OutputDirectory = 'storage/app/restore-tests',
    [switch] $KeepEnvironment
)

$ErrorActionPreference = 'Stop'
$engine = Join-Path $PSScriptRoot 'lib/staging-database.php'
$arguments = @($engine, 'restore-test', '--output-dir', $OutputDirectory)
if ($KeepEnvironment) { $arguments += '--keep-environment' }
& php @arguments
if ($LASTEXITCODE -ne 0) { throw "Staging restore test failed with exit code $LASTEXITCODE." }
