# 04 OpenAI/Anthropic 兼容网关任务清单

## 目标

实现对外 OpenAI 兼容 API、Anthropic 兼容 API、模型路由、厂商适配、高速转发和流式响应。

## 核心接口

- `POST /v1/chat/completions`
- `POST /v1/responses`
- `POST /v1/embeddings`
- `POST /v1/messages`
- `POST /v1/audio/transcriptions`
- `POST /v1/audio/speech`
- `POST /v1/images/generations`
- `POST /v1/videos/generations`

## 开发任务

- [ ] 实现 Gateway 请求入口，中间件顺序为请求 ID、IP 校验、API Key 鉴权、模型权限、限额预检、路由、转发、计量回写。
- [ ] 实现 OpenAI 兼容请求解析器，支持 chat、responses、embeddings、audio、images、videos。
- [ ] 实现 Anthropic 兼容请求解析器，支持 messages 和多模态 content block。
- [ ] 实现 `ProviderAdapterInterface`，统一请求转换、响应转换、错误映射、用量解析。
- [ ] 实现 OpenAI 格式适配器。
- [ ] 实现 Anthropic 格式适配器。
- [ ] 实现厂商原生适配器基础类，用于腾讯云等多模态服务扩展。
- [ ] 实现 `ModelRouter`，按模型、厂商状态、优先级、能力类型选择上游。
- [ ] 实现非流式转发，透传兼容响应格式。
- [ ] 实现 SSE 流式转发，边读边写，不完整缓存响应体。
- [ ] 流式响应结束后解析或估算输出 Token。
- [ ] 实现上游超时、重试、熔断和错误码映射。
- [ ] 禁止在错误响应中返回上游密钥、内部地址、堆栈和配置路径。
- [ ] 所有请求写入摘要日志和用量异步任务。

## 测试任务

- [ ] OpenAI chat 请求可以转发到 OpenAI 格式厂商。
- [ ] Anthropic messages 请求可以转发到 Anthropic 格式厂商。
- [ ] 流式响应首包不等待完整上游响应。
- [ ] 上游超时返回 `PROVIDER_TIMEOUT`。
- [ ] 上游 5xx 返回 `PROVIDER_ERROR`，日志记录上游状态但不泄露密钥。
- [ ] 禁用模型或无权限模型不能转发。
- [ ] 响应结束后产生用量明细异步任务。
- [ ] 同一请求的访问日志、用量明细、审计事件可以通过 `request_id` 串联。

## 验收标准

- 对外调用方只需要 TokenHub API Key 和 OpenAI/Anthropic 兼容格式。
- 流式响应保持低首包延迟。
- Gateway 不依赖 MySQL 高频同步写入。
- 所有业务鉴权都在 Gateway 执行，不能只依赖 Caddy。

