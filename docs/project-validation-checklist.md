# TokenHub 项目验证清单

本文档用于 TokenHub 全项目交付、回归、上线前验收和生产变更后的验证。清单覆盖代码质量、数据库、后端服务、Admin 前端、网关兼容、安全、日志审计、性能、部署、备份恢复和证据留存。

## 1. 验证范围

- [ ] PHP 8.2+ 原生后端、路由、中间件、服务层和控制器。
- [ ] MySQL 9.7 数据库迁移和核心业务表。
- [ ] Redis 队列、限额、会话和异步任务。
- [ ] Caddy 静态资源、反向代理、安全响应头、WAF 和日志过滤。
- [ ] Admin 管理后台、Screen 大屏、下载入口和 `/health` 健康检查。
- [ ] OpenAI/Anthropic 兼容网关，包括非流式、SSE 流式和多模态入口。
- [ ] 安全、权限、脱敏、审计、日志留存、备份恢复和生产部署。

## 2. 验证前置条件

- [ ] 本地或目标环境已准备 Docker Compose，或生产环境已准备 PHP-FPM、Caddy、MySQL、Redis、systemd。
- [ ] `.env` 已从 `.env.example` 创建，并完成 `APP_KEY`、`DB_*`、`REDIS_*`、`SESSION_SECURE`、`LOG_PATH`、`EXPORT_PATH`、`MEDIA_PATH` 等必要配置。
- [ ] 生产环境 `APP_DEBUG=false`，`SESSION_SECURE=true`，密钥和数据库密码不使用示例值。
- [ ] 需要真实网关验证时，已准备可用的 `API_KEY`、`MODEL` 和上游 provider 配置。
- [ ] 需要 Admin 验证时，已通过 `php scripts/create-admin.php admin '<password>'` 创建管理员。
- [ ] 需要生产验证时，已明确 `BASE_URL=https://your-domain.example`、日志目录、备份目录和恢复演练目录。

## 3. 基础工程验证

### 3.1 依赖与启动

- [ ] `composer.json` 要求的 PHP 扩展可用：`json`、`pdo`、`pdo_mysql`、`redis`。
- [ ] Docker 本地环境可以启动 `mysql`、`redis`、`php`、`caddy`、`queue-worker`。
- [ ] 容器健康检查通过，`docker compose ps` 中服务处于 running/healthy。
- [ ] PHP 容器内可以执行 `php -v`，版本满足 PHP 8.2+。

推荐命令：

```bash
cp .env.example .env
docker compose up -d --build
docker compose ps
docker compose exec -T php php -v
```

通过标准：

- [ ] 所有核心容器启动成功。
- [ ] PHP、MySQL、Redis 无启动循环或健康检查失败。
- [ ] `storage/logs` 可写，应用没有权限错误。

### 3.2 数据库迁移

- [ ] `php bin/migrate.php` 可在空库执行成功。
- [ ] `php bin/migrate.php` 可重复执行，不产生重复建表、重复字段或不可恢复错误。
- [ ] `database/migrations` 中迁移文件按预期被记录和跳过。
- [ ] 核心表包含用户、部门、角色权限、provider、model、price、api key、usage、audit、content、media、log 等模块所需结构。

推荐命令：

```bash
docker compose exec -T php php bin/migrate.php
docker compose exec -T php php bin/migrate.php
docker compose exec -T mysql mysql -utokenhub -pchange-me tokenhub -e "SHOW TABLES;"
```

通过标准：

- [ ] 首次迁移成功。
- [ ] 二次迁移无破坏性错误。
- [ ] 表结构满足全部模块测试和接口运行要求。

## 4. 自动化测试验证

### 4.1 PHP 测试套件

- [ ] `bin/run-tests.php` 能加载 `vendor/autoload.php`。
- [ ] `tests/*/*Test.php` 全部通过。
- [ ] 路由、响应、配置、Redis、权限、限额、网关解析、provider adapter、usage、审计、日志、导出、留存、Screen 分享、Admin 安全等测试覆盖关键路径。

推荐命令：

```bash
php bin/run-tests.php
docker compose exec -T php php bin/run-tests.php
```

通过标准：

- [ ] 输出包含全部测试文件 `PASS`。
- [ ] 最终输出类似 `N test file(s) passed.`。
- [ ] 不允许存在被忽略的失败测试；如环境依赖导致失败，必须记录原因和复验计划。

### 4.2 脚本语法验证

