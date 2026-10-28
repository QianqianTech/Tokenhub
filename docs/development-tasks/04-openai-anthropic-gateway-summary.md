# 04 OpenAI/Anthropic 兼容网关开发总结

## 开发结论

`04-openai-anthropic-gateway.md` 对应的网关开发已完成。当前实现已经覆盖 OpenAI 兼容接口、Anthropic 兼容接口、模型路由、厂商适配、非流式转发、SSE 流式转发、错误映射、限额预检、摘要日志和用量异步任务。

本次开发保持项目现有 Native PHP 8.2 架构，不引入框架，也未新增数据库迁移。上游转发和流式响应继续基于 PHP stream API 实现，符合当前 `composer.json` 未声明 `ext-curl` 的运行约束。

## 已完成内容

- 网关入口重构：`GatewayService` 现在按请求解析、模型路由、限额预检、厂商适配、上游转发、响应处理、用量记录的顺序编排。
- OpenAI 请求解析：支持 chat、responses、embeddings、audio transcriptions、audio speech、images generations、videos generations，并映射 capability 和预估用量。
- Anthropic 请求解析：支持 `/v1/messages`，保留多模态 content block 原始结构。
- 模型路由：新增 `ModelRouter`，按模型名、协议、能力类型、厂商状态、模型状态和 `route_priority` 选择上游。
- 厂商适配：升级 `ProviderAdapterInterface`，OpenAI、Anthropic、Native 多模态适配器统一处理请求构造、响应归一化、错误映射和用量解析。
- 非流式转发：新增 `ProviderHttpClient::sendJson()`，支持超时、5xx 重试和统一错误码映射。
- SSE 流式转发：新增 `Response::stream()` 和 `ProviderHttpClient::stream()`，实现边读边写，不完整缓存上游响应体。
- 流式用量：流式结束后优先使用上游 usage；缺失时按输入估算和输出字符数估算 token。
- 限额和并发：网关接入现有 `QuotaService::preflight()` 和 `releaseConcurrency()`，不再使用旧的内联限额逻辑。
- 日志和用量：新增 `GatewayUsageRecorder`，统一写入 `gateway-access` 摘要日志和 `usage.write` 异步任务，保留 `request_id` / `trace_id` 串联能力。
- 安全处理：错误响应只返回安全错误码和通用错误信息，不暴露上游密钥、内部地址、堆栈和配置路径。

## 主要变更文件

- `app/Services/GatewayService.php`
- `app/Services/ProviderHttpClient.php`
- `app/Support/Response.php`
- `app/Services/Gateway/GatewayRequest.php`
- `app/Services/Gateway/OpenAIRequestParser.php`
- `app/Services/Gateway/AnthropicRequestParser.php`
- `app/Services/Gateway/ModelRouter.php`
- `app/Services/Gateway/GatewayUsageRecorder.php`
- `app/Services/Providers/ProviderAdapterInterface.php`
- `app/Services/Providers/OpenAIAdapter.php`
- `app/Services/Providers/AnthropicAdapter.php`
- `app/Services/Providers/NativeMultimodalAdapter.php`

## 新增测试

- `tests/Services/GatewayRequestParserTest.php`
- `tests/Services/ProviderAdapterContractTest.php`
- `tests/Support/ResponseStreamTest.php`

## 验证结果

已通过 04 网关相关 focused tests：

```sh
docker compose run --rm php sh -lc "php tests/Services/GatewayRequestParserTest.php && php tests/Services/ProviderAdapterContractTest.php && php tests/Support/ResponseStreamTest.php && php tests/Services/ProviderHttpClientTest.php"
```

执行结果：退出码 `0`。

完整测试套件已执行：

```sh
docker compose run --rm php php bin/run-tests.php
```

完整套件中 04 相关测试均已通过，但仍有 5 个非 04 范围失败：

- `tests/Frontend/AdminFrontendSecurityTest.php`
- `tests/Services/ExportServiceTest.php`
- `tests/Services/MediaAssetServiceTest.php`
- `tests/Services/MultimodalTaskServiceTest.php`
- `tests/Services/WorkerServiceTest.php`

这些失败分别集中在前端鉴权封装、导出服务、媒体资产服务、多模态任务服务和 Worker 清理逻辑，不属于本次 04 OpenAI/Anthropic 兼容网关开发范围。

## 验收状态

- 对外调用方只需要 TokenHub API Key 和 OpenAI/Anthropic 兼容格式：已完成。
- 流式响应保持低首包延迟：已完成，使用 callback response 边读边写。
- Gateway 不依赖 MySQL 高频同步写入：已完成，用量明细通过 Redis 队列异步写入。
- 所有业务鉴权都在 Gateway 执行，不只依赖 Caddy：已完成，Key、IP、模型权限、限额和路由均在 PHP Gateway 层执行。

## 后续建议

04 已完成。后续建议单独处理完整测试套件中剩余的 5 个非 04 失败项，并为真实上游 OpenAI/Anthropic 兼容服务增加端到端 smoke test。
