# Changelog

TokenHub 的版本变更记录。格式参考 [Keep a Changelog](https://keepachangelog.com/zh-CN/1.1.0/)，版本号遵循 [语义化版本](https://semver.org/lang/zh-CN/)。

`deploy/docker/upgrade.sh` 升级时会打印“已安装版本之后、目标版本及之前”的各节内容，因此每个版本标题必须写成 `## [x.y.z] - YYYY-MM-DD`。

## [Unreleased]

## [1.0.2] - 2026-11-04

### 管理后台

- **会话记录**（默认关闭，两级开关）。超级管理员先在「系统设置 › 会话记录设置」中启用会话记录功能，再在「API 接入 › API 密钥」中为单个密钥开启；两者都打开才记录。功能未启用时密钥开关被锁定，关闭功能时可选择同时删除全部记录。
- 新增「API 接入 › 会话记录」：按密钥查看每次向 AI 的提问和回答，以及每次消耗的输入、输出、合计、缓存 Token 和费用；也可按会话查看完整对话。可删除单条问答、整段会话或清空某个密钥的记录。查看、开关和删除都写入审计日志。
- 清理：默认保存天数（密钥可单独设置）、每个密钥最多保留条数、密钥删除后清除其记录、每日自动清理（可关闭）及清理时间与时区；调度器每 5 分钟检查一次（`cleanup.conversation-logs`），到点后每天执行一次。支持手动清理（全部或指定密钥；按保存规则、按日期或全部），执行前可预览条数；最近 30 次清理会列在设置页的清理记录中。
- **独立存储目录、独立数据库与每日压缩**：会话记录完全不进 TokenHub 主数据库。问答内容按“年/月/日/key-密钥ID”写入独立目录（`CONVERSATION_LOG_PATH`，默认 `storage/conversation-logs`，Docker 下在 storage 数据卷中并随 `backup.sh` 备份）；当天逐条加密追加，每天定时把前一天及更早的文件压缩并整体加密为 `.jsonl.gz.enc`，也可“立即压缩”。索引（密钥、会话、时间、Token、费用）存放在独立数据库：默认是该目录下的 SQLite 文件 `index.sqlite`，访问量大时可通过 `CONVERSATION_LOG_DB_DSN` 改用单独的 MySQL 库；目录不可写时内容也只暂存到这个独立数据库。主数据库只保存设置和每个密钥的开关。删除记录时同步改写或删除对应文件，每日任务还会清掉孤立文件。设置页显示目录、独立数据库位置与大小、压缩前后占用。
- **可选本地存储或腾讯云 COS**：在「会话记录设置 › 存储方式」中选择。COS 需先填写 SecretId / SecretKey（加密保存、不回显）、存储桶、地域、路径前缀、可选自定义 Endpoint、存储类型（标准 / 低频），并“测试连接”（写入、读取、列出、删除测试文件）通过后才能选用。选用 COS 后：当天文件仍先写在服务器上，每 5 分钟把有变化的文件上传到 COS；每日任务压缩前一天的文件并上传，上传成功后按“本地保留天数”删除服务器上的副本；查看时本地没有就从 COS 读取；删除单条、整段会话或清理时同步改写或删除 COS 中的文件；可选每天把 SQLite 索引数据库备份到 COS（保留最近 7 份）。改回本地存储后可一键“从 COS 迁回本地”。COS 请求按官方签名算法（q-sign-algorithm=sha1）实现，不依赖 SDK。
- 每次请求只保存新增的一轮和本次回复，系统提示词同一会话只存一次；图片、音频等只记录占位说明。会话按客户端会话 ID、Responses 的 `previous_response_id` 或首条消息自动归并。轮换密钥时新密钥沿用会话记录开关。
- 与“内容留存策略 / 会话归档”相互独立。升级迁移为 `api_keys` 新增会话记录开关与保留天数字段。早期开发版在主库中的记录仍保留在原表中。`bin/rotate-app-key.php` 会一并重加密新版会话记录文件和独立数据库。

### 运营大屏

- 重做总览和运维视图，改进生成任务展示，增加近 48 小时调用趋势、厂商和渠道状态、P95/P99 延迟、数据刷新状态及全屏快捷键。

### 安装与升级

- 提供可独立下载的 `upgrade.sh` 和 `upgrade.ps1`。支持预检、原仓库镜像升级及按 GitHub 标签重新构建。升级前备份，升级后执行验收。见 [Docker 升级指南](docs/deployment/upgrade.md)。
- Docker Hub 版使用 `docker.io/leofan2022/` 下的五个 `1.0.2` 镜像，适用于 `linux/arm64`。同仓库的 `1.0.0` 用户可原地升级到 `1.0.2`。镜像和部署步骤见 [Docker 部署指南](docs/deployment/README.md#从-docker-hub-部署)。
- 本版包含下方 1.0.1 的 RAG 改进。升级前备份数据库和数据卷，部署会执行知识库与 API 密钥迁移。

## [1.0.1] - 2026-11-04

### 知识库检索

- **多轮查询改写**。开启后，系统会结合对话历史将“那个怎么报？”这类追问改写成独立问题再检索。支持自动和每次改写，可在 `rag.rewritten_query` 查看实际检索问题。
- **以文搜图与以图搜图**。图片可用多模态模型直接向量化，支持 OpenAI 混合输入、火山方舟和阿里云百炼的多模态向量接口。对话图片或 `rag.query_image` 可作为检索输入。
- **向量阈值校准与重排**。可用知识库评测集建议相似度阈值，也可配置重排得分下限，让符合条件的候选先进入重排。
- **适配模型输入长度**。可为向量配置设置最大输入 Token 数，超长分块可拆成多个向量并保留文档和章节上下文。
- **紧凑向量副本**。迁移后新向量使用 float32 编码存储，旧 JSON 向量继续可读，支持按批转换。

配置、校准和转换命令见[知识库向量运维](docs/rag-vector-operations.md)。

### 安装与升级

- 增加可独立下载的 GitHub 安装入口 `install.sh` / `install.ps1`，支持环境预检、指定源码版本、只下载模式、独立提交镜像标签及已有目录保护。
- 完善项目首页：功能介绍、平台要求、Linux/macOS/Windows 安装、首次配置、API 调用、升级备份和常见问题。
- 修复 Windows PowerShell 5.1 默认编码导致 `.env` 中文注释反复转码、安装配置截断的问题；安装、升级和备份统一按 UTF-8 读取配置。
- 数据库迁移新增 `rag_vector_records.vector_f32` 列。该阶段的改动合并在 1.0.2 发布，已有 Docker Hub 用户可直接升级至 1.0.2。

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
