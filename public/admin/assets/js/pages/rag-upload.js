import { adminErrorMessage, api, isAbortError } from "../api.js";
import { button, clear, el, field, readFields, statusMessage } from "../dom.js";
import { emptyState, errorState, loadingState, toast } from "../components/feedback.js";
import { pager } from "../components/pager.js";
import { columnLabel, valueLabel } from "../resources.js";
import { ragNavigate } from "../lib/navigation.js";
import { renderDataDetails, setErrorOutput, setOperationOutput } from "../lib/output.js";
import { departmentSelectOptions, lookupRows } from "../lib/references.js";

export async function renderRagUploadWorkbenchPage(content, options = {}) {
  clear(content);
  const [knowledgeBases, datasets, departmentOptions] = await Promise.all([
    lookupRows("rag-knowledge-bases"),
    lookupRows("rag-vector-datasets"),
    departmentSelectOptions()
  ]);
  const form = el("form", { className: "edit-form rag-upload-workbench", onSubmit: (event) => event.preventDefault() });
  const kbOptions = knowledgeBases.map((row) => [row.kb_id, `${row.name || row.kb_id} (${row.kb_id})`]);
  const datasetOptions = datasets.map((row) => [row.dataset_id, `${row.name || row.dataset_id} (${row.dataset_id})`]);
  const fileInput = el("input", { type: "file", name: "rag_file", accept: ".xlsx,.csv,.txt,.zip,.docx,.pptx,.pdf,.md,.markdown,.html,.htm" });
  const output = el("p", { className: "notice", hidden: true });
  const uploadResult = el("section", { className: "rag-upload-result" });

  form.append(
    field({ name: "kb_id", label: "知识库", type: "select", options: kbOptions }),
    field({ name: "target_dataset_id", label: "向量数据集", type: "select", options: datasetOptions }),
    field({ name: "department_id", label: "部门", type: "select", coerce: "number", options: departmentOptions }),
    field({ name: "template", label: "导入类型", type: "select", options: [["text", "文本"], ["qa", "问答"], ["document", "文档（Word / PDF / PPT / Markdown / HTML）"], ["image", "图片清单"], ["image_package", "ZIP 图片包"]] }),
    // RAG-022: optional masking before indexing; nothing selected = knowledge base / system default.
    field({ name: "redaction", label: "导入前脱敏（不选则按知识库或系统默认）", type: "multiselect", optional: true, options: [["phone", "手机号"], ["email", "邮箱"], ["id_card", "身份证号"], ["bank_card", "银行卡号"], ["ipv4", "IP 地址"]] }),
    field({ name: "confirm_required", label: "上传后确认", type: "select", options: [["true", "需要预览确认"], ["false", "允许自动向量化"]] }),
    el("label", { className: "field" }, [
      el("span", { text: "上传文件" }),
      fileInput
    ]),
    el("div", { className: "toolbar-actions" }, [
      button("上传并生成预览", async () => {
        await submitRagUploadWorkbench(form, fileInput, output, uploadResult, options.onUploaded);
      }, "primary", { disabled: kbOptions.length === 0 || datasetOptions.length === 0 })
    ]),
    output
  );

  content.append(el("section", { className: "panel" }, [
    el("h2", { text: "RAG 上传工作台" }),
    form
  ]), uploadResult);
}

