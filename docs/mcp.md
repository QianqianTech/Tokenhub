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

## 登录方式

有两种方式，权限完全相同（都等于某一个 API Key 的权限）：

1. **OAuth 登录（推荐）**：客户端里只填地址 `https://tokenhub.example.com/mcp`。首次连接时客户端会打开 TokenHub 的授权页，用户选择：
   - **账号密码**：用 TokenHub 用户名和密码登录；名下有多个可用 Key 时，选择授权哪一个；
   - **粘贴 API Key**：直接粘贴一个 Key。

   授权后客户端拿到访问令牌（1 小时）和刷新令牌（30 天，每次刷新都会轮换），自动续期，不需要把 Key 写进配置文件。
2. **直接带 Key**：在请求头里配置 `Authorization: Bearer tkh_xxx`（见下文各客户端示例），适合不支持 OAuth 的客户端或脚本。

### 各平台怎么连

| 平台 | 方式 | 要求 |
| --- | --- | --- |
| claude.ai、Claude 桌面版（自定义连接器）、ChatGPT（连接器 / 开发者模式） | OAuth | 由平台云端访问 TokenHub，需要 **公网可访问的 https 地址**（设置 `MCP_PUBLIC_URL`）。claude.ai 的回调地址 `https://claude.ai/api/mcp/auth_callback` 已内置放行；有出口白名单时放行 Anthropic 出口 `160.79.104.0/21` |
| Claude Code、Codex CLI、Cursor、VS Code | OAuth 或请求头 | 从用户本机连接，内网地址即可。回调是本机 `127.0.0.1`/`localhost`（任意端口）或应用自定义协议（如 `cursor://`） |
| Cherry Studio 等 | 请求头 | 见下文 |

Claude Code 使用「客户端元数据文档」（client_id 是一个 https 网址），TokenHub 需要能出站访问 `claude.ai`；内网没有外网时设置 `MCP_OAUTH_CLIENT_METADATA_DOCUMENTS=false`，Claude Code 会改用动态注册。

### OAuth 的安全规则

- 只支持授权码 + PKCE（S256）；令牌绑定到本站的 `/mcp`（RFC 8707），在别的服务上无效；授权响应带 `iss`（RFC 9207）。
- 回调地址必须是注册时登记的地址；只允许 https、本机回环地址和原生应用的自定义协议。设置 `MCP_OAUTH_REDIRECT_URI_ALLOWLIST` 后，只接受白名单内的回调（以及 claude.ai / ChatGPT 的固定回调）。
- 客户端 ID 或回调地址校验失败时，授权页只显示错误，不会跳转（防开放重定向）；授权页不能被嵌入其他网页（防点击劫持），且只能在发起连接的同一个浏览器里完成。
- 登录按 IP 和账号限流；未知用户名与错误密码返回同样的提示和耗时。
- 刷新令牌被重复使用（超过 60 秒宽限期）会被视为泄露，整个授权立即作废。
- 数据库只保存授权码、令牌和客户端密钥的 HMAC 哈希。
- 以下情况授权失效：对应的 Key 被停用、过期或删除（Key 重新启用后恢复）；用户被停用或其部门被停用；**用户修改密码**（立即撤销该用户所有 MCP 授权）；管理员在后台撤销。

### 管理

后台「API 密钥」列表每行的「MCP 授权」按钮可查看用这个 Key 登录的客户端、登录方式、最近使用时间，并逐个撤销。接口：`GET /admin-api/api-keys/mcp-oauth-grants?api_key_id=`、`POST /admin-api/api-keys/mcp-oauth-grants/{grant_id}/revoke`（审计记录 `mcp_oauth.authorize` / `mcp_oauth.grant.revoke`）。过期数据由调度任务 `cleanup.mcp-oauth` 每小时清理。

