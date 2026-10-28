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
- `POST /v1/completions`（旧版文本补全，路由到 `text_generation` 模型，支持 `stream`）
- `POST /v1/responses`
- `POST /v1/messages`
- `POST /v1/messages/count_tokens`
- `POST /v1/embeddings`
- `POST /v1/rerank`（检索重排，路由到能力为 `rerank` 的模型）
- `POST /v1/moderations`（内容审核，路由到能力为 `moderation` 的模型）
- `POST /v1/audio/transcriptions`
- `POST /v1/audio/translations`
- `POST /v1/audio/speech`
- `POST /v1/images/generations`
- `POST /v1/images/edits`
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

重排与审核示例（请求体按 Jina / Cohere / TEI 与 OpenAI 的通用形态原样转发，`model` 改写为渠道的上游模型名）：

```bash
curl "${TOKENHUB_BASE_URL}/v1/rerank" \
  -H "Authorization: Bearer ${TOKENHUB_API_KEY}" \
  -H "Content-Type: application/json" \
  -d '{"model": "bge-reranker", "query": "差旅报销流程", "documents": ["差旅报销需要发票", "年假规定"], "top_n": 1}'

curl "${TOKENHUB_BASE_URL}/v1/moderations" \
  -H "Authorization: Bearer ${TOKENHUB_API_KEY}" \
  -H "Content-Type: application/json" \
  -d '{"model": "omni-moderation", "input": "需要审核的文本"}'
```

- 重排用量取上游 `usage.total_tokens`（或 `prompt_tokens`、Cohere 的 `meta.billed_units.input_tokens`）记为输入 token；审核接口通常不返回用量，按输入估算并标记 `usage_source=estimated`。
- 模型的 `capability` 必须与接口一致：用聊天模型调用 `/v1/rerank`（或反过来）返回 `422 MODEL_CAPABILITY_UNSUPPORTED`。
- 上游路径不是 `/v1/rerank` 的厂商（如部分云厂商的 `/reranks`、`/compatible-api/v1/rerank`）在渠道的 `tokenhub_endpoint_map` 中映射，例如 `{"/v1/rerank": "/compatible-api/v1/rerank"}`。
- `/v1/completions` 流式请求会自动加上 `stream_options.include_usage`，用量按上游最后一帧计量。

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

### 4.1 多模态接口约定

| 能力 | 接口 | 请求 | 响应 |
| --- | --- | --- | --- |
| 图片理解 | `/v1/chat/completions`、`/v1/responses`、`/v1/messages` | `image_url`（URL 或 `data:image/...;base64,`）、`input_image`、Anthropic `image` 块 | 与上游协议一致 |
| 图片生成 | `/v1/images/generations` | JSON | OpenAI 结构：`data[]` 每项带 `b64_json`，或 `url`（TokenHub 短期下载链接，默认 3600 秒，`GATEWAY_MEDIA_URL_TTL_SECONDS`）；另附 `assets[]` |
| 图片编辑 | `/v1/images/edits` | `multipart/form-data`：`image` 或多个 `image[]`（png/jpeg/webp），可选 `mask` | 同图片生成 |
| 语音合成 | `/v1/audio/speech` | JSON | 默认返回音频二进制（上游 Content-Type）；请求头 `X-TokenHub-Response-Mode: json` 返回 `b64_json` |
| 语音识别 / 翻译 | `/v1/audio/transcriptions`、`/v1/audio/translations` | `multipart/form-data`：`file`（单文件 ≤ 25 MiB，`GATEWAY_MAX_UPLOAD_BYTES`） | `json` / `verbose_json` 返回 JSON；`text` / `srt` / `vtt` 原样返回文本 |
| 视频理解 | `/v1/chat/completions` | `{"type":"video_url","video_url":{"url":...}}`（豆包、通义 VL、GLM-V 等）；Gemini 原生 `inline_data` / `file_data` 的 `video/*` | 与上游协议一致；模型须为 `video_understanding` 或在能力契约中声明 video |
| 音频理解 | `/v1/chat/completions` | `{"type":"input_audio","input_audio":{"data":...,"format":"wav"}}`；Gemini `audio/*` | 与上游协议一致；模型须为 `audio_understanding` 或在契约中声明 audio |
| 视频生成 | `/v1/videos/generations` | JSON，`image_url` 为图生视频 | 异步，`202` + `task_id`，见第 5 节 |

