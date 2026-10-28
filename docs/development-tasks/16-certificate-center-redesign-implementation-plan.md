# 证书中心重构 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:executing-plans` to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 将证书页重构为围绕真实运行状态和申请闭环的证书中心，并支持域名、公网 IP、内网 IP 三种类型。

**Architecture:** `CertificateRequestService` 统一负责类型归一化、目标校验和安全的 Caddy 片段生成；申请接口继续返回受理结果，但前端只在真实状态读取到匹配证书时显示部署成功。公网 IP 明确为 Let’s Encrypt 短期证书意图与 12 小时维护策略；实际外部签发仍由已配置的运行环境完成，缺少前置条件时显示可操作失败。

**Tech Stack:** PHP 8.2、原生 ES Modules、Caddy、Docker PHP 测试环境、Node 语法检查。

---

### Task 1: 三类证书的服务契约

**Files:**

- Modify: `app/Services/CertificateRequestService.php`
- Modify: `app/Services/SystemSettingsService.php`
- Modify: `tests/Services/CertificateRequestServiceTest.php`
- Modify: `tests/Services/SystemSettingsServiceTest.php`

- [ ] **Step 1: 写入失败测试，规定 `public_ip` 与私网拒绝行为。**

```php
$publicIp = $service->build([
    'request_type' => 'public_ip',
    'ip_addresses' => '8.8.8.8',
]);
assert_same('public_ip', $publicIp['request_type']);
assert_same('letsencrypt_ip', $publicIp['settings']['renewal_provider']);
assert_same(12, $publicIp['settings']['renewal_check_interval_hours']);
assert_true(str_contains($publicIp['caddy_tls_fragment'], 'PUBLIC IP CERTIFICATE'));
```

同时对 `10.0.0.8` 作为 `public_ip` 调用断言 `CERTIFICATE_REQUEST_INVALID`，并断言 `internal_ip` 仍生成 `tls internal`。

- [ ] **Step 2: 在 Docker PHP 环境运行服务测试，确认因缺失 `public_ip` 而失败。**

Run: `docker compose exec -T php php tests/Services/CertificateRequestServiceTest.php`

Expected: 失败信息指出 `public_ip` 尚未被支持或预期的 provider 不存在。

- [ ] **Step 3: 最小实现类型与设置归一化。**

在 `CertificateRequestService` 中增加 `public_ip` 类型、IPv4/IPv6 公网地址判断、`letsencrypt_ip` provider、`renewal_check_interval_hours: 12` 和独立片段注释；不把私网、回环、链路本地或保留地址交给公网 CA。`SystemSettingsService` 仅接受 `domain`、`public_ip`、`internal_ip`，并保留该维护字段。

- [ ] **Step 4: 重跑两项服务测试。**

Run: `docker compose exec -T php php tests/Services/CertificateRequestServiceTest.php; docker compose exec -T php php tests/Services/SystemSettingsServiceTest.php`

Expected: 两个脚本均以退出码 0 完成。

### Task 2: 申请结果与真实状态的闭环数据

**Files:**

- Modify: `app/Services/CertificateRequestService.php`
- Modify: `app/Services/CertificateStatusService.php`
- Modify: `tests/Services/CertificateRequestServiceTest.php`
- Modify: `tests/Services/CertificateStatusServiceTest.php`

- [ ] **Step 1: 写入失败测试，规定申请结果与证书状态可关联。**

```php
assert_same('requested', $publicIp['settings']['certificate_request']['status']);
assert_same('public_ip', $publicIp['settings']['certificate_request']['request_type']);
assert_same(['8.8.8.8'], $publicIp['settings']['certificate_request']['targets']);
assert_same(12, $publicIp['settings']['certificate_request']['renewal_check_interval_hours']);
```

在状态服务测试加入 IP SAN 证书 fixture，并断言解析结果包含 `certificate_type === 'ip'`、目标 IP、签发者和到期时间。

