#!/usr/bin/env bash
# Download TokenHub from GitHub and build its production Docker stack.
set -euo pipefail

REPOSITORY=https://github.com/QianqianTech/Tokenhub.git
INSTALL_DIR="$PWD/tokenhub"
REF=main
DOWNLOAD_ONLY=0 CHECK_ONLY=0
INSTALL_ARGS=()
STAGING=""

info() { printf '==> %s\n' "$*"; }
die() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }
cleanup() { if [ -n "$STAGING" ]; then rm -rf -- "$STAGING"; fi; }
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

usage() {
  cat <<'TXT'
TokenHub GitHub installer (Linux / macOS / Linux inside WSL 2)

Usage: bash install.sh [options]
  --dir PATH             New source/install directory (default: ./tokenhub)
  --ref REF              GitHub branch, tag, or full commit SHA (default: main)
  --download-only        Download source without requiring or starting Docker
  --check                Check prerequisites only; do not download or install
  --site ADDRESS         Public domain, https://IP, or http://IP
  --http-port PORT       Host HTTP port (default: 80)
  --https-port PORT      Host HTTPS port (default: 443)
  --bind ADDRESS         Host bind address (default: 0.0.0.0)
  --admin-user NAME      Initial administrator name (default: admin)
  --vector-store NAME    qdrant (default) or mysql_fallback
  --no-start             Download source and generate .env, without building/starting
  -y, --yes              Non-interactive installation
  -h, --help             Show this help

Examples:
  bash install.sh --site tokenhub.example.com
  bash install.sh --site http://localhost --http-port 8080 --https-port 8443 --bind 127.0.0.1 -y
  bash install.sh --dir /opt/tokenhub --ref main --download-only

Requires Git and Docker Engine 24+ with Compose 2.20+ (or newer).
Start Docker Desktop first on macOS; use install.ps1 for Windows PowerShell.
The destination must not already exist. Failed builds keep the downloaded source
and .env: retry with bash <directory>/deploy/docker/install.sh --build.
This entry point does not upgrade an existing installation or install Docker.
Upgrades: https://github.com/QianqianTech/Tokenhub/blob/main/docs/deployment/upgrade.md
TXT
}

value_required() { [ "$#" -ge 2 ] && [ -n "$2" ] && [ "${2#--}" = "$2" ] || die "$1 requires a value."; }
while [ "$#" -gt 0 ]; do
  case "$1" in
    --dir) value_required "$@"; INSTALL_DIR="$2"; shift 2 ;;
    --ref) value_required "$@"; REF="$2"; shift 2 ;;
    --download-only) DOWNLOAD_ONLY=1; shift ;;
    --check) CHECK_ONLY=1; shift ;;
    --site|--http-port|--https-port|--bind|--admin-user|--vector-store)
      value_required "$@"
      case "$1" in
        --http-port|--https-port)
          [[ "$2" =~ ^[0-9]{1,5}$ ]] && [ "$((10#$2))" -ge 1 ] && [ "$((10#$2))" -le 65535 ] || die "$1 must be 1-65535."
          INSTALL_ARGS+=("$1" "$((10#$2))") ;;
        --vector-store)
          case "$2" in qdrant|mysql_fallback) ;; *) die "Unknown vector store: $2" ;; esac
          INSTALL_ARGS+=("$1" "$2") ;;
        *) INSTALL_ARGS+=("$1" "$2") ;;
      esac
      shift 2 ;;
    --no-start|-y|--yes) INSTALL_ARGS+=("$1"); shift ;;
    -h|--help) usage; exit 0 ;;
    *) die "Unknown option: $1 (see --help)." ;;
  esac
done

