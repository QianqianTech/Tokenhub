# TokenHub 对外 API 完整中文接入文档

> 兼容标题：TokenHub External API Complete Guide

本文面向外部系统、客户端、Agent Runtime、自动化脚本和业务后台。TokenHub 对外 API 坚持 **API Key-only**：外部普通用户不登录 TokenHub 页面，也不直接接触上游厂商密钥；所有模型、RAG、同步、文件、Webhook 和用量接口都通过 `Authorization: Bearer ${TOKENHUB_API_KEY}` 调用。

## 1. 基础约定

生产地址按实际部署域名替换：

```text
https://your-tokenhub.example.com
```

本地开发默认地址：

```text
http://localhost:8080
```

通用请求头：

```http
Authorization: Bearer ${TOKENHUB_API_KEY}
Content-Type: application/json
Accept: application/json
```

通用成功响应：

```json
{
  "ok": true,
  "data": {},
  "request_id": "req_xxx"
}
```

通用错误响应：

```json
{
  "ok": false,
  "error": {
    "code": "MODEL_FORBIDDEN",
    "message": "No enabled provider route for model"
  },
  "request_id": "req_xxx"
}
```

## 2. 接入流程

1. 管理员在后台创建用户、部门和 API Key。
2. 管理员给 API Key 授权可调用模型和 RAG 知识库/数据集。
3. 外部系统只保存 API Key，不保存普通用户密码。
4. 外部系统启动时先调用 `GET /v1/me`、`GET /v1/models`、`GET /v1/model-health` 做身份和能力发现。
5. 模型调用走 `/v1/chat/completions`、`/v1/responses`、`/v1/messages` 等网关接口。
6. 长任务轮询 `GET /v1/tasks/{id}`，必要时调用 `POST /v1/tasks/{id}:refresh` 刷新上游状态。
7. 业务数据同步走 `/v1/sync/*`，文件和素材走 `/v1/files`、`/v1/assets`。
8. 事件回调走 `/v1/webhooks`，RAG 知识库导入和检索反馈走 `/v1/rag/*`。

## 3. 身份、模型和配额

### GET /v1/me

返回当前 API Key、归属用户、归属部门和作用域信息，不返回明文 Key 或 `key_hash`。

### GET /v1/models

返回当前 API Key 授权的模型列表。该接口按 OpenAI-compatible model discovery 返回顶层 `data` 数组，供第三方客户端直接读取模型 ID。

Compatibility response:

```json
{
  "data": [
    { "id": "deepseek-v4-flash" }
  ]
}
```

`data[].id` is the TokenHub model name to use in `/v1/chat/completions` and other model gateway requests. Extended capability and health metadata is available from `GET /v1/model-health` and `GET /v1/models/{model}`.

### GET /v1/models/{model}

返回单个授权模型详情和路由健康信息。`{model}` 可以是 TokenHub 模型名，也可以是上游 `external_name`。

### GET /v1/model-health

返回当前 API Key 可用模型的健康摘要，用于客户端自动降级和 fallback。

### GET /v1/quota

返回当前 API Key 命中的配额策略、已用量、剩余额度、周期和重置时间。`0` 或空额度表示不限制。

## 4. 模型网关 API

TokenHub 对外暴露 OpenAI-compatible 和 Anthropic-compatible 网关接口：

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
      {"role": "user", "content": "请总结这段内容"}
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
    "input": "生成一份接口测试清单"
  }'
```

`POST /v1/messages` 支持 Anthropic 形态的 `system`、`tools`、`tool_choice`、`stream`、文本块、图片块、`tool_use` 和 `tool_result`。`POST /v1/messages/count_tokens` 用于按 Anthropic 消息结构估算输入 token。

流式输出：

```bash
curl -N "${TOKENHUB_BASE_URL}/v1/chat/completions" \
  -H "Authorization: Bearer ${TOKENHUB_API_KEY}" \
  -H "Content-Type: application/json" \
  -d '{
    "model": "gpt-compatible-model",
    "messages": [
      {"role": "user", "content": "stream hello"}
    ],
    "stream": true
  }'
