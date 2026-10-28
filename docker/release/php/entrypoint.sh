#!/usr/bin/env sh
# Release entrypoint shared by the php, queue-worker and init containers.
#  - prepares the storage volume (directories, Caddy fragments, ownership)
#  - refuses to start php-fpm with an unsafe production configuration (bin/doctor.php)
#  - runs every command except php-fpm as www-data
set -eu

cd /srv/app

test -f vendor/autoload.php || {
    echo 'Packaged Composer dependencies are missing.' >&2
    exit 70
}

mkdir -p storage/logs storage/exports storage/media storage/cache storage/caddy
touch storage/caddy/tls-current.caddy

if [ "$(id -u)" = 0 ]; then
    # Only touch entries with the wrong owner so large volumes start quickly.
    find storage ! -user www-data -exec chown www-data:www-data {} + 2>/dev/null || true
fi

if [ "${1:-}" = php-fpm ] && [ "${TOKENHUB_SKIP_DOCTOR:-0}" != 1 ]; then
    php bin/doctor.php --config || {
        echo 'Refusing to start: fix the configuration problems above (set TOKENHUB_SKIP_DOCTOR=1 to bypass).' >&2
        exit 78
    }
fi

if [ "$(id -u)" = 0 ] && [ "${1:-}" != php-fpm ]; then
    exec setpriv --reuid=www-data --regid=www-data --init-groups "$@"
fi

exec "$@"
