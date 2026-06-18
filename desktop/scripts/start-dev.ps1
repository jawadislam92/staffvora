[CmdletBinding()]
param(
    [string] $ServerUrl = 'http://timetracker.test',
    [switch] $SkipMigrate,
    [switch] $SkipRebuild
)

$ErrorActionPreference = 'Stop'

$desktopRoot = Resolve-Path (Join-Path $PSScriptRoot '..')
$webRoot = Resolve-Path (Join-Path $desktopRoot '..')
$serverUrl = $ServerUrl.TrimEnd('/')

if (-not (Test-Path (Join-Path $webRoot 'artisan'))) {
    throw "Could not find Laravel artisan at $webRoot"
}

if (-not (Test-Path (Join-Path $desktopRoot 'package.json'))) {
    throw "Could not find desktop package.json at $desktopRoot"
}

Write-Host "Timetracker desktop dev startup" -ForegroundColor Cyan
Write-Host "Web app:  $webRoot"
Write-Host "Desktop:  $desktopRoot"
Write-Host "Server:   $serverUrl"
Write-Host ''

if (-not $SkipMigrate) {
    Write-Host "Running Laravel migrations..." -ForegroundColor Cyan
    Push-Location $webRoot
    try {
        php artisan migrate
    } finally {
        Pop-Location
    }
    Write-Host ''
} else {
    Write-Host "Skipping Laravel migrations." -ForegroundColor Yellow
}

Write-Host "Checking server URL..." -ForegroundColor Cyan
try {
    Invoke-WebRequest -Uri "$serverUrl/login" -UseBasicParsing -TimeoutSec 10 | Out-Null
    Write-Host "Server is reachable." -ForegroundColor Green
} catch {
    Write-Warning "Could not reach $serverUrl/login. Make sure Laragon is running and the site URL is correct."
}
Write-Host ''

Push-Location $desktopRoot
try {
    if (-not $SkipRebuild) {
        Write-Host "Rebuilding Electron native modules..." -ForegroundColor Cyan
        npm run rebuild
        Write-Host ''
    } else {
        Write-Host "Skipping native rebuild." -ForegroundColor Yellow
    }

    Write-Host "Starting desktop app..." -ForegroundColor Cyan
    npm run dev
} finally {
    Pop-Location
}
