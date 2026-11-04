# TokenHub 部署方式

TokenHub 1.0 提供两种交付方式，两者使用同一套安装逻辑（`bin/install.php`、`bin/doctor.php`、`bin/migrate.php`）。

**还没有下载源码？** 使用根目录的独立入口 [install.sh](../../install.sh)（Linux/macOS）或 [install.ps1](../../install.ps1)（Windows）。它会从 GitHub 获取指定分支、tag 或提交，再从源码构建生产 Docker 镜像。下载命令、前置环境、续装和升级步骤见 [README 快速安装](../../README.md#快速安装)。需要 Git 和已启动的 Docker；`--download-only` / `-DownloadOnly` 仅下载源码。

| | Docker 部署（推荐） | 源码安装 |
| --- | --- | --- |
| 适用系统 | Linux（Ubuntu、Debian、CentOS Stream、Rocky、AlmaLinux、麒麟、统信等能运行 Docker 的系统）、Windows（Docker Desktop + WSL 2）、macOS | Ubuntu 22.04/24.04、Debian 12、RHEL/CentOS Stream/Rocky/AlmaLinux 8 和 9 |
| 入口 | `deploy/docker/install.sh` / `install.ps1` | `sudo deploy/linux/install.sh` |
| 依赖 | 只需要 Docker | 由脚本安装 PHP 8.2+、Caddy、MySQL、Redis、Qdrant 和 Python |
| 镜像来源 | 离线包、镜像仓库（规划中）、源码构建 | — |
| 文档 | [docker.md](docker.md)、[deploy/docker/README.md](../../deploy/docker/README.md) | [linux-source.md](linux-source.md) |

- Windows 不支持源码安装，因为 PHP-FPM 没有 Windows 版本，Redis 扩展也不完整。Windows 请使用 Docker Desktop。
- CentOS 7 已停止维护，安装脚本会拒绝执行，请改用 Rocky/AlmaLinux 8 以上版本或 Docker 部署。

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
