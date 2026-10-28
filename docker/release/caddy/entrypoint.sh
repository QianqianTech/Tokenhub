#!/usr/bin/env sh
# Caddy entrypoint for the release image.
#  - validates the site configuration from TOKENHUB_SITE_ADDRESS / TOKENHUB_CADDY_TLS before starting
#  - reloads Caddy automatically when the certificate center rewrites storage/caddy/tls-current.caddy
#    (the php container cannot reach Caddy, so no manual `docker compose restart caddy` is needed)
#  - makes issued certificate files (not private keys) readable for the php container's status page
set -eu

CONFIG=/etc/caddy/Caddyfile
FRAGMENT=/srv/app/storage/caddy/tls-current.caddy
INTERVAL="${TOKENHUB_CADDY_WATCH_SECONDS:-10}"

mkdir -p /srv/app/storage/logs /srv/app/storage/caddy
touch "$FRAGMENT"

caddy validate --config "$CONFIG" --adapter caddyfile >/dev/null || {
    echo "Invalid Caddy configuration. Check TOKENHUB_SITE_ADDRESS='${TOKENHUB_SITE_ADDRESS:-}' and TOKENHUB_CADDY_TLS='${TOKENHUB_CADDY_TLS:-}'." >&2
    exit 78
}

caddy run --config "$CONFIG" --adapter caddyfile &
caddy_pid=$!
trap 'kill -TERM "$caddy_pid" 2>/dev/null; wait "$caddy_pid"; exit 0' TERM INT

share_certificates() {
    if [ -d /data/caddy/certificates ]; then
        find /data/caddy /data/caddy/certificates -maxdepth 0 -type d -exec chmod 755 {} + 2>/dev/null || true
        find /data/caddy/certificates -type d -exec chmod 755 {} + 2>/dev/null || true
        find /data/caddy/certificates -type f \( -name '*.crt' -o -name '*.json' \) -exec chmod 644 {} + 2>/dev/null || true
    fi
}

last="$(cksum "$FRAGMENT" 2>/dev/null || true)"
while kill -0 "$caddy_pid" 2>/dev/null; do
    sleep "$INTERVAL" &
    wait $! || true
    share_certificates
    current="$(cksum "$FRAGMENT" 2>/dev/null || true)"
    if [ "$current" != "$last" ]; then
        last="$current"
        if caddy validate --config "$CONFIG" --adapter caddyfile >/dev/null 2>&1; then
            caddy reload --config "$CONFIG" --adapter caddyfile && echo "tokenhub: certificate configuration reloaded" >&2
        else
            echo "tokenhub: tls-current.caddy is invalid; keeping the running configuration" >&2
        fi
    fi
done

wait "$caddy_pid"
