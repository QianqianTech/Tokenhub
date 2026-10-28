# TokenHub 安装代码与 Docker 部署模式：现状分析与任务清单

分析日期：2026-10-28 · 源码基线：`1ea2251`（Initial import）· 状态：规划，全部任务为 `proposed`

## 0. 范围与前提

本期版本目标理解为提供两种交付方式：

- **源码安装模式**：在 Linux 服务器上直接安装 PHP-FPM、Caddy、MySQL、Redis 和 Worker，由安装脚本完成初始化。
- **Docker 部署模式**：包括在线构建/拉取镜像的生产部署，以及现有的离线镜像包交付。

本次只做了源码与配置的静态核查。用户机器的 Linux VM 中没有 PHP 和 Docker，所以没有构建镜像、启动容器或运行测试。下文标注：

- **已确认**：从源码或配置可以直接确定。
- **需验证**：推断可能性高，需要实际运行确认。

本清单与 `2026-10-28-platform-completeness-audit.md`（TH-001～TH-042）互补。那份清单关注平台业务闭环，本清单只覆盖安装与部署。两者重叠的条目已在第 5 节对应。

## 1. 现状盘点

| 领域 | 已有资产 | 判断 |
| --- | --- | --- |
| 开发 Compose | `docker-compose.yml`：php、queue-worker、rag-worker、caddy、mysql 9.7、redis、qdrant；autoscaler 为可选 profile | 结构完整，但在干净环境中有阻断问题（见 2.1） |
| 离线发布 | `docker-compose.offline.yml`、`docker/release/*`、`package-offline-release.ps1`、`import-offline-release.{ps1,sh}`、离线部署文档 | 只能把源环境（数据和 `.env`）整体搬迁；没有全新安装模式，且有阻断问题 |
| 源码安装（Ubuntu） | `docs/deployment/production.md`、`deploy/ubuntu/*`（FPM pool、worker systemd、self-check、`.env.production.example`）、`Caddyfile.example` | 只有手工步骤文档，没有安装脚本；依赖清单与实际需求不符 |
| 安装/初始化代码 | `bin/migrate.php`、`scripts/migrate.php`、`scripts/create-admin.php`、`scripts/seed-demo.php` | 没有统一安装入口，没有预检、密钥生成和安装锁；迁移器和 Worker 入口各有多套 |
| 备份恢复 | `backup-tokenhub.sh`（只有 mysqldump）、`restore-drill-check.sh`、`backup-restore.md` | 没有覆盖 Qdrant 向量、storage 和 Redis |
| 验收 | `smoke-test.sh`、`acceptance-check.sh`、`security-smoke.sh`、`pressure-smoke.sh`，以及 `tests/Console/*Deployment*` | 部署相关测试主要检查文件中是否包含某个字符串，没有实际构建和启动 |

## 2. 关键问题（按影响排序）

### 2.1 阻断问题：干净环境无法跑通

