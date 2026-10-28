#!/usr/bin/env sh
set -eu

cd /srv/app

test -f vendor/autoload.php || {
    echo 'Packaged Composer dependencies are missing.' >&2
    exit 70
}

mkdir -p storage/logs storage/exports storage/media storage/caddy
touch storage/caddy/waf-current.caddy storage/caddy/tls-current.caddy

exec "$@"
