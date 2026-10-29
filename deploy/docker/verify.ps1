#requires -Version 5.1
<#
.SYNOPSIS
  Acceptance check for a TokenHub Docker installation on Windows (same core checks as verify.sh).
  For the backup/restore drill run `./verify.sh --backup-drill` from WSL or Git Bash.
#>
$ErrorActionPreference = 'Continue'
$KitDir = $PSScriptRoot
$EnvFile = Join-Path $KitDir '.env'
if (-not (Test-Path $EnvFile)) { Write-Host "ERROR: $EnvFile not found; run install.ps1 first." -ForegroundColor Red; exit 1 }
$envValues = @{}
Get-Content $EnvFile | Where-Object { $_ -match '^[A-Z0-9_]+=' } | ForEach-Object { $k, $v = $_ -split '=', 2; $envValues[$k] = $v.Trim('"') }
$compose = @('compose', '--project-directory', $KitDir, '--env-file', $EnvFile, '-f', (Join-Path $KitDir 'compose.yml'))
$report = Join-Path $KitDir ('verify-report-' + (Get-Date -Format 'yyyyMMdd-HHmmss') + '.txt')
$script:failures = 0

function Record([string]$Status, [string]$Name, [string]$Detail = '') {
    if ($Status -eq 'FAIL') { $script:failures++ }
    $line = '{0,-5} {1,-28} {2}' -f $Status, $Name, $Detail
    Write-Host $line
    Add-Content -Path $report -Value $line
}

"TokenHub verification $((Get-Date).ToUniversalTime().ToString('o'))  tag=$($envValues['TOKENHUB_IMAGE_TAG'])  url=$($envValues['APP_URL'])" | Tee-Object -FilePath $report

foreach ($service in @('mysql', 'redis', 'qdrant', 'php', 'queue-worker', 'scheduler', 'rag-worker', 'realtime-gateway', 'caddy', 'init')) {
    $id = (& docker @compose ps -a -q $service | Select-Object -First 1)
    $state = if ($id) { (& docker inspect -f '{{.State.Status}} {{.State.ExitCode}} {{if .State.Health}}{{.State.Health.Status}}{{end}}' $id) } else { 'missing' }
    if ($service -eq 'init') {
        if ($state -like 'exited 0*') { Record 'PASS' 'init (migrations, admin)' 'completed' } else { Record 'FAIL' 'init (migrations, admin)' $state }
    } elseif ($state -like '* healthy') { Record 'PASS' "container $service" $state }
    elseif ($state -like 'running*') { Record 'WARN' "container $service" "$state (no health result yet)" }
    else { Record 'FAIL' "container $service" $state }
}

$url = [Uri]$envValues['APP_URL']
$port = if ($url.Scheme -eq 'https') { $envValues['TOKENHUB_HTTPS_PORT'] } else { $envValues['TOKENHUB_HTTP_PORT'] }
$connectAddress = $envValues['TOKENHUB_BIND_ADDRESS']
if ([string]::IsNullOrEmpty($connectAddress) -or $connectAddress -eq '0.0.0.0') { $connectAddress = '127.0.0.1' }
elseif ($connectAddress -eq '::') { $connectAddress = '[::1]' }
elseif ($connectAddress.Contains(':') -and -not $connectAddress.StartsWith('[')) { $connectAddress = "[$connectAddress]" }
foreach ($check in @(@('/health', 200), @('/ready', 200), @('/admin/', 200), @('/v1/models', 401))) {
    # curl.exe ships with Windows 10+; --resolve keeps the Host header of domain sites while connecting locally.
    $code = & curl.exe -s -k -o NUL -w '%{http_code}' --max-time 15 --noproxy "*" --resolve "$($url.Host):${port}:$connectAddress" "$($url.Scheme)://$($url.Host):$port$($check[0])"
    if ("$code" -eq "$($check[1])") { Record 'PASS' "GET $($check[0])" "HTTP $code" } else { Record 'FAIL' "GET $($check[0])" "HTTP $code, expected $($check[1])" }
}

$doctor = & docker @compose exec -T php php bin/doctor.php 2>&1
if ($LASTEXITCODE -eq 0) { Record 'PASS' 'bin/doctor.php' } else { Record 'FAIL' 'bin/doctor.php' (($doctor | Select-String '\[FAIL\]' | Select-Object -First 3) -join ' ') }
Add-Content -Path $report -Value ($doctor -join [Environment]::NewLine)

if ($script:failures -eq 0) { Write-Host "All checks passed. Report: $report"; exit 0 }
Write-Host "$($script:failures) check(s) failed. Report: $report" -ForegroundColor Red
exit 1
