# TokenHub

TokenHub uses native PHP 8.2+ FPM, Caddy, MySQL 9.7, Redis, and a Redis queue worker. Docker Compose is the default local development environment.

## Quick Start

```sh
cp .env.example .env
docker compose up -d --build
docker compose exec -T php php bin/migrate.php
docker compose exec -T php php bin/run-tests.php
docker compose exec -T php php scripts/create-admin.php admin 'change-this-password'
```

## Verify

```sh
curl http://localhost:8080/health
curl http://localhost:8080/admin/
curl http://localhost:8080/screen/
docker compose exec -T php php bin/queue-test-job.php
docker compose exec -T php php bin/queue-worker.php --once
BASE_URL=http://localhost:8080 bash scripts/acceptance-check.sh
```

## Docs

- Usage and management guide: `docs/tokenhub-usage-management-guide.md`
- Local deployment: `docs/deployment/local.md`
- Production deployment: `docs/deployment/production.md`
- Offline Docker release: `docs/deployment/offline-docker-release.md`
- Backup and restore drill: `docs/deployment/backup-restore.md`
- Final acceptance checklist: `docs/acceptance/final-acceptance-checklist.md`
