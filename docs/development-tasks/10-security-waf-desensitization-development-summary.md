# 10 安全 WAF 与脱敏开发总结

## 开发结论

`10-security-waf-desensitization.md` 对应开发任务已完成。当前实现覆盖 Caddy 边缘 WAF、WAF 规则后台管理、Caddyfile 片段发布与回滚、WAF 命中事件导入、结构化内容脱敏、出站脱敏、统一日志脱敏和后台安全资源入口。

本任务延续现有 Native PHP 架构，没有引入新框架；数据继续复用 `waf_rules`、`waf_rule_versions`、`waf_events`、`desensitization_policies`、`desensitization_events` 和 `audit_logs` 等既有表。

## 已完成范围

### Caddy WAF

- `WafService` 支持从后台 `waf_rules` 生成结构化 Caddyfile 片段。
- WAF 规则支持启停、优先级、规则编码、描述、匹配器、动作和作用范围。
- 匹配器覆盖 IP/CIDR、HTTP method、高风险 path、header、query、body size 和安全响应头。
- Query 规则支持敏感键 `token`、`key`、`secret`，并覆盖 SQL 注入和路径穿越特征。
- Header 规则支持删除、替换脱敏和阻断。
- 阻断规则通过 `log_name caddy-security` 写入专用安全日志，并注入 `waf_rule` / `waf_action` 变量，便于后续导入 `waf_events`。
- `docker/caddy/Caddyfile` 增加 `caddy-security.jsonl` 专用安全日志，并对 `Authorization`、`Cookie`、`X-Api-Key`、`Api-Key` 做 `****` 脱敏输出。

### 发布与回滚

- `POST /admin-api/waf/publish` 支持发布当前启用规则。
- `POST /admin-api/waf/rollback` 支持回滚到指定 `version_code`。
- `GET /admin-api/waf/versions` 支持查看发布版本。
- `GET /admin-api/waf/versions/{version_code}/diff` 支持版本差异查看。
- 发布流程采用候选片段、Caddy 配置校验、原子替换、可配置 reload 的链路。
- 发布前校验会把候选片段放到真实 import 路径进行验证，避免只验证旧片段。
- reload 失败时会恢复旧片段，不破坏当前有效 Caddy 配置。
- 发布和回滚均写入 `waf_rule_versions` 与审计日志。

### WAF 命中事件

- Caddy WAF 命中先落入 `storage/logs/caddy-security.jsonl`。
- `WafService::securityEventsFromLog()` 支持解析 Caddy JSONL 安全日志。
- `WafService::ingestSecurityLog()` 使用 offset 文件做增量导入，避免重复写入。
- `bin/import-waf-events.php` 提供命令行导入入口。
- `WorkerService` 支持 `waf.import` 任务类型，可由队列或定时任务触发安全日志导入。
- 导入后的命中事件写入 `waf_events`，后台可通过通用资源页面查询。

### 脱敏与出站保护

- `DesensitizationService` 支持按策略执行出站脱敏。
- 策略作用范围支持 `system`、`department`、`api_key`、`model`、`provider`。
- 策略动作支持 `replace`、`block`、`summary`。
- `replace` 会返回脱敏后的 payload。
- `block` 会在命中敏感内容时返回安全错误，不继续发送上游。
- `summary` 仅记录摘要并避免发送原文。
- 敏感类型覆盖密钥、连接串、Bearer token、手机号、邮箱、密码、API key、secret、token 等。
- 内容识别支持 JSON、YAML、SQL、Env、JavaScript/TypeScript 和 HTML 的结构化优先检测；解析失败时降级到文本脱敏。
- `GatewayService` 在调用外部 provider 前执行出站脱敏，确保敏感字段不会发往上游厂商。
- 脱敏命中写入 `desensitization_events`，事件摘要不记录敏感原文。

### 日志脱敏

- `Logger` / `JsonLogger` 对敏感字段执行统一脱敏。
- `Authorization`、`Cookie`、`token`、`api_key`、`secret`、`password` 等字段不会明文进入日志。
- 脱敏失败时记录摘要并标记 `redaction_failed=true`，避免失败路径泄露原始内容。
- Caddy access log 和 `caddy-security` log 均使用字段过滤规则输出 `****`。

### 后台管理

- Admin 超级管理员资源中加入 WAF 规则、WAF 发布版本、WAF 事件、脱敏策略和脱敏事件。
- WAF 发布版本页面提供发布当前规则、回滚版本和查看差异入口。
- WAF 事件与脱敏事件沿用现有 CRUD 风格查询。
- Admin 前端继续使用静态 ES Modules，不使用 `console`、`innerHTML`、`localStorage`、`sessionStorage` 保存或渲染敏感内容。

## 主要变更文件

