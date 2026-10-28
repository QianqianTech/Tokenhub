# 11 日志、可观测性和审计任务清单

## 目标

实现独立日志文件、JSON Lines 格式、日志脱敏、日志留存、查询、导出、告警、审计日志和链路追踪。

## 日志文件

- `caddy-access.log`
- `caddy-security.log`
- `gateway-access.log`
- `provider.log`
- `usage.log`
- `audit.log`
- `security.log`
- `performance.log`
- `worker.log`
- `export.log`

## 开发任务

- [x] 实现 `LogService`，支持按日志类型写入独立文件。
- [x] 日志统一 JSON Lines 格式。
- [x] 日志字段包含 `timestamp`、`level`、`request_id`、`trace_id`、`user_id`、`department_id`、`api_key_id`、`provider_id`、`model_id`、`client_ip`、`status`、`latency_ms`、`error_code`。
- [x] 所有日志写入前执行脱敏。
- [x] 日志中禁止出现 API Key 明文、厂商密钥、Authorization、Cookie、完整 prompt、完整响应。
- [x] 创建 `log_retention_policies` 表，按日志类型配置在线保留、归档保留、压缩、加密和最小保留限制。
- [x] 实现日志轮转，支持按天和按大小。
- [x] 实现日志归档压缩和加密。
- [x] 实现到期日志清理，不删除业务明细和审计数据。
- [x] 修改日志留存策略写入审计日志。
- [x] 实现日志中心查询，支持时间、日志类型、请求 ID、用户、部门、Key、厂商、模型、错误码、安全事件。
- [x] 实现日志导出 CSV 异步任务。
- [x] 导出日志必须权限校验和审计。
- [x] 实现告警事件：WAF 命中异常、厂商错误率升高、队列堆积、日志写入失败、证书即将过期。
- [x] 实现审计日志写入封装，审计日志不允许普通管理员删除。

## 测试任务

- [x] 每种日志写入对应独立文件。
- [x] 日志为合法 JSON Lines。
- [x] 敏感字段写日志前被脱敏。
- [x] 访问日志不包含完整 prompt 和响应。
- [x] 日志留存策略可按类型配置。
- [x] 审计日志和安全日志不能低于最小保留天数。
- [x] 日志轮转后新日志继续写入。
- [x] 到期日志被清理，业务明细不受影响。
- [x] 普通管理员不能删除审计日志。
- [x] 日志导出 CSV 只包含授权范围数据。
- [x] 告警事件可以在后台查询。

## 验收标准

- 日志能力独立于控制台输出。
- 日志可查询、可导出、可留存、可清理、可告警。
- 审计日志覆盖登录、配置、Key、导出、内容查看、安全策略变更。
- 日志系统本身不成为数据泄漏点。

