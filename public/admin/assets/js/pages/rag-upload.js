import { getLocale, localize } from "../i18n.js";
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
  const fileInput = el("input", { type: "file", name: "rag_file", accept: RAG_UPLOAD_ACCEPT });
  const output = el("p", { className: "notice", hidden: true });
  const uploadResult = el("section", { className: "rag-upload-result" });

  form.append(
    field({ name: "kb_id", label: localize("知识库"), type: "select", options: kbOptions }),
    field({ name: "target_dataset_id", label: localize("向量数据集"), type: "select", options: datasetOptions }),
    field({ name: "department_id", label: localize("部门"), type: "select", coerce: "number", options: departmentOptions }),
    field({ name: "template", label: localize("导入类型"), type: "select", options: [["text", localize("文本（表格模板）")], ["qa", localize("问答（表格模板）")], ["document", localize("文档（PDF / Word / WPS / PPT / RTF / Markdown / HTML / TXT）")], ["image", localize("图片清单")], ["image_package", localize("ZIP 图片包")]] }),
    // RAG-022: optional masking before indexing; nothing selected = knowledge base / system default.
    field({ name: "redaction", label: localize("导入前脱敏（不选则按知识库或系统默认）"), type: "multiselect", optional: true, options: [["phone", localize("手机号")], ["email", localize("邮箱")], ["id_card", localize("身份证号")], ["bank_card", localize("银行卡号")], ["ipv4", localize("IP 地址")]] }),
    field({ name: "confirm_required", label: localize("上传后确认"), type: "select", options: [["true", localize("需要预览确认")], ["false", localize("允许自动向量化")]] }),
    el("label", { className: "field" }, [
      el("span", { text: localize("上传文件") }),
      fileInput,
      el("small", { className: "field-hint", text: localize("文档：PDF、Word（.docx/.doc）、WPS 文字（.wps）、RTF、ODT、PPT（.pptx/.ppt）、WPS 演示（.dps）、ODP、Markdown、HTML、TXT；表格：XLSX、XLS、WPS 表格（.et）、ODS、CSV。单个文件不超过 20 MB；扫描版 PDF 需要启用 OCR。") })
    ]),
    el("div", { className: "toolbar-actions" }, [
      button(localize("上传并生成预览"), async () => {
        await submitRagUploadWorkbench(form, fileInput, output, uploadResult, options.onUploaded);
      }, "primary", { disabled: kbOptions.length === 0 || datasetOptions.length === 0 })
    ]),
    output
  );

  // Documents can only be imported as "document"; switch the import type when one is chosen
  // so a PDF or Word file is not sent to the spreadsheet template reader.
  fileInput.addEventListener("change", () => {
    const template = form.querySelector('[name="template"]');
    const name = fileInput.files?.[0]?.name || "";
    if (template && isRagDocumentFile(name) && template.value !== "document") {
      template.value = "document";
      template.dispatchEvent(new Event("change", { bubbles: true }));
    }
  });

  content.append(el("section", { className: "panel" }, [
    el("h2", { text: localize("RAG 上传工作台") }),
    form
  ]), uploadResult);
}

export const RAG_DOCUMENT_EXTENSIONS = ["pdf", "docx", "doc", "wps", "rtf", "odt", "pptx", "ppt", "dps", "odp", "md", "markdown", "html", "htm", "txt"];
export const RAG_SPREADSHEET_EXTENSIONS = ["xlsx", "xls", "et", "ods", "csv"];
const RAG_UPLOAD_ACCEPT = [...RAG_SPREADSHEET_EXTENSIONS, ...RAG_DOCUMENT_EXTENSIONS, "zip"].map((ext) => `.${ext}`).join(",");

