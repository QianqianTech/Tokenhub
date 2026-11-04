#requires -Version 5.1
<#
.SYNOPSIS
  Upgrade a TokenHub Docker installation on Windows (same steps as upgrade.sh).
.EXAMPLE
  powershell -ExecutionPolicy Bypass -File .\upgrade.ps1 -Tag 1.1.0
  powershell -ExecutionPolicy Bypass -File .\upgrade.ps1 -Build
#>
[CmdletBinding()]
param([string]$Tag = '', [switch]$Build, [switch]$SkipBackup, [switch]$NoRollback)

# Native commands report failures through `$LASTEXITCODE`; 'Stop' would abort on harmless stderr output.
$ErrorActionPreference = 'Continue'
$KitDir = $PSScriptRoot
$EnvFile = Join-Path $KitDir '.env'
if (-not (Test-Path $EnvFile)) { throw "$EnvFile not found; run install.ps1 first." }

# Plain function on purpose: an advanced function would bind `-d` to -Debug and reject `-T`.
function Invoke-Compose {
    $Arguments = @($args)
    $files = @('-f', (Join-Path $KitDir 'compose.yml'))
    if ($Build) { $files += @('-f', (Join-Path $KitDir 'compose.build.yml')) }
    & docker compose --project-directory $KitDir --env-file $EnvFile @files @Arguments
    if ($LASTEXITCODE -ne 0) { throw ('docker compose ' + ($Arguments -join ' ') + ' failed') }
}

$lines = Get-Content -LiteralPath $EnvFile -Encoding UTF8 -ErrorAction Stop
$previous = (($lines | Where-Object { $_ -like 'TOKENHUB_IMAGE_TAG=*' } | Select-Object -Last 1) -replace '^TOKENHUB_IMAGE_TAG=', '')
if (-not $Tag -and $Build -and (Test-Path (Join-Path $KitDir '..\..\VERSION'))) { $Tag = (Get-Content (Join-Path $KitDir '..\..\VERSION') -Raw).Trim() }
if (-not $Tag) { $Tag = $previous }

# Applied migrations, read from MySQL (empty when it cannot be read).
function Get-AppliedMigrations {
    $out = & docker compose --project-directory $KitDir --env-file $EnvFile -f (Join-Path $KitDir 'compose.yml') exec -T mysql sh -c 'exec mysql -N -B -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE" -e "SELECT COUNT(*) FROM migrations"' 2>$null
    if ($LASTEXITCODE -ne 0) { return '' }
    return ("$out").Trim()
}

function Set-ImageTag([string]$Value) {
    $current = Get-Content -LiteralPath $EnvFile -Encoding UTF8 -ErrorAction Stop
    $current = $current | ForEach-Object { if ($_ -like 'TOKENHUB_IMAGE_TAG=*') { "TOKENHUB_IMAGE_TAG=$Value" } else { $_ } }
    [System.IO.File]::WriteAllLines($EnvFile, $current, (New-Object System.Text.UTF8Encoding($false)))
}

$migrationsBefore = Get-AppliedMigrations
$backupPath = ''
if (-not $SkipBackup) {
    Write-Host '==> Backing up before the upgrade...'
    & (Join-Path $KitDir 'backup.ps1')
    $backupRoot = $null
    $fromEnv = (Get-Content -LiteralPath $EnvFile -Encoding UTF8 -ErrorAction Stop | Where-Object { $_ -match '^BACKUP_DIR=.+' } | Select-Object -Last 1) -replace '^BACKUP_DIR=', ''
    if ($fromEnv) { $backupRoot = $fromEnv.Trim('"') } elseif ($env:ProgramData) { $backupRoot = Join-Path $env:ProgramData 'TokenHub\backups' } else { $backupRoot = Join-Path $env:USERPROFILE 'tokenhub-backups' }
    if (Test-Path $backupRoot) { $backupPath = (Get-ChildItem -Directory $backupRoot -Filter '20*' | Sort-Object Name | Select-Object -Last 1).FullName }
}

