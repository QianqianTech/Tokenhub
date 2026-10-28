# TokenHub 管理后台 Web 深度优化任务清单

分析日期：2026-10-28　源码基线：`1ea2251`　状态：全部任务为 `proposed`

范围：`public/admin/**`（管理后台 SPA）及其直接依赖的 `admin-api` 列表接口和 Caddy 静态资源配置。不含运营大屏 `public/screen`。
方法：静态代码审阅，**未在浏览器中运行**。标注“需核实”的条目需要运行环境确认后再排期。

与 [平台完善性审计](2026-10-28-platform-completeness-audit.md) 的关系：那份清单关注调用、计量和授权等后端流程；本清单只处理管理界面本身的正确性、工程结构、交互体验和性能，两者不重复。

---

## 1. 现状概览

| 指标 | 数值 | 说明 |
| --- | --- | --- |
| 技术栈 | 原生 ES Modules，无构建步骤 | `index.html` 直接加载 `js/app.js` |
| JS 总量 | 7 个模块 / 7,865 行 | 其中 `table.js` 一个文件 **5,596 行、238 KB、232 个顶层函数** |
| CSS 总量 | `app.css` 4,051 行 / 84 KB | 76 种硬编码颜色；主题变量从第 2060 行才开始定义 |
| 页面数 | `resources.js` 中约 130 个资源配置 | 10 个业务域，权限菜单动态生成 |
| 前端测试 | `tests/Frontend` 33 个 | **全部**是对源码做 `str_contains` 的字符串断言，不运行页面 |
| 静态资源传输 | Caddy 未开启压缩、未设置缓存头 | `docker/caddy/Caddyfile:52-56` |

**做得好的地方（优化时保留）：** 全部用 `textContent` / `createElement` 渲染，未使用 `innerHTML`；CSP 严格（`script-src 'self'`）；CSRF token 只放在内存；Logo 地址有同源白名单（`app.js` 中的 `adminSafeLocalAssetUrl`）；侧边栏已有键盘、`aria-expanded` 和移动端遮罩；支持深色、浅色和跟随系统三种主题。

---

## 2. 最需要先处理的问题

1. **每次导航都渲染两次，带筛选条件的跳转会丢失筛选。** 点击菜单写入 hash，`hashchange` 回调再走一遍导航，把 `filters` 清空后重新渲染。仪表盘下钻到“用量记录（某用户）”时，用户条件会被第二次渲染清掉。→ UI-001
2. **通用列表只能看前 50 条，没有翻页。** 后端列表接口不返回 total，前端也没有分页器。日志、审计、用量记录等数据量大的页面只能看到最新 50 条。→ UI-002
3. **下拉选项最多只有 100 条。** 前端请求 `limit: 500`，后端统一截断为 100。超过 100 个用户或 API Key 后，新建表单里选不到后面的对象，列表里的名称也会退化为 ID。→ UI-003
4. **`table.js` 已成为巨石文件，前端测试又锁死了源码字符串。** 任何重构都会让大量测试失败，所以要先建立行为测试，再做拆分。→ UI-013、UI-010

---

## 3. 任务清单

字段说明：**优先级** P0（结果错误或功能缺失）、P1（工程与核心体验）、P2（体验增强）、P3（长期）。**工作量** S ≤ 1 天，M 2–4 天，L ≥ 1 周。

### A. 正确性缺陷（P0）

