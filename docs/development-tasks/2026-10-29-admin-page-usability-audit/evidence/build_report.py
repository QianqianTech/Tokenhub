"""Build the dated audit artifacts from reviewed observations; no network or application writes."""
from pathlib import Path
import collections
import csv
import hashlib
import json
import re
import shutil

ROOT = Path(__file__).resolve().parents[4]
OUT = Path(__file__).resolve().parents[1]
EVIDENCE = OUT / 'evidence'
INV = json.loads((EVIDENCE / 'source-inventory.json').read_text())
TASKS = []

def ref(file, needle):
    text = (ROOT / file).read_text(encoding='utf-8-sig')
    offset = text.index(needle)
    return {'path': file, 'line': text[:offset].count('\n') + 1, 'symbol': needle}

def js(name, needle):
    return ref('public/admin/assets/js/' + name + '.js', needle)

def task(number, priority, title, kind, pages, problem, solution, acceptance, sources, deps=(), size='M', old=()):
    TASKS.append(dict(id=f'UX-{number:03d}', priority=priority, title=title, evidence_type=kind,
        status='proposed', affected_pages=pages.split(), problem=problem, solution=solution,
        acceptance=acceptance, evidence=sources, dependencies=[f'UX-{x:03d}' for x in deps],
        relative_effort=size, previous_task_ids=list(old), live_verified=False))

task(1,'P0','统一导航入口，保留筛选和业务上下文','source_reproduced','*',
    'adminNavigate 先渲染，hashchange 再调用 adminNavigateDomain；第二次清空 filters。排行下钻、RAG 加入样本、上传后的 upload_id 等上下文可能消失。PROBE-02 复现两次渲染及空筛选。',
    '把路由、筛选、内部 tab、页码和对象标识序列化到 URL；只保留一个渲染入口，加入请求取消或序号校验。',
    ['一次跳转只渲染一次并发出一组必要请求。','从员工排行下钻后保留 user_id；刷新、后退、重新打开 URL 后结果一致。','上传成功后回到同一 upload_id；加入评估样本保留问题及期望片段。'],
    [js('app','async function adminNavigate('),js('app','async function adminNavigateDomain('),js('app','window.addEventListener("hashchange"')],old=['UI-001','UI-023'])
task(2,'P0','修复内部页面路由被退回主分类的问题','source_reproduced','rag-* settings-* settings-center',
    'parseAdminHash 仅接受主域 tabs；#/rag/rag-knowledge-bases 被解析为 rag-build。内部页面可能短暂出现后消失，直达链接和刷新无法恢复。PROBE-01 已复现。',
    '建立包含主菜单与内部页面的路由表，明确 parent、权限和 canonical URL；兼容旧链接，非法路径显示解释及返回入口。',
    ['知识库、数据集、向量配置和上传详情均能用 URL 直达、刷新和后退。','内部页面顶栏、菜单选中态和面包屑归属一致。'],
    [js('domains','export function parseAdminHash'),js('app','function adminDomainForTab(')],deps=[1],old=['UI-001'])
task(3,'P0','让通用列表查看全部数据','source_confirmed','generic-lists content-archive system-settings permission-config',
    '通用列表固定 limit=50，没有分页器；高级设置、管理员权限列表也只取前 100 条。不能靠“暂无数据”证明数据已查全。API 密钥已单独分页，不应重做。',
    '复用密钥分页组件；接口返回 total/has_more 与分页位置；筛选、排序、翻页共用状态。大日志可用游标。',
    ['准备 135 条记录，能查看第 1、2、3 页且不漏不重复。','筛选后的总数、当前页、每页条数准确，空的末页自动回退。','日志、用量、归档、管理员与高级设置都有查全路径。'],
    [js('table','async function renderGenericResourcePage('),js('table','async function renderRows('),ref('app/Services/CrudService.php','public function list('),ref('app/Http/Controllers/AdminController.php','public function index(')],old=['UI-002'])
task(4,'P0','引用选择器不能静默截断或把失败当空数据','source_confirmed','*',
    'lookupRows 请求 500 条，而通用列表、用户和 Key 服务上限为 100；失败又返回 []。大组织会选不到对象，编辑模型授权还存在丢失不可见选择的风险。',
    '提供按关键字远程搜索、按已有 ID 补全、加载更多；明确加载失败和无权限；编辑时合并完整原选择，不因选项未载入而覆盖。',
    ['有 1000 个用户、模型、Key 时可搜索到最后一个对象。','编辑带有未加载模型授权的 Key，仅改名称不丢授权。','断网或 403 时显示重试，不显示“没有知识库”等业务空态。'],
    [js('table','async function lookupRows('),js('table','async function showEditModal('),ref('app/Services/CrudService.php','$limit = min(100'),ref('app/Services/AdminUserService.php','$limit = min(100')],old=['UI-003'])
task(5,'P1','加载失败必须显示错误，不渲染成空白数据行','source_reproduced','generic-lists api-keys content-archive',
    'renderRows 失败时把 message/request_id 放进 data 数组，再按 id/username 等配置列画表，错误会变成空白行。PROBE-07 已复现；密钥页也采用同一错误数组回退。',
    '统一 Loading、Empty、Error 状态；错误含清楚原因、复制请求编号、重试；刷新失败时保留旧数据并标记过期。',
    ['注入 500、离线和 403 时都不出现伪数据行。','错误可重试，恢复后沿用原筛选；真正零记录时显示独立空态。'],
    [js('table','async function renderRows('),js('table','async function renderApiKeyPage('),js('table','export async function renderContentArchivePage(')],old=['UI-005','UI-020','UI-025'])
task(6,'P0','按资源声明真实动作，消除无效“停用”','source_confirmed','model-prices quota-bindings log-retention-policies media-assets multimodal-tasks rag-*',
    '通用表按 readOnly/status 自动添加 DELETE“停用”。价格、配额绑定、日志留存等没有 status；CrudService::disable 仍可能返回 disabled=true 而不修改。部分评估运行表是后端只读，前端却会给出停用。RAG 授权行的 DELETE 实际是撤销授权。',
    '为每种资源声明 allowedActions、动作含义和权限；无操作的只读记录不渲染操作列；撤销、终止、停用、删除分别实现。后端不支持时返回明确错误。',
    ['价格和留存页面没有假停用；配额解绑确实解除绑定并可回读。','评估运行/结果页只出现可执行的查看动作。','撤销授权明确显示对象和影响；不以通用 DELETE 冒充取消生成任务。'],
    [js('table','function renderTable('),ref('app/Services/CrudService.php','public function disable('),ref('app/Services/CrudService.php','public function isReadOnlyTable('),ref('database/migrations/0001_create_tokenhub_schema.sql','CREATE TABLE IF NOT EXISTS quota_bindings')])
task(7,'P0','区分未修改、清空权限、使用预设三种意图','source_reproduced','admin-users permission-config',
    'optional 多选为空时 readFields 省略字段。权限编辑同时提交 admin_type，后端在没有 permissions 时切回 preset；“清空”可能变成恢复预设权限。授权部门清空也无法明确提交 []。PROBE-04 验证了字段被省略。',
    '显式提供权限模式（预设/自定义）及空数组语义；脏字段与未提交分开；显示保存前后有效权限和范围。',
    ['自定义权限全清空后回读为空，而不是恢复全功能预设。','切回预设需明确选择；清空管理部门能提交 [] 并得到有效范围提示。','保存后通过新会话验证真实可见菜单与服务端权限。'],
    [js('dom','export function readFields('),js('table','async function showPermissionConfigEditor('),ref('app/Services/AdminUserService.php','private function administratorAccess(')],old=['UI-022'])
task(8,'P0','API 密钥编辑使用原子保存','source_confirmed','api-keys',
    '编辑先 PUT Key 基础配置，再 PUT /models。第二步失败时界面报保存失败，但第一步已生效，名称、有效期、管理权限与模型授权可能处于不同版本。',
    '用事务接口保存基础配置与模型授权，返回完整新版本；无法合并时明确分步结果、恢复办法和重试范围。',
    ['模拟模型授权保存失败，基础配置与授权一起回滚。','并发编辑返回冲突且保留输入；成功后回读与界面一致。'],
    [js('table','async function showEditModal('),ref('app/Http/Controllers/AdminController.php','public function updateApiKey('),ref('app/Http/Controllers/AdminController.php','public function updateApiKeyModels(')],deps=[4],old=['UI-022'])
task(9,'P1','仪表盘防过期请求覆盖，并允许单个面板失败','source_confirmed','dashboard',
    '搜索防抖和刷新可重叠，load 先 clear 再等待 Promise.all，旧请求返回后仍 append；7 个统计接口任一个失败会取消全部结果。',
    '使用请求序号/取消信号；按面板独立显示加载、错误和重试；显示数据更新时间。',
    ['慢网连续改 3 次员工关键字，只显示最后一次结果且面板不重复。','单独让 security 接口失败，其他 6 个面板仍可用。'],
    [js('dashboard','export async function renderDashboard(')],old=['UI-004','UI-016'])
task(10,'P1','明确仪表盘时间口径及筛选生效方式','source_confirmed','dashboard usage-records logs',
    '快捷日期按 UTC 零点计算，未展示统计时区；选“近30天”仍有“今日请求”标题。period/bucket/sort 改动也不会像部门那样立即查询，用户难判断条件是否已应用。后端时区需在部署环境复验。',
    '统一业务统计时区和区间边界，展示当前区间；全部筛选采用一致的“自动应用”或“应用”方式。',
    ['选择今日/7天/30天后卡片名称和范围一致，显示时区。','在指定业务时区午夜前后造数，明细与图表合计一致。','未应用的修改有提示，结束时间不得早于开始时间。'],
    [js('dashboard','function startOfUtcDay('),js('dashboard','function metricGrid('),js('dashboard','export async function renderDashboard(')],old=['UI-004'])
