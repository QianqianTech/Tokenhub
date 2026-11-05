#!/usr/bin/env bash
# Upgrade a Docker installation.
#
#   ./upgrade.sh --tag 1.1.0                     registry or offline-loaded images
#   ./upgrade.sh --build                         rebuild from the updated source tree
#   ./upgrade.sh --tag 1.1.0 --skip-backup       not recommended
#   ./upgrade.sh --tag 1.1.0 --no-rollback       leave the failed version in place for debugging
#
# Steps: show changelog -> backup -> switch image tag -> pull/build -> start (the init container
#        applies migrations) -> wait until healthy -> self-check.
# On failure the previous image tag is restored and started again automatically. Database
# migrations are not reversible: when the failed upgrade already applied some, the script says
# so and names the backup to restore (./restore.sh <backup-dir>).
set -euo pipefail

# shellcheck source=lib.sh
. "$(cd "$(dirname "$0")" && pwd)/lib.sh"

TAG="" BUILD=0 SKIP_BACKUP=0 ROLLBACK=1
while [ $# -gt 0 ]; do
  case "$1" in
    --tag) TAG="${2:-}"; shift 2 ;;
    --build) BUILD=1; shift ;;
    --skip-backup) SKIP_BACKUP=1; shift ;;
    --no-rollback) ROLLBACK=0; shift ;;
    -h|--help) sed -n '2,14p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) die "Unknown option: $1" ;;
  esac
done

require_docker
[ -f "$ENV_FILE" ] || die "$ENV_FILE not found; run install.sh first."
export TOKENHUB_BUILD="$BUILD"

PREVIOUS_TAG="$(env_get TOKENHUB_IMAGE_TAG)"
if [ -z "$TAG" ] && [ "$BUILD" = 1 ] && [ -f "$KIT_DIR/../../VERSION" ]; then
  TAG="$(tr -d '[:space:]' <"$KIT_DIR/../../VERSION")"
fi
TAG="${TAG:-$PREVIOUS_TAG}"

# ------------------------------------------------------------- changelog
# Prints the CHANGELOG.md sections newer than the installed version, up to the
# target version ("## [1.1.0] - 2026-11-20" headings, Keep a Changelog style).
CHANGELOG=""
for candidate in "$KIT_DIR/CHANGELOG.md" "$KIT_DIR/../../CHANGELOG.md"; do
  [ -f "$candidate" ] && { CHANGELOG="$candidate"; break; }
done
show_changelog() {
  local from="$1" to="$2"
  [ -n "$CHANGELOG" ] || return 0
  [ "$from" != "$to" ] || return 0
  local section
  section="$(awk -v from="$from" -v to="$to" '
    function norm(v) { sub(/^v/, "", v); return v }
    # Compare dotted versions numerically; returns -1, 0 or 1.
    function cmp(a, b,   x, y, i, n, m) {
      n = split(norm(a), x, /[.-]/); m = split(norm(b), y, /[.-]/)
      for (i = 1; i <= (n > m ? n : m); i++) {
        if ((x[i] + 0) < (y[i] + 0)) return -1
        if ((x[i] + 0) > (y[i] + 0)) return 1
      }
      return 0
    }
    /^## \[/ {
      v = $0; sub(/^## \[/, "", v); sub(/\].*$/, "", v)
      show = (v != "Unreleased" && v ~ /^[0-9]/ && (from !~ /^v?[0-9]/ || cmp(v, from) > 0) && (to !~ /^v?[0-9]/ || cmp(v, to) <= 0))
    }
    /^## / && !/^## \[/ { show = 0 }
    show { print }
  ' "$CHANGELOG")"
  if [ -n "$section" ]; then
    info "Changes from $from to $to ($(basename "$CHANGELOG")):"
    printf '%s\n\n' "$section"
  fi
}
show_changelog "$PREVIOUS_TAG" "$TAG"