#### UI-001 修复导航双重渲染和筛选丢失　`P0 · S`
- **问题：** `adminNavigateDomain` 和 `adminNavigate` 写入 hash 后，`hashchange` 再次调用 `adminNavigateDomain(..., fromHash=true)`。这条路径没有“页面未变化就返回”的判断，并且执行 `adminState.filters = {}`。结果是每次导航请求两遍接口，`tokenhub-admin-navigate` 事件和仪表盘下钻传入的 filters 被清空。
- **证据：** `public/admin/assets/js/app.js:460-476`（`adminNavigateDomain`，第 470 行清空 filters）、`:582`（`adminNavigate` 写 hash）、`:677-681`（hashchange 监听）。
- **方案：** 只保留一条入口：点击时只写 hash，所有渲染都由 `hashchange` 触发；或者在写 hash 前设置 `suppressNextHashChange` 标记。把 filters 序列化到 hash 的 query（如 `#/usage/usage-records?user_id=3`），刷新页面后筛选仍保留（与 UI-023 合并）。
- **验收：** 浏览器 Network 面板中，一次菜单点击只产生一组列表请求；从仪表盘排行点击员工，跳转后的列表带有该员工筛选，刷新后仍然有效。

#### UI-002 通用列表分页　`P0 · M`
- **问题：** 通用资源页固定 `limit: 50`，没有分页器。只有 API Key 页单独实现了分页（`renderApiKeyPager`）。
- **证据：** `table.js:1040`、`table.js:1573-1587`（`renderRows`）；后端 `AdminController::index`（`app/Http/Controllers/AdminController.php:87`）只返回行数据，但 `CrudService::count()`（`app/Services/CrudService.php:48`）已经存在，只是没有被调用。
- **方案：** `index` 返回 `{ items, pagination: { limit, offset, total } }`。为兼容旧响应，可以通过 `?with_total=1` 开启，或使用响应头 `X-Total-Count`。前端把 `renderApiKeyPager` 抽成通用 `Pager` 组件，支持每页 20/50/100 条和跳页。对日志类大表评估改用游标分页（`before_id`），避免深度 OFFSET。
- **验收：** logs、audit-logs、usage-records、desensitization-events 都能翻到最后一页；总数与数据库一致；翻页条件写入 URL。

#### UI-003 引用数据被截断：改为远程搜索选择器　`P0 · M`
- **问题：** `lookupRows` 和若干弹窗请求 `limit: 500`，但后端各列表服务统一使用 `min(100, …)`。超过 100 条后，下拉选项缺失，列表中的“部门 / 员工 / 模型名称”会显示为原始 ID。
- **证据：** `table.js:1610`、`:2612-2613`、`:2753`、`:3817`；`CrudService.php:39`、`AdminUserService.php:40`、`ApiKeyService.php:317`；仪表盘部门下拉 `dashboard.js` 中的 `loadDepartmentOptions` 也是 `limit: 100`。
- **方案：** 新增轻量接口 `GET /admin-api/lookups/{users|api-keys|models|departments|providers}?q=&ids=`，只返回 `id`、`label` 和必要的外键。前端实现可搜索的 `RemoteSelect` 和 `RemoteMultiSelect`，在输入时搜索。列表名称解析改为“按当前页出现的 ID 批量查询 `ids=`”，不再拉取全量数据。
- **验收：** 准备 1,000 个用户的数据，新建 API Key 时可以搜到第 1,000 个用户；列表页不出现裸 ID；单次打开表单的请求体积小于 20 KB。

#### UI-004 仪表盘并发、局部失败和时区　`P0 · S`
- **问题：**
  1. 员工搜索使用 300 ms 防抖后调用 `load()`。`load()` 先 `clear(host)`，再等待 7 个接口返回，最后追加内容。两次请求重叠时，两批结果都会追加到页面，出现**重复面板**。
  2. 7 个接口使用 `Promise.all`，任意一个失败，整个仪表盘都显示错误。
  3. “今日 / 近 7 天”按 **UTC 零点**计算起点。对东八区用户，“今日”从当天 08:00 开始，前 8 小时会被漏算（**需核实**后端对 `from` 和 `to` 的时区解释）。
- **证据：** `dashboard.js:41`、`:56`、`:72-84`、`:121`（`startOfUtcDay`）。
- **方案：** 每次加载生成 `loadSeq` 或 `AbortController`，过期的结果直接丢弃；改用 `Promise.allSettled`，失败的面板单独显示错误和重试按钮；前端只传本地时区的日期边界和 `tz=Asia/Shanghai`，由后端统一换算。
- **验收：** 连续快速输入搜索词后面板不重复；断开 `/dashboard/security` 时其他面板正常显示；00:00–08:00 产生的调用会计入“今日”。

