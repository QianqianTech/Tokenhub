# 对外 OpenAI 服务生产加固设计

## 目标

将 TokenHub 的对外 OpenAI 兼容网关从当前可用的开发/预生产形态，提升为可安全部署的生产形态。范围限于外部网关、Webhook、生产部署模板、配置安全和运行可观测性；不改变 API Key 用户模型或既有 API 合约。

## 方案

采用“开发与生产配置分离”的方案：保留本地 Docker Compose 的开发便利性，但将其默认端口绑定到 loopback；生产安全规则由正式 Caddy、Ubuntu 运行说明和生产环境样例承载。

## 组件与数据流

### Webhook 队列

Webhook 创建、手动重试、事件投递和到期补偿都使用 `queue.name`。队列 Worker 继续从同一配置读取待处理队列，保证任务可被消费、重试或进入死信队列。

### 上游请求幂等与重试

TokenHub 为每次非流式网关请求确定一个上游幂等键：优先使用客户端 `Idempotency-Key`，否则使用服务端请求 ID。该键会以 `Idempotency-Key` 转发给支持的上游。流式请求在首个响应数据前只能尝试一次；非流式请求仅对连接失败和 5xx 重试，且重试请求沿用相同幂等键。

### 部署安全

开发 Compose 将 Caddy、MySQL、Redis、Qdrant 端口绑定到 `127.0.0.1`，Redis 使用密码。生产模板明确要求 HTTPS、`SESSION_SECURE=true`、真实应用 URL、精确的可信代理 CIDR、私网数据服务和 Redis 密码。

### 网关与可观测性

Caddy 隐藏 PHP 版本头，限制请求体并对安全日志的 query 做脱敏。公开 `/health` 维持数据库和 Redis 存活检查；新增/扩展 readiness 信号以包含队列 Worker 心跳，便于负载均衡与发布系统识别异步任务失效。

## 错误与兼容性

- 不改变现有 `/v1/*` 路径、认证方式或响应包络。
- 上游不支持幂等键时，应忽略该请求头而不能使请求失败。
- Redis、队列或 Worker 不健康时，readiness 必须准确返回不可就绪；公开 health 的语义保持稳定。
- 开发环境仍可通过本机端口访问服务；生产监听和证书由正式部署配置决定。

## 测试与验收

- 回归测试证明 Webhook 与 Worker 使用同一队列名。
- 回归测试证明重试会复用同一上游幂等键，流式请求不会重试。
- 配置契约测试验证 loopback 端口、Redis 密码、生产 HTTPS/Cookie/代理要求、日志脱敏和响应头。
- Docker PHP 测试、Caddy 配置校验、容器健康检查。
- 上线前另以受限真实 API Key 验证 Chat、Responses、Embeddings、SSE、配额、超时重试与 Webhook 接收端。
