# 12 测试、部署和验收开发总结

生成日期：2026-07-08  
对应任务清单：`docs/development-tasks/12-testing-deployment-acceptance.md`

## 开发结论

`12-testing-deployment-acceptance.md` 对应开发已完成。当前交付覆盖本地部署、生产部署、Caddy 生产模板、数据库初始化和迁移说明、Worker 运维、备份策略、恢复演练、压测脚本、安全测试脚本和 01-11 模块最终验收清单。

本次开发没有改动业务 HTTP API 和核心 PHP 业务逻辑，重点补齐部署、运维、测试和上线验收资产。所有可在当前 Docker 环境验证的内容均已通过；需要真实生产凭据或真实 Ubuntu 主机的项目已明确列入生产验收边界。

## 已完成内容

- 本地部署说明：`docs/deployment/local.md` 覆盖 Docker Compose、本地 PHP-FPM、Caddy、MySQL、Redis、Worker 和本地 smoke 命令。
- 生产部署说明：`docs/deployment/production.md` 覆盖 Ubuntu 包安装、服务账号、目录权限、日志目录、媒体目录、导出目录、数据库初始化、迁移、PHP-FPM、Caddy、Worker、定时清理和生产验收。
- Caddy 生产模板：`Caddyfile.example` 覆盖 HTTPS、自动 HTTP 到 HTTPS、Admin/Screen 静态资源、PHP-FPM 反代、安全响应头、WAF fragment、敏感路径拦截、异常 Query 拦截、访问日志脱敏和多 upstream 配置说明。
- 数据库初始化和迁移：生产文档明确 `CREATE DATABASE`、用户授权、迁移执行和迁移前备份要求。
- Worker 运维：生产文档和 `deploy/ubuntu/tokenhub-worker.service` 覆盖启动、重启、失败重试配置、日志查看和 Redis 队列堆积检查。
- 备份策略：`docs/deployment/backup-restore.md` 和 `scripts/backup-tokenhub.sh` 覆盖 MySQL、配置、Caddy 数据目录、Caddy 配置、媒体资产和导出归档。
- 恢复演练：`scripts/restore-drill-check.sh` 覆盖备份文件完整性、压缩包可读性、校验和验证、临时目录展开和可选恢复库导入。
- 压测脚本：`scripts/pressure-smoke.sh` 覆盖非流式请求、SSE 流式请求、Key 鉴权失败、限额 429 预期、异步日志写入、大屏接口耗时、Redis 队列堆积和 Caddy 多 upstream 检查。
- 安全测试脚本：`scripts/security-smoke.sh` 覆盖 WAF 路径拦截、异常 Query 强校验、IP 白名单强校验、CSRF、CSP、安全响应头、Cookie flags、Key 泄漏防护和日志脱敏。
- 最终验收清单：`docs/acceptance/final-acceptance-checklist.md` 按 01-11 模块汇总验收标准，并补充 12 的部署、备份和恢复验收项。
- 12 任务清单回写：`docs/development-tasks/12-testing-deployment-acceptance.md` 已更新为完成态，并附带完成记录。

## 主要变更文件

- `docs/deployment/local.md`
- `docs/deployment/production.md`
- `docs/deployment/backup-restore.md`
- `docs/acceptance/final-acceptance-checklist.md`
- `docs/development-tasks/12-testing-deployment-acceptance.md`
- `docs/development-tasks/12-testing-deployment-acceptance-summary.md`
- `Caddyfile.example`
- `README.md`
- `deploy/ubuntu/README.md`
- `scripts/acceptance-check.sh`
- `scripts/security-smoke.sh`
- `scripts/pressure-smoke.sh`
- `scripts/backup-tokenhub.sh`
- `scripts/restore-drill-check.sh`
- `tests/Console/DeploymentArtifactsTest.php`

## 脚本接口

### 验收脚本

```bash
BASE_URL=https://your-domain.example \
ADMIN_PASSWORD='admin-password' \
API_KEY='tkh_xxx' \
MODEL='model-id' \
bash scripts/acceptance-check.sh
```

无 `ADMIN_PASSWORD` 时跳过 Admin 登录、资源列表、CSRF 和大屏分享管理检查。无 `API_KEY` 或 `MODEL` 时跳过真实兼容网关调用，不伪造通过。

### 安全脚本

```bash
BASE_URL=https://your-domain.example \
ADMIN_PASSWORD='admin-password' \
API_KEY='tkh_xxx' \
LOG_PATH=/var/log/tokenhub \
EXPECT_WAF_QUERY_BLOCK=1 \
EXPECT_ADMIN_IP_BLOCK=1 \
BLOCKED_IP=203.0.113.10 \
bash scripts/security-smoke.sh
```

默认模式适合本地 Docker；强校验模式用于生产或预生产，要求异常 Query 和非授权 IP 被明确阻断。

### 压测脚本

```bash
BASE_URL=https://your-domain.example \
API_KEY='tkh_xxx' \
MODEL='model-id' \
CONCURRENCY=20 \
REQUESTS=100 \
STREAM_REQUESTS=10 \
ADMIN_PASSWORD='admin-password' \
DASHBOARD_MAX_SECONDS=1.0 \
SSE_FIRST_BYTE_MAX_SECONDS=5.0 \
REDIS_HOST=127.0.0.1 \
QUEUE_ALERT_THRESHOLD=1000 \
CADDY_LB_UPSTREAMS='127.0.0.1:9000 127.0.0.1:9001' \
bash scripts/pressure-smoke.sh
```

