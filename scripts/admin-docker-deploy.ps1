$ErrorActionPreference = 'Continue'
Set-Location 'D:\mysoft\tokenhub'

$Docker = 'C:\Program Files\Docker\Docker\resources\bin\docker.exe'
$Out = 'D:\mysoft\tokenhub\admin-docker-deploy-output.txt'

Remove-Item Env:HTTP_PROXY -ErrorAction SilentlyContinue
Remove-Item Env:HTTPS_PROXY -ErrorAction SilentlyContinue
Remove-Item Env:ALL_PROXY -ErrorAction SilentlyContinue

"admin docker deploy started $(Get-Date -Format o)" | Set-Content -LiteralPath $Out

& $Docker info --format '{{.ServerVersion}}' >> $Out 2>&1
"docker info exit=$LASTEXITCODE" >> $Out

if ($LASTEXITCODE -ne 0) {
    exit $LASTEXITCODE
}

if (-not (Test-Path '.env') -and (Test-Path '.env.example')) {
    Copy-Item '.env.example' '.env'
}

& $Docker compose config >> $Out 2>&1
"compose config exit=$LASTEXITCODE" >> $Out
if ($LASTEXITCODE -ne 0) {
    exit $LASTEXITCODE
}

& $Docker compose up -d --build mysql redis php caddy >> $Out 2>&1
"compose up exit=$LASTEXITCODE" >> $Out
if ($LASTEXITCODE -ne 0) {
    exit $LASTEXITCODE
}

& $Docker compose exec -T php php -v >> $Out 2>&1
& $Docker compose exec -T mysql mysql --version >> $Out 2>&1
& $Docker compose exec -T php php bin/migrate.php >> $Out 2>&1
& $Docker compose exec -T php php bin/run-tests.php >> $Out 2>&1

"admin docker deploy finished $(Get-Date -Format o)" >> $Out