```

## 5. 火山引擎 Seedance / 视频生成

`POST /v1/videos/generations` 支持 Seedance 风格的视频生成和图生视频请求。TokenHub 会根据模型配置把请求适配到火山引擎 Ark/Seedance 上游接口。

文本生成视频：

```json
{
  "model": "seedance-video",
  "prompt": "A product demo video on a clean desk",
  "duration": 5,
  "resolution": "720p",
  "ratio": "16:9"
}
```

图片生成视频：

```json
{
  "model": "seedance-video",
  "prompt": "让产品镜头缓慢推进，背景保持干净",
  "image_url": "https://example.com/input.png",
  "duration": 5,
  "resolution": "720p"
}
```

轮询任务：

```http
GET /v1/tasks/{id}
```

主动刷新支持的上游异步任务：

```http
POST /v1/tasks/{id}:refresh
```

任务成功后，使用资产接口获取短期下载链接：

```http
GET /v1/assets/{id}/download-url
```

## 6. 任务和资产 API

### GET /v1/tasks/{id}

查询当前 API Key 范围内的长任务状态。返回 `status`、`task_type`、`provider_task_id`、`progress`、`assets`、`error_code` 和时间字段。

### POST /v1/tasks/{id}:refresh

当任务处于非终态，并且已有 `provider_task_id` 时，TokenHub 会尝试从支持的上游厂商刷新状态。终态任务返回当前结果，并带 `refreshed=false`。

### GET /v1/assets

列出当前 API Key 拥有的上传文件和生成资产。支持 `type=file|image|video|audio`、`limit=1..100` 和 `cursor=id_<last_id>`。响应中的 `has_more=true` 时，用 `next_cursor` 继续翻页。

### GET /v1/assets/{id}/download-url

返回短期下载地址：

```json
{
  "asset_id": "asset_xxx",
  "download_url": "https://your-tokenhub.example.com/download/dl_xxx",
  "expires_in": 300
}
```

## 7. 同步 API

同步接口用于外部客户端换机、离线缓存和 Agent 状态同步。

- `POST /v1/sync/devices`
- `GET /v1/sync/devices`
- `DELETE /v1/sync/devices/{device_id}`
- `GET /v1/sync/manifest`
- `GET /v1/sync/namespaces`
- `GET /v1/sync/records`
- `POST /v1/sync/records:batch`
- `PUT /v1/sync/records/{namespace}/{client_record_id}`
- `GET /v1/sync/records/{namespace}/{client_record_id}`
- `DELETE /v1/sync/records/{namespace}/{client_record_id}`
- `POST /v1/sync/records/{namespace}/{client_record_id}:resolve-conflict`

批量上传示例：

```json
{
  "device_id": "desktop-001",
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

推荐命名空间：`profile`、`settings`、`workspace`、`agent-state`、`agent-memory`、`chat-sessions`、`tool-results`、`files`。

## 8. 文件 API

### POST /v1/files

上传 JSON base64 小文件，当前默认限制 10 MB。适合配置、图片输入、RAG 小文件和 Agent 附件。

```json
{
  "file_name": "input.png",
  "mime_type": "image/png",
  "b64_data": "<base64>"
}
```

### GET /v1/files/{file_id}/download-url

为当前 API Key 范围内的文件生成短期下载链接。

### DELETE /v1/files/{file_id}

删除会先撤销 API 可见性；受 TokenHub 管理的本地外部上传文件会同步物理删除。上游生成资产和非本地 URL 不会被此接口删除。

软删除当前 API Key 范围内的文件。

## 9. 用量、请求和事件

- `GET /v1/usage/summary`：按当前 API Key 返回用量汇总。
- `GET /v1/usage/trends`：按天或小时返回用量趋势。
- `GET /v1/usage/records`：分页查询当前 API Key 的调用明细。
- `GET /v1/requests/{request_id}`：查询某次请求的排障详情。
- `GET /v1/events`：拉取当前 API Key 的持久化 outbox 事件流。

## 10. Webhook API

### POST /v1/webhooks

```json
{
  "url": "https://example.com/tokenhub/webhook",
  "event_types": ["request.completed", "request.failed", "task.succeeded"]
}
```

生产地址必须是 HTTPS。本地 smoke 允许 `http://localhost`、`http://127.0.0.1`、`http://caddy`、`http://host.docker.internal`。创建响应只返回一次 `secret`，外部系统需要保存它用于验签。

### Webhook 签名

TokenHub 投递请求会带：

```http
X-TokenHub-Signature: sha256=<hmac>
X-TokenHub-Event: request.completed
X-TokenHub-Delivery: whd_xxx
```

签名算法：

```text
HMAC-SHA256(secret, raw_json_body)
```

`POST /v1/webhooks/{id}:rotate-secret` 会返回一次新的 secret。旧 secret 保留 24 小时；重叠期内请求同时携带 `X-TokenHub-Signature`（新 secret）和 `X-TokenHub-Signature-Previous`（旧 secret）。

### GET /v1/webhooks

列出当前 API Key 注册的 Webhook，不返回 `secret`。

### GET /v1/webhooks/{id}/deliveries

查询某个 Webhook 的投递记录，包括 `delivery_id`、`event_type`、`status`、`attempt_count`、`response_status`、`error_summary`、`next_attempt_at`、`delivered_at`。不会返回签名密钥或原始业务 payload。

### POST /v1/webhooks/{id}:test

创建一条 `webhook.test` 诊断投递，并进入正常队列处理。

### POST /v1/webhook-deliveries/{delivery_id}:retry

把失败或 dead 的投递重置为 `pending` 并重新入队。已成功投递的记录不能重试。

### DELETE /v1/webhooks/{id}

禁用并软删除当前 API Key 下的 Webhook。

## 11. RAG API

RAG API 用于外部系统导入知识库、预览解析结果、确认向量化、读取检索来源并提交反馈。

### GET /v1/rag/import-templates

列出支持的导入模板，包括文本、问答、图片知识和 `image_package`。

### GET /v1/rag/import-templates/{template_id}/download

下载带格式和说明的 Excel 模板。图片批量包使用 `tpl_image_package_v1` 和 `manifest_zip`。

### GET /v1/rag/knowledge-bases

列出当前 API Key 有权限访问的知识库，返回 `kb_id`、名称、状态、权限和默认标记。

### GET /v1/rag/knowledge-bases/{kb_id}/datasets

列出某个知识库下当前 API Key 有权限访问的数据集，返回 `dataset_id`、数据集类型、权限、chunk 数量、vector 数量和索引时间。

### POST /v1/rag/knowledge-bases/{kb_id}/uploads

上传 Excel、文本或 ZIP 图片包，生成预览数据。

普通问答上传示例：

```json
{
  "filename": "faq.xlsx",
  "content_type": "qa",
  "content_base64": "<base64>",
  "processing": {
    "template": "qa",
    "target_dataset_id": "ds_product_faq",
    "confirm_required": true
  }
}
```

图片包上传示例：

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

常见 RAG 错误码：`RAG_PACKAGE_INVALID`、`RAG_PACKAGE_MANIFEST_MISSING`、`RAG_PACKAGE_MANIFEST_CONFLICT`、`RAG_PACKAGE_PATH_INVALID`、`RAG_PACKAGE_IMAGE_MISSING`、`RAG_IMAGE_UNSUPPORTED_FORMAT`、`RAG_IMAGE_ANNOTATION_REQUIRED`、`RAG_AI_ANNOTATION_FAILED`、`RAG_IMAGE_EMBEDDING_FAILED`、`RAG_MULTIMODAL_EMBEDDING_FAILED`、`RAG_VECTOR_SUCCESS_RATE_LOW`、`RAG_MODEL_NOT_CONFIGURED`。

### GET /v1/rag/uploads/{upload_id}

查询上传状态、当前阶段、进度、文本条数、图片条数、失败条数和目标数据集。

### GET /v1/rag/uploads/{upload_id}/preview

预览解析后的文本、问答或图片条目。支持 `type=text|image&page=1&page_size=20`。

### POST /v1/rag/uploads/{upload_id}/confirm-vectorization

确认预览内容并启动向量化。存在校验错误时会返回 `RAG_UPLOAD_HAS_ERRORS`。

### GET /v1/rag/retrievals/{retrieval_id}

读取某次 RAG 模型调用返回的检索来源。只能读取当前 API Key 产生的检索记录。

### POST /v1/rag/feedback

提交 RAG 答案反馈。`bad`、`outdated`、`wrong_source`、`missing_context` 等反馈会自动创建纠错任务。

```json
{
  "retrieval_id": "rtr_xxx",
  "rating": "outdated",
  "reason": "引用的政策已过期",
  "selected_source_ids": ["src_1"]
}
```

## 12. 在模型调用中启用 RAG

Chat 或 Responses 请求可以携带 `rag` 参数：

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

响应会包含：

```json
{
  "rag": {
    "retrieval_id": "rtr_xxx",
    "sources": [],
    "fallback_used": false
  }
}
```

## 13. 示例代码

可运行示例位于：

- `examples/external-api/curl.sh`
- `examples/external-api/python_client.py`
- `examples/external-api/node_client.mjs`
- `examples/external-api/php_client.php`

示例覆盖 Seedance 视频生成、`GET /v1/tasks/{id}`、`POST /v1/tasks/{id}:refresh`、用户同步 `POST /v1/sync/records:batch`、文件上传 `POST /v1/files`、资产列表 `GET /v1/assets`、Webhook 创建/测试/投递查询/重试，以及 RAG 知识库发现、上传、确认向量化和检索详情。

## 14. 常见错误码

| HTTP | code | 说明 |
| --- | --- | --- |
| 401 | `API_KEY_INVALID` | API Key 缺失、错误或不可用 |
| 401 | `API_KEY_EXPIRED` | API Key 已过期 |
| 403 | `IP_FORBIDDEN` | 来源 IP 不在白名单 |
| 403 | `MODEL_FORBIDDEN` | 模型未授权或没有可用路由 |
| 403 | `RAG_KB_FORBIDDEN` | 当前 API Key 无知识库权限 |
| 403 | `RAG_DATASET_FORBIDDEN` | 当前 API Key 无数据集权限 |
| 404 | `CONTENT_FORBIDDEN` | 任务或资产不存在，或不属于当前 API Key |
| 404 | `WEBHOOK_NOT_FOUND` | Webhook 不存在或不属于当前 API Key |
| 409 | `WEBHOOK_DELIVERY_ALREADY_SUCCEEDED` | 已成功投递的 Webhook 不能重试 |
| 413 | `FILE_TOO_LARGE` | 文件超过上传大小限制 |
| 422 | `FILE_INVALID_BASE64` | 文件 base64 无效 |
| 429 | `QUOTA_EXCEEDED` | 超出配额 |
| 502 | `PROVIDER_ERROR` | 上游模型服务错误 |
| 504 | `PROVIDER_TIMEOUT` | 上游模型服务超时 |

## 15. 接入建议

- 客户端启动时先调用 `GET /v1/models` 和 `GET /v1/model-health`，不要硬编码模型是否可用。
- 长任务先轮询 `GET /v1/tasks/{id}`；如果长时间停留在处理中，再调用 `POST /v1/tasks/{id}:refresh`。
- 图生视频前，先用 `POST /v1/files` 上传输入图，再用下载 URL 或公网可访问 URL 传给 Seedance。
- Webhook 接入时必须校验 `X-TokenHub-Signature`，并用 `GET /v1/webhooks/{id}/deliveries` 排查失败投递。
- RAG 上传后先 `preview`，确认数据质量后再 `confirm-vectorization`。
- 所有本地缓存和同步数据都应带 `base_sync_version`，避免多设备覆盖。
- 同一用户的 API Key 共享该用户的同步设备、记录和同步版本；不要把 API Key 当作独立同步数据租户。
- 网关成功响应会立即返回 `request_id`，但用量明细、汇总、事件和 webhook 由队列异步完成；客户端应对刚完成的请求短暂重试查询。
- `GET /v1/events` 基于持久化 outbox，以 `evt_<id>` 作为单调游标。客户端应持久保存 `next_cursor` 并按页续拉；事件在对应同步、用量和 Webhook 状态写入时产生。
- outbox 默认保留 90 天；需要长期审计时请在客户端持续消费并自行归档。