| # | 问题 | 依据 | 结论 |
| --- | --- | --- | --- |
| B1 | 仓库缺少根目录 `.env.example`。README、`local.md`、开发 entrypoint、`admin-docker-deploy.ps1` 都要求先复制它；release PHP 镜像执行 `COPY .env.example` 会构建失败；`QdrantDeploymentConfigTest` 读取它时会断言失败 | `docker/release/php/Dockerfile:32`；`tests/Console/QdrantDeploymentConfigTest.php:9`；git 中只有 `deploy/ubuntu/.env.production.example` | 已确认 |
| B2 | 开发镜像缺少 `gd` 和 `zip` 扩展。`phpoffice/phpspreadsheet 2.4.6` 强制要求 ext-gd 和 ext-zip，但开发 Dockerfile 只安装了 pdo_mysql 和 redis。entrypoint 中的 `composer install` 会因平台依赖不满足而失败，php 和 queue-worker 会反复重启 | `docker/php/Dockerfile:5`；`docker/php/entrypoint.sh:24`；composer.lock | 已确认（失败现象需验证） |
| B3 | Release PHP 镜像没有包含 `public/`。Caddy 以 `/srv/app/public` 为根目录转发 FastCGI，PHP-FPM 容器中没有 `public/index.php`，导致 `/health`、`/v1/*`、`/admin-api/*` 全部失败。现有测试只检查了 Caddy 镜像是否包含 public | `docker/release/php/Dockerfile:24-32`；`tests/Console/OfflineDockerReleaseTest.php:28` | 已确认 |
| B4 | 离线 Compose 没有把应用配置传给容器。php 和 queue-worker 只收到 `APP_ENV`、`REDIS_PASSWORD` 和 QDRANT 变量，release 镜像内也没有 `.env`。`--env-file` 只用于 Compose 变量插值，所以 `DB_HOST` 和 `REDIS_HOST` 会回落到 127.0.0.1，`APP_KEY` 和 `ADMIN_CSRF_SECRET` 为空 | `docker-compose.offline.yml:1-60`；`config/database.php`；`config/redis.php`；`config/app.php` | 已确认 |
| B5 | ~~开发环境的 Caddy 会 import 一个尚不存在的 WAF 片段~~。2026-10-28 已移除 WAF 功能，开发 Caddyfile 不再 import `waf-current.caddy`，该问题随之消失 | `docker/caddy/Caddyfile` | 已解决（WAF 已移除） |
| B6 | 离线导入不完整，两个平台的脚本行为也不一致。`.sh` 先执行 `down --volumes` 删除所有卷，之后只导入 `mysql.sql`，storage、redis、caddy 卷全部丢失。`.ps1` 会恢复卷。两个脚本都没有恢复已经导出的 Qdrant 快照，迁移后 RAG 向量会丢失 | `scripts/import-offline-release.sh:18-21`；`import-offline-release.ps1:38-41`；`package-offline-release.ps1:103-112` | 已确认 |

### 2.2 结构性缺口

1. **缺少安装器。** 没有统一的“预检 → 生成配置和密钥 → 迁移 → 初始化基础数据 → 创建管理员 → 写安装锁”流程。`create-admin.php` 硬编码 `department_id = 1`，依赖迁移或种子数据已经存在。
2. **迁移器有三套，入口有两个。** 分别是 `Support\MigrationRunner`（列名 `migration`）、`Services\MigrationRunner`（列名 `version`）和 `Console\MigrationRunner`（列名 `migration` 加 `batch`）。README 使用 `bin/migrate.php`，生产文档使用 `scripts/migrate.php`。三个迁移文件前缀重复（`2026_07_08_000002`、`_000008`、`2026_07_09_000009`）。多个容器并发迁移时没有加锁。
3. **Worker 入口有两套。** systemd 使用 `scripts/worker.php`（WorkerService），Docker 使用 `bin/queue-worker.php`（Console\QueueWorker）。
4. **Docker 只有开发模式和离线搬迁模式，没有在线生产部署模式**，也不能用离线包为新客户做全新安装。打包时会把开发者本机的明文 `.env` 一起打进去。
5. **源码安装文档与实际依赖不符。**
   - apt 清单缺少 php-gd、php-zip、php-xml。
   - 文档没有执行 `composer install` 的步骤，而 vendor 不在 git 中，Excel 导入因此不可用。
   - Ubuntu 24.04 默认源没有 php8.2，22.04 默认源是 8.1。
   - apt 中的 `mysql-server` 是 8.0，与文档要求的 9.7 冲突。
   - 文档没有覆盖 Qdrant 和 Python rag-worker。
