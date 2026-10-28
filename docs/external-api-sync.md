# TokenHub 外部软件 API 调用文档

> 当前文件已补充完整中文接入说明，保留历史内容用于追溯。新接入优先阅读本文中的“TokenHub 外部软件 API 与同步接口中文接入文档”章节。

本文面向外部软件、客户端、Agent Runtime 和自动化脚本。普通用户不登录 TokenHub 页面，外部软件只通过 API Key 调用模型网关和用户数据同步接口。

## 1. 基础信息

默认地址：

```text
https://your-tokenhub.example.com
```

## 15. External API gap-fill endpoint summary

- `GET /v1/models/{model}` returns one authorized model with health metadata.
- `GET /v1/model-health` returns API-key scoped route health for fallback decisions.
- `POST /v1/tasks/{id}:refresh` refreshes supported async provider tasks.
- `GET /v1/assets` lists current API Key owned generated assets and uploaded files.
- `GET /v1/webhooks/{id}/deliveries` lists webhook delivery attempts.
- `POST /v1/webhooks/{id}:test` creates a `webhook.test` diagnostic delivery.
- `POST /v1/webhook-deliveries/{delivery_id}:retry` retries a failed or dead delivery.
- `GET /v1/rag/knowledge-bases` lists authorized RAG knowledge bases.
- `GET /v1/rag/knowledge-bases/{kb_id}/datasets` lists authorized datasets for a knowledge base.
- `POST /v1/rag/search` searches the key's knowledge bases directly (no model call); see `external-api-complete.md` §11.
- `POST /v1/completions`, `POST /v1/rerank` and `POST /v1/moderations` proxy legacy completions, rerank models and moderation models.
- `/v1/management/*` (agent management: department, users, API keys incl. PATCH and `:rotate`, quota, usage, RAG) is documented in `agent-management-api.md` and in `public/openapi.json` under the `Agent Management` tag.

本地开发地址：

```text
http://localhost:8080
```

认证方式：

```http
Authorization: Bearer tkh_xxx
Content-Type: application/json
```

通用成功响应：

```json
{
  "ok": true,
  "data": {},
  "request_id": "req_xxx"
}
```

## 14. External API gap-fill endpoints

These endpoints are API-key-only and scoped to the current key.

### GET /v1/models/{model}

Return one authorized model, including provider, protocol, multimodal IO, and route health.

### GET /v1/model-health

Return API-key scoped model health summary and per-model route status for client fallback decisions.

### POST /v1/tasks/{id}:refresh

Refresh a long-running multimodal task from the upstream provider when the task has a provider task id and a supported provider adapter. Terminal local tasks return the current task with `refreshed=false`.

### GET /v1/assets

List current API Key owned generated assets and uploaded files. Supports `type=file|image|video|audio`, `limit`, and `cursor=id_<last_id>`.

### GET /v1/webhooks/{id}/deliveries

List recent delivery attempts for one webhook. The response includes delivery id, event type, status, attempt count, response status, error summary, and timestamps. It does not return the signing secret or raw payload.

### POST /v1/webhooks/{id}:test

Create a `webhook.test` diagnostic delivery and enqueue it for normal delivery processing. Webhook target secrets are encrypted at rest; rotation keeps the prior signature valid for 24 hours through `X-TokenHub-Signature-Previous`.

### POST /v1/webhook-deliveries/{delivery_id}:retry

Reset a failed or dead delivery to `pending` and enqueue it again. Succeeded deliveries cannot be retried.

### GET /v1/rag/knowledge-bases

List RAG knowledge bases authorized for the current API Key. The response includes `kb_id`, name, status, permission, and default flag.

### GET /v1/rag/knowledge-bases/{kb_id}/datasets

List datasets under one authorized knowledge base. The response includes `dataset_id`, type, status, permission, chunk count, vector count, and indexing timestamp.

通用错误响应：

```json
{
  "ok": false,
  "error": {
    "code": "SYNC_INVALID_PAYLOAD",
    "message": "payload must be an object"
  },
  "request_id": "req_xxx"
}
```

## 2. 外部软件接入流程

1. 管理员在后台创建普通用户。
2. 管理员为该用户创建 API Key。
3. 外部软件保存 API Key。
4. 外部软件首次启动时调用 `POST /v1/sync/devices` 登记设备。
5. 外部软件调用 `/v1/sync/*` 上传、下载、换机同步用户数据。
6. 外部软件调用 `/v1/chat/completions`、`/v1/responses` 或 `/v1/messages` 访问三方智能体。

API Key 状态、归属用户、归属部门、过期时间、来源 IP 白名单都会影响调用结果。

## 3. 用户数据同步 API

### 3.1 登记设备

```http
POST /v1/sync/devices
```

请求：

```json
{
  "device_id": "client-device-001",
  "device_name": "Windows Desktop",
  "platform": "windows",
  "client_version": "1.0.0"
}
```

响应：

```json
{
  "ok": true,
  "data": {
    "device_id": "client-device-001",
    "user_id": 12,
    "department_id": 1,
    "status": "active",
    "last_seen_at": "2026-07-08 13:00:00"
  },
  "request_id": "req_xxx"
}
```

### 3.2 上传或更新单条记录

```http
PUT /v1/sync/records/{namespace}/{client_record_id}
```

示例：

```bash
curl -X PUT http://localhost:8080/v1/sync/records/settings/default \
  -H "Authorization: Bearer tkh_xxx" \
  -H "Content-Type: application/json" \
  -d '{
    "device_id": "client-device-001",
    "payload": {
      "theme": "dark",
      "language": "zh-CN"
    },
    "client_updated_at": "2026-07-08T13:00:00Z",
    "base_sync_version": 0
  }'
```

