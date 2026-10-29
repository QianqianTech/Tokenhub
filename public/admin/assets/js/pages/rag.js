import { adminErrorMessage, api } from "../api.js";
import { button, clear, el, field, readFields, statusMessage } from "../dom.js";
import { ragNavigate } from "../lib/navigation.js";
import { lookupRows } from "../lib/references.js";
import { renderGenericResourcePage } from "../lib/resource-table.js";
import { renderRagEvaluationCasesPage, renderRagEvaluationPage } from "./rag-quality.js";
import { renderRagImagePreviewPage, renderRagImportTaskPage, renderRagUploadDetailPage, renderRagUploadWorkbenchPage } from "./rag-upload.js";
import { columnLabel, formatResourceValue, resources } from "../resources.js";

export const RAG_CATEGORY_TABS = {
  "rag-build": [
    ["rag-dashboard", "构建总览"],
    ["rag-knowledge-bases", "知识库"],
    ["rag-vector-datasets", "数据集"],
    ["rag-import-task", "导入任务"],
    ["rag-uploads", "任务记录"]
  ],
  "rag-quality": [
    ["rag-evaluation", "检索评估"],
    ["rag-eval-cases", "评估样本"],
    ["rag-answer-feedback", "用户反馈"],
    ["rag-correction-tasks", "纠偏任务"]
  ],
  "rag-operations": [
    ["rag-vectorization-metrics", "向量化健康"],
    ["rag-embedding-jobs", "向量任务"],
    ["rag-retrieval-logs", "检索记录"],
    ["rag-retention-policies", "数据留存"]
  ],
  "rag-console": [
    ["rag-dashboard", "总览"],
    ["rag-retrieval-lab", "检索试验台"],
    ["rag-vectorization-metrics", "向量化监控"],
    ["rag-retrieval-evaluation", "检索评测"]
  ],
  "rag-knowledge-management": [
    ["rag-knowledge-bases", "知识库"],
    ["rag-vector-datasets", "向量数据集"],
    ["rag-dataset-bindings", "数据集授权"],
    ["rag-vector-configs", "向量配置"]
  ],
  "rag-import-review": [
    ["rag-template-center", "模板下载"],
    ["rag-upload-workbench", "上传导入"],
    ["rag-upload-detail", "上传详情"],
    ["rag-uploads", "上传记录"],
    ["rag-image-preview", "图片预览"]
  ],
  "rag-image-vector": [
    ["rag-image-assets", "图片资产"],
    ["rag-embedding-jobs", "向量任务"],
    ["rag-vector-records", "向量记录"]
  ],
  "rag-feedback-correction": [
    ["rag-evaluation", "检索评估"],
    ["rag-retrieval-logs", "检索日志"],
    ["rag-answer-feedback", "用户反馈"],
    ["rag-correction-tasks", "纠偏任务"],
    ["rag-retention-policies", "过期策略"]
  ],
  "rag-advanced-data": [
    ["rag-documents", "文档"],
    ["rag-chunks", "Chunk"],
    ["rag-upload-preview-items", "上传预览原始数据"],
    ["rag-import-templates", "导入模板原始表"],
    ["rag-eval-cases", "评估样本"],
    ["rag-eval-runs", "评估运行"],
    ["rag-eval-results", "评估结果"]
  ]
};

function renderRagOnboardingPage(summary = {}) {
  const totals = summary.totals || {};
  const hasKnowledgeBase = Number(totals.active_knowledge_bases || 0) > 0;
  const hasDataset = Number(totals.active_datasets || 0) > 0;
  const nextPage = hasKnowledgeBase ? "rag-vector-datasets" : "rag-knowledge-bases";
  const nextLabel = hasKnowledgeBase ? "创建第一个数据集" : "创建第一个知识库";
  return el("section", { className: "panel rag-onboarding" }, [
    el("span", { className: "eyebrow", text: "从第一条可验证知识开始" }),
    el("h2", { text: "RAG 还没有可检索的知识" }),
    el("p", { text: "完成知识库、数据集和一次导入后，系统会带你进入检索试验台验证结果。" }),
    el("ol", { className: "rag-onboarding-steps" }, [
      el("li", { text: `${hasKnowledgeBase ? "知识库已创建" : "创建知识库"}` }),
      el("li", { text: `${hasDataset ? "数据集已创建" : "创建数据集"}` }),
      el("li", { text: "上传文件并预览审核" }),
      el("li", { text: "向量化后在检索试验台提问" })
    ]),
    button(nextLabel, () => ragNavigate(nextPage, { openCreate: true }), "primary")
  ]);
}