task(11,'P1','金额保留精度，区分零成本与未配置','source_reproduced','dashboard api-keys model-prices usage-records',
    '仪表盘成本先 Math.round，再把 0 显示“未配置”；0.21 的有效成本被显示未配置（PROBE-11）。缺币种/单位时金额也难比较。',
    '金额按币种与精度显示；null、未配置、实际0分别表示；小额可用 <0.0001 等明确格式。',
    ['0、0.0001、0.21、1.49 和未配置各显示正确，不丢小数。','大屏汇总、Key 卡片和明细的币种、精度与统计口径一致。'],
    [js('dashboard','function formatIntegerCost('),js('dashboard','function metricGrid('),js('table','function formatApiKeyCost(')])
task(12,'P1','弹窗支持键盘、未保存保护和一次性结果保护','source_confirmed','all-dialogs',
    'showModal 没有 dialog 语义、Esc、焦点锁定/回退，点击遮罩直接清空。编辑内容和新建 Key 一次性明文都可能随误点丢失。',
    '统一模态组件，支持标题关联、焦点管理、未保存确认；一次性明文单独结果页，明确保存提醒。',
    ['全键盘可打开、填写、关闭并回到触发控件，焦点不进入背景。','填写后点遮罩不会静默丢失；一次性密钥关闭前有明确提示。'],
    [js('dom','export function showModal('),js('table','async function showCreateModal(')],old=['UI-021'])
task(13,'P1','表单按字段校验并显示修正方法','source_confirmed','all-forms',
    'field 未传递 required、min/max、step、placeholder、help 等约束；JSON.parse 的错误只被外层泛化成失败。日期、百分比、金额容易填错。',
    '字段 schema 驱动校验、单位、示例与错误；首次失败聚焦字段；JSON高级编辑有格式校验和定位。',
    ['错误位于相应字段下并保留输入；空必填、负数、无效日期/JSON 在提交前指出。','0、空值、不限、继承具有不同且明确的语义。'],
    [js('dom','export function field('),js('dom','export function readFields(')],old=['UI-022'])
task(14,'P1','写操作统一防重复与成功失败反馈','source_confirmed','all-write-actions',
    '通用新增/编辑、导出、RAG 上传等多数按钮执行期间未禁用；若干 async 点击无 catch；成功后保留可再次点击的保存按钮，容易重复创建。目录同步等已有防重复应保留。',
    '统一提交状态组件，显示正在处理、成功和失败；生成任务使用幂等键；成功明确关闭或进入结果。',
    ['快速双击只产生一个创建/导出/上传任务。','500/超时后有明确反馈和可重试状态，无未处理 Promise。','成功回读后原输入不会无意重复提交。'],
    [js('table','async function showCreateModal('),js('table','async function renderGenericResourcePage('),js('table','async function submitRagUploadWorkbench(')],old=['UI-006'])
task(15,'P1','只显示当前角色能够执行的动作','source_confirmed','generic-lists api-keys departments settings-* rag-*',
    '资源可见性按 read 权限过滤，但通用新增/编辑/停用及 Key 批量动作主要依据 config.form/status，没有对应 manage/export 权限判断，受限管理员会不断点到403。',
    '页面和动作共用权限声明；不可执行时隐藏或解释原因。服务端鉴权继续保留。',
    ['用仅查看、部门管理员、RAG管理员、API管理员各走一遍可见页面。','只读账号无写操作，导出/原文/轮换遵守独立权限，服务端拒绝仍有清楚反馈。'],
    [js('resources','export function resourcesForUser('),js('table','async function renderGenericResourcePage('),js('table','function renderApiKeyTable(')],old=['UI-014'])
task(16,'P1','登录支持回车并准确区分登录错误','source_confirmed','login',
    '登录区是 section，只有按钮监听，没有表单提交；错误账号密码返回 ADMIN_UNAUTHENTICATED，而该码统一被翻译为“登录状态已失效”。',
    '使用可提交表单；登录和会话过期分别提示；加入提交中状态、密码可见切换及正确的自动填充语义。',
    ['用户名/密码框按回车能登录，按钮双击只发一次。','错密码提示账号或密码错误，网络失败与会话过期有不同文案。'],
    [ref('public/admin/index.html','<section id="login"'),js('app','$("login-button").addEventListener'),js('api','ADMIN_UNAUTHENTICATED:')],old=['UI-022'])
task(17,'P1','会话过期后可恢复原任务','source_confirmed','*',
    '401 直接切到登录；重新登录恢复 hash 时清空 filters 并重画页面，未提交表单、聊天和内部子页状态无法恢复。streamApi 的错误也未共用 api 的会话处理。',
    '统一 API/流式鉴权错误处理，显示重登录覆盖层；恢复非敏感草稿、路由及筛选；不要持久存储密钥等秘密。',
    ['表单填写中模拟过期，重登录后仍回到原对象及输入。','流式请求401也能正确引导重登录；敏感字段要求重新输入。'],
    [js('app','onAuthRequired: () => adminShowLogin()'),js('app','function adminRestoreHashRoute('),js('api','export async function streamApi(')],old=['UI-026'])
task(18,'P1','每个页面使用适用的筛选器和状态字典','source_confirmed','generic-lists content-archive',
    '多种资源共用部门/用户/厂商/模型/能力筛选，后端只接收各表允许字段，部分条件可能被忽略；时间是普通文本框，状态集合也混入不适用选项。无统一重置。',
    '逐页声明实际支持的过滤字段；实体用搜索选择器，日期用时间控件；状态按资源配置；显示已应用条件和重置。',
    ['每个可见条件都能改变相应请求和结果，不支持的条件不显示。','用户离职、告警已确认、导出过期、RAG阶段等状态可准确筛选。','重置恢复默认并清除URL条件。'],
    [js('resources','export function filterFields('),js('table','function renderFilters('),ref('app/Services/CrudService.php','private function listWhere(')],deps=[1,4],old=['UI-023'])
task(19,'P2','列表展示名称与业务信息，提供详情和复制','usability_improvement','generic-lists content-archive',
    '大量表直接显示 user_id、department_id、长 request_id、JSON 和原始时间；通用表没有排序交互或列管理，阅读与查找成本高。宽表已具备局部横向滚动，不预设存在整页溢出。',
    '名称主展示、ID辅助；固定关键/动作列，按需详情；支持复制、服务端排序、列选择，统一时间及时区。',
    ['用量和日志无需记住数字ID就能识别用户、Key与模型。','长ID/JSON可以查看完整和复制；排序覆盖整个结果集。'],
    [js('table','function renderTableCell('),js('table','function renderTable(')],deps=[3,4],old=['UI-024'])
task(20,'P2','减少重复导航层级并提供快速定位','usability_improvement','navigation',
    '左侧域/页面与内容上方 domain-tabs 重复；内部 RAG 又有分类标签。多层导航缺少明确面包屑，页面标题通常只显示域名称。',
    '保留清楚的域-页面-对象层级，增加页名、面包屑、最近访问与快捷搜索；保留已有侧栏收起和移动遮罩。',
    ['不熟悉系统的用户能在两次有意义的选择内找到常用任务。','当前页、当前对象及返回入口明确，浏览器前进后退与选中态一致。'],
    [js('app','async function renderDomainPage('),js('app','async function adminRender(')],deps=[1,2],old=['UI-027'])
task(21,'P1','部门维护用树选择父级并说明停用影响','source_confirmed','departments',
    '新增和编辑父部门仍要求数字ID；树一次展开全部，无搜索/折叠。停用直接发送DELETE，而后端会使相关Key失效，当前按钮不解释影响。',
    '父级树选择排除自身子孙；树可查找/折叠；新增子部门带上下文；停用前展示受影响成员、Key和恢复方法。',
    ['不用记ID可创建、移动和查找三级部门。','无法选自身/子部门为父级；停用前能看到真实影响。'],
    [js('table','async function renderDepartmentTree('),js('table','function renderDepartmentBranch('),js('table','function showDepartmentEdit('),ref('app/Services/CrudService.php',"if ($table === 'departments')")])
task(22,'P1','用户管理支持按账号查找及清楚的账号生命周期','source_confirmed','users',
    '用户页筛选只有通用状态、部门ID、用户ID，缺少账号关键字；行内重置、移动、更新状态、编辑、停用并列，存在作用重复。',
    '增加账号/姓名搜索、部门树和离职筛选；主要动作精简到查看/编辑，账号安全动作集中；移动和离职前解释现有Key及用量归属。',
    ['输入账号片段即可定位用户。','移动部门、停用、离职分别显示具体后果且回读正确；历史用量不会被误解为删除。'],
    [js('resources','"users": {'),js('resources','export function filterFields('),js('table','async function showUserDepartmentMove(')],deps=[18,21])
task(23,'P2','管理员授权以有效权限和数据范围为核心','usability_improvement','admin-users permission-config',
    '管理员管理和权限配置分散，预设、单独授权、角色和范围多个概念叠加；有效权限列表直接展示代码，难判断保存后的实际能力。',
    '按业务域分组权限，展示继承/覆盖/实际生效；范围用部门树；保存前显示差异及会话失效影响；保护当前账号和最后一个超管。',
    ['用户能解释“自定义权限覆盖预设”的结果。','保存预览和新会话实际可用页面一致，范围变更说明包含子部门。'],
    [js('table','async function renderPermissionConfigPage('),js('table','async function showPermissionConfigEditor(')],deps=[7,15])