- [ ] **Step 2: 运行聚焦测试，确认新字段/fixture 断言失败。**

Run: `docker compose exec -T php php tests/Services/CertificateRequestServiceTest.php`

Expected: 失败原因是操作记录缺少维护字段。

- [ ] **Step 3: 最小实现闭环元数据。**

为三类申请保存 `status`、`request_type`、`targets`、`requested_at`、`renewal_check_interval_hours`、`issuer_mode`；公网 IP 使用 `letsencrypt_shortlived`，内网 IP 使用 `internal_ca`。保持 `CertificateStatusService` 的真实 PEM 扫描为唯一的部署成功证据，不从设置 JSON 虚构证书状态。

- [ ] **Step 4: 运行请求与状态服务测试。**

Run: `docker compose exec -T php php tests/Services/CertificateRequestServiceTest.php; docker compose exec -T php php tests/Services/CertificateStatusServiceTest.php`

Expected: 两个测试脚本退出码为 0。

### Task 3: 重写证书中心前端

**Files:**

- Modify: `public/admin/assets/js/table.js`
- Modify: `public/admin/assets/css/app.css`
- Modify: `tests/Frontend/AdminSettingsCenterFrontendTest.php`

- [ ] **Step 1: 写入失败的前端契约。**

添加断言，要求源码包含：`certificate-center-page`、`certificate-type-card`、`domain`/`public_ip`/`internal_ip`、`certificate-request-progress`、`renderCertificateInventory`、`letsencrypt_shortlived`，并且不再要求旧的 `certificate-tabs`/`certificate-tab-panel` 结构。

- [ ] **Step 2: 运行前端契约测试并确认失败。**

Run: `docker compose exec -T php php tests/Frontend/AdminSettingsCenterFrontendTest.php`

Expected: 失败信息指向缺少新证书中心选择器或公网 IP 类型。

- [ ] **Step 3: 以单一证书中心渲染器替换旧四 Tab 渲染器。**

`createCertificateSettingsPage()` 生成：运行摘要、三种类型卡、按类型切换的请求字段、申请进度、真实证书库存和告警操作。提交时调用既有 `/admin-api/system/certificate-request`；响应只能显示“已受理/正在验证”，随后刷新 `/admin-api/system/certificate-status`。当真实证书含所请求 SAN 时才显示“已部署”。

公网 IP 表单显示 Let’s Encrypt、约 6 天有效期、HTTP-01/TLS-ALPN-01 可达性前置条件和 12 小时检查策略；内网 IP 表单显示内部 CA 与根证书信任提示。不要暴露私钥、完整证书路径或执行命令。

- [ ] **Step 4: 添加响应式样式。**

使用 `.certificate-center-page`、`.certificate-summary-grid`、`.certificate-type-grid`、`.certificate-request-workspace`、`.certificate-operation-progress` 和 `.certificate-inventory`。在窄屏将摘要、类型卡和申请/进度双栏降为单列。

- [ ] **Step 5: 运行语法与前端契约测试。**

Run: `node --check public/admin/assets/js/table.js; docker compose exec -T php php tests/Frontend/AdminSettingsCenterFrontendTest.php`

Expected: Node 和 PHP 测试都以退出码 0 完成。

### Task 4: API 与安全契约回归

**Files:**

- Modify: `tests/Support/AdminSecurityContractTest.php`
- Modify: `tests/Frontend/AdminFrontendSecurityTest.php`
- Modify: `tests/Services/CertificateRequestServiceTest.php`

- [ ] **Step 1: 写入回归断言。**

断言申请仍在管理员认证和 CSRF 保护下；前端源码不包含私钥字段名、证书文件内容或 shell 命令输出；公网 IP 申请的 `request_type` 为 `public_ip`，内网 IP 不会被重写为公网类型。

- [ ] **Step 2: 运行安全契约测试并确认失败。**

