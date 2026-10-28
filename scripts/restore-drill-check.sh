#!/usr/bin/env bash
set -euo pipefail

BACKUP_DIR="${BACKUP_DIR:-/backup/tokenhub/latest}"
CONFIRM_RESTORE="${CONFIRM_RESTORE:-0}"
RESTORE_TARGET="${RESTORE_TARGET:-/tmp/tokenhub-restore-drill}"
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"
DB_DATABASE="${DB_DATABASE:-tokenhub_restore_drill}"
DB_USERNAME="${DB_USERNAME:-tokenhub}"
DB_PASSWORD="${DB_PASSWORD:-}"

fail() {
  echo "FAIL: $*" >&2
  exit 1
}

need_cmd() {
  command -v "$1" >/dev/null 2>&1 || fail "$1 is required"
}

require_file() {
  local file="$1"
  test -s "$BACKUP_DIR/$file" || fail "backup file missing or empty: $BACKUP_DIR/$file"
}

check_tar() {
  local file="$1"
  if [[ -f "$BACKUP_DIR/$file" ]]; then
    tar -tzf "$BACKUP_DIR/$file" >/dev/null || fail "archive is not readable: $file"
  else
    echo "SKIP: optional archive not present: $file"
  fi
}

restore_archives_to_scratch() {
  case "$RESTORE_TARGET" in
    /tmp/tokenhub-restore-drill|/tmp/tokenhub-restore-drill/*|*/tokenhub-restore-drill|*/tokenhub-restore-drill/*) ;;
    *) fail "RESTORE_TARGET must be a tokenhub-restore-drill scratch directory, got: $RESTORE_TARGET" ;;
  esac
  rm -rf "$RESTORE_TARGET"
  mkdir -p "$RESTORE_TARGET"
  for file in config.tar.gz caddy-data.tar.gz caddy-config.tar.gz media.tar.gz exports.tar.gz; do
    if [[ -f "$BACKUP_DIR/$file" ]]; then
      mkdir -p "$RESTORE_TARGET/${file%.tar.gz}"
      tar -xzf "$BACKUP_DIR/$file" -C "$RESTORE_TARGET/${file%.tar.gz}"
    fi
  done
}

maybe_restore_database() {
  if [[ "$CONFIRM_RESTORE" != "1" ]]; then
    echo "SKIP: CONFIRM_RESTORE=1 not set; database import drill skipped"
    return
  fi

  need_cmd mysql
  if [[ -n "$DB_PASSWORD" ]]; then
    export MYSQL_PWD="$DB_PASSWORD"
  fi

  mysql --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USERNAME" \
    -e "CREATE DATABASE IF NOT EXISTS \`$DB_DATABASE\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
  mysql --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USERNAME" "$DB_DATABASE" <"$BACKUP_DIR/mysql.sql"
  mysql --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USERNAME" "$DB_DATABASE" \
    -e "SHOW TABLES;" | grep -q . || fail "restored database has no tables"

  unset MYSQL_PWD || true
}

need_cmd tar
need_cmd grep

test -d "$BACKUP_DIR" || fail "BACKUP_DIR is not a directory: $BACKUP_DIR"
require_file mysql.sql

for file in config.tar.gz caddy-data.tar.gz caddy-config.tar.gz media.tar.gz exports.tar.gz; do
  check_tar "$file"
done

if [[ -f "$BACKUP_DIR/SHA256SUMS" ]]; then
  need_cmd sha256sum
  (cd "$BACKUP_DIR" && sha256sum -c SHA256SUMS)
else
  echo "SKIP: SHA256SUMS not present"
fi

restore_archives_to_scratch
maybe_restore_database

echo "TokenHub restore drill checks completed: backup=$BACKUP_DIR scratch=$RESTORE_TARGET"