task(24,'P1','厂商配置提供清楚的连接与导入流程','usability_improvement','providers',
    '已有厂商预设、拉取模型和目录同步，基础表单仍让用户处理协议/URL/JSON请求头；连接失败需经拉取模型才暴露。',
    '先选择厂商或自定义，再验证协议、地址和凭据状态；提供脱敏连接诊断；导入与目录同步解释新增、变化、下线及保存影响。',
    ['新管理员能完成选择厂商→验证→导入模型。','失败定位到地址/鉴权/超时，不回显密钥；已有密钥留空语义明确。'],
    [js('table','function renderProviderPresetPicker('),js('table','async function showProviderModelImport('),js('table','async function showProviderCatalogSync(')])
task(25,'P1','模型管理提供搜索、路由与能力的业务解释','usability_improvement','models',
    '模型表有支持状态和批量操作，但筛选无名称/厂商关键字；同名逻辑模型与上游通道关系难理解。能力契约编辑较长，生效值需要一眼可见。',
    '按逻辑模型分组或支持厂商/模型搜索；展示通道状态、优先级与可调用原因；能力字段按常用/高级分层。',
    ['可按逻辑名/上游名/厂商找到模型，知道为何不可调用。','批量操作说明当前页/已选范围；能力覆盖修改前可查看生效差异。'],
    [js('resources','"models": {'),js('table','function createModelCapabilityEditor('),js('table','async function runModelBatchAction(')],deps=[3,18])
task(26,'P1','价格按计价单位配置并预览生效结果','source_confirmed','model-prices',
    '新建/编辑直接铺开多种单价与 pricing_rules JSON；输入/输出Token等字段标签缺少明确的每Token/每百万Token换算，生效时间是文本。列表虽有价格摘要，填写仍易误判单位。',
    '按模型能力显示价格项，单位、币种、精度与生效时区紧邻输入；提供计算示例、版本时间线及结构化高级规则。',
    ['输入单价后可用固定样例计算并与后端计量一致。','区分免费0、未配置和继承；生效时间和历史版本可核对。'],
    [js('resources','"model-prices": {'),js('table','function showModelPriceDetails('),js('table','const MODEL_PRICE_CATEGORIES')],deps=[6,11,13])
task(27,'P1','API 密钥统计不能混用全量与当前页','source_confirmed','api-keys',
    'renderApiKeyAnalytics 用全量 total 显示“API密钥总数”，启用数、请求数、Token、成本却只合计当前页20条；翻页会改变这些未标注口径的卡片。',
    '使用与当前筛选一致的服务端聚合，或全部明确标记“当前页”；展示统计周期。',
    ['有45个Key时三页的全量统计一致；筛选部门后按筛选集统计。','启用+停用等状态合计与总数解释一致，请求/Token明确周期。'],
    [js('table','function renderApiKeyAnalytics('),js('table','async function renderApiKeyPage(')])
task(28,'P1','API 密钥创建、轮换和保存形成完整结果流程','source_confirmed','api-keys',
    '创建结果用 notice 显示一次性明文，缺少该流程专用的复制/确认保存控件，保存按钮仍能再次创建；轮换页已有一次性结果和宽限期说明，应统一体验。',
    '创建后进入专用一次性结果；提供复制、用途示例、已保存确认；轮换显示新旧Key生效/失效时间和验证入口。',
    ['创建一次只得到一个Key，复制成功有反馈，关闭不会误丢唯一明文。','过期/轮换时间使用日期控件和明确时区；用户可验证新Key生效。'],
    [js('table','async function showCreateModal('),js('table','function oneTimeSecretText('),js('table','function showApiKeyRotationResult(')],deps=[12,14])
task(29,'P1','配额配置同时解释实际生效额度和绑定关系','usability_improvement','api-key-model-quotas quota-policies quota-bindings',
    '配额策略、绑定和模型限额分为三页；策略的并发限制不在主表，0/空的不限语义和多层策略优先关系难一次看懂。Key额度弹窗已有实际用量，可作为统一视图基础。',
    '对象选择后显示当前绑定、实际限额、已用/预占/剩余和周期重置；提供新增策略→绑定→验证的引导；解绑明确后果。',
    ['能从用户/Key找到部门、用户、Key、模型各层有效限制。','0、空、继承显示准确；变更/解绑后可回读真实额度。'],
    [js('resources','"quota-policies": {'),js('table','async function showApiKeyQuota('),js('table','function quotaTargetOptions(')],deps=[3,4,6])
task(30,'P1','用量、内容留存、归档能够沿请求相互定位','usability_improvement','usage-records usage-contents content-archive',
    '用量已有内容/诊断入口，留存和归档仍以ID列表为主；用户难区分未留存、已清理、摘要、脱敏、原文及无权限。',
    '请求详情串联耗时、计量、内容、归档；展示留存模式/剩余时间；原文单独按权限展示并说明访问会被审计。',
    ['从一个请求可定位对应留存/归档，不手工抄ID。','正文不可用时说明具体原因；原文选择不会误带入默认导出。'],
    [js('table','async function showUsageContent('),js('table','export async function renderContentArchivePage('),js('table','function renderRequestDiagnostics(')],deps=[1,3,18])
task(31,'P1','多模态任务和媒体资产提供结果预览与真实动作','usability_improvement','multimodal-tasks media-assets',
    '多模态任务基本是状态表，没有进度/失败原因/结果的专用详情；媒体列表主要提供下载，缺少类型友好预览和大小格式。通用停用不能代表取消任务。',
    '任务详情展示排队、执行、失败、完成及产物；媒体支持可用格式的缩略图/播放和关联请求；仅在后端支持时提供取消/重试。',
    ['成功任务可直接找到产物；失败可看原因和可执行下一步。','过期或无预览能力时有明确提示，不显示假播放/假取消。'],
    [js('resources','"multimodal-tasks":'),js('resources','"media-assets":'),js('table','function renderTable(')],deps=[3,6,18])
task(32,'P1','导出任务从创建到下载可追踪','source_confirmed','export-tasks usage-records logs content-archive',
    '导出后只显示任务创建结果，没有直达任务的动作；任务列表无自动刷新/轮询，显示内部file_path；下载 await 后才 window.open，存在弹窗拦截风险待实测。',
    '创建后给任务链接；展示范围、预计/完成条数、阶段、失败原因和过期时间；采用稳定下载方式并提示链接过期。',
    ['创建后直接进入对应任务，运行到成功无需整页重开。','失败能重试或说明原因；下载拦截、过期、无权限均有明确反馈。'],
    [js('table','async function renderGenericResourcePage('),js('table','async function openAdminDownload('),js('resources','"export-tasks":')],deps=[1,3,14])
task(33,'P1','大屏分享用可理解的范围和指标选择','source_confirmed','dashboard-share-links dashboard-share-access-logs',
    '分享创建仍要求 scope_ids、allowed_metrics JSON；行内“大屏1/大屏2”缺少用途名称，重置密码/撤销会话直接执行，访问日志难关联分享对象。',
    '用对象和指标复选替代JSON，提供权限预览、有效期和IP说明；两个入口按用途命名；密码重置与会话撤销显示影响并确认。',
    ['不写JSON可创建限定部门/指标的大屏分享。','使用分享身份验证实际范围；日志可返回对应分享，安全动作有结果和影响说明。'],
    [js('resources','"dashboard-share-links":'),js('table','function renderTable('),js('table','function showAllowClientIp(')],deps=[4,14,18])
task(34,'P1','告警处置后立即回读并保留筛选','source_confirmed','alert-events',
    'updateAlertStatus 仅发POST，没有更新卡片、刷新列表或成功提示；用户看不到确认/关闭是否生效。prompt 处置说明不可结构化，列表限100且无状态筛选。',
    '告警列表按状态/严重度/时间筛选；处置弹窗明确对象、备注和状态；成功回读当前条，提供关联请求和历史。',
    ['确认后立刻显示已确认，关闭后显示已解决，刷新后保持。','处置失败保留说明；重复点击不重复处理；已解决告警不出现无意义确认。'],
    [js('table','async function renderAlertEventsPage('),js('table','async function updateAlertStatus(')],deps=[3,14,18])
task(35,'P2','日志与审计提供按请求排障的完整视图','usability_improvement','logs audit-logs desensitization-events dashboard-share-access-logs log-archive-files',
    '日志已有request诊断，但通用筛选和原始字段仍要求用户知道ID及类型；审计动作、脱敏策略、分享访问之间缺少可点击关联。',
    '常用日志类型/级别、时间快捷项、请求链路视图、可复制编号与保存查询；审计展示操作人、对象和变更差异。',
    ['复制一个request_id即可看到相关用量、网关错误及安全事件。','审计能解释谁改了什么；导出沿用可见筛选和权限。'],
    [js('resources','export function filterFields('),js('table','function renderRequestDiagnosticsLookup('),js('table','function renderRequestDiagnostics(')],deps=[3,18,19])
