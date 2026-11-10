# TokenHub

[English](README.en.md)

**面向团队和企业的大模型统一接入、权限与用量管理平台。**

TokenHub 部署在自己的服务器上，负责在业务应用与多个模型供应商之间转发请求。平台管理员配置供应商、模型和访问规则，开发者用 TokenHub 签发的 API Key 接入，运维人员可按部门、模型或请求编号追踪调用、费用和故障。

[软件功能](#可以做什么) · [界面截图](#界面截图) · [Docker Hub 快速安装](#docker-hub-快速安装) · [从源码构建](#从源码构建) · [首次使用](#首次使用) · [初始管理员密码](docs/deployment/initial-admin-password.md) · [升级与备份](#升级与备份) · [版本与新增功能](docs/deployment/versions.md) · [常见问题](#常见问题) · [文档](#文档) · [公司信息](#公司信息) · [许可](#许可--license)

## 可以做什么

| 模块 | 使用场景与主要功能 |
| --- | --- |
| 运营中心 | 在智慧分析查看请求趋势、Token、部门与员工用量、安全事件和延迟。用会话测试检查模型配置，也可为运营大屏设置共享范围和访问条件。 |
| 组织与权限 | 按部门管理用户，区分业务用户与管理员。为不同管理员分配角色和可管理的数据范围，并在权限配置页查看授权情况。 |
| 模型服务 | 管理上游厂商、模型目录、路由优先级、渠道限流和健康检查。为模型维护价格，用于费用统计和结算。 |
| API 接入 | 为用户和部门发放密钥，设置可用模型、有效期、IP 白名单以及请求数、Token 和成本限额。启用会话记录后，可按密钥查看问答及受支持的多模态内容。 |
| 用量中心 | 按时间、用户、部门和结果查询请求，查看 Token、费用、耗时和失败原因。跟踪多模态异步任务与媒体资产，管理内容留存、会话归档和导出。 |
| RAG 知识库 | 建立知识库和数据集，导入文档、问答或图片，完成解析、预览和向量化。可按部门、用户或密钥控制访问范围，并用检索试验台、评估样本和索引监控检查质量。 |
| 安全审计 | 设置脱敏策略，查看脱敏事件、告警、网关日志和管理员操作记录。按日志类别配置保留期限并管理归档。 |
| 系统设置 | 查看核心服务健康、任务队列与积压，配置站点标识、TLS 证书、SMTP 密码找回、告警阈值及内容和会话留存策略。 |

### 业务应用接入

网关提供 OpenAI 和 Anthropic 兼容接口、Gemini 原生接口及 SSE 流式输出。配置相应上游后，还可接入图像、语音、视频和音乐能力，以及独立的实时语音网关。智能体和自动化工具可通过管理 API、Webhook 或 MCP 接入。模型由部署方配置，调用费用按所用服务计费。[对外 API](docs/external-api-complete.md) · [智能体管理 API](docs/agent-management-api.md) · [MCP 接入](docs/mcp.md)

### 管理入口

- **Web 后台 `/admin/`** 管理平台配置，排查用量和故障。
- **移动管理端 `/app/`** 通过设备认证链接登录，支持二次验证和可选推送。原生壳工程的构建与签名另行配置。[APP 管理端](docs/app-admin.md) · [原生工程](native/README.md)
- **运营大屏 `/screen/`** 通过独立分享入口展示 Token、调用趋势、排行和多模态任务，按分享配置限制可见指标和访问范围。[使用和管理指南](docs/tokenhub-usage-management-guide.md#10-大屏和大屏分享)

## 界面截图

以下 **56 张截图**来自 `v1.0.3` Docker 验收实例，覆盖后台 41 个菜单页面、12 个 RAG 子页和 3 个认证入口。验收实例采用空白业务数据，部分列表展示空状态。点击模块名称可在本页展开截图，点击图片可查看原图。

![智慧分析](docs/screenshots/v1.0.3/operations--dashboard.jpg)

| 模型管理 | RAG 质量评估 |
| --- | --- |
| ![模型管理](docs/screenshots/v1.0.3/models--models.jpg) | ![质量评估](docs/screenshots/v1.0.3/rag--rag-quality.jpg) |

| 会话记录 | 运行状态 |
| --- | --- |
| ![会话记录](docs/screenshots/v1.0.3/access--conversation-logs.jpg) | ![运行状态](docs/screenshots/v1.0.3/system--operations-status.jpg) |

<details>
<summary>运营中心（4 张）</summary>

| 智慧分析 | 会话测试 |
| --- | --- |
| ![智慧分析](docs/screenshots/v1.0.3/operations--dashboard.jpg) | ![会话测试](docs/screenshots/v1.0.3/operations--admin-chat-sessions.jpg) |

| 大屏分享 | 大屏访问日志 |
| --- | --- |
| ![大屏分享](docs/screenshots/v1.0.3/operations--dashboard-share-links.jpg) | ![大屏访问日志](docs/screenshots/v1.0.3/operations--dashboard-share-access-logs.jpg) |

</details>

<details>
<summary>组织与权限（4 张）</summary>

| 部门管理 | 用户管理 |
| --- | --- |
| ![部门管理](docs/screenshots/v1.0.3/organization--departments.jpg) | ![用户管理](docs/screenshots/v1.0.3/organization--users.jpg) |

| 管理员管理 | 权限配置 |
| --- | --- |
| ![管理员管理](docs/screenshots/v1.0.3/organization--admin-users.jpg) | ![权限配置](docs/screenshots/v1.0.3/organization--permission-config.jpg) |

</details>

<details>
<summary>模型服务（3 张）</summary>

| 厂商管理 | 模型管理 |
| --- | --- |
| ![厂商管理](docs/screenshots/v1.0.3/models--providers.jpg) | ![模型管理](docs/screenshots/v1.0.3/models--models.jpg) |

**价格配置**

![价格配置](docs/screenshots/v1.0.3/models--model-prices.jpg)

</details>

<details>
<summary>API 接入（5 张）</summary>

| API 密钥 | 会话记录 |
| --- | --- |
| ![API 密钥](docs/screenshots/v1.0.3/access--api-keys.jpg) | ![会话记录](docs/screenshots/v1.0.3/access--conversation-logs.jpg) |

| 模型限额 | 限额策略 |
| --- | --- |
| ![模型限额](docs/screenshots/v1.0.3/access--api-key-model-quotas.jpg) | ![限额策略](docs/screenshots/v1.0.3/access--quota-policies.jpg) |

**限额绑定**

![限额绑定](docs/screenshots/v1.0.3/access--quota-bindings.jpg)

</details>

<details>
<summary>用量中心（6 张）</summary>

| 用量明细 | 多模态任务 |
| --- | --- |
| ![用量明细](docs/screenshots/v1.0.3/usage--usage-records.jpg) | ![多模态任务](docs/screenshots/v1.0.3/usage--multimodal-tasks.jpg) |

| 媒体资产 | 内容留存 |
| --- | --- |
| ![媒体资产](docs/screenshots/v1.0.3/usage--media-assets.jpg) | ![内容留存](docs/screenshots/v1.0.3/usage--usage-contents.jpg) |

| 会话归档 | 导出任务 |
| --- | --- |
| ![会话归档](docs/screenshots/v1.0.3/usage--content-archive.jpg) | ![导出任务](docs/screenshots/v1.0.3/usage--export-tasks.jpg) |

</details>

<details>
<summary>RAG 知识库（16 张）</summary>

| 构建知识 | 构建知识 · 知识库 |
| --- | --- |
| ![构建知识](docs/screenshots/v1.0.3/rag--rag-build.jpg) | ![构建知识 · 知识库](docs/screenshots/v1.0.3/rag--rag-knowledge-bases.jpg) |

| 构建知识 · 数据集 | 构建知识 · 知识集权限 |
| --- | --- |
| ![构建知识 · 数据集](docs/screenshots/v1.0.3/rag--rag-vector-datasets.jpg) | ![构建知识 · 知识集权限](docs/screenshots/v1.0.3/rag--rag-access.jpg) |

| 构建知识 · 导入任务 | 构建知识 · 任务记录 |
| --- | --- |
| ![构建知识 · 导入任务](docs/screenshots/v1.0.3/rag--rag-import-task.jpg) | ![构建知识 · 任务记录](docs/screenshots/v1.0.3/rag--rag-uploads.jpg) |

| 检索试验台 | 质量评估 |
| --- | --- |
| ![检索试验台](docs/screenshots/v1.0.3/rag--rag-retrieval-lab.jpg) | ![质量评估](docs/screenshots/v1.0.3/rag--rag-quality.jpg) |

| 质量评估 · 评估样本 | 质量评估 · 用户反馈 |
| --- | --- |
| ![质量评估 · 评估样本](docs/screenshots/v1.0.3/rag--rag-eval-cases.jpg) | ![质量评估 · 用户反馈](docs/screenshots/v1.0.3/rag--rag-answer-feedback.jpg) |

| 质量评估 · 纠偏任务 | 运行维护 |
| --- | --- |
| ![质量评估 · 纠偏任务](docs/screenshots/v1.0.3/rag--rag-correction-tasks.jpg) | ![运行维护](docs/screenshots/v1.0.3/rag--rag-operations.jpg) |

| 运行维护 · 向量化健康 | 运行维护 · 向量任务 |
| --- | --- |
| ![运行维护 · 向量化健康](docs/screenshots/v1.0.3/rag--rag-vectorization-metrics.jpg) | ![运行维护 · 向量任务](docs/screenshots/v1.0.3/rag--rag-embedding-jobs.jpg) |

| 运行维护 · 检索记录 | 运行维护 · 数据留存 |
| --- | --- |
| ![运行维护 · 检索记录](docs/screenshots/v1.0.3/rag--rag-retrieval-logs.jpg) | ![运行维护 · 数据留存](docs/screenshots/v1.0.3/rag--rag-retention-policies.jpg) |

</details>

<details>
<summary>安全审计（7 张）</summary>

| 脱敏策略 | 脱敏事件 |
| --- | --- |
| ![脱敏策略](docs/screenshots/v1.0.3/security--desensitization-policies.jpg) | ![脱敏事件](docs/screenshots/v1.0.3/security--desensitization-events.jpg) |

| 告警事件 | 日志中心 |
| --- | --- |
| ![告警事件](docs/screenshots/v1.0.3/security--alert-events.jpg) | ![日志中心](docs/screenshots/v1.0.3/security--logs.jpg) |

| 审计日志 | 日志留存 |
| --- | --- |
| ![审计日志](docs/screenshots/v1.0.3/security--audit-logs.jpg) | ![日志留存](docs/screenshots/v1.0.3/security--log-retention-policies.jpg) |

**日志归档**

![日志归档](docs/screenshots/v1.0.3/security--log-archive-files.jpg)

</details>

<details>
<summary>系统设置（8 张）</summary>

| 运行状态 | 标题与标识 |
| --- | --- |
| ![运行状态](docs/screenshots/v1.0.3/system--operations-status.jpg) | ![标题与标识](docs/screenshots/v1.0.3/system--settings-brand-identity.jpg) |

| 证书与入口 | SMTP 邮件 |
| --- | --- |
| ![证书与入口](docs/screenshots/v1.0.3/system--settings-tls-certificates.jpg) | ![SMTP 邮件](docs/screenshots/v1.0.3/system--settings-smtp.jpg) |

| 告警设置 | 内容留存策略 |
| --- | --- |
| ![告警设置](docs/screenshots/v1.0.3/system--settings-alert-thresholds.jpg) | ![内容留存策略](docs/screenshots/v1.0.3/system--content-retention-policy.jpg) |

| 会话记录设置 | 高级设置 |
| --- | --- |
| ![会话记录设置](docs/screenshots/v1.0.3/system--conversation-log-settings.jpg) | ![高级设置](docs/screenshots/v1.0.3/system--system-settings.jpg) |

</details>

<details>
<summary>登录与其他入口（3 张）</summary>

| 管理后台登录 | 移动管理端入口 |
| --- | --- |
| ![管理后台登录](docs/screenshots/v1.0.3/interfaces--admin-login.png) | ![移动管理端入口](docs/screenshots/v1.0.3/interfaces--mobile-entry.jpg) |

**运营大屏入口**

![运营大屏入口](docs/screenshots/v1.0.3/interfaces--operations-screen-entry.jpg)

</details>

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

### Linux / macOS 一条命令

启动 Docker 后，在终端执行下面的命令。脚本会下载源码包、选择适用的镜像并初始化 TokenHub。

```bash
curl -fsSL --retry 3 https://raw.githubusercontent.com/QianqianTech/Tokenhub/main/install.sh -o tokenhub-install.sh && bash tokenhub-install.sh --quick
```

安装完成后，打开 `http://localhost:8080/admin/`，用终端显示的初始账号和密码登录。公网服务器运行 `bash tokenhub-install.sh --quick --public-ip`，让脚本检测 IPv4 并配置 HTTPS 8443。使用域名时把 `--public-ip` 换成 `--site 你的域名`。[服务器访问与证书](deploy/docker/README.md#公网-ipv4-和-8443)

快装会优先复用本机已加载的同架构镜像，ARM64 可直接拉取已发布的 1.0.4 镜像，其余情况由脚本构建。弱网环境可由技术人员提前制作离线包，用户解压后运行 `./load-images.sh --install`。[离线安装](docs/deployment/offline-docker-release.md#安装客户方)

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
git -c http.version=HTTP/1.1 clone --depth 1 --branch v1.0.3 https://github.com/QianqianTech/Tokenhub.git tokenhub &&
cd tokenhub &&
bash deploy/docker/install.sh \
  --image-prefix docker.io/leofan2022/ --tag 1.0.3 \
  --site http://localhost --http-port 8080 --https-port 8443 \
  --bind 127.0.0.1 --yes
```

安装成功后，用终端显示的初始账号和密码登录 `http://localhost:8080/admin/`。在安装目录运行 `bash deploy/docker/verify.sh` 可检查服务状态。[初始管理员密码](docs/deployment/initial-admin-password.md) · [完整 Docker Hub 部署指南](docs/deployment/README.md#从-docker-hub-部署) · [镜像摘要](https://github.com/QianqianTech/Tokenhub/releases/download/v1.0.3/docker-hub-digests.tsv) · [1.0.3 更新说明](CHANGELOG.md#103---2026-11-05)

### 从源码构建

源码构建适用于 AMD64 和 ARM64。

#### 1. 准备环境

| 项目 | 要求 |
| --- | --- |
| 系统 | Linux；macOS + Docker Desktop；Windows + Docker Desktop（Linux 容器，建议 WSL 2 后端） |
| 架构 | x86_64 / AMD64 或 ARM64；主机系统需满足 Docker 的支持要求 |
| Docker | Engine 24+、Compose 2.20+ 和 Buildx；Docker Desktop 用户需先启动应用 |
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
docker buildx version
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

公网 IPv4 可用 `--public-ip --https-port 8443` 配置 HTTPS。已装好的实例可保留密钥和数据卷，原地更改访问地址。[公网 IPv4 和 8443](deploy/docker/README.md#公网-ipv4-和-8443) · [切换已有安装](deploy/docker/README.md#将已有本机安装切换到公网-https)

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
| `--quick` | | Bash 直接下载源码包，无需 Git，自动选择镜像或构建 |
| `--check` | `-Check` | 仅检查前置环境，不下载、不启动服务 |
| `--download-only` | `-DownloadOnly` | 仅下载源码，不要求 Docker，不生成配置 |
| `--site ADDRESS` | `-Site ADDRESS` | 域名、`https://IP` 或 `http://IP` |
| `--public-ip` | `-Site https://IP`（手动指定） | Bash 自动查询出口 IPv4 并配置 HTTPS。公网端口 80 可达时申请公开证书 |
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

1. 用初始账号和密码登录后台，设置新密码并验证登录。[初始管理员密码](docs/deployment/initial-admin-password.md)
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

### 获取或重置管理员密码

安装脚本会在终端显示初始账号和密码。Docker 和 Linux 的配置位置、安装时如何指定初始密码，以及找不到初始密码时的处理方法都在独立指南中。[初始管理员密码](docs/deployment/initial-admin-password.md)

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
| GitHub 下载失败 | 安装入口会用 HTTP/1.1 重试一次。持续失败时检查网络、Git 代理和 ref，或使用已校验的源码包 |
| 目标目录已存在 | 首次安装换一个新目录；续装使用目录内 `deploy/docker/install.*`，升级按上节执行 |
| Docker 镜像或构建依赖下载超时 | 按[安装网络排查](deploy/docker/README.md#安装网络排查)配置可访问的镜像源，回到原目录续装 |
| 80 / 443 被占用 | 本机体验改用 8080 / 8443；域名自动证书需要公网可达的 80 / 443，已有代理时按部署文档配置 |
| `init` 失败或 `/ready` 异常 | 在 `deploy/docker` 查看 `docker compose logs --tail=100 init php mysql redis qdrant`，再运行 `bin/doctor.php` |
| 安装成功但模型不能调用 | 检查厂商地址和 Key、模型名称、能力、Key 授权、额度、上游余额及网络；TokenHub 不自带模型服务 |
| 找不到初始密码或忘记现用密码 | [查看初始密码和恢复方式](docs/deployment/initial-admin-password.md) |
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

<img src="brand/source/tokenhub-master-250.png" alt="贵阳黔前科技有限公司 Logo" width="96" height="96">

**贵阳黔前科技有限公司**

- 服务热线 400-656-3113
- 网站 [https://www.qianqianai.com](https://www.qianqianai.com)

## 许可 / License

源代码公开。**个人免费，企业商用需要授权许可。** 详见 [《TokenHub 软件许可协议》](LICENSE)，版权归黔前智算所有。

- **个人免费**：个人学习、研究和自用，以及教育机构、非营利组织的非商业使用，可以免费使用、修改和非商业分发。
- **企业商用需要授权**：企业及其他组织用于业务、内部办公、对外提供服务（含 SaaS、托管）或销售交付，须事先取得授权。组织可在内部非生产环境免费评估 30 天。

企业商用授权请通过本仓库 [Issues](https://github.com/QianqianTech/Tokenhub/issues) 联系。

Personal use is free. Enterprise and other commercial use requires a license. See [LICENSE](LICENSE).