- [ ] Shell 脚本语法通过。
- [ ] 生产脚本不依赖本地硬编码路径。
- [ ] 备份和恢复脚本的危险操作有显式确认变量保护。

推荐命令：

```bash
bash -n scripts/smoke-test.sh
bash -n scripts/verify-foundation.sh
bash -n scripts/acceptance-check.sh
bash -n scripts/security-smoke.sh
bash -n scripts/pressure-smoke.sh
bash -n scripts/backup-tokenhub.sh
bash -n scripts/restore-drill-check.sh
bash -n scripts/caddy-publish.sh
bash -n scripts/caddy-rollback.sh
```

通过标准：

- [ ] 所有脚本语法检查返回 0。
- [ ] 备份脚本默认 `DRY_RUN=1`。
- [ ] 恢复脚本仅在 scratch 目录执行归档恢复，并且数据库导入需要 `CONFIRM_RESTORE=1`。

## 5. HTTP 与服务烟测

### 5.1 基础入口

- [ ] `/health` 返回可识别健康状态。
- [ ] `/admin-api/health` 返回 Admin API 健康状态。
- [ ] `/admin/` 返回 Admin 静态页面。
- [ ] `/screen/` 返回大屏静态页面。
- [ ] `/download/*` 对非法 token 返回 400、401、403 或 404。

推荐命令：

```bash
curl -i http://localhost:8080/health
curl -i http://localhost:8080/admin-api/health
curl -i http://localhost:8080/admin/
curl -i http://localhost:8080/screen/
BASE_URL=http://localhost:8080 bash scripts/acceptance-check.sh
```

通过标准：

- [ ] 健康接口无 5xx。
- [ ] Admin 和 Screen 页面包含 TokenHub 标识。
- [ ] 不存在目录列表、源码下载或敏感文件暴露。

### 5.2 Worker 与队列

- [ ] Redis 连接正常。
- [ ] `bin/queue-test-job.php` 可投递测试任务。
- [ ] `bin/queue-worker.php --once` 可消费一次任务。
- [ ] 持续 worker `queue-worker` 容器或 systemd 服务运行稳定。
- [ ] 失败任务进入 `QUEUE_FAILED_NAME`，不会丢失。

推荐命令：

```bash
docker compose exec -T redis redis-cli ping
docker compose exec -T php php bin/queue-test-job.php
docker compose exec -T php php bin/queue-worker.php --once
docker compose logs --tail=100 queue-worker
```

通过标准：

- [ ] Redis 返回 `PONG`。
- [ ] 测试任务可被消费。
- [ ] Worker 日志无连续异常或权限错误。

## 6. Admin 与权限验证

### 6.1 登录和会话

- [ ] 管理员可登录。
- [ ] 登录响应返回 `csrf_token`。
- [ ] `/admin-api/me` 可读取当前用户。
- [ ] 退出登录后会话失效。
- [ ] Cookie 具备 `HttpOnly`、`SameSite=Strict`，HTTPS 环境具备 `Secure`。

推荐命令：

```bash
BASE_URL=http://localhost:8080 ADMIN_PASSWORD='admin-password' bash scripts/acceptance-check.sh
BASE_URL=https://your-domain.example ADMIN_PASSWORD='admin-password' bash scripts/security-smoke.sh
```

通过标准：

- [ ] 登录成功并能访问 Admin API。
- [ ] 未登录访问受保护接口返回 401 或 403。
- [ ] 无 CSRF token 的写请求返回 419。

### 6.2 用户、部门和权限

- [ ] 部门、用户、角色权限可创建、查询、禁用或更新。
- [ ] 服务端强制校验部门访问范围和角色权限。
- [ ] Admin 前端隐藏按钮只作为体验优化，不能替代服务端权限。
- [ ] 登录、权限变更、敏感配置变更写入审计日志。

通过标准：

- [ ] 超权访问被拒绝。
- [ ] 禁用用户或禁用 key 后立即生效。
- [ ] 权限失败路径有测试或手工证据。

## 7. Provider、模型和价格验证

- [ ] Provider 可配置名称、类型、base URL 和加密密钥。
- [ ] 模型可绑定 provider，并配置能力、上下文、启用状态。
- [ ] 价格配置可创建、查询和更新。
- [ ] 未配置价格时不影响 usage 写入。
- [ ] 已配置价格时，usage 可生成成本快照和聚合成本。
- [ ] provider 密钥不出现在 API 响应、错误响应、应用日志、Caddy 日志、导出文件中。

