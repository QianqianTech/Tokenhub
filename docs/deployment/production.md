# TokenHub 生产部署说明（手工步骤）

> 推荐用安装脚本完成全部步骤：
> - 源码安装：`sudo deploy/linux/install.sh`，见 [linux-source.md](linux-source.md)
> - Docker 部署：`deploy/docker/install.sh`，见 [README.md](README.md)
>
> 本文保留手工步骤，供审计或定制安装参考。

目标环境为 Ubuntu + PHP-FPM + Caddy + MySQL + Redis + Qdrant + systemd Worker。Caddy 是唯一公网入口，PHP-FPM 只监听本机 Unix socket。

## 服务账号和目录

默认服务账号使用 `www-data`。生产目录建议固定如下：

```bash
sudo mkdir -p /var/www/tokenhub
sudo mkdir -p /etc/tokenhub
sudo mkdir -p /var/log/tokenhub
sudo mkdir -p /var/lib/tokenhub/{exports,media,caddy}
sudo chown -R www-data:www-data /var/www/tokenhub /var/log/tokenhub /var/lib/tokenhub
sudo chmod 0750 /etc/tokenhub /var/log/tokenhub /var/lib/tokenhub
sudo chmod 0750 /var/lib/tokenhub/exports /var/lib/tokenhub/media /var/lib/tokenhub/caddy
```

目录用途：

| 路径 | 用途 | 权限建议 |
| --- | --- | --- |
| `/var/www/tokenhub` | 应用代码 | `www-data:www-data`, `0750` |
| `/etc/tokenhub/tokenhub.env` | 生产环境变量 | `root:www-data`, `0640` |
| `/var/log/tokenhub` | PHP、Worker、Caddy 日志 | `www-data:www-data`, `0750` |
| `/var/lib/tokenhub/media` | 媒体资产 | `www-data:www-data`, `0750` |
| `/var/lib/tokenhub/exports` | 导出归档 | `www-data:www-data`, `0750` |
| `/var/lib/tokenhub/caddy` | TLS 证书片段 | `www-data:www-data`, `0750` |

## 软件包

```bash
sudo apt update
# Ubuntu 24.04 自带 PHP 8.3；Ubuntu 22.04 需先添加 ppa:ondrej/php。PHP 要求 8.2 – 8.5。
PHP=8.3
sudo apt install -y php$PHP-fpm php$PHP-cli php$PHP-mysql php$PHP-redis php$PHP-curl php$PHP-mbstring php$PHP-gd php$PHP-zip php$PHP-xml php$PHP-intl redis-server caddy unzip
```

`gd`、`zip`、`xml` 是 PhpSpreadsheet 的必需扩展，缺少时 `composer install` 会失败。

MySQL 要求 8.0.19 以上，推荐 8.4 LTS 或 9.x（Ubuntu 自带的是 8.0，生产环境建议使用 MySQL 官方 APT 源）。使用外部 MySQL 时，先确认版本：

```bash
mysql --version
mysql -uroot -p -e "SELECT VERSION();"
```

## 应用发布

```bash
sudo rsync -a --delete --exclude .git ./ /var/www/tokenhub/
cd /var/www/tokenhub && sudo -u www-data composer install --no-dev --optimize-autoloader
# 生成带随机密钥的配置（也可以复制 deploy/ubuntu/.env.production.example 后手工修改）
sudo php bin/install.php env --target=native --output=/etc/tokenhub/tokenhub.env --site=your-domain.example
# sudo cp deploy/ubuntu/.env.production.example /etc/tokenhub/tokenhub.env
sudo chown root:www-data /etc/tokenhub/tokenhub.env
sudo chmod 0640 /etc/tokenhub/tokenhub.env
sudo ln -sfn /etc/tokenhub/tokenhub.env /var/www/tokenhub/.env
```

必须修改 `/etc/tokenhub/tokenhub.env` 中的 `APP_KEY`、`DB_*`、`REDIS_*`、`LOG_PATH`、`EXPORT_PATH`、`MEDIA_PATH`、`APP_URL` 和 `TRUSTED_PROXY_CIDRS`。`SESSION_SECURE` 必须保持为 `true`；MySQL、Redis 和 Qdrant 仅允许私网或本机访问，不能映射为公网端口。

