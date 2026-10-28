# 接入与排障手册（TH-028）

面向管理员：从零接入一个厂商（含 OpenRouter）到用户调用成功，以及失败时如何定位。

## 一、接入流程

1. **添加供应商**：后台“供应商”新建，填写 API 根地址（不是完整端点，例如 `https://openrouter.ai/api/v1`、
   `https://generativelanguage.googleapis.com/v1beta/openai`）。服务端会拒绝带凭据、查询串或完整端点路径的地址。
   - 凭据填在“密钥”字段；如果厂商要求放在头部（`x-api-key`、`api-key`），可写在默认头部，保存后会加密并显示为 `****`。
   - 内网推理服务需在默认头部加 `"tokenhub_allow_private_network": true`，或由运维配置 `OUTBOUND_PRIVATE_ALLOWLIST`。
2. **同步模型**：`POST /admin-api/providers/{id}/catalog-sync` 先预览（新增、变更、上游已下线），确认后传 `apply=true`。
   同步不会重新启用已停用的模型；OpenRouter 的能力、上下文与价格进入模型能力合同的 `upstream` 层。
3. **确认能力合同**：需要工具调用、JSON Schema、推理或图片输入的模型，需在 `models.capabilities` 中声明
   （`admin` 层覆盖、`verified` 层记录验收结果）。有合同的模型，未声明的特性视为不支持。
4. **设置价格**：输入、输出、缓存读写、推理分别定价；币种以三位代码记录。未定价且 Key 有费用限额时，
   `GATEWAY_UNKNOWN_PRICE_POLICY` 决定放行（记为未知成本）或拒绝。
5. **分配权限与额度**：给 Key 授权具体模型（渠道级别）；同名模型分布在多个供应商时，只授权的渠道可被路由。
6. **OpenRouter 路由策略**（可选）：默认拒绝客户端的 `provider/plugins/models` 参数。需要放开时在默认头部设置
   `"tokenhub_client_routing": "restricted"` 与 `"tokenhub_provider_policy": {"data_collection": "deny", "allow_fallbacks": false}`。
7. **正式链路试聊**：后台试聊时选择一个 Key（请求带 `api_key_id`），结果与该 Key 的正式调用一致，并计入其用量。
   不带 Key 的试聊只用于连通性诊断，结果标记为 `direct_diagnostic`。
8. **发 Key 并验收**：用 `scripts/provider-acceptance.php` 对具体模型生成验收报告，存档到发布记录。

## 二、排障

每个响应都带 `X-Request-Id`，错误体中也有 `request_id`。管理员调用
`GET /admin-api/diagnostics/requests/{request_id}` 可看到：实际渠道、每次尝试的状态、用量来源（measured/estimated）、
上游 generation id、流式结束方式、记账阶段，以及下表中的原因与处理建议。

