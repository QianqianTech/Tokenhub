# 05 API Key、限额和用量统计任务清单

## 目标

实现对外 API Key 生成、绑定用户、模型权限、IP 白名单、限额策略、调用明细、聚合统计和可选成本计算。

## 数据表

- `api_keys`
- `api_key_model_permissions`
- `quota_policies`
- `quota_bindings`
- `usage_records`
- `usage_aggregates_minute`
- `usage_aggregates_hour`
- `usage_aggregates_day`

## 开发任务

- [ ] 实现 API Key 生成，明文只展示一次。
- [ ] 数据库只保存 Key 哈希，不保存明文。
- [ ] API Key 绑定用户、部门、允许模型、IP/CIDR 白名单、状态、过期时间。
- [ ] 实现 API Key 列表、创建、停用、删除、权限编辑接口。
- [ ] 实现 Key 权限缓存，Key 状态变化后立即失效缓存。
- [ ] 创建限额策略，支持次数、输入 Token、输出 Token、总 Token、音频秒数、TTS 字符数、图片数量、视频秒数、可选成本。
- [ ] 限额策略可绑定 Key、用户、部门。
- [ ] 使用 Redis 原子计数处理请求前限额预检。
- [ ] 响应结束后按实际用量回写计量。
- [ ] 实现并发计数，超过并发返回 `CONCURRENCY_EXCEEDED`。
- [ ] 实现 `usage_records` 明细写入任务。
- [ ] 实现分钟、小时、日聚合任务。
- [ ] 聚合统计支持部门、用户、Key、模型、厂商、模态维度。
- [ ] 成本仅在模型价格存在时计算，并保存价格快照。

## 测试任务

- [ ] 创建 Key 后只显示一次明文。
- [ ] 数据库和日志中不能搜索到 Key 明文。
- [ ] Key 停用后调用立即失败。
- [ ] Key IP 白名单不匹配时调用失败。
- [ ] Key 未授权模型时返回 `MODEL_FORBIDDEN`。
- [ ] 次数限额达到后返回 `QUOTA_EXCEEDED`。
- [ ] Token 限额达到后返回 `QUOTA_EXCEEDED`。
- [ ] 并发限制达到后返回 `CONCURRENCY_EXCEEDED`。
- [ ] 高并发下 Redis 计数不明显超卖。
- [ ] 聚合表能按分钟、小时、日查询趋势。

## 验收标准

- 每次调用都能追踪到用户、部门、Key、模型、厂商和用量。
- 限额校验在请求前执行，实际用量在请求后修正。
- 统计和聚合异步写入，不阻塞主转发链路。
- 未配置价格时不展示成本，配置后可查看成本。

