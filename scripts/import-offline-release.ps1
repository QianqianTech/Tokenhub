#requires -Version 5.1
<#
.SYNOPSIS
  Load the images of an offline bundle (shipped as load-images.ps1 in the bundle root).
  Then: cd tokenhub; powershell -ExecutionPolicy Bypass -File .\install.ps1 -Site <domain-or-IP>
#>
[CmdletBinding()]
param([string]$BundleRoot = $PSScriptRoot)

$ErrorActionPreference = 'Stop'
$BundleRoot = (Resolve-Path -LiteralPath $BundleRoot).Path
$serverArchitecture = (docker version --format '{{.Server.Arch}}').Trim()
$architecture = if ($serverArchitecture -in @('amd64', 'x86_64')) { 'amd64' } elseif ($serverArchitecture -in @('arm64', 'aarch64')) { 'arm64' } else { throw ('Unsupported Docker architecture: ' + $serverArchitecture) }
$imageDir = Join-Path $BundleRoot ('images/' + $architecture)
if (-not (Test-Path $imageDir)) { throw "This bundle has no images for $architecture." }

Write-Host '==> Verifying checksums'
Get-Content (Join-Path $BundleRoot 'SHA256SUMS') | ForEach-Object {
    $parts = $_ -split '\s+\*?', 2
    if ($parts.Count -ne 2) { return }
    $file = Join-Path $BundleRoot ($parts[1] -replace '^\./', '')
    if ((Get-FileHash -Algorithm SHA256 -LiteralPath $file).Hash.ToLowerInvariant() -ne $parts[0].ToLowerInvariant()) { throw ('Checksum mismatch: ' + $parts[1]) }
}
Get-ChildItem $imageDir -Filter '*.tar' | Sort-Object Name | ForEach-Object {
    Write-Host "==> docker load $($_.Name)"
    & docker load --input $_.FullName
    if ($LASTEXITCODE -ne 0) { throw ('docker load failed: ' + $_.Name) }
}
Write-Host 'Images loaded. Next: cd tokenhub; powershell -ExecutionPolicy Bypass -File .\install.ps1 -Site <domain-or-IP>'
