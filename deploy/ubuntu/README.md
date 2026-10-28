# TokenHub Ubuntu 部署资产（旧版）

新安装请使用 [deploy/linux/install.sh](../linux/install.sh)。它同时支持 Ubuntu、Debian 和 RHEL 系发行版，会自动安装依赖、生成配置并启用 systemd 服务。

本目录保留给手工部署和已有环境参考：

- `.env.production.example`：手工安装时使用的生产环境变量模板
- `php-fpm-tokenhub.conf`：PHP-FPM pool 配置示例
- `tokenhub-worker.service`：队列 worker（已改为 `bin/queue-worker.php`）
- `tokenhub-self-check.sh`：服务器自检脚本

手工步骤见 `docs/deployment/production.md`。
