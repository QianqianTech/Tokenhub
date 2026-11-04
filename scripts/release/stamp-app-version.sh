#!/bin/sh
# Stamp the TokenHub APP service worker with a build id so every release gets a
# fresh offline cache (public/app/sw.js, placeholder __TOKENHUB_APP_BUILD__).
#
#   scripts/release/stamp-app-version.sh <app root> [build id]
#
# Without a build id: <VERSION>-<git short sha> when the root is a git checkout,
# otherwise <VERSION>-<content hash of public/app>. Used by the release images
# (docker/release/php, docker/release/caddy) and deploy/linux/install.sh. An
# unstamped sw.js (running from a source checkout) uses the cache name "dev".
set -eu

ROOT="${1:-.}"
BUILD="${2:-}"
SW="$ROOT/public/app/sw.js"
[ -f "$SW" ] || { echo "stamp-app-version: $SW not found" >&2; exit 1; }

if [ -z "$BUILD" ]; then
  version="$(tr -d '[:space:]' <"$ROOT/VERSION" 2>/dev/null || true)"
  [ -n "$version" ] || version="0"
  rev=""
  if command -v git >/dev/null 2>&1; then
    rev="$(git -C "$ROOT" rev-parse --short=12 HEAD 2>/dev/null || true)"
  fi
  if [ -z "$rev" ]; then
    if command -v sha256sum >/dev/null 2>&1; then
      rev="$(cd "$ROOT/public/app" && find . -type f ! -name sw.js | LC_ALL=C sort | xargs cat | sha256sum | cut -c1-12)"
    elif command -v shasum >/dev/null 2>&1; then
      rev="$(cd "$ROOT/public/app" && find . -type f ! -name sw.js | LC_ALL=C sort | xargs cat | shasum -a 256 | cut -c1-12)"
    fi
  fi
  BUILD="$version${rev:+-$rev}"
fi

# Cache names: keep to a safe character set.
BUILD="$(printf '%s' "$BUILD" | tr -c 'A-Za-z0-9._-' '-' | cut -c1-64)"
[ -n "$BUILD" ] || BUILD="0"

if grep -q '__TOKENHUB_APP_BUILD__' "$SW"; then
  tmp="$SW.tmp.$$"
  sed "s/__TOKENHUB_APP_BUILD__/$BUILD/" "$SW" >"$tmp"
  cat "$tmp" >"$SW"
  rm -f "$tmp"
  echo "APP service worker build: $BUILD"
else
  echo "APP service worker already stamped; left unchanged."
fi
