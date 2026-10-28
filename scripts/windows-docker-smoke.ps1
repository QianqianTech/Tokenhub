$ErrorActionPreference = 'Stop'

$Docker = 'C:\Program Files\Docker\Docker\resources\bin\docker.exe'
$DockerDesktop = 'C:\Program Files\Docker\Docker\Docker Desktop.exe'

Remove-Item Env:HTTP_PROXY -ErrorAction SilentlyContinue
Remove-Item Env:HTTPS_PROXY -ErrorAction SilentlyContinue
Remove-Item Env:ALL_PROXY -ErrorAction SilentlyContinue

if (-not (Test-Path $Docker)) {
    throw "Docker CLI not found at $Docker"
}

if (-not (Test-Path '.env') -and (Test-Path '.env.example')) {
    Copy-Item '.env.example' '.env'
}

$infoOk = $false
for ($i = 0; $i -lt 60; $i++) {
    & $Docker info *> $null
    if ($LASTEXITCODE -eq 0) {
        $infoOk = $true
        break
    }

    if (Test-Path $DockerDesktop) {
        Start-Process -FilePath $DockerDesktop -WindowStyle Hidden
    }

    Start-Sleep -Seconds 5
}

if (-not $infoOk) {
    & $Docker info
    throw 'Docker daemon is not available.'
}

& $Docker compose up -d --build mysql redis php caddy
& $Docker compose exec -T php php bin/migrate.php
& $Docker compose exec -T php php bin/run-tests.php

$baseUrl = if ($env:BASE_URL) { $env:BASE_URL } else { 'http://localhost:8080' }
$health = Invoke-WebRequest -UseBasicParsing "$baseUrl/health"
$adminHealth = Invoke-WebRequest -UseBasicParsing "$baseUrl/admin-api/health"
$admin = Invoke-WebRequest -UseBasicParsing "$baseUrl/admin/"

if ($health.Content -notmatch '"service":"tokenhub"') {
    throw 'Public health response did not include service tokenhub.'
}

if ($adminHealth.Content -notmatch '"service":"tokenhub"') {
    throw 'Admin health response did not include service tokenhub.'
}

if ($admin.Content -notmatch 'TokenHub Admin') {
    throw 'Admin page did not include TokenHub Admin.'
}

& $Docker compose exec -T php php bin/queue-test-job.php
& $Docker compose run --rm queue-worker php bin/queue-worker.php --once

Write-Host 'TokenHub Docker/PHP smoke test passed.'
