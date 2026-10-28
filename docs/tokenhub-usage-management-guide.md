# TokenHub 项目使用和管理文档

本文面向 TokenHub 的部署管理员、系统管理员和日常运维人员，覆盖项目启动、管理员登录、密码初始化、用户部门管理、模型网关配置、API Key、配额、日志审计、内容留存、大屏分享和备份恢复。

## 1. 系统概览

TokenHub 是一个基于 PHP 8.2+ 的模型网关和管理平台，默认由以下组件组成：

| 组件 | 用途 |
| --- | --- |
| PHP-FPM | 执行业务接口、管理端接口、网关转发逻辑 |
| Caddy | 对外 HTTP/HTTPS 入口，服务 Admin、Screen 静态资源并反向代理 PHP |
| MySQL | 存储用户、部门、厂商、模型、API Key、用量、审计、配置等核心数据 |
| Redis | 管理队列、限额计数、API Key 鉴权缓存 |
| Queue Worker | 处理异步任务、清理、导出和日志归档 |

主要入口：

| 入口 | 默认本地地址 | 说明 |
| --- | --- | --- |
| 管理后台 | `http://localhost:8080/admin/` | 管理员登录和系统配置 |
| 管理 API | `http://localhost:8080/admin-api/*` | 管理后台使用的 JSON API |
| 模型网关 | `http://localhost:8080/v1/*` | OpenAI / Anthropic 兼容接口 |
| 大屏 | `http://localhost:8080/screen/` | 独立大屏分享入口 |
| 健康检查 | `http://localhost:8080/health` | 外部健康检查 |
| Admin 健康检查 | `http://localhost:8080/admin-api/health` | 管理侧健康检查 |

## 2. 本地部署和首次初始化

### 2.1 准备环境

本地默认使用 Docker Compose：

```bash
cp .env.example .env
docker compose up -d --build
docker compose exec -T php php bin/migrate.php
```

常用检查：

```bash
curl http://localhost:8080/health
curl http://localhost:8080/admin/
curl http://localhost:8080/screen/
docker compose exec -T redis redis-cli ping
docker compose exec -T php php bin/run-tests.php
```

### 2.2 初始化超级管理员密码

首次部署后必须创建超级管理员。脚本会写入或更新 `users` 表中的超级管理员账号，并绑定根部门 `id=1`：

```bash
docker compose exec -T php php scripts/create-admin.php admin 'change-this-password'
```

生产环境示例：

```bash
cd /var/www/tokenhub
sudo -u www-data php scripts/create-admin.php admin 'change-this-password'
```

要求：

- 密码长度至少 12 位。
- 该命令创建的账号角色为 `super_admin`。
- 如用户名已存在，脚本会重置该用户密码、角色和状态。
- 根部门由迁移脚本初始化：`id=1`、`name=Root`、`path=/1/`。

### 2.3 登录后台

访问：

```text
http://localhost:8080/admin/
```

输入：

- 用户名：初始化时传给 `scripts/create-admin.php` 的用户名，例如 `admin`。
- 密码：初始化时传入的密码。

登录成功后，服务端设置 `tokenhub_admin_session` Cookie，并返回 CSRF Token。后台前端会在后续写操作中自动附带 `x-csrf-token`。

后台会话规则：

- 默认有效期由 `.env` 的 `ADMIN_SESSION_TTL_MINUTES` 控制，默认 120 分钟。
- Cookie 默认使用 `HttpOnly`、`SameSite=Strict`。
- `SESSION_SECURE=true` 时 Cookie 带 `Secure`，生产环境必须保持开启。
- 用户被禁用、离职、所属部门被禁用或会话被撤销后，已有登录会话失效。

## 3. 密码和账号管理

### 3.1 超级管理员密码初始化或找回

如果忘记超级管理员密码，在服务器上重新执行初始化脚本：

```bash
docker compose exec -T php php scripts/create-admin.php admin 'new-strong-password'
```

这会将 `admin` 更新为可用的 `super_admin`，适合首次初始化和紧急找回。

### 3.2 普通用户初始密码

后台进入“用户”页面，创建用户时填写：

- `department_id`：所属部门 ID。
- `username`：登录名，全局唯一。
- `password`：初始密码。
- `role`：`super_admin`、`admin` 或 `user`。
- `managed_department_ids`：管理员可管理部门 ID 数组，JSON 格式，例如 `[2,3]`。

创建时明文密码只用于生成 `password_hash`，数据库不保存明文密码。

### 3.3 重置用户密码