响应：

```json
{
  "ok": true,
  "data": {
    "record_id": "rec_xxx",
    "namespace": "settings",
    "client_record_id": "default",
    "sync_version": 1,
    "updated_at": "2026-07-08 13:00:00",
    "conflict": false
  },
  "request_id": "req_xxx"
}
```

字段说明：

| 字段 | 说明 |
| --- | --- |
| `namespace` | 数据命名空间，如 `settings`、`profile`、`agent-state` |
| `client_record_id` | 外部软件自己的稳定记录 ID |
| `payload` | 外部软件业务 JSON |
| `base_sync_version` | 客户端最后看到的服务端版本，用于冲突检测 |
| `client_updated_at` | 客户端更新时间 |

### 3.3 批量上传

```http
POST /v1/sync/records:batch
```

请求：

```json
{
  "device_id": "client-device-001",
  "records": [
    {
      "namespace": "settings",
      "client_record_id": "default",
      "payload": {
        "theme": "dark"
      },
      "base_sync_version": 1
    },
    {
      "namespace": "agent-state",
      "client_record_id": "task-001",
      "payload": {
        "status": "running"
      },
      "base_sync_version": 0
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
        "record_id": "rec_xxx",
        "namespace": "settings",
        "client_record_id": "default",
        "sync_version": 2,
        "status": "updated"
      }
    ],
    "next_cursor": "ver_2"
  },
  "request_id": "req_xxx"
}
```

限制：

- 单批最多 200 条。
- 单条 payload 默认不超过 256 KB。

### 3.4 增量下载

```http
GET /v1/sync/records?cursor=ver_0&limit=100
```

示例：

```bash
curl "http://localhost:8080/v1/sync/records?cursor=ver_0&limit=100" \
  -H "Authorization: Bearer tkh_xxx"
```

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
        "sync_version": 2,
        "client_updated_at": "2026-07-08 13:00:00",
        "updated_at": "2026-07-08 13:01:00",
        "deleted_at": null
      }
    ],
    "next_cursor": "ver_2",
    "has_more": false
  },
  "request_id": "req_xxx"
}
```

查询参数：

| 参数 | 说明 |
| --- | --- |
| `cursor` | 上次返回的 `next_cursor` |
| `limit` | 默认 100，最大 500 |
| `namespace` | 只拉取指定命名空间 |
| `updated_after` | 只拉取指定时间之后的数据 |
| `include_deleted` | 是否包含删除墓碑，默认 `true` |

### 3.5 删除记录

```http
DELETE /v1/sync/records/{namespace}/{client_record_id}
```

请求：

```json
{
  "device_id": "client-device-001"
}
```

响应：

```json
{
  "ok": true,
  "data": {
    "namespace": "settings",
    "client_record_id": "default",
    "sync_version": 3,
    "deleted": true,
    "deleted_at": "2026-07-08 13:10:00"
  },
  "request_id": "req_xxx"
}
```

删除是软删除。换机或离线设备仍可通过增量拉取拿到删除状态。

### 3.6 获取同步概览

```http
GET /v1/sync/manifest
```

响应：

```json
{
  "ok": true,
  "data": {
    "user_id": 12,
    "department_id": 1,
    "latest_sync_version": 3,
    "namespaces": [
      {
        "namespace": "settings",
        "record_count": 1,
        "latest_sync_version": 3,
        "updated_at": "2026-07-08 13:10:00"
      }
    ]
  },
  "request_id": "req_xxx"
}
```

## 4. 换机同步流程

新设备恢复数据：

```text
POST /v1/sync/devices
GET  /v1/sync/manifest
GET  /v1/sync/records?cursor=ver_0&limit=500
GET  /v1/sync/records?cursor=<next_cursor>&limit=500
```

客户端建议：

- 本地保存最后成功的 `next_cursor`。
- 启动时先调用 `GET /v1/sync/manifest` 判断服务端是否有更新。
- 如果 `has_more=true`，继续用 `next_cursor` 拉取。
- 收到 `deleted_at` 不为空的记录时，本地删除对应数据。
- 写入时带 `base_sync_version`，避免覆盖其他设备的新版本。

## 5. 三方智能体模型调用

TokenHub 对外统一暴露模型 API。外部软件只使用 TokenHub API Key，不接触 OpenAI、Anthropic、Gemini 等上游密钥。

### 5.1 OpenAI Chat Completions 兼容

```http
POST /v1/chat/completions
```

```bash
curl http://localhost:8080/v1/chat/completions \
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

### 5.2 OpenAI Responses 兼容

```http
POST /v1/responses
```

```bash
curl http://localhost:8080/v1/responses \
  -H "Authorization: Bearer tkh_xxx" \
  -H "Content-Type: application/json" \
  -d '{
    "model": "gpt-compatible-model",
    "input": "总结当前用户设置"
  }'
```

### 5.3 Anthropic Messages 兼容

```http
POST /v1/messages
```

