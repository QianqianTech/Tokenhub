#requires -Version 5.1
<#
.SYNOPSIS
Download TokenHub from GitHub and install its production Docker stack.
.EXAMPLE
powershell -ExecutionPolicy Bypass -File .\install.ps1 -Site tokenhub.example.com
.EXAMPLE
powershell -ExecutionPolicy Bypass -File .\install.ps1 -DownloadOnly -Ref main
#>
[CmdletBinding()]
param(
    [string]$Directory = (Join-Path (Get-Location) 'tokenhub'),
    [string]$Ref = 'main',
    [switch]$DownloadOnly,
    [switch]$Check,
    [string]$Site,
    [ValidateRange(1, 65535)][int]$HttpPort = 80,
    [ValidateRange(1, 65535)][int]$HttpsPort = 443,
    [string]$Bind = '0.0.0.0',
    [string]$AdminUser = 'admin',
    [ValidateSet('qdrant', 'mysql_fallback')][string]$VectorStore = 'qdrant',
    [switch]$NoStart,
    [switch]$Yes,
    [switch]$Help
)

$ErrorActionPreference = 'Stop'
$Repository = 'https://github.com/QianqianTech/Tokenhub.git'
$Staging = $null

function Invoke-Git {
    # Windows PowerShell 5.1 can turn normal Git stderr progress into an error.
    $saved = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        & git @args
        if ($LASTEXITCODE -ne 0) { throw "Git failed (exit $LASTEXITCODE). Check the ref, GitHub connectivity and directory permissions." }
    } finally { $ErrorActionPreference = $saved }
}

