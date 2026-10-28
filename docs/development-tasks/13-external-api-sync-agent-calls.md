# 13 对外 API、用户数据同步与三方智能体调用开发方案

## 1. 背景和当前项目分析

当前 TokenHub 是 PHP 8.2+ 原生应用，核心运行栈为 PHP-FPM、Caddy、MySQL、Redis 和队列 Worker。仓库中已有管理端、模型网关、Provider 适配器、API Key、用量、内容留存、媒体资产、导出和审计能力。

现有能力边界：

| 模块 | 当前实现 | 可复用点 | 缺口 |
| --- | --- | --- | --- |
| 管理员登录 | `POST /admin-api/login`，`AuthService` 写入 `admin_sessions`，Cookie + CSRF | 管理端继续只服务管理员 | 普通用户不应开放页面登录 |
| 普通用户身份 | `users` 表已有 `role=user`，API Key 绑定 `user_id`、`department_id` | API Key 可代表普通用户调用 | 缺少面向 API Key 的用户数据上传/下载接口 |
| 网关认证 | `GatewayAuthMiddleware` 校验 `Authorization: Bearer tkh_xxx` | 统一复用 Bearer API Key | 现有鉴权会读取 JSON `model`，同步接口无 `model` 时需兼容 |
| 三方模型调用 | `GatewayController` 暴露 `/v1/*`，`GatewayService` 路由到 `OpenAIAdapter`、`AnthropicAdapter`、`NativeMultimodalAdapter` | 已有 OpenAI/Anthropic 兼容转发、SSE、用量、配额、审计 | 缺少明确的三方接入配置文档；Gemini 可走 OpenAI compatibility |
| 数据下载 | `GET /v1/assets/{id}/download-url` 只面向媒体资产；管理员导出走 `/admin-api/exports/*` | 下载短链、Redis TTL、授权校验可复用 | 缺少普通用户换机同步所需的增量下载、批量拉取和冲突处理 |
| 内容留存 | `usage_contents` 保存模型调用输入/输出 | 可作为模型会话归档 | 不适合直接承载用户业务数据同步，应新增独立表 |

产品约束：

- 普通用户不能登录当前页面。
- 普通用户或客户端只通过 API 上传、下载和重新同步数据。
- 管理员仍通过后台创建用户、API Key、模型权限、配额和三方 Provider。
- 换机同步以 API Key 代表用户身份，不能要求用户输入后台账号密码。

## 2. 推荐方案

采用“管理端分配身份 + API Key 调用 + 增量同步”的方案。

管理员在后台创建用户和 API Key。客户端持有 API Key 后，可以：

1. 调用 TokenHub 现有 `/v1/chat/completions`、`/v1/responses`、`/v1/messages` 等接口访问三方智能体。
2. 调用新增 `/v1/sync/*` 接口上传本地用户数据。
3. 换机后使用同一个 API Key 调用 `/v1/sync/records` 拉取全量或增量数据。
4. 使用 `cursor`、`updated_after` 和 `sync_version` 做断点续传、增量同步和冲突解决。

不推荐新增普通用户网页登录入口。这样可以保持当前后台安全边界不变，避免把 `admin_sessions`、CSRF、后台权限模型暴露给普通用户。

## 3. 数据模型设计

新增迁移文件建议命名：

```text
database/migrations/2026_07_08_000013_external_sync_api.sql
```

### 3.1 `user_sync_devices`

记录客户端设备，便于换机、审计和问题定位。设备不是登录态，只是 API 调用附带的客户端标识。

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `id` | BIGINT UNSIGNED PK | 内部 ID |
| `device_id` | VARCHAR(80) UNIQUE | 服务端生成或客户端上报的设备 ID |
| `user_id` | BIGINT UNSIGNED | API Key 归属用户 |
| `department_id` | BIGINT UNSIGNED | API Key 归属部门 |
| `api_key_id` | BIGINT UNSIGNED NULL | 首次登记设备使用的 API Key |
| `device_name` | VARCHAR(160) NULL | 客户端显示名称 |
| `platform` | VARCHAR(80) NULL | `windows`、`macos`、`linux`、`ios`、`android`、`web` |
| `client_version` | VARCHAR(80) NULL | 客户端版本 |
| `last_seen_at` | TIMESTAMP NULL | 最近同步时间 |
| `status` | ENUM | `active`、`disabled` |
| `created_at` / `updated_at` | TIMESTAMP | 时间戳 |