通过标准：

- [ ] 加密字段落库后不可明文读取。
- [ ] 成本统计和 usage 明细数据一致。
- [ ] 密钥泄漏检查无命中。

## 8. API Key、限额和用量验证

- [ ] API Key 明文只在创建时展示一次。
- [ ] API Key 哈希存储，可按 hash 鉴权。
- [ ] Key 禁用、过期、IP/CIDR 白名单生效。
- [ ] Redis 限额计数具备原子性。
- [ ] 达到限额时返回 429。
- [ ] 网关请求成功或失败后，usage 明细和聚合任务可异步写入。
- [ ] usage 写入失败不阻塞主链路，但必须可观测。

推荐命令：

```bash
BASE_URL=http://localhost:8080 API_KEY='tkh_xxx' MODEL='model-id' bash scripts/pressure-smoke.sh
BASE_URL=http://localhost:8080 API_KEY='tkh_xxx' MODEL='model-id' EXPECT_RATE_LIMIT=1 bash scripts/pressure-smoke.sh
```

通过标准：

- [ ] 无效 key 返回 401、403 或 429。
- [ ] 有效 key 可调用授权模型。
- [ ] 限额和白名单策略按配置生效。
- [ ] 异步 usage 记录可在 Admin 或数据库中查询。

## 9. 兼容网关验证

### 9.1 OpenAI 兼容接口

- [ ] `/v1/chat/completions` 支持非流式请求。
- [ ] `/v1/chat/completions` 支持 `stream=true` SSE 响应。
- [ ] `/v1/responses` 支持兼容请求。
- [ ] `/v1/embeddings` 支持兼容请求。
- [ ] 错误响应不泄漏 provider key、内部路径或敏感配置。

### 9.2 Anthropic 兼容接口

- [ ] `/v1/messages` 支持 Anthropic 兼容请求。
- [ ] Anthropic 请求体解析、模型路由和错误格式符合兼容层约定。
- [ ] provider 超时、上游失败、模型不可用时返回可识别错误。

推荐命令：

```bash
BASE_URL=http://localhost:8080 API_KEY='tkh_xxx' MODEL='model-id' bash scripts/acceptance-check.sh
BASE_URL=http://localhost:8080 API_KEY='tkh_xxx' MODEL='model-id' REQUESTS=20 CONCURRENCY=10 STREAM_REQUESTS=3 bash scripts/pressure-smoke.sh
```

通过标准：

- [ ] 非流式请求返回 2xx 或兼容错误格式。
- [ ] SSE 响应包含 `data:` 或明确的流式响应特征。
- [ ] 首字节延迟符合 `SSE_FIRST_BYTE_MAX_SECONDS` 阈值。
- [ ] 网关错误不暴露内部密钥。

## 10. 多模态与媒体资产验证

- [ ] ASR、TTS、视觉、文生图、文生视频入口可调用或返回明确的 400、422、501。
- [ ] 多模态任务状态可查询。
- [ ] 媒体资产落到 `MEDIA_PATH`。
- [ ] 下载链接短期有效，并受权限或 token 控制。
- [ ] 下载响应不暴露内部绝对路径。
- [ ] 媒体清理任务可入队并执行。

通过标准：

- [ ] 支持的多模态入口返回预期业务响应。
- [ ] 暂未实现入口返回受控错误，不产生 500。
- [ ] 媒体文件权限、过期和下载审计符合策略。

## 11. 内容留存、导出和下载验证

- [ ] 内容留存策略可查看和修改。
- [ ] 会话存档可查询。
- [ ] CSV 导出任务可创建。
- [ ] 导出任务由 worker 生成文件。
- [ ] 下载链接短期有效，过期或非法 token 被拒绝。
- [ ] 到期内容、导出文件和媒体文件清理任务可执行。

通过标准：

- [ ] 导出文件字段符合权限和脱敏要求。
- [ ] 敏感字段不出现在导出文件中。
- [ ] 清理任务可重复执行，不误删未过期数据。

## 12. Screen 大屏与分享链接验证

- [ ] `/screen/` 静态页面可访问。
- [ ] Admin 可创建独立大屏分享链接。
- [ ] 分享链接可设置密码和过期策略。
- [ ] 分享登录后保持会话。
- [ ] 主动退出后需要重新登录。
- [ ] 分享访问日志可查询和审计。
- [ ] 大屏聚合接口响应时间满足产品要求。

通过标准：

