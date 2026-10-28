# TokenHub 备份和恢复演练

默认目标：

- RPO：24 小时。
- RTO：4 小时，从数据库和文件备份可用开始计算。
- 恢复后核心配置、用户、API Key、用量明细、审计数据必须一致。

## 备份范围

| 范围 | 默认路径或来源 | 说明 |
| --- | --- | --- |
| MySQL | `DB_*` | 核心业务数据、审计、用量、配置 |
| 应用配置 | `/etc/tokenhub` | `.env` 和生产配置 |
| Caddy 数据 | `/var/lib/caddy` | 证书和 Caddy 状态 |
| Caddy 配置 | `/etc/caddy` | 入口配置 |
| 媒体资产 | `/var/lib/tokenhub/media` | ASR/TTS/图片/视频相关资产 |
| 导出归档 | `/var/lib/tokenhub/exports` | CSV、会话归档和短期下载文件 |

## 备份执行

先 dry-run：

```bash
DRY_RUN=1 BACKUP_DIR=/backup/tokenhub DB_HOST=127.0.0.1 DB_USERNAME=tokenhub DB_DATABASE=tokenhub bash scripts/backup-tokenhub.sh
```

真实执行必须显式关闭 dry-run，并确认本次要创建真实备份：

```bash
DRY_RUN=0 \
CONFIRM_BACKUP=1 \
BACKUP_DIR=/backup/tokenhub \
DB_HOST=127.0.0.1 \
DB_USERNAME=tokenhub \
DB_PASSWORD='db-password' \
DB_DATABASE=tokenhub \
CONFIG_PATH=/etc/tokenhub \
CADDY_DATA_PATH=/var/lib/caddy \
CADDY_CONFIG_PATH=/etc/caddy \
MEDIA_PATH=/var/lib/tokenhub/media \
EXPORT_PATH=/var/lib/tokenhub/exports \
bash scripts/backup-tokenhub.sh
```

输出结构：

```text
/backup/tokenhub/YYYYMMDD-HHMMSS/
  mysql.sql
  config.tar.gz
  caddy-data.tar.gz
  caddy-config.tar.gz
  media.tar.gz
  exports.tar.gz
  SHA256SUMS
/backup/tokenhub/latest -> /backup/tokenhub/YYYYMMDD-HHMMSS
```

## 恢复演练检查

不导入数据库，仅校验备份文件、压缩包和校验和：

```bash
BACKUP_DIR=/backup/tokenhub/latest bash scripts/restore-drill-check.sh
```

导入到演练库：

```bash
BACKUP_DIR=/backup/tokenhub/latest \
CONFIRM_RESTORE=1 \
DB_HOST=127.0.0.1 \
DB_USERNAME=tokenhub \
DB_PASSWORD='db-password' \
DB_DATABASE=tokenhub_restore_drill \
bash scripts/restore-drill-check.sh
```

恢复演练不会默认覆盖生产库；只有设置 `CONFIRM_RESTORE=1` 时才会导入到 `DB_DATABASE` 指定的库。

## 灾难恢复步骤

1. 准备新的 Ubuntu 主机，安装 PHP-FPM、Caddy、MySQL、Redis。
2. 恢复 `/etc/tokenhub`，确认 `APP_KEY` 与原环境一致。
3. 恢复 `/etc/caddy` 和 `/var/lib/caddy`，确认域名证书和 Caddy 配置可用。
4. 恢复 `/var/lib/tokenhub/media` 和 `/var/lib/tokenhub/exports`。
5. 创建空 MySQL 库并导入 `mysql.sql`。
6. 发布应用代码到 `/var/www/tokenhub`。
7. 执行 `php scripts/migrate.php`，确保迁移幂等。
8. 启动 PHP-FPM、Caddy、Redis、`tokenhub-worker`。
9. 执行验收脚本和人工核对。

## 验证点

- `/health` 返回健康或降级状态。
- Admin 可以登录，用户、部门、厂商、模型、价格配置存在。
- API Key 哈希和授权配置存在，明文 Key 不应从备份或日志中恢复展示。
- 用量明细、聚合统计、审计日志数量与备份时间点一致。
- 媒体文件和导出文件能按权限下载。
- Caddy 安全响应头、WAF fragment、日志脱敏生效。
- Worker 可以消费新队列任务，失败队列可见。

## 性能和安全演练命令

```bash
BASE_URL=https://your-domain.example \
API_KEY='tkh_xxx' \
MODEL='model-id' \
ADMIN_PASSWORD='admin-password' \
REDIS_HOST=127.0.0.1 \
QUEUE_ALERT_THRESHOLD=1000 \
CADDY_LB_UPSTREAMS='127.0.0.1:9000 127.0.0.1:9001' \
bash scripts/pressure-smoke.sh

BASE_URL=https://your-domain.example \
EXPECT_WAF_QUERY_BLOCK=1 \
EXPECT_ADMIN_IP_BLOCK=1 \
BLOCKED_IP=203.0.113.10 \
bash scripts/security-smoke.sh
```
