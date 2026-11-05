#!/usr/bin/env bash
# Standalone upgrade entry point for an existing TokenHub Docker installation.
set -euo pipefail

REPOSITORY=https://github.com/QianqianTech/Tokenhub.git
DIRECTORY="$PWD" TAG='' REF='' CHECK=0 YES=0

info() { printf '==> %s\n' "$*"; }
die() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }
usage() {
  cat <<'TXT'
TokenHub Docker upgrade (Linux / macOS / WSL 2)

Usage: bash upgrade.sh [--dir PATH] (--tag TAG | --ref vX.Y.Z) [--check | --yes]
  --dir PATH    Existing TokenHub directory (default: current directory).
                With --tag you may also pass its standalone Docker kit directory.
  --tag TAG     Pull a tag from the registry already configured in the installation.
  --ref vX.Y.Z  Download an official GitHub release tag and rebuild a source install.
  --check       Check the local deployment and print the plan; no downloads or changes.
  -y, --yes     Skip the confirmation prompt; the backup still runs.
  -h, --help    Show this help.

Examples:
  bash upgrade.sh --dir /opt/tokenhub --tag 1.0.3 --check
  bash upgrade.sh --dir /opt/tokenhub --ref v1.0.3
TXT
}

value_required() { [ "$#" -ge 2 ] && [ -n "$2" ] && [ "${2#-}" = "$2" ] || die "$1 requires a value."; }
while [ "$#" -gt 0 ]; do
  case "$1" in
    --dir) value_required "$@"; DIRECTORY="$2"; shift 2 ;;
    --tag) value_required "$@"; TAG="$2"; shift 2 ;;
    --ref) value_required "$@"; REF="$2"; shift 2 ;;
    --check) CHECK=1; shift ;;
    -y|--yes) YES=1; shift ;;
    -h|--help) usage; exit 0 ;;
    *) die "Unknown option: $1 (see --help)." ;;
  esac
done
tag_pattern='^[a-zA-Z0-9_][a-zA-Z0-9_.-]{0,127}$'
ref_pattern='^v[0-9]+\.[0-9]+\.[0-9]+$'
if [ -n "$TAG" ]; then
  [ -z "$REF" ] && [[ "$TAG" =~ $tag_pattern ]] || die 'Specify a valid --tag or --ref, never both.'
else
  [[ "$REF" =~ $ref_pattern ]] || die 'Specify --tag TAG or an official --ref vX.Y.Z.'
fi

ROOT="$(cd -- "$DIRECTORY" && pwd -P)" || die "Cannot open existing directory: $DIRECTORY"
KIT="$ROOT/deploy/docker"
if [ -n "$TAG" ] && [ ! -f "$KIT/.env" ] && [ -f "$ROOT/compose.yml" ] && [ -f "$ROOT/.env" ]; then KIT="$ROOT"; fi
ENV_FILE="$KIT/.env"
for file in .env compose.yml upgrade.sh backup.sh verify.sh; do
  [ -f "$KIT/$file" ] || die "Missing $KIT/$file. Select an existing Docker installation."
done

# Compose would let process variables override the .env values. Backups must
# target the same project and image as the upgrade.
for key in COMPOSE_PROJECT_NAME TOKENHUB_IMAGE_PREFIX TOKENHUB_IMAGE_TAG TOKENHUB_BUILD; do
  [ -z "${!key+x}" ] || die "Unset the shell variable $key and keep the setting in the deployment .env."
done
env_value() {
  awk -v key="$1" 'index($0, key "=") == 1 { v = substr($0, length(key) + 2); sub(/\r$/, "", v); sub(/^"/, "", v); sub(/"$/, "", v) } END { print v }' "$ENV_FILE"
}
PREVIOUS="$(env_value TOKENHUB_IMAGE_TAG)"
PREFIX="$(env_value TOKENHUB_IMAGE_PREFIX)"
[[ "$PREVIOUS" =~ $tag_pattern ]] || die 'TOKENHUB_IMAGE_TAG is missing or invalid in the deployment .env.'

source_clean() {
  local status
  status="$(git --no-optional-locks -C "$ROOT" status --porcelain --untracked-files=normal)" || die 'Cannot inspect the source directory.'
  [ -z "$status" ] || die 'The source tree has local changes or untracked files. Save or move them before upgrading.'
}

