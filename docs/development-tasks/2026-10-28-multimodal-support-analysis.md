# TokenHub 多模态支持深度分析与修复报告（2026-10-28）

范围：模型网关对文本、图片（理解 / 生成 / 编辑）、音频（识别 / 翻译 / 合成）、视频（生成）的支持；目标是接口按 OpenAI / Anthropic 协议正常工作。

## 1. 支持矩阵（修复后）

| 能力 | 对外接口 | 上游协议 / 适配器 | 状态 |
| --- | --- | --- | --- |
| 文本对话 | `/v1/chat/completions`、`/v1/responses`、`/v1/messages`（含流式、工具调用、count_tokens） | OpenAI、Anthropic、Kimi Code 桥接 | 正常 |
| 向量 | `/v1/embeddings` | OpenAI | 正常 |
| 图片理解 | 上述三个文本接口中的 `image_url` / `input_image` / Anthropic `image` 块 | OpenAI、Anthropic | 已修复 3 个问题，正常 |
| 图片生成 | `/v1/images/generations` | OpenAI 兼容（含 Ark Seedream） | 已修复（之前返回中没有图片） |
| 图片编辑 | `/v1/images/edits`（新增） | OpenAI 兼容 multipart | 新增 |
| 语音合成 | `/v1/audio/speech` | OpenAI 兼容 | 已修复计费、JSON 模式 |
| 语音识别 | `/v1/audio/transcriptions` | OpenAI 兼容 multipart | 已修复 text/srt/vtt 和计费 |
| 语音翻译 | `/v1/audio/translations`（新增） | OpenAI 兼容 multipart | 新增 |
| 视频生成 | `/v1/videos/generations` + `/v1/tasks/{id}` + `/v1/assets/{id}/download-url` | 仅火山引擎 Ark / Seedance（异步任务） | 正常；其他厂商见第 4 节 |

## 2. 发现并已修复的问题

| # | 严重度 | 现象 | 根因 | 修复 |
| --- | --- | --- | --- | --- |
| 1 | 高 | 图片生成返回的 `data[]` 里既没有 `url` 也没有 `b64_json`，OpenAI SDK 拿不到图片 | `publicProviderResult()` 删除了所有媒体字段 | `data[i].url` 改为 TokenHub 短期下载链接（默认 1 小时），`b64_json` 原样返回；`assets[]` 保留；`GATEWAY_MEDIA_INLINE_RESPONSE=false` 可恢复旧行为 |
| 2 | 高 | 带 base64 图片的视觉请求被误判超额（`QUOTA_EXCEEDED`），预占额度可达几十万 token | 输入 token 按整个 JSON 长度 / 4 估算，base64 被当成文本 | 新增 `MediaPayload`：每个媒体部分按固定预算估算（`GATEWAY_MEDIA_TOKEN_ESTIMATE`，默认 1500） |
| 3 | 高 | 手工创建的 `vision_understanding` 模型收到纯文本请求返回 422 | 路由只匹配 `capability = text_generation`，而 `capability_tags` 只在同步目录时写入 | 文本请求同时匹配视觉模型 |
| 4 | 高 | 启用脱敏策略后，带签名的图片 URL、base64 被改写，上游取图失败 | 脱敏正则（`token=`、11 位手机号）作用于所有字符串 | 脱敏跳过 base64 媒体和媒体 URL；正文中的手机号、邮箱、token 仍会脱敏 |
| 5 | 高 | 语音识别 `response_format=text/srt/vtt` 返回 `{"raw": "..."}` JSON | 非 JSON 响应被统一包装 | 原样返回文本和上游 Content-Type |
| 6 | 中 | 语音识别计费秒数恒为 0 | 只读取 `usage.audio_seconds`，上游实际返回 `usage.seconds` / `duration` | 读取 `usage.seconds`、`duration`，字幕格式取最后时间戳，向上取整 |
| 7 | 中 | 中文 TTS 按 3 倍计费 | `strlen` 按 UTF-8 字节计数 | 改为 `mb_strlen` |
| 8 | 中 | TTS 的 JSON 模式（`X-TokenHub-Response-Mode: json`）不含音频 | 同 #1 | JSON 模式返回 `b64_json` |
| 9 | 中 | Anthropic PDF 文档块被当成图片，要求视觉能力并按图片计费 | 识别条件包含 `source.media_type` | 只识别 `type=image` |
| 10 | 中 | 下载的生成图片 Content-Type 为 `application/octet-stream` | 以 URL 存储的素材没有 MIME | 使用上游 Content-Type，取不到时按内容识别 |
| 11 | 中 | 签名 URL 超过 800 字符时写入素材表失败（上游已扣费） | `media_assets.storage_path VARCHAR(800)` | 迁移 `2026_10_28_000003` 扩到 2048 |
| 12 | 低 | 额度错误信息显示为 `total_[redacted]s exceeded` | 错误信息脱敏正则误伤 `tokens` | 只匹配完整单词 |
| 13 | 低 | multipart 数组字段（如 `timestamp_granularities[]`）被丢弃，`image[]` 多图被拒绝 | 编码时跳过数组 | 数组字段和多文件都按原字段名转发 |
| 14 | 低 | `bin/run-tests.php` 中 `GatewayFlowTest` 失败 | 同进程运行时配置已缓存，测试改的环境变量不生效 | 测试内重新加载配置并在结束时恢复 |

