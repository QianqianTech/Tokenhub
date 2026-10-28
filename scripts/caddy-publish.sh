#!/usr/bin/env bash
set -euo pipefail

CADDYFILE="${CADDYFILE:-/etc/caddy/Caddyfile}"
SOURCE_FRAGMENT="${SOURCE_FRAGMENT:-/var/www/tokenhub/storage/caddy/waf-current.caddy}"
ACTIVE_FRAGMENT="${ACTIVE_FRAGMENT:-/var/lib/tokenhub/caddy/waf-current.caddy}"
BACKUP="${BACKUP:-/var/lib/tokenhub/caddy/waf-current.caddy.bak}"

test -f "$CADDYFILE" || { echo "Caddyfile not found: $CADDYFILE" >&2; exit 1; }
test -f "$SOURCE_FRAGMENT" || { echo "WAF fragment not found: $SOURCE_FRAGMENT" >&2; exit 1; }
mkdir -p "$(dirname "$ACTIVE_FRAGMENT")"
if test -f "$ACTIVE_FRAGMENT"; then
  cp "$ACTIVE_FRAGMENT" "$BACKUP"
fi
cp "$SOURCE_FRAGMENT" "$ACTIVE_FRAGMENT"
caddy validate --config "$CADDYFILE"
caddy reload --config "$CADDYFILE"
echo "Caddy configuration validated and reloaded"
