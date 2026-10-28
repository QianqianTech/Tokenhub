#requires -Version 5.1
<#
.SYNOPSIS
  Create an offline installation bundle on Windows (same result as package-offline-release.sh).
.EXAMPLE
  powershell -ExecutionPolicy Bypass -File scripts/package-offline-release.ps1 -OutputDirectory D:\releases
  powershell -ExecutionPolicy Bypass -File scripts/package-offline-release.ps1 -OutputDirectory D:\releases -Platforms linux/amd64
.NOTES
  The bundle contains images and the deployment kit only: no .env, no secrets, no data.
  Cross-architecture builds need QEMU (Docker Desktop includes it).
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)][string]$OutputDirectory,
    [string]$Platforms = 'linux/amd64,linux/arm64',
    [string]$Tag = '',
    [string]$PhpVersion = '8.4',
    [switch]$DryRun
)

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
$Root = Split-Path -Parent $PSScriptRoot
if (-not $Tag) { $Tag = (Get-Content (Join-Path $Root 'VERSION') -Raw).Trim() }
$Bundle = Join-Path $OutputDirectory ('TokenHub-' + $Tag + '-offline')

function Invoke-Docker {
    param([string[]]$Arguments)
    if ($DryRun) {
        Write-Host ('DRY-RUN: docker ' + ($Arguments -join ' '))
        return
    }
    & docker @Arguments | Out-Host
    if ($LASTEXITCODE -ne 0) { throw ('docker command failed: docker ' + ($Arguments -join ' ')) }
}

function Get-EnvDefault([string]$Key) {
    $line = Get-Content (Join-Path $Root '.env.example') | Where-Object { $_ -like "$Key=*" } | Select-Object -First 1
    return ($line -replace "^$Key=", '')
}

$localImages = @(
    @{ name = 'tokenhub-php'; dockerfile = 'docker/release/php/Dockerfile' },
    @{ name = 'tokenhub-caddy'; dockerfile = 'docker/release/caddy/Dockerfile' },
    @{ name = 'tokenhub-rag-worker'; dockerfile = 'docker/release/rag-worker/Dockerfile' },
    @{ name = 'tokenhub-worker-autoscaler'; dockerfile = 'docker/release/worker-autoscaler/Dockerfile' },
    @{ name = 'tokenhub-realtime-gateway'; dockerfile = 'docker/release/realtime-gateway/Dockerfile' }
)
$thirdParty = @((Get-EnvDefault 'MYSQL_IMAGE'), (Get-EnvDefault 'REDIS_IMAGE'), (Get-EnvDefault 'QDRANT_IMAGE'))

if (-not $DryRun) {
    if (Test-Path $Bundle) { Remove-Item -Recurse -Force $Bundle }
    New-Item -ItemType Directory -Force -Path (Join-Path $Bundle 'images'), (Join-Path $Bundle 'tokenhub') | Out-Null
}

foreach ($platform in ($Platforms -split ',')) {
    $platform = $platform.Trim()
    $arch = $platform -replace '^linux/', ''
    $target = Join-Path $Bundle ('images/' + $arch)
    if (-not $DryRun) { New-Item -ItemType Directory -Force -Path $target | Out-Null }
    foreach ($item in $localImages) {
        $archive = Join-Path $target ($item.name + '.tar')
        Invoke-Docker @('buildx', 'build', '--platform', $platform, '--build-arg', "PHP_VERSION=$PhpVersion", '--build-arg', "TOKENHUB_VERSION=$Tag",
            '--file', (Join-Path $Root $item.dockerfile), '--tag', ($item.name + ':' + $Tag), '--output', "type=docker,dest=$archive", $Root)
    }
    foreach ($image in $thirdParty) {
        Invoke-Docker @('pull', '--platform', $platform, $image)
        Invoke-Docker @('image', 'save', '--platform', $platform, '--output', (Join-Path $target (($image -replace '[/:]', '_') + '.tar')), $image)
    }
}

if ($DryRun) { Write-Host ('DRY-RUN: would create ' + $Bundle); exit 0 }

foreach ($file in @('compose.yml', 'lib.sh', 'install.sh', 'install.ps1', 'upgrade.sh', 'upgrade.ps1', 'backup.sh', 'backup.ps1', 'restore.sh', 'verify.sh', 'verify.ps1', 'README.md')) {
    Copy-Item (Join-Path $Root ('deploy/docker/' + $file)) (Join-Path $Bundle ('tokenhub/' + $file))
}
Copy-Item (Join-Path $Root '.env.example') (Join-Path $Bundle 'tokenhub/.env.example')
Set-Content -Encoding ascii (Join-Path $Bundle 'tokenhub/VERSION') $Tag
Set-Content -Encoding ascii (Join-Path $Bundle 'VERSION') $Tag
Copy-Item (Join-Path $Root 'scripts/import-offline-release.sh') (Join-Path $Bundle 'load-images.sh')
Copy-Item (Join-Path $Root 'scripts/import-offline-release.ps1') (Join-Path $Bundle 'load-images.ps1')
@{ version = $Tag; created_at = (Get-Date).ToUniversalTime().ToString('o'); platforms = $Platforms; third_party_images = $thirdParty } |
    ConvertTo-Json -Depth 4 | Set-Content -Encoding utf8 (Join-Path $Bundle 'manifest.json')
Get-ChildItem -LiteralPath $Bundle -Recurse -File | Where-Object Name -ne 'SHA256SUMS' | Sort-Object FullName | ForEach-Object {
    $relative = $_.FullName.Substring($Bundle.Length + 1).Replace('\', '/')
    ((Get-FileHash -Algorithm SHA256 -LiteralPath $_.FullName).Hash.ToLowerInvariant() + '  ./' + $relative)
} | Set-Content -Encoding ascii (Join-Path $Bundle 'SHA256SUMS')
Write-Host ('Offline bundle created: ' + $Bundle)