task(36,'P1','脱敏策略配置可验证且不用猜范围ID','source_confirmed','desensitization-policies desensitization-events',
    '脱敏策略要求 scope_id 和 parsers JSON；block/replace/summary 对请求的影响缺少测试预览，事件又主要展示policy_id。',
    '选择范围对象和解析器模板，提供示例输入/结果预览、命中与优先级解释；保留高级JSON入口。',
    ['用合成样例能预览阻断/替换/摘要，不需要真实敏感数据。','事件可返回触发策略和请求；策略变更前说明影响范围。'],
    [js('resources','"desensitization-policies":'),js('resources','"desensitization-events":')],deps=[4,13,15])
task(37,'P1','留存策略提供真正可编辑的业务界面','source_confirmed','content-retention-policy log-retention-policies log-archive-files settings-retention-export rag-retention-policies',
    '日志留存资源没有form，页面不能编辑天数/压缩/加密，却有通用停用；RAG过期策略也没有新增/编辑form。内容策略又要求角色逗号串和多段JSON，治理设置分散。',
    '按内容、日志、RAG、导出区分策略，给作用域选择器、天数约束、预计影响、归档/清理说明及编辑入口；后台已有能力复用。',
    ['能在对应页面修改并回读留存天数，检查最小<=在线<=归档等适用规则。','策略保存与立即执行清理明确区分，能看到何时生效和受影响数据。'],
    [js('resources','"log-retention-policies":'),js('resources','"rag-retention-policies":'),js('table','export async function renderContentRetentionPolicyPage(')],deps=[6,13])
task(38,'P1','运行状态区分异常、未知与未启用','source_confirmed','operations-status',
    '固定展示6种服务，services[service]?.healthy !== true 都判异常，缺字段/可选服务也会变红；页面只加载一次，无更新时间/刷新。',
    '按部署启用服务展示正常/异常/未知/未启用，补检测时间、手动刷新和可控自动刷新；异常链接到相关任务或日志。',
    ['未启用的可选服务不误报故障；真实失败展示原因和下一步。','队列变化能刷新，旧数据带时间；离开页面停止轮询。'],
    [js('table','async function renderOperationsStatusPage(')],deps=[5])
task(39,'P1','设置中心切换分类自动展示首个或上次分区','source_reproduced','settings-center rag-settings settings-*',
    'sectionActive 依赖 categoryActive，只有首分类首分区初始化为可见；切换安全防护/治理告警/RAG设置只显示分类容器，内部所有分区仍hidden。PROBE-03 已复现3个分类空白。',
    '每个分类独立维护活动分区；点击分类恢复上次分区或自动选第一项，URL记录当前分区。',
    ['首次进入四个分类均立即出现表单，选中态与内容一致。','刷新/后退恢复分区；切换时未保存表单受保护。'],
    [js('table','export async function renderSettingsCenterPage(')],deps=[1])
task(40,'P1','设置保存应确认生效并可识别冲突','source_confirmed','settings-* settings-center system-settings',
    'settingsFormPanel/certificateSettingsForm 保存成功调用 hideOutput，用户得不到“已保存”的反馈；多个设置入口可编辑同一配置，缺少统一生效/重启说明。',
    '保存成功回读显示更新时间、哪些项已生效/需重启；显示差异、还原草稿和并发版本冲突；品牌设置可预览。',
    ['每次保存均能区分成功/失败/部分生效，重新打开值一致。','并发修改不静默覆盖；离开有未保存提示。'],
    [js('table','function settingsFormPanel('),js('table','function certificateSettingsForm('),js('table','function createSystemSettingEditor(')],deps=[12,13,14])
task(41,'P1','补全RAG配置和高级功能的可发现入口','source_reproduced','rag-*',
    '主菜单仅4个RAG分类；向量配置、授权、模板下载、高级数据等存在于旧分类常量，却无法从当前主分类继续进入。rag-settings 和知识库授权不属于任何当前导航项；PROBE-05给出孤立集合。',
    '在4个业务入口下提供需要的设置/高级入口，明确知识库→数据集→上传→向量→检索→反馈关系；旧容器合并或兼容跳转。',
    ['从构建知识能找到向量配置、授权、模板和导入任务。','RAG管理员无需手改hash，能在其权限内完成配置闭环。','旧入口有明确迁移目的地，保留可用深链接。'],
    [js('domains','export const adminDomains'),js('table','const RAG_CATEGORY_TABS'),js('table','function renderRagOnboardingPage(')],deps=[2,15,20])
task(42,'P1','RAG上传按知识库联动数据集并预检文件','source_confirmed','rag-upload-workbench rag-import-task rag-build',
    '上传工作台把全部知识库/数据集独立列出，没有按知识库联动；文件一次读为base64，读取在try外，没有大小/类型/模板对应校验和上传进度。',
    '选择知识库后过滤可写数据集和部门；上传前检查类型、大小、模板及权限，显示限制和示例；处理读文件失败、上传中和取消。',
    ['切换知识库不会保留另一库的数据集。','超大/类型不符文件在读取前提示；读取失败有错误且页面可继续。','上传期间不能重复创建任务，成功直接进入该任务预览。'],
    [js('table','async function renderRagUploadWorkbenchPage('),js('table','async function submitRagUploadWorkbench(')],deps=[1,4,14])
task(43,'P1','RAG文本/图片/错误行预览支持真实翻页','source_reproduced','rag-upload-detail rag-image-preview rag-import-task',
    '预览请求携带page/page_size，页面也显示total及页码，但renderRagPreviewPage和图片预览都没有翻页按钮。超过20项无法审核；PROBE-08确认45条数据时没有任何翻页控件。',
    '加入上一页/下一页/跳页及错误项定位，当前类型高亮随切换更新；URL保存任务、类型、页码；不要在查询失败后残留旧任务预览。',
    ['45条文本、图片或错误行均能审到第45条。','换任务/类型重置合法页码，当前标签与数据一致。','加载失败明确标记旧数据或清空，不把上一任务当本任务。'],
    [js('table','function renderRagPreviewPage('),js('table','function renderRagImagePreviewItems('),js('table','function renderRagPreviewTabs(')],deps=[1,3])
task(44,'P0','修复RAG审核与免审核向量化按钮状态','source_reproduced','rag-upload-detail rag-import-task rag-build',
    '前端审核按钮只接受waiting_review/pending_review，后端review_status使用pending；免审核为not_required，但前端要求approved才能向量化。PROBE-09复现待审按钮全禁用及免审任务不能确认。后端部署版本尚未比对。',
    '前后端共用明确状态契约，优先返回can_review/can_vectorize及禁止原因；区分preview/review/vectorize状态，不直接猜枚举。审核意见可编辑。',
    ['pending+有审核权限时可通过/拒绝；无权限有说明。','not_required+preview ready可确认，pending/rejected/预览有错不可确认并说明原因。','审批成功、排队、处理中、完成均回读并正确更新按钮。'],
    [js('table','async function refreshRagUploadDetail('),ref('app/Services/RagUploadService.php','public function confirm('),ref('app/Services/RagUploadService.php','private function applyUploadReviewGate(')],deps=[14,15])
task(45,'P1','评估结果说明样本范围和指标，并可比较运行','usability_improvement','rag-quality rag-evaluation rag-retrieval-evaluation rag-eval-cases rag-eval-runs rag-eval-results',
    '评估同时展示原始数据和指标，Recall/MRR缺少解释及可配置阈值；“运行完成”不足以说明质量达标。样本创建后整页刷新，成功反馈和草稿易被重置。',
    '展示数据集、样本数/启用数、运行阶段、质量阈值和失败原因；支持样本搜索、编辑和运行比较；导入校验定位行。',
    ['零样本/无运行/全失败/部分失败/达标分别准确显示。','样本创建、批量导入和启停有结果摘要；比较两次评估能看到改进。'],
    [js('table','async function renderRagEvaluationPage('),js('table','async function renderRagEvaluationCasesPage(')],deps=[1,3,14])
task(46,'P1','反馈与纠偏提供可完成的处理流程','source_confirmed','rag-answer-feedback rag-correction-tasks rag-quality',
    '两个页面目前主要是通用列表，资源没有专用form或处理动作；虽能从失败样本转纠偏，进入纠偏后缺少明确分配、修复、验证、关闭入口。',
    '从反馈展开问题、证据和关联检索；纠偏展示负责人/原因/操作/验证结果，支持状态流转；失败样本转任务避免重复。',
    ['一条反馈可以转为任务、修正数据、重新评估并关闭。','每次状态变更有原因和审计记录；失败可恢复，不只改变显示标签。'],
    [js('resources','"rag-answer-feedback":'),js('resources','"rag-correction-tasks":'),js('table','function renderRagEvaluationFailureCards(')],deps=[14,15,45])
task(47,'P2','RAG详情把原始JSON改为可读证据','source_confirmed','rag-*',
    '图片预览直接显示human_annotation/ai_annotation JSON，任务状态和指标仍暴露多种英文枚举；后台对象记录缺少关联跳转。',
    '图片大图与缩略图、人工/AI/OCR分栏、警告清单、可编辑标注和高级原始数据折叠；文档/片段/向量/检索记录保留关联链。',
    ['业务用户能读懂图片用途、识别结果和警告，不需要解读JSON。','文档到片段到向量再到检索证据可往返；原始数据仅作高级详情。'],
    [js('table','function renderRagPreviewImageItem('),js('table','function renderRagImagePreviewItems('),js('table','function renderRagMetricsList(')],deps=[19,41])
