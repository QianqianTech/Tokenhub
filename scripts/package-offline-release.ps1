#requires -Version 5.1
[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)][string]$OutputDirectory,
    [string]$SourceProjectName = 'tokenhub',
    [switch]$DryRun
)

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
$Root = Split-Path -Parent $PSScriptRoot
$Stamp = (Get-Date).ToUniversalTime().ToString('yyyyMMdd-HHmmss')
$Bundle = Join-Path $OutputDirectory ('TokenHub-offline-' + $Stamp)
$DataDirectory = Join-Path $Bundle 'data'
$ImagesDirectory = Join-Path $Bundle 'images'

function Invoke-Docker {
    param([string[]]$Arguments)
    if ($DryRun) {
        Write-Host ('DRY-RUN: docker ' + ($Arguments -join ' '))
        return
    }
    & docker @Arguments | Out-Host
    if ($LASTEXITCODE -ne 0) { throw ('docker command failed: docker ' + ($Arguments -join ' ')) }
}

function Get-DockerArchitecture {
    $value = (& docker version --format '{{.Server.Arch}}').Trim()
    if ($LASTEXITCODE -ne 0) { throw 'Unable to read Docker server architecture.' }
    if ($value -in @('amd64', 'x86_64')) { return 'amd64' }
    if ($value -in @('arm64', 'aarch64')) { return 'arm64' }
    throw ('Unsupported Docker server architecture: ' + $value)
}

function Save-Image {
    param([string]$Image, [string]$Path)
    Invoke-Docker @('image', 'save', '--output', $Path, $Image)
}

function Build-Image {
    param([string]$Platform, [string]$Image, [string]$Dockerfile, [string]$Archive)
    Invoke-Docker @('buildx', 'build', '--platform', $Platform, '--load', '--tag', $Image, '--file', $Dockerfile, $Root)
    Save-Image -Image $Image -Path $Archive
}

function Export-Platform {
    param([string]$Platform, [string]$Architecture)
    $tag = 'offline-' + $Stamp + '-' + $Architecture
    $target = Join-Path $ImagesDirectory $Architecture
    New-Item -ItemType Directory -Force -Path $target | Out-Null
    $localImages = @(
        @{ name = 'tokenhub-php'; dockerfile = 'docker/release/php/Dockerfile' },
        @{ name = 'tokenhub-caddy'; dockerfile = 'docker/release/caddy/Dockerfile' },
        @{ name = 'tokenhub-rag-worker'; dockerfile = 'docker/release/rag-worker/Dockerfile' },
        @{ name = 'tokenhub-worker-autoscaler'; dockerfile = 'docker/release/worker-autoscaler/Dockerfile' },
        @{ name = 'tokenhub-tools'; dockerfile = 'docker/release/tools/Dockerfile' }
    )
    foreach ($item in $localImages) {
        $image = $item.name + ':' + $tag
        Build-Image -Platform $Platform -Image $image -Dockerfile (Join-Path $Root $item.dockerfile) -Archive (Join-Path $target ($item.name + '.tar'))
    }
    foreach ($item in @(
        @{ source = 'mysql:9.7'; target = 'tokenhub-mysql' },
        @{ source = 'redis:latest'; target = 'tokenhub-redis' },
        @{ source = 'qdrant/qdrant:latest'; target = 'tokenhub-qdrant' }
    )) {
        Invoke-Docker @('pull', '--platform', $Platform, $item.source)
        $image = $item.target + ':' + $tag
        Invoke-Docker @('image', 'tag', $item.source, $image)
        Save-Image -Image $image -Path (Join-Path $target ($item.target + '.tar'))
    }
    return $tag
}

function Save-Volume {
    param([string]$Volume, [string]$Archive, [string]$ToolImage)
    $volumeMount = 'type=volume,src=' + $Volume + ',dst=/source,readonly'
    $backupMount = 'type=bind,src=' + $DataDirectory + ',dst=/backup'
    Invoke-Docker @('run', '--rm', '--mount', $volumeMount, '--mount', $backupMount, $ToolImage, '-c', ('tar -C /source -czf /backup/' + $Archive + ' .'))
}

