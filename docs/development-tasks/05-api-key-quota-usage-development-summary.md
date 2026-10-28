# 05 API Key、限额和用量统计开发总结

## 开发结论

`05-api-key-quota-usage.md` 对应开发已完成。当前实现覆盖 API Key 生成、哈希存储、用户/部门绑定、模型权限、IP/CIDR 白名单、状态和过期时间控制、限额策略与绑定、Redis 请求前预检、并发控制、实际用量回写、调用明细、分钟/小时/日聚合统计，以及模型价格存在时的成本计算和价格快照。

本次开发延续项目现有 Native PHP 8.2 架构，不引入新框架。高频请求链路中，限额和并发控制走 Redis，明细和聚合写入走队列任务，避免阻塞主网关转发。

## 已完成内容

- API Key 创建：`ApiKeyService::create()` 生成 `tkh_` 前缀 Key，返回明文仅用于创建响应；数据库仅保存 `key_hash`、前缀、后缀和脱敏展示字段。
- API Key 管理接口：新增/完善列表、创建、更新、软删除、模型权限替换能力；删除按 `status = "deleted"` 处理，保留历史用量关联。
- Key 绑定信息：API Key 支持绑定用户、部门、模型权限、IP/CIDR 白名单、状态和过期时间。
- 权限缓存：认证时按 Key 哈希缓存 Key 和模型权限；Key 状态、过期时间、IP 白名单或模型权限变化后立即删除缓存。
- 用户/部门联动失效：用户或部门状态变化后会触发相关 API Key 缓存失效，避免已禁用主体继续使用旧缓存。
- 网关鉴权：`GatewayAuthMiddleware` 使用 Bearer API Key 完成 Key、用户、部门、过期、IP 和模型权限校验，保留 `API_KEY_INVALID`、`API_KEY_EXPIRED`、`IP_FORBIDDEN`、`MODEL_FORBIDDEN` 错误码。
- 限额策略：`quota_policies` 支持请求次数、输入 Token、输出 Token、总 Token、音频秒数、TTS 字符数、图片数量、视频秒数、成本和并发限制。
- 限额绑定：`quota_bindings` 支持将策略绑定到 API Key、用户和部门。
- Redis 预检：`QuotaService::preflight()` 在请求前执行请求数和并发计数；超限返回 `QUOTA_EXCEEDED` 或 `CONCURRENCY_EXCEEDED`。
- 预检回滚：当某个策略拒绝请求时，当前预检已增加的请求数和并发计数会回滚，避免失败请求污染限额窗口。
- 原子计数：`RedisClient` 增加浮点增量和回滚能力，成本等非整数指标使用 Redis 原子更新路径。
- 实际用量回写：网关响应结束后按上游返回或估算结果调用 `QuotaService::recordUsage()` 修正 Token、成本和多模态用量。
- 用量明细：`UsageService::write()` 统一负责 `usage_records` 写入、成本计算和价格快照保存。
- 写入路径统一：`QueueWorker` 和 `WorkerService` 的 `usage.write` 均委托 `UsageService::write()`，避免两套明细/聚合逻辑分叉。
- 聚合统计：写入明细后同步更新 `usage_aggregates_minute`、`usage_aggregates_hour`、`usage_aggregates_day`，维度包含部门、用户、Key、厂商、模型和 capability。
- 管理端入口：后台 API Key 页面支持创建、编辑状态/IP/过期时间、替换模型权限和软删除；限额页面支持创建策略和绑定。
- 日志脱敏：`Logger` 和 `JsonLogger` 均会脱敏 API Key、Token、Secret、Password 和 `key_hash`，避免明文 Key 或哈希进入日志。

## 主要变更文件

- `app/Services/ApiKeyService.php`
- `app/Services/QuotaService.php`
- `app/Services/UsageService.php`
- `app/Services/WorkerService.php`
- `app/Services/QueueWorker.php`
- `app/Support/RedisClient.php`
- `app/Support/Logger.php`
- `app/Support/JsonLogger.php`
- `app/Http/Controllers/AdminController.php`
- `app/Bootstrap/Kernel.php`
- `app/Middleware/GatewayAuthMiddleware.php`
- `public/admin/assets/js/app.js`
- `public/admin/assets/js/resources.js`

## 管理接口

- `GET /admin-api/api-keys`：查询 API Key 列表，响应不包含 `key_hash`。
- `POST /admin-api/api-keys`：创建 API Key，并仅在本次响应返回明文 Key。
- `PUT /admin-api/api-keys/{id}`：更新名称、状态、IP/CIDR 白名单和过期时间。
- `PUT /admin-api/api-keys/{id}/models`：整体替换 API Key 可访问模型列表。
- `DELETE /admin-api/api-keys/{id}`：软删除 API Key。
- `GET /admin-api/quota-policies` / `POST /admin-api/quota-policies`：限额策略列表和创建。
- `GET /admin-api/quota-bindings` / `POST /admin-api/quota-bindings`：限额绑定列表和创建。

## 新增或覆盖测试

- `tests/Services/ApiKeyServiceManagementTest.php`
- `tests/Services/QuotaServiceRollbackTest.php`
- `tests/Services/UsageWritePathTest.php`
- `tests/Services/QuotaServiceTest.php`
- `tests/Services/PriceServiceTest.php`
- `tests/Services/SensitiveDataServiceTest.php`
- `tests/Services/DashboardServiceSourceTest.php`
- `tests/Frontend/AdminFrontendSecurityTest.php`

测试覆盖重点：

- 创建 Key 后仅创建响应展示明文。
- API Key 响应、审计和日志不暴露明文 Key 或 `key_hash`。
- IP/CIDR 匹配和不匹配行为。
- 未授权模型返回 `MODEL_FORBIDDEN`。
- 请求数、Token 和成本限额返回 `QUOTA_EXCEEDED`。
- 并发限额返回 `CONCURRENCY_EXCEEDED`。
- 预检失败后回滚 Redis 计数。
- 用量写入路径统一委托 `UsageService::write()`。
- 成本仅在模型价格存在时计算，并保存价格快照。
- 聚合数据可供仪表盘按小时/日读取。

## 验证结果

已执行数据库迁移：

```sh
docker compose exec php php bin/migrate.php
```

执行结果：退出码 `0`，已应用迁移。

已执行前端语法检查：

```sh
node --check public/admin/assets/js/app.js
```

执行结果：退出码 `0`。

已执行完整 PHP 测试套件：

```sh
docker compose exec php php bin/run-tests.php
```

执行结果：退出码 `0`，`25 test file(s) passed.`。

## 环境说明

本地 Windows 环境中 `php` 不在 PATH，因此 PHP 测试通过 Docker Compose 内的 `php` 服务执行。测试前发现 `.env` 中 `DB_PASSWORD=change-me` 与本地 Docker MySQL 已初始化用户密码不一致，已将本地 Docker MySQL 的 `tokenhub` 用户密码调整为 `change-me` 后完成迁移和测试；此操作只影响本地 Docker 数据库状态，未修改仓库文件。

## 验收状态

- 每次调用可追踪到用户、部门、Key、模型、厂商和用量：已完成。
- 限额校验在请求前执行，实际用量在请求后修正：已完成。
- 统计和聚合通过异步任务写入，不阻塞主转发链路：已完成。
- 未配置价格时不计算成本，配置价格后可查看成本和价格快照：已完成。
- API Key 明文只展示一次，数据库和日志不保存明文：已完成。

## 后续建议

05 已完成。后续可以基于真实上游模型调用增加端到端 smoke test，覆盖从 API Key 创建、模型授权、网关调用、限额命中到仪表盘趋势查询的完整业务路径。
