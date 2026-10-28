#!/usr/bin/env bash
# Create an offline installation bundle (no registry or internet needed on the target server).
#
#   scripts/package-offline-release.sh --output dist                      amd64 + arm64
#   scripts/package-offline-release.sh --output dist --platforms linux/amd64
#
# The bundle contains the TokenHub images, the MySQL/Redis/Qdrant images named in .env.example,
# the Docker deployment kit (deploy/docker) and load-images scripts. It contains NO secrets and NO data:
# the target runs load-images.sh, then tokenhub/install.sh which generates its own .env.
# To move an existing installation, add a backup made with deploy/docker/backup.sh and use restore.sh.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
VERSION="$(tr -d '[:space:]' <"$ROOT/VERSION")"
OUTPUT="" PLATFORMS="linux/amd64,linux/arm64"

while [ $# -gt 0 ]; do
  case "$1" in
    --output) OUTPUT="$2"; shift 2 ;;
    --platforms) PLATFORMS="$2"; shift 2 ;;
    --tag) VERSION="$2"; shift 2 ;;
    -h|--help) sed -n '2,11p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) echo "Unknown option: $1" >&2; exit 64 ;;
  esac
done
[ -n "$OUTPUT" ] || { echo "--output DIR is required" >&2; exit 64; }
command -v docker >/dev/null || { echo "docker is required" >&2; exit 1; }
docker buildx version >/dev/null 2>&1 || { echo "docker buildx is required (cross-arch builds also need QEMU: docker run --privileged --rm tonistiigi/binfmt --install all)" >&2; exit 1; }

BUNDLE="$OUTPUT/TokenHub-$VERSION-offline"
rm -rf "$BUNDLE"
mkdir -p "$BUNDLE/images" "$BUNDLE/tokenhub"

default_of() { sed -n "s/^$1=//p" "$ROOT/.env.example" | head -n1; }
THIRD_PARTY=("$(default_of MYSQL_IMAGE)" "$(default_of REDIS_IMAGE)" "$(default_of QDRANT_IMAGE)")

"$ROOT/scripts/release/build-images.sh" --tag "$VERSION" --platforms "$PLATFORMS" --output "$BUNDLE/images"

IFS=',' read -r -a platform_list <<<"$PLATFORMS"
for platform in "${platform_list[@]}"; do
  arch="${platform#linux/}"
  for image in "${THIRD_PARTY[@]}"; do
    file="$(printf '%s' "$image" | tr '/:' '__').tar"
    echo "==> $image ($platform)"
    docker pull --platform "$platform" "$image"
    docker save --platform "$platform" -o "$BUNDLE/images/$arch/$file" "$image" 2>/dev/null \
      || docker save -o "$BUNDLE/images/$arch/$file" "$image"
  done
done

cp "$ROOT"/deploy/docker/{compose.yml,lib.sh,install.sh,install.ps1,upgrade.sh,upgrade.ps1,backup.sh,backup.ps1,restore.sh,verify.sh,verify.ps1,README.md} "$BUNDLE/tokenhub/"
cp "$ROOT/.env.example" "$BUNDLE/tokenhub/.env.example"
printf '%s\n' "$VERSION" >"$BUNDLE/tokenhub/VERSION"
printf '%s\n' "$VERSION" >"$BUNDLE/VERSION"
cp "$ROOT/scripts/import-offline-release.sh" "$BUNDLE/load-images.sh"
cp "$ROOT/scripts/import-offline-release.ps1" "$BUNDLE/load-images.ps1"
chmod +x "$BUNDLE"/load-images.sh "$BUNDLE"/tokenhub/*.sh

{
  printf '{\n  "version": "%s",\n  "created_at": "%s",\n  "platforms": "%s",\n' "$VERSION" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$PLATFORMS"
  printf '  "third_party_images": ["%s", "%s", "%s"]\n}\n' "${THIRD_PARTY[@]}"
} >"$BUNDLE/manifest.json"

cat >"$BUNDLE/README.txt" <<TXT
TokenHub $VERSION offline bundle

Linux / macOS:
  ./load-images.sh
  cd tokenhub && ./install.sh --site <domain-or-IP>

Windows (Docker Desktop):
  powershell -ExecutionPolicy Bypass -File .\\load-images.ps1
  cd tokenhub; powershell -ExecutionPolicy Bypass -File .\\install.ps1 -Site <domain-or-IP>

Upgrade an existing installation: load the new images, copy the new tokenhub/ files over the old
ones (keep .env), then run ./upgrade.sh --tag $VERSION (upgrade.ps1 -Tag $VERSION on Windows).
TXT

(cd "$BUNDLE" && find . -type f ! -name SHA256SUMS -print0 | sort -z | xargs -0 sha256sum >SHA256SUMS)
echo "Offline bundle created: $BUNDLE"
du -sh "$BUNDLE" 2>/dev/null || true