task(48,'P1','证书部署判断必须检查有效性和真实加载状态','source_reproduced','settings-tls-certificates',
    'certificateRequestIsDeployed 仅匹配域名/IP，不检查有效期/状态/实际部署；匹配的过期证书也返回true（PROBE-10）。申请后库存未自动回读刷新；进度缺少持续更新。',
    '部署结论由后端返回有效性、目标匹配、已加载证书指纹及检测时间；申请后轮询进度并刷新库存，失败提供恢复办法。',
    ['同域名的过期/未部署证书不显示“已部署”。','真正成功后库存、状态和入口探测一致；申请失败、续签和内网信任说明清楚。'],
    [js('table','async function certificateRequestIsDeployed('),js('table','function renderCertificateCenterRequestForm('),js('table','function renderCertificateRequestProgress(')],deps=[14,38,40])
task(49,'P1','恢复浏览器连接后补齐逐页真实验收','runtime_verification','*',
    '本轮Chrome标签页/窗口读取连续超时，未取得登录后DOM、截图、网络/控制台证据，不能声称已完成逐页点击或真实权限、保存、下载验收。现有源码字符串测试无法代替运行。',
    '恢复Chrome控制后按页面覆盖表执行；只读路径先完成，写操作使用明确的测试数据/环境；保存脱敏截图、角色、视口、步骤、预期与实际。',
    ['38个主菜单及可达子页逐项有运行记录，未测/受限单独说明。','每类页面覆盖正常、无数据、错误、无权限和慢网；至少覆盖超管及两类受限管理员。','保存、撤销、删除等只在授权测试数据中验证；无凭据和原文进入证据。'],
    [ref('tests/Frontend/AdminLayoutOverflowTest.php','$adminCss = file_get_contents'),js('table','export async function renderResourcePage(')],old=['UI-013'])
task(50,'P2','按页面拆分加载并实测压缩、缓存和生命周期','source_confirmed','*',
    'table.js为5851行/253936字节；实际GET请求带Accept-Encoding仍传输253936字节且无Content-Encoding/Cache-Control。主CSS82936字节。当前ES模块导入版本已一致，不再重复沿用旧UI-011。',
    '行为保护建立后拆分页面模块，按需import；统一内容版本与缓存；配置压缩并测量；路由卸载清理请求/监听/定时器。',
    ['上线前后记录同设备同网络首屏、传输大小和请求数，无虚构性能百分比。','发布后无旧模块混用；大模块不阻塞无关页面；慢网切页不被旧响应覆盖。'],
    [js('table','export async function renderResourcePage('),js('app','import { adminErrorMessage'),js('dashboard','import { button')],deps=[49],old=['UI-010','UI-012','UI-016','UI-040'])
task(51,'P2','补齐键盘、窄屏、缩放与主题视觉验收','runtime_verification','*',
    '已有主题变量、焦点样式、移动菜单和横向滚动，应保留。域容器有tablist但按钮未声明tab/aria-selected；真实溢出、对比度和点击尺寸尚无截图测量，不能直接判不合格。',
    '统一tab语义/方向键、表格caption/scope、状态live region；在桌面/平板/手机和200%缩放下检查阅读、操作、主题及焦点。',
    ['1440×900、1024×768、390×844及200%缩放下主动作可达，无整页水平溢出或被遮挡。','深/浅主题状态可辨，键盘可完成关键任务；自动可访问性检查与人工读屏都留证据。'],
    [js('app','const tabs = el("div", { className: "domain-tabs"'),js('table','function renderTable('),ref('public/admin/assets/css/app.css','button:focus-visible')],deps=[12,49],old=['UI-030','UI-031'])
task(52,'P2','统一中文状态、单位和零值表达','source_reproduced','*',
    'music_generation/realtime 已在能力选择器中，但valueLabels未给中文（PROBE-06）；RAG和高级数据还显示Cases/Failures/status等原始词。统一字典也未覆盖各类业务状态。',
    '建立按业务资源的名称/状态/单位字典；中文主文案、技术代码作辅助；时间、大小、百分比、成本统一；未知值明确标记。',
    ['音乐生成、实时语音及新状态均有中文；不存在有含义却显示裸枚举的主流程。','0/未配置/未知/无数据彼此不同；单位紧邻数值。'],
    [js('resources','export const valueLabels'),js('resources','const capabilityOptions'),js('table','function renderRagUploadStatusCard(')],old=['UI-034'])
task(53,'P1','会话测试支持停止生成并隔离模式和上下文','source_confirmed','admin-chat-sessions',
    '发送虽有禁用，清空、模型/Key切换仍可在流式生成中操作；没有停止按钮。清空后流继续回调，切换Key/模式时还沿用原messages。每个文本片段重画全部会话，长回答可能抖动。',
    '增加取消信号和停止；生成期间锁定或明确确认模式变更；按Key/模型隔离上下文，保留草稿和重试；增量更新最后一条消息并支持复制。',
    ['生成中停止/清空不会被后续片段重新写回。','切换Key或直连/计费模式明确提示上下文及计费对象，发出的messages符合选择。','网络中断和重试有清楚状态，长回答滚动稳定，离页终止前端流。'],
    [js('table','async function renderAdminChatSessionPage('),js('api','export async function streamApi(')],deps=[14,17])

# Each entry describes a distinct view, including configured but currently orphaned views.
# A configured view is not proof of a reachable or functioning live page.
PAGE = {}
def page(key, goal, observation, numbers, scenario):
    PAGE[key] = dict(goal=goal, observation=observation, tasks=[f'UX-{n:03d}' for n in numbers], live_scenario=scenario)