```bash
curl http://localhost:8080/v1/messages \
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

#### Anthropic tools, streaming, and token count

`POST /v1/messages` accepts Anthropic-compatible message payloads, including `system`, `tools`, `tool_choice`, `stream`, text blocks, image blocks, `tool_use`, and `tool_result` blocks. Tool definitions are passed through to the upstream provider.

Tool request example:

```json
{
  "model": "claude-compatible-model",
  "max_tokens": 1024,
  "tools": [
    {
      "name": "get_weather",
      "description": "Get weather by city",
      "input_schema": {
        "type": "object",
        "properties": {
          "city": {"type": "string"}
        },
        "required": ["city"]
      }
    }
  ],
  "messages": [
    {"role": "user", "content": "weather in Shanghai"}
  ]
}
```

Tool result example:

```json
{
  "model": "claude-compatible-model",
  "max_tokens": 1024,
  "messages": [
    {"role": "user", "content": "weather in Shanghai"},
    {
      "role": "assistant",
      "content": [
        {"type": "tool_use", "id": "toolu_01", "name": "get_weather", "input": {"city": "Shanghai"}}
      ]
    },
    {
      "role": "user",
      "content": [
        {"type": "tool_result", "tool_use_id": "toolu_01", "content": "Sunny, 31C"}
      ]
    }
  ]
}
```

When `stream: true`, TokenHub proxies Anthropic SSE events without translating them. Clients should handle `message_start`, `content_block_start`, `content_block_delta`, `content_block_stop`, `message_delta`, `message_stop`, `ping`, and `error`. Tool input streaming uses `input_json_delta.partial_json` chunks.

Token counting uses:

```http
POST /v1/messages/count_tokens
```

The request body follows the same Anthropic message shape as `POST /v1/messages`, and the upstream response is returned as-is, normally with `input_tokens`.

### 5.4 流式输出

```bash
curl -N http://localhost:8080/v1/chat/completions \
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

### 5.5 Model gateway capability matrix

TokenHub routes external model calls by model `capability`. Clients should call `GET /v1/models` first, then choose the endpoint that matches the authorized model capability.

| Capability | Input | Output | Endpoint |
| --- | --- | --- | --- |
| `text_generation` | text | text | `POST /v1/chat/completions` (legacy prompt format: `POST /v1/completions`) |
| `vision_understanding` | text, image | text | `POST /v1/chat/completions` or `POST /v1/messages` |
| `embedding` | text | embedding | `POST /v1/embeddings` |
| `rerank` | text (query + documents) | relevance scores | `POST /v1/rerank` |
| `moderation` | text, image | classification | `POST /v1/moderations` |
| `asr` | audio | text | `POST /v1/audio/transcriptions` |
| `tts` | text | audio | `POST /v1/audio/speech` |
| `image_generation` | text | image | `POST /v1/images/generations` |
| `video_generation` | text | video | `POST /v1/videos/generations` |

ASR, TTS, image generation, and video generation create multimodal tasks. Synchronous upstream responses return 200. Async upstream responses or native routes return 202 with `task_id`. Clients can poll `GET /v1/tasks/{id}` and request generated asset links with `GET /v1/assets/{id}/download-url`.

The current upload API is JSON base64 via `POST /v1/files` with a 10 MB limit. Multipart upload is not supported yet. `GET /v1/capabilities` exposes planned capabilities such as `image_edit`, `image_variation`, `image_to_video`, `video_understanding`, `video_edit`, and `audio_understanding`; clients should not treat those as callable endpoints until dedicated routes are added.

### 5.6 Kimi API / Moonshot and Kimi Code

Kimi API / Moonshot and Kimi Code are different upstream products and must use different provider URLs. Normal Kimi API traffic uses Moonshot's OpenAI-compatible API and Kimi model ids such as `kimi-k2.7-code-highspeed` or `kimi-k2.6`. Kimi Code traffic uses the Kimi Code API URL and the fixed model id `kimi-for-coding`.

When a route points to Kimi Code, TokenHub sends the upstream request to `/v1/chat/completions`, then returns a Claude Code-compatible Anthropic Messages response to the downstream application. TokenHub strips client IP forwarding headers such as `X-Forwarded-For`, `Forwarded`, `CF-Connecting-IP`, `True-Client-IP`, and `X-Real-IP` before contacting the upstream, so the upstream only sees the TokenHub server egress IP.

Normal Kimi API / Moonshot Provider:

```json
{
  "name": "Kimi API / Moonshot",
  "protocol": "openai",
  "base_url": "https://api.moonshot.cn/v1",
  "secret": "MOONSHOT_API_KEY"
}
```

Normal Kimi API coding model:

```json
{
  "name": "kimi-api-code",
  "external_name": "kimi-k2.7-code-highspeed",
  "capability": "text_generation",
  "context_length": 256000
}
```

Kimi Code Provider:

```json
{
  "name": "Kimi Code",
  "protocol": "openai",
  "base_url": "https://api.kimi.com/coding/v1",
  "secret": "MOONSHOT_API_KEY"
}
```

Kimi Code Model:

```json
{
  "name": "kimi-code",
  "external_name": "kimi-for-coding",
  "capability": "text_generation",
  "context_length": 256000
}
```

Use `https://api.moonshot.cn/v1` for the Kimi API China platform and `https://api.moonshot.ai/v1` for the Kimi API global platform. Use `https://api.kimi.com/coding/v1` for Kimi Code OpenAI-compatible traffic, or `https://api.kimi.com/coding/` when a tool requires the Anthropic-compatible Kimi Code base URL. Use `kimi-k2.7-code-highspeed` for normal Kimi API programming-agent traffic and `kimi-for-coding` for Kimi Code entitlement traffic. Use `kimi-k2.6` for general multimodal input; TokenHub maps it to `vision_understanding` until a dedicated video-understanding capability is added.

Clients can call either the TokenHub Anthropic-compatible gateway or the OpenAI-compatible gateway. Kimi Code routes return Anthropic/Claude Code-compatible responses:

