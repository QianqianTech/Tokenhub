#!/usr/bin/env sh
set -eu

base_url="${TOKENHUB_BASE_URL:-http://localhost:${CADDY_HTTP_PORT:-8080}}"

docker compose up -d --build
docker compose exec -T php php bin/migrate.php
docker compose exec -T php php bin/run-tests.php
docker compose exec -T php php bin/queue-test-job.php
docker compose exec -T php php bin/queue-worker.php --once

curl -fsS "${base_url}/health" >/tmp/tokenhub-health.json
curl -fsS "${base_url}/admin-api/health" >/tmp/tokenhub-admin-health.json
curl -fsS "${base_url}/admin/" >/tmp/tokenhub-admin.html

docker compose ps

echo "TokenHub foundation verification completed."