async function submitRagUploadWorkbench(form, fileInput, output, uploadResult, onUploaded = null) {
  const values = readFields(form, [
    { name: "kb_id" },
    { name: "target_dataset_id" },
    { name: "department_id", coerce: "number" },
    { name: "template" },
    { name: "confirm_required" },
    { name: "redaction", type: "multiselect", optional: true }
  ]);
  const file = fileInput.files?.[0];
  if (!file) {
    setErrorOutput(output, "请选择要上传的 Excel、CSV、文本、文档或 ZIP 文件");
    return;
  }
  const template = String(values.template || "text");
  const contentBase64 = await readFileAsBase64(file);
  const processing = {
    target_dataset_id: values.target_dataset_id,
    template,
    confirm_required: values.confirm_required !== "false",
    auto_vectorize: values.confirm_required === "false"
  };
  if (template === "image_package") {
    processing.package_mode = "manifest_zip";
  }
  if (Array.isArray(values.redaction) && values.redaction.length > 0) {
    processing.redaction = values.redaction;
  }
  try {
    const kbId = String(values.kb_id || "");
    const result = await api(`/admin-api/rag/knowledge-bases/${encodeURIComponent(kbId)}/uploads`, {
      method: "POST",
      body: JSON.stringify({
        department_id: values.department_id,
        filename: file.name,
        mime_type: file.type || "application/octet-stream",
        content_type: template === "image_package" ? "zip" : template,
        content_base64: contentBase64,
        processing
      })
    });
    const uploadId = String(result.data?.upload_id || "");
    if (uploadId && typeof onUploaded === "function") {
      await onUploaded(uploadId);
      return;
    }
    setOperationOutput(output, result.data, "上传已解析，请在导入任务中预览确认");
    uploadResult.replaceChildren(renderRagUploadStatusCard(result.data));
  } catch (error) {
    setErrorOutput(output, adminErrorMessage(error, "RAG 上传失败"));
  }
}

function readFileAsBase64(file) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(String(reader.result || "").split(",").pop() || "");
    reader.onerror = () => reject(reader.error || new Error("file read failed"));
    reader.readAsDataURL(file);
  });
}

export async function renderRagUploadDetailPage(content, initialFilters = {}) {
  clear(content);
  const taskMode = initialFilters.import_task === true;
  const state = {
    uploadId: String(initialFilters.upload_id || ""),
    previewType: "text",
    page: 1,
    uploadLoaded: false
  };
  const uploadField = taskMode ? null : field({ name: "upload_id", label: "导入任务编号" }, state.uploadId);
  const statusPanel = el("section", { className: "panel rag-upload-status-panel" }, [statusMessage(taskMode ? "正在加载导入任务…" : "请输入导入任务编号查询上传详情。")]);
  const previewPanel = el("section", { className: "panel rag-upload-preview-panel" });
  const output = el("p", { className: "notice", hidden: true });
  const approveButton = button("审核通过", async () => reviewRagUpload(state.uploadId, "approve", output, refresh), "secondary", { disabled: !state.uploadLoaded });
  const rejectButton = button("审核拒绝", async () => reviewRagUpload(state.uploadId, "reject", output, refresh), "secondary", { disabled: !state.uploadLoaded });
  const vectorizeButton = button("确认向量化", async () => confirmRagUpload(state.uploadId, output, refresh), "primary", { disabled: !state.uploadLoaded });

  const refresh = async () => {
    if (uploadField) {
      state.uploadId = String(uploadField.querySelector("[name=\"upload_id\"]")?.value || "").trim();
    }
    if (state.uploadId === "") {
      statusPanel.replaceChildren(statusMessage(taskMode ? "尚未创建导入任务，请先上传文件。" : "请输入导入任务编号查询上传详情。"));
      previewPanel.replaceChildren();
      return;
    }
    await refreshRagUploadDetail(state, statusPanel, previewPanel, output, refresh, { approveButton, rejectButton, vectorizeButton });
  };

  content.append(el("section", { className: "panel rag-upload-detail" }, [
    el("h2", { text: taskMode ? "导入任务" : "RAG 上传详情" }),
    el("div", { className: "toolbar-actions" }, [
      uploadField,
      uploadField ? button("查询", refresh) : null,
      approveButton,
      rejectButton,
      vectorizeButton
    ].filter(Boolean)),
    output
  ]), statusPanel, renderRagPreviewTabs(state, previewPanel, refresh), previewPanel);

  await refresh();
}

export function readFileAsText(file) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(String(reader.result || ""));
    reader.onerror = () => reject(reader.error || new Error("file read failed"));
    reader.readAsText(file);
  });
}