```http
POST /v1/chat/completions
Authorization: Bearer tkh_xxx
Content-Type: application/json
```

## 6. 用量统计 API

用量统计接口只返回当前 API Key 自己的用量，不返回同用户其他 Key、同部门其他用户或全局数据。

### 6.1 用量汇总

```http
GET /v1/usage/summary
```

示例：

```bash
curl "http://localhost:8080/v1/usage/summary?from=2026-07-01&to=2026-07-08" \
  -H "Authorization: Bearer tkh_xxx"
```

可选查询参数：

| 参数 | 说明 |
| --- | --- |
| `from` | 开始时间，默认最近 30 天 |
| `to` | 结束时间 |
| `model_id` | 只统计指定模型 ID |
| `provider_id` | 只统计指定上游厂商 ID |
| `capability` | 只统计指定能力，如 `text_generation` |

响应：

```json
{
  "ok": true,
  "data": {
    "scope": {
      "api_key_id": 10,
      "user_id": 12,
      "department_id": 1
    },
    "period": {
      "from": "2026-07-01 00:00:00",
      "to": "2026-07-08 00:00:00"
    },
    "totals": {
      "request_count": 30,
      "success_count": 29,
      "failed_count": 1,
      "success_rate": 96.67,
      "input_tokens": 10000,
      "output_tokens": 3000,
      "total_tokens": 13000,
      "cache_read_tokens": 0,
      "cache_write_tokens": 0,
      "cache_tokens": 0,
      "total_cost": "0.12000000",
      "avg_latency_ms": 420.5
    },
    "by_model": [],
    "by_capability": [],
    "generated_at": "2026-07-08T13:00:00+00:00"
  },
  "request_id": "req_xxx"
}
```

### 6.2 用量趋势

```http
GET /v1/usage/trends
```

示例：

```bash
curl "http://localhost:8080/v1/usage/trends?bucket=day&from=2026-07-01&limit=31" \
  -H "Authorization: Bearer tkh_xxx"
```

可选查询参数：

| 参数 | 说明 |
| --- | --- |
| `bucket` | `day` 或 `hour`，默认 `day` |
| `from` | 开始时间 |
| `to` | 结束时间 |
| `limit` | 返回桶数量，最大 200 |
| `model_id` | 只统计指定模型 ID |
| `provider_id` | 只统计指定上游厂商 ID |
| `capability` | 只统计指定能力 |

响应：

```json
{
  "ok": true,
  "data": {
    "scope": {
      "api_key_id": 10,
      "user_id": 12,
      "department_id": 1
    },
    "bucket": "day",
    "buckets": [
      {
        "bucket_at": "2026-07-08 00:00:00",
        "request_count": 12,
        "success_count": 12,
        "failed_count": 0,
        "success_rate": 100,
        "input_tokens": 3000,
        "output_tokens": 900,
        "total_tokens": 3900,
        "cache_tokens": 0,
        "total_cost": "0.03000000",
        "avg_latency_ms": 350
      }
    ],
    "generated_at": "2026-07-08T13:00:00+00:00"
  },
  "request_id": "req_xxx"
}
```

## 7. 常见错误码

| HTTP | code | 说明 |
| --- | --- | --- |
| 401 | `API_KEY_INVALID` | 缺少、错误或不可用 API Key |
| 401 | `API_KEY_EXPIRED` | API Key 已过期 |
| 403 | `IP_FORBIDDEN` | 来源 IP 不在白名单 |
| 403 | `MODEL_FORBIDDEN` | 模型未授权或无可用路由 |
| 404 | `SYNC_RECORD_NOT_FOUND` | 同步记录不存在 |
| 409 | `SYNC_CONFLICT` | 服务端记录已被其他设备更新 |
| 413 | `SYNC_PAYLOAD_TOO_LARGE` | 单条或批量 payload 超限 |
| 422 | `SYNC_INVALID_PAYLOAD` | 请求 JSON 不合法 |
| 429 | `QUOTA_EXCEEDED` | 超出配额 |

## 8. 推荐命名空间

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

## 9. 验收命令

服务层 smoke：

```bash
docker compose exec -T php php scripts/sync-smoke.php
```

HTTP API smoke：

```bash
docker compose exec -T php php scripts/sync-api-smoke.php
```

完整测试：

```bash
docker compose exec -T php php bin/run-tests.php
```

## 10. P0 外部接入闭环接口

所有接口继续使用：

```http
Authorization: Bearer tkh_xxx
Accept: application/json
```

外部软件不能使用普通用户密码登录，也不能自助创建 API Key。所有返回数据仅限当前 API Key 作用域。

### GET /v1/me

返回当前 API Key、用户和部门信息，不返回 `key_hash` 或明文 Key。

### GET /v1/models

返回当前 Key 授权模型，按 OpenAI-compatible model discovery 暴露顶层 `data` 数组：

```json
{
  "data": [
    { "id": "deepseek-v4-flash" }
  ]
}
```

`data[].id` 是调用 `/v1/chat/completions`、`/v1/responses`、`/v1/embeddings` 等网关接口时使用的 TokenHub 模型名。扩展字段和健康状态通过 `GET /v1/models/{model}` 和 `GET /v1/model-health` 获取。

### GET /v1/quota

返回当前 Key 命中的 `api_key`、`user`、`department` 限额策略，以及 `api_key_model_quotas` 的 per-model quota；包含 `limits`、`usage`、`remaining`、`period`、`reset_at`。

### GET /v1/usage/records

