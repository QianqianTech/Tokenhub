#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${BASE_URL:-https://localhost}"
API_KEY="${API_KEY:?API_KEY is required}"
MODEL="${MODEL:-demo-model}"
ADMIN_USER="${ADMIN_USER:-admin}"
ADMIN_PASSWORD="${ADMIN_PASSWORD:-}"
CONCURRENCY="${CONCURRENCY:-10}"
REQUESTS="${REQUESTS:-20}"
STREAM_REQUESTS="${STREAM_REQUESTS:-3}"
TIMEOUT_SECONDS="${TIMEOUT_SECONDS:-30}"
EXPECT_RATE_LIMIT="${EXPECT_RATE_LIMIT:-0}"
LOG_PATH="${LOG_PATH:-}"
DASHBOARD_MAX_SECONDS="${DASHBOARD_MAX_SECONDS:-1.0}"
SSE_FIRST_BYTE_MAX_SECONDS="${SSE_FIRST_BYTE_MAX_SECONDS:-5.0}"
QUEUE_ALERT_THRESHOLD="${QUEUE_ALERT_THRESHOLD:-1000}"
REDIS_HOST="${REDIS_HOST:-}"
REDIS_PORT="${REDIS_PORT:-6379}"
REDIS_PASSWORD="${REDIS_PASSWORD:-}"
QUEUE_NAME="${QUEUE_NAME:-tokenhub:jobs}"
CADDY_LB_UPSTREAMS="${CADDY_LB_UPSTREAMS:-${TOKENHUB_PHP_FASTCGI:-}}"

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

post_json() {
  local endpoint="$1"
  local body="$2"
  local out="$3"
  local metric="${4:-}"
  local write_out=()
  if [[ -n "$metric" ]]; then
    write_out=(-w '%{time_total}')
  fi
  curl -sS -m "$TIMEOUT_SECONDS" \
    "${write_out[@]}" \
    -o "$out" \
    "$BASE_URL$endpoint" \
    -H "Authorization: Bearer $API_KEY" \
    -H "Content-Type: application/json" \
    -d "$body" >"${metric:-/dev/null}"
}

expect_auth_failure() {
  local code
  code="$(http_code \
    -H "Authorization: Bearer invalid-pressure-key" \
    -H "Content-Type: application/json" \
    -d "{\"model\":\"$MODEL\",\"messages\":[{\"role\":\"user\",\"content\":\"auth failure\"}]}" \
    "$BASE_URL/v1/chat/completions")"
  case "$code" in
    401|403|429) ;;
    *) fail "invalid API key should be rejected, got HTTP $code" ;;
  esac
}

check_async_logs() {
  if [[ -z "$LOG_PATH" || ! -d "$LOG_PATH" ]]; then
    echo "SKIP: LOG_PATH is not a readable local directory; async log write check skipped"
    return
  fi

  sleep 2
  find "$LOG_PATH" -type f \( -name '*.log' -o -name '*.jsonl' \) -mmin -10 -print -quit | grep -q . \
    || fail "no recent log file found under LOG_PATH=$LOG_PATH"
}

run_non_stream_batch() {
  local tmp="$1"
  local active=0
  local launched=0

  for i in $(seq 1 "$REQUESTS"); do
    launched=$((launched + 1))
    (
      post_json "/v1/chat/completions" \
        "{\"model\":\"$MODEL\",\"messages\":[{\"role\":\"user\",\"content\":\"pressure ping $i\"}]}" \
        "$tmp/non-stream-$i.json" \
        "$tmp/non-stream-$i.time"
    ) &
    active=$((active + 1))
    if [[ "$active" -ge "$CONCURRENCY" ]]; then
      wait -n || fail "one non-stream pressure request failed"
      active=$((active - 1))
    fi
  done

  wait || fail "one or more non-stream pressure requests failed"
  test "$launched" -eq "$REQUESTS" || fail "launched $launched requests, expected $REQUESTS"
  summarize_timings "$tmp"/non-stream-*.time
}

run_stream_checks() {
  local tmp="$1"
  local first_byte
  for i in $(seq 1 "$STREAM_REQUESTS"); do
    first_byte="$(curl -sS -m "$TIMEOUT_SECONDS" \
      -w '%{time_starttransfer}' \
      -o "$tmp/stream-$i.txt" \
      "$BASE_URL/v1/chat/completions" \
      -H "Authorization: Bearer $API_KEY" \
      -H "Content-Type: application/json" \
      -d "{\"model\":\"$MODEL\",\"stream\":true,\"messages\":[{\"role\":\"user\",\"content\":\"stream ping $i\"}]}" \
    )" || fail "SSE stream request $i failed"

    grep -Eq '(^data:|text/event-stream|"ok":true)' "$tmp/stream-$i.txt" \
      || fail "SSE stream request $i did not return stream-like output"
    awk -v actual="$first_byte" -v max="$SSE_FIRST_BYTE_MAX_SECONDS" 'BEGIN { exit !(actual <= max) }' \
      || fail "SSE first byte took ${first_byte}s, threshold is ${SSE_FIRST_BYTE_MAX_SECONDS}s"
  done
}

