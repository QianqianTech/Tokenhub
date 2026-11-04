# 运维：队列、监控指标、告警与数据留存

本文说明 TokenHub 后台进程的运行方式，以及怎样接入监控。涉及的配置项都在 `.env.example` 中有说明。

## 1. 队列与 worker

所有生产者仍然把作业投递到 `QUEUE_NAME`（默认 `tokenhub:jobs`），再由 `RedisClient::push()` 按作业类型分到子队列：

| 子队列 | 作业类型 |
|---|---|
| `<QUEUE_NAME>:usage` | `usage.write`（用量明细与聚合） |
| `<QUEUE_NAME>` | `webhook.deliver`、`rag.*` 向量化，以及其他未列出的作业 |
| `<QUEUE_NAME>:tasks` | `tasks.poll` / `tasks.expire` / `tasks.settle` / `tasks.cancel`、`openrouter.reconcile` |
| `<QUEUE_NAME>:export` | `export` |
| `<QUEUE_NAME>:maintenance` | `cleanup.*`、`logs.*`、`alerts.*`、`retention.*`、`usage.reconcile`、`usage.drift-check`、`catalog.*`、`rag.cleanup` / `rag.reconcile` / `rag.publish-versions` |

默认情况下，每个 worker 消费全部子队列，并优先处理 `usage`，所以已有部署不需要做任何改动。需要隔离时，可以把 worker 按队列分开运行：

```bash
php bin/queue-worker.php --queues=usage,default      # 计费与 Webhook
php bin/queue-worker.php --queues=export,maintenance # 导出与清理
```

也可以用环境变量 `QUEUE_WORKER_QUEUES` 指定。设置 `QUEUE_ROUTING=false` 后，所有作业回到同一个队列（与旧版本行为一致）。

- 空闲的 worker 会阻塞等待新作业（Redis 唤醒列表 `<queue>:notify`），不再每秒轮询；过期租约每 15～30 秒扫描一次（`QUEUE_REQUEUE_INTERVAL_SECONDS`）。
- 作业失败后进入 `<queue>:delayed`（有序集合），按 `QUEUE_BACKOFF_BASE_SECONDS × 2^(n-1)` 退避后重试，最长等待 `QUEUE_BACKOFF_MAX_SECONDS`。达到 `QUEUE_MAX_ATTEMPTS` 后进入死信队列 `QUEUE_FAILED_NAME`，并触发 `queue_dead_letter` 告警。未知作业类型会直接进入死信队列。
- 每个作业都携带投递它的请求的 `request_context`（`request_id`、`trace_id`）。worker 处理作业期间写的日志使用这两个 ID，因此一次网关请求可以从 API 一路追到用量写入和 Webhook。

## 2. 调度器

`bin/scheduler.php` 负责定时投递维护作业。除原有的清理作业外，新增：

| 作业 | 周期 | 用途 |
|---|---|---|
| `logs.rotate` / `logs.archive` / `logs.cleanup` | 1 小时 / 1 天 / 1 天 | 日志轮转、归档、删除过期归档 |
| `retention.purge` | 1 小时 | 数据库大表按天数分批清理（见第 5 节） |
| `retention.archive` | 1 天 | `usage_records` / `audit_logs` 先归档再删除，默认关闭 |
| `catalog.dry-run` | 1 天 | 所有启用的供应商做模型目录和价格同步的 dry-run，有差异时产生 `catalog_drift` 告警 |
| `usage.drift-check` | 1 小时 | 比对用量明细和小时聚合（含成本），有差异时产生 `usage_drift` 告警 |

调度器每分钟写入一次心跳 `operations:worker:scheduler`。`/ready` 和告警都会检查这个心跳。

## 3. 健康检查

- `GET /health`：只检查 PHP、MySQL 和 Redis。
- `GET /ready`：在 `/health` 的基础上，还检查迁移是否全部执行、queue worker 心跳和调度器心跳。另外会报告 Qdrant 和 rag-worker 的状态，但默认不把它们作为必需项。必需项由 `READINESS_REQUIRE` 配置（默认 `queue-worker,scheduler`）。响应中每一项都带有 `required` 字段。

## 4. Prometheus 指标

设置 `METRICS_TOKEN` 后，`GET /metrics` 会输出 Prometheus 文本格式的指标；未设置时，该地址返回 404。指标结果在 Redis 中缓存 `METRICS_CACHE_SECONDS` 秒（默认 10 秒）。

```yaml
scrape_configs:
  - job_name: tokenhub
    metrics_path: /metrics
    authorization:
      type: Bearer
      credentials: <METRICS_TOKEN>
    static_configs:
      - targets: ["tokenhub.example.com"]
```

