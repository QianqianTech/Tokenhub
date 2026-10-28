#requires -Version 5.1
<#
.SYNOPSIS
  TokenHub Docker installer for Windows (Docker Desktop with the WSL 2 backend).

.EXAMPLE
  powershell -ExecutionPolicy Bypass -File .\install.ps1 -Site tokenhub.example.com
  powershell -ExecutionPolicy Bypass -File .\install.ps1 -Site https://10.0.0.8
  powershell -ExecutionPolicy Bypass -File .\install.ps1 -Site 10.0.0.8 -HttpPort 8080 -Build

.NOTES
  Same behaviour as install.sh. Re-running keeps the existing .env and restarts the stack.
#>
[CmdletBinding()]
param(
    [string]$Site,
    [int]$HttpPort = 80,
    [int]$HttpsPort = 443,
    [string]$Bind = '0.0.0.0',
    [switch]$Build,
    [string]$ImagePrefix = '',
    [string]$Tag = '',
    [ValidateSet('qdrant', 'mysql_fallback')][string]$VectorStore = 'qdrant',
    [string]$AdminUser = 'admin',
    [string]$AdminPassword = '',
    [switch]$NoStart,
    [switch]$Yes
)

# Native commands report failures through `$LASTEXITCODE`; 'Stop' would abort on harmless stderr output.
$ErrorActionPreference = 'Continue'
$KitDir = $PSScriptRoot
$EnvFile = Join-Path $KitDir '.env'
$SourceRoot = (Resolve-Path (Join-Path $KitDir '..\..') -ErrorAction SilentlyContinue)

function Info([string]$m) { Write-Host "==> $m" -ForegroundColor Cyan }
function Warn([string]$m) { Write-Host "WARN: $m" -ForegroundColor Yellow }
function Die([string]$m) { Write-Host "ERROR: $m" -ForegroundColor Red; exit 1 }

# Plain function on purpose: an advanced function would bind `-d` to -Debug and reject `-T`.
function Invoke-Compose {
    $Arguments = @($args)
    $files = @('-f', (Join-Path $KitDir 'compose.yml'))
    if ($Build) { $files += @('-f', (Join-Path $KitDir 'compose.build.yml')) }
    & docker compose --project-directory $KitDir --env-file $EnvFile @files @Arguments
    if ($LASTEXITCODE -ne 0) { throw ('docker compose ' + ($Arguments -join ' ') + ' failed') }
}