#### UI-005 列表加载失败时不再渲染成数据行　`P0 · S`
- **问题：** 接口失败时，代码构造 `data: [{ message, request_id }]` 并按正常表格渲染，用户看到的是一张只有 message 列的表。
- **证据：** `table.js:562`、`:1576`、`:3222`。
- **方案：** 统一 `ErrorState` 组件，显示错误文案、请求编号（可复制）和“重试”按钮。与 UI-020 一起实现。

#### UI-006 按钮防重复提交和未捕获异常　`P0 · S`
- **问题：** “导出”和配置化动作按钮直接 `await api(...)`，没有 try/catch，也没有禁用状态。失败时产生未处理的 Promise rejection，用户没有反馈；连续点击会提交多次。弹窗中的“保存”按钮同样没有禁用。
- **证据：** `table.js:1052-1068`、`:2554`、`:2580`；整个 `table.js` 只有 5 处 `disabled = true`。
- **方案：** 新增 `asyncButton(label, handler)` 工具函数：执行期间禁用并显示加载状态，统一捕获错误并提示，完成后恢复。用它替换所有调用写接口的按钮。
- **验收：** 快速双击“导出”只创建一个导出任务；接口返回 500 时出现错误提示，控制台没有 `Uncaught (in promise)`。

### B. 工程结构（P1）

#### UI-013 建立浏览器级行为测试（重构前置）　`P1 · M`
- **问题：** `tests/Frontend` 中的 33 个测试全部读取源码做字符串断言，例如 `str_contains($tableSource, 'className: "panel table-panel"')`。它们既不能证明页面真的可用，又会在任何文件拆分或改名时失败。
- **方案：** 用 Playwright（已有 Docker Compose 环境）编写 E2E 冒烟测试：登录、遍历每个菜单页并断言无控制台错误、新建 / 编辑 / 删除一条部门 / 用户 / API Key、分页、筛选、深色主题截图。逐步把字符串断言迁移成行为断言，只保留确实需要锁定的安全约束（如禁用 `innerHTML`）。接入 `axe-core`，为 UI-031 做准备。
- **验收：** `bin/run-tests.php` 或独立的 `npm run e2e` 可在 CI 中运行；覆盖全部 10 个域的首页；拆分 `table.js` 后测试仍然通过。

#### UI-010 拆分 `table.js`，按页面注册和按需加载　`P1 · L`　依赖 UI-013
- **问题：** 一个文件同时包含通用表格、30 多个专用页面（证书、RAG、设置中心……）、表单、弹窗和格式化逻辑。`renderResourcePage` 是一个包含 30 多个 `if` 的分发链（`table.js:389-509`），并有重复分支（`rag-evaluation` 和 `rag-retrieval-evaluation` 渲染同一页面，`:490-496`）。首次加载就要下载并解析全部 238 KB。
- **方案：** 建议目录结构：
  ```
  js/core/      api.js dom.js router.js store.js
  js/components/ table.js pager.js modal.js form.js remote-select.js toast.js states.js
  js/pages/     rag/ settings/ certificates/ api-keys/ ...
  js/registry.js  // { key: () => import("./pages/rag/build.js") }
  ```
  使用页面注册表 + `import()` 动态加载，替代 if 链。通用 CRUD 页继续由 `resources.js` 驱动。
- **验收：** 入口包小于 60 KB（未压缩）；单个文件不超过 800 行；首屏只加载当前页面的模块。

