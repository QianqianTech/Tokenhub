#!/usr/bin/env sh
set -eu

BASE_URL="${BASE_URL:-http://localhost:8080}"

if ! command -v docker >/dev/null 2>&1; then
  echo "docker is required" >&2
  exit 1
fi

docker compose up -d --build mysql redis php caddy
docker compose exec -T php php bin/migrate.php
docker compose exec -T php php bin/run-tests.php

health_body="$(curl -fsS "${BASE_URL}/health")"
admin_health_body="$(curl -fsS "${BASE_URL}/admin-api/health")"
admin_body="$(curl -fsS "${BASE_URL}/admin/")"

printf '%s' "$health_body" | grep -q '"service":"tokenhub"'
printf '%s' "$admin_health_body" | grep -q '"service":"tokenhub"'
printf '%s' "$admin_body" | grep -q 'TokenHub Admin'

docker compose exec -T php php bin/queue-test-job.php
docker compose run --rm queue-worker php bin/queue-worker.php --once

echo "TokenHub foundation smoke test passed."