后台通过用户管理中的密码重置功能，或调用接口：

```http
POST /admin-api/users/{id}/reset-password
Content-Type: application/json
x-csrf-token: <csrf>

{"password":"new-strong-password"}
```

重置后系统会：

- 更新用户的 `password_hash`。
- 撤销该用户已有后台会话。
- 失效该用户名下 API Key 的 Redis 鉴权缓存。
- 写入审计日志 `user.password.reset`。

### 3.4 禁用、离职和启用状态

用户状态：

| 状态 | 含义 |
| --- | --- |
| `active` | 正常可登录、可调用 |
| `disabled` | 禁用，不能登录，相关 API Key 不可用 |
| `departed` | 离职，不能登录，相关 API Key 不可用 |

部门状态：

| 状态 | 含义 |
| --- | --- |
| `active` | 部门正常 |
| `disabled` | 部门停用，部门下用户和 API Key 调用受影响 |

禁用用户或部门会撤销会话或失效相关 API Key 缓存，后续鉴权会拒绝。

## 4. 角色和权限模型

TokenHub 有三类管理角色：

| 角色 | 权限 |
| --- | --- |
| `super_admin` | 可管理所有部门、用户、厂商、模型、价格、系统配置、安全策略 |
| `admin` | 可管理自身授权部门及子部门内的用户、API Key、用量等数据 |
| `user` | 只能查看自己相关的用量、任务、资产、归档和导出 |

权限边界以部门树为核心：

- 部门使用 `path` 表示层级，例如 `/1/2/5/`。
- `super_admin` 不受部门范围限制。
- `admin` 的范围来自自身 `department_id` 和 `managed_department_ids`。
- 普通用户无法管理用户、部门和全局配置。
- 前端隐藏菜单只是体验优化，服务端仍会强制校验权限。

## 5. 后台功能模块

后台菜单按角色展示，核心模块如下。

| 模块 | 说明 |
| --- | --- |
| 智慧大屏 | 实时、趋势、排行、安全、性能、多模态指标 |
| 部门 | 创建、编辑、移动、排序、启停部门 |
| 用户 | 创建用户、调整角色、重置密码、移动部门、启停用户 |
| 厂商 | 配置 OpenAI、Anthropic、Native 等上游供应商 |
| 模型 | 配置内部模型名、上游模型名、能力类型、路由优先级 |
| 价格 | 配置 Token、音频、图片、视频、请求级计价 |
| API Key | 创建调用密钥、限制模型、IP 白名单、过期时间 |
| 限额策略 | 配置请求数、Token、成本、并发等配额 |
| 用量明细 | 查询模型调用记录、成本、延迟和状态 |
| 多模态任务 | 查询 ASR、TTS、图片、视频等异步任务 |
| 媒体资产 | 查询生成或上传的媒体资产和下载授权 |
| 内容留存 | 配置会话内容保留模式和保留天数 |
| 会话存档 | 查询、查看和导出留存内容 |
| 导出任务 | 创建和下载 CSV 等导出文件 |
| 大屏分享 | 创建独立大屏账号、密码、访问范围和有效期 |
| 脱敏策略 | 配置敏感信息解析、阻断、替换或摘要 |
| 日志中心 | 查询应用、Worker、Caddy、安全等日志 |
| 审计日志 | 查看登录、退出、配置变更、敏感操作 |
| 系统设置 | 管理系统级 JSON 配置 |

## 6. 厂商、模型和价格配置

### 6.1 厂商配置

进入“厂商”页面，配置：

- `name`：厂商名称。
- `protocol`：`openai`、`anthropic` 或 `native`。
- `base_url`：上游 API 基础地址。
- `secret`：上游密钥。保存后加密存储，不在列表和日志中明文展示。
- `timeout_ms`：请求超时毫秒数。
- `retry_count`：重试次数。
- `default_headers`：默认请求头 JSON。

具备 `api_catalog.manage` 权限的管理员可以维护厂商。

### 6.2 模型配置

进入“模型”页面，配置：

- `provider_id`：所属厂商 ID。
- `name`：TokenHub 对外暴露的内部模型名。
- `external_name`：转发到上游时使用的模型名。
- `capability`：能力类型。
- `context_length`：上下文长度。
- `route_priority`：路由优先级，数值用于选择可用路由。
- `status`：`active` 或 `disabled`。

能力类型包括：

```text
text_generation
vision_understanding
embedding
asr
tts
image_generation
video_generation
```

### 6.3 价格配置

进入“价格”页面，为模型配置生效时间和计费项：