索引：

- `idx_sync_devices_user (user_id, status)`
- `idx_sync_devices_api_key (api_key_id)`

### 3.2 `user_sync_records`

保存普通用户通过 API 上传的业务数据。服务端不理解业务结构，只按命名空间、记录 ID 和 JSON 内容保存。

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `id` | BIGINT UNSIGNED PK | 内部 ID |
| `record_id` | VARCHAR(80) UNIQUE | 对外记录 ID，建议 `rec_` 前缀 |
| `user_id` | BIGINT UNSIGNED | 归属用户 |
| `department_id` | BIGINT UNSIGNED | 归属部门 |
| `api_key_id` | BIGINT UNSIGNED NULL | 最后写入 API Key |
| `device_id` | VARCHAR(80) NULL | 最后写入设备 |
| `namespace` | VARCHAR(120) | 数据命名空间，如 `profile`、`settings`、`workspace`、`chat_sessions` |
| `client_record_id` | VARCHAR(190) | 客户端侧稳定 ID |
| `payload_json` | JSON | 业务数据 |
| `payload_hash` | CHAR(64) | payload SHA-256，用于去重和冲突判断 |
| `sync_version` | BIGINT UNSIGNED | 服务端递增版本 |
| `client_updated_at` | TIMESTAMP NULL | 客户端最后修改时间 |
| `deleted_at` | TIMESTAMP NULL | 软删除墓碑 |
| `created_at` / `updated_at` | TIMESTAMP | 时间戳 |

唯一约束：

- `uq_sync_user_namespace_client_record (user_id, namespace, client_record_id)`

索引：

- `idx_sync_user_version (user_id, sync_version)`
- `idx_sync_user_updated (user_id, updated_at)`
- `idx_sync_namespace (user_id, namespace, updated_at)`

### 3.3 `user_sync_events`

记录上传、下载、删除、冲突、批量同步等审计事件。

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `id` | BIGINT UNSIGNED PK | 内部 ID |
| `request_id` | VARCHAR(80) | 请求 ID |
| `user_id` | BIGINT UNSIGNED | 用户 |
| `department_id` | BIGINT UNSIGNED | 部门 |
| `api_key_id` | BIGINT UNSIGNED NULL | API Key |
| `device_id` | VARCHAR(80) NULL | 设备 |
| `action` | VARCHAR(80) | `record.upsert`、`record.delete`、`records.pull` 等 |
| `namespace` | VARCHAR(120) NULL | 命名空间 |
| `record_count` | INT UNSIGNED | 记录数量 |
| `status` | ENUM | `success`、`failed`、`conflict` |
| `summary` | JSON NULL | 摘要，禁止保存敏感明文 |
| `created_at` | TIMESTAMP | 时间戳 |

## 4. 对外 API 设计

所有新增接口使用：

```http
Authorization: Bearer tkh_xxx
Content-Type: application/json
```

鉴权规则复用 API Key：

- API Key 必须 `active`。
- API Key 未过期。
- 归属用户必须 `active`。
- 归属部门必须 `active`。
- 来源 IP 必须匹配 `allowed_ip_cidrs`。
- 同步接口不检查模型权限；模型权限只用于模型网关接口。

建议新增 `ApiAuthMiddleware` 或调整 `GatewayAuthMiddleware`，把“API Key 鉴权”和“模型权限鉴权”拆开。`/v1/sync/*` 只使用 API Key 鉴权，`/v1/chat/completions` 等模型接口继续检查 `model` 权限。

### 4.1 设备登记

```http
POST /v1/sync/devices
```

请求：

```json
{
  "device_id": "optional-client-device-id",
  "device_name": "Leo Windows Desktop",
  "platform": "windows",
  "client_version": "1.2.0"
}
```

响应：

```json
{
  "ok": true,
  "data": {
    "device_id": "dev_xxx",
    "user_id": 12,
    "department_id": 3,
    "status": "active",
    "last_seen_at": "2026-07-08T13:00:00Z"
  },
  "request_id": "req_xxx"
}
```

用途：