无 Admin 密码时跳过大屏接口耗时检查；无 Redis 配置时跳过队列堆积检查；无多 upstream 配置时跳过 Caddy 负载均衡配置检查。

### 备份和恢复

备份脚本默认 dry-run：

```bash
DRY_RUN=1 BACKUP_DIR=/backup/tokenhub bash scripts/backup-tokenhub.sh
```

真实备份必须显式确认：

```bash
DRY_RUN=0 \
CONFIRM_BACKUP=1 \
BACKUP_DIR=/backup/tokenhub \
DB_HOST=127.0.0.1 \
DB_USERNAME=tokenhub \
DB_PASSWORD='db-password' \
DB_DATABASE=tokenhub \
bash scripts/backup-tokenhub.sh
```

恢复演练默认只校验备份，不导入数据库：

```bash
BACKUP_DIR=/backup/tokenhub/latest bash scripts/restore-drill-check.sh
```

导入恢复演练库必须显式确认：

```bash
BACKUP_DIR=/backup/tokenhub/latest \
CONFIRM_RESTORE=1 \
DB_HOST=127.0.0.1 \
DB_USERNAME=tokenhub \
DB_PASSWORD='db-password' \
DB_DATABASE=tokenhub_restore_drill \
bash scripts/restore-drill-check.sh
```

## 新增或覆盖测试

- `tests/Console/DeploymentArtifactsTest.php`

测试覆盖重点：

- 部署文档、验收清单、12 总结文档存在。
- 压测脚本包含并发、SSE、大屏耗时、队列堆积、多 upstream 检查入口。
- 安全脚本包含 CSP、CSRF、Cookie flags、Key 泄漏、异常 Query 强校验和 IP 白名单强校验入口。
- 验收脚本覆盖 OpenAI、Anthropic、SSE、大屏、Dashboard 路径。
- 生产 Caddy 模板包含关键环境变量、安全响应头和日志脱敏规则。
- 备份脚本默认 dry-run，并要求真实备份确认参数。
- 12 任务清单不再包含未完成复选框，并包含完成记录。

## 验证结果

已执行脚本语法检查：

```bash
docker compose exec -T php bash -n scripts/pressure-smoke.sh scripts/security-smoke.sh scripts/acceptance-check.sh scripts/backup-tokenhub.sh scripts/restore-drill-check.sh
```

执行结果：退出码 `0`。

已执行生产 Caddy 模板校验：

```bash
docker run --rm -v D:\mysoft\tokenhub:/srv/app -w /srv/app -e TOKENHUB_WAF_FRAGMENT=/srv/app/storage/caddy/waf-current.caddy -e TOKENHUB_LOG_PATH=/tmp/tokenhub-caddy-access.log caddy:2 caddy validate --config /srv/app/Caddyfile.example
```

执行结果：退出码 `0`，输出包含 `Valid configuration`。

已执行完整 PHP 测试套件：

```bash
docker compose exec -T php php bin/run-tests.php
```

执行结果：退出码 `0`，`38 test file(s) passed.`。

已执行无凭据本地验收 smoke：

```bash
docker compose exec -T php env BASE_URL=http://caddy bash scripts/acceptance-check.sh
```

执行结果：退出码 `0`，Admin 和 Gateway 真实凭据相关检查按设计跳过。

已执行无凭据本地安全 smoke：

```bash
docker compose exec -T php env BASE_URL=http://caddy bash scripts/security-smoke.sh
```

执行结果：退出码 `0`，HTTPS、Admin 登录、Key 泄漏和日志脱敏等需要真实配置的检查按设计跳过。

已执行备份 dry-run：

```bash
docker compose exec -T php env DRY_RUN=1 BACKUP_DIR=/tmp/tokenhub-backup bash scripts/backup-tokenhub.sh
```

执行结果：退出码 `0`，输出 dry-run 命令，不写入真实备份。

## 环境说明

当前 Windows 本机 `php` 不在 PATH，且 WSL/Git Bash 在普通沙箱内被系统权限拒绝。因此 PHP 测试和 Bash 语法检查均通过 Docker Compose 内的 `php` 容器执行。Caddy 模板校验通过一次性 `caddy:2` 容器执行，并将 WAF fragment 环境变量指向仓库内现有 `storage/caddy/waf-current.caddy`。

当前工作目录 `D:\mysoft\tokenhub` 无法通过 `git rev-parse` 识别为可用 Git 仓库，因此本次未生成提交。

## 验收状态

- 本地部署说明、生产部署说明、数据库迁移说明和 Worker 运维说明：已完成。
- Caddy 生产模板和 Caddy 校验：已完成。
- 备份策略和恢复演练清单：已完成。
- 压测脚本和安全脚本：已完成。
- 01-11 最终验收清单：已完成。
- 当前 Docker 环境可验证项：已通过。
- 需要真实生产环境验证项：已列入验收边界，不在本地伪造通过。

## 后续建议

12 已完成。上线前仍需在真实 Ubuntu 或预生产环境执行以下动作：

- 使用真实域名验证 HTTPS 证书签发、续期和 HSTS。
- 使用真实 Admin 账号运行 `acceptance-check.sh` 和 `security-smoke.sh` 的强校验模式。
- 使用真实 API Key 和模型运行 `pressure-smoke.sh`，记录非流式耗时、SSE 首包和大屏接口耗时。
- 配置两个或更多 PHP-FPM/Gateway upstream，验证 Caddy 负载均衡。
- 执行 `DRY_RUN=0 CONFIRM_BACKUP=1` 真实备份，并用 `CONFIRM_RESTORE=1` 导入恢复演练库。
