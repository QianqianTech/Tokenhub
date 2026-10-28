# 对外 OpenAI 生产就绪加固记录

## 本轮修复

- Webhook 的创建、重试、事件投递与到期补偿统一写入 `queue.name`，不再误写固定 `jobs` 队列。
- 非流式上游请求复用客户端 `Idempotency-Key`；未提供时使用 TokenHub 请求 ID。流式请求固定为单次尝试，避免部分输出后重复调用上游。
- 新增 `GET /ready`：除 PHP、MySQL、Redis 外，还要求 queue-worker 心跳存在；`GET /health` 保持原有存活语义。
- 开发 Compose 的 Caddy、MySQL、Redis、Qdrant 均仅绑定 `127.0.0.1`，Redis 强制密码认证；开发 Caddy 去除 PHP 版本头并限制请求体为 32MB。
- 新增 Ubuntu 生产环境样例，要求 HTTPS、Secure Cookie、显式可信代理 CIDR、Redis 密码和私网数据服务。
- Python RAG Worker 读取并使用 `REDIS_PASSWORD`，使其健康检查与 Redis 认证兼容。
- Docker socket autoscaler 改为 `autoscaling` 显式 profile。Docker Desktop 默认 Compose 不启动它，避免容器内 Compose 错误解析宿主机项目路径。

## 自动化验证

```bash
docker compose exec -T php php tests/run.php
docker compose config --quiet
docker compose exec -T rag-worker python -m tokenhub_rag_worker --healthcheck
curl -fsS http://127.0.0.1:8080/health
curl -fsS http://127.0.0.1:8080/ready
```

## 上线前仍需执行的外部演练

使用受限生产 API Key 和真实上游运行 Chat、Responses、Embeddings、SSE、配额耗尽、上游超时和 HTTPS Webhook 接收端演练。该演练涉及外部 Provider 额度与真实回调地址，未在本地自动执行。
