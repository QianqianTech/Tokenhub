# 07 内容留存、会话存档和导出开发总结

## 总结

07 任务已完成后端、队列、管理端 UI、迁移、文档和测试闭环。当前实现覆盖请求/响应正文采集、内容留存策略、权限化归档查询、原文受控查看、CSV 异步导出、短期下载链接、导出文件过期清理和审计记录。

本轮复核中曾发现两个阻断项：迁移 runner 只扫描 `*foundation*.sql`，导致 07 迁移不会执行；部分兼容迁移使用了 MySQL 9.7 不支持的 `ADD COLUMN IF NOT EXISTS` / `ADD INDEX IF NOT EXISTS` 语法。已修正为扫描全部 SQL 迁移，并将兼容迁移改为 `information_schema + PREPARE` 的幂等写法。

## 已交付范围

### 数据与迁移

- 主 schema 已包含 `usage_contents` 和 `export_tasks` 的完整字段、索引和状态字段。
- 兼容迁移补齐旧库中的 `usage_contents.user_id/department_id/api_key_id/model_id/session_id/updated_at` 及查询索引。
- 兼容迁移补齐 `export_tasks.format/download_count/finished_at` 及导出查询索引。
- 兼容迁移补齐旧版 `audit_logs` 缺失的 `actor_user_id/trace_id/client_ip/details`，保证内容查看、原文查看、导出创建和下载链接生成可以正常写审计。
- `system_settings` 存储 `content_retention.policy` JSON，默认策略为：

```json
{
  "mode": "summary",
  "default_days": 30,
  "max_days": 180,
  "raw_allowed_roles": ["super_admin"],
  "departments": {},
  "users": {},
  "api_keys": {}
}
```

### 后端服务

- `ContentRetentionService` 负责策略解析、生效策略选择、摘要生成、脱敏正文、原文加密和 `usage_contents` 写入。
- 策略优先级为 API Key > 用户 > 最近部门/祖先部门 > 系统默认，保留天数受 `max_days` 截断。
- `ContentArchiveService` 负责归档查询、详情查看、权限范围 SQL、原文解密控制和审计。
- `GatewayService` / `GatewayUsageRecorder` 在成功网关响应时将请求 payload、规范化响应和会话 ID 放入 `usage.write` 队列。
- `UsageService` 写入 `usage_records` 后调用 `ContentRetentionService` 写入 `usage_contents`。
- `ExportService` 统一处理 `usage`、`session_archive`、`logs` 三类 CSV 导出，包含权限快照、过滤条件清洗、敏感列过滤、原文导出权限控制、加密落盘和短期下载链接。
- `WorkerService` 的导出路径委托 `ExportService::process()`，内容清理只清正文并保留明细，导出过期后删除文件并置为 `expired`。

### API

- `GET /admin-api/system/content-retention`
- `PUT /admin-api/system/content-retention`
- `GET /admin-api/content-archive`
- `GET /admin-api/content-archive/{id}`
- `POST /admin-api/exports/{type}`
- `GET /admin-api/exports/{export_id}/download-url`

### 权限与审计

- 普通用户只能访问本人内容。
- 管理员只能访问授权部门及子部门内容。
- 超级管理员可访问全局内容，但仍写审计。
- 原文查看和 `include_raw=true` 导出受 `content_retention.policy.raw_allowed_roles` 控制，默认仅 `super_admin`。
- 内容列表、详情、原文拒绝/查看、导出创建、原文导出、下载链接生成均写入 `audit_logs`。
- 导出任务创建时将 actor scope 写入 `filters._scope`，避免 worker 脱离登录上下文后越权。

### 导出与安全

- 支持 `usage`、`session_archive`、`logs` CSV。
- 支持用户、部门、API Key、模型、时间范围、会话 ID 组合筛选。
- 默认导出摘要/脱敏内容；原文导出需额外权限。
- CSV 不输出 API Key 明文、厂商密钥、`Authorization`、`Cookie` 等敏感字段。
- 导出文件以加密内容保存到 `paths.exports` 或 `storage_path('exports')`。
- 下载链接有效期为 300 秒。

### 管理端与文档

- 管理端新增会话存档、留存策略和导出任务页面。
- 前端继续使用现有原生 JS/CSS 架构，无新增构建链。
- 开发指南已同步 API、策略 JSON、CSV 字段和权限规则。
- 07 任务清单已全部勾选完成。

## 验证结果

已在 Docker PHP/MySQL/Redis 环境验证：

```bash
docker compose run --rm php php bin/migrate.php
docker compose run --rm --no-deps php php bin/run-tests.php
```

结果：

- 迁移执行成功，修复后应用了 6 个未执行迁移。
- 全量测试通过：`33 test file(s) passed.`
- 07 相关测试覆盖 `ContentRetentionServiceTest`、`ContentArchiveServiceTest`、`ExportServiceTest`、`WorkerServiceTest`、`CrudServiceRetentionExportTest`。

本机 shell 仍找不到 `php`，所以验证依赖 Docker PHP 环境完成。

## 注意事项

- 尚未做浏览器人工点测；管理端 UI 已有源码落地和自动化安全测试通过，但保存策略、筛选归档、查看详情、创建导出、获取下载链接仍建议在登录态下做一次手工验收。
- 当前目录不是可用 git 工作树状态，无法在本地生成可信 commit/diff 结论；交付状态以文件内容、迁移执行和测试结果为准。