- [ ] 未授权分享访问被拒绝。
- [ ] 合法分享访问只看到授权范围内数据。
- [ ] 大屏接口在压测阈值内完成，或有明确瓶颈说明。

## 13. 安全、WAF 和脱敏验证

### 13.1 Caddy 安全配置

- [ ] `Caddyfile.example` 可作为生产模板。
- [ ] HTTPS 环境启用 HSTS。
- [ ] 响应头包含 CSP、X-Frame-Options、X-Content-Type-Options、Referrer-Policy、Permissions-Policy。
- [ ] `.env`、`.git`、备份文件、debug 路径、vendor 路径被拒绝。
- [ ] WAF fragment 可发布和回滚。
- [ ] Caddy 日志过滤 Authorization、Cookie、API key 和敏感 query。

推荐命令：

```bash
TOKENHUB_DOMAIN=your-domain.example caddy validate --config Caddyfile.example
BASE_URL=https://your-domain.example bash scripts/security-smoke.sh
SOURCE_FRAGMENT=storage/caddy/waf-current.caddy ACTIVE_FRAGMENT=/var/lib/tokenhub/caddy/waf-current.caddy bash scripts/caddy-publish.sh
bash scripts/caddy-rollback.sh
```

通过标准：

- [ ] Caddy 配置验证通过。
- [ ] 安全响应头存在。
- [ ] 敏感路径无法访问。
- [ ] WAF 变更可回滚。

### 13.2 Admin 与接口安全

- [ ] Admin 写操作缺少 CSRF 时返回 419。
- [ ] 未授权 Admin API 返回 401 或 403。
- [ ] 非法方法返回 405 或受控错误。
- [ ] API Key 不在响应体、日志、导出文件中明文出现。
- [ ] AST 脱敏和出站脱敏对嵌套字段生效。

推荐命令：

```bash
BASE_URL=https://your-domain.example ADMIN_PASSWORD='admin-password' API_KEY='tkh_xxx' LOG_PATH=/var/log/tokenhub bash scripts/security-smoke.sh
```

通过标准：

- [ ] 安全脚本通过。
- [ ] 日志目录中无真实 API key、provider key、管理员密码。
- [ ] 错误响应不包含内部文件路径或堆栈。

## 14. 日志、观测和审计验证

- [ ] 应用日志、worker 日志、Caddy 访问日志、安全日志、审计日志分离。
- [ ] 日志为 JSONL 或可机器解析格式。
- [ ] 登录、登出、权限变更、provider 变更、key 变更、导出、下载、分享访问写入审计。
- [ ] 日志留存策略可配置。
- [ ] 日志查询和导出受权限控制。
- [ ] 队列积压、WAF 命中异常、日志写入失败有可观测入口。

推荐命令：

```bash
ls -lah storage/logs
tail -n 50 storage/logs/app.jsonl
tail -n 50 storage/logs/audit.jsonl
tail -n 50 storage/logs/worker.jsonl
BASE_URL=http://localhost:8080 API_KEY='tkh_xxx' MODEL='model-id' LOG_PATH=storage/logs bash scripts/pressure-smoke.sh
```

通过标准：

- [ ] 关键行为有审计记录。
- [ ] 日志可读、可轮转、可查询。
- [ ] 敏感字段被脱敏。

## 15. 性能和压力验证

- [ ] 非流式网关请求在目标并发下完成。
- [ ] SSE 首字节延迟小于 `SSE_FIRST_BYTE_MAX_SECONDS`。
- [ ] Admin dashboard 接口小于 `DASHBOARD_MAX_SECONDS`，默认 1 秒。
- [ ] Redis 队列积压小于 `QUEUE_ALERT_THRESHOLD`。
- [ ] Caddy 多 upstream 配置时至少包含 2 个 upstream。
- [ ] 压测后 usage、日志、审计和队列状态一致。

推荐命令：

```bash
BASE_URL=https://your-domain.example API_KEY='tkh_xxx' MODEL='model-id' REQUESTS=100 CONCURRENCY=20 STREAM_REQUESTS=5 bash scripts/pressure-smoke.sh
BASE_URL=https://your-domain.example API_KEY='tkh_xxx' MODEL='model-id' ADMIN_PASSWORD='admin-password' REDIS_HOST=127.0.0.1 LOG_PATH=/var/log/tokenhub bash scripts/pressure-smoke.sh
```

通过标准：