export async function renderRagImportTaskPage(content, initialFilters = {}) {
  const uploadId = String(initialFilters.upload_id || "");
  if (uploadId !== "") {
    await renderRagUploadDetailPage(content, { ...initialFilters, upload_id: uploadId, import_task: true });
    return;
  }
  await renderRagUploadWorkbenchPage(content, {
    onUploaded: async (uploadId) => {
      ragNavigate("rag-build", { tab: "rag-import-task", upload_id: uploadId });
    }
  });
}

async function refreshRagUploadDetail(state, statusPanel, previewPanel, output, refresh, actions = {}) {
  try {
    const uploadId = state.uploadId;
    const [status, preview] = await Promise.all([
      api(`/admin-api/rag/uploads/${encodeURIComponent(uploadId)}`),
      api(`/admin-api/rag/uploads/${encodeURIComponent(uploadId)}/preview`, {
        query: { ...PREVIEW_QUERY_DEFAULTS, type: state.previewType, page: state.page }
      })
    ]);
    statusPanel.replaceChildren(renderRagUploadStatusCard(status.data));
    previewPanel.replaceChildren(renderRagPreviewPage(preview.data, refresh, (page) => {
      state.page = page;
      return refresh();
    }));
    state.uploadLoaded = true;
    const reviewStatus = String(status.data?.review_status || "");
    const vectorizeStatus = String(status.data?.vectorize_status || "");
    if (actions.approveButton) actions.approveButton.disabled = !["waiting_review", "pending_review"].includes(reviewStatus);
    if (actions.rejectButton) actions.rejectButton.disabled = !["waiting_review", "pending_review"].includes(reviewStatus);
    if (actions.vectorizeButton) actions.vectorizeButton.disabled = reviewStatus !== "approved" || ["queued", "processing", "succeeded"].includes(vectorizeStatus);
    output.hidden = true;
  } catch (error) {
    state.uploadLoaded = false;
    if (actions.approveButton) actions.approveButton.disabled = true;
    if (actions.rejectButton) actions.rejectButton.disabled = true;
    if (actions.vectorizeButton) actions.vectorizeButton.disabled = true;
    if (isAbortError(error)) {
      return;
    }
    statusPanel.replaceChildren(errorState(error, { fallback: "RAG 上传详情加载失败", onRetry: () => refresh() }));
    previewPanel.replaceChildren();
  }
}

const RAG_STATE_LABELS = {
  pending: "待处理",
  uploaded: "已上传",
  parsing: "解析中",
  parsed: "已解析",
  validating: "校验中",
  previewing: "待预览",
  waiting_review: "待审核",
  pending_review: "待审核",
  not_required: "无需审核",
  approved: "已通过",
  rejected: "已拒绝",
  queued: "排队中",
  processing: "处理中",
  vectorizing: "向量化中",
  succeeded: "成功",
  completed: "已完成",
  failed: "失败",
  valid: "校验通过",
  passed: "校验通过",
  invalid: "校验未通过",
  warning: "有警告"
};

const RAG_FIELD_LABELS = {
  purpose: "用途",
  description: "简述",
  short_description: "简述",
  detailed_description: "详细描述",
  title: "标题",
  tags: "标签",
  labels: "标签",
  caption: "图片说明",
  ocr_text: "OCR 文本",
  confidence: "置信度",
  model: "模型",
  model_name: "模型",
  language: "语言",
  objects: "识别对象",
  status: "状态",
  error: "错误"
};

const PREVIEW_QUERY_DEFAULTS = { page_size: 20 };

function ragStateLabel(value) {
  const text = String(value ?? "").trim();
  return text === "" ? "-" : RAG_STATE_LABELS[text] || valueLabel("status", text);
}

function ragValueText(value) {
  if (value === null || value === undefined || value === "") {
    return "-";
  }
  if (Array.isArray(value)) {
    return value.length ? value.map((item) => ragValueText(item)).join("，") : "-";
  }
  if (typeof value === "object") {
    return Object.entries(value).map(([key, nested]) => `${RAG_FIELD_LABELS[key] || columnLabel(key)}：${ragValueText(nested)}`).join("；");
  }
  if (typeof value === "number") {
    return value.toLocaleString("zh-CN");
  }
  if (typeof value === "boolean") {
    return value ? "是" : "否";
  }
  return String(value);
}

