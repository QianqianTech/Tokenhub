#!/usr/bin/env sh
set -eu

cd /srv/app

if [ ! -f .env ] && [ -f .env.example ]; then
  cp .env.example .env
fi

if [ -f composer.json ] && [ ! -f vendor/autoload.php ]; then
  has_lock=0
  while [ "$has_lock" -eq 0 ] && ! mkdir /tmp/tokenhub-composer-install.lock 2>/dev/null; do
    sleep 1
    if [ -f vendor/autoload.php ]; then
      break
    fi
  done

  if [ ! -f vendor/autoload.php ] && [ -d /tmp/tokenhub-composer-install.lock ]; then
    has_lock=1
  fi

  if [ ! -f vendor/autoload.php ]; then
    composer install --no-interaction --prefer-dist
  fi

  if [ "$has_lock" -eq 1 ]; then
    rmdir /tmp/tokenhub-composer-install.lock
  fi
fi

mkdir -p storage/logs storage/exports storage/media

exec "$@"
