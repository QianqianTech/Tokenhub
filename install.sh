#!/usr/bin/env bash
# Download TokenHub from GitHub and build its production Docker stack.
set -euo pipefail

REPOSITORY=https://github.com/QianqianTech/Tokenhub.git
ARCHIVE_BASE=https://codeload.github.com/QianqianTech/Tokenhub/tar.gz
INSTALL_DIR="$PWD/tokenhub"
REF=main
DOWNLOAD_ONLY=0 CHECK_ONLY=0 START=1 QUICK=0 SITE_GIVEN=0 PUBLIC_IP=0
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
  --quick                Download an archive and automatically choose images or a source build
  --download-only        Download source without requiring or starting Docker
  --check                Check prerequisites only; do not download or install
  --site ADDRESS         Public domain, https://IP, or http://IP
  --public-ip            Detect this server's public IPv4 and configure HTTPS for it
  --http-port PORT       Host HTTP port (default: 80)
  --https-port PORT      Host HTTPS port (default: 443)
  --bind ADDRESS         Host bind address (default: 0.0.0.0)
  --admin-user NAME      Initial administrator name (default: admin)
  --vector-store NAME    qdrant (default) or mysql_fallback
  --no-start             Download source and generate .env, without building/starting
  -y, --yes              Non-interactive installation
  -h, --help             Show this help

Examples:
  bash install.sh --quick
  bash install.sh --quick --public-ip
  bash install.sh --site tokenhub.example.com
  bash install.sh --site http://localhost --http-port 8080 --https-port 8443 --bind 127.0.0.1 -y
  bash install.sh --dir /opt/tokenhub --ref main --download-only

Requires Docker Engine 24+ and Compose 2.20+. Use Git for the source checkout
or curl and tar with --quick. Source builds also require Buildx.
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
    --quick) QUICK=1; shift ;;
    --download-only) DOWNLOAD_ONLY=1; shift ;;
    --check) CHECK_ONLY=1; shift ;;
    --site|--http-port|--https-port|--bind|--admin-user|--vector-store)
      value_required "$@"
      case "$1" in
        --site) SITE_GIVEN=1; INSTALL_ARGS+=("$1" "$2") ;;
        --http-port|--https-port)
          [[ "$2" =~ ^[0-9]{1,5}$ ]] && [ "$((10#$2))" -ge 1 ] && [ "$((10#$2))" -le 65535 ] || die "$1 must be 1-65535."
          INSTALL_ARGS+=("$1" "$((10#$2))") ;;
        --vector-store)
          case "$2" in qdrant|mysql_fallback) ;; *) die "Unknown vector store: $2" ;; esac
          INSTALL_ARGS+=("$1" "$2") ;;
        *) INSTALL_ARGS+=("$1" "$2") ;;
      esac
      shift 2 ;;
    --no-start) START=0; INSTALL_ARGS+=("$1"); shift ;;
    --public-ip) PUBLIC_IP=1; INSTALL_ARGS+=("$1"); shift ;;
    -y|--yes) INSTALL_ARGS+=("$1"); shift ;;
    -h|--help) usage; exit 0 ;;
    *) die "Unknown option: $1 (see --help)." ;;
  esac
done