// Readable key/value list for annotation objects (no raw JSON).
function ragFacts(title, data) {
  const entries = Object.entries(data || {}).filter(([, value]) => value !== null && value !== undefined && value !== "" && !(Array.isArray(value) && value.length === 0));
  if (!entries.length) {
    return el("div", { className: "rag-facts" }, [el("h4", { text: title }), el("p", { className: "chart-subtle", text: "暂无" })]);
  }
  return el("div", { className: "rag-facts" }, [
    el("h4", { text: title }),
    el("dl", {}, entries.flatMap(([key, value]) => [
      el("dt", { text: RAG_FIELD_LABELS[key] || columnLabel(key) }),
      el("dd", { text: ragValueText(value) })
    ]))
  ]);
}

function ragIssues(title, issues) {
  const list = (Array.isArray(issues) ? issues : issues ? [issues] : []).map((issue) => ragValueText(issue));
  if (!list.length) {
    return null;
  }
  return el("div", { className: "rag-issues notice error" }, [
    el("strong", { text: title }),
    el("ul", {}, list.map((text) => el("li", { text })))
  ]);
}

function renderRagUploadStatusCard(upload) {
  const progress = Number(upload?.progress || upload?.progress_percent || 0);
  const reviewStatus = upload?.review_status || "not_required";
  return el("section", { className: "rag-upload-status-card" }, [
    el("h3", { text: upload?.original_filename || upload?.upload_id || "导入任务" }),
    el("div", { className: "rag-upload-progress", role: "progressbar", "aria-valuemin": "0", "aria-valuemax": "100", "aria-valuenow": String(Math.max(0, Math.min(100, progress))), "aria-label": "导入进度" }, [
      el("div", { className: "rag-upload-progress-bar", style: `width: ${Math.max(0, Math.min(100, progress))}%` })
    ]),
    el("dl", { className: "rag-upload-facts" }, [
      ["任务编号", upload?.upload_id || "-"],
      ["状态", ragStateLabel(upload?.status)],
      ["当前阶段", ragStateLabel(upload?.stage || upload?.current_stage)],
      ["审核", ragStateLabel(reviewStatus)],
      ["向量化", ragStateLabel(upload?.vectorize_status)],
      ["进度", `${progress}%`]
    ].flatMap(([label, value]) => [el("dt", { text: label }), el("dd", { text: value })])),
    reviewStatus === "waiting_review" ? el("p", { className: "notice", text: "该任务需要审核：确认预览内容无误后点击“审核通过”，再提交向量化。" }) : null,
    el("details", { className: "chart-data-table" }, [
      el("summary", { text: "查看全部字段" }),
      renderDataDetails(upload || {})
    ])
  ]);
}

function renderRagPreviewTabs(state, previewPanel, refresh) {
  const tabs = [["text", "文本"], ["image", "图片"], ["error", "错误行"]];
  const group = el("section", { className: "rag-preview-tabs", role: "group", "aria-label": "预览类型" });
  const sync = () => {
    for (const node of group.querySelectorAll("button")) {
      const active = node.dataset.previewType === state.previewType;
      node.className = active ? "primary" : "secondary";
      node.setAttribute("aria-pressed", String(active));
    }
  };
  group.append(...tabs.map(([type, label]) => button(label, async () => {
    state.previewType = type;
    state.page = 1;
    sync();
    await refresh();
  }, "", { dataset: { previewType: type } })));
  sync();
  return group;
}

function previewPager(data, onPage) {
  const limit = Number(data?.page_size) || PREVIEW_QUERY_DEFAULTS.page_size;
  const page = Math.max(1, Number(data?.page) || 1);
  return pager({
    total: Number(data?.total) || 0,
    limit,
    offset: (page - 1) * limit,
    count: Array.isArray(data?.items) ? data.items.length : 0,
    pageSizes: [limit],
    label: "预览分页",
    onChange: ({ offset }) => onPage(Math.floor(offset / limit) + 1)
  });
}

const PREVIEW_TYPE_LABELS = { text: "文本", image: "图片", error: "错误行", all: "全部" };

