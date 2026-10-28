# TokenHub 最终验收清单

本文按 01-11 模块汇总最终验收标准，并补充 12 号测试、部署、安全和恢复要求。

## 01 基础工程

- [ ] Docker 本地环境可以启动 PHP-FPM、Caddy、MySQL、Redis。
- [ ] `php bin/migrate.php` 可重复执行。
- [ ] `php bin/run-tests.php` 全部通过。
- [ ] `/health`、`/admin-api/health` 返回可识别健康状态。
- [ ] Worker 可以消费 `bin/queue-test-job.php` 投递的任务。

## 02 用户权限

- [ ] Admin 可以登录和退出。
- [ ] 用户、部门、角色权限可创建、禁用和查询。
- [ ] 部门权限和角色权限在服务端强制校验。
- [ ] 登录、配置变更和敏感操作写入审计日志。

## 03 厂商模型价格

- [ ] 厂商、模型、价格配置可维护。
- [ ] 未配置价格时仍统计用量。
- [ ] 配置价格后可以查看成本快照和聚合成本。
- [ ] 厂商密钥加密存储，不出现在日志和错误响应中。

## 04 OpenAI/Anthropic 网关

- [ ] `/v1/chat/completions` 支持非流式和 SSE 流式响应。
- [ ] `/v1/responses`、`/v1/embeddings` 可通过兼容格式调用。
- [ ] `/v1/messages` 支持 Anthropic 兼容调用。
- [ ] 网关错误响应不泄漏 provider 密钥或 API Key。

## 05 API Key、限额和用量

- [ ] API Key 明文只在创建时展示一次。
- [ ] Key 授权、停用、过期和 IP 白名单生效。
- [ ] Redis 限额计数在高并发下保持原子性。
- [ ] 用量明细和聚合统计异步写入，不阻塞主链路。

## 06 多模态资产

- [ ] ASR、TTS、视觉、文生图、文生视频入口可调用。
- [ ] 多模态任务状态可查询。
- [ ] 媒体资产按权限生成短期下载链接。
- [ ] 媒体文件路径不暴露内部存储绝对路径。

## 07 内容留存和导出

- [ ] 内容留存策略可查看和修改。
- [ ] 会话存档可查询。
- [ ] CSV 导出任务可创建并生成下载链接。
- [ ] 到期内容、导出和媒体清理任务可入队执行。

## 08 Admin 和智慧大屏

- [ ] Admin 静态资源可由 Caddy 直接服务。
- [ ] 实时、趋势、排行、安全、性能、多模态看板可访问。
- [ ] 大屏聚合接口响应小于 1 秒，或在压测报告中说明瓶颈和样本。
- [ ] 后台权限隐藏仅作为体验优化，服务端权限仍生效。

## 09 大屏分享链接

- [ ] 可创建独立大屏分享链接和密码。
- [ ] `/screen/` 静态页可访问。
- [ ] 分享登录后保持会话，主动退出后才需要重新登录。
- [ ] 分享访问日志可查询和审计。

## 10 安全、WAF 和脱敏

- [ ] Caddy 启用 HTTPS、安全响应头、IP/CIDR 限制和 WAF fragment。
- [ ] WAF 可拦截非法方法、高风险路径、异常 Query 和非授权 IP。
- [ ] Admin Cookie 使用 `HttpOnly`、`Secure`、`SameSite=Strict`。
- [ ] Admin 写操作缺少 CSRF 时返回 419。
- [ ] AST 脱敏和出站脱敏生效。
- [ ] 日志、错误响应、导出文件不包含敏感密钥。

## 11 日志、观测和审计

- [ ] 应用日志、Worker 日志、Caddy 访问日志、安全日志、审计日志分离。
- [ ] 日志留存策略可配置。
- [ ] 日志查询和导出可审计。
- [ ] 队列堆积、WAF 命中异常、日志写入失败有可观测入口。

## 12 测试、部署和恢复

- [ ] `docs/deployment/local.md` 覆盖本地 PHP-FPM、Caddy、MySQL、Redis、Worker。
- [ ] `docs/deployment/production.md` 覆盖生产目录权限、服务账号、日志目录、媒体目录、导出目录。
- [ ] `Caddyfile.example` 可作为生产模板，并可通过 `caddy validate`。
- [ ] `scripts/pressure-smoke.sh` 覆盖非流式、SSE、Key 鉴权、限额和异步日志检查。
- [ ] `scripts/security-smoke.sh` 覆盖 WAF、CSRF、CSP、Cookie flags、Key 泄漏和日志脱敏。
- [ ] `scripts/acceptance-check.sh` 可在无凭据和有凭据两种模式运行。
- [ ] `scripts/backup-tokenhub.sh` 能生成数据库和文件备份。
- [ ] `scripts/restore-drill-check.sh` 能校验备份，并可导入演练库。

## 推荐验收命令

```bash
php bin/run-tests.php
bash -n scripts/pressure-smoke.sh scripts/security-smoke.sh scripts/acceptance-check.sh scripts/backup-tokenhub.sh scripts/restore-drill-check.sh
BASE_URL=https://your-domain.example bash scripts/security-smoke.sh
BASE_URL=https://your-domain.example ADMIN_PASSWORD='admin-password' API_KEY='tkh_xxx' MODEL='model-id' bash scripts/acceptance-check.sh
BASE_URL=https://your-domain.example API_KEY='tkh_xxx' MODEL='model-id' bash scripts/pressure-smoke.sh
DRY_RUN=1 BACKUP_DIR=/backup/tokenhub bash scripts/backup-tokenhub.sh
DRY_RUN=0 CONFIRM_BACKUP=1 BACKUP_DIR=/backup/tokenhub bash scripts/backup-tokenhub.sh
BACKUP_DIR=/backup/tokenhub/latest bash scripts/restore-drill-check.sh
```
