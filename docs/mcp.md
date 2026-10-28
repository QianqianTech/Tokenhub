# MCP 服务（Model Context Protocol）

TokenHub 在 `/mcp` 提供标准 MCP Server，Claude Code、Cursor、VS Code、Claude Desktop、Cherry Studio 等支持 MCP 的客户端接入后，模型可以直接检索企业知识库，或查看当前 API Key 的可用模型、额度和用量。

- 地址：`https://<你的 TokenHub 域名>/mcp`
- 传输：Streamable HTTP（无状态，仅 POST，返回 `application/json`；不建立 SSE 长连接，不下发 `Mcp-Session-Id`）
- 协议版本：`2025-11-25`、`2025-06-18`、`2025-03-26`、`2024-11-05`
- 认证：`Authorization: Bearer <API Key>`，与 `/v1` 使用同一套 API Key
- 限流：每个 Key 共享 `EXTERNAL_API_OTHER_PER_MINUTE`（默认 120 次/分钟），认证失败按 IP 计入 `EXTERNAL_API_INVALID_AUTH_PER_MINUTE`

## 工具

所有工具都是只读的，权限与该 API Key 调用 REST 接口时完全一致：只能检索绑定给该 Key（或其用户、部门）的知识库和数据集，只能看到该 Key 有权限的模型。

| 工具 | 作用 | 参数 |
| --- | --- | --- |
| `search_knowledge_base` | 知识库检索（向量 + 关键词混合检索，中英文），返回片段、来源 ID、标题、得分和 `retrieval_id`；查询先按出站脱敏策略处理再检索；不走 MCP 的客户端可调用等价的 `POST /v1/rag/search` | `query`（必填）、`knowledge_base_ids`、`dataset_ids`、`top_k`（1–20，默认 5） |
| `list_knowledge_bases` | 列出可访问的知识库；传 `knowledge_base_id` 时列出其下数据集 | `knowledge_base_id`（可选） |
| `list_models` | 可通过网关调用的模型 | 无 |
| `get_quota` | 适用的额度策略和单模型额度 | 无 |
| `get_usage_summary` | 请求数、Token、费用、延迟汇总（默认最近 30 天，按模型拆分） | `from`、`to`（可选，日期或 ISO 8601） |
| `get_api_key_info` | 当前连接所用的 Key、用户和部门 | 无 |

不传 `knowledge_base_ids` / `dataset_ids` 时检索该 Key 的默认知识库。每次检索都写入 `rag_retrieval_logs`，在后台的知识库检索日志和检索质量统计中可见；返回的 `retrieval_id` 可用于 `POST /v1/rag/feedback` 反馈。

## 客户端配置

下文的 `tkh_xxx` 换成在后台「API Key 管理」中创建的 Key。建议给 MCP 单独建一个 Key，只绑定需要开放的知识库。

**Claude Code**

```sh
claude mcp add --transport http tokenhub https://tokenhub.example.com/mcp \
  --header "Authorization: Bearer tkh_xxx"
```

**Cursor**（`~/.cursor/mcp.json` 或项目内 `.cursor/mcp.json`）

```json
{
  "mcpServers": {
    "tokenhub": {
      "url": "https://tokenhub.example.com/mcp",
      "headers": { "Authorization": "Bearer tkh_xxx" }
    }
  }
}
```

**VS Code**（`.vscode/mcp.json`）

```json
{
  "servers": {
    "tokenhub": {
      "type": "http",
      "url": "https://tokenhub.example.com/mcp",
      "headers": { "Authorization": "Bearer tkh_xxx" }
    }
  }
}
```

**Claude Desktop**：本地配置文件只支持 stdio，用 `mcp-remote` 转接（需要 Node.js）。编辑 `claude_desktop_config.json`：

```json
{
  "mcpServers": {
    "tokenhub": {
      "command": "npx",
      "args": ["-y", "mcp-remote", "https://tokenhub.example.com/mcp", "--header", "Authorization:${TOKENHUB_AUTH}"],
      "env": { "TOKENHUB_AUTH": "Bearer tkh_xxx" }
    }
  }
}
```

**Cherry Studio 等其他客户端**：类型选「可流式传输的 HTTP（streamableHttp）」，填入地址，并添加请求头 `Authorization: Bearer tkh_xxx`。

使用 `--site https://IP`（内部 CA 证书）部署时，客户端所在机器需要信任 TokenHub 的内部 CA；基于 Node 的客户端（如 `mcp-remote`）可设置 `NODE_EXTRA_CA_CERTS=/path/to/ca.crt`。

## 手动验证

```sh
curl -s https://tokenhub.example.com/mcp \
  -H "Authorization: Bearer tkh_xxx" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json, text/event-stream" \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'
```

## 配置项

| 变量 | 默认值 | 说明 |
| --- | --- | --- |
| `MCP_ENABLED` | `true` | 设为 `false` 关闭 `/mcp`（返回 404 `MCP_DISABLED`） |
| `MCP_ALLOWED_ORIGINS` | 空 | 允许调用 `/mcp` 的额外浏览器来源，逗号分隔，如 `https://chat.example.com`。桌面和命令行客户端不发送 `Origin`，不受影响 |

带 `Origin` 头的请求只有在与 TokenHub 自身域名一致或在白名单内时才放行，按 MCP 规范防止 DNS rebinding。

`/v1/capabilities` 的 `mcp` 字段会返回该实例是否启用 MCP、地址和工具列表。

## 常见问题

- **401 `API_KEY_INVALID` / `API_KEY_EXPIRED`**：未带 `Authorization` 头，或 Key 无效、已过期。响应带 `WWW-Authenticate: Bearer`。部分客户端遇到 401 会尝试 OAuth 登录，TokenHub 不提供 OAuth，请在客户端里配置请求头。
- **403 `IP_FORBIDDEN`**：客户端 IP 不在该 Key 的 IP 白名单内。
- **工具返回 `[RAG_KB_FORBIDDEN]` / `[RAG_KB_NOT_FOUND]`**：Key 没有该知识库的读权限，或 ID 写错。先调用 `list_knowledge_bases` 确认。
- **检索提示没有可用知识库**：在后台为该 Key（或其用户、部门）配置知识库绑定，例如「API Key 管理」中该 Key 的知识库绑定。
- **404 页面而不是 JSON**：反向代理没有把 `/mcp` 转发给 PHP。升级后的 Caddy 配置已包含 `/mcp`；自建 Nginx 等需要自行添加。
- **GET /mcp 返回 405**：正常。本服务是无状态的，不提供 SSE 推送流。