新增接口 `/v1/images/edits`、`/v1/audio/translations` 已同步到路由、限流 / 幂等判断、`/v1/capabilities`、`openapi.json` 和 `docs/external-api-complete.md`（4.1 节）。

## 3. 验证

- `tests/Unit/MultimodalGatewayFlowTest.php`（新增）：使用假上游 `tests/fixtures/upstream/multimodal.php` 端到端覆盖文本、视觉（Chat / Responses / Anthropic）、脱敏、向量、图片生成（url / b64）、图片编辑、TTS（二进制 / JSON）、ASR（json / verbose_json / text / srt）、翻译、非音频拒收、视频异步任务（提交 → 刷新 → 素材 → 下载）。修复前有 27 项断言失败，修复后全部通过。
- `tests/Unit/MediaPayloadTest.php`（新增）：媒体估算、脱敏边界、multipart 编码。
- 使用 `php -S` 和真实 HTTP（curl 发送 multipart、读取二进制和文本响应、下载链接）验证了同样的流程。
- 全量 `bin/run-tests.php`：修复前后对比没有回退；剩余失败均因为沙箱里没有 MySQL / Qdrant 或没有复制 docs 目录，与本次修改无关。**需要在有 MySQL 的 Docker 环境中再跑一次全量测试。**

## 4. 尚未支持 / 建议的后续工作

1. **视频只支持火山引擎 Ark。** 其他协议的视频任务无法刷新状态，会在 24 小时后标记为 `TASK_TIMEOUT`。如需要，可按厂商补适配器：OpenAI Sora（`/v1/videos`）、阿里云百炼万相（DashScope 异步任务）、可灵、MiniMax 海螺、Google Veo。
2. **视频理解 / 音频输入未识别。** `video_url`、`input_audio` 按普通文本路由，不检查模型能力。需要时在 `ModelCapabilityContract::requirements()` 中增加 `video` / `audio` 输入模态。
3. **生成的素材没有落盘。** 以 URL 存储的图片和视频在下载时才从上游获取，上游链接过期后（OpenAI 约 1 小时，Ark 24 小时）就无法下载。建议由 worker 在任务完成后把素材保存到本地或对象存储。
4. **语音识别无法预检音频秒数额度。** 请求前不知道音频时长，`audio_second_limit` 只在请求结束后计入；`text` 格式没有时长信息，无法计费。
5. **图片流式输出、TTS 流式输出（`stream_format: sse`）、Realtime API** 目前没有专门处理。
6. 视觉请求的 `image_count` 也会乘以模型的 `image_price`。同一模型不要同时配置图片单价和视觉能力。
7. 响应中的 `model` 是上游模型 ID，没有替换为 TokenHub 中的逻辑名称。
8. 高质量图片生成可能超过 60 秒。图片和视频渠道的超时时间建议设为 180 秒以上。

## 5. 管理员配置要点

- 视觉模型的能力设为 `vision_understanding`（修复后也能处理纯文本请求）。
- 火山引擎渠道的 Base URL 填 `https://ark.cn-beijing.volces.com/api/v3`，或在默认请求头中加 `{"tokenhub_adapter":"volcengine_ark"}`。
- 新增可选环境变量：`GATEWAY_MEDIA_INLINE_RESPONSE`（默认 true）、`GATEWAY_MEDIA_URL_TTL_SECONDS`（默认 3600）、`GATEWAY_MEDIA_TOKEN_ESTIMATE`（默认 1500）、`GATEWAY_MAX_UPLOAD_FILES`（默认 16）。
- 上传限制：单个文件 25 MiB（`GATEWAY_MAX_UPLOAD_BYTES`），Caddy 和 PHP 的请求体上限为 32 MB。