case "$(uname -s)" in Linux|Darwin) ;; *) die "Use install.ps1 on Windows, or run this script inside WSL 2 Linux." ;; esac
case "$(uname -m)" in x86_64|aarch64|arm64) ;; *) die "Supported architectures: x86_64 and ARM64." ;; esac
case "$REF" in -*|'') die "Invalid Git ref." ;; esac
[ "$SITE_GIVEN" = 0 ] || [ "$PUBLIC_IP" = 0 ] || die "Choose --site or --public-ip."
if [ "$QUICK" = 1 ]; then
  command -v curl >/dev/null 2>&1 && command -v tar >/dev/null 2>&1 || die "--quick requires curl and tar."
  [[ "$REF" =~ ^[A-Za-z0-9][A-Za-z0-9._/-]*$ && "$REF" != *..* ]] || die "Invalid archive ref: $REF"
  if [ "$PUBLIC_IP" = 1 ]; then
    INSTALL_ARGS=(--https-port 8443 "${INSTALL_ARGS[@]}")
  elif [ "$SITE_GIVEN" = 0 ]; then
    INSTALL_ARGS=(--site http://localhost --bind 127.0.0.1 --http-port 8080 --https-port 8443 ${INSTALL_ARGS[@]+"${INSTALL_ARGS[@]}"})
  fi
else
  command -v git >/dev/null 2>&1 || die "Git is required: https://git-scm.com/downloads"
  git check-ref-format "refs/heads/$REF" >/dev/null 2>&1 || die "Invalid Git ref: $REF"
fi

require_buildx() {
  docker buildx version >/dev/null 2>&1 || die "Docker Buildx is required for source builds (Ubuntu: install the docker-buildx-plugin package)."
}

if [ "$DOWNLOAD_ONLY" = 0 ]; then
  command -v docker >/dev/null 2>&1 || die "Install Docker Engine with the Compose and Buildx plugins, or start Docker Desktop: https://docs.docker.com/engine/install/"
  docker info >/dev/null 2>&1 || die "Docker is not running or is inaccessible to this user. Start Docker and check docker info."
  [ "$(docker info --format '{{.OSType}}')" = linux ] || die "Switch Docker Desktop to Linux containers."
  engine="$(docker version --format '{{.Server.Version}}')" || die "Cannot read the Docker Engine version."
  [[ "$engine" =~ ^([0-9]+)\. ]] && [ "${BASH_REMATCH[1]}" -ge 24 ] || die "Docker Engine 24+ is required (found $engine)."
  version="$(docker compose version --short 2>/dev/null)" || die "Docker Compose 2.20+ is required (Ubuntu: install the docker-compose-plugin package)."
  [[ "$version" =~ ^v?([0-9]+)\.([0-9]+) ]] || die "Cannot read Docker Compose version: $version"
  major="${BASH_REMATCH[1]}" minor="${BASH_REMATCH[2]}"
  [ "$major" -gt 2 ] || { [ "$major" -eq 2 ] && [ "$minor" -ge 20 ]; } || die "Docker Compose 2.20+ is required (found $version)."
  if [ "$START" = 1 ] && [ "$QUICK" = 0 ]; then require_buildx; fi
fi
info "Prerequisites passed. Source: $REPOSITORY ($REF)"
[ "$CHECK_ONLY" = 0 ] || exit 0

case "$INSTALL_DIR" in /*) ;; *) INSTALL_DIR="$PWD/$INSTALL_DIR" ;; esac
[ ! -e "$INSTALL_DIR" ] && [ ! -L "$INSTALL_DIR" ] || die "Destination already exists: $INSTALL_DIR. To resume, run its deploy/docker/install.sh --build; to upgrade, use upgrade.sh."
if [ "$QUICK" = 1 ] && [ "$DOWNLOAD_ONLY" = 0 ] && [ "$START" = 1 ]; then
  project_filter="label=com.docker.compose.project=${COMPOSE_PROJECT_NAME:-tokenhub}"
  containers="$(docker ps -aq --filter "$project_filter")" || die "Cannot check existing Docker installations."
  volumes="$(docker volume ls -q --filter "$project_filter")" || die "Cannot check existing Docker volumes."
  [ -z "$containers$volumes" ] || die "An existing TokenHub project or data volume was found. Use its current deployment directory to resume or upgrade."
fi
parent="$(dirname "$INSTALL_DIR")"
mkdir -p -- "$parent"
STAGING="$(mktemp -d "$parent/.tokenhub-download.XXXXXX")"
info "Downloading source from GitHub..."
if [ "$QUICK" = 1 ]; then
  archive="$STAGING/source.tar.gz"
  curl -q --fail --show-error --silent --location --retry 3 --connect-timeout 10 --max-time 300 \
    --output "$archive" "$ARCHIVE_BASE/$REF" || die "Could not download the TokenHub archive. Check GitHub connectivity and retry."
  mkdir "$STAGING/source"
  tar -xzf "$archive" -C "$STAGING/source" --strip-components=1 || die "Could not unpack the TokenHub archive."
  if command -v sha256sum >/dev/null 2>&1; then
    digest="$(sha256sum <"$archive")"
  else
    digest="$(shasum -a 256 <"$archive")"
  fi
  revision="${digest%% *}"
  revision_label="Archive SHA-256"
else
  export GIT_TERMINAL_PROMPT=0
  git init --quiet "$STAGING/source"
  git -C "$STAGING/source" remote add origin "$REPOSITORY"
  if ! git -C "$STAGING/source" fetch --depth 1 --no-tags origin "$REF"; then
    info "GitHub download failed. Retrying once over HTTP/1.1..."
    git -C "$STAGING/source" -c http.version=HTTP/1.1 fetch --depth 1 --no-tags origin "$REF" \
      || die "GitHub download failed. Check the ref and Git proxy, or use a verified TokenHub source archive."
  fi
  git -C "$STAGING/source" checkout --quiet --detach FETCH_HEAD
  revision="$(git -C "$STAGING/source" rev-parse HEAD)"
  revision_label=Commit
fi
for file in deploy/docker/install.sh deploy/docker/compose.yml docker/release/php/Dockerfile .env.example; do
  [ -f "$STAGING/source/$file" ] || die "Selected ref does not contain the Docker installer: $file"
done
image_args=(--build --tag "source-${revision:0:12}")
image_mode="Building TokenHub from source"
if [ "$QUICK" = 1 ] && [ "$DOWNLOAD_ONLY" = 0 ]; then
  release="$(tr -d '[:space:]' <"$STAGING/source/VERSION")" || die "The source archive is missing VERSION."
  [[ "$release" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || die "The source archive has an invalid VERSION."
  arch="$(docker version --format '{{.Server.Arch}}')" || die "Cannot read the Docker server architecture."
  case "$arch" in
    amd64|x86_64) arch=amd64 ;;
    arm64|aarch64) arch=arm64 ;;
    *) die "Unsupported Docker server architecture: $arch" ;;
  esac
  cached=1
  for image in tokenhub-php tokenhub-caddy tokenhub-rag-worker tokenhub-realtime-gateway; do
    if [ "$(docker image inspect --format '{{.Architecture}}' "$image:$release" 2>/dev/null || true)" != "$arch" ]; then
      cached=0
      break
    fi
  done
  if [ "$cached" = 1 ]; then
    image_args=(--tag "$release")
    image_mode="Using locally loaded TokenHub $release images"
  elif [ "$arch" = arm64 ] && [ "$release" = 1.0.4 ]; then
    image_args=(--image-prefix docker.io/leofan2022/ --tag "$release")
    image_mode="Using published TokenHub $release images"
  elif [ "$START" = 1 ]; then
    require_buildx
  fi
fi
# mkdir refuses a destination created by another process during the download.
mkdir -- "$INSTALL_DIR"
cp -R "$STAGING/source/." "$INSTALL_DIR/"
cleanup
STAGING=""
info "Source ready: $INSTALL_DIR"
info "$revision_label: $revision"
if [ "$DOWNLOAD_ONLY" = 1 ]; then
  info "Download complete. To install:"
  printf '  bash %q --build --tag %q\n' "$INSTALL_DIR/deploy/docker/install.sh" "source-${revision:0:12}"
  exit 0
fi
info "$image_mode."
if ! bash "$INSTALL_DIR/deploy/docker/install.sh" "${image_args[@]}" ${INSTALL_ARGS[@]+"${INSTALL_ARGS[@]}"}; then
  info "Retry the same install mode after fixing the error above:"
  printf '  bash %q' "$INSTALL_DIR/deploy/docker/install.sh"
  printf ' %q' "${image_args[@]}" ${INSTALL_ARGS[@]+"${INSTALL_ARGS[@]}"}
  printf '\n'
  die "Installation failed. Source and configuration are preserved at $INSTALL_DIR."
fi