6. **镜像版本没有固定。** 使用了 `redis:latest`、`qdrant/qdrant:latest`、`caddy:2`、`composer:2`。版本基线也不一致：文档写 PHP 8.2，镜像使用 8.5。
7. **网络与 TLS。** 离线 Compose 只绑定 127.0.0.1，局域网无法访问。release Caddy 只监听 `:80`。`SESSION_SECURE` 默认为 true，通过 `http://IP` 访问时 Secure Cookie 会被丢弃，后台可能无法登录（需验证）。
8. **健康检查失真。** php 和 queue-worker 的健康检查是 `php -v`，只能说明二进制文件存在，不能说明服务可用。
9. **安全与清理。**
   - autoscaler 挂载了 `docker.sock`，相当于拥有宿主机 root 权限。
   - 仓库中提交了 Windows 诊断输出文件：`wsl-*.txt`、`containers-feature-enable.txt`、`docker-services-status.txt`，以及根目录的 `$a`。
   - `admin-docker-deploy.ps1` 硬编码了 `D:\` 路径。

## 3. 任务清单

优先级定义：

- **P0**：阻断干净环境安装或部署。
- **P1**：本期两种模式交付必须具备。
- **P2**：增强项。

### M1 阻断修复：让 Docker 在干净机器上能启动

| 编号 | 优先级 | 任务 | 工作内容 | 验收标准 | 依赖 |
| --- | --- | --- | --- | --- | --- |
| DEP-001 | P0 | 补齐根目录 `.env.example` | 覆盖 `config/*.php` 中全部约 50 个 env 键。Docker 默认值：`DB_HOST=mysql`、`REDIS_HOST=redis`、`QDRANT_URL`。密钥使用占位符，并注明必须修改。与 `.env.production.example` 保持同一组键 | `QdrantDeploymentConfigTest` 通过；release 镜像可以构建；新增测试校验两个模板的键集合与 config 中的 env 键一致 | — |
| DEP-002 | P0 | 开发镜像补齐 PHP 扩展 | 开发和 release 共用一个多阶段 Dockerfile 的 base stage，包含 gd、zip、pdo_mysql、redis。开发镜像改为在构建阶段 `composer install` 或使用缓存卷 | 干净 clone 后执行 `docker compose up -d --build`，php 和 queue-worker 不反复重启 | — |
| DEP-003 | P0 | Release PHP 镜像加入 `public/` | 在 Dockerfile 中 `COPY public`；补测试断言 PHP 镜像同样包含 `public/index.php` | 离线 Compose 下 `/health`、`/ready`、`/admin-api/health` 返回 200 | DEP-001 |
| DEP-004 | P0 | 离线和生产 Compose 注入应用配置 | 为 php、queue-worker、rag-worker 统一配置 `env_file`（或显式 environment），覆盖 DB、REDIS、APP_KEY、CSRF、SESSION、PATH 等变量 | 容器内能读取到正确的 `DB_HOST` 和 `APP_KEY`；缺少关键变量时容器启动失败并给出明确报错 | DEP-001 |
| DEP-005 | P0 | 开发 Caddy 首次启动 | 已随 WAF 功能移除解决：开发 Caddyfile 不再 import WAF 片段；如后续开发 Caddyfile 引入 `tls-current.caddy`，需在 entrypoint 中 touch 生成 | 删除 storage 目录后重新启动，Caddy 正常运行 | 已解决 |
| DEP-006 | P0 | 修复离线导入脚本 | 让 sh 与 ps1 行为一致：恢复全部卷，恢复 Qdrant 快照（读取 `qdrant-index.json`），用健康检查等待替代 `sleep 10`。高风险步骤之前打印即将删除的卷 | 在两个平台上导入同一个包，MySQL 行数、storage 文件数、Qdrant collection 和 point 数与源环境一致 | DEP-003, DEP-004 |
| DEP-007 | P1 | 仓库清理 | 删除 `$a`、`wsl-*.txt`、`containers-feature-enable.txt`、`docker-services-status.txt`；`admin-docker-deploy.ps1` 去掉硬编码路径或移入 examples；补充 `.gitignore` | 仓库根目录只保留项目文件 | — |

### M2 安装代码：统一安装、初始化和升级入口

| 编号 | 优先级 | 任务 | 工作内容 | 验收标准 | 依赖 |
| --- | --- | --- | --- | --- | --- |
| DEP-010 | P0 | 统一安装命令 `bin/install.php` | 流程为：预检 → 生成 `.env`（随机生成 APP_KEY、CSRF、DB/Redis 密码）→ 迁移 → 基础数据（默认部门、角色等）→ 创建超级管理员 → 写安装锁和版本号。同时支持交互模式和非交互模式（`--non-interactive --admin-user ...`） | 空数据库上一条命令完成安装；重复执行不破坏数据，只给出提示；在源码和 Docker 两种模式下行为一致 | DEP-011 |
| DEP-011 | P0 | 统一迁移器 | 保留一套 runner 和一个入口，兼容已有 `migrations` 表的结构。加入 MySQL `GET_LOCK` 防止并发迁移，增加 `--status`/`--pretend`，出错立即停止。处理重复的文件前缀 | 两个容器同时迁移只执行一次；旧库升级不会重复执行已应用的迁移 | — |
| DEP-012 | P1 | 统一 Worker 入口 | 确认 `scripts/worker.php` 与 `bin/queue-worker.php` 覆盖的 job 类型，合并为一个入口；systemd 与 Compose 使用同一个命令 | 两种部署模式下，所有 job 类型都能被同一个入口消费 | — |
| DEP-013 | P0 | 启动配置自检 `bin/doctor.php` | 检查 PHP 版本和扩展、目录可写、MySQL 版本、Redis 与 Qdrant 连通性。生产环境拒绝空值或默认值（`change-me`）的 APP_KEY、CSRF 和密码。检查 SESSION_SECURE 与访问协议是否匹配 | 生产配置有误时进程拒绝启动，并列出逐项原因；`/ready` 复用同一套检查 | 与 TH-005 合并 |
| DEP-014 | P1 | 升级命令 `bin/upgrade.php` | 流程为：读取已安装版本 → 前置备份 → 迁移 → 清缓存 → 健康检查；失败时给出回滚指引 | 从基线版本升级到新版本的演练通过 | DEP-010, DEP-041 |

### M3 Docker 部署模式

| 编号 | 优先级 | 任务 | 工作内容 | 验收标准 | 依赖 |
| --- | --- | --- | --- | --- | --- |
| DEP-030 | P0 | 增加生产 Compose（在线模式） | 新增 `docker-compose.prod.yml`：使用镜像而不是挂载源码，配置通过 env_file 提供，使用命名卷，镜像 tag 可配置；与离线 Compose 共用同一份服务定义，必要时用 override 区分 | 一台只装了 Docker 的机器，按文档 3～5 条命令完成部署 | DEP-001～004 |
| DEP-031 | P0 | Docker 全新安装流程 | 增加一次性 `init`/`migrate` 服务（或安装锁加 entrypoint 的机制），调用 DEP-010。离线包分为“程序包（只有镜像）”和“数据迁移包”两种用法 | 新客户使用不含数据的离线包可以完成全新安装；迁移已有环境仍使用数据包 | DEP-010 |
| DEP-032 | P1 | 固定镜像版本 | redis、qdrant、caddy、composer、mysql 固定到具体版本或 digest；统一 PHP 版本基线（文档 8.2 与镜像 8.5 二选一）；确认 `mysql:9.7` 的 tag 可用 | 两次打包得到的组件版本一致；`docker compose config` 输出中没有 `latest` | — |
| DEP-033 | P1 | 让健康检查反映真实状态 | php 改用 FPM ping 或 `/ready`；queue-worker 和 rag-worker 增加心跳；caddy 增加健康检查；depends_on 全部改为 `service_healthy` | 停掉 MySQL 或 Redis 后，对应服务显示 unhealthy | — |
| DEP-034 | P1 | 端口、域名和 TLS 方案 | 端口绑定地址可配置（默认仍为本机）。提供三种方案：域名加自动证书、内网自签或导入证书、前置负载均衡（配合 `TRUSTED_PROXY_CIDRS`）。明确内网 HTTP 访问时 SESSION_SECURE 的策略 | 三种方案都能正常登录后台并调用 API | DEP-013 |
| DEP-035 | P1 | 重写打包脚本 | 提供跨平台版本（增加 sh 版本，便于 macOS/Linux 使用）；固定 `-f` 和 `--project-directory`；默认不打包明文 `.env`，改为导入时生成；检查 buildx 和 QEMU；输出包清单和体积 | 在 macOS 和 Windows 上都能打出可导入的包 | DEP-006, DEP-031 |
| DEP-036 | P2 | autoscaler 安全收敛 | 文档明确说明 docker.sock 的风险；默认使用固定副本数（`deploy.replicas`），autoscaler 只作为可选项 | 默认部署不挂载 docker.sock | — |
| DEP-037 | P1 | 卷权限与非 root 运行 | 统一 php（www-data）与 rag-worker 对共享 storage 的 UID/GID；容器尽量以非 root 运行 | 上传、导出、RAG 处理在同一个卷上读写正常，没有权限错误 | — |
| DEP-038 | P2 | 日志策略 | 容器日志输出到 stdout，或者对卷内 jsonl 做轮转；补充磁盘占用告警说明 | 长时间运行后磁盘占用可控 | — |

### M4 源码安装模式

| 编号 | 优先级 | 任务 | 工作内容 | 验收标准 | 依赖 |
| --- | --- | --- | --- | --- | --- |
| DEP-020 | P0 | 修正依赖清单 | 确定目标操作系统和版本；补充 php-gd、php-zip、php-xml、php-intl 等扩展；补充 `composer install --no-dev -o` 步骤；PHP 和 MySQL 9.7 使用官方或 PPA 源安装 | 在目标操作系统上按文档从零安装，没有缺失依赖 | DEP-032（版本基线） |
| DEP-021 | P1 | Qdrant 与 RAG Worker | 提供 Qdrant 安装方式（二进制或单容器），以及 Python venv 加 `tokenhub-rag-worker.service`；也可以明确不装 Qdrant 时使用 `RAG_VECTOR_STORE` 的 MySQL 降级方案 | RAG 上传、向量化和检索端到端可用 | — |
| DEP-022 | P1 | 定时任务和日志轮转 | 提供 `/etc/cron.d/tokenhub` 或 systemd timer，以及 logrotate 配置 | 清理任务按计划执行，日志自动轮转 | DEP-012 |
| DEP-023 | P1 | 一键安装脚本 `deploy/ubuntu/install.sh` | 封装上面的步骤，可以重复执行，支持 `--check` 只做预检；最后调用 DEP-010 和 `tokenhub-self-check.sh` | 干净 VM 上一条命令安装完成，自检全部通过 | DEP-010, 020～022 |

### M5 升级、备份、恢复与验收

| 编号 | 优先级 | 任务 | 工作内容 | 验收标准 | 依赖 |
| --- | --- | --- | --- | --- | --- |
| DEP-040 | P1 | 升级与回滚流程（两种模式） | Docker：切换镜像 tag → 备份 → 迁移 → 逐个重启 → 回滚。源码：切换 release 目录加软链。迁移需要设计成可向前兼容 | 演练一次升级和一次回滚，过程中数据和记账不出错 | DEP-014；与 TH-037 合并 |
| DEP-041 | P1 | 备份覆盖全部状态 | `backup-tokenhub.sh` 增加 Qdrant 快照、storage（媒体和导出）、Redis RDB、配置和密钥（单独加密存放）；同时支持两种模式 | 备份清单可以校验，缺失或损坏时报错 | 与 TH-038 合并 |
| DEP-042 | P1 | 恢复演练自动化 | 在隔离环境自动执行：恢复 → 登录 → 模型调用 → RAG 检索 → 下载 → 对账；记录 RTO 和 RPO | 恢复报告自动生成 | DEP-041 |
| DEP-050 | P0 | CI 实际构建与启动验收 | 新增流水线：从干净 clone 构建开发和 release 镜像 → compose up → install → `/ready` → smoke、acceptance、security 脚本；amd64 和 arm64 各跑一次。把字符串匹配式的测试替换为实际行为验证 | 本清单中 B1～B6 这类问题能被流水线发现 | M1；与 TH-036 合并 |
| DEP-051 | P1 | 文档对齐 | README 快速开始；`local.md`（示例密码 `change-me` 与 compose 默认值 `tokenhub_secret` 不一致）；`production.md` 与 Ubuntu README 合并去重；新增环境变量参考表和两种模式的安装手册 | 新成员只看文档就能完成两种模式的部署 | M2～M4 |

## 4. 建议的执行顺序

1. **M1（约 1 周）**：完成 DEP-001～007，加上 DEP-050 的最小版本（干净 clone 加 compose up 加 `/ready`）。先让问题能在流水线上暴露。
2. **M2**：DEP-011 → DEP-010 → DEP-013 → DEP-012 → DEP-014。安装器是两种模式共用的核心。
3. **M3 和 M4 可以并行**：Docker 侧 DEP-030 → 031 → 032～035；源码侧 DEP-020 → 021 → 022 → 023。
4. **M5**：DEP-041 → 042 → 040，再补齐 DEP-050 的完整版本和 DEP-051。

## 5. 与平台完善性清单的对应

| 本清单 | 平台清单 | 说明 |
| --- | --- | --- |
| DEP-013 | TH-005 | 生产主密钥校验合并实施 |
| DEP-040 | TH-037 | 滚动更新、回滚、镜像版本固定 |
| DEP-041, DEP-042 | TH-038 | 完整平台恢复（包括 RAG 向量） |
| DEP-050 | TH-036 | 用行为测试替代静态断言 |
| DEP-034 | Q10 | 部署位置和访问方式 |

## 6. 需要确认的问题

| 编号 | 问题 | 影响的任务 |
| --- | --- | --- |
| Q1 | 源码安装的目标操作系统是哪些：Ubuntu 22.04 或 24.04？是否需要支持麒麟、统信等国产系统和 arm64（鲲鹏）？ | DEP-020, 023, 035 |
| Q2 | Docker 模式是否允许连接镜像仓库，还是只能使用离线包？ | DEP-030, 031, 035 |
| Q3 | 本期主要场景是新客户全新安装，还是把已有环境整体迁移？ | DEP-031, 006 |
| Q4 | 客户如何访问：域名加公网证书、内网 IP 加自签证书，还是前置负载均衡？ | DEP-034 |
| Q5 | RAG 是否必须使用 Qdrant，还是允许使用 MySQL 降级方案？ | DEP-021 |
| Q6 | PHP 版本基线定为 8.2 还是 8.5？phpspreadsheet 2.4.6 要求低于 8.6。 | DEP-002, 020, 032 |

---

## 7. 决策记录（2026-10-28 确认）

| 编号 | 决定 |
| --- | --- |
| Q1 | 支持常见的 Ubuntu、CentOS 系和 Windows。**源码安装**支持 Ubuntu 22.04/24.04、Debian 12、RHEL/CentOS Stream/Rocky/AlmaLinux 8 和 9。**Windows 只走 Docker Desktop**，因为 PHP-FPM 没有 Windows 版本。CentOS 7 已停止维护，不再支持 |
| Q2 | 后续会发布镜像仓库：镜像前缀做成可配置项（`TOKENHUB_IMAGE_PREFIX`），在仓库上线前用离线包或源码构建 |
| Q3 | 以新用户的全新安装为主；已有环境的迁移改用 `backup.sh` + `restore.sh` 完成 |
| Q4 | 域名和 IP 都支持：域名自动签发 HTTPS 证书；`https://IP` 使用 Caddy 内部 CA；纯 IP 走 HTTP；也可以同时配置多个地址 |
| Q5 | 知识库默认使用 **Qdrant**。代码已经对接 Qdrant，支持 payload 过滤和快照，单个二进制或镜像即可运行，并开启 API Key；小规模部署可选 `mysql_fallback` |
| Q6 | PHP 要求 8.2 以上；因 PhpSpreadsheet 2.x 的限制，上限是 8.5。Docker 镜像默认 8.4，可通过 `PHP_VERSION` 修改 |

## 8. 执行记录（2026-10-28）

验证范围：PHP 语法检查、`bin/run-tests.php`（与基线相比没有新增失败，并修复了 `QdrantDeploymentConfigTest`）、新增的 `InstallerTest` 和 `DeploymentModesTest`、`docker compose config` 校验、所有 shell 脚本的 `bash -n` 检查，以及在 Redis 上实际运行 scheduler 和 doctor。

以下项目**尚未实际运行**：镜像构建、整栈启动、MySQL 迁移、在真实操作系统上执行源码安装。执行环境无法访问 Docker Hub 和 apt 源，这些验证已写入 `.github/workflows/deployment.yml`，需要在 CI 或本机 Docker 上跑一次。

| 编号 | 状态 | 实现 |
| --- | --- | --- |
| DEP-001 | 完成 | 根目录新增 `.env.example`，覆盖全部配置键，由测试保证 |
| DEP-002 | 完成 | 开发镜像和发布镜像的扩展保持一致（pdo_mysql、gd、zip），增加 FPM ping、php.ini 和 `ARG PHP_VERSION` |
| DEP-003 | 完成 | 发布镜像 `COPY public`，并补充测试 |
| DEP-004 | 完成 | 生产 Compose 通过 `env_file: .env` 注入配置；php 启动前执行 `doctor --config`，生产配置不安全时拒绝启动 |
| DEP-005 | 完成 | 开发 entrypoint 创建 TLS 片段（WAF 已移除）；caddy 等待 php healthy 后再启动 |
| DEP-006 | 完成（方案调整） | 导入脚本只负责校验和 `docker load`，不再删除数据卷。数据迁移改由 `backup.sh`/`restore.sh` 完成，其中包含 Qdrant |
| DEP-007 | 完成 | 删除 Windows 诊断输出文件和 `$a`；`admin-docker-deploy.ps1` 去掉硬编码路径 |
| DEP-010 | 完成 | `bin/install.php env|run|admin|check`，可重复执行，并把版本记录到 `system_settings.install.state` |
| DEP-011 | 完成 | 只保留一个迁移器：应用全部 `*.sql`（**修复了全新安装时不执行 0001 全量 schema 和 6 个迁移文件的问题**），加 `GET_LOCK`，支持 `--status/--pretend` |
| DEP-012 | 完成 | `scripts/worker.php` 改为委托 `bin/queue-worker.php`；新增 `bin/scheduler.php`，取代 crontab，Docker 中同样生效 |
| DEP-013 | 完成 | `bin/doctor.php`：检查平台、配置和运行时状态；`/ready` 增加 schema 检查 |
| DEP-014 | 完成 | `bin/upgrade.php`，以及 `deploy/docker/upgrade.sh/.ps1`、`deploy/linux/upgrade.sh` |
| DEP-020~023 | 完成（待真机验证） | `deploy/linux/install.sh`：apt/dnf、PPA/Remi、Qdrant、RAG venv、systemd、SELinux、防火墙、logrotate |
| DEP-030/031 | 完成 | `deploy/docker/compose.yml`（init 一次性服务、镜像前缀和 tag 可配置），以及 `install.sh` / `install.ps1` |
| DEP-032 | 完成 | redis 8.0、qdrant v1.15.0、caddy 2.10、mysql 9.7 均通过变量固定版本；首次部署前请确认这些 tag 在镜像源中可用 |
| DEP-033 | 完成 | FPM ping、worker/scheduler 心跳、caddy `:8081` 健康端点 |
| DEP-034 | 完成 | 支持 `TOKENHUB_SITE_ADDRESS`、`TOKENHUB_CADDY_TLS`、`TOKENHUB_CADDY_GLOBAL` 和可配置的绑定地址与端口 |
| DEP-035 | 完成 | 新增 `scripts/release/build-images.sh`（本机加载、推送仓库、导出 tar）；打包脚本提供 sh 和 ps1 两个版本，不再附带 `.env` |
| DEP-036 | 完成 | autoscaler 仍作为可选 profile，默认使用固定副本数 |
| DEP-037 | 完成 | 存储卷归属 www-data，worker 以 www-data 运行，rag-worker 以 uid 33 运行 |
| DEP-038 | 完成 | json-file 日志轮转；源码安装配置 logrotate |
| DEP-040~042 | 完成（待演练） | Docker 的备份、恢复、升级脚本；源码安装的备份增加 Qdrant、存储和 Redis |
| DEP-050 | 完成（待首次运行） | `.github/workflows/deployment.yml` |
| DEP-051 | 完成 | `README.md`、`docs/deployment/{README,docker,linux-source}.md`、`deploy/docker/README.md`，并更新原有文档 |

**补充（第二轮，代码部分）**

- 证书热重载：caddy 容器监测 `tls-current.caddy` 的变化，校验通过后自动 `caddy reload`。管理接口只监听容器内的 `localhost:2019`，不对外暴露。同时把已签发证书的 `.crt` 和 `.json` 文件放开为可读，供 php 容器的证书状态页读取；私钥和内部 CA 的权限保持不变。
- 验收脚本：`deploy/docker/verify.sh` 和 `verify.ps1`，检查容器状态、`/health`、`/ready`、管理后台、未带 Key 的 API 返回 401、自检和迁移状态。`--backup-drill` 会额外演练一次备份和恢复，并记录恢复耗时。检查结果写入报告文件，CI 中也使用该脚本。
- 自检增加可选扩展提示：缺少 opcache 或 pcntl 时给出警告（pcntl 用于长任务的队列租约续期）。

需要注意的遗留问题：

- Docker 模式下，证书中心更换证书后，caddy 容器会自动检测 `tls-current.caddy` 的变化，校验通过后热重载，无需手动重启。（WAF 已由平台完善任务移除。）
- 仍有 5 个 Admin 前端测试和 `ConfigTest` 失败。它们在本任务开始前就已失败，与安装部署无关。
- 本任务与同期进行的平台完善任务（移除 WAF、新增迁移和工具）已做三方合并，合并后没有新增测试失败。
