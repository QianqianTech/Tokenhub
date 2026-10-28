# 11 日志、可观测性和审计开发总结

生成日期：2026-07-08

## 总结

11 号任务已完成日志、可观测性、告警和审计能力的后端闭环。当前实现覆盖独立 JSON Lines 日志文件、统一日志字段、写入前脱敏、日志中心查询、CSV 异步导出、日志保留策略、轮转、归档、清理、告警事件、审计写入封装和管理端资源入口。

本轮实现遵循现有 TokenHub 原生 PHP 架构，不引入额外框架。`Logger` 继续作为兼容门面，实际日志格式化、脱敏和落盘统一收口到 `LogService`，避免大面积改动既有调用点。

## 已交付范围

### 日志写入与字段规范

- `LogService` 支持按日志类型写入独立文件，当前标准类型包括 `caddy-access`、`caddy-security`、`gateway-access`、`provider`、`usage`、`audit`、`security`、`performance`、`worker`、`export`。
- 应用日志统一写入 `storage/logs/<log_type>.jsonl`，每行一个 JSON 对象。
- 标准字段包括 `timestamp`、`level`、`message`、`log_type`、`request_id`、`trace_id`、`user_id`、`department_id`、`api_key_id`、`provider_id`、`model_id`、`client_ip`、`status`、`latency_ms`、`error_code`。
- `Logger` 已委托给 `LogService`，现有 `App::logger()->info/error/warning/audit()` 调用自动使用统一日志能力。
- Caddy 原生日志在查询阶段被归一化：`ts` 映射为 `timestamp`，`request.remote_ip` 映射为 `client_ip`，`duration` 映射为 `latency_ms`，WAF `vars.waf_rule` / `vars.waf_action` 放入 `context`。

### 脱敏与防泄漏

- 所有应用日志写入前统一走 `LogService::redact()` 和现有 `Logger::sanitize()`。
- 敏感键名会被替换为 `****`，包括 Authorization、Cookie、API Key、key hash、password、secret、token、connection string 等。
- `prompt`、`response`、`input`、`output`、`body`、`messages`、`content` 等正文类字段不会完整落日志，只保留摘要元数据：`redacted`、`bytes`、`sha256`。
- Caddy 查询结果也会再次脱敏，避免原始日志中残留的请求头被后台查询或导出暴露。

### 保留、轮转、归档和清理

- `database/migrations/2026_07_08_000011_logs_observability_audit.sql` 增加并兼容化日志可观测性表结构。
- `log_retention_policies` 支持在线保留天数、归档保留天数、最小保留天数、压缩开关和加密开关。
- `log_archive_files` 记录归档文件元数据、源文件路径、归档路径、压缩/加密状态、大小和删除时间。
- `LogRetentionService` 支持按日期和按大小轮转日志，轮转后重新创建空的活动日志文件。
- 归档支持 gzip 压缩和现有 `Crypto` AES-GCM 加密，归档文件默认写入 `storage/logs/archive`。
- 到期清理只删除日志归档文件和归档元数据状态，不删除 `usage_records`、`usage_contents`、`audit_logs` 等业务明细和审计数据。
- `LogRetentionPolicyService` 强制审计和安全类日志不低于最小保留天数：`audit` / `security` 默认至少 180 天，`caddy-security` / `export` 默认至少 90 天。

### 查询、导出和权限

- 新增 `GET /admin-api/logs` 日志中心接口，复用管理端登录态和权限上下文。
- 支持按时间、日志类型、请求 ID、链路 ID、用户、部门、API Key、厂商、模型、状态、错误码过滤。
- `security_event` 支持按 WAF rule 或 action 查询 `caddy-security` 安全事件。
- `ExportService` 扩展 `logs` 导出过滤，支持日志类型、请求 ID、链路 ID、厂商、模型、错误码、安全事件等条件。
- 日志导出仍走异步 `export_tasks`，创建和下载链接生成会写审计。
- 导出范围写入 `filters._scope`，worker 脱离登录态执行时仍只能导出请求者授权范围内的数据。

### 告警事件

- 新增 `alert_events` 表，用于保存告警类型、级别、消息、元数据、状态和时间。
- `AlertService` 支持记录并脱敏告警事件。
- `alerts.evaluate` worker 分支覆盖：
  - WAF 命中异常。
  - 厂商错误率升高。
  - Redis 队列堆积。
  - 日志写入失败。
  - `system_settings.tls_certificates` 中配置的证书即将过期。
- 管理端资源已暴露 `alert-events`，可通过后台查询告警。

### 审计

