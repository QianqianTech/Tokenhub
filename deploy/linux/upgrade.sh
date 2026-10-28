#!/usr/bin/env bash
# Upgrade a source installation from this (newer) source tree.
#
#   sudo deploy/linux/upgrade.sh                 backup, copy the new code, migrate, restart services
#   sudo deploy/linux/upgrade.sh --skip-backup   not recommended
#
# Rollback: re-run the previous release's deploy/linux/install.sh -y and restore the database backup
# if migrations ran (scripts/restore-drill-check.sh shows how to import a dump).
set -euo pipefail

SOURCE_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
SKIP_BACKUP=0
EXTRA=()
for arg in "$@"; do
  case "$arg" in
    --skip-backup) SKIP_BACKUP=1 ;;
    *) EXTRA+=("$arg") ;;
  esac
done
[ "$(id -u)" = 0 ] || { echo "Run as root (sudo)." >&2; exit 1; }
[ -f /etc/tokenhub/tokenhub.env ] || { echo "No installation found (/etc/tokenhub/tokenhub.env); use install.sh." >&2; exit 1; }

# Pass --app-dir DIR (forwarded to install.sh) if TokenHub was installed elsewhere.
APP_DIR=/var/www/tokenhub
for ((i = 0; i < ${#EXTRA[@]}; i++)); do
  [ "${EXTRA[$i]}" = --app-dir ] && APP_DIR="${EXTRA[$((i + 1))]}"
done

echo "Installed version: $(cat "$APP_DIR/VERSION" 2>/dev/null || echo unknown)  ->  new version: $(cat "$SOURCE_DIR/VERSION")"
if [ "$SKIP_BACKUP" = 0 ]; then
  DRY_RUN=0 CONFIRM_BACKUP=1 APP_DIR="$APP_DIR" bash "$SOURCE_DIR/scripts/backup-tokenhub.sh"
fi

for unit in $(systemctl list-units --plain --no-legend 'tokenhub-worker@*' 'tokenhub-scheduler.service' 'tokenhub-rag-worker.service' | awk '{print $1}'); do
  systemctl stop "$unit" || true
done

"$SOURCE_DIR/deploy/linux/install.sh" -y ${EXTRA[@]+"${EXTRA[@]}"}