Run: `docker compose exec -T php php tests/Support/AdminSecurityContractTest.php; docker compose exec -T php php tests/Frontend/AdminFrontendSecurityTest.php`

Expected: 失败原因是缺少新的公网 IP/非泄露断言对应实现。

- [ ] **Step 3: 完成最小安全调整并重跑测试。**

只调整服务归一化、前端渲染文本和现有受保护接口的调用；不改变 `.env`、`docker-compose.yml`、Caddyfile 或管理员路由中间件。

Run: `docker compose exec -T php php tests/Support/AdminSecurityContractTest.php; docker compose exec -T php php tests/Frontend/AdminFrontendSecurityTest.php`

Expected: 两个脚本退出码为 0。

### Task 5: 综合验证与人工验收准备

**Files:**

- Modify: `docs/development-tasks/16-certificate-center-redesign-design.md`

- [ ] **Step 1: 在设计说明中记录实际验证结果。**

补充测试命令、运行日期和结果；明确生产公网 IP 签发仍需要已安装且受控配置的 Certbot 5.4+、外网可达挑战端口和 Caddy 重载权限。

- [ ] **Step 2: 运行完整测试套件。**

Run: `docker compose exec -T php php bin/run-tests.php`

Expected: 退出码 0；若 Docker API 权限阻断，则记录为环境阻断，不能误报测试通过。

- [ ] **Step 3: 执行 staging 人工验收清单。**

使用 Let’s Encrypt staging 完成一条可公网访问 IP 申请；确认页面从“已受理”推进到“已部署”、Caddy 已读取新证书、状态接口读到同一 IP SAN，并且维护计划显示 12 小时检查。域名与内网 IP 各完成一条同样的真实状态回读。

### Task 6: 内网 IP 单选与单证书约束

**Files:**

- Modify: `app/Services/CertificateRequestService.php`
- Modify: `public/admin/assets/js/table.js`
- Modify: `tests/Services/CertificateRequestServiceTest.php`
- Modify: `tests/Frontend/AdminSettingsCenterFrontendTest.php`

- [ ] **Step 1: 写入失败测试。**

在 `CertificateRequestServiceTest.php` 通过 `ReflectionMethod($service, 'singleInternalIp')` 断言 `['10.10.0.20', '192.168.1.10']` 返回 `null`，断言 `['10.10.0.20']` 返回该唯一 IP；在前端契约中断言存在 `renderInternalIpChoices`、`internal-ip-choice` 和 `data-internal-ip-choice`。

- [ ] **Step 2: 运行失败测试。**

Run: `docker compose exec -T php php tests/Services/CertificateRequestServiceTest.php; docker compose exec -T php php tests/Frontend/AdminSettingsCenterFrontendTest.php`

Expected: 服务测试因缺少 `singleInternalIp` 方法而失败，前端测试因缺少单选控件而失败。

- [ ] **Step 3: 最小实现单 IP 服务约束。**

新增 `singleInternalIp(array $ips): ?string`；在 `CertificateRequestService::build()` 中，`internal_ip` 只允许一个通过 IP 校验的目标，多个目标时返回 `CERTIFICATE_REQUEST_INVALID`。单个目标继续生成 `tls internal` 片段和 `internal_ca` 操作元数据。

- [ ] **Step 4: 最小实现内网 IP 选择器。**

在 `renderCertificateCenterRequestForm()` 中调用既有 `/admin-api/system/network-interface-ips`，将候选项渲染成 `internal-ip-choice` 单选按钮；点击时写入唯一的 `request_ip_addresses` 值。无候选时保留手工输入，但提交前只接受一个值。

- [ ] **Step 5: 运行回归验证。**

Run: `node --check public/admin/assets/js/table.js; docker compose exec -T php php tests/Services/CertificateRequestServiceTest.php; docker compose exec -T php php tests/Frontend/AdminSettingsCenterFrontendTest.php`

Expected: 三个命令均以退出码 0 完成。
