#!/usr/bin/env bash
# Enable the multimodal model support release (MM-001..MM-032) on an existing TokenHub installation.
#
#   scripts/multimodal-upgrade.sh                      detect the installation and upgrade it
#   scripts/multimodal-upgrade.sh --mode docker        Docker kit (deploy/docker/.env)
#   sudo scripts/multimodal-upgrade.sh --mode native   source installation (/etc/tokenhub/tokenhub.env)
#   scripts/multimodal-upgrade.sh --mode dev           development stack (docker-compose.yml + .env)
#   scripts/multimodal-upgrade.sh --verify-only        only run the checks
#   scripts/multimodal-upgrade.sh --dry-run            print the plan, change nothing
#
# Steps:
#   1. REALTIME_INTERNAL_TOKEN: generated in the environment file when missing (an existing one is kept).
#   2. Backup, then database migrations: 2026_10_29_000001 adds model_prices.pricing_rules,
#      2026_10_29_000002 adds music_generation / realtime to the capability ENUMs.
#   3. Realtime gateway: image or binary built, service (compose / systemd) started, Caddy reloaded.
#   4. Checks: migration applied, column present, gateway healthy, internal API accepts the token,
#      /v1/realtime routed through Caddy to the gateway.
#
# Rollback: database changes are additive (one nullable column); restore the backup taken in step 2
# only if needed. Stop the gateway with `docker compose stop realtime-gateway` or
# `systemctl disable --now tokenhub-realtime`.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
MIGRATION=2026_10_29_000001_model_prices_pricing_rules.sql
MIGRATIONS=("$MIGRATION" 2026_10_29_000002_models_music_realtime_capabilities.sql 2026_10_29_000003_task_billing_settlement_modalities.sql)
MODE=auto DRY_RUN=0 VERIFY_ONLY=0 SKIP_BACKUP=0 TAG="" APP_DIR=/var/www/tokenhub INSTALL_GO=1
GO_VERSION="${GO_VERSION:-1.24.4}"

info() { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
ok() { printf '\033[1;32m ok\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33mWARN:\033[0m %s\n' "$*" >&2; }
die() { printf '\033[1;31mERROR:\033[0m %s\n' "$*" >&2; exit 1; }
run() {
  if [ "$DRY_RUN" = 1 ]; then printf '   [dry-run] %s\n' "$*"; else "$@"; fi
}

while [ $# -gt 0 ]; do
  case "$1" in
    --mode) MODE="${2:-}"; shift 2 ;;
    --tag) TAG="${2:-}"; shift 2 ;;
    --app-dir) APP_DIR="${2:-}"; shift 2 ;;
    --skip-backup) SKIP_BACKUP=1; shift ;;
    --no-go-install) INSTALL_GO=0; shift ;;
    --verify-only) VERIFY_ONLY=1; shift ;;
    --dry-run) DRY_RUN=1; shift ;;
    -h|--help) sed -n '2,24p' "$0" | sed 's/^# \{0,1\}//'; cat <<'TXT'
Options:
  --mode auto|dev|docker|native   installation type (default: detected)
  --tag TAG                       docker: use this image tag instead of building from source
  --app-dir DIR                   native: installation directory (default /var/www/tokenhub)
  --skip-backup                   do not back up the database first (not recommended)
  --no-go-install                 native: do not download Go when it is missing or older than 1.22
  --verify-only                   only run the checks of step 4
  --dry-run                       show what would be done
TXT
      exit 0 ;;
    *) die "Unknown option: $1 (see --help)" ;;
  esac
done

# ------------------------------------------------------------------ preflight
[ -f "$ROOT/database/migrations/$MIGRATION" ] && [ -f "$ROOT/realtime-gateway/main.go" ] \
  || die "This source tree does not contain the multimodal release (apply multimodal-model-support.patch first)."