- 新设备首次启动后登记设备信息。
- 老设备可重复调用，服务端按 `device_id + user_id` 更新 `last_seen_at`。

### 4.2 单条数据上传或更新

```http
PUT /v1/sync/records/{namespace}/{client_record_id}
```

请求：

```json
{
  "device_id": "dev_xxx",
  "payload": {
    "title": "default workspace",
    "settings": {
      "theme": "dark",
      "language": "zh-CN"
    }
  },
  "client_updated_at": "2026-07-08T12:58:00Z",
  "base_sync_version": 128
}
```

响应：

```json
{
  "ok": true,
  "data": {
    "record_id": "rec_xxx",
    "namespace": "settings",
    "client_record_id": "default",
    "sync_version": 129,
    "updated_at": "2026-07-08T13:00:00Z",
    "conflict": false
  },
  "request_id": "req_xxx"
}
```

冲突规则：

- 如果 `base_sync_version` 小于服务端当前版本，且 `payload_hash` 不同，返回 `409 SYNC_CONFLICT`。
- 响应中返回服务端当前记录，客户端决定覆盖、合并或放弃。
- 如果 payload 相同，只更新设备和时间，返回成功。

冲突响应：

```json
{
  "ok": false,
  "error": {
    "code": "SYNC_CONFLICT",
    "message": "Record has been modified on server"
  },
  "data": {
    "server_record": {
      "record_id": "rec_xxx",
      "namespace": "settings",
      "client_record_id": "default",
      "payload": {
        "theme": "light"
      },
      "sync_version": 130,
      "updated_at": "2026-07-08T13:01:00Z"
    }
  },
  "request_id": "req_xxx"
}
```

### 4.3 批量上传

```http
POST /v1/sync/records:batch
```

请求：

```json
{
  "device_id": "dev_xxx",
  "records": [
    {
      "namespace": "settings",
      "client_record_id": "default",
      "payload": {
        "theme": "dark"
      },
      "client_updated_at": "2026-07-08T12:58:00Z",
      "base_sync_version": 128
    },
    {
      "namespace": "workspace",
      "client_record_id": "main",
      "payload": {
        "name": "Main"
      },
      "client_updated_at": "2026-07-08T12:59:00Z",
      "base_sync_version": 88
    }
  ]
}
```

响应：

```json
{
  "ok": true,
  "data": {
    "accepted": 2,
    "conflicted": 0,
    "records": [
      {
        "namespace": "settings",
        "client_record_id": "default",
        "record_id": "rec_1",
        "sync_version": 129,
        "status": "updated"
      },
      {
        "namespace": "workspace",
        "client_record_id": "main",
        "record_id": "rec_2",
        "sync_version": 89,
        "status": "updated"
      }
    ],
    "next_cursor": "ver_129"
  },
  "request_id": "req_xxx"
}
```

限制：

- 单批最多 200 条。
- 单条 payload 默认不超过 256 KB。
- 单次请求默认不超过 10 MB。
- 超限返回 `SYNC_PAYLOAD_TOO_LARGE`。

### 4.4 增量下载

```http
GET /v1/sync/records?cursor=ver_128&limit=100
```

可选查询参数：

| 参数 | 说明 |
| --- | --- |
| `cursor` | 上次响应返回的游标，如 `ver_128` |
| `updated_after` | ISO 8601 时间，适合首次按时间拉取 |
| `namespace` | 只拉取某个命名空间 |
| `include_deleted` | 是否返回删除墓碑，默认 `true` |
| `limit` | 默认 100，最大 500 |

响应：

```json
{
  "ok": true,
  "data": {
    "records": [
      {
        "record_id": "rec_xxx",
        "namespace": "settings",
        "client_record_id": "default",
        "payload": {
          "theme": "dark"
        },
        "sync_version": 129,
        "client_updated_at": "2026-07-08T12:58:00Z",
        "updated_at": "2026-07-08T13:00:00Z",
        "deleted_at": null
      }
    ],
    "next_cursor": "ver_129",
    "has_more": false
  },
  "request_id": "req_xxx"
}
```

换机流程：

1. 新设备登记 `POST /v1/sync/devices`。
2. 首次拉取 `GET /v1/sync/records?limit=500`，不传 `cursor`。
3. 如果 `has_more=true`，继续用 `next_cursor` 拉取。
4. 本地保存最后成功游标。
5. 后续定时或启动时增量拉取。