function renderRagTrialSource(source, index, context = {}) {
  const signals = source?.rank_signals && typeof source.rank_signals === "object" ? source.rank_signals : {};
  const signalText = Object.entries(signals)
    .filter(([, value]) => value !== null && value !== undefined && value !== "")
    .map(([key, value]) => `${key}: ${Array.isArray(value) ? value.join(", ") : value}`)
    .join(" · ");
  return el("article", { className: "rag-trial-source" }, [
    el("header", {}, [
      el("strong", { text: `${index + 1}. ${source.title || source.chunk_id || "未命名片段"}` }),
      el("span", { className: "status-badge", text: source.retrieval_strategy || "未知策略" })
    ]),
    el("p", { text: source.content || source.text || source.description || "无可展示正文" }),
    source.chunk_id ? button("加入评估样本", () => ragNavigate("rag-quality", {
      tab: "rag-eval-cases",
      dataset_id: context.dataset_id || source.dataset_id || "",
      query: context.query || "",
      expected_chunk_ids: [String(source.chunk_id)]
    }), "secondary") : null,
    el("small", { text: signalText || "暂无排序信号" })
  ].filter(Boolean));
}

export async function renderRagRetrievalLabPage(content) {
  clear(content);
  const [knowledgeBases, datasets] = await Promise.all([
    lookupRows("rag-knowledge-bases"),
    lookupRows("rag-vector-datasets")
  ]);
  if (knowledgeBases.length === 0 || datasets.length === 0) {
    content.append(renderRagOnboardingPage({ totals: {
      active_knowledge_bases: knowledgeBases.length,
      active_datasets: datasets.length
    } }));
    return;
  }

  const form = el("form", { className: "edit-form rag-retrieval-lab", onSubmit: (event) => event.preventDefault() });
  const resultPanel = el("section", { className: "rag-trial-results" }, [statusMessage("在左侧输入一个真实问题并开始检索，命中的片段、得分和来源会显示在这里。")]);
  const datasetOptions = datasets.map((dataset) => [dataset.dataset_id, `${dataset.name || dataset.dataset_id}`]);
  form.append(
    field({ name: "dataset_id", label: "数据集", type: "select", options: datasetOptions }),
    field({ name: "query", label: "真实问题", type: "textarea", rows: 3 }),
    field({ name: "top_k", label: "返回条数", type: "select", options: [["3", "3 条"], ["5", "5 条"], ["10", "10 条"]] }, "5")
  );
  form.append(el("div", { className: "toolbar-actions" }, [button("开始检索", async () => {
    const values = readFields(form, [{ name: "dataset_id" }, { name: "query" }, { name: "top_k", coerce: "number" }]);
    if (!values.query) {
      resultPanel.replaceChildren(statusMessage("请输入要验证的真实问题。", "error"));
      return;
    }
    resultPanel.replaceChildren(statusMessage("正在执行真实检索..."));
    try {
      const result = await api("/admin-api/rag/trial-retrieval", { method: "POST", body: JSON.stringify(values) });
      const data = result.data || {};
      const sources = Array.isArray(data.sources) ? data.sources : [];
      resultPanel.replaceChildren(
        el("header", { className: "rag-trial-result-header" }, [
          el("h2", { text: `命中 ${sources.length} 条` }),
          el("span", { text: `${data.latency_ms || 0} ms · ${data.fallback_used ? "已降级" : "未降级"}` })
        ]),
        el("p", { className: "notice", text: `数据集：${data.dataset?.name || data.dataset?.dataset_id || values.dataset_id}` }),
        ...(sources.length > 0 ? sources.map((source, index) => renderRagTrialSource(source, index, values)) : [statusMessage("没有命中内容。可调整问题或回到构建知识检查导入。", "error")])
      );
    } catch (error) {
      resultPanel.replaceChildren(statusMessage(adminErrorMessage(error, "检索试验失败"), "error"));
    }
  }, "primary")]));
  content.append(el("div", { className: "rag-trial-layout" }, [
    el("section", { className: "panel rag-trial-form-panel" }, [
      el("h2", { text: "检索试验台" }),
      el("p", { text: "选择数据集，输入实际用户问题，查看命中证据和排序原因。" }),
      form
    ]),
    resultPanel
  ]));
}


