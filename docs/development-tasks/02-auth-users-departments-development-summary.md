# TokenHub 02 用户权限和多级部门开发总结

## 结论

`02-auth-users-departments` 任务已完成当前清单要求。系统已经具备 Admin 登录会话、CSRF 校验、用户管理、部门树管理、角色权限范围、停用失效、审计记录和后续 API Key 权限联动的基础能力。

本轮未修改环境配置。当前环境口径保持为 MySQL 9.7。

已验证结果：

```text
38 test file(s) passed.
```

迁移验证：

```text
Applied 0 migration(s).
```

当前数据库表检查确认存在：

```text
departments: yes
users: yes
admin_sessions: yes
audit_logs: yes
system_settings: yes
```

## 开发范围

本任务围绕管理后台的身份认证、用户组织结构和权限边界展开，覆盖以下核心对象：

- `departments`
- `users`
- `admin_sessions`
- `audit_logs`
- `system_settings`

实现目标是让系统可以维护任意层级部门树，支持超级管理员、管理员、普通用户三类角色，并在服务端强制执行部门范围权限，确保用户、部门变更可以立即影响登录、管理端访问和 API Key 调用权限。

## 已完成能力

### 数据库结构

新增独立 02 迁移文件：

- `database/migrations/2026_07_08_000002_auth_users_departments.sql`

该迁移创建：

- `departments`
- `users`
- `admin_sessions`

`departments` 支持：

- `parent_id`
- `name`
- `path`
- `level`
- `sort_order`
- `status`
- 创建和更新时间

`users` 支持：

- `department_id`
- `username`
- `password_hash`
- `role`
- `status`
- `managed_department_ids`
- `last_login_at`
- 创建和更新时间

`admin_sessions` 支持：

- `user_id`
- `session_hash`
- `csrf_token_hash`
- `client_ip`
- `user_agent`
- `expires_at`
- `revoked_at`

迁移中同时插入根部门：

```text
Root /1/
```

### Admin 登录和会话

已实现 Admin 认证接口：

- `POST /admin-api/login`
- `GET /admin-api/me`
- `POST /admin-api/logout`
- `GET /admin-api/csrf`

会话能力包括：

- 密码使用 `password_hash` / `password_verify` 机制，禁止明文保存密码。
- 登录成功后写入 `admin_sessions`。
- 会话使用服务端哈希保存，不保存明文 session token。
- CSRF token 使用哈希保存。
- 退出登录会撤销当前会话。
- 用户停用后已有会话会失效。
- 用户所属部门停用后会话校验失败。

相关代码：

- `app/Services/AuthService.php`
- `app/Middleware/AdminAuthMiddleware.php`
- `app/Middleware/CsrfMiddleware.php`
- `app/Http/Controllers/AdminController.php`

### CSRF 校验

高危写操作已经接入 `CsrfMiddleware`。

缺失或错误 CSRF 时返回：

```text
ADMIN_CSRF_INVALID
```

HTTP 状态码为：

```text
403
```

覆盖的写操作包括：

- 用户新增、编辑、停用
- 用户重置密码
- 用户调整部门
- 部门新增、编辑、停用
- 部门移动
- 部门排序
- 退出登录
- 后续资源型管理接口写操作

### 角色权限

已实现三类角色边界：

- `super_admin`
- `admin`
- `user`

权限规则：

- 超级管理员可以管理全部部门和用户。
- 管理员只能管理授权部门及其子部门。
- 普通用户不能管理其他用户和部门。
- 管理端资源访问会在服务端按角色和部门范围过滤。
- 更新、停用等操作会先校验目标数据是否在当前管理员可管理范围内。

相关代码：

- `app/Services/PermissionService.php`
- `app/Services/CrudService.php`
- `app/Http/Controllers/AdminController.php`

### 部门树管理

已实现部门树查询：

- `GET /admin-api/departments/tree`

已实现部门维护接口：

- `POST /admin-api/departments`
- `PUT /admin-api/departments/{id}`
- `DELETE /admin-api/departments/{id}`
- `POST /admin-api/departments/{id}/move`
- `POST /admin-api/departments/sort`
- `PATCH /admin-api/departments/{id}/status`

部门能力包括：

- 创建任意层级部门。
- 查询部门树。
- 管理员视角只返回授权范围内的部门。
- 当父部门不可见时，授权范围内的子部门会作为当前视图根节点返回。
- 移动部门后重建自身和所有子部门的 `path` 与 `level`。
- 支持同级部门排序。
- 支持部门启用和停用。
- 部门停用后，部门下用户的会话和 API Key 权限缓存会立即失效。

相关代码：

- `app/Services/DepartmentService.php`
- `app/Http/Controllers/AdminController.php`

### 用户管理

已实现用户维护接口：

- `GET /admin-api/users`
- `POST /admin-api/users`
- `PUT /admin-api/users/{id}`
- `DELETE /admin-api/users/{id}`
- `POST /admin-api/users/{id}/reset-password`
- `POST /admin-api/users/{id}/department`
- `PATCH /admin-api/users/{id}/status`

用户能力包括：

