# 14 对外 API P0/P1 修复总结

## 1. 目标与范围

本轮对 TokenHub 对外 API 完成 P0/P1 安全、可靠性和接入契约加固，覆盖 API Key 入口、模型网关配额、同步、文件、Webhook、异步用量与事件流。

普通用户仍只通过 API Key 使用对外能力；没有新增普通用户 Web 登录入口。

## 2. P0：安全与数据正确性

### 2.1 可信客户端 IP 与限流

- 新增 `TrustedProxyService`：只有 `REMOTE_ADDR` 位于配置的可信代理 CIDR 时，才使用 `X-Forwarded-For` 的首个合法 IP；直连请求不能伪造来源 IP。
- 新增 Redis 固定窗口限流：默认无效鉴权 `30/IP/分钟`、模型调用 `600/API Key/分钟`、其他 `/v1` API `120/API Key/分钟`。
- 限流返回 `429 RATE_LIMITED` 和 `Retry-After`；阈值可通过 `EXTERNAL_API_*_PER_MINUTE` 环境变量调整。

### 2.2 写操作幂等

- 新增 `api_idempotency_records`。
- 外部非模型写操作支持可选 `Idempotency-Key`；相同 API Key、方法、路径和请求体会重放初始响应，不会再次执行业务逻辑。
- 同一个幂等键用于不同请求体时返回 `409 IDEMPOTENCY_CONFLICT`。
- 模型网关和流式接口不进入响应缓存，避免错误重放 SSE。

### 2.3 Webhook 出网与可靠重试

- Webhook 仅允许 HTTPS 且目标必须解析为公网 IP；拒绝私网、回环、保留地址以及 URL 中的账号密码。
- 禁用 HTTP 重定向，防止公网 URL 跳转到内网。
- 投递通过数据库条件更新领取 5 分钟租约，重复队列消息不会造成重复发送。
- 失败后只在 `next_attempt_at` 到期后重试；空闲 Worker 会扫描并入队到期投递。

### 2.4 同步并发正确性

- 新增 `user_sync_sequences`，以每用户原子递增序列取代 `MAX(sync_version)+1`。
- 记录更新与删除使用 `sync_version` 条件写入；竞争写入和旧版本删除返回 `SYNC_CONFLICT`，不再静默覆盖。
- 提供 `device_id` 的同步写入会校验设备属于当前用户且状态为 active。

### 2.5 原子配额预留与结算

- 新增 `RedisClient::reserveCounters()` Lua 原子操作：先检查全部计数器上限，再一次性预留请求、token、成本和并发指标。
- 通用配额与 `API Key × 模型` 配额均使用同一预留机制。
- 上游返回后以实际用量补差；上游失败时释放 token/cost 预留，保留请求计数。

## 3. P1：可靠性、生命周期与可观测性

### 3.1 文件分页与删除

- `GET /v1/assets` 支持 `cursor=id_<id>`、`next_cursor` 和 `has_more`。
- 删除外部上传文件时，确认路径属于 TokenHub 受控外部媒体目录后同步物理删除；上游资产和远程 URL 不会被误删。

### 3.2 Webhook secret 生命周期

- 新建 secret 以 AES-GCM 应用层加密存储。
- 新增 `POST /v1/webhooks/{id}:rotate-secret`；新 secret 仅在轮换响应中返回一次。
- 旧 secret 保留 24 小时。重叠期内投递同时包含新 `X-TokenHub-Signature` 与旧 `X-TokenHub-Signature-Previous`。

### 3.3 持久化事件 Outbox

- 新增 `external_api_events`，按 API Key 和单调递增 ID 存储事件。
- 同步操作、网关用量写入、Webhook 投递结果均写入 outbox。
- `GET /v1/events` 改为稳定 `evt_<id>` cursor 分页，不再使用四张表拼接和秒级时间戳 cursor。
- 新增 `cleanup.external-events` Worker 任务；默认保留 90 天，可通过 `EXTERNAL_EVENT_RETENTION_DAYS` 调整。

### 3.4 接入文档与 OpenAPI

- 中文接入文档明确同步数据以用户为共享边界、异步用量最终一致性、文件 cursor、outbox cursor 和 Webhook 双签名轮换。
- `public/openapi.json` 已补充事件/文件 cursor、Webhook `:rotate-secret`、429 `Retry-After` 以及 `Idempotency-Key` 定义。

## 4. 数据库迁移

| 迁移 | 用途 |
| --- | --- |
| `2026_07_10_000001_external_api_p0_security.sql` | 幂等记录与 P0 基础结构 |
| `2026_07_10_000002_external_api_p0_sync.sql` | 同步版本序列 |
| `2026_07_10_000003_external_api_p1_webhook_secrets.sql` | Webhook 旧 secret 重叠期字段 |
| `2026_07_10_000004_external_api_p1_webhook_secret_width.sql` | 加密密文列扩容 |
| `2026_07_10_000005_external_api_event_outbox.sql` | 持久化外部事件 outbox |

部署后执行：

```bash
docker compose exec -T php php bin/migrate.php
```

## 5. 验证证据

新增或增强的关键回归覆盖包括：

- 可信代理 IP、API 限流；
- 幂等持久化重放；
- Webhook SSRF、到期租约重试和 secret 轮换兼容；
- 同步版本与冲突行为；
- Redis 原子配额预留；
- 文件 cursor；
- outbox 事件稳定分页。

最终验证命令：

```bash
docker compose exec -T php php bin/migrate.php
docker compose exec -T php php tests/run.php
```

本轮最终全量 PHP 测试通过，迁移状态为 `Applied 0 migration(s).`。

## 6. 运维注意事项

- 生产环境必须配置正确的 `TRUSTED_PROXY_CIDRS`，仅包含实际反向代理地址。
- 连续 Worker 必须运行，负责用量落库、Webhook 到期重试和 outbox 清理。
- 客户端应保存 `next_cursor`；不要再依赖时间戳对事件去重。
- 对可重试写操作传入 `Idempotency-Key`；模型流式调用按客户端协议自行处理重试。
- outbox 默认不回填历史四表事件。如需历史审计，应使用既有 usage/sync/webhook 表或单独离线导出。