if [ "$MODE" = auto ]; then
  found=()
  [ -f /etc/tokenhub/tokenhub.env ] && found+=(native)
  [ -f "$ROOT/deploy/docker/.env" ] && found+=(docker)
  [ -f "$ROOT/.env" ] && [ -f "$ROOT/docker-compose.yml" ] && found+=(dev)
  case "${#found[@]}" in
    0) die "No installation found (/etc/tokenhub/tokenhub.env, deploy/docker/.env or .env). Install first, or pass --mode." ;;
    1) MODE="${found[0]}" ;;
    *) die "Several installations found (${found[*]}); choose one with --mode." ;;
  esac
fi
case "$MODE" in
  native) ENV_FILE=/etc/tokenhub/tokenhub.env ;;
  docker) ENV_FILE="$ROOT/deploy/docker/.env" ;;
  dev) ENV_FILE="$ROOT/.env" ;;
  *) die "--mode must be auto, dev, docker or native" ;;
esac
[ -f "$ENV_FILE" ] || die "$ENV_FILE not found."

env_get() {
  awk -F= -v k="$1" '$1 == k { sub(/^[^=]*=/, ""); gsub(/^"|"$/, ""); v = $0 } END { if (v != "") print v }' "$ENV_FILE"
}

# Replaces or appends KEY=VALUE, keeping the file's owner and mode.
env_put() {
  local key="$1" value="$2" tmp
  tmp="$(mktemp "$ENV_FILE.XXXXXX")"
  KEY="$key" VALUE="$value" awk '
    BEGIN { k = ENVIRON["KEY"]; v = ENVIRON["VALUE"]; done = 0 }
    { split($0, parts, "="); if (parts[1] == k && $0 !~ /^[[:space:]]*#/) { if (!done) print k "=" v; done = 1 } else { print } }
    END { if (!done) print k "=" v }
  ' "$ENV_FILE" >"$tmp"
  cat "$tmp" >"$ENV_FILE"
  rm -f "$tmp"
}

random_token() {
  local out=""
  while [ "${#out}" -lt 48 ]; do
    out="$out$(LC_ALL=C tr -dc 'A-HJ-NP-Za-km-z2-9' </dev/urandom 2>/dev/null | head -c 48 || true)"
  done
  printf '%s' "${out:0:48}"
}

if [ "$MODE" = native ]; then
  [ "$(id -u)" = 0 ] || [ "$DRY_RUN" = 1 ] || [ "$VERIFY_ONLY" = 1 ] || die "Native mode needs root (sudo)."
else
  command -v docker >/dev/null 2>&1 || die "Docker is not installed."
  docker info >/dev/null 2>&1 || die "The Docker daemon is not running or this user cannot use it."
fi

dev_compose() { docker compose --project-directory "$ROOT" -f "$ROOT/docker-compose.yml" "$@"; }
kit_compose() {
  local files=(-f "$ROOT/deploy/docker/compose.yml")
  [ -z "$TAG" ] && files+=(-f "$ROOT/deploy/docker/compose.build.yml")
  docker compose --project-directory "$ROOT/deploy/docker" --env-file "$ENV_FILE" "${files[@]}" "$@"
}
stack() { if [ "$MODE" = dev ]; then dev_compose "$@"; else kit_compose "$@"; fi; }

# Runs a PHP command in the application (container or installation directory).
app_php() {
  case "$MODE" in
    native) (cd "$APP_DIR" && sudo -u tokenhub php "$@") ;;
    *) stack exec -T php php "$@" ;;
  esac
}

info "Mode: $MODE   environment file: $ENV_FILE"

# ------------------------------------------------------------------ 1. internal token
step_token() {
  info "Step 1/3: REALTIME_INTERNAL_TOKEN"
  local current
  current="$(env_get REALTIME_INTERNAL_TOKEN || true)"
  if [ "${#current}" -ge 24 ] && [ "$current" != change-me ]; then
    ok "already set (${#current} characters, kept)"
  elif [ "$DRY_RUN" = 1 ]; then
    printf '   [dry-run] generate a 48-character token in %s\n' "$ENV_FILE"
  else
    env_put REALTIME_INTERNAL_TOKEN "$(random_token)"
    ok "generated in $ENV_FILE (shared by PHP and the gateway; not printed)"
  fi
}

