# Docker 部署：构建、发布与交付

客户侧的安装和运维步骤见 [deploy/docker/README.md](../../deploy/docker/README.md)，本文面向发布方。

## 目录结构

| 路径 | 用途 |
| --- | --- |
| `docker-compose.yml` | **开发环境**：挂载源码，镜像为 `tokenhub-php-dev` |
| `deploy/docker/compose.yml` | **生产环境**：只使用镜像，配置来自同目录的 `.env` |
| `deploy/docker/compose.build.yml` | 从源码构建生产镜像（`install.sh --build`） |
| `docker/release/*/Dockerfile` | 发布镜像：tokenhub-php、tokenhub-caddy、tokenhub-rag-worker、tokenhub-worker-autoscaler |
| `docker/php/Dockerfile` | 开发镜像，PHP 扩展与发布镜像保持一致 |
| `scripts/release/build-images.sh` | 构建镜像，可加载到本机、推送到镜像仓库或导出为 tar |
| `scripts/package-offline-release.sh` / `.ps1` | 制作离线安装包 |

## 生产栈的服务

| 服务 | 作用 | 健康检查 |
| --- | --- | --- |
| `init` | 一次性任务：等待 MySQL 和 Redis 就绪 → `bin/install.php run`（加锁迁移、创建首个管理员、记录版本） | 正常退出码为 0 |
| `php` | PHP-FPM。启动前执行 `bin/doctor.php --config`，生产配置不安全时拒绝启动 | FPM ping |
| `queue-worker` | 可靠队列 worker，默认 2 个副本（`QUEUE_WORKER_REPLICAS`） | 心跳文件 |
| `scheduler` | 定时投递清理任务，取代原来的 crontab | 心跳文件 |
| `rag-worker` | 知识库向量化（Python），以 uid 33 运行 | `--healthcheck` |
| `caddy` | 对外入口，负责域名/IP 的 HTTPS；证书中心更换证书后自动热重载 | `:8081/caddy-health` |
| `mysql` / `redis` / `qdrant` | 状态服务，不映射端口到宿主机 | 原生检查 |
| `worker-autoscaler` | 可选 profile，需要挂载 Docker socket | — |

所有容器的日志输出都限制为每个文件 20 MB、最多保留 5 个。

## 验收

安装或升级完成后执行 `deploy/docker/verify.sh`（Windows 使用 `verify.ps1`），检查结果写入报告文件。加 `--backup-drill` 参数会额外演练一次备份和恢复。

## 构建镜像

```sh
scripts/release/build-images.sh --tag 1.0.0                                    # 加载到本机
scripts/release/build-images.sh --tag 1.0.0 --push --image-prefix registry.example.com/tokenhub/
```

`--push` 会同时构建 amd64 和 arm64 两种架构。准备好镜像仓库后，客户安装时传入 `--image-prefix` 即可。

PHP 版本可以用 `--php-version 8.3` 指定，默认 8.4。

## 离线安装包

```sh
scripts/package-offline-release.sh --output dist                      # amd64 + arm64
scripts/package-offline-release.sh --output dist --platforms linux/amd64
```

```powershell
powershell -ExecutionPolicy Bypass -File scripts/package-offline-release.ps1 -OutputDirectory D:\releases
```

安装包结构：

```
TokenHub-1.0.0-offline/
  images/amd64/*.tar  images/arm64/*.tar      TokenHub 镜像，以及 .env.example 中指定的 MySQL/Redis/Qdrant 镜像
  tokenhub/                                   deploy/docker 部署包，附带 .env.example 和 VERSION
  load-images.sh  load-images.ps1             校验 SHA256 后执行 docker load
  manifest.json  SHA256SUMS  README.txt
```

安装包**不包含 `.env`、密钥或业务数据**，目标机器安装时会自行生成密钥。

如需迁移已有环境：在源服务器执行 `backup.sh`，把备份目录随安装包一起交付，在目标服务器安装完成后执行 `restore.sh`。

跨架构构建需要 QEMU。Docker Desktop 已自带；Linux 上执行：

```sh
docker run --privileged --rm tonistiigi/binfmt --install all
```

## 版本与升级

- 版本号记录在仓库根目录的 `VERSION` 文件中，也是镜像 tag。
- `bin/install.php run` 会把安装和升级历史写入 `system_settings.install.state`。
- 升级时，`upgrade.sh` 依次执行：备份 → 修改 `TOKENHUB_IMAGE_TAG` → 拉取或构建镜像 → `up -d`（init 容器执行迁移）→ 等待健康 → 自检。
- 数据库迁移无法自动回退。回滚步骤：切回旧的镜像 tag，再用 `restore.sh` 恢复升级前的备份。

## 配置优先级

PHP 读取配置时，**进程环境变量优先于 `.env` 文件**（compose 的 `environment:`、`env_file:`、CI 注入的变量都算环境变量），`.env` 只补充环境里没有的键。生产栈通过 `env_file: .env` 在容器创建时注入配置，因此修改 `.env` 后要执行 `docker compose up -d`（或 `upgrade.sh`）重建容器才会生效；只 `restart` 不会重新读取 `env_file`。源码安装的 PHP 服务不从 systemd 注入环境变量，修改 `/etc/tokenhub/tokenhub.env` 后重启对应服务即可。

## 持续集成

`.github/workflows/deployment.yml` 包含以下检查：

- 静态检查：PHP 语法、shellcheck、Compose 配置校验、安装器单元测试；
- 用 `install.sh --build` 完整安装，然后验证 `/health` 和 `/ready`，并依次演练重复安装、备份、恢复和升级；
- 在开发栈中运行完整测试；
- 在干净的 Ubuntu runner 上执行 `deploy/linux/install.sh`。
