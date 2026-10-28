# 热门模型接入指南（厂商适配、计价规则、实时语音）

适用版本：2026-10-29 起。对应路线图任务 MM-001～MM-032。

客户端一律使用 TokenHub 的 OpenAI / Anthropic / Gemini 风格接口；TokenHub 按渠道配置把请求转换成各厂商自己的接口，并把结果统一返回。

## 1. 怎么配置一个渠道

1. 后台“厂商”新建渠道：名称、协议、基础地址、密钥。常用厂商可以点“常用厂商”按钮自动填地址。
2. 适配器按**基础地址的域名**自动选择；代理或私有部署地址需要在“默认请求头 JSON”里写 `{"tokenhub_adapter": "<适配器 ID>"}`。
3. 后台“模型”里为渠道添加模型：`上游模型名` 填厂商的模型 ID，`能力` 选对应能力（见第 2 节）。从厂商导入模型列表时，能力会按 `config/model_catalog.php` 的规则自动推断，可直接编辑该文件补充新的模型系列。

### 1.1 适配器一览

| 适配器 ID | 厂商 | 基础地址 | 支持能力 | 鉴权（密钥字段填什么） |
| --- | --- | --- | --- | --- |
| `kling` | 可灵 | `https://api-singapore.klingai.com` | 视频（4.0 omni / 3.0）、图片 | API Key；或 `AccessKey:SecretKey`（自动签 JWT） |
| `minimax` | MiniMax | `https://api.minimaxi.com`（国内）/ `https://api.minimax.io` | 视频（H3 v2）、图片 `image-01`、语音合成 `speech-2.8-*` | API Key |
| `dashscope` | 阿里云百炼原生 | `https://dashscope.aliyuncs.com` 或工作空间域名 | 万相视频 / 万相图片（异步）、CosyVoice、`qwen*-asr` | API Key |
| `vidu` | 生数 Vidu | `https://api.vidu.com` | 视频 | API Key（自动用 `Token` 前缀） |
| `zhipu` | 智谱 | `https://open.bigmodel.cn/api/paas/v4` | CogVideoX 视频（图片/文本走 OpenAI 兼容） | API Key |
| `xai` | xAI | `https://api.x.ai/v1` | Grok Imagine 视频；图片编辑自动转 JSON | API Key |
| `openrouter` | OpenRouter | `https://openrouter.ai/api/v1` | 视频、`/images` 生图（聊天仍走 OpenAI 兼容） | API Key |
| `bfl` | Black Forest Labs | `https://api.bfl.ai` | FLUX 3 / FLUX.2 生图（异步） | API Key（`x-key`） |
| `fal` | fal.ai | `https://queue.fal.run` | 视频 / 图片 / 音乐（模型 ID 如 `fal-ai/kling-video/v2/master/text-to-video`） | API Key（`Key` 前缀） |
| `replicate` | Replicate | `https://api.replicate.com/v1` | 视频 / 图片 / 音乐（模型 ID 如 `owner/name`） | API Token |
| `runway` | Runway | `https://api.dev.runwayml.com` | Gen-4.5 视频 | API Key |
| `luma` | Luma | `https://agents.lumalabs.ai/v1` | Ray 3.2 视频 | API Key |
| `tencent_hunyuan` | 腾讯混元生图 | `https://hunyuan.tencentcloudapi.com` | 图片（异步） | `SecretId:SecretKey`（TC3-HMAC 签名） |
| `google_vertex` | Vertex AI Veo | `https://us-central1-aiplatform.googleapis.com/v1/projects/<项目>/locations/us-central1/publishers/google/models` | 视频 | 服务账号 JSON 整段 |
| `elevenlabs` | ElevenLabs | `https://api.elevenlabs.io` | 语音合成、语音识别 Scribe、音乐 | API Key（`xi-api-key`） |
| `deepgram` | Deepgram | `https://api.deepgram.com` | 语音识别 | API Key（`Token` 前缀） |
| `ideogram` | Ideogram | `https://api.ideogram.ai` | 图片（模型 ID 如 `ideogram-4-5`） | API Key（`Api-Key`） |
| `stability` | Stability AI | `https://api.stability.ai` | 图片（`ultra` / `core` / `sd3.5-large`） | API Key |
| `volcengine_ark` | 火山方舟 | `https://ark.cn-beijing.volces.com/api/v3` | Seedance 视频（Seedream 走 OpenAI 兼容） | API Key |