| 错误码 | 类别 | 原因 | 处理 |
| --- | --- | --- | --- |
| `MODEL_FORBIDDEN` | permission | The API key has no grant for this model or channel. | Grant the model to the key (API keys > Models) or call a granted model. |
| `MODEL_NOT_FOUND` | configuration | No model with this name is configured. | Check the model name or import it from the provider catalog. |
| `MODEL_UNAVAILABLE` | configuration | Every channel for the model is disabled or retired. | Enable a channel or move the key to another model. |
| `MODEL_CAPABILITY_UNSUPPORTED` | configuration | No enabled channel supports this endpoint or a requested feature (tools, JSON schema, reasoning, images). | Use a model whose capability contract includes the feature, or update the contract after verifying it. |
| `CAPABILITY_UNAVAILABLE` | configuration | Only "native" channels exist for this capability and they have no execution backend. | Configure an OpenAI/Anthropic compatible channel (e.g. Volcengine Ark) for the capability. |
| `QUOTA_EXCEEDED` | quota | A quota policy bound to the key, user or department is exhausted for the current window (reservations of in-flight requests count). | Wait for the window reset, raise the limit, or apply an audited quota adjustment. |
| `MODEL_QUOTA_EXCEEDED` | quota | The per-key model quota is exhausted. | Raise the model quota for the key or wait for the reset. |
| `CONCURRENCY_EXCEEDED` | quota | Too many simultaneous requests for the bound policy. | Reduce parallelism or raise concurrency_limit. |
| `PRICE_UNKNOWN` | configuration | A cost limit applies but the model has no price, and GATEWAY_UNKNOWN_PRICE_POLICY=deny. | Add a model price or relax the unknown-price policy. |
| `DESENSITIZATION_BLOCKED` | policy | Outbound content (including injected RAG context) matched a blocking desensitization rule. | Review the desensitization event for this request id. |
| `ROUTING_PARAMETER_FORBIDDEN` | policy | The request tried to change aggregator routing (provider/plugins/models) beyond the channel policy. | Remove the parameter or set tokenhub_client_routing/tokenhub_provider_policy on the provider. |
| `ROUTING_AUTO_ROUTER_FORBIDDEN` | policy | Auto routers choose models outside TokenHub authorization. | Use an explicit model or set tokenhub_allow_auto_router on the provider. |
| `TOOL_CALL_MISMATCH` | client | A tool result references a tool call id that was never issued in the conversation. | Send the assistant message with tool_calls before the matching tool results. |
| `TOOL_CHOICE_UNKNOWN` | client | tool_choice names an undeclared tool. | Declare the tool in tools or change tool_choice. |
| `OUTBOUND_BLOCKED` | configuration | The channel URL resolves to a private, loopback or metadata address. | For private inference servers set tokenhub_allow_private_network on the provider or OUTBOUND_PRIVATE_ALLOWLIST. |
| `PROVIDER_ENDPOINT_UNSUPPORTED` | configuration | The channel endpoint map marks this endpoint unsupported. | Route the request to a channel that supports the endpoint. |
| `PROVIDER_TIMEOUT` | upstream | The upstream did not answer (or stopped streaming) within timeout_ms. | Check upstream status; raise timeout_ms for long generations. |
| `PROVIDER_RATE_LIMITED` | upstream | The upstream returned 429 for every attempted channel. | Respect Retry-After, add channels or raise the upstream account limits. |
| `PROVIDER_REJECTED` | upstream | The upstream refused the request (4xx): bad parameters, auth or account state. | Check the provider secret, account balance and request parameters. |
| `PROVIDER_ERROR` | upstream | The upstream returned 5xx on every attempt. | Check upstream status; the circuit breaker will route around a failing channel. |
| `PROVIDER_STREAM_ERROR` | upstream | The upstream sent an error event after the stream started. | Inspect the upstream error in the retained output; usually overload or content policy. |
| `PROVIDER_STREAM_INCOMPLETE` | upstream | The stream ended without its terminal event. | Treat output as truncated; check upstream/network stability. |
| `CLIENT_CANCELLED` | client | The client disconnected; usage observed until then was charged. | None unless unexpected; check client timeouts. |
| `RAG_REQUIRED_NO_CONTEXT` | rag | RAG was required but no dataset/context met the thresholds. | Bind a dataset to the key or lower the retrieval threshold. |
| `RAG_RETRIEVAL_FAILED` | rag | Required retrieval failed (vector store or embedding provider). | Check Qdrant and the embedding channel health. |
| `API_KEY_INVALID` | permission | Unknown, disabled or deleted key, or its user/department is disabled. | Issue or re-enable a key. |
| `API_KEY_EXPIRED` | permission | The key passed expires_at (also after a rotation grace period). | Switch the client to the rotated key. |

### 常见现象

- **请求记录查不到**：用量写入是异步的；查看运维状态中的队列积压与 `usage_pipeline`，必要时运行 `php bin/usage-reconcile.php --resume`。
- **额度显示已用但请求未结束**：额度视图中的 `reserved_in_flight` 是进行中请求的预留，结束后按实测结算。
- **进程重启后并发额度被占满**：worker 每 15 秒回收过期预留租约；可在运维状态 `reservations.expired` 查看。
- **模型健康显示 unknown**：最近 15 分钟没有真实请求；`unhealthy` 表示熔断打开，查看 `last_failure_reason`。
- **失败任务**：`php bin/queue-replay-failed.php --list` 查看原因，修复后 `--replay --job-id=...`。