function renderRagPreviewPage(data, refresh, onPage) {
  const items = Array.isArray(data?.items) ? data.items : [];
  const body = items.map((item) => item.item_type === "image"
    ? renderRagPreviewImageItem(item, refresh)
    : renderRagPreviewTextItem(item));
  return el("section", { className: "rag-preview-page" }, [
    el("header", { className: "table-toolbar" }, [
      el("h2", { text: `预览：${PREVIEW_TYPE_LABELS[data?.type] || PREVIEW_TYPE_LABELS.all}` }),
      el("span", { className: "chart-subtle", text: `共 ${Number(data?.total || 0).toLocaleString("zh-CN")} 条` })
    ]),
    ...(body.length > 0 ? body : [emptyState({ title: "暂无预览数据" })]),
    Number(data?.total || 0) > 0 ? previewPager(data, onPage) : null
  ]);
}

function renderRagPreviewTextItem(item) {
  return el("article", { className: "rag-preview-item" }, [
    el("h3", { text: item.title || item.question || item.preview_item_id || "文本条目" }),
    el("p", { text: item.content || item.answer || item.description || "" }),
    el("p", { className: "chart-subtle", text: `校验：${ragStateLabel(item.validation_status)}` }),
    ragIssues("校验问题", item.validation_errors)
  ]);
}

function renderRagPreviewImageItem(item, refresh) {
  const image = item.image_asset || {};
  const ai = image.metadata?.ai_annotation || {};
  const title = item.title || image.original_filename || item.preview_item_id || "图片";
  return el("article", { className: "rag-image-preview-item" }, [
    image.thumbnail_path ? el("img", { className: "rag-image-preview-thumb", alt: `${title} 缩略图`, src: image.thumbnail_path }) : el("div", { className: "rag-image-preview-thumb", text: "无缩略图" }),
    el("div", { className: "rag-image-preview-body" }, [
      el("h3", { text: title }),
      ragFacts("人工标注", { purpose: item.purpose || image.purpose, description: item.description || image.short_description, detailed_description: item.detailed_description || image.detailed_description }),
      ragFacts("AI 标注", ai),
      ragFacts("OCR", { ocr_text: image.ocr_text || ai.ocr_text }),
      ragIssues("警告", image.warnings || item.validation_errors),
      image.image_id ? button("重新 AI 标注", async () => {
        await api(`/admin-api/rag/image-assets/${encodeURIComponent(image.image_id)}/reprocess-ai`, { method: "POST" });
        toast("已提交重新标注", { tone: "success" });
        await refresh();
      }, "secondary") : null
    ].filter(Boolean))
  ]);
}

async function reviewRagUpload(uploadId, decision, output, refresh) {
  if (!uploadId) {
    setErrorOutput(output, "请先输入导入任务编号");
    return;
  }
  try {
    const result = await api(`/admin-api/rag/uploads/${encodeURIComponent(uploadId)}/review`, {
      method: "POST",
      body: JSON.stringify({ decision, comment: decision === "approve" ? "web approve" : "web reject" })
    });
    setOperationOutput(output, result.data, "审核状态已更新");
    await refresh();
  } catch (error) {
    setErrorOutput(output, adminErrorMessage(error, "审核操作失败"));
  }
}

async function confirmRagUpload(uploadId, output, refresh) {
  if (!uploadId) {
    setErrorOutput(output, "请先输入导入任务编号");
    return;
  }
  try {
    const result = await api(`/admin-api/rag/uploads/${encodeURIComponent(uploadId)}/confirm-vectorization`, { method: "POST" });
    setOperationOutput(output, result.data, "确认向量化已提交");
    await refresh();
  } catch (error) {
    setErrorOutput(output, adminErrorMessage(error, "确认向量化失败"));
  }
}

