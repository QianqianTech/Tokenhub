#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${BASE_URL:-http://127.0.0.1}"
ADMIN_USER="${ADMIN_USER:-admin}"
ADMIN_PASSWORD="${ADMIN_PASSWORD:-}"
API_KEY="${API_KEY:-}"
MODEL="${MODEL:-}"
TIMEOUT_SECONDS="${TIMEOUT_SECONDS:-25}"

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

post_gateway() {
  local endpoint="$1"
  local body="$2"
  local output="$3"
  curl -fsS -m "$TIMEOUT_SECONDS" \
    -H "Authorization: Bearer $API_KEY" \
    -H "Content-Type: application/json" \
    -d "$body" \
    "$BASE_URL$endpoint" >"$output"
}

need_cmd curl
need_cmd grep
need_cmd sed

tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

curl -fsS -m "$TIMEOUT_SECONDS" "$BASE_URL/health" >"$tmp/health.json" || fail "public health endpoint failed"
grep -Eq '"ok":true|"status":"healthy"|"status":"degraded"' "$tmp/health.json" || fail "health response is not recognized"

curl -fsS -m "$TIMEOUT_SECONDS" "$BASE_URL/admin/" >"$tmp/admin.html" || fail "admin static page failed"
grep -q 'TokenHub' "$tmp/admin.html" || fail "admin static page does not contain TokenHub"

curl -fsS -m "$TIMEOUT_SECONDS" "$BASE_URL/screen/" >"$tmp/screen.html" || fail "screen static page failed"
grep -q 'id="screen"' "$tmp/screen.html" || fail "screen static page is missing its main view"
grep -q '/screen/assets/screen.js' "$tmp/screen.html" || fail "screen static page is missing its application script"

assert_status_in ".env block" "403 404" -I "$BASE_URL/.env"
assert_status_in ".git block" "403 404" -I "$BASE_URL/.git/config"
assert_status_in "download protection" "400 401 403 404" "$BASE_URL/download/not-a-real-token"
assert_status_in "sensitive path block" "403 404" -I "$BASE_URL/phpinfo"

headers="$(curl -sSI -m "$TIMEOUT_SECONDS" "$BASE_URL/admin/" || true)"
printf '%s\n' "$headers" | grep -qi 'content-security-policy' || fail "CSP header is missing"
printf '%s\n' "$headers" | grep -qi 'x-frame-options' || fail "X-Frame-Options header is missing"
printf '%s\n' "$headers" | grep -qi 'x-content-type-options' || fail "X-Content-Type-Options header is missing"

if [[ -n "$ADMIN_PASSWORD" ]]; then
  curl -fsS -m "$TIMEOUT_SECONDS" -c "$tmp/cookies.txt" \
    -H 'Content-Type: application/json' \
    -d "{\"username\":\"$ADMIN_USER\",\"password\":\"$ADMIN_PASSWORD\"}" \
    "$BASE_URL/admin-api/login" >"$tmp/login.json" || fail "admin login failed"
  csrf="$(sed -n 's/.*"csrf_token":"\([^"]*\)".*/\1/p' "$tmp/login.json")"
  test -n "$csrf" || fail "csrf token missing from login response"
  curl -fsS -m "$TIMEOUT_SECONDS" -b "$tmp/cookies.txt" "$BASE_URL/admin-api/me" >"$tmp/me.json" || fail "admin me failed"
  grep -q '"ok":true' "$tmp/me.json" || fail "admin me response is not ok"

  for resource in departments users providers models model-prices quota-policies api-keys usage-records audit-logs; do
    curl -fsS -m "$TIMEOUT_SECONDS" -b "$tmp/cookies.txt" "$BASE_URL/admin-api/$resource" >"$tmp/$resource.json" \
      || fail "admin resource list failed: $resource"
  done

  for dashboard_path in dashboard/realtime dashboard/trends dashboard/rankings dashboard/security dashboard/performance dashboard/multimodal; do
    dashboard_file="${dashboard_path#dashboard/}"
    curl -fsS -m "$TIMEOUT_SECONDS" -b "$tmp/cookies.txt" "$BASE_URL/admin-api/$dashboard_path" >"$tmp/dashboard-$dashboard_file.json" \
      || fail "admin $dashboard_path failed"
  done

  curl -fsS -m "$TIMEOUT_SECONDS" -b "$tmp/cookies.txt" "$BASE_URL/admin-api/dashboard-share-links" >"$tmp/share-links.json" \
    || fail "dashboard share link list failed"

  code="$(http_code -b "$tmp/cookies.txt" \
    -H 'Content-Type: application/json' \
    -X POST \
    -d '{"name":"csrf-probe"}' \
    "$BASE_URL/admin-api/providers")"
  test "$code" = "419" || fail "admin write without csrf should return 419, got $code"
else
  echo "SKIP: ADMIN_PASSWORD is not set; admin authenticated checks skipped"
fi

if [[ -n "$API_KEY" && -n "$MODEL" ]]; then
  post_gateway "/v1/chat/completions" \
    "{\"model\":\"$MODEL\",\"messages\":[{\"role\":\"user\",\"content\":\"ping\"}]}" \
    "$tmp/chat.json" || fail "OpenAI chat gateway failed"

  post_gateway "/v1/responses" \
    "{\"model\":\"$MODEL\",\"input\":\"ping\"}" \
    "$tmp/responses.json" || fail "OpenAI responses gateway failed"

  post_gateway "/v1/embeddings" \
    "{\"model\":\"$MODEL\",\"input\":\"ping\"}" \
    "$tmp/embeddings.json" || fail "OpenAI embeddings gateway failed"

  post_gateway "/v1/messages" \
    "{\"model\":\"$MODEL\",\"max_tokens\":32,\"messages\":[{\"role\":\"user\",\"content\":\"ping\"}]}" \
    "$tmp/messages.json" || fail "Anthropic messages gateway failed"

  curl -sS -m "$TIMEOUT_SECONDS" \
    -H "Authorization: Bearer $API_KEY" \
    -H "Content-Type: application/json" \
    -d "{\"model\":\"$MODEL\",\"stream\":true,\"messages\":[{\"role\":\"user\",\"content\":\"stream ping\"}]}" \
    "$BASE_URL/v1/chat/completions" >"$tmp/stream.txt" || fail "SSE stream gateway failed"
  grep -Eq '(^data:|text/event-stream|"ok":true)' "$tmp/stream.txt" || fail "SSE stream response is not stream-like"

  for endpoint in /v1/audio/transcriptions /v1/audio/speech /v1/images/generations /v1/videos/generations; do
    code="$(http_code \
      -H "Authorization: Bearer $API_KEY" \
      -H "Content-Type: application/json" \
      -d "{\"model\":\"$MODEL\",\"input\":\"acceptance probe\"}" \
      "$BASE_URL$endpoint")"
    case "$code" in
      200|201|202|400|422|501) ;;
      *) fail "$endpoint returned unexpected HTTP $code" ;;
    esac
  done
else
  echo "SKIP: API_KEY and MODEL are not both set; gateway compatibility checks skipped"
fi

echo "TokenHub acceptance checks completed"
