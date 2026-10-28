#!/usr/bin/env bash
set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/tokenhub}"
ENV_FILE="${ENV_FILE:-/etc/tokenhub/tokenhub.env}"
BASE_URL="${BASE_URL:-http://127.0.0.1/health}"

fail() {
  echo "FAIL: $*" >&2
  exit 1
}

need_cmd() {
  command -v "$1" >/dev/null 2>&1 || fail "$1 is not installed or not in PATH"
}

need_cmd php
need_cmd caddy
need_cmd mysql
need_cmd redis-cli
need_cmd curl

php -r 'exit(PHP_VERSION_ID >= 80200 ? 0 : 1);' || fail "PHP 8.2+ is required"
php -m | grep -qi '^pdo_mysql$' || fail "pdo_mysql extension is required"
php -m | grep -qi '^redis$' || fail "redis extension is required"
mysql --version | grep -q ' 9\.7\.' || fail "MySQL 9.7 is required"
test -d "$APP_DIR" || fail "$APP_DIR does not exist"
test -f "$APP_DIR/public/index.php" || fail "public/index.php is missing"
test -f "$APP_DIR/scripts/migrate.php" || fail "scripts/migrate.php is missing"
test -f "$ENV_FILE" || fail "$ENV_FILE is missing"
test -w /var/log/tokenhub || fail "/var/log/tokenhub must be writable"
test -w /var/lib/tokenhub || fail "/var/lib/tokenhub must be writable"

if command -v systemctl >/dev/null 2>&1; then
  systemctl is-active --quiet php8.2-fpm || fail "php8.2-fpm is not active"
  systemctl is-active --quiet caddy || fail "caddy is not active"
  systemctl is-active --quiet redis-server || fail "redis-server is not active"
  systemctl is-active --quiet tokenhub-worker || fail "tokenhub-worker is not active"
fi

caddy validate --config /etc/caddy/Caddyfile >/dev/null || fail "Caddyfile validation failed"
curl -fsS "$BASE_URL" >/tmp/tokenhub-self-check-health.json || fail "health endpoint failed"
grep -q '"ok":true\|"status":"healthy"\|"status":"degraded"' /tmp/tokenhub-self-check-health.json || fail "unexpected health response"

echo "TokenHub Ubuntu self-check passed"
