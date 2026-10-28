# TokenHub 开发任务清单总览

> **执行方式建议：** 每个模块文档可以独立分配给开发人员或小组。所有实现必须以 `docs/tokenhub-development-guide.md` 为总需求基准。

## 文档拆分

| 编号 | 文档 | 模块 | 主要产出 |
| --- | --- | --- | --- |
| 01 | `01-foundation-php-caddy-mysql.md` | 基础工程 | 原生 PHP 工程、Caddy、MySQL、Redis、队列、配置 |
| 02 | `02-auth-users-departments.md` | 用户权限 | 登录、角色、多级部门、权限范围、审计 |
| 03 | `03-provider-model-price.md` | 厂商模型 | 厂商、模型、可选价格、成本快照 |
| 04 | `04-openai-anthropic-gateway.md` | 兼容网关 | OpenAI/Anthropic API、高速转发、流式响应 |
| 05 | `05-api-key-quota-usage.md` | Key 和计量 | API Key、限额、用量明细、聚合统计 |
| 06 | `06-multimodal-assets.md` | 多模态 | ASR、TTS、视觉、文生图、文生视频、媒体资产 |
| 07 | `07-content-retention-export.md` | 内容留存和导出 | 生成内容留存、会话存档、CSV 导出、短期下载 |
| 08 | `08-admin-js-dashboard.md` | Admin 和智慧大屏 | 原生 JS/TS Admin、统计页面、排行、下钻 |
| 09 | `09-dashboard-share-links.md` | 独立大屏链接 | `/screen/{share_code}`、独立账号、持久会话、访问日志 |
| 10 | `10-security-waf-desensitization.md` | 安全和 WAF | Caddy 增强 WAF、AST 脱敏、安全策略 |
| 11 | `11-logs-observability-audit.md` | 日志审计 | 独立日志、留存、查询、告警、审计 |
| 12 | `12-testing-deployment-acceptance.md` | 测试部署 | 集成测试、压测、部署、备份、验收 |

## 推荐开发顺序

1. 先完成 `01` 基础工程，保证 PHP、Caddy、MySQL、Redis、队列可运行。
2. 并行开发 `02` 用户权限、`03` 厂商模型、`05` Key 和计量的数据基础。
3. 在基础数据可用后开发 `04` 兼容网关，先支持文本和流式响应。
4. 同步推进 `10` 安全 WAF 和 `11` 日志审计，避免后期补安全。
5. 完成 `06` 多模态和 `07` 内容导出。
6. 完成 `08` Admin 和智慧大屏，再接 `09` 独立大屏链接。
7. 用 `12` 做全链路测试、部署和验收。

## 全局约束

- 后端使用 PHP 8.2+ 原生自研服务，不使用 Laravel、Hyperf、ThinkPHP、Symfony 等完整后端框架。
- Caddy 是唯一入口层，负责 HTTPS、反代、增强 WAF、IP/CIDR 限制、安全响应头和静态资源托管。
- MySQL 存储核心业务数据；Redis 用于缓存、限额、会话、队列或队列辅助。
- Admin 使用原生 JavaScript/TypeScript + ES Modules，自研静态页面，通过同域 `/admin-api/*` 调用 PHP。
- 所有密钥、生成内容、日志、导出文件必须按最小权限、脱敏、审计和留存策略处理。
- API 对外提供 OpenAI 兼容格式和 Anthropic 兼容格式。
- 价格配置只用于内部成本查看，可不配置；未配置价格时不影响用量统计。

## 统一交付要求

- 每个模块必须提供数据库迁移脚本或清晰的表结构变更说明。
- 每个模块必须提供 API 请求、响应、错误码和权限校验说明。
- 每个模块必须提供至少一组正常路径、一组权限失败、一组安全失败测试。
- 所有敏感操作必须写入审计日志。
- 所有导出、下载、查看敏感内容的行为必须可审计。
- 管理后台权限隐藏只作为体验优化，服务端必须再次校验。

