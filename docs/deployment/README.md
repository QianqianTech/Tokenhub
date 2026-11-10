# TokenHub 部署方式

TokenHub 提供两种交付方式，两者使用同一套安装逻辑（`bin/install.php`、`bin/doctor.php`、`bin/migrate.php`）。

**还没有下载源码？** 使用根目录的独立入口 [install.sh](../../install.sh)（Linux/macOS）或 [install.ps1](../../install.ps1)（Windows）。它会从 GitHub 获取指定分支、tag 或提交，再从源码构建生产 Docker 镜像。下载命令、前置环境、续装和升级步骤见 [README 快速安装](../../README.md#快速安装)。需要 Git 和已启动的 Docker；`--download-only` / `-DownloadOnly` 仅下载源码。

**已有 Docker 实例？** 请按 [Docker 升级指南](upgrade.md)下载独立升级脚本，在原目录备份并更新。

[Docker Hub 安装](#从-docker-hub-部署) · [Docker Hub 原地升级](#升级已有-docker-hub-部署) · [各版本新增功能和可用标签](versions.md)

| | Docker 部署（推荐） | 源码安装 |
| --- | --- | --- |
| 适用系统 | Linux（Ubuntu、Debian、CentOS Stream、Rocky、AlmaLinux、麒麟、统信等能运行 Docker 的系统）、Windows（Docker Desktop + WSL 2）、macOS | Ubuntu 22.04/24.04、Debian 12、RHEL/CentOS Stream/Rocky/AlmaLinux 8 和 9 |
| 入口 | `deploy/docker/install.sh` / `install.ps1` | `sudo deploy/linux/install.sh` |
| 依赖 | 只需要 Docker | 由脚本安装 PHP 8.2+、Caddy、MySQL、Redis、Qdrant 和 Python |
| 镜像来源 | [Docker Hub 预构建镜像](#从-docker-hub-部署)、离线包、源码构建 | — |
| 文档 | [docker.md](docker.md)、[deploy/docker/README.md](../../deploy/docker/README.md) | [linux-source.md](linux-source.md) |

- Windows 不支持源码安装，因为 PHP-FPM 没有 Windows 版本，Redis 扩展也不完整。Windows 请使用 Docker Desktop。
- CentOS 7 已停止维护，安装脚本会拒绝执行，请改用 Rocky/AlmaLinux 8 以上版本或 Docker 部署。

## 从 Docker Hub 部署

以下五个镜像已推送到 Docker Hub，标签均为 `1.0.3`，适用于 **`linux/arm64`**。[v1.0.3 发布说明](https://github.com/QianqianTech/Tokenhub/releases/tag/v1.0.3) · [镜像摘要清单](https://github.com/QianqianTech/Tokenhub/releases/download/v1.0.3/docker-hub-digests.tsv)

### 已推送的镜像

| Docker Hub 仓库 | 完整镜像地址 |
| --- | --- |
| [tokenhub-php](https://hub.docker.com/r/leofan2022/tokenhub-php/tags?name=1.0.3) | `docker.io/leofan2022/tokenhub-php:1.0.3` |
| [tokenhub-caddy](https://hub.docker.com/r/leofan2022/tokenhub-caddy/tags?name=1.0.3) | `docker.io/leofan2022/tokenhub-caddy:1.0.3` |
| [tokenhub-rag-worker](https://hub.docker.com/r/leofan2022/tokenhub-rag-worker/tags?name=1.0.3) | `docker.io/leofan2022/tokenhub-rag-worker:1.0.3` |
| [tokenhub-realtime-gateway](https://hub.docker.com/r/leofan2022/tokenhub-realtime-gateway/tags?name=1.0.3) | `docker.io/leofan2022/tokenhub-realtime-gateway:1.0.3` |
| [tokenhub-worker-autoscaler](https://hub.docker.com/r/leofan2022/tokenhub-worker-autoscaler/tags?name=1.0.3) | `docker.io/leofan2022/tokenhub-worker-autoscaler:1.0.3` |

如果需要提前下载镜像，可以逐个拉取。

```bash
docker pull --platform linux/arm64 docker.io/leofan2022/tokenhub-php:1.0.3
docker pull --platform linux/arm64 docker.io/leofan2022/tokenhub-caddy:1.0.3
docker pull --platform linux/arm64 docker.io/leofan2022/tokenhub-rag-worker:1.0.3
docker pull --platform linux/arm64 docker.io/leofan2022/tokenhub-realtime-gateway:1.0.3
docker pull --platform linux/arm64 docker.io/leofan2022/tokenhub-worker-autoscaler:1.0.3
```

安装脚本会自动拉取 Compose 所需镜像并初始化服务。`tokenhub-worker-autoscaler` 用于可选的自动扩缩容。[Docker 运维说明](../../deploy/docker/README.md#可选按队列长度自动扩缩-worker)

在 ARM64 Linux 或使用 Docker Desktop 的 Apple 芯片 Mac 上，先确认 Docker 服务端的系统和架构：

```sh
docker version --format '{{.Server.Os}}/{{.Server.Arch}}'
docker compose version
```

Docker 服务端应显示 `linux/arm64`，Compose 需要 2.20 或以上。AMD64 主机可使用[源码构建](../../README.md#快速安装)。首次部署需安装 Git，并能访问 GitHub 和 Docker Hub。Compose 还会拉取 MySQL、Redis 和 Qdrant 镜像。

### 首次安装

Linux、macOS 或 WSL 2 从 GitHub 下载与镜像配套的部署文件。下面的配置仅允许本机访问。

```bash
git -c http.version=HTTP/1.1 clone --branch v1.0.3 --depth 1 https://github.com/QianqianTech/Tokenhub.git tokenhub &&
cd tokenhub &&
bash deploy/docker/install.sh \
  --image-prefix docker.io/leofan2022/ \
  --tag 1.0.3 \
  --site http://localhost --http-port 8080 --https-port 8443 \
  --bind 127.0.0.1 --yes
```

Windows PowerShell 也可使用这组镜像，前提是 Docker Desktop 的服务端显示 `linux/arm64`。

```powershell
git -c http.version=HTTP/1.1 clone --branch v1.0.3 --depth 1 https://github.com/QianqianTech/Tokenhub.git tokenhub
if ($LASTEXITCODE -ne 0) { throw '下载 TokenHub 失败' }
Set-Location .\tokenhub
powershell -NoProfile -ExecutionPolicy Bypass -File .\deploy\docker\install.ps1 `
  -ImagePrefix 'docker.io/leofan2022/' -Tag '1.0.3' `
  -Site 'http://localhost' -HttpPort 8080 -HttpsPort 8443 -Bind '127.0.0.1' -Yes
```

部署到公网服务器时，将示例中的站点、端口及绑定参数替换为 `--site tokenhub.example.com --yes`，PowerShell 对应 `-Site tokenhub.example.com -Yes`。请用自己的域名，并将域名解析到服务器，开放公网 80 和 443 端口。

安装完成后用终端显示的账号和初始密码登录 `http://localhost:8080/admin/`，然后修改密码。可从安装目录执行下面的命令，检查容器、数据库迁移以及 `/health` 和 `/ready`。[初始管理员密码](initial-admin-password.md)

```bash
bash deploy/docker/verify.sh
```

Windows 可执行 `powershell -NoProfile -ExecutionPolicy Bypass -File .\deploy\docker\verify.ps1`。

### 升级已有 Docker Hub 部署

确认原部署 `.env` 中的 `TOKENHUB_IMAGE_PREFIX` 为 `docker.io/leofan2022/`。在原安装目录运行预检和升级，脚本会保留配置和数据卷，先备份，再拉取镜像、执行迁移和验收。

```bash
(
  upgrade_script=$(mktemp "${TMPDIR:-/tmp}/tokenhub-upgrade.XXXXXX") || exit 1
  trap 'rm -f "$upgrade_script"' EXIT
  curl --fail --show-error --location --retry 3 \
    https://raw.githubusercontent.com/QianqianTech/Tokenhub/v1.0.3/upgrade.sh \
    --output "$upgrade_script" &&
  bash "$upgrade_script" --dir "$PWD" --tag 1.0.3 --check &&
  bash "$upgrade_script" --dir "$PWD" --tag 1.0.3
)
```

Windows PowerShell 对应 `-Tag 1.0.3`。需要选择其他版本时，先查看已发布的镜像标签和迁移说明。[完整升级指南](upgrade.md) · [各版本新增功能与可用标签](versions.md)

## 版本基线

| 组件 | 要求 | 说明 |
| --- | --- | --- |
| PHP | 8.2 – 8.5 | Docker 镜像默认使用 8.4。PhpSpreadsheet 2.x 只支持 8.6 以下；8.2 的安全支持在 2026 年底结束，建议使用 8.3 或 8.4 |
| MySQL | 8.0.19 以上 | 推荐 8.4 LTS 或 9.x，Docker 默认 9.7。MariaDB 未经测试 |
| Redis | 6.2 以上 | Docker 默认 8.0 |
| Qdrant | 1.15 | 知识库向量库，默认启用。小规模部署可改用 `mysql_fallback` |
| Caddy | 2.8 以上 | 唯一的对外入口，负责 HTTPS |

## 访问方式

域名和 IP 两种方式都支持，安装时通过 `--site` 参数指定：

| 写法 | 效果 |
| --- | --- |
| `tokenhub.example.com` | 自动申请 HTTPS 证书 |
| `https://10.0.0.8` | IP 访问，使用 Caddy 内部 CA 签发的 HTTPS 证书 |
| `10.0.0.8` 或留空 | 纯 HTTP，仅限可信内网 |
| 逗号分隔的多个地址 | 同时启用多种访问方式 |

规则实现在 `app/Console/Install/SiteAddress.php`。

## 自检

任何时候都可以执行自检：

```sh
php bin/doctor.php                 # 源码安装
docker compose exec php php bin/doctor.php   # Docker 部署（在 deploy/docker 目录下执行）
```

自检内容包括：

- PHP 版本和扩展、目录权限；
- 生产配置是否仍为模板值；
- 访问地址与 Cookie 设置是否匹配；
- MySQL 版本、Redis、Qdrant 是否可用；
- 是否有待执行的迁移、是否存在管理员、队列 worker 心跳。