说明：

- 上游返回的素材 URL 不会直接暴露给调用方；图片 `url` 指向 TokenHub 的 `/download/...`，过期后用 `GET /v1/assets/{id}/download-url` 重新获取。设置 `GATEWAY_MEDIA_INLINE_RESPONSE=false` 后只返回 `assets[]`。
- 图片按每张固定预算估算输入 token（`GATEWAY_MEDIA_TOKEN_ESTIMATE`，默认 1500），不再按 base64 长度估算；实际用量以上游返回为准。
- 脱敏策略不会改写 base64 媒体数据和媒体 URL（如带签名参数的对象存储链接）。
- 语音合成按字符计费（中文一个字算 1）；语音识别的音频秒数取自上游 `usage.seconds`、`duration` 或字幕最后时间戳。
- 只配置为 `vision_understanding` 的模型同样接收纯文本请求。
- 网关按请求内容识别输入模态：含视频片段的请求只路由到 `video_understanding` 模型（也接受图片），含音频输入的只路由到 `audio_understanding` 模型；模型能力契约（`capabilities.input_modalities`）有声明时以契约为准。不满足时返回 `422 MODEL_CAPABILITY_UNSUPPORTED` 并说明缺少哪种输入。`audio`、`modalities` 等语音输出参数不算音频输入。

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

### POST /v1/tasks/{id}:cancel

取消尚未开始的上游任务（火山方舟、MiniMax H3、百炼万相支持；运行中的任务会被上游拒绝）。成功后任务状态为 `failed`，`error_code` 为 `TASK_CANCELLED`，提交时的预扣全额退还。无法取消时返回 `409 TASK_NOT_CANCELLABLE`。

### 异步任务计费

异步任务（视频、异步生图、音乐）提交时按请求参数预扣，任务结束时结算：失败、取消、过期全额退还；成功按厂商实测用量（实际时长、输出 token、上游成本等）多退少补。结算结果在 `GET /v1/tasks/{id}` 的 `result.billing` 中：

```json
{"reason": "actual_usage", "adjusted": true, "charged": {"video_seconds": 10, "cost_amount": "1.00000000"}, "final": {"video_seconds": 6, "output_tokens": 108900, "cost_amount": "0.81780000"}, "cost_delta": "-0.18220000"}
```

`reason` 取值：`actual_usage`（按实测结算）、`refund_failed`、`refund_cancelled`、`refund_expired`、`no_measurement`（厂商未报告用量，保持预扣）。

### OpenAI Videos API

使用 OpenAI SDK（`client.videos.*`）的应用可以直接接入，后端是渠道配置的任意视频厂商：

| 接口 | 说明 |
| --- | --- |
| `POST /v1/videos` | 请求体同 `/v1/videos/generations`；另外 `seconds` 换算为时长，`size`（如 `1920x1080`）换算为比例和分辨率，`input_reference`（URL 或 data URI）作为首帧。返回 OpenAI video 对象，`id` 即任务 ID |
| `GET /v1/videos/{id}` | video 对象（`queued` / `in_progress` / `completed` / `failed`），未完成时顺带刷新上游状态；`seconds` 为实测时长 |
| `GET /v1/videos/{id}/content` | 视频文件；`?variant=thumbnail` 返回尾帧图片（有时） |
| `DELETE /v1/videos/{id}` | 未完成时取消任务，已完成时删除素材 |

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

### POST /v1/rag/search

