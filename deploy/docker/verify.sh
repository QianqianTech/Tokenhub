#!/usr/bin/env bash
# Acceptance check for a Docker installation. Run it after install.sh / upgrade.sh.
#
#   ./verify.sh                 services, health, readiness, self-check, admin console
#   ./verify.sh --backup-drill  also back up and restore into this installation (same data, takes a while)
#
# Writes verify-report-<time>.txt next to this script; exit code 1 when any check fails.
set -uo pipefail

# shellcheck source=lib.sh
. "$(cd "$(dirname "$0")" && pwd)/lib.sh"

DRILL=0
for arg in "$@"; do
  case "$arg" in
    --backup-drill) DRILL=1 ;;
    -h|--help) sed -n '2,8p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) die "Unknown option: $arg" ;;
  esac
done

require_docker
[ -f "$ENV_FILE" ] || die "$ENV_FILE not found; run install.sh first."

REPORT="$KIT_DIR/verify-report-$(date +%Y%m%d-%H%M%S).txt"
FAILURES=0
record() {
  local status="$1" name="$2" detail="${3:-}"
  [ "$status" = PASS ] || [ "$status" = WARN ] || FAILURES=$((FAILURES + 1))
  printf '%-5s %-28s %s\n' "$status" "$name" "$detail" | tee -a "$REPORT"
}

{
  echo "TokenHub verification $(date -u +%Y-%m-%dT%H:%M:%SZ)"
  echo "image tag: $(env_get TOKENHUB_IMAGE_TAG)  site: $(env_get TOKENHUB_SITE_ADDRESS)  url: $(env_get APP_URL)"
  docker version --format 'docker {{.Server.Version}} ({{.Server.Arch}})' 2>/dev/null
  docker compose version 2>/dev/null
  echo
} | tee "$REPORT"

# ---------------------------------------------------------------- containers
for service in mysql redis qdrant php queue-worker scheduler rag-worker caddy; do
  state="$(container_state "$service")"
  case "$state" in
    *" healthy") record PASS "container $service" "$state" ;;
    running*) record WARN "container $service" "$state (no health result yet)" ;;
    *) record FAIL "container $service" "$state" ;;
  esac
done
case "$(container_state init)" in
  "exited 0"*) record PASS "init (migrations, admin)" "completed" ;;
  *) record FAIL "init (migrations, admin)" "$(container_state init) - see: docker compose logs init" ;;
esac

# ---------------------------------------------------------------- HTTP through Caddy
APP_URL_VALUE="$(env_get APP_URL)"
scheme="${APP_URL_VALUE%%://*}"
hostport="${APP_URL_VALUE#*://}"; hostport="${hostport%%/*}"
host="${hostport%%:*}"
if [ "$scheme" = https ]; then port="$(env_get TOKENHUB_HTTPS_PORT)"; else port="$(env_get TOKENHUB_HTTP_PORT)"; fi
port="${port:-80}"
base="$scheme://$host:$port"
curl_local() { curl -sS -k --max-time 15 --resolve "$host:$port:127.0.0.1" "$@"; }

for path in /health /ready; do
  body="$(curl_local -w ' HTTP=%{http_code}' "$base$path" 2>&1)"
  case "$body" in
    *"HTTP=200"*) record PASS "GET $path" "${body:0:160}" ;;
    *) record FAIL "GET $path" "${body:0:300}" ;;
  esac
done
code="$(curl_local -o /dev/null -w '%{http_code}' "$base/admin/" 2>/dev/null || echo 000)"
[ "$code" = 200 ] && record PASS "GET /admin/" "HTTP $code" || record FAIL "GET /admin/" "HTTP $code"
code="$(curl_local -o /dev/null -w '%{http_code}' "$base/v1/models" 2>/dev/null || echo 000)"
[ "$code" = 401 ] && record PASS "GET /v1/models (no key)" "HTTP 401 as expected" || record FAIL "GET /v1/models (no key)" "HTTP $code, expected 401"

# ---------------------------------------------------------------- self-check inside the stack
if doctor="$(compose exec -T php php bin/doctor.php 2>&1)"; then
  record PASS "bin/doctor.php" "$(printf '%s\n' "$doctor" | grep -c '\[ OK \]') ok, $(printf '%s\n' "$doctor" | grep -c '\[WARN\]') warnings"
else
  record FAIL "bin/doctor.php" "$(printf '%s\n' "$doctor" | grep '\[FAIL\]' | head -3 | tr '\n' ' ')"
fi
printf '%s\n' "$doctor" >>"$REPORT"
if status="$(compose exec -T php php bin/migrate.php --status 2>&1 | tail -n1)"; then
  case "$status" in *" 0 pending."*) record PASS "migrations" "$status" ;; *) record FAIL "migrations" "$status" ;; esac
fi

# ---------------------------------------------------------------- optional backup / restore drill
if [ "$DRILL" = 1 ]; then
  if BACKUP_DIR="$KIT_DIR/backups" KEEP=0 "$KIT_DIR/backup.sh" >>"$REPORT" 2>&1; then
    latest="$(ls -1d "$KIT_DIR"/backups/20* | sort | tail -n1)"
    record PASS "backup.sh" "$latest"
    start=$(date +%s)
    if CONFIRM_RESTORE=1 "$KIT_DIR/restore.sh" "$latest" >>"$REPORT" 2>&1; then
      record PASS "restore.sh" "restored in $(( $(date +%s) - start ))s (RTO sample)"
      body="$(curl_local -w ' HTTP=%{http_code}' "$base/ready" 2>&1)"
      case "$body" in *"HTTP=200"*) record PASS "GET /ready after restore" "" ;; *) record FAIL "GET /ready after restore" "${body:0:200}" ;; esac
    else
      record FAIL "restore.sh" "see $REPORT"
    fi
  else
    record FAIL "backup.sh" "see $REPORT"
  fi
fi

echo | tee -a "$REPORT"
if [ "$FAILURES" -eq 0 ]; then
  echo "All checks passed. Report: $REPORT" | tee -a "$REPORT"
else
  echo "$FAILURES check(s) failed. Report: $REPORT (send it together with: docker compose logs --tail=200)" | tee -a "$REPORT"
  exit 1
fi