推荐生产路径：

```env
LOG_PATH=/var/log/tokenhub
EXPORT_PATH=/var/lib/tokenhub/exports
MEDIA_PATH=/var/lib/tokenhub/media
SESSION_SECURE=true
```

## 数据库初始化和迁移

创建数据库和账号：

```sql
CREATE DATABASE tokenhub CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'tokenhub'@'localhost' IDENTIFIED BY 'change-me';
GRANT ALL PRIVILEGES ON tokenhub.* TO 'tokenhub'@'localhost';
FLUSH PRIVILEGES;
```

执行迁移：

```bash
cd /var/www/tokenhub
sudo -u www-data php bin/install.php run --non-interactive    # 迁移、创建首个管理员、记录版本
sudo -u www-data php bin/doctor.php
```

迁移前必须完成一次数据库备份；迁移后执行：

```bash
sudo -u www-data php bin/run-tests.php
curl -i https://your-domain.example/health
```

## PHP-FPM

```bash
sudo cp /var/www/tokenhub/deploy/ubuntu/php-fpm-tokenhub.conf /etc/php/8.2/fpm/pool.d/tokenhub.conf
sudo systemctl restart php8.2-fpm
sudo systemctl status php8.2-fpm --no-pager
```

PHP-FPM socket：`/run/php/php8.2-fpm-tokenhub.sock`。

## Caddy

```bash
sudo cp /var/www/tokenhub/Caddyfile.example /etc/caddy/Caddyfile
sudo install -o www-data -g www-data -m 0644 /dev/null /var/lib/tokenhub/caddy/tls-current.caddy
sudo TOKENHUB_DOMAIN=your-domain.example caddy validate --config /etc/caddy/Caddyfile
sudo systemctl restart caddy
```

生产模板支持：

- `TOKENHUB_DOMAIN`
- `CADDY_ADMIN_API`
- `TOKENHUB_PHP_FASTCGI`：可传单个 PHP-FPM upstream，也可传多个 upstream 用于 Caddy 负载均衡。
- `TOKENHUB_TLS_FRAGMENT`
- `TOKENHUB_LOG_PATH`

## Worker 运维

安装 systemd 服务：

```bash
sudo cp /var/www/tokenhub/deploy/ubuntu/tokenhub-worker.service /etc/systemd/system/tokenhub-worker.service
sudo systemctl daemon-reload
sudo systemctl enable --now tokenhub-worker
```

启动、重启、日志检查：

```bash
sudo systemctl status tokenhub-worker --no-pager
sudo systemctl restart tokenhub-worker
sudo journalctl -u tokenhub-worker -n 200 --no-pager
sudo tail -n 200 /var/log/tokenhub/worker.jsonl
```

失败重试由 `config/queue.php` 控制：

- `QUEUE_RETRY_AFTER_SECONDS`
- `QUEUE_MAX_ATTEMPTS`
- `QUEUE_FAILED_NAME`

队列堆积检查可结合 Redis：

```bash
redis-cli LLEN tokenhub:jobs
redis-cli LLEN tokenhub:failed-jobs
```

## 定时清理

由常驻进程 `bin/scheduler.php` 投递保留期清理任务：`cleanup.retention` 每分钟，`cleanup.exports` 每 5 分钟，`cleanup.media` 每 10 分钟，`cleanup.external-events` 每小时。systemd 单元见 `deploy/linux/systemd/tokenhub-scheduler.service`。

只能使用 cron 的环境，可以改用以下配置：

```cron
* * * * * www-data cd /var/www/tokenhub && php bin/scheduler.php --once >/dev/null 2>&1
```

## 生产验收

```bash
BASE_URL=https://your-domain.example bash /var/www/tokenhub/scripts/security-smoke.sh
BASE_URL=https://your-domain.example ADMIN_PASSWORD='admin-password' bash /var/www/tokenhub/scripts/acceptance-check.sh
BASE_URL=https://your-domain.example API_KEY='tkh_xxx' MODEL='model-id' bash /var/www/tokenhub/scripts/pressure-smoke.sh
DRY_RUN=1 BACKUP_DIR=/backup/tokenhub bash /var/www/tokenhub/scripts/backup-tokenhub.sh
```
