# 平台完善改造：配置项与升级说明

适用版本：2026-10-28 平台完善改造（任务清单 `docs/development-tasks/2026-10-28-platform-completeness-audit.md`）。

## 升级步骤

1. 备份数据库与 Redis（见 `backup-restore.md`）。
2. 部署代码后执行迁移：`php bin/migrate.php`。新迁移文件为
   `database/migrations/2026_10_28_000002_foundation_platform_completeness.sql`，可重复执行。
   未执行迁移时，新代码按旧行为运行（`SchemaInspector` 检测列是否存在），但分阶段记账、能力合同、
   告警去重、成本分币种聚合等功能不会生效。
3. 生产环境确认 `APP_ENV=production` 且 `APP_KEY` 为至少 32 位的随机值，否则进程启动即失败。
4. 如有内网推理服务（vLLM、Ollama 等），按下文“出站策略”显式放行，否则调用返回 `OUTBOUND_BLOCKED`。
5. 执行 `php bin/rotate-app-key.php --dry-run` 查看存量明文敏感头部数量；确认后去掉 `--dry-run` 完成加密。
6. 运行 `php bin/run-unit-tests.php`（无需 MySQL）与完整测试 `php tests/run.php`（需要 MySQL/Redis）。

## 行为变化（需要评估的兼容性影响）

| 变化 | 原行为 | 新行为 | 回退方式 |
| --- | --- | --- | --- |
| 任务/媒体/文件访问范围 | 同部门可互相访问 | 默认同一用户可访问 | `ASSET_SHARING_SCOPE=department` |
| 生产主密钥 | 缺失时用开发默认值 | 启动失败 | 配置正式 `APP_KEY` |
| 私有网络上游 | 直接访问 | 需显式放行 | `OUTBOUND_PRIVATE_ALLOWLIST` 或供应商 `tokenhub_allow_private_network` |
| native 协议渠道 | 返回固定“已接受”内容或只入库 queued | 返回 501 `CAPABILITY_UNAVAILABLE` | 改用 OpenAI/Anthropic 兼容渠道 |
| `/v1/audio/speech` | base64 JSON 包装 | 直接返回音频二进制 | 请求头 `X-TokenHub-Response-Mode: json` |
| 输出预算 | 只预留输入 | 预留输入 + 声明的 max_tokens（未声明时默认 1024） | `GATEWAY_DEFAULT_OUTPUT_RESERVE_TOKENS` |
| 流式 usage | 依赖上游自发 | 自动加 `stream_options.include_usage=true` | `GATEWAY_FORCE_STREAM_USAGE=false` |
| 聚合平台参数 | `provider/models/plugins` 透传 | 默认拒绝，需供应商策略放开 | 供应商 `tokenhub_client_routing=restricted` |
| 未知队列任务 | 记日志后确认 | 进入失败队列 | — |
| Anthropic 计费口径 | input 仅为非缓存输入 | input 含缓存读写（与 OpenAI 一致），缓存可单独定价 | 配置 `cache_read_token_price` 等 |
| 有历史用量的模型/供应商删除 | 物理删除价格、授权 | 标记 `retired`，保留价格版本，撤销授权 | — |
| 供应商/模型保存 | 仅前端校验 | 服务端校验地址、协议、头部、同名模型能力冲突；可带 `expected_updated_at` 防并发覆盖 | — |
| 上游拒绝请求（400/404/413/422） | 统一返回“Upstream provider rejected the request” | 透传经过脱敏的上游 `message`、`type`、`param`，上游 `code` 放在 `upstream_code`；`/v1/messages` 使用 Anthropic 错误格式（`"type":"error"`）。账号、计费、密钥类消息和 401/403/429/5xx 仍是通用文案 | — |
| 流式请求在首字节前失败 | HTTP 200 + `event: error` | 返回真实状态码和 JSON 错误；已输出数据后仍用 `event: error` | — |
| 上游超时 | 连接与读取共用渠道 `timeout_ms` | 连接超时单独 5s，读取空闲超时仍为 `timeout_ms`，单次上游交互最长 1800s | `GATEWAY_UPSTREAM_CONNECT_TIMEOUT_MS`、`GATEWAY_UPSTREAM_MAX_DURATION_SECONDS` |
| `X-Forwarded-For` 解析 | 取最左侧地址（客户端可伪造） | 从右向左取第一个非受信地址 | — |
| 无效 API Key | 每次查库 | 未命中结果缓存 30s；同一地址无效认证超限后，未缓存的 Key 直接 429，不再查库（已缓存的有效 Key 不受影响） | — |
| 用户可见错误码 | 无候选统一 `MODEL_FORBIDDEN` 403 | 区分 `MODEL_NOT_FOUND` 404、`MODEL_FORBIDDEN` 403、`MODEL_UNAVAILABLE` 503、`MODEL_CAPABILITY_UNSUPPORTED` 422 | — |