page('login','快速、安全地进入后台','回车提交和登录错误文案需修复；自动填充已有基础。',[16,14,17],'回车、错密码、离线、登录中重复点击、过期后重登录。')
page('dashboard','理解当前运行并下钻异常','已有趋势/排行/安全/性能图；并发、日期、金额和下钻口径需校正。',[1,9,10,11,51],'近30天切今日、连续搜索、单面板失败、点击员工排行。')
page('admin-chat-sessions','验证直连或真实Key调用结果','已明确两种模式及计费区别；缺停止，生成中清空/切换会话状态不安全。',[4,17,53],'两种模式、切Key、停流、清空、断流；实际调用需使用授权测试额度。')
page('dashboard-share-links','创建范围可控的大屏分享','范围/指标需填JSON，安全动作反馈和入口名称需改进。',[3,15,33],'限制部门及指标、到期、IP拒绝、撤销会话。')
page('dashboard-share-access-logs','解释分享访问成功或受阻','表格是ID和事件枚举；缺清楚对象关联及适用筛选。',[3,18,33,35],'按分享和拒绝原因查找，回到对应分享；不实际扩展白名单。')
page('departments','维护组织层级','已有部门树和概况；父级需手输ID，树缺搜索/折叠，停用后果未说明。',[15,21],'三级树新增/移动/停用；验证Key影响须用测试组织。')
page('users','定位用户并维护在职、部门和账号','缺账号关键字搜索，多种安全动作并列，错误文案存在串页。',[3,15,18,22],'同名用户定位、移部门、离职和停用后Key处理。')
page('admin-users','维护管理员身份与授权范围','角色、类型、预设/自定义分散；清空权限/部门的语义存在实质错误。',[3,7,15,23],'预设与自定义、全清空、授权子部门、自身账号保护。')
page('permission-config','清楚地配置有效权限','有预设能力说明；保存payload不能表达空权限，列表限100，权限码阅读困难。',[3,7,23],'保存前后差异、新会话真实权限、清空与恢复预设分别验证。')
page('providers','接入厂商并导入可调用模型','已有预设/拉取/目录同步；填写到验证的引导和密钥保留语义需清楚。',[3,13,15,24],'配置→验证→预览→导入，URL/鉴权失败时修正。')
page('models','维护逻辑模型和上游通道','有网关支持/批量和能力契约；名称/厂商查找不足，通道关系难读。',[3,15,25,52],'同名模型多通道、不可调用原因、当前页批量范围。')
page('model-prices','正确填写并查看生效成本','模型选择/单位/日期和复杂规则不直观；通用停用不对应价格状态。',[3,6,11,26],'免费0、未配置、小额、不同币种、生效边界和历史版本。')
page('api-keys','发放/管理Key并掌握实际用量','已有分页与轮换额度工具；统计口径、原子保存、引用截断及一次性结果需修复。',[4,8,15,27,28],'45个Key翻页、搜索、创建一次、保留未载入模型授权、故障回滚。')
page('api-key-model-quotas','限制某Key的某模型用量','创建已有Key/模型引用选择；列表筛选仍手输ID，不能查全。',[3,4,18,29],'对象名称搜索、周期切换、0不限、实际用量及重置。')
page('quota-policies','建立可理解的限额规则','表单有并发限制，主表未展示；多层优先/不限/预占解释不足。',[3,13,29],'多层策略生效与并发额度回读。')
page('quota-bindings','把限额绑定到正确对象并解除','绑定已有目标选择；通用“停用”面对无status表可能无效果。',[3,4,6,29],'绑定→回读实际限额→解绑→回读恢复。')
page('usage-records','核对调用、计量与异常原因','已有内容和诊断；大量原始ID/横向列，前50条及错误伪行影响查账。',[1,3,5,18,30],'按员工和时段下钻、失败请求诊断、关联内容、查到历史记录。')
page('multimodal-tasks','追踪生成任务到产物','只有通用状态表；缺专用进度/失败/结果入口，停用不能代表取消。',[3,6,18,31],'排队/进行中/成功/失败/过期各有可执行下一步。')
page('media-assets','找到并预览下载生成媒体','只有原始大小/类型和下载；无友好预览与请求关系。',[3,6,18,31,32],'图像、音频、视频结果及过期资产，下载受阻时提示。')
page('usage-contents','理解内容留存和可访问性','摘要/模式/过期等列表信息有基础，缺请求到内容的直接查看闭环。',[3,18,30],'区分无正文、已清理、无权限和真实错误。')
page('content-archive','检索完整会话归档并受控查看原文','有原文选项和详情；筛选要求ID/文本日期，错误伪行及分页仍缺失。',[1,3,5,18,30,32],'会话/用户时段查询、原文权限、带当前条件导出。')
page('export-tasks','获取完整且可追踪的导出结果','只有状态/内部路径和成功下载，任务状态不持续更新。',[3,18,32],'创建直达、生成进度、失败、下载链接到期。')
page('desensitization-policies','配置并验证请求脱敏','JSON解析器与范围ID门槛高，缺预览；编辑需manage权限。',[3,13,15,36],'用合成敏感样例验证替换、阻断、摘要及作用范围。')
page('desensitization-events','解释脱敏命中及影响请求','主要展示policy_id等，缺策略及请求联动。',[3,18,35,36],'从命中事件定位原策略/请求，不展示不必要原文。')
page('alert-events','及时处理并关闭告警','确认/关闭后没有刷新和成功反馈，状态筛选及分页缺失。',[3,14,18,34],'处置说明、确认、关闭、重复点击及失败恢复。')
page('logs','按请求定位系统和上游故障','已有诊断工具；日志类型、时间和ID筛选需业务化，可见数据不能止于50条。',[3,5,18,19,35],'请求ID串联、级别/时间查询、错误与空数据区分。')
page('audit-logs','追溯操作人、对象及改动','只读表有基础；动作代码和对象ID不够直观，通用筛选不完全适用。',[3,18,19,35],'按操作者/动作/对象定位并查看变更差异。')
page('operations-status','快速判断部署健康与任务积压','缺字段被判异常，状态仅首次加载，没有刷新/未知说明。',[5,38],'可选服务未启用、真实故障、队列变化、更新时间。')
page('settings-brand-identity','设置和预览产品标题/标识','要求同源路径或data URL，保存成功不提示，不会立即刷新壳层品牌。',[13,40],'无效Logo路径、保存反馈、后台/登录/大屏一致预览。')
page('settings-tls-certificates','确认有效证书真实部署和续签','已有证书类型和库存；部署检查仅匹配名字，过期证书可误判成功。',[14,38,40,48],'有效/过期/未部署、申请失败、库存更新及入口探测。')
page('content-retention-policy','配置内容保留模式、期限及范围','有专用表单，范围策略仍是JSON，角色逗号输入；缺生效影响预览。',[13,15,30,37],'不留存/摘要/脱敏/原文、例外范围及到期行为。')
page('log-retention-policies','编辑日志在线/归档保留策略','没有新增/编辑form，通用停用无status可更新。',[6,13,37],'修改天数和压缩/加密、回读及最小天数约束。')
page('log-archive-files','找到归档并安全下载','展示路径和布尔值，缺友好文件信息及适用过滤。',[3,18,32,35,37],'时间范围、日志类型、已删除/过期、下载结果。')
page('settings-alert-thresholds','配置清楚的告警阈值','错误率缺单位/范围示例，通用数值字段缺约束，保存成功无反馈。',[13,34,40],'比例与百分比语义、最小请求量、窗口单位和生效验证。')
page('system-settings','查看和编辑高级配置','已有按字段编辑卡片，限前100且缺搜索；多入口同配置一致性待核。',[3,13,40],'搜索配置项、复杂类型、冲突、保存后回读。')
page('settings-center','按分类查找系统配置','非首分类切换后内部表单全hidden，且在当前菜单没有主入口。',[2,39,40,41],'首次切4类，恢复上次分区、保存和返回。')
page('settings-entry-security','理解管理端/API访问限制','CIDR列表和开关缺示例/当前访问影响；仅藏在设置中心分类，直达路由不支持。',[2,13,15,39,40],'当前IP被移出范围的预警；仅在授权测试环境保存。')
page('settings-retention-export','配置导出下载和文件保留期限','秒/天有标签但无范围校验或影响预览，与内容/日志策略分散。',[2,13,32,37,40],'链接TTL、文件保留、清理批次和到期行为。')
page('settings-rag-upload-review','配置审核角色与导入准入','角色按文本行输入；免审核/自审/驳回语义需连同导入流程验证。',[2,13,39,40,44],'审核开/关、自审规则、驳回阻断。')
page('settings-rag-package-upload','配置图片包大小与扩展名','限制未与上传前预检联动，保存反馈不明确。',[2,13,39,40,42],'最大包大小与允许后缀在上传页同步提示并执行。')
page('settings-rag-ai-image-processing','配置AI标注模型、提示策略和重试','视觉模型手输ID，需模型选择和能力校验；单位/失败恢复需解释。',[2,4,13,39,40,47],'只选可用视觉模型，超时/重试与任务实际执行一致。')
page('settings-rag-vectorization-monitoring','配置向量健康告警','成功率阈值无范围/百分比提示；与健康页关系不清楚。',[2,13,38,39,40],'阈值0–100、窗口/连续失败触发及未启用Qdrant。')
page('rag-settings','进入RAG相关配置','作为特殊页直接渲染整个系统设置中心，当前RAG主菜单没有入口。',[2,15,39,41],'RAG角色只看到其有权配置的RAG分区，不能绕到全局设置。')
page('rag-build','构建可检索知识的完整流程','有构建总览/知识库/数据集/导入任务/记录；深链接和导入后的上下文易丢。',[1,2,41,42,43,44],'从零创建到首条可检索知识；现有数据不出现错误引导。')
page('rag-retrieval-lab','用真实问题验证命中证据','已有数据集/问题/命中及加入样本；lookup失败会误变新手空态，重复检索无序号防护。',[1,4,14,41,45,47],'无知识库与无权限/失败区分，检索后加入样本保留上下文。')
page('rag-quality','从评估定位问题并跟进纠偏','已有评估/样本/反馈/纠偏4子页；缺完整处理闭环及URL保留活动子页。',[1,41,45,46],'失败样本→纠偏→复评→关闭，全程对象清楚。')
page('rag-operations','运行维护与向量健康诊断','健康/任务/检索/留存分组合理，但子页原始字段多，留存缺编辑。',[3,37,38,41,47],'由失败统计定位任务/记录，查看范围和可执行恢复。')
page('rag-dashboard','理解RAG流水线和阻塞位置','有指标/最近数据卡片；名称/统计口径混合技术字段，卡片缺详情联动。',[4,19,38,41,47],'零数据、正在构建、失败阻塞、有知识但无命中分别说明。')
page('rag-template-center','下载匹配当前导入方式的模板','只列类型/版本/下载，缺字段要求、例子和上传直达；当前主分类没入口。',[2,41,42],'文本/问答/图片清单/图片包下载与上传类型一致。')
page('rag-upload-workbench','上传并生成可审核预览','知识库/数据集未联动；无文件预检/进度，重复上传防护缺失。',[4,14,41,42],'文件类型/大小/模板错配、读取失败、上传成功。')
page('rag-import-task','持续跟踪某次导入并完成审核向量化','内部renderer有实现但不在resources字典；依赖rag-build的tab/upload_id，刷新易丢上下文。',[1,2,14,42,43,44],'上传→预览→审核/免审核确认→向量化→检索，刷新始终同一任务。')
page('rag-upload-detail','查询指定任务和审核内容','Upload ID手输入，预览无翻页；pending/not_required按钮状态不匹配。',[1,2,43,44,47],'从记录直达、45项预览、待审和免审确认、切任务失败不残留。')
page('rag-uploads','按状态找导入任务并进入详情','通用记录没有上传详情操作，缺upload_id等主识别；停用不解释取消含义。',[3,6,18,41,42,43],'通过文件名/任务ID/阶段定位，点击进入对应导入详情。')
page('rag-knowledge-bases','创建知识库和默认向量配置','form已有默认向量配置选择；部门是数字ID，授权/数据集/向量配置缺近旁入口。',[2,3,4,41],'新建知识库→配置向量→授权→数据集，不需要手改hash。')
page('rag-knowledge-base-bindings','授予或撤销知识库访问','有列定义但当前导航完全孤立、没有新增/编辑form；停用实际是撤销授权。',[2,6,15,41],'明确授权对象/权限/默认行为，撤销有影响说明与回读。')
page('rag-vector-datasets','维护数据集模态和向量参数','已有表单，但向量配置未按文本/图片/多模态过滤，检索配置用JSON。',[2,3,4,13,41],'按知识库筛选，配置模态匹配，修改索引参数说明重建影响。')
page('rag-dataset-bindings','维护数据集授权范围','只有通用列无管理表单，旧分类可找到而当前主菜单找不到；撤销名称不准。',[2,6,15,41],'添加/查看/撤销有效授权，重复授权和继承策略清楚。')
page('rag-vector-configs','配置并测试可用向量模型','有测试向量模型动作，入口藏在旧分类，选择器全量截断且维度/模态需联动。',[2,4,6,13,41],'测试模型维度→保存→绑定数据集，错误定位和配置冲突。')
page('rag-import-templates','高级维护模板版本','通用原始表没有编辑form；应明确只读或提供结构化维护，不出现假停用。',[2,3,6,41,47],'业务模板下载与高级模板元数据一致，停用语义真实。')
page('rag-image-preview','核对图片和人工/AI/OCR信息','有缩略图和重新标注，主体为JSON/英文且无翻页，需手输upload_id。',[2,14,43,47],'45张图片翻页、大图、AI失败/警告、重新标注状态。')
page('rag-vectorization-metrics','解释向量化健康和失败原因','有无任务提示，但分布是key:JSON清单，缺任务下钻和刷新。',[19,38,47],'失败类型→具体任务→处理结果，时间窗和阈值一致。')
page('rag-retrieval-evaluation','运行并解释检索评测','与rag-evaluation共用renderer，名称和入口重复，应迁移到同一评估流程。',[2,41,45],'同一运行从两个旧入口显示相同数据和URL。')
page('rag-upload-preview-items','查看原始导入项和校验错误','通用原始数据表无修正/定位入口，可能出现不合适停用。',[2,3,6,41,47],'错误行能对应文件行号、任务、修正办法。')
page('rag-documents','查看文档版本和所属数据集','原始ID多，缺文档→片段→索引链；通用停用未说明对向量影响。',[2,3,6,19,41,47],'版本、删除/停用范围和索引一致，详情链可走通。')
page('rag-chunks','查看和管理可检索内容片段','列表缺kb/dataset和正文摘要关联，通用动作不足以解释片段生命周期。',[2,3,6,19,41,47],'禁用后检索确实不命中，显示所属文档及重新启用能力。')
page('rag-image-assets','管理知识库图片及描述质量','没有友好预览和说明编辑，列表长技术字段；需与上传预览共用组件。',[2,3,6,31,41,47],'图片→上传/文档/向量关联，标注与警告可读。')
page('rag-embedding-jobs','找到失败向量任务并恢复','仅进度/计数/错误码，缺详情、重试条件与结果；通用停用不等于取消。',[3,6,18,38,47],'处理数量/总数、失败原因、允许重试/不允许原因。')
page('rag-vector-records','核对内容和索引一致性','集合/片段/状态/维度原始列表，缺索引健康和安全的重建入口。',[2,3,6,19,41,47],'从内容到向量记录及集合状态，已删向量不作正常记录。')
page('rag-retrieval-logs','复盘问题、命中证据与降级','表只列ID/命中数，缺问题、策略、耗时详情；原始ID不便定位。',[3,6,18,35,47],'检索→证据→反馈/评估样本，降级原因可解释。')
page('rag-evaluation','查看最近评估质量和失败样本','运行及转纠偏已有入口；原始数据量大，结果需阈值、范围和比较。',[14,45,46],'零样本、不同失败比例、转纠偏幂等和复评。')
page('rag-answer-feedback','处理用户反馈并关联检索','只有通用列，缺看详情和处理动作，无法形成有效反馈闭环。',[3,6,18,46,47],'差评→查看检索→归因→转任务→处理回告。')
page('rag-correction-tasks','完成纠偏和验证','没有专用编辑/执行/验证/关闭界面，通用停用不是纠偏完成。',[3,6,18,46],'受理、修改、复评、关闭及失败恢复。')
page('rag-retention-policies','设置RAG数据过期与处理方式','资源无form，enabled与通用status停用不匹配。',[3,6,13,37],'按知识库/数据集等对象修改策略，验证到期动作和保留边界。')
page('rag-eval-cases','维护可信评估样本','专用创建/导入/启停有实现；查全和编辑欠缺，期望片段仍可手输ID。',[1,3,4,14,45],'加入样本自动填值、批量导入行错误、启停和搜索。')
page('rag-eval-runs','比较各次评估运行','后端只读但前端资源未标readOnly，会出现停用；缺运行比较和失败详情关联。',[2,3,6,41,45],'只读动作正确，选两次运行比较指标和失败变化。')
page('rag-eval-results','解释单个样本评估结果','后端只读但前端可能给停用；只列run/case/rank，问题和证据不可直接读。',[2,3,6,19,41,45],'回到运行与样本，查看期望/实际命中，不能对结果做假停用。')
for key, goal in [('rag-console','旧版RAG总览入口'),('rag-knowledge-management','旧版知识库配置入口'),('rag-import-review','旧版导入审核入口'),('rag-image-vector','旧版图片向量入口'),('rag-feedback-correction','旧版反馈纠偏入口'),('rag-advanced-data','旧版高级数据入口')]:
    page(key,goal,'已注册并有子页常量，但没有当前主菜单入口，不能靠直接hash稳定访问；须决定迁移或提供明确高级入口。',[2,41],'旧链接迁移、权限、当前菜单选中态与所有子页可达性。')