- [ ] 压测脚本返回 0。
- [ ] 无 5xx 激增。
- [ ] Worker 和 Redis 无持续积压。
- [ ] 压测报告记录请求数、并发数、平均耗时、最大耗时和失败数。

## 16. 部署验证

### 16.1 本地部署

- [ ] `docs/deployment/local.md` 中命令可执行。
- [ ] `scripts/smoke-test.sh` 可完成基础部署、迁移、测试、健康检查和 worker 单次消费。
- [ ] `scripts/verify-foundation.sh` 可完成基础服务验证。

推荐命令：

```bash
bash scripts/smoke-test.sh
bash scripts/verify-foundation.sh
```

通过标准：

- [ ] 脚本执行成功。
- [ ] 本地服务入口可访问。
- [ ] 本地日志无权限错误。

### 16.2 生产部署

- [ ] 生产目录符合 `/var/www/tokenhub`、`/etc/tokenhub`、`/var/log/tokenhub`、`/var/lib/tokenhub/{exports,media,caddy}` 约定。
- [ ] `/etc/tokenhub/tokenhub.env` 权限为 `root:www-data`、`0640` 或等价最小权限。
- [ ] PHP-FPM socket 与 Caddy upstream 一致。
- [ ] `tokenhub-worker.service` 已启用并运行。
- [ ] Caddy 是唯一公网入口。
- [ ] 迁移前已完成数据库备份。

推荐命令：

```bash
sudo systemctl status php8.2-fpm --no-pager
sudo systemctl status caddy --no-pager
sudo systemctl status tokenhub-worker --no-pager
sudo -u www-data php /var/www/tokenhub/bin/run-tests.php
curl -i https://your-domain.example/health
```

通过标准：

- [ ] 生产服务全部 running。
- [ ] 健康检查通过。
- [ ] 外部无法访问 PHP 源码、配置文件、`.env`、`.git`。

## 17. 备份和恢复验证

### 17.1 备份

- [ ] 备份包含 MySQL dump。
- [ ] 备份包含配置、Caddy 数据、Caddy 配置、媒体资产、导出文件。
- [ ] 真实备份需要 `DRY_RUN=0` 和 `CONFIRM_BACKUP=1`。
- [ ] 备份完成后生成 `SHA256SUMS`。
- [ ] `latest` 指向最新备份集。

推荐命令：

```bash
DRY_RUN=1 BACKUP_DIR=/backup/tokenhub bash scripts/backup-tokenhub.sh
DRY_RUN=0 CONFIRM_BACKUP=1 BACKUP_DIR=/backup/tokenhub bash scripts/backup-tokenhub.sh
```

通过标准：

- [ ] dry run 输出预期命令。
- [ ] 真实备份产物完整。
- [ ] 校验文件存在且可用于恢复验证。

### 17.2 恢复演练

- [ ] 恢复脚本可校验 `mysql.sql`。
- [ ] tar 归档可读取。
- [ ] `SHA256SUMS` 校验通过。
- [ ] 归档可恢复到 scratch 目录。
- [ ] 如设置 `CONFIRM_RESTORE=1`，可导入演练数据库并查询到表。

推荐命令：

```bash
BACKUP_DIR=/backup/tokenhub/latest bash scripts/restore-drill-check.sh
CONFIRM_RESTORE=1 BACKUP_DIR=/backup/tokenhub/latest DB_DATABASE=tokenhub_restore_drill bash scripts/restore-drill-check.sh
```

通过标准：

- [ ] 恢复演练脚本返回 0。
- [ ] 演练不会覆盖生产库。
- [ ] 恢复后的数据库有表且可查询。

## 18. 文档和交付物验证

- [ ] `README.md` Quick Start 与当前命令一致。
- [ ] `docs/deployment/local.md` 覆盖本地 Docker Compose、迁移、测试、健康检查和 worker。
- [ ] `docs/deployment/production.md` 覆盖生产目录、权限、PHP-FPM、Caddy、worker、cron、验收。
- [ ] `docs/deployment/backup-restore.md` 覆盖备份和恢复演练。
- [ ] `docs/acceptance/final-acceptance-checklist.md` 与当前模块范围一致。
- [ ] `docs/development-tasks` 中模块文档与实际实现没有明显漂移。
- [ ] 所有新增脚本和命令在文档中有使用方式和必要环境变量说明。

通过标准：

- [ ] 文档中的命令可复制执行。
- [ ] 文档不包含过期路径、过期端口或示例密钥误用。
- [ ] 验收清单覆盖所有已交付模块。

