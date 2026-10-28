# TokenHub 源码安装（不使用 Docker）

完整说明见 [docs/deployment/linux-source.md](../../docs/deployment/linux-source.md)。

```sh
sudo deploy/linux/install.sh --check                       # 只检测系统并打印安装计划
sudo deploy/linux/install.sh --site tokenhub.example.com   # 安装
sudo deploy/linux/upgrade.sh                               # 用新版本源码升级（会先备份）
```

| 文件 | 用途 |
| --- | --- |
| `install.sh` | 安装 PHP、Caddy、MySQL、Redis、Qdrant 和 RAG worker，并完成初始化 |
| `upgrade.sh` | 备份后重新部署代码、执行迁移、重启服务 |
| `systemd/` | `tokenhub-worker@.service`、`tokenhub-scheduler.service`、`tokenhub-rag-worker.service`、`qdrant.service` 以及 Caddy drop-in |