注意：前置 Caddy 需要把 `/metrics` 转发给 PHP（与 `/ready` 的处理方式相同）。

| 指标 | 类型 | 说明 |
|---|---|---|
| `tokenhub_dependency_up{dependency}` | gauge | MySQL / Redis 是否可达 |
| `tokenhub_worker_up{name}` | gauge | queue-worker / scheduler / rag-worker 的心跳是否存在 |
| `tokenhub_queue_length{queue}` | gauge | 各子队列中待处理的作业数 |
| `tokenhub_queue_processing{queue}` | gauge | 已被领取、正在处理的作业数 |
| `tokenhub_queue_delayed{queue}` | gauge | 正在等待重试退避的作业数 |
| `tokenhub_queue_dead_letter` | gauge | 死信队列中的作业数 |
| `tokenhub_queue_dead_letter_total` | counter | 累计进入死信队列的作业数 |
| `tokenhub_provider_requests_total{provider}` / `_failures_total` | counter | 按厂商统计的请求数和失败数（来自日聚合） |
| `tokenhub_provider_latency_ms_bucket{provider,le}` | histogram | 延迟分布（100 / 300 / 1000 / 3000 / 10000 / 30000 / 120000 ms） |
| `tokenhub_provider_error_ratio_5m{provider}` | gauge | 最近 5 分钟的失败率 |

## 5. 告警

`alerts.evaluate`（每分钟执行一次）在原有告警之外新增了以下告警：

- `queue_dead_letter`：死信队列有增长。
- `worker_heartbeat_missing`：queue-worker 或调度器的心跳丢失；rag-worker 只在有 Python 作业等待时才检查。
- `usage_aggregation_backlog`：超过 10 分钟仍未聚合的用量行达到 `ALERT_USAGE_BACKLOG_COUNT`（默认 100）。
- `webhook_delivery_dead`：24 小时内有 Webhook 投递达到最终失败。
- `quota_nearly_exhausted`：日、周、月配额用量达到 `ALERT_QUOTA_RATIO`（默认 0.9）。

`provider_error_rate_high` 现在只统计真正到达厂商的失败（`failed` 和 `timeout`）。本地拒绝不计入，包括配额、限流、权限、客户端取消以及 `PROVIDER_REJECTED` 一类的 4xx。

## 6. 数据留存

`retention.purge` 每次按 `RETENTION_BATCH_SIZE` 行分批执行 `DELETE ... LIMIT`。每张表每轮最多执行 `RETENTION_MAX_BATCHES` 批，没删完的部分留到下一小时继续：

| 表 | 默认保留天数 | 保留不删的数据 |
|---|---|---|
| `usage_aggregates_minute` | 14 | — |
| `alert_events` | 180 | `open` 状态的告警 |
| `alert_notifications` | 90 | `pending` / `retrying` 状态的通知 |
| `external_webhook_deliveries` | 30 | `pending` 状态的投递 |
| `dashboard_share_access_logs` | 90（`SCREEN_ACCESS_LOG_RETENTION_DAYS`） | — |
| `rag_retrieval_logs` | 90 | — |

把某张表的天数设为 0，即关闭该表的清理。`usage_records` 和 `audit_logs` 是计费和审计的原始数据，默认不清理。如需清理，设置 `RETENTION_ARCHIVE_ENABLED=true`：系统会先把超过 `RETENTION_USAGE_RECORDS_DAYS` / `RETENTION_AUDIT_LOGS_DAYS` 天的数据写成 `RETENTION_ARCHIVE_PATH/<表>-<日期>.jsonl.gz`，写成功后再删除。

迁移 `2026_10_31_000020` 为上述按时间删除的语句补充了时间列索引（`audit_logs`、`external_api_events` 等）。

## 7. 对账与配额重建

```bash
php bin/usage-reconcile.php --drift-alert --hours=24      # 检查明细与聚合是否一致（含成本），并产生或解除告警
php bin/usage-reconcile.php --rebuild --from=... --to=... # 用明细重建聚合
php bin/usage-reconcile.php --rebuild-quota --dry-run     # Redis 数据丢失后，从 usage_records 重算当期配额计数
php bin/usage-reconcile.php --rebuild-quota [--policy=ID]
```

## 8. 时区

所有 MySQL 会话都会执行 `SET time_zone = '+00:00'`（PHP 和 Python RAG worker 都是如此），SQL 中统一使用 `UTC_TIMESTAMP()`，所以 `NOW()` 和 PHP 的 `gmdate()` 一致。因此，即使数据库服务器的时区不是 UTC，Webhook 重试、告警窗口和过期清理也不会再偏移。按业务日或业务月计算的逻辑使用 `BUSINESS_TIMEZONE`。
