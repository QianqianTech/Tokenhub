# TokenHub 离线 Docker 安装包

离线安装包用于目标服务器无法访问外网或镜像仓库的场景。目标服务器只需安装 Docker Engine（或 Docker Desktop）和 Docker Compose v2.20 以上版本。

## 制作（发布方）

```sh
scripts/package-offline-release.sh --output dist                     # amd64 + arm64
```

```powershell
powershell -ExecutionPolicy Bypass -File scripts/package-offline-release.ps1 -OutputDirectory D:\releases
```

生成目录 `TokenHub-<版本>-offline/`，包含以下内容：

- 两种架构的镜像：TokenHub 自身的镜像，以及 `.env.example` 中指定的 MySQL、Redis、Qdrant 版本；
- `tokenhub/` 部署包；
- `load-images.sh` 和 `load-images.ps1`；
- `manifest.json` 和 `SHA256SUMS`。

**安装包不含 `.env`、密钥或业务数据**，可以按普通软件介质交付。（旧版安装包会附带明文 `.env` 和数据快照，现已取消。）

## 安装（客户方）

```sh
./load-images.sh                               # 校验 SHA256，再按本机架构执行 docker load
cd tokenhub && ./install.sh --site tokenhub.example.com
```

Windows：

```powershell
powershell -ExecutionPolicy Bypass -File .\load-images.ps1
cd tokenhub; powershell -ExecutionPolicy Bypass -File .\install.ps1 -Site https://10.0.0.8
```

后续步骤见 `tokenhub/README.md`（即仓库中的 `deploy/docker/README.md`）。

## 升级

1. 在新安装包根目录执行 `load-images.sh`。
2. 用新的 `tokenhub/` 文件覆盖旧目录，保留原有的 `.env` 和 `backups/`。
3. 执行 `./upgrade.sh --tag <新版本>`。

## 迁移已有环境到新服务器

1. 源服务器执行 `./backup.sh`。
2. 把备份目录拷贝到目标服务器，在目标服务器完成安装。
3. 在目标服务器执行 `CONFIRM_RESTORE=1 ./restore.sh <备份目录>`。

恢复时会同时还原备份中的 `.env`，因为 `APP_KEY` 必须与已加密的数据一致。