export async function renderRagDashboardPage(content) {
  clear(content);
  const metricsPanel = el("section", { className: "panel rag-dashboard-panel" }, [
    el("h2", { text: "RAG 运营总览" }),
    statusMessage("正在从数据库加载 RAG 指标...")
  ]);
  const grid = el("section", { className: "rag-dashboard-grid" }, [metricsPanel]);
  content.append(grid);

  try {
    const result = await api("/admin-api/rag/summary", { query: { window_hours: 24 } });
    const data = result.data || {};
    const totals = data.totals || {};
    const retrieval = data.retrieval || {};
    if (Number(totals.active_knowledge_bases || 0) === 0 || Number(totals.active_datasets || 0) === 0) {
      metricsPanel.replaceChildren(renderRagOnboardingPage(data));
      return;
    }
    metricsPanel.replaceChildren(
      el("h2", { text: "RAG 运营总览" }),
      el("p", { className: "notice", text: `数据来源: ${data.source || "database"} / 统计窗口: ${data.window_hours || 24}h` }),
      el("div", { className: "rag-dashboard-cards" }, [
        ragMetricCard("知识库", totals.active_knowledge_bases || 0),
        ragMetricCard("数据集", totals.active_datasets || 0),
        ragMetricCard("上传记录", totals.uploads || 0),
        ragMetricCard("预览条目", totals.preview_items || 0),
        ragMetricCard("有效 Chunk", totals.enabled_chunks || 0),
        ragMetricCard("已索引向量", totals.indexed_vectors || 0),
        ragMetricCard("检索次数", retrieval.total || 0),
        ragMetricCard("降级检索", retrieval.fallback_count || 0, Number(retrieval.fallback_count || 0) > 0 ? "warning" : ""),
        ragMetricCard("待处理纠偏", totals.open_corrections || 0, Number(totals.open_corrections || 0) > 0 ? "warning" : "")
      ]),
      renderRagPipeline(data.pipeline || []),
      renderRagStatusBuckets(data.status || {})
    );

    const recent = data.recent || {};
    grid.append(
      ragSummaryTable("最近上传", recent.uploads || [], ["upload_id", "content_type", "status", "review_status", "vectorize_status", "stage", "progress", "total_items", "created_at"]),
      ragSummaryTable("数据集实况", recent.datasets || [], ["dataset_id", "name", "knowledge_base_name", "status", "chunk_count", "vector_count", "last_indexed_at"]),
      ragSummaryTable("向量任务", recent.jobs || [], ["job_id", "job_type", "status", "processed_items", "total_items", "progress_percent", "error_code", "created_at"]),
      ragSummaryTable("检索日志", recent.retrievals || [], ["retrieval_id", "api_key_id", "query_modality", "hit_count", "latency_ms", "fallback_used", "created_at"]),
      ragSummaryTable("反馈", recent.feedback || [], ["feedback_id", "retrieval_id", "rating", "status", "created_at"]),
      ragSummaryTable("纠偏任务", recent.corrections || [], ["task_id", "issue_type", "priority", "status", "action", "created_at"])
    );
  } catch (error) {
    metricsPanel.replaceChildren(statusMessage(adminErrorMessage(error, "RAG 数据库总览加载失败"), "error"));
  }
}

export function ragMetricCard(label, value, tone = "") {
  return el("article", { className: `rag-metric-card ${tone}`.trim() }, [
    el("span", { text: label }),
    el("strong", { text: value })
  ]);
}

function renderRagPipeline(rows) {
  return el("section", { className: "rag-pipeline" }, [
    el("h3", { text: "真实写入链路" }),
    el("div", { className: "rag-pipeline-steps" }, (rows || []).map((row) => el("article", { className: Number(row.blocked || 0) > 0 ? "rag-pipeline-step warning" : "rag-pipeline-step" }, [
      el("span", { text: row.label || row.step || "" }),
      el("strong", { text: formatResourceValue("count", row.count || 0) }),
      el("small", { text: `异常/阻塞 ${row.blocked || 0}` })
    ])))
  ]);
}

