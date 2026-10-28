#!/usr/bin/env sh
set -eu

mkdir -p /srv/app/storage/logs /srv/app/storage/caddy
touch /srv/app/storage/caddy/waf-current.caddy /srv/app/storage/caddy/tls-current.caddy

exec caddy run --config /etc/caddy/Caddyfile --adapter caddyfile