case "$(uname -s)" in Linux|Darwin) ;; *) die "Use install.ps1 on Windows, or run this script inside WSL 2 Linux." ;; esac
case "$(uname -m)" in x86_64|aarch64|arm64) ;; *) die "Supported architectures: x86_64 and ARM64." ;; esac
command -v git >/dev/null 2>&1 || die "Git is required: https://git-scm.com/downloads"
case "$REF" in -*|'') die "Invalid Git ref." ;; esac
git check-ref-format "refs/heads/$REF" >/dev/null 2>&1 || die "Invalid Git ref: $REF"

if [ "$DOWNLOAD_ONLY" = 0 ]; then
  command -v docker >/dev/null 2>&1 || die "Install Docker first: https://docs.docker.com/engine/install/"
  docker info >/dev/null 2>&1 || die "Docker is not running or is inaccessible to this user. Start Docker and check docker info."
  [ "$(docker info --format '{{.OSType}}')" = linux ] || die "Switch Docker Desktop to Linux containers."
  engine="$(docker version --format '{{.Server.Version}}')"
  [[ "$engine" =~ ^([0-9]+)\. ]] && [ "${BASH_REMATCH[1]}" -ge 24 ] || die "Docker Engine 24+ is required (found $engine)."
  version="$(docker compose version --short)" || die "Docker Compose 2.20+ is required."
  [[ "$version" =~ ^v?([0-9]+)\.([0-9]+) ]] || die "Cannot read Docker Compose version: $version"
  major="${BASH_REMATCH[1]}" minor="${BASH_REMATCH[2]}"
  [ "$major" -gt 2 ] || { [ "$major" -eq 2 ] && [ "$minor" -ge 20 ]; } || die "Docker Compose 2.20+ is required (found $version)."
fi
info "Prerequisites passed. Source: $REPOSITORY ($REF)"
[ "$CHECK_ONLY" = 0 ] || exit 0

case "$INSTALL_DIR" in /*) ;; *) INSTALL_DIR="$PWD/$INSTALL_DIR" ;; esac
[ ! -e "$INSTALL_DIR" ] && [ ! -L "$INSTALL_DIR" ] || die "Destination already exists: $INSTALL_DIR. To resume, run its deploy/docker/install.sh --build; to upgrade, use upgrade.sh."
parent="$(dirname "$INSTALL_DIR")"
mkdir -p -- "$parent"
STAGING="$(mktemp -d "$parent/.tokenhub-download.XXXXXX")"
export GIT_TERMINAL_PROMPT=0
info "Downloading source from GitHub..."
git init --quiet "$STAGING/source"
git -C "$STAGING/source" remote add origin "$REPOSITORY"
git -C "$STAGING/source" fetch --depth 1 --no-tags origin "$REF" || die "GitHub download failed. Check the ref, network or Git proxy, then retry."
git -C "$STAGING/source" checkout --quiet --detach FETCH_HEAD
for file in deploy/docker/install.sh deploy/docker/compose.yml docker/release/php/Dockerfile .env.example; do
  [ -f "$STAGING/source/$file" ] || die "Selected ref does not contain the Docker installer: $file"
done
commit="$(git -C "$STAGING/source" rev-parse HEAD)"
# mkdir refuses a destination created by another process during the download.
mkdir -- "$INSTALL_DIR"
cp -R "$STAGING/source/." "$INSTALL_DIR/"
cleanup
STAGING=""
info "Source ready: $INSTALL_DIR"
info "Commit: $commit"
if [ "$DOWNLOAD_ONLY" = 1 ]; then
  info "Download complete. To install:"
  printf '  bash %q --build --tag %q\n' "$INSTALL_DIR/deploy/docker/install.sh" "source-${commit:0:12}"
  exit 0
fi
info "Building and installing TokenHub; the first build depends on your network and hardware."
if ! bash "$INSTALL_DIR/deploy/docker/install.sh" --build --tag "source-${commit:0:12}" ${INSTALL_ARGS[@]+"${INSTALL_ARGS[@]}"}; then
  die "Installation failed. Source and configuration are preserved at $INSTALL_DIR. Fix the reported error and rerun deploy/docker/install.sh --build there."
fi