function Save-Data {
    param([string]$ToolImage)
    if ($DryRun) {
        Write-Host 'DRY-RUN: export MySQL, Redis, Qdrant snapshots and named volumes.'
        return
    }
    & docker compose stop php queue-worker rag-worker
    if ($LASTEXITCODE -ne 0) { throw 'Unable to stop application writers before snapshot.' }
    try {
        & docker compose exec -T mysql sh -lc 'mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction --routines --triggers "$MYSQL_DATABASE"' | Set-Content -Encoding utf8 (Join-Path $DataDirectory 'mysql.sql')
        if ($LASTEXITCODE -ne 0) { throw 'MySQL export failed.' }
        Invoke-Docker @('compose', 'exec', '-T', 'redis', 'sh', '-lc', 'redis-cli --no-auth-warning -a "$REDIS_PASSWORD" SAVE')
        foreach ($item in @(
            @{ volume = 'redis_data'; archive = 'redis-data.tar.gz' },
            @{ volume = 'caddy_data'; archive = 'caddy-data.tar.gz' },
            @{ volume = 'caddy_config'; archive = 'caddy-config.tar.gz' },
            @{ volume = 'tokenhub_storage'; archive = 'storage.tar.gz' }
        )) {
            Save-Volume -Volume ($SourceProjectName + '_' + $item.volume) -Archive $item.archive -ToolImage $ToolImage
        }
        $port = (& docker compose port qdrant 6333).Trim()
        $snapshotDirectory = Join-Path $DataDirectory 'qdrant-snapshots'
        New-Item -ItemType Directory -Force -Path $snapshotDirectory | Out-Null
        $index = @()
        foreach ($entry in (Invoke-RestMethod -Uri ('http://' + $port + '/collections')).result.collections) {
            $collection = [string]$entry.name
            $snapshot = (Invoke-RestMethod -Method Post -Uri ('http://' + $port + '/collections/' + $collection + '/snapshots')).result
            Invoke-WebRequest -Uri ('http://' + $port + '/collections/' + $collection + '/snapshots/' + $snapshot.name) -OutFile (Join-Path $snapshotDirectory $snapshot.name)
            $index += @{ collection = $collection; file = [string]$snapshot.name }
        }
        $index | ConvertTo-Json -Depth 4 | Set-Content -Encoding utf8 (Join-Path $DataDirectory 'qdrant-index.json')
    }
    finally {
        & docker compose up -d php queue-worker rag-worker | Out-Host
    }
}

if (-not $DryRun) {
    New-Item -ItemType Directory -Force -Path $DataDirectory | Out-Null
    Copy-Item (Join-Path $Root 'docker-compose.offline.yml') (Join-Path $Bundle 'compose.offline.yml')
    Copy-Item (Join-Path $Root 'scripts/import-offline-release.ps1') (Join-Path $Bundle 'import-and-start.ps1')
    Copy-Item (Join-Path $Root 'scripts/import-offline-release.sh') (Join-Path $Bundle 'import-and-start.sh')
    Copy-Item (Join-Path $Root '.env') (Join-Path $Bundle '.env')
}
$dockerArchitecture = Get-DockerArchitecture
$pairs = if ($dockerArchitecture -eq 'amd64') { @(@('linux/amd64', 'amd64'), @('linux/arm64', 'arm64')) } else { @(@('linux/arm64', 'arm64'), @('linux/amd64', 'amd64')) }
$tags = @{}
foreach ($pair in $pairs) { $tags[$pair[1]] = Export-Platform -Platform $pair[0] -Architecture $pair[1] }
Save-Data -ToolImage ('tokenhub-tools:' + $tags[$dockerArchitecture])
if ($DryRun) { Write-Host ('DRY-RUN: would create ' + $Bundle); exit 0 }
@{ created_at = (Get-Date).ToUniversalTime().ToString('o'); release_tags = $tags; architectures = @('amd64', 'arm64') } | ConvertTo-Json -Depth 5 | Set-Content -Encoding utf8 (Join-Path $Bundle 'manifest.json')
Get-ChildItem -LiteralPath $Bundle -Recurse -File | Sort-Object FullName | ForEach-Object {
    $relative = $_.FullName.Substring($Bundle.Length + 1).Replace('\', '/')
    ((Get-FileHash -Algorithm SHA256 -LiteralPath $_.FullName).Hash.ToLowerInvariant() + ' *' + $relative)
} | Set-Content -Encoding ascii (Join-Path $Bundle 'SHA256SUMS')
Write-Host ('Offline release created: ' + $Bundle)