协议为 `gemini` 的渠道（基础地址 `https://generativelanguage.googleapis.com`）提供 Gemini 原生接口；Kimi Code 订阅建议直接配置 `anthropic` 协议、基础地址 `https://api.kimi.com/coding`（国际版 `https://api.kimi.ai/coding`），TokenHub 原样转发。配置成 `openai` 协议时，TokenHub 会把 Claude Code / Codex 请求转换成 Chat Completions，并完整保留工具调用和思考内容。

自定义鉴权：在默认请求头 JSON 里写 `"tokenhub_auth"`，例如 `{"type":"header","name":"X-Key","prefix":""}`、`{"type":"query","name":"key"}`、`{"type":"jwt_hs256"}`、`{"type":"tc3_hmac","service":"hunyuan","region":"ap-guangzhou","version":"2023-09-01"}`、`{"type":"google_service_account"}`。凭据始终放在“密钥”字段，不要写进请求头 JSON。

## 2. 对外接口与统一参数

| 能力 | 接口 | 说明 |
| --- | --- | --- |
| 视频 | `POST /v1/videos/generations` → `GET /v1/tasks/{task_id}` | 返回 202 和 `task_id`；任务完成后视频复制到 TokenHub 存储，用 `/v1/assets/{id}/download-url` 下载 |
| 图片 | `POST /v1/images/generations`、`/v1/images/edits` | 同步厂商直接返回 `data[]`；异步厂商（FLUX、万相、可灵、混元）返回 202 和 `task_id` |
| 语音合成 | `POST /v1/audio/speech` | 所有厂商都返回音频二进制（MiniMax 的 hex、CosyVoice 的 URL 会自动转换） |
| 语音识别 | `POST /v1/audio/transcriptions`（multipart） | 返回 OpenAI 形态的 `{text}`；`stream=true` 的转写也能计费 |
| 音乐 | `POST /v1/music/generations` | 能力 `music_generation`；`duration` 秒数计入 `audio_seconds` |
| Gemini 原生 | `POST /v1beta/models/{model}:generateContent`、`:streamGenerateContent?alt=sse` | Google GenAI SDK 把 base URL 指向 TokenHub，API Key 放 `x-goog-api-key` |
| 实时语音 | `GET /v1/realtime?model=`（WebSocket） | 由 Go 实时网关提供，见第 5 节 |

鉴权头：`Authorization: Bearer`、`x-api-key`（Anthropic SDK / Claude Code）、`x-goog-api-key`（Google SDK）都可以，Gemini 路径还支持 `?key=`。

视频 / 图片的统一字段（TokenHub 自动映射到各厂商字段）：

| 字段 | 含义 |
| --- | --- |
| `prompt`、`negative_prompt` | 提示词 |
| `duration` | 秒数 |
| `resolution` | `720p`、`1080p`、`2k`… |
| `aspect_ratio`（或 `ratio`）、`size` | 画面比例、`1280x720` |
| `image`（或 `image_url`、`first_frame`） | 首帧 / 输入图片，URL 或 `data:` URI |
| `last_frame`（或 `end_image`） | 尾帧 |
| `reference_images` | 参考图列表 |
| `generate_audio` | 是否生成声音 |
| `seed`、`n` | 随机种子、数量 |
| `vendor_options` | 合并进厂商请求体，用于厂商特有参数；计费相关字段（model、duration/seconds、n/num_images、resolution/size/width/height、回调地址等）会被忽略，只能通过统一字段设置 |

## 3. 计价规则（`model_prices.pricing_rules`）

后台“价格”表单新增“计价规则 JSON”。规则叠加在价格版本上，按以下顺序生效：时段 → 上下文分档 → 按请求参数定价 → 图片/音频 token 单价；可选按上游成本加价。

DeepSeek V4.1 Flash（平时价为基础价，工作日高峰翻倍，节假日不算高峰）：