#### UI-011 统一资源版本，消除模块重复加载　`P1 · S`
- **问题：** 各文件手写 `?v=` 版本号且互不一致。`dashboard.js` 导入未带版本的 `./dom.js` 和 `./resources.js`，而其他模块导入带 `?v=...` 的版本。浏览器按完整 URL 区分模块，所以 **`resources.js`（46 KB）和 `dom.js` 各被加载、执行两份**。
- **证据：** `dashboard.js:2-3` 与 `app.js:2,4`、`domains.js:1`、`table.js:2-3` 对比。
- **方案：** 短期去掉模块内部 import 的 `?v=`，只在 `index.html` 入口带版本。长期引入 esbuild 或 Vite 构建，输出带内容 hash 的文件名，`index.html` 由构建过程生成（与 UI-012 配合）。
- **验收：** Network 面板中每个模块只请求一次；发布新版本后无需手动修改版本号。

#### UI-012 静态资源压缩与缓存　`P1 · S`
- **问题：** Caddy 的 `@admin` 段没有 `encode`，也没有 `Cache-Control`。按文本类资源常见压缩率估算，传输体积还可以下降 70%–80%（**需实测**）。
- **方案：** 在站点块中加入 `encode zstd gzip`；对带 hash 的资源设置 `Cache-Control: public, max-age=31536000, immutable`；`index.html` 设置 `no-cache`。`Caddyfile.example` 和 `docker/release/caddy` 同步修改。
- **验收：** 对 `table.js` 请求的响应头包含 `content-encoding: zstd` 或 `gzip`；二次访问时静态资源命中缓存。

#### UI-014 菜单和权限只保留一个数据来源　`P1 · M`
- **问题：** 页面可见性由四处代码共同决定：`resources.js` 中硬编码的 `roleAccess` 和 `resourceCapability`、`domains.js` 中的 `specialCapabilities`，以及 `app.js` 中的 `ADMIN_SPECIAL_DOMAIN_TABS`。`domains.js` 还在模块加载时对导出常量执行 `shift()` 和 `push()`。新增页面时很容易漏改一处，导致菜单和后端权限不一致。
- **方案：** 每个页面在注册表中声明自己需要的 capability，菜单完全由 `user.permissions` 推导；删除 `roleAccess` 这条兼容路径（`/me` 已经返回 permissions）。后端增加测试，确保每个 `admin-api` 路由的权限与前端声明一致。
- **验收：** 新增一个页面只需修改一处；为不同管理员类型（`admin_type`）自动生成菜单快照测试。

#### UI-016 页面生命周期：取消请求、清理副作用　`P1 · M`
- **问题：** 切换页面时，上一个页面的请求仍在进行，返回后还会写入全局 `#request-id`；定时器和全局监听没有统一的销毁机制。
- **方案：** 路由器为每个页面创建 `AbortController` 和 `cleanup` 列表；`api()` 支持传入 `signal`；页面卸载时统一中止请求并执行清理。
- **验收：** 在慢网络下快速切换 5 个页面，最终页面不被旧请求的结果覆盖，控制台没有报错。

#### UI-015 清理死代码和编码问题　`P1 · S`
- `public/admin/assets/app.js` 和 `public/admin/assets/app.css` 是旧版文件：没有被引用，脚本还查询不存在的 `#status`。
- `resources.js` 中的 `navSectionsForRole`、`navigationSections`、`resourcesForRole`，以及 `domains.js` 中的 `domainsForRole`，替换为新菜单机制后可以删除。
- `table.js` 中的 `showProviderModelImportLegacy` 只有定义，没有调用。
- `table.js` 中有 46 处 `\uXXXX` 形式的中文转义，与其他地方的直接中文混用，应统一为 UTF-8 明文。`resources.js` 文件头带有 BOM，应去掉。

### C. 交互体验（P1–P2）

#### UI-020 统一加载、空数据和错误状态　`P1 · S`
当前多数页面先 `clear()` 再等待接口返回，期间页面空白，`table.js` 中只有 8 处加载提示。需要提供 `LoadingState`（表格骨架）、`EmptyState`（含“新增”入口）和 `ErrorState`（含重试和请求编号）三个组件，并用于所有页面。

