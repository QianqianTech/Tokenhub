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

# Build id of the APP service worker cache: tag + commit (the build context has no .git).
APP_BUILD="$TAG"
if rev="$(git -C "$ROOT" rev-parse --short=12 HEAD 2>/dev/null)"; then
  APP_BUILD="$TAG-$rev"
fi

build_args=(--build-arg "PHP_VERSION=$PHP_VERSION" --build-arg "TOKENHUB_VERSION=$TAG" --build-arg "TOKENHUB_APP_BUILD=$APP_BUILD")

# Base image digests (DEP-101): docker/release/base-images.lock, refreshed by
# scripts/release/pin-base-images.sh. A missing digest builds from the tag.
LOCK="$ROOT/docker/release/base-images.lock"
pinned() {
  local ref="$1" digest=""
  [ -f "$LOCK" ] && digest="$(awk -v r="$ref" '$1 == r {print $2; exit}' "$LOCK")"
  if [[ "$digest" =~ ^sha256:[0-9a-f]{64}$ ]]; then
    printf '%s@%s' "$ref" "$digest"
  else
    echo "WARN: base image $ref is not pinned by digest (scripts/release/pin-base-images.sh)" >&2
    printf '%s' "$ref"
  fi
}
build_args+=(
  --build-arg "PHP_BASE_IMAGE=$(pinned "php:$PHP_VERSION-fpm-bookworm")"
  --build-arg "COMPOSER_IMAGE=$(pinned composer:2)"
  --build-arg "CADDY_BASE_IMAGE=$(pinned caddy:2.10)"
  --build-arg "PYTHON_BASE_IMAGE=$(pinned python:3.12-slim)"
  --build-arg "DOCKER_CLI_BASE_IMAGE=$(pinned docker:27-cli)"
  --build-arg "GO_IMAGE=$(pinned golang:1.24-alpine)"
  --build-arg "RUNTIME_IMAGE=$(pinned alpine:3.20)"
)

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
