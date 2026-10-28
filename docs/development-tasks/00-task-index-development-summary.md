# TokenHub 00 任务清单开发总结

生成日期：2026-07-08  
对应任务索引：`docs/development-tasks/00-task-index.md`  
当前目标环境：Ubuntu 生产部署口径与开发环境统一为 PHP-FPM + Caddy + MySQL 9.7 + Redis + systemd Worker；本地开发使用 Docker Compose 验证。

## 1. 总体结论

TokenHub 已按 `00-task-index.md` 的 01-12 全量范围完成一个可运行版本。当前仓库已经从纯文档状态扩展为原生 PHP 项目，包含后端服务、数据库迁移、Admin 静态前端、独立大屏、兼容网关、队列 worker、安全 WAF、脱敏、内容留存、导出、日志审计、Ubuntu 部署文件和测试套件。

本次实现没有引入 Laravel、Symfony、ThinkPHP、Hyperf 等完整后端框架。后端采用原生 PHP 8.2+ 风格、PSR-4 自动加载、PDO、Redis、Caddy 和 Docker/Ubuntu 部署脚本。当前开发环境实际运行 MySQL 9.7，生产部署文档和自检脚本也已统一为 MySQL 9.7。

## 2. 交付范围

### 01 基础工程

已完成原生 PHP 工程骨架和运行入口：

- `app/Bootstrap`：应用启动、容器、Kernel 路由注册。
- `app/Support`：配置、数据库、Redis、日志、路由、响应、请求上下文、加密和辅助函数。
- `public/index.php`：统一 HTTP 入口。
- `docker-compose.yml`：PHP、Caddy、MySQL 9.7、Redis、Queue Worker。
- `docker/caddy/Caddyfile`：静态资源、PHP 代理、安全响应头、WAF 片段导入和安全日志。
- `database/migrations`：基础表、兼容修复、内容留存、多模态、聚合和审计字段迁移。

### 02 用户权限

已完成 Admin 登录、Cookie 会话、CSRF、用户、部门和权限范围：

- `AuthService`、`PermissionService`、`DepartmentService`。
- `AdminAuthMiddleware`、`CsrfMiddleware`。
- 多级部门路径、管理员范围过滤、角色权限、用户停用和审计写入。

### 03 厂商模型价格

已完成厂商、模型和价格快照基础能力：

- 厂商密钥加密存储，API 和日志不返回明文。
- 模型能力、路由优先级、价格快照和成本统计。
- `PriceService`、`ProviderHttpClient`、厂商适配器基础。

### 04 OpenAI/Anthropic 兼容网关

已完成兼容网关基础闭环：

- OpenAI 请求解析：chat、responses、embeddings、audio、images、videos。
- Anthropic messages 请求解析。
- `GatewayService`、`GatewayController`、`GatewayAuthMiddleware`。
- `ProviderAdapterInterface`、`OpenAIAdapter`、`AnthropicAdapter`、`NativeMultimodalAdapter`。
- 支持非流式和 SSE 转发路径，错误映射不暴露内部路径、密钥和上游地址。

### 05 API Key、限额和用量

已完成 API Key 生命周期、限额预检和用量记录：

- API Key 明文只展示一次，数据库只保存哈希。
- IP/CIDR 校验、模型权限、状态和过期判断。
- Redis 限额、并发计数、回滚逻辑。
- 用量明细、聚合写入和成本统计。

### 06 多模态资产

已完成多模态任务和媒体资产基础：

- ASR、TTS、视觉、文生图、文生视频能力映射。
- 多模态任务状态、媒体资产登记、短期下载 URL。
- API Key 范围和独立大屏范围下的资产访问控制。

### 07 内容留存和导出

已完成内容留存策略、会话归档和导出任务：

- 原文、脱敏、摘要留存策略。
- 内容查看权限、原文权限控制。
- 用量导出、会话归档导出、日志导出。
- 导出文件加密存储，短期下载链接，导出审计。

### 08 Admin 和智慧大屏

已完成原生 ES Modules Admin：

- `public/admin/index.html` 和 `public/admin/assets/js/*`。
- 统一 `api.js`，处理 CSRF、401/403、错误码和 request_id。
- 覆盖用户部门、厂商模型价格、Key 限额、用量、内容、导出、日志审计、WAF、脱敏、系统设置和大屏分享。
- Dashboard 支持实时、趋势、排行、安全、性能和多模态视图。

### 09 独立大屏分享

已完成 `/screen/{share_code}` 独立大屏：

- 独立账号密码登录。
- Cookie 持久会话。
- 链接停用、密码重置、会话撤销、IP 拒绝强制下线。
- 范围过滤、访问日志和管理员侧分享链接管理。

### 10 安全 WAF 和脱敏

已完成 WAF 规则、发布、回滚和脱敏：

- Caddy WAF 片段生成、验证、发布失败回滚。
- IP allow/deny、method、query、header、body size、安全响应头规则。
- 专用 `caddy-security.jsonl` 安全日志。
- JSON、SQL、ENV、Header、Query 等敏感内容脱敏。
- 出站脱敏和脱敏事件记录。

### 11 日志可观测和审计

