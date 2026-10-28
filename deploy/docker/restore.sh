#!/usr/bin/env bash
# Restore a backup made by backup.sh into this installation (replaces current data).
#
#   CONFIRM_RESTORE=1 ./restore.sh ./backups/20261028-023000
#
# The .env of the backup is restored as well, because APP_KEY must match the encrypted data.
set -euo pipefail

# shellcheck source=lib.sh
. "$(cd "$(dirname "$0")" && pwd)/lib.sh"

SOURCE="${1:-}"
[ -n "$SOURCE" ] && [ -d "$SOURCE" ] || die "usage: CONFIRM_RESTORE=1 $0 <backup-directory>"
[ "${CONFIRM_RESTORE:-0}" = 1 ] || die "This replaces the current database and files. Re-run with CONFIRM_RESTORE=1."
SOURCE="$(cd "$SOURCE" && pwd)"
require_docker

info "Verifying checksums"
(cd "$SOURCE" && { sha256sum -c SHA256SUMS 2>/dev/null || shasum -a 256 -c SHA256SUMS; }) || die "Checksum verification failed."

if [ -f "$SOURCE/env" ]; then
  [ -f "$ENV_FILE" ] && cp "$ENV_FILE" "$ENV_FILE.before-restore.$(date +%s)"
  cp "$SOURCE/env" "$ENV_FILE"
  chmod 600 "$ENV_FILE"
fi
PROJECT="$(env_get COMPOSE_PROJECT_NAME)"; PROJECT="${PROJECT:-tokenhub}"
IMAGE="$(php_image)"
docker image inspect "$IMAGE" >/dev/null 2>&1 || die "Image $IMAGE is not available; load or pull it first."

volume_restore() {
  local volume="$1" archive="$2"
  [ -f "$SOURCE/$archive" ] || { warn "$archive not in backup, skipped"; return; }
  docker volume create "${PROJECT}_$volume" >/dev/null
  docker run --rm --entrypoint sh \
    --mount "type=volume,src=${PROJECT}_$volume,dst=/target" \
    --mount "type=bind,src=$SOURCE,dst=/backup,readonly" \
    "$IMAGE" -c "find /target -mindepth 1 -delete && tar -C /target -xzf /backup/$archive"
}

info "Stopping application containers"
compose stop caddy php queue-worker scheduler rag-worker >/dev/null 2>&1 || true
compose stop redis >/dev/null 2>&1 || true
# Recreate MySQL from scratch: the restored .env carries the backup's passwords, and tables created by
# newer migrations must not survive a rollback.
compose rm -s -f mysql >/dev/null 2>&1 || true
docker volume rm "${PROJECT}_mysql_97_data" >/dev/null 2>&1 || true

volume_restore tokenhub_storage storage.tar.gz
volume_restore caddy_data caddy-data.tar.gz
volume_restore redis_data redis-data.tar.gz
volume_restore qdrant_snapshots qdrant-snapshots.tar.gz

info "Starting state services"
compose up -d mysql redis qdrant
wait_for_healthy mysql 300
wait_for_healthy redis 120
wait_for_healthy qdrant 120

info "Restoring MySQL"
gunzip -c "$SOURCE/mysql.sql.gz" | compose exec -T mysql sh -c 'exec mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"'

if [ -s "$SOURCE/qdrant-index.json" ]; then
  info "Restoring Qdrant collections"
  compose run --rm --no-deps -v "$SOURCE/qdrant-index.json:/tmp/qdrant-index.json:ro" --entrypoint php php \
    bin/qdrant-snapshot.php restore --index=/tmp/qdrant-index.json --snapshots-dir=/qdrant/snapshots
fi

info "Starting TokenHub"
compose up -d
wait_for_init 900
wait_for_healthy php 300
compose exec -T php php bin/doctor.php || warn "Self-check reported problems."
info "Restore finished from $SOURCE"
