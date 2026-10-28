# 02 用户权限和多级部门任务清单

## 目标

实现超级管理员、管理员、普通用户、多级部门、登录会话、权限范围和审计基础。

## 数据表

- `users`
- `departments`
- `audit_logs`
- `system_settings`

## 开发任务

- [ ] 创建 `departments` 表，字段包含 `id`、`parent_id`、`name`、`path`、`level`、`sort_order`、`status`、`created_at`、`updated_at`。
- [ ] 创建 `users` 表，字段包含 `id`、`department_id`、`username`、`password_hash`、`role`、`status`、`last_login_at`、`created_at`、`updated_at`。
- [ ] 实现密码哈希和校验，禁止保存明文密码。
- [ ] 实现 Admin 登录接口：`POST /admin-api/login`。
- [ ] 实现 Admin 当前用户接口：`GET /admin-api/me`。
- [ ] 实现 Admin 退出接口：`POST /admin-api/logout`。
- [ ] 实现 Admin 会话 Cookie，使用 `HttpOnly`、`Secure`、`SameSite=Strict`。
- [ ] 实现 CSRF Token 校验，高危写操作必须校验。
- [ ] 实现角色权限：超级管理员、管理员、普通用户。
- [ ] 实现部门树查询：`GET /admin-api/departments/tree`。
- [ ] 实现部门新增、编辑、停用、移动、排序接口。
- [ ] 部门移动后更新自身和所有子部门的 `path`、`level`。
- [ ] 实现用户新增、编辑、停用、重置密码、调整部门。
- [ ] 管理员只能管理授权部门及子部门。
- [ ] 普通用户不能管理其他用户和部门。
- [ ] 用户、部门停用后，关联 API Key 和调用权限必须立即失效。
- [ ] 所有登录、退出、用户变更、部门变更写入审计日志。

## 测试任务

- [ ] 超级管理员可以创建一级部门和多级子部门。
- [ ] 部门移动后，子部门 `path` 和 `level` 正确更新。
- [ ] 管理员不能访问平级部门、上级部门和未授权部门数据。
- [ ] 普通用户访问用户管理接口返回 `ADMIN_PERMISSION_DENIED`。
- [ ] 禁用用户后，该用户登录失败，已有会话失效。
- [ ] 禁用部门后，部门下用户不能继续调用对外 API。
- [ ] CSRF 缺失时，写操作返回 `ADMIN_CSRF_INVALID`。
- [ ] 审计日志记录操作者、目标对象、操作类型、时间和 IP。

## 验收标准

- 可以维护任意层级部门树。
- 权限范围以部门树为基础在服务端强制校验。
- 用户和部门停用会立即影响登录、管理端权限和 API Key 调用。
- 敏感操作可在审计日志中追踪。