发现文档：`/.well-known/oauth-protected-resource/mcp`（RFC 9728）、`/.well-known/oauth-authorization-server`（RFC 8414）。端点：`/oauth/register`（RFC 7591）、`/oauth/authorize`、`/oauth/token`、`/oauth/revoke`（RFC 7009）。自建反向代理需要把 `/oauth/*` 和上述 `/.well-known/` 路径转发给 PHP（自带的 Caddy 配置已包含），并且不要对授权页加 `form-action 'self'` 的 CSP（会拦截授权后跳回客户端）。

## 客户端配置

下文的 `tkh_xxx` 换成在后台「API Key 管理」中创建的 Key。建议给 MCP 单独建一个 Key，只绑定需要开放的知识库。支持 OAuth 的客户端可以不配请求头，只填地址，首次连接时在浏览器里登录。

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
| `MCP_OAUTH_ENABLED` | `true` | 内置 OAuth 登录。设为 `false` 后只能用请求头带 Key |
| `MCP_PUBLIC_URL` | 空（用 `APP_URL`，再退回请求的域名） | 对外地址，作为 OAuth 签发方和资源地址，如 `https://tokenhub.example.com` |
| `MCP_OAUTH_DYNAMIC_REGISTRATION` | `true` | 允许客户端动态注册（RFC 7591） |
| `MCP_OAUTH_CLIENT_METADATA_DOCUMENTS` | `true` | 接受 https 网址形式的 client_id（需要出站访问） |
| `MCP_OAUTH_PASSWORD_LOGIN` / `MCP_OAUTH_API_KEY_LOGIN` | `true` | 授权页提供的登录方式 |
| `MCP_OAUTH_ACCESS_TOKEN_TTL_SECONDS` / `MCP_OAUTH_REFRESH_TOKEN_TTL_SECONDS` | `3600` / `2592000` | 令牌有效期 |
| `MCP_OAUTH_REFRESH_GRACE_SECONDS` | `60` | 并发刷新的宽限期 |
| `MCP_OAUTH_REDIRECT_URI_ALLOWLIST` | 空 | 回调地址白名单（前缀，逗号分隔）；为空时接受任意 https / 本机 / 自定义协议回调 |
| `MCP_OAUTH_LOGIN_ATTEMPTS_PER_MINUTE` / `MCP_OAUTH_AUTHORIZE_PER_MINUTE` | `10` / `30` | 授权页限流 |

带 `Origin` 头的请求只有在与 TokenHub 自身域名一致或在白名单内时才放行，按 MCP 规范防止 DNS rebinding。

`/v1/capabilities` 的 `mcp` 字段会返回该实例是否启用 MCP、地址和工具列表。

## 常见问题

- **401 `API_KEY_INVALID` / `API_KEY_EXPIRED`**：未带凭据，或 Key / 访问令牌无效、已过期。响应带 `WWW-Authenticate: Bearer resource_metadata="…"`，支持 OAuth 的客户端会据此打开授权页。
- **claude.ai 提示无法连接或授权失败**：确认 `MCP_PUBLIC_URL` 是公网 https 地址，且 `/.well-known/oauth-protected-resource/mcp` 能从公网访问。
- **授权页提示“登录链接已过期或在另一个浏览器中打开”**：授权需要在同一个浏览器里 10 分钟内完成，回到客户端重新连接即可。
- **403 `IP_FORBIDDEN`**：客户端 IP 不在该 Key 的 IP 白名单内。
- **工具返回 `[RAG_KB_FORBIDDEN]` / `[RAG_KB_NOT_FOUND]`**：Key 没有该知识库的读权限，或 ID 写错。先调用 `list_knowledge_bases` 确认。
- **检索提示没有可用知识库**：在后台为该 Key（或其用户、部门）配置知识库绑定，例如「API Key 管理」中该 Key 的知识库绑定。
- **404 页面而不是 JSON**：反向代理没有把 `/mcp` 转发给 PHP。升级后的 Caddy 配置已包含 `/mcp`；自建 Nginx 等需要自行添加。
- **GET /mcp 返回 405**：正常。本服务是无状态的，不提供 SSE 推送流。