try {
    if ($Help) {
        Write-Output @'
TokenHub GitHub installer for Windows PowerShell 5.1+ / PowerShell 7
  -Directory PATH   New source/install directory (default: .\tokenhub)
  -Ref REF          GitHub branch, tag or full commit SHA (default: main)
  -DownloadOnly     Download source; Docker is not required
  -Check            Only check prerequisites, without downloading or installing
  -Site ADDRESS     Domain, https://IP or http://IP
  -HttpPort PORT    Host HTTP port (default: 80)
  -HttpsPort PORT   Host HTTPS port (default: 443)
  -Bind ADDRESS    Bind address (default: 0.0.0.0)
  -AdminUser NAME  First administrator (default: admin)
  -VectorStore     qdrant (default) or mysql_fallback
  -NoStart         Download source and generate .env only
  -Yes             Non-interactive installation

Install Git and Docker Desktop (Linux containers / WSL 2) first.
Requires Docker Engine 24+ and Compose 2.20+ (or newer).
The destination must not already exist. This script does not upgrade an
existing installation or install Docker.
Upgrades: https://github.com/QianqianTech/Tokenhub/blob/main/docs/deployment/upgrade.md
'@
        exit 0
    }
    if (-not (Get-Command git -ErrorAction SilentlyContinue)) { throw 'Install Git first: https://git-scm.com/downloads' }
    if ([string]::IsNullOrWhiteSpace($Ref) -or $Ref.StartsWith('-')) { throw 'Invalid Git ref.' }
    Invoke-Git check-ref-format "refs/heads/$Ref"
    if (-not $DownloadOnly) {
        if (-not (Get-Command docker -ErrorAction SilentlyContinue)) { throw 'Install and start Docker Desktop first: https://docs.docker.com/desktop/setup/install/windows-install/' }
        $osType = & docker info --format '{{.OSType}}' 2>$null
        if ($LASTEXITCODE -ne 0) { throw 'Docker is not running or is inaccessible. Start Docker Desktop and check docker info.' }
        if ($osType -ne 'linux') { throw 'Switch Docker Desktop to Linux containers.' }
        $engine = & docker version --format '{{.Server.Version}}'
        if ($LASTEXITCODE -ne 0 -or $engine -notmatch '^(\d+)\.' -or [int]$Matches[1] -lt 24) { throw 'Docker Engine 24+ is required.' }
        $composeVersion = & docker compose version --short
        if ($LASTEXITCODE -ne 0 -or $composeVersion -notmatch '^v?(\d+)\.(\d+)') { throw 'Docker Compose 2.20+ is required.' }
        if ([int]$Matches[1] -lt 2 -or ([int]$Matches[1] -eq 2 -and [int]$Matches[2] -lt 20)) { throw "Docker Compose is too old: $composeVersion" }
    }
    Write-Host "==> Prerequisites passed. Source: $Repository ($Ref)"
    if ($Check) { exit 0 }
    if ([string]::IsNullOrWhiteSpace($Directory)) { throw 'Directory must not be empty.' }
    $Destination = $ExecutionContext.SessionState.Path.GetUnresolvedProviderPathFromPSPath($Directory)
    if (Test-Path -LiteralPath $Destination) { throw "Destination already exists: $Destination. Resume with its deploy/docker/install.ps1 -Build; use upgrade.ps1 for upgrades." }
    $parent = Split-Path -Parent $Destination
    [void][System.IO.Directory]::CreateDirectory($parent)
    $Staging = Join-Path $parent ('.tokenhub-download.' + [Guid]::NewGuid().ToString('N'))
    $source = Join-Path $Staging 'source'
    [void][System.IO.Directory]::CreateDirectory($Staging)
    $priorPrompt = $env:GIT_TERMINAL_PROMPT
    try {
        $env:GIT_TERMINAL_PROMPT = '0'
        Invoke-Git init --quiet $source
        Invoke-Git -C $source remote add origin $Repository
        Invoke-Git -C $source fetch --depth 1 --no-tags origin $Ref
        Invoke-Git -C $source checkout --quiet --detach FETCH_HEAD
        foreach ($file in @('deploy/docker/install.ps1', 'deploy/docker/compose.yml', 'docker/release/php/Dockerfile', '.env.example')) {
            if (-not (Test-Path -LiteralPath (Join-Path $source $file) -PathType Leaf)) { throw "Selected ref does not contain the Docker installer: $file" }
        }
        $commit = (Invoke-Git -C $source rev-parse HEAD).Trim()
    } finally { $env:GIT_TERMINAL_PROMPT = $priorPrompt }
    # Directory.Move refuses an existing destination instead of merging with it.
    [System.IO.Directory]::Move($source, $Destination)
    Write-Host "==> Source ready: $Destination"
    Write-Host "==> Commit: $commit"
    $installer = Join-Path $Destination 'deploy/docker/install.ps1'
    if ($DownloadOnly) {
        Write-Host 'Download complete. From the source directory run:'
        Write-Host ('.\deploy\docker\install.ps1 -Build -Tag source-' + $commit.Substring(0, 12))
        exit 0
    }
    $options = @{
        Build = $true; Tag = ('source-' + $commit.Substring(0, 12))
        HttpPort = $HttpPort; HttpsPort = $HttpsPort; Bind = $Bind
        AdminUser = $AdminUser; VectorStore = $VectorStore
        NoStart = $NoStart; Yes = $Yes
    }
    if ($PSBoundParameters.ContainsKey('Site')) { $options.Site = $Site }
    Write-Host '==> Building and installing TokenHub. Failed builds keep the source and .env for retry.'
    # A child process contains exit statements in the existing installer.
    $hostExecutable = (Get-Process -Id $PID).Path
    $arguments = @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', $installer)
    foreach ($key in $options.Keys) {
        if ($options[$key] -is [bool] -or $options[$key] -is [System.Management.Automation.SwitchParameter]) {
            if ($options[$key]) { $arguments += "-$key" }
        } else { $arguments += @("-$key", [string]$options[$key]) }
    }
    & $hostExecutable @arguments
    if ($LASTEXITCODE -ne 0) { throw "Installation failed. Source and configuration remain at $Destination. Fix the error and rerun deploy/docker/install.ps1 -Build there." }
} catch {
    [Console]::Error.WriteLine('ERROR: ' + $_.Exception.Message)
    exit 1
} finally {
    if ($Staging -and (Test-Path -LiteralPath $Staging)) { Remove-Item -LiteralPath $Staging -Recurse -Force }
}