### 4.5 删除记录

```http
DELETE /v1/sync/records/{namespace}/{client_record_id}
```

请求：

```json
{
  "device_id": "dev_xxx",
  "base_sync_version": 129
}
```

响应：

```json
{
  "ok": true,
  "data": {
    "namespace": "settings",
    "client_record_id": "default",
    "sync_version": 131,
    "deleted": true,
    "deleted_at": "2026-07-08T13:10:00Z"
  },
  "request_id": "req_xxx"
}
```

删除使用软删除墓碑。服务端保留墓碑 30 到 90 天，保证离线设备能同步删除状态。

### 4.6 同步状态

```http
GET /v1/sync/manifest
```

响应：

```json
{
  "ok": true,
  "data": {
    "user_id": 12,
    "department_id": 3,
    "latest_sync_version": 131,
    "namespaces": [
      {
        "namespace": "settings",
        "record_count": 1,
        "latest_sync_version": 131,
        "updated_at": "2026-07-08T13:10:00Z"
      }
    ]
  },
  "request_id": "req_xxx"
}
```

用途：

- 客户端快速判断是否需要同步。
- 换机时先看数据规模，再分页拉取。

### 4.7 用户文件/资产上传

如果需要同步二进制文件，建议新增独立资产接口，不把大文件塞进 `user_sync_records.payload_json`。

小文件可先支持 base64 JSON：

```http
POST /v1/sync/assets
```

请求：

```json
{
  "device_id": "dev_xxx",
  "namespace": "workspace-files",
  "client_asset_id": "local-file-1",
  "file_name": "config.json",
  "mime_type": "application/json",
  "b64_data": "eyJ0aGVtZSI6ImRhcmsifQ=="
}
```

响应：

```json
{
  "ok": true,
  "data": {
    "asset_id": "asset_xxx",
    "media_type": "file",
    "mime_type": "application/json",
    "size_bytes": 16,
    "download_url_endpoint": "/v1/assets/asset_xxx/download-url"
  },
  "request_id": "req_xxx"
}
```

下载仍复用现有：

```http
GET /v1/assets/{id}/download-url
```

后续大文件再扩展分片上传：

```text
POST /v1/sync/uploads
PUT  /v1/sync/uploads/{upload_id}/parts/{part_number}
POST /v1/sync/uploads/{upload_id}:complete
```

## 5. 三方智能体调用文档

TokenHub 对外暴露统一 API，调用方只使用 TokenHub API Key，不直接接触上游 OpenAI、Anthropic、Gemini 等密钥。

### 5.1 TokenHub 对外模型调用

基础地址：

```text
https://your-tokenhub.example.com
```

认证：

```http
Authorization: Bearer tkh_xxx
```

支持路径：

| 能力 | TokenHub 路径 | 请求格式 | 当前解析器 |
| --- | --- | --- | --- |
| Chat Completions | `POST /v1/chat/completions` | OpenAI 兼容 | `OpenAIRequestParser` |
| Responses | `POST /v1/responses` | OpenAI Responses 兼容 | `OpenAIRequestParser` |
| Embeddings | `POST /v1/embeddings` | OpenAI 兼容 | `OpenAIRequestParser` |
| Anthropic Messages | `POST /v1/messages` | Anthropic 兼容 | `AnthropicRequestParser` |
| ASR | `POST /v1/audio/transcriptions` | OpenAI 兼容或 Native | `OpenAIRequestParser` |
| TTS | `POST /v1/audio/speech` | OpenAI 兼容或 Native | `OpenAIRequestParser` |
| Image | `POST /v1/images/generations` | OpenAI 兼容或 Native | `OpenAIRequestParser` |
| Video | `POST /v1/videos/generations` | OpenAI 兼容或 Native | `OpenAIRequestParser` |
| Async task | `GET /v1/tasks/{id}` | TokenHub 任务查询 | `GatewayService::task` |
| Asset download URL | `GET /v1/assets/{id}/download-url` | TokenHub 资产授权 | `GatewayService::assetDownloadUrl` |

OpenAI Chat 示例：