function renderRagStatusBuckets(status) {
  const groups = Object.entries(status || {});
  return el("section", { className: "rag-status-buckets" }, [
    el("h3", { text: "状态分布" }),
    el("div", { className: "rag-status-grid" }, groups.map(([title, values]) => renderRagMetricsList(title, values || {})))
  ]);
}

export function ragSummaryTable(title, rows, columns) {
  return el("section", { className: "panel table-panel rag-summary-panel" }, [
    el("h2", { text: title }),
    Array.isArray(rows) && rows.length > 0 ? renderRagSummaryList(rows, columns) : statusMessage("暂无数据")
  ]);
}

function renderRagSummaryList(rows, columns) {
  return el("div", { className: "rag-summary-list" }, rows.slice(0, 8).map((row) => renderRagSummaryItem(row, columns)));
}

function renderRagSummaryItem(row, columns) {
  const primaryColumn = columns[0] || "id";
  const title = formatResourceValue(primaryColumn, row[primaryColumn]) || "-";
  const statusColumn = columns.find((column) => ["status", "review_status", "vectorize_status", "fallback_used"].includes(column));
  const status = statusColumn ? formatResourceValue(statusColumn, row[statusColumn]) : "";
  const chips = columns
    .slice(1)
    .filter((column) => row[column] !== null && row[column] !== undefined && String(row[column]) !== "")
    .map((column) => el("span", { className: "rag-summary-chip" }, [
      el("small", { text: columnLabel(column) }),
      el("b", { text: formatResourceValue(column, row[column]) })
    ]));

  return el("article", { className: "rag-summary-item" }, [
    el("header", { className: "rag-summary-item-header" }, [
      el("strong", { text: title }),
      status ? el("span", { className: `status-badge ${String(row[statusColumn] || "")}`, text: status }) : null
    ].filter(Boolean)),
    el("div", { className: "rag-summary-chip-row" }, chips.length > 0 ? chips : [el("span", { className: "muted", text: "无更多字段" })])
  ]);
}

export async function renderRagCategoryPage(content, resourceKey, initialFilters = {}) {
  clear(content);
  const tabs = RAG_CATEGORY_TABS[resourceKey] || [];
  if (tabs.length === 0) {
    content.append(statusMessage("RAG 分类不存在", "error"));
    return;
  }

  let activeKey = String(initialFilters.tab || tabs[0][0]);
  if (!tabs.some(([key]) => key === activeKey)) {
    activeKey = tabs[0][0];
  }

  const tabBar = el("div", { className: "rag-category-tabs" });
  const panel = el("section", { className: "rag-category-tab-panel" });
  const pageTitle = resources[resourceKey]?.label || "RAG";

  const renderActive = async (nextKey = activeKey) => {
    activeKey = nextKey;
    tabBar.replaceChildren(...tabs.map(([key, label]) => button(label, async () => {
      await renderActive(key);
    }, key === activeKey ? "primary rag-category-tab active" : "secondary rag-category-tab")));
    await renderRagTabContent(panel, activeKey, activeKey === String(initialFilters.tab || "") ? initialFilters : {});
  };

  content.append(el("section", { className: "panel rag-category-page" }, [
    el("h2", { text: pageTitle }),
    tabBar
  ]), panel);

  await renderActive(activeKey);
}