已完成 JSON 日志、审计和日志留存基础：

- 请求日志、审计日志、安全日志分离。
- 敏感字段清理，Authorization、Cookie、API Key、厂商密钥不落明文。
- 审计封装覆盖登录、导出、WAF、分享链接等敏感操作。
- 日志导出脱敏和权限范围控制。

### 12 测试、部署和验收

已完成测试和 Ubuntu 部署资产：

- `tests/` 覆盖 Support、Services、Frontend、Console。
- `deploy/ubuntu/README.md`：Ubuntu 部署说明。
- `deploy/ubuntu/php-fpm-tokenhub.conf`：PHP-FPM pool。
- `deploy/ubuntu/tokenhub-worker.service`：systemd worker。
- `deploy/ubuntu/tokenhub-self-check.sh`：生产自检，包含 MySQL 9.7 校验。
- `scripts/acceptance-check.sh`、`security-smoke.sh`、`pressure-smoke.sh`、`caddy-publish.sh`、`caddy-rollback.sh`。

## 3. 关键公共接口

Admin API 统一响应格式：

```json
{ "ok": true, "data": {}, "request_id": "req_xxx" }
```

```json
{ "ok": false, "error": { "code": "ERROR_CODE", "message": "message" }, "request_id": "req_xxx" }
```

兼容网关保留 OpenAI/Anthropic 原始响应结构，并统一隐藏内部路径、堆栈、上游地址和密钥。

固定错误码已覆盖：

- `ADMIN_UNAUTHENTICATED`
- `ADMIN_PERMISSION_DENIED`
- `ADMIN_CSRF_INVALID`
- `API_KEY_INVALID`
- `API_KEY_EXPIRED`
- `IP_FORBIDDEN`
- `MODEL_FORBIDDEN`
- `QUOTA_EXCEEDED`
- `CONCURRENCY_EXCEEDED`
- `PROVIDER_TIMEOUT`
- `PROVIDER_ERROR`
- `CONTENT_FORBIDDEN`
- `WAF_BLOCKED`

所有 HTTP 响应携带 `X-Request-Id`，内部日志携带 request/trace 上下文。

## 4. 数据安全策略

已落实的安全策略：

- API Key 明文只展示一次，数据库只存哈希。
- 厂商密钥加密存储。
- 日志、审计、导出、Admin API 不返回密钥明文。
- 导出文件加密落盘，下载链接短期有效。
- Admin 前端不使用 `localStorage` 或 `sessionStorage` 保存敏感凭证。
- Caddy 层过滤敏感路径，`.env` 和 `.git/config` 不可访问。
- 内容留存支持原文、脱敏、摘要和清理策略。

## 5. 当前运行环境

当前 Docker Compose 服务状态已验证：

- `tokenhub-caddy`：运行中，暴露 `8080` 和 `8443`。
- `tokenhub-php`：运行中，健康。
- `tokenhub-queue-worker`：运行中，健康。
- `tokenhub-mysql`：运行中，镜像 `mysql:9.7`，健康。
- `tokenhub-redis`：运行中，健康。

当前开发环境只使用 `mysql:9.7` 和 `mysql_97_data`。生产部署文档、自检脚本和开发文档均已统一为 MySQL 9.7。

## 6. 验收记录

已执行并通过的验证：

```bash
docker compose config --quiet
docker compose run --rm php php bin/migrate.php
docker compose run --rm php php bin/run-tests.php
```

测试结果：

```text
33 test file(s) passed.
```

HTTP 烟测结果：

- `GET http://localhost:8080/health` 返回 healthy，包含 PHP、MySQL、Redis 状态。
- `GET http://localhost:8080/admin/` 返回 `200`。
- `GET http://localhost:8080/.env` 返回 `404`。
- `GET http://localhost:8080/.git/config` 返回 `404`。

队列烟测：

- `php bin/queue-test-job.php` 可入队。
- `php bin/queue-worker.php --once` 可正常执行并退出。

版本一致性检查：

```bash
rg -n "MySQL 8|MySQL 8\\.0|mysql:8|mysql 8|8\\.0\\+" README.md deploy docs docker-compose.yml .env.example scripts config database
```

结果：无命中。

## 7. 仍需在真实 Ubuntu 环境执行的动作

当前已在 Docker/Windows 工作区完成运行验证。以下动作属于真实生产主机上线前验收，需要在 Ubuntu 主机执行：

- 安装或接入 MySQL 9.7，确认 `mysql --version` 符合要求。
- 部署 PHP-FPM pool、Caddyfile、systemd worker。
- 执行 `deploy/ubuntu/tokenhub-self-check.sh`。
- 执行 `scripts/acceptance-check.sh`。
- 做一次备份恢复演练。
- 做一次 Caddy WAF 发布失败回滚演练。
- 按生产域名、TLS、日志目录、媒体目录和导出目录重新确认权限。

## 8. 结论

`00-task-index.md` 中 01-12 的开发目标已形成完整可运行版本，并通过当前 Docker 开发环境测试。当前剩余工作不是功能开发缺口，而是真实 Ubuntu 生产主机上的部署执行和上线验收。
