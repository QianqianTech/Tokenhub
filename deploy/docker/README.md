# TokenHub Docker 部署包

这个目录可以单独交付给客户，包含生产 Compose 文件和安装、升级、备份、恢复脚本。适用于 Linux、macOS 和 Windows（Docker Desktop）。

## 环境要求

- Docker Engine 24 以上（Linux），或 Docker Desktop（Windows 需使用 WSL 2 后端，也可用于 macOS）。
- Docker Compose v2.20 以上，即 `docker compose` 子命令。
- 建议配置：4 核 CPU、8 GB 内存、50 GB 磁盘。知识库数据量较大时，按向量规模增加内存。
- 使用域名自动签发证书时，宿主机的 80 和 443 端口必须能从公网访问。

## 首次安装

```sh
# Linux / macOS
./install.sh --site tokenhub.example.com          # 域名，自动申请 HTTPS 证书
./install.sh --site https://10.0.0.8              # IP 访问，使用 HTTPS（Caddy 内部 CA 签发）
./install.sh --site 10.0.0.8 --http-port 8080     # IP 访问，使用纯 HTTP（仅限可信内网）
./install.sh --site "tokenhub.example.com, https://10.0.0.8"   # 域名和 IP 同时可用
```

```powershell
# Windows
powershell -ExecutionPolicy Bypass -File .\install.ps1 -Site tokenhub.example.com
```

镜像来源有三种，每次安装选其一：

| 来源 | 参数 |
| --- | --- |
| 离线包 | 先在包根目录执行 `load-images.sh` 或 `load-images.ps1`，然后直接运行 `install.sh` |
| 镜像仓库（规划中） | `--image-prefix registry.example.com/tokenhub/ --tag 1.0.0` |
| 源码构建 | 在完整源码树中执行 `deploy/docker/install.sh --build` |

安装脚本依次执行以下步骤：

1. 生成 `.env`，所有密钥和密码随机生成。
2. 启动 MySQL、Redis 和 Qdrant。
3. 由 `init` 容器执行数据库迁移，并创建第一个超级管理员。
4. 启动 php、queue-worker、scheduler、rag-worker 和 caddy。
5. 运行自检，并打印访问地址和管理员密码。

安装后需要完成两件事：

- 首次登录后修改管理员密码，然后从 `.env` 中删除 `TOKENHUB_ADMIN_PASSWORD`。
- 妥善备份 `.env`。其中的 `APP_KEY` 用于加密厂商密钥，丢失后已保存的密钥无法解密。

## 安装后验收

```sh
./verify.sh                  # 检查容器状态、/health、/ready、管理后台、未带 Key 的 API 返回 401、自检、迁移状态
./verify.sh --backup-drill   # 另外演练一次备份和恢复，并记录恢复耗时
```

Windows 使用 `verify.ps1`。检查结果会写入 `verify-report-<时间>.txt`；如果有失败项，把这个文件和 `docker compose logs --tail=200` 的输出一起发给支持人员。

## 日常运维

```sh
docker compose ps                      # 查看状态，所有服务应显示 healthy
docker compose logs -f php             # 查看日志
docker compose exec php php bin/doctor.php           # 自检
docker compose exec php php bin/install.php admin --username=admin   # 重置管理员密码
```

## 升级、备份与恢复

```sh
./upgrade.sh --tag 1.1.0               # 先自动备份，再切换镜像版本、执行迁移并检查健康状态
./backup.sh                            # 备份到 ./backups/<时间>/：MySQL、Qdrant、存储卷、Redis、证书和 .env
CONFIRM_RESTORE=1 ./restore.sh ./backups/20261028-023000
```

建议用 cron 每天备份一次：

```
30 2 * * * /opt/tokenhub/backup.sh >> /var/log/tokenhub-backup.log 2>&1
```

Windows 使用 `upgrade.ps1` 和 `backup.ps1`，恢复需在 WSL 或 Git Bash 中执行 `restore.sh`。

## 访问方式说明

| 场景 | `--site` | 结果 |
| --- | --- | --- |
| 公网域名 | `tokenhub.example.com` | 自动申请 Let's Encrypt 证书，Cookie 带 Secure 标记 |
| 内网 IP + HTTPS | `https://10.0.0.8` | 使用 Caddy 内部 CA 签发的证书。在后台证书中心更换证书后，caddy 容器会在约 10 秒内自动重载，无需重启。根证书可从 caddy_data 卷导出（`/data/caddy/pki/authorities/local/root.crt`），分发给客户端后浏览器不再报警告 |
| 内网 IP + HTTP | `10.0.0.8` 或留空 | 不加密，`SESSION_SECURE=false`，仅用于可信网络 |
| 前面已有负载均衡或 HTTPS 代理 | 留空 | 将 `TRUSTED_PROXY_CIDRS` 设为代理的地址 |

安装后如需更改访问方式，修改 `.env` 中的 `TOKENHUB_SITE_ADDRESS`、`TOKENHUB_CADDY_TLS`、`TOKENHUB_CADDY_GLOBAL`、`APP_URL` 和 `SESSION_SECURE`，然后执行 `docker compose up -d`。

## 知识库向量库

默认使用 Qdrant（`RAG_VECTOR_STORE=qdrant`），并已启用 API Key。没有知识库需求的小规模部署可以在安装时传 `--vector-store mysql_fallback`，这样会改用 MySQL 计算向量相似度，但只适合几千个分片以内的数据量。

## 可选：按队列长度自动扩缩 worker

```sh
docker compose --profile autoscaling up -d
```

autoscaler 需要挂载 Docker socket，相当于获得宿主机 root 权限，因此默认不启用。默认的 worker 数量由 `QUEUE_WORKER_REPLICAS` 控制，值为 2。