Write-Host "==> Upgrading image tag $previous -> $Tag"
$lines = $lines | ForEach-Object { if ($_ -like 'TOKENHUB_IMAGE_TAG=*') { "TOKENHUB_IMAGE_TAG=$Tag" } else { $_ } }
# Older .env files predate the realtime gateway: give them a shared internal token.
$realtimeToken = (($lines | Where-Object { $_ -like 'REALTIME_INTERNAL_TOKEN=*' } | Select-Object -Last 1) -replace '^REALTIME_INTERNAL_TOKEN=', '')
if ($realtimeToken.Length -lt 24 -or $realtimeToken -eq 'change-me') {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789'.ToCharArray()
    $bytes = New-Object byte[] 48
    [System.Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($bytes)
    $newToken = -join ($bytes | ForEach-Object { $chars[$_ % $chars.Length] })
    $lines = @($lines | Where-Object { $_ -notlike 'REALTIME_INTERNAL_TOKEN=*' }) + "REALTIME_INTERNAL_TOKEN=$newToken"
    Write-Host "==> Generated REALTIME_INTERNAL_TOKEN in $EnvFile"
}
[System.IO.File]::WriteAllLines($EnvFile, $lines, (New-Object System.Text.UTF8Encoding($false)))

try {
    if ($Build) { Invoke-Compose build }
    elseif (($lines | Where-Object { $_ -match '^TOKENHUB_IMAGE_PREFIX=.+' })) { Invoke-Compose pull }

    Invoke-Compose up -d --remove-orphans
    Write-Host '==> Waiting for migrations (init container)...'
    $deadline = (Get-Date).AddMinutes(30)
    do {
        Start-Sleep -Seconds 5
        $id = (& docker compose --project-directory $KitDir --env-file $EnvFile -f (Join-Path $KitDir 'compose.yml') ps -a -q init | Select-Object -First 1)
        $state = if ($id) { & docker inspect -f '{{.State.Status}} {{.State.ExitCode}}' $id } else { '' }
        if ($state -like 'exited*' -and $state -ne 'exited 0') { Invoke-Compose logs --tail=80 init; throw 'Upgrade migrations failed.' }
    } while ($state -ne 'exited 0' -and (Get-Date) -lt $deadline)
    if ($state -ne 'exited 0') { throw 'Migrations did not finish within 30 minutes.' }
} catch {
    Write-Host "WARN: upgrade to $Tag failed: $($_.Exception.Message)" -ForegroundColor Yellow
    $migrationsAfter = Get-AppliedMigrations
    if (-not $NoRollback -and $previous -and $previous -ne $Tag) {
        Write-Host "==> Rolling back the image tag to $previous..."
        Set-ImageTag $previous
        & docker compose --project-directory $KitDir --env-file $EnvFile -f (Join-Path $KitDir 'compose.yml') up -d --remove-orphans
        if ($LASTEXITCODE -eq 0) { Write-Host "==> Previous version $previous started again." }
        else { Write-Host "WARN: starting $previous failed as well; check 'docker compose ps'." -ForegroundColor Yellow }
    }
    if ($migrationsBefore -and $migrationsAfter -and $migrationsBefore -ne $migrationsAfter) {
        Write-Host "WARN: database migrations already ran ($migrationsBefore -> $migrationsAfter applied) and are not reversible." -ForegroundColor Yellow
        Write-Host "WARN: restore the pre-upgrade backup (from WSL / Git Bash): CONFIRM_RESTORE=1 ./restore.sh $(if ($backupPath) { $backupPath } else { '<backup-dir>' })" -ForegroundColor Yellow
    } elseif (-not $migrationsBefore -or -not $migrationsAfter) {
        Write-Host "WARN: could not tell whether migrations ran; if $previous misbehaves, restore the backup $backupPath" -ForegroundColor Yellow
    }
    exit 1
}
try { Invoke-Compose exec -T php php bin/doctor.php } catch { Write-Host 'WARN: self-check reported problems.' -ForegroundColor Yellow }
Write-Host "==> Upgrade to $Tag finished. Previous tag: $previous"