function New-Secret([int]$Length = 32) {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789'.ToCharArray()
    $bytes = New-Object byte[] ($Length * 2)
    [System.Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($bytes)
    $chars = for ($i = 0; $i -lt $Length; $i++) { $alphabet[$bytes[$i] % $alphabet.Length] }
    -join $chars
}

function Get-EnvValue([string]$Key) {
    if (-not (Test-Path $EnvFile)) { return '' }
    $line = Get-Content $EnvFile | Where-Object { $_ -match ('^' + [regex]::Escape($Key) + '=') } | Select-Object -Last 1
    if ($null -eq $line) { return '' }
    return ($line -replace ('^' + [regex]::Escape($Key) + '='), '').Trim('"')
}

function Set-EnvValue([string]$Key, [string]$Value) {
    $lines = [System.Collections.Generic.List[string]](Get-Content $EnvFile)
    $found = $false
    for ($i = 0; $i -lt $lines.Count; $i++) {
        if ($lines[$i] -match ('^' + [regex]::Escape($Key) + '=')) { $lines[$i] = "$Key=$Value"; $found = $true }
    }
    if (-not $found) { $lines.Add("$Key=$Value") }
    [System.IO.File]::WriteAllLines($EnvFile, $lines, (New-Object System.Text.UTF8Encoding($false)))
}

function Get-ContainerState([string]$Service) {
    $id = (& docker compose --project-directory $KitDir --env-file $EnvFile -f (Join-Path $KitDir 'compose.yml') ps -a -q $Service | Select-Object -First 1)
    if (-not $id) { return 'missing' }
    return (& docker inspect -f '{{.State.Status}} {{.State.ExitCode}} {{if .State.Health}}{{.State.Health.Status}}{{end}}' $id)
}

function Wait-Init([int]$Timeout = 900) {
    Info 'Waiting for the init container (database migrations, first administrator)...'
    $deadline = (Get-Date).AddSeconds($Timeout)
    while ((Get-Date) -lt $deadline) {
        $state = Get-ContainerState 'init'
        if ($state -like 'exited 0*') { Info 'Initialisation finished.'; return }
        if ($state -like 'exited*') { Invoke-Compose logs --no-color --tail=80 init; Die 'Initialisation failed (see the log above).' }
        Start-Sleep -Seconds 5
    }
    Die "Initialisation did not finish within $Timeout seconds."
}

function Wait-Healthy([string]$Service, [int]$Timeout = 300) {
    $deadline = (Get-Date).AddSeconds($Timeout)
    while ((Get-Date) -lt $deadline) {
        $state = Get-ContainerState $Service
        if ($state -like '* healthy') { return }
        if ($state -like 'exited*') { Invoke-Compose logs --no-color --tail=80 $Service; Die "$Service stopped unexpectedly." }
        Start-Sleep -Seconds 3
    }
    Die "$Service did not become healthy within $Timeout seconds."
}

function Get-SiteSettings([string]$InputValue) {
    # Mirrors app/Console/Install/SiteAddress.php and install.sh.
    $result = @{ Address = ':80'; Tls = ''; Global = ''; Url = ''; Secure = 'false' }
    $entries = @($InputValue -split ',' | ForEach-Object { $_.Trim() } | Where-Object { $_ -ne '' })
    if ($entries.Count -eq 0 -or ($entries.Count -eq 1 -and $entries[0] -eq ':80')) {
        $hostName = (Get-NetIPAddress -AddressFamily IPv4 -ErrorAction SilentlyContinue | Where-Object { $_.IPAddress -notlike '127.*' -and $_.IPAddress -notlike '169.254.*' } | Select-Object -First 1).IPAddress
        if (-not $hostName) { $hostName = 'localhost' }
        $result.Url = 'http://' + $hostName + $(if ($HttpPort -ne 80) { ":$HttpPort" } else { '' })
        return $result
    }
    $addresses = New-Object System.Collections.Generic.List[string]
    $ipHttps = $false; $domain = $false; $first = $true
    foreach ($entry in $entries) {
        $scheme = ''
        if ($entry -match '^(https?)://(.+)$') { $scheme = $Matches[1].ToLower(); $entry = $Matches[2] }
        $entry = $entry.TrimEnd('/')
        $hostPart = ($entry -split ':')[0]
        if ($hostPart -match '^\d+(\.\d+){3}$') {
            if (-not $scheme) { $scheme = 'http' }
            if ($scheme -eq 'https') {
                $addresses.Add("https://$entry"); $ipHttps = $true
                if (-not $result.Global) { $result.Global = "default_sni $hostPart" }
            } elseif (-not $addresses.Contains(':80')) { $addresses.Add(':80') }
        } else {
            if ($hostPart -notmatch '^[A-Za-z0-9]([A-Za-z0-9-]*[A-Za-z0-9])?(\.[A-Za-z0-9]([A-Za-z0-9-]*[A-Za-z0-9])?)*$') { Die "Invalid domain or IP: $hostPart" }
            if (-not $scheme) { $scheme = $(if ($hostPart -eq 'localhost') { 'http' } else { 'https' }) }
            if ($scheme -eq 'https') { $addresses.Add($entry); $domain = $true } else { $addresses.Add("http://$entry") }
        }
        if ($first) {
            $first = $false
            if ($scheme -eq 'https') {
                $result.Secure = 'true'
                $result.Url = "https://$hostPart" + $(if ($HttpsPort -ne 443) { ":$HttpsPort" } else { '' })
            } else {
                $result.Url = "http://$hostPart" + $(if ($HttpPort -ne 80) { ":$HttpPort" } else { '' })
            }
        }
    }
    if ($ipHttps -and -not $domain) { $result.Tls = 'tls internal' }
    $result.Address = ($addresses -join ', ')
    return $result
}

# ------------------------------------------------------------------ checks
if (-not (Get-Command docker -ErrorAction SilentlyContinue)) { Die 'Docker Desktop is not installed (https://docs.docker.com/desktop/setup/install/windows-install/).' }
& docker info *> $null
if ($LASTEXITCODE -ne 0) { Die 'Docker Desktop is not running. Start it and wait until the engine is running.' }
$composeVersion = (& docker compose version --short) -replace '^v', ''
if ($LASTEXITCODE -ne 0) { Die 'Docker Compose v2 is required.' }
if ([version]($composeVersion -replace '[^0-9.].*$', '') -lt [version]'2.20') { Die "Docker Compose $composeVersion is too old; 2.20 or newer is required." }
if ($Build -and -not (Test-Path (Join-Path $KitDir '..\..\docker\release\php\Dockerfile'))) { Die '-Build needs the full TokenHub source tree.' }

# ------------------------------------------------------------------ .env
if (Test-Path $EnvFile) {
    Info "Using existing $EnvFile (delete it to start over with new secrets)."
} else {
    $template = Join-Path $KitDir '.env.example'
    if (-not (Test-Path $template) -and $SourceRoot) { $template = Join-Path $SourceRoot '.env.example' }
    if (-not (Test-Path $template)) { Die '.env.example not found.' }
    if (-not $PSBoundParameters.ContainsKey('Site') -and -not $Yes) {
        Write-Host 'How will users reach TokenHub?'
        Write-Host '  - a domain (tokenhub.example.com) gets an automatic HTTPS certificate'
        Write-Host '  - https://<IP> uses HTTPS with a self-signed internal CA'
        Write-Host '  - <IP> or empty uses plain HTTP'
        $Site = Read-Host 'Domain or IP (several separated by commas)'
    }
    $s = Get-SiteSettings $Site
    if (-not $Tag) {
        $versionFile = Join-Path $KitDir '..\..\VERSION'
        if (-not (Test-Path $versionFile)) { $versionFile = Join-Path $KitDir 'VERSION' }
        $Tag = $(if (Test-Path $versionFile) { (Get-Content $versionFile -Raw).Trim() } else { 'local' })
    }
    if (-not $AdminPassword) { $AdminPassword = New-Secret 20 }
    if ($AdminPassword.Length -lt 12) { Die 'The administrator password must have at least 12 characters.' }
    if ($AdminPassword -match '[\$#''"\\\s]') { Die 'The administrator password must not contain $ # quotes, backslashes or spaces (they break .env parsing).' }

    Copy-Item $template $EnvFile
    $values = [ordered]@{
        APP_ENV = 'production'; APP_DEBUG = 'false'; APP_KEY = (New-Secret 48); ADMIN_CSRF_SECRET = (New-Secret 40); REALTIME_INTERNAL_TOKEN = (New-Secret 48)
        APP_URL = $s.Url; SESSION_SECURE = $s.Secure
        TOKENHUB_SITE_ADDRESS = $s.Address; TOKENHUB_CADDY_TLS = $s.Tls; TOKENHUB_CADDY_GLOBAL = $s.Global
        TOKENHUB_BIND_ADDRESS = $Bind; TOKENHUB_HTTP_PORT = "$HttpPort"; TOKENHUB_HTTPS_PORT = "$HttpsPort"
        TOKENHUB_IMAGE_PREFIX = $ImagePrefix; TOKENHUB_IMAGE_TAG = $Tag
        TOKENHUB_ADMIN_USERNAME = $AdminUser; TOKENHUB_ADMIN_PASSWORD = $AdminPassword
        DB_HOST = 'mysql'; DB_PASSWORD = (New-Secret 32); DB_ROOT_PASSWORD = (New-Secret 32)
        REDIS_HOST = 'redis'; REDIS_PASSWORD = (New-Secret 32)
        QDRANT_URL = 'http://qdrant:6333'; QDRANT_API_KEY = (New-Secret 32); RAG_VECTOR_STORE = $VectorStore
        CADDY_VALIDATE_COMMAND = ''; CADDY_RELOAD_COMMAND = ''
    }
    foreach ($key in $values.Keys) { Set-EnvValue $key $values[$key] }
    Info "Wrote $EnvFile with random secrets. Keep a copy of APP_KEY in a safe place."
}

if ($NoStart) { exit 0 }

# ------------------------------------------------------------------ images and start
$tagNow = Get-EnvValue 'TOKENHUB_IMAGE_TAG'
if ($Build) {
    Info 'Building images from source (first build takes several minutes)...'
    Invoke-Compose build
} elseif (Get-EnvValue 'TOKENHUB_IMAGE_PREFIX') {
    Info 'Pulling images...'
    Invoke-Compose pull
} else {
    foreach ($image in @('tokenhub-php', 'tokenhub-caddy', 'tokenhub-rag-worker')) {
        & docker image inspect "${image}:$tagNow" *> $null
        if ($LASTEXITCODE -ne 0) { Die "Image ${image}:$tagNow not found. Use -Build, -ImagePrefix or load the offline bundle first." }
    }
}

Info 'Starting TokenHub...'
Invoke-Compose up -d --remove-orphans
Wait-Init 900
Info 'Waiting for the web services...'
Wait-Healthy 'php' 300
Wait-Healthy 'caddy' 120
Info 'Self-check:'
try { Invoke-Compose exec -T php php bin/doctor.php } catch { Warn 'Some checks reported problems (see above).' }

$url = (Get-EnvValue 'APP_URL').TrimEnd('/')
Write-Host ''
Write-Host 'TokenHub is running.'
Write-Host "  Admin console : $url/admin/"
Write-Host "  API base URL  : $url/v1"
Write-Host "  Administrator : $(Get-EnvValue 'TOKENHUB_ADMIN_USERNAME')"
Write-Host "  Password      : $(Get-EnvValue 'TOKENHUB_ADMIN_PASSWORD')"
Write-Host ''
Write-Host "Next: log in, change the password, then remove TOKENHUB_ADMIN_PASSWORD from $EnvFile and back up the file."