分页查询当前 Key 调用明细。支持 `from/to/model_id/status/error_code/capability/limit/cursor`。`limit` 默认 100，最大 500。

### GET /v1/requests/{request_id}

查询当前 Key 某次调用的排障详情，包括模型、状态、错误码、用量、延迟、关联任务和资产摘要。跨 Key 查询返回 404。

## 11. P1 同步和文件能力

### GET /v1/sync/devices

列出当前用户同步设备。

### DELETE /v1/sync/devices/{device_id}

注销当前用户设备，设备状态改为 `disabled`。

### GET /v1/sync/namespaces

返回 namespace 统计，数据源与 `/v1/sync/manifest` 一致。

### GET /v1/sync/records/{namespace}/{client_record_id}

读取单条同步记录。不存在或跨用户时返回 404。

### POST /v1/sync/records/{namespace}/{client_record_id}:resolve-conflict

`strategy=server_wins` 直接返回服务端当前记录；`strategy=client_wins` 使用请求中的 `payload` 覆盖服务端。

```json
{
  "strategy": "client_wins",
  "device_id": "dev_xxx",
  "payload": {
    "theme": "dark"
  }
}
```

### POST /v1/files

JSON base64 小文件上传，最大 10 MB，写入 `media_assets`，当前版本不支持 multipart。

```json
{
  "file_name": "settings.json",
  "mime_type": "application/json",
  "b64_data": "eyJvayI6dHJ1ZX0="
}
```

### GET /v1/files/{file_id}/download-url

为当前 Key 范围内文件生成短时下载地址。

### DELETE /v1/files/{file_id}

软删除当前 Key 范围内文件。

## 12. P2 集成体验接口

### GET /v1/capabilities

返回服务端版本、启用能力、payload 限制、batch 限制和支持的模型协议。

### GET /v1/openapi.json

返回机器可读 OpenAPI 3.1 文档。静态文件位于 `public/openapi.json`。

### GET /v1/events

分页拉取当前 Key 的持久化 outbox 事件，包括同步、请求状态和 webhook 投递事件。支持 `limit` 和稳定游标 `cursor=evt_<id>`；客户端保存 `next_cursor` 后可无重复续拉。

### POST /v1/webhooks

注册回调。生产地址必须是 HTTPS；本地 smoke 允许 `http://localhost`、`http://127.0.0.1`、`http://caddy`、`http://host.docker.internal`。

```json
{
  "url": "https://example.com/tokenhub/webhook",
  "event_types": ["request.failed", "task.succeeded", "sync.record.upsert"]
}
```

创建响应只返回一次 `secret`。投递请求带签名头：

```http
X-TokenHub-Signature: sha256=<hmac>
X-TokenHub-Event: request.failed
X-TokenHub-Delivery: whd_xxx
```

签名内容为原始 JSON body，算法为 `HMAC-SHA256(secret, body)`。最多重试 5 次，投递记录写入 `external_webhook_deliveries`。

### GET /v1/webhooks

查询当前 Key 注册的 webhook，不返回 secret。

### DELETE /v1/webhooks/{id}

删除当前 Key 的 webhook。

### POST /v1/feedback

外部软件提交反馈：

```json
{
  "request_id": "req_xxx",
  "rating": 5,
  "tags": ["good-answer"],
  "comment": "用户确认结果可用"
}
```

`request_id` 如果提供，必须属于当前 API Key。
## 13. RAG knowledge base APIs

All RAG endpoints keep the same external rule: callers use only `Authorization: Bearer tkh_xxx`.
Ordinary external users do not get a page-login flow. Dataset access is resolved from API Key bindings.

### GET /v1/rag/import-templates

List supported import templates: text, question-answer, image knowledge, and `image_package`. Use `tpl_image_package_v1` for batch image packages.

### GET /v1/rag/import-templates/{template_id}/download

Download a styled Excel template. Templates include frozen headers, required-column marks, examples, validation hints, and an instruction sheet. The image package template is a manifest file; package images stay as normal files under `images/`.

### POST /v1/rag/knowledge-bases/{kb_id}/uploads

Upload Excel content or ZIP image packages for parsing and preview. The request records uploader identity from the current API Key, department, device id, file hash, template type, and target datasets.

Example:

```json
{
  "template_type": "qa",
  "file_name": "faq.xlsx",
  "b64_data": "<base64>",
  "dataset_ids": ["ds_product_faq"],
  "confirm_required": true
}
```

The upload only creates preview rows. It does not vectorize until confirmation unless `auto_vectorize=true` is explicitly used and all rows pass validation.

Batch image package example:

```json
{
  "filename": "product-images.zip",
  "content_type": "zip",
  "content_base64": "<base64 zip>",
  "processing": {
    "package_mode": "manifest_zip",
    "template": "image_package",
    "target_dataset_id": "ds_product_images",
    "confirm_required": true
  }
}
```

The ZIP root must contain exactly one `manifest.csv` or `manifest.xlsx`. Image rows use relative paths such as `images/e03.png`; absolute paths, `..`, hidden path segments, and unsupported formats are rejected. Required manifest columns are `image`, `purpose`, and `description`; optional annotation columns include `title`, `detailed_description`, `objects`, `tags`, `category`, `source_uri`, `language`, and `enabled`.

Package errors: `RAG_PACKAGE_INVALID`, `RAG_PACKAGE_MANIFEST_MISSING`, `RAG_PACKAGE_MANIFEST_CONFLICT`, `RAG_PACKAGE_PATH_INVALID`.
Row errors: `RAG_PACKAGE_IMAGE_MISSING`, `RAG_IMAGE_UNSUPPORTED_FORMAT`, `RAG_IMAGE_ANNOTATION_REQUIRED`.

