#!/usr/bin/env sh
# Development entrypoint: the repository is bind mounted at /srv/app.
set -eu

cd /srv/app

if [ ! -f .env ] && [ -f .env.example ]; then
  cp .env.example .env
  sed -i 's/^APP_ENV=.*/APP_ENV=local/' .env
  echo 'Created .env from .env.example (APP_ENV=local).' >&2
fi

if [ -f composer.json ] && [ ! -f vendor/autoload.php ]; then
  # Several containers share the bind mount; only one runs composer.
  if mkdir /srv/app/.composer-install.lock 2>/dev/null; then
    trap 'rmdir /srv/app/.composer-install.lock 2>/dev/null || true' EXIT
    composer install --no-interaction --prefer-dist
    rmdir /srv/app/.composer-install.lock
    trap - EXIT
  else
    waited=0
    while [ ! -f vendor/autoload.php ] && [ "$waited" -lt 600 ]; do
      sleep 2
      waited=$((waited + 2))
    done
  fi
fi

mkdir -p storage/logs storage/exports storage/media storage/cache storage/caddy
touch storage/caddy/tls-current.caddy

exec "$@"
