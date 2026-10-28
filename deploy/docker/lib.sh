# Shared helpers for the TokenHub Docker scripts (sourced, not executed).
# shellcheck shell=bash

KIT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENV_FILE="$KIT_DIR/.env"

info() { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33mWARN:\033[0m %s\n' "$*" >&2; }
die() { printf '\033[1;31mERROR:\033[0m %s\n' "$*" >&2; exit 1; }

compose() {
  local files=(-f "$KIT_DIR/compose.yml")
  if [ "${TOKENHUB_BUILD:-0}" = 1 ]; then
    files+=(-f "$KIT_DIR/compose.build.yml")
  fi
  docker compose --project-directory "$KIT_DIR" --env-file "$ENV_FILE" "${files[@]}" "$@"
}

require_docker() {
  command -v docker >/dev/null 2>&1 || die "Docker is not installed. Install Docker Engine (Linux) or Docker Desktop (Windows/macOS) first."
  docker info >/dev/null 2>&1 || die "The Docker daemon is not running or this user cannot access it (try sudo or add the user to the docker group)."
  docker compose version >/dev/null 2>&1 || die "Docker Compose v2 is required (the 'docker compose' plugin)."
  local version major minor
  version="$(docker compose version --short 2>/dev/null | sed 's/^v//')"
  major="${version%%.*}"
  minor="$(printf '%s' "$version" | cut -d. -f2)"
  if [ "${major:-0}" -lt 2 ] || { [ "${major:-0}" -eq 2 ] && [ "${minor:-0}" -lt 20 ]; }; then
    die "Docker Compose $version is too old; 2.20 or newer is required."
  fi
}

env_get() {
  local key="$1"
  [ -f "$ENV_FILE" ] || return 0
  awk -F= -v k="$key" '$1 == k { sub(/^[^=]*=/, ""); gsub(/^"|"$/, ""); v = $0 } END { if (v != "") print v }' "$ENV_FILE"
}

env_set() {
  local key="$1" value="$2" tmp
  tmp="$(mktemp "$ENV_FILE.XXXXXX")"
  KEY="$key" VALUE="$value" awk '
    BEGIN { k = ENVIRON["KEY"]; v = ENVIRON["VALUE"]; done = 0 }
    {
      split($0, parts, "=")
      if (parts[1] == k && $0 !~ /^[[:space:]]*#/) { print k "=" v; done = 1 } else { print }
    }
    END { if (!done) print k "=" v }
  ' "$ENV_FILE" >"$tmp"
  chmod 600 "$tmp"
  mv "$tmp" "$ENV_FILE"
}

random_alnum() {
  local length="${1:-32}" out=""
  while [ "${#out}" -lt "$length" ]; do
    out="$out$(LC_ALL=C tr -dc 'A-HJ-NP-Za-km-z2-9' </dev/urandom 2>/dev/null | head -c "$length" || true)"
  done
  printf '%s' "${out:0:$length}"
}

# Older .env files predate the realtime gateway: give them a shared internal token.
ensure_realtime_token() {
  local current
  current="$(env_get REALTIME_INTERNAL_TOKEN)"
  if [ "${#current}" -lt 24 ] || [ "$current" = change-me ]; then
    env_set REALTIME_INTERNAL_TOKEN "$(random_alnum 48)"
    info "Generated REALTIME_INTERNAL_TOKEN in $ENV_FILE"
  fi
}

php_image() {
  printf '%stokenhub-php:%s' "$(env_get TOKENHUB_IMAGE_PREFIX)" "$(env_get TOKENHUB_IMAGE_TAG)"
}

container_state() {
  local id
  id="$(compose ps -a -q "$1" 2>/dev/null | head -n1)"
  [ -n "$id" ] || { echo missing; return; }
  docker inspect -f '{{.State.Status}} {{.State.ExitCode}} {{if .State.Health}}{{.State.Health.Status}}{{end}}' "$id"
}

wait_for_init() {
  local timeout="${1:-900}" waited=0 state
  info "Waiting for the init container (database migrations, first administrator)..."
  while [ "$waited" -lt "$timeout" ]; do
    state="$(container_state init)"
    case "$state" in
      "exited 0"*) info "Initialisation finished."; return 0 ;;
      exited*) compose logs --no-color --tail=80 init >&2 || true; die "Initialisation failed (see the log above)." ;;
    esac
    sleep 5
    waited=$((waited + 5))
  done
  compose logs --no-color --tail=80 init >&2 || true
  die "Initialisation did not finish within ${timeout}s."
}

wait_for_healthy() {
  local service="$1" timeout="${2:-300}" waited=0 state
  while [ "$waited" -lt "$timeout" ]; do
    state="$(container_state "$service")"
    case "$state" in
      *" healthy") return 0 ;;
      exited*|dead*) compose logs --no-color --tail=80 "$service" >&2 || true; die "$service stopped unexpectedly." ;;
    esac
    sleep 3
    waited=$((waited + 3))
  done
  compose logs --no-color --tail=80 "$service" >&2 || true
  die "$service did not become healthy within ${timeout}s."
}

detect_host_ip() {
  local ip=""
  if command -v hostname >/dev/null 2>&1; then
    ip="$(hostname -I 2>/dev/null | awk '{print $1}')"
  fi
  if [ -z "$ip" ] && command -v ipconfig >/dev/null 2>&1; then
    ip="$(ipconfig getifaddr en0 2>/dev/null || true)"
  fi
  printf '%s' "${ip:-localhost}"
}
