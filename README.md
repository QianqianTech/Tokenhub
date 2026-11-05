# TokenHub

**面向团队和企业的大模型统一接入、权限与用量管理平台。**

TokenHub 将不同供应商的模型接入统一网关，集中管理 API Key、用户与部门、调用额度、成本、知识库和审计记录。业务系统使用 TokenHub 发放的 Key 调用模型，无需各自保存厂商密钥；管理员可以在一个后台查看模型状态、分配资源、追踪用量并处理告警。

[Docker Hub 快速安装](#docker-hub-快速安装) · [从源码构建](#从源码构建) · [首次使用](#首次使用) · [升级与备份](#升级与备份) · [版本与新增功能](docs/deployment/versions.md) · [常见问题](#常见问题) · [文档](#文档) · [公司信息](#公司信息) · [许可](#许可--license)

## 可以做什么

| 能力 | 说明 |
| --- | --- |
| 统一模型网关 | OpenAI / Anthropic 兼容接口、Gemini 原生接口、SSE 流式输出，为应用提供统一入口 |
| 多供应商与路由 | 厂商和模型目录、模型导入、路由优先级、健康检查、渠道限流与故障处理 |
| API Key 与权限 | 按用户、部门和 Key 分配模型权限，设置有效期、IP 白名单、请求/Token/成本额度 |
| 用量与成本 | 查询调用记录、Token 消耗、延迟、计价及结算，支持导出与审计 |
| 多模态 | 图片、语音、视频等能力接入，异步任务与媒体资产管理；可用能力取决于上游模型及配置 |
| 知识库 RAG | 文档上传、解析、切分、向量化、检索、引用与质量评估，支持部门和 Key 访问范围 |
| 智能体与 MCP | 对外管理 API 与 MCP 服务，供业务系统及兼容客户端接入 |
| 管理与运维 | Web 后台、运维大屏、告警、SMTP 密码找回、日志、自检与备份恢复 |
| 移动管理 | `/app/` 管理端、设备授权、二次验证与可选推送；另有 Capacitor / Tauri 原生工程 |

适用于统一管理团队模型账号、为内部应用提供模型网关、按部门分摊用量、搭建带权限的企业知识库，以及对接智能体和自动化系统。

TokenHub 不提供模型权重或免费的模型调用额度。安装后需要配置自己的模型服务地址与厂商 Key；实际调用费用由相应服务产生。原生应用工程的构建和分发见 [native/README.md](native/README.md)，服务器安装脚本不会安装手机或桌面客户端。

## 系统组成

```mermaid
flowchart LR
    client[业务系统 / SDK / MCP 客户端] --> caddy[Caddy HTTP / HTTPS]
    admin[管理后台 / APP / 大屏] --> caddy
    caddy --> php[PHP 网关与管理 API]
    caddy --> realtime[Go 实时语音网关]
    php --> providers[模型供应商]
    realtime --> providers
    php --> mysql[(MySQL)]
    php --> redis[(Redis)]
    php --> qdrant[(Qdrant)]
    redis --> workers[队列 / 调度器 / Python RAG worker]
    workers --> mysql
    workers --> qdrant
```

Docker 安装会获取应用镜像并启动 Caddy、PHP-FPM、MySQL、Redis、Qdrant、队列 worker、调度器、RAG worker 和 realtime-gateway。数据保存在 Docker 命名卷中，生产配置位于 `deploy/docker/.env`。主机无需另外安装 PHP、MySQL、Python 或 Go。

## 快速安装

### Docker Hub 快速安装

TokenHub `1.0.3` 已推送以下五个 `linux/arm64` 镜像。安装脚本会按 Compose 配置拉取所需镜像。

| Docker Hub 仓库 | 完整镜像地址 |
| --- | --- |
| [tokenhub-php](https://hub.docker.com/r/leofan2022/tokenhub-php/tags?name=1.0.3) | `docker.io/leofan2022/tokenhub-php:1.0.3` |
| [tokenhub-caddy](https://hub.docker.com/r/leofan2022/tokenhub-caddy/tags?name=1.0.3) | `docker.io/leofan2022/tokenhub-caddy:1.0.3` |
| [tokenhub-rag-worker](https://hub.docker.com/r/leofan2022/tokenhub-rag-worker/tags?name=1.0.3) | `docker.io/leofan2022/tokenhub-rag-worker:1.0.3` |
| [tokenhub-realtime-gateway](https://hub.docker.com/r/leofan2022/tokenhub-realtime-gateway/tags?name=1.0.3) | `docker.io/leofan2022/tokenhub-realtime-gateway:1.0.3` |
| [tokenhub-worker-autoscaler](https://hub.docker.com/r/leofan2022/tokenhub-worker-autoscaler/tags?name=1.0.3) | `docker.io/leofan2022/tokenhub-worker-autoscaler:1.0.3` |

先安装 Git、Docker 和 Compose 2.20 或以上版本，并确认 `docker version --format '{{.Server.Os}}/{{.Server.Arch}}'` 输出 `linux/arm64`。

在 Linux、macOS 或 WSL 2 上执行以下命令。示例仅允许本机访问。

```bash
git clone --depth 1 --branch v1.0.3 https://github.com/QianqianTech/Tokenhub.git tokenhub &&
cd tokenhub &&
bash deploy/docker/install.sh \
  --image-prefix docker.io/leofan2022/ --tag 1.0.3 \
  --site http://localhost --http-port 8080 --https-port 8443 \
  --bind 127.0.0.1 --yes
```

安装成功后，用终端显示的初始账号和密码登录 `http://localhost:8080/admin/`。在安装目录运行 `bash deploy/docker/verify.sh` 可检查服务状态。单独拉取镜像、PowerShell 安装、公网部署和原地升级见[完整 Docker Hub 部署指南](docs/deployment/README.md#从-docker-hub-部署)。[镜像摘要](https://github.com/QianqianTech/Tokenhub/releases/download/v1.0.3/docker-hub-digests.tsv) · [1.0.3 更新说明](CHANGELOG.md#103---2026-11-05)

### 从源码构建

源码构建适用于 AMD64 和 ARM64。

#### 1. 准备环境

| 项目 | 要求 |
| --- | --- |
| 系统 | Linux；macOS + Docker Desktop；Windows + Docker Desktop（Linux 容器，建议 WSL 2 后端） |
| 架构 | x86_64 / AMD64 或 ARM64；主机系统需满足 Docker 的支持要求 |
| Docker | Engine 24+，Compose 2.20+；Docker Desktop 用户需先启动应用 |
| 下载工具 | Git；Linux/macOS 下载入口脚本还需要 curl，Windows 使用 PowerShell 5.1+ |
| 资源 | 建议从 4 核 CPU、8 GB 内存、50 GB 可用磁盘起步；大量文档/OCR/并发调用需要增加资源 |
| 网络 | 能访问 GitHub、镜像仓库和构建依赖源；模型调用还需能访问上游服务 |
| 端口 | 默认 80 / 443；本机体验可改为 8080 / 8443 |

安装依赖：[Git](https://git-scm.com/downloads)、[Linux Docker Engine](https://docs.docker.com/engine/install/)、[Windows Docker Desktop](https://docs.docker.com/desktop/setup/install/windows-install/)、[macOS Docker Desktop](https://docs.docker.com/desktop/setup/install/mac-install/)。安装入口会检测依赖，不自动修改系统软件源或安装 Docker。

安装前确认以下命令正常返回：

```sh
git --version
docker info
docker compose version
```

#### 2. Linux / macOS：下载脚本并安装

在准备保存 TokenHub 的目录中执行。下面的例子仅在本机开放 `http://localhost:8080`：

```bash
curl --fail --show-error --location --retry 3 \
  https://raw.githubusercontent.com/QianqianTech/Tokenhub/main/install.sh \
  --output tokenhub-install.sh && \
bash tokenhub-install.sh --site http://localhost \
  --http-port 8080 --https-port 8443 --bind 127.0.0.1 --yes
```

默认源码目录为当前目录下的 `tokenhub/`。首次安装从 GitHub 的 `main` 获取源码，固定到本次下载的提交并在本机构建镜像；终端会打印提交 SHA，镜像使用 `source-<提交前12位>` 标签。构建耗时取决于网络和机器性能，请保留终端直到安装完成。

**服务器域名部署：** 先将域名解析到服务器，放通防火墙/安全组的 80 和 443，然后执行：

```bash
bash tokenhub-install.sh --dir /opt/tokenhub --site tokenhub.example.com --yes
```

将示例域名换成自己的域名；安装用户需要有目标目录写权限和 Docker 访问权限。普通用户也可以选择 `--dir "$HOME/tokenhub"`。Caddy 为可验证的公网域名自动申请 HTTPS 证书。

**局域网部署：** 把 IP 换成实际服务器地址。HTTP 仅适合可信内网；HTTPS IP 模式使用内部 CA，客户端需要信任其根证书。

```bash
bash tokenhub-install.sh --site http://10.0.0.8 --http-port 8080 --yes
# 或在另一台全新环境使用 HTTPS IP 入口：
bash tokenhub-install.sh --site https://10.0.0.8 --yes
```

更多网络和证书说明见 [Docker 部署包文档](deploy/docker/README.md#访问方式说明)。同一台机器上的多套部署需要分别设置 Compose 项目名和端口；默认项目名为 `tokenhub`，改变下载目录不会自动隔离另一套实例。

#### 3. Windows：下载 PowerShell 安装脚本

先安装 Git 和 Docker Desktop，启动 Docker Desktop 并切换为 Linux 容器。在 PowerShell 中执行：

```powershell
Invoke-WebRequest -UseBasicParsing -ErrorAction Stop `
  -Uri "https://raw.githubusercontent.com/QianqianTech/Tokenhub/main/install.ps1" `
  -OutFile ".\tokenhub-install.ps1"
if ($?) {
  powershell -NoProfile -ExecutionPolicy Bypass -File .\tokenhub-install.ps1 `
    -Site http://localhost -HttpPort 8080 -HttpsPort 8443 -Bind 127.0.0.1 -Yes
}
```

服务器部署可改用 `-Directory C:\TokenHub -Site tokenhub.example.com -Yes`。`ExecutionPolicy Bypass` 仅用于本次进程，不修改系统执行策略。WSL 2 用户也可以在 Linux 终端使用前面的 Bash 入口。

#### 4. 安装脚本参数

| Bash | PowerShell | 作用 |
| --- | --- | --- |
| `--dir PATH` | `-Directory PATH` | 指定新的源码目录，默认 `./tokenhub`；已有目录会被拒绝，避免覆盖配置和代码 |
| `--ref REF` | `-Ref REF` | 获取指定分支、已有 tag 或完整提交 SHA；默认 `main` |
| `--check` | `-Check` | 仅检查前置环境，不下载、不启动服务 |
| `--download-only` | `-DownloadOnly` | 仅下载源码，不要求 Docker，不生成配置 |
| `--site ADDRESS` | `-Site ADDRESS` | 域名、`https://IP` 或 `http://IP` |
| `--http-port N` / `--https-port N` | `-HttpPort N` / `-HttpsPort N` | 宿主机端口，默认 80 / 443 |
| `--bind ADDRESS` | `-Bind ADDRESS` | 默认 `0.0.0.0`；本机体验选 `127.0.0.1` |
| `--admin-user NAME` | `-AdminUser NAME` | 首个管理员用户名，默认 `admin`；密码随机生成 |
| `--vector-store NAME` | `-VectorStore NAME` | `qdrant` 或小规模数据使用的 `mysql_fallback`；默认 Compose 仍包含 Qdrant 服务 |
| `--no-start` | `-NoStart` | 下载并生成 `.env`，不构建或启动；仍检查 Docker |
| `--yes` | `-Yes` | 非交互运行；未指定站点时使用默认 HTTP 入口 |
| `--help` | `-Help` | 查看帮助 |

`--ref` 固定应用源码版本。要同时固定安装入口，请把下载 URL 中的 `main` 换成含有该入口脚本的同一个提交 SHA 或 tag。`main` 是持续更新的开发分支；生产部署应选定经过自己验收的提交。基础镜像和构建依赖的锁定说明见 [发布文档](docs/deployment/docker.md)。

只获取源码，供审阅或稍后安装：

```bash
bash tokenhub-install.sh --dir ./tokenhub-source --download-only
```

已有完整源码、无法直接下载入口脚本或需要离线/无 Docker 安装时，见 [其他安装方式](docs/deployment/README.md)。

## 首次使用

安装流程会生成随机密钥和数据库密码、执行迁移、创建首个超级管理员、启动服务并运行自检。终端最后显示后台地址、用户名和初始密码。自检告警需要继续排查，出现访问地址并不代表所有可选能力已配置完成。

以本机体验命令为例：

| 入口 | 地址 |
| --- | --- |
| 管理后台 | <http://localhost:8080/admin/> |
| 移动管理页面 | <http://localhost:8080/app/> |
| 运维大屏 | <http://localhost:8080/screen/> |
| OpenAI 兼容 Base URL | `http://localhost:8080/v1` |
| 存活 / 就绪检查 | <http://localhost:8080/health> / <http://localhost:8080/ready> |

1. 登录后台，修改管理员密码，然后从 `tokenhub/deploy/docker/.env` 移除 `TOKENHUB_ADMIN_PASSWORD`。妥善保管整份 `.env`，特别是用于解密厂商密钥的 `APP_KEY`。
2. 在“厂商”配置自己的模型服务地址、协议与 Key；在“模型”导入或添加模型，确认上游模型名、能力和启用状态。
3. 建立部门和用户，为其创建 API Key，授权可用模型并设置适当的额度和价格。
4. 通过模型列表确认权限，再发起一次调用。在用量明细中检查记录、计价和错误信息。
5. 有知识库需求时，再配置嵌入模型、数据集和文档处理流程；邮件、推送等按需配置。

调用示例（Bash；替换 Key 和模型名）：

```bash
export TOKENHUB_BASE_URL='http://localhost:8080/v1'
read -r -s -p 'TokenHub API Key: ' TOKENHUB_API_KEY; echo
export TOKENHUB_API_KEY

curl --fail-with-body "$TOKENHUB_BASE_URL/models" \
  -H "Authorization: Bearer $TOKENHUB_API_KEY"

curl --fail-with-body "$TOKENHUB_BASE_URL/chat/completions" \
  -H "Authorization: Bearer $TOKENHUB_API_KEY" \
  -H 'Content-Type: application/json' \
  -d '{"model":"替换为模型列表中的id","messages":[{"role":"user","content":"你好，请介绍一下你自己。"}]}'
```

使用 OpenAI 兼容客户端时，将 Base URL 设置为平台的 `/v1` 地址，将 API Key 设置为 TokenHub 发放的 Key。Anthropic、Gemini、多模态、知识库与 MCP 的协议差异见 [对外 API 文档](docs/external-api-complete.md) 和 [MCP 接入文档](docs/mcp.md)。

## 日常管理

以下命令在 **生产部署目录** 执行，避免误用仓库根目录的开发 Compose：

```bash
cd tokenhub/deploy/docker
docker compose ps                              # 服务状态；init 正常为 exited (0)
docker compose logs --tail=100 php caddy init    # 查看启动或请求错误
docker compose exec -T php php bin/doctor.php   # 配置与运行状态自检
bash verify.sh                                 # 部署验证，输出检查报告
```

Windows 可以使用相同的 `docker compose` 命令，验证脚本使用 `powershell -File .\verify.ps1`。改动 `.env` 后执行 `docker compose up -d` 让容器重新加载环境变量；仅 `restart` 不会刷新配置。

## 升级与备份

已有 Docker 实例请按 [Docker 升级指南](docs/deployment/upgrade.md)下载独立入口 [upgrade.sh](upgrade.sh)（Linux/macOS/WSL）或 [upgrade.ps1](upgrade.ps1)（Windows）。指定原安装目录和目标版本，用 `--check` / `-Check` 预检，确认后执行升级。脚本会备份、更新应用、执行迁移并检查服务。

- **镜像仓库安装**：用 `--tag 1.0.3` / `-Tag 1.0.3` 从原仓库升级；本次 Docker Hub 镜像适用于 `linux/arm64`。
- **GitHub 源码构建安装**：用 `--ref v1.0.3` / `-Ref v1.0.3` 获取正式发布标签并重新构建。

选择其他目标版本前，请确认该版本是否提供对应的镜像或源码标签。[版本与新增功能](docs/deployment/versions.md)

首次安装中断时，回到原下载目录重试 `bash deploy/docker/install.sh --build` 或 `powershell -File .\deploy\docker\install.ps1 -Build`。若尚未生成 `.env`，请带上首次安装的站点、端口等参数。

切回镜像不会撤销数据库迁移。备份位置、定时任务和数据恢复步骤见 [备份与恢复](docs/deployment/backup-restore.md)。

## 常见问题

| 问题 | 处理方式 |
| --- | --- |
| 提示 Git / Docker 缺失 | 按前面的官方链接安装；重开终端后运行 `git --version`、`docker info` |
| 无法连接 Docker | 启动 Docker Desktop 或 Docker 服务，确认当前用户有访问权限；Windows 选择 Linux 容器 |
| GitHub 下载失败 | 检查 GitHub / raw.githubusercontent.com 的网络访问、Git 代理和 ref 是否存在；修复后重试 |
| 目标目录已存在 | 首次安装换一个新目录；续装使用目录内 `deploy/docker/install.*`，升级按上节执行 |
| 构建时依赖下载超时 | 检查 Docker 的网络/代理及镜像、系统包、Composer、Python、Go 依赖源的连通性；源码保留，修复后重试 |
| 80 / 443 被占用 | 本机体验改用 8080 / 8443；域名自动证书需要公网可达的 80 / 443，已有代理时按部署文档配置 |
| `init` 失败或 `/ready` 异常 | 在 `deploy/docker` 查看 `docker compose logs --tail=100 init php mysql redis qdrant`，再运行 `bin/doctor.php` |
| 安装成功但模型不能调用 | 检查厂商地址和 Key、模型名称、能力、Key 授权、额度、上游余额及网络；TokenHub 不自带模型服务 |
| 管理员忘记密码 | 配置 SMTP 后使用找回密码；服务器管理员可运行 `docker compose exec php php bin/install.php admin --username=admin` 重置 |
| HTTPS IP 出现证书警告 | 内部 CA 证书需导入客户端信任，或在证书中心配置适用证书；公网推荐使用域名 |
| APP 推送或 Passkey 不可用 | 检查 HTTPS、安全上下文、设备权限和对应推送凭证，见 [APP 管理端文档](docs/app-admin.md) |

TokenHub 不内置 WAF，需要时在前面部署专业 WAF 或云 WAF。反馈问题请通过 [Issues](https://github.com/QianqianTech/Tokenhub/issues) 提供系统、架构、提交 SHA、报错及脱敏日志，勿提交 `.env`、真实 Key 或数据库备份。

## 开发与验证

技术栈：PHP-FPM（Docker 默认 8.4）、Caddy、MySQL、Redis、Qdrant、Python RAG worker、Go realtime-gateway；前端使用仓库中的 Web 资源。兼容版本范围见 [部署基线](docs/deployment/README.md#版本基线)。

开发环境使用仓库根目录的 `docker-compose.yml`：

```bash
cp .env.example .env
# 将 .env 的 APP_ENV 改为 local，再启动开发栈
docker compose up -d --build
docker compose exec -T php php bin/install.php run --admin-password='replace-with-a-strong-password'
docker compose exec -T php php bin/run-tests.php
```

安装入口回归测试不依赖真实 Docker 服务，不安装系统组件：

```bash
python3 -m unittest discover -s tests/installer -v
```

CI 包含 PHP、前端浏览器、RAG worker、部署和安装入口测试。流水线结果以当前提交的 [Actions](https://github.com/QianqianTech/Tokenhub/actions) 为准；脚本级测试通过不等于所有系统上的实际部署均已验收。

## 文档

| 文档 | 内容 |
| --- | --- |
| [部署选择与版本基线](docs/deployment/README.md) | Docker、离线包和 Linux 源码部署 |
| [Docker 安装与运维](deploy/docker/README.md) | 环境配置、证书、验证、运维命令 |
| [镜像构建与发布](docs/deployment/docker.md) | 镜像锁定、离线包、Release 流水线 |
| [Linux 源码安装](docs/deployment/linux-source.md) | 无 Docker 的服务器安装 |
| [备份与恢复](docs/deployment/backup-restore.md) | 数据保护、迁移与恢复 |
| [使用和管理指南](docs/tokenhub-usage-management-guide.md) | 用户、部门、厂商、模型、额度、审计 |
| [APP 管理端](docs/app-admin.md) | 设备认证、二次验证、推送 |
| [对外 API](docs/external-api-complete.md) / [OpenAPI](public/openapi.json) | 模型与业务接口；线上规范为 `GET /v1/openapi.json` |
| [智能体管理 API](docs/agent-management-api.md) / [MCP](docs/mcp.md) | Agent 与客户端接入 |
| [变更记录](CHANGELOG.md) / [验收清单](docs/acceptance/final-acceptance-checklist.md) | 版本变化与验收项目 |

## 公司信息

**贵阳黔前科技有限公司**

- 服务热线 400-656-3113
- 网站 [https://www.qianqianai.com](https://www.qianqianai.com)

## 许可 / License

源代码公开。**个人免费，企业商用需要授权许可。** 详见 [《TokenHub 软件许可协议》](LICENSE)，版权归黔前智算所有。

- **个人免费**：个人学习、研究和自用，以及教育机构、非营利组织的非商业使用，可以免费使用、修改和非商业分发。
- **企业商用需要授权**：企业及其他组织用于业务、内部办公、对外提供服务（含 SaaS、托管）或销售交付，须事先取得授权。组织可在内部非生产环境免费评估 30 天。

企业商用授权请通过本仓库 [Issues](https://github.com/QianqianTech/Tokenhub/issues) 联系。

Personal use is free. Enterprise and other commercial use requires a license. See [LICENSE](LICENSE).
