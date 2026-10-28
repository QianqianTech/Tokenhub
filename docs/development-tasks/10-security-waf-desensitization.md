# 10 安全、Caddy 增强 WAF 和脱敏任务清单

## 目标

实现 Caddy 增强 WAF、WAF 规则管理、Caddy 配置发布、AST 脱敏、日志脱敏、出站脱敏和安全审计。

## 数据表

- `waf_rules`
- `waf_rule_versions`
- `waf_events`
- `desensitization_policies`
- `desensitization_events`
- `audit_logs`

## 开发任务

- [x] 定义 WAF 结构化规则模型：规则 ID、作用域、匹配器、动作、优先级、启停、描述。
- [x] 支持 IP/CIDR 白名单和黑名单。
- [x] 支持 HTTP 方法限制。
- [x] 支持高风险路径拦截：`.env`、`.git`、备份文件、调试路径、源码路径。
- [x] 支持 Header 规则：删除、脱敏、阻断。
- [x] 支持 Query 规则：敏感字段脱敏、SQL 注入特征、路径穿越特征。
- [x] 支持请求体大小限制。
- [x] 支持安全响应头：HSTS、CSP、X-Frame-Options、X-Content-Type-Options、Referrer-Policy、Permissions-Policy。
- [x] 实现 WAF 规则后台管理页面。
- [x] 实现 Caddyfile 片段生成。
- [x] 发布前执行 Caddy 配置语法校验。
- [x] Caddy reload 失败时保留旧配置。
- [x] 每次发布生成 `waf_rule_versions`，支持查看差异和回滚。
- [x] WAF 命中写入 `waf_events` 和 `caddy-security.log`。
- [x] 实现 AST 脱敏策略，支持 JSON、YAML、SQL、Env、JavaScript/TypeScript、HTML。
- [x] 脱敏策略可绑定系统、部门、API Key、模型、厂商。
- [x] 脱敏动作支持阻断、替换、只保存摘要。
- [x] 实现出站脱敏，发送上游前处理敏感内容。
- [x] 日志写入前统一脱敏，脱敏失败只记录摘要。

## 测试任务

- [x] 非授权 IP 访问管理端被 Caddy 拦截。
- [x] 非法 HTTP 方法被拦截。
- [x] 访问 `.env`、`.git`、备份路径返回安全响应。
- [x] Query 中 `token`、`key`、`secret` 被阻断或脱敏。
- [x] Caddy 日志中 `Authorization`、`Cookie` 显示为 `****`。
- [x] 发布非法 Caddy 配置不会替换当前有效配置。
- [x] WAF 规则可以回滚到上一版本。
- [x] WAF 命中事件在后台可查询。
- [x] AST 脱敏能识别密钥、连接串、Token、手机号、邮箱。
- [x] AST 解析失败时降级到文本脱敏。
- [x] 启用出站脱敏后敏感字段不会发往外部厂商。

## 验收标准

- Caddy WAF 是入口第一层防护。
- Gateway 仍执行业务权限、限额、脱敏和审计。
- WAF 规则可后台配置、发布、回滚、审计。
- 敏感数据不会进入日志、导出、错误响应和外部厂商请求。
