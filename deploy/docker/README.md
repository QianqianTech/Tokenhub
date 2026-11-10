# TokenHub Docker 部署包

这个目录可以单独交付给客户，包含生产 Compose 文件和安装、升级、备份、恢复脚本。适用于 Linux、macOS 和 Windows（Docker Desktop）。

从 GitHub 在线安装时，ARM64 用户可按 [Docker Hub 部署步骤](../../docs/deployment/README.md#从-docker-hub-部署)直接拉取预构建镜像。源码构建使用[根目录 README 中的下载入口](../../README.md#快速安装)，该入口会下载完整源码并启用源码构建。本目录的同名脚本需要配套的部署文件。

## 环境要求

- Docker Engine 24 以上（Linux），或 Docker Desktop（Windows 需使用 WSL 2 后端，也可用于 macOS）。
- Docker Compose v2.20 以上，即 `docker compose` 子命令。
- 源码构建还需要 Docker Buildx。
- 建议配置：4 核 CPU、8 GB 内存、50 GB 磁盘。知识库数据量较大时，按向量规模增加内存。
- 使用域名自动签发证书时，宿主机的 80 和 443 端口必须能从公网访问。

## 首次安装

下面是使用完整源码树构建镜像时的站点参数示例。使用 Docker Hub 或离线包时，按下表选择相应参数。

```sh
# Linux / macOS
./install.sh --build --site tokenhub.example.com          # 域名，自动申请 HTTPS 证书
./install.sh --build --site https://10.0.0.8              # IP 访问，使用 HTTPS（Caddy 内部 CA 签发）
./install.sh --build --site 10.0.0.8 --http-port 8080      # IP 访问，使用纯 HTTP（仅限可信内网）
./install.sh --build --site "tokenhub.example.com, https://10.0.0.8"   # 域名和 IP 同时可用
```

```powershell
# Windows
powershell -ExecutionPolicy Bypass -File .\install.ps1 -Build -Site tokenhub.example.com
```

镜像来源有三种，每次安装选其一：

| 来源 | 参数 |
| --- | --- |
| 离线包 | 先在包根目录执行 `load-images.sh` 或 `load-images.ps1`，然后直接运行 `install.sh` |
| [Docker Hub 预构建镜像](../../docs/deployment/README.md#从-docker-hub-部署)（`linux/arm64`） | `--image-prefix docker.io/leofan2022/ --tag 1.0.3`。PowerShell 使用 `-ImagePrefix` 和 `-Tag` |
| 源码构建 | 在完整源码树中执行 `deploy/docker/install.sh --build` |

安装脚本依次执行以下步骤：

1. 生成 `.env`，所有密钥和密码随机生成。
2. 启动 MySQL、Redis 和 Qdrant。
3. 由 `init` 容器执行数据库迁移，并创建第一个超级管理员。
4. 启动 php、queue-worker、scheduler、rag-worker 和 caddy。
5. 运行自检，并打印访问地址和初始管理员凭据。

安装后需要完成两件事：

- 首次登录后修改管理员密码，然后从 `.env` 中删除 `TOKENHUB_ADMIN_PASSWORD`。
- 妥善备份 `.env`。其中的 `APP_KEY` 用于加密厂商密钥，丢失后已保存的密钥无法解密。

## 安装网络排查

Ubuntu 22.04 使用 `jammy` 软件源。按 Docker 文档配置 APT 源和 `Signed-By` 后，安装 Engine、Compose 和 Buildx，再从解压后的源码目录运行预检。若 `download.docker.com` 连接重置，可从联网机器下载匹配 Ubuntu 版本和 CPU 架构的官方 DEB 包，或采用阿里云等可信 Docker CE APT 镜像。[Docker Engine 安装文档](https://docs.docker.com/engine/install/ubuntu/) · [阿里云 Docker CE 镜像](https://developer.aliyun.com/mirror/docker-ce)

```bash
sudo apt-get install docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
sudo systemctl enable --now docker
bash deploy/docker/install.sh --check --build
```

Git 下载遇到 `curl 92` 或 `early EOF` 时，根目录安装入口会以 HTTP/1.1 重试一次。仍无法连接 GitHub 时，可从可信发布渠道取得并校验完整源码包，解压后直接运行 `deploy/docker/install.sh --build`。

`registry-1.docker.io` 超时属于镜像拉取阶段。Docker daemon 负责运行镜像的拉取，独立的 BuildKit builder 可单独配置镜像站。如果有可直接访问的镜像仓库，也可以在首次安装命令加 `--no-start` 生成配置，再在 `deploy/docker/.env` 中填入所需镜像源。已有 `.env` 可以直接编辑。以下值是示例，请替换成实际可用的地址。[Docker 镜像站配置](https://docs.docker.com/docker-hub/image-library/mirror/) · [BuildKit 镜像站配置](https://docs.docker.com/build/buildkit/configure/)

```dotenv
TOKENHUB_BUILD_BASE_IMAGE_PREFIX=registry.example.com/dockerhub/library/
MYSQL_IMAGE=registry.example.com/dockerhub/library/mysql:9.7
REDIS_IMAGE=registry.example.com/dockerhub/library/redis:8.0-alpine
QDRANT_IMAGE=registry.example.com/dockerhub/qdrant/qdrant:v1.15.0
TOKENHUB_BUILD_DEBIAN_MIRROR=https://packages.example.com
TOKENHUB_BUILD_PIP_INDEX_URL=https://packages.example.com/pypi/simple
```

基础镜像前缀需以 `/` 结尾。Debian 镜像需要同时提供 `/debian` 和 `/debian-security`。PyPI 镜像填写包索引的 `/simple` URL，例如清华镜像的 `https://pypi.tuna.tsinghua.edu.cn/simple`。构建参数请使用无需凭据的地址。[清华 PyPI 镜像](https://mirrors.tuna.tsinghua.edu.cn/help/pypi/)

配置后回到原目录执行 `bash deploy/docker/install.sh --build --yes`，续用已有密钥和镜像缓存。目标机长期无法访问依赖源时，可以在联网机器制作与目标架构匹配的离线包。[离线安装包](../../docs/deployment/offline-docker-release.md)

## 管理员密码

首次安装默认创建 `admin`，未指定密码时自动生成 20 位随机密码。若找不到安装终端的记录，在 `compose.yml` 所在目录执行以下命令查看 `.env` 中的初始凭据。

```sh
grep -E '^TOKENHUB_ADMIN_(USERNAME|PASSWORD)=' .env
```

Windows PowerShell 可用 `Select-String -Path .env -Pattern '^TOKENHUB_ADMIN_(USERNAME|PASSWORD)='`。请在自己的终端查看，并在分享日志时删除任何凭据。

原地升级保留当前密码，`.env` 里的密码仅用于初始化。忘记现用密码时，可在 SMTP 可用且已绑定邮箱的情况下通过登录页“忘记密码？”重置，或请另一位超级管理员在后台重置。需要从服务器恢复超级管理员访问时，在同一目录运行以下命令，并在提示时输入至少 12 个字符的新密码。

```sh
docker compose exec php php bin/install.php admin --username=admin
```

如果部署时自定义了超级管理员用户名，请将 `admin` 换成实际用户名。命令也能创建超级管理员，填错用户名可能新增账号。

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
```

## 升级、备份与恢复

从 GitHub 下载升级入口、预检以及源码构建升级的步骤见 [Docker 升级指南](../../docs/deployment/upgrade.md)。若只更新镜像，也可在已有运维包内直接执行：

```sh
./upgrade.sh --tag 1.1.0               # 打印版本变更，自动备份，切换镜像版本、执行迁移并检查健康状态
./backup.sh                            # 备份到 /var/backups/tokenhub/<时间>/（非 root 为 ~/tokenhub-backups/，可用 BACKUP_DIR 指定）
CONFIRM_RESTORE=1 ./restore.sh /var/backups/tokenhub/20261028-023000
```

升级失败时（拉取 / 构建失败、迁移失败、服务不健康），`upgrade.sh` 自动把镜像 tag 改回原版本并重新启动；如果迁移已经执行过一部分，会提示用升级前的备份恢复（迁移不可逆）。加 `--no-rollback` 可保留失败现场排查。

资源上限与代理（写在 `.env` 中）：

| 变量 | 默认 | 说明 |
| --- | --- | --- |
| `PHP_MEMORY_LIMIT` / `PHP_CPUS` | `2g` / `0` | php 容器（`0` = 不限 CPU） |
| `MYSQL_MEMORY_LIMIT` / `MYSQL_CPUS` | `2g` / `0` | |
| `QDRANT_MEMORY_LIMIT` / `QDRANT_CPUS` | `4g` / `0` | |
| `RAG_WORKER_MEMORY_LIMIT` / `RAG_WORKER_CPUS` | `2g` / `0` | 开启 OCR 时建议调高 |
| `HTTP_PROXY` / `HTTPS_PROXY` / `NO_PROXY` | 空 | 内网经代理访问模型供应商、推送服务和 ACME；容器名（mysql、redis、qdrant、caddy 等）始终直连。SMTP 不走 HTTP 代理 |
| `BACKUP_DIR` | 见上 | 备份目录 |

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

### 公网 IPv4 和 8443

首次安装时，可以显式使用 `--public-ip`，让安装器从本机路由或外部查询获取公网 IPv4 并配置 HTTPS。若出口 IP 和入站 IP 不同，或者查询失败，使用 `--site "https://$public_ip"` 明确指定实际入站地址，并把 `public_ip` 设为服务器的公网 IPv4。Windows 可通过 `-Site` 指定地址。

```bash
bash deploy/docker/install.sh --build --public-ip --https-port 8443 --yes
```

公网访问使用 `https://公网IPv4:8443/admin/`，云安全组和主机防火墙需允许 TCP 8443。使用 `--public-ip` 且公网 TCP 80 可达时，Caddy 会申请并自动续期浏览器信任的 Let's Encrypt IP 证书。申请失败或选择了其他 HTTP 端口时，Caddy 使用内部 CA。此时可从部署目录执行 `docker compose cp caddy:/data/caddy/pki/authorities/local/root.crt ./tokenhub-root.crt`，再将根证书导入客户端。[Let's Encrypt IP 证书](https://letsencrypt.org/2026/01/15/6day-and-ip-general-availability)

公网域名使用 `--site tokenhub.example.com`，将域名解析到服务器并放行 80/443，以便 Caddy 自动签发证书。

### 将已有本机安装切换到公网 HTTPS

`--site http://localhost --bind 127.0.0.1` 用于 Docker 宿主机上的 HTTP 访问。要让现有实例通过公网 IP 的 8443 提供 HTTPS，先备份 `deploy/docker/.env`，然后更新下列配置。将示例 IP `203.0.113.9` 换成实际公网 IPv4。

```dotenv
APP_URL=https://203.0.113.9:8443
SESSION_SECURE=true
TOKENHUB_SITE_ADDRESS=https://203.0.113.9
TOKENHUB_CADDY_TLS=tls internal
TOKENHUB_CADDY_GLOBAL=default_sni 203.0.113.9
TOKENHUB_BIND_ADDRESS=0.0.0.0
TOKENHUB_HTTPS_PORT=8443
TOKENHUB_HTTP_REDIRECT_URL=https://203.0.113.9:8443
```

在 `deploy/docker` 目录应用配置，使用原有镜像、密钥和数据卷。

```bash
docker compose up -d --no-build --pull never
bash verify.sh
```

## 知识库向量库

默认使用 Qdrant（`RAG_VECTOR_STORE=qdrant`），并已启用 API Key。没有知识库需求的小规模部署可以在安装时传 `--vector-store mysql_fallback`，这样会改用 MySQL 计算向量相似度，但只适合几千个分片以内的数据量。

## 可选：按队列长度自动扩缩 worker

```sh
docker compose --profile autoscaling up -d
```

autoscaler 需要挂载 Docker socket，相当于获得宿主机 root 权限，因此默认不启用。默认的 worker 数量由 `QUEUE_WORKER_REPLICAS` 控制，值为 2。
