[CmdletBinding()]
param(
    [ValidateRange(1024, 65535)][int]$WebPort = 8001,
    [ValidateRange(1024, 65535)][int]$AiPort = 8000,
    [switch]$SkipInstall,
    [switch]$NoBrowser
)

$ErrorActionPreference = 'Stop'
$OutputEncoding = [System.Text.UTF8Encoding]::new($false)
[Console]::OutputEncoding = $OutputEncoding
$env:PYTHONUTF8 = '1'
$env:PYTHONIOENCODING = 'utf-8'
$projectRoot = $PSScriptRoot
$children = @()
Set-Location -LiteralPath $projectRoot

function Invoke-ProjectCommand {
    param([string]$Program, [string[]]$Arguments)
    & $Program @Arguments
    if ($LASTEXITCODE -ne 0) {
        throw "$Program failed (exit $LASTEXITCODE)."
    }
}

function Invoke-ProjectCode {
    param([string]$Program, [string[]]$Arguments, [string]$Code)
    $Code | & $Program @Arguments
    if ($LASTEXITCODE -ne 0) { throw "$Program runtime check failed (exit $LASTEXITCODE)." }
}

function Assert-FreePort {
    param([int]$Port)
    $listener = [System.Net.Sockets.TcpListener]::new([System.Net.IPAddress]::Loopback, $Port)
    try { $listener.Start() }
    catch { throw "Port $Port is occupied. Stop the old server or choose another port." }
    finally { $listener.Stop() }
}