backup_and_verify() {
  local output manifest filtered
  if ! output="$(bash "$KIT/backup.sh")"; then
    printf '%s\n' "$output"
    die 'Backup failed; the checkout and deployed version were not changed.'
  fi
  printf '%s\n' "$output"
  BACKUP_PATH="$(printf '%s\n' "$output" | sed -n 's/^.*Backup written to \(.*\) (contains secrets: keep it protected)\.$/\1/p' | tail -1)"
  manifest="$BACKUP_PATH/SHA256SUMS"
  [ -n "$BACKUP_PATH" ] && [ -s "$manifest" ] && [ -s "$BACKUP_PATH/mysql.sql.gz" ] &&
    [ -s "$BACKUP_PATH/env" ] && [ -s "$BACKUP_PATH/image-tag" ] || die 'The backup did not produce the required files.'

  # Older kits could list the temporary checksum file that the backup just renamed.
  if grep -Eq '^[[:xdigit:]]{64}  \./\.SHA256SUMS\.tmp$' "$manifest"; then
    filtered="$(mktemp "$BACKUP_PATH/.SHA256SUMS.XXXXXX")"
    awk '$2 != "./.SHA256SUMS.tmp"' "$manifest" > "$filtered"
    mv -- "$filtered" "$manifest"
  fi
  if command -v sha256sum >/dev/null 2>&1; then
    (cd "$BACKUP_PATH" && sha256sum -c SHA256SUMS >/dev/null) || die 'Backup checksum verification failed.'
  else
    (cd "$BACKUP_PATH" && shasum -a 256 -c SHA256SUMS >/dev/null) || die 'Backup checksum verification failed.'
  fi
  info "Backup verified: $BACKUP_PATH"
}
if [ -n "$REF" ]; then
  [ -z "$PREFIX" ] || die 'This installation uses registry images. Use --tag instead.'
  command -v git >/dev/null || die 'Git is required for a source upgrade.'
  [ "$(git -C "$ROOT" rev-parse --is-inside-work-tree 2>/dev/null)" = true ] &&
    [ -z "$(git -C "$ROOT" rev-parse --show-prefix)" ] || die 'Use the root of the existing TokenHub Git checkout.'
  source_clean
  [ -z "$(git -C "$ROOT" ls-files -- deploy/docker/.env)" ] || die 'The deployment .env must not be tracked by Git.'
  PREVIOUS_COMMIT="$(git -C "$ROOT" rev-parse HEAD)"
else
  [ -n "$PREFIX" ] || die 'No image registry is configured. Use --ref for a source install or see the offline upgrade guide.'
fi

command -v docker >/dev/null || die 'Install and start Docker before upgrading.'
PLATFORM="$(docker version --format '{{.Server.Os}}/{{.Server.Arch}}')" || die 'Cannot reach the Docker daemon.'
case "$PLATFORM" in linux/amd64|linux/arm64) ;; *) die "Unsupported Docker platform: $PLATFORM." ;; esac
COMPOSE_VERSION="$(docker compose version --short)" || die 'Docker Compose 2.20+ is required.'
compose_pattern='^v?([0-9]+)\.([0-9]+)'
[[ "$COMPOSE_VERSION" =~ $compose_pattern ]] || die "Cannot read Docker Compose version: $COMPOSE_VERSION."
major="${BASH_REMATCH[1]}" minor="${BASH_REMATCH[2]}"
[ "$major" -gt 2 ] || { [ "$major" -eq 2 ] && [ "$minor" -ge 20 ]; } || die 'Docker Compose 2.20+ is required.'
docker compose --project-directory "$KIT" --env-file "$ENV_FILE" -f "$KIT/compose.yml" config -q || die 'The current Compose configuration is invalid or Docker Compose v2 is unavailable.'
info "Deployment: $KIT ($PLATFORM)"
info "Upgrade plan: $PREVIOUS -> ${TAG:-$REF}"
if [ "$CHECK" = 1 ]; then info 'Local checks passed. No images or source were downloaded.'; exit 0; fi
if [ "$TAG" = "$PREVIOUS" ]; then info 'The target tag is already configured. Use the deployment verify script to check the running version.'; exit 0; fi
if [ "$YES" = 0 ]; then
  [ -t 0 ] || die 'Review the plan with --check, then run with --yes in non-interactive mode.'
  printf 'Back up and restart this deployment? [y/N] '
  read -r answer || exit 0
  case "$answer" in y|Y|yes|YES) ;; *) info 'Upgrade cancelled.'; exit 0 ;; esac
fi

if [ -n "$REF" ]; then
  info "Fetching official release $REF from GitHub..."
  GIT_TERMINAL_PROMPT=0 git -C "$ROOT" fetch --depth 1 --no-tags "$REPOSITORY" "refs/tags/$REF" || die "Release $REF is unavailable. The installation was not changed."
  COMMIT="$(git -C "$ROOT" rev-parse --verify 'FETCH_HEAD^{commit}')"
  for file in deploy/docker/upgrade.sh deploy/docker/compose.yml deploy/docker/compose.build.yml deploy/docker/verify.sh; do
    git -C "$ROOT" cat-file -e "$COMMIT:$file" || die "Release $REF does not contain $file."
  done
  if git -C "$ROOT" cat-file -e "$COMMIT:deploy/docker/.env" 2>/dev/null; then die 'Target release tracks the deployment .env.'; fi
  TAG="source-${COMMIT:0:12}"
  if [ "$TAG" = "$PREVIOUS" ] && [ "$COMMIT" = "$PREVIOUS_COMMIT" ]; then info 'The release is already configured.'; exit 0; fi
  source_clean
  info "Current source commit: $PREVIOUS_COMMIT. Target: $COMMIT ($TAG)."
  info 'Backing up with the currently installed deployment files...'
  KEEP=0 backup_and_verify
  source_clean
  git -C "$ROOT" checkout --quiet --detach --no-overwrite-ignore "$COMMIT" || die 'Cannot switch source. Keep the backup printed above.'
  if ! bash "$KIT/upgrade.sh" --build --tag "$TAG" --skip-backup; then
    die "Upgrade failed. Use backup $BACKUP_PATH and the previous source commit $PREVIOUS_COMMIT if recovery is needed."
  fi
else
  backup_and_verify
  bash "$KIT/upgrade.sh" --tag "$TAG" --skip-backup || die "Upgrade failed. Review the rollback output and keep backup $BACKUP_PATH."
fi

if ! bash "$KIT/verify.sh"; then
  die 'The upgrade ran but verification failed. Check the report and logs before deciding whether to restore the backup.'
fi
info "Upgrade verified: $TAG"
