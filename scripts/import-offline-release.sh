#!/usr/bin/env sh
# Load the images of an offline bundle (shipped as load-images.sh in the bundle root).
#
#   ./load-images.sh [BUNDLE_DIRECTORY]
#   ./load-images.sh --install [INSTALL_OPTIONS]
#
# Then install:  cd tokenhub && ./install.sh --site <domain-or-IP>
# Data is never touched here; to move an existing installation use tokenhub/restore.sh with a backup.
set -eu

install=0
if [ "${1:-}" = --install ]; then
  install=1
  shift
  bundle_root=$(cd "$(dirname "$0")" && pwd)
else
  bundle_root=${1:-$(cd "$(dirname "$0")" && pwd)}
fi
cd "$bundle_root"
[ "$install" = 0 ] || test -f tokenhub/install.sh || { echo 'Missing TokenHub installer in the bundle.' >&2; exit 65; }

case "$(docker version --format '{{.Server.Arch}}')" in
  amd64|x86_64) architecture=amd64 ;;
  arm64|aarch64) architecture=arm64 ;;
  *) echo 'Unsupported Docker architecture.' >&2; exit 64 ;;
esac
test -d "images/$architecture" || { echo "This bundle has no images for $architecture." >&2; exit 65; }

echo '==> Verifying checksums'
if command -v sha256sum >/dev/null 2>&1; then sha256sum -c --quiet SHA256SUMS; else shasum -a 256 -c SHA256SUMS >/dev/null; fi

find "images/$architecture" -name '*.tar' -print | sort | while IFS= read -r image; do
  echo "==> docker load $image"
  docker load --input "$image"
done
if [ "$install" = 1 ]; then exec bash tokenhub/install.sh "$@"; fi
echo "Images loaded. Next: cd tokenhub && ./install.sh --site <domain-or-IP>"
