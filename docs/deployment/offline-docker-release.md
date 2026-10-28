# TokenHub 离线 Docker 发布包

离线发布包包含 amd64 与 arm64 镜像、MySQL/Redis/Qdrant/应用存储数据、发布 Compose 文件和明文 `.env`。目标服务器只需要 Docker Engine 与 Docker Compose，不需要源码、构建工具或网络访问。

在源环境执行：

```powershell
powershell -ExecutionPolicy Bypass -File scripts/package-offline-release.ps1 -OutputDirectory D:\releases\tokenhub
```

将完整 `TokenHub-offline-YYYYMMDD-HHMMSS` 目录通过受控介质交付。目录包含业务数据和明文机密，应按最高敏感级别保护。

在 Windows 目标服务器恢复：

```powershell
powershell -ExecutionPolicy Bypass -File .\import-and-start.ps1 -BundleRoot .\TokenHub-offline-YYYYMMDD-HHMMSS -ConfirmRestore
```

在 Linux/macOS 目标服务器恢复：

```sh
chmod +x ./import-and-start.sh
CONFIRM_RESTORE=1 ./import-and-start.sh ./TokenHub-offline-YYYYMMDD-HHMMSS
```

恢复会删除指定 Compose 项目的旧命名卷，再创建新卷并恢复数据；只有显式确认后才执行。导入器先校验 `SHA256SUMS`、加载当前 CPU 架构对应镜像、复制包内 `.env`，随后启动状态服务、导入 MySQL 和启动完整服务。完成后检查 `/health`。
