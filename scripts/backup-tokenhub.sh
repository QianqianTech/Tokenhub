#!/usr/bin/env bash
set -euo pipefail

BACKUP_DIR="${BACKUP_DIR:-/backup/tokenhub}"
STAMP="${STAMP:-$(date +%Y%m%d-%H%M%S)}"
TARGET_DIR="$BACKUP_DIR/$STAMP"
DRY_RUN="${DRY_RUN:-1}"
CONFIRM_BACKUP="${CONFIRM_BACKUP:-0}"

DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"
DB_DATABASE="${DB_DATABASE:-tokenhub}"
DB_USERNAME="${DB_USERNAME:-tokenhub}"
DB_PASSWORD="${DB_PASSWORD:-}"

CONFIG_PATH="${CONFIG_PATH:-/etc/tokenhub}"
CADDY_DATA_PATH="${CADDY_DATA_PATH:-/var/lib/caddy}"
CADDY_CONFIG_PATH="${CADDY_CONFIG_PATH:-/etc/caddy}"
MEDIA_PATH="${MEDIA_PATH:-/var/lib/tokenhub/media}"
EXPORT_PATH="${EXPORT_PATH:-/var/lib/tokenhub/exports}"

fail() {
  echo "FAIL: $*" >&2
  exit 1
}

need_cmd() {
  command -v "$1" >/dev/null 2>&1 || fail "$1 is required"
}

run_cmd() {
  if [[ "$DRY_RUN" = "1" ]]; then
    printf 'DRY-RUN:'
    printf ' %q' "$@"
    printf '\n'
    return
  fi
  "$@"
}

archive_dir() {
  local label="$1"
  local source="$2"
  local output="$3"

  if [[ ! -d "$source" ]]; then
    echo "SKIP: $label source directory not found: $source"
    return
  fi

  run_cmd tar -C "$(dirname "$source")" -czf "$TARGET_DIR/$output" "$(basename "$source")"
}

need_cmd mkdir

if [[ "$DRY_RUN" != "1" ]]; then
  test "$CONFIRM_BACKUP" = "1" || fail "set CONFIRM_BACKUP=1 and DRY_RUN=0 to create a real backup"
  need_cmd tar
  need_cmd sha256sum
  need_cmd mysqldump
fi

run_cmd mkdir -p "$TARGET_DIR"

if [[ "$DRY_RUN" = "1" ]]; then
  echo "DRY-RUN: would write backup set to $TARGET_DIR"
else
  test -d "$TARGET_DIR" || fail "backup target was not created: $TARGET_DIR"
fi

if [[ "$DRY_RUN" = "1" ]]; then
  run_cmd mysqldump \
    --host="$DB_HOST" \
    --port="$DB_PORT" \
    --user="$DB_USERNAME" \
    --single-transaction \
    --routines \
    --triggers \
    "$DB_DATABASE"
else
  if [[ -n "$DB_PASSWORD" ]]; then
    export MYSQL_PWD="$DB_PASSWORD"
  fi
  mysqldump \
    --host="$DB_HOST" \
    --port="$DB_PORT" \
    --user="$DB_USERNAME" \
    --single-transaction \
    --routines \
    --triggers \
    "$DB_DATABASE" >"$TARGET_DIR/mysql.sql"
  unset MYSQL_PWD || true
fi

archive_dir "TokenHub config" "$CONFIG_PATH" "config.tar.gz"
archive_dir "Caddy data" "$CADDY_DATA_PATH" "caddy-data.tar.gz"
archive_dir "Caddy config" "$CADDY_CONFIG_PATH" "caddy-config.tar.gz"
archive_dir "media assets" "$MEDIA_PATH" "media.tar.gz"
archive_dir "export archives" "$EXPORT_PATH" "exports.tar.gz"

if [[ "$DRY_RUN" != "1" ]]; then
  (
    cd "$TARGET_DIR"
    sha256sum ./* > SHA256SUMS
  )
  ln -sfn "$TARGET_DIR" "$BACKUP_DIR/latest"
fi

echo "TokenHub backup completed: $TARGET_DIR"