assert len(TASKS)==53
assert len({t['id'] for t in TASKS})==len(TASKS)
keys={r['key'] for r in INV['resources']}|{'login','rag-import-task'}
assert keys==set(PAGE), (keys-set(PAGE),set(PAGE)-keys)
known={t['id'] for t in TASKS}
for row in PAGE.values():
    assert set(row['tasks'])<=known
for row in TASKS:
    assert set(row['dependencies'])<=known

main={key:d for d in INV['domains'] for key in d['tabs']}
reachable={key:parent for parent,tabs in INV['ragTabs'].items() if parent in main for key,label in tabs}
pages=[]
for key in [r['key'] for r in INV['resources']]+['login','rag-import-task']:
    cfg=next((r for r in INV['resources'] if r['key']==key),{'label':'管理员登录' if key=='login' else '导入任务'})
    if key in main:
        entry='main_menu'; domain=main[key]['key']; route=f'#/{domain}/{key}'
        entry_text=main[key]['label']+' → '+cfg['label']
    elif key in reachable:
        entry='current_internal_tab'; route=None; entry_text=f"{PAGE[reachable[key]]['goal']} / {reachable[key]} → {cfg['label']}（tab状态尚未写URL）"
    elif key.startswith('settings-'):
        entry='settings_center_internal'; route=None; entry_text='设置中心内部分类（设置中心本身入口需补全）'
    elif key=='login':
        entry='login';route='/admin/';entry_text='登录入口'
    else:
        entry='legacy_or_orphaned';route=None;entry_text='已注册的旧容器/内部页，当前主菜单无稳定入口'
    row=dict(key=key,label=cfg['label'],entry_type=entry,entry_description=entry_text,
        current_canonical_url=route,static_review='reviewed',live_review='pending_browser_connection',
        **PAGE[key])
    pages.append(row)

counts=dict(collections.Counter(t['priority'] for t in TASKS))
types=dict(collections.Counter(t['evidence_type'] for t in TASKS))
summary=dict(target='http://192.168.8.236:8080/admin/',client_date='2026-10-29',client_timezone='America/Los_Angeles',
    task_state='proposed',scope='Admin SPA configured views; standalone /screen pages excluded',
    domains=len(INV['domains']),main_menu_pages=len(main),configured_resources=len(INV['resources']),
    reviewed_view_targets=len(pages),live_verified_pages=0,priorities=counts,evidence_types=types,
    limitations=['Chrome UI reads repeatedly timed out; no authenticated live page or screenshot was captured.',
        'Public frontend hashes match local files. This does not establish deployed backend or database equivalence.',
        'Local probes use synthetic fake DOM/data and do not validate visual layout, real permissions or production writes.'])
(OUT/'tasks.json').write_text(json.dumps(dict(summary=summary,tasks=TASKS),ensure_ascii=False,indent=2)+'\n')
(OUT/'pages.json').write_text(json.dumps(dict(summary=summary,pages=pages),ensure_ascii=False,indent=2)+'\n')
with (OUT/'page-coverage.csv').open('w',newline='',encoding='utf-8-sig') as f:
    writer=csv.writer(f);writer.writerow(['页面标识','页面','入口类型','入口','静态审阅','浏览器实测','主要问题','任务','运行验收场景'])
    for p in pages:writer.writerow([p['key'],p['label'],p['entry_type'],p['entry_description'],'已审阅','待浏览器连接恢复',p['observation'],' / '.join(p['tasks']),p['live_scenario']])

remote=Path('/private/tmp/tokenhub-page-audit-20261029')
if (remote/'public-asset-manifest.json').exists():shutil.copyfile(remote/'public-asset-manifest.json',EVIDENCE/'public-asset-manifest.json')
if (remote/'compression-get.headers').exists():shutil.copyfile(remote/'compression-get.headers',EVIDENCE/'compression-get.headers')
source_paths=sorted({s['path'] for t in TASKS for s in t['evidence']})
snapshot={'note':'Audit source snapshot only; unrelated working tree changes pre-existed. Backend files were read locally only.',
    'files':[dict(path=p,sha256=hashlib.sha256((ROOT/p).read_bytes()).hexdigest()) for p in source_paths]}
(EVIDENCE/'source-snapshot.json').write_text(json.dumps(snapshot,ensure_ascii=False,indent=2)+'\n')

