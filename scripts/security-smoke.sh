#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${BASE_URL:-https://localhost}"
ADMIN_USER="${ADMIN_USER:-admin}"
ADMIN_PASSWORD="${ADMIN_PASSWORD:-}"
API_KEY="${API_KEY:-}"
LOG_PATH="${LOG_PATH:-}"
TIMEOUT_SECONDS="${TIMEOUT_SECONDS:-20}"
EXPECT_QUERY_BLOCK="${EXPECT_QUERY_BLOCK:-0}"
EXPECT_ADMIN_IP_BLOCK="${EXPECT_ADMIN_IP_BLOCK:-0}"
BLOCKED_IP="${BLOCKED_IP:-203.0.113.10}"

fail() {
  echo "FAIL: $*" >&2
  exit 1
}

need_cmd() {
  command -v "$1" >/dev/null 2>&1 || fail "$1 is required"
}

http_code() {
  curl -sS -m "$TIMEOUT_SECONDS" -o /dev/null -w '%{http_code}' "$@"
}

assert_status_in() {
  local label="$1"
  local expected="$2"
  shift 2
  local code
  code="$(http_code "$@")"
  case " $expected " in
    *" $code "*) ;;
    *) fail "$label expected HTTP one of [$expected], got $code" ;;
  esac
}

assert_header() {
  local headers="$1"
  local name="$2"
  printf '%s\n' "$headers" | grep -qi "$name" || fail "$name header is missing"
}

assert_secret_absent() {
  local secret="$1"
  local target="$2"
  [[ -z "$secret" ]] && return
  [[ ! -e "$target" ]] && return
  if grep -R --fixed-strings "$secret" "$target" >/dev/null 2>&1; then
    fail "secret value was found in $target"
  fi
}

check_headers() {
  local headers
  headers="$(curl -sSI -m "$TIMEOUT_SECONDS" "$BASE_URL/admin/" || true)"
  assert_header "$headers" 'content-security-policy'
  assert_header "$headers" 'x-frame-options'
  assert_header "$headers" 'x-content-type-options'
  if [[ "$BASE_URL" == https://* ]]; then
    assert_header "$headers" 'strict-transport-security'
  else
    echo "SKIP: BASE_URL is not HTTPS; HSTS assertion skipped"
  fi
}

check_sensitive_paths() {
  curl -fsS -m "$TIMEOUT_SECONDS" "$BASE_URL/health" >/dev/null || fail "health endpoint failed"
  assert_status_in ".env block" "403 404" -I "$BASE_URL/.env"
  assert_status_in ".git block" "403 404" -I "$BASE_URL/.git/config"
  assert_status_in "backup file block" "403 404" -I "$BASE_URL/config.php.bak"
  assert_status_in "debug path block" "403 404" -I "$BASE_URL/debug"
  if [[ "$EXPECT_QUERY_BLOCK" = "1" ]]; then
    assert_status_in "abnormal query block" "403" "$BASE_URL/health?token=leak-check&secret=leak-check"
  else
    assert_status_in "abnormal query block" "200 400 403 404" "$BASE_URL/health?token=leak-check&secret=leak-check"
    echo "SKIP: EXPECT_QUERY_BLOCK=1 not set; abnormal query block is accepted as optional"
  fi
}

check_ip_whitelist() {
  if [[ "$EXPECT_ADMIN_IP_BLOCK" != "1" ]]; then
    echo "SKIP: EXPECT_ADMIN_IP_BLOCK=1 not set; IP whitelist block check skipped"
    return
  fi

  assert_status_in "admin IP whitelist block" "403" \
    -H "X-Forwarded-For: $BLOCKED_IP" \
    "$BASE_URL/admin/"
}

check_admin_security() {
  if [[ -z "$ADMIN_PASSWORD" ]]; then
    echo "SKIP: ADMIN_PASSWORD is not set; admin cookie and csrf checks skipped"
    return
  fi

  local tmp headers csrf code
  tmp="$(mktemp -d)"
  trap 'rm -rf "$tmp"' RETURN

  headers="$(curl -sS -i -m "$TIMEOUT_SECONDS" -c "$tmp/cookies.txt" \
    -H 'Content-Type: application/json' \
    -d "{\"username\":\"$ADMIN_USER\",\"password\":\"$ADMIN_PASSWORD\"}" \
    "$BASE_URL/admin-api/login")" || fail "admin login failed"

  printf '%s\n' "$headers" | grep -qi 'set-cookie' || fail "set-cookie header is missing"
  printf '%s\n' "$headers" | grep -qi 'httponly' || fail "admin cookie missing HttpOnly"
  printf '%s\n' "$headers" | grep -qi 'samesite=strict' || fail "admin cookie missing SameSite=Strict"
  if [[ "$BASE_URL" == https://* ]]; then
    printf '%s\n' "$headers" | grep -qi 'secure' || fail "admin cookie missing Secure"
  else
    echo "SKIP: BASE_URL is not HTTPS; Secure cookie assertion skipped"
  fi

  csrf="$(printf '%s\n' "$headers" | sed -n 's/.*"csrf_token":"\([^"]*\)".*/\1/p')"
  test -n "$csrf" || fail "csrf token missing from login response"

  code="$(http_code -b "$tmp/cookies.txt" \
    -H 'Content-Type: application/json' \
    -X POST \
    -d '{"name":"csrf-probe"}' \
    "$BASE_URL/admin-api/departments")"
  test "$code" = "419" || fail "write without csrf should return 419, got $code"
}

check_key_leakage() {
  if [[ -n "$API_KEY" ]]; then
    local tmp
    tmp="$(mktemp)"
    curl -sS -m "$TIMEOUT_SECONDS" \
      -H "Authorization: Bearer $API_KEY" \
      -H 'Content-Type: application/json' \
      -d '{"model":"security-probe","messages":[{"role":"user","content":"leak check"}]}' \
      "$BASE_URL/v1/chat/completions" >"$tmp" || true
    assert_secret_absent "$API_KEY" "$tmp"
    rm -f "$tmp"
  else
    echo "SKIP: API_KEY is not set; response key leakage check skipped"
  fi

  if [[ -n "$LOG_PATH" && -d "$LOG_PATH" ]]; then
    assert_secret_absent "$API_KEY" "$LOG_PATH"
    assert_secret_absent "$ADMIN_PASSWORD" "$LOG_PATH"
  else
    echo "SKIP: LOG_PATH is not a readable local directory; log desensitization check skipped"
  fi
}

need_cmd curl
need_cmd grep
need_cmd sed

check_sensitive_paths
check_ip_whitelist
check_headers
check_admin_security
check_key_leakage

echo "security smoke checks completed"