### GET /v1/rag/uploads/{upload_id}

Return upload stage, weighted progress, text count, image count, failed count, and target datasets.

### GET /v1/rag/uploads/{upload_id}/preview

Preview parsed rows before vectorization. Use `type=text|image&page=1&page_size=20`. Text rows include document text or Q/A fields. Image rows include thumbnail asset data, human annotations, `ai_annotation`, `quality_warnings`, OCR/caption warning status, purpose, description, detailed description, labels, and row validation errors.

### POST /v1/rag/uploads/{upload_id}/confirm-vectorization

Confirm preview rows and start batch vectorization. Rows with validation errors block confirmation and return `RAG_UPLOAD_HAS_ERRORS`.

### GET /v1/rag/retrievals/{retrieval_id}

Read retrieval sources returned from a RAG model call. The lookup is limited to the current API Key.

### POST /v1/rag/feedback

Submit RAG answer feedback. Ratings `bad`, `outdated`, `wrong_source`, and `missing_context` create correction tasks automatically.

Example:

```json
{
  "retrieval_id": "rtr_xxx",
  "rating": "outdated",
  "reason": "The quoted policy expired.",
  "selected_source_ids": ["src_1"]
}
```
# TokenHub 外部软件 API 与同步接口中文接入文档

本文面向外部软件、桌面客户端、Agent Runtime、自动化脚本和第三方业务系统。外部调用统一使用 API Key，不提供普通用户网页登录流程，也不暴露上游模型厂商密钥。

## 1. 基础信息

生产地址按实际部署替换：

```text
https://your-tokenhub.example.com
```

本地开发地址：

```text
http://localhost:8080
```

认证方式：

```http
Authorization: Bearer ${TOKENHUB_API_KEY}
Content-Type: application/json
Accept: application/json
```

成功响应：

```json
{
  "ok": true,
  "data": {},
  "request_id": "req_xxx"
}
```

错误响应：

```json
{
  "ok": false,
  "error": {
    "code": "API_KEY_INVALID",
    "message": "Invalid API key"
  },
  "request_id": "req_xxx"
}
```

## 2. 外部软件接入流程

1. 管理员在 TokenHub 后台创建用户、部门和 API Key。
2. 管理员给 API Key 授权模型、配额、RAG 知识库和数据集。
3. 外部软件保存 API Key，并在每次请求中带 `Authorization: Bearer ...`。
4. 软件首次启动时调用 `POST /v1/sync/devices` 注册设备。
5. 软件调用 `GET /v1/models` 和 `GET /v1/model-health` 发现可用模型。
6. 模型调用走 `/v1/chat/completions`、`/v1/responses`、`/v1/messages` 等接口。
7. 用户配置、Agent 状态、会话等数据走 `/v1/sync/*` 同步。
8. 文件、图片输入和生成资产走 `/v1/files`、`/v1/assets`。
9. 业务回调走 `/v1/webhooks`。
10. 知识库上传、预览、向量化和检索反馈走 `/v1/rag/*`。

## 3. 身份、模型和配额接口

### GET /v1/me

返回当前 API Key、用户、部门和作用域信息，不返回明文 Key 或 `key_hash`。

### GET /v1/models

返回当前 API Key 授权的模型列表，格式兼容只读取 `data[].id` 的 OpenAI-style 客户端：

```json
{
  "data": [
    { "id": "deepseek-v4-flash" }
  ]
}
```

`data[].id` 是 TokenHub 模型名。模型能力、Provider、流式支持和多模态信息通过 `GET /v1/models/{model}` 或 `GET /v1/model-health` 查询。

### GET /v1/models/{model}

查询单个授权模型详情和健康状态。`{model}` 可以传 TokenHub 模型名，也可以传上游 `external_name`。

### GET /v1/model-health

返回当前 API Key 可用模型的路由健康摘要和逐模型状态。外部客户端应使用它做模型 fallback，而不是硬编码模型可用性。

### GET /v1/quota

返回当前 API Key 命中的配额策略、已用量、剩余额度、周期和重置时间。`0` 或空额度表示不限制。

## 4. 模型网关接口

TokenHub 对外暴露 OpenAI-compatible 和 Anthropic-compatible 形态：

- `POST /v1/chat/completions`
- `POST /v1/responses`
- `POST /v1/messages`
- `POST /v1/messages/count_tokens`
- `POST /v1/embeddings`
- `POST /v1/audio/transcriptions`
- `POST /v1/audio/speech`
- `POST /v1/images/generations`
- `POST /v1/videos/generations`

Chat Completions 示例：

```bash
curl "${TOKENHUB_BASE_URL}/v1/chat/completions" \
  -H "Authorization: Bearer ${TOKENHUB_API_KEY}" \
  -H "Content-Type: application/json" \
  -d '{
    "model": "gpt-compatible-model",
    "messages": [
      {"role": "user", "content": "hello"}
    ],
    "stream": false
  }'
```

Responses 示例：

```bash
curl "${TOKENHUB_BASE_URL}/v1/responses" \
  -H "Authorization: Bearer ${TOKENHUB_API_KEY}" \
  -H "Content-Type: application/json" \
  -d '{
    "model": "gpt-compatible-model",
    "input": "总结当前用户设置"
  }'
```

`POST /v1/messages` 支持 Anthropic 风格的 `system`、`tools`、`tool_choice`、`stream`、文本块、图片块、`tool_use` 和 `tool_result`。

