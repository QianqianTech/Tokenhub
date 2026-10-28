#!/usr/bin/env bash
set -euo pipefail

CADDYFILE="${CADDYFILE:-/etc/caddy/Caddyfile}"
ACTIVE_FRAGMENT="${ACTIVE_FRAGMENT:-/var/lib/tokenhub/caddy/waf-current.caddy}"
BACKUP="${BACKUP:-/var/lib/tokenhub/caddy/waf-current.caddy.bak}"

test -f "$BACKUP" || { echo "Backup fragment not found: $BACKUP" >&2; exit 1; }
cp "$BACKUP" "$ACTIVE_FRAGMENT"
caddy validate --config "$CADDYFILE"
caddy reload --config "$CADDYFILE"
echo "Caddy WAF fragment rolled back and reloaded"
