#!/usr/bin/env bash
# Back up a Docker installation: MySQL dump, Qdrant collection snapshots, application storage
# (media, exports, logs, Caddy fragments), Redis, Caddy certificates and the .env file.
#
#   ./backup.sh                      writes ./backups/<timestamp>/
#   BACKUP_DIR=/data/backup ./backup.sh
#   KEEP=14 ./backup.sh              keep the newest 14 backups (default 7)
#
# Cron example (daily 02:30):  30 2 * * * /opt/tokenhub/backup.sh >>/var/log/tokenhub-backup.log 2>&1
set -euo pipefail

# shellcheck source=lib.sh
. "$(cd "$(dirname "$0")" && pwd)/lib.sh"

require_docker
[ -f "$ENV_FILE" ] || die "$ENV_FILE not found."

BACKUP_ROOT="${BACKUP_DIR:-$KIT_DIR/backups}"
STAMP="$(date +%Y%m%d-%H%M%S)"
TARGET="$BACKUP_ROOT/$STAMP"
KEEP="${KEEP:-7}"
PROJECT="$(env_get COMPOSE_PROJECT_NAME)"; PROJECT="${PROJECT:-tokenhub}"
IMAGE="$(php_image)"

mkdir -p "$TARGET"
chmod 700 "$TARGET"

volume_tar() {
  local volume="$1" archive="$2"
  docker volume inspect "${PROJECT}_$volume" >/dev/null 2>&1 || { warn "volume ${PROJECT}_$volume not found, skipped"; return; }
  docker run --rm --entrypoint tar \
    --mount "type=volume,src=${PROJECT}_$volume,dst=/source,readonly" \
    --mount "type=bind,src=$TARGET,dst=/backup" \
    "$IMAGE" -C /source -czf "/backup/$archive" .
}

info "MySQL dump"
compose exec -T mysql sh -c 'exec mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction --routines --triggers --hex-blob --no-tablespaces --set-gtid-purged=OFF "$MYSQL_DATABASE"' \
  | gzip >"$TARGET/mysql.sql.gz"
test -s "$TARGET/mysql.sql.gz" || die "MySQL dump is empty."

if [ "$(env_get RAG_VECTOR_STORE)" != mysql_fallback ]; then
  info "Qdrant snapshots"
  compose exec -T php sh -c 'php bin/qdrant-snapshot.php create --index=/tmp/qdrant-index.json >&2 && cat /tmp/qdrant-index.json' \
    >"$TARGET/qdrant-index.json"
  volume_tar qdrant_snapshots qdrant-snapshots.tar.gz
  # The archive now holds them; do not let snapshots accumulate inside Qdrant.
  compose exec -T php sh -c 'php bin/qdrant-snapshot.php delete --index=/tmp/qdrant-index.json' >/dev/null || warn "Old Qdrant snapshots were not deleted."
fi

info "Redis"
compose exec -T redis sh -c 'redis-cli --no-auth-warning -a "$REDIS_PASSWORD" SAVE' >/dev/null
volume_tar redis_data redis-data.tar.gz

info "Application storage and certificates"
volume_tar tokenhub_storage storage.tar.gz
volume_tar caddy_data caddy-data.tar.gz
cp "$ENV_FILE" "$TARGET/env"
chmod 600 "$TARGET/env"
printf '%s\n' "$(env_get TOKENHUB_IMAGE_TAG)" >"$TARGET/image-tag"

if command -v sha256sum >/dev/null 2>&1; then HASH=(sha256sum); else HASH=(shasum -a 256); fi
(cd "$TARGET" && find . -type f ! -name SHA256SUMS | sort | while read -r f; do "${HASH[@]}" "$f"; done >"$TARGET/.SHA256SUMS.tmp" && mv "$TARGET/.SHA256SUMS.tmp" "$TARGET/SHA256SUMS")

if [ "$KEEP" -gt 0 ] 2>/dev/null; then
  # Newest first; everything after the first $KEEP entries is removed (portable to BSD tools).
  # shellcheck disable=SC2012
  ls -1d "$BACKUP_ROOT"/20* 2>/dev/null | sort -r | tail -n "+$((KEEP + 1))" | while read -r old; do rm -rf "$old"; done
fi

info "Backup written to $TARGET (contains secrets: keep it protected)."