async function renderRagTabContent(panel, resourceKey, filters = {}) {
  clear(panel);
  if (resourceKey === "rag-retrieval-lab") {
    await renderRagRetrievalLabPage(panel);
    return;
  }
  if (resourceKey === "rag-dashboard") {
    await renderRagDashboardPage(panel);
    return;
  }
  if (resourceKey === "rag-vectorization-metrics") {
    await renderRagVectorizationMetricsPage(panel);
    return;
  }
  if (resourceKey === "rag-retrieval-evaluation") {
    await renderRagEvaluationPage(panel);
    return;
  }
  if (resourceKey === "rag-evaluation") {
    await renderRagEvaluationPage(panel);
    return;
  }
  if (resourceKey === "rag-eval-cases") {
    await renderRagEvaluationCasesPage(panel, filters);
    return;
  }
  if (resourceKey === "rag-template-center") {
    await renderRagTemplateCenterPage(panel);
    return;
  }
  if (resourceKey === "rag-upload-workbench") {
    await renderRagUploadWorkbenchPage(panel);
    return;
  }
  if (resourceKey === "rag-import-task") {
    await renderRagImportTaskPage(panel, filters);
    return;
  }
  if (resourceKey === "rag-upload-detail") {
    await renderRagUploadDetailPage(panel, filters);
    return;
  }
  if (resourceKey === "rag-image-preview") {
    await renderRagImagePreviewPage(panel, filters);
    return;
  }
  await renderGenericResourcePage(panel, resourceKey, filters, { pageHead: false });
}

export async function renderRagTemplateCenterPage(content) {
  clear(content);
  const panel = el("section", { className: "panel rag-template-center" }, [
    el("h2", { text: "RAG 模板下载" }),
    statusMessage("正在加载模板...")
  ]);
  content.append(panel);
  try {
    const result = await api("/admin-api/rag/import-templates");
    const templates = Array.isArray(result.data) ? result.data : [];
    panel.replaceChildren(
      el("h2", { text: "RAG 模板下载" }),
      el("div", { className: "rag-template-grid" }, templates.map((template) => {
        const templateId = String(template.template_id || "");
        return el("article", { className: "rag-template-card" }, [
          el("h3", { text: template.name || templateId }),
          el("p", { text: `类型: ${template.template_type || ""}` }),
          el("p", { text: `版本: ${template.version || ""}` }),
          button("下载模板", () => {
            window.location.href = `/admin-api/rag/import-templates/${encodeURIComponent(templateId)}/download`;
          }, "secondary")
        ]);
      }))
    );
  } catch (error) {
    panel.replaceChildren(statusMessage(adminErrorMessage(error, "RAG 模板加载失败"), "error"));
  }
}

export async function renderRagVectorizationMetricsPage(content) {
  clear(content);
  const host = el("section", { className: "panel rag-vectorization-metrics" }, [
    el("h2", { text: "RAG 向量化成功率" }),
    statusMessage("正在加载 RAG 向量化监控...")
  ]);
  content.append(host);
  try {
    const result = await api("/admin-api/rag/vectorization-metrics");
    const data = result.data || {};
    host.replaceChildren(
      el("h2", { text: "RAG 向量化成功率" }),
      el("p", { className: data.below_threshold ? "notice error" : "notice", text: Number(data.total_jobs || 0) === 0 ? "暂无可计算任务" : `最近 ${data.window_hours || 24}h 总体成功率 ${data.overall_success_rate || 0}%` }),
      el("p", { text: `上传任务 ${data.total_jobs || 0}，失败 ${data.failed_jobs || 0}，阈值 ${data.threshold || 95}%` }),
      el("p", { text: `告警：${data.alert_code || "无"}` }),
      renderRagMetricsList("任务类型", data.by_job_type || {}),
      renderRagMetricsList("Top 失败原因", data.top_failures || {})
    );
  } catch (error) {
    host.replaceChildren(statusMessage(adminErrorMessage(error, "RAG 向量化监控加载失败"), "error"));
  }
}

function metricText(value) {
  if (value === null || value === undefined || value === "") {
    return "-";
  }
  if (typeof value === "number") {
    return value.toLocaleString("zh-CN");
  }
  if (Array.isArray(value)) {
    return value.map(metricText).join("，");
  }
  if (typeof value === "object") {
    return Object.entries(value).map(([key, nested]) => `${columnLabel(key)} ${metricText(nested)}`).join("，");
  }
  return String(value);
}

function renderRagMetricsList(title, data) {
  const rows = Object.entries(data || {}).map(([key, value]) => el("li", { text: `${columnLabel(key)}：${metricText(value)}` }));
  return el("section", { className: "rag-metrics-list" }, [
    el("h3", { text: title }),
    el("ul", {}, rows.length > 0 ? rows : [el("li", { text: "暂无数据" })])
  ]);
}