- `app/Services/WafService.php`
- `app/Services/DesensitizationService.php`
- `app/Services/GatewayService.php`
- `app/Services/WorkerService.php`
- `app/Support/Logger.php`
- `app/Support/JsonLogger.php`
- `app/Http/Controllers/AdminController.php`
- `app/Bootstrap/Kernel.php`
- `bin/import-waf-events.php`
- `docker/caddy/Caddyfile`
- `Caddyfile.example`
- `public/admin/assets/js/resources.js`
- `tests/Services/WafServiceTest.php`
- `tests/Services/DesensitizationPolicyTest.php`
- `tests/Frontend/AdminFrontendSecurityTest.php`

## 管理接口

- `POST /admin-api/waf/publish`
  - 请求体：`{ "reload": true }`
  - 响应包含 `version_code`、`status`、`fragment_file`、`validated`、`reloaded`。
- `POST /admin-api/waf/rollback`
  - 请求体：`{ "version_code": "waf_xxx", "reload": true }`
  - 复用发布链路的校验、替换、reload 和失败恢复逻辑。
- `GET /admin-api/waf/versions`
  - 返回最近 WAF 发布版本。
- `GET /admin-api/waf/versions/{version_code}/diff?base=previous`
  - 返回当前版本与上一版本或指定版本的规则和片段差异。

## 环境变量

- `CADDY_CONFIG_PATH`
  - 默认：`/srv/app/docker/caddy/Caddyfile`
- `CADDY_WAF_FRAGMENT_PATH`
  - 默认：`/srv/app/storage/caddy/waf-current.caddy`
- `CADDY_VALIDATE_COMMAND`
  - 默认：`caddy validate --config {config}`
- `CADDY_RELOAD_COMMAND`
  - 默认：`caddy reload --config {config}`

本地测试中未假定宿主机存在 Caddy binary；服务通过 fake runner 覆盖 validate/reload 分支，不会在 reload 未执行时静默宣称成功。

## 测试覆盖

新增和强化的测试覆盖：

- `tests/Services/WafServiceTest.php`
  - Caddy WAF 片段生成。
  - IP/CIDR、HTTP method、path、header、query、body size、安全响应头规则。
  - Query 敏感键、SQL 注入和路径穿越匹配。
  - Header 删除、脱敏和阻断。
  - 发布前 Caddy 校验失败不替换当前有效片段。
  - reload 失败恢复旧片段。
  - rollback 使用同一安全安装链路。
  - Caddy security log 增量导入 `waf_events`。
- `tests/Services/DesensitizationPolicyTest.php`
  - JSON、YAML、SQL、Env、JavaScript/TypeScript、HTML 的敏感内容识别。
  - 密钥、连接串、Bearer token、手机号、邮箱脱敏。
  - 解析失败时降级文本脱敏。
  - `replace`、`block`、`summary` 出站动作。
- `tests/Frontend/AdminFrontendSecurityTest.php`
  - Admin 前端不使用 `console`、`innerHTML`、`localStorage`、`sessionStorage`。
  - Caddyfile 保留 CSP、安全响应头和 `caddy-security.jsonl`。
  - Caddy 日志中 `Authorization`、`Cookie` 输出为 `****`。

## 验证结果

已在 Docker Compose 环境执行 Caddy 配置校验：

```sh
docker compose exec caddy caddy validate --config /etc/caddy/Caddyfile
```

执行结果：退出码 `0`，输出 `Valid configuration`。

已执行完整 PHP 测试套件：

```sh
docker compose exec php php bin/run-tests.php
```

执行结果：退出码 `0`，`33 test file(s) passed.`。

已确认任务 10 清单没有剩余未勾选项：

```powershell
Select-String -LiteralPath docs/development-tasks/10-security-waf-desensitization.md -Pattern '\[ \]'
```

执行结果：无未完成项。

## 验收状态

- Caddy WAF 作为入口第一层防护：已完成。
- Gateway 继续执行业务权限、限额、脱敏和审计：已完成。
- WAF 规则可后台配置、发布、回滚、审计：已完成。
- 敏感数据不进入日志、错误响应和外部厂商请求：已完成。
- WAF 命中事件可进入后台查询链路：已完成。
- 出站脱敏命中事件可审计：已完成。

## 运维注意事项

- `waf_events` 导入不是由 Caddy 直接写 MySQL，而是通过 `caddy-security.jsonl` 加 PHP 导入任务完成。
- 生产环境需要将 `bin/import-waf-events.php` 或 worker job `waf.import` 接入定时任务、队列调度或常驻 worker。
- Caddy 发布链路依赖 `CADDY_CONFIG_PATH`、`CADDY_WAF_FRAGMENT_PATH`、`CADDY_VALIDATE_COMMAND` 和 `CADDY_RELOAD_COMMAND`，生产部署时应确认这些路径与容器挂载一致。
- 当前脱敏实现采用结构化格式优先识别并降级文本脱敏的工程方案，覆盖任务要求的 JSON、YAML、SQL、Env、JavaScript/TypeScript 和 HTML 场景；如果后续需要完整语言 AST，可在现有 `DesensitizationService` 边界内替换为专用 parser。