#### UI-021 弹窗与确认框重做　`P1 · M`
- `showModal` 目前没有 `role="dialog"` 和 `aria-modal`，不支持 Esc 关闭，没有焦点锁定，关闭后焦点也不会回到触发按钮；点击遮罩会直接关闭并丢失未保存内容（`dom.js:158-171`）。
- 共有 13 处 `window.confirm`、`alert` 和 `prompt`（如 `table.js:767`、`:1392`、`:2995`），无法统一样式，也无法展示影响范围。
- **方案：** 基于 `<dialog>` 实现 `Modal` 组件，支持焦点锁定、Esc、关闭前检查未保存修改；`confirmDanger({ title, impact, typeToConfirm })` 用于删除厂商、撤销 API Key、删除知识库等高风险操作，要求输入名称确认；保存成功后自动关闭弹窗并显示 toast。

#### UI-022 表单与登录　`P1 · M`
- 登录区不是 `<form>`，只监听按钮点击，按回车无法登录，部分密码管理器也无法识别（`index.html` 的 `#login`、`app.js:643`）。
- 表单没有前端校验：`required` 和数字范围没有提示；`json` 字段在 `readFields` 中直接 `JSON.parse`，解析失败时只显示“新增失败，请检查输入内容”，无法定位到具体字段。
- 编辑 API Key 时先 `PUT /api-keys/{id}`，再 `PUT /api-keys/{id}/models`（`table.js:2584`、`:2592`），两步不是原子操作，第二步失败会留下半更新状态。建议后端合并为一个事务接口。
- **验收：** 回车可以登录；字段错误显示在字段下方；JSON 字段失焦时校验并给出错误位置。

#### UI-023 筛选器：用选择器替代 ID 输入，并保留在 URL 中　`P1 · M`
- 通用筛选要求手动输入“部门 ID / 用户 ID / 厂商 ID / 模型 ID”，“开始时间 / 结束时间”是普通文本框（`resources.js` 中的 `filterFields`）。
- **方案：** 使用 UI-003 的 `RemoteSelect` 和部门树选择器；时间改为日期时间控件，并提供“今天 / 近 7 天 / 近 30 天”快捷选项；增加“重置”按钮；筛选条件同步到 hash 的 query，可以刷新、分享和回退。

#### UI-024 表格能力增强　`P2 · M`
- 列排序：后端 `CrudService::sortClause` 已经支持 `id`、`created_at`、`request_count`、`total_tokens`、`cost_amount` 等字段（`CrudService.php:609`），前端只需加上表头排序交互。
- 列显示 / 隐藏和列宽记忆（按资源保存到 localStorage）。
- 长文本省略显示，悬停查看完整内容，并提供一键复制（Key、request_id、trace_id）。
- 时间统一按本地时区格式化，显示相对时间，悬停显示绝对时间。
- 批量选择通用化：目前模型表（`renderTable` 中的 `isModelTable`）和 API Key 表各有一套实现，应抽成通用的 `selection` 能力。

#### UI-025 全局反馈　`P2 · S`
增加 toast 队列；全局错误条可关闭，并在切换页面时自动清除；`#request-id` 当前是 `hidden`，改为在出错时显示可点击复制的请求编号，便于排障。

#### UI-026 会话过期体验　`P2 · S`
会话固定 120 分钟（`config/app.php:10`）。过期时 `onAuthRequired` 直接切换到登录页，正在填写的表单内容会丢失。改为弹出覆盖层重新登录，登录成功后继续当前页面；到期前 5 分钟提醒并提供“续期”。后端需要增加续期接口（**需确认**后端会话是否支持滑动过期）。

#### UI-027 全局搜索和快速跳转　`P2 · S`
目前有 10 个域、60 多个可见页面，只能逐级展开菜单查找。增加 Ctrl/⌘ + K 命令面板，按页面名称或拼音首字母跳转；“最近访问”从 1 条扩展到 5 条（已有 `recentPages` 数据）。

### D. 视觉、可访问性与性能（P2–P3）

