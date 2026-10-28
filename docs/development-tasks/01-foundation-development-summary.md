# TokenHub 01 基础工程开发总结

## 概要

本阶段完成 TokenHub MVP 基础工程层建设，采用 Docker Compose 提供 Ubuntu/Debian 风格开发运行环境。系统入口由 Caddy 统一接入，后端使用原生 PHP FPM，不引入 Laravel、Symfony、ThinkPHP、Hyperf 等完整后端框架。Composer 仅用于 PSR-4 自动加载和基础依赖管理。

当前环境已部署并验证：

- PHP 8.5.8 FPM
- Caddy latest
- MySQL 9.7.1
- Redis latest
- Redis Queue Worker
- MySQL 迁移框架
- 健康检查接口
- JSONL 基础日志
- request_id / trace_id 请求追踪

## 主要交付内容

### 工程结构

已建立原生 PHP 项目结构：

```text
app/
  Bootstrap/
  Console/
  Http/
  Middleware/
  Services/
  Support/
bin/
config/
database/migrations/
docker/
  caddy/
  php/
public/
  admin/
  index.php
scripts/
storage/
  caddy/
  exports/
  logs/
  media/
```

### Docker Compose 环境

已配置 `docker-compose.yml`：

- `php`：原生 PHP FPM 服务，镜像 `tokenhub-php:latest`
- `caddy`：唯一 HTTP/HTTPS 入口，镜像 `caddy:latest`
- `mysql`：数据库服务，镜像 `mysql:9.7`
- `redis`：缓存和队列服务，镜像 `redis:latest`
- `queue-worker`：后台队列消费进程，复用 `tokenhub-php:latest`

MySQL 9.7 使用独立 volume `mysql_97_data`。旧 MySQL 8 volume 未删除，避免破坏历史开发数据。

### PHP 基础支撑层

已实现基础支撑能力：

- `Support\Router`：支持 `GET`、`POST`、`PUT`、`PATCH`、`DELETE`
- `Support\Response`：支持 JSON、错误、下载、SSE 响应
- `Support\Config`：加载 `.env` 和 `config/*.php`
- `Support\Database`：PDO MySQL 连接、事务和异常包装
- `Support\RedisClient`：Redis 缓存、计数、队列基础操作
- `Support\RequestContext`：生成并传递 `request_id`、`trace_id`
- `Support\Logger` / `JsonLogger`：写入 JSONL 应用日志

### Caddy 入口

Caddy 已作为统一入口：

- `/admin/` 托管静态 Admin 页面
- `/health` 转发到 PHP
- `/admin-api/*` 转发到 PHP
- 写入 `storage/logs/caddy-access.jsonl`
- 删除访问日志中的 `Authorization`、`Cookie`、`X-Api-Key`、`Api-Key` 和 URI 字段
- 设置基础安全响应头
- 预留 `storage/caddy/waf-current.caddy` 作为本地 WAF 配置占位文件

### 数据库迁移

已实现迁移命令：

```sh
php bin/migrate.php
```

当前基础表已创建：

- `system_settings`
- `audit_logs`
- `log_retention_policies`
- `migrations`

### 健康检查

已实现接口：

- `GET /health`
- `GET /admin-api/health`

返回内容包含：

- PHP 版本
- MySQL 连通状态
- Redis 连通状态
- `request_id`

### Queue Worker

已实现基础队列 Worker：

- 从 Redis 队列 `tokenhub:jobs` 拉取任务
- 支持测试任务消费
- 支持失败队列 `tokenhub:failed-jobs`
- 写入 `storage/logs/worker.jsonl`

队列测试命令：

```sh
php bin/queue-test-job.php
php bin/queue-worker.php
```

## 已验证结果

### 容器状态

`docker compose ps` 验证结果：

- `mysql:9.7` 正常运行，健康检查通过
- `tokenhub-php:latest` 正常运行，健康检查通过
- `redis:latest` 正常运行，健康检查通过
- `caddy:latest` 正常运行，端口 `8080` / `8443` 已暴露
- `queue-worker` 正常运行，健康检查通过

