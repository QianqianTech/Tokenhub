# Dual-Architecture Offline Docker Release Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox syntax for tracking.

**Goal:** Build a self-contained TokenHub Docker release bundle for linux/amd64 and linux/arm64, with code inside images and encrypted state recovery.

**Architecture:** Keep docker-compose.yml as the source-mounted development stack. Add release images and docker-compose.offline.yml, which use image tags and named state volumes only. A PowerShell packager exports both architectures, data snapshots and encrypted configuration; Windows and POSIX importers restore without building.

**Tech Stack:** Docker Buildx, Docker Compose, PHP 8.5 FPM, Caddy 2, Python 3.12, MySQL 9.7, Redis, Qdrant, PowerShell 7, POSIX shell.

---

## File structure

- Create docker/release/php/Dockerfile and docker/release/php/entrypoint.sh for the PHP code image.
- Create docker/release/caddy/Dockerfile, docker/release/caddy/Caddyfile and docker/release/caddy/entrypoint.sh for static resources and proxying.
- Create docker/release/rag-worker/Dockerfile and docker/release/worker-autoscaler/Dockerfile for worker images.
- Create docker-compose.offline.yml with no build directives or application-source bind mounts.
- Create scripts/package-offline-release.ps1, scripts/import-offline-release.ps1 and scripts/import-offline-release.sh.
- Create tests/Console/OfflineDockerReleaseTest.php and docs/deployment/offline-docker-release.md.
- Modify .dockerignore and README.md.

### Task 1: Lock the release contract with a failing test

**Files:**
- Create tests/Console/OfflineDockerReleaseTest.php

- [ ] **Step 1: Write the test**

    <?php
    declare(strict_types=1);
    require __DIR__ . '/../bootstrap.php';
    $root = dirname(__DIR__, 2);
    $read = static function (string $path): string {
        $content = file_get_contents($path);
        assert_true(is_string($content), "Missing release artifact: " . $path);
        return $content;
    };
    $compose = $read($root . '/docker-compose.offline.yml');
    $php = $read($root . '/docker/release/php/Dockerfile');
    $caddy = $read($root . '/docker/release/caddy/Dockerfile');
    $rag = $read($root . '/docker/release/rag-worker/Dockerfile');
    $pack = $read($root . '/scripts/package-offline-release.ps1');
    $windows = $read($root . '/scripts/import-offline-release.ps1');
    $unix = $read($root . '/scripts/import-offline-release.sh');
    assert_true(str_contains($php, 'composer install --no-dev'), 'PHP image must install production dependencies.');
    assert_true(str_contains($caddy, 'COPY public /srv/app/public'), 'Caddy image must contain public assets.');
    assert_true(str_contains($rag, 'COPY python-rag-worker/tokenhub_rag_worker'), 'RAG image must contain worker code.');
    assert_true(!str_contains($compose, 'build:'), 'Offline Compose must not build.');
    assert_true(!str_contains($compose, './:/srv/app'), 'Offline Compose must not mount source.');
    assert_true(str_contains($compose, 'tokenhub_storage:'), 'Offline Compose must use state volumes.');
    assert_true(str_contains($pack, 'linux/amd64') && str_contains($pack, 'linux/arm64'), 'Packager must export both platforms.');
    assert_true(str_contains($pack, '.env.enc') && str_contains($pack, 'SHA256SUMS'), 'Packager must protect config and write hashes.');
    assert_true(str_contains($windows, 'docker load') && str_contains($unix, 'docker load'), 'Importers must load offline images.');

- [ ] **Step 2: Verify red**

Run: docker compose exec -T php php tests/Console/OfflineDockerReleaseTest.php

Expected: failure because docker-compose.offline.yml is absent.

### Task 2: Build code-contained release images

**Files:**
- Create docker/release/php/Dockerfile
- Create docker/release/php/entrypoint.sh
- Create docker/release/caddy/Dockerfile
- Create docker/release/caddy/Caddyfile
- Create docker/release/caddy/entrypoint.sh
- Create docker/release/rag-worker/Dockerfile
- Create docker/release/worker-autoscaler/Dockerfile

