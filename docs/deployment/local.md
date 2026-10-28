# TokenHub 本地部署说明

本文用于本地开发和验收前自测，默认使用 Docker Compose 启动 PHP-FPM、Caddy、MySQL、Redis 和队列 Worker。

## 环境要求

- Docker 和 Docker Compose。
- 可访问本仓库根目录。
- 需要本机直接运行脚本时，安装 `bash`、`curl`、`grep`、`sed`。

## 容器部署

```bash
cp .env.example .env && sed -i.bak 's/^APP_ENV=.*/APP_ENV=local/' .env
docker compose up -d --build
docker compose exec -T php php bin/install.php run --admin-password='change-this-password'
docker compose exec -T php php bin/run-tests.php
```

开发栈与生产栈的区别：开发栈挂载源码，第一次启动时自动执行 `composer install`，使用开发镜像 `tokenhub-php-dev`。生产部署请使用 `deploy/docker/install.sh`，详见 `docs/deployment/README.md`。

`.env.example` 中的密码默认为 `change-me`，开发环境可以直接使用。`php bin/doctor.php` 在 `APP_ENV=local` 时只给出警告，不会阻止启动。

默认入口：

- Admin：`http://localhost:8080/admin/`
- 大屏：`http://localhost:8080/screen/`
- 健康检查：`http://localhost:8080/health`
- Admin 健康检查：`http://localhost:8080/admin-api/health`

## PHP-FPM

本地容器使用 `docker/php/Dockerfile` 和 `docker/php/entrypoint.sh` 构建 PHP-FPM 环境，应用代码挂载到 `/srv/app`。

检查 PHP 服务：

```bash
docker compose ps php
docker compose logs --tail=100 php
docker compose exec -T php php -v
```

## Caddy

本地 Caddy 配置为 `docker/caddy/Caddyfile`，只服务开发环境，不启用生产 TLS 模板。

检查入口：

```bash
curl -i http://localhost:8080/health
curl -i http://localhost:8080/admin/
curl -i http://localhost:8080/screen/
```

## MySQL

本地 MySQL 使用 `.env` 中的 `DB_*` 配置。初始化和迁移：

```bash
docker compose exec -T php php bin/migrate.php
docker compose exec -T php php bin/migrate.php --status
docker compose exec -T mysql sh -c 'mysql -utokenhub -p"$MYSQL_PASSWORD" tokenhub -e "SHOW TABLES;"'
```

如果 `.env` 中密码被修改，应同步调整 MySQL 检查命令。

## Redis

Redis 用于限额、会话和队列。检查 Redis：

```bash
docker compose ps redis
docker compose exec -T redis sh -c 'redis-cli --no-auth-warning -a "$REDIS_PASSWORD" ping'
```

## Worker

队列 Worker 可按一次性模式或持续模式运行：

```bash
docker compose exec -T php php bin/queue-test-job.php
docker compose exec -T php php bin/queue-worker.php --once
docker compose run --rm queue-worker php bin/queue-worker.php
```

查看日志：

```bash
docker compose logs --tail=100 queue-worker
docker compose exec -T php ls -lah storage/logs
```

## 本地验收命令

```bash
bash scripts/smoke-test.sh
BASE_URL=http://localhost:8080 bash scripts/acceptance-check.sh
BASE_URL=http://localhost:8080 bash scripts/security-smoke.sh
```

带真实 Admin 和 API Key 时：

```bash
BASE_URL=http://localhost:8080 ADMIN_PASSWORD='admin-password' bash scripts/security-smoke.sh
BASE_URL=http://localhost:8080 ADMIN_PASSWORD='admin-password' API_KEY='tkh_xxx' MODEL='demo-model' bash scripts/acceptance-check.sh
BASE_URL=http://localhost:8080 API_KEY='tkh_xxx' MODEL='demo-model' bash scripts/pressure-smoke.sh
```
