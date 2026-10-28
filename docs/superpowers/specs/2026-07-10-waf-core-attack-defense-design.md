# WAF 核心攻击防护设计

## 目标

在保留现有 Caddy 规则发布、版本回滚、系统 IP 封禁和 `waf_events` 运营闭环的前提下，为动态请求增加语义检测与风险评分。首期覆盖 SQL 注入、XSS、命令注入、SSRF 和路径穿越，并支持安全的“观察 -> 拦截”灰度切换。

## 范围与边界

- 不变更 `.env`、Docker Compose、Caddyfile 或对外 API 的认证契约。
- Caddy 继续承担危险路径、方法、显式查询特征、请求体大小和已发布静态规则的快速拒绝。
- 新增应用层 `WafInspectionMiddleware`，在全局 `SystemIpBanMiddleware` 之后、路由与业务认证之前执行；它保护 TokenHub 的动态 API，而不是替代 Caddy 的边缘防护。
- `/health` 跳过语义检测；其他动态请求默认受检。静态管理端和屏幕端资源不进入 PHP，因此仍由边缘规则保护。
- 不复制雷池规则或代码；只借鉴其“分层检测、攻击分类、观察与阻断分离”的能力模型。

## 请求检测流程

1. `Request` 已一次性读取原始请求体。中间件只读取现有 `rawBody`、`query`、`body`、`path` 与请求头，不再次读取输入流。
2. `WafRequestInspector` 以受限大小处理候选字段：递归展开 JSON/form 标量、限制字段数量和单字段长度。
3. 对路径、查询键值、请求头和正文值进行确定性规范化：一次 URL 解码、HTML 实体解码、反斜杠统一、空白折叠和 ASCII 小写比较。解码深度与输入长度均受上限控制，防止检测本身成为资源消耗点。
4. 内置检测器对每个候选字段产生无敏感明文的命中项：攻击类别、规则码、位置、权重与脱敏指纹。
5. 累计风险分。命中项按规则码去重；严重单项或累计分数达到阈值即判定为风险请求。
6. 处于 `observe` 时，记录事件并继续请求；处于 `block` 时，记录后返回 `403 WAF_BLOCKED`。检测器内部异常采用 fail-open、写安全日志，不影响业务可用性。

## 检测规则与评分

新增 `attack` matcher 类型，不由 Caddy 编译，而由应用层解释。其结构包含 `categories`、`targets`、`weight`、`critical`、`exclude_paths` 和可选 `mode`。

首批内置规则由 `WafService` 提供模板，并可存入既有 `waf_rules`：

- `attack_sql_injection`：注释/空白规避后的联合查询、布尔表达式和危险 SQL 关键字组合。
- `attack_xss`：脚本上下文、事件属性、危险 URL scheme 和编码后的标签构造。
- `attack_command_injection`：shell 分隔符与命令解释器、下载执行和敏感系统文件组合。
- `attack_ssrf`：特殊 URL scheme、回环/私有/链路本地地址及云元数据入口。
- `attack_path_traversal`：规范化后的 `..` 路径段、双重编码和 Windows 分隔符规避。

默认阈值为 100；普通命中采用权重累计，`critical=true` 的命中立即达到阈值。所有默认规则先以 `observe` 模式启用。规则级模式优先于全局模式，禁用规则不会参与评分。

## 配置与数据契约

`SystemSettingsService` 新增类型化 `waf_detection_settings`：

```json
{
  "enabled": true,
  "mode": "observe",
  "block_threshold": 100,
  "max_inspect_bytes": 65536,
  "max_field_count": 128,
  "max_field_bytes": 4096,
  "excluded_paths": ["/health"]
}
```

扩展 `waf_events` 以记录检测模式、风险分、攻击类别和脱敏指纹。保留 `rule_code`、`request_id`、`client_ip`、`method`、`path`、`action` 和 `summary` 的既有兼容含义；`summary` 不保存原始 query/body/header 值、API key、Cookie 或 Authorization 值。

新增索引覆盖检测时间、风险分和模式，供观察期复盘与误报筛选。迁移通过既有 `MigrationRunner` 注册并对已有数据库安全升级。

## 管理端与发布

- 设置中心增加 WAF 检测配置项：启用状态、全局模式、阈值与检测上限；切换到 `block` 需明确确认。
- WAF 规则编辑器支持攻击类别、目标位置、权重、严重级别、例外路径和规则级模式；不能把 `attack` matcher 当作 Caddy 静态片段生成。
- 规则预检验证 `attack` matcher 的结构、上限和模式。发布快照仍保存全部规则；Caddy 片段只包含可在边缘执行的 matcher。
- 攻击记录页显示模式、风险分、类别和脱敏指纹，并允许将误报标记为 `false_positive`；不提供原始载荷回放。

## 错误处理与安全性

- 原始载荷、认证头、Cookie 与 API key 不进入 `waf_events`、审计日志或错误响应。
- 输入超出检测上限时，仅检查受限前缀和结构化字段；不因检测器截断而阻断合法请求。
- 非法正则、未知类别或无效攻击 matcher 在预检阶段拒绝发布。
- 观察模式事件使用 `action=observe`；阻断事件使用 `action=block` 并返回统一的 `WAF_BLOCKED` 错误码。

## 验证标准

- 五类攻击分别覆盖查询、JSON/form 正文、路径或请求头中的代表性负载与编码规避。
- 正常的模型提示文本、URL 与 API 业务请求在观察模式下继续通过。
- 观察命中返回成功响应并写入脱敏事件；达到阈值的阻断请求返回 403 且不执行下游处理器。
- 禁用、例外路径、规则级模式覆盖和检测器异常均符合设计。
- 既有 `WafService` 发布/回滚、日志导入、IP 封禁及前端 WAF 控制台测试继续通过。