#### UI-030 设计令牌化与 CSS 拆分　`P2 · L`
- `app.css` 有 4,051 行、76 种硬编码颜色，按钮主色 `#2d6cdf` 等写死在前半部分；主题变量从第 2060 行才开始定义，深色主题依靠后面的覆盖规则补救（第 2892 行注释：“layered after the legacy shell rules”）。
- **方案：** 建立 `tokens.css`（颜色、间距、圆角、字号、阴影，按浅色和深色两套定义），把文件拆为 `base`、`components` 和 `pages/*`；用 stylelint 禁止在 tokens 以外写颜色字面量；统一断点（当前混用了 640、820、900、1023、1120 和 1280）。
- **验收：** 颜色字面量只出现在 `tokens.css`；在 E2E 中对深色主题下每个页面截图对比。

#### UI-031 可访问性　`P2 · M`
- 域标签容器设置了 `role="tablist"`，但子按钮没有 `role="tab"` 和 `aria-selected`（`app.js:482-486`）。
- 表格缺少 `caption` 和 `scope`；仪表盘 SVG 图表只有 `<title>`，没有可供读屏的数据表替代内容。
- 接入 axe-core（见 UI-013），以 0 个严重（serious / critical）问题为目标。

#### UI-033 图表交互　`P2 · M`
趋势图是手写 SVG 折线（`dashboard.js:239` 起）。需要增加悬停提示、图例开关、空数据状态和“导出 CSV”。如果引入图表库，要注意 CSP 只允许 `'self'`，需要本地托管。

#### UI-040 减少重复数据请求　`P2 · S`
- 每次刷新列表时，`renderContext` 都会重新拉取 API Key、用户、模型等引用数据（`table.js:1589-1607`）。UI-003 完成后改为按 ID 批量查询，并加入页面级短时缓存。
- 登录后的 `adminRefreshBrandIdentity` 请求整个 `settings-center`，却只使用其中的品牌信息（`app.js:166-168`）。改为使用已有的公开接口 `/admin-api/system/brand-identity`。
- 每次导航都重建整个侧边栏 DOM（`adminRenderNavList`）。可以改为只切换 `active` 和 `open` 类名。

#### UI-034 文案集中管理　`P3 · M`
错误文案（`api.js` 中的 `adminErrorMessages`）、枚举值（`valueLabels`）和列名（`columnLabels`）已经部分集中，但大量文案仍然散落在 `table.js` 的代码中。可以统一抽到 `i18n/zh-CN.js`，为以后的多语言或私有化定制文案做准备。

---

## 4. 实施路线

| 阶段 | 周期（估） | 任务 | 目标 |
| --- | --- | --- | --- |
| 0 快速修复 | 1 周 | UI-001、002、003、004、005、006、011、012、015 | 消除数据显示错误和看不全的问题，静态资源传输体积明显下降 |
| 1 测试与架构 | 2–3 周 | UI-013 → UI-010、014、016 | 有行为测试保护，完成模块化和按需加载 |
| 2 交互体验 | 2 周 | UI-020、021、022、023、025、026 | 统一组件，高风险操作可确认，表单可校验 |
| 3 打磨 | 持续 | UI-024、027、030、031、033、034、040 | 设计令牌、可访问性、图表和效率工具 |

依赖关系：UI-010 依赖 UI-013；UI-023 依赖 UI-003；UI-005 与 UI-020 合并实施；UI-001 的 URL 筛选与 UI-023 共用同一套 hash query 序列化。

## 5. 需要运行环境确认的事项

1. 仪表盘时间参数的时区语义：后端按 UTC 还是服务器本地时区解析 `from` 和 `to`（UI-004）。
2. 开启压缩后的实际传输体积，以及首屏加载时间的基线（UI-012，建议用 Lighthouse 记录优化前后对比）。
3. 后端会话是否支持滑动续期（UI-026）。
4. 生产环境中用户、API Key 和模型的规模，用于判断 UI-003 的优先级和是否需要游标分页（UI-002）。
