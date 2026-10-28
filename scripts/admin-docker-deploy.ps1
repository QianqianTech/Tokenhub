#requires -Version 5.1
<#
.SYNOPSIS
  Development helper for Windows: start the development Docker stack from this repository,
  apply migrations and run the test suite. Output is also written to admin-docker-deploy-output.txt.
  Production installs use deploy/docker/install.ps1.
#>
[CmdletBinding()]
param([string]$Docker = 'docker')

$ErrorActionPreference = 'Continue'
$Root = Split-Path -Parent $PSScriptRoot
Set-Location $Root
$Out = Join-Path $Root 'admin-docker-deploy-output.txt'

Remove-Item Env:HTTP_PROXY -ErrorAction SilentlyContinue
Remove-Item Env:HTTPS_PROXY -ErrorAction SilentlyContinue
Remove-Item Env:ALL_PROXY -ErrorAction SilentlyContinue

"admin docker deploy started $(Get-Date -Format o)" | Set-Content -LiteralPath $Out

& $Docker info --format '{{.ServerVersion}}' >> $Out 2>&1
"docker info exit=$LASTEXITCODE" >> $Out
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

if (-not (Test-Path '.env') -and (Test-Path '.env.example')) {
    (Get-Content '.env.example') -replace '^APP_ENV=.*', 'APP_ENV=local' | Set-Content '.env'
}

& $Docker compose config --quiet >> $Out 2>&1
"compose config exit=$LASTEXITCODE" >> $Out
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

& $Docker compose up -d --build mysql redis qdrant php caddy >> $Out 2>&1
"compose up exit=$LASTEXITCODE" >> $Out
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

& $Docker compose exec -T php php -v >> $Out 2>&1
& $Docker compose exec -T mysql mysql --version >> $Out 2>&1
& $Docker compose exec -T php php bin/migrate.php >> $Out 2>&1
& $Docker compose exec -T php php bin/run-tests.php >> $Out 2>&1

"admin docker deploy finished $(Get-Date -Format o)" >> $Out
Get-Content $Out