```json
{"time_windows": [{"name": "peak", "timezone": "UTC", "days": [1,2,3,4,5],
  "hours": ["01:00-04:00", "06:00-10:00"], "exclude_dates": ["2026-10-01"], "multiplier": "2"}]}
```

Claude Haiku 5.5（提示词超过 100K 时整单按高档价）：

```json
{"context_tiers": [{"name": ">100K", "min_input_tokens": 100001, "prices": {"input_token_price": "0.000002", "output_token_price": "0.00001"}}]}
```

视频按分辨率和有无声音定价（单价 = 每秒）：

```json
{"dimension_prices": [
  {"name": "1080p+audio", "match": {"resolution": "1080p", "generate_audio": true}, "prices": {"video_second_price": "0.42"}},
  {"name": "1080p", "match": {"resolution": "1080p"}, "prices": {"video_second_price": "0.28"}}]}
```

gpt-image（图片 token 与文本 token 分开计价）：

```json
{"modality_token_prices": {"image_input_token_price": "0.000008", "image_output_token_price": "0.00003"}}
```

聚合平台按上游成本加价（OpenRouter `usage.cost` 等；没有上游成本时仍用单价）：

```json
{"upstream_cost": {"enabled": true, "multiplier": "1.1"}}
```

时段的 `days` 是 ISO 星期（1 = 周一），`hours` 按 `timezone` 计算，可跨午夜（`"22:00-02:00"`）。每次计费的价格快照会记录命中的规则（`applied_rules`）。

目录价格：`GET /admin-api/providers/{id}/catalog-prices` 预览厂商目录价格与当前价格的差异；`POST` 同一路径、正文 `{"apply": true, "model_ids": [..]}` 才会写入新的价格版本。

## 4. 异步任务与回调

- 任务状态由后台 `tasks.poll` 定时轮询，客户端也可以调用 `POST /v1/tasks/{id}:refresh`。
- 当 `APP_URL` 是 https 且 `TASK_CALLBACKS_ENABLED=true` 时，支持回调的厂商（可灵、MiniMax、Vidu、OpenRouter、Replicate、fal、火山方舟）会收到 `{APP_URL}/v1/task-callbacks/{task_id}/{签名}`。回调只触发一次立即轮询，结果仍由 TokenHub 自己去厂商查询，所以伪造或重复的回调无法注入结果，也不会重复计费。
- 生成结果默认立刻复制到媒体存储（`MEDIA_PERSIST_REMOTE=true`，目录由 `MEDIA_PATH` / `MEDIA_STORAGE_PATH` 决定）。复制失败时保留上游地址并记录告警日志。存储驱动接口为 `TokenHub\Services\Media\MediaStorage`，可通过 `MediaStorageManager::register()` 增加对象存储驱动。
- 计费分两步：提交时按请求参数预扣（时长、分辨率、张数），保证任务运行期间额度有效；任务结束时结算（`TaskBillingService`）：
  - 失败、取消、过期：全额退还（用量和费用清零）。
  - 成功：按厂商实测用量重算（实际秒数、输出 token、张数、上游成本，记在任务结果的 `settled_usage` 中）；价格用提交时生效的价格，厂商没有报告的指标保持预扣值。
  - 结算会同步修正用量记录、分钟/小时/天聚合、费用聚合，以及提交时所在周期的额度计数器；每个任务只结算一次，并在 `usage_settlements` 留存记录，结果写入任务的 `result.billing`。
  - 用量记录由队列异步写入：任务先结束时，记录写入后再结算；worker 每分钟还会扫描一次遗漏的任务（`tasks.settle`）。
  - `TASK_BILLING_SETTLEMENT=actual`（默认）| `refund_only`（只退失败）| `off`；`TASK_BILLING_ALLOW_INCREASE=false` 时实测多于请求也不补扣。
- 未开始的任务可以用 `POST /v1/tasks/{id}:cancel` 取消（火山方舟、MiniMax H3、百炼万相），取消后全额退还；运行中的任务会被厂商拒绝（`409 TASK_NOT_CANCELLABLE`）。

## 5. 实时语音（Go 实时网关）

