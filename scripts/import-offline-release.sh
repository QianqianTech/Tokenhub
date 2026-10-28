#!/usr/bin/env sh
set -eu

bundle_root=${1:?usage: CONFIRM_RESTORE=1 ./import-offline-release.sh BUNDLE_DIRECTORY}
test "${CONFIRM_RESTORE:-}" = 1 || { echo 'Set CONFIRM_RESTORE=1 before restoring volumes.' >&2; exit 64; }
case "$(docker version --format '{{.Server.Arch}}')" in
  amd64|x86_64) architecture=amd64 ;;
  arm64|aarch64) architecture=arm64 ;;
  *) echo 'Unsupported Docker architecture.' >&2; exit 64 ;;
esac
cd "$bundle_root"
sha256sum -c SHA256SUMS
find "images/$architecture" -name '*.tar' -print | sort | while IFS= read -r image; do docker load --input "$image"; done
tag=$(sed -n "s/.*\"$architecture\": \"\([^\"]*\)\".*/\1/p" manifest.json)
test -n "$tag" || { echo 'Release tag missing from manifest.' >&2; exit 65; }
cp .env .runtime.env
printf '\nTOKENHUB_RELEASE_TAG=%s\nCOMPOSE_PROJECT_NAME=%s\n' "$tag" "${COMPOSE_PROJECT_NAME:-tokenhub}" >> .runtime.env
docker compose --env-file .runtime.env -f compose.offline.yml down --volumes --remove-orphans
docker compose --env-file .runtime.env -f compose.offline.yml up -d mysql redis qdrant
sleep 10
docker compose --env-file .runtime.env -f compose.offline.yml exec -T mysql sh -lc 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' < data/mysql.sql
docker compose --env-file .runtime.env -f compose.offline.yml up -d --no-build
deadline=$(( $(date +%s) + 120 ))
while test "$(date +%s)" -lt "$deadline"; do
  port=$(docker compose --env-file .runtime.env -f compose.offline.yml port caddy 80)
  if curl -fsS "http://$port/health" >/dev/null; then
    echo 'Offline release restored and healthy.'
    exit 0
  fi
  sleep 3
done
echo 'Offline release did not become healthy within 120 seconds.' >&2
exit 1