```bash
curl https://your-tokenhub.example.com/v1/chat/completions \
  -H "Authorization: Bearer tkh_xxx" \
  -H "Content-Type: application/json" \
  -d '{
    "model": "gpt-compatible-model",
    "messages": [
      {"role": "user", "content": "hello"}
    ],
    "stream": false
  }'
```

OpenAI Responses 示例：

```bash
curl https://your-tokenhub.example.com/v1/responses \
  -H "Authorization: Bearer tkh_xxx" \
  -H "Content-Type: application/json" \
  -d '{
    "model": "gpt-compatible-model",
    "input": "用三句话总结 TokenHub 的作用"
  }'
```

Anthropic Messages 示例：

```bash
curl https://your-tokenhub.example.com/v1/messages \
  -H "Authorization: Bearer tkh_xxx" \
  -H "Content-Type: application/json" \
  -d '{
    "model": "claude-compatible-model",
    "max_tokens": 1024,
    "messages": [
      {"role": "user", "content": "hello"}
    ]
  }'
```

流式调用：

```bash
curl -N https://your-tokenhub.example.com/v1/chat/completions \
  -H "Authorization: Bearer tkh_xxx" \
  -H "Content-Type: application/json" \
  -d '{
    "model": "gpt-compatible-model",
    "messages": [
      {"role": "user", "content": "stream hello"}
    ],
    "stream": true
  }'
```

### 5.2 管理端配置 OpenAI 上游

后台创建 Provider：

```json
{
  "name": "OpenAI",
  "protocol": "openai",
  "base_url": "https://api.openai.com",
  "secret": "sk-xxx",
  "timeout_ms": 60000,
  "retry_count": 1,
  "default_headers": {}
}
```

后台创建 Model：

```json
{
  "provider_id": 1,
  "name": "gpt-compatible-model",
  "external_name": "gpt-5.5",
  "capability": "text_generation",
  "context_length": 400000,
  "route_priority": 100,
  "status": "active"
}
```

说明：

- TokenHub 对外使用 `models.name`。
- 转发给上游时使用 `models.external_name`。
- `ProviderHttpClient` 会为 OpenAI 协议自动设置 `Authorization: Bearer <secret>`。
- OpenAI 官方 Responses API 是创建模型响应的接口，支持文本、图像输入、JSON 输出和工具调用；官方 API 参考将创建响应列为 `POST /responses`。

官方参考：

- OpenAI Responses API: https://developers.openai.com/api/reference/resources/responses/methods/create

### 5.3 管理端配置 Anthropic 上游

后台创建 Provider：

```json
{
  "name": "Anthropic",
  "protocol": "anthropic",
  "base_url": "https://api.anthropic.com",
  "secret": "sk-ant-xxx",
  "timeout_ms": 60000,
  "retry_count": 1,
  "default_headers": {}
}
```

后台创建 Model：

```json
{
  "provider_id": 2,
  "name": "claude-compatible-model",
  "external_name": "claude-sonnet-4-5",
  "capability": "text_generation",
  "context_length": 200000,
  "route_priority": 100,
  "status": "active"
}
```

说明：

- TokenHub 的 `AnthropicAdapter` 会附带 `anthropic-version: 2023-06-01`。
- `ProviderHttpClient` 对 Anthropic 协议使用 `x-api-key: <secret>`。
- `/v1/messages` 按 Anthropic Messages 格式解析。

官方参考：

- Anthropic Messages API: https://docs.anthropic.com/en/api/messages

### 5.4 管理端配置 Gemini 上游

推荐先使用 Gemini 官方 OpenAI compatibility，不新增 Gemini 专用适配器。

后台创建 Provider：

```json
{
  "name": "Gemini OpenAI Compatible",
  "protocol": "openai",
  "base_url": "https://generativelanguage.googleapis.com/v1beta/openai",
  "secret": "GEMINI_API_KEY",
  "timeout_ms": 60000,
  "retry_count": 1,
  "default_headers": {}
}
```

后台创建 Model：

```json
{
  "provider_id": 3,
  "name": "gemini-compatible-model",
  "external_name": "gemini-3.5-flash",
  "capability": "text_generation",
  "context_length": 1000000,
  "route_priority": 100,
  "status": "active"
}
```