- 输入 Token 单价：`input_token_price`
- 输出 Token 单价：`output_token_price`
- 音频秒单价：`audio_second_price`
- TTS 字符单价：`tts_char_price`
- 图片单价：`image_price`
- 视频秒单价：`video_second_price`
- 请求单价：`request_price`
- 币种：`currency`
- 生效时间：`effective_at`

未配置价格时仍会记录用量，但成本可能为空。

## 7. API Key 管理和模型调用

### 7.1 创建 API Key

进入“API Key”页面，创建时填写：

- `user_id`：归属用户。
- `department_id`：归属部门。
- `name`：Key 名称。
- `allowed_ip_cidrs`：允许来源 IP/CIDR JSON 数组，例如 `["192.168.1.0/24"]`。
- `model_ids`：允许调用的模型 ID JSON 数组，例如 `[1,2]`。
- `expires_at`：过期时间，可为空。

创建成功后返回明文 Key，格式前缀为 `tkh_`。明文 Key 只展示一次，后续列表只显示前缀和后缀。

### 7.2 API Key 生效规则

网关调用必须使用：

```http
Authorization: Bearer tkh_xxx
```

鉴权时会检查：

- API Key 状态必须是 `active`。
- Key 不能过期。
- 归属用户必须是 `active`。
- 归属部门必须是 `active`。
- 来源 IP 必须匹配 `allowed_ip_cidrs`，为空表示不限制。
- 请求中的 `model` 必须在 Key 授权模型范围内。

常见错误：

| 错误码 | 含义 |
| --- | --- |
| `API_KEY_INVALID` | 缺少、错误或已不可用的 API Key |
| `API_KEY_EXPIRED` | API Key 已过期 |
| `IP_FORBIDDEN` | 来源 IP 不在白名单内 |
| `MODEL_FORBIDDEN` | 模型未授权或没有可用路由 |
| `QUOTA_EXCEEDED` | 超出限额策略 |

### 7.3 OpenAI 兼容调用示例

```bash
curl http://localhost:8080/v1/chat/completions \
  -H "Authorization: Bearer tkh_xxx" \
  -H "Content-Type: application/json" \
  -d '{
    "model": "gpt-compatible-model",
    "messages": [{"role": "user", "content": "hello"}]
  }'
```

支持的网关路径：

```text
POST /v1/chat/completions
POST /v1/responses
POST /v1/embeddings
POST /v1/messages
POST /v1/audio/transcriptions
POST /v1/audio/speech
POST /v1/images/generations
POST /v1/videos/generations
GET  /v1/tasks/{id}
GET  /v1/assets/{id}/download-url
```

`/v1/messages` 使用 Anthropic 兼容解析，其余默认按 OpenAI 兼容格式解析。

### 7.4 MCP 接入

TokenHub 在 `/mcp` 提供 MCP Server（Streamable HTTP），用同一个 API Key 认证。Claude Code、Cursor、VS Code、Claude Desktop 等客户端接入后可调用知识库检索（`search_knowledge_base`）以及模型、额度、用量查询工具，权限与该 Key 的知识库绑定和模型授权一致。

```sh
claude mcp add --transport http tokenhub https://tokenhub.example.com/mcp \
  --header "Authorization: Bearer tkh_xxx"
```

其他客户端的配置、工具清单和开关（`MCP_ENABLED`、`MCP_ALLOWED_ORIGINS`）见 `docs/mcp.md`。

## 8. 限额和用量

限额策略可绑定到：

- `api_key`
- `user`
- `department`

策略字段包括：

- 周期：`minute`、`hour`、`day`、`month`
- 请求次数限制：`request_limit`
- 输入 Token 限制：`input_token_limit`
- 输出 Token 限制：`output_token_limit`
- 总 Token 限制：`total_token_limit`
- 成本限制：`cost_limit`
- 并发限制：`concurrency_limit`

每次网关调用会在前置检查中校验限额，成功后记录用量。用量明细可在“用量明细”页面按用户、部门、API Key、模型、能力和时间范围筛选。

## 9. 内容留存、会话存档和导出

内容留存策略位于“内容留存策略”模块。支持模式：

| 模式 | 含义 |
| --- | --- |
| `summary` | 只保存摘要 |
| `redacted` | 保存脱敏内容 |
| `raw` | 保存原文 |
| `none` | 不保存正文 |

建议：

- 默认使用 `summary` 或 `redacted`。
- 仅允许 `super_admin` 等可信角色查看 `raw` 原文。
- 为部门、用户、API Key 设置差异化保留天数。