直接检索当前 API Key 可读的知识库，不调用对话模型。适合自己组织提示词的智能体（例如先检索、再决定调用哪个模型）。检索引擎、权限绑定与网关 `rag` 参数及 MCP `search_knowledge_base` 工具相同。

**计量**：查询向量化会调用知识库配置的 embedding 模型。这些调用（包括本接口、MCP 检索和网关 `rag` 参数触发的检索）每次记一条用量，计入当前 API Key、用户和部门，按该 embedding 模型的价格计费，在 `/v1/usage/*`、`/v1/management/usage/*` 和后台用量中以 `capability=embedding`、`endpoint=rag:query-embedding` 出现。上游未返回用量时按查询长度估算（`usage_source=estimated`）。这些记录不扣减额度策略中的请求数或 token 额度。使用本地回退向量（未配置 embedding 模型）时不产生费用记录。

```bash
curl "${TOKENHUB_BASE_URL}/v1/rag/search" \
  -H "Authorization: Bearer ${TOKENHUB_API_KEY}" \
  -H "Content-Type: application/json" \
  -d '{"query": "差旅报销需要哪些材料", "knowledge_base_ids": ["kb_finance"], "top_k": 5}'
```

| 字段 | 说明 |
| --- | --- |
| `query` | 必填，1–2000 字符 |
| `knowledge_base_ids` | 可选，`kb_id` 列表（最多 20 个）；不传时检索 Key 的默认知识库 |
| `dataset_ids` | 可选，数据集 ID 列表（最多 50 个） |
| `top_k` | 可选，1–20，默认 5 |

查询在送往向量模型和写入检索日志之前，按与网关请求相同的出站脱敏策略处理（系统、部门、API Key 范围的策略生效；模型/厂商范围的策略因没有模型路由而不适用）：`replace` 策略会把手机号、邮箱、密钥等替换为 `****` 后再检索，响应中的 `query` 也是脱敏后的文本；`block` 策略命中或 `summary` 策略返回 `422 DESENSITIZATION_BLOCKED`。

未知字段、类型错误返回 `422 RAG_SEARCH_INVALID`，缺少 `query` 返回 `422 RAG_QUERY_REQUIRED`，无权访问指定知识库返回 `403 RAG_KB_FORBIDDEN`。响应：

```json
{
  "ok": true,
  "data": {
    "query": "差旅报销需要哪些材料",
    "retrieval_id": "rtr_xxx",
    "searched_datasets": 2,
    "hit_count": 1,
    "sources": [
      {"source_id": "src_1", "title": "差旅报销制度", "content": "……", "content_truncated": false, "score": 0.9123,
       "knowledge_base_id": "kb_finance", "dataset_id": "ds_1", "dataset_name": "财务制度", "source_uri": "policy.pdf#p3",
       "modality": "text", "retrieval_strategy": "hybrid"}
    ]
  }
}
```

单条 `content` 最多 6000 字符，超出时截断并置 `content_truncated: true`。`searched_datasets` 为 0 表示该 Key 没有绑定可读知识库。`retrieval_id` 可用于 `GET /v1/rag/retrievals/{retrieval_id}` 和 `POST /v1/rag/feedback`。

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

`POST /v1/chat/completions`、`POST /v1/responses` 和 `POST /v1/messages` 可以携带 `rag` 参数；其他接口（embeddings、rerank、moderations、旧版 completions、多模态）没有可注入的对话，携带 `rag` 会返回 `422 RAG_ENDPOINT_UNSUPPORTED`，需要检索时改用 `POST /v1/rag/search`。

Chat 请求示例：

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
| 422 | `MODEL_CAPABILITY_UNSUPPORTED` | 模型能力与接口不符（如用聊天模型调用 `/v1/rerank`） |
| 422 | `RAG_ENDPOINT_UNSUPPORTED` | 该接口不支持 `rag` 参数 |
| 422 | `RAG_SEARCH_INVALID` | `/v1/rag/search` 参数不合法 |
| 422 | `DESENSITIZATION_BLOCKED` | 内容命中阻断型脱敏策略 |
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