try {
    if ($env:OS -ne 'Windows_NT') { throw 'This launcher requires Windows.' }
    if ($WebPort -eq $AiPort) { throw 'WebPort and AiPort must be different.' }
    Assert-FreePort $WebPort
    Assert-FreePort $AiPort

    foreach ($tool in @('php', 'composer', 'node', 'npm')) {
        if (-not (Get-Command $tool -ErrorAction SilentlyContinue)) {
            throw "Install $tool and add it to PATH, then run this file again. See RUN_PROJECT.md."
        }
    }
    Invoke-ProjectCode -Program 'php' -Arguments @() -Code '<?php if (PHP_VERSION_ID < 80300) { file_put_contents("php://stderr", "PHP 8.3+ required.\n"); exit(1); } $required = ["curl", "dom", "fileinfo", "mbstring", "openssl", "pdo", "xml", "xmlwriter", "zip", "gd"]; foreach ($required as $ext) { if (!extension_loaded($ext)) { file_put_contents("php://stderr", "Enable PHP extension: ".$ext."\n"); exit(1); } }'
    Invoke-ProjectCode -Program 'node' -Arguments @() -Code 'const [a,b]=process.versions.node.split(".").map(Number); if (!((a===20 && b>=19)||(a>=22 && (a!==22 || b>=12)))) { console.error("Node 20.19+ or 22.12+ required (Node 21 unsupported)."); process.exit(1); }'

    $pythonCommand = 'py'
    $pythonPrefix = @('-3.12')
    if (-not (Get-Command 'py' -ErrorAction SilentlyContinue)) {
        $pythonCommand = 'python'
        $pythonPrefix = @()
    }
    Invoke-ProjectCode -Program $pythonCommand -Arguments ($pythonPrefix + @('-')) -Code 'import sys,struct; assert sys.version_info[:2]==(3,12) and struct.calcsize("P")==8, "Install Python 3.12 x64"'

    $envPath = Join-Path $projectRoot '.env'
    if (-not (Test-Path -LiteralPath $envPath)) {
        Copy-Item -LiteralPath (Join-Path $projectRoot '.env.example') -Destination $envPath
    }
    $settings = @{}
    foreach ($line in Get-Content -LiteralPath $envPath) {
        if ($line -match '^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*?)\s*$') {
            $settings[$matches[1]] = $matches[2].Trim().Trim('"').Trim("'")
        }
    }
    if ($settings['APP_ENV'] -eq 'production' -or $env:APP_ENV -eq 'production') {
        throw 'This launcher is for local development, not production.'
    }

    # Only OCR variables are copied to the AI process; do not print credentials.
    foreach ($key in $settings.Keys) {
        if ($key -match '^(OCR_PROVIDER|AI_OCR_PROVIDER_STATE_PATH|AI_MAX_CONCURRENT_JOBS|AI_LOCAL_OCR_WORKERS|FPT_AI_READER_.*|VNPT_EKYC_OCR_.*|EKYC_OCR_.*)$') {
            if ($null -eq [Environment]::GetEnvironmentVariable($key, 'Process')) {
                [Environment]::SetEnvironmentVariable($key, $settings[$key], 'Process')
            }
        }
    }
    $env:APP_URL = "http://127.0.0.1:$WebPort"
    $env:AI_ENGINE_URL = "http://127.0.0.1:$AiPort"
    $env:APP_FORCE_HTTPS = 'false'
    $env:SESSION_SECURE_COOKIE = 'false'
    if (-not $env:AI_MAX_CONCURRENT_JOBS) { $env:AI_MAX_CONCURRENT_JOBS = '2' }
    if (-not $env:AI_LOCAL_OCR_WORKERS) { $env:AI_LOCAL_OCR_WORKERS = '2' }

    $dbConnection = $env:DB_CONNECTION
    if (-not $dbConnection) { $dbConnection = $settings['DB_CONNECTION'] }
    if (-not $dbConnection -or $dbConnection -eq 'sqlite') {
        Invoke-ProjectCode -Program 'php' -Arguments @() -Code '<?php if (!extension_loaded("pdo_sqlite")) { file_put_contents("php://stderr", "Enable pdo_sqlite.\n"); exit(1); }'
        $databasePath = $env:DB_DATABASE
        if (-not $databasePath) { $databasePath = $settings['DB_DATABASE'] }
        if (-not $databasePath) { $databasePath = Join-Path $projectRoot 'database\database.sqlite' }
        if ($databasePath -ne ':memory:') {
            if (-not [System.IO.Path]::IsPathRooted($databasePath)) { $databasePath = Join-Path $projectRoot $databasePath }
            if (-not (Test-Path -LiteralPath $databasePath)) {
                New-Item -ItemType File -Path $databasePath -Force | Out-Null
            }
        } else { throw 'A persistent SQLite database is required; do not use :memory:.' }
        $env:DB_CONNECTION = 'sqlite'
        $env:DB_DATABASE = $databasePath
    }

    $python = Join-Path $projectRoot 'ai-engine\.venv-windows\Scripts\python.exe'
    if (-not $SkipInstall) {
        Write-Host '[1/4] Installing PHP packages from composer.lock...'
        Invoke-ProjectCommand 'composer' @('install', '--prefer-dist', '--no-interaction')
        Invoke-ProjectCommand 'composer' @('check-platform-reqs', '--no-interaction')
        Write-Host '[2/4] Installing frontend packages and building assets...'
        if (Test-Path -LiteralPath 'package-lock.json') { Invoke-ProjectCommand 'npm' @('ci') }
        else { Invoke-ProjectCommand 'npm' @('install') }
        Invoke-ProjectCommand 'npm' @('run', 'build')
        # A stale Vite dev-server marker would override the freshly built assets.
        if (Test-Path -LiteralPath 'public\hot') { Remove-Item -LiteralPath 'public\hot' }
        Write-Host '[3/4] Installing Python CPU dependencies into a separate Windows venv...'
        if (-not (Test-Path -LiteralPath $python)) {
            Invoke-ProjectCommand $pythonCommand ($pythonPrefix + @('-m', 'venv', 'ai-engine\.venv-windows'))
        }
        Invoke-ProjectCommand $python @('-m', 'pip', 'install', '--upgrade', 'pip', 'setuptools==80.10.2', 'wheel')
        Invoke-ProjectCommand $python @('-m', 'pip', 'install', '-r', 'requirement.txt')
        Invoke-ProjectCommand $python @('-m', 'pip', 'check')
    } else {
        foreach ($path in @('vendor\autoload.php', 'public\build\manifest.json', $python)) {
            if (-not (Test-Path -LiteralPath $path)) { throw "Missing $path. Run without -SkipInstall first." }
        }
        if (Test-Path -LiteralPath 'public\hot') { Remove-Item -LiteralPath 'public\hot' }
    }

    Write-Host '[4/4] Preparing database and starting services...'
    Invoke-ProjectCommand 'php' @('artisan', 'config:clear', '--no-interaction')
    if (-not $settings['APP_KEY'] -and -not $env:APP_KEY) {
        Invoke-ProjectCommand 'php' @('artisan', 'key:generate', '--no-interaction')
    }
    Invoke-ProjectCommand 'php' @('artisan', 'migrate', '--no-interaction')
    $userCount = Invoke-ProjectCode -Program 'php' -Arguments @() -Code '<?php require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo App\Models\User::query()->count();'
    if (($userCount | Out-String).Trim() -eq '0') {
        Invoke-ProjectCommand 'php' @('artisan', 'db:seed', '--no-interaction')
        Write-Host 'Demo accounts created. Credentials are listed in RUN_PROJECT.md.'
    }

    $php = (Get-Command 'php').Source
    $children += Start-Process -FilePath $python -ArgumentList @('-m', 'uvicorn', 'app:app', '--host', '127.0.0.1', '--port', "$AiPort") -WorkingDirectory (Join-Path $projectRoot 'ai-engine') -NoNewWindow -PassThru
    $children += Start-Process -FilePath $php -ArgumentList @('artisan', 'serve', '--host=127.0.0.1', "--port=$WebPort", '--tries=1', '--no-reload', '--no-interaction') -WorkingDirectory $projectRoot -NoNewWindow -PassThru
    $children += Start-Process -FilePath $php -ArgumentList @('artisan', 'schedule:work', '--no-interaction') -WorkingDirectory $projectRoot -NoNewWindow -PassThru

    $ready = $false
    for ($attempt = 0; $attempt -lt 90; $attempt++) {
        foreach ($child in $children) {
            if ($child.HasExited) { throw "Service PID $($child.Id) exited. Read its output above." }
        }
        try {
            $health = Invoke-RestMethod -Uri "$env:AI_ENGINE_URL/health" -TimeoutSec 2
            $web = Invoke-WebRequest -Uri "$env:APP_URL/up" -UseBasicParsing -TimeoutSec 2
            if ($health.status -eq 'ok' -and $web.StatusCode -eq 200) { $ready = $true; break }
        } catch { Start-Sleep -Seconds 2 }
    }
    if (-not $ready) { throw 'Services did not become ready within the startup timeout.' }
    Write-Host "Ready: $env:APP_URL -- Ctrl+C stops the services launched by this script."
    Write-Host 'OCR models download on first use. YuNet face crop needs the optional model described in RUN_PROJECT.md.'
    if (-not $NoBrowser) { Start-Process $env:APP_URL }
    while ($true) {
        foreach ($child in $children) {
            if ($child.HasExited) { throw "Service PID $($child.Id) stopped. Read its output above." }
        }
        Start-Sleep -Seconds 2
    }
} catch {
    Write-Host "ERROR: $($_.Exception.Message)" -ForegroundColor Red
    exit 1
} finally {
    foreach ($child in $children) {
        try {
            if (-not $child.HasExited) { & taskkill.exe /PID $child.Id /T /F 2>$null | Out-Null }
        } catch {
            Write-Host "Could not stop service PID $($child.Id); close its process manually."
        }
    }
}
