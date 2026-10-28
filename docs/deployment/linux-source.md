# 源码安装（Linux，不使用 Docker）

## 支持的系统

| 系统 | PHP 来源 | MySQL | 说明 |
| --- | --- | --- | --- |
| Ubuntu 24.04 | 系统自带 8.3 | 系统自带 8.0 | 已测试路径（CI） |
| Ubuntu 22.04 | ondrej/php PPA | 系统自带 8.0 | 系统自带的是 PHP 8.1，脚本会自动添加 PPA |
| Debian 12 | 系统自带 8.2 | 系统只有 MariaDB | 建议改装 MySQL 官方 APT 源中的 8.4 LTS，或使用 `--db-host` 连接外部 MySQL |
| RHEL / CentOS Stream / Rocky / AlmaLinux 8、9 | EPEL + Remi（`php:remi-8.3`） | AppStream 8.0 | 会自动开启 SELinux 布尔值并设置文件上下文 |
| openEuler、麒麟、统信等衍生版 | 按 apt 或 dnf 路径尽量兼容 | — | 未逐一测试，建议优先使用 Docker 部署 |
| CentOS 7 | 不支持 | | 已停止维护 |
| Windows | 不支持 | | 请使用 Docker Desktop 和 `deploy/docker/install.ps1` |

MySQL 8.0 可以运行 TokenHub，自检会建议升级到 8.4 LTS。生产环境推荐使用 MySQL 官方源安装 8.4 LTS，或连接已有的 MySQL 8.4/9.x（`--db-host`）。

## 安装

```sh
git clone <仓库> tokenhub && cd tokenhub      # 或解压发布包
sudo deploy/linux/install.sh --check          # 只检测系统并打印安装计划，不做改动
sudo deploy/linux/install.sh --site tokenhub.example.com
```

常用参数：

| 参数 | 说明 |
| --- | --- |
| `--site` | 域名、`https://IP`、IP，或逗号分隔的多个地址 |
| `--admin-user` / `--admin-password` | 第一个超级管理员。不指定密码时自动生成并打印 |
| `--db-host --db-user --db-password --db-name` | 使用已有的 MySQL（需提前建库并授权） |
| `--redis-host --redis-password` | 使用已有的 Redis |
| `--qdrant-url` | 使用已有的 Qdrant |
| `--qdrant-archive FILE` | 无外网时提供 Qdrant 发布包（`qdrant-<arch>-unknown-linux-musl.tar.gz`） |
| `--vector-store mysql_fallback` | 不安装 Qdrant，同时不启用 Python RAG worker |
| `--workers N` | 队列 worker 进程数，默认 2 |
| `--php-version 8.4` | 需要添加软件源时安装的 PHP 版本 |
| `--app-dir DIR` | 安装目录，默认 `/var/www/tokenhub` |

## 安装后的布局

| 路径 | 内容 |
| --- | --- |
| `/var/www/tokenhub` | 应用代码（所有者 `tokenhub`）。`.env` 是指向 `/etc/tokenhub/tokenhub.env` 的软链接 |
| `/etc/tokenhub/tokenhub.env` | 配置和密钥（`root:tokenhub 0640`），**务必备份** |
| `/etc/tokenhub/caddy.env` | Caddy 使用的站点地址、TLS 和 FastCGI 参数 |
| `/var/lib/tokenhub/{exports,media,caddy}` | 导出文件、媒体文件、证书中心生成的 TLS 片段 |
| `/var/log/tokenhub` | PHP、Caddy、worker 日志（已配置 logrotate） |
| `/var/lib/qdrant`、`/etc/qdrant/config.yaml` | Qdrant 数据和配置（只监听 127.0.0.1，已启用 API Key） |
| `/opt/tokenhub-rag-worker` | Python RAG worker 及其 venv |

systemd 服务：

- PHP-FPM 的 `tokenhub` pool
- `caddy`（通过 drop-in 读取 `/etc/tokenhub/caddy.env`）
- `tokenhub-worker@1..N`
- `tokenhub-scheduler`
- `tokenhub-rag-worker`
- `qdrant`

此外，安装脚本会添加 sudoers 规则，允许 `tokenhub` 用户执行 `systemctl reload caddy`。证书中心更换证书后，依靠这条规则重载 Caddy。

## 运维

```sh
sudo -u tokenhub php /var/www/tokenhub/bin/doctor.php           # 自检
sudo -u tokenhub php /var/www/tokenhub/bin/install.php admin --username=admin   # 重置管理员密码
sudo systemctl status 'tokenhub-*' caddy qdrant
sudo journalctl -u 'tokenhub-worker@*' -n 100
```

## 升级

```sh
cd <新版本源码> && sudo deploy/linux/upgrade.sh
```

升级依次执行：备份（`scripts/backup-tokenhub.sh`）→ 停止 worker → 同步代码 → `composer install` → `bin/install.php run`（执行迁移并记录版本）→ 重启服务 → 自检。

## 备份

```sh
sudo DRY_RUN=0 CONFIRM_BACKUP=1 BACKUP_DIR=/backup/tokenhub /var/www/tokenhub/scripts/backup-tokenhub.sh
```

备份内容包括 MySQL、Qdrant 快照、Redis、配置、Caddy 证书、媒体、导出文件和应用存储。详见 [backup-restore.md](backup-restore.md)。
