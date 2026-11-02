#Requires -RunAsAdministrator
<#
.SYNOPSIS
    Installs RustDesk silently, points it at the owner's own server and turns on
    unattended access.

.PARAMETER Password
    The permanent password for unattended access. Prompted for when left out.
    It is never stored in this file.
#>
param(
    [string]$Password
)

$ErrorActionPreference = 'Stop'

$ServerHost = '{{HOST}}'
$RelayHost = '{{RELAY}}'
$ServerKey = '{{KEY}}'

if (-not $Password) {
    $secure = Read-Host 'Password for unattended access' -AsSecureString
    $Password = [System.Net.NetworkCredential]::new('', $secure).Password
}

if ($Password.Length -lt 8) {
    throw 'Use a password of at least 8 characters.'
}

$release = Invoke-RestMethod 'https://api.github.com/repos/rustdesk/rustdesk/releases/latest' -Headers @{ 'User-Agent' = 'pc-checkup' }
$asset = $release.assets | Where-Object { $_.name -match '^rustdesk-.+-x86_64\.exe$' } | Select-Object -First 1

if (-not $asset) {
    throw 'Could not find the 64-bit RustDesk installer in the latest release.'
}

$installer = Join-Path $env:TEMP $asset.name
Invoke-WebRequest $asset.browser_download_url -OutFile $installer -UseBasicParsing

Write-Host "Installing RustDesk $($release.tag_name)..."
Start-Process $installer -ArgumentList '--silent-install' -Wait

$exe = Join-Path $env:ProgramFiles 'RustDesk\rustdesk.exe'

if (-not (Test-Path $exe)) {
    throw "RustDesk was not found at $exe after installing."
}

& $exe --install-service | Out-Null

for ($i = 0; $i -lt 30 -and -not (Get-Service 'RustDesk' -ErrorAction SilentlyContinue); $i++) {
    Start-Sleep -Seconds 1
}

Stop-Service 'RustDesk' -ErrorAction SilentlyContinue
Get-Process rustdesk -ErrorAction SilentlyContinue | Stop-Process -Force

$config = @"
rendezvous_server = '$ServerHost`:21116'
nat_type = 1
serial = 0

[options]
custom-rendezvous-server = '$ServerHost'
relay-server = '$RelayHost'
key = '$ServerKey'
"@

$folders = @(
    (Join-Path $env:APPDATA 'RustDesk\config'),
    'C:\Windows\ServiceProfiles\LocalService\AppData\Roaming\RustDesk\config'
)

foreach ($folder in $folders) {
    New-Item -ItemType Directory -Force -Path $folder | Out-Null
    Set-Content -Path (Join-Path $folder 'RustDesk2.toml') -Value $config -Encoding ASCII
}

Start-Service 'RustDesk'
Start-Process $exe -ArgumentList '--tray'
Start-Sleep -Seconds 5

& $exe --config "host=$ServerHost,key=$ServerKey,relay=$RelayHost" | Out-Null
& $exe --password $Password | Out-Null

if ($LASTEXITCODE -ne 0) {
    Write-Warning 'RustDesk did not accept the password. Open RustDesk once, then run this script again.'
}

$id = (& $exe --get-id | Out-String).Trim()
Write-Host "RustDesk is installed and points at $ServerHost."
Write-Host "This machine's RustDesk ID: $id"