- 组件：`realtime-gateway/`（只用 Go 标准库，可离线构建）。Docker 部署新增 `realtime-gateway` 服务；源码安装时若有 Go 1.22+ 会自动编译并启用 `tokenhub-realtime.service`。
- 配置：`REALTIME_INTERNAL_TOKEN`（TokenHub 与网关共享，安装器自动生成）、`REALTIME_MAX_SESSION_SECONDS`、`REALTIME_TRUSTED_PROXIES`（默认回环与私有网段；只有来自这些地址的 `X-Forwarded-For` 才被采信，用于 API Key 的 IP 白名单）。网关通过 Caddy 的内部监听 `:8082`（不对外发布）调用 `/internal/realtime/*`。
- 模型：能力选 `realtime`，例如 OpenAI `gpt-realtime-2.1`、`gpt-realtime-2.1-mini`。非 OpenAI 地址可在默认请求头 JSON 写 `"tokenhub_realtime_url": "wss://…?model={model}"`；协议为 `gemini` 的渠道按 Gemini Live 的 `usageMetadata` 计量。
- 客户端：服务端程序用 API Key 连接 `wss://<TokenHub>/v1/realtime?model=gpt-realtime`；浏览器先用 API Key 调 `POST /v1/realtime/client_secrets`（`{"model": "gpt-realtime"}`）换取一次性 `ek_` 令牌（默认 60 秒），再以子协议 `openai-insecure-api-key.<ek_…>` 连接。
- 计量：每个 `response.done` 记一条用量（输入/输出/缓存/音频 token），超出额度时网关以 4002 关闭连接。

## 6. 升级已有安装

`scripts/multimodal-upgrade.sh` 一次完成上线前的三件事，最后自动检查：

| 安装方式 | 命令 | 做什么 |
| --- | --- | --- |
| Docker 部署包（`deploy/docker/.env`） | `scripts/multimodal-upgrade.sh --mode docker`（离线镜像加 `--tag 版本`） | 补 `REALTIME_INTERNAL_TOKEN`，然后调用 `deploy/docker/upgrade.sh`：备份、构建镜像、init 容器跑迁移、启动 realtime-gateway |
| 源码安装（`/etc/tokenhub/tokenhub.env`） | `sudo scripts/multimodal-upgrade.sh --mode native` | 补令牌；缺 Go 1.22+ 时下载（`--no-go-install` 关闭）；调用 `deploy/linux/upgrade.sh`：备份、迁移、编译网关、启用 `tokenhub-realtime.service`、重载 Caddy |
| 开发环境（`docker-compose.yml` + `.env`） | `scripts/multimodal-upgrade.sh --mode dev` | 补令牌，mysqldump 备份到 `storage/backups/`，重建 php 与网关容器，跑迁移，重启 Caddy |

检查项：
- 迁移 `2026_10_29_000001`（`model_prices.pricing_rules` 列）和 `2026_10_29_000002`（能力 ENUM 增加 `music_generation`、`realtime`）已执行。
- 网关 `/healthz` 正常。
- 内部接口 `:8082` 接受正确令牌、拒绝错误令牌。
- `/v1/realtime` 经 Caddy 转到网关。

其他用法：`--dry-run` 只打印计划不做改动，`--verify-only` 只跑检查。令牌已存在时保留原值，脚本可重复执行。

## 7. 未实测项

以下内容依据厂商公开文档实现，未在真实账号上调用过，上线前请用 `scripts/provider-acceptance.php` 或小额请求验证：

- 腾讯 TC3 签名、Vertex Veo（请求字段与轮询方式）、Google 服务账号令牌交换。
- Luma 创建接口路径、OpenRouter 视频 `urls` 字段结构、Runway 完整状态列表、fal / Replicate 各模型的输入字段。
- 火山方舟 Seedance 2.5 模型 ID、MiniMax 语音默认音色、`qwen3-asr-flash` 的 `input_audio` 结构、ElevenLabs 音乐请求字段。
- Gemini Live 的 `usageMetadata` 是否为单轮增量（目前按单轮上报）。
- 尚未适配：PixVerse、腾讯 MPS 视频、阿里云百炼文件转写（需要公网可访问的音频地址）、AssemblyAI、火山语音（文档无法读取）、Suno（无官方 API）、Midjourney（无官方 API）。