调用方仍按 OpenAI Chat Completions 调 TokenHub：

```bash
curl https://your-tokenhub.example.com/v1/chat/completions \
  -H "Authorization: Bearer tkh_xxx" \
  -H "Content-Type: application/json" \
  -d '{
    "model": "gemini-compatible-model",
    "messages": [
      {"role": "user", "content": "hello from gemini"}
    ]
  }'
```

Google 官方文档说明 Gemini 模型可通过 OpenAI libraries 和 REST API 访问，只需设置 Gemini API key、OpenAI compatibility 的 `base_url`，并选择兼容模型。

官方参考：

- Gemini OpenAI compatibility: https://ai.google.dev/gemini-api/docs/openai

### 5.5 Configure Kimi API / Moonshot and Kimi Code

Kimi API / Moonshot and Kimi Code are separate upstream products. Normal Kimi API traffic uses Moonshot's OpenAI-compatible API with Kimi model ids such as `kimi-k2.7-code-highspeed` or `kimi-k2.6`. Kimi Code traffic uses the Kimi Code API URL and the fixed model id `kimi-for-coding`.

When a route points to Kimi Code, TokenHub sends the upstream request to `/v1/chat/completions`, then returns a Claude Code-compatible Anthropic Messages response to the downstream application. TokenHub also strips client IP forwarding headers such as `X-Forwarded-For`, `Forwarded`, `CF-Connecting-IP`, `True-Client-IP`, and `X-Real-IP` before contacting the upstream, so the upstream only sees the TokenHub server egress IP.

Normal Kimi API / Moonshot Provider:

```json
{
  "name": "Kimi API / Moonshot",
  "protocol": "openai",
  "base_url": "https://api.moonshot.cn/v1",
  "secret": "MOONSHOT_API_KEY",
  "timeout_ms": 120000,
  "retry_count": 1,
  "default_headers": {}
}
```

Normal Kimi API Model for coding agents:

```json
{
  "provider_id": 4,
  "name": "kimi-api-code",
  "external_name": "kimi-k2.7-code-highspeed",
  "capability": "text_generation",
  "context_length": 256000,
  "route_priority": 100,
  "status": "active"
}
```

Kimi Code Provider:

```json
{
  "name": "Kimi Code",
  "protocol": "openai",
  "base_url": "https://api.kimi.com/coding/v1",
  "secret": "MOONSHOT_API_KEY",
  "timeout_ms": 120000,
  "retry_count": 1,
  "default_headers": {}
}
```

Kimi Code Model:

```json
{
  "provider_id": 5,
  "name": "kimi-code",
  "external_name": "kimi-for-coding",
  "capability": "text_generation",
  "context_length": 256000,
  "route_priority": 100,
  "status": "active"
}
```

TokenHub call shape:

```bash
curl https://your-tokenhub.example.com/v1/chat/completions \
  -H "Authorization: Bearer tkh_xxx" \
  -H "Content-Type: application/json" \
  -d '{
    "model": "kimi-code",
    "messages": [
      {"role": "system", "content": "You are a coding assistant."},
      {"role": "user", "content": "Explain this repository structure."}
    ],
    "stream": true
  }'
```

Notes:

- Use `https://api.moonshot.cn/v1` for the Kimi API China platform and `https://api.moonshot.ai/v1` for the Kimi API global platform when configuring the upstream provider.
- Use `https://api.kimi.com/coding/v1` for Kimi Code OpenAI-compatible traffic, or `https://api.kimi.com/coding/` when a tool requires the Anthropic-compatible Kimi Code base URL.
- Use `kimi-k2.7-code-highspeed` first for normal Kimi API programming-agent traffic. `kimi-k2.7-code` is the non-highspeed coding option. Use `kimi-for-coding` for Kimi Code entitlement traffic.
- Use `kimi-k2.6` for general multimodal text/image/video input. TokenHub maps it to the current `vision_understanding` capability until a dedicated video-understanding capability is implemented.
- Downstream clients receive Anthropic/Claude Code-compatible message responses for Kimi Code routes even if they entered through the OpenAI-compatible TokenHub endpoint.

Official references:

- Kimi China quickstart: https://platform.kimi.com/docs/guide/start-using-kimi-api
- Kimi global quickstart: https://platform.kimi.ai/docs/overview

