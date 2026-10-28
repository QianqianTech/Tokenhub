#requires -Version 5.1
[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)][string]$BundleRoot,
    [string]$ProjectName = 'tokenhub',
    [switch]$ConfirmRestore
)

$ErrorActionPreference = 'Stop'
if (-not $ConfirmRestore) { throw 'Pass -ConfirmRestore before replacing project volumes.' }
$BundleRoot = (Resolve-Path -LiteralPath $BundleRoot).Path
$compose = Join-Path $BundleRoot 'compose.offline.yml'
$manifest = Get-Content -Raw (Join-Path $BundleRoot 'manifest.json') | ConvertFrom-Json
$serverArchitecture = (docker version --format '{{.Server.Arch}}').Trim()
$architecture = if ($serverArchitecture -in @('amd64', 'x86_64')) { 'amd64' } elseif ($serverArchitecture -in @('arm64', 'aarch64')) { 'arm64' } else { throw ('Unsupported Docker architecture: ' + $serverArchitecture) }
Get-Content (Join-Path $BundleRoot 'SHA256SUMS') | ForEach-Object {
    $parts = $_ -split '\s+\*', 2
    if ($parts.Count -ne 2 -or (Get-FileHash -Algorithm SHA256 -LiteralPath (Join-Path $BundleRoot $parts[1])).Hash.ToLowerInvariant() -ne $parts[0].ToLowerInvariant()) { throw ('Checksum mismatch: ' + $_) }
}
Get-ChildItem (Join-Path $BundleRoot ('images/' + $architecture)) -Filter '*.tar' | Sort-Object Name | ForEach-Object {
    & docker load --input $_.FullName
    if ($LASTEXITCODE -ne 0) { throw ('docker load failed: ' + $_.Name) }
}
$tag = [string]$manifest.release_tags.$architecture
$runtimeEnv = Join-Path $BundleRoot '.runtime.env'
Copy-Item (Join-Path $BundleRoot '.env') $runtimeEnv -Force
Add-Content -Encoding utf8 $runtimeEnv ([Environment]::NewLine + 'TOKENHUB_RELEASE_TAG=' + $tag + [Environment]::NewLine + 'COMPOSE_PROJECT_NAME=' + $ProjectName)
& docker compose --project-name $ProjectName --env-file $runtimeEnv -f $compose down --volumes --remove-orphans
foreach ($name in @('tokenhub_storage', 'mysql_97_data', 'redis_data', 'qdrant_data', 'caddy_data', 'caddy_config')) { & docker volume create ($ProjectName + '_' + $name) | Out-Null }
$data = Join-Path $BundleRoot 'data'
function Restore-Volume([string]$name, [string]$archive) {
    if (-not (Test-Path (Join-Path $data $archive))) { return }
    $targetMount = 'type=volume,src=' + $ProjectName + '_' + $name + ',dst=/target'
    $backupMount = 'type=bind,src=' + $data + ',dst=/backup,readonly'
    & docker run --rm --mount $targetMount --mount $backupMount ('tokenhub-tools:' + $tag) -c ('tar -C /target -xzf /backup/' + $archive)
    if ($LASTEXITCODE -ne 0) { throw ('Failed to restore ' + $archive) }
}
Restore-Volume 'tokenhub_storage' 'storage.tar.gz'
Restore-Volume 'redis_data' 'redis-data.tar.gz'
Restore-Volume 'caddy_data' 'caddy-data.tar.gz'
Restore-Volume 'caddy_config' 'caddy-config.tar.gz'
& docker compose --project-name $ProjectName --env-file $runtimeEnv -f $compose up -d mysql redis qdrant
if ($LASTEXITCODE -ne 0) { throw 'Unable to start state services.' }
Start-Sleep -Seconds 10
Get-Content (Join-Path $data 'mysql.sql') | & docker compose --project-name $ProjectName --env-file $runtimeEnv -f $compose exec -T mysql sh -lc 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"'
if ($LASTEXITCODE -ne 0) { throw 'MySQL restore failed.' }
& docker compose --project-name $ProjectName --env-file $runtimeEnv -f $compose up -d --no-build
$deadline = (Get-Date).AddSeconds(120)
do {
    try {
        $port = (& docker compose --project-name $ProjectName --env-file $runtimeEnv -f $compose port caddy 80).Trim()
        Invoke-WebRequest -UseBasicParsing -TimeoutSec 5 ('http://' + $port + '/health') | Out-Null
        Write-Host 'Offline release restored and healthy.'
        exit 0
    } catch { Start-Sleep -Seconds 3 }
} while ((Get-Date) -lt $deadline)
throw 'Offline release did not become healthy within 120 seconds.'