## 5. 火山引擎 Seedance 视频接口

`POST /v1/videos/generations` 支持 Seedance 文生视频和图生视频。TokenHub 会根据模型配置把请求适配到火山引擎 Ark/Seedance 上游。

```json
{
  "model": "seedance-video",
  "prompt": "product demo",
  "image_url": "https://example.com/input.png",
  "duration": 5,
  "resolution": "720p",
  "ratio": "16:9"
}
```

异步任务查询：

```http
GET /v1/tasks/{id}
```

主动刷新支持的上游任务：

```http
POST /v1/tasks/{id}:refresh
```

获取生成资产下载链接：

```http
GET /v1/assets/{id}/download-url
```

## 6. 任务和资产接口

### GET /v1/tasks/{id}

查询当前 API Key 范围内的多模态长任务。返回任务状态、进度、上游任务 ID、错误码、错误消息和资产摘要。

### POST /v1/tasks/{id}:refresh

刷新支持的上游异步任务状态。终态任务返回当前状态并带 `refreshed=false`。

### GET /v1/assets

列出当前 API Key 拥有的上传文件和生成资产。支持 `type=file|image|video|audio` 和 `limit=1..100`。

### GET /v1/assets/{id}/download-url

生成短期下载地址，默认有效期 300 秒。

## 7. 用户同步接口

同步接口用于外部软件换机、离线缓存、多设备合并和 Agent 状态同步。

### POST /v1/sync/devices

注册当前设备。

```json
{
  "device_id": "client-device-001",
  "device_name": "Windows Desktop",
  "platform": "windows",
  "client_version": "1.0.0"
}
```

### GET /v1/sync/devices

列出当前用户设备。

### DELETE /v1/sync/devices/{device_id}

禁用当前用户设备。

### GET /v1/sync/manifest

返回同步概览，包括最新版本、命名空间、记录数量和更新时间。

### GET /v1/sync/namespaces

返回命名空间统计。

### GET /v1/sync/records

增量拉取记录。常用参数：`cursor`、`limit`、`namespace`、`updated_after`、`include_deleted`。

### PUT /v1/sync/records/{namespace}/{client_record_id}

上传或更新单条记录。

```json
{
  "device_id": "client-device-001",
  "payload": {
    "theme": "dark",
    "language": "zh-CN"
  },
  "client_updated_at": "2026-07-08T13:00:00Z",
  "base_sync_version": 0
}
```

### POST /v1/sync/records:batch

批量上传记录。

```json
{
  "device_id": "client-device-001",
  "records": [
    {
      "namespace": "settings",
      "client_record_id": "default",
      "payload": {
        "theme": "dark"
      },
      "base_sync_version": 1
    }
  ]
}
```

### GET /v1/sync/records/{namespace}/{client_record_id}

读取单条同步记录。

### DELETE /v1/sync/records/{namespace}/{client_record_id}

软删除单条同步记录。

### POST /v1/sync/records/{namespace}/{client_record_id}:resolve-conflict

解决同步冲突。`strategy=server_wins` 返回服务端版本；`strategy=client_wins` 使用请求中的 `payload` 覆盖服务端版本。

推荐命名空间：`profile`、`settings`、`workspace`、`agent-state`、`agent-memory`、`chat-sessions`、`tool-results`、`files`。

## 8. 文件接口

### POST /v1/files

上传 JSON base64 小文件，当前默认限制 10 MB。

```json
{
  "file_name": "settings.json",
  "mime_type": "application/json",
  "b64_data": "eyJvayI6dHJ1ZX0="
}
```

### GET /v1/files/{file_id}/download-url

为当前 API Key 范围内的文件生成短期下载链接。

### DELETE /v1/files/{file_id}

软删除当前 API Key 范围内的文件。

## 9. 用量、请求和事件接口

- `GET /v1/usage/summary`：返回当前 API Key 的用量汇总。
- `GET /v1/usage/trends`：返回当前 API Key 的用量趋势。
- `GET /v1/usage/records`：分页查询当前 API Key 的调用明细。
- `GET /v1/requests/{request_id}`：查询某次调用排障详情。跨 API Key 查询返回 404。
- `GET /v1/events`：拉取当前 API Key 的持久化 outbox 事件流，使用 `evt_<id>` 游标续拉。

## 10. Webhook 接口

### POST /v1/webhooks

注册回调。生产 URL 必须是 HTTPS；本地 smoke 允许 `http://localhost`、`http://127.0.0.1`、`http://caddy` 和 `http://host.docker.internal`。

```json
{
  "url": "https://example.com/tokenhub/webhook",
  "event_types": ["request.completed", "request.failed", "task.succeeded"]
}
```

投递请求带签名头：

```http
X-TokenHub-Signature: sha256=<hmac>
X-TokenHub-Event: request.completed
X-TokenHub-Delivery: whd_xxx
```

签名算法：`HMAC-SHA256(secret, raw_json_body)`。

### GET /v1/webhooks

列出当前 API Key 注册的 Webhook，不返回 `secret`。

### GET /v1/webhooks/{id}/deliveries

查询 Webhook 投递记录，包括投递状态、尝试次数、响应状态、错误摘要和时间字段。

### POST /v1/webhooks/{id}:test

创建 `webhook.test` 诊断投递，并进入正常投递队列。

### POST /v1/webhook-deliveries/{delivery_id}:retry

重试失败或 dead 的投递。已成功投递的记录不能重试。

### DELETE /v1/webhooks/{id}