### 5.5 多智能体/工具调用约定

TokenHub 本身不编排智能体工作流，建议先作为统一模型网关和数据同步后端。外部 Agent Runtime 可按以下约定调用：

| Agent 场景 | 调用 TokenHub | 数据同步 |
| --- | --- | --- |
| 单 Agent 问答 | `/v1/chat/completions` 或 `/v1/responses` | 把用户设置、会话摘要写入 `/v1/sync/records` |
| 多 Agent 协作 | 每个 Agent 使用不同 `model` 或不同 API Key | 用 `namespace=agent-state` 保存任务状态 |
| 工具执行结果 | Responses 或 Chat 工具调用结果由客户端处理 | 用 `namespace=tool-results` 上传结构化结果 |
| 换机恢复 | 新设备拉取 `/v1/sync/records` | 恢复 `settings`、`agent-state`、`workspace` 等命名空间 |

推荐命名空间：

```text
profile
settings
workspace
agent-state
agent-memory
chat-sessions
tool-results
files
```

## 6. 错误码

新增同步错误码：

| HTTP | code | 说明 |
| --- | --- | --- |
| 400 | `SYNC_INVALID_PAYLOAD` | JSON 结构错误或必填字段缺失 |
| 401 | `API_KEY_INVALID` | 缺少或无效 API Key |
| 401 | `API_KEY_EXPIRED` | API Key 已过期 |
| 403 | `IP_FORBIDDEN` | 来源 IP 不允许 |
| 403 | `SYNC_DEVICE_DISABLED` | 设备被禁用 |
| 404 | `SYNC_RECORD_NOT_FOUND` | 记录不存在或不属于当前 API Key 用户 |
| 409 | `SYNC_CONFLICT` | 服务端记录已被其他设备更新 |
| 413 | `SYNC_PAYLOAD_TOO_LARGE` | 单条或批量请求超限 |
| 429 | `QUOTA_EXCEEDED` | 同步接口配额或频率超限 |
| 500 | `SYNC_WRITE_FAILED` | 服务端写入失败 |

响应格式沿用 `Response::ok()` 和 `Response::error()`：

```json
{
  "ok": false,
  "error": {
    "code": "SYNC_CONFLICT",
    "message": "Record has been modified on server"
  },
  "request_id": "req_xxx"
}
```

## 7. 安全和权限

必须满足：

- 普通用户无页面登录入口。
- API Key 明文只展示一次。
- 同步数据按 `user_id` 隔离，不能跨用户读取。
- `department_id` 用于管理端审计和统计，不作为普通用户跨部门读取依据。
- 上传 payload 默认保存 JSON；如包含敏感信息，建议后续支持按命名空间加密或复用 `Crypto::encrypt`。
- 日志和审计只写摘要、数量、哈希、命名空间，不写完整 payload。
- 下载链接继续使用短期 Redis token，默认 300 秒。
- 删除使用软删除墓碑，避免离线设备漏同步删除。

## 8. 后台管理扩展

第一阶段不新增普通用户前台页面，只在管理端补充必要管理能力：

| 管理模块 | 能力 |
| --- | --- |
| API Key | 展示该 Key 最近同步时间、设备数、同步记录数 |
| 用户 | 查看用户同步设备、禁用设备、查看同步统计 |
| 日志/审计 | 查询 `sync.device.register`、`sync.record.upsert`、`sync.records.pull` 等事件 |
| 系统设置 | 同步 payload 大小、批量条数、墓碑保留天数、下载 TTL |

## 9. 实施任务拆分

### 任务 1：拆分 API Key 鉴权中间件

文件：

- 新增 `app/Middleware/ApiKeyAuthMiddleware.php`
- 修改 `app/Middleware/GatewayAuthMiddleware.php`
- 修改 `app/Bootstrap/Kernel.php`
- 新增测试 `tests/Support/ApiKeyAuthMiddlewareTest.php`

目标：

- `ApiKeyAuthMiddleware` 只校验 Bearer API Key、IP、用户和部门状态。
- `GatewayAuthMiddleware` 在此基础上继续校验 `model` 权限。
- `/v1/sync/*` 使用 `ApiKeyAuthMiddleware`。

### 任务 2：新增同步数据迁移

文件：