# ------------------------------------------------------------------ 2 + 3 per mode
backup_dev() {
  local dir="$ROOT/storage/backups" file
  file="$dir/tokenhub-before-multimodal-$(date +%Y%m%d-%H%M%S).sql.gz"
  run mkdir -p "$dir"
  if [ "$DRY_RUN" = 1 ]; then printf '   [dry-run] mysqldump -> %s\n' "$file"; return; fi
  dev_compose exec -T mysql sh -c 'exec mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction --routines --triggers "$MYSQL_DATABASE"' | gzip >"$file"
  [ -s "$file" ] || die "Backup failed ($file is empty)."
  ok "database backup: $file"
}

upgrade_dev() {
  info "Step 2/3: backup and migrations (development stack)"
  [ "$SKIP_BACKUP" = 1 ] && warn "Skipping the backup." || backup_dev
  # php and the gateway read REALTIME_INTERNAL_TOKEN from the environment: recreate them.
  run dev_compose build realtime-gateway
  run dev_compose up -d php queue-worker realtime-gateway
  run dev_compose exec -T php php bin/migrate.php
  info "Step 3/3: realtime gateway and Caddy"
  # The Caddyfile is bind-mounted; restart so the new /v1/realtime, /v1beta and :8082 routes load.
  run dev_compose up -d caddy
  run dev_compose restart caddy
}

upgrade_docker() {
  info "Step 2/3 + 3/3: deploy/docker/upgrade.sh (backup, images, migrations in the init container, start)"
  local args=()
  if [ -n "$TAG" ]; then args+=(--tag "$TAG"); else args+=(--build); fi
  [ "$SKIP_BACKUP" = 1 ] && args+=(--skip-backup)
  run "$ROOT/deploy/docker/upgrade.sh" "${args[@]}"
}

go_ok() {
  command -v go >/dev/null 2>&1 || return 1
  local v
  v="$(go env GOVERSION 2>/dev/null | sed 's/^go//')"
  [ "$(printf '%s\n1.22\n' "${v%%[^0-9.]*}" | sort -V | head -n1)" = 1.22 ]
}

ensure_go() {
  go_ok && { ok "Go $(go env GOVERSION)"; return; }
  [ -x /usr/local/go/bin/go ] && export PATH="/usr/local/go/bin:$PATH" && go_ok && { ok "Go $(go env GOVERSION)"; return; }
  [ "$INSTALL_GO" = 1 ] || die "Go 1.22+ is required to build the realtime gateway (remove --no-go-install to download it)."
  local arch
  case "$(uname -m)" in x86_64) arch=amd64 ;; aarch64|arm64) arch=arm64 ;; *) die "Unsupported CPU: $(uname -m)" ;; esac
  info "Installing Go $GO_VERSION to /usr/local/go"
  run curl -fL "https://go.dev/dl/go$GO_VERSION.linux-$arch.tar.gz" -o "/tmp/go$GO_VERSION.tar.gz" \
    || die "Downloading Go failed. Install Go 1.22+ manually (or copy /usr/local/go from another machine) and rerun."
  run rm -rf /usr/local/go
  run tar -C /usr/local -xzf "/tmp/go$GO_VERSION.tar.gz"
  export PATH="/usr/local/go/bin:$PATH"
}

upgrade_native() {
  info "Step 2/3 + 3/3: deploy/linux/upgrade.sh (backup, code, migrations, gateway, Caddy)"
  ensure_go
  local args=(--app-dir "$APP_DIR")
  [ "$SKIP_BACKUP" = 1 ] && args+=(--skip-backup)
  # install.sh builds the gateway only when `go` is on PATH.
  run env PATH="$PATH" "$ROOT/deploy/linux/upgrade.sh" "${args[@]}"
}

# ------------------------------------------------------------------ 4. checks
FAILED=0
check() { if "$@"; then :; else FAILED=$((FAILED + 1)); fi; }