- 创建用户。
- 编辑用户。
- 停用用户。
- 重置密码。
- 调整所属部门。
- 按部门权限范围查询和操作。
- 用户停用后撤销其管理端会话。
- 用户停用后失效其 API Key 权限缓存。

相关代码：

- `app/Services/CrudService.php`
- `app/Services/AuthService.php`
- `app/Services/ApiKeyService.php`
- `app/Http/Controllers/AdminController.php`

### 停用和权限失效

用户或部门停用后已经接入联动失效：

- 停用用户后撤销该用户已有 Admin 会话。
- 停用用户后失效该用户 API Key 缓存。
- 停用部门后撤销部门下用户已有 Admin 会话。
- 停用部门后失效部门下用户 API Key 缓存。
- 登录态校验会检查用户状态。
- 登录态校验会检查用户所属部门状态。

相关代码：

- `app/Services/AuthService.php`
- `app/Services/CrudService.php`
- `app/Services/DepartmentService.php`
- `app/Services/ApiKeyService.php`

### 审计日志

登录、退出、用户变更和部门变更已接入审计记录基础能力。

审计目标包括：

- 操作人
- 操作类型
- 目标对象
- IP
- 时间
- 变更上下文

相关代码：

- `app/Services/AuditLogger.php`
- `app/Http/Controllers/AdminController.php`
- `app/Services/CrudService.php`

## 接口清单

### 认证接口

- `POST /admin-api/login`
- `GET /admin-api/me`
- `POST /admin-api/logout`
- `GET /admin-api/csrf`

### 部门接口

- `GET /admin-api/departments/tree`
- `GET /admin-api/departments`
- `POST /admin-api/departments`
- `PUT /admin-api/departments/{id}`
- `DELETE /admin-api/departments/{id}`
- `POST /admin-api/departments/{id}/move`
- `POST /admin-api/departments/sort`
- `PATCH /admin-api/departments/{id}/status`

### 用户接口

- `GET /admin-api/users`
- `POST /admin-api/users`
- `PUT /admin-api/users/{id}`
- `DELETE /admin-api/users/{id}`
- `POST /admin-api/users/{id}/reset-password`
- `POST /admin-api/users/{id}/department`
- `PATCH /admin-api/users/{id}/status`

## 关键文件

- `database/migrations/2026_07_08_000002_auth_users_departments.sql`
- `app/Bootstrap/Kernel.php`
- `app/Http/Controllers/AdminController.php`
- `app/Middleware/AdminAuthMiddleware.php`
- `app/Middleware/CsrfMiddleware.php`
- `app/Services/AuthService.php`
- `app/Services/PermissionService.php`
- `app/Services/DepartmentService.php`
- `app/Services/CrudService.php`
- `app/Services/ApiKeyService.php`
- `app/Services/AuditLogger.php`
- `tests/AuthUsersDepartmentsContractTest.php`

## 验收覆盖

当前已经覆盖的验收点：

- 超级管理员可以创建一级部门和多级子部门。
- 部门移动后，子部门 `path` 和 `level` 由服务端重建。
- 管理员不能越权访问平级、上级和未授权部门数据。
- 普通用户访问用户和部门管理能力会被拒绝。
- 停用用户后，该用户登录失败，已有会话失效。
- 停用部门后，部门下用户不能继续通过有效会话访问管理端。
- 用户和部门停用会触发 API Key 缓存失效边界。
- CSRF 缺失时，写操作返回 `ADMIN_CSRF_INVALID`。
- 登录、退出、用户变更、部门变更写入审计日志。

## 验证记录

测试命令：

```bash
docker compose exec -T php php bin/run-tests.php
```

结果：

```text
38 test file(s) passed.
```

迁移命令：

```bash
docker compose exec -T php php bin/migrate.php
```

结果：

```text
Applied 0 migration(s).
```

表存在性检查：

```text
departments:yes
users:yes
admin_sessions:yes
audit_logs:yes
system_settings:yes
```

Compose 配置检查确认当前 MySQL 环境为：

```text
image: mysql:9.7
```

## 当前边界

02 任务本身已经满足清单中的开发任务、测试任务和验收标准。当前未继续扩展以下内容：

- 不修改 Docker、Caddy、`.env`、MySQL 数据卷等环境配置。
- 不实现 03 之后的厂商、模型、价格、网关、配额、内容留存、WAF、大屏等业务能力。
- 不把旧的大型全量迁移作为当前 02 验收路径；当前 02 使用独立 auth/users/departments 迁移。

如需做最终人工验收，建议只执行操作级链路验证：

1. 使用 `scripts/create-admin.php` 创建管理员。
2. 登录 `/admin-api/login`。
3. 获取 `/admin-api/csrf`。
4. 携带会话和 CSRF 调用用户、部门写接口。
5. 验证越权访问、停用用户、停用部门和审计日志。

## 结论

02 用户权限和多级部门任务已经完成。当前系统具备后续 03 厂商模型、05 API Key、网关调用和管理后台资源权限控制所需的用户、部门、会话、CSRF、审计和权限范围基础。