- 新增 `database/migrations/2026_07_08_000013_external_sync_api.sql`
- 新增/更新 `tests/Console/MigrationFilesTest.php`

目标：

- 创建 `user_sync_devices`、`user_sync_records`、`user_sync_events`。
- 加齐唯一约束和增量同步索引。

### 任务 3：新增同步服务

文件：

- 新增 `app/Services/SyncDeviceService.php`
- 新增 `app/Services/UserSyncService.php`
- 新增测试 `tests/Services/UserSyncServiceTest.php`

目标：

- 设备登记。
- 单条 upsert。
- 批量 upsert。
- 增量 list。
- 软删除。
- 冲突检测。
- 事件记录。

### 任务 4：新增同步控制器和路由

文件：

- 新增 `app/Http/Controllers/SyncController.php`
- 修改 `app/Bootstrap/Kernel.php`
- 新增测试 `tests/Support/RouterTest.php` 或独立 `tests/Api/SyncRoutesTest.php`

路由：

```text
POST   /v1/sync/devices
GET    /v1/sync/manifest
GET    /v1/sync/records
POST   /v1/sync/records:batch
PUT    /v1/sync/records/{namespace}/{client_record_id}
DELETE /v1/sync/records/{namespace}/{client_record_id}
POST   /v1/sync/assets
```

### 任务 5：扩展资产上传

文件：

- 修改 `app/Services/MediaAssetService.php`
- 修改/新增 `app/Http/Controllers/SyncController.php`
- 新增测试 `tests/Services/MediaAssetServiceTest.php`

目标：

- 支持 API Key 用户上传小型 base64 文件。
- 写入 `media_assets.owner_user_id`、`department_id`、`api_key_id`。
- 下载授权仍复用 `/v1/assets/{id}/download-url`。

### 任务 6：管理端统计和审计

文件：

- 修改 `app/Http/Controllers/AdminController.php`
- 修改 `app/Services/CrudService.php` 或新增只读查询服务
- 修改 `public/admin/assets/js/resources.js`

目标：

- 管理员可查看同步设备、记录和事件。
- 管理员可禁用设备。
- 不允许后台直接编辑用户 payload 原文，避免误操作和泄露。

### 任务 7：文档和验收脚本

文件：

- 新增 `docs/external-api-sync.md`
- 更新 `docs/tokenhub-usage-management-guide.md`
- 新增 `scripts/sync-smoke.sh`
- 新增测试 `tests/Services/UserSyncServiceTest.php`

验收：

```bash
docker compose exec -T php php bin/migrate.php
docker compose exec -T php php bin/run-tests.php
BASE_URL=http://localhost:8080 API_KEY='tkh_xxx' bash scripts/sync-smoke.sh
```

## 10. 验收标准

功能验收：

- 管理员可以创建普通用户和 API Key。
- 普通用户不能登录当前页面。
- 客户端使用 API Key 可以登记设备。
- 客户端可以上传、批量上传、删除、拉取同步数据。
- 新设备使用同一 API Key 可以完整拉取该用户数据。
- 增量同步不会重复返回旧数据。
- 冲突写入返回 `409 SYNC_CONFLICT`。
- 禁用用户、部门或 API Key 后，同步接口立即不可用。
- 三方智能体调用仍可通过 `/v1/*` 统一转发，且不暴露上游密钥。

安全验收：

- 任何同步接口不能跨用户读取数据。
- 日志和审计不记录完整 payload、Authorization、API Key、上游密钥。
- 下载链接过期后不可访问。
- 软删除墓碑在保留期内可被新设备同步。

性能验收：

- 单用户 10 万条同步记录时，按 `sync_version` 增量拉取仍使用索引。
- 批量上传 200 条记录在本地 Docker 环境可稳定完成。
- 大 payload 被明确拒绝，不拖垮 PHP-FPM。

## 11. 推荐开发顺序

1. 先拆 API Key 鉴权，保证同步接口不依赖 `model`。
2. 再建同步表和服务层，完成纯服务测试。
3. 接入控制器和路由，完成 API 级 smoke。
4. 扩展资产上传。
5. 最后补管理端只读视图和文档。

这个顺序可以保证每一步都有可验证产物，也不会破坏当前管理端登录和模型网关。
