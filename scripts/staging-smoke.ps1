[CmdletBinding()]
param(
    [string] $BaseUrl = 'http://127.0.0.1:8081',
    [System.Management.Automation.PSCredential] $Credential
)

$ErrorActionPreference = 'Stop'
$base = $BaseUrl.TrimEnd('/')
$session = New-Object Microsoft.PowerShell.Commands.WebRequestSession

if (-not $Credential) {
    if (-not $env:STAGING_SMOKE_EMAIL -or -not $env:STAGING_SMOKE_PASSWORD) {
        throw 'Provide -Credential or the ephemeral STAGING_SMOKE_EMAIL and STAGING_SMOKE_PASSWORD environment variables.'
    }

    $securePassword = ConvertTo-SecureString $env:STAGING_SMOKE_PASSWORD -AsPlainText -Force
    $Credential = New-Object System.Management.Automation.PSCredential($env:STAGING_SMOKE_EMAIL, $securePassword)
}

function Assert-HttpOk {
    param(
        [string] $Name,
        [string] $Uri,
        [hashtable] $Headers = @{}
    )

    $response = Invoke-WebRequest -Uri $Uri -WebSession $session -Headers $Headers -UseBasicParsing
    if ($response.StatusCode -ne 200) {
        throw "$Name returned HTTP $($response.StatusCode)."
    }
    Write-Output ("{0}`t{1}`t{2}" -f $Name, $response.StatusCode, $response.Headers['Content-Type'])

    return $response
}

Assert-HttpOk -Name 'frontend health' -Uri "$base/healthz" | Out-Null
Assert-HttpOk -Name 'application shell' -Uri "$base/" | Out-Null
Assert-HttpOk -Name 'manifest' -Uri "$base/build/manifest.webmanifest" | Out-Null
Assert-HttpOk -Name 'service worker' -Uri "$base/build/sw.js" | Out-Null

$csrfResponse = Assert-HttpOk -Name 'csrf' -Uri "$base/api/v1/auth/csrf"
$csrf = ($csrfResponse.Content | ConvertFrom-Json).data.token
$plainPassword = $Credential.GetNetworkCredential().Password
$loginBody = @{
    email = $Credential.UserName
    password = $plainPassword
} | ConvertTo-Json

$loginResponse = Invoke-WebRequest `
    -Uri "$base/api/v1/auth/login" `
    -Method Post `
    -WebSession $session `
    -Headers @{ 'X-CSRF-TOKEN' = $csrf } `
    -ContentType 'application/json' `
    -Body $loginBody `
    -UseBasicParsing

if ($loginResponse.StatusCode -ne 200) {
    throw "login returned HTTP $($loginResponse.StatusCode)."
}
Write-Output ("login`t{0}`t{1}" -f $loginResponse.StatusCode, $loginResponse.Headers['Content-Type'])

$login = $loginResponse.Content | ConvertFrom-Json
$organizationId = $login.data.organizations[0].id
$branchId = $login.data.branches[0].id
$tenantHeaders = @{
    'X-Organization-ID' = $organizationId
    'X-Branch-ID' = $branchId
}

Assert-HttpOk -Name 'dashboard' -Uri "$base/api/v1/dashboard/summary" -Headers $tenantHeaders | Out-Null
Assert-HttpOk -Name 'customers' -Uri "$base/api/v1/customers?per_page=10" -Headers $tenantHeaders | Out-Null
Assert-HttpOk -Name 'lots' -Uri "$base/api/v1/lots?per_page=10" -Headers $tenantHeaders | Out-Null
Assert-HttpOk -Name 'alerts' -Uri "$base/api/v1/alerts?per_page=10" -Headers $tenantHeaders | Out-Null

Write-Output 'Staging smoke test passed.'