导出入口：

- 用量明细导出：`usage`
- 会话存档导出：`session_archive`
- 日志/审计导出：`logs`

导出任务创建后，在“导出任务”页面查看状态并生成下载链接。

## 10. 大屏和大屏分享

### 10.1 管理员大屏

登录后台后可查看：

- 实时指标
- 趋势
- 排行
- 安全事件
- 性能指标
- 多模态指标

### 10.2 创建大屏分享链接

进入“大屏分享”页面，配置：

- `share_code`：分享码，可留空自动生成。
- `name`：分享名称。
- `username`：大屏登录用户名。
- `password`：初始密码，可留空自动生成。
- `scope_type`：`global`、`department`、`user`、`api_key`。
- `scope_ids`：范围 ID JSON 数组。
- `allowed_metrics`：允许查看的指标 JSON 数组。
- `allow_drilldown`：是否允许下钻。
- `allowed_ip_cidrs`：允许访问的 IP/CIDR JSON 数组。
- `expires_at`：登录截止时间。
- `status`：`active` 或 `disabled`。

创建成功后会返回分享密码和分享地址：

```text
/screen/{share_code}
```

大屏分享登录使用独立 Cookie `screen_session`。禁用分享链接、重置密码或撤销会话会立即使已有大屏会话失效。

## 11. 脱敏和安全管理

### 11.1 脱敏策略

脱敏策略包含：

- `scope_type`：`system`、`department`、`api_key`、`model`、`provider`。
- `scope_id`：范围 ID。
- `parsers`：解析器 JSON。
- `action`：`block`、`replace`、`summary`。

出站请求会先经过脱敏处理，再发送到上游模型供应商。

### 11.2 安全基线

生产环境必须确认：

- `.env` 中 `APP_KEY`、`ADMIN_CSRF_SECRET`、数据库密码、Redis 密码均已替换。
- `SESSION_SECURE=true`。
- Caddy 使用 HTTPS。
- Admin 写操作必须携带 CSRF Token。
- 日志和错误响应不会输出密钥、密码、Token、API Key。

## 12. 日志、审计和观测

默认日志目录由 `LOG_PATH` 控制，本地为 `/srv/app/storage/logs`，生产建议为 `/var/log/tokenhub`。

常见日志：

| 文件或来源 | 说明 |
| --- | --- |
| `app.jsonl` | 应用日志 |
| `worker.jsonl` | Worker 日志 |
| `audit.jsonl` | 审计日志 |
| `security.jsonl` | 安全日志 |
| `caddy-access.jsonl` | Caddy 访问日志 |

审计日志会记录：

- 管理员登录和退出。
- 用户、部门、厂商、模型、价格、API Key 等变更。
- 密码重置。
- 大屏分享创建、禁用、密码重置和会话撤销。

后台“日志中心”和“审计日志”支持按时间、用户、部门、API Key、模型、错误码等条件筛选。

## 13. Worker 和定时任务

本地一次性消费测试：

```bash
docker compose exec -T php php bin/queue-test-job.php
docker compose exec -T php php bin/queue-worker.php --once
```

本地持续 Worker：

```bash
docker compose run --rm queue-worker php bin/queue-worker.php
```

生产 systemd：

```bash
sudo cp /var/www/tokenhub/deploy/ubuntu/tokenhub-worker.service /etc/systemd/system/tokenhub-worker.service
sudo systemctl daemon-reload
sudo systemctl enable --now tokenhub-worker
sudo systemctl status tokenhub-worker --no-pager
```

建议定时任务：

```cron
* * * * * www-data cd /var/www/tokenhub && php scripts/enqueue.php cleanup.retention >/dev/null 2>&1
*/5 * * * * www-data cd /var/www/tokenhub && php scripts/enqueue.php cleanup.exports >/dev/null 2>&1
*/10 * * * * www-data cd /var/www/tokenhub && php scripts/enqueue.php cleanup.media >/dev/null 2>&1
```

Redis 队列检查：

```bash
redis-cli LLEN tokenhub:jobs
redis-cli LLEN tokenhub:failed-jobs
```

## 14. 备份和恢复

备份范围：

- MySQL 数据库。
- `/etc/tokenhub` 或 `.env` 配置。
- Caddy 配置和证书状态。
- 媒体资产目录。
- 导出文件目录。

Dry-run：

```bash
DRY_RUN=1 BACKUP_DIR=/backup/tokenhub bash scripts/backup-tokenhub.sh
```

真实备份：