### 版本验证

PHP：

```text
PHP 8.5.8 (cli)
```

MySQL：

```text
mysql Ver 9.7.1 for Linux on x86_64
```

### 迁移验证

执行：

```sh
docker compose exec -T php php bin/migrate.php
```

结果：

```text
Applied 1 migration(s).
```

MySQL 表验证：

```text
audit_logs
log_retention_policies
migrations
system_settings
```

### HTTP 验证

`GET http://localhost:8080/health`：

```json
{
  "ok": true,
  "data": {
    "status": "healthy",
    "php": "8.5.8",
    "mysql": { "ok": true },
    "redis": { "ok": true }
  }
}
```

`GET http://localhost:8080/admin-api/health` 返回 200。

`GET http://localhost:8080/admin/` 返回 200。

### 测试验证

执行：

```sh
docker compose exec -T php php bin/run-tests.php
```

结果：

```text
25 test file(s) passed.
```

### 队列验证

执行：

```sh
docker compose exec -T php php bin/queue-test-job.php
```

结果：

```text
Queued test job.
```

验证结果：

- `storage/logs/worker.jsonl` 写入测试任务日志
- Redis 队列 `tokenhub:jobs` 长度回到 `0`
- `queue-worker` 容器保持健康运行

### 日志脱敏验证

使用包含以下请求头的请求访问 `/health`：

- `Authorization`
- `Cookie`
- `X-Api-Key`

检查 `storage/logs/caddy-access.jsonl`，未发现测试明文 token、cookie 或 API key。

## 本地运行命令

启动：

```sh
docker compose up -d --build
```

执行迁移：

```sh
docker compose exec php php bin/migrate.php
```

运行测试：

```sh
docker compose exec php php bin/run-tests.php
```

查看日志：

```sh
docker compose logs -f php caddy queue-worker
```

访问入口：

```text
Admin: http://localhost:8080/admin/
Health: http://localhost:8080/health
Admin API Health: http://localhost:8080/admin-api/health
```

## 部署中处理的问题

### MySQL 9.7 与旧数据卷不兼容

旧 `mysql_data` 数据卷中存在 MySQL 8.0.46 数据目录。MySQL 9.7.1 不允许从该版本直接原地升级，启动时报：

```text
Invalid MySQL server upgrade: Cannot upgrade from 80046 to 90701.
```

处理方式：

- 不删除旧数据卷
- 新增并使用 `mysql_97_data`
- 使用干净数据目录初始化 MySQL 9.7

### Caddy WAF 导入文件缺失

Caddyfile 引用了：

```text
/srv/app/storage/caddy/waf-current.caddy
```

初始容器内未挂载该目录，导致 Caddy 启动失败。

处理方式：

- 新增 `storage/caddy/waf-current.caddy`
- 将 `./storage/caddy` 挂载到 Caddy 容器

### Docker 代理环境变量

本机环境存在无效代理变量：

```text
HTTP_PROXY=http://127.0.0.1:9
HTTPS_PROXY=http://127.0.0.1:9
ALL_PROXY=http://127.0.0.1:9
```

处理方式：

- Docker 相关脚本在执行前清理这些代理变量
- 避免 Docker pull / build 被错误代理影响

## 当前边界

本阶段只完成 01 基础工程层。以下业务能力不属于本阶段范围：

- 用户权限完整业务流
- 模型厂商管理
- API Key 完整管理
- 网关转发完整业务
- 生产 HTTPS 证书自动签发策略
- 集中日志平台
- 高级 WAF 规则集

## 结论

01 基础工程任务已完成并通过本地 Docker 验证。当前系统具备继续开发后续业务模块所需的基础运行层：PHP FPM、Caddy、MySQL、Redis、队列 Worker、迁移、健康检查、日志和请求追踪。