# ------------------------------------------------------------- helpers
# Number of applied migrations, read straight from MySQL (works whichever php image runs).
applied_migrations() {
  compose exec -T mysql sh -c 'exec mysql -N -B -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE" -e "SELECT COUNT(*) FROM migrations"' 2>/dev/null | tr -d '[:space:]' || true
}

latest_backup() {
  local root
  root="$(backup_root)"
  # shellcheck disable=SC2012
  ls -1d "$root"/20* 2>/dev/null | sort | tail -n1
}

BACKUP_PATH=""
MIGRATIONS_BEFORE=""
PHASE="prepare"

# Restores the previous image tag after a failure (DEP-104).
rollback() {
  local status=$?
  trap - EXIT
  [ "$status" -ne 0 ] || exit 0
  case "$PHASE" in prepare|done) exit "$status" ;; esac
  set +e
  warn "Upgrade to $TAG failed (phase: $PHASE)."
  local after=""
  [ -z "$MIGRATIONS_BEFORE" ] || after="$(applied_migrations)"
  if [ "$ROLLBACK" = 1 ] && [ -n "$PREVIOUS_TAG" ] && [ "$PREVIOUS_TAG" != "$TAG" ]; then
    warn "Rolling back the image tag to $PREVIOUS_TAG..."
    env_set TOKENHUB_IMAGE_TAG "$PREVIOUS_TAG"
    if TOKENHUB_BUILD=0 compose up -d --remove-orphans; then
      info "Previous version $PREVIOUS_TAG started again."
    else
      warn "Starting $PREVIOUS_TAG failed as well; check 'docker compose ps' and the logs."
    fi
  elif [ "$ROLLBACK" = 1 ]; then
    warn "No different previous tag to roll back to (was $PREVIOUS_TAG)."
  else
    warn "--no-rollback: $TAG stays configured in $ENV_FILE."
  fi
  if [ -n "$MIGRATIONS_BEFORE" ] && [ -n "$after" ] && [ "$after" != "$MIGRATIONS_BEFORE" ]; then
    warn "Database migrations already ran ($MIGRATIONS_BEFORE -> $after applied). They are not reversible:"
    warn "the old version may not work with the new schema. Restore the pre-upgrade backup:"
    warn "  CONFIRM_RESTORE=1 $KIT_DIR/restore.sh ${BACKUP_PATH:-<backup-dir>}"
  elif [ "$PHASE" = start ] && { [ -z "$MIGRATIONS_BEFORE" ] || [ -z "$after" ]; }; then
    warn "Could not tell whether migrations ran. If $PREVIOUS_TAG misbehaves, restore the backup:"
    warn "  CONFIRM_RESTORE=1 $KIT_DIR/restore.sh ${BACKUP_PATH:-<backup-dir>}"
  fi
  exit "$status"
}
trap rollback EXIT

# ------------------------------------------------------------- upgrade
MIGRATIONS_BEFORE="$(applied_migrations)"

if [ "$SKIP_BACKUP" = 0 ]; then
  info "Backing up before the upgrade..."
  "$KIT_DIR/backup.sh"
  BACKUP_PATH="$(latest_backup)"
fi

ensure_realtime_token
PHASE="images"
info "Upgrading image tag $PREVIOUS_TAG -> $TAG"
env_set TOKENHUB_IMAGE_TAG "$TAG"

if [ "$BUILD" = 1 ]; then
  compose build
elif [ -n "$(env_get TOKENHUB_IMAGE_PREFIX)" ]; then
  compose pull
fi

PHASE="start"
compose up -d --remove-orphans || { compose logs --no-color --tail=80 init >&2 || true; die "Starting the stack failed (init log above)."; }
wait_for_init 1800
wait_for_healthy php 300
wait_for_healthy caddy 120
PHASE="done"
compose exec -T php php bin/doctor.php || warn "Self-check reported problems."
info "Upgrade to $TAG finished. Previous tag: $PREVIOUS_TAG${BACKUP_PATH:+ (backup: $BACKUP_PATH)}"