export function isRagDocumentFile(name) {
  const ext = String(name || "").toLowerCase().split(".").pop();
  return RAG_DOCUMENT_EXTENSIONS.includes(ext);
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
    setErrorOutput(output, localize("请选择要上传的文档（PDF、Word、PPT 等）、表格或 ZIP 文件"));
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
    setOperationOutput(output, result.data, localize("上传已解析，请在导入任务中预览确认"));
    uploadResult.replaceChildren(renderRagUploadStatusCard(result.data));
  } catch (error) {
    setErrorOutput(output, adminErrorMessage(error, localize("RAG 上传失败")));
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
  const uploadField = taskMode ? null : field({ name: "upload_id", label: localize("导入任务编号") }, state.uploadId);
  const statusPanel = el("section", { className: "panel rag-upload-status-panel" }, [statusMessage(taskMode ? localize("正在加载导入任务…") : localize("请输入导入任务编号查询上传详情。"))]);
  const previewPanel = el("section", { className: "panel rag-upload-preview-panel" });
  const output = el("p", { className: "notice", hidden: true });
  const approveButton = button(localize("审核通过"), async () => reviewRagUpload(state.uploadId, "approve", output, refresh), "secondary", { disabled: !state.uploadLoaded });
  const rejectButton = button(localize("审核拒绝"), async () => reviewRagUpload(state.uploadId, "reject", output, refresh), "secondary", { disabled: !state.uploadLoaded });
  const vectorizeButton = button(localize("确认向量化"), async () => confirmRagUpload(state.uploadId, output, refresh), "primary", { disabled: !state.uploadLoaded });

  const refresh = async () => {
    if (uploadField) {
      state.uploadId = String(uploadField.querySelector("[name=\"upload_id\"]")?.value || "").trim();
    }
    if (state.uploadId === "") {
      statusPanel.replaceChildren(statusMessage(taskMode ? localize("尚未创建导入任务，请先上传文件。") : localize("请输入导入任务编号查询上传详情。")));
      previewPanel.replaceChildren();
      return;
    }
    await refreshRagUploadDetail(state, statusPanel, previewPanel, output, refresh, { approveButton, rejectButton, vectorizeButton });
  };

  content.append(el("section", { className: "panel rag-upload-detail" }, [
    el("h2", { text: taskMode ? localize("导入任务") : localize("RAG 上传详情") }),
    el("div", { className: "toolbar-actions" }, [
      uploadField,
      uploadField ? button(localize("查询"), refresh) : null,
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
    statusPanel.replaceChildren(errorState(error, { fallback: localize("RAG 上传详情加载失败"), onRetry: () => refresh() }));
    previewPanel.replaceChildren();
  }
}

const RAG_STATE_LABELS = {
  pending: localize("待处理"),
  uploaded: localize("已上传"),
  parsing: localize("解析中"),
  parsed: localize("已解析"),
  validating: localize("校验中"),
  previewing: localize("待预览"),
  waiting_review: localize("待审核"),
  pending_review: localize("待审核"),
  not_required: localize("无需审核"),
  approved: localize("已通过"),
  rejected: localize("已拒绝"),
  queued: localize("排队中"),
  processing: localize("处理中"),
  vectorizing: localize("向量化中"),
  succeeded: localize("成功"),
  completed: localize("已完成"),
  failed: localize("失败"),
  valid: localize("校验通过"),
  passed: localize("校验通过"),
  invalid: localize("校验未通过"),
  warning: localize("有警告")
};

const RAG_FIELD_LABELS = {
  purpose: localize("用途"),
  description: localize("简述"),
  short_description: localize("简述"),
  detailed_description: localize("详细描述"),
  title: localize("标题"),
  tags: localize("标签"),
  labels: localize("标签"),
  caption: localize("图片说明"),
  ocr_text: localize("OCR 文本"),
  confidence: localize("置信度"),
  model: localize("模型"),
  model_name: localize("模型"),
  language: localize("语言"),
  objects: localize("识别对象"),
  status: localize("状态"),
  error: localize("错误")
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
    return value.toLocaleString(getLocale());
  }
  if (typeof value === "boolean") {
    return value ? localize("是") : localize("否");
  }
  return String(value);
}

// Readable key/value list for annotation objects (no raw JSON).
function ragFacts(title, data) {
  const entries = Object.entries(data || {}).filter(([, value]) => value !== null && value !== undefined && value !== "" && !(Array.isArray(value) && value.length === 0));
  if (!entries.length) {
    return el("div", { className: "rag-facts" }, [el("h4", { text: title }), el("p", { className: "chart-subtle", text: localize("暂无") })]);
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
    el("h3", { text: upload?.original_filename || upload?.upload_id || localize("导入任务") }),
    el("div", { className: "rag-upload-progress", role: "progressbar", "aria-valuemin": "0", "aria-valuemax": "100", "aria-valuenow": String(Math.max(0, Math.min(100, progress))), "aria-label": localize("导入进度") }, [
      el("div", { className: "rag-upload-progress-bar", style: `width: ${Math.max(0, Math.min(100, progress))}%` })
    ]),
    el("dl", { className: "rag-upload-facts" }, [
      [localize("任务编号"), upload?.upload_id || "-"],
      [localize("状态"), ragStateLabel(upload?.status)],
      [localize("当前阶段"), ragStateLabel(upload?.stage || upload?.current_stage)],
      [localize("审核"), ragStateLabel(reviewStatus)],
      [localize("向量化"), ragStateLabel(upload?.vectorize_status)],
      [localize("进度"), `${progress}%`]
    ].flatMap(([label, value]) => [el("dt", { text: label }), el("dd", { text: value })])),
    reviewStatus === "waiting_review" ? el("p", { className: "notice", text: localize("该任务需要审核：确认预览内容无误后点击“审核通过”，再提交向量化。") }) : null,
    el("details", { className: "chart-data-table" }, [
      el("summary", { text: localize("查看全部字段") }),
      renderDataDetails(upload || {})
    ])
  ]);
}

function renderRagPreviewTabs(state, previewPanel, refresh) {
  const tabs = [["text", localize("文本")], ["image", localize("图片")], ["error", localize("错误行")]];
  const group = el("section", { className: "rag-preview-tabs", role: "group", "aria-label": localize("预览类型") });
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
    label: localize("预览分页"),
    onChange: ({ offset }) => onPage(Math.floor(offset / limit) + 1)
  });
}

