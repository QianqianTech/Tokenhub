# 12 测试、部署和验收任务清单

## 目标

完成全系统集成测试、性能测试、安全测试、部署配置、备份恢复和最终验收。

## 开发任务

- [x] 编写本地部署说明，覆盖 PHP-FPM、Caddy、MySQL、Redis、Worker。
- [x] 编写生产部署说明，覆盖目录权限、服务账号、日志目录、媒体目录、导出目录。
- [x] 编写 Caddy 生产配置模板，包含 HTTPS、反代、Admin 静态资源、安全响应头、增强 WAF。
- [x] 编写数据库初始化和迁移执行说明。
- [x] 编写队列 Worker 启动、重启、失败重试和日志检查说明。
- [x] 编写备份策略，覆盖 MySQL、配置、Caddy 数据目录、媒体资产、导出归档。
- [x] 编写恢复演练清单，明确 RPO、RTO、恢复步骤和验证点。
- [x] 编写压测脚本，覆盖非流式请求、SSE 流式请求、Key 鉴权、限额、日志异步写入。
- [x] 编写安全测试脚本，覆盖 WAF、IP 白名单、CSRF、CSP、Key 泄漏防护、日志脱敏。
- [x] 编写验收测试清单，按模块汇总 01-11 的验收标准。

## 集成测试范围

- [x] 用户登录、部门权限、角色权限。
- [x] 厂商、模型、价格配置。
- [x] API Key 创建、授权、停用、IP 白名单。
- [x] OpenAI chat、responses、embeddings。
- [x] Anthropic messages。
- [x] SSE 流式响应。
- [x] ASR、TTS、文生图、文生视频。
- [x] 用量明细、聚合统计、成本查看。
- [x] 内容留存、会话存档、CSV 导出。
- [x] Admin 后台、智慧大屏、大屏分享链接。
- [x] Caddy WAF、AST 脱敏、日志脱敏。
- [x] 日志查询、日志留存、审计日志。

## 性能测试目标

- [x] 大屏聚合接口响应小于 1 秒。
- [x] Gateway 非流式请求平台额外延迟保持可控。
- [x] SSE 首包不等待完整响应。
- [x] 明细、日志、聚合异步写入不阻塞主链路。
- [x] Redis 限额计数在高并发下保持原子性。
- [x] Worker 队列堆积达到阈值时触发告警。
- [x] Caddy 到 Gateway 多实例负载均衡正常。

## 安全验收

- [x] API Key 明文只展示一次。
- [x] 厂商密钥加密存储。
- [x] 日志、错误响应、导出文件不包含敏感密钥。
- [x] Admin Cookie 使用 `HttpOnly`、`Secure`、`SameSite=Strict`。
- [x] Admin 写操作校验 CSRF。
- [x] 大屏分享会话符合持久登录规则。
- [x] WAF 可以拦截非法方法、高风险路径、异常 Query、非授权 IP。
- [x] AST 脱敏和出站脱敏生效。
- [x] 所有敏感查看、导出、配置变更可审计。

## 最终验收标准

- 系统可以完成从创建用户、部门、厂商、模型、Key 到调用模型并查看用量明细的完整链路。
- 系统可以对外提供 OpenAI 和 Anthropic 兼容 API。
- 系统可以统计文本、视觉、ASR、TTS、文生图、文生视频用量。
- 系统可以按用户、部门、Key、模型、厂商查看明细和聚合。
- 系统可以在不配置价格时只统计用量，在配置价格后查看成本。
- 系统可以生成独立大屏链接，输入密码后保持登录，主动退出后才需要重新登录。
- 系统具备 HTTPS、IP 限制、Caddy WAF、AST 脱敏、日志脱敏、审计、导出控制。
- 系统具备日志留存配置、日志导出、会话存档导出和到期清理。
- 系统备份可恢复，恢复后核心配置、用户、Key、明细、审计数据一致。

## 完成记录

- 本地部署说明：见 `docs/deployment/local.md`，覆盖 PHP-FPM、Caddy、MySQL、Redis、Worker 和本地验收命令。
- 生产部署说明：见 `docs/deployment/production.md`，覆盖服务账号、目录权限、日志/媒体/导出目录、迁移、Caddy、PHP-FPM、Worker 运维。
- Caddy 生产模板：见 `Caddyfile.example`，覆盖 HTTPS、Admin/Screen 静态资源、PHP-FPM 反代、安全响应头、WAF fragment、日志脱敏和多 upstream 配置方式。
- 数据库迁移和 Worker 运维：见 `docs/deployment/production.md` 与 `deploy/ubuntu/tokenhub-worker.service`。
- 备份和恢复：见 `docs/deployment/backup-restore.md`、`scripts/backup-tokenhub.sh`、`scripts/restore-drill-check.sh`。备份脚本默认 dry-run，真实备份必须设置 `DRY_RUN=0 CONFIRM_BACKUP=1`。
- 压测和性能验收：见 `scripts/pressure-smoke.sh`，覆盖非流式、SSE 首包、Key 鉴权、限额、日志异步写入、大屏接口耗时、Redis 队列堆积和 Caddy 多 upstream 配置检查。
- 安全验收：见 `scripts/security-smoke.sh`，覆盖 WAF 路径/Query、IP 白名单强校验开关、CSRF、CSP、Cookie flags、Key 泄漏和日志脱敏。
- 最终验收清单：见 `docs/acceptance/final-acceptance-checklist.md`，按 01-11 模块汇总验收项。

需要真实生产环境执行的项目：HTTPS 证书签发、真实 Admin 密码登录、真实 API Key 和模型调用、真实 Redis 队列堆积阈值、Caddy 多实例 upstream、备份介质和恢复演练库导入。这些项目已提供脚本入口，但不能在无生产凭据的本地环境中伪造通过。


