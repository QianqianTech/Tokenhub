#!/usr/bin/env bash
# Backup for source installations (deploy/linux/install.sh). Docker installations use deploy/docker/backup.sh.
#
#   sudo DRY_RUN=0 CONFIRM_BACKUP=1 BACKUP_DIR=/backup/tokenhub scripts/backup-tokenhub.sh
#
# When ENV_FILE exists (default /etc/tokenhub/tokenhub.env) the DB_*, REDIS_PASSWORD and paths are read from it.
set -euo pipefail

ENV_FILE="${ENV_FILE:-/etc/tokenhub/tokenhub.env}"
if [[ -r "$ENV_FILE" ]]; then
  env_default() { local value; value="$(sed -n "s/^$1=//p" "$ENV_FILE" | tail -n1 | sed 's/^"//; s/"$//')"; [[ -n "$value" ]] && printf '%s' "$value"; return 0; }
  : "${DB_HOST:=$(env_default DB_HOST)}" "${DB_PORT:=$(env_default DB_PORT)}" "${DB_DATABASE:=$(env_default DB_DATABASE)}"
  : "${DB_USERNAME:=$(env_default DB_USERNAME)}" "${DB_PASSWORD:=$(env_default DB_PASSWORD)}" "${REDIS_PASSWORD:=$(env_default REDIS_PASSWORD)}"
  : "${MEDIA_PATH:=$(env_default MEDIA_PATH)}" "${EXPORT_PATH:=$(env_default EXPORT_PATH)}" "${RAG_VECTOR_STORE:=$(env_default RAG_VECTOR_STORE)}"
  : "${QDRANT_URL:=$(env_default QDRANT_URL)}" "${QDRANT_API_KEY:=$(env_default QDRANT_API_KEY)}"
fi

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
# RAG source uploads/packages (storage/rag) and the Qdrant vector store.
RAG_STORAGE_PATH="${RAG_STORAGE_PATH:-/var/lib/tokenhub/rag}"
QDRANT_URL="${QDRANT_URL:-}"
QDRANT_API_KEY="${QDRANT_API_KEY:-}"
QDRANT_COLLECTION_PREFIX="${QDRANT_COLLECTION_PREFIX:-tokenhub_rag}"
APP_DIR="${APP_DIR:-/var/www/tokenhub}"
STORAGE_PATH="${STORAGE_PATH:-$APP_DIR/storage}"
CADDY_FRAGMENT_PATH="${CADDY_FRAGMENT_PATH:-/var/lib/tokenhub/caddy}"
RAG_VECTOR_STORE="${RAG_VECTOR_STORE:-qdrant}"
REDIS_HOST_BACKUP="${REDIS_HOST_BACKUP:-127.0.0.1}"
REDIS_PASSWORD="${REDIS_PASSWORD:-}"
REDIS_DATA_PATH="${REDIS_DATA_PATH:-/var/lib/redis}"
PHP_BIN="${PHP_BIN:-php}"

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
    --no-tablespaces \
    --routines \
    --triggers \
    "$DB_DATABASE" >"$TARGET_DIR/mysql.sql"
  unset MYSQL_PWD || true
fi


if command -v redis-cli >/dev/null 2>&1; then
  if [[ "$DRY_RUN" = "1" ]]; then
    echo "DRY-RUN: redis-cli -h $REDIS_HOST_BACKUP SAVE"
  else
    REDISCLI_AUTH="$REDIS_PASSWORD" redis-cli -h "$REDIS_HOST_BACKUP" SAVE >/dev/null || echo "WARN: Redis SAVE failed"
  fi
  archive_dir "Redis data" "$REDIS_DATA_PATH" "redis-data.tar.gz"
fi

archive_dir "TokenHub config" "$CONFIG_PATH" "config.tar.gz"
archive_dir "Caddy data" "$CADDY_DATA_PATH" "caddy-data.tar.gz"
archive_dir "Caddy config" "$CADDY_CONFIG_PATH" "caddy-config.tar.gz"
archive_dir "media assets" "$MEDIA_PATH" "media.tar.gz"
archive_dir "export archives" "$EXPORT_PATH" "exports.tar.gz"
archive_dir "application storage" "$STORAGE_PATH" "storage.tar.gz"
archive_dir "TLS fragments" "$CADDY_FRAGMENT_PATH" "caddy-fragments.tar.gz"
archive_dir "RAG uploads" "$RAG_STORAGE_PATH" "rag-storage.tar.gz"

# Qdrant: one snapshot per TokenHub collection, downloaded next to the SQL dump
# so vectors and rag_* rows come from the same backup window. Without
# QDRANT_URL the step is reported as skipped (vectors must then be rebuilt
# from rag_chunks after restore, see docs/deployment/backup-restore.md).
backup_qdrant() {
  if [[ "$RAG_VECTOR_STORE" = "mysql_fallback" ]]; then
    echo "SKIP: RAG_VECTOR_STORE=mysql_fallback; no Qdrant"
    return
  fi
  if [[ -z "$QDRANT_URL" ]]; then
    echo "SKIP: QDRANT_URL not set; vector snapshots not taken"
    return
  fi
  if [[ "$DRY_RUN" = "1" ]]; then
    echo "DRY-RUN: would snapshot Qdrant collections with prefix $QDRANT_COLLECTION_PREFIX from $QDRANT_URL"
    return
  fi
  need_cmd curl
  local auth=()
  if [[ -n "$QDRANT_API_KEY" ]]; then
    auth=(-H "api-key: $QDRANT_API_KEY")
  fi
  mkdir -p "$TARGET_DIR/qdrant"
  local collections
  collections="$(curl -fsS "${auth[@]}" "$QDRANT_URL/collections" | grep -o '"name":"[^"]*"' | cut -d'"' -f4 | grep "^${QDRANT_COLLECTION_PREFIX}" || true)"
  for collection in $collections; do
    local snapshot
    snapshot="$(curl -fsS -X POST "${auth[@]}" "$QDRANT_URL/collections/$collection/snapshots" | grep -o '"name":"[^"]*"' | head -1 | cut -d'"' -f4)"
    test -n "$snapshot" || fail "Qdrant snapshot failed for $collection"
    curl -fsS "${auth[@]}" -o "$TARGET_DIR/qdrant/$collection.snapshot" "$QDRANT_URL/collections/$collection/snapshots/$snapshot"
    # The copy is in the backup now; do not let snapshots accumulate inside Qdrant.
    curl -fsS -X DELETE "${auth[@]}" "$QDRANT_URL/collections/$collection/snapshots/$snapshot" >/dev/null || echo "WARN: could not delete Qdrant snapshot $snapshot"
    echo "$collection" >>"$TARGET_DIR/qdrant/collections.txt"
  done
  touch "$TARGET_DIR/qdrant/collections.txt"
}
backup_qdrant

if [[ "$DRY_RUN" != "1" ]]; then
  (
    cd "$TARGET_DIR"
    find . -type f ! -name SHA256SUMS -print0 | sort -z | xargs -0 sha256sum > .SHA256SUMS.tmp && mv .SHA256SUMS.tmp SHA256SUMS
  )
  ln -sfn "$TARGET_DIR" "$BACKUP_DIR/latest"
fi

echo "TokenHub backup completed: $TARGET_DIR"