- [ ] **Step 1: Implement the PHP release image**

    FROM composer:2 AS dependencies
    WORKDIR /srv/app
    COPY composer.json composer.lock ./
    RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

    FROM php:8.5-fpm-bookworm
    RUN apt-get update && apt-get install -y --no-install-recommends git unzip libzip-dev default-mysql-client openssl && docker-php-ext-install pdo_mysql && pecl install redis && docker-php-ext-enable redis && rm -rf /var/lib/apt/lists/*
    COPY docker/release/php/entrypoint.sh /usr/local/bin/tokenhub-release-entrypoint
    RUN chmod +x /usr/local/bin/tokenhub-release-entrypoint
    WORKDIR /srv/app
    COPY --from=dependencies /srv/app/vendor ./vendor
    COPY app ./app
    COPY bin ./bin
    COPY config ./config
    COPY database ./database
    COPY scripts ./scripts
    COPY composer.json .env.example ./
    RUN mkdir -p storage/logs storage/exports storage/media storage/caddy
    ENTRYPOINT ["tokenhub-release-entrypoint"]
    CMD ["php-fpm"]

    #!/usr/bin/env sh
    set -eu
    cd /srv/app
    test -f vendor/autoload.php || { echo 'Packaged Composer dependencies are missing.' >&2; exit 70; }
    mkdir -p storage/logs storage/exports storage/media storage/caddy
    touch storage/caddy/waf-current.caddy storage/caddy/tls-current.caddy
    exec "$@"

- [ ] **Step 2: Implement Caddy and worker images**

    FROM caddy:2
    COPY docker/release/caddy/Caddyfile /etc/caddy/Caddyfile
    COPY docker/release/caddy/entrypoint.sh /usr/local/bin/tokenhub-caddy-entrypoint
    COPY public /srv/app/public
    RUN chmod +x /usr/local/bin/tokenhub-caddy-entrypoint
    ENTRYPOINT ["tokenhub-caddy-entrypoint"]

    #!/usr/bin/env sh
    set -eu
    mkdir -p /srv/app/storage/logs /srv/app/storage/caddy
    touch /srv/app/storage/caddy/waf-current.caddy /srv/app/storage/caddy/tls-current.caddy
    exec caddy run --config /etc/caddy/Caddyfile --adapter caddyfile

    FROM python:3.12-slim
    WORKDIR /worker
    COPY python-rag-worker/requirements.txt ./
    RUN pip install --no-cache-dir -r requirements.txt
    COPY python-rag-worker/tokenhub_rag_worker ./tokenhub_rag_worker
    CMD ["python", "-m", "tokenhub_rag_worker"]

    FROM docker:27-cli
    RUN apk add --no-cache redis
    COPY scripts/worker-autoscaler.sh /usr/local/bin/worker-autoscaler
    RUN chmod +x /usr/local/bin/worker-autoscaler
    CMD ["/usr/local/bin/worker-autoscaler"]

Copy all routing, headers and log-redaction directives from docker/caddy/Caddyfile to the release Caddyfile, retaining php:9000.

- [ ] **Step 3: Verify partial contract**

Run: docker compose exec -T php php tests/Console/OfflineDockerReleaseTest.php

Expected: image assertions pass; Compose/importer assertions fail.

### Task 3: Add the source-free Compose graph

**Files:**
- Create docker-compose.offline.yml

- [ ] **Step 1: Implement service isolation**

Define php, queue-worker, rag-worker, caddy, mysql, redis, qdrant and optional worker-autoscaler. The PHP, Caddy, RAG and autoscaler services use Compose interpolation of TOKENHUB_RELEASE_TAG, for example tokenhub-php:${_TOKENHUB_RELEASE_TAG:?TOKENHUB_RELEASE_TAG is required}. All runtime data uses named volumes named tokenhub_storage, mysql_97_data, redis_data, qdrant_data, caddy_data and caddy_config.

Use this PHP service shape:

    php:
      image: tokenhub-php:${_TOKENHUB_RELEASE_TAG:?TOKENHUB_RELEASE_TAG is required}
      working_dir: /srv/app
      volumes:
        - tokenhub_storage:/srv/app/storage
        - caddy_data:/srv/caddy-data:ro
      environment:
        APP_ENV: production
        CADDY_DATA_PATH: /srv/caddy-data
        QDRANT_URL: http://qdrant:6333
      restart: unless-stopped

Copy MySQL, Redis and Qdrant healthchecks from docker-compose.yml. Do not define build, local-source mounts, or ports for MySQL/Redis/Qdrant.

- [ ] **Step 2: Verify Compose syntax**

Run: docker compose --env-file .env -f docker-compose.offline.yml config --quiet

Expected: exit code 0 without a build.

- [ ] **Step 3: Verify contract**

Run: docker compose exec -T php php tests/Console/OfflineDockerReleaseTest.php

Expected: only packager/importer assertions fail.

### Task 4: Package both architectures and state

**Files:**
- Create scripts/package-offline-release.ps1
- Modify .dockerignore

- [ ] **Step 1: Exclude secrets and output**

Append these lines to .dockerignore:

    .env
    release/
    offline-release/
    *.enc

- [ ] **Step 2: Implement image export functions**

    function Save-ImageTar {
        param([string]$Image, [string]$Path)
        & docker image save --output $Path $Image
        if ($LASTEXITCODE -ne 0) { throw "docker image save failed for $Image" }
    }
    function Build-ReleaseImage {
        param([string]$Platform, [string]$Tag, [string]$Dockerfile, [string]$Archive)
        & docker buildx build --platform $Platform --load --tag $Tag --file $Dockerfile .
        if ($LASTEXITCODE -ne 0) { throw "Buildx failed for $Tag on $Platform" }
        Save-ImageTar -Image $Tag -Path $Archive
    }

Call Build-ReleaseImage for php, caddy, rag-worker and autoscaler twice: linux/amd64 and linux/arm64. Pull and save mysql:9.7, redis:latest and qdrant/qdrant:latest after selecting each platform. Use one UTC timestamp and architecture suffix in each image tag.

- [ ] **Step 3: Snapshot state and encrypt configuration**

Stop php, queue-worker and rag-worker, snapshot data, and restart them from a finally block. Export MySQL through mysqldump with single-transaction, routines and triggers. Run redis-cli SAVE before archiving its named volume with busybox:1.36. List Qdrant collections, create/download a snapshot for each collection, and archive tokenhub_storage, caddy_data and caddy_config.

Read the password with Read-Host -AsSecureString. Pipe .env into openssl enc -aes-256-cbc -pbkdf2 -salt inside the packaged PHP image; write secrets/.env.enc. Generate manifest.json and SHA256SUMS for every non-secret artifact.

- [ ] **Step 4: Verify dry run**

Run: pwsh -File scripts/package-offline-release.ps1 -OutputDirectory .\offline-release-test -DryRun

Expected: plans both image directories, every data snapshot, encrypted configuration and checksums; writes no archive.

### Task 5: Add guarded importers

**Files:**
- Create scripts/import-offline-release.ps1
- Create scripts/import-offline-release.sh
- Modify tests/Console/OfflineDockerReleaseTest.php

- [ ] **Step 1: Implement importer invariants**

Both importers must map amd64/x86_64 to amd64 and arm64/aarch64 to arm64, validate SHA256SUMS before docker load, load every tar in the selected images/amd64 or images/arm64 directory, decrypt .env.enc, append the selected release tag, and refuse to restore without an explicit confirmation flag.

Use the POSIX mapping:

    case "$(docker version --format '{{.Server.Arch}}')" in
      amd64|x86_64) architecture=amd64 ;;
      arm64|aarch64) architecture=arm64 ;;
      *) echo 'Unsupported Docker architecture.' >&2; exit 64 ;;
    esac
    test "$(printf %s "$CONFIRM_RESTORE")" = 1 || exit 64
    sha256sum -c SHA256SUMS
    find "images/$architecture" -name '*.tar' -print | sort | while IFS= read -r image; do docker load --input "$image"; done

Windows requires -ConfirmRestore; POSIX requires CONFIRM_RESTORE=1. Start MySQL/Redis/Qdrant first, restore SQL, volumes and Qdrant snapshots, then run Compose with --no-build and poll /health for 120 seconds.

- [ ] **Step 2: Extend test and verify green**

Add assertions for architecture mapping, SHA256SUMS, .env.enc decryption, explicit confirmation, --no-build and /health in both importer files.

Run: docker compose exec -T php php tests/Console/OfflineDockerReleaseTest.php

Expected: PASS tests/Console/OfflineDockerReleaseTest.php.

### Task 6: Document and perform a recovery drill

**Files:**
- Create docs/deployment/offline-docker-release.md
- Modify README.md

- [ ] **Step 1: Document commands and safeguards**

Document these invocations:

    pwsh -File scripts/package-offline-release.ps1 -OutputDirectory D:\releases\tokenhub
    pwsh -File .\import-offline-release.ps1 -BundleRoot .\TokenHub-offline-YYYYMMDD-HHMMSS -ConfirmRestore
    CONFIRM_RESTORE=1 ./import-offline-release.sh ./TokenHub-offline-YYYYMMDD-HHMMSS

State that the package contains confidential data, requires Docker Engine and Compose but no internet, and must be transferred separately from its password.

- [ ] **Step 2: Link the guide**

Add “Offline Docker release: docs/deployment/offline-docker-release.md” to README.md’s Docs list.

- [ ] **Step 3: Run the actual recovery drill**

Run: pwsh -File scripts/package-offline-release.ps1 -OutputDirectory .\offline-release

Expected: timestamped directory contains images/amd64, images/arm64, data, secrets/.env.enc, manifest.json and SHA256SUMS.

Run: pwsh -File scripts/import-offline-release.ps1 -BundleRoot .\offline-release\TokenHub-offline-YYYYMMDD-HHMMSS -ProjectName tokenhub-offline-drill -ConfirmRestore

Expected: an isolated Compose project restores state and the health endpoint returns HTTP 200.

- [ ] **Step 4: Run focused gate and commit when possible**

Run: docker compose exec -T php php tests/Console/OfflineDockerReleaseTest.php

Expected: PASS tests/Console/OfflineDockerReleaseTest.php.

Run: git add .dockerignore README.md docker docker-compose.offline.yml scripts docs/deployment/offline-docker-release.md tests/Console/OfflineDockerReleaseTest.php && git commit -m "feat: add dual-architecture offline Docker release"

Expected: one scoped commit. The current workspace reports not a git repository; do not fabricate a commit if that remains true.