export async function renderRagImagePreviewPage(content, initialFilters = {}) {
  clear(content);
  const state = {
    uploadId: String(initialFilters.upload_id || ""),
    page: Math.max(1, Number(initialFilters.page || 1) || 1),
  };
  const uploadField = field({ name: "upload_id", label: "导入任务编号" }, state.uploadId);
  const output = el("p", { className: "notice", hidden: true });
  const list = el("section", { className: "rag-image-preview-list" }, [
    statusMessage("请输入导入任务编号查询图片预览。")
  ]);
  const refresh = async () => {
    state.uploadId = String(uploadField.querySelector("[name=\"upload_id\"]")?.value || "").trim();
    if (state.uploadId === "") {
      list.replaceChildren(statusMessage("请输入导入任务编号查询图片预览。"));
      return;
    }
    try {
      list.replaceChildren(loadingState("正在加载图片预览…"));
      const result = await api(`/admin-api/rag/uploads/${encodeURIComponent(state.uploadId)}/preview`, {
        query: { ...PREVIEW_QUERY_DEFAULTS, type: "image", page: state.page }
      });
      list.replaceChildren(renderRagImagePreviewItems(result.data, refresh, (page) => {
        state.page = page;
        return refresh();
      }));
      output.hidden = true;
    } catch (error) {
      if (isAbortError(error)) {
        return;
      }
      list.replaceChildren(errorState(error, { fallback: "RAG 图片预览加载失败", onRetry: () => refresh() }));
    }
  };
  content.append(el("section", { className: "panel rag-image-preview-page" }, [
    el("h2", { text: "RAG 图片预览" }),
    el("div", { className: "toolbar-actions" }, [
      uploadField,
      button("查询", () => {
        state.page = 1;
        return refresh();
      })
    ]),
    statusMessage("请在上传详情页审核预览后提交向量化。"),
    output
  ]));
  content.append(list);
  await refresh();
}

function renderRagImagePreviewItems(data, refresh, onPage) {
  const items = Array.isArray(data?.items) ? data.items : [];
  const rows = items.map((item) => {
    const metadata = item.metadata || {};
    const image = metadata.image_asset || metadata.image || {};
    const thumbnail = image.thumbnail_url || image.thumbnail_path || item.thumbnail_url || "";
    const human = metadata.human_annotation || item.human_annotation || {
      title: item.title,
      purpose: item.purpose,
      description: item.description,
      detailed_description: metadata.detailed_description
    };
    const ai = metadata.ai_annotation || image.ai_annotation || {};
    const warnings = item.validation_errors || image.quality_warnings || metadata.quality_warnings || [];
    const title = String(human.title || item.title || item.item_id || item.id || "图片");
    return el("article", { className: "rag-image-preview-item" }, [
      thumbnail ? el("img", { className: "rag-image-preview-thumb", alt: `${title} 缩略图`, src: thumbnail }) : el("div", { className: "rag-image-preview-thumb", text: "无缩略图" }),
      el("div", { className: "rag-image-preview-body" }, [
        el("h3", { text: title }),
        el("p", { className: "chart-subtle", text: `状态：${ragStateLabel(item.validation_status || item.status)}` }),
        ragFacts("人工标注", human),
        ragFacts("AI 标注", ai),
        ragFacts("OCR", { ocr_text: ai.ocr_text || image.ocr_text || metadata.ocr_text }),
        ragIssues("质量警告", warnings),
        image.image_id || item.image_id ? button("重新 AI 标注", async () => {
          await api(`/admin-api/rag/image-assets/${encodeURIComponent(image.image_id || item.image_id)}/reprocess-ai`, { method: "POST" });
          toast("已提交重新标注", { tone: "success" });
          await refresh();
        }, "secondary") : null
      ].filter(Boolean))
    ]);
  });
  if (rows.length === 0) {
    rows.push(emptyState({ title: "暂无图片预览数据" }));
  }
  return el("section", { className: "panel rag-image-preview-results" }, [
    el("header", { className: "table-toolbar" }, [
      el("h2", { text: "图片预览" }),
      el("span", { className: "chart-subtle", text: `共 ${Number(data?.total || 0).toLocaleString("zh-CN")} 条` })
    ]),
    ...rows,
    Number(data?.total || 0) > 0 ? previewPager(data, onPage) : null
  ]);
}