## 环境变量

| 变量 | 默认 | 说明 |
| --- | --- | --- |
| `APP_KEY_ID` | `k1` | 新密文写入的密钥编号 |
| `APP_PREVIOUS_KEYS` | 空 | 轮换期间仍可解密的旧密钥，`k1:旧密钥,k0:更旧密钥` |
| `APP_HASH_KEY` | 空（=APP_KEY） | 固定 API Key/会话哈希密钥，轮换 APP_KEY 前设为旧 APP_KEY |
| `ASSET_SHARING_SCOPE` | `user` | `api_key` / `user` / `department` |
| `OUTBOUND_PRIVATE_ALLOWLIST` | 空 | 允许访问的内网主机名、`.后缀` 或 CIDR，逗号分隔 |
| `OUTBOUND_DOWNLOAD_MAX_BYTES` | 104857600 | 远程资产下载上限 |
| `OUTBOUND_DOWNLOAD_TIMEOUT_SECONDS` | 30 | 远程资产下载超时 |
| `GATEWAY_DEFAULT_OUTPUT_RESERVE_TOKENS` | 1024 | 未声明 max_tokens 时的输出预留 |
| `GATEWAY_UNKNOWN_PRICE_POLICY` | `allow` | 有费用限额但无价格时：`allow` 记为未知，`deny` 拒绝（402 PRICE_UNKNOWN） |
| `GATEWAY_MAX_CHANNELS_PER_REQUEST` | 2 | 单请求最多尝试的渠道数（含首个） |
| `GATEWAY_CIRCUIT_FAILURES` | 5 | 连续失败多少次熔断 |
| `GATEWAY_CIRCUIT_COOLDOWN_SECONDS` | 30 | 熔断冷却时间 |
| `GATEWAY_FORCE_STREAM_USAGE` | `true` | Chat 流式自动请求 usage 帧 |
| `GATEWAY_STREAM_RETAIN_MAX_BYTES` | 1048576 | 流式输出留存文本上限 |
| `GATEWAY_RESERVATION_LEASE_SECONDS` | 900 | 预留租约默认时长（进程异常回收） |
| `GATEWAY_MAX_UPLOAD_BYTES` | 26214400 | multipart 单文件上限 |
| `PROVIDER_RETRY_MAX_BACKOFF_MS` | 2000 | 上游重试最大退避 |
| `GATEWAY_UPSTREAM_CONNECT_TIMEOUT_MS` | 5000 | 上游连接（TCP+TLS）超时，不超过渠道 `timeout_ms` |
| `GATEWAY_UPSTREAM_MAX_DURATION_SECONDS` | 1800 | 单次上游交互（含长流式）最长时长，0 为不限 |
| `GATEWAY_UPSTREAM_TRANSPORT` | `curl` | `curl` 或 `stream`（PHP 流封装，无独立连接超时）；缺少 ext-curl 时自动用 `stream` |
| `GATEWAY_UPSTREAM_PROXY` | 空 | 上游调用的出站代理；不读取 `http(s)_proxy` 环境变量 |
| `RAG_CONTEXT_MAX_CHARS` | 12000 | 注入知识上下文字符预算 |
| `CONTENT_RETAIN_MAX_BYTES` | 262144 | 单条输入/输出留存上限 |
| `BILLING_BASE_CURRENCY` | `USD` | 聚合 `total_cost` 只累计该币种 |
| `ALERT_WEBHOOK_URLS` | 空 | 告警通知 Webhook，逗号分隔 |
| `ALERT_WEBHOOK_ALLOW_PRIVATE` | `false` | 允许告警 Webhook 指向内网 |
| `ALERT_NOTIFY_MAX_ATTEMPTS` | 5 | 通知最大重试次数 |
| `ALERT_ESCALATE_AFTER_MINUTES` | 30 | 未确认告警升级时间 |
| `TASK_STALE_MINUTES` | 30 | 无上游任务号的任务判定为无执行器 |
| `TASK_MAX_AGE_HOURS` | 24 | 异步任务最长存活时间 |
| `REDIS_IMAGE` / `QDRANT_IMAGE` | `latest` | 开发 compose 镜像固定版本 |

