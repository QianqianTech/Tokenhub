#requires -Version 5.1
<#
.SYNOPSIS
  Back up a TokenHub Docker installation on Windows (same content as backup.sh).
  Restore with restore.sh from WSL or Git Bash: CONFIRM_RESTORE=1 ./restore.sh <backup-dir>
.EXAMPLE
  powershell -ExecutionPolicy Bypass -File .\backup.ps1 -BackupDir D:\tokenhub-backups -Keep 14
#>
[CmdletBinding()]
param([string]$BackupDir = '', [int]$Keep = 7)

# Native commands report failures through `$LASTEXITCODE`; 'Stop' would abort on harmless stderr output.
$ErrorActionPreference = 'Continue'
$KitDir = $PSScriptRoot
$EnvFile = Join-Path $KitDir '.env'
if (-not (Test-Path $EnvFile)) { throw "$EnvFile not found." }
if (-not $BackupDir) { $BackupDir = Join-Path $KitDir 'backups' }
$envValues = @{}
Get-Content $EnvFile | Where-Object { $_ -match '^[A-Z0-9_]+=' } | ForEach-Object { $k, $v = $_ -split '=', 2; $envValues[$k] = $v.Trim('"') }
$project = $(if ($envValues['COMPOSE_PROJECT_NAME']) { $envValues['COMPOSE_PROJECT_NAME'] } else { 'tokenhub' })
$image = $envValues['TOKENHUB_IMAGE_PREFIX'] + 'tokenhub-php:' + $envValues['TOKENHUB_IMAGE_TAG']
$target = Join-Path $BackupDir (Get-Date -Format 'yyyyMMdd-HHmmss')
New-Item -ItemType Directory -Force -Path $target | Out-Null
$compose = @('compose', '--project-directory', $KitDir, '--env-file', $EnvFile, '-f', (Join-Path $KitDir 'compose.yml'))

function Save-Volume([string]$Volume, [string]$Archive) {
    & docker volume inspect "${project}_$Volume" *> $null
    if ($LASTEXITCODE -ne 0) { Write-Host "WARN: volume ${project}_$Volume not found, skipped"; return }
    & docker run --rm --entrypoint tar --mount "type=volume,src=${project}_$Volume,dst=/source,readonly" --mount "type=bind,src=$target,dst=/backup" $image -C /source -czf "/backup/$Archive" .
    if ($LASTEXITCODE -ne 0) { throw "Backing up $Volume failed." }
}

Write-Host '==> MySQL dump'
& docker @compose exec -T mysql sh -c 'mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction --routines --triggers --hex-blob --set-gtid-purged=OFF "$MYSQL_DATABASE" | gzip > /tmp/tokenhub.sql.gz'
if ($LASTEXITCODE -ne 0) { throw 'MySQL dump failed.' }
$mysqlId = (& docker @compose ps -q mysql | Select-Object -First 1)
& docker cp "${mysqlId}:/tmp/tokenhub.sql.gz" (Join-Path $target 'mysql.sql.gz')
& docker @compose exec -T mysql rm -f /tmp/tokenhub.sql.gz

if ($envValues['RAG_VECTOR_STORE'] -ne 'mysql_fallback') {
    Write-Host '==> Qdrant snapshots'
    & docker @compose exec -T php php bin/qdrant-snapshot.php create --index=/tmp/qdrant-index.json
    if ($LASTEXITCODE -ne 0) { throw 'Qdrant snapshot failed.' }
    $phpId = (& docker @compose ps -q php | Select-Object -First 1)
    & docker cp "${phpId}:/tmp/qdrant-index.json" (Join-Path $target 'qdrant-index.json')
    Save-Volume 'qdrant_snapshots' 'qdrant-snapshots.tar.gz'
}

Write-Host '==> Redis, storage, certificates'
& docker @compose exec -T redis sh -c 'redis-cli --no-auth-warning -a "$REDIS_PASSWORD" SAVE' | Out-Null
Save-Volume 'redis_data' 'redis-data.tar.gz'
Save-Volume 'tokenhub_storage' 'storage.tar.gz'
Save-Volume 'caddy_data' 'caddy-data.tar.gz'
Copy-Item $EnvFile (Join-Path $target 'env')
Set-Content -Path (Join-Path $target 'image-tag') -Value $envValues['TOKENHUB_IMAGE_TAG'] -Encoding ascii

Get-ChildItem -LiteralPath $target -File | Where-Object Name -ne 'SHA256SUMS' | Sort-Object Name | ForEach-Object {
    (Get-FileHash -Algorithm SHA256 -LiteralPath $_.FullName).Hash.ToLowerInvariant() + '  ./' + $_.Name
} | Set-Content -Encoding ascii (Join-Path $target 'SHA256SUMS')

if ($Keep -gt 0) {
    Get-ChildItem -LiteralPath $BackupDir -Directory | Where-Object Name -like '20*' | Sort-Object Name -Descending | Select-Object -Skip $Keep | Remove-Item -Recurse -Force
}
Write-Host "==> Backup written to $target (contains secrets: keep it protected)."