const PREVIEW_TYPE_LABELS = { text: localize("文本"), image: localize("图片"), error: localize("错误行"), all: localize("全部") };

function renderRagPreviewPage(data, refresh, onPage) {
  const items = Array.isArray(data?.items) ? data.items : [];
  const body = items.map((item) => item.item_type === "image"
    ? renderRagPreviewImageItem(item, refresh)
    : renderRagPreviewTextItem(item));
  return el("section", { className: "rag-preview-page" }, [
    el("header", { className: "table-toolbar" }, [
      el("h2", { text: localize`预览：${PREVIEW_TYPE_LABELS[data?.type] || PREVIEW_TYPE_LABELS.all}` }),
      el("span", { className: "chart-subtle", text: localize`共 ${Number(data?.total || 0).toLocaleString(getLocale())} 条` })
    ]),
    ...(body.length > 0 ? body : [emptyState({ title: localize("暂无预览数据") })]),
    Number(data?.total || 0) > 0 ? previewPager(data, onPage) : null
  ]);
}

function renderRagPreviewTextItem(item) {
  return el("article", { className: "rag-preview-item" }, [
    el("h3", { text: item.title || item.question || item.preview_item_id || localize("文本条目") }),
    el("p", { text: item.content || item.answer || item.description || "" }),
    el("p", { className: "chart-subtle", text: localize`校验：${ragStateLabel(item.validation_status)}` }),
    ragIssues(localize("校验问题"), item.validation_errors)
  ]);
}

function renderRagPreviewImageItem(item, refresh) {
  const image = item.image_asset || {};
  const ai = image.metadata?.ai_annotation || {};
  const title = item.title || image.original_filename || item.preview_item_id || localize("图片");
  return el("article", { className: "rag-image-preview-item" }, [
    image.thumbnail_path ? el("img", { className: "rag-image-preview-thumb", alt: localize`${title} 缩略图`, src: image.thumbnail_path }) : el("div", { className: "rag-image-preview-thumb", text: localize("无缩略图") }),
    el("div", { className: "rag-image-preview-body" }, [
      el("h3", { text: title }),
      ragFacts(localize("人工标注"), { purpose: item.purpose || image.purpose, description: item.description || image.short_description, detailed_description: item.detailed_description || image.detailed_description }),
      ragFacts(localize("AI 标注"), ai),
      ragFacts("OCR", { ocr_text: image.ocr_text || ai.ocr_text }),
      ragIssues(localize("警告"), image.warnings || item.validation_errors),
      image.image_id ? button(localize("重新 AI 标注"), async () => {
        await api(`/admin-api/rag/image-assets/${encodeURIComponent(image.image_id)}/reprocess-ai`, { method: "POST" });
        toast(localize("已提交重新标注"), { tone: "success" });
        await refresh();
      }, "secondary") : null
    ].filter(Boolean))
  ]);
}