check_migration() {
  local out
  out="$(app_php bin/migrate.php --status 2>&1)" || { warn "migrate --status failed: $out"; return 1; }
  local name missing=0
  for name in "${MIGRATIONS[@]}"; do
    if printf '%s\n' "$out" | grep -q "\[applied\] $name"; then ok "migration $name applied"; else warn "migration $name is not applied"; missing=1; fi
  done
  [ "$missing" = 0 ] || return 1
  if printf '%s\n' "$out" | grep -q '\[pending\]'; then warn "other migrations are still pending:"; printf '%s\n' "$out" | grep '\[pending\]' >&2; fi
}

check_column() {
  local out
  out="$(app_php -r '$c = require "app/Bootstrap/container.php"; $rows = $c[TokenHub\Support\Database::class]->select("SHOW COLUMNS FROM model_prices LIKE ?", ["pricing_rules"]); echo $rows === [] ? "missing" : "present";' 2>&1 | tail -n1)"
  if [ "$out" = present ]; then ok "model_prices.pricing_rules present"; else warn "model_prices.pricing_rules: $out"; return 1; fi
  out="$(app_php -r '$c = require "app/Bootstrap/container.php"; $rows = $c[TokenHub\Support\Database::class]->select("SHOW COLUMNS FROM models LIKE ?", ["capability"]); echo str_contains((string) ($rows[0]["Type"] ?? ""), "music_generation") ? "present" : "missing";' 2>&1 | tail -n1)"
  if [ "$out" = present ]; then ok "models.capability accepts music_generation / realtime"; else warn "models.capability ENUM: $out"; return 1; fi
  out="$(app_php -r '$c = require "app/Bootstrap/container.php"; $rows = $c[TokenHub\Support\Database::class]->select("SHOW COLUMNS FROM multimodal_tasks LIKE ?", ["settled_at"]); echo $rows === [] ? "missing" : "present";' 2>&1 | tail -n1)"
  if [ "$out" = present ]; then ok "multimodal_tasks.settled_at present (task billing settlement)"; else warn "multimodal_tasks.settled_at: $out"; return 1; fi
}

# Prints "<http status> <body>" for a request made where the gateway runs.
gateway_request() {
  local url="$1" token="$2" body="$3"
  case "$MODE" in
    native)
      curl -sS -m 10 -o /tmp/tokenhub-check.$$ -w '%{http_code}' -H 'Content-Type: application/json' \
        -H "X-TokenHub-Internal-Token: $token" --data "$body" "$url" || true
      printf ' '; cat /tmp/tokenhub-check.$$ 2>/dev/null; rm -f /tmp/tokenhub-check.$$ ;;
    *)
      # GNU wget in the gateway image; the token stays inside the container's argv, not on the host.
      stack exec -T -e CHECK_TOKEN="$token" -e CHECK_URL="$url" -e CHECK_BODY="$body" realtime-gateway sh -c \
        'out=$(wget -q -S -O - --content-on-error --header="Content-Type: application/json" --header="X-TokenHub-Internal-Token: $CHECK_TOKEN" --post-data="$CHECK_BODY" "$CHECK_URL" 2>/tmp/h); code=$(awk "/HTTP\\//{c=\$2} END{print c}" /tmp/h); echo "$code $out"' 2>/dev/null || true ;;
  esac
}

