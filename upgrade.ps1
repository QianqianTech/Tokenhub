#requires -Version 5.1
[CmdletBinding()]
param(
    [string]$Directory = (Get-Location).Path,
    [string]$Tag,
    [string]$Ref,
    [switch]$Check,
    [switch]$Yes,
    [switch]$Help
)

$ErrorActionPreference = 'Stop'
$Repository = 'https://github.com/QianqianTech/Tokenhub.git'
$tagPattern = '^[a-zA-Z0-9_][a-zA-Z0-9_.-]{0,127}\z'

function Invoke-Native {
    $program, $arguments = $args
    $saved = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        & $program @arguments
        if ($LASTEXITCODE -ne 0) { throw "Native command failed (exit $LASTEXITCODE): $program" }
    } finally { $ErrorActionPreference = $saved }
}

function Assert-CleanSource {
    if (Invoke-Native git --no-optional-locks -C $Root status --porcelain --untracked-files=normal) {
        throw 'The source tree has local changes or untracked files. Save or move them before upgrading.'
    }
}

try {
    if ($Help) {
        Write-Output @'
TokenHub Docker upgrade (Windows PowerShell 5.1+ / PowerShell 7)

Usage: upgrade.ps1 [-Directory PATH] (-Tag TAG | -Ref vX.Y.Z) [-Check | -Yes]
  -Directory PATH  Existing TokenHub directory (default: current directory).
                   With -Tag you may also pass its standalone Docker kit directory.
  -Tag TAG         Pull a tag from the registry already configured in the installation.
  -Ref vX.Y.Z     Download an official GitHub release tag and rebuild a source install.
  -Check          Check the local deployment and print the plan; no downloads or changes.
  -Yes            Skip the confirmation prompt; the backup still runs.
  -Help           Show this help.
'@
        exit 0
    }
    if ($Tag) {
        if ($Ref -or $Tag -cnotmatch $tagPattern) { throw 'Specify a valid -Tag or -Ref, never both.' }
    } elseif ($Ref -cnotmatch '^v[0-9]+\.[0-9]+\.[0-9]+\z') { throw 'Specify -Tag TAG or an official -Ref vX.Y.Z.' }

    $Root = (Get-Item -LiteralPath $Directory -Force).FullName
    $Kit = Join-Path $Root 'deploy/docker'
    if ($Tag -and -not (Test-Path -LiteralPath (Join-Path $Kit '.env') -PathType Leaf) -and
        (Test-Path -LiteralPath (Join-Path $Root 'compose.yml') -PathType Leaf) -and
        (Test-Path -LiteralPath (Join-Path $Root '.env') -PathType Leaf)) { $Kit = $Root }
    $EnvFile = Join-Path $Kit '.env'
    foreach ($file in @('.env', 'compose.yml', 'upgrade.ps1', 'backup.ps1', 'verify.ps1')) {
        if (-not (Test-Path -LiteralPath (Join-Path $Kit $file) -PathType Leaf)) { throw "Missing $Kit/$file. Select an existing Docker installation." }
    }
    foreach ($key in @('COMPOSE_PROJECT_NAME', 'TOKENHUB_IMAGE_PREFIX', 'TOKENHUB_IMAGE_TAG', 'TOKENHUB_BUILD')) {
        if (Test-Path -LiteralPath "Env:$key") { throw "Unset the shell variable $key and keep the setting in the deployment .env." }
    }
    $values = @{}
    Get-Content -LiteralPath $EnvFile -Encoding UTF8 | ForEach-Object {
        if ($_ -cmatch '^(TOKENHUB_IMAGE_TAG|TOKENHUB_IMAGE_PREFIX)=(.*)$') { $values[$Matches[1]] = $Matches[2].Trim('"') }
    }
    $previous = $values['TOKENHUB_IMAGE_TAG']
    $prefix = $values['TOKENHUB_IMAGE_PREFIX']
    if ($previous -cnotmatch $tagPattern) { throw 'TOKENHUB_IMAGE_TAG is missing or invalid in the deployment .env.' }

    if ($Ref) {
        if ($prefix) { throw 'This installation uses registry images. Use -Tag instead.' }
        if (-not (Get-Command git -ErrorAction SilentlyContinue)) { throw 'Git is required for a source upgrade.' }
        if ((Invoke-Native git -C $Root rev-parse --is-inside-work-tree) -ne 'true' -or (Invoke-Native git -C $Root rev-parse --show-prefix)) { throw 'Use the root of the existing TokenHub Git checkout.' }
        Assert-CleanSource
        if (Invoke-Native git -C $Root ls-files -- deploy/docker/.env) { throw 'The deployment .env must not be tracked by Git.' }
        $previousCommit = Invoke-Native git -C $Root rev-parse HEAD
    } elseif (-not $prefix) { throw 'No image registry is configured. Use -Ref for a source install or see the offline upgrade guide.' }

    if (-not (Get-Command docker -ErrorAction SilentlyContinue)) { throw 'Install and start Docker before upgrading.' }
    $platform = Invoke-Native docker version --format '{{.Server.Os}}/{{.Server.Arch}}'
    if ($platform -notin @('linux/amd64', 'linux/arm64')) { throw "Unsupported Docker platform: $platform." }
    $composeVersion = Invoke-Native docker compose version --short
    if ($composeVersion -notmatch '^v?(\d+)\.(\d+)' -or [int]$Matches[1] -lt 2 -or ([int]$Matches[1] -eq 2 -and [int]$Matches[2] -lt 20)) { throw 'Docker Compose 2.20+ is required.' }
    Invoke-Native docker compose --project-directory $Kit --env-file $EnvFile -f (Join-Path $Kit 'compose.yml') config -q
    $target = if ($Tag) { $Tag } else { $Ref }
    Write-Host "==> Deployment: $Kit ($platform)"
    Write-Host "==> Upgrade plan: $previous -> $target"
    if ($Check) { Write-Host 'Local checks passed. No images or source were downloaded.'; exit 0 }
    if ($Tag -ceq $previous) { Write-Host 'The target tag is already configured. Use the deployment verify script to check the running version.'; exit 0 }
    if (-not $Yes) {
        if ([Console]::IsInputRedirected) { throw 'Review the plan with -Check, then run with -Yes in non-interactive mode.' }
        if ((Read-Host 'Back up and restart this deployment? [y/N]') -notin @('y', 'yes')) { Write-Host 'Upgrade cancelled.'; exit 0 }
    }

    $hostExecutable = (Get-Process -Id $PID).Path
    $childOptions = @('-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-File')
    $upgradeOptions = @()
    if ($Ref) {
        Write-Host "==> Fetching official release $Ref from GitHub..."
        $priorPrompt = $env:GIT_TERMINAL_PROMPT
        try {
            $env:GIT_TERMINAL_PROMPT = '0'
            Invoke-Native git -C $Root fetch --depth 1 --no-tags $Repository "refs/tags/$Ref"
        } finally { $env:GIT_TERMINAL_PROMPT = $priorPrompt }
        $commit = Invoke-Native git -C $Root rev-parse --verify 'FETCH_HEAD^{commit}'
        foreach ($file in @('deploy/docker/upgrade.ps1', 'deploy/docker/compose.yml', 'deploy/docker/compose.build.yml', 'deploy/docker/verify.ps1')) {
            Invoke-Native git -C $Root cat-file -e "${commit}:$file"
        }
        if (Invoke-Native git -C $Root ls-tree $commit -- deploy/docker/.env) { throw 'Target release tracks the deployment .env.' }
        $Tag = 'source-' + $commit.Substring(0, 12)
        if ($Tag -ceq $previous -and $commit -eq $previousCommit) { Write-Host 'The release is already configured.'; exit 0 }
        Assert-CleanSource
        Write-Host "==> Current source commit: $previousCommit. Target: $commit ($Tag)."
        Write-Host '==> Backing up with the currently installed deployment files...'
        & $hostExecutable @childOptions (Join-Path $Kit 'backup.ps1') -Keep 0
        if ($LASTEXITCODE -ne 0) { throw 'Backup failed; the checkout and deployed version were not changed.' }
        Assert-CleanSource
        Invoke-Native git -C $Root checkout --quiet --detach --no-overwrite-ignore $commit
        $upgradeOptions += @('-Build', '-SkipBackup')
    }

    & $hostExecutable @childOptions (Join-Path $Kit 'upgrade.ps1') -Tag $Tag @upgradeOptions
    if ($LASTEXITCODE -ne 0) {
        if ($Ref) { throw "Upgrade failed. Use the backup printed above and the previous source commit $previousCommit if recovery is needed." }
        throw 'Upgrade failed. Review the rollback output and keep the backup.'
    }
    & $hostExecutable @childOptions (Join-Path $Kit 'verify.ps1')
    if ($LASTEXITCODE -ne 0) { throw 'The upgrade ran but verification failed. Check the report and logs before deciding whether to restore the backup.' }
    Write-Host "==> Upgrade verified: $Tag"
    exit 0
} catch {
    [Console]::Error.WriteLine('ERROR: ' + $_.Exception.Message)
    exit 1
}
