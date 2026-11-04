# Changelog

TokenHub 的版本变更记录。格式参考 [Keep a Changelog](https://keepachangelog.com/zh-CN/1.1.0/)，版本号遵循 [语义化版本](https://semver.org/lang/zh-CN/)。

`deploy/docker/upgrade.sh` 升级时会打印“已安装版本之后、目标版本及之前”的各节内容，因此每个版本标题必须写成 `## [x.y.z] - YYYY-MM-DD`。

## [Unreleased]

## [1.0.0] - 2026-10-31

第一个正式版本。

### 网关与协议

- OpenAI 兼容接口（`/v1/chat/completions`、`/v1/responses`、`/v1/embeddings`、图片、音频、模型列表）和 Anthropic Messages 兼容接口，支持流式输出（SSE）与用量归一化。
- Gemini 原生接口（`/v1beta/*`），可直接对接 Google GenAI SDK。
- 实时语音（`/v1/realtime`，WebSocket），由 Go 编写的 realtime-gateway 转发，不占用 PHP-FPM 进程。
- 多模态：图片、视频、音乐等异步任务，媒体资产自动转存，任务计费结算（按实际用量、只退不补或关闭）。
- 供应商与模型管理：路由策略、健康检查、价格目录同步、模型批量导入。
- 外发请求统一经过出站地址策略（拒绝内网地址、按解析结果固定连接 IP）。

### 密钥、额度与用量

- API 密钥：轮换、批量操作、IP 白名单、模型范围、到期时间。
- 多级额度（用户、部门、密钥），基于 Redis 预占与结算，防止并发超额。
- 用量与成本统计、导出，内容留存与脱敏策略。

### 知识库（RAG）

- 数据集、文档上传审核、分部门访问控制；Python RAG worker 负责解析（PDF、Office、可选 OCR）、切分与向量化。
- 向量库：Qdrant（推荐），或用于小规模数据的 MySQL 兜底；检索质量评估与引用溯源。

### 管理后台与大屏

- 按 A! 设计规范重建的管理后台：组织与权限、供应商、模型、密钥、额度、告警、审计、系统设置中心、证书中心、智能分析。
- 运维大屏与分享链接（访问密码、会话管理、客户端 IP 放行）。
- 管理员找回密码（SMTP）。

### TokenHub APP 管理端

- 可安装的 PWA（`/app/`）：认证链接登录、闪退免登录、设备管理、可选的新设备确认（匹配码）。
- 敏感操作二次验证（PIN / Passkey），告警推送（Web Push、APNs、FCM），前台实时更新。
- iOS / Android（Capacitor）与 macOS / Windows（Tauri）安装包工程，凭证保存在系统钥匙串。

### 智能体与 MCP

- 智能体管理 API（外部系统可管理密钥、额度与用量）。
- 内置 MCP 服务端与 OAuth 2.1 登录，支持 Claude Code、Cursor、Claude Desktop 等客户端。

### 部署与运维

- Docker 部署（Linux / macOS / Windows）：一键安装、升级、备份、恢复、验证脚本；镜像仓库与离线安装包。
- 源码安装（Ubuntu / Debian / RHEL 系），systemd 服务与 Caddy 自动 HTTPS（域名、`https://IP` 内部 CA 或纯 HTTP）。
- `bin/doctor.php` 自检：配置、平台、MySQL、Redis、Qdrant、迁移、worker 心跳，以及 realtime-gateway、RAG worker、SMTP、APNs / FCM 凭证文件和出站连通性。
- 发布流水线：打 `v*` tag 后构建多架构镜像（amd64 / arm64）、离线包和 SHA256SUMS，并发布到 GitHub Release。
- 升级失败时自动回滚镜像 tag；已执行迁移时提示用升级前的备份恢复。
- 备份默认写到源码目录之外（`/var/backups/tokenhub`，否则 `~/tokenhub-backups`）。
- 基础镜像可按 digest 锁定（`docker/release/base-images.lock`），phpredis 固定版本；运行时镜像不含 git、unzip、composer。
- Compose 为 php、mysql、qdrant、rag-worker 设置内存 / CPU 上限（可在 `.env` 中覆盖），并透传 `HTTP(S)_PROXY` / `NO_PROXY`。

### 安全

- 管理后台 CSRF、会话安全 Cookie、审计（含渠道与设备）。
- 生产环境自检拒绝模板密钥和开发用默认口令（`change-me`、compose 回退口令）；APP_URL 为 https 时要求 `SESSION_SECURE=true`。
- Caddy 安全响应头；HTTPS 下发送 HSTS；只有 `/app/` 允许使用相机（扫码登录）。
- APP 凭证防重放：凭证轮换宽限期；丢失轮换结果后的自动恢复要求同一客户端且同一网段（IPv4 /24，IPv6 /48），否则吊销设备。

### 升级说明

- 全新安装无需额外操作。
- 使用 Docker 部署时，`.env` 可新增以下可选项：`BACKUP_DIR`、`PHP_MEMORY_LIMIT`、`MYSQL_MEMORY_LIMIT`、`QDRANT_MEMORY_LIMIT`、`RAG_WORKER_MEMORY_LIMIT`（及对应的 `*_CPUS`）、`HTTP_PROXY`、`HTTPS_PROXY`、`NO_PROXY`。
- 以前的备份位于 `deploy/docker/backups/`，请移到新的备份目录并妥善保管（备份中含 APP_KEY 和数据库口令）。