check_gateway() {
  local internal health
  case "$MODE" in
    native)
      internal=http://127.0.0.1:8082
      systemctl is-active --quiet tokenhub-realtime && ok "tokenhub-realtime.service active" || { warn "tokenhub-realtime.service is not active (journalctl -u tokenhub-realtime)"; return 1; }
      health="$(curl -sS -m 5 http://127.0.0.1:8090/healthz 2>&1 || true)" ;;
    *)
      internal=http://caddy:8082
      health="$(stack exec -T realtime-gateway wget -qO- http://127.0.0.1:8090/healthz 2>&1 || true)" ;;
  esac
  if [ "$health" = ok ]; then ok "gateway /healthz"; else warn "gateway /healthz: ${health:-no answer} (logs: realtime-gateway)"; return 1; fi

  # The usage endpoint with an unknown session has no side effects: 404 SESSION_NOT_FOUND proves the
  # token matches; 403 FORBIDDEN means PHP and the gateway see different tokens.
  local token reply
  token="$(env_get REALTIME_INTERNAL_TOKEN || true)"
  reply="$(gateway_request "$internal/internal/realtime/usage" "$token" '{"session_id":"rt_upgrade_check","usage":{},"final":true}')"
  case "$reply" in
    404*SESSION_NOT_FOUND*) ok "internal API accepts the token (via $internal)" ;;
    403*) warn "internal API rejected the token: PHP was not restarted with the new REALTIME_INTERNAL_TOKEN"; return 1 ;;
    *) warn "internal API check: ${reply:-no answer} (is the :8082 site in the Caddyfile?)"; return 1 ;;
  esac
  reply="$(gateway_request "$internal/internal/realtime/usage" "wrong-token" '{}')"
  case "$reply" in 403*) ok "internal API rejects a wrong token" ;; *) warn "internal API accepted a wrong token: $reply"; return 1 ;; esac
}

check_public_route() {
  local url host scheme port code body
  case "$MODE" in
    dev) url="http://127.0.0.1:$(env_get CADDY_HTTP_PORT || true)"; url="${url%:}"; [ "$url" = "http://127.0.0.1" ] && url=http://127.0.0.1:8080 ;;
    *) url="$(env_get APP_URL || true)" ;;
  esac
  [ -n "$url" ] || { warn "APP_URL is not set; skipped the public route check"; return 0; }
  scheme="${url%%://*}"; host="${url#*://}"; host="${host%%/*}"; port="${host##*:}"
  [ "$port" = "$host" ] && port="" && { [ "$scheme" = https ] && port=443 || port=80; }
  host="${host%:*}"
  if [ "$MODE" = docker ] && [ "$port" = 80 ] && [ -n "$(env_get TOKENHUB_HTTP_PORT || true)" ]; then port="$(env_get TOKENHUB_HTTP_PORT)"; fi
  if [ "$MODE" = docker ] && [ "$port" = 443 ] && [ -n "$(env_get TOKENHUB_HTTPS_PORT || true)" ]; then port="$(env_get TOKENHUB_HTTPS_PORT)"; fi
  # Resolve the site name to this machine so the check does not depend on DNS.
  local addr=127.0.0.1
  if [ "$MODE" = docker ]; then
    case "$(env_get TOKENHUB_BIND_ADDRESS || true)" in ''|0.0.0.0|'*') ;; *) addr="$(env_get TOKENHUB_BIND_ADDRESS)" ;; esac
  fi
  body="$(curl -sS -k -m 10 --resolve "$host:$port:$addr" -o - -w '\n%{http_code}' "$scheme://$host:$port/v1/realtime" 2>&1 || true)"
  code="$(printf '%s' "$body" | tail -n1)"
  if [ "$code" = 400 ] && printf '%s' "$body" | grep -q REALTIME_BAD_HANDSHAKE; then
    ok "$scheme://$host:$port/v1/realtime is served by the gateway"
  else
    warn "/v1/realtime via Caddy answered $code (expected 400 REALTIME_BAD_HANDSHAKE): $(printf '%s' "$body" | head -c 200)"
    return 1
  fi
}

verify() {
  info "Step 4: checks"
  check check_migration
  check check_column
  check check_gateway
  check check_public_route
  if [ "$FAILED" = 0 ]; then
    info "All checks passed. Realtime speech: wss://<site>/v1/realtime?model=<realtime model>; prices: admin console, model prices, pricing_rules."
  else
    die "$FAILED check(s) failed (see the warnings above)."
  fi
}

# ------------------------------------------------------------------ main
if [ "$VERIFY_ONLY" = 0 ]; then
  step_token
  case "$MODE" in
    dev) upgrade_dev ;;
    docker) upgrade_docker ;;
    native) upgrade_native ;;
  esac
fi
if [ "$DRY_RUN" = 1 ]; then
  info "Dry run: nothing was changed; the checks run after a real upgrade."
  exit 0
fi
verify