labels={'source_reproduced':'源码行为复现','source_confirmed':'代码可确认','usability_improvement':'体验改进建议','runtime_verification':'待运行验证'}
def md(text):return text.replace('|','\\|').replace('\n',' ')
lines=['# TokenHub 管理后台逐页可用性审查与任务清单','',
    '> 审查日期：2026-10-29（用户端 America/Los_Angeles）。目标：让管理员更容易找到功能、完成任务、理解结果并从错误中恢复。',
    '', '## 结论与证据边界','',
    '**已完成服务器前端核对、完整页面目录审阅和源码行为验证；浏览器逐页真实操作尚未完成。** Chrome 已有标签页可被列出，但领取标签页、读取窗口和独立审查页连续超时，未取得登录后页面 DOM 或截图。因此本报告不提供视觉打分，不声称完成真实增删改、权限或安装验收。',
    '',
    f'本次覆盖 **{len(INV["domains"])} 个业务域、{len(main)} 个主菜单页、{len(INV["resources"])} 个资源定义**；加登录页和独立内部导入任务，共 **{len(pages)} 个审查对象**。其中包含当前菜单无法进入的旧容器/隐藏页，不能把资源定义数当成已能访问的页面数。当前无稳定入口也是发现的问题。独立 `/screen/*` 大屏页面未纳入；已审查管理端分享配置及访问日志。',
    '',
    f'形成 **{len(TASKS)} 项待办**：'+ '、'.join(f'{k} {v}项' for k,v in sorted(counts.items()))+'。所有状态均为 proposed，未实施修复。P0表示核心任务阻断、错误结果或授权编辑语义错误，不等同安全漏洞评级。',
    '',
    '- 入口、7个JS模块、主CSS共9个公开静态响应与本地文件SHA-256一致，前端结论适用于本次从服务器取得的文件。浏览器缓存中的实际执行版本未验证。',
    '- '+str(len(json.loads((EVIDENCE/'source-behavior-probes.json').read_text())['results']))+'组本地行为探针使用原函数与合成数据/简化DOM，验证了路由、权限payload、分类可见性、错误渲染、预览翻页缺失、RAG按钮、证书判断与金额显示；不替代浏览器渲染。',
    '- 后端只审阅本地实现和迁移，没有对部署后端/数据库做版本一致性证明；涉及前后端契约的结论均须部署环境复验。',
    '- 未读取登录凭据、会话Cookie、真实原文或API密钥，未更改站点配置和业务数据。仓库原有大量改动，本次仅新增本审查目录。',
    '', '证据类型：代码可确认 / 源码行为复现 / 体验改进建议 / 待运行验证。后两类不伪装成线上故障。',
    '', '## 应先处理的核心问题','',
    '|任务|影响|最小验收|','|---|---|---|']
for t in TASKS:
    if t['priority']=='P0':lines.append(f"|[{t['id']}](#{t['id'].lower()}) {md(t['title'])}|{md(t['problem'])}|{md(t['acceptance'][0])}|")
lines+=['','## 已有能力应保留','',
    '已有按权限生成菜单、三种主题、侧栏收起/移动遮罩、宽表局部滚动、API密钥分页、模型批量操作、请求诊断、额度查看调整、密钥轮换、厂商目录同步、RAG真实检索与评估。这些是本次体验完善的基础，不需要重建。前端使用textContent/createElement，服务端仍做权限检查；本报告的按钮权限问题不等于后端越权已被验证。',
    '', '## 逐页分析','',
    '以下每页均已完成源码审阅；**浏览器状态全部为待实测**。每页列出业务目标、具体观察、对应任务与实际验收场景，详见机器可读的 [pages.json](pages.json) 与 [覆盖表](page-coverage.csv)。', '']
ordered=[]
for d in INV['domains']:
    ordered.append((d['label'],[p for key in d['tabs'] for p in pages if p['key']==key]))
ordered += [('登录与系统内部设置',[p for p in pages if p['key']=='login' or (p['key'].startswith('settings-') and p['key'] not in main) or p['key'] in ['settings-center','rag-settings']]),
    ('RAG当前子页、旧容器与高级内部页面',[p for p in pages if p['key'].startswith('rag-') and p['key'] not in main and p['key']!='rag-settings'])]
displayed=[]
for title,group in ordered:
    lines += ['### '+title,'','|页面 / 标识|要完成的工作|观察与问题|关联任务 / 运行验收|','|---|---|---|---|']
    for p in group:
        displayed.append(p['key'])
        refs='、'.join(f'[{x}](#{x.lower()})' for x in p['tasks'])
        lines.append(f"|{md(p['label'])}<br>`{p['key']}`|{md(p['goal'])}|{md(p['observation'])}|{refs}<br>验收：{md(p['live_scenario'])}|")
    lines.append('')
assert set(displayed)==keys and len(displayed)==len(keys)
lines+=['## 可执行任务','',
    '字段：P0优先修正核心正确性，P1完成主要工作流，P2提高效率及长期可维护性。S/M/L为相对规模，不是排期承诺；大多数任务为M，需要前后端协作。所有任务均需运行验收后才可标完成。通用项的 `generic-lists` 指通过renderGenericResourcePage渲染的资源表；`*` 指共享壳层/组件。','']
for t in TASKS:
    lines += [f"### {t['id']}",'',f"**{t['priority']} · {t['title']}**",'',
        f"证据：**{labels[t['evidence_type']]}**；状态：`proposed`；相对规模：{t['relative_effort']}；页面：`{' / '.join(t['affected_pages'])}`。",'',
        '**问题与影响：** '+t['problem'],'','**任务：** '+t['solution'],'','**验收：**','']
    lines += ['- '+x for x in t['acceptance']]
    lines += ['','**定位：** '+'；'.join(f"`{s['path']}:{s['line']}`（{s['symbol']}）" for s in t['evidence'])+'。']
    if t['dependencies']:lines += ['','依赖：'+'、'.join(t['dependencies'])+'。']
    if t['previous_task_ids']:lines += ['','与旧清单对应：'+'、'.join(t['previous_task_ids'])+'，本次以当前文件重新核对。']
    lines.append('')
lines+=['## 实施顺序','',
    '|阶段|任务范围|完成门槛|','|---|---|---|',
    '|第一批：正确性|UX-001～008、UX-044、UX-039、UX-011、UX-048|导航/授权保存/审核不阻断；数据查全；错误和金额/证书不误导|',
    '|第二批：任务闭环|UX-012～018、UX-021～022、UX-024～038、UX-040～046、UX-053|配置→保存→回读→验证→异常恢复连贯；主角色可完成核心任务|',
    '|第三批：效率与可维护|UX-019～020、UX-023、UX-047、UX-050～052|查找更快，页面结构可维护，视觉/键盘/窄屏有实际证据|',
    '|贯穿所有阶段|UX-009～010、UX-049|慢网/时间边界/角色/逐页真实运行验收持续补齐|',
    '',
    '优先串起三条端到端路径：①厂商→模型→价格→Key→会话测试→用量诊断；②知识库→向量配置→数据集→导入→审核→向量化→检索→评估→纠偏；③告警→日志→请求→处置→回读。共享组件先修一次，再覆盖所有受影响页面；避免逐页重复开发同类分页/选择器。',
    '', '## 与2026-10-28旧清单的关系','',
    '- 旧文档为静态分析；本次以服务器公开前端指纹和当前源码重新核对，新增逐页覆盖及可复现探针。',
    '- 旧UI-001/002/003/004/005/006、弹窗/表单和筛选类问题仍有对应证据，已经并入本次任务。不要把两份清单重复计工作量。',
    '- **旧UI-011“模块导入版本不一致导致重复加载”不再沿用**：当前7个模块的相关导入URL已一致；保留统一发布版本的长期要求。',
    '- 旧UI-015关于WAF/旧分支及行数的结论不原样复制；本次不以过期行号或页面数作为证据。侧栏焦点、移动遮罩、局部横向滚动和减少动效支持已有实现；仍需实际主题/窄屏验证。',
    '- 本次新增/细化：隐藏页路由、权限清空语义、无效停用、设置分类空白、Key统计口径、RAG审核枚举及免审核确认、预览翻页、证书误判和小额成本丢失。',
    '', '## 补齐浏览器验收的方法','',
    '浏览器连接恢复后，以page-coverage.csv逐项记录，不把本地探针结果算作线上通过。先核对已登录账号角色、环境与部署版本，再做只读遍历；保存/撤销/删除/轮换、真实模型调用、上传与导出仅在明确测试环境和测试对象中进行。',
    '',
    '每页最少记录：入口与返回、主任务、搜索/筛选/分页、空态/错误/无权限、加载与重复点击、字段校验、保存回读及恢复。共享组件在代表页面完整验证，其余页面检查集成；对RAG、Key、授权、证书、价格仍需分别验证业务状态。',
    '',
    '视觉检查视口建议：1440×900、1024×768、390×844和200%缩放；使用深/浅主题；检查长名称、多级部门、1000条引用及零/小额金额。没有截图/测量前，不给布局、色彩对比度或性能做已通过结论。',
    '', '## 文件与复核','',
    '- [tasks.json](tasks.json)：53项任务，优先级、证据、依赖、验收、状态。',
    '- [pages.json](pages.json) / [page-coverage.csv](page-coverage.csv)：82个审查对象及逐页复验场景。',
    '- [公开资产指纹](evidence/public-asset-manifest.json)：9个公开静态响应与本地SHA-256比对。',
    '- [源码行为验证](evidence/source-behavior-probes.json)：11组结果；[可重跑脚本](evidence/source-behavior-probes.cjs)。',
    '- [源码目录](evidence/source-inventory.json)、[源码指纹](evidence/source-snapshot.json)、[压缩GET响应头](evidence/compression-get.headers)。',
    '',
    '复核命令（仓库根目录）：`node docs/development-tasks/2026-10-29-admin-page-usability-audit/evidence/source-behavior-probes.cjs`。其成功仅表示当前源码仍能复现列出的问题，不表示问题已经修好，也不表示浏览器验收通过。',
    '']
(OUT/'report.md').write_text('\n'.join(lines))
print(json.dumps(summary,ensure_ascii=False,indent=2))