禁用并软删除当前 API Key 下的 Webhook。

## 11. RAG knowledge base APIs

所有 RAG 接口仍然使用 API Key 鉴权，权限来自 API Key、用户或部门绑定。

### GET /v1/rag/import-templates

列出导入模板，包括文本、问答、图片知识和 `image_package`。

### GET /v1/rag/import-templates/{template_id}/download

下载导入模板。图片包模板使用 `tpl_image_package_v1` 和 `manifest_zip`。

### GET /v1/rag/knowledge-bases

列出当前 API Key 有权限访问的知识库。

### GET /v1/rag/knowledge-bases/{kb_id}/datasets

列出某个知识库下当前 API Key 有权限访问的数据集。

### POST /v1/rag/knowledge-bases/{kb_id}/uploads

上传 Excel、文本或 ZIP 图片包，生成预览数据。

```json
{
  "filename": "product-images.zip",
  "content_type": "zip",
  "content_base64": "<base64 zip>",
  "processing": {
    "package_mode": "manifest_zip",
    "template": "image_package",
    "target_dataset_id": "ds_product_images",
    "confirm_required": true
  }
}
```

常见错误码：`RAG_PACKAGE_INVALID`、`RAG_PACKAGE_MANIFEST_MISSING`、`RAG_PACKAGE_MANIFEST_CONFLICT`、`RAG_PACKAGE_PATH_INVALID`、`RAG_PACKAGE_IMAGE_MISSING`、`RAG_IMAGE_UNSUPPORTED_FORMAT`、`RAG_IMAGE_ANNOTATION_REQUIRED`、`RAG_AI_ANNOTATION_FAILED`、`RAG_IMAGE_EMBEDDING_FAILED`、`RAG_MULTIMODAL_EMBEDDING_FAILED`、`RAG_VECTOR_SUCCESS_RATE_LOW`、`RAG_MODEL_NOT_CONFIGURED`。

### GET /v1/rag/uploads/{upload_id}

查询上传状态、当前阶段、进度、文本条数、图片条数、失败条数和目标数据集。

### GET /v1/rag/uploads/{upload_id}/preview

预览解析结果。支持 `type=text|image&page=1&page_size=20`。

### POST /v1/rag/uploads/{upload_id}/confirm-vectorization

确认预览内容并启动向量化。预览中有错误时返回 `RAG_UPLOAD_HAS_ERRORS`。

### GET /v1/rag/retrievals/{retrieval_id}

读取某次 RAG 模型调用返回的检索来源。只能读取当前 API Key 产生的检索记录。

### POST /v1/rag/feedback

提交 RAG 答案反馈。`bad`、`outdated`、`wrong_source`、`missing_context` 等反馈会创建纠错任务。

## 12. 模型调用中启用 RAG

```json
{
  "model": "gpt-compatible-model",
  "messages": [
    {"role": "user", "content": "根据知识库回答售后政策"}
  ],
  "rag": {
    "enabled": true,
    "knowledge_base_ids": ["kb_product"],
    "dataset_ids": ["ds_product_faq"],
    "top_k": 8,
    "required": false
  }
}
```

## 13. 常见错误码

| HTTP | code | 说明 |
| --- | --- | --- |
| 401 | `API_KEY_INVALID` | API Key 缺失、错误或不可用 |
| 401 | `API_KEY_EXPIRED` | API Key 已过期 |
| 403 | `IP_FORBIDDEN` | 来源 IP 不在白名单 |
| 403 | `MODEL_FORBIDDEN` | 模型未授权或无可用路由 |
| 403 | `RAG_KB_FORBIDDEN` | 无知识库权限 |
| 403 | `RAG_DATASET_FORBIDDEN` | 无数据集权限 |
| 404 | `SYNC_RECORD_NOT_FOUND` | 同步记录不存在 |
| 404 | `CONTENT_FORBIDDEN` | 任务或资产不存在，或不属于当前 API Key |
| 404 | `WEBHOOK_NOT_FOUND` | Webhook 不存在 |
| 409 | `SYNC_CONFLICT` | 同步版本冲突 |
| 409 | `WEBHOOK_DELIVERY_ALREADY_SUCCEEDED` | 成功投递不能重试 |
| 413 | `FILE_TOO_LARGE` | 文件超过限制 |
| 422 | `FILE_INVALID_BASE64` | 文件 base64 无效 |
| 429 | `QUOTA_EXCEEDED` | 超出配额 |
| 502 | `PROVIDER_ERROR` | 上游模型服务错误 |
| 504 | `PROVIDER_TIMEOUT` | 上游模型服务超时 |

## 14. 验收和示例

OpenAPI 文件：`public/openapi.json`。

智能体管理（普通用户、API Key 生命周期与部门内 RAG 资源）的接口说明、curl/Python/Node.js 演示：`docs/agent-management-api.md`。

完整外部 API 示例：

- `examples/external-api/curl.sh`
- `examples/external-api/python_client.py`
- `examples/external-api/node_client.mjs`
- `examples/external-api/php_client.php`

推荐验证命令：

```bash
docker compose exec -T php php tests/Support/ExternalApiSurfaceContractTest.php
docker compose exec -T php php tests/Support/RagApiSurfaceContractTest.php
docker compose exec -T php php tests/Support/ExternalUsageApiContractTest.php
docker compose exec -T php php tests/Support/KimiCodexCompatibilityContractTest.php
```

---

## 历史内容

以下为文件原有历史内容，保留用于追溯旧任务记录。新接入请以上方中文文档为准。
