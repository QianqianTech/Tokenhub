#!/usr/bin/env bash
# Upgrade a Docker installation.
#
#   ./upgrade.sh --tag 1.1.0                     registry or offline-loaded images
#   ./upgrade.sh --build                         rebuild from the updated source tree
#   ./upgrade.sh --tag 1.1.0 --skip-backup       not recommended
#
# Steps: backup -> switch image tag -> pull/build -> start (the init container applies migrations)
#        -> wait until healthy -> self-check. Rollback: ./upgrade.sh --tag <previous> and, if migrations
#        ran, ./restore.sh <backup-dir> (database changes are not reversible).
set -euo pipefail

# shellcheck source=lib.sh
. "$(cd "$(dirname "$0")" && pwd)/lib.sh"

TAG="" BUILD=0 SKIP_BACKUP=0
while [ $# -gt 0 ]; do
  case "$1" in
    --tag) TAG="${2:-}"; shift 2 ;;
    --build) BUILD=1; shift ;;
    --skip-backup) SKIP_BACKUP=1; shift ;;
    -h|--help) sed -n '2,12p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) die "Unknown option: $1" ;;
  esac
done

require_docker
[ -f "$ENV_FILE" ] || die "$ENV_FILE not found; run install.sh first."
export TOKENHUB_BUILD="$BUILD"
ensure_realtime_token

PREVIOUS_TAG="$(env_get TOKENHUB_IMAGE_TAG)"
if [ -z "$TAG" ] && [ "$BUILD" = 1 ] && [ -f "$KIT_DIR/../../VERSION" ]; then
  TAG="$(tr -d '[:space:]' <"$KIT_DIR/../../VERSION")"
fi
TAG="${TAG:-$PREVIOUS_TAG}"

if [ "$SKIP_BACKUP" = 0 ]; then
  info "Backing up before the upgrade..."
  "$KIT_DIR/backup.sh"
fi

info "Upgrading image tag $PREVIOUS_TAG -> $TAG"
env_set TOKENHUB_IMAGE_TAG "$TAG"

if [ "$BUILD" = 1 ]; then
  compose build
elif [ -n "$(env_get TOKENHUB_IMAGE_PREFIX)" ]; then
  compose pull
fi

compose up -d --remove-orphans || { compose logs --no-color --tail=80 init >&2 || true; die "Starting the stack failed (init log above)."; }
wait_for_init 1800
wait_for_healthy php 300
wait_for_healthy caddy 120
compose exec -T php php bin/doctor.php || warn "Self-check reported problems."
info "Upgrade to $TAG finished. Previous tag: $PREVIOUS_TAG"
