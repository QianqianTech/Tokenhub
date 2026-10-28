#!/usr/bin/env bash
# Build the TokenHub release images (php, caddy, rag-worker, worker-autoscaler, realtime-gateway).
#
#   scripts/release/build-images.sh --tag 1.0.0                         load into the local Docker (native arch)
#   scripts/release/build-images.sh --tag 1.0.0 --push --image-prefix registry.example.com/tokenhub/
#                                                                         multi-arch push to a registry
#   scripts/release/build-images.sh --tag 1.0.0 --output dist/images      one .tar per image and arch
#
# Options: --platforms linux/amd64,linux/arm64 (default for --push/--output), --php-version 8.4
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
TAG="$(tr -d '[:space:]' <"$ROOT/VERSION")"
PREFIX="" PUSH=0 OUTPUT="" PLATFORMS="" PHP_VERSION="${PHP_VERSION:-8.4}"

while [ $# -gt 0 ]; do
  case "$1" in
    --tag) TAG="$2"; shift 2 ;;
    --image-prefix) PREFIX="$2"; shift 2 ;;
    --push) PUSH=1; shift ;;
    --output) OUTPUT="$2"; shift 2 ;;
    --platforms) PLATFORMS="$2"; shift 2 ;;
    --php-version) PHP_VERSION="$2"; shift 2 ;;
    -h|--help) sed -n '2,11p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) echo "Unknown option: $1" >&2; exit 64 ;;
  esac
done

IMAGES=(
  "tokenhub-php docker/release/php/Dockerfile"
  "tokenhub-caddy docker/release/caddy/Dockerfile"
  "tokenhub-rag-worker docker/release/rag-worker/Dockerfile"
  "tokenhub-worker-autoscaler docker/release/worker-autoscaler/Dockerfile"
  "tokenhub-realtime-gateway docker/release/realtime-gateway/Dockerfile"
)

build_args=(--build-arg "PHP_VERSION=$PHP_VERSION" --build-arg "TOKENHUB_VERSION=$TAG")

if [ "$PUSH" = 1 ]; then
  [ -n "$PREFIX" ] || { echo "--push needs --image-prefix" >&2; exit 64; }
  PLATFORMS="${PLATFORMS:-linux/amd64,linux/arm64}"
  for entry in "${IMAGES[@]}"; do
    set -- $entry
    echo "==> $PREFIX$1:$TAG ($PLATFORMS)"
    docker buildx build --platform "$PLATFORMS" "${build_args[@]}" -f "$ROOT/$2" -t "$PREFIX$1:$TAG" --push "$ROOT"
  done
  exit 0
fi

if [ -n "$OUTPUT" ]; then
  PLATFORMS="${PLATFORMS:-linux/amd64,linux/arm64}"
  IFS=',' read -r -a platform_list <<<"$PLATFORMS"
  for platform in "${platform_list[@]}"; do
    arch="${platform#linux/}"
    mkdir -p "$OUTPUT/$arch"
    for entry in "${IMAGES[@]}"; do
      set -- $entry
      echo "==> $1:$TAG ($platform)"
      docker buildx build --platform "$platform" "${build_args[@]}" -f "$ROOT/$2" -t "$PREFIX$1:$TAG" \
        --output "type=docker,dest=$OUTPUT/$arch/$1.tar" "$ROOT"
    done
  done
  exit 0
fi

for entry in "${IMAGES[@]}"; do
  set -- $entry
  echo "==> $PREFIX$1:$TAG"
  docker build "${build_args[@]}" -f "$ROOT/$2" -t "$PREFIX$1:$TAG" "$ROOT"
done