summarize_timings() {
  awk '
    NF { count += 1; total += $1; if ($1 > max) max = $1 }
    END {
      if (count == 0) {
        print "FAIL: no timing samples" > "/dev/stderr";
        exit 1;
      }
      printf "non-stream timing: count=%d avg=%.3fs max=%.3fs\n", count, total / count, max;
    }
  ' "$@"
}

admin_login() {
  local cookie_file="$1"
  local login_body="$2"
  curl -fsS -m "$TIMEOUT_SECONDS" -c "$cookie_file" \
    -H 'Content-Type: application/json' \
    -d "{\"username\":\"$ADMIN_USER\",\"password\":\"$ADMIN_PASSWORD\"}" \
    "$BASE_URL/admin-api/login" >"$login_body"
}

check_dashboard_latency() {
  if [[ -z "$ADMIN_PASSWORD" ]]; then
    echo "SKIP: ADMIN_PASSWORD is not set; dashboard latency check skipped"
    return
  fi

  local tmp="$1"
  admin_login "$tmp/admin-cookies.txt" "$tmp/admin-login.json" || fail "admin login failed for dashboard latency check"
  for endpoint in dashboard/realtime dashboard/trends dashboard/rankings dashboard/security dashboard/performance; do
    local total
    total="$(curl -sS -m "$TIMEOUT_SECONDS" \
      -w '%{time_total}' \
      -o "$tmp/${endpoint#dashboard/}.json" \
      -b "$tmp/admin-cookies.txt" \
      "$BASE_URL/admin-api/$endpoint")" || fail "$endpoint latency request failed"
    awk -v actual="$total" -v max="$DASHBOARD_MAX_SECONDS" 'BEGIN { exit !(actual <= max) }' \
      || fail "$endpoint took ${total}s, threshold is ${DASHBOARD_MAX_SECONDS}s"
  done
}

check_queue_backlog() {
  if [[ -z "$REDIS_HOST" ]]; then
    echo "SKIP: REDIS_HOST is not set; worker queue backlog check skipped"
    return
  fi
  if ! command -v redis-cli >/dev/null 2>&1; then
    echo "SKIP: redis-cli is not installed; worker queue backlog check skipped"
    return
  fi

  local args=(-h "$REDIS_HOST" -p "$REDIS_PORT")
  if [[ -n "$REDIS_PASSWORD" ]]; then
    args+=(-a "$REDIS_PASSWORD")
  fi
  local backlog
  backlog="$(redis-cli "${args[@]}" LLEN "$QUEUE_NAME")" || fail "failed to read Redis queue length"
  awk -v actual="$backlog" -v max="$QUEUE_ALERT_THRESHOLD" 'BEGIN { exit !(actual < max) }' \
    || fail "queue backlog $backlog reached threshold $QUEUE_ALERT_THRESHOLD"
}

check_caddy_lb_hint() {
  if [[ -z "$CADDY_LB_UPSTREAMS" ]]; then
    echo "SKIP: CADDY_LB_UPSTREAMS/TOKENHUB_PHP_FASTCGI is not set; Caddy load-balancing check skipped"
    return
  fi

  local upstream_count
  upstream_count="$(printf '%s\n' "$CADDY_LB_UPSTREAMS" | tr ', ' '\n' | grep -c . || true)"
  test "$upstream_count" -ge 2 || fail "Caddy load-balancing check needs at least 2 upstreams, got $upstream_count"
}

check_rate_limit_signal() {
  if [[ "$EXPECT_RATE_LIMIT" != "1" ]]; then
    echo "SKIP: EXPECT_RATE_LIMIT=1 not set; quota/429 assertion skipped"
    return
  fi

  local code
  code="$(http_code \
    -H "Authorization: Bearer $API_KEY" \
    -H "Content-Type: application/json" \
    -d "{\"model\":\"$MODEL\",\"messages\":[{\"role\":\"user\",\"content\":\"quota check\"}]}" \
    "$BASE_URL/v1/chat/completions")"
  test "$code" = "429" || fail "expected quota/rate limit HTTP 429, got $code"
}

need_cmd curl
need_cmd grep
need_cmd find
need_cmd awk

tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

expect_auth_failure
run_non_stream_batch "$tmp"
run_stream_checks "$tmp"
check_rate_limit_signal
check_async_logs
check_dashboard_latency "$tmp"
check_queue_backlog
check_caddy_lb_hint

echo "pressure smoke checks completed: requests=$REQUESTS concurrency=$CONCURRENCY stream_requests=$STREAM_REQUESTS"