## 供应商控制项（`providers.default_headers`，不会发往上游）

| 键 | 说明 |
| --- | --- |
| `tokenhub_endpoint_map` | 端点映射，如 `{"/v1/chat/completions":"/llm/chat"}`，值为 null 表示不支持 |
| `tokenhub_unsupported_endpoints` | 明确不支持的端点列表 |
| `tokenhub_allow_private_network` | `true` 允许该供应商访问内网地址（元数据地址始终禁止） |
| `tokenhub_client_routing` | `deny`（默认）或 `restricted`（客户端只能收窄服务端策略） |
| `tokenhub_provider_policy` | 服务端强制的 OpenRouter `provider` 对象 |
| `tokenhub_allowed_plugins` | 允许的插件 id 列表 |
| `tokenhub_allow_auto_router` | `true` 允许 `openrouter/auto` 等自动路由模型 |

敏感头部（`Authorization`、`x-api-key`、`api-key`、`x-goog-api-key` 等）保存时加密为 `enc:...`，
后台显示为 `****`；提交 `****` 保留原值，提交空字符串删除该头部。

## 新增运维命令

| 命令 | 用途 |
| --- | --- |
| `php bin/run-unit-tests.php` | 行为测试（无需 MySQL） |
| `php bin/rotate-app-key.php [--dry-run]` | 主密钥轮换后重新加密 |
| `php bin/queue-replay-failed.php --list / --replay` | 查看、重放失败队列 |
| `php bin/usage-reconcile.php --resume / --check / --rebuild / --openrouter` | 用量恢复、对账、重建聚合、OpenRouter 补账 |
| `php scripts/provider-acceptance.php --models=...` | 具体模型的接入验收证据 |

## 新增管理接口

| 接口 | 说明 |
| --- | --- |
| `POST /admin-api/api-keys/{id}/rotate` | Key 轮换，旧 Key 在宽限期后失效（`grace_minutes`） |
| `GET /admin-api/quota-status?api_key_id=` | 额度用量、在途预留与剩余 |
| `POST /admin-api/quota-adjustments` | 审计化的额度调整（需原因） |
| `GET /admin-api/diagnostics/requests/{request_id}` | 请求诊断：渠道、尝试、用量来源、错误解释与处理建议 |
| `POST /admin-api/providers/{id}/catalog-sync` | 模型目录同步预览（`apply=true` 才写入） |
| `POST /admin-api/alerts/{id}/status` | 告警确认、解决、静默（`silence_until`） |
| `POST /admin-api/chat-session/messages` + `api_key_id` | 以指定 Key 走正式网关链路试聊 |