- `AuditService` 继续封装数据库审计写入，并同步写入 `audit` JSONL 日志。
- 管理端配置、API Key、导出、内容查看、安全策略变更等已有操作继续写入 `audit_logs`。
- `audit_logs` 在通用 CRUD 中保持只读，普通管理员不能通过后台通用接口删除审计日志。
- `log_retention_policies` 写入仍走 `CrudService`，因此创建、更新、禁用会产生 `admin.create`、`admin.update`、`admin.disable` 审计记录。

### Worker 任务

`WorkerService` 已新增以下任务分支：

- `logs.rotate`：按日志类型或全部类型执行轮转。
- `logs.archive`：按 `log_retention_policies` 将过期在线日志压缩、加密并登记归档。
- `logs.cleanup`：清理超过归档保留期的归档文件，只更新 `log_archive_files.deleted_at`。
- `alerts.evaluate`：执行 WAF、厂商错误率、队列堆积、证书到期等告警评估。

## 管理端与资源入口

- `public/admin/assets/js/resources.js` 新增 `logs` 日志中心、`log-archive-files` 日志归档、`alert-events` 告警事件资源。
- `logs` 资源支持日志类型、请求 ID、链路 ID、用户、部门、API Key、厂商、模型、错误码和时间范围过滤。
- `log-retention-policies` 继续作为可配置资源暴露给超级管理员。
- `alert-events` 和 `log-archive-files` 为只读资源，避免后台误改运行记录。

## 主要文件

- `app/Services/LogService.php`
- `app/Services/LogRetentionService.php`
- `app/Services/LogRetentionPolicyService.php`
- `app/Services/AlertService.php`
- `app/Services/ExportService.php`
- `app/Services/WorkerService.php`
- `app/Services/AuditService.php`
- `app/Support/Logger.php`
- `app/Bootstrap/Kernel.php`
- `database/migrations/2026_07_08_000011_logs_observability_audit.sql`
- `public/admin/assets/js/resources.js`
- `tests/Services/LogServiceTest.php`
- `tests/Services/LogRetentionServiceTest.php`
- `tests/Services/AlertServiceTest.php`

## 验证结果

已在 Docker PHP/MySQL/Redis 环境完成验证：

```bash
docker compose exec -T php php bin/run-tests.php
docker compose exec -T php php bin/migrate.php
docker compose exec -T php php -r "require 'vendor/autoload.php'; TokenHub\Support\App::bootstrap('/srv/app'); (new TokenHub\Services\WorkerService())->handle(['type'=>'logs.rotate','log_type'=>'worker','max_size_bytes'=>1]); (new TokenHub\Services\WorkerService())->handle(['type'=>'logs.archive']); (new TokenHub\Services\WorkerService())->handle(['type'=>'logs.cleanup']); (new TokenHub\Services\WorkerService())->handle(['type'=>'alerts.evaluate']); echo 'worker observability jobs ok'.PHP_EOL;"
curl.exe -fsS http://localhost:8080/health
```

结果：

- 全量测试通过：`38 test file(s) passed.`
- 迁移幂等通过：`Applied 0 migration(s).`
- worker 可观测性分支通过：`worker observability jobs ok`
- 健康检查返回 `status: healthy`，MySQL 和 Redis 均为 `ok`。

## 深度复核发现并补齐的问题

任务首次实现后又做了一轮对照复核，补齐了以下弱项：

- Caddy JSONL 查询结果没有统一字段映射，已补 `normalizeExternalRow()`。
- `caddy-security` 无法按安全事件过滤，已补 `security_event` 查询和导出过滤。
- 审计/安全日志的最小保留期只存在于默认数据中，缺少写入时强制，已补 `LogRetentionPolicyService`。
- 证书即将过期告警有独立方法但没有接入 `alerts.evaluate`，已补 `system_settings.tls_certificates` 配置读取。

## 运维使用建议

- 生产环境应定时投递或调度 `logs.rotate`、`logs.archive`、`logs.cleanup`、`alerts.evaluate`。
- 建议将 `storage/logs` 和 `storage/logs/archive` 纳入磁盘容量监控。
- 如需启用证书到期告警，可在 `system_settings` 写入 `tls_certificates`，示例：

```json
{
  "threshold_days": 14,
  "certificates": [
    {
      "name": "gateway.example.com",
      "expires_at": "2026-08-01 00:00:00"
    }
  ]
}
```

## 已知边界

- 日志系统当前提供后台查询和 CSV 导出，不包含外部告警通知渠道。
- 归档使用应用层 gzip 和 `Crypto` 加密，密钥轮换不在本任务范围内。
- 当前本地 workspace 的 `.git` 不被 Git 识别为有效仓库，无法生成可靠的 git diff 或 commit 结论；交付状态以文件内容、迁移执行和测试结果为准。
