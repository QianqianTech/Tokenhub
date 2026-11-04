#!/usr/bin/env bash
# Resolve the base images of the release Dockerfiles to their digests and write
# docker/release/base-images.lock (DEP-101). Commit the result with the release.
#
#   scripts/release/pin-base-images.sh                 resolve every image in the lock file
#   scripts/release/pin-base-images.sh --php-version 8.3   also add php:8.3-fpm-bookworm
#   scripts/release/pin-base-images.sh --check         exit 1 when an image has no digest
#
# Needs `docker buildx imagetools` (or `crane`) and access to the registry.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
LOCK="$ROOT/docker/release/base-images.lock"
CHECK=0 EXTRA=()

while [ $# -gt 0 ]; do
  case "$1" in
    --php-version) EXTRA+=("php:$2-fpm-bookworm"); shift 2 ;;
    --check) CHECK=1; shift ;;
    -h|--help) sed -n '2,9p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) echo "Unknown option: $1" >&2; exit 64 ;;
  esac
done

[ -f "$LOCK" ] || { echo "Missing $LOCK" >&2; exit 1; }

refs=()
while read -r ref _; do
  case "$ref" in ''|'#'*) continue ;; esac
  refs+=("$ref")
done <"$LOCK"
for ref in "${EXTRA[@]+"${EXTRA[@]}"}"; do
  printf '%s\n' "${refs[@]}" | grep -qx "$ref" || refs+=("$ref")
done

if [ "$CHECK" = 1 ]; then
  missing=0
  while read -r ref digest _; do
    case "$ref" in ''|'#'*) continue ;; esac
    if ! [[ "${digest:-}" =~ ^sha256:[0-9a-f]{64}$ ]]; then
      echo "not pinned: $ref" >&2
      missing=1
    fi
  done <"$LOCK"
  exit "$missing"
fi

resolve() {
  local ref="$1" digest=""
  if command -v docker >/dev/null 2>&1 && docker buildx version >/dev/null 2>&1; then
    digest="$(docker buildx imagetools inspect "$ref" 2>/dev/null | awk '/^Digest:/ {print $2; exit}')"
  fi
  if [ -z "$digest" ] && command -v crane >/dev/null 2>&1; then
    digest="$(crane digest "$ref" 2>/dev/null || true)"
  fi
  [[ "$digest" =~ ^sha256:[0-9a-f]{64}$ ]] || return 1
  printf '%s' "$digest"
}

tmp="$(mktemp)"
trap 'rm -f "$tmp"' EXIT
grep '^#' "$LOCK" >"$tmp" || true
failed=0
for ref in "${refs[@]}"; do
  if digest="$(resolve "$ref")"; then
    echo "$ref $digest" >>"$tmp"
    echo "pinned  $ref  $digest"
  else
    old="$(awk -v r="$ref" '$1 == r {print $2; exit}' "$LOCK")"
    echo "$ref${old:+ $old}" >>"$tmp"
    echo "FAILED  $ref (kept ${old:-no digest})" >&2
    failed=1
  fi
done
cat "$tmp" >"$LOCK"
exit "$failed"