async function reviewRagUpload(uploadId, decision, output, refresh) {
  if (!uploadId) {
    setErrorOutput(output, localize("请先输入导入任务编号"));
    return;
  }
  try {
    const result = await api(`/admin-api/rag/uploads/${encodeURIComponent(uploadId)}/review`, {
      method: "POST",
      body: JSON.stringify({ decision, comment: decision === "approve" ? "web approve" : "web reject" })
    });
    setOperationOutput(output, result.data, localize("审核状态已更新"));
    await refresh();
  } catch (error) {
    setErrorOutput(output, adminErrorMessage(error, localize("审核操作失败")));
  }
}

async function confirmRagUpload(uploadId, output, refresh) {
  if (!uploadId) {
    setErrorOutput(output, localize("请先输入导入任务编号"));
    return;
  }
  try {
    const result = await api(`/admin-api/rag/uploads/${encodeURIComponent(uploadId)}/confirm-vectorization`, { method: "POST" });
    setOperationOutput(output, result.data, localize("确认向量化已提交"));
    await refresh();
  } catch (error) {
    setErrorOutput(output, adminErrorMessage(error, localize("确认向量化失败")));
  }
}

export async function renderRagImagePreviewPage(content, initialFilters = {}) {
  clear(content);
  const state = {
    uploadId: String(initialFilters.upload_id || ""),
    page: Math.max(1, Number(initialFilters.page || 1) || 1),
  };
  const uploadField = field({ name: "upload_id", label: localize("导入任务编号") }, state.uploadId);
  const output = el("p", { className: "notice", hidden: true });
  const list = el("section", { className: "rag-image-preview-list" }, [
    statusMessage(localize("请输入导入任务编号查询图片预览。"))
  ]);
  const refresh = async () => {
    state.uploadId = String(uploadField.querySelector("[name=\"upload_id\"]")?.value || "").trim();
    if (state.uploadId === "") {
      list.replaceChildren(statusMessage(localize("请输入导入任务编号查询图片预览。")));
      return;
    }
    try {
      list.replaceChildren(loadingState(localize("正在加载图片预览…")));
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
      list.replaceChildren(errorState(error, { fallback: localize("RAG 图片预览加载失败"), onRetry: () => refresh() }));
    }
  };
  content.append(el("section", { className: "panel rag-image-preview-page" }, [
    el("h2", { text: localize("RAG 图片预览") }),
    el("div", { className: "toolbar-actions" }, [
      uploadField,
      button(localize("查询"), () => {
        state.page = 1;
        return refresh();
      })
    ]),
    statusMessage(localize("请在上传详情页审核预览后提交向量化。")),
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
    const title = String(human.title || item.title || item.item_id || item.id || localize("图片"));
    return el("article", { className: "rag-image-preview-item" }, [
      thumbnail ? el("img", { className: "rag-image-preview-thumb", alt: localize`${title} 缩略图`, src: thumbnail }) : el("div", { className: "rag-image-preview-thumb", text: localize("无缩略图") }),
      el("div", { className: "rag-image-preview-body" }, [
        el("h3", { text: title }),
        el("p", { className: "chart-subtle", text: localize`状态：${ragStateLabel(item.validation_status || item.status)}` }),
        ragFacts(localize("人工标注"), human),
        ragFacts(localize("AI 标注"), ai),
        ragFacts("OCR", { ocr_text: ai.ocr_text || image.ocr_text || metadata.ocr_text }),
        ragIssues(localize("质量警告"), warnings),
        image.image_id || item.image_id ? button(localize("重新 AI 标注"), async () => {
          await api(`/admin-api/rag/image-assets/${encodeURIComponent(image.image_id || item.image_id)}/reprocess-ai`, { method: "POST" });
          toast(localize("已提交重新标注"), { tone: "success" });
          await refresh();
        }, "secondary") : null
      ].filter(Boolean))
    ]);
  });
  if (rows.length === 0) {
    rows.push(emptyState({ title: localize("暂无图片预览数据") }));
  }
  return el("section", { className: "panel rag-image-preview-results" }, [
    el("header", { className: "table-toolbar" }, [
      el("h2", { text: localize("图片预览") }),
      el("span", { className: "chart-subtle", text: localize`共 ${Number(data?.total || 0).toLocaleString(getLocale())} 条` })
    ]),
    ...rows,
    Number(data?.total || 0) > 0 ? previewPager(data, onPage) : null
  ]);
}