```bash
DRY_RUN=0 \
CONFIRM_BACKUP=1 \
BACKUP_DIR=/backup/tokenhub \
DB_HOST=127.0.0.1 \
DB_USERNAME=tokenhub \
DB_PASSWORD='db-password' \
DB_DATABASE=tokenhub \
CONFIG_PATH=/etc/tokenhub \
CADDY_DATA_PATH=/var/lib/caddy \
CADDY_CONFIG_PATH=/etc/caddy \
MEDIA_PATH=/var/lib/tokenhub/media \
EXPORT_PATH=/var/lib/tokenhub/exports \
bash scripts/backup-tokenhub.sh
```

恢复演练检查：

```bash
BACKUP_DIR=/backup/tokenhub/latest bash scripts/restore-drill-check.sh
```

导入到演练库：

```bash
BACKUP_DIR=/backup/tokenhub/latest \
CONFIRM_RESTORE=1 \
DB_HOST=127.0.0.1 \
DB_USERNAME=tokenhub \
DB_PASSWORD='db-password' \
DB_DATABASE=tokenhub_restore_drill \
bash scripts/restore-drill-check.sh
```

注意：恢复演练不会默认覆盖生产库，只有设置 `CONFIRM_RESTORE=1` 时才会导入到指定数据库。

## 15. 验收和巡检命令

本地基础验收：

```bash
docker compose exec -T php php bin/run-tests.php
BASE_URL=http://localhost:8080 bash scripts/acceptance-check.sh
BASE_URL=http://localhost:8080 bash scripts/security-smoke.sh
```

带真实管理员和 API Key 验收：

```bash
BASE_URL=http://localhost:8080 \
ADMIN_PASSWORD='admin-password' \
API_KEY='tkh_xxx' \
MODEL='model-id' \
bash scripts/acceptance-check.sh
```

压测和安全检查：

```bash
BASE_URL=http://localhost:8080 \
API_KEY='tkh_xxx' \
MODEL='model-id' \
bash scripts/pressure-smoke.sh

BASE_URL=http://localhost:8080 \
EXPECT_QUERY_BLOCK=1 \
EXPECT_ADMIN_IP_BLOCK=1 \
BLOCKED_IP=203.0.113.10 \
bash scripts/security-smoke.sh
```

## 16. 常见问题处理

### 16.1 无法登录后台

检查：

```bash
docker compose exec -T php php scripts/create-admin.php admin 'new-strong-password'
curl -i http://localhost:8080/admin-api/health
docker compose logs --tail=100 php
```

重点确认：

- 用户状态是 `active`。
- 所属部门状态是 `active`。
- 密码长度符合初始化脚本要求。
- 浏览器是否阻止 Secure Cookie。本地 HTTP 调试时如需要，可在 `.env` 中临时设置 `SESSION_SECURE=false`。

### 16.2 写操作返回 `ADMIN_CSRF_INVALID`

含义：缺少或错误的 `x-csrf-token`。

处理：

- 刷新后台页面重新获取 CSRF Token。
- 自定义脚本调用管理 API 时先登录并保存 Cookie，再调用 `GET /admin-api/csrf` 获取 Token。

### 16.3 API 调用返回 `MODEL_FORBIDDEN`

检查：

- API Key 是否授权了该模型 ID。
- 请求体 `model` 是否等于后台“模型”里的内部模型名 `name`。
- 模型状态是否为 `active`。
- 厂商状态是否为 `active`。
- 是否存在可用上游路由。

### 16.4 API 调用返回 `API_KEY_INVALID`

检查：

- `Authorization: Bearer tkh_xxx` 是否存在。
- Key 是否被禁用、删除或过期。
- 归属用户和部门是否仍为 `active`。
- 重置用户密码或禁用用户后，需要重新创建或启用对应 Key。

### 16.5 Worker 不消费任务

检查：

```bash
docker compose ps redis
docker compose exec -T redis redis-cli ping
docker compose logs --tail=100 queue-worker
docker compose exec -T php php bin/queue-worker.php --once
```

生产检查：

```bash
sudo systemctl status tokenhub-worker --no-pager
sudo journalctl -u tokenhub-worker -n 200 --no-pager
redis-cli LLEN tokenhub:jobs
redis-cli LLEN tokenhub:failed-jobs
```

### 16.6 生产 Cookie 不安全或登录异常

确认：

- `APP_URL` 使用 HTTPS 域名。
- `SESSION_SECURE=true`。
- Caddy 正常终止 TLS。
- 反向代理没有丢失 Cookie 或安全响应头。