## 19. 证据留存清单

每次完整验收建议留存以下证据：

- [ ] 验证日期、验证人、代码版本或发布包版本。
- [ ] `.env` 配置摘要，敏感值必须脱敏。
- [ ] `docker compose ps` 或生产 `systemctl status` 输出。
- [ ] `php bin/run-tests.php` 输出。
- [ ] `scripts/acceptance-check.sh` 输出。
- [ ] `scripts/security-smoke.sh` 输出。
- [ ] `scripts/pressure-smoke.sh` 输出和压测参数。
- [ ] `scripts/backup-tokenhub.sh` dry run 和真实备份输出。
- [ ] `scripts/restore-drill-check.sh` 输出。
- [ ] 关键 HTTP 响应头截图或 `curl -i` 输出。
- [ ] Admin 登录、权限拒绝、CSRF 拒绝、审计日志记录的证据。
- [ ] 网关成功请求、SSE 流式请求、错误脱敏响应的证据。
- [ ] 日志目录敏感字段搜索结果。

推荐证据目录格式：

```text
validation-evidence/
  YYYYMMDD-HHMM-release-name/
    environment.md
    test-output.txt
    acceptance-output.txt
    security-output.txt
    pressure-output.txt
    backup-output.txt
    restore-output.txt
    curl-headers.txt
    notes.md
```

## 20. 阻断上线的问题

以下任一问题未解决时，不建议上线：

- [ ] 迁移无法在目标环境执行或无法重复执行。
- [ ] `php bin/run-tests.php` 存在未解释的失败。
- [ ] `/health` 或 `/admin-api/health` 不可用。
- [ ] Admin 登录、CSRF、权限校验存在绕过。
- [ ] API Key、provider key、管理员密码出现在响应、日志或导出文件中。
- [ ] `.env`、`.git`、备份文件、源码或 vendor 目录可被公网访问。
- [ ] Worker 无法消费队列，导致 usage、导出、清理或审计异步任务持续积压。
- [ ] 备份无法生成，或恢复演练无法通过。
- [ ] 生产 `APP_DEBUG=true`，或示例密钥、示例密码未替换。
- [ ] Caddy、PHP-FPM、MySQL、Redis、worker 任一核心服务无法稳定运行。

## 21. 推荐完整验收命令序列

本地环境：

```bash
cp .env.example .env
docker compose up -d --build
docker compose exec -T php php bin/migrate.php
docker compose exec -T php php bin/run-tests.php
docker compose exec -T php php scripts/create-admin.php admin 'change-this-password'
docker compose exec -T php php bin/queue-test-job.php
docker compose exec -T php php bin/queue-worker.php --once
BASE_URL=http://localhost:8080 bash scripts/acceptance-check.sh
BASE_URL=http://localhost:8080 ADMIN_PASSWORD='change-this-password' bash scripts/security-smoke.sh
```

带真实网关凭证的本地或预生产环境：

```bash
BASE_URL=http://localhost:8080 ADMIN_PASSWORD='change-this-password' API_KEY='tkh_xxx' MODEL='model-id' bash scripts/acceptance-check.sh
BASE_URL=http://localhost:8080 API_KEY='tkh_xxx' MODEL='model-id' REQUESTS=20 CONCURRENCY=10 STREAM_REQUESTS=3 bash scripts/pressure-smoke.sh
```

生产环境：

```bash
BASE_URL=https://your-domain.example bash /var/www/tokenhub/scripts/security-smoke.sh
BASE_URL=https://your-domain.example ADMIN_PASSWORD='admin-password' API_KEY='tkh_xxx' MODEL='model-id' bash /var/www/tokenhub/scripts/acceptance-check.sh
BASE_URL=https://your-domain.example API_KEY='tkh_xxx' MODEL='model-id' ADMIN_PASSWORD='admin-password' LOG_PATH=/var/log/tokenhub bash /var/www/tokenhub/scripts/pressure-smoke.sh
DRY_RUN=1 BACKUP_DIR=/backup/tokenhub bash /var/www/tokenhub/scripts/backup-tokenhub.sh
DRY_RUN=0 CONFIRM_BACKUP=1 BACKUP_DIR=/backup/tokenhub bash /var/www/tokenhub/scripts/backup-tokenhub.sh
BACKUP_DIR=/backup/tokenhub/latest bash /var/www/tokenhub/scripts/restore-drill-check.sh
```
